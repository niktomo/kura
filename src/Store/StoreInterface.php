<?php

namespace Kura\Store;

interface StoreInterface
{
    // pks

    /** @return list<int|string>|false */
    public function getPks(string $table, string $version): array|false;

    /** @param list<int|string> $pks */
    public function putPks(string $table, string $version, array $pks, int $ttl): void;

    // record

    /** @return array<string, mixed>|false */
    public function getRecord(string $table, string $version, int|string $id): array|false;

    /** @param array<string, mixed> $record */
    public function putRecord(string $table, string $version, int|string $id, array $record, int $ttl): void;

    // index (whole-column, [[value, [ids]], ...] format)

    /**
     * @return list<array{mixed, list<int|string>}>|false
     */
    public function getIndex(string $table, string $version, string $column): array|false;

    /**
     * @param  list<array{mixed, list<int|string>}>  $entries
     */
    public function putIndex(string $table, string $version, string $column, array $entries, int $ttl): void;

    // composite index (combined-value hashmap)

    /**
     * @return array<string, list<int|string>>|false combined_key => ids
     */
    public function getCompositeIndex(string $table, string $version, string $name): array|false;

    /**
     * @param  array<string, list<int|string>>  $map  combined_key => ids
     */
    public function putCompositeIndex(string $table, string $version, string $name, array $map, int $ttl): void;

    // lock (version-independent)

    public function acquireLock(string $table, int $ttl): bool;

    public function releaseLock(string $table): void;

    public function isLocked(string $table): bool;

    // flush

    public function flush(string $table, string $version): void;
}
