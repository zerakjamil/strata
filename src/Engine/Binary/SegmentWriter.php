<?php

declare(strict_types=1);

namespace Strata\Engine\Binary;

use Strata\Contracts\TokenizerInterface;
use Strata\Engine\Lexical\SimpleTokenizer;
use Strata\Engine\Lexical\VariableByte;
use Strata\Engine\Storage\ColumnarDocStore;
use Strata\Engine\Storage\TombstoneBitmask;
use Strata\Engine\Vector\ScalarQuantizer;

final class SegmentWriter
{
    private readonly TokenizerInterface $tokenizer;
    private readonly ScalarQuantizer $quantizer;

    public function __construct(
        ?TokenizerInterface $tokenizer = null,
        ?ScalarQuantizer $quantizer = null
    ) {
        $this->tokenizer = $tokenizer ?? new SimpleTokenizer();
        $this->quantizer = $quantizer ?? new ScalarQuantizer();
    }

    /**
     * Write an array of documents to a binary .seg file.
     *
     * @param string $filePath Destination file path
     * @param array<int, array{
     *     id: int|string,
     *     text: string,
     *     vector?: array<float>,
     *     payload?: array<string, mixed>,
     *     filters?: array<string, scalar>
     * }> $documents
     * @param int $vectorDimension
     * @return SegmentHeader
     */
    public function write(string $filePath, array $documents, int $vectorDimension = 384): SegmentHeader
    {
        $docCount = count($documents);
        $termPostings = []; // term => [ [docIndex, freq], ... ]
        $docPayloads = [];
        $vectorMatrix = '';

        for ($docIndex = 0; $docIndex < $docCount; $docIndex++) {
            $doc = $documents[$docIndex];

            // 1. DocStore payload
            $payload = $doc['payload'] ?? [];
            if (!isset($payload['id'])) {
                $payload['id'] = $doc['id'];
            }
            $docPayloads[$docIndex] = $payload;

            // 2. Lexical tokens
            $tokens = $this->tokenizer->tokenize($doc['text'] ?? '');
            $termFreqs = array_count_values($tokens);

            // 3. Synthetic Filter tokens (for zero-cost filter matching)
            if (isset($doc['filters']) && is_array($doc['filters'])) {
                foreach ($doc['filters'] as $key => $val) {
                    $filterToken = '_f:' . $key . '#' . (is_bool($val) ? ($val ? '1' : '0') : (string)$val);
                    $termFreqs[$filterToken] = 1;
                }
            }

            foreach ($termFreqs as $term => $freq) {
                $termPostings[$term][] = [$docIndex, $freq];
            }

            // 4. Vector quantization
            if (!empty($doc['vector'])) {
                $vectorMatrix .= $this->quantizer->quantize($doc['vector']);
            } else {
                $vectorMatrix .= str_repeat("\0", $vectorDimension);
            }
        }

        // Sort terms alphabetically for binary search
        ksort($termPostings, SORT_STRING);
        $termCount = count($termPostings);

        // Compile Postings Block and Term Dictionary
        $postingsBinary = '';
        $dictEntries = []; // [term, postingsOffset, docFreq]
        $currentPostingsOffset = 0;

        foreach ($termPostings as $term => $postings) {
            $docIds = [];
            $freqs = [];
            foreach ($postings as [$dId, $f]) {
                $docIds[] = (int) $dId;
                $freqs[] = min(255, (int) $f);
            }

            $encodedDocIds = pack('V*', ...$docIds);
            $encodedFreqs = pack('C*', ...$freqs);
            $postingChunk = pack('V', count($docIds)) . $encodedDocIds . $encodedFreqs;

            $dictEntries[] = [
                'term' => (string) $term,
                'offset' => $currentPostingsOffset,
                'df' => count($docIds),
            ];

            $postingsBinary .= $postingChunk;
            $currentPostingsOffset += strlen($postingChunk);
        }

        // Compile Dictionary Block with Stride Index (1 stride pointer every 64 terms)
        $dictBinary = self::compileDictionary($dictEntries);

        // Compile DocStore
        $docStoreBinary = ColumnarDocStore::compile($docPayloads);

        // Compile Tombstone Bitmask
        $tombstone = new TombstoneBitmask($docCount);
        $tombstoneBinary = $tombstone->toBinary();

        // Calculate 64-bit offsets and lengths
        $currentOffset = SegmentHeader::HEADER_SIZE;

        $dictOffset = $currentOffset;
        $dictLength = strlen($dictBinary);
        $currentOffset += $dictLength;

        $postingsOffset = $currentOffset;
        $postingsLength = strlen($postingsBinary);
        $currentOffset += $postingsLength;

        $vectorsOffset = $currentOffset;
        $vectorsLength = strlen($vectorMatrix);
        $currentOffset += $vectorsLength;

        $docStoreOffset = $currentOffset;
        $docStoreLength = strlen($docStoreBinary);
        $currentOffset += $docStoreLength;

        $tombstoneOffset = $currentOffset;
        $tombstoneLength = strlen($tombstoneBinary);

        $header = new SegmentHeader(
            docCount: $docCount,
            deletedCount: 0,
            termCount: $termCount,
            vectorDimension: $vectorDimension,
            dictOffset: $dictOffset,
            dictLength: $dictLength,
            postingsOffset: $postingsOffset,
            postingsLength: $postingsLength,
            vectorsOffset: $vectorsOffset,
            vectorsLength: $vectorsLength,
            docStoreOffset: $docStoreOffset,
            docStoreLength: $docStoreLength,
            tombstoneOffset: $tombstoneOffset,
            tombstoneLength: $tombstoneLength
        );

        $fullBinary = $header->toBinary()
            . $dictBinary
            . $postingsBinary
            . $vectorMatrix
            . $docStoreBinary
            . $tombstoneBinary;

        // Atomic file write using temp file + rename
        $tempPath = $filePath . '.tmp.' . bin2hex(random_bytes(4));
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($tempPath, $fullBinary);
        rename($tempPath, $filePath);

        return $header;
    }

    /**
     * Compile term dictionary with stride index.
     *
     * Format:
     * - TermCount: uint32 (4 bytes)
     * - StrideCount: uint32 (4 bytes)
     * - StridePointers: uint32[StrideCount] (offsets in dictionary entries block)
     * - Entries: list of [termLen: uint8, term: string, postingsOffset: uint32, df: uint32]
     *
     * @param array<int, array{term: string, offset: int, df: int}> $entries
     * @return string
     */
    private static function compileDictionary(array $entries): string
    {
        $termCount = count($entries);
        $strideStep = 64;
        $stridePointers = [];
        $entriesBinary = '';

        for ($i = 0; $i < $termCount; $i++) {
            if ($i % $strideStep === 0) {
                $stridePointers[] = strlen($entriesBinary);
            }

            $e = $entries[$i];
            $termBytes = (string) $e['term'];
            $termLen = min(255, strlen($termBytes));

            $entriesBinary .= pack('C', $termLen)
                . substr($termBytes, 0, $termLen)
                . pack('V', $e['offset'])
                . pack('V', $e['df']);
        }

        $strideCount = count($stridePointers);
        $header = pack('V', $termCount) . pack('V', $strideCount);
        $strides = pack('V*', ...$stridePointers);

        return $header . $strides . $entriesBinary;
    }
}
