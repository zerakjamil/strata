<?php

declare(strict_types=1);

namespace Strata\Commands;

use Illuminate\Console\Command;
use Strata\Engine\Binary\SegmentReader;
use Strata\Strata;

final class StrataStatusCommand extends Command
{
    protected $signature = 'strata:status';

    protected $description = 'Inspect active Strata segments, total indexed documents, and index storage metrics';

    public function handle(Strata $strata): int
    {
        $manifest = $strata->getManifest();
        $this->info("Strata Search Engine Status");
        $this->line("Storage Path: {$strata->getStoragePath()}");
        $this->line("Generation:   {$manifest->generation}");
        $this->line("Total Docs:   " . number_format($manifest->totalDocs));

        if (empty($manifest->segments)) {
            $this->warn("No active segments found in manifest.");
            return self::SUCCESS;
        }

        $rows = [];
        $totalBytes = 0;

        foreach ($manifest->segments as $idx => $segMeta) {
            $path = $segMeta['path'];
            $exists = file_exists($path);
            $size = $exists ? filesize($path) : 0;
            $totalBytes += $size;

            $sizeStr = $exists ? (round($size / (1024 * 1024), 2) . ' MB') : 'MISSING';
            $docCount = $segMeta['doc_count'] ?? 0;
            $level = $segMeta['level'] ?? 0;

            $rows[] = [
                basename($path),
                $level,
                number_format($docCount),
                $sizeStr,
                date('Y-m-d H:i:s', $segMeta['created_at'] ?? time()),
            ];
        }

        $this->newLine();
        $this->table(
            ['Segment File', 'Level', 'Documents', 'Size', 'Created At'],
            $rows
        );

        $totalMb = round($totalBytes / (1024 * 1024), 2);
        $this->info("Total Index Size: {$totalMb} MB across " . count($manifest->segments) . " segments.");

        return self::SUCCESS;
    }
}
