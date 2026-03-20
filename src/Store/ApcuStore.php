<?php

namespace Kura\Store;

class ApcuStore implements StoreInterface
{
    public function __construct(
        private readonly string $prefix,
    ) {}

    // -------------------------------------------------------------------------
    // Key builders (public for testing)
    // -------------------------------------------------------------------------

    public function idsKey(string $table, string $version): string
    {
        return "{$this->prefix}:{$table}:{$version}:ids";
    }

    public function recordKey(string $table, string $version, int|string $id): string
    {
        return "{$this->prefix}:{$table}:{$version}:record:{$id}";
    }

    public function indexKey(string $table, string $version, string $column): string
    {
        return "{$this->prefix}:{$table}:{$version}:idx:{$column}";
    }

    public function compositeIndexKey(string $table, string $version, string $name): string
    {
        return "{$this->prefix}:{$table}:{$version}:cidx:{$name}";
    }

    public function lockKey(string $table): string
    {
        return "{$this->prefix}:{$table}:lock";
    }

    // -------------------------------------------------------------------------
    // StoreInterface — IDs
    // -------------------------------------------------------------------------

    public function getIds(string $table, string $version): array|false
    {
        return apcu_fetch($this->idsKey($table, $version));
    }

    public function putIds(string $table, string $version, array $ids, int $ttl): void
    {
        apcu_store($this->idsKey($table, $version), $ids, $ttl);
    }

    // -------------------------------------------------------------------------
    // StoreInterface — Record
    // -------------------------------------------------------------------------

    /** @return array<string, mixed>|false */
    public function getRecord(string $table, string $version, int|string $id): array|false
    {
        return apcu_fetch($this->recordKey($table, $version, $id));
    }

    /** @param array<string, mixed> $record */
    public function putRecord(string $table, string $version, int|string $id, array $record, int $ttl): void
    {
        apcu_store($this->recordKey($table, $version, $id), $record, $ttl);
    }

    // -------------------------------------------------------------------------
    // StoreInterface — Index
    // -------------------------------------------------------------------------

    /**
     * @return list<array{mixed, list<int|string>}>|false
     */
    public function getIndex(string $table, string $version, string $column): array|false
    {
        return apcu_fetch($this->indexKey($table, $version, $column));
    }

    /**
     * @param  list<array{mixed, list<int|string>}>  $entries
     */
    public function putIndex(string $table, string $version, string $column, array $entries, int $ttl): void
    {
        apcu_store($this->indexKey($table, $version, $column), $entries, $ttl);
    }

    // -------------------------------------------------------------------------
    // StoreInterface — Composite Index
    // -------------------------------------------------------------------------

    /** @return array<string, list<int|string>>|false */
    public function getCompositeIndex(string $table, string $version, string $name): array|false
    {
        return apcu_fetch($this->compositeIndexKey($table, $version, $name));
    }

    /** @param array<string, list<int|string>> $map */
    public function putCompositeIndex(string $table, string $version, string $name, array $map, int $ttl): void
    {
        apcu_store($this->compositeIndexKey($table, $version, $name), $map, $ttl);
    }

    // -------------------------------------------------------------------------
    // StoreInterface — Lock
    // -------------------------------------------------------------------------

    public function acquireLock(string $table, int $ttl): bool
    {
        return apcu_add($this->lockKey($table), true, $ttl);
    }

    public function releaseLock(string $table): void
    {
        apcu_delete($this->lockKey($table));
    }

    public function isLocked(string $table): bool
    {
        return apcu_exists($this->lockKey($table));
    }

    // -------------------------------------------------------------------------
    // StoreInterface — Flush
    // -------------------------------------------------------------------------

    public function flush(string $table, string $version): void
    {
        $pattern = '/^'.preg_quote("{$this->prefix}:{$table}:{$version}:", '/').'/';
        apcu_delete(new \APCUIterator($pattern));
    }
}
