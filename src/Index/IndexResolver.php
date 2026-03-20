<?php

namespace Kura\Index;

use Kura\Exceptions\IndexInconsistencyException;
use Kura\Store\StoreInterface;

/**
 * Resolves candidate IDs from stored indexes using where conditions.
 *
 * Index structure (which columns are indexed, which composites exist) is
 * provided at construction time from the Loader — not read from APCu meta.
 *
 * Returns null when the condition cannot be resolved via index (full scan needed).
 * Returns list<int|string> when IDs were successfully resolved.
 */
final class IndexResolver
{
    /** @var array<string, true> */
    private readonly array $indexedColumnsMap;

    /**
     * @param  array<string, true>  $indexedColumns  set of indexed column names
     * @param  list<string>  $compositeNames  composite index names (e.g. 'country|type')
     */
    public function __construct(
        private readonly StoreInterface $store,
        private readonly string $table,
        private readonly string $version,
        array $indexedColumns,
        private readonly array $compositeNames,
    ) {
        $this->indexedColumnsMap = $indexedColumns;
    }

    /**
     * Resolve candidate IDs for a single where condition.
     *
     * @param  array<string, mixed>  $where
     * @return list<int|string>|null null = not index-resolvable
     */
    public function resolveForWhere(array $where): ?array
    {
        return match ($where['type']) {
            'basic' => $this->resolveBasic($where),
            'between' => $this->resolveBetween($where),
            'in' => $this->resolveIn($where),
            'rowValuesIn' => $this->resolveRowValuesIn($where),
            default => null,
        };
    }

    /**
     * Resolve candidate IDs for multiple where conditions.
     *
     * 1. Try composite index first (multiple AND equality conditions on a composite key).
     * 2. Fall back to per-condition resolution with AND/OR logic.
     *
     * Partial AND resolution: if an AND condition cannot be index-resolved, it is
     * skipped here and left to WhereEvaluator. This narrows candidates using whatever
     * indexes are available without abandoning the entire index resolution.
     *
     * OR conditions are all-or-nothing: if any OR branch is not index-resolvable,
     * we cannot safely narrow (records matching only that branch would be missed).
     *
     * @param  list<array<string, mixed>>  $wheres
     * @return list<int|string>|null null = cannot narrow (full scan needed)
     */
    public function resolveIds(array $wheres): ?array
    {
        if ($wheres === []) {
            return null;
        }

        // Try composite index lookup for AND equality conditions
        $compositeResult = $this->tryCompositeIndex($wheres);
        if ($compositeResult !== null) {
            return $compositeResult;
        }

        /** @var array<int|string, true>|null $result null = not yet established */
        $result = null;

        // Track whether any AND conditions were skipped before the first resolved result.
        // If true and we later encounter an OR, we cannot safely narrow.
        $skippedAndBeforeResult = false;

        foreach ($wheres as $where) {
            $boolean = $where['boolean'] ?? 'and';
            $ids = $this->resolveForWhere($where);

            if ($ids === null) {
                if ($boolean === 'or') {
                    // Non-resolvable OR branch: records matching only this branch
                    // would be missed → must full scan.
                    return null;
                }

                // Non-resolvable AND: skip. WhereEvaluator will evaluate it.
                if ($result === null) {
                    $skippedAndBeforeResult = true;
                }

                continue;
            }

            $set = array_fill_keys($ids, true);

            if ($result === null) {
                if ($skippedAndBeforeResult && $boolean === 'or') {
                    // Skipped AND conditions precede this OR — the OR's "other side"
                    // is unknown, so we cannot safely narrow.
                    return null;
                }

                $result = $set;
            } elseif ($boolean === 'and') {
                $result = array_intersect_key($result, $set);
            } else {
                // OR: union. Previously skipped AND conditions only narrow the AND
                // group, so $result is already a superset — safe to union.
                $result += $set;
            }
        }

        return $result !== null ? array_keys($result) : null;
    }

    // -------------------------------------------------------------------------
    // Composite index
    // -------------------------------------------------------------------------

    /**
     * Try to resolve IDs using a composite index.
     *
     * Matches when all conditions are AND + basic '=' and their columns
     * exactly match a registered composite index.
     *
     * @param  list<array<string, mixed>>  $wheres
     * @return list<int|string>|null
     */
    private function tryCompositeIndex(array $wheres): ?array
    {
        if ($this->compositeNames === []) {
            return null;
        }

        // Collect AND conditions: column → single value (=) or value list (in)
        // Any OR, NOT IN, or non-equality/non-in condition disqualifies composite resolution.
        /** @var array<string, list<mixed>> $colValues column → list of candidate values */
        $colValues = [];
        foreach ($wheres as $where) {
            $boolean = $where['boolean'] ?? 'and';
            if ($boolean !== 'and') {
                return null;
            }
            $type = $where['type'] ?? '';
            if ($type === 'basic' && ($where['operator'] ?? '') === '=') {
                /** @var string $column */
                $column = $where['column'];
                $colValues[$column] = [(string) $where['value']];
            } elseif ($type === 'in' && ! $where['not']) {
                /** @var string $column */
                $column = $where['column'];
                $colValues[$column] = array_map('strval', $where['values']);
            } else {
                return null;
            }
        }

        // Find a composite index whose columns are all covered by conditions
        foreach ($this->compositeNames as $name) {
            $cols = explode('|', $name);
            if (count($cols) !== count($colValues)) {
                continue;
            }

            $allMatch = true;
            /** @var list<list<string>> $valueGroups */
            $valueGroups = [];
            foreach ($cols as $col) {
                if (! array_key_exists($col, $colValues)) {
                    $allMatch = false;
                    break;
                }
                $valueGroups[] = array_values($colValues[$col]);
            }

            if (! $allMatch) {
                continue;
            }

            // Fetch composite index once
            $map = $this->store->getCompositeIndex($this->table, $this->version, $name);
            if ($map === false) {
                throw new IndexInconsistencyException(
                    "Composite index key missing for '{$name}' in table '{$this->table}' (declared in Loader but missing from APCu)",
                    table: $this->table,
                    column: $name,
                );
            }

            // Cartesian product of value groups → hashmap key lookups
            $ids = [];
            foreach ($this->cartesian($valueGroups) as $combo) {
                $key = implode('|', $combo);
                foreach ($map[$key] ?? [] as $id) {
                    $ids[] = $id;
                }
            }

            return array_values(array_unique($ids));
        }

        return null;
    }

    /**
     * Compute the Cartesian product of a list of value groups.
     *
     * cartesian([['JP','US'], ['A','B']]) → [['JP','A'],['JP','B'],['US','A'],['US','B']]
     *
     * @param  list<list<string>>  $groups
     * @return list<list<string>>
     */
    private function cartesian(array $groups): array
    {
        $result = [[]];
        foreach ($groups as $group) {
            $next = [];
            foreach ($result as $existing) {
                foreach ($group as $value) {
                    $next[] = [...$existing, $value];
                }
            }
            $result = $next;
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // ROW constructor IN — composite index acceleration
    // -------------------------------------------------------------------------

    /**
     * Resolve IDs for (col1, col2) IN ((v1a, v2a), (v1b, v2b)) via composite index.
     *
     * When a composite index matches the columns, each tuple is looked up in O(1)
     * from the hashmap, and the results are unioned. Falls back to null (full scan)
     * when no matching composite index exists or NOT IN is used.
     *
     * @param  array<string, mixed>  $where
     * @return list<int|string>|null
     */
    private function resolveRowValuesIn(array $where): ?array
    {
        if ($where['not']) {
            return null; // NOT IN cannot be accelerated via index
        }

        /** @var list<string> $columns */
        $columns = $where['columns'];
        $compositeName = implode('|', $columns);

        if (! in_array($compositeName, $this->compositeNames, true)) {
            return null; // No matching composite index
        }

        $map = $this->store->getCompositeIndex($this->table, $this->version, $compositeName);
        if ($map === false) {
            throw new IndexInconsistencyException(
                "Composite index key missing for '{$compositeName}' in table '{$this->table}' (declared in Loader but missing from APCu)",
                table: $this->table,
                column: $compositeName,
            );
        }

        /** @var array<int|string, true> $idSet */
        $idSet = [];
        foreach ($where['tuples'] as $tuple) {
            $key = implode('|', array_map('strval', $tuple));
            foreach ($map[$key] ?? [] as $id) {
                $idSet[$id] = true;
            }
        }

        return array_keys($idSet);
    }

    // -------------------------------------------------------------------------
    // Per-type resolvers
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $where
     * @return list<int|string>|null
     */
    private function resolveBasic(array $where): ?array
    {
        $column = $where['column'];
        $operator = $where['operator'];
        $value = $where['value'];

        if (! $this->isIndexed($column)) {
            return null;
        }

        // Only these operators are index-resolvable
        if (! in_array($operator, ['=', '>', '>=', '<', '<='], true)) {
            return null;
        }

        return $this->searchIndex($column, $operator, $value);
    }

    /**
     * @param  array<string, mixed>  $where
     * @return list<int|string>|null
     */
    private function resolveBetween(array $where): ?array
    {
        if ($where['not']) {
            return null;
        }

        $column = $where['column'];

        if (! $this->isIndexed($column)) {
            return null;
        }

        return $this->searchIndexBetween($column, $where['values'][0], $where['values'][1]);
    }

    /**
     * @param  array<string, mixed>  $where
     * @return list<int|string>|null
     */
    private function resolveIn(array $where): ?array
    {
        if ($where['not']) {
            return null;
        }

        $column = $where['column'];

        if (! $this->isIndexed($column)) {
            return null;
        }

        // Fetch index once, then binary-search all values in memory
        $entries = $this->store->getIndex($this->table, $this->version, $column);

        if ($entries === false) {
            throw new IndexInconsistencyException(
                "Index key missing for column '{$column}' in table '{$this->table}' (declared in Loader but missing from APCu)",
            );
        }

        $allIds = [];
        foreach ($where['values'] as $value) {
            foreach (BinarySearch::equal($entries, $value) as $id) {
                $allIds[] = $id;
            }
        }

        return array_values(array_unique($allIds));
    }

    // -------------------------------------------------------------------------
    // Index access
    // -------------------------------------------------------------------------

    /**
     * @return list<int|string>|null
     */
    private function searchIndex(string $column, string $operator, mixed $value): ?array
    {
        $entries = $this->store->getIndex($this->table, $this->version, $column);

        if ($entries === false) {
            throw new IndexInconsistencyException(
                "Index key missing for column '{$column}' in table '{$this->table}' (declared in Loader but missing from APCu)",
                table: $this->table,
                column: $column,
            );
        }

        return match ($operator) {
            '=' => BinarySearch::equal($entries, $value),
            '>' => BinarySearch::greaterThan($entries, $value),
            '>=' => BinarySearch::greaterThanOrEqual($entries, $value),
            '<' => BinarySearch::lessThan($entries, $value),
            '<=' => BinarySearch::lessThanOrEqual($entries, $value),
            default => null,
        };
    }

    /**
     * @return list<int|string>
     */
    private function searchIndexBetween(string $column, mixed $min, mixed $max): array
    {
        $entries = $this->store->getIndex($this->table, $this->version, $column);

        if ($entries === false) {
            throw new IndexInconsistencyException(
                "Index key missing for column '{$column}' in table '{$this->table}' (declared in Loader but missing from APCu)",
                table: $this->table,
                column: $column,
            );
        }

        return BinarySearch::between($entries, $min, $max);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function isIndexed(string $column): bool
    {
        return isset($this->indexedColumnsMap[$column]);
    }
}
