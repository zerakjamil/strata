<?php

declare(strict_types=1);

namespace Strata;

use Strata\Engine\Binary\Manifest;
use Strata\Engine\Binary\SegmentReader;
use Strata\Scout\StrataBuilder;

final class Strata
{
    private string $storagePath;
    /** @var array<string, Manifest> */
    private array $manifests = [];
    /** @var array<string, SegmentReader> */
    private array $readers = [];

    public function __construct(array $config = [])
    {
        $this->storagePath = $config['path'] ?? (getcwd() . '/storage/strata');
    }

    /**
     * Start a fluent search query builder on an index or model.
     */
    public function index(string $indexName): StrataBuilder
    {
        return new StrataBuilder($this, $indexName);
    }

    /**
     * Search across all active segments for an index using Block-Max WAND pruning.
     *
     * @param string $index
     * @param string $query
     * @param string|null $queryVector
     * @param array<string, scalar> $filters
     * @param int $limit
     * @param bool $fastPath
     * @return array<int, array<string, mixed>>
     */
    public function search(
        string $index,
        string $query = '',
        ?string $queryVector = null,
        array $filters = [],
        int $limit = 20,
        bool $fastPath = true
    ): array {
        $manifest = $this->getManifest($index);
        $results = [];
        $theta = 0.0;
        $topScoresHeap = [];

        foreach ($manifest->segments as $segMeta) {
            $path = $segMeta['path'];
            if (!isset($this->readers[$path])) {
                if (!file_exists($path)) {
                    continue;
                }
                $this->readers[$path] = SegmentReader::open($path);
            }

            // Propagate dynamic Block-Max WAND score threshold ($theta) across segments
            $segResults = $this->readers[$path]->search(
                query: $query,
                queryVector: $queryVector,
                filters: $filters,
                limit: $limit,
                fastPath: $fastPath,
                sharedTheta: $theta
            );

            foreach ($segResults as $r) {
                $results[] = $r;
                $score = (float)($r['_score'] ?? 0.0);
                $topScoresHeap[] = $score;
                if (count($topScoresHeap) >= $limit) {
                    sort($topScoresHeap);
                    if (count($topScoresHeap) > $limit) {
                        array_shift($topScoresHeap);
                    }
                    $theta = max($theta, $topScoresHeap[0]);
                }
            }
        }

        // Global sort by _score descending
        usort($results, fn($a, $b) => ($b['_score'] ?? 0.0) <=> ($a['_score'] ?? 0.0));

        if ($limit > 0 && count($results) > $limit) {
            return array_slice($results, 0, $limit);
        }

        return $results;
    }

    /**
     * Tombstone documents by external model keys across all segments in an index.
     *
     * @param array<int|string> $keys
     * @param string $index
     * @return int Total documents marked deleted
     */
    public function deleteKeys(array $keys, string $index = 'default'): int
    {
        $deletedTotal = 0;
        $manifest = $this->getManifest($index);

        foreach ($manifest->segments as $segMeta) {
            $path = $segMeta['path'];
            if (!file_exists($path)) {
                continue;
            }

            if (!isset($this->readers[$path])) {
                $this->readers[$path] = SegmentReader::open($path);
            }

            $reader = $this->readers[$path];

            foreach ($keys as $key) {
                $internalDocId = $reader->findDocIndexByKey($key);
                if ($internalDocId !== null && !$reader->isDeleted($internalDocId)) {
                    $reader->markDeletedByDocId($internalDocId);
                    $deletedTotal++;
                }
            }
        }

        return $deletedTotal;
    }

    public function getManifest(string $index = 'default'): Manifest
    {
        if (!isset($this->manifests[$index])) {
            $indexPath = $this->getStoragePath($index);
            $manifestFile = $indexPath . '/manifest.json';

            if (file_exists($manifestFile)) {
                $this->manifests[$index] = Manifest::load($manifestFile);
            } else {
                // Fallback to root storage directory for backward compatibility with default benchmarks
                $rootManifest = $this->storagePath . '/manifest.json';
                if (file_exists($rootManifest)) {
                    $this->manifests[$index] = Manifest::load($rootManifest);
                } else {
                    $this->manifests[$index] = new Manifest();
                }
            }
        }

        return $this->manifests[$index];
    }

    public function getStoragePath(string $index = ''): string
    {
        if ($index === '' || $index === 'default') {
            return $this->storagePath;
        }

        return $this->storagePath . '/' . $index;
    }
}
