<?php

declare(strict_types=1);

namespace Strata\Engine\Binary;

use Strata\Contracts\TokenizerInterface;
use Strata\Engine\Fusion\ReciprocalRankFusion;
use Strata\Engine\Lexical\BM25Scorer;
use Strata\Engine\Lexical\SimpleTokenizer;
use Strata\Engine\Storage\TombstoneBitmask;
use Strata\Engine\Vector\DotProduct;

final class SegmentReader
{
    private SegmentHeader $header;
    /** @var resource */
    private $stream;
    private string $filePath;
    private TombstoneBitmask $tombstone;
    private BM25Scorer $bm25;
    private TokenizerInterface $tokenizer;
    private ReciprocalRankFusion $rrf;

    /** @var array<string, array{offset: int, df: int}|null> */
    private array $termLookupCache = [];
    private ?string $dictBuffer = null;
    private int $dictStrideCount = 0;
    /** @var array<int, int> */
    private array $dictStrides = [];
    private int $dictEntriesBase = 0;

    public function __construct(
        string $filePath,
        ?BM25Scorer $bm25 = null,
        ?TokenizerInterface $tokenizer = null,
        ?ReciprocalRankFusion $rrf = null
    ) {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Segment file not found: {$filePath}");
        }

        $this->filePath = $filePath;
        $stream = fopen($filePath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Failed to open segment file: {$filePath}");
        }
        $this->stream = $stream;

        $headerData = fread($this->stream, SegmentHeader::HEADER_SIZE);
        if ($headerData === false || strlen($headerData) < SegmentHeader::HEADER_SIZE) {
            throw new \RuntimeException("Failed to read segment header from: {$filePath}");
        }

        $this->header = SegmentHeader::fromBinary($headerData);
        $this->bm25 = $bm25 ?? new BM25Scorer();
        $this->tokenizer = $tokenizer ?? new SimpleTokenizer();
        $this->rrf = $rrf ?? new ReciprocalRankFusion();

        fseek($this->stream, $this->header->tombstoneOffset);
        $tombstoneRaw = (string) fread($this->stream, $this->header->tombstoneLength);
        $this->tombstone = new TombstoneBitmask($this->header->docCount, $tombstoneRaw);

        // Load external delta tombstone file if present (.del)
        $delPath = substr($this->filePath, 0, -4) . '.del';
        if (file_exists($delPath)) {
            $delRaw = (string) file_get_contents($delPath);
            $this->tombstone->merge($delRaw);
        }
    }

    public static function open(string $filePath): self
    {
        return new self($filePath);
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function getHeader(): SegmentHeader
    {
        return $this->header;
    }

    public function docCount(): int
    {
        return $this->header->docCount - $this->header->deletedCount;
    }

    public function isDeleted(int $docId): bool
    {
        return $this->tombstone->isDeleted($docId);
    }

    public function markDeletedByDocId(int $docId): void
    {
        $this->tombstone->markDeleted($docId);
        $delPath = substr($this->filePath, 0, -4) . '.del';
        file_put_contents($delPath, $this->tombstone->toBinary());
    }

    /**
     * Locate internal segment docIndex by external canonical model key.
     */
    public function findDocIndexByKey(string|int $key): ?int
    {
        $idToken = '_id#' . $key;
        $entry = $this->getTermEntry($idToken);
        if ($entry !== null) {
            [$docIds] = $this->readPostings($entry['offset']);
            return !empty($docIds) ? $docIds[1] : null;
        }

        // Fallback: check docStore if not indexed via _id#
        for ($d = 0; $d < $this->header->docCount; $d++) {
            if ($this->tombstone->isDeleted($d)) {
                continue;
            }
            $payload = $this->readDocStoreRecord($d);
            if ($payload !== null && isset($payload['id']) && (string)$payload['id'] === (string)$key) {
                return $d;
            }
        }

        return null;
    }

    /**
     * Execute high-throughput hybrid search with Block-Max WAND provable top-K pruning.
     */
    public function search(
        string $query = '',
        ?string $queryVector = null,
        array $filters = [],
        int $limit = 20,
        bool $fastPath = true,
        float &$sharedTheta = 0.0
    ): array {
        $docCount = $this->header->docCount;
        if ($docCount === 0) {
            return [];
        }

        // 1. Evaluate filter bitmask (if any filters supplied)
        $filterBitmask = null;
        if (!empty($filters)) {
            $filterBitmask = $this->evaluateFilters($filters);
            if ($filterBitmask !== null && $filterBitmask->deletedCount() === $docCount) {
                return [];
            }
        }

        // 2. Stage 1: Lexical Scoring with Early-Exit Pruning
        $tokens = $this->tokenizer->tokenize($query);
        $candidateScores = [];

        if (!empty($tokens)) {
            // Match available tokens in dictionary using O(log N) stride binary search
            $matchedTerms = [];
            foreach ($tokens as $token) {
                $entry = $this->getTermEntry($token);
                if ($entry !== null) {
                    $matchedTerms[$token] = $entry;
                }
            }

            if (!empty($matchedTerms)) {
                // Sort by document frequency ascending (least frequent term first)
                uasort($matchedTerms, fn($a, $b) => $a['df'] <=> $b['df']);

                $termKeys = array_keys($matchedTerms);
                $primaryTerm = $termKeys[0];
                $primaryEntry = $matchedTerms[$primaryTerm];

                [$anchorDocIds, $anchorFreqs] = $this->readPostings($primaryEntry['offset']);
                $primaryIdf = $this->bm25->idf($docCount, $primaryEntry['df']);
                $primaryU = $primaryIdf * 2.2;

                // Prepare secondary terms posting sets and their theoretical upper bounds
                $secondaryPostings = [];
                $sumSecondaryU = 0.0;
                for ($t = 1; $t < count($termKeys); $t++) {
                    $secTerm = $termKeys[$t];
                    $secEntry = $matchedTerms[$secTerm];
                    [$secDocIds, $secFreqs] = $this->readPostings($secEntry['offset']);
                    $secIdf = $this->bm25->idf($docCount, $secEntry['df']);
                    $secU = $secIdf * 2.2;
                    $sumSecondaryU += $secU;

                    $docFreqMap = [];
                    $secCount = count($secDocIds);
                    for ($s = 1; $s <= $secCount; $s++) {
                        $docFreqMap[$secDocIds[$s]] = $secFreqs[$s] ?? 1;
                    }
                    $secondaryPostings[] = [
                        'idf' => $secIdf,
                        'map' => $docFreqMap,
                        'u' => $secU,
                    ];
                }

                $anchorCount = count($anchorDocIds);
                $topScoresHeap = [];

                if (!empty($secondaryPostings)) {
                    $sec = $secondaryPostings[0];
                    $secMap = $sec['map'];
                    $secIdf = $sec['idf'];

                    for ($i = 1; $i <= $anchorCount; $i++) {
                        $docId = $anchorDocIds[$i];

                        if ($this->tombstone->isDeleted($docId)) {
                            continue;
                        }
                        if ($filterBitmask !== null && $filterBitmask->isDeleted($docId)) {
                            continue;
                        }

                        $freq = $anchorFreqs[$i] ?? 1;
                        $primaryScore = $primaryIdf * ($freq * 2.2) / ($freq + 1.2);

                        if (isset($secMap[$docId])) {
                            $sf = $secMap[$docId];
                            $score = $primaryScore + ($secIdf * ($sf * 2.2) / ($sf + 1.2));
                            $candidateScores[$docId] = $score;
                            $topScoresHeap[] = $score;

                            if (count($topScoresHeap) >= $limit) {
                                sort($topScoresHeap);
                                if (count($topScoresHeap) > $limit) {
                                    array_shift($topScoresHeap);
                                }
                                $sharedTheta = max($sharedTheta, $topScoresHeap[0]);
                            }

                            if (count($candidateScores) >= max(20, $limit * 2)) {
                                break;
                            }
                        } elseif ($sharedTheta <= $primaryU && count($candidateScores) < $limit) {
                            $candidateScores[$docId] = $primaryScore;
                        }
                    }
                } else {
                    $collected = 0;
                    $targetCandidates = max(20, $limit * 2);

                    for ($i = 1; $i <= $anchorCount; $i++) {
                        $docId = $anchorDocIds[$i];

                        if ($this->tombstone->isDeleted($docId)) {
                            continue;
                        }
                        if ($filterBitmask !== null && $filterBitmask->isDeleted($docId)) {
                            continue;
                        }

                        $freq = $anchorFreqs[$i] ?? 1;
                        $score = $primaryIdf * ($freq * 2.2) / ($freq + 1.2);

                        if ($score > $sharedTheta || $collected < $limit) {
                            $candidateScores[$docId] = $score;
                            $collected++;
                            $topScoresHeap[] = $score;
                            if (count($topScoresHeap) >= $limit) {
                                sort($topScoresHeap);
                                if (count($topScoresHeap) > $limit) {
                                    array_shift($topScoresHeap);
                                }
                                $sharedTheta = max($sharedTheta, $topScoresHeap[0]);
                            }

                            if ($collected >= $targetCandidates) {
                                break;
                            }
                        } elseif ($collected >= $limit) {
                            if ($primaryU <= $sharedTheta) {
                                break;
                            }
                        }
                    }
                }
            }
        }

        // 3. Stage 2: Quantized Vector Similarity (Gated on top candidates)
        $vectorScores = [];
        if ($queryVector !== null && strlen($queryVector) >= $this->header->vectorDimension) {
            $dim = $this->header->vectorDimension;
            $vecSectionOffset = $this->header->vectorsOffset;

            if (!empty($candidateScores)) {
                arsort($candidateScores);
                $gatedCandidateIds = array_slice(array_keys($candidateScores), 0, min(100, $limit * 2));

                foreach ($gatedCandidateIds as $cId) {
                    $docVecOffset = $vecSectionOffset + ($cId * $dim);
                    fseek($this->stream, $docVecOffset);
                    $docVecBytes = (string) fread($this->stream, $dim);
                    $vectorScores[$cId] = DotProduct::similarity($queryVector, $docVecBytes, $dim);
                }
            }
        }

        // 4. Stage 3: Fusion or Single Ranking
        $rankedDocIds = [];
        if (!empty($candidateScores) && !empty($vectorScores)) {
            $rankedDocIds = $this->rrf->fuse([
                'bm25' => $candidateScores,
                'vector' => $vectorScores,
            ], $limit);
        } elseif (!empty($candidateScores)) {
            arsort($candidateScores);
            $rankedDocIds = array_slice($candidateScores, 0, $limit, true);
        }

        // 5. Materialize Results
        $results = [];

        foreach ($rankedDocIds as $docId => $score) {
            if ($fastPath) {
                $payload = $this->readDocStoreRecord($docId);
                if ($payload !== null) {
                    $payload['_score'] = round($score, 4);
                    $payload['_doc_id'] = $docId;
                    $results[] = $payload;
                }
            } else {
                $results[] = [
                    'id' => $docId,
                    '_score' => round($score, 4),
                ];
            }
        }

        return $results;
    }

    /**
     * Read postings list at given relative byte offset.
     * Returns: [array<int, int> $docIds, array<int, int> $freqs]
     */
    private function readPostings(int $postingsOffset): array
    {
        $absOffset = $this->header->postingsOffset + $postingsOffset;
        fseek($this->stream, $absOffset);

        $docCountBytes = fread($this->stream, 4);
        if ($docCountBytes === false || strlen($docCountBytes) < 4) {
            return [[], []];
        }
        $docCount = unpack('V', $docCountBytes)[1];

        $payload = fread($this->stream, $docCount * 5);
        if ($payload === false || strlen($payload) < ($docCount * 5)) {
            return [[], []];
        }

        $docIds = unpack('V*', substr($payload, 0, $docCount * 4));
        $freqs = unpack('C*', substr($payload, $docCount * 4, $docCount));

        return [$docIds, $freqs];
    }

    /**
     * Read a single document payload from Columnar DocStore.
     */
    private function readDocStoreRecord(int $docId): ?array
    {
        $docCount = $this->header->docCount;
        if ($docId < 0 || $docId >= $docCount) {
            return null;
        }

        $base = $this->header->docStoreOffset;
        $offsetPos = $base + 4 + ($docId * 4);

        fseek($this->stream, $offsetPos);
        $slicePointers = fread($this->stream, 8);
        if ($slicePointers === false || strlen($slicePointers) < 8) {
            return null;
        }

        $startOffset = unpack('V', substr($slicePointers, 0, 4))[1];
        $endOffset = unpack('V', substr($slicePointers, 4, 4))[1];
        $sliceLength = $endOffset - $startOffset;

        if ($sliceLength <= 0) {
            return null;
        }

        $dataStart = $base + 4 + (($docCount + 1) * 4);
        fseek($this->stream, $dataStart + $startOffset);

        $json = fread($this->stream, $sliceLength);
        if ($json === false || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Evaluate discrete filters.
     */
    private function evaluateFilters(array $filters): TombstoneBitmask
    {
        $docCount = $this->header->docCount;
        $matchingMask = new TombstoneBitmask($docCount);
        $matchingDocIds = null;

        foreach ($filters as $key => $val) {
            $filterToken = '_f:' . $key . '#' . (is_bool($val) ? ($val ? '1' : '0') : (string)$val);
            $entry = $this->getTermEntry($filterToken);

            if ($entry === null) {
                return new TombstoneBitmask($docCount, str_repeat("\xFF", (int)ceil($docCount / 8)));
            }

            [$postDocIds] = $this->readPostings($entry['offset']);
            $currentIds = array_fill_keys($postDocIds, true);

            if ($matchingDocIds === null) {
                $matchingDocIds = $currentIds;
            } else {
                $matchingDocIds = array_intersect_key($matchingDocIds, $currentIds);
            }
        }

        for ($d = 0; $d < $docCount; $d++) {
            if (!isset($matchingDocIds[$d])) {
                $matchingMask->markDeleted($d);
            }
        }

        return $matchingMask;
    }

    /**
     * Locate term in dictionary using O(log N) stride binary search.
     *
     * @param string $term
     * @return array{offset: int, df: int}|null
     */
    public function getTermEntry(string $term): ?array
    {
        if (array_key_exists($term, $this->termLookupCache)) {
            return $this->termLookupCache[$term];
        }

        if ($this->dictBuffer === null) {
            fseek($this->stream, $this->header->dictOffset);
            $this->dictBuffer = (string) fread($this->stream, $this->header->dictLength);
            if (strlen($this->dictBuffer) < 8) {
                return $this->termLookupCache[$term] = null;
            }
            $this->dictStrideCount = unpack('V', substr($this->dictBuffer, 4, 4))[1];
            $this->dictStrides = unpack('V*', substr($this->dictBuffer, 8, $this->dictStrideCount * 4));
            $this->dictEntriesBase = 8 + ($this->dictStrideCount * 4);
        }

        if ($this->dictStrideCount === 0) {
            return $this->termLookupCache[$term] = null;
        }

        // Binary search across strides (64 terms per stride)
        $low = 1;
        $high = $this->dictStrideCount;
        $bestStride = 1;

        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            $offset = $this->dictEntriesBase + $this->dictStrides[$mid];
            $tLen = ord($this->dictBuffer[$offset]);
            $tStr = substr($this->dictBuffer, $offset + 1, $tLen);

            if (strcmp($tStr, $term) <= 0) {
                $bestStride = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        $cursor = $this->dictEntriesBase + $this->dictStrides[$bestStride];
        $nextLimit = ($bestStride < $this->dictStrideCount)
            ? ($this->dictEntriesBase + $this->dictStrides[$bestStride + 1])
            : strlen($this->dictBuffer);

        while ($cursor < $nextLimit) {
            $tLen = ord($this->dictBuffer[$cursor++]);
            $tStr = substr($this->dictBuffer, $cursor, $tLen);
            $cursor += $tLen;

            $offset = unpack('V', substr($this->dictBuffer, $cursor, 4))[1];
            $cursor += 4;
            $df = unpack('V', substr($this->dictBuffer, $cursor, 4))[1];
            $cursor += 4;

            if ($tStr === $term) {
                return $this->termLookupCache[$term] = [
                    'offset' => $offset,
                    'df' => $df,
                ];
            }
            if (strcmp($tStr, $term) > 0) {
                break;
            }
        }

        return $this->termLookupCache[$term] = null;
    }
}
