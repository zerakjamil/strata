<?php

declare(strict_types=1);

namespace Strata\Commands;

use Illuminate\Console\Command;
use Strata\Engine\Binary\Manifest;
use Strata\Engine\Binary\SegmentReader;
use Strata\Engine\Binary\SegmentWriter;
use Strata\Strata;

final class StrataCompactCommand extends Command
{
    protected $signature = 'strata:compact 
                            {--target-level=2 : Target merge level for consolidated master segment}';

    protected $description = 'Consolidate fragmented segments into a single unified master segment for sub-millisecond retrieval';

    public function handle(Strata $strata): int
    {
        ini_set('memory_limit', '2048M');

        $manifest = $strata->getManifest();
        $segmentCount = count($manifest->segments);

        if ($segmentCount <= 1) {
            $this->info("Index already compacted into a single segment. No compaction required.");
            return self::SUCCESS;
        }

        $this->info("Compacting {$segmentCount} segments ({$manifest->totalDocs} total documents) into unified master segment...");

        $storageDir = $strata->getStoragePath();
        $startTime = microtime(true);
        $consolidatedDocs = [];
        $totalMerged = 0;

        $bar = $this->output->createProgressBar($manifest->totalDocs);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | Elapsed: %elapsed:6s% | Memory: %memory:6s%');
        $bar->start();

        foreach ($manifest->segments as $segMeta) {
            $path = $segMeta['path'];
            if (!file_exists($path)) {
                continue;
            }

            $reader = SegmentReader::open($path);
            $header = $reader->getHeader();
            $docCount = $header->docCount;

            for ($d = 0; $d < $docCount; $d++) {
                if ($reader->isDeleted($d)) {
                    continue; // Skip tombstoned documents to prevent resurrection
                }

                // Read record payload
                $ref = new \ReflectionMethod($reader, 'readDocStoreRecord');
                $ref->setAccessible(true);
                $payload = $ref->invoke($reader, $d);

                if ($payload === null) {
                    continue;
                }

                $title = $payload['title'] ?? '';
                $desc = $payload['description'] ?? '';
                $text = trim($title . ' ' . $desc);
                if ($text === '') {
                    $text = $title;
                }

                $filters = [];
                if (isset($payload['status'])) {
                    $filters['status'] = $payload['status'];
                }
                if (isset($payload['in_stock'])) {
                    $filters['in_stock'] = (bool)$payload['in_stock'];
                }

                $consolidatedDocs[] = [
                    'id' => $payload['id'] ?? ($totalMerged + 1),
                    'text' => $text,
                    'filters' => $filters,
                    'payload' => $payload,
                ];

                $totalMerged++;
                $bar->advance();
            }

            unset($reader);
        }

        $bar->finish();
        $this->newLine();

        $this->info("Writing consolidated master segment file...");
        $masterSegPath = $storageDir . '/seg_master_' . time() . '.seg';
        $writer = new SegmentWriter();
        $masterHeader = $writer->write($masterSegPath, $consolidatedDocs);

        // Delete old fragmented segments
        foreach ($manifest->segments as $segMeta) {
            if (file_exists($segMeta['path']) && $segMeta['path'] !== $masterSegPath) {
                @unlink($segMeta['path']);
            }
        }

        // Atomically update manifest
        $newManifest = new Manifest();
        $newManifest->addSegment($masterSegPath, $masterHeader->docCount, level: (int)$this->option('target-level'));
        $newManifest->save($storageDir . '/manifest.json');

        $duration = round(microtime(true) - $startTime, 2);
        $this->info("✓ Compaction completed: Consolidated {$totalMerged} documents into {$masterSegPath} in {$duration}s.");

        return self::SUCCESS;
    }
}
