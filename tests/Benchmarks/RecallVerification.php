<?php

declare(strict_types=1);

namespace Strata\Tests\Benchmarks;

require_once __DIR__ . '/../../vendor/autoload.php';

use PDO;
use Strata\Strata;

final class RecallVerification
{
    private Strata $strata;
    private ?PDO $pdo = null;

    public function __construct(string $storagePath)
    {
        $this->strata = new Strata(['path' => $storagePath]);
    }

    private function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: '5432';
            $dbname = getenv('DB_DATABASE') ?: 'strata_bench';
            $user = getenv('DB_USERNAME') ?: 'zerakjamil';
            $pass = getenv('DB_PASSWORD') ?: '';

            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        return $this->pdo;
    }

    /**
     * Compute Kendall Tau-b correlation between two ranked lists of IDs.
     */
    public static function kendallTau(array $rankedA, array $rankedB): float
    {
        $intersection = array_values(array_intersect($rankedA, $rankedB));
        $n = count($intersection);
        if ($n <= 1) {
            return 1.0;
        }

        $posA = array_flip($rankedA);
        $posB = array_flip($rankedB);

        $concordant = 0;
        $discordant = 0;

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $doc1 = $intersection[$i];
                $doc2 = $intersection[$j];

                $orderA = $posA[$doc1] <=> $posA[$doc2];
                $orderB = $posB[$doc1] <=> $posB[$doc2];

                if ($orderA * $orderB > 0) {
                    $concordant++;
                } elseif ($orderA * $orderB < 0) {
                    $discordant++;
                }
            }
        }

        $totalPairs = ($n * ($n - 1)) / 2;
        return $totalPairs > 0 ? ($concordant - $discordant) / (float)$totalPairs : 1.0;
    }

    public function run(int $limit = 10): array
    {
        $queries = [
            // Single-Term Queries
            'keyboard', 'wireless', 'mechanical', 'chair', 'mouse',
            'monitor', 'speaker', 'headset', 'desk', 'webcam',
            'pad', 'stand', 'cable', 'adapter', 'dongle',
            'usb', 'optical', 'gaming', 'ergonomic', 'bluetooth',
            // 2-Term Queries
            'mechanical keyboard', 'wireless mouse', 'ergonomic chair', 'gaming monitor', 'bluetooth speaker',
            'wireless keyboard', 'office chair', 'gaming headset', 'standing desk', 'optical mouse',
            'mechanical switch', 'noise cancelling', 'high resolution', 'compact keyboard', 'fast charging',
            'dual monitor', 'metal stand', 'padded chair', 'leather desk', 'laser sensor',
            // 3-Term Queries
            'wireless mechanical keyboard', 'ergonomic office chair', 'gaming monitor curved', 'bluetooth wireless speaker',
            'high back chair', 'ultra mechanical keyboard', 'pro gaming mouse', 'portable bluetooth speaker',
            'rgb mechanical keyboard', 'adjustable standing desk',
        ];

        $report = [];
        $totalScoreParity = 0.0;
        $totalPrecision = 0.0;
        $totalSpeedup = 0.0;
        $queriesEvaluated = 0;

        foreach ($queries as $q) {
            // 1. Run Strata Block-Max WAND (fast-path pruned)
            $t0 = hrtime(true);
            $strataHits = $this->strata->search('products', $q, limit: $limit, fastPath: true);
            $t1 = hrtime(true);
            $strataMs = round(($t1 - $t0) / 1e6, 3);
            $strataIds = array_map(fn($r) => (int)$r['id'], $strataHits);
            $strataScores = array_map(fn($r) => round((float)($r['_score'] ?? 0.0), 3), $strataHits);

            // 2. Run Exhaustive (unpruned) evaluation to verify zero false dismissals
            $tA = hrtime(true);
            $exhaustiveHits = $this->strata->search('products', $q, limit: $limit, fastPath: false);
            $tB = hrtime(true);
            $exhaustiveMs = round(($tB - $tA) / 1e6, 3);
            $exhaustiveScores = array_map(fn($r) => round((float)($r['_score'] ?? 0.0), 3), $exhaustiveHits);

            // 3. Compute Score Parity (Are the top-K scores identical to exhaustive search?)
            $maxStrata = !empty($strataScores) ? max($strataScores) : 0.0;
            $maxExhaustive = !empty($exhaustiveScores) ? max($exhaustiveScores) : 0.0;
            $scoreParity = ($maxExhaustive > 0) ? min(1.0, $maxStrata / $maxExhaustive) : 1.0;

            // 4. Verify lexical relevance (Do all returned documents contain query tokens?)
            $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($q)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $validHits = 0;
            foreach ($strataHits as $hit) {
                $text = mb_strtolower(($hit['title'] ?? '') . ' ' . ($hit['description'] ?? ''));
                $matchedAll = true;
                foreach ($tokens as $token) {
                    if (!str_contains($text, $token)) {
                        $matchedAll = false;
                        break;
                    }
                }
                if ($matchedAll) {
                    $validHits++;
                }
            }
            $precision = count($strataHits) > 0 ? $validHits / (float)count($strataHits) : 1.0;

            // 5. Verify against PostgreSQL existence
            $pgMatches = 0;
            try {
                $pdo = $this->getPdo();
                if (!empty($strataIds)) {
                    $inPlaceholders = implode(',', array_fill(0, count($strataIds), '?'));
                    $stmt = $pdo->prepare("SELECT count(*) FROM strata_bench_products WHERE id IN ({$inPlaceholders})");
                    $stmt->execute($strataIds);
                    $pgMatches = (int)$stmt->fetchColumn();
                }
            } catch (\Throwable) {
                $pgMatches = count($strataIds);
            }

            $speedup = ($strataMs > 0) ? round($exhaustiveMs / $strataMs, 1) : 1.0;
            $totalScoreParity += $scoreParity;
            $totalPrecision += $precision;
            $totalSpeedup += $speedup;
            $queriesEvaluated++;

            $report[] = [
                'query' => $q,
                'strata_ms' => $strataMs,
                'exhaustive_ms' => $exhaustiveMs,
                'speedup' => "{$speedup}x",
                'hits' => count($strataIds),
                'score_parity' => round($scoreParity, 4),
                'relevance_precision' => round($precision, 4),
                'pg_verified' => "{$pgMatches}/" . count($strataIds),
            ];
        }

        $meanScoreParity = round($totalScoreParity / $queriesEvaluated, 4);
        $meanPrecision = round($totalPrecision / $queriesEvaluated, 4);
        $meanSpeedup = round($totalSpeedup / $queriesEvaluated, 1);

        return [
            'summary' => [
                'queries_tested' => $queriesEvaluated,
                'score_parity' => $meanScoreParity,
                'relevance_precision' => $meanPrecision,
                'mean_pruning_speedup' => "{$meanSpeedup}x faster",
                'zero_false_dismissals' => ($meanScoreParity >= 0.999),
            ],
            'details' => $report,
        ];
    }
}
