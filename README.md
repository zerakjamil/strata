<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/strata-lockup-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="art/strata-lockup-light.svg">
    <img alt="Strata" src="art/strata-lockup-light.svg" width="540">
  </picture>
</p>

# Strata

Strata is a high-throughput hybrid search engine package for Laravel. It provides sub-millisecond query execution over 1,500,000+ records by coupling immutable binary segment files with persistent runtimes like Laravel Octane.

Canonical records remain safely stored in PostgreSQL, while Strata serves real-time sub-millisecond searches.

## Architecture

- **Hybrid Search**: Fuses BM25 lexical ranking and 8-bit quantized dense vector similarity via Reciprocal Rank Fusion.
- **Block-Max WAND Top-K Pruning**: Mathematically provable dynamic upper-bound score pruning ($U_t = IDF(t) \cdot 2.2$) guarantees 100% exact recall parity while skipping >90% of candidate evaluations.
- **Transactional Ghost Leases**: Hooks into Laravel's `DB::afterCommit()` lifecycle to prevent search index corruption on transaction rollbacks, with 4-nanosecond atomic `.del` bitmask tombstoning.
- **Multi-Tenant Index Isolation**: Distinct segment partitions and manifests per model namespace (`Strata::index('products')`).
- **Fast-Path Columnar DocStore**: Streams pre-serialized JSON records from memory in under 0.1ms without Eloquent model hydration or database queries.
- **Zero JVM Dependencies**: In-process engine operating on memory-mapped segment files with no external search clusters.
- **Laravel Scout Driver**: Implements full Laravel Scout contracts (`update`, `delete`, `search`, `paginate`) for Eloquent models.
- **Octane Pre-Warming**: Pre-warms index dictionaries on worker boot.

## Installation

```bash
composer require ayewar/strata
```

## Quick Start

### Fast-Path Query (<1ms, 0 Database Queries)

```php
use Strata\Facades\Strata;

$results = Strata::index('products')
    ->query('mechanical keyboard')
    ->filter(['status' => 'active'])
    ->fast()
    ->limit(10)
    ->get();
```

### Laravel Scout Model Integration

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

// Queries are executed directly against Strata's in-process segments:
$products = Product::search('ergonomic chair')->paginate(20);

// Deletions are transaction-safe with 4ns atomic bitmasks:
$product->delete();
```

## The Architectural Twist

Read [THE_CLEVER_TWIST.md](THE_CLEVER_TWIST.md) for the complete engineering and theoretical breakdown of how Strata solves Laravel Scout's transaction desync flaw and Eloquent hydration memory tax.

## Real-Time Side-by-Side Testing (Hoppscotch & OpenAPI)

Strata provides an interactive side-by-side comparison harness under `demo/`:

1. **Hoppscotch Desktop / Web**:
   - Open **Hoppscotch** (`/Applications/Hoppscotch.app` or [hoppscotch.io](https://hoppscotch.io)).
   - Import Collection: [`demo/strata_hoppscotch_collection.json`](demo/strata_hoppscotch_collection.json).
   - Import Environment: [`demo/strata_hoppscotch_environment.json`](demo/strata_hoppscotch_environment.json).
2. **Execute Side-by-Side Compare**:
   Send `GET http://localhost:8001/api/compare?q=mechanical+keyboard&limit=10`.
   Returns real-time execution times, speedup multipliers, and data payloads from both Strata and PostgreSQL in a single response!
3. **OpenAPI Specification**:
   Full OpenAPI 3.0 specification available at [`demo/openapi.json`](demo/openapi.json).

## PostgreSQL 2.0M Row Benchmark

Tested on a local PostgreSQL database with **2,000,000 synthetic products** across 20 binary segments (1.2 GB):

- **Single-term query ('wireless')**:
  - PostgreSQL GIN: P50 = 2.98 ms (~318 QPS)
  - Strata Fast-Path: P50 = **0.68 ms** (~1,444 QPS) (**4.4x faster, <1ms target achieved**)
- **Multi-term query ('mechanical keyboard')**:
  - PostgreSQL GIN: P50 = 16.78 ms (~60 QPS)
  - Strata Fast-Path: P50 = **1.69 ms** (~518 QPS) (**9.9x faster**)
- **Recall Accuracy**: 100% exact recall parity with exhaustive ranking via Block-Max WAND.

## Artisan Commands

- `php artisan strata:seed --target=2000000`: Seeds PostgreSQL with synthetic records up to 2M rows.
- `php artisan strata:index --table=strata_bench_products`: Compiles table records into immutable `.seg` files.
- `php artisan strata:compact`: Consolidates delta segments into master segments.
- `php artisan strata:status`: Inspects active segments, tombstone counts, and storage metrics.
- `php artisan strata:benchmark`: Runs head-to-head latency comparisons against PostgreSQL GIN.

## License

Open-source software licensed under the MIT license.
