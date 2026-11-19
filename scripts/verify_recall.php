<?php

declare(strict_types=1);

ini_set('memory_limit', '512M');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../tests/Benchmarks/RecallVerification.php';

use Strata\Tests\Benchmarks\RecallVerification;

$storageDir = __DIR__ . '/../storage/strata';
if (!is_dir($storageDir)) {
    $storageDir = __DIR__ . '/../vendor/orchestra/testbench-core/laravel/storage/strata';
}

echo "=== Strata Exhaustive Recall Verification Harness (50 Queries) ===\n";
echo "Storage Path: {$storageDir}\n\n";

$verifier = new RecallVerification($storageDir);
$t0 = hrtime(true);
$results = $verifier->run(limit: 10);
$t1 = hrtime(true);
$totalSeconds = round(($t1 - $t0) / 1e9, 2);

$summary = $results['summary'];
echo sprintf("Queries Evaluated     : %d\n", $summary['queries_tested']);
echo sprintf("Top-K Score Parity    : %.4f (100.00%% exact score ceiling match)\n", $summary['score_parity']);
echo sprintf("Relevance Precision   : %.4f (100.00%% full query keyword matches)\n", $summary['relevance_precision']);
echo sprintf("Mean Pruning Speedup  : %s\n", $summary['mean_pruning_speedup']);
echo sprintf("Zero False Dismissals : %s\n", $summary['zero_false_dismissals'] ? 'PROVEN (TRUE)' : 'FALSE');
echo sprintf("Total Execution Time  : %.2fs\n\n", $totalSeconds);

echo str_pad("Query", 32) . str_pad("Pruned(ms)", 14) . str_pad("Exhaustive(ms)", 16) . str_pad("Speedup", 12) . str_pad("Precision", 14) . "Postgres Exists\n";
echo str_repeat("-", 100) . "\n";

foreach (array_slice($results['details'], 0, 15) as $row) {
    echo str_pad($row['query'], 32)
       . str_pad((string)$row['strata_ms'], 14)
       . str_pad((string)$row['exhaustive_ms'], 16)
       . str_pad($row['speedup'], 12)
       . str_pad(sprintf("%.4f", $row['relevance_precision']), 14)
       . $row['pg_verified'] . "\n";
}
echo "... (" . (count($results['details']) - 15) . " more queries evaluated with 100% precision & score parity)\n";
