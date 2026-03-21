> Japanese version: [version-management-ja.md](version-management-ja.md)

# Version Management

## Overview

Kura manages reference data versions to enable seamless data switching without downtime. When the version changes, cache keys change automatically, and old caches expire naturally via TTL.

```
v1.0.0 active → cache keys: kura:products:v1.0.0:*
         ↓ version switch
v2.0.0 active → cache keys: kura:products:v2.0.0:*
                 v1.0.0 keys expire via TTL (no manual cleanup)
```

---

## Version Drivers

Kura supports two version drivers: **CSV** and **Database**.

### CSV Driver

Version information is stored in a `versions.csv` file shared across tables.

```php
// config/kura.php
'version' => [
    'driver'    => 'csv',
    'csv_path'  => base_path('data/versions.csv'),
    'cache_ttl' => 300,
],
```

**versions.csv:**
```csv
id,version,activated_at
1,v1.0.0,2024-01-01 00:00:00
2,v2.0.0,2024-06-01 00:00:00
3,v3.0.0,2025-01-01 00:00:00
```

The version with `activated_at <= now()` and the latest timestamp is selected.

Each table has its own directory with a single `data.csv` file that includes a `version` column:

```
data/
├── versions.csv
├── stations/
│   ├── defines.csv
│   └── data.csv          # version column required
└── lines/
    ├── defines.csv
    └── data.csv
```

The CsvLoader reads `data.csv` and loads rows where `version IS NULL` (always loaded, shared across all versions) or `version <= currentVersion` (past and current versions). Rows where `version > currentVersion` are skipped — they represent data not yet active.

**data.csv example:**
```csv
id,name,prefecture,version
1,Tokyo,Tokyo,
2,Osaka,Osaka,
3,Sapporo,Hokkaido,v1.0.0
4,Fukuoka,Fukuoka,v2.0.0
```

In this example, rows 1 and 2 (version = null) are loaded for every version. Row 3 (v1.0.0) is loaded when activeVersion >= v1.0.0. Row 4 (v2.0.0) is loaded when activeVersion >= v2.0.0, and skipped when activeVersion = v1.0.0.

### Database Driver

Version information is stored in a database table.

```php
// config/kura.php
'version' => [
    'driver'    => 'database',
    'table'     => 'reference_versions',
    'columns'   => [
        'version'      => 'version',
        'activated_at' => 'activated_at',
    ],
    'cache_ttl' => 300,
],
```

**Migration example:**
```php
Schema::create('reference_versions', function (Blueprint $table) {
    $table->id();
    $table->string('version')->unique();
    $table->timestamp('activated_at');
    $table->timestamps();
});
```

**Seed example:**
```php
DB::table('reference_versions')->insert([
    ['version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
    ['version' => 'v2.0.0', 'activated_at' => '2024-06-01 00:00:00'],
]);
```

The same selection rule applies: `activated_at <= now()`, latest wins.

---

## Version Resolvers

### VersionResolverInterface

```php
interface VersionResolverInterface
{
    public function resolve(): ?string;
}
```

### Implementations

| Class | Role | Use case |
|---|---|---|
| `CsvVersionResolver` | Loads all rows from `versions.csv` | CSV-only deployments |
| `DatabaseVersionResolver` | Loads all rows from DB table | DB-backed deployments |
| `CachedVersionResolver` | Caches all rows in APCu; filters by `now()` at resolve time | Production (wraps either of the above) |

### CachedVersionResolver

`CachedVersionResolver` wraps a `VersionsLoaderInterface` (either `CsvVersionResolver` or `DatabaseVersionResolver`). It caches **all version rows** in APCu, then filters by the current time at every `resolve()` call:

```
APCu miss → load all rows from DB/CSV → store in APCu for cache_ttl seconds
APCu hit  → filter rows: activated_at <= now() → return latest matching version
```

```php
use Illuminate\Database\ConnectionInterface;
use Kura\Version\CachedVersionResolver;
use Kura\Version\DatabaseVersionResolver;

// KuraServiceProvider binds this automatically via $app['db']->connection()
$inner = new DatabaseVersionResolver(
    connection: $app['db']->connection(),
    table: 'reference_versions',
);
$resolver = new CachedVersionResolver($inner, ttl: 300);

// First call: reads all rows from DB, stores in APCu
// Subsequent calls within 5 min: filters rows from APCu by now()
$version = $resolver->resolve();
```

- **PHP var cache**: pins the resolved version for the duration of the current request (Octane safe)
- **APCu cache**: stores all version rows cross-request; refreshed every `cache_ttl` seconds
- **DB/CSV**: only called on APCu miss

When a new version's `activated_at` arrives, `resolve()` automatically returns it — no cache invalidation needed, because all rows are already cached and filtered on every call.

`KuraServiceProvider` automatically creates and binds the appropriate resolver based on config.

---

## Version Override

### Artisan command

```bash
# Rebuild with a specific version (ignores activated_at)
php artisan kura:rebuild --reference-version=v2.0.0
```

### Programmatic

```php
use Kura\Facades\Kura;

// Override version for all subsequent operations
Kura::setVersionOverride('v2.0.0');
```

### HTTP header

Use the `X-Reference-Version` header to pin a version per request (see Middleware below).

---

## Middleware

An example middleware is provided in `examples/KuraVersionMiddleware.php`:

```php
class KuraVersionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $serverVersion = $this->resolver->resolve();

        $response = $next($request);
        $response->headers->set('X-Reference-Version', $serverVersion);

        $clientVersion = $request->header('X-Reference-Version');
        if ($clientVersion !== null && $clientVersion !== $serverVersion) {
            $response->headers->set('X-Reference-Version-Mismatch', 'true');
        }

        return $response;
    }
}
```

This middleware:
1. Resolves the current server version
2. Attaches it to the response header
3. Detects client/server version mismatch

Register it in `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->api(append: [KuraVersionMiddleware::class]);
})
```

---

## Version Deployment Flow

```
1. Prepare new data
   └─ Update CSV files or DB records for v2.0.0

2. Register version
   └─ Add row to reference_versions: v2.0.0, activated_at = future time
   └─ Or add line to versions.csv

3. Activation
   └─ activated_at arrives → VersionResolver returns v2.0.0

4. Cache transition
   └─ New queries use kura:*:v2.0.0:* keys
   └─ Cache miss → Self-Healing rebuilds v2.0.0 cache
   └─ Old v1.0.0 keys expire via TTL (no manual cleanup)

5. (Optional) Pre-warm
   └─ php artisan kura:rebuild --reference-version=v2.0.0
   └─ Or POST /kura/warm?version=v2.0.0
```

### Best practices

- **Set `activated_at` in the future** and pre-warm the cache before activation
- **Use `cache_ttl`** to control how quickly version changes propagate (default: 5 min)
- **Keep old version CSVs** until their TTL expires — Kura may still serve them briefly during transition
- **Monitor with `X-Reference-Version` header** — clients can detect version changes

---

## Client Version Strategy

How clients hold and track the active version. Choose based on how often data changes and whether schema changes are involved.

### Pattern A — Embed at build time

```
CI/CD build
  └─ Fetch current version (e.g. v2.0.0) from versions.csv or API
  └─ Embed into app binary at build time

Client (mobile app / SPA bundle)
  └─ Always sends: X-Reference-Version: v2.0.0 (fixed)
  └─ Receives X-Reference-Version-Mismatch: true
       └─ Prompt user to update the app
```

**Best for:** Changes that involve schema or UI updates (new columns, removed fields). Version bump = app release. Safe and predictable.

---

### Pattern B — Fetch at startup or on mismatch ⭐ recommended

```
App startup
  └─ GET /api/version  →  "v2.0.0"
  └─ Store version in memory / local storage

Each request
  └─ Send: X-Reference-Version: v2.0.0
  └─ Receive: X-Reference-Version: v3.0.0  (server moved on)
            + X-Reference-Version-Mismatch: true
       └─ Re-fetch version → update to v3.0.0
       └─ Retry request with new version (no app update needed)
```

**Best for:** Data-only changes (new rows, value updates) with no schema change. Clients adapt automatically without a release.

---

### Pattern C — App version tied to data version

```
Data version v3.0.0 released
  └─ App v3.0 is also released (schema/UI changes bundled together)
  └─ Users on app v2.x receive X-Reference-Version-Mismatch
       └─ Force-upgrade prompt
```

**Best for:** Tightly coupled data and UI (e.g. new data fields require new screens).

---

### Choosing a pattern

| | Pattern A | Pattern B | Pattern C |
|---|---|---|---|
| Data-only updates | App rebuild needed | ✅ Automatic | App rebuild needed |
| Schema/UI updates | ✅ Controlled via release | Manual handling needed | ✅ Controlled via release |
| Operational simplicity | High | Medium | High |
| Recommended for | Static reference data | Frequently updated data | Tightly coupled app+data |

---

## Config Reference

```php
'version' => [
    // Version resolution driver
    'driver' => 'database',       // 'database' or 'csv'

    // Database driver settings
    'table' => 'reference_versions',
    'columns' => [
        'version'      => 'version',       // column name for version string
        'activated_at' => 'activated_at',   // column name for activation timestamp
    ],

    // CSV driver settings
    'csv_path' => '',  // absolute path to versions.csv

    // How long to cache all version rows in APCu (seconds)
    // 0 = no caching (reads from DB/CSV on every request)
    'cache_ttl' => 300,
],
```
