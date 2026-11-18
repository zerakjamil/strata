<?php

declare(strict_types=1);

namespace Strata\Engine\Lexical;

final class BM25Scorer
{
    public function __construct(
        private readonly float $k1 = 1.2,
        private readonly float $b = 0.75
    ) {}

    /**
     * Calculate Robertson-Sparck Jones Inverse Document Frequency (IDF).
     *
     * @param int $totalDocs Total document count in corpus (N)
     * @param int $docFreq Number of documents containing the term (df)
     * @return float
     */
    public function idf(int $totalDocs, int $docFreq): float
    {
        if ($totalDocs <= 0 || $docFreq <= 0) {
            return 0.0;
        }

        // Standard Lucene/BM25 smoothed IDF formula
        $numerator = ($totalDocs - $docFreq + 0.5);
        $denominator = ($docFreq + 0.5);
        $val = ($numerator / $denominator) + 1.0;

        return max(0.0, log($val));
    }

    /**
     * Calculate score contribution of a term for a document.
     *
     * @param float $idf Precomputed IDF of the term
     * @param int $termFreq Frequency of term in the document (tf)
     * @param int $docLength Length of the document in tokens (|D|)
     * @param float $avgDocLength Average document length in corpus (avgdl)
     * @return float
     */
    public function scoreTerm(float $idf, int $termFreq, int $docLength, float $avgDocLength): float
    {
        if ($termFreq <= 0 || $idf <= 0.0) {
            return 0.0;
        }

        $lengthNorm = 1.0 - $this->b + ($this->b * ($docLength / max(1.0, $avgDocLength)));
        $tfPart = ($termFreq * ($this->k1 + 1.0)) / ($termFreq + ($this->k1 * $lengthNorm));

        return $idf * $tfPart;
    }

    /**
     * Compute maximum theoretical score a term can contribute (for WAND pruning).
     *
     * @param float $idf
     * @return float
     */
    public function maxTermScore(float $idf): float
    {
        return $idf * ($this->k1 + 1.0);
    }
}
