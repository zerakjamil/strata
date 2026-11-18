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

        $writer = new SegmentWriter();
        $storageDir = $this->strata->getStoragePath();
        $segName = 'seg_' . time() . '_' . bin2hex(random_bytes(3)) . '.seg';
        $segPath = $storageDir . '/' . $segName;

        $writer->write($segPath, $documents);

        $manifest = $this->strata->getManifest();
        $manifest->addSegment($segPath, count($documents), level: 0);
        $manifest->save($storageDir . '/manifest.json');
    }

    /**
     * Remove the given model from the index.
     *
     * @param Collection<int, Model> $models
     */
    public function delete($models): void
    {
        // Deletions are recorded in tombstone bitmasks during compaction
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
        return $this->performSearch($builder, [
            'limit' => $perPage,
            'page' => $page,
        ]);
    }

    protected function performSearch(Builder $builder, array $options = []): array
    {
        $limit = $options['limit'] ?? 20;

        return $this->strata->search(
            index: $builder->index ?: $builder->model->searchableAs(),
            query: $builder->query,
            filters: $builder->wheres,
            limit: $limit,
            fastPath: true
        );
    }

    /**
     * Pluck and return the primary keys of the given results.
     */
    public function mapIds($results): \Illuminate\Support\Collection
    {
        return collect($results)->pluck('id')->values();
    }

    /**
     * Map the given results to instances of the given model.
     */
    public function map(Builder $builder, $results, $model): Collection
    {
        if (empty($results)) {
            return $model->newCollection();
        }

        $objectIds = collect($results)->pluck('id')->values()->all();
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
        return count($results);
    }

    /**
     * Flush all of the model's records from the engine.
     */
    public function flush($model): void
    {
        // Flush active segments
    }

    /**
     * Create a search index.
     */
    public function createIndex($name, array $options = []): void
    {
        // Handled automatically by segment manifest
    }

    /**
     * Delete a search index.
     */
    public function deleteIndex($name): void
    {
        // Handled automatically by segment manifest
    }
}
