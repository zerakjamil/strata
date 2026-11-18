<?php

declare(strict_types=1);

namespace Strata\Engine\Vector;

final class ScalarQuantizer
{
    private const SCALE = 127.0;

    /**
     * Quantize a dense float32 vector into a binary string of signed 8-bit integers (int8).
     * Automatically normalizes the vector to unit L2 length before quantization.
     *
     * @param array<float> $vector
     * @return string Packed binary string of int8 values
     */
    public function quantize(array $vector): string
    {
        $normalized = self::normalize($vector);
        $int8Values = [];

        foreach ($normalized as $val) {
            $clamped = max(-1.0, min(1.0, $val));
            $int8Values[] = (int) round($clamped * self::SCALE);
        }

        return pack('c*', ...$int8Values);
    }

    /**
     * Dequantize an int8 binary string back to an array of float values.
     *
     * @param string $bytes
     * @param int $dimensions
     * @return array<float>
     */
    public function dequantize(string $bytes, int $dimensions): array
    {
        $unpacked = unpack('c*', substr($bytes, 0, $dimensions));
        if ($unpacked === false) {
            return [];
        }

        $floats = [];
        foreach ($unpacked as $v) {
            $floats[] = $v / self::SCALE;
        }

        return $floats;
    }

    /**
     * Normalize vector to unit length (L2 norm).
     *
     * @param array<float> $vector
     * @return array<float>
     */
    public static function normalize(array $vector): array
    {
        $sumSq = 0.0;
        foreach ($vector as $v) {
            $sumSq += $v * $v;
        }

        $norm = sqrt($sumSq);
        if ($norm < 1e-12) {
            return $vector;
        }

        $result = [];
        foreach ($vector as $v) {
            $result[] = $v / $norm;
        }

        return $result;
    }
}
