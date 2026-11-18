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

    /** @var array<string, array{offset: int, df: int}> */
    private ?array $termDictCache = null;

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

    /**
     * Execute high-throughput hybrid search with early-exit candidate pruning.
     */
    public function search(
        string $query = '',
        ?string $queryVector = null,
        array $filters = [],
        int $limit = 20,
        bool $fastPath = true
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
            $this->loadTermDictionary();

            // Match available tokens in dictionary
            $matchedTerms = [];
            foreach ($tokens as $token) {
                if (isset($this->termDictCache[$token])) {
                    $matchedTerms[$token] = $this->termDictCache[$token];
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

                // Prepare secondary terms posting sets
                $secondaryPostings = [];
                for ($t = 1; $t < count($termKeys); $t++) {
                    $secTerm = $termKeys[$t];
                    $secEntry = $matchedTerms[$secTerm];
                    [$secDocIds, $secFreqs] = $this->readPostings($secEntry['offset']);
                    $secIdf = $this->bm25->idf($docCount, $secEntry['df']);

                    $docFreqMap = [];
                    $secCount = count($secDocIds);
                    for ($s = 1; $s <= $secCount; $s++) {
                        $docFreqMap[$secDocIds[$s]] = $secFreqs[$s] ?? 1;
                    }
                    $secondaryPostings[] = [
                        'idf' => $secIdf,
                        'map' => $docFreqMap,
                    ];
                }

                $anchorCount = count($anchorDocIds);
                $maxCandidatesToScore = max(50, $limit * 3);
                $collected = 0;

                for ($i = 1; $i <= $anchorCount; $i++) {
                    $docId = $anchorDocIds[$i];

                    if ($this->tombstone->isDeleted($docId)) {
                        continue;
                    }
                    if ($filterBitmask !== null && $filterBitmask->isDeleted($docId)) {
                        continue;
                    }

                    $freq = $anchorFreqs[$i] ?? 1;
                    // Inlined BM25 score formula (k1=1.2, b=0.75, avgdl=25)
                    $score = $primaryIdf * ($freq * 2.2) / ($freq + 1.2);

                    // Add contributions from secondary terms
                    foreach ($secondaryPostings as $sec) {
                        if (isset($sec['map'][$docId])) {
                            $sf = $sec['map'][$docId];
                            $score += $sec['idf'] * ($sf * 2.2) / ($sf + 1.2);
                        }
                    }

                    $candidateScores[$docId] = $score;
                    $collected++;

                    // Early exit once sufficient top candidates are gathered
                    if ($collected >= $maxCandidatesToScore) {
                        break;
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
        $this->loadTermDictionary();

        $matchingDocIds = null;

        foreach ($filters as $key => $val) {
            $filterToken = '_f:' . $key . '#' . (is_bool($val) ? ($val ? '1' : '0') : (string)$val);

            if (!isset($this->termDictCache[$filterToken])) {
                return new TombstoneBitmask($docCount, str_repeat("\xFF", (int)ceil($docCount / 8)));
            }

            $entry = $this->termDictCache[$filterToken];
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
     * Load term dictionary into in-memory hash table on first query.
     */
    private function loadTermDictionary(): void
    {
        if ($this->termDictCache !== null) {
            return;
        }

        $this->termDictCache = [];
        fseek($this->stream, $this->header->dictOffset);
        $dictData = (string) fread($this->stream, $this->header->dictLength);

        if (strlen($dictData) < 8) {
            return;
        }

        $termCount = unpack('V', substr($dictData, 0, 4))[1];
        $strideCount = unpack('V', substr($dictData, 4, 4))[1];

        $cursor = 8 + ($strideCount * 4);
        $totalLen = strlen($dictData);

        for ($i = 0; $i < $termCount && $cursor < $totalLen; $i++) {
            $termLen = ord($dictData[$cursor++]);
            $term = substr($dictData, $cursor, $termLen);
            $cursor += $termLen;

            $offset = unpack('V', substr($dictData, $cursor, 4))[1];
            $cursor += 4;
            $df = unpack('V', substr($dictData, $cursor, 4))[1];
            $cursor += 4;

            $this->termDictCache[$term] = [
                'offset' => $offset,
                'df' => $df,
            ];
        }
    }
}
