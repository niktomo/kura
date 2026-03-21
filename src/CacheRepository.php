<?php

namespace Kura;

use Kura\Index\IndexBuilder;
use Kura\Loader\LoaderInterface;
use Kura\Store\StoreInterface;

/**
 * Thin data layer over StoreInterface for a single table.
 *
 * Provides ids(), find(), isLocked(), rebuild().
 * No auto-reload on cache miss — the caller (CacheProcessor or query layer)
 * decides when to trigger a rebuild.
 */
class CacheRepository
{
    public function __construct(
        private readonly string $table,
        private readonly string $primaryKey,
        private readonly StoreInterface $store,
        private readonly LoaderInterface $loader,
        private readonly ?string $versionOverride = null,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function table(): string
    {
        return $this->table;
    }

    public function primaryKey(): string
    {
        return $this->primaryKey;
    }

    public function store(): StoreInterface
    {
        return $this->store;
    }

    public function loader(): LoaderInterface
    {
        return $this->loader;
    }

    public function version(): string
    {
        return (string) ($this->versionOverride ?? $this->loader->version());
    }

    /**
     * Return the IDs list from the store, or false if not cached.
     *
     * @return list<int|string>|false
     */
    public function ids(): array|false
    {
        return $this->store->getIds($this->table, $this->version());
    }

    /**
     * Fetch a single record from the store, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function find(int|string $id): ?array
    {
        $record = $this->store->getRecord($this->table, $this->version(), $id);

        return $record !== false ? $record : null;
    }

    public function isLocked(): bool
    {
        return $this->store->isLocked($this->table);
    }

    /**
     * Full rebuild: flush and re-import all records from the loader.
     *
     * Both record/ids writes (Phase 1) and index writes (Phase 2) are performed
     * inside the lock. This eliminates the Phase-1→Phase-2 window where index
     * keys are absent while ids is already present, preventing thundering-herd
     * against the data source during that window.
     *
     * @param  array{ids?: int, record?: int, index?: int, ids_jitter?: int}  $ttl
     */
    public function rebuild(array $ttl = [], int $lockTtl = 60): void
    {
        if (! $this->store->acquireLock($this->table, $lockTtl)) {
            return; // Another process is already rebuilding
        }

        $version = $this->version();
        $idsJitter = $ttl['ids_jitter'] ?? 0;
        $idsTtl = ($ttl['ids'] ?? 3600) + ($idsJitter > 0 ? random_int(0, $idsJitter) : 0);
        $recordTtl = $ttl['record'] ?? 4800;
        $indexTtl = $ttl['index'] ?? $idsTtl; // Default: same as ids (including jitter)

        try {
            $this->store->flush($this->table, $version);

            // Phase 1: load records + build ids + collect index data
            /** @var list<int|string> $idsList */
            $idsList = [];
            /** @var array<string, array<string|int, list<int|string>>> $indexData col → [value → [ids]] */
            $indexData = [];
            /** @var array<string, array<string, list<int|string>>> $compositeData name → [combined_key → [ids]] */
            $compositeData = [];
            $indexDefinitions = $this->loader->indexes();
            $indexedColumns = $this->extractIndexedColumns($indexDefinitions);
            $compositeDefs = $this->extractCompositeDefs($indexDefinitions);

            foreach ($this->loader->load() as $record) {
                $id = $record[$this->primaryKey];
                $idsList[] = $id;
                $this->store->putRecord($this->table, $version, $id, $record, $recordTtl);

                // Collect single-column index data
                foreach ($indexedColumns as $col) {
                    $value = $record[$col] ?? null;
                    if ($value !== null) {
                        $indexData[$col][(string) $value] ??= [];
                        $indexData[$col][(string) $value][] = $id;
                    }
                }

                // Collect composite index data
                foreach ($compositeDefs as $name => $cols) {
                    $parts = [];
                    $skip = false;
                    foreach ($cols as $col) {
                        $value = $record[$col] ?? null;
                        if ($value === null) {
                            $skip = true;
                            break;
                        }
                        $parts[] = (string) $value;
                    }
                    if ($skip) {
                        continue;
                    }
                    $combinedKey = implode('|', $parts);
                    $compositeData[$name][$combinedKey] ??= [];
                    $compositeData[$name][$combinedKey][] = $id;
                }
            }

            $this->store->putIds($this->table, $version, $idsList, $idsTtl);

            // Phase 2: build and write indexes (inside lock — eliminates Phase-1→2 window)
            $indexBuilder = new IndexBuilder($this->primaryKey);

            foreach ($indexData as $column => $valueMap) {
                ksort($valueMap, SORT_NATURAL);
                /** @var list<array{mixed, list<int|string>}> $entries */
                $entries = [];
                foreach ($valueMap as $value => $ids) {
                    $entries[] = [$indexBuilder->restoreType($value), $ids];
                }
                $this->store->putIndex($this->table, $version, $column, $entries, $indexTtl);
            }

            // Write empty index for indexed columns with no data
            foreach ($indexedColumns as $col) {
                if (! isset($indexData[$col])) {
                    $this->store->putIndex($this->table, $version, $col, [], $indexTtl);
                }
            }

            // Write composite indexes
            foreach ($compositeData as $name => $map) {
                $this->store->putCompositeIndex($this->table, $version, $name, $map, $indexTtl);
            }

            // Write empty composite index for definitions with no data
            foreach ($compositeDefs as $name => $cols) {
                if (! isset($compositeData[$name])) {
                    $this->store->putCompositeIndex($this->table, $version, $name, [], $indexTtl);
                }
            }
        } finally {
            $this->store->releaseLock($this->table);
        }
    }

    /**
     * Extract all columns that need indexing from index definitions.
     *
     * Composite indexes auto-expand to include each column.
     *
     * @param  list<array{columns: list<string>, unique: bool}>  $definitions
     * @return list<string>
     */
    private function extractIndexedColumns(array $definitions): array
    {
        $columns = [];
        foreach ($definitions as $def) {
            foreach ($def['columns'] as $col) {
                $columns[$col] = true;
            }
        }

        return array_keys($columns);
    }

    /**
     * Extract composite (multi-column) index definitions.
     *
     * Returns name (col1|col2) → list of columns for definitions with 2+ columns.
     *
     * @param  list<array{columns: list<string>, unique: bool}>  $definitions
     * @return array<string, list<string>>
     */
    private function extractCompositeDefs(array $definitions): array
    {
        $composites = [];
        foreach ($definitions as $def) {
            if (count($def['columns']) >= 2) {
                $name = implode('|', $def['columns']);
                $composites[$name] = $def['columns'];
            }
        }

        return $composites;
    }

    /**
     * Simple reload for backward compatibility.
     * Delegates to rebuild() with default TTLs.
     */
    public function reload(): void
    {
        $this->rebuild();
    }
}
