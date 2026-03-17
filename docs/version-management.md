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

The CsvLoader reads `data.csv` and loads rows where `version = currentVersion` or `version IS NULL`. Rows with a null `version` are always loaded regardless of the active version — useful for records that do not change between versions.

**data.csv example:**
```csv
id,name,prefecture,version
1,Tokyo,Tokyo,
2,Osaka,Osaka,
3,Sapporo,Hokkaido,v1.0.0
4,Fukuoka,Fukuoka,v2.0.0
```

In this example, rows 1 and 2 (version = null) are loaded for every version; rows 3 and 4 are version-specific.

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

| Resolver | Source | Use case |
|---|---|---|
| `CsvVersionResolver` | `versions.csv` file | CSV-only deployments |
| `DatabaseVersionResolver` | DB `reference_versions` table | DB-backed deployments |
| `CachedVersionResolver` | Decorator — caches result in APCu + PHP var | Production (wraps either of the above) |

### CachedVersionResolver

Wraps any resolver to avoid repeated DB/CSV reads:

```php
use Kura\Version\CachedVersionResolver;
use Kura\Version\DatabaseVersionResolver;

$inner = new DatabaseVersionResolver(/* ... */);
$resolver = new CachedVersionResolver($inner, cacheTtl: 300);

// First call: reads from DB, caches in APCu + PHP var
// Subsequent calls within 5 min: returns from cache
$version = $resolver->resolve();
```

- **PHP var cache**: instant (same request)
- **APCu cache**: sub-millisecond (cross-request, within same SAPI)
- **DB/CSV**: only called when both caches miss (every `cache_ttl` seconds)

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

    // How long to cache the resolved version in APCu (seconds)
    // 0 = no caching (resolves every time)
    'cache_ttl' => 300,
],
```
