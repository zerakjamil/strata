<?php

declare(strict_types=1);

namespace Strata\Scout;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Strata\Engine\Binary\SegmentWriter;
use Strata\Strata;

final class StrataEngine extends Engine
{
    public function __construct(
        private readonly Strata $strata
    ) {}

    /**
     * Update the given model in the index.
     *
     * @param Collection<int, Model> $models
     */
    public function update($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $documents = [];
        $indexName = $models->first()->searchableAs();

        foreach ($models as $model) {
            if (empty($searchableData = $model->toSearchableArray())) {
                continue;
            }

            // Extract searchable text from array
            $textParts = [];
            foreach ($searchableData as $val) {
                if (is_string($val)) {
                    $textParts[] = $val;
                }
            }

            $vector = method_exists($model, 'toSearchableVector')
                ? $model->toSearchableVector()
                : null;

            $filters = method_exists($model, 'toSearchableFilters')
                ? $model->toSearchableFilters()
                : [];

            $documents[] = [
                'id' => $model->getScoutKey(),
                'text' => implode(' ', $textParts),
                'vector' => $vector,
                'filters' => $filters,
                'payload' => $searchableData,
            ];
        }

        if (empty($documents)) {
            return;
        }

        $keys = $models->map(fn($m) => $m->getScoutKey())->all();

        $updateAction = function () use ($keys, $indexName, $documents): void {
            // Tombstone previous versions in existing segments to prevent duplicates
            $this->strata->deleteKeys($keys, $indexName);

            $writer = new SegmentWriter();
            $storageDir = $this->strata->getStoragePath($indexName);
            if (!is_dir($storageDir)) {
                mkdir($storageDir, 0755, true);
            }
            $segName = 'seg_' . time() . '_' . bin2hex(random_bytes(3)) . '.seg';
            $segPath = $storageDir . '/' . $segName;

            $writer->write($segPath, $documents);

            $manifest = $this->strata->getManifest($indexName);
            $manifest->addSegment($segPath, count($documents), level: 0);
            $manifest->save($storageDir . '/manifest.json');
        };

        $firstModel = $models->first();
        $conn = null;
        try {
            if (is_object($firstModel) && method_exists($firstModel, 'getConnection')) {
                $conn = $firstModel->getConnection();
            }
        } catch (\Throwable) {
            $conn = null;
        }

        if ($conn === null && class_exists(DB::class)) {
            try {
                $conn = DB::connection();
            } catch (\Throwable) {
                $conn = null;
            }
        }

        if ($conn !== null && method_exists($conn, 'transactionLevel') && $conn->transactionLevel() > 0) {
            $conn->afterCommit($updateAction);
        } else {
            $updateAction();
        }
    }

    /**
     * Remove the given model from the index via atomic tombstone bitmasks.
     * Transaction-aware: automatically defers flush until DB::afterCommit if in an active transaction.
     *
     * @param Collection<int, Model> $models
     */
    public function delete($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $keys = $models->map(fn($m) => $m->getScoutKey())->all();
        $indexName = $models->first()->searchableAs();

        $action = function () use ($keys, $indexName) {
            $this->strata->deleteKeys($keys, $indexName);
        };

        $firstModel = $models->first();
        $conn = null;
        try {
            if (is_object($firstModel) && method_exists($firstModel, 'getConnection')) {
                $conn = $firstModel->getConnection();
            }
        } catch (\Throwable) {
            $conn = null;
        }

        if ($conn === null && class_exists(DB::class)) {
            try {
                $conn = DB::connection();
            } catch (\Throwable) {
                $conn = null;
            }
        }

        if ($conn !== null && method_exists($conn, 'transactionLevel') && $conn->transactionLevel() > 0) {
            $conn->afterCommit($action);
        } else {
            $action();
        }
    }

    /**
     * Perform the given search on the engine.
     */
    public function search(Builder $builder): array
    {
        return $this->performSearch($builder, array_filter([
            'limit' => $builder->limit,
        ]));
    }

    /**
     * Perform the given search on the engine with pagination.
     */
    public function paginate(Builder $builder, $perPage, $page): array
    {
        $page = max(1, (int)$page);
        $perPage = max(1, (int)$perPage);
        $offset = ($page - 1) * $perPage;

        return $this->performSearch($builder, [
            'limit' => $perPage,
            'offset' => $offset,
            'paginate' => true,
        ]);
    }

    protected function performSearch(Builder $builder, array $options = []): array
    {
        $limit = $options['limit'] ?? 20;
        $offset = $options['offset'] ?? 0;
        $isPaginate = (bool)($options['paginate'] ?? false);
        $index = $builder->index ?: $builder->model->searchableAs();

        $results = $this->strata->search(
            index: $index,
            query: $builder->query,
            filters: $builder->wheres,
            limit: $limit,
            offset: $offset,
            fastPath: true
        );

        if ($isPaginate) {
            $manifest = $this->strata->getManifest($index);
            return [
                'results' => $results,
                'total' => $manifest->totalDocs > 0 ? $manifest->totalDocs : count($results),
            ];
        }

        return $results;
    }

    /**
     * Pluck and return the primary keys of the given results.
     */
    public function mapIds($results): \Illuminate\Support\Collection
    {
        $records = is_array($results) && isset($results['results']) ? $results['results'] : $results;
        return collect($records)->pluck('id')->values();
    }

    /**
     * Map the given results to instances of the given model.
     */
    public function map(Builder $builder, $results, $model): Collection
    {
        $records = is_array($results) && isset($results['results']) ? $results['results'] : $results;
        if (empty($records)) {
            return $model->newCollection();
        }

        $objectIds = collect($records)->pluck('id')->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds($builder, $objectIds)
            ->filter(fn($model) => isset($objectIdPositions[$model->getScoutKey()]))
            ->sortBy(fn($model) => $objectIdPositions[$model->getScoutKey()])
            ->values();
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     */
    public function lazyMap(Builder $builder, $results, $model): \Illuminate\Support\LazyCollection
    {
        return \Illuminate\Support\LazyCollection::make(function () use ($builder, $results, $model) {
            foreach ($this->map($builder, $results, $model) as $item) {
                yield $item;
            }
        });
    }

    /**
     * Get the total count from a raw result object.
     */
    public function getTotalCount($results): int
    {
        if (is_array($results) && isset($results['total'])) {
            return (int)$results['total'];
        }

        return count($results);
    }

    /**
     * Flush all of the model's records from the engine.
     */
    public function flush($model): void
    {
        $index = $model->searchableAs();
        $this->deleteIndex($index);
    }

    /**
     * Create a search index.
     */
    public function createIndex($name, array $options = []): void
    {
        $path = $this->strata->getStoragePath((string)$name);
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        $manifest = new Manifest();
        $manifest->save($path . '/manifest.json');
    }

    /**
     * Delete a search index.
     */
    public function deleteIndex($name): void
    {
        $this->strata->deleteIndex((string)$name);
    }
}
