> Japanese version: [README-ja.md](README-ja.md)

> [!WARNING]
> This package is currently under active development. APIs may change without notice before v1.0.0.

# Kura

[![Tests](https://github.com/niktomo/kura/actions/workflows/tests.yml/badge.svg)](https://github.com/niktomo/kura/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/niktomo/kura.svg)](https://packagist.org/packages/niktomo/kura)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue)](https://www.php.net/)
[![License](https://img.shields.io/github/license/niktomo/kura)](LICENSE)

**Kura** (蔵 — *storehouse*) is a Laravel package that caches reference data in APCu and queries it with a **Laravel QueryBuilder-compatible API**.

Load data once from CSV or DB, store it in APCu, and query it with the same fluent API you already know — **no database queries at runtime**. Index-accelerated lookups keep response times sub-millisecond even with large datasets.

Kura provides a QueryBuilder-compatible API backed by APCu (in-process memory), serving reference data entirely without touching the database at query time. Index-based lookups deliver fast filtered queries; generator-based full scans keep per-request memory use low. The design assumes that the pks list and index data for each table fit within APCu's configured shared memory.

## Why Kura?

- **Familiar API** — `where`, `orderBy`, `paginate`, `find`, `count`, `sum` — same as Laravel's QueryBuilder
- **Sub-millisecond reads** — APCu shared memory, no network round-trips ([see benchmarks](#benchmarks))
- **Low memory footprint** — Generator-based traversal; never loads entire datasets into PHP memory
- **Smart indexes** — Binary search indexes for range queries, composite index hashmaps for O(1) multi-column lookups, automatic chunk splitting for large datasets
- **Self-Healing** — Cache eviction? Kura automatically rebuilds from the data source — your app never sees stale or missing data
- **Version management** — Switch reference data versions seamlessly via DB or CSV
- **Pluggable data sources** — `LoaderInterface` lets you bring any backend: CSV, Eloquent, QueryBuilder, REST API, S3, etc. Built-in loaders included; swap or extend with 5 methods

## Requirements

- PHP 8.2 / 8.3 / 8.4 (8.5+ expected to work)
- Laravel ^11.0 / ^12.0 / ^13.0
- APCu extension (`pecl install apcu`)

> **Laravel Octane is not yet supported.** Persistent processes require per-request state reset (version resolver, override) that is not yet implemented. Octane support is planned for v1.0.

## Installation

```bash
composer require niktomo/kura
php artisan vendor:publish --tag=kura-config
```

---

## Quick Start

### 1. Configure

Edit `config/kura.php` — the key sections for getting started:

```php
// config/kura.php
return [
    'prefix' => 'kura',

    // Version resolution — how Kura determines which data version to use
    'version' => [
        'driver'    => 'csv',                           // 'csv' or 'database'
        'csv_path'  => base_path('data/versions.csv'),  // path to versions.csv
        'cache_ttl' => 300,                             // cache all version rows in APCu for 5 min
    ],

    // Rebuild strategy — what happens when cache is missing
    'rebuild' => [
        'strategy' => 'sync',  // 'sync' | 'queue' (recommended for production) | 'callback'
    ],
];
```

### 2. Prepare your data

Kura supports two data sources: **CSV files** and **Database (Eloquent)**.

#### Option A: CSV files

Organize CSV files by table, with a shared `versions.csv`:

```
data/
├── versions.csv           # shared version registry
└── stations/
    ├── table.yaml         # column types, indexes, primary key
    └── data.csv           # data (version column required)
```

**versions.csv** — controls which version is active:
```csv
id,version,activated_at
1,v1.0.0,2024-01-01 00:00:00
2,v2.0.0,2024-06-01 00:00:00
```

The version with `activated_at <= now` and the latest timestamp is used.

**stations/table.yaml** — column types, index declarations, and primary key:
```yaml
primary_key: id          # optional, defaults to 'id'
columns:
  id: int
  name: string
  prefecture: string
  city: string
  lat: float
  lng: float
  line_id: int
  version: string        # required for version-based filtering
indexes:                 # optional
  - columns: [prefecture]
    unique: false
  - columns: [line_id]
    unique: false
  - columns: [prefecture, city]  # composite index — O(1) multi-column equality
    unique: false
```

Supported types: `int`, `float`, `bool`, `string`

- Composite indexes (`[col1, col2]`) enable O(1) multi-column equality lookups
- If no indexes are needed, omit the `indexes` key entirely

> **Tip:** `primary_key` defaults to `id` — only specify it if your table uses a different column name.

**stations/data.csv** — the actual data, with a `version` column:
```csv
id,name,prefecture,city,lat,lng,line_id,version
1,Tokyo,Tokyo,Chiyoda,35.6812,139.7671,1,
2,Shibuya,Tokyo,Shibuya,35.6580,139.7016,1,
3,Shinjuku,Tokyo,Shinjuku,35.6896,139.7006,1,v1.0.0
4,Osaka,Osaka,Kita,34.7024,135.4959,2,v1.0.0
5,Namba,Osaka,Chuo,34.6629,135.5013,3,v1.0.0
```

The CsvLoader loads rows where `version IS NULL` (always loaded, shared across all versions) or `version <= currentVersion` (past and current versions). Rows where `version > currentVersion` are skipped (future versions not yet active).

#### Option B: Database (Eloquent)

No data CSV needed — load directly from your database. Column definitions and index declarations are read from `table.yaml` in the table directory:

```
data/stations/
└── table.yaml    # column types, index declarations, primary key
```

```php
use Kura\Loader\EloquentLoader;
use Kura\Loader\StaticVersionResolver;

$loader = new EloquentLoader(
    query: Station::query(),
    tableDirectory: base_path('data/stations'),
    resolver: new StaticVersionResolver('v1.0.0'),
);
```

Or with the version-managed resolver (recommended for production):

```php
use Kura\Loader\EloquentLoader;
use Kura\Contracts\VersionResolverInterface;

$loader = new EloquentLoader(
    query: Station::query(),
    tableDirectory: base_path('data/stations'),
    resolver: app(VersionResolverInterface::class),
);
```

#### Option C: Custom Loader

Any data source works — implement `LoaderInterface` with 5 methods:

```php
use Kura\Loader\LoaderInterface;

class MyApiLoader implements LoaderInterface
{
    public function load(): \Generator { /* fetch & yield records */ }
    public function columns(): array   { /* column → type map */ }
    public function indexes(): array   { /* index definitions */ }
    public function primaryKey(): string { /* primary key column name */ }
    public function version(): string  { /* cache key identifier */ }
}
```

See [Implementing a Custom Loader](docs/overview.md#implementing-a-custom-loader) for a full example.

### 3. Register tables

#### Option A: Auto-discovery (CSV only)

The easiest approach when using CSV files — Kura scans a directory and registers every subdirectory that contains `data.csv` automatically. No `AppServiceProvider` code needed.

```php
// config/kura.php
'csv' => [
    'base_path'     => storage_path('reference'),  // directory to scan
    'auto_discover' => true,
],
```

```
storage/reference/
├── versions.csv        # shared version registry
├── stations/
│   ├── table.yaml
│   └── data.csv
└── lines/
    ├── table.yaml
    └── data.csv
```

That's it — `stations` and `lines` are registered automatically. To override the primary key for a specific table:

```php
// config/kura.php
'tables' => [
    'products' => ['primary_key' => 'product_code'],
],
```

> **Note:** Adding a new table directory requires restarting the PHP process (`php artisan octane:restart` for Octane, or reloading PHP-FPM). The directory scan runs once at boot. Updating data inside an existing table (data.csv) also requires running `php artisan kura:rebuild` — there is no automatic file-change detection. Self-Healing only triggers on APCu TTL expiry, not on data.csv modification.

#### Option B: Manual registration

In your `AppServiceProvider` (or a dedicated service provider):

```php
use Kura\Contracts\VersionResolverInterface;
use Kura\Facades\Kura;
use Kura\Loader\CsvLoader;

public function boot(): void
{
    // Use the container-bound resolver (configured in config/kura.php)
    $resolver = app(VersionResolverInterface::class);

    Kura::register('stations', new CsvLoader(
        tableDirectory: base_path('data/stations'),
        resolver: $resolver,
    ));

    // You can register multiple tables
    Kura::register('lines', new CsvLoader(
        tableDirectory: base_path('data/lines'),
        resolver: $resolver,
    ));
}
```

### 4. Build the cache

```bash
# Rebuild all registered tables
php artisan kura:rebuild

# Rebuild a specific table
php artisan kura:rebuild stations

# Rebuild with a specific version
php artisan kura:rebuild --reference-version=v2.0.0
```

### 5. Query

```php
use Kura\Facades\Kura;

// Find by primary key
$station = Kura::table('stations')->find(1);

// Filter
$tokyoStations = Kura::table('stations')
    ->where('prefecture', 'Tokyo')
    ->get();

// Sort & paginate
$page = Kura::table('stations')
    ->where('prefecture', 'Tokyo')
    ->orderBy('name')
    ->paginate(20);

// Aggregates
$count = Kura::table('stations')->where('prefecture', 'Osaka')->count();
$maxLat = Kura::table('stations')->max('lat');
$avgLng = Kura::table('stations')->where('line_id', 1)->avg('lng');

// Cross-table filtering (lazy subquery)
$tokyoLineIds = fn() => Kura::table('lines')
    ->where('region', 'Kanto')
    ->pluck('id');
$stations = Kura::table('stations')
    ->whereIn('line_id', $tokyoLineIds)
    ->get();
```

---

## How it works

When you call `kura:rebuild`, Kura loads data from your source (CSV or DB), stores each record in APCu, and builds search indexes. Subsequent queries read directly from shared memory — no database involved.

```
Data Source (CSV / DB)
  └─ Generator streaming (low memory)
       └─ APCu: records + indexes + metadata
            └─ QueryBuilder API → sub-millisecond response
```

### APCu key structure

```
kura:stations:v1.0.0:pks                    # all PKs
kura:stations:v1.0.0:record:1               # single record
kura:stations:v1.0.0:idx:prefecture         # search index (single column)
kura:stations:v1.0.0:cidx:prefecture|city   # composite index (O(1) multi-column lookup)
```

### Self-Healing

If APCu evicts cached data, Kura detects the loss at query time and automatically rebuilds from the data source. Your application always receives complete, correct results.

```
Query
  ├─ Cache hit → respond from APCu (normal path)
  ├─ Cache miss → respond from Loader + dispatch rebuild
  └─ Record loss mid-query → fallback to Loader
```

With `rebuild.strategy = 'queue'`, the rebuild runs asynchronously — the current request gets data from the Loader directly while the cache is rebuilt in the background.
With `rebuild.strategy = 'callback'`, you supply a custom callable (e.g. to dispatch to a Horizon priority queue). See [Cache Architecture](docs/cache-architecture.md) for details.

---

## Supported Query Methods

Kura implements ~99 methods from Laravel's QueryBuilder. For the complete list, see [Laravel Builder Coverage](docs/laravel-builder-coverage.md).

### WHERE

`where`, `orWhere`, `whereNot`, `whereIn`, `whereNotIn`, `whereBetween`, `whereNull`, `whereNotNull`, `whereLike`, `whereColumn`, `whereAll`, `whereAny`, `whereNone`, `whereExists`, `whereFilter`, `whereRowValuesIn`, and more.

### ORDER BY / LIMIT / PAGINATION

`orderBy`, `orderByDesc`, `latest`, `oldest`, `inRandomOrder`, `limit`, `offset`, `paginate`, `simplePaginate`

### RETRIEVAL / AGGREGATES

`get`, `first`, `find`, `sole`, `value`, `pluck`, `cursor`, `count`, `min`, `max`, `sum`, `avg`, `exists`

---

## Documentation

| Document | Description |
|---|---|
| [Version Management](docs/version-management.md) / [日本語](docs/version-management-ja.md) | Version switching, CSV/DB drivers, middleware |
| [Index Guide](docs/index-guide.md) / [日本語](docs/index-guide-ja.md) | Index types, chunking, composite indexes, range queries |
| [Query Recipes](docs/query-recipes.md) / [日本語](docs/query-recipes-ja.md) | Common query patterns and examples |
| [Cache Architecture](docs/cache-architecture.md) / [日本語](docs/cache-architecture-ja.md) | Internal design: TTL, self-healing, rebuild flow |
| [Overview](docs/overview.md) / [日本語](docs/overview-ja.md) | Class structure and responsibilities |
| [Laravel Builder Coverage](docs/laravel-builder-coverage.md) / [日本語](docs/laravel-builder-coverage-ja.md) | Full API compatibility table |
| [Troubleshooting](docs/troubleshooting.md) / [日本語](docs/troubleshooting-ja.md) | APCu issues, slow queries, multi-server setup |
| [Design Constraints](docs/design-constraints.md) / [日本語](docs/design-constraints-ja.md) | Extension points, fixed behaviours, QueryBuilder rules |

## Design Constraints & Extension Points

Kura is intentionally narrow in scope. Two operations are central: **QueryBuilder-compatible filtering** and **index-based lookups**. Everything else is either pluggable via interface/closure or fixed by design.

### What you can extend

| Extension point | How |
|---|---|
| Data source | Implement `LoaderInterface` (5 methods: `load`, `columns`, `indexes`, `primaryKey`, `version`) |
| Version resolution | Bind `VersionResolverInterface` in the service container |
| Rebuild dispatch | `strategy: callback` with a `\Closure(\Kura\CacheRepository): void` |
| Per-table TTL | `tables` key in `config/kura.php` |

### Fixed by design

| Behaviour | Reason |
|---|---|
| APCu key format `kura:{table}:{version}:{type}` | Self-healing and invalidation depend on this structure |
| Full-table load (no partial updates) | Ensures consistency; diff rebuilds are not supported |
| Self-healing is always active | Triggered automatically on missing `pks` key; cannot be disabled |
| Index types: unique, non-unique, composite | Declared by the Loader; no runtime registration API |
| QueryBuilder join / raw / cross-table subquery methods excluded | These have no meaning over in-memory flat data; closure-based condition grouping within a single table is supported |

See [Design Constraints](docs/design-constraints.md) for extension patterns, memory model details, and contribution rules.

## Configuration

All options are in [`config/kura.php`](config/kura.php). Below is the complete reference.

```php
return [
    // APCu key prefix
    'prefix' => 'kura',

    // TTL in seconds per cache type
    'ttl' => [
        'pks'        => 3600,   // rebuild trigger — expiry causes next query to rebuild
        'record'     => 4800,   // longer than ids so records survive across rebuilds
        // 'index'   => omit to match ids TTL including jitter (recommended)
        //              ids and indexes then expire together, preventing a window where
        //              index keys are missing while ids is still present
        'pks_jitter' => 600,    // random 0–N seconds added to ids and index TTL (thundering herd prevention)
    ],

    // Rebuild lock TTL (seconds). Set to 1.5–2× the expected Loader execution time.
    'lock_ttl' => 60,

    // Rebuild strategy
    'rebuild' => [
        // 'sync'     — rebuild synchronously in the current request
        // 'queue'    — async via Laravel queue job
        // 'callback' — custom callable; set 'callback' below
        'strategy' => 'sync',

        // Required when strategy = 'callback'
        // Example: dispatch to a Horizon priority queue
        // 'callback' => static function (\Kura\CacheRepository $repository): void {
        //     dispatch(new \App\Jobs\RebuildReferenceJob($repository->table()))
        //         ->onQueue('high');
        // },
        'callback' => null,

        // Used when strategy = 'queue'
        'queue' => [
            'connection' => null,   // queue connection (null = default)
            'queue'      => null,   // queue name (null = default)
            'retry'      => 3,      // max attempts
        ],
    ],

    // Version resolution
    'version' => [
        'driver' => 'database',             // 'database' or 'csv'

        // database driver
        'table'   => 'reference_versions',
        'columns' => [
            'version'      => 'version',      // column name for the version string
            'activated_at' => 'activated_at', // column name for activation timestamp
        ],

        // csv driver
        'csv_path' => '',                   // absolute path to versions.csv

        // Seconds to cache all version rows in APCu (0 = no cache, re-reads every request)
        'cache_ttl' => 300,
    ],

    // Cache warm endpoint
    'warm' => [
        'enabled'           => false,
        'token'             => env('KURA_WARM_TOKEN', ''),  // Bearer token (required)
        'path'              => 'kura/warm',                 // URL path
        'controller'        => \Kura\Http\Controllers\WarmController::class,
        'status_controller' => \Kura\Http\Controllers\WarmStatusController::class,
    ],

    // CSV auto-discovery
    'csv' => [
        'base_path'     => '',     // directory to scan for table subdirectories
        'auto_discover' => false,  // enable auto-registration of CSV tables
    ],

    // Per-table overrides (primary_key and/or ttl)
    'tables' => [
        // 'products' => [
        //     'primary_key' => 'product_code',  // override primary key (default: 'id')
        //     'ttl' => ['record' => 7200],       // override specific TTL values
        // ],
    ],
];
```

## Benchmarks

### Environment

| | |
|---|---|
| Host | Apple M4 Pro |
| Runtime | Docker linux/aarch64 |
| PHP | 8.4.19 (JIT disabled) |
| APCu | 5.1.28 (`apc.shm_size=256M`) |
| Iterations | 500 per scenario |
| Metric | p95 latency |

### Dataset

Synthetic product records with the following schema and indexes:

| Column | Type | Cardinality |
|---|---|---|
| `id` | int | unique (1…N) |
| `name` | string | unique |
| `country` | string | 5 values (JP / US / GB / DE / FR), evenly distributed |
| `category` | string | 10 values (electronics / clothing / …), evenly distributed |
| `price` | float | 200 distinct values (1.99–200.99), cyclic |
| `active` | bool | 67% true / 33% false |

Indexes declared: `country`, `price`, `country|category` (composite).

### Results (p95 latency)

| Scenario | 1K records | 10K records | 100K records |
|---|---|---|---|
| `find($id)` — single record lookup | **0.9 µs** | **1.0 µs** | **1.0 µs** |
| `where('country','JP')` — indexed `=` (20% hit) | **119 µs** | **1.08 ms** | **12.60 ms** |
| `where('country','JP')->where('category','electronics')` — composite index (2% hit) | **99 µs** | **885 µs** | **9.68 ms** |
| `whereBetween('price', [50,100])` — range index (25% hit) | **159 µs** | **1.35 ms** | **14.47 ms** |
| `where('country','JP')->orderBy('price')` — index walk | **170 µs** | **1.40 ms** | **17.49 ms** |
| `where('active', true)` — non-indexed full scan (67% hit) | 400 µs | 3.74 ms | 39.42 ms |
| `get()` — all records | 436 µs | 3.71 ms | 38.74 ms |
| Cache build (`rebuild()`) | 3.02 ms | 12.32 ms | 118.24 ms |

Index-accelerated queries (**bold**) are 3–5× faster than full scans at the same dataset size.
At 100K records, indexed queries respond in under 18 ms; a non-indexed full scan takes ~39 ms.

`orderBy` on an indexed column uses a pre-sorted index walk — no PHP sort needed.
`orderBy` on a **non-indexed** column falls back to a PHP-side sort that collects all matching records into memory first (O(N)). For large tables, declare an index on any column you frequently sort by.

> Run `php benchmarks/benchmark.php` in the Docker environment to reproduce.

### With JIT + igbinary (100K records, p95)

Enabling PHP JIT and the igbinary APCu serializer delivers an additional **15–40% improvement** across all query types.

| Scenario | Baseline | JIT + igbinary | Improvement |
|---|---|---|---|
| `find($id)` — single record | 0.8 µs | 0.7 µs | -12% |
| `where country` — indexed `=` | 12.93 ms | 10.04 ms | -22% |
| composite index | 9.96 ms | 7.50 ms | -25% |
| `whereBetween` — range narrow | 14.58 ms | 11.82 ms | -19% |
| `orderBy price` — index walk | 17.81 ms | 11.72 ms | **-34%** |
| `orderBy name` — PHP sort | 42.05 ms | 31.12 ms | **-26%** |
| `count()` | 12.77 ms | 8.96 ms | **-30%** |
| non-indexed full scan | 39.79 ms | 32.88 ms | -17% |

JIT accelerates closure evaluation in the `WhereCompiler` and PHP-side sort. igbinary reduces APCu deserialization overhead on every record fetch.

**To enable**, add the following to your `php.ini`:

```ini
; JIT
opcache.enable = 1
opcache.enable_cli = 1
opcache.jit = tracing
opcache.jit_buffer_size = 128M

; igbinary serializer for APCu (requires ext-igbinary)
apc.serializer = igbinary
```

`ext-igbinary` can be installed via `pecl install igbinary`.

---

## Cache Warming

Pre-warm the APCu cache via HTTP after deployment (before traffic arrives).

### Enable the warm endpoint

```php
// config/kura.php
'warm' => [
    'enabled' => true,
    'token'   => env('KURA_WARM_TOKEN', ''),
],
```

### Generate a Bearer token

```bash
php artisan kura:token          # generates and writes to .env
php artisan kura:token --show   # display current token
php artisan kura:token --force  # overwrite without confirmation
```

### Endpoints

**`POST /kura/warm`** — rebuild cache for all registered tables

```bash
# Synchronous (strategy=sync, default)
curl -X POST https://your-app.com/kura/warm \
     -H "Authorization: Bearer $KURA_WARM_TOKEN"

# Asynchronous via queue (strategy=queue)
curl -X POST https://your-app.com/kura/warm \
     -H "Authorization: Bearer $KURA_WARM_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"strategy": "queue"}'
# → 202 {"batch_id": "abc123"}
```

**`GET /kura/warm/status/{batchId}`** — check async rebuild progress

```bash
curl https://your-app.com/kura/warm/status/abc123 \
     -H "Authorization: Bearer $KURA_WARM_TOKEN"
# → {"id":"abc123","totalJobs":3,"pendingJobs":1,"failedJobs":0,"finished":false}
```

### Testing without APCu

Use `ArrayStore` as a drop-in replacement for `ApcuStore` in tests and CI:

```php
use Kura\Store\ArrayStore;

$store = new ArrayStore;
$repository = new CacheRepository(table: 'products', primaryKey: 'id', store: $store, loader: $loader);
```

`ArrayStore` operates on plain PHP arrays — no APCu extension required.

---

## License

MIT
