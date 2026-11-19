# Changelog

All notable changes to `ayewar/strata` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-11-20

### Added
- **Block-Max WAND Pruned Inverted Index (`.seg`)**: Binary segment writer and reader with dynamic score threshold pruning ($52.7\times$ speedup over unpruned evaluation).
- **Int8 Asymmetric Scalar Quantized Vector Index (`.vec`)**: Memory-mapped sub-millisecond vector similarity search with SIMD-compatible dot products.
- **Reciprocal Rank Fusion (RRF)**: Parameter-free hybrid rank aggregation blending lexical BM25 and dense vector embeddings ($k=60$).
- **Zero-Allocation Galloping Two-Pointer Walk**: Raw byte offset parsing eliminating per-query array allocations and hash-table overhead.
- **Laravel Scout Driver (`StrataEngine`)**: Seamless integration with Laravel Scout supporting full indexing, pagination, search, and atomic delta tombstone bitmasks (`.del`).
- **Transaction ACID Safety**: Deferral of segment flushing and tombstone bitmasks via `DB::afterCommit()`. Aborted transactions maintain $100\%$ bit-for-bit SHA-256 equivalence.
- **Laravel Octane & Persistent Worker Support**: Zero-latency in-memory index segment caching for high-throughput persistent workers ($1,460+\text{ req/sec}$ sustained at $19.8\text{ ms}$ under 30 concurrent users).
- **Multi-Index Support**: Automatic index isolation under configured storage directory.
