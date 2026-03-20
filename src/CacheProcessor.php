<?php

namespace Kura;

use Kura\Exceptions\CacheInconsistencyException;
use Kura\Exceptions\IndexInconsistencyException;
use Kura\Index\IndexResolver;
use Kura\Store\ArrayStore;
use Kura\Store\StoreInterface;
use Kura\Support\RecordCursor;
use Kura\Support\WhereEvaluator;

/**
 * Orchestrates query execution over cached data.
 *
 * Responsibilities:
 *   - Lock check → Loader fallback
 *   - ids existence → Loader fallback + rebuild
 *   - Index resolution (via IndexResolver) to narrow candidate IDs
 *   - Record fetch with inconsistency detection
 *   - Self-Healing dispatch
 *
 * Index structure (which columns are indexed, which composites exist) is
 * derived from Loader::indexes() — not from an APCu meta key.
 *
 * cursor() is a generator that throws CacheInconsistencyException on record miss.
 * select() wraps cursor() and catches the exception, falling back to Loader.
 */
class CacheProcessor
{
    /** @var (\Closure(CacheRepository): void)|null */
    private ?\Closure $rebuildDispatcher;

    /** @var array<string, true>|null lazily derived from Loader::indexes() */
    private ?array $indexedColumnsCache = null;

    /** @var list<string>|null lazily derived from Loader::indexes() */
    private ?array $compositeNamesCache = null;

    /**
     * @param  (\Closure(CacheRepository): void)|null  $rebuildDispatcher
     *                                                                     Custom rebuild dispatcher. When null, rebuild() is called synchronously.
     *                                                                     For queue strategy: fn (CacheRepository $repo) => dispatch(new RebuildJob($repo))
     */
    public function __construct(
        private readonly CacheRepository $repository,
        private readonly StoreInterface $store,
        ?\Closure $rebuildDispatcher = null,
    ) {
        $this->rebuildDispatcher = $rebuildDispatcher;
    }

    /**
     * Execute a query as a generator.
     *
     * Throws CacheInconsistencyException if a record that should exist is missing.
     *
     * @param  list<array<string, mixed>>  $wheres
     * @param  list<array{column: string, direction: string}>  $orders
     * @return \Generator<int, array<string, mixed>>
     *
     * @throws CacheInconsistencyException
     */
    public function cursor(
        array $wheres,
        array $orders,
        ?int $limit,
        ?int $offset,
        bool $randomOrder,
    ): \Generator {
        $table = $this->repository->table();
        $version = $this->repository->version();

        // ロック中 → Loader 直撃
        if ($this->repository->isLocked()) {
            yield from $this->cursorFromLoader($wheres, $orders, $limit, $offset, $randomOrder);

            return;
        }

        $ids = $this->repository->ids();

        // ids なし → rebuild dispatch + Loader 直撃
        if ($ids === false) {
            $this->dispatchRebuild();
            yield from $this->cursorFromLoader($wheres, $orders, $limit, $offset, $randomOrder);

            return;
        }

        // Derive index structure from Loader (instance-cached per processor)
        [$indexedColumns, $compositeNames] = $this->resolveIndexDefs();

        // 候補 IDs の解決
        $candidateIds = $ids;

        if ($indexedColumns !== [] || $compositeNames !== []) {
            $resolver = new IndexResolver(
                $this->store,
                $table,
                $version,
                $indexedColumns,
                $compositeNames,
            );
            $resolved = $resolver->resolveIds($wheres);

            if ($resolved !== null) {
                $candidateIds = $resolved;
            }
        }

        // Record 欠損チェック用に hashmap を作成（array_flip で O(1) lookup）
        /** @var array<int|string, true> $idsMap */
        $idsMap = array_fill_keys($ids, true);

        // Single-column indexed orderBy → walk pre-sorted index directly (skip PHP sort)
        if (! $randomOrder && count($orders) === 1 && isset($indexedColumns[$orders[0]['column']])) {
            yield from $this->cursorFromCacheIndexWalked(
                candidateIds: $candidateIds,
                ids: $ids,
                idsMap: $idsMap,
                wheres: $wheres,
                order: $orders[0],
                limit: $limit,
                offset: $offset,
            );

            return;
        }

        // RecordCursor でフィルタ + ソート + ページネーション
        yield from $this->cursorFromCache($candidateIds, $idsMap, $wheres, $orders, $limit, $offset, $randomOrder);
    }

    /**
     * Execute a query and return all matching records as an array.
     *
     * Catches CacheInconsistencyException and falls back to Loader.
     *
     * @param  list<array<string, mixed>>  $wheres
     * @param  list<array{column: string, direction: string}>  $orders
     * @return list<array<string, mixed>>
     */
    public function select(
        array $wheres,
        array $orders,
        ?int $limit,
        ?int $offset,
        bool $randomOrder,
    ): array {
        try {
            return iterator_to_array($this->cursor($wheres, $orders, $limit, $offset, $randomOrder), preserve_keys: false);
        } catch (CacheInconsistencyException) {
            $this->dispatchRebuild();

            return iterator_to_array($this->cursorFromLoader($wheres, $orders, $limit, $offset, $randomOrder), preserve_keys: false);
        }
    }

    // -------------------------------------------------------------------------
    // Cache cursor with inconsistency detection
    // -------------------------------------------------------------------------

    /**
     * @param  list<int|string>  $candidateIds
     * @param  array<int|string, true>  $idsMap
     * @param  list<array<string, mixed>>  $wheres
     * @param  list<array{column: string, direction: string}>  $orders
     * @return \Generator<int, array<string, mixed>>
     *
     * @throws CacheInconsistencyException
     */
    private function cursorFromCache(
        array $candidateIds,
        array $idsMap,
        array $wheres,
        array $orders,
        ?int $limit,
        ?int $offset,
        bool $randomOrder,
    ): \Generator {
        // Sorted/random queries need all records upfront — delegate to RecordCursor
        // with inconsistency check integrated into the ID validation pass.
        if ($orders !== [] || $randomOrder) {
            yield from $this->cursorFromCacheSorted($candidateIds, $idsMap, $wheres, $orders, $limit, $offset, $randomOrder);

            return;
        }

        // No sorting: stream records with early exit on limit.
        // Record inconsistency is checked inline — no pre-fetch needed.
        $skipped = 0;
        $yielded = 0;

        foreach ($candidateIds as $id) {
            if ($limit !== null && $yielded >= $limit) {
                return;
            }

            $record = $this->repository->find($id);

            if ($record === null) {
                if (isset($idsMap[$id])) {
                    throw new CacheInconsistencyException(
                        "Record {$id} missing from cache but present in ids for table {$this->repository->table()}",
                        table: $this->repository->table(),
                        recordId: $id,
                    );
                }

                continue;
            }

            if (! WhereEvaluator::evaluate($record, $wheres)) {
                continue;
            }

            if ($offset !== null && $skipped < $offset) {
                $skipped++;

                continue;
            }

            yield $record;
            $yielded++;
        }
    }

    /**
     * Cache cursor for sorted/random queries — must collect all matching records.
     *
     * @param  list<int|string>  $candidateIds
     * @param  array<int|string, true>  $idsMap
     * @param  list<array<string, mixed>>  $wheres
     * @param  list<array{column: string, direction: string}>  $orders
     * @return \Generator<int, array<string, mixed>>
     *
     * @throws CacheInconsistencyException
     */
    private function cursorFromCacheSorted(
        array $candidateIds,
        array $idsMap,
        array $wheres,
        array $orders,
        ?int $limit,
        ?int $offset,
        bool $randomOrder,
    ): \Generator {
        yield from (new RecordCursor(
            ids: $candidateIds,
            repository: $this->repository,
            wheres: $wheres,
            orders: $orders,
            limit: $limit,
            offset: $offset,
            randomOrder: $randomOrder,
            idsMap: $idsMap,
        ))->generate();
    }

    /**
     * Walk the APCu index in sorted order and yield matching records.
     *
     * The index is already sorted by value, so no PHP-side sort is needed.
     * Offset and limit are applied by skipping/stopping during traversal,
     * giving O(offset + limit) performance instead of O(N log N).
     *
     * @param  list<int|string>  $candidateIds
     * @param  list<int|string>  $ids
     * @param  array<int|string, true>  $idsMap
     * @param  list<array<string, mixed>>  $wheres
     * @param  array{column: string, direction: string}  $order
     * @return \Generator<int, array<string, mixed>>
     *
     * @throws CacheInconsistencyException
     */
    private function cursorFromCacheIndexWalked(
        array $candidateIds,
        array $ids,
        array $idsMap,
        array $wheres,
        array $order,
        ?int $limit,
        ?int $offset,
    ): \Generator {
        $table = $this->repository->table();
        $version = $this->repository->version();
        $column = $order['column'];
        $desc = $order['direction'] === 'desc';

        // Build candidate map for O(1) membership check (only when IDs were narrowed)
        /** @var array<int|string, true>|null $candidateMap */
        $candidateMap = count($candidateIds) < count($ids)
            ? array_fill_keys($candidateIds, true)
            : null;

        $skipped = 0;
        $yielded = 0;

        foreach ($this->walkIndex($table, $version, $column, $desc) as [, $entryIds]) {
            foreach ($entryIds as $id) {
                if ($limit !== null && $yielded >= $limit) {
                    return;
                }

                if ($candidateMap !== null && ! isset($candidateMap[$id])) {
                    continue;
                }

                $record = $this->repository->find($id);

                if ($record === null) {
                    if (isset($idsMap[$id])) {
                        throw new CacheInconsistencyException(
                            "Record {$id} missing from cache but present in ids for table {$table}",
                            table: $table,
                            recordId: $id,
                        );
                    }

                    continue;
                }

                if (! WhereEvaluator::evaluate($record, $wheres)) {
                    continue;
                }

                if ($offset !== null && $skipped < $offset) {
                    $skipped++;

                    continue;
                }

                yield $record;
                $yielded++;
            }
        }
    }

    /**
     * Yield index entries in value order (asc or desc).
     *
     * @return \Generator<int, array{mixed, list<int|string>}>
     */
    private function walkIndex(string $table, string $version, string $column, bool $desc): \Generator
    {
        $entries = $this->store->getIndex($table, $version, $column);

        if ($entries === false) {
            throw new IndexInconsistencyException(
                "Index key missing for column '{$column}' in table '{$table}' during ordered walk (declared in Loader but missing from APCu)",
                table: $table,
                column: $column,
            );
        }

        yield from $desc ? array_reverse($entries) : $entries;
    }

    // -------------------------------------------------------------------------
    // Loader fallback
    // -------------------------------------------------------------------------

    /**
     * Execute query directly from Loader (bypass cache).
     *
     * @param  list<array<string, mixed>>  $wheres
     * @param  list<array{column: string, direction: string}>  $orders
     * @return \Generator<int, array<string, mixed>>
     */
    private function cursorFromLoader(
        array $wheres,
        array $orders,
        ?int $limit,
        ?int $offset,
        bool $randomOrder,
    ): \Generator {
        $table = $this->repository->table();
        $version = $this->repository->version();
        $primaryKey = $this->repository->primaryKey();

        // Use a temporary in-memory store to avoid polluting the shared APCu cache
        $tempStore = new ArrayStore;
        $tempIds = [];

        foreach ($this->repository->loader()->load() as $record) {
            $id = $record[$primaryKey];
            $tempIds[] = $id;
            $tempStore->putRecord($table, $version, $id, $record, 0);
        }

        $tempRepository = new CacheRepository(
            table: $table,
            primaryKey: $primaryKey,
            store: $tempStore,
            loader: $this->repository->loader(),
        );

        yield from (new RecordCursor(
            ids: $tempIds,
            repository: $tempRepository,
            wheres: $wheres,
            orders: $orders,
            limit: $limit,
            offset: $offset,
            randomOrder: $randomOrder,
        ))->generate();
    }

    // -------------------------------------------------------------------------
    // Rebuild dispatch
    // -------------------------------------------------------------------------

    /**
     * Dispatch a rebuild using the configured strategy.
     *
     * sync (default): calls rebuild() directly.
     * queue/callback: calls the injected dispatcher closure.
     */
    public function dispatchRebuild(): void
    {
        if ($this->rebuildDispatcher !== null) {
            ($this->rebuildDispatcher)($this->repository);

            return;
        }

        // Default: sync rebuild
        $this->repository->rebuild();
    }

    // -------------------------------------------------------------------------
    // Index definition helpers
    // -------------------------------------------------------------------------

    /**
     * Derive indexed columns and composite names from the Loader.
     *
     * Result is cached per CacheProcessor instance (one instance per table per request).
     *
     * @return array{array<string, true>, list<string>}
     */
    private function resolveIndexDefs(): array
    {
        if ($this->indexedColumnsCache === null) {
            $indexDefs = $this->repository->loader()->indexes();
            $indexedColumns = [];
            $compositeNames = [];

            foreach ($indexDefs as $def) {
                foreach ($def['columns'] as $col) {
                    $indexedColumns[$col] = true;
                }
                if (count($def['columns']) >= 2) {
                    $compositeNames[] = implode('|', $def['columns']);
                }
            }

            $this->indexedColumnsCache = $indexedColumns;
            $this->compositeNamesCache = $compositeNames;
        }

        return [$this->indexedColumnsCache, $this->compositeNamesCache ?? []];
    }
}
