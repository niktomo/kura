> Japanese version: [troubleshooting-ja.md](troubleshooting-ja.md)

# Troubleshooting

## APCu is not working

### Symptoms

- Cache is never built (always falls back to Loader)
- `apcu_store` / `apcu_fetch` return `false`
- Queries are slow (always hitting the DB/CSV)

### Causes and fixes

**1. APCu extension not installed**

```bash
php -m | grep apcu
```

If not listed:

```bash
pecl install apcu
```

Add to `php.ini`:

```ini
extension=apcu.so
```

**2. APCu disabled in CLI (`apc.enable_cli=0`)**

APCu is disabled in CLI by default. This affects artisan commands (`kura:rebuild`, tests).

```ini
; php.ini or a dedicated ini file (e.g. /etc/php/8.x/cli/conf.d/20-apcu.ini)
apc.enable_cli=1
```

Verify:

```bash
php -r "var_dump(apcu_store('test', 1)); var_dump(apcu_fetch('test'));"
# bool(true) bool(true) — working
# bool(false) bool(false) — APCu disabled
```

**3. APCu not enabled for php-fpm / web server**

APCu is process-local. PHP-FPM workers each have their own APCu pool. `apc.enable_cli=1` only affects CLI; the web process uses a separate ini.

Check your FPM pool ini:

```bash
php-fpm -m | grep apcu
```

**4. Shared memory too small (`apc.shm_size`)**

Default is `32M`. Large datasets can exhaust it silently (new entries are not cached).

```ini
apc.shm_size=256M
```

Check current usage:

```bash
php -r "print_r(apcu_sma_info());"
# avail_mem close to 0 → increase shm_size
```

---

## Cache is not updated after data changes

Kura does not auto-detect data changes. You must trigger a rebuild manually:

```bash
# Rebuild all tables
php artisan kura:rebuild

# Rebuild a specific table
php artisan kura:rebuild --table=products
```

Or via HTTP (if warm endpoint is configured):

```bash
curl -X POST https://your-app.com/kura/warm \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"strategy":"queue"}'
```

---

## Cache disappears after server restart

APCu is stored in shared memory and **does not persist across restarts**. This is expected.

Kura's Self-Healing handles this automatically: the first query after restart detects the missing `ids` key and rebuilds from the Loader. No manual intervention is needed.

To pre-warm on startup, call `kura:rebuild` from your deploy script or a supervisor hook.

---

## `IndexInconsistencyException` is thrown

**Cause:** An index APCu key (`idx:*` or `cidx:*`) is missing while the `ids` key is still present. This can happen if a specific APCu entry was evicted under memory pressure before the `ids` key expired.

**What Kura does:** Automatically catches the exception, dispatches a rebuild job, and falls back to the Loader for the current request. No user-visible error.

**If it recurs frequently:** Increase `apc.shm_size` or lower per-table TTL so all keys expire together.

---

## `CacheInconsistencyException` is thrown

**Cause:** A `record:{id}` key is missing while that ID is still in the `ids` list — meaning the `ids` and `record` keys went out of sync (partial eviction).

**What Kura does:** Same as above — auto-rebuild + Loader fallback.

---

## Queries are slow unexpectedly

**Check 1: Is the index being used?**

Only columns declared in `LoaderInterface::indexes()` are indexed. Queries on non-indexed columns always full-scan.

```php
public function indexes(): array
{
    return [
        ['columns' => ['country'], 'unique' => false],
    ];
}
```

**Check 2: OR with a non-indexed branch forces full scan**

```php
// Full scan — 'active' is not indexed, so the OR branch cannot use an index
->where('country', 'JP')->orWhere('active', true)
```

**Check 3: Large result sets are always slow**

Index acceleration helps narrow candidates. Once candidates are identified, every matched record requires an APCu fetch. At 100K records with 20% selectivity, you are fetching 20K records — no index can avoid that.

**Check 4: `orderBy` on a non-indexed column triggers PHP sort**

```php
->orderBy('name')  // PHP sort over all matched records
->orderBy('price') // index walk — much faster if 'price' is indexed
```

---

## Multi-server deployments — cache is inconsistent across servers

APCu is per-process and per-server. Each server has its own APCu pool. There is no cross-server synchronization.

**Recommended approach:**

- Trigger `kura:rebuild` (or POST `/kura/warm`) on **all servers** after data changes
- Use the `strategy=queue` option with a shared queue to fan out to all servers simultaneously

---

## Docker / php-fpm: APCu is empty despite `apc.enable_cli=1`

CLI and php-fpm are **separate processes**. `apc.enable_cli=1` only affects CLI (artisan, tests). The web worker uses a different ini.

Ensure your FPM config also loads APCu:

```dockerfile
RUN echo "extension=apcu.so" >> /usr/local/etc/php/conf.d/apcu.ini
RUN echo "apc.enable_cli=1" >> /usr/local/etc/php/conf.d/apcu.ini
RUN echo "apc.shm_size=128M" >> /usr/local/etc/php/conf.d/apcu.ini
```
