# Drift Report: Strata Search Engine Specification vs Implementation

**Generated Date:** 2026-11-19  
**Specification Documents:** `concepts_brief.md`, `README.md`  
**Source Scope:** `src/`  

---

## concepts_brief.md

- **Scout Deletion Incompleteness**: `concepts_brief.md` (Section 2A #5, Section 3) specifies that deleted documents must be tombstoned via O(1) bitmasks and excluded from search traversal. In reality, `StrataEngine::delete($models)` in `src/Scout/StrataEngine.php` is empty (`// Deletions are recorded in tombstone bitmasks during compaction`), leaving deleted documents active in search results until a manual compaction occurs.
- **Index Partitioning Ignored**: `concepts_brief.md` and Laravel Scout conventions assume discrete indexes based on `searchableAs()`. In `src/Strata.php`, `Strata::search(string $index, ...)` accepts an `$index` parameter but completely ignores it, scanning only the root manifest loaded during construction. Multi-index applications bleed results across models.
- **Heuristic Early-Exit vs Provable WAND Pruning**: `concepts_brief.md` (Section 2B #1) specifies Document-At-A-Time (DAAT) WAND evaluation using term maximum scores ($Score_{max}(t)$) to prune non-competitive documents. The implementation in `SegmentReader.php` (line 180) and `Strata.php` (line 75) instead uses hard-coded count limits (`$collected >= $limit * 3` and `count($results) >= $limit * 2`), which prematurely terminates segment traversal and can exclude higher-ranked documents in subsequent posting blocks or segments.

---

## README.md

- **Scout delete() Contract Violation**: `README.md` states full implementation of Laravel Scout contracts for Eloquent models. However, calling `$model->unsearchable()` or `$model->delete()` does not remove or mask the document in the search index due to the stubbed `StrataEngine::delete()` method.
- **Index Isolation Missing**: `README.md` examples use `Strata::index('products')`, implying dedicated index namespaces. The implementation does not partition segments or manifests by index name, routing all index queries to the same root storage directory.
