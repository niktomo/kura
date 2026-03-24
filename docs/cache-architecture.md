> Japanese version: [cache-architecture-ja.md](cache-architecture-ja.md)

# Cache Architecture

## Overview

Kura caches reference/master data in APCu and queries it via `ReferenceQueryBuilder`.
Data loading is performed through `LoaderInterface`. `CsvLoader`, `EloquentLoader`, and `QueryBuilderLoader` are all included in this package under `src/Loader/`.

> **This document is the implementation design specification.** For overall structure and usage, see `overview.md`.
>
> Related docs:
> - [Version Management](version-management.md) — version drivers, middleware, deployment flow
> - [Index Guide](index-guide.md) — index types, composite indexes, range queries
> - [Query Recipes](query-recipes.md) — practical query patterns and examples

---

## Architecture

### Class Structure

```
ReferenceQueryBuilderInterface extends BuilderContract
  └─ ReferenceQueryBuilder implements ReferenceQueryBuilderInterface
       │
       │  where(), orderBy(), limit(), etc. — same signatures as Laravel Builder
       │  State construction only. Execution is delegated to CacheProcessor
       │
       └─ CacheProcessor
            │  select(), cursor() — execution from cache
            │  resolveIds()       — narrows candidate IDs via indexes
            │  compilePredicate() — converts where conditions to closures
            │
            ├─ CacheRepository
            │    │  find(), pks(), reload()
            │    │  APCu read/write + Self-Healing
            │    │
            │    ├─ StoreInterface (APCu abstraction)
            │    │    ├─ ApcuStore (production)
            │    │    └─ ArrayStore (testing)
            │    │
            │    └─ LoaderInterface (data loading)
            │         └─ CsvLoader / EloquentLoader / QueryBuilderLoader (in src/Loader/)
            │
            └─ RecordCursor
                 Generator-based record traversal; delegates condition evaluation to WhereEvaluator
```

### Design Principles

- **BuilderContract**: `ReferenceQueryBuilderInterface` extends `Illuminate\Contracts\Database\Query\Builder`.
  BuilderContract is currently an empty interface, but `instanceof BuilderContract` works
- **Processor Pattern**: Similar to how Laravel executes queries via `Grammar → Connection → Processor`,
  Kura executes cache queries via `ReferenceQueryBuilder → CacheProcessor → CacheRepository`.
  Grammar and Connection are not needed
- **QueryBuilder is state-only**: Holds where/order/limit state; execution is delegated to `CacheProcessor`.
  Index resolution, record traversal, and condition evaluation are all Processor responsibilities
- **LoaderInterface is defined in Kura**: Has `load()`, `columns()`, `indexes()`, `primaryKey()`, `version()`.
  `CsvLoader`, `EloquentLoader`, and `QueryBuilderLoader` are included in `src/Loader/`

### LoaderInterface

```php
interface LoaderInterface
{
    /** @return Generator<int, array<string, mixed>> */
    public function load(): Generator;

    /** @return array<string, string> column name → type ('int', 'string', 'float', 'bool') */
    public function columns(): array;

    /**
     * @return list<array{
     *     columns: list<string>,
     *     unique: bool,
     * }>
     *
     * Example:
     *   [
     *       ['columns' => ['country'], 'unique' => false],
     *       ['columns' => ['email'], 'unique' => true],
     *       ['columns' => ['country', 'category'], 'unique' => false],  // composite
     *   ]
     *
     * Composite index column order:
     *   first = column with lower cardinality (fewer distinct values)
     *   → single-column indexes for each column are also created automatically
     */
    public function indexes(): array;

    /**
     * Primary key column name.
     */
    public function primaryKey(): string;

    /**
     * Version identifier included in cache keys.
     *
     * @return string|int|Stringable
     *
     * Examples: 'v1.0.0', 20260313, new SemVer(1, 0, 0)
     */
    public function version(): string|int|\Stringable;
}
```

- `load()`: Generator for low memory usage. For DB, equivalent to paginated reads
- `columns()`: Column names and type definitions (`'int'`, `'string'`, `'float'`, `'bool'`). Used at query time for type-aware index building
- `indexes()`: Declares single-column and composite indexes. Loader's responsibility
  - `unique: true` / `unique: false` — documentation hint only. Kura does not enforce uniqueness; both store IDs as a list and behave identically at query time
  - Composite indexes specify columns in order. Single-column indexes for each column are auto-created
- `version()`: Version identifier included in cache keys. Returns `string|int|Stringable`
  - The Loader manages the data source version (CSV filename, DB timestamp, etc.)
  - When the version changes, cache keys change, and old caches naturally expire via TTL

---

## Cache Types

APCu stores **4 types** of data.

| Type | Purpose | Behavior on loss |
|------|---------|-----------------|
| **ids** | List of all IDs | Full rebuild |
| **record** | Single record data (associative array) | Check existence in ids → full rebuild if expected |
| **index** | Search indexes (ID lists) | Respond via full scan + full cache rebuild |
| **cidx** | Composite index (multi-column hashmap) | Respond via full scan + full cache rebuild |

Index structure (which columns are indexed, which composites exist) is **not stored in APCu**.
It is derived at query time from `LoaderInterface::indexes()`, which is instance-cached in the Loader.

---

## 1. pks

List of all PKs.

```php
kura:products:v1.0.0:pks → [1, 2, 3, ...]
```

### Purpose

- Candidate ID set for full scans
- Reference for determining whether a missing record "should exist"
- Converted to hashmap via `array_flip` when intersection is needed

### Characteristics

- If ids is lost → **full rebuild**
- Has the shortest TTL among the 4 types (serves as rebuild trigger)

---

## 2. record

Data for a single record. Stored as-is as an associative array.

```php
kura:products:v1.0.0:record:1 → ['id' => 1, 'name' => 'Widget A', 'country' => 'JP', 'price' => 500]
```

- Records are self-contained (no meta dependency)
- `find(id)` is the most frequent operation → can return immediately

### Self-Healing on Record Loss

```
Record retrieval
  └─ Hit → normal response
  └─ Miss
       └─ ids[id] exists → expected data is missing → full rebuild
       └─ ids[id] doesn't exist → truly non-existent data → return null
```

---

## 3. index

Search indexes. Structure for looking up IDs from column values (single column).

One key per column. Value → IDs mapping, sorted by value ascending.

```php
kura:products:v1.0.0:idx:country → [
    ['JP', [1, 3, 6]],
    ['US', [2, 4, 8]],
    ['DE', [5, 7]],
]
```

- Equality search `=` → binary search O(log n)
- Range search `>`, `<`, `BETWEEN` → binary search to find start position → slice

### Index Query Behavior

```
where('price', '=', 700)
  └─ Binary search → immediately get [8, 14]

where('price', '>', 800)
  └─ Binary search to find start position → slice to end → collect IDs

where('price', 'BETWEEN', [200, 600])
  └─ Binary search for range boundaries → slice → collect IDs
```

### Multi-Column WHERE (Intersection)

```
where('country', 'JP')->where('price', '>', 500)
  └─ country index → [1, 3, 6, ...]
  └─ price index   → [2, 3, 8, ...]
  └─ array_flip → hashmap → array_intersect_key → [3]
  └─ record fetch → filter
```

Index return values are ID lists `[id, ...]`. For intersection, they are converted to hashmaps via `array_flip` and intersected with `array_intersect_key` for performance.

### Index Declaration

Index definitions are the **Loader's responsibility**. Provided alongside data via `LoaderInterface::indexes()`.
For CSV and DB loaders, read from table.yaml in the table directory.
Kura just receives them via `LoaderInterface`.

### Composite Index

Both single-column indexes and composite indexes are declared in Loader's indexes().

```php
// Example return value of LoaderInterface::indexes()
[
    ['columns' => ['country'],            'unique' => false],
    ['columns' => ['email'],              'unique' => true],
    ['columns' => ['country', 'category'],'unique' => false],  // composite
]
```

For the first column of a composite index, choose the **column with more records per value**
(lower cardinality). In the example above, `country` (JP, US, DE, etc.) has lower cardinality
than `category`, so it becomes the first column.

Kura **automatically creates single-column indexes for each column** when a composite index is declared.
This supports WHERE conditions on individual columns or in different orders.
There is no need to redundantly declare single-column indexes in the Loader.

From the example above, Kura automatically builds these indexes:
- `idx:country` — explicitly declared
- `idx:email` — explicitly declared (unique)
- `idx:category` — auto-generated from composite `['country', 'category']`
- composite: `country → category` hierarchical index

### Conditions Where Indexes Are Used

```
=        → binary search for exact match
>, <     → binary search to find start/end position → slice
>=, <=   → same as above
BETWEEN  → binary search for range slice
AND      → intersection of each index's results (array_intersect_key)
OR       → all conditions hit index → union (array + array_unique)
           → any condition misses index → abandon index resolution, full scan with all ids
ROW IN   → if composite index exists, hashmap lookup O(1) per tuple
           no composite index or NOT IN → full scan
```

### Negation of Compound Conditions (De Morgan's Law)

`whereNone` / `orWhereNone` internally apply De Morgan's law.

```
whereNone(['name', 'email'], '=', 'alice@example.com')

Internal conversion:
  NOT (name = 'alice@example.com' OR email = 'alice@example.com')

By De Morgan's law:
  (name != 'alice@example.com') AND (email != 'alice@example.com')
```

The implementation negates OR-joined nested conditions with a `negate` flag.
De Morgan expansion is implicitly applied during closure evaluation in compilePredicate().

### When Indexes Are Lost

- Same flow as other cache losses (see Self-Healing Summary)
- The synchronous path responds via full scan without indexes
- Queue dispatch rebuilds entire cache (including indexes)

---

## 4. Composite Index (cidx)

A hashmap for resolving multi-column AND equality in O(1).

```php
kura:products:v1.0.0:cidx:country|category → [
    'JP|electronics' => [1, 3],
    'JP|food'        => [6],
    'US|electronics' => [2, 4],
    'US|food'        => [8],
]
```

### Structure

- Key: string concatenation of `{val1|val2}`
- Value: ID list `[id, ...]`
- Hashmap, so lookup is O(1)

### Use Cases

- **AND equality**: `where('country', 'JP')->where('category', 'electronics')` → `IndexResolver::tryCompositeIndex()` resolves with a single APCu fetch
- **ROW constructor IN**: `whereRowValuesIn(['country', 'category'], [['JP', 'electronics'], ...])` → `IndexResolver::resolveRowValuesIn()` performs O(1) lookup per tuple

### Construction

`IndexBuilder::buildCompositeIndexes()` builds during rebuild.
Auto-generated from index declarations with 2+ columns. Value combinations containing NULL are skipped.

---

### ROW Constructor IN (Kura Extension)

A Kura-specific extension supporting MySQL's ROW constructor syntax.
Does not exist in Laravel's `Query\Builder` (requires `whereRaw()`).

```php
// MySQL: SELECT * FROM t WHERE (user_id, item_id) IN ((1, 10), (2, 20))
$builder->whereRowValuesIn(['user_id', 'item_id'], [[1, 10], [2, 20]]);

// NOT IN
$builder->whereRowValuesNotIn(['user_id', 'item_id'], [[1, 10]]);

// OR variants
$builder->orWhereRowValuesIn(['user_id', 'item_id'], [[1, 10]]);
$builder->orWhereRowValuesNotIn(['user_id', 'item_id'], [[1, 10]]);
```

**Internal implementation:**
- where type: `rowValuesIn`
- `resolveSubqueries()` builds a `tupleSet` hashmap: `"1|10" => true` (values joined by `|` as string key)
- O(1) matching in RecordCursor
- If a composite index exists, IndexResolver resolves IDs directly (no full scan needed)
- NOT IN cannot be accelerated with composite indexes (falls back to full scan)

**NULL handling (MySQL-compatible):**
- If a column value is NULL, both IN and NOT IN return false (NULL propagation)

---

### NULL Handling (MySQL-Compatible)

Kura follows MySQL semantics: comparisons involving NULL return false.

| Operation | NULL behavior |
|-----------|--------------|
| `=`, `!=`, `<>` | strict comparison (`null === null` is true) |
| `>`, `>=`, `<`, `<=` | NULL → false |
| `IN` / `NOT IN` | Column value is NULL → always false |
| `BETWEEN` / `NOT BETWEEN` | NULL → false (NOT → true) |
| `LIKE` / `NOT LIKE` | NULL → false |
| ROW constructor IN/NOT IN | Column value contains NULL → always false |
| `ORDER BY` | NULL is treated as minimum value (ASC: first, DESC: last) |

---

## Data Retrieval → Cache Construction Flow

### Cache Decision During Query

```
Query execution
  │
  ├─ Lock present (rebuild in progress)
  │    → Don't look at cache
  │    → Loader→generator → where evaluation → return
  │
  ├─ No lock + ids present
  │    → Normal query (uses indexes derived from Loader::indexes())
  │
  └─ No lock + ids missing
       → Queue dispatch for full cache rebuild
       → Loader→generator → where evaluation → return
```

- During rebuild, cache consistency cannot be guaranteed, so respond directly from Loader
- Loader uses generators for low memory usage (equivalent to paginated reads for DB)

### Rebuild Job

**Cache is built entirely under the rebuild lock.**

```
(APCu locked throughout):
  flush() — clear all existing keys for this table+version

  Get generator from Loader->load()
  In a single loop:
    ├─ apcu_store records (one at a time)
    ├─ Collect ids [id, ...]
    └─ Collect index data [col → [value → [id, ...]]]
  After loop:
    ├─ apcu_store ids
    ├─ apcu_store indexes (sorted by value)
    └─ apcu_store composite indexes (hashmap)

  Release lock ← index-accelerated queries now possible
```

- While locked, all queries fall back to Loader directly (safe, correct)
- Index writes happen inside the lock — no window where ids exist but indexes don't
- Records are `apcu_store`d one at a time during the loop
- ids, indexes, and cidx are bulk-written after the loop completes

apcu_store overwrites. Even if existing data is present, it's overwritten with the same data and TTL is reset (extended).
No existence check needed. Simply rewrite everything from Loader.

### Query Execution Flow (CacheProcessor)

cursor() returns records one at a time via generator. get() uses cursor() internally and returns an array.
On record loss, cursor() throws an exception; get() catches it and falls back to Loader.

```
where('country', 'JP')->where('price', '>', 500)->get()

⓪ Lock check + ids existence check
   ├─ Lock present → Loader fallback
   ├─ ids missing → Loader fallback + rebuild dispatch
   └─ ids present → continue cache query

① Candidate ID resolution (resolveIds)
   Derive index structure from Loader::indexes() (instance-cached)
     ├─ Conditions match indexed columns → get ID set from index (intersection / union)
     └─ No index match → ids (all IDs, full scan)

② Convert all where conditions to closures (compilePredicate)

③ Loop through candidate IDs
   foreach ($candidateIds as $id)
     ├─ Fetch record
     │    └─ record missing + in ids → Loader fallback (see below)
     ├─ Evaluate all where conditions via closure
     │    ├─ Match → add to result array
     │    └─ No match → skip
     └─ (All conditions are re-evaluated, including index-hit ones)

④ Apply order / limit / offset

⑤ Return results (array)
```

**Indexes only narrow candidates. Final judgment is always via closures.**
The only branching for index presence/absence is in resolveIds(). Loop and judgment logic are shared.

---

## Self-Healing Summary

**Always returns complete results to callers.** Data loss is never exposed.

### Types of Loss and Responses

```
Query execution
  │
  ├─ Lock present (rebuild in progress)
  │    → Respond from Loader directly
  │
  ├─ ids present → normal query (index structure from Loader::indexes())
  │
  ├─ ids missing → respond from Loader directly + Queue dispatch for full rebuild
  │
  ├─ record loss (detected mid-loop; extremely rare)
  │    → cursor(): CacheInconsistencyException + Queue dispatch
  │    → get(): catch exception → Loader fallback
  │
  └─ index loss (declared in Loader::indexes() but key missing from APCu)
       → IndexInconsistencyException → get(): Loader fallback + Queue dispatch for full rebuild
```

### cursor() and get()

```
cursor(): returns records one at a time via generator
  Normal → yield as-is (99.99%)
  record loss → CacheInconsistencyException + Queue dispatch

get(): returns array
  Uses cursor() internally
  CacheInconsistencyException → catch → Loader fallback
```

Record loss only occurs during APCu anomalies (memory pressure, process restart, etc.).
Under normal operation, all reference data fits in APCu.

**Why cursor() and get() behave differently on exceptions:**
cursor() is a generator, so switching to Loader mid-stream would produce duplicate records.
get() is a self-contained array return, so it can catch the exception and re-fetch from Loader.

### Operational Notes

Record loss occurs only from APCu eviction due to memory pressure.
Self-Healing is a safety valve; if it triggers frequently, address as an infrastructure issue:

- Increase `apc.shm_size`
- Monitor APCu memory usage (apc.php, Prometheus exporter, etc.)
- Review cached tables (exclude unnecessary ones)
- Exclude blob or large JSON columns from caching

### Recommended Scale

Kura is designed for **reference data that fits entirely in APCu** — data that is read-heavy,
changes infrequently, and can be rebuilt in seconds to minutes from DB or CSV.

| Records per table | APCu estimate | Notes |
|---|---|---|
| < 10K | < 10 MB | Trivial |
| 10K – 100K | 10 – 100 MB | Comfortable operating range ✅ |
| 100K – 500K | 100 – 500 MB | Requires `apc.shm_size` tuning; watch ids overhead |
| > 500K | 500 MB+ | Not recommended — see below |

**Recommended maximum: ~100K records per table.**

#### Why ids becomes a bottleneck at large scale

Every query fetches the full ids list from APCu and builds a PHP hashmap from it:

```php
$ids    = apcu_fetch('kura:products:v1:ids');  // deserialize all N entries
$idsMap = array_fill_keys($ids, true);          // build another N-entry hashmap
```

At 1M records this allocates **~80–160 MB per request** just for ids, before any records
are touched — even when an index narrows the actual candidates to a handful of rows.

#### What to do for larger datasets

- **Split into smaller tables** by category, status, or region — keep each table under 100K
- **Pre-filter in the Loader** — load only the active or relevant subset, not the full table
- **Exclude large columns** — omit blob, large JSON, or free-text columns from the cached record
- **Consider a different tool** — for datasets that change frequently or exceed 500K rows,
  a Redis-backed read model or a materialized DB view may be more appropriate

### Estimating apc.shm_size

```
Required memory ≒ Σ(per-record size × record count × 2–3x)

Breakdown:
  record:  serialize(associative array) × record count
  ids:     ~8–12 bytes/entry after serialization ([id, ...] list format)
  index:   [[value, [ids]], ...] × column count

× 2–3x: APCu internal overhead (hash table, memory fragmentation)
```

Guidelines:
- 1 record at 200 bytes × 50,000 records → records alone ~10MB
- Including index, ids, and overhead: **~30–50MB**
- Sum across all cached tables
- Check actual usage with `apcu_cache_info('user')` and maintain usage below 80%

### APCu Constraints and Production Considerations

#### APCu is process-local

APCu stores data in shared memory **within a single PHP-FPM process pool** (or CLI process).
It is **not shared across servers**.

```
Server A  [PHP-FPM]  ←→  APCu (Server A only)
Server B  [PHP-FPM]  ←→  APCu (Server B only)   ← independent cache
Server C  [PHP-FPM]  ←→  APCu (Server C only)   ← independent cache
```

**Implications for multi-server deployments:**

- Each server maintains its own independent cache
- After a deployment, each server rebuilds its cache independently (triggered by the first request
  that finds ids missing, or via `POST /kura/warm` called against each server)
- A version change on one server does not propagate to others — version resolution happens
  per-server per-request
- **Recommended**: call the warm endpoint (or `artisan kura:rebuild`) on each server after deployment

#### APCu is not available in PHP CLI by default

APCu in CLI is disabled by default (`apc.enable_cli=0`).
Enable it for artisan commands (including `kura:rebuild`):

```ini
; .docker/kura.ini or php.ini
apc.enable_cli = 1
```

#### apc.shm_size tuning

The default `apc.shm_size` is often 32MB — too small for production reference data.
Set it based on your estimated usage (see "Estimating apc.shm_size" above):

```ini
apc.shm_size = 256M   ; adjust to your dataset size
```

Monitor usage in production:

```php
$info = apcu_cache_info('user');
// $info['mem_size'] = total allocated
// $info['cache_list'] = per-key details
```

Keep usage below **80%** to avoid eviction pressure triggering excessive self-healing.

---

### Error Handling

Kura recovers from cache losses but does not suppress Loader failures.

- Loader connection errors (DB connection failure, CSV file not found, etc.) → exception is thrown as-is
- APCu write failures → exception is thrown (apc.shm_size insufficient, etc.)
- Loader errors during self-healing → same as above. Propagated to caller

Kura's responsibility extends to "return fast from cache if available, delegate to Loader if not."
Loader availability is the responsibility of the Loader and infrastructure layers.

### Implementation Overview

```php
// CacheProcessor::cursor()
public function cursor(
    array $wheres,
    array $orders,
    ?int $limit,
    ?int $offset,
    bool $randomOrder,
): Generator {
    // Lock present → cache consistency not guaranteed
    if ($repository->isLocked()) {
        yield from $this->cursorFromLoader($wheres, $orders, $limit, $offset, $randomOrder);
        return;
    }

    $ids = $repository->ids();

    if ($ids === false) {
        // ids missing → Loader fallback + full rebuild dispatch
        $this->dispatchRebuild();
        yield from $this->cursorFromLoader($wheres, $orders, $limit, $offset, $randomOrder);
        return;
    }

    // Derive index structure from Loader::indexes() (instance-cached per CacheProcessor)
    [$indexedColumns, $compositeNames] = $this->resolveIndexDefs();
    $resolver = new IndexResolver($store, $table, $version, $indexedColumns, $compositeNames);

    $candidateIds = $resolver->resolveIds($wheres) ?? $ids;

    $idsMap = array_fill_keys($ids, true);

    foreach ($candidateIds as $id) {
        $record = $repository->find($id);

        if ($record === null && isset($idsMap[$id])) {
            throw new CacheInconsistencyException("Record {$id} missing");
        }

        if ($record !== null && WhereEvaluator::evaluate($record, $wheres)) {
            yield $record;
        }
    }
}

// CacheProcessor::select() — called by get()
public function select(
    array $wheres,
    array $orders,
    ?int $limit,
    ?int $offset,
    bool $randomOrder,
): array {
    try {
        return iterator_to_array($this->cursor($wheres, $orders, $limit, $offset, $randomOrder));
    } catch (CacheInconsistencyException) {
        $this->dispatchRebuild();
        return iterator_to_array($this->cursorFromLoader($wheres, $orders, $limit, $offset, $randomOrder));
    }
}
```

### Preventing Duplicate Rebuilds

Uses `apcu_add` to acquire a lock key, preventing multiple simultaneous rebuilds.

```
{prefix}:{table}:lock → apcu_add (TTL configurable. Default 60 seconds)
```

- Lock acquired → execute rebuild
- Lock acquisition failed → another process is rebuilding. Respond via Loader fallback only
- Rebuild complete → lock is explicitly deleted via `apcu_delete()` in a `finally` block (immediate release)
- TTL on the lock key is crash safety only — if the process dies, the lock auto-expires after the TTL

※ `apcu_add` is used only for locking. All data writes use `apcu_store`.

### Queue Job Principles

**"Extend TTL if exists, create if not."**

- Present in APCu → read and re-save (`apcu_store` resets TTL)
- Not in APCu → fetch from Loader, build and save
- Works the same whether everything or only parts are missing
- When Loader is called, all caches (ids, record, index, cidx) are rebuilt

**rebuild() always does a full flush + rebuild**: it calls `flush()` first to clear all existing cache
keys for the table, then reloads everything from the Loader. There is no partial-rebuild path —
all keys (ids, records, indexes, composite indexes) are always re-written together, inside the lock.

### Rebuild Strategy

The rebuild dispatcher is a `Closure(CacheRepository): void` injected into `CacheProcessor`.
When `null`, rebuild runs synchronously. The strategy is configured in `config/kura.php` and wired by `KuraServiceProvider`.

---

#### strategy: sync (default)

```php
'rebuild' => ['strategy' => 'sync'],
```

```
get() / first() — cache miss detected
  │
  ├─ Respond from Loader (Generator → records returned to caller)
  └─ rebuild() called in the same process, same request
       └─ Flush + load all records → write record + ids + indexes to APCu  (locked throughout)
       └─ Next request hits APCu normally

Latency:  first miss = Loader read time + full cache write time
Queue:    not needed
Use case: development, small datasets, no-queue environments
```

---

#### strategy: queue ⭐ recommended for production

```php
'rebuild' => [
    'strategy' => 'queue',
    'queue' => [
        'connection' => null,   // null = default connection
        'queue'      => null,   // null = default queue
        'retry'      => 3,
    ],
],
```

```
get() / first() — cache miss detected
  │
  ├─ dispatch(RebuildCacheJob)  ← async, returns immediately
  └─ Respond from Loader        ← current request is served immediately

  [Background worker]
    RebuildCacheJob::handle()
      └─ KuraManager::rebuild($table)
           └─ flush + load → APCu (locked throughout)

  Next request → APCu hit (normal fast path)

Latency:  first miss = Loader read time only (no cache write overhead)
Queue:    Laravel Queue required (Redis, SQS, database, etc.)
Use case: production — cache miss is transparent to the caller
```

---

#### strategy: callback

For custom dispatch logic — Horizon priority queues, Octane tasks, custom telemetry, etc.
Set `strategy` to `'callback'` and provide a callable in `config/kura.php`:

```php
'rebuild' => [
    'strategy' => 'callback',
    'callback' => static function (\Kura\CacheRepository $repository): void {
        // e.g. dispatch to a specific Horizon queue
        MyCustomRebuildJob::dispatch($repository->table())->onQueue('kura-rebuild');
    },
],
```

The callable receives the `CacheRepository` for the table that needs rebuilding.
It must be set when `strategy` is `'callback'`; omitting it throws `InvalidArgumentException` at container resolution time.

```
get() / first() — cache miss detected
  │
  ├─ ($yourCallable)($repository)  ← your logic runs here
  └─ Respond from Loader

Latency:  depends on callable implementation
Queue:    your choice
Use case: Horizon priority queues, Octane, custom telemetry, etc.
```

---

#### Comparison

| strategy | Queue needed | Miss latency | When to use |
|---|---|---|---|
| **sync** | No | Loader + rebuild time | Dev / small scale / no queue |
| **queue** | Yes (Laravel Queue) | Loader only | Production (recommended) |
| **custom** (`app->extend`) | Your choice | Your choice | Custom infrastructure / Horizon |

---

## Warm Endpoint

`POST /kura/warm` rebuilds the APCu cache for all registered tables (or a specified subset).
Useful for pre-warming after a deployment before traffic arrives.

Enable in `config/kura.php`:

```php
'warm' => [
    'enabled'           => true,
    'token'             => env('KURA_WARM_TOKEN', ''),  // Bearer token (required)
    'path'              => 'kura/warm',                  // URL path
    'controller'        => \Kura\Http\Controllers\WarmController::class,
    'status_controller' => \Kura\Http\Controllers\WarmStatusController::class,
],
```

Generate a token with:

```bash
php artisan kura:token          # generates and writes to .env
php artisan kura:token --show   # display current token
php artisan kura:token --force  # overwrite without confirmation
```

**Customizing the controllers** — publish stubs to `app/Http/Controllers/Kura/`:

```bash
php artisan vendor:publish --tag=kura-controllers
```

Then update the config to point to your custom classes:

```php
'warm' => [
    'controller'        => \App\Http\Controllers\Kura\WarmController::class,
    'status_controller' => \App\Http\Controllers\Kura\WarmStatusController::class,
],
```

### Request

```
POST /kura/warm
Authorization: Bearer {KURA_WARM_TOKEN}

Query parameters:
  tables  — comma-separated table names (omit = all registered tables)
  version — reference data version override (e.g. v2.0.0)
```

### Behavior by Strategy

#### strategy: sync

Rebuilds all tables sequentially in the same request. Returns when all are done.

```
POST /kura/warm
  │
  ├─ rebuild stations  ┐
  ├─ rebuild lines     ├ sequential, same request
  └─ rebuild products  ┘
  │
  └─ 200 OK
     {
       "message": "All tables warmed.",
       "tables": {
         "stations": {"status": "ok", "version": "v1.0.0"},
         "lines":    {"status": "ok", "version": "v1.0.0"}
       }
     }
```

#### strategy: queue ⭐ recommended for production

Dispatches one `RebuildCacheJob` per table as a **Bus batch**. Returns immediately (202).
Queue workers process tables in parallel.

```
POST /kura/warm
  │
  ├─ Bus::batch([StationsJob, LinesJob, ProductsJob])->dispatch()
  └─ 202 Accepted (immediate)
     {
       "message": "Rebuild dispatched.",
       "batch_id": "550e8400-e29b-41d4-a716-446655440000",
       "tables": {
         "stations": {"status": "dispatched", "version": "v1.0.0"},
         "lines":    {"status": "dispatched", "version": "v1.0.0"}
       }
     }

  [Queue Workers — parallel]
    Worker 1: RebuildCacheJob(stations) → rebuild
    Worker 2: RebuildCacheJob(lines)    → rebuild
    Worker 3: RebuildCacheJob(products) → rebuild
```

### batch_id

`batch_id` is a UUID auto-generated by Laravel when `Bus::batch()->dispatch()` is called.
It is stored in the `job_batches` table.

Use the status endpoint to poll progress:

```
GET /kura/warm/status/{batchId}
Authorization: Bearer {KURA_WARM_TOKEN}

Response 200:
{
  "batch_id": "550e8400-e29b-41d4-a716-446655440000",
  "total":     3,
  "pending":   1,
  "failed":    0,
  "finished":  false,
  "cancelled": false
}

Response 404: {"message": "Batch not found."}
```

Internally `WarmStatusController` depends on `BatchFinderInterface` (not `Bus::findBatch()` directly),
which makes it easy to swap in a fake implementation for tests without Mockery.

**Required migration** (only when using `strategy: queue`):

```bash
php artisan queue:batches-table
php artisan migrate
```

Without this migration, `Bus::batch()->dispatch()` will throw an error.

---

## TTL

```php
'ttl' => [
    'pks'        => 3600,   // 1 hour (shortest. rebuild trigger)
    'record'     => 4800,   // 1 hour 20 minutes
    'index'      => 4800,   // 1 hour 20 minutes
    'pks_jitter' => 600,    // random 0–600s added to pks TTL (thundering herd prevention)
],
```

### Relationships

```
pks (3600) < record / index / cidx (4800)
```

- **pks expires first** → rebuild trigger
- **record/index are still alive** → can respond to queries during rebuild
- Index structure is always available from `Loader::indexes()` (no APCu dependency)

### Write Rules

**All keys use `apcu_store` uniformly.**

- `apcu_store` sets expiration at current time + TTL. Each re-store resets (effectively extends) the expiration
- When lost data is recreated, TTL is extended simultaneously
- `apcu_add` is not used

---

## Config

```php
// config/kura.php
return [
    'prefix' => 'kura',

    'ttl' => [
        'pks'        => 3600,   // shortest — expiry triggers rebuild
        'record'     => 4800,
        'index'      => 4800,
        'pks_jitter' => 600,    // random 0–600s added to pks TTL (thundering herd prevention)
    ],

    'lock_ttl' => 60,  // Rebuild lock TTL (seconds). Set to 1.5–2x the expected Loader execution time

    'rebuild' => [
        'strategy' => 'sync',   // 'sync' | 'queue' | 'callback'
        'queue' => [
            'connection' => null,  // null = default connection
            'queue'      => null,  // null = default queue
            'retry'      => 3,
        ],
    ],

    'warm' => [
        'enabled'           => false,
        'token'             => env('KURA_WARM_TOKEN', ''),
        'path'              => 'kura/warm',
        'controller'        => \Kura\Http\Controllers\WarmController::class,
        'status_controller' => \Kura\Http\Controllers\WarmStatusController::class,
    ],

    'tables' => [
        // Only when per-table overrides are needed
        // 'products' => [
        //     'ttl' => ['record' => 7200],
        // ],
    ],
];
```

---

## Key Structure

```
{prefix}:{table}:{version}:ids                     — Full ID list [id, ...]
{prefix}:{table}:{version}:record:{id}             — Single record (associative array)
{prefix}:{table}:{version}:idx:{col}               — Index (single key per column)
{prefix}:{table}:{version}:cidx:{col1|col2}        — Composite index (hashmap)
{prefix}:{table}:lock                               — Rebuild lock (version-independent)
```

Default (prefix=`kura`):
```
kura:products:v1.0.0:ids
kura:products:v1.0.0:record:1
kura:products:v1.0.0:idx:country
kura:products:v1.0.0:idx:price
kura:products:v1.0.0:cidx:country|category    — composite index
```
