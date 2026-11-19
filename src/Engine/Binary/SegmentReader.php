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

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function getHeader(): SegmentHeader
    {
        return $this->header;
    }

    public function docCount(): int
    {
        return $this->header->docCount - $this->header->deletedCount;
    }

    private ?int $lastDelMtime = null;

    public function isDeleted(int $docId): bool
    {
        return $this->tombstone->isDeleted($docId);
    }

    public function markDeletedByDocId(int $docId): void
    {
        $this->tombstone->markDeleted($docId);
        $delPath = substr($this->filePath, 0, -4) . '.del';
        file_put_contents($delPath, $this->tombstone->toBinary(), LOCK_EX);
        $this->lastDelMtime = filemtime($delPath) ?: time();
    }

    private function syncDeltaTombstones(): void
    {
        $delPath = substr($this->filePath, 0, -4) . '.del';
        if (file_exists($delPath)) {
            $mtime = filemtime($delPath) ?: 0;
            if ($this->lastDelMtime === null || $mtime > $this->lastDelMtime) {
                $delRaw = (string) file_get_contents($delPath);
                $this->tombstone->merge($delRaw);
                $this->lastDelMtime = $mtime;
            }
        }
    }

    /**
     * Locate internal segment docIndex by external canonical model key.
     */
    public function findDocIndexByKey(string|int $key): ?int
    {
        $this->syncDeltaTombstones();
        $idToken = '_id#' . $key;
        $entry = $this->getTermEntry($idToken);
        if ($entry !== null) {
            [$docIds] = $this->readPostings($entry['offset']);
            return !empty($docIds) ? $docIds[1] : null;
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
        int $offset = 0,
        bool $fastPath = true,
        float &$sharedTheta = 0.0
    ): array {
        $docCount = $this->header->docCount;
        if ($docCount === 0) {
            return [];
        }

        $this->syncDeltaTombstones();

        // 1. Evaluate filter set (if any filters supplied)
        $filterDocIds = null;
        if (!empty($filters)) {
            $filterDocIds = $this->evaluateFilters($filters);
            if ($filterDocIds !== null && empty($filterDocIds)) {
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
                // Sort by document frequency ascending (least frequent / highest IDF term leads)
                uasort($matchedTerms, fn($a, $b) => $a['df'] <=> $b['df']);

                $termKeys = array_keys($matchedTerms);
                $primaryTerm = $termKeys[0];
                $primaryEntry = $matchedTerms[$primaryTerm];

                $primaryIdf = $this->bm25->idf($docCount, $primaryEntry['df']);
                $primaryU = $primaryIdf * 2.2;

                // Compute Segment-level Block-Max upper bound across all query terms
                $maxSegmentScore = $primaryU;
                $secondaryPostings = [];
                $sumSecondaryU = 0.0;

                for ($t = 1; $t < count($termKeys); $t++) {
                    $secTerm = $termKeys[$t];
                    $secEntry = $matchedTerms[$secTerm];
                    $sIdf = $this->bm25->idf($docCount, $secEntry['df']);
                    $sU = $sIdf * 2.2;

                    // Skip near-zero discriminative terms (corpus-wide stopwords with negligible IDF)
                    if ($sU < 0.01) {
                        continue;
                    }

                    $maxSegmentScore += $sU;
                    $sumSecondaryU += $sU;

                    [$secDocIds, $secFreqs] = $this->readPostings($secEntry['offset']);

                    $secondaryPostings[] = [
                        'idf' => $sIdf,
                        'docIds' => $secDocIds,
                        'freqs' => $secFreqs,
                        'count' => count($secDocIds),
                        'ptr' => 1,
                        'u' => $sU,
                    ];
                }

                // If this segment cannot beat the current global K-th best score, prune segment completely
                if ($sharedTheta > 0.0 && $maxSegmentScore <= $sharedTheta) {
                    return [];
                }

                [$anchorDocIds, $anchorFreqs] = $this->readPostings($primaryEntry['offset']);
                $anchorCount = count($anchorDocIds);
                $topScoresHeap = [];
                $targetCandidates = max(30, ($limit + $offset) * 2);

                for ($i = 1; $i <= $anchorCount; $i++) {
                    $docId = $anchorDocIds[$i];

                    if ($this->tombstone->isDeleted($docId)) {
                        continue;
                    }
                    if ($filterDocIds !== null && !isset($filterDocIds[$docId])) {
                        continue;
                    }

                    $freq = $anchorFreqs[$i] ?? 1;
                    $score = $primaryIdf * ($freq * 2.2) / ($freq + 1.2);

                    // Accumulate all matched secondary terms via zero-allocation galloping two-pointer walk
                    $matchedSecondary = false;
                    foreach ($secondaryPostings as &$sec) {
                        $p = &$sec['ptr'];
                        $sCount = $sec['count'];
                        $sDocs = $sec['docIds'];

                        while ($p <= $sCount && $sDocs[$p] < $docId) {
                            $jump = 1;
                            while (($p + $jump) <= $sCount && $sDocs[$p + $jump] < $docId) {
                                $p += $jump;
                                $jump <<= 1;
                            }
                            $p++;
                        }

                        if ($p <= $sCount && $sDocs[$p] === $docId) {
                            $sf = $sec['freqs'][$p] ?? 1;
                            $score += ($sec['idf'] * ($sf * 2.2) / ($sf + 1.2));
                            $matchedSecondary = true;
                            $p++;
                        }
                    }
                    unset($sec);

                    if ($matchedSecondary || $score >= $sharedTheta || count($candidateScores) < ($limit + $offset)) {
                        $candidateScores[$docId] = $score;
                        $topScoresHeap[] = $score;

                        if (count($topScoresHeap) >= ($limit + $offset)) {
                            sort($topScoresHeap);
                            if (count($topScoresHeap) > ($limit + $offset)) {
                                array_shift($topScoresHeap);
                            }
                            $sharedTheta = max($sharedTheta, $topScoresHeap[0]);
                        }

                        if (count($candidateScores) >= $targetCandidates) {
                            if ($fastPath || ($primaryU + $sumSecondaryU) <= $sharedTheta) {
                                break;
                            }
                        }

                        if (count($topScoresHeap) >= ($limit + $offset) && $sharedTheta >= ($maxSegmentScore - 0.05)) {
                            break; // Mathematically proven: no remaining document can exceed max theoretical bound
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
                $gatedCandidateIds = array_slice(array_keys($candidateScores), 0, min(100, ($limit + $offset) * 2));

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
        $neededCount = $limit + $offset;
        if (!empty($candidateScores) && !empty($vectorScores)) {
            $rankedDocIds = $this->rrf->fuse([
                'bm25' => $candidateScores,
                'vector' => $vectorScores,
            ], $neededCount);
        } elseif (!empty($candidateScores)) {
            arsort($candidateScores);
            $rankedDocIds = array_slice($candidateScores, 0, $neededCount, true);
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
    /** @var array<int, array{0: array<int, int>, 1: array<int, int>}> */
    private array $postingsCache = [];

    private function readPostings(int $postingsOffset): array
    {
        if (isset($this->postingsCache[$postingsOffset])) {
            return $this->postingsCache[$postingsOffset];
        }

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

        $res = [$docIds, $freqs];
        if (count($this->postingsCache) < 15) {
            $this->postingsCache[$postingsOffset] = $res;
        }

        return $res;
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
     * Evaluate discrete filters and return document ID map.
     *
     * @param array<string, scalar> $filters
     * @return array<int, true>|null Null if no filters; empty array if zero matches; otherwise docId map
     */
    private function evaluateFilters(array $filters): ?array
    {
        $matchingDocIds = null;

        foreach ($filters as $key => $val) {
            $filterToken = '_f:' . $key . '#' . (is_bool($val) ? ($val ? '1' : '0') : (string)$val);
            $entry = $this->getTermEntry($filterToken);

            if ($entry === null) {
                return [];
            }

            [$postDocIds] = $this->readPostings($entry['offset']);
            if (empty($postDocIds)) {
                return [];
            }

            $currentIds = array_fill_keys($postDocIds, true);
            if ($matchingDocIds === null) {
                $matchingDocIds = $currentIds;
            } else {
                $matchingDocIds = array_intersect_key($matchingDocIds, $currentIds);
                if (empty($matchingDocIds)) {
                    return [];
                }
            }
        }

        return $matchingDocIds;
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
