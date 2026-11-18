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
                            {--count=1500000 : Total number of synthetic records to generate}
                            {--chunk=5000 : Batch size for multi-value SQL insertion}
                            {--drop : Drop and recreate benchmark table before seeding}';

    protected $description = 'Seed PostgreSQL with 1.5M synthetic product records via high-speed batched SQL inserts';

    public function handle(): int
    {
        $total = (int) $this->option('count');
        $chunkSize = (int) $this->option('chunk');
        $shouldDrop = (bool) $this->option('drop');

        $this->info("Initializing PostgreSQL schema for Strata 1.5M Benchmark...");

        $this->ensureTableExists($shouldDrop);

        $faker = Faker::create();
        $this->info("Generating {$total} records in batches of {$chunkSize}...");

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | Elapsed: %elapsed:6s% | Memory: %memory:6s%');
        $bar->start();

        $startTime = microtime(true);
        $inserted = 0;

        $categories = ['Electronics', 'Home & Kitchen', 'Office Supplies', 'Gaming', 'Ergonomics', 'Audio'];
        $adjectives = ['Ultra', 'Ergonomic', 'Wireless', 'Pro', 'Compact', 'Mechanical', 'Smart', 'Precision', 'High-Speed'];
        $nouns = ['Keyboard', 'Mouse', 'Monitor', 'Desk', 'Chair', 'Headset', 'Microphone', 'Webcam', 'Speaker', 'Pad'];

        $pdo = DB::connection()->getPdo();

        while ($inserted < $total) {
            $currentChunk = min($chunkSize, $total - $inserted);
            $valuesSql = [];
            $params = [];

            for ($i = 0; $i < $currentChunk; $i++) {
                $adj = $adjectives[array_rand($adjectives)];
                $noun = $nouns[array_rand($nouns)];
                $cat = $categories[array_rand($categories)];

                $title = "{$adj} {$noun} {$faker->word()} Edition";
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title) . '-' . ($inserted + $i + 1));
                $sku = 'SKU-' . strtoupper(bin2hex(random_bytes(4)));
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

        $this->info("✓ Successfully seeded {$total} records in {$duration}s ({$throughput} rows/sec).");

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
