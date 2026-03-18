> Japanese version: [cache-architecture-ja.md](cache-architecture-ja.md)

# Cache Architecture

## Overview

Kura caches reference/master data in APCu and queries it via `ReferenceQueryBuilder`.
Data loading is performed through `LoaderInterface`, with Loader implementations in separate packages.

> **This document is the implementation design specification.** For overall structure and usage, see `overview.md`.
>
> Related docs:
> - [Version Management](version-management.md) — version drivers, middleware, deployment flow
> - [Index Guide](index-guide.md) — index types, chunking, composite indexes, range queries
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
            │    │  find(), ids(), meta(), reload()
            │    │  APCu read/write + Self-Healing
            │    │
            │    ├─ StoreInterface (APCu abstraction)
            │    │    ├─ ApcuStore (production)
            │    │    └─ ArrayStore (testing)
            │    │
            │    └─ LoaderInterface (data loading)
            │         └─ Implemented in separate packages (CsvLoader, EloquentLoader, etc.)
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
- **LoaderInterface is defined in Kura**: Has `load()`, `columns()`, `indexes()`.
  Implementations (CSV, DB, etc.) are in separate packages

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
     * Version identifier included in cache keys.
     *
     * @return string|int|Stringable
     *
     * Examples: 'v1.0.0', 20260313, new SemVer(1, 0, 0)
     */
    public function version(): string|int|\Stringable;
}
```

- `load()`: Generator for low memory usage. For DB, equivalent to paginated chunk reads
- `columns()`: Column names and type definitions (`'int'`, `'string'`, `'float'`, `'bool'`). Used for meta construction
- `indexes()`: Declares single-column and composite indexes. Loader's responsibility
  - `unique: true` → unique index (returns single ID)
  - `unique: false` → non-unique index (returns ID list)
  - Composite indexes specify columns in order. Single-column indexes for each column are auto-created
- `version()`: Version identifier included in cache keys. Returns `string|int|Stringable`
  - The Loader manages the data source version (CSV filename, DB timestamp, etc.)
  - When the version changes, cache keys change, and old caches naturally expire via TTL

---

## Cache Types

APCu stores **5 types** of data.

| Type | Purpose | Behavior on loss |
|------|---------|-----------------|
| **meta** | Column definitions + index structure + composites | Full rebuild |
| **ids** | List of all IDs | Full rebuild |
| **record** | Single record data (associative array) | Check existence in ids → full rebuild if expected |
| **index** | Search indexes (ID lists) | Respond via full scan + full cache rebuild |
| **cidx** | Composite index (multi-column hashmap) | Respond via full scan + full cache rebuild |

---

## 1. meta

Table metadata. Holds column definitions and index structure.

```php
kura:products:v1.0.0:meta → [
    'columns' => [
        'id'      => 'int',
        'name'    => 'string',
        'country' => 'string',
        'price'   => 'int',
    ],
    'indexes' => [
        // No chunking (default)
        'country' => [],

        // Chunked (when chunk_size is set in config)
        'price' => [
            ['min' => 100,  'max' => 500],
            ['min' => 501,  'max' => 1000],
            ['min' => 1001, 'max' => 3000],
        ],
    ],
    'composites' => ['country|category'],
]
```

### Purpose

- **columns**: Column names and type definitions. Used for type determination during index construction
- **indexes**: Which columns have indexes and how chunks are split
  - `[]` (empty array) → no chunking. Index is a single key
  - Array present → chunked. Each element's min/max represents the range
- **composites**: List of composite index names (`"col1|col2"` format)

### Characteristics

- If meta is lost → **full rebuild**

---

## 2. ids

List of all IDs.

```php
kura:products:v1.0.0:ids → [1, 2, 3, ...]
```

### Purpose

- Candidate ID set for full scans
- Reference for determining whether a missing record "should exist"
- Converted to hashmap via `array_flip` when intersection is needed

### Characteristics

- If ids is lost → **full rebuild**
- Has the shortest TTL among the 5 types (serves as rebuild trigger)

---

## 3. record

Data for a single record. Stored as-is as an associative array.

```php
kura:products:v1.0.0:record:1 → ['id' => 1, 'name' => 'Widget A', 'country' => 'JP', 'price' => 500]
```

- Records are self-contained (readable without meta)
- `find(id)` is the most frequent operation → can return immediately without meta
- meta focuses on index structure management

### Self-Healing on Record Loss

```
Record retrieval
  └─ Hit → normal response
  └─ Miss
       └─ ids[id] exists → expected data is missing → full rebuild
       └─ ids[id] doesn't exist → truly non-existent data → return null
```

---

## 4. index

Search indexes. Structure for looking up IDs from column values (single column).

### No Chunking (Default)

One key per value. Value → IDs mapping.

```php
kura:products:v1.0.0:idx:country → [
    ['JP', [1, 3, 6]],
    ['US', [2, 4, 8]],
    ['DE', [5, 7]],
]
// Sorted by value in ascending order
```

- Equality search `=` → binary search O(log n)
- Range search `>`, `<`, `BETWEEN` → binary search to find start position → slice

### Chunked (When chunk_size Is Set in Config)

For large datasets. Index is split by unique value count into chunk_size groups, with each chunk's min/max stored in meta.
chunk_size unit is **number of unique values** (distinct values per chunk).

```php
// Definition in meta
'price' => [
    ['min' => 100,  'max' => 500],    // chunk 0
    ['min' => 501,  'max' => 1000],   // chunk 1
    ['min' => 1001, 'max' => 3000],   // chunk 2
]

// Each chunk key
kura:products:v1.0.0:idx:price:0 → [
    [100, [3, 7]],       // IDs for price=100
    [200, [1, 12]],      // IDs for price=200
    [500, [6, 9, 15]],   // IDs for price=500
]
kura:products:v1.0.0:idx:price:1 → [
    [501, [2, 5]],
    [700, [8, 14]],
    [1000, [4, 11]],
]
```

- Chunks also use `[[value, [ids]], ...]` structure (sorted by value ascending)
- Both equality and range queries can resolve IDs without fetching records
- **The same value always stays in the same chunk** (never spans chunk boundaries)

#### Chunk Splitting Algorithm

```
1. Sort index data [value → [ids]] by value ascending
2. Slice the sorted list into chunks of chunk_size (number of unique values)
3. Record first value = min, last value = max for each chunk in meta
```

### Index Query Behavior

```
where('price', '=', 700)
  └─ Check meta → hits chunk 1 (501–1000)
       └─ Binary search within chunk 1 → immediately get [8, 14]

where('price', '>', 800)
  └─ Check meta → chunk 1 + chunk 2 overlap
       └─ Binary search within each chunk → collect matching IDs

where('price', 'BETWEEN', [200, 600])
  └─ Check meta → chunk 0 + chunk 1 overlap
       └─ Range slice within each chunk → collect matching IDs
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
For CSV, read from defines.csv or indexes.csv. For DB, derive from schema.
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

## 5. Composite Index (cidx)

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
  ├─ No lock + ids present + meta present
  │    → Normal query (uses indexes)
  │
  ├─ No lock + ids present + meta missing
  │    → ids + full scan (index being built or index lost)
  │    → Queue dispatch to rebuild index + meta
  │
  └─ No lock + ids missing
       → Queue dispatch for full cache rebuild
       → Loader→generator → where evaluation → return
```

- During rebuild, cache consistency cannot be guaranteed, so respond directly from Loader
- Loader uses generators for low memory usage (equivalent to paginated chunk reads for DB)

### Rebuild Job

**Cache is built in 2 phases.** record + ids complete first, making queries possible.

```
Phase 1 (APCu locked):
  Get generator from Loader->load()
  In a single loop:
    ├─ apcu_store records (one at a time)
    ├─ Collect ids [id, ...]
    └─ Collect index data [col → [value → [id, ...]]]
  After loop:
    └─ apcu_store ids
  Release lock ← queries now possible (full scan mode)

Phase 2 (no lock):
  Build indexes (sort + chunk split) → apcu_store
  Build meta → apcu_store
  ← index-accelerated queries now possible
```

- **Phase 1**: Builds record + ids. While locked, all queries fall back to Loader
- **Phase 2**: Builds index + meta. Lock released, so queries respond via full scan
- Records are `apcu_store`d one at a time during the loop for immediate availability
- ids are bulk `apcu_store`d after the loop

apcu_store overwrites. Even if existing data is present, it's overwritten with the same data and TTL is reset (extended).
No existence check needed. Simply rewrite everything from Loader.

### Query Execution Flow (CacheProcessor)

cursor() returns records one at a time via generator. get() uses cursor() internally and returns an array.
On record loss, cursor() throws an exception; get() catches it and falls back to Loader.

```
where('country', 'JP')->where('price', '>', 500)->get()

⓪ Lock check + ids/meta existence check
   ├─ Lock present → Loader fallback
   ├─ ids missing → Loader fallback + rebuild dispatch
   └─ ids present → continue cache query

① Candidate ID resolution (resolveIds)
   meta present → determine if conditions can be narrowed via index
     ├─ Yes → get ID set from index (intersection / union)
     └─ No → ids (all)
   meta missing → all ids (full scan) + rebuild dispatch

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
  ├─ ids present + meta present → normal query (uses indexes)
  │
  ├─ ids present + meta missing → respond via full scan + Queue dispatch for index/meta rebuild
  │
  ├─ ids missing → respond from Loader directly + Queue dispatch for full rebuild
  │
  ├─ record loss (detected mid-loop; extremely rare)
  │    → cursor(): CacheInconsistencyException + Queue dispatch
  │    → get(): catch exception → Loader fallback
  │
  └─ index loss (meta exists but index key is gone)
       → respond via full scan + Queue dispatch for index rebuild
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

### Estimating apc.shm_size

```
Required memory ≒ Σ(per-record size × record count × 2–3x)

Breakdown:
  record:  serialize(associative array) × record count
  ids:     ~8–12 bytes/entry after serialization ([id, ...] list format)
  index:   [[value, [ids]], ...] × column count
  meta:    a few KB (negligible)

× 2–3x: APCu internal overhead (hash table, memory fragmentation)
```

Guidelines:
- 1 record at 200 bytes × 50,000 records → records alone ~10MB
- Including index, ids, and overhead: **~30–50MB**
- Sum across all cached tables
- Check actual usage with `apcu_cache_info('user')` and maintain usage below 80%

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
public function cursor(Builder $builder): Generator
{
    $meta = $repository->meta();
    $ids = $repository->ids();

    // Lock present → cache consistency not guaranteed
    if ($repository->isLocked()) {
        yield from $this->cursorFromLoader($builder);
        return;
    }

    if ($ids === false) {
        // ids missing → Loader fallback + full rebuild dispatch
        $this->dispatchRebuild($table, $version);
        yield from $this->cursorFromLoader($builder);
        return;
    }

    // meta missing → can't use indexes. full scan + index/meta rebuild dispatch
    if ($meta === false) {
        $this->dispatchRebuild($table, $version);
    }

    // meta present → narrow via indexes, meta missing → all ids
    $candidateIds = $meta !== false
        ? $this->resolveIds($builder, $ids, $meta)
        : $ids;
    $predicate = $this->compilePredicate($builder);

    $idsMap = array_fill_keys($ids, true);

    foreach ($candidateIds as $id) {
        $record = $repository->find($id);

        if ($record === null && isset($idsMap[$id])) {
            $this->dispatchRebuild($table, $version);
            throw new CacheInconsistencyException("Record {$id} missing");
        }

        if ($record !== null && $predicate($record)) {
            yield $record;
        }
    }
}

// CacheProcessor::select() — called by get()
public function select(Builder $builder): array
{
    try {
        return iterator_to_array($this->cursor($builder));
    } catch (CacheInconsistencyException) {
        return $this->selectFromLoader($builder);
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
- Rebuild complete → lock expires naturally via TTL (not explicitly deleted = fail-safe)

※ `apcu_add` is used only for locking. All data writes use `apcu_store`.

### Queue Job Principles

**"Extend TTL if exists, create if not."**

- Present in APCu → read and re-save (`apcu_store` resets TTL)
- Not in APCu → fetch from Loader, build and save
- Works the same whether everything or only parts are missing
- When Loader is called, all caches (ids, record, meta, index) are rebuilt

**When only ids is lost**: meta/record/index are still alive, so only ids is rebuilt from Loader,
and existing caches get their TTL reset via `apcu_store`. Full reload is not needed.

### Rebuild Strategy

```php
// config/kura.php
'rebuild' => [
    // 'sync'     — synchronous rebuild (no queue needed. single loop for response + cache construction)
    // 'queue'    — synchronous Loader response + async construction via queue
    // 'callback' — custom callback (configured via ServiceProvider)
    'strategy' => 'sync',

    // Settings for strategy = 'queue'
    'queue' => [
        'connection' => null,
        'queue'      => null,
        'retry'      => 3,
    ],
],
```

| strategy | Queue needed | Initial latency | Use case |
|---|---|---|---|
| **sync** | No | Slow (Loader + cache construction) | Small scale, development, no-queue environments |
| **queue** | Yes | Loader fallback only | Recommended for production |
| **callback** | Optional | Custom | Special requirements |

---

## TTL

```php
'ttl' => [
    'ids'    => 3600,    // 1 hour (shortest. rebuild trigger)
    'meta'   => 4800,    // 1 hour 20 minutes
    'record' => 4800,    // 1 hour 20 minutes
    'index'  => 4800,    // 1 hour 20 minutes
],
```

### Relationships

```
ids (3600) < meta / record / index / cidx (4800)
```

- **ids expires first** → rebuild trigger
- **meta is still alive** → index structure is known, query optimization possible
- **record/index are still alive** → can respond to queries during rebuild

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
        'ids'    => 3600,
        'meta'   => 4800,
        'record' => 4800,
        'index'  => 4800,
    ],

    'chunk_size' => null,  // null = no chunking. Set to 10000 etc. for global chunking

    'lock_ttl' => 60,  // Rebuild lock TTL (seconds). Set to 1.5–2x the expected Loader execution time

    'tables' => [
        // Only when per-table overrides are needed
        // 'products' => [
        //     'ttl' => ['record' => 7200],
        //     'chunk_size' => 10000,
        // ],
    ],
];
```

---

## Key Structure

```
{prefix}:{table}:{version}:meta                    — Meta information (columns + indexes + composites)
{prefix}:{table}:{version}:ids                     — Full ID list [id, ...]
{prefix}:{table}:{version}:record:{id}             — Single record (associative array)
{prefix}:{table}:{version}:idx:{col}               — Index (no chunking, single key)
{prefix}:{table}:{version}:idx:{col}:{chunk}       — Index (chunked, chunk number)
{prefix}:{table}:{version}:cidx:{col1|col2}        — Composite index (hashmap)
{prefix}:{table}:lock                               — Rebuild lock (version-independent)
```

Default (prefix=`kura`):
```
kura:products:v1.0.0:meta
kura:products:v1.0.0:ids
kura:products:v1.0.0:record:1
kura:products:v1.0.0:idx:country              — no chunking
kura:products:v1.0.0:idx:price:0              — chunked
kura:products:v1.0.0:idx:price:1
kura:products:v1.0.0:cidx:country|category    — composite index
```
