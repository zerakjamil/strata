<?php

declare(strict_types=1);

namespace Strata\Scout;

use Strata\Engine\Vector\ScalarQuantizer;
use Strata\Strata;

final class StrataBuilder
{
    private string $query = '';
    private ?string $queryVector = null;
    private array $filters = [];
    private int $limit = 20;
    private bool $fastPath = false;
    private ScalarQuantizer $quantizer;

    public function __construct(
        private readonly Strata $strata,
        private readonly string $index
    ) {
        $this->quantizer = new ScalarQuantizer();
    }

    public function query(string $query): self
    {
        $this->query = $query;
        return $this;
    }

    /**
     * Attach a dense float vector embedding to the query for hybrid RRF scoring.
     *
     * @param array<float> $vector
     */
    public function withVector(array $vector): self
    {
        $this->queryVector = $this->quantizer->quantize($vector);
        return $this;
    }

    /**
     * Attach a pre-quantized int8 vector binary string.
     */
    public function withQuantizedVector(string $int8Bytes): self
    {
        $this->queryVector = $int8Bytes;
        return $this;
    }

    /**
     * Apply discrete key-value filters.
     *
     * @param array<string, scalar> $filters
     */
    public function filter(array $filters): self
    {
        $this->filters = array_merge($this->filters, $filters);
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Enable Fast-Path projection: returns pre-serialized JSON records directly from
     * memory in <0.1ms without executing any database SQL queries.
     */
    public function fast(bool $enable = true): self
    {
        $this->fastPath = $enable;
        return $this;
    }

    /**
     * Execute the query and return the result set.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(): array
    {
        return $this->strata->search(
            index: $this->index,
            query: $this->query,
            queryVector: $this->queryVector,
            filters: $this->filters,
            limit: $this->limit,
            fastPath: $this->fastPath
        );
    }
}
