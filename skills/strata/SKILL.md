---
name: strata
description: Best-practice implementation guide for Strata high-throughput hybrid search in Laravel. Use when integrating Strata, configuring Scout drivers, optimizing Octane search, or setting up sub-millisecond search endpoints.
---

# Strata Implementation Guide

Guidelines for integrating and optimizing Strata in Laravel applications for sub-millisecond search performance.

## Core Principles

1. **Fast-Path for Read-Heavy Search APIs**: Always prefer `$builder->fast()` for search queries that return JSON to frontend or mobile clients. It streams pre-serialized JSON slices directly from memory in <0.1ms, eliminating Eloquent model hydration and database round-trips.
2. **Hydrated Path Only When Mutating**: Use standard `$model->search()->get()` only when Active Record models, relationships, or mutators are required.
3. **Asynchronous Index Ingestion**: Never build segments synchronously inside HTTP request cycles. Queue model index updates using Laravel Scout's queue driver (`SCOUT_QUEUE=true`).
4. **Regular Compaction**: Schedule `strata:compact` via Laravel's console schedule to consolidate delta segments into master segments.

---

## 3-Step Implementation

### Step 1: Install Package

```bash
composer require ayewar/strata
```

Set Scout driver in `.env`:
```env
SCOUT_DRIVER=strata
SCOUT_QUEUE=true
```

### Step 2: Configure Model

Add `Searchable` to your Eloquent model:

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
            'status' => $this->status,
        ];
    }

    public function toSearchableFilters(): array
    {
        return [
            'status' => $this->status,
            'in_stock' => (bool) $this->in_stock,
        ];
    }
}
```

### Step 3: Query Endpoint (<1ms Response)

In your controller:

```php
namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Strata\Facades\Strata;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $results = Strata::index('products')
            ->query($request->input('q', ''))
            ->filter(array_filter(['status' => $request->input('status')]))
            ->fast()
            ->limit(10)
            ->get();

        return response()->json(['data' => $results]);
    }
}
```

---

## Maintenance & Octane Configuration

### Scheduled Compaction (`routes/console.php`)

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('strata:compact')->dailyAt('03:00');
```

### Laravel Octane Worker Warmup

Strata automatically registers a listener for `WorkerStarting` events. To verify index health in production:

```bash
php artisan strata:status
```

---

## Anti-Patterns to Avoid

* **Anti-Pattern 1**: Calling `->get()` without `->fast()` on public autocomplete or search endpoints. Eloquent hydration on 20 models takes 5-15ms; Fast-Path takes 0.08ms.
* **Anti-Pattern 2**: Synchronous indexing on model save. Always enable `SCOUT_QUEUE=true` in production to prevent write latency spikes.
* **Anti-Pattern 3**: Leaving delta segments uncompacted. If segment count exceeds 20, query times will increase linearly. Run `php artisan strata:compact` regularly.
