<?php

namespace Kura\Support;

use Kura\CacheRepository;
use Kura\Exceptions\CacheInconsistencyException;

/**
 * Generator-based cursor that fetches and filters records from CacheRepository.
 *
 * Traversal strategies:
 *   generate()       — streaming with early exit on limit (no sort)
 *   generateSorted() — collect all, sort, then yield with offset/limit
 *   generateRandom() — reservoir sampling when limit is set, else shuffle
 *
 * Predicate evaluation is delegated to WhereEvaluator.
 *
 * When $pksMap is provided, record inconsistency is detected inline (one fetch pass).
 * This avoids the double-fetch that occurs when CacheProcessor pre-validates PKs
 * before delegating to this cursor for sorted/random queries.
 */
class RecordCursor
{
    /**
     * @param  list<int|string>  $pks
     * @param  list<array<string, mixed>>  $wheres  already resolved (no Closures in 'in')
     * @param  list<array{column: string, direction: string}>  $orders
     * @param  array<int|string, true>  $pksMap  when non-empty, throws CacheInconsistencyException on record miss
     */
    public function __construct(
        private readonly array $pks,
        private readonly CacheRepository $repository,
        private readonly array $wheres,
        private readonly array $orders,
        private readonly ?int $limit,
        private readonly ?int $offset,
        private readonly bool $randomOrder = false,
        private readonly array $pksMap = [],
    ) {}

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function generate(): \Generator
    {
        if ($this->randomOrder) {
            yield from $this->generateRandom();

            return;
        }

        if ($this->orders !== []) {
            yield from $this->generateSorted();

            return;
        }

        $skipped = 0;
        $yielded = 0;

        foreach ($this->pks as $pk) {
            if ($this->limit !== null && $yielded >= $this->limit) {
                return;
            }

            $record = $this->repository->find($pk);
            if ($record === null || ! WhereEvaluator::evaluate($record, $this->wheres)) {
                continue;
            }

            if ($this->offset !== null && $skipped < $this->offset) {
                $skipped++;

                continue;
            }

            yield $record;
            $yielded++;
        }
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function generateSorted(): \Generator
    {
        $records = [];

        foreach ($this->pks as $pk) {
            $record = $this->repository->find($pk);

            if ($record === null) {
                if ($this->pksMap !== [] && isset($this->pksMap[$pk])) {
                    throw new CacheInconsistencyException(
                        "Record {$pk} missing from cache but present in pks for table {$this->repository->table()}",
                        table: $this->repository->table(),
                        recordId: $pk,
                    );
                }

                continue;
            }

            if (! WhereEvaluator::evaluate($record, $this->wheres)) {
                continue;
            }

            $records[] = $record;
        }

        usort($records, $this->buildSorter());

        foreach (array_slice($records, $this->offset ?? 0, $this->limit) as $record) {
            yield $record;
        }
    }

    /**
     * Random order with reservoir sampling when limit is set.
     *
     * When limit+offset is known, uses reservoir sampling to keep at most
     * (limit+offset) records in memory instead of collecting all matches.
     * Without limit, falls back to collect-all + shuffle.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function generateRandom(): \Generator
    {
        $reservoirSize = $this->limit !== null
            ? $this->limit + ($this->offset ?? 0)
            : null;

        if ($reservoirSize === null) {
            // No limit — must collect all, shuffle, then yield
            $records = [];
            foreach ($this->pks as $pk) {
                $record = $this->repository->find($pk);

                if ($record === null) {
                    if ($this->pksMap !== [] && isset($this->pksMap[$pk])) {
                        throw new CacheInconsistencyException(
                            "Record {$pk} missing from cache but present in pks for table {$this->repository->table()}",
                            table: $this->repository->table(),
                            recordId: $pk,
                        );
                    }

                    continue;
                }

                if (! WhereEvaluator::evaluate($record, $this->wheres)) {
                    continue;
                }

                $records[] = $record;
            }

            shuffle($records);

            foreach (array_slice($records, $this->offset ?? 0) as $record) {
                yield $record;
            }

            return;
        }

        // Reservoir sampling: keep at most $reservoirSize items
        /** @var list<array<string, mixed>> $reservoir */
        $reservoir = [];
        $seen = 0;

        foreach ($this->pks as $pk) {
            $record = $this->repository->find($pk);

            if ($record === null) {
                if ($this->pksMap !== [] && isset($this->pksMap[$pk])) {
                    throw new CacheInconsistencyException(
                        "Record {$pk} missing from cache but present in pks for table {$this->repository->table()}",
                        table: $this->repository->table(),
                        recordId: $pk,
                    );
                }

                continue;
            }

            if (! WhereEvaluator::evaluate($record, $this->wheres)) {
                continue;
            }

            $seen++;

            if ($seen <= $reservoirSize) {
                $reservoir[] = $record;
            } else {
                // Replace a random element with probability reservoirSize/seen
                $j = random_int(1, $seen);
                if ($j <= $reservoirSize) {
                    $reservoir[$j - 1] = $record;
                }
            }
        }

        shuffle($reservoir);

        foreach (array_slice($reservoir, $this->offset ?? 0, $this->limit) as $record) {
            yield $record;
        }
    }

    // -------------------------------------------------------------------------
    // Shared utilities
    // -------------------------------------------------------------------------

    private function buildSorter(): \Closure
    {
        $orders = $this->orders;

        return function (array $a, array $b) use ($orders): int {
            foreach ($orders as ['column' => $col, 'direction' => $dir]) {
                $va = $a[$col] ?? null;
                $vb = $b[$col] ?? null;

                // MySQL semantics: NULL is smallest (first in ASC, last in DESC)
                if ($va === null && $vb === null) {
                    continue;
                }
                if ($va === null) {
                    return $dir === 'desc' ? 1 : -1;
                }
                if ($vb === null) {
                    return $dir === 'desc' ? -1 : 1;
                }

                $cmp = $va <=> $vb;
                if ($cmp !== 0) {
                    return $dir === 'desc' ? -$cmp : $cmp;
                }
            }

            return 0;
        };
    }
}
