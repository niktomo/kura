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
│   └── ReferenceQueryBuilderInterface.php
├── Console/
│   └── RebuildCommand.php             artisan kura:rebuild
├── Index/
│   ├── IndexDefinition.php            Index definition DTO (unique / non-unique)
│   ├── IndexBuilder.php               Index construction (sorting, chunk splitting, composite)
│   ├── IndexResolver.php              Candidate ID resolution from indexes
│   └── BinarySearch.php               Binary search on sorted indexes
├── Jobs/
│   └── RebuildCacheJob.php            Async cache rebuild job
├── Loader/
│   └── LoaderInterface.php            Abstract interface for data retrieval (implementation in separate package)
├── Store/
│   ├── StoreInterface.php             Abstract interface for APCu operations
│   ├── ApcuStore.php                  Production APCu implementation
│   └── ArrayStore.php                 In-memory implementation for tests
├── Version/
│   ├── VersionResolverInterface.php   Common interface for version resolution
│   ├── DatabaseVersionResolver.php    Resolves from DB reference_versions table
│   ├── CsvVersionResolver.php         Resolves from CSV versions.csv
│   └── CachedVersionResolver.php      Decorator (cached via APCu + PHP var)
└── Support/
    ├── RecordCursor.php               Generator-based cursor (streaming, sorted, random)
    └── WhereEvaluator.php             Stateless where-condition evaluator (static methods)
```

---

## APCu Key Structure

```
{prefix}:{table}:{version}:meta                    Meta information (columns + indexes + composites)
{prefix}:{table}:{version}:ids                     Full ID list [id, ...]
{prefix}:{table}:{version}:record:{id}             Single record (associative array)
{prefix}:{table}:{version}:idx:{col}               Index (no chunking)
{prefix}:{table}:{version}:idx:{col}:{chunk}       Index (chunked)
{prefix}:{table}:{version}:cidx:{col1|col2}        Composite index (hashmap)
{prefix}:{table}:lock                               Rebuild lock (version-independent)
```

### TTL Strategy

| Key | TTL | Purpose |
|------|-----|------|
| `ids` | Short (e.g., 3600s) | Expiration triggers full rebuild |
| `meta` | Long (e.g., 4800s) | Expiration → full scan + rebuild |
| `record:*` | Long (e.g., 4800s) | Expiration + present in ids → full rebuild |
| `index` | Long (e.g., 4800s) | Expiration → full scan + rebuild |
| `cidx` | Long (e.g., 4800s) | Expiration → full scan + rebuild |

TTL is configured in `config/kura.php`. `ids` has the shortest TTL (serving as the rebuild trigger).

### Version Management

Versions are resolved via `VersionResolverInterface`.

- `DatabaseVersionResolver` — DB `reference_versions` table (id, version, activated_at)
- `CsvVersionResolver` — CSV versions.csv (id, version, activated_at)
- `CachedVersionResolver` — Decorator. Cached via APCu + PHP var (default 5 minutes)

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
       └─ apcu_store({version}:idx:*)        ← Built in Phase 2
       └─ apcu_store({version}:cidx:*)       ← Built in Phase 2
       └─ apcu_store({version}:meta)         ← Built in Phase 2
```

### Self-Healing During Query Execution

```
ReferenceQueryBuilder::get()
  ├─ ids present + meta present → Normal query (uses indexes)
  ├─ ids present + meta missing → Responds via full scan + dispatches rebuild
  ├─ ids missing → Falls back to Loader directly + dispatches rebuild
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
  ├─ Role: Per-table cache management. Retrieves ids / record / meta & triggers rebuild
  ├─ Dependencies: StoreInterface, LoaderInterface
  └─ Responsibilities:
       ├─ ids() — Returns false if ids key is missing
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
  └─ getMeta / putMeta
     getIds / putIds
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

Implementations are in separate packages (CsvLoader, EloquentLoader, etc.)
```

### Index Layer

```
IndexDefinition
  └─ unique() / nonUnique() static factory
     columns: list<string>, unique: bool

IndexBuilder
  └─ Builds indexes during rebuild
     buildSorted(): [[value, [ids]], ...] sorted list
     buildChunked(): chunk splitting
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
```

### Class Dependency Diagram

```
ReferenceQueryBuilder
  └── CacheProcessor
        ├── CacheRepository
        │     ├── StoreInterface ←── ApcuStore / ArrayStore
        │     └── LoaderInterface ←── Implemented in separate package
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
    ├── defines.csv              column, type, description
    ├── indexes.csv              columns, unique
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

`data.csv` requires a `version` column. The CsvLoader loads rows where `version = currentVersion` or `version IS NULL`. Rows with a null `version` are always loaded (shared across all versions).

```csv
id,name,price,version
1,Widget A,9.99,
2,Widget B,19.99,v1.0.0
3,Widget C,29.99,v1.1.0
```

### defines.csv

```csv
column,type,description
id,int,Product ID
name,string,Product name
price,float,Price
active,bool,Active flag
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
