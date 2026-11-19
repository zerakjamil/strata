<?php

declare(strict_types=1);

namespace Strata\Commands;

use Faker\Factory as Faker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

final class StrataSeedCommand extends Command
{
    protected $signature = 'strata:seed 
                            {--count= : Total number of synthetic records to insert}
                            {--target=2000000 : Ensure table reaches this total record count}
                            {--chunk=5000 : Batch size for multi-value SQL insertion}
                            {--drop : Drop and recreate benchmark table before seeding}';

    protected $description = 'Seed PostgreSQL with 2M synthetic product records via high-speed batched SQL inserts';

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');
        $shouldDrop = (bool) $this->option('drop');

        $this->info("Initializing PostgreSQL schema for Strata 2.0M Benchmark...");

        $this->ensureTableExists($shouldDrop);

        $existingCount = DB::table('strata_bench_products')->count();
        $target = $this->option('target') !== null ? (int) $this->option('target') : 2000000;
        $countOption = $this->option('count');

        if ($countOption !== null) {
            $total = (int) $countOption;
        } else {
            $total = max(0, $target - $existingCount);
        }

        if ($total === 0) {
            $this->info("✓ Table strata_bench_products already has {$existingCount} records (target: {$target}). Nothing to seed.");
            return self::SUCCESS;
        }

        $faker = Faker::create();
        $this->info("Current records: {$existingCount}. Seeding {$total} additional records in batches of {$chunkSize} to reach " . ($existingCount + $total) . "...");

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | Elapsed: %elapsed:6s% | Memory: %memory:6s%');
        $bar->start();

        $startTime = microtime(true);
        $inserted = 0;
        $offset = (int) (DB::table('strata_bench_products')->max('id') ?? 0);

        $categories = ['Electronics', 'Home & Kitchen', 'Office Supplies', 'Gaming', 'Ergonomics', 'Audio', 'Computing', 'Accessories'];
        $adjectives = ['Ultra', 'Ergonomic', 'Wireless', 'Pro', 'Compact', 'Mechanical', 'Smart', 'Precision', 'High-Speed', 'Studio', 'Elite'];
        $nouns = ['Keyboard', 'Mouse', 'Monitor', 'Desk', 'Chair', 'Headset', 'Microphone', 'Webcam', 'Speaker', 'Pad', 'Controller', 'Hub'];

        $pdo = DB::connection()->getPdo();

        while ($inserted < $total) {
            $currentChunk = min($chunkSize, $total - $inserted);
            $valuesSql = [];
            $params = [];

            for ($i = 0; $i < $currentChunk; $i++) {
                $idx = $offset + $inserted + $i + 1;
                $adj = $adjectives[array_rand($adjectives)];
                $noun = $nouns[array_rand($nouns)];
                $cat = $categories[array_rand($categories)];

                $title = "{$adj} {$noun} {$faker->word()} Edition";
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title) . '-' . $idx);
                $sku = 'SKU-' . str_pad((string)$idx, 8, '0', STR_PAD_LEFT);
                $desc = "High-quality {$cat} item designed for daily use with {$faker->catchPhrase()}.";
                $price = $faker->randomFloat(2, 19, 999);
                $status = ($i % 10 === 0) ? 'inactive' : 'active';
                $inStock = ($i % 5 !== 0) ? 'true' : 'false';

                $valuesSql[] = "(?, ?, ?, ?, ?, ?, ?)";
                $params[] = $title;
                $params[] = $slug;
                $params[] = $sku;
                $params[] = $desc;
                $params[] = $price;
                $params[] = $status;
                $params[] = $inStock;
            }

            $sql = "INSERT INTO strata_bench_products (title, slug, sku, description, price, status, in_stock) VALUES "
                . implode(', ', $valuesSql);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $inserted += $currentChunk;
            $bar->advance($currentChunk);
        }

        $bar->finish();
        $this->newLine();

        $duration = round(microtime(true) - $startTime, 2);
        $throughput = round($total / max(0.01, $duration));
        $finalCount = DB::table('strata_bench_products')->count();

        $this->info("✓ Successfully seeded {$total} records in {$duration}s ({$throughput} rows/sec). Total records in DB: {$finalCount}.");

        return self::SUCCESS;
    }

    private function ensureTableExists(bool $drop): void
    {
        if ($drop && Schema::hasTable('strata_bench_products')) {
            Schema::drop('strata_bench_products');
            $this->warn("Dropped existing strata_bench_products table.");
        }

        if (!Schema::hasTable('strata_bench_products')) {
            Schema::create('strata_bench_products', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('slug');
                $table->string('sku', 64);
                $table->text('description');
                $table->decimal('price', 10, 2);
                $table->string('status', 32)->default('active');
                $table->boolean('in_stock')->default(true);
                $table->timestamp('created_at')->useCurrent();
            });

            // Create GIN full-text search index for PostgreSQL baseline benchmark
            DB::statement("
                CREATE INDEX IF NOT EXISTS idx_products_fts 
                ON strata_bench_products 
                USING GIN (to_tsvector('english', title || ' ' || description))
            ");

            $this->info("Created table strata_bench_products with GIN full-text index.");
        }
    }
}
