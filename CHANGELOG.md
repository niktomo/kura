# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-03-20

Initial release.

### Added

#### Core

- `ReferenceQueryBuilder` — Laravel QueryBuilder-compatible fluent API (`where`, `orWhere`, `whereBetween`, `whereIn`, `orderBy`, `paginate`, `find`, `count`, `sum`, `avg`, `min`, `max`, etc.)
- `CacheProcessor` — Processor pattern; handles index resolution, predicate compilation, and record retrieval
- `CacheRepository` — Per-table APCu cache management with Self-Healing
- `KuraManager` — Central registry for table registration, queries, and rebuild
- `KuraServiceProvider` — Laravel service provider with auto-discovery support
- `Kura` Facade

#### Store layer

- `StoreInterface` — APCu abstraction (ids, record, index, composite index, lock)
- `ApcuStore` — Production APCu implementation (`apcu_store` everywhere; TTL extension on write)
- `ArrayStore` — In-memory implementation for tests

#### Loader layer

- `LoaderInterface` — Data source abstraction (`load`, `columns`, `indexes`, `version`)
- `CsvLoader` — CSV-based loader (`data.csv` + `defines.csv` + `indexes.csv`); CSV auto-discovery via `kura.csv.auto_discover`
- `EloquentLoader` — Eloquent model-based loader
- `QueryBuilderLoader` — Query builder-based loader

#### Version management

- `VersionResolverInterface` — Common interface for version resolution
- `DatabaseVersionResolver` — Resolves from DB `reference_versions` table
- `CsvVersionResolver` — Resolves from `versions.csv`
- `CachedVersionResolver` — Decorator; caches version via APCu + PHP var (default 5 min)

#### Index layer

- `IndexBuilder` — Builds sorted single-column indexes and composite index hashmaps at rebuild time
- `IndexResolver` — Resolves candidate IDs from indexes; supports AND/OR, partial AND resolution, composite index acceleration, and Cartesian product for `whereIn + AND equality`
- `BinarySearch` — Binary search on sorted `[[value, [ids]], ...]` for `=`, `>`, `>=`, `<`, `<=`, `BETWEEN`

#### Query extensions (Kura-specific, not in Laravel QueryBuilder)

- `whereRowValuesIn` / `whereRowValuesNotIn` — ROW constructor `(col1, col2) IN ((v1, v2), ...)` with composite index acceleration
- `whereValueBetween` / `whereValueNotBetween` — Scalar-in-range: `low <= scalar <= high`
- `whereNone` / `whereAll` / `whereAny` and their `or`-prefixed variants

#### Infrastructure

- `RecordCursor` — Generator-based cursor; streaming, sorted (index walk), and random traversal
- `WhereEvaluator` — Stateless where-condition evaluator (pure static)
- `RebuildCommand` (`kura:rebuild`) — Artisan command; supports `--table` and `--reference-version`
- `RebuildCacheJob` — Async cache rebuild job (configurable tries via `kura.rebuild.tries`)
- `WarmController` (`POST /kura/warm`) — HTTP endpoint for cache warming; `strategy=sync` or `strategy=queue` (Bus batch)
- `WarmStatusController` (`GET /kura/warm/status/{batchId}`) — Batch progress endpoint
- `KuraAuthMiddleware` — Bearer token auth for warm routes
- `TokenCommand` (`kura:token`) — Generates a secure warm token

#### Self-Healing

- `ids` key missing → full rebuild from Loader (non-blocking; dispatches `RebuildCacheJob`)
- `record` missing while present in `ids` → `CacheInconsistencyException` → full rebuild
- `index` / composite index key missing → `IndexInconsistencyException` → full rebuild + Loader fallback

#### Performance

- Index walk for `orderBy` on indexed columns — eliminates PHP sort; uses pre-sorted APCu index directly
- Composite index for multi-column AND equality — O(1) hashmap lookup vs full scan
- `whereIn` on indexed column — single APCu fetch + binary search for all values in memory
- Partial AND index resolution — skips non-indexable AND conditions rather than abandoning index use entirely
- `orderBy` double-fetch eliminated — `idsMap` passed to cursor for inline consistency check

[Unreleased]: https://github.com/tomonori/kura/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/tomonori/kura/releases/tag/v0.1.0
