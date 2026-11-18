<?php

declare(strict_types=1);

namespace Strata\Engine\Fusion;

final class ReciprocalRankFusion
{
    public function __construct(
        private readonly int $k = 60
    ) {}

    /**
     * Merge multiple ranked result lists into a single ranked list using Reciprocal Rank Fusion.
     *
     * @param array<string, array<int, float>> $rankedLists Array of [rankingName => [docId => rawScore, ...]]
     * @param int $limit Maximum number of results to return
     * @return array<int, float> Map of [docId => rrfScore], sorted descending by rrfScore
     */
    public function fuse(array $rankedLists, int $limit = 20): array
    {
        $rrfScores = [];

        foreach ($rankedLists as $list) {
            // Sort list by score descending to get ranks
            arsort($list);
            $rank = 1;

            foreach ($list as $docId => $score) {
                if (!isset($rrfScores[$docId])) {
                    $rrfScores[$docId] = 0.0;
                }

                $rrfScores[$docId] += 1.0 / ($this->k + $rank);
                $rank++;
            }
        }

        arsort($rrfScores);

        if ($limit > 0 && count($rrfScores) > $limit) {
            return array_slice($rrfScores, 0, $limit, true);
        }

        return $rrfScores;
    }
}
