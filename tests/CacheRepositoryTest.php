<?php

namespace Kura\Tests;

use Kura\CacheRepository;
use Kura\Loader\LoaderInterface;
use Kura\Store\ArrayStore;
use Kura\Tests\Support\InMemoryLoader;
use PHPUnit\Framework\TestCase;

class CacheRepositoryTest extends TestCase
{
    private ArrayStore $store;

    protected function setUp(): void
    {
        $this->store = new ArrayStore;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function makeRepository(
        array $records,
        string $primaryKey = 'id',
        string $version = 'v1',
    ): CacheRepository {
        return new CacheRepository(
            table: 'users',
            primaryKey: $primaryKey,
            store: $this->store,
            loader: new InMemoryLoader($records, version: $version),
        );
    }

    // -------------------------------------------------------------------------
    // table() / primaryKey() / version()
    // -------------------------------------------------------------------------

    public function test_table_returns_table_name(): void
    {
        $repo = $this->makeRepository([]);

        $this->assertSame(
            'users',
            $repo->table(),
            'table() should return the table name passed to constructor',
        );
    }

    public function test_primary_key_returns_configured_key(): void
    {
        $repo = $this->makeRepository([], primaryKey: 'user_id');

        $this->assertSame(
            'user_id',
            $repo->primaryKey(),
            'primaryKey() should return the primary key passed to constructor',
        );
    }

    public function test_version_returns_loader_version(): void
    {
        $repo = $this->makeRepository([], version: 'v2.0');

        $this->assertSame(
            'v2.0',
            $repo->version(),
            'version() should return the version from the loader',
        );
    }

    // -------------------------------------------------------------------------
    // ids()
    // -------------------------------------------------------------------------

    public function test_ids_returns_false_when_not_cached(): void
    {
        $repo = $this->makeRepository([
            ['id' => 1, 'name' => 'Alice'],
        ]);

        $this->assertFalse(
            $repo->pks(),
            'ids() should return false when ids are not yet cached (no auto-reload)',
        );
    }

    public function test_ids_returns_cached_ids_list(): void
    {
        $this->store->putPks('users', 'v1', [1, 2, 3], 3600);

        $repo = $this->makeRepository([]);

        $this->assertSame(
            [1, 2, 3],
            $repo->pks(),
            'ids() should return the list stored in the cache',
        );
    }

    // -------------------------------------------------------------------------
    // find()
    // -------------------------------------------------------------------------

    public function test_find_returns_stored_record(): void
    {
        $record = ['id' => 1, 'name' => 'Alice'];
        $this->store->putRecord('users', 'v1', 1, $record, 3600);

        $repo = $this->makeRepository([]);

        $this->assertSame(
            $record,
            $repo->find(1),
            'find() should return the record when it is in the cache',
        );
    }

    public function test_find_returns_null_for_unknown_id(): void
    {
        $repo = $this->makeRepository([]);

        $this->assertNull(
            $repo->find(999),
            'find() should return null when the record is not in the cache',
        );
    }

    // -------------------------------------------------------------------------
    // isLocked()
    // -------------------------------------------------------------------------

    public function test_is_locked_returns_false_when_not_locked(): void
    {
        $repo = $this->makeRepository([]);

        $this->assertFalse(
            $repo->isLocked(),
            'isLocked() should return false when no lock is held',
        );
    }

    public function test_is_locked_returns_true_when_locked(): void
    {
        $this->store->acquireLock('users', 30);
        $repo = $this->makeRepository([]);

        $this->assertTrue(
            $repo->isLocked(),
            'isLocked() should return true when a lock has been acquired',
        );
    }

    // -------------------------------------------------------------------------
    // rebuild() / reload()
    // -------------------------------------------------------------------------

    public function test_rebuild_stores_all_records(): void
    {
        $repo = $this->makeRepository([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);

        $repo->rebuild();

        $this->assertSame(
            ['id' => 1, 'name' => 'Alice'],
            $this->store->getRecord('users', 'v1', 1),
            'rebuild should store all records in the cache',
        );
        $this->assertSame(
            ['id' => 2, 'name' => 'Bob'],
            $this->store->getRecord('users', 'v1', 2),
            'rebuild should store all records in the cache',
        );
    }

    public function test_rebuild_stores_ids_as_hashmap(): void
    {
        $repo = $this->makeRepository([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);

        $repo->rebuild();

        $this->assertSame(
            [1, 2],
            $this->store->getPks('users', 'v1'),
            'rebuild should store ids as a list [id, ...]',
        );
    }

    public function test_rebuild_flushes_stale_data_before_loading(): void
    {
        $this->store->putPks('users', 'v1', [99], 3600);
        $this->store->putRecord('users', 'v1', 99, ['id' => 99, 'name' => 'Stale'], 3600);

        $repo = $this->makeRepository([
            ['id' => 1, 'name' => 'Alice'],
        ]);

        $repo->rebuild();

        $this->assertFalse(
            $this->store->getRecord('users', 'v1', 99),
            'Stale records should be removed after rebuild',
        );
        $this->assertSame(
            [1],
            $this->store->getPks('users', 'v1'),
            'Only current records should remain after rebuild',
        );
    }

    public function test_rebuild_stores_indexes(): void
    {
        $repo = new CacheRepository(
            table: 'products',
            primaryKey: 'id',
            store: $this->store,
            loader: new InMemoryLoader(
                records: [
                    ['id' => 1, 'country' => 'JP', 'price' => 500],
                    ['id' => 2, 'country' => 'US', 'price' => 200],
                    ['id' => 3, 'country' => 'JP', 'price' => 100],
                ],
                columns: ['id' => 'int', 'country' => 'string', 'price' => 'int'],
                indexes: [
                    ['columns' => ['country'], 'unique' => false],
                    ['columns' => ['price'], 'unique' => false],
                ],
            ),
        );

        $repo->rebuild();

        // Verify country index
        $countryIndex = $this->store->getIndex('products', 'v1', 'country');
        $this->assertIsArray($countryIndex, 'rebuild should store country index');
        $this->assertSame(
            [['JP', [1, 3]], ['US', [2]]],
            $countryIndex,
            'Country index should contain sorted entries with grouped IDs',
        );

        // Verify price index
        $priceIndex = $this->store->getIndex('products', 'v1', 'price');
        $this->assertIsArray($priceIndex, 'rebuild should store price index');
        $this->assertSame(
            [[100, [3]], [200, [2]], [500, [1]]],
            $priceIndex,
            'Price index should contain sorted entries with grouped IDs',
        );
    }

    public function test_rebuild_composite_index_auto_creates_single_column_indexes(): void
    {
        $repo = new CacheRepository(
            table: 'products',
            primaryKey: 'id',
            store: $this->store,
            loader: new InMemoryLoader(
                records: [
                    ['id' => 1, 'country' => 'JP', 'category' => 'A'],
                    ['id' => 2, 'country' => 'US', 'category' => 'B'],
                ],
                columns: ['id' => 'int', 'country' => 'string', 'category' => 'string'],
                indexes: [['columns' => ['country', 'category'], 'unique' => false]],
            ),
        );

        $repo->rebuild();

        $countryIndex = $this->store->getIndex('products', 'v1', 'country');
        $this->assertIsArray($countryIndex, 'Composite index should auto-create single column index for country');

        $categoryIndex = $this->store->getIndex('products', 'v1', 'category');
        $this->assertIsArray($categoryIndex, 'Composite index should auto-create single column index for category');
    }

    public function test_rebuild_acquires_and_releases_lock(): void
    {
        $repo = $this->makeRepository([
            ['id' => 1, 'name' => 'Alice'],
        ]);

        // Before rebuild: not locked
        $this->assertFalse(
            $this->store->isLocked('users'),
            'Table should not be locked before rebuild',
        );

        $repo->rebuild();

        // After rebuild: lock released
        $this->assertFalse(
            $this->store->isLocked('users'),
            'Lock should be released after rebuild completes',
        );

        // Data should be present
        $this->assertIsArray(
            $this->store->getPks('users', 'v1'),
            'ids should be stored after rebuild',
        );
    }

    public function test_rebuild_skips_when_already_locked(): void
    {
        $this->store->acquireLock('users', 60);

        $repo = $this->makeRepository([
            ['id' => 1, 'name' => 'Alice'],
        ]);

        $repo->rebuild();

        // Should NOT have rebuilt — ids should still be missing
        $this->assertFalse(
            $this->store->getPks('users', 'v1'),
            'rebuild should skip when lock is already held by another process',
        );
    }

    public function test_rebuild_releases_lock_on_loader_exception(): void
    {
        $failingLoader = new class implements LoaderInterface
        {
            public function load(): \Generator
            {
                throw new \RuntimeException('DB connection failed');
            }

            /** @return array<string, string> */
            public function columns(): array
            {
                return [];
            }

            /** @return list<array{columns: list<string>, unique: bool}> */
            public function indexes(): array
            {
                return [];
            }

            public function primaryKey(): string
            {
                return 'id';
            }

            public function version(): string
            {
                return 'v1';
            }
        };

        $repo = new CacheRepository(
            table: 'users',
            primaryKey: 'id',
            store: $this->store,
            loader: $failingLoader,
        );

        try {
            $repo->rebuild();
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse(
            $this->store->isLocked('users'),
            'Lock should be released even when Loader throws an exception',
        );
    }

    public function test_rebuild_stores_composite_index(): void
    {
        // Arrange
        $repo = new CacheRepository(
            table: 'products',
            primaryKey: 'id',
            store: $this->store,
            loader: new InMemoryLoader(
                records: [
                    ['id' => 1, 'country' => 'JP', 'category' => 'A'],
                    ['id' => 2, 'country' => 'US', 'category' => 'B'],
                    ['id' => 3, 'country' => 'JP', 'category' => 'B'],
                    ['id' => 4, 'country' => 'JP', 'category' => 'A'],
                ],
                columns: ['id' => 'int', 'country' => 'string', 'category' => 'string'],
                indexes: [['columns' => ['country', 'category'], 'unique' => false]],
            ),
        );

        // Act
        $repo->rebuild();

        // Assert — composite index stored
        $compositeIndex = $this->store->getCompositeIndex('products', 'v1', 'country|category');
        $this->assertIsArray($compositeIndex, 'rebuild should store composite index');
        $this->assertSame([1, 4], $compositeIndex['JP|A'], 'Composite index JP|A should contain IDs 1 and 4');
        $this->assertSame([2], $compositeIndex['US|B'], 'Composite index US|B should contain ID 2');
        $this->assertSame([3], $compositeIndex['JP|B'], 'Composite index JP|B should contain ID 3');
    }

    public function test_rebuild_skips_composite_entry_when_column_is_null(): void
    {
        // Arrange
        $repo = new CacheRepository(
            table: 'products',
            primaryKey: 'id',
            store: $this->store,
            loader: new InMemoryLoader(
                records: [
                    ['id' => 1, 'country' => 'JP', 'category' => 'A'],
                    ['id' => 2, 'country' => 'US', 'category' => null],
                ],
                columns: ['id' => 'int', 'country' => 'string', 'category' => 'string'],
                indexes: [['columns' => ['country', 'category'], 'unique' => false]],
            ),
        );

        // Act
        $repo->rebuild();

        // Assert
        $compositeIndex = $this->store->getCompositeIndex('products', 'v1', 'country|category');
        $this->assertIsArray($compositeIndex, 'Composite index should exist');
        $this->assertArrayNotHasKey('US|', $compositeIndex, 'Record with null column should be skipped');
        $this->assertCount(1, $compositeIndex, 'Only one composite key should exist');
    }

    public function test_reload_delegates_to_rebuild(): void
    {
        $repo = $this->makeRepository([
            ['id' => 1, 'name' => 'Alice'],
        ]);

        $repo->reload();

        $this->assertSame(
            [1],
            $this->store->getPks('users', 'v1'),
            'reload() should delegate to rebuild() and populate the cache',
        );
    }

    // -------------------------------------------------------------------------
    // Empty dataset (zero records)
    // -------------------------------------------------------------------------

    public function test_rebuild_with_no_records_stores_empty_ids(): void
    {
        // Arrange — loader yields nothing (e.g. all rows are future-version)
        $repo = $this->makeRepository([]);

        // Act
        $repo->rebuild();

        // Assert — ids key exists and is empty array (not false)
        $ids = $this->store->getPks('users', 'v1');
        $this->assertSame(
            [],
            $ids,
            'ids should be stored as empty array, not false, so it is not mistaken for a cache miss',
        );
    }

    public function test_ids_distinguishes_empty_cache_from_missing_cache(): void
    {
        // Arrange — nothing in store yet
        $repo = $this->makeRepository([]);

        // Act — before rebuild, ids() returns false
        $before = $repo->pks();

        $repo->rebuild();

        // After rebuild with zero records, ids() returns []
        $after = $repo->pks();

        // Assert
        $this->assertFalse(
            $before,
            'Before rebuild, ids() should return false (cache absent)',
        );
        $this->assertSame(
            [],
            $after,
            'After rebuild with zero records, ids() should return [] (cache present but empty)',
        );
        $this->assertNotSame(
            $before,
            $after,
            'false !== [] — empty cache must be distinguishable from absent cache',
        );
    }

    public function test_rebuild_with_no_records_does_not_trigger_further_rebuild(): void
    {
        // Arrange — empty dataset (e.g. table has only future-version rows)
        $repo = $this->makeRepository([]);
        $repo->rebuild();

        // Act — ids() after rebuild
        $ids = $repo->pks();

        // Assert — ids is [] not false, so CacheProcessor would NOT dispatch a rebuild
        $this->assertIsArray(
            $ids,
            'ids() must return array (not false) so callers do not treat empty table as cache miss',
        );
        $this->assertSame(
            [],
            $ids,
            'Empty table should yield empty ids, not trigger a rebuild loop',
        );
    }
}
