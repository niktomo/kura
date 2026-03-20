<?php

namespace Kura\Tests;

use Kura\CacheProcessor;
use Kura\CacheRepository;
use Kura\ReferenceQueryBuilder;
use Kura\Store\ArrayStore;
use Kura\Tests\Support\InMemoryLoader;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Kura-specific QueryBuilder extensions not present in Laravel's QueryBuilder.
 *
 * Covers:
 *   - orWhereNone / orWhereAll / orWhereAny
 *   - orWhereValueBetween / orWhereValueNotBetween
 *   - whereIn + composite index acceleration (E2E)
 */
class ReferenceQueryBuilderKuraExtensionsTest extends TestCase
{
    private ArrayStore $store;

    /**
     * @var list<array<string, mixed>>
     */
    private array $users = [
        ['id' => 1, 'name' => 'Alice', 'age' => 30, 'country' => 'JP', 'role' => 'admin'],
        ['id' => 2, 'name' => 'Bob',   'age' => 25, 'country' => 'US', 'role' => 'user'],
        ['id' => 3, 'name' => 'Carol', 'age' => 35, 'country' => 'JP', 'role' => 'user'],
        ['id' => 4, 'name' => 'Dave',  'age' => 20, 'country' => 'US', 'role' => 'admin'],
        ['id' => 5, 'name' => 'Eve',   'age' => 28, 'country' => 'UK', 'role' => 'user'],
    ];

    /**
     * Range dataset: each record has its own [low, high] range.
     *
     * whereValueBetween(27, ['low','high']):
     *   id=1: 20<=27<=30 → YES
     *   id=2: 28<=27<=35 → NO  (27 < low)
     *   id=3: 10<=27<=25 → NO  (27 > high)
     *   id=4: 25<=27<=30 → YES
     *   id=5: 27<=27<=27 → YES (boundary)
     *
     * @var list<array<string, mixed>>
     */
    private array $ranges = [
        ['id' => 1, 'label' => 'A', 'low' => 20, 'high' => 30],
        ['id' => 2, 'label' => 'B', 'low' => 28, 'high' => 35],
        ['id' => 3, 'label' => 'C', 'low' => 10, 'high' => 25],
        ['id' => 4, 'label' => 'D', 'low' => 25, 'high' => 30],
        ['id' => 5, 'label' => 'E', 'low' => 27, 'high' => 27],
    ];

    protected function setUp(): void
    {
        $this->store = new ArrayStore;
    }

    /** @param list<array<string, mixed>> $records */
    private function builder(string $table, array $records, ?InMemoryLoader $loader = null): ReferenceQueryBuilder
    {
        $loader ??= new InMemoryLoader($records);
        $repository = new CacheRepository(
            table: $table,
            primaryKey: 'id',
            loader: $loader,
            store: $this->store,
        );

        return new ReferenceQueryBuilder(table: $table, repository: $repository);
    }

    private function users(): ReferenceQueryBuilder
    {
        return $this->builder('users', $this->users);
    }

    private function ranges(): ReferenceQueryBuilder
    {
        return $this->builder('ranges', $this->ranges);
    }

    /** @return list<int|string> */
    private function ids(ReferenceQueryBuilder $builder): array
    {
        return array_column($builder->get(), 'id');
    }

    // =========================================================================
    // orWhereNone — union of records where none of the columns match
    // =========================================================================

    public function test_or_where_none_unions_with_preceding_condition(): void
    {
        // Arrange
        // where('country', 'UK')             → Eve [5]
        // orWhereNone(['name','role'], 'admin') → none of name or role = 'admin'
        //   → excludes Alice(name=Alice,role=admin) Dave(name=Dave,role=admin)
        //   → Bob[2], Carol[3], Eve[5]
        // OR union: [5] + [2,3,5] = [2,3,5]

        // Act
        $ids = $this->ids(
            $this->users()
                ->where('country', 'UK')
                ->orWhereNone(['name', 'role'], '=', 'admin'),
        );

        // Assert
        $this->assertSame(
            [2, 3, 5],
            $ids,
            'orWhereNone should union records where none of the given columns match',
        );
    }

    public function test_or_where_none_with_no_preceding_where(): void
    {
        // Arrange: only orWhereNone — behaves like whereNone
        // None of name or role = 'admin': Bob[2], Carol[3], Eve[5]

        // Act
        $ids = $this->ids(
            $this->users()->orWhereNone(['name', 'role'], '=', 'admin'),
        );

        // Assert
        $this->assertSame(
            [2, 3, 5],
            $ids,
            'orWhereNone alone should behave like whereNone',
        );
    }

    // =========================================================================
    // orWhereAll — union of records where all columns match
    // =========================================================================

    public function test_or_where_all_unions_with_preceding_condition(): void
    {
        // Arrange
        // where('id', 1)     → Alice [1]
        // orWhereAll(['country','role'], '=', 'user') → both country and role = 'user'
        //   → no record has country='user' — returns []
        // OR union: [1] + [] = [1]

        // Act
        $ids = $this->ids(
            $this->users()
                ->where('id', 1)
                ->orWhereAll(['country', 'role'], '=', 'user'),
        );

        // Assert
        $this->assertSame(
            [1],
            $ids,
            'orWhereAll should return union when all-columns-match condition yields empty',
        );
    }

    public function test_or_where_all_matches_when_all_columns_equal_value(): void
    {
        // Arrange: records where both name and role equal a test value — none exist in dataset
        // but we can verify the union with a known matching condition

        // where('country', 'UK') → Eve [5]
        // orWhereAll(['country','role'], '=', 'JP') → both = 'JP' — no record → []
        // Result: [5]

        // Act
        $ids = $this->ids(
            $this->users()
                ->where('country', 'UK')
                ->orWhereAll(['country', 'role'], '=', 'JP'),
        );

        // Assert
        $this->assertSame(
            [5],
            $ids,
            'orWhereAll should union only the records that satisfy all-columns-match',
        );
    }

    // =========================================================================
    // orWhereAny — union of records where at least one column matches
    // =========================================================================

    public function test_or_where_any_unions_additional_records(): void
    {
        // Arrange
        // where('country', 'UK')           → Eve [5]
        // orWhereAny(['name','role'], '=', 'admin') → name='admin' OR role='admin'
        //   → Alice(role=admin)[1], Dave(role=admin)[4]
        // OR union: [5] + [1,4] = [1,4,5]

        // Act
        $ids = $this->ids(
            $this->users()
                ->where('country', 'UK')
                ->orWhereAny(['name', 'role'], '=', 'admin'),
        );

        // Assert
        $this->assertSame(
            [1, 4, 5],
            $ids,
            'orWhereAny should union records where any of the given columns match',
        );
    }

    public function test_or_where_any_with_like_operator(): void
    {
        // where('id', 99)                         → nobody []
        // orWhereAny(['name','role'], 'like', '%ol%') → name LIKE '%ol%': Carol[3]
        // OR union: [] + [3] = [3]

        // Act
        $ids = $this->ids(
            $this->users()
                ->where('id', 99)
                ->orWhereAny(['name', 'role'], 'like', '%ol%'),
        );

        // Assert
        $this->assertSame(
            [3],
            $ids,
            'orWhereAny with like operator should find records where any column matches the pattern',
        );
    }

    // =========================================================================
    // orWhereValueBetween — union of records whose [low,high] range contains scalar
    // =========================================================================

    public function test_or_where_value_between_unions_matching_ranges(): void
    {
        // Arrange
        // whereValueBetween(27, ['low','high']) → id=1[20-30], id=4[25-30], id=5[27-27] → [1,4,5]
        // orWhereValueBetween(33, ['low','high']) → id=2[28-35] → [2]
        // OR union: [1,4,5] + [2] = [1,2,4,5]

        // Act
        $ids = $this->ids(
            $this->ranges()
                ->whereValueBetween(27, ['low', 'high'])
                ->orWhereValueBetween(33, ['low', 'high']),
        );

        // Assert
        $this->assertSame(
            [1, 2, 4, 5],
            $ids,
            'orWhereValueBetween should union records whose range contains the scalar',
        );
    }

    // =========================================================================
    // orWhereValueNotBetween — union of records whose [low,high] range excludes scalar
    // =========================================================================

    public function test_or_where_value_not_between_unions_out_of_range_records(): void
    {
        // whereValueBetween(27, ['low','high'])    → [1,4,5]
        // orWhereValueNotBetween(27, ['low','high']) → NOT (low<=27<=high)
        //   id=2: 28<=27<=35 → NO (27 < low) → qualifies for NOT
        //   id=3: 10<=27<=25 → NO (27 > high) → qualifies for NOT
        //   → [2,3]
        // OR union: [1,4,5] + [2,3] = all 5

        // Act
        $ids = $this->ids(
            $this->ranges()
                ->whereValueBetween(27, ['low', 'high'])
                ->orWhereValueNotBetween(27, ['low', 'high']),
        );

        // Assert
        $this->assertSame(
            [1, 2, 3, 4, 5],
            $ids,
            'orWhereValueBetween + orWhereValueNotBetween should together cover all records',
        );
    }

    // =========================================================================
    // whereIn + composite index acceleration (E2E)
    // =========================================================================

    public function test_where_in_with_and_equality_uses_composite_index(): void
    {
        // Arrange: dataset with composite index on country|role
        /** @var list<array<string, mixed>> $records */
        $records = [
            ['id' => 1, 'country' => 'JP', 'role' => 'admin'],
            ['id' => 2, 'country' => 'US', 'role' => 'user'],
            ['id' => 3, 'country' => 'JP', 'role' => 'user'],
            ['id' => 4, 'country' => 'US', 'role' => 'admin'],
            ['id' => 5, 'country' => 'UK', 'role' => 'admin'],
        ];
        $loader = new InMemoryLoader(
            $records,
            indexes: [['columns' => ['country', 'role'], 'unique' => false]],
        );
        $repository = new CacheRepository(
            table: 'staff',
            primaryKey: 'id',
            loader: $loader,
            store: $this->store,
        );
        $repository->rebuild();
        $builder = new ReferenceQueryBuilder(
            table: 'staff',
            repository: $repository,
            processor: new CacheProcessor($repository, $this->store),
        );

        // Act: whereIn('country', ['JP','US']) + where('role', 'admin')
        // Composite keys: JP|admin[1], US|admin[4] → candidates [1,4]
        $results = $builder->whereIn('country', ['JP', 'US'])->where('role', 'admin')->get();
        $ids = array_column($results, 'id');
        sort($ids);

        // Assert
        $this->assertSame(
            [1, 4],
            $ids,
            'whereIn + AND equality should use composite index to resolve candidates directly',
        );
    }

    public function test_where_in_composite_returns_correct_records_without_extra(): void
    {
        // Arrange: ensure UK|admin (id=5) is NOT included
        /** @var list<array<string, mixed>> $records */
        $records = [
            ['id' => 1, 'country' => 'JP', 'role' => 'admin'],
            ['id' => 2, 'country' => 'US', 'role' => 'user'],
            ['id' => 3, 'country' => 'JP', 'role' => 'user'],
            ['id' => 4, 'country' => 'US', 'role' => 'admin'],
            ['id' => 5, 'country' => 'UK', 'role' => 'admin'],
        ];
        $loader = new InMemoryLoader(
            $records,
            indexes: [['columns' => ['country', 'role'], 'unique' => false]],
        );
        $repository = new CacheRepository(
            table: 'staff2',
            primaryKey: 'id',
            loader: $loader,
            store: $this->store,
        );
        $repository->rebuild();
        $builder = new ReferenceQueryBuilder(
            table: 'staff2',
            repository: $repository,
            processor: new CacheProcessor($repository, $this->store),
        );

        // Act
        $results = $builder->whereIn('country', ['JP', 'US'])->where('role', 'admin')->get();
        $ids = array_column($results, 'id');
        sort($ids);

        // Assert: UK|admin (id=5) must not be in results
        $this->assertNotContains(
            5,
            $ids,
            'UK should not appear in results when whereIn is JP or US',
        );
        $this->assertSame(
            [1, 4],
            $ids,
            'Only JP|admin and US|admin should be returned',
        );
    }
}
