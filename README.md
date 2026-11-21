<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/strata-lockup-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="art/strata-lockup-light.svg">
    <img alt="Strata" src="art/strata-lockup-light.svg" width="540">
  </picture>
</p>

# Strata

Embedded hybrid search engine for Laravel. Sub-millisecond query execution over millions of records via immutable binary segments and persistent runtimes.

> **Student Guide & Academic Manual**: [`STRATA_STUDENT_MANUAL.pdf`](STRATA_STUDENT_MANUAL.pdf) contains a complete conceptual overview (explained without code), benchmark summaries, a ready-to-use written report template, and a 6-slide presentation blueprint with speaker scripts.

## Benchmarks

Tested on 2,000,000 PostgreSQL records (`strata_bench_products`) across 20 binary segments (1.2 GB).

### Concurrency

<p align="center">
  <img src="art/benchmark-throughput.svg" alt="Concurrency Saturation" width="880">
</p>

| Engine | Throughput | Median ($p50$) | $p95$ | Errors |
| :--- | :--- | :--- | :--- | :--- |
| **Strata** | **1,468 req/s** | **19.8 ms** | **28.2 ms** | 0.00% |
| **PostgreSQL 16 (GIN)** | 59.5 req/s | 497.6 ms | 548.5 ms | 0.00% |

```bash
k6 run --duration 10s --vus 50 benchmarks/k6_strata.js
```

### Ranked Search Latency

Head-to-head against PostgreSQL true relevance ranking (`ORDER BY ts_rank(...) DESC`):

<p align="center">
  <img src="art/benchmark-hardest-queries.svg" alt="Ranked Search Latency" width="880">
</p>

| Query | PostgreSQL (`ts_rank`) | Strata (BM25 WAND) | Speedup |
| :--- | :--- | :--- | :--- |
| `"mechanical keyboard edition"` | 2,025.0 ms | **74.8 ms** | **27.1×** |
| `"wireless mouse repudiandae"` | 131.0 ms | **2.8 ms** | **46.1×** |
| `"ergonomic chair"` (active, >$500) | 1,539.5 ms | **25.4 ms** | **60.6×** |
| `"wireless keyboard"` (offset 100) | 1,210.3 ms | **21.7 ms** | **55.9×** |

```bash
php scripts/hardest_query_challenge.php
```

## Installation

```bash
composer require ayewar/strata
```

## Usage

### Fast-Path Search

```php
use Strata\Facades\Strata;

$results = Strata::index('products')
    ->query('mechanical keyboard')
    ->filter(['status' => 'active'])
    ->fast()
    ->limit(10)
    ->get();
```

### Laravel Scout

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Product extends Model
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'price' => (float) $this->price,
        ];
    }
}

// Search
$products = Product::search('ergonomic chair')->paginate(20);

// Atomic bitmask tombstone deletion
$product->delete();
```

## Architecture

- **Block-Max WAND**: Dynamic score upper-bound pruning ($U_t = \text{IDF}_t \times 2.2$) skips >90% of candidate evaluations while guaranteeing 100% exact recall parity.
- **Galloping Postings Walk**: Evaluates strictly-ordered docIDs with exponential leaps (`$jump <<= 1`), eliminating per-query hash map allocations.
- **Quantized Vector Search**: Int8 asymmetric scalar quantization with SIMD dot products, blended via Reciprocal Rank Fusion ($k=60$).
- **Transaction ACID Safety**: Defers segment flushes and `.del` bitmasks to `DB::afterCommit()`. Aborted transactions maintain bit-for-bit SHA-256 equivalence.
- **In-Process Segments**: Zero external daemon dependencies; operates on mmap/stream binary files in OS page cache.

## Verification

```bash
# Full test suite (17 tests, 66 assertions)
./vendor/bin/phpunit

# Transaction ACID rollback proof (SHA-256 equivalence)
./vendor/bin/phpunit tests/Feature/AcidRollbackTest.php

# 50-query exact recall verification vs PostgreSQL
php scripts/verify_recall.php
```

## Artisan Commands

| Command | Description |
| :--- | :--- |
| `php artisan strata:index {--table=}` | Compiles table records into immutable `.seg` binary segments |
| `php artisan strata:compact` | Merges fragmented delta segments into master segments |
| `php artisan strata:status` | Displays active segments, tombstone counts, and disk usage |
| `php artisan strata:benchmark` | Runs comparative latency tests against PostgreSQL GIN |
| `php artisan strata:seed {--target=2000000}` | Generates high-volume test records |

## Demo & Testing

Pre-configured collections for [Hoppscotch](https://hoppscotch.io) and Postman:
- Native Hoppscotch: [`demo/hoppscotch/IMPORT_AS_HOPPSCOTCH.json`](demo/hoppscotch/IMPORT_AS_HOPPSCOTCH.json)
- Postman Collection: [`demo/hoppscotch/IMPORT_AS_POSTMAN.json`](demo/hoppscotch/IMPORT_AS_POSTMAN.json)
- OpenAPI 3.0 Spec: [`demo/hoppscotch/IMPORT_AS_OPENAPI.json`](demo/hoppscotch/IMPORT_AS_OPENAPI.json)

## License

MIT
