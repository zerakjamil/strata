<?php

declare(strict_types=1);

namespace Strata\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Strata\Engine\Vector\DotProduct;
use Strata\Engine\Vector\ScalarQuantizer;

final class ScalarQuantizerTest extends TestCase
{
    public function testQuantizationAndSimilarity(): void
    {
        $quantizer = new ScalarQuantizer();

        // 4-dimensional synthetic vectors
        $v1 = [1.0, 0.0, 0.0, 0.0];
        $v2 = [1.0, 0.0, 0.0, 0.0]; // Identical vector: cosine sim ~ 1.0
        $v3 = [0.0, 1.0, 0.0, 0.0]; // Orthogonal vector: cosine sim ~ 0.0

        $q1 = $quantizer->quantize($v1);
        $q2 = $quantizer->quantize($v2);
        $q3 = $quantizer->quantize($v3);

        $this->assertSame(4, strlen($q1));

        $simIdentical = DotProduct::similarity($q1, $q2, 4);
        $this->assertEqualsWithDelta(1.0, $simIdentical, 0.02);

        $simOrthogonal = DotProduct::similarity($q1, $q3, 4);
        $this->assertEqualsWithDelta(0.0, $simOrthogonal, 0.02);
    }
}
