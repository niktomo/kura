> Japanese version: [overview-ja.md](overview-ja.md)

# Kura — Structure and Usage

## Overview

Kura is a Laravel package that caches reference data in APCu and provides a search interface using the same API as Laravel's `QueryBuilder`.

- Retrieves data at high speed from in-memory cache without issuing DB queries
- Data retrieval is Generator-based for low memory usage
- Cache construction is delegated to Laravel Queue (non-blocking)
- Automatically self-heals if the cache is evicted

---

## Directory Structure

```
src/
├── ReferenceQueryBuilder.php          Main fluent query builder
├── CacheProcessor.php                 Processor pattern — handles execution
├── CacheRepository.php                Per-table cache management & self-healing
├── KuraManager.php                  Central registry for table registration, queries & rebuild
├── KuraServiceProvider.php          Laravel service provider
├── Concerns/
│   ├── BuildsWhereConditions.php      where-related methods
│   ├── BuildsOrderAndPagination.php   orderBy / paginate methods
│   └── ExecutesQueries.php            Execution methods like get / first / find
├── Contracts/
│   ├── ReferenceQueryBuilderInterface.php
│   ├── VersionResolverInterface.php   Common interface for version resolution
│   └── VersionsLoaderInterface.php    Interface for loading all version rows
├── Console/
│   ├── RebuildCommand.php             artisan kura:rebuild
│   └── TokenCommand.php               artisan kura:token (generate Bearer token)
├── Exceptions/
│   ├── CacheInconsistencyException.php
│   ├── RecordsNotFoundException.php
│   └── MultipleRecordsFoundException.php
├── Http/
│   ├── Controllers/
│   │   ├── WarmController.php         POST /kura/warm (invokable)
│   │   └── WarmStatusController.php   GET /kura/warm/status/{batchId} (invokable)
│   ├── Batch/
│   │   ├── BatchFinderInterface.php   Abstraction for batch lookup (testable)
│   │   ├── BatchSummary.php           Read-only DTO for batch progress
│   │   └── LaravelBatchFinder.php     Production impl wrapping Bus::findBatch()
│   └── Middleware/
│       └── KuraAuthMiddleware.php     Bearer token auth for warm routes
├── Index/
│   ├── IndexDefinition.php            Index definition DTO (unique / non-unique)
│   ├── IndexBuilder.php               Index construction (sorting, composite)
│   ├── IndexResolver.php              Candidate ID resolution from indexes
│   └── BinarySearch.php               Binary search on sorted indexes
├── Jobs/
│   └── RebuildCacheJob.php            Async cache rebuild job
├── Loader/
│   ├── LoaderInterface.php            Abstract interface for data retrieval
│   ├── TableDefinitionReader.php      Reads table.yaml (columns, indexes, primary key)
│   ├── CsvLoader.php                  CSV-based loader (data.csv + table.yaml)
│   ├── CsvVersionResolver.php         Loads all version rows from versions.csv (VersionsLoaderInterface)
│   ├── EloquentLoader.php             Eloquent model-based loader (reads defines/indexes from tableDirectory)
│   ├── QueryBuilderLoader.php         Query builder-based loader (reads defines/indexes from tableDirectory)
│   └── StaticVersionResolver.php      Fixed version string resolver (for simple setups and tests)
├── Store/
│   ├── StoreInterface.php             Abstract interface for APCu operations
│   ├── ApcuStore.php                  Production APCu implementation
│   └── ArrayStore.php                 In-memory implementation for tests
├── Version/
│   ├── DatabaseVersionResolver.php    Loads all version rows from DB table (VersionsLoaderInterface)
│   ├── CachedVersionResolver.php      Caches all rows in APCu; filters by now() at resolve time
│   └── SystemClock.php                PSR-20 ClockInterface implementation (returns current time)
└── Support/
    ├── RecordCursor.php               Generator-based cursor (streaming, sorted, random)
    └── WhereEvaluator.php             Stateless where-condition evaluator (static methods)
```

---

## APCu Key Structure

```
{prefix}:{table}:{version}:ids                     Full PK list [id, ...]
{prefix}:{table}:{version}:record:{id}             Single record (associative array)
{prefix}:{table}:{version}:idx:{col}               Index (one key per column)
{prefix}:{table}:{version}:cidx:{col1|col2}        Composite index (hashmap)
{prefix}:{table}:lock                               Rebuild lock (version-independent)
```

Index structure (which columns are indexed, which composites exist) is **not stored in APCu**.
It is derived at query time from `LoaderInterface::indexes()`, which is instance-cached in the Loader.

### TTL Strategy

| Key | TTL | Purpose |
|------|-----|------|
| `pks` | Short (e.g., 3600s) | Expiration triggers full rebuild |
| `record:*` | Long (e.g., 4800s) | Expiration + present in ids → full rebuild |
| `index` | Same as `pks` (default) | Expiration → `IndexInconsistencyException` → rebuild |
| `cidx` | Same as `pks` (default) | Expiration → `IndexInconsistencyException` → rebuild |

TTL is configured in `config/kura.php`. `pks` has the shortest TTL (serving as the rebuild trigger). `index` defaults to the same TTL as `pks` (including jitter) so they expire together.

If an index is declared in `LoaderInterface::indexes()` but the APCu key is missing (`IndexInconsistencyException`), Kura triggers a rebuild and falls back to the Loader — the same recovery path as `CacheInconsistencyException`.

### Version Management

Versions are resolved via `VersionResolverInterface`.

- `DatabaseVersionResolver` (`src/Version/`) — implements `VersionsLoaderInterface`; loads all rows from DB `reference_versions` table
- `CsvVersionResolver` (`src/Loader/`) — implements `VersionsLoaderInterface`; loads all rows from versions.csv
- `CachedVersionResolver` (`src/Version/`) — wraps `VersionsLoaderInterface`; caches all rows in APCu, filters by `clock->now()` at each `resolve()` call (default TTL: 5 minutes)

When the version changes, cache keys change accordingly, and old caches naturally expire via TTL.

### Middleware

**`KuraVersionMiddleware`** (sample in `examples/`) runs at the beginning of each request to pin the version for the entire request lifecycle.
The version can be explicitly specified via the `X-Reference-Version` header.

```
HTTP Request
  └─ KuraVersionMiddleware
       └─ Resolves the active version for each table & binds to the container
  └─ Controller
       └─ All subsequent queries use the bound version
```

### Cache Write Rules

**All keys use `apcu_store` uniformly.**

- `apcu_store` overwrites. Each re-store resets (extends) the TTL
- `apcu_add` is used only for locking purposes

---

## Data Flow

### Initial Load / Cache Rebuild

```
artisan kura:rebuild
  └─ KuraManager::rebuild($table)

RebuildCacheJob (async)
  └─ Loader::load()                     ← Streams records via Generator
       └─ apcu_store({version}:record:{id})  ← Writes one record at a time
       └─ apcu_store({version}:ids)          ← Bulk write after loop
       └─ apcu_store({version}:idx:*)        ← Written inside the lock (after loop)
       └─ apcu_store({version}:cidx:*)       ← Written inside the lock (after loop)
```

### Self-Healing During Query Execution

```
ReferenceQueryBuilder::get()
  ├─ pks present → Normal query (index structure from Loader::indexes())
  ├─ pks missing → Falls back to Loader directly + dispatches rebuild
  └─ record missing + present in ids → CacheInconsistencyException → rebuild
```

---

## Class Structure and Responsibilities

### Core Classes

```
ReferenceQueryBuilder
  ├─ Role: Entry point for the fluent API. Provides where / orderBy / get / paginate, etc.
  ├─ Dependencies: CacheRepository, CacheProcessor
  └─ Traits:
       ├─ BuildsWhereConditions      — where / orWhere / whereBetween / whereIn / whereRowValuesIn, etc.
       ├─ BuildsOrderAndPagination   — orderBy / limit / offset / paginate / simplePaginate
       └─ ExecutesQueries            — get / first / find / sole / count / min / max / sum / avg, etc.

CacheProcessor
  ├─ Role: Executes queries against the cache (select, cursor)
  ├─ Dependencies: CacheRepository, StoreInterface
  └─ Responsibilities:
       ├─ resolveIds() — Narrows candidate IDs using IndexResolver
       ├─ compilePredicate() — Converts where conditions into closures
       └─ cursor() / select() — Record retrieval & self-healing

CacheRepository
  ├─ Role: Per-table cache management. Retrieves ids / record & triggers rebuild
  ├─ Dependencies: StoreInterface, LoaderInterface
  └─ Responsibilities:
       ├─ pks() — Returns false if pks key is missing
       ├─ find(id) — Retrieves a record
       └─ rebuild() — Iterates through Loader to build entire cache

KuraManager
  ├─ Role: Central registry for table registration, queries & rebuild
  └─ Responsibilities:
       ├─ query($table) — Returns a ReferenceQueryBuilder
       ├─ rebuild($table) — Rebuilds cache for the specified table
       └─ setVersionOverride() — Allows external version specification (e.g., from artisan)

RecordCursor (Support)
  ├─ Role: Generator-based cursor. Handles streaming, sorted, and random traversal
  └─ Responsibilities: Iterates over IDs, delegates predicate evaluation to WhereEvaluator

WhereEvaluator (Support)
  ├─ Role: Stateless where-condition evaluator
  └─ Responsibilities: evaluate(record, wheres) — pure static evaluation of the where tree
```

### Store Layer (APCu Abstraction)

```
StoreInterface
  └─ getPks / putPks
     getRecord / putRecord
     getIndex / putIndex
     getCompositeIndex / putCompositeIndex

ApcuStore  — Production use. Writes via apcu_store (overwrite + TTL extension)
ArrayStore — Test use. Operates on in-memory PHP associative arrays
```

### Loader Layer (Data Source Abstraction)

```
LoaderInterface
  └─ load(): Generator<int, array<string, mixed>>   Yields all records
     columns(): array<string, string>                Column name → type
     indexes(): list<array{columns, unique}>         Index definitions
     version(): string|int|Stringable                Version identifier

CsvLoader / EloquentLoader / QueryBuilderLoader are included in src/Loader/

CsvLoader file layout:
  {tableDirectory}/
    data.csv      — rows with a version column (required)
    table.yaml    — column types, index definitions, primary key

table.yaml format:
  primary_key: id          # optional, defaults to 'id'
  columns:
    id: int
    prefecture: string     — supported types: int, float, bool, string
    email: string
    country: string
  indexes:                 # optional
    - columns: [prefecture]
      unique: false        — single-column index
    - columns: [email]
      unique: true         — unique index
    - columns: [country, type]
      unique: false        — composite index

Auto-discovery (config: kura.csv.auto_discover = true):
  KuraServiceProvider scans kura.csv.base_path for subdirectories.
  Any subdirectory containing data.csv is registered as a CsvLoader table.
  versions.csv is shared at base_path/versions.csv.
  primary_key overrides: config('kura.tables.{table}.primary_key')
```

### Index Layer

```
IndexDefinition
  └─ unique() / nonUnique() static factory
     columns: list<string>, unique: bool

IndexBuilder
  └─ Builds indexes during rebuild
     buildSorted(): [[value, [ids]], ...] sorted list
     buildCompositeIndexes(): composite index hashmap
     When a composite is declared, single-column indexes for each column are also created automatically

IndexResolver
  └─ Resolves candidate IDs from indexes at query time
     resolveIds(): AND/OR across multiple conditions
     tryCompositeIndex(): O(1) resolution of AND equality via composite index
     resolveRowValuesIn(): Accelerates ROW constructor IN using composite indexes

BinarySearch
  └─ Search on sorted [[value, [ids]], ...]
     equal / greaterThan / lessThan / between
```

### Queue Jobs

```
RebuildCacheJob
  └─ Delegates to KuraManager::rebuild()
     tries: 3 (overridable via config)
     Executes per table
     Optional $version parameter for version override
```

### HTTP Layer

```
WarmController (POST /kura/warm)
  └─ Rebuilds cache for all registered tables (or specified subset)
     strategy=sync  → sequential rebuild, returns 200
     strategy=queue → Bus::batch() dispatch, returns 202 with batch_id
     Customizable: publish with vendor:publish --tag=kura-controllers

WarmStatusController (GET /kura/warm/status/{batchId})
  └─ Returns progress of a queued warm batch
     Depends on BatchFinderInterface (not Bus facade directly — testable)

BatchFinderInterface / BatchSummary / LaravelBatchFinder
  └─ Abstraction over Bus::findBatch()
     BatchSummary: id, totalJobs, pendingJobs, failedJobs, finished, cancelled
     Swap LaravelBatchFinder with a fake in tests (no Mockery needed)

KuraAuthMiddleware
  └─ Validates Authorization: Bearer {KURA_WARM_TOKEN}
     Applied to both warm routes automatically
```

### Class Dependency Diagram

```
ReferenceQueryBuilder
  └── CacheProcessor
        ├── CacheRepository
        │     ├── StoreInterface ←── ApcuStore / ArrayStore
        │     └── LoaderInterface ←── CsvLoader / EloquentLoader (src/Loader/)
        └── IndexResolver
              └── StoreInterface

KuraManager
  └── CacheRepository (per table)

RecordCursor
  └── WhereEvaluator (standalone — stateless, no dependencies)
```

---

## CSV File Structure

One table = one directory. `versions.csv` is shared across all tables (placed in the parent directory).

```
data/
├── versions.csv                 id, version, activated_at
└── products/
    ├── table.yaml               column types, indexes, primary key
    └── data.csv                 data rows with version column
```

### versions.csv

```csv
id,version,activated_at
1,v1.0.0,2024-01-01 00:00:00
2,v1.1.0,2024-06-01 00:00:00
```

The most recent version where `activated_at <= current time` is used.

### data.csv

`data.csv` requires a `version` column. The CsvLoader loads rows where `version <= currentVersion` or `version IS NULL`. Rows with a null `version` are always loaded (shared across all versions). Rows with a future version are skipped.

```csv
id,name,price,version
1,Widget A,9.99,
2,Widget B,19.99,v1.0.0
3,Widget C,29.99,v1.1.0
```

**Empty cells are treated as `null`.** An empty cell in `data.csv` (e.g. the `version` column of row 1 above) is stored as `null` in the record. This follows standard CSV convention and is consistent with MySQL `NULL` semantics used throughout Kura.

### table.yaml

```yaml
primary_key: id          # optional, defaults to 'id'
columns:
  id: int
  name: string
  price: float
  active: bool
indexes:                 # optional
  - columns: [name]
    unique: false
```

Supported types: `int` / `float` / `bool` / `string`

---

## Usage

### 1. Index Definition

```php
use Kura\Index\IndexDefinition;

$indexes = [
    IndexDefinition::nonUnique('country'),          // Non-unique index
    IndexDefinition::unique('code'),                // Unique index
    IndexDefinition::nonUnique('country', 'type'),  // Composite index
];
```

### 2. Building the Repository and Query Builder

```php
use Kura\CacheProcessor;
use Kura\CacheRepository;
use Kura\ReferenceQueryBuilder;
use Kura\Store\ApcuStore;

$store = new ApcuStore;

$repository = new CacheRepository(
    table: 'products',
    primaryKey: 'id',
    store: $store,
    loader: $loader,   // LoaderInterface implementation
);

$processor = new CacheProcessor($repository, $store);

$builder = new ReferenceQueryBuilder(
    table: 'products',
    repository: $repository,
    processor: $processor,
);
```

### 3. Querying

```php
// Get all records
$products = $builder->get();

// Filter with conditions
$jpProducts = $builder->where('country', 'JP')->get();

// Sorting & pagination
$page = $builder->orderBy('name')->paginate(20, page: 2);

// Single record retrieval
$product = $builder->find(42);
$product = $builder->where('code', 'ABC-001')->sole();

// Aggregations
$max = $builder->where('active', true)->max('price');
$avg = $builder->avg('price');

// ROW constructor IN (Kura extension)
$result = $builder
    ->whereRowValuesIn(['user_id', 'item_id'], [[1, 10], [2, 20]])
    ->get();
```

---

## Implementing a Custom Loader

Simply implement `LoaderInterface` to support any data source.

```php
use Kura\Loader\LoaderInterface;

class EloquentLoader implements LoaderInterface
{
    public function __construct(private string $modelClass) {}

    public function load(): \Generator
    {
        foreach ($this->modelClass::cursor() as $model) {
            yield $model->toArray();
        }
    }

    public function columns(): array
    {
        return ['id' => 'int', 'name' => 'string', 'price' => 'float'];
    }

    public function indexes(): array
    {
        return [
            ['columns' => ['name'], 'unique' => false],
        ];
    }

    public function primaryKey(): string
    {
        return 'id';
    }

    public function version(): string
    {
        return 'v1.0.0';
    }
}
```

---

## Related Documents

- [`docs/cache-architecture.md`](./cache-architecture.md) — Cache design details (TTL, Queue, self-healing)
- [`docs/laravel-builder-coverage.md`](./laravel-builder-coverage.md) — API coverage table compared to Laravel QueryBuilder
