<?php

declare(strict_types=1);

namespace Strata\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Strata\Engine\Lexical\BM25Scorer;

final class BM25ScorerTest extends TestCase
{
    public function testIdfCalculation(): void
    {
        $scorer = new BM25Scorer();

        // Rare term in 1M corpus (appears in 10 docs) has high IDF
        $rareIdf = $scorer->idf(1000000, 10);
        // Common term in 1M corpus (appears in 500,000 docs) has lower IDF
        $commonIdf = $scorer->idf(1000000, 500000);

        $this->assertGreaterThan($commonIdf, $rareIdf);
    }

    public function testScoreTermIncreasesWithFrequency(): void
    {
        $scorer = new BM25Scorer();
        $idf = 5.0;
        $docLen = 20;
        $avgDocLen = 20.0;

        $scoreF1 = $scorer->scoreTerm($idf, 1, $docLen, $avgDocLen);
        $scoreF3 = $scorer->scoreTerm($idf, 3, $docLen, $avgDocLen);

        $this->assertGreaterThan($scoreF1, $scoreF3);
    }
}
