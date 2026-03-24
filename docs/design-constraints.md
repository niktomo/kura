> Japanese version: [design-constraints-ja.md](design-constraints-ja.md)

# Design Constraints & Extension Points

Kura is intentionally narrow in scope. Two operations are central:

- **QueryBuilder-compatible filtering** — the fluent API your application calls
- **Index-based lookups** — APCu-backed binary search and composite hashmaps for fast filtered access

Everything else is either pluggable via interface/closure, or fixed by design to guarantee correctness.

---

## Extension Points

### 1. Data source — `LoaderInterface`

Implement `LoaderInterface` to bring any data backend: REST API, S3, another database, a custom binary format, etc.

```php
use Kura\Loader\LoaderInterface;

class MyApiLoader implements LoaderInterface
{
    public function load(): \Generator
    {
        foreach ($this->api->fetchAll() as $row) {
            yield $row;  // must yield associative arrays
        }
    }

    public function columns(): array
    {
        // column name => type ('int', 'float', 'string', 'bool')
        return ['id' => 'int', 'code' => 'string', 'price' => 'float'];
    }

    public function indexes(): array
    {
        return [
            ['columns' => ['code'], 'unique' => true],
            ['columns' => ['category', 'code'], 'unique' => false],  // composite
        ];
    }

    public function version(): string|int|\Stringable
    {
        return $this->api->currentVersion();
    }
}
```

Rules for `load()`:
- Must use `yield` (generator) — never return an array
- Each yielded value must be an associative array with string keys
- The primary key column must be present in every record
- Column names and types must match the `columns()` return value

Rules for `indexes()`:
- For composite indexes, list columns from **lowest to highest cardinality** (e.g. `['country', 'city']` not `['city', 'country']`)
- Single-column indexes are automatically created for each column in a composite index — no need to declare them separately

### 2. Version resolution — `VersionResolverInterface`

Bind a custom implementation in your `AppServiceProvider`:

```php
use Kura\Contracts\VersionResolverInterface;

$this->app->bind(VersionResolverInterface::class, MyVersionResolver::class);
```

The resolver is called once per request and its result is cached in PHP memory for the duration of that request.

### 3. Rebuild dispatch — `strategy: callback`

When you need custom rebuild routing (e.g. Horizon priority queues, multi-tenant dispatch):

```php
// config/kura.php
'rebuild' => [
    'strategy' => 'callback',
    'callback' => static function (\Kura\CacheRepository $repository): void {
        dispatch(new \App\Jobs\RebuildReferenceJob($repository->table()))
            ->onQueue('high');
    },
],
```

The closure receives the `CacheRepository` for the affected table. It is called synchronously in the request that detected the cache miss — keep it fast (dispatch, don't process).

### 4. Per-table TTL — `config tables`

```php
'tables' => [
    'products' => [
        'ttl' => ['record' => 7200],
    ],
],
```

Only `ttl` overrides are supported per table. Other config keys (prefix, rebuild strategy, etc.) are global.

---

## Fixed Behaviours

These behaviours cannot be overridden. They are load-bearing for correctness and self-healing.

### APCu key format

```
kura:{prefix}:{table}:{version}:pks
kura:{prefix}:{table}:{version}:record:{id}
kura:{prefix}:{table}:{version}:idx:{column}
kura:{prefix}:{table}:{version}:cidx:{col1|col2}
kura:{table}:lock
```

The `pks` key is the existence signal for a table's cache. Self-healing watches for its absence. Do not write to these keys externally.

### Full-table load (no partial updates)

When a rebuild is triggered, Kura always loads and stores the entire table. There is no API for inserting, updating, or deleting individual records in the cache. This is intentional — partial state leads to query inconsistencies.

### Self-healing is always active

When `pks` is missing at query time, Kura automatically triggers a rebuild. This cannot be disabled. If you need to prevent a rebuild (e.g. during a deployment), use the `kura:rebuild` Artisan command to pre-warm before traffic arrives.

### Index types: unique, non-unique, composite

Index types are declared by the `Loader` and built at load time. There is no runtime API to register additional indexes or change index type after loading. To change index structure, update your `LoaderInterface::indexes()` return value and trigger a rebuild.

---

## QueryBuilder Compatibility Rules

Kura implements ~99 methods from Laravel's `Illuminate\Database\Query\Builder`. The following rules define what is and is not in scope:

**In scope** — methods that operate on flat, in-memory records:
- All `where*` variants (equality, range, NULL, LIKE, IN, BETWEEN, composite conditions)
- `orderBy`, `limit`, `offset`, `paginate`, `simplePaginate`
- `get`, `first`, `find`, `sole`, `value`, `pluck`, `cursor`
- `count`, `min`, `max`, `sum`, `avg`, `exists`

**Out of scope** — methods that require a relational database:
- `join`, `leftJoin`, `rightJoin`, `crossJoin`
- `whereHas`, `with` (Eloquent relations)
- `toSql`, `dd`, `dump` (query compilation)
- `lock`, `lockForUpdate`, `sharedLock`
- `union`, `unionAll`
- `where` closures that reference **other tables** (cross-table subqueries have no meaning over in-memory flat data; closures that group conditions within the same table are supported)

If you call an out-of-scope method, Kura will throw `\BadMethodCallException`.

---

## Memory Model

Understanding Kura's memory usage prevents surprises in production.

### APCu shared memory (`apc.shm_size`)

All data lives here. The following are stored per table per version:

| Key type | Memory usage |
|---|---|
| `pks` | Proportional to record count (hashmap `[id => true]`) |
| `record:{id}` | One entry per record (associative array) |
| `idx:{column}` | One entry per indexed column (sorted value→ids array) |
| `cidx:{col1\|col2}` | One entry per composite index (value-pair→ids hashmap) |

Set `apc.shm_size` large enough to hold all tables across all active versions. A rebuild writes a new version before the old one expires, so peak usage is approximately 2× normal size.

### PHP per-request memory

Records are never bulk-loaded into PHP memory. Generator-based traversal fetches each record from APCu individually. The per-request footprint is:

- Index data for the query's resolved ID set (may be large for unindexed full scans)
- One record at a time during traversal

**Practical limit**: If the `pks` list or a single index for a table is too large to fit in a single APCu entry, you will see APCu store failures. In that case, consider enabling chunk splitting in your index configuration.

---

## Contributing: Adding New `where` Methods

To add a new `where*` method:

1. **`ReferenceQueryBuilder`** — add the method, store the condition in `$this->wheres` using the same structure as existing conditions
2. **`CacheProcessor::compilePredicate()`** — add the evaluation logic for the new condition type (used in full-scan fallback)
3. **`CacheProcessor::resolveIds()`** — if the condition can be index-accelerated, add the index lookup path here
4. **Tests** — add a test that covers both the indexed path and the full-scan fallback

Do not add methods that are not present in `Illuminate\Database\Query\Builder` — the API surface must remain a strict subset.

---

## Contributing: Index Structure

Index data is built entirely by `CacheRepository::rebuild()` using the definitions returned by `LoaderInterface::indexes()`. There is no plugin interface for new index types. If you need a custom lookup strategy, implement it in a custom `CacheProcessor` subclass (experimental; not officially supported).
