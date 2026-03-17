> Japanese version: [README-ja.md](README-ja.md)

# Kura

**Kura** (蔵 — *storehouse*) is a Laravel package that caches reference data in APCu and queries it with a **Laravel QueryBuilder-compatible API**.

Load data once from CSV or DB, store it in APCu, and query it with the same fluent API you already know — **no database queries at runtime**. Index-accelerated lookups keep response times sub-millisecond even with large datasets.

## Why Kura?

- **Familiar API** — `where`, `orderBy`, `paginate`, `find`, `count`, `sum` — same as Laravel's QueryBuilder
- **Sub-millisecond reads** — APCu shared memory, no network round-trips
- **Low memory footprint** — Generator-based traversal; never loads entire datasets into PHP memory
- **Smart indexes** — Binary search indexes for range queries, composite index hashmaps for O(1) multi-column lookups, automatic chunk splitting for large datasets
- **Self-Healing** — Cache eviction? Kura automatically rebuilds from the data source — your app never sees stale or missing data
- **Version management** — Switch reference data versions seamlessly via DB or CSV

## Requirements

- PHP ^8.4
- Laravel ^12.0
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
    └── v1.0.0.csv         # data snapshot (full dump per version)
```

**versions.csv** — controls which version is active:
```csv
id,version,activated_at
1,v1.0.0,2024-01-01 00:00:00
2,v2.0.0,2024-06-01 00:00:00
```

The version with `activated_at <= now` and the latest timestamp is used.
Each version's CSV is a **full snapshot** (not a diff).

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
```

Supported types: `int`, `float`, `bool`, `string`

**stations/v1.0.0.csv** — the actual data:
```csv
id,name,prefecture,city,lat,lng,line_id
1,Tokyo,Tokyo,Chiyoda,35.6812,139.7671,1
2,Shibuya,Tokyo,Shibuya,35.6580,139.7016,1
3,Shinjuku,Tokyo,Shinjuku,35.6896,139.7006,1
4,Osaka,Osaka,Kita,34.7024,135.4959,2
5,Namba,Osaka,Chuo,34.6629,135.5013,3
```

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

### 3. Register tables

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
kura:stations:v1.0.0:meta                   # column definitions + index structure
kura:stations:v1.0.0:record:1               # single record
kura:stations:v1.0.0:idx:prefecture         # search index (single column)
kura:stations:v1.0.0:idx:price:0            # chunked index (large datasets)
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

## License

MIT
