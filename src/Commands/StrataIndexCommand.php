<?php

declare(strict_types=1);

namespace Strata\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Strata\Engine\Binary\Manifest;
use Strata\Engine\Binary\SegmentWriter;
use Strata\Strata;

final class StrataIndexCommand extends Command
{
    protected $signature = 'strata:index 
                            {--table=strata_bench_products : Canonical database table to index}
                            {--segment-size=100000 : Maximum documents per segment file}';

    protected $description = 'Compile PostgreSQL table records into immutable Strata binary segments (.seg)';

    public function handle(Strata $strata): int
    {
        ini_set('memory_limit', '1024M');

        $table = (string) $this->option('table');
        $segmentSize = (int) $this->option('segment-size');

        $totalDocs = DB::table($table)->count();
        if ($totalDocs === 0) {
            $this->error("Table {$table} is empty. Run php artisan strata:seed first.");
            return self::FAILURE;
        }

        $this->info("Compiling {$totalDocs} records from {$table} into immutable binary segments...");

        $storageDir = $strata->getStoragePath();
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $manifest = new Manifest();
        $writer = new SegmentWriter();
        $startTime = microtime(true);
        $processed = 0;
        $segIndex = 1;

        $bar = $this->output->createProgressBar($totalDocs);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | Segments: %message% | Memory: %memory:6s%');
        $bar->setMessage("0");
        $bar->start();

        // Query using cursor to keep memory under 20MB
        $batch = [];

        foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
            $batch[] = [
                'id' => (int)$row->id,
                'text' => $row->title . ' ' . $row->description,
                'filters' => [
                    'status' => $row->status,
                    'in_stock' => (bool)$row->in_stock,
                ],
                'payload' => [
                    'id' => (int)$row->id,
                    'title' => $row->title,
                    'slug' => $row->slug,
                    'sku' => $row->sku,
                    'price' => (float)$row->price,
                    'status' => $row->status,
                    'in_stock' => (bool)$row->in_stock,
                ],
            ];

            $processed++;

            if (count($batch) >= $segmentSize) {
                $segFileName = sprintf("seg_%04d.seg", $segIndex++);
                $segPath = $storageDir . '/' . $segFileName;

                $header = $writer->write($segPath, $batch);
                $manifest->addSegment($segPath, $header->docCount, level: 1);

                $bar->setMessage((string)($segIndex - 1));
                $bar->advance(count($batch));
                $batch = [];
                unset($header);
                gc_collect_cycles();
            }
        }

        // Flush remaining records
        if (!empty($batch)) {
            $segFileName = sprintf("seg_%04d.seg", $segIndex++);
            $segPath = $storageDir . '/' . $segFileName;

            $header = $writer->write($segPath, $batch);
            $manifest->addSegment($segPath, $header->docCount, level: 0);
            $bar->advance(count($batch));
        }

        $manifest->save($storageDir . '/manifest.json');

        $bar->finish();
        $this->newLine();

        $duration = round(microtime(true) - $startTime, 2);
        $rate = round($totalDocs / max(0.01, $duration));

        $this->info("✓ Indexing complete: {$processed} documents compiled into " . ($segIndex - 1) . " segments in {$duration}s ({$rate} docs/sec).");

        return self::SUCCESS;
    }
}
