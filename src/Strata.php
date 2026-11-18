<?php

declare(strict_types=1);

namespace Strata;

use Strata\Engine\Binary\Manifest;
use Strata\Engine\Binary\SegmentReader;
use Strata\Scout\StrataBuilder;

final class Strata
{
    private string $storagePath;
    private Manifest $manifest;
    /** @var array<string, SegmentReader> */
    private array $readers = [];

    public function __construct(array $config = [])
    {
        $this->storagePath = $config['path'] ?? (getcwd() . '/storage/strata');
        $this->manifest = Manifest::load($this->storagePath . '/manifest.json');
    }

    /**
     * Start a fluent search query builder on an index or model.
     */
    public function index(string $indexName): StrataBuilder
    {
        return new StrataBuilder($this, $indexName);
    }

    /**
     * Search across all active segments for an index.
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
        $results = [];

        foreach ($this->manifest->segments as $segMeta) {
            $path = $segMeta['path'];
            if (!isset($this->readers[$path])) {
                if (!file_exists($path)) {
                    continue;
                }
                $this->readers[$path] = SegmentReader::open($path);
            }

            $segResults = $this->readers[$path]->search(
                query: $query,
                queryVector: $queryVector,
                filters: $filters,
                limit: $limit,
                fastPath: $fastPath
            );

            foreach ($segResults as $r) {
                $results[] = $r;
            }
        }

        // Global sort by _score descending
        usort($results, fn($a, $b) => ($b['_score'] ?? 0.0) <=> ($a['_score'] ?? 0.0));

        if ($limit > 0 && count($results) > $limit) {
            return array_slice($results, 0, $limit);
        }

        return $results;
    }

    public function getManifest(): Manifest
    {
        return $this->manifest;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }
}
