<?php

declare(strict_types=1);

namespace Strata\Engine\Lexical;

/**
 * High-performance Variable-Byte (VByte) codec for compressing integer arrays.
 * Used for delta-compressed posting lists (document IDs and term frequencies).
 */
final class VariableByte
{
    /**
     * Encode an array of positive integers into a variable-byte binary string.
     *
     * @param array<int> $numbers
     * @return string
     */
    public static function encode(array $numbers): string
    {
        $bytes = '';
        foreach ($numbers as $n) {
            while ($n >= 128) {
                $bytes .= chr(($n & 0x7F) | 0x80);
                $n >>= 7;
            }
            $bytes .= chr($n & 0x7F);
        }

        return $bytes;
    }

    /**
     * Decode a variable-byte binary string into an array of integers.
     *
     * @param string $bytes
     * @param int $offset Reference offset where reading begins and updates
     * @param int $count Number of integers to decode
     * @return array<int>
     */
    public static function decode(string $bytes, int &$offset, int $count): array
    {
        $numbers = [];
        $len = strlen($bytes);

        for ($i = 0; $i < $count && $offset < $len; $i++) {
            $num = 0;
            $shift = 0;

            while ($offset < $len) {
                $b = ord($bytes[$offset++]);
                $num |= ($b & 0x7F) << $shift;
                if (($b & 0x80) === 0) {
                    break;
                }
                $shift += 7;
            }

            $numbers[] = $num;
        }

        return $numbers;
    }

    /**
     * Encode an array of strictly increasing document IDs using delta-encoding + VByte.
     *
     * @param array<int> $docIds
     * @return string
     */
    public static function encodeDeltas(array $docIds): string
    {
        $deltas = [];
        $prev = 0;
        foreach ($docIds as $id) {
            $deltas[] = $id - $prev;
            $prev = $id;
        }

        return self::encode($deltas);
    }

    /**
     * Decode an array of delta-encoded document IDs.
     *
     * @param string $bytes
     * @param int $offset
     * @param int $count
     * @return array<int>
     */
    public static function decodeDeltas(string $bytes, int &$offset, int $count): array
    {
        $deltas = self::decode($bytes, $offset, $count);
        $docIds = [];
        $accum = 0;
        foreach ($deltas as $d) {
            $accum += $d;
            $docIds[] = $accum;
        }

        return $docIds;
    }
}
