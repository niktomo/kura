> Japanese version: [README-ja.md](README-ja.md)

> [!WARNING]
> This package is currently under active development. APIs may change without notice before v1.0.0.

# Kura

[![Tests](https://github.com/tomonori/kura/actions/workflows/tests.yml/badge.svg)](https://github.com/tomonori/kura/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/tomonori/kura.svg)](https://packagist.org/packages/tomonori/kura)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue)](https://www.php.net/)
[![License](https://img.shields.io/github/license/tomonori/kura)](LICENSE)

**Kura** (蔵 — *storehouse*) is a Laravel package that caches reference data in APCu and queries it with a **Laravel QueryBuilder-compatible API**.

Load data once from CSV or DB, store it in APCu, and query it with the same fluent API you already know — **no database queries at runtime**. Index-accelerated lookups keep response times sub-millisecond even with large datasets.

## Why Kura?

- **Familiar API** — `where`, `orderBy`, `paginate`, `find`, `count`, `sum` — same as Laravel's QueryBuilder
- **Sub-millisecond reads** — APCu shared memory, no network round-trips ([see benchmarks](#benchmarks))
- **Low memory footprint** — Generator-based traversal; never loads entire datasets into PHP memory
- **Smart indexes** — Binary search indexes for range queries, composite index hashmaps for O(1) multi-column lookups, automatic chunk splitting for large datasets
- **Self-Healing** — Cache eviction? Kura automatically rebuilds from the data source — your app never sees stale or missing data
- **Version management** — Switch reference data versions seamlessly via DB or CSV
- **Pluggable data sources** — `LoaderInterface` lets you bring any backend: CSV, Eloquent, QueryBuilder, REST API, S3, etc. Built-in loaders included; swap or extend with 4 methods

## Requirements

- PHP ^8.2
- Laravel ^10.0 / ^11.0 / ^12.0
- APCu extension (`pecl install apcu`)

## Installation

```bash
composer require tomonori/kura
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
        'cache_ttl' => 300,                             // cache resolved version for 5 min
    ],

    // Rebuild strategy — what happens when cache is missing
    'rebuild' => [
        'strategy' => 'sync',  // 'sync' (no queue needed) or 'queue' (recommended for production)
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
    ├── defines.csv        # column definitions
    ├── indexes.csv        # index definitions (optional)
    └── data.csv           # data (version column required)
```

**versions.csv** — controls which version is active:
```csv
id,version,activated_at
1,v1.0.0,2024-01-01 00:00:00
2,v2.0.0,2024-06-01 00:00:00
```

The version with `activated_at <= now` and the latest timestamp is used.

**stations/defines.csv** — column names and types:
```csv
column,type,description
id,int,Station ID
name,string,Station name
prefecture,string,Prefecture
city,string,City
lat,float,Latitude
lng,float,Longitude
line_id,int,Railway line ID
version,string,Data version (required)
```

Supported types: `int`, `float`, `bool`, `string`

**stations/indexes.csv** — optional; defines which columns to index for fast lookups:
```csv
columns,unique
prefecture,false
line_id,false
prefecture|city,false
```

- `columns`: column name, or `|`-separated list for a composite index
- `unique`: `true` / `false`
- Composite indexes (`col1|col2`) enable O(1) multi-column equality lookups

> **Tip:** If `indexes.csv` is absent, you can pass `indexDefinitions` to `CsvLoader` directly (see [Register tables](#3-register-tables)).

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

No CSV files needed — load directly from your database:

```php
use Kura\Loader\EloquentLoader;

$loader = new EloquentLoader(
    query: Station::query(),
    columns: [
        'id' => 'int', 'name' => 'string', 'prefecture' => 'string',
        'city' => 'string', 'lat' => 'float', 'lng' => 'float',
        'line_id' => 'int',
    ],
    indexDefinitions: [
        ['columns' => ['prefecture'], 'unique' => false],
        ['columns' => ['line_id'], 'unique' => false],
    ],
    version: 'v1.0.0',
);
```

#### Option C: Custom Loader

Any data source works — implement `LoaderInterface` with 4 methods:

```php
use Kura\Loader\LoaderInterface;

class MyApiLoader implements LoaderInterface
{
    public function load(): \Generator { /* fetch & yield records */ }
    public function columns(): array   { /* column → type map */ }
    public function indexes(): array   { /* index definitions */ }
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
│   ├── data.csv
│   ├── defines.csv
│   └── indexes.csv
└── lines/
    ├── data.csv
    ├── defines.csv
    └── indexes.csv
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
use Kura\Facades\Kura;
use Kura\Loader\CsvLoader;
use Kura\Loader\CsvVersionResolver;

public function boot(): void
{
    // CSV example
    $resolver = new CsvVersionResolver(base_path('data/versions.csv'));

    Kura::register('stations', new CsvLoader(
        tableDirectory: base_path('data/stations'),
        resolver: $resolver,
        indexDefinitions: [
            ['columns' => ['prefecture'], 'unique' => false],
            ['columns' => ['line_id'], 'unique' => false],
        ],
    ), primaryKey: 'id');

    // You can register multiple tables
    Kura::register('lines', new CsvLoader(
        tableDirectory: base_path('data/lines'),
        resolver: $resolver,
    ), primaryKey: 'id');
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
kura:stations:v1.0.0:ids                    # all IDs
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

## Configuration

See [`config/kura.php`](config/kura.php) for all available options. Per-table overrides are supported:

```php
'tables' => [
    'stations' => [
        'ttl' => ['record' => 7200],
        'chunk_size' => 10000,  // split large indexes into chunks
    ],
],
```

## Benchmarks

Measured on Apple M4 Pro / PHP 8.4.19 / APCu 5.1.28 (`apc.shm_size=256M`).
Each scenario runs 500 iterations; p95 latency shown.

| Scenario | 1K records | 10K records | 100K records |
|---|---|---|---|
| `find($id)` — single record | **0.9 µs** | **0.8 µs** | **0.9 µs** |
| `where('country','JP')` — indexed `=` | **139 µs** | **1.3 ms** | **16 ms** |
| `where('country','JP')->where('category','...')` — composite index | **105 µs** | **984 µs** | **11 ms** |
| `whereBetween('price', [50,100])` — range index | **181 µs** | **1.7 ms** | **18 ms** |
| `where(...)->orderBy('price')->get()` — index walk | **190 µs** | **1.7 ms** | **21 ms** |
| `where('active', true)` — non-indexed (full scan) | 477 µs | 4.8 ms | 52 ms |
| `get()` — all records | 379 µs | 3.7 ms | 40 ms |
| Cache build (`rebuild()`) | 3.9 ms | 11.8 ms | 115 ms |

Index-accelerated queries (**bold**) are 3–5× faster than full scans at all scales.
At 100K records, indexed queries stay under 21 ms; full scans reach ~52 ms.
`orderBy` on an indexed column uses an index walk (pre-sorted in APCu) — no PHP sort needed.

> Run `php benchmarks/benchmark.php` in the Docker environment to reproduce.

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
