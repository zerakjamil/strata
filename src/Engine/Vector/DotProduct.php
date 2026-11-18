<?php

declare(strict_types=1);

namespace Strata\Engine\Vector;

final class DotProduct
{
    private const SCALE_SQ = 16129.0; // 127.0 * 127.0

    /**
     * Compute cosine similarity between two int8 binary vectors of equal length.
     *
     * @param string $v1 Binary packed int8 vector
     * @param string $v2 Binary packed int8 vector
     * @param int $dimensions Number of vector dimensions
     * @return float Estimated cosine similarity between -1.0 and 1.0
     */
    public static function similarity(string $v1, string $v2, int $dimensions): float
    {
        $a = unpack('c*', substr($v1, 0, $dimensions));
        $b = unpack('c*', substr($v2, 0, $dimensions));

        if ($a === false || $b === false) {
            return 0.0;
        }

        $dot = 0;
        // Fast 4x unrolled dot-product loop
        $limit = $dimensions - ($dimensions % 4);
        for ($i = 1; $i <= $limit; $i += 4) {
            $dot += ($a[$i] * $b[$i])
                  + ($a[$i + 1] * $b[$i + 1])
                  + ($a[$i + 2] * $b[$i + 2])
                  + ($a[$i + 3] * $b[$i + 3]);
        }

        for ($i = $limit + 1; $i <= $dimensions; $i++) {
            $dot += $a[$i] * $b[$i];
        }

        return $dot / self::SCALE_SQ;
    }
}
