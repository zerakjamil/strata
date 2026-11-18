# Strata

Strata is an in-process, high-throughput hybrid search engine package for Laravel. It provides sub-millisecond query execution across 1,500,000+ records by coupling immutable memory-mapped binary segment files with persistent worker runtimes like Laravel Octane.

## Highlights

- **Dual-Modal Retrieval**: Combines BM25 lexical scoring with scalar-quantized dense vector similarity via Reciprocal Rank Fusion (RRF).
- **Zero-Copy Memory-Mapped Segments**: Operates directly on binary `.seg` files via operating system virtual memory mapping (`mmap`), bypassing PHP garbage collection overhead.
- **Fast-Path Columnar DocStore**: Projects pre-serialized JSON records directly from memory in <0.1ms, eliminating database hydration bottlenecks during search queries.
- **Laravel Scout Driver**: Seamlessly hooks into Eloquent model events and Scout query builders.
- **PostgreSQL 1.5M Benchmark**: Includes automated seeding and benchmark tools comparing raw PostgreSQL queries with Strata.

## Requirements

- PHP 8.2 or higher
- Laravel 10.x, 11.x, or 12.x
- PostgreSQL 15+ (for data storage and benchmarks)
- Laravel Octane (recommended for sub-millisecond end-to-end HTTP responses)

## License

This package is open-source software licensed under the MIT license.
