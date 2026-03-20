<?php

namespace Kura\Tests\Index;

use Kura\Exceptions\IndexInconsistencyException;
use Kura\Index\IndexResolver;
use Kura\Store\ArrayStore;
use PHPUnit\Framework\TestCase;

/**
 * Feature: Resolve candidate IDs from index using where conditions
 *
 * Given index data stored in a Store,
 * When resolving IDs for various where conditions,
 * Then the correct candidate IDs should be returned
 * using binary search on the sorted index entries.
 */
class IndexResolverTest extends TestCase
{
    private ArrayStore $store;

    /** @var array<string, true> */
    private array $defaultIndexedColumns;

    protected function setUp(): void
    {
        $this->store = new ArrayStore;
        $this->defaultIndexedColumns = ['country' => true, 'price' => true];

        // Store index entries for 'country'
        $this->store->putIndex('products', 'v1', 'country', [
            ['DE', [4]],
            ['JP', [1, 3, 6]],
            ['US', [2, 5, 8]],
        ], 3600);

        // Store index entries for 'price'
        $this->store->putIndex('products', 'v1', 'price', [
            [100, [3, 7]],
            [200, [1, 12]],
            [500, [6, 9, 15]],
            [700, [8, 14]],
            [1000, [4, 11]],
        ], 3600);
    }

    /**
     * Build a resolver with the default indexed columns (country, price) and no composites.
     */
    /**
     * @param  array<string, true>|null  $indexedColumns
     * @param  list<string>  $compositeNames
     */
    private function resolver(?array $indexedColumns = null, array $compositeNames = []): IndexResolver
    {
        return new IndexResolver(
            $this->store,
            'products',
            'v1',
            $indexedColumns ?? $this->defaultIndexedColumns,
            $compositeNames,
        );
    }

    // =========================================================================
    // Equal (=)
    // =========================================================================

    public function test_resolve_equal(): void
    {
        // Given index for country with JP => [1, 3, 6]
        $where = ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'];
        $result = $this->resolver()->resolveForWhere($where);

        // Then IDs [1, 3, 6] should be returned
        $this->assertNotNull($result, 'Index should resolve for equal condition');
        sort($result);
        $this->assertSame([1, 3, 6], $result, 'Equal should return IDs matching the value');
    }

    public function test_resolve_equal_no_match(): void
    {
        $where = ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'FR', 'boolean' => 'and'];
        $result = $this->resolver()->resolveForWhere($where);

        $this->assertNotNull($result, 'Index should resolve even for non-matching value');
        $this->assertSame([], $result, 'Equal with no match should return empty array');
    }

    // =========================================================================
    // Greater than (>, >=)
    // =========================================================================

    public function test_resolve_greater_than(): void
    {
        $where = ['type' => 'basic', 'column' => 'price', 'operator' => '>', 'value' => 500, 'boolean' => 'and'];
        $result = $this->resolver()->resolveForWhere($where);

        $this->assertNotNull($result, 'Index should resolve for > condition');
        sort($result);
        $this->assertSame([4, 8, 11, 14], $result, 'Greater than should return IDs for values above threshold');
    }

    public function test_resolve_greater_than_or_equal(): void
    {
        $where = ['type' => 'basic', 'column' => 'price', 'operator' => '>=', 'value' => 500, 'boolean' => 'and'];
        $result = $this->resolver()->resolveForWhere($where);

        $this->assertNotNull($result, 'Index should resolve for >= condition');
        sort($result);
        $this->assertSame([4, 6, 8, 9, 11, 14, 15], $result, 'Greater than or equal should include the boundary value');
    }

    // =========================================================================
    // Less than (<, <=)
    // =========================================================================

    public function test_resolve_less_than(): void
    {
        $where = ['type' => 'basic', 'column' => 'price', 'operator' => '<', 'value' => 500, 'boolean' => 'and'];
        $result = $this->resolver()->resolveForWhere($where);

        $this->assertNotNull($result, 'Index should resolve for < condition');
        sort($result);
        $this->assertSame([1, 3, 7, 12], $result, 'Less than should return IDs for values below threshold');
    }

    public function test_resolve_less_than_or_equal(): void
    {
        $where = ['type' => 'basic', 'column' => 'price', 'operator' => '<=', 'value' => 500, 'boolean' => 'and'];
        $result = $this->resolver()->resolveForWhere($where);

        $this->assertNotNull($result, 'Index should resolve for <= condition');
        sort($result);
        $this->assertSame([1, 3, 6, 7, 9, 12, 15], $result, 'Less than or equal should include the boundary value');
    }

    // =========================================================================
    // Between
    // =========================================================================

    public function test_resolve_between(): void
    {
        $where = ['type' => 'between', 'column' => 'price', 'values' => [200, 700], 'not' => false, 'boolean' => 'and'];
        $result = $this->resolver()->resolveForWhere($where);

        $this->assertNotNull($result, 'Index should resolve for between condition');
        sort($result);
        $this->assertSame([1, 6, 8, 9, 12, 14, 15], $result, 'Between should return IDs for values within inclusive range');
    }

    // =========================================================================
    // Non-indexed column returns null
    // =========================================================================

    public function test_resolve_non_indexed_column_returns_null(): void
    {
        $where = ['type' => 'basic', 'column' => 'name', 'operator' => '=', 'value' => 'Alice', 'boolean' => 'and'];
        $result = $this->resolver()->resolveForWhere($where);

        $this->assertNull($result, 'Non-indexed column should return null to indicate full scan needed');
    }

    // =========================================================================
    // Unsupported operator returns null
    // =========================================================================

    public function test_resolve_unsupported_operator_returns_null(): void
    {
        // 'like' operator is not index-resolvable
        $where = ['type' => 'basic', 'column' => 'country', 'operator' => 'like', 'value' => 'J%', 'boolean' => 'and'];
        $result = $this->resolver()->resolveForWhere($where);

        $this->assertNull($result, 'Unsupported operator should return null to indicate full scan needed');
    }

    // =========================================================================
    // Non-basic where type returns null (filter, nested, etc.)
    // =========================================================================

    public function test_resolve_filter_type_returns_null(): void
    {
        $where = ['type' => 'filter', 'callback' => fn ($r) => true, 'boolean' => 'and'];
        $result = $this->resolver()->resolveForWhere($where);

        $this->assertNull($result, 'Filter type should return null — not index-resolvable');
    }

    // =========================================================================
    // Index key missing from store → IndexInconsistencyException
    // =========================================================================

    public function test_resolve_throws_when_index_declared_in_loader_but_missing_from_store(): void
    {
        // Given: resolver declares 'status' as indexed, but the APCu key does not exist
        $resolver = new IndexResolver(
            $this->store,
            'products',
            'v1',
            ['status' => true],
            [],
        );

        $where = ['type' => 'basic', 'column' => 'status', 'operator' => '=', 'value' => 'active', 'boolean' => 'and'];

        // Then: IndexInconsistencyException is thrown (APCu eviction detected)
        $this->expectException(IndexInconsistencyException::class);
        $resolver->resolveForWhere($where);
    }

    // =========================================================================
    // Intersection (AND of multiple indexed conditions)
    // =========================================================================

    public function test_intersect_combines_multiple_results(): void
    {
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'price', 'operator' => '>=', 'value' => 200, 'boolean' => 'and'],
        ];

        // country=JP → [1, 3, 6], price>=200 → [1, 6, 8, 9, 12, 14, 15, 4, 11]
        // intersection → [1, 6]
        $result = $this->resolver()->resolveIds($wheres);

        $this->assertNotNull($result, 'Should resolve IDs when all conditions are indexed');
        sort($result);
        $this->assertSame([1, 6], $result, 'AND intersection should return IDs present in both index results');
    }

    public function test_resolve_ids_uses_partial_index_when_some_and_conditions_not_indexed(): void
    {
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'name', 'operator' => '=', 'value' => 'Alice', 'boolean' => 'and'],
        ];

        // name is not indexed → skipped (WhereEvaluator handles it)
        // country is indexed → narrows to JP candidates
        $result = $this->resolver()->resolveIds($wheres);

        $this->assertNotNull($result, 'Should use country index even though name has no index');
        sort($result);
        $this->assertSame([1, 3, 6], $result, 'Should return candidates narrowed by the indexed condition');
    }

    public function test_resolve_ids_returns_null_when_no_and_condition_is_indexed(): void
    {
        $wheres = [
            ['type' => 'basic', 'column' => 'name', 'operator' => '=', 'value' => 'Alice', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'email', 'operator' => '=', 'value' => 'a@example.com', 'boolean' => 'and'],
        ];

        // name and email are both non-indexed → no candidates to narrow → full scan
        $result = $this->resolver()->resolveIds($wheres);

        $this->assertNull($result, 'Should return null when no AND condition can use an index');
    }

    public function test_resolve_ids_returns_null_when_or_condition_is_not_indexed(): void
    {
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'name', 'operator' => '=', 'value' => 'Alice', 'boolean' => 'or'],
        ];

        // name is OR and not indexed → records matching only name=Alice would be missed
        $result = $this->resolver()->resolveIds($wheres);

        $this->assertNull($result, 'Should return null when an OR branch cannot be index-resolved');
    }

    // =========================================================================
    // IN condition
    // =========================================================================

    public function test_resolve_in_condition(): void
    {
        $where = [
            'type' => 'in',
            'column' => 'country',
            'values' => ['JP', 'DE'],
            'not' => false,
            'boolean' => 'and',
        ];

        $result = $this->resolver()->resolveForWhere($where);

        $this->assertNotNull($result, 'Index should resolve for IN condition');
        sort($result);
        $this->assertSame([1, 3, 4, 6], $result, 'IN should return union of IDs for all specified values');
    }

    // =========================================================================
    // OR union
    // =========================================================================

    public function test_resolve_or_union(): void
    {
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'DE', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'or'],
        ];

        // DE => [4], JP => [1, 3, 6], union => [1, 3, 4, 6]
        $result = $this->resolver()->resolveIds($wheres);

        $this->assertNotNull($result, 'OR conditions should resolve via union when all are indexed');
        sort($result);
        $this->assertSame([1, 3, 4, 6], $result, 'OR should return union of index results');
    }

    public function test_resolve_and_then_or(): void
    {
        // country=JP AND price>=500 OR country=DE
        // (JP ∩ >=500) ∪ DE = {6} ∪ {4} = {4, 6}
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'price', 'operator' => '>=', 'value' => 500, 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'DE', 'boolean' => 'or'],
        ];

        $result = $this->resolver()->resolveIds($wheres);

        $this->assertNotNull($result, 'Mixed AND/OR should resolve when all conditions are indexed');
        sort($result);
        $this->assertSame([4, 6], $result, 'Should intersect AND conditions, then union OR condition');
    }

    public function test_resolve_or_falls_back_when_not_indexed(): void
    {
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'name', 'operator' => '=', 'value' => 'Alice', 'boolean' => 'or'],
        ];

        $result = $this->resolver()->resolveIds($wheres);

        $this->assertNull($result, 'Should return null when any OR condition is not index-resolvable');
    }

    // =========================================================================
    // Composite index
    // =========================================================================

    public function test_resolve_composite_index_for_and_equality(): void
    {
        // Given a composite index country|category
        $this->store->putCompositeIndex('products', 'v1', 'country|category', [
            'JP|A' => [1, 4],
            'JP|B' => [3],
            'US|B' => [2],
        ], 3600);
        $this->store->putIndex('products', 'v1', 'category', [
            ['A', [1, 4]],
            ['B', [2, 3]],
        ], 3600);

        $resolver = $this->resolver(
            ['country' => true, 'category' => true],
            ['country|category'],
        );

        // When resolving where('country', 'JP')->where('category', 'A')
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'category', 'operator' => '=', 'value' => 'A', 'boolean' => 'and'],
        ];

        $result = $resolver->resolveIds($wheres);

        // Then IDs [1, 4] should be returned via composite lookup
        $this->assertNotNull($result, 'Composite index should resolve AND equality conditions');
        sort($result);
        $this->assertSame([1, 4], $result, 'Composite index should return IDs for combined key');
    }

    public function test_resolve_composite_index_returns_empty_for_no_match(): void
    {
        $this->store->putCompositeIndex('products', 'v1', 'country|category', [
            'JP|A' => [1],
        ], 3600);

        $resolver = $this->resolver(
            ['country' => true, 'category' => true],
            ['country|category'],
        );

        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'FR', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'category', 'operator' => '=', 'value' => 'Z', 'boolean' => 'and'],
        ];

        $result = $resolver->resolveIds($wheres);

        $this->assertNotNull($result, 'Composite index should resolve even for non-matching key');
        $this->assertSame([], $result, 'Non-matching composite key should return empty array');
    }

    public function test_resolve_composite_skipped_for_non_equality(): void
    {
        // Given a composite index and individual indexes stored properly
        $this->store->putCompositeIndex('products', 'v1', 'country|category', [
            'JP|A' => [1],
        ], 3600);
        $this->store->putIndex('products', 'v1', 'category', [
            ['A', [1, 2]],
            ['B', [3, 4]],
        ], 3600);

        $resolver = $this->resolver(
            ['country' => true, 'category' => true],
            ['country|category'],
        );

        // When one condition uses '>' instead of '=' — composite index is bypassed
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'category', 'operator' => '>', 'value' => 'A', 'boolean' => 'and'],
        ];

        // Then falls back to per-column index resolution:
        // country=JP → [1,3,6], category>A → [3,4], AND intersection → [3]
        $result = $resolver->resolveIds($wheres);

        $this->assertNotNull($result, 'Should use per-column indexes when composite is skipped');
        sort($result);
        $this->assertSame([3], $result, 'Should return candidates from AND intersection of country and category indexes');
    }

    public function test_resolve_composite_skipped_for_or_boolean(): void
    {
        $this->store->putCompositeIndex('products', 'v1', 'country|category', [
            'JP|A' => [1],
        ], 3600);
        $this->store->putIndex('products', 'v1', 'category', [
            ['A', [1, 2]],
            ['B', [3, 4]],
        ], 3600);

        $resolver = $this->resolver(
            ['country' => true, 'category' => true],
            ['country|category'],
        );

        // OR conditions should not use composite index — falls back to per-column
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'category', 'operator' => '=', 'value' => 'A', 'boolean' => 'or'],
        ];

        // country=JP (AND) → {1,3,6}, category=A (OR) → {1,2} → union {1,2,3,6}
        $result = $resolver->resolveIds($wheres);

        $this->assertNotNull($result, 'Per-column OR resolution should return a union of IDs');
        sort($result);
        $this->assertSame([1, 2, 3, 6], $result, 'OR should union country=JP and category=A candidates');
    }

    public function test_resolve_composite_skipped_when_columns_dont_match(): void
    {
        $this->store->putCompositeIndex('products', 'v1', 'country|category', [
            'JP|A' => [1],
        ], 3600);

        $resolver = $this->resolver(
            ['country' => true, 'price' => true],
            ['country|category'],
        );

        // Conditions on country + price, but composite is country|category
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'price', 'operator' => '=', 'value' => 100, 'boolean' => 'and'],
        ];

        $result = $resolver->resolveIds($wheres);

        // Falls back to per-column intersection
        $this->assertNotNull($result, 'Should fall back to per-column when composite columns do not match');
    }

    // =========================================================================
    // Composite index — whereIn acceleration
    // =========================================================================

    public function test_composite_index_used_for_where_in_plus_equality(): void
    {
        // Given composite index country|category
        $this->store->putCompositeIndex('products', 'v1', 'country|category', [
            'JP|A' => [1, 4],
            'US|A' => [2],
            'JP|B' => [3],
            'DE|A' => [5],
        ], 3600);

        $resolver = $this->resolver(
            ['country' => true],
            ['country|category'],
        );

        // whereIn('country', ['JP','US']) + where('category', 'A')
        $wheres = [
            ['type' => 'in', 'column' => 'country', 'values' => ['JP', 'US'], 'not' => false, 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'category', 'operator' => '=', 'value' => 'A', 'boolean' => 'and'],
        ];

        $result = $resolver->resolveIds($wheres);

        $this->assertNotNull($result, 'Composite index should resolve whereIn + equality');
        sort($result);
        $this->assertSame([1, 2, 4], $result, 'Should return JP|A + US|A union');
    }

    public function test_composite_index_used_for_two_where_in_conditions(): void
    {
        // Given composite index country|category
        $this->store->putCompositeIndex('products', 'v1', 'country|category', [
            'JP|A' => [1],
            'JP|B' => [2],
            'US|A' => [3],
            'US|B' => [4],
            'DE|A' => [5],
        ], 3600);

        $resolver = $this->resolver(
            ['country' => true],
            ['country|category'],
        );

        // Both columns use IN — cartesian product: JP|A, JP|B, US|A, US|B
        $wheres = [
            ['type' => 'in', 'column' => 'country', 'values' => ['JP', 'US'], 'not' => false, 'boolean' => 'and'],
            ['type' => 'in', 'column' => 'category', 'values' => ['A', 'B'], 'not' => false, 'boolean' => 'and'],
        ];

        $result = $resolver->resolveIds($wheres);

        $this->assertNotNull($result, 'Composite index should resolve two whereIn conditions');
        sort($result);
        $this->assertSame([1, 2, 3, 4], $result, 'Cartesian product should cover JP|A + JP|B + US|A + US|B');
    }

    public function test_composite_not_used_for_not_in(): void
    {
        $this->store->putCompositeIndex('products', 'v1', 'country|category', [
            'JP|A' => [1],
        ], 3600);

        $resolver = $this->resolver(
            ['country' => true],
            ['country|category'],
        );

        // NOT IN → composite cannot be used
        $wheres = [
            ['type' => 'in', 'column' => 'country', 'values' => ['JP'], 'not' => true, 'boolean' => 'and'],
            ['type' => 'basic', 'column' => 'category', 'operator' => '=', 'value' => 'A', 'boolean' => 'and'],
        ];

        $result = $resolver->resolveIds($wheres);

        $this->assertNull($result, 'NOT IN should not use composite index — falls back to full scan');
    }

    public function test_resolve_not_in_returns_null(): void
    {
        $where = [
            'type' => 'in',
            'column' => 'country',
            'values' => ['JP'],
            'not' => true,
            'boolean' => 'and',
        ];

        $result = $this->resolver()->resolveForWhere($where);

        $this->assertNull($result, 'NOT IN should return null — not efficiently index-resolvable');
    }
}
