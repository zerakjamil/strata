<?php

declare(strict_types=1);

namespace Strata\Engine\Storage;

final class ColumnarDocStore
{
    /**
     * Compile an array of document payloads into a compact binary DocStore payload.
     * Binary structure:
     * - DocCount: uint32 (4 bytes)
     * - Offsets: uint32[DocCount + 1] (4 bytes each, pointing to start of JSON byte slices)
     * - Data: raw concatenated JSON strings
     *
     * @param array<int, array<string, mixed>|string> $documents Array of [docId => data]
     * @return string
     */
    public static function compile(array $documents): string
    {
        $docCount = count($documents);
        $data = '';
        $offsets = [];
        $currentOffset = 0;

        for ($i = 0; $i < $docCount; $i++) {
            $offsets[] = $currentOffset;
            $raw = $documents[$i] ?? [];
            $json = is_string($raw) ? $raw : json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $data .= $json;
            $currentOffset += strlen($json);
        }
        $offsets[] = $currentOffset; // Terminal offset to calculate last item length

        $header = pack('V', $docCount);
        $offsetBytes = pack('V*', ...$offsets);

        return $header . $offsetBytes . $data;
    }

    /**
     * Read a document's JSON slice directly from a compiled DocStore binary buffer.
     *
     * @param string $binary
     * @param int $docId
     * @return string|null Raw JSON string or null if not found
     */
    public static function readJson(string $binary, int $docId): ?string
    {
        $len = strlen($binary);
        if ($len < 4) {
            return null;
        }

        $docCount = unpack('V', substr($binary, 0, 4))[1];
        if ($docId < 0 || $docId >= $docCount) {
            return null;
        }

        // Each offset is 4 bytes. Offsets table starts at byte 4.
        $offsetPos = 4 + ($docId * 4);
        if ($offsetPos + 8 > $len) {
            return null;
        }

        $startOffset = unpack('V', substr($binary, $offsetPos, 4))[1];
        $endOffset = unpack('V', substr($binary, $offsetPos + 4, 4))[1];

        $dataStart = 4 + (($docCount + 1) * 4);
        $sliceLength = $endOffset - $startOffset;

        if ($sliceLength <= 0 || ($dataStart + $startOffset + $sliceLength) > $len) {
            return '';
        }

        return substr($binary, $dataStart + $startOffset, $sliceLength);
    }

    /**
     * Read and decode a document's JSON slice into an associative array.
     *
     * @param string $binary
     * @param int $docId
     * @return array<string, mixed>|null
     */
    public static function read(string $binary, int $docId): ?array
    {
        $json = self::readJson($binary, $docId);
        if ($json === null || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }
}
