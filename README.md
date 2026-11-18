![Strata](art/strata-lockup.svg)

# Strata

Strata is a high-throughput hybrid search engine package for Laravel. It provides sub-millisecond query execution over 1,500,000+ records by coupling immutable binary segment files with persistent runtimes like Laravel Octane.

Canonical records remain safely stored in PostgreSQL, while Strata serves real-time sub-millisecond searches.

## Architecture

- **Hybrid Search**: Fuses BM25 lexical ranking and 8-bit quantized dense vector similarity via Reciprocal Rank Fusion.
- **Fast-Path Columnar DocStore**: Streams pre-serialized JSON records from memory in under 0.1ms without Eloquent model hydration or database queries.
- **Zero JVM Dependencies**: In-process engine operating on memory-mapped segment files with no external search clusters.
- **Laravel Scout Driver**: Implements Laravel Scout contracts for Eloquent models.
- **Octane Pre-Warming**: Pre-warms index dictionaries on worker boot.

## Installation

```bash
composer require ayewar/strata
```

## Quick Start

### Fast-Path Query

```php
use Strata\Facades\Strata;

$results = Strata::index('products')
    ->query('mechanical keyboard')
    ->filter(['status' => 'active'])
    ->fast()
    ->limit(10)
    ->get();
```

### Laravel Scout Model

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
```

```php
$products = Product::search('ergonomic chair')->paginate(20);
```

### Search API Endpoint

```http
GET /api/strata/search?q=keyboard&limit=10&status=active
```

## PostgreSQL 1.5M Row Benchmark

Tested on a local PostgreSQL database with 1,500,000 synthetic products:

- **Single-term query ('keyboard')**:
  - PostgreSQL GIN: P50 = 1.55 ms (~586 QPS)
  - Strata Fast-Path: P50 = 0.48 ms (~2,035 QPS) (3.2x faster)
- **Multi-term query ('mechanical keyboard')**:
  - PostgreSQL GIN: P50 = 14.86 ms (~67 QPS)
  - Strata Fast-Path: P50 = 1.65 ms (~566 QPS) (9.0x faster)

## Artisan Commands

- `php artisan strata:seed --count=1500000`: Seeds PostgreSQL with synthetic records.
- `php artisan strata:index`: Compiles table records into immutable `.seg` files.
- `php artisan strata:compact`: Consolidates delta segments into master segments.
- `php artisan strata:status`: Inspects active segments and storage metrics.
- `php artisan strata:benchmark`: Runs head-to-head latency comparisons against PostgreSQL GIN.

## License

Open-source software licensed under the MIT license.
