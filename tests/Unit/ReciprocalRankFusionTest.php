<?php

declare(strict_types=1);

namespace Strata\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Strata\Engine\Fusion\ReciprocalRankFusion;

final class ReciprocalRankFusionTest extends TestCase
{
    public function testRrfFusesRanksCorrectly(): void
    {
        $rrf = new ReciprocalRankFusion(k: 60);

        // Doc 101 is #1 in BM25, and #1 in vector -> should be highest overall
        // Doc 102 is #2 in BM25, not in vector top list
        // Doc 103 is #2 in vector, not in BM25 top list
        $bm25List = [
            101 => 12.5,
            102 => 8.0,
        ];

        $vectorList = [
            101 => 0.95,
            103 => 0.88,
        ];

        $fused = $rrf->fuse([
            'bm25' => $bm25List,
            'vector' => $vectorList,
        ], limit: 5);

        $keys = array_keys($fused);
        $this->assertSame(101, $keys[0]);
        // Doc 101 score = 1/(60+1) + 1/(60+1) = 2/61 ~ 0.03278
        $this->assertEqualsWithDelta(2.0 / 61.0, $fused[101], 0.0001);
    }
}
