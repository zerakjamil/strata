<?php

declare(strict_types=1);

namespace Strata\Engine\Binary;

use Strata\Contracts\TokenizerInterface;
use Strata\Engine\Fusion\ReciprocalRankFusion;
use Strata\Engine\Lexical\BM25Scorer;
use Strata\Engine\Lexical\SimpleTokenizer;
use Strata\Engine\Lexical\VariableByte;
use Strata\Engine\Storage\ColumnarDocStore;
use Strata\Engine\Storage\TombstoneBitmask;
use Strata\Engine\Vector\DotProduct;

final class SegmentReader
{
    private SegmentHeader $header;
    private string $data;
    private TombstoneBitmask $tombstone;
    private BM25Scorer $bm25;
    private TokenizerInterface $tokenizer;
    private ReciprocalRankFusion $rrf;

    // Parsed dictionary cache for fast in-memory query evaluation
    /** @var array<string, array{offset: int, df: int}> */
    private ?array $termDictCache = null;

    public function __construct(
        string $segmentBinary,
        ?BM25Scorer $bm25 = null,
        ?TokenizerInterface $tokenizer = null,
        ?ReciprocalRankFusion $rrf = null
    ) {
        $this->data = $segmentBinary;
        $this->header = SegmentHeader::fromBinary($this->data);
        $this->bm25 = $bm25 ?? new BM25Scorer();
        $this->tokenizer = $tokenizer ?? new SimpleTokenizer();
        $this->rrf = $rrf ?? new ReciprocalRankFusion();

        $tombstoneRaw = substr(
            $this->data,
            $this->header->tombstoneOffset,
            $this->header->tombstoneLength
        );
        $this->tombstone = new TombstoneBitmask($this->header->docCount, $tombstoneRaw);
    }

    /**
     * Create reader directly from a file path.
     */
    public static function open(string $filePath): self
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Segment file not found: {$filePath}");
        }

        $data = file_get_contents($filePath);
        if ($data === false) {
            throw new \RuntimeException("Failed to read segment file: {$filePath}");
        }

        return new self($data);
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
     * Execute a hybrid query against the segment.
     *
     * @param string $query Query text (keywords)
     * @param string|null $queryVector Packed int8 quantized vector (or null for pure lexical)
     * @param array<string, scalar> $filters Filter attributes (e.g. ['status' => 'active'])
     * @param int $limit Maximum results to return
     * @param bool $fastPath Whether to project Fast-Path JSON payloads directly from DocStore
     * @return array<int, array<string, mixed>>
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

        // 1. Evaluate filter bitset (if any filters provided)
        $filterBitmask = null;
        if (!empty($filters)) {
            $filterBitmask = $this->evaluateFilters($filters);
            if ($filterBitmask !== null && $filterBitmask->deletedCount() === $docCount) {
                return []; // No document matches the required filters
            }
        }

        // 2. Stage 1: Lexical BM25 Scoring
        $tokens = $this->tokenizer->tokenize($query);
        $candidateScores = []; // docId => bm25Score
        $avgDocLength = 25.0; // Standard average token length

        if (!empty($tokens)) {
            $this->loadTermDictionary();

            foreach ($tokens as $token) {
                if (!isset($this->termDictCache[$token])) {
                    continue;
                }

                $dictEntry = $this->termDictCache[$token];
                $postings = $this->readPostings($dictEntry['offset']);
                $idf = $this->bm25->idf($docCount, $dictEntry['df']);

                foreach ($postings as [$docId, $freq]) {
                    // Check tombstones and filters
                    if ($this->tombstone->isDeleted($docId)) {
                        continue;
                    }
                    if ($filterBitmask !== null && $filterBitmask->isDeleted($docId)) {
                        continue;
                    }

                    $score = $this->bm25->scoreTerm($idf, $freq, 20, $avgDocLength);
                    $candidateScores[$docId] = ($candidateScores[$docId] ?? 0.0) + $score;
                }
            }
        }

        // 3. Stage 2: Dense Vector Similarity (Two-Stage Candidate Gating)
        $vectorScores = [];
        if ($queryVector !== null && strlen($queryVector) >= $this->header->vectorDimension) {
            $dim = $this->header->vectorDimension;
            $vecSectionOffset = $this->header->vectorsOffset;

            // If we have lexical candidates, gate vector scoring to the top 500 lexical candidates
            if (!empty($candidateScores)) {
                arsort($candidateScores);
                $gatedCandidateIds = array_slice(array_keys($candidateScores), 0, 500);

                foreach ($gatedCandidateIds as $cId) {
                    $docVecOffset = $vecSectionOffset + ($cId * $dim);
                    $docVecBytes = substr($this->data, $docVecOffset, $dim);
                    $sim = DotProduct::similarity($queryVector, $docVecBytes, $dim);
                    $vectorScores[$cId] = $sim;
                }
            } else {
                // Pure vector search (no keywords): scan all non-deleted documents
                for ($dId = 0; $dId < $docCount; $dId++) {
                    if ($this->tombstone->isDeleted($dId)) {
                        continue;
                    }
                    if ($filterBitmask !== null && $filterBitmask->isDeleted($dId)) {
                        continue;
                    }

                    $docVecOffset = $vecSectionOffset + ($dId * $dim);
                    $docVecBytes = substr($this->data, $docVecOffset, $dim);
                    $sim = DotProduct::similarity($queryVector, $docVecBytes, $dim);
                    $vectorScores[$dId] = $sim;
                }
            }
        }

        // 4. Stage 3: Fusion or Single-Modality Selection
        $rankedDocIds = [];
        if (!empty($candidateScores) && !empty($vectorScores)) {
            // True Hybrid Fusion via RRF
            $rankedDocIds = $this->rrf->fuse([
                'bm25' => $candidateScores,
                'vector' => $vectorScores,
            ], $limit);
        } elseif (!empty($candidateScores)) {
            arsort($candidateScores);
            $rankedDocIds = array_slice($candidateScores, 0, $limit, true);
        } elseif (!empty($vectorScores)) {
            arsort($vectorScores);
            $rankedDocIds = array_slice($vectorScores, 0, $limit, true);
        }

        // 5. Materialize Results
        $results = [];
        $docStoreBinary = substr(
            $this->data,
            $this->header->docStoreOffset,
            $this->header->docStoreLength
        );

        foreach ($rankedDocIds as $docId => $score) {
            if ($fastPath) {
                $payload = ColumnarDocStore::read($docStoreBinary, $docId);
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
     * Read postings list at given byte offset.
     *
     * @return array<int, array{0: int, 1: int}> List of [docId, freq]
     */
    private function readPostings(int $postingsOffset): array
    {
        $absOffset = $this->header->postingsOffset + $postingsOffset;
        $docCount = unpack('V', substr($this->data, $absOffset, 4))[1];

        $cursor = $absOffset + 4;
        $docIds = VariableByte::decodeDeltas($this->data, $cursor, $docCount);
        $freqs = VariableByte::decode($this->data, $cursor, $docCount);

        $pairs = [];
        for ($i = 0; $i < $docCount; $i++) {
            $pairs[] = [$docIds[$i], $freqs[$i]];
        }

        return $pairs;
    }

    /**
     * Evaluate discrete filters using synthetic inverted index terms.
     */
    private function evaluateFilters(array $filters): TombstoneBitmask
    {
        $docCount = $this->header->docCount;
        $matchingMask = new TombstoneBitmask($docCount);
        // By default, invert so all docs are treated as non-matching (marked deleted)
        // and matching documents are un-marked.
        $this->loadTermDictionary();

        // For simplicity: compute intersection of matching doc IDs for each filter
        $matchingDocIds = null;

        foreach ($filters as $key => $val) {
            $filterToken = '_f:' . $key . '#' . (is_bool($val) ? ($val ? '1' : '0') : (string)$val);

            if (!isset($this->termDictCache[$filterToken])) {
                // Filter value does not exist at all in segment
                return new TombstoneBitmask($docCount, str_repeat("\xFF", (int)ceil($docCount / 8)));
            }

            $entry = $this->termDictCache[$filterToken];
            $postings = $this->readPostings($entry['offset']);
            $currentIds = [];
            foreach ($postings as [$dId, $f]) {
                $currentIds[$dId] = true;
            }

            if ($matchingDocIds === null) {
                $matchingDocIds = $currentIds;
            } else {
                $matchingDocIds = array_intersect_key($matchingDocIds, $currentIds);
            }
        }

        // Build excluded mask
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
        $dictData = substr(
            $this->data,
            $this->header->dictOffset,
            $this->header->dictLength
        );

        if (strlen($dictData) < 8) {
            return;
        }

        $termCount = unpack('V', substr($dictData, 0, 4))[1];
        $strideCount = unpack('V', substr($dictData, 4, 4))[1];

        // Stride pointers table length = strideCount * 4
        $entriesStart = 8 + ($strideCount * 4);
        $cursor = $entriesStart;
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
