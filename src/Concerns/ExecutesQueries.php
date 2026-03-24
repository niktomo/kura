<?php

namespace Kura\Concerns;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Kura\Exceptions\MultipleRecordsFoundException;
use Kura\Exceptions\RecordsNotFoundException;

/**
 * Query execution methods: cursor, get, find, aggregates, pagination, etc.
 *
 * Expects the using class to expose these protected members:
 *   array  $wheres
 *   array  $orders
 *   ?int   $limit
 *   ?int   $offset
 *   bool   $randomOrder
 *   string $primaryKey
 *   string $table
 *   CacheRepository $repository
 *   CacheProcessor  $processor
 *
 * And these protected methods:
 *   resolveSubqueries(array): array
 */
trait ExecutesQueries
{
    // =========================================================================
    // Core execution
    // =========================================================================

    /**
     * Generator-based cursor — low memory, lazy evaluation.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function cursor(): \Generator
    {
        $wheres = $this->resolveSubqueries($this->wheres);

        return $this->processor->cursor(
            wheres: $wheres,
            orders: $this->orders,
            limit: $this->limit,
            offset: $this->offset,
            randomOrder: $this->randomOrder,
        );
    }

    /**
     * Collect all matching records into an array.
     *
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        $wheres = $this->resolveSubqueries($this->wheres);

        return $this->processor->select(
            wheres: $wheres,
            orders: $this->orders,
            limit: $this->limit,
            offset: $this->offset,
            randomOrder: $this->randomOrder,
        );
    }

    /**
     * Return the first matching record or null.
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        foreach ($this->cursor() as $record) {
            return $record;
        }

        return null;
    }

    /**
     * Return the only matching record.
     *
     *
     * @return array<string, mixed>
     *
     * @throws RecordsNotFoundException if no records match
     * @throws MultipleRecordsFoundException if more than one record matches
     */
    public function sole(): array
    {
        $results = $this->clone()->limit(2)->get();

        if ($results === []) {
            throw new RecordsNotFoundException;
        }

        if (count($results) > 1) {
            throw new MultipleRecordsFoundException(count($results));
        }

        return $results[0];
    }

    /**
     * Return the value of a single column from the only matching record.
     *
     * @throws RecordsNotFoundException
     * @throws MultipleRecordsFoundException
     */
    public function soleValue(string $column): mixed
    {
        return $this->sole()[$column] ?? null;
    }

    // =========================================================================
    // Find by primary key
    // =========================================================================

    /**
     * Find a record by its primary key.
     *
     * Delegates directly to CacheRepository for an O(1) APCu lookup.
     * On cache miss, checks ids to determine if rebuild is needed.
     *
     * @return array<string, mixed>|null
     */
    public function find(int|string $id): ?array
    {
        $record = $this->repository->find($id);

        if ($record !== null) {
            return $record;
        }

        // Cache miss — check if pks exist to decide recovery strategy
        $pks = $this->repository->pks();

        if ($pks === false) {
            // No cache at all — rebuild and retry
            $this->processor->dispatchRebuild();

            return $this->repository->find($id);
        }

        // pks exist but record missing
        if (isset($pks[$id])) {
            // Record should exist — inconsistency, rebuild
            $this->processor->dispatchRebuild();

            return $this->repository->find($id);
        }

        // Not in pks — genuinely doesn't exist
        return null;
    }

    /**
     * Find a record by primary key, or call the callback if not found.
     *
     * @return array<string, mixed>|mixed
     */
    public function findOr(int|string $id, \Closure $callback): mixed
    {
        $record = $this->find($id);

        return $record !== null ? $record : $callback();
    }

    // =========================================================================
    // Single-value extraction
    // =========================================================================

    /**
     * Return the value of $column from the first matching record.
     */
    public function value(string $column): mixed
    {
        return $this->first()[$column] ?? null;
    }

    /**
     * Return values of $column from all matching records joined by $glue.
     */
    public function implode(string $column, string $glue = ''): string
    {
        return implode($glue, $this->pluck($column));
    }

    // =========================================================================
    // count / pluck
    // =========================================================================

    public function count(): int
    {
        $n = 0;
        foreach ($this->cursor() as $_) {
            $n++;
        }

        return $n;
    }

    /**
     * Return an array of values for a single column.
     *
     * When $key is provided, the result is keyed by that column.
     *
     *   $names = $builder->pluck('name');           // ['Alice', 'Bob', ...]
     *   $nameById = $builder->pluck('name', 'id');  // [1 => 'Alice', 2 => 'Bob', ...]
     *
     * @return array<array-key, mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $records = $this->get();

        if ($key === null) {
            return array_column($records, $column);
        }

        return array_column($records, $column, $key);
    }

    // =========================================================================
    // Existence checks
    // =========================================================================

    public function exists(): bool
    {
        return $this->first() !== null;
    }

    public function doesntExist(): bool
    {
        return ! $this->exists();
    }

    /**
     * Return true if the query has results, otherwise call the callback and
     * return its return value.
     */
    public function existsOr(\Closure $callback): mixed
    {
        return $this->exists() ? true : $callback();
    }

    /**
     * Return true if the query has no results, otherwise call the callback and
     * return its return value.
     */
    public function doesntExistOr(\Closure $callback): mixed
    {
        return $this->doesntExist() ? true : $callback();
    }

    // =========================================================================
    // Aggregates
    // =========================================================================

    /**
     * Return the minimum value of $column across all matching records.
     * Returns null if there are no matching records.
     */
    public function min(string $column): mixed
    {
        $values = $this->pluck($column);

        return $values !== [] ? min($values) : null;
    }

    /**
     * Return the maximum value of $column across all matching records.
     * Returns null if there are no matching records.
     */
    public function max(string $column): mixed
    {
        $values = $this->pluck($column);

        return $values !== [] ? max($values) : null;
    }

    /**
     * Return the sum of $column across all matching records (0 for empty set).
     */
    public function sum(string $column): int|float
    {
        return array_sum($this->pluck($column));
    }

    /**
     * Return the average of $column across all matching records.
     * Returns null if there are no matching records.
     */
    public function avg(string $column): int|float|null
    {
        $values = $this->pluck($column);

        if ($values === []) {
            return null;
        }

        return array_sum($values) / count($values);
    }

    /** Alias for avg(). */
    public function average(string $column): int|float|null
    {
        return $this->avg($column);
    }

    // =========================================================================
    // Pagination
    // =========================================================================

    /**
     * Paginate the results using a LengthAwarePaginator (includes total count).
     *
     *   $page = $builder->paginate(15);
     *   $page = $builder->paginate(15, page: 2);
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(
        int $perPage = 15,
        string $pageName = 'page',
        ?int $page = null,
    ): LengthAwarePaginator {
        $page = $page ?? (int) Paginator::resolveCurrentPage($pageName);

        // Count total without limit/offset — clone the current state.
        $total = $this->cloneWithout(['limit', 'offset'])->count();

        $results = $total > 0
            ? $this->forPage($page, $perPage)->get()
            : [];

        return new LengthAwarePaginator(
            items: $results,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ],
        );
    }

    /**
     * Paginate using a simple Paginator (no total count — more efficient).
     *
     * Fetches perPage + 1 records to determine whether a next page exists.
     *
     * @return Paginator<int, array<string, mixed>>
     */
    public function simplePaginate(
        int $perPage = 15,
        string $pageName = 'page',
        ?int $page = null,
    ): Paginator {
        $page = $page ?? (int) Paginator::resolveCurrentPage($pageName);

        $results = $this->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();

        return new Paginator(
            items: $results,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ],
        );
    }

    // =========================================================================
    // Debug helpers
    // =========================================================================

    /** Dump the current query state and return $this for chaining. */
    public function dump(): static
    {
        dump([
            'wheres' => $this->wheres,
            'orders' => $this->orders,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'randomOrder' => $this->randomOrder,
        ]);

        return $this;
    }

    /** Dump and die. */
    public function dd(): never
    {
        dd([
            'wheres' => $this->wheres,
            'orders' => $this->orders,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'randomOrder' => $this->randomOrder,
        ]);
    }
}
