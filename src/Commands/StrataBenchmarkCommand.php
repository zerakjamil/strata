<?php

declare(strict_types=1);

namespace Strata\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Strata\Facades\Strata;

final class StrataBenchmarkCommand extends Command
{
    protected $signature = 'strata:benchmark 
                            {--iterations=500 : Number of test queries to execute}
                            {--query=mechanical keyboard : Test search keyword}';

    protected $description = 'Benchmark head-to-head search latency between PostgreSQL GIN and Strata in-process search';

    public function handle(): int
    {
        $iterations = (int) $this->option('iterations');
        $queryText = (string) $this->option('query');

        $this->info("Starting Strata vs PostgreSQL Benchmark ({$iterations} iterations, warm cache)...");

        // Warm up cache for both engines
        $this->warmup($queryText);

        // 1. PostgreSQL tsvector + GIN Benchmark
        $pgLatencies = $this->benchmarkPostgresGin($queryText, $iterations);

        // 2. Strata In-Process Fast-Path Benchmark
        $strataLatencies = $this->benchmarkStrata($queryText, $iterations);

        // Render Results Table
        $this->renderReport($iterations, $pgLatencies, $strataLatencies);

        return self::SUCCESS;
    }

    private function warmup(string $query): void
    {
        for ($i = 0; $i < 5; $i++) {
            DB::select("
                SELECT id, title, price 
                FROM strata_bench_products 
                WHERE to_tsvector('english', title || ' ' || description) @@ plainto_tsquery('english', ?) 
                LIMIT 10
            ", [$query]);

            Strata::index('products')
                ->query($query)
                ->fast()
                ->limit(10)
                ->get();
        }
    }

    private function benchmarkPostgresGin(string $query, int $iterations): array
    {
        $latencies = [];

        for ($i = 0; $i < $iterations; $i++) {
            $t0 = hrtime(true);
            DB::select("
                SELECT id, title, price 
                FROM strata_bench_products 
                WHERE to_tsvector('english', title || ' ' || description) @@ plainto_tsquery('english', ?) 
                LIMIT 10
            ", [$query]);
            $t1 = hrtime(true);

            $latencies[] = ($t1 - $t0) / 1_000_000.0; // convert nanoseconds to milliseconds
        }

        return $latencies;
    }

    private function benchmarkStrata(string $query, int $iterations): array
    {
        $latencies = [];

        for ($i = 0; $i < $iterations; $i++) {
            $t0 = hrtime(true);
            Strata::index('products')
                ->query($query)
                ->fast()
                ->limit(10)
                ->get();
            $t1 = hrtime(true);

            $latencies[] = ($t1 - $t0) / 1_000_000.0;
        }

        return $latencies;
    }

    private function renderReport(int $iterations, array $pg, array $strata): void
    {
        sort($pg);
        sort($strata);

        $p50Index = (int) floor($iterations * 0.50);
        $p95Index = (int) floor($iterations * 0.95);
        $p99Index = (int) floor($iterations * 0.99);

        $pgP50 = round($pg[$p50Index], 3);
        $pgP95 = round($pg[$p95Index], 3);
        $pgP99 = round($pg[$p99Index], 3);
        $pgQps = round(1000.0 / max(0.001, array_sum($pg) / count($pg)));

        $strataP50 = round($strata[$p50Index], 3);
        $strataP95 = round($strata[$p95Index], 3);
        $strataP99 = round($strata[$p99Index], 3);
        $strataQps = round(1000.0 / max(0.001, array_sum($strata) / count($strata)));

        $speedup = ($strataP50 > 0) ? round($pgP50 / $strataP50, 1) : 0;

        $this->newLine();
        $this->table(
            ['Search Strategy / Engine', 'P50 Latency', 'P95 Latency', 'P99 Latency', 'Throughput'],
            [
                ['PostgreSQL (tsvector + GIN)', "{$pgP50} ms", "{$pgP95} ms", "{$pgP99} ms", "~{$pgQps} QPS"],
                ['Strata (In-Process Fast-Path)', "{$strataP50} ms", "{$strataP95} ms", "{$strataP99} ms", "~{$strataQps} QPS"],
            ]
        );

        $this->info("⚡ Strata is {$speedup}x faster than PostgreSQL GIN on P50 latency.");
        if ($strataP50 < 1.0) {
            $this->info("✓ Sub-millisecond target achieved (< 1ms P50 latency).");
        }
    }
}
