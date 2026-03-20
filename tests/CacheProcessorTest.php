<?php

namespace Kura\Tests;

use Kura\CacheProcessor;
use Kura\CacheRepository;
use Kura\Exceptions\CacheInconsistencyException;
use Kura\Store\ArrayStore;
use Kura\Tests\Support\InMemoryLoader;
use PHPUnit\Framework\TestCase;

/**
 * Feature: CacheProcessor orchestrates query execution
 *
 * Given query state (wheres, orders, limit, offset),
 * When executing via cursor(),
 * Then the processor should:
 *   - Check lock → Loader fallback
 *   - Check ids → Loader fallback + rebuild
 *   - Derive index structure from Loader → index resolution or full scan
 *   - Detect record inconsistency → CacheInconsistencyException
 */
class CacheProcessorTest extends TestCase
{
    private ArrayStore $store;

    /** @var list<array<string, mixed>> */
    private array $records;

    protected function setUp(): void
    {
        $this->store = new ArrayStore;
        $this->records = [
            ['id' => 1, 'name' => 'Alice', 'country' => 'JP', 'price' => 500],
            ['id' => 2, 'name' => 'Bob', 'country' => 'US', 'price' => 200],
            ['id' => 3, 'name' => 'Charlie', 'country' => 'JP', 'price' => 100],
        ];
    }

    private function makeProcessor(
        ?InMemoryLoader $loader = null,
        string $primaryKey = 'id',
    ): CacheProcessor {
        $loader ??= new InMemoryLoader(
            $this->records,
            columns: ['id' => 'int', 'name' => 'string', 'country' => 'string', 'price' => 'int'],
            indexes: [['columns' => ['country'], 'unique' => false]],
        );

        $repository = new CacheRepository(
            table: 'products',
            primaryKey: $primaryKey,
            store: $this->store,
            loader: $loader,
        );

        return new CacheProcessor($repository, $this->store);
    }

    private function buildAndPopulateCache(): void
    {
        $loader = new InMemoryLoader(
            $this->records,
            columns: ['id' => 'int', 'name' => 'string', 'country' => 'string', 'price' => 'int'],
            indexes: [['columns' => ['country'], 'unique' => false]],
        );

        $repository = new CacheRepository(
            table: 'products',
            primaryKey: 'id',
            store: $this->store,
            loader: $loader,
        );

        $repository->rebuild();
    }

    // =========================================================================
    // Normal operation (cache populated)
    // =========================================================================

    public function test_cursor_returns_all_records_when_no_conditions(): void
    {
        // Given a fully populated cache
        $this->buildAndPopulateCache();
        $processor = $this->makeProcessor();

        // When executing with no where conditions
        $results = iterator_to_array($processor->cursor(
            wheres: [],
            orders: [],
            limit: null,
            offset: null,
            randomOrder: false,
        ), preserve_keys: false);

        // Then all records should be returned
        $this->assertCount(3, $results, 'Should return all 3 records from cache');
    }

    public function test_cursor_applies_where_filter(): void
    {
        $this->buildAndPopulateCache();
        $processor = $this->makeProcessor();

        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
        ];

        $results = iterator_to_array($processor->cursor(
            wheres: $wheres,
            orders: [],
            limit: null,
            offset: null,
            randomOrder: false,
        ), preserve_keys: false);

        $this->assertCount(2, $results, 'Should return 2 records matching country=JP');
        $ids = array_column($results, 'id');
        sort($ids);
        $this->assertSame([1, 3], $ids, 'Should return Alice and Charlie (both JP)');
    }

    // =========================================================================
    // Index resolution
    // =========================================================================

    public function test_cursor_uses_index_when_available(): void
    {
        $this->buildAndPopulateCache();
        $processor = $this->makeProcessor();

        // country is indexed, so index should be used to narrow candidates
        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'US', 'boolean' => 'and'],
        ];

        $results = iterator_to_array($processor->cursor(
            wheres: $wheres,
            orders: [],
            limit: null,
            offset: null,
            randomOrder: false,
        ), preserve_keys: false);

        $this->assertCount(1, $results, 'Index should narrow candidates to US records only');
        $this->assertSame('Bob', $results[0]['name'], 'Should return Bob (country=US)');
    }

    // =========================================================================
    // Lock → Loader fallback
    // =========================================================================

    public function test_cursor_falls_back_to_loader_when_locked(): void
    {
        // Given the table is locked (rebuild in progress)
        $this->store->acquireLock('products', 60);
        $processor = $this->makeProcessor();

        // When executing a query
        $results = iterator_to_array($processor->cursor(
            wheres: [],
            orders: [],
            limit: null,
            offset: null,
            randomOrder: false,
        ), preserve_keys: false);

        // Then results should come from Loader directly
        $this->assertCount(3, $results, 'Should return all records from Loader when locked');
    }

    public function test_cursor_applies_filter_on_loader_fallback(): void
    {
        $this->store->acquireLock('products', 60);
        $processor = $this->makeProcessor();

        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
        ];

        $results = iterator_to_array($processor->cursor(
            wheres: $wheres,
            orders: [],
            limit: null,
            offset: null,
            randomOrder: false,
        ), preserve_keys: false);

        $this->assertCount(2, $results, 'Should filter Loader results with where conditions');
    }

    // =========================================================================
    // ids missing → Loader fallback + rebuild
    // =========================================================================

    public function test_cursor_falls_back_to_loader_when_ids_missing(): void
    {
        // Given no cache at all (ids missing)
        $processor = $this->makeProcessor();

        $results = iterator_to_array($processor->cursor(
            wheres: [],
            orders: [],
            limit: null,
            offset: null,
            randomOrder: false,
        ), preserve_keys: false);

        // Then results should come from Loader
        $this->assertCount(3, $results, 'Should return records from Loader when ids are missing');

        // And cache should be rebuilt
        $ids = $this->store->getIds('products', 'v1');
        $this->assertIsArray($ids, 'Cache should be rebuilt after ids-missing fallback');
    }

    // =========================================================================
    // Record inconsistency → CacheInconsistencyException
    // =========================================================================

    public function test_cursor_throws_on_record_inconsistency(): void
    {
        // Given ids claim record 1 exists, but record 1 is missing from store
        $this->store->putIds('products', 'v1', [1, 2], 3600);
        // Only store record 2, not record 1
        $this->store->putRecord('products', 'v1', 2, $this->records[1], 3600);

        $processor = $this->makeProcessor();

        $this->expectException(CacheInconsistencyException::class);

        // Consume the generator to trigger the exception
        iterator_to_array($processor->cursor(
            wheres: [],
            orders: [],
            limit: null,
            offset: null,
            randomOrder: false,
        ), preserve_keys: false);
    }

    // =========================================================================
    // select() catches CacheInconsistencyException
    // =========================================================================

    public function test_select_catches_inconsistency_and_falls_back_to_loader(): void
    {
        // Given record inconsistency
        $this->store->putIds('products', 'v1', [1, 2], 3600);
        $this->store->putRecord('products', 'v1', 2, $this->records[1], 3600);

        $processor = $this->makeProcessor();

        // select() should catch the exception and fall back to Loader
        $results = $processor->select(
            wheres: [],
            orders: [],
            limit: null,
            offset: null,
            randomOrder: false,
        );

        $this->assertCount(3, $results, 'select() should fall back to Loader on record inconsistency');
    }

    // =========================================================================
    // Orders and pagination work through processor
    // =========================================================================

    public function test_cursor_applies_order_and_limit(): void
    {
        $this->buildAndPopulateCache();
        $processor = $this->makeProcessor();

        $results = iterator_to_array($processor->cursor(
            wheres: [],
            orders: [['column' => 'price', 'direction' => 'asc']],
            limit: 2,
            offset: null,
            randomOrder: false,
        ), preserve_keys: false);

        $this->assertCount(2, $results, 'Should return only 2 records with limit=2');
        $this->assertSame(100, $results[0]['price'], 'First record should have lowest price');
        $this->assertSame(200, $results[1]['price'], 'Second record should have second lowest price');
    }

    // =========================================================================
    // Index walk — orderBy on indexed column
    // =========================================================================

    private function buildCacheWithPriceIndex(): CacheProcessor
    {
        $loader = new InMemoryLoader(
            $this->records,
            columns: ['id' => 'int', 'name' => 'string', 'country' => 'string', 'price' => 'int'],
            indexes: [
                ['columns' => ['country'], 'unique' => false],
                ['columns' => ['price'], 'unique' => false],
            ],
        );

        $repository = new CacheRepository(
            table: 'products',
            primaryKey: 'id',
            store: $this->store,
            loader: $loader,
        );

        $repository->rebuild();

        return new CacheProcessor($repository, $this->store);
    }

    public function test_orderby_on_indexed_column_returns_records_in_ascending_order(): void
    {
        // Arrange
        $processor = $this->buildCacheWithPriceIndex();

        // Act
        $results = iterator_to_array($processor->cursor(
            wheres: [],
            orders: [['column' => 'price', 'direction' => 'asc']],
            limit: null,
            offset: null,
            randomOrder: false,
        ), preserve_keys: false);

        // Assert
        $this->assertCount(3, $results, 'Should return all 3 records');
        $this->assertSame([100, 200, 500], array_column($results, 'price'), 'Should be sorted ascending by price via index walk');
    }

    public function test_orderby_on_indexed_column_returns_records_in_descending_order(): void
    {
        // Arrange
        $processor = $this->buildCacheWithPriceIndex();

        // Act
        $results = iterator_to_array($processor->cursor(
            wheres: [],
            orders: [['column' => 'price', 'direction' => 'desc']],
            limit: null,
            offset: null,
            randomOrder: false,
        ), preserve_keys: false);

        // Assert
        $this->assertCount(3, $results, 'Should return all 3 records');
        $this->assertSame([500, 200, 100], array_column($results, 'price'), 'Should be sorted descending by price via index walk');
    }

    public function test_orderby_index_walk_respects_limit(): void
    {
        // Arrange
        $processor = $this->buildCacheWithPriceIndex();

        // Act — only 2 cheapest records
        $results = iterator_to_array($processor->cursor(
            wheres: [],
            orders: [['column' => 'price', 'direction' => 'asc']],
            limit: 2,
            offset: null,
            randomOrder: false,
        ), preserve_keys: false);

        // Assert
        $this->assertCount(2, $results, 'limit=2 should stop after 2 records');
        $this->assertSame([100, 200], array_column($results, 'price'), 'Should return the 2 cheapest records via index walk');
    }

    public function test_orderby_index_walk_respects_offset(): void
    {
        // Arrange
        $processor = $this->buildCacheWithPriceIndex();

        // Act — skip cheapest, return the remaining two
        $results = iterator_to_array($processor->cursor(
            wheres: [],
            orders: [['column' => 'price', 'direction' => 'asc']],
            limit: null,
            offset: 1,
            randomOrder: false,
        ), preserve_keys: false);

        // Assert
        $this->assertCount(2, $results, 'offset=1 should skip first record, returning 2');
        $this->assertSame([200, 500], array_column($results, 'price'), 'Should skip 100 and return [200, 500]');
    }

    public function test_orderby_index_walk_combines_with_where_on_indexed_column(): void
    {
        // Arrange — only JP records (Charlie=100, Alice=500) ordered by price asc
        $processor = $this->buildCacheWithPriceIndex();

        $wheres = [
            ['type' => 'basic', 'column' => 'country', 'operator' => '=', 'value' => 'JP', 'boolean' => 'and'],
        ];

        // Act
        $results = iterator_to_array($processor->cursor(
            wheres: $wheres,
            orders: [['column' => 'price', 'direction' => 'asc']],
            limit: null,
            offset: null,
            randomOrder: false,
        ), preserve_keys: false);

        // Assert
        $this->assertCount(2, $results, 'Should return 2 JP records');
        $this->assertSame([100, 500], array_column($results, 'price'), 'JP records should be price-sorted via index walk');
        $this->assertSame(['Charlie', 'Alice'], array_column($results, 'name'), 'Order: Charlie (100) before Alice (500)');
    }

    public function test_orderby_on_non_indexed_column_does_not_use_index_walk(): void
    {
        // Arrange — price is NOT indexed in this setup (only country is)
        $this->buildAndPopulateCache();
        $processor = $this->makeProcessor();

        // Act — orderBy price: falls back to RecordCursor sort (no index walk)
        $results = iterator_to_array($processor->cursor(
            wheres: [],
            orders: [['column' => 'price', 'direction' => 'asc']],
            limit: null,
            offset: null,
            randomOrder: false,
        ), preserve_keys: false);

        // Assert — RecordCursor still sorts correctly
        $this->assertCount(3, $results, 'Should return all 3 records even without index walk');
        $this->assertSame([100, 200, 500], array_column($results, 'price'), 'Records should still be sorted by price via RecordCursor fallback');
    }
}
