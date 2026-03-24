<?php

namespace Kura\Store;

/**
 * In-memory store for testing. No APCu required.
 */
class ArrayStore implements StoreInterface
{
    /** @var array<string, list<int|string>> */
    private array $pks = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $records = [];

    /** @var array<string, list<array{mixed, list<int|string>}>> */
    private array $indexes = [];

    /** @var array<string, array<string, list<int|string>>> */
    private array $compositeIndexes = [];

    /** @var array<string, true> */
    private array $locks = [];

    // -------------------------------------------------------------------------
    // PKs
    // -------------------------------------------------------------------------

    public function getPks(string $table, string $version): array|false
    {
        $key = "{$table}:{$version}:pks";

        return $this->pks[$key] ?? false;
    }

    public function putPks(string $table, string $version, array $pks, int $ttl): void
    {
        $key = "{$table}:{$version}:pks";
        $this->pks[$key] = $pks;
    }

    // -------------------------------------------------------------------------
    // Records
    // -------------------------------------------------------------------------

    /** @return array<string, mixed>|false */
    public function getRecord(string $table, string $version, int|string $id): array|false
    {
        $key = "{$table}:{$version}";

        return $this->records[$key][(string) $id] ?? false;
    }

    /** @param array<string, mixed> $record */
    public function putRecord(string $table, string $version, int|string $id, array $record, int $ttl): void
    {
        $key = "{$table}:{$version}";
        $this->records[$key][(string) $id] = $record;
    }

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    /**
     * @return list<array{mixed, list<int|string>}>|false
     */
    public function getIndex(string $table, string $version, string $column): array|false
    {
        $key = "{$table}:{$version}:idx:{$column}";

        return $this->indexes[$key] ?? false;
    }

    /**
     * @param  list<array{mixed, list<int|string>}>  $entries
     */
    public function putIndex(string $table, string $version, string $column, array $entries, int $ttl): void
    {
        $key = "{$table}:{$version}:idx:{$column}";
        $this->indexes[$key] = $entries;
    }

    // -------------------------------------------------------------------------
    // Composite Index
    // -------------------------------------------------------------------------

    /** @return array<string, list<int|string>>|false */
    public function getCompositeIndex(string $table, string $version, string $name): array|false
    {
        $key = "{$table}:{$version}:cidx:{$name}";

        return $this->compositeIndexes[$key] ?? false;
    }

    /** @param array<string, list<int|string>> $map */
    public function putCompositeIndex(string $table, string $version, string $name, array $map, int $ttl): void
    {
        $key = "{$table}:{$version}:cidx:{$name}";
        $this->compositeIndexes[$key] = $map;
    }

    // -------------------------------------------------------------------------
    // Lock
    // -------------------------------------------------------------------------

    public function acquireLock(string $table, int $ttl): bool
    {
        $key = "{$table}:lock";
        if (isset($this->locks[$key])) {
            return false;
        }
        $this->locks[$key] = true;

        return true;
    }

    public function isLocked(string $table): bool
    {
        return isset($this->locks["{$table}:lock"]);
    }

    /**
     * Release the lock for a table (test helper).
     */
    public function releaseLock(string $table): void
    {
        unset($this->locks["{$table}:lock"]);
    }

    // -------------------------------------------------------------------------
    // Flush
    // -------------------------------------------------------------------------

    public function flush(string $table, string $version): void
    {
        $prefix = "{$table}:{$version}";

        unset($this->pks["{$prefix}:pks"]);
        unset($this->records[$prefix]);

        foreach (array_keys($this->indexes) as $key) {
            if (str_starts_with($key, "{$prefix}:idx:")) {
                unset($this->indexes[$key]);
            }
        }

        foreach (array_keys($this->compositeIndexes) as $key) {
            if (str_starts_with($key, "{$prefix}:cidx:")) {
                unset($this->compositeIndexes[$key]);
            }
        }
    }
}
