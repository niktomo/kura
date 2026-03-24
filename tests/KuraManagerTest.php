<?php

namespace Kura\Tests;

use Kura\CacheProcessor;
use Kura\CacheRepository;
use Kura\KuraManager;
use Kura\ReferenceQueryBuilder;
use Kura\Store\ArrayStore;
use Kura\Tests\Support\InMemoryLoader;
use PHPUnit\Framework\TestCase;

class KuraManagerTest extends TestCase
{
    private ArrayStore $store;

    private KuraManager $manager;

    protected function setUp(): void
    {
        $this->store = new ArrayStore;
        $this->manager = new KuraManager(store: $this->store);
    }

    // -------------------------------------------------------------------------
    // register / registeredTables
    // -------------------------------------------------------------------------

    public function test_register_and_list_tables(): void
    {
        // Arrange
        $loader = new InMemoryLoader([['id' => 1, 'name' => 'Alice']]);

        // Act
        $this->manager->register('users', $loader);

        // Assert
        $this->assertSame(
            ['users'],
            $this->manager->registeredTables(),
            'registeredTables() should return all registered table names',
        );
    }

    public function test_register_multiple_tables(): void
    {
        // Arrange
        $usersLoader = new InMemoryLoader([['id' => 1, 'name' => 'Alice']]);
        $productsLoader = new InMemoryLoader([['id' => 1, 'title' => 'Widget']]);

        // Act
        $this->manager->register('users', $usersLoader);
        $this->manager->register('products', $productsLoader, primaryKey: 'id');

        // Assert
        $this->assertCount(
            2,
            $this->manager->registeredTables(),
            'Should track all registered tables',
        );
    }

    // -------------------------------------------------------------------------
    // table() returns a ReferenceQueryBuilder
    // -------------------------------------------------------------------------

    public function test_table_returns_query_builder(): void
    {
        // Arrange
        $loader = new InMemoryLoader([['id' => 1, 'name' => 'Alice']]);
        $this->manager->register('users', $loader);

        // Act
        $builder = $this->manager->table('users');

        // Assert
        $this->assertInstanceOf(
            ReferenceQueryBuilder::class,
            $builder,
            'table() should return a ReferenceQueryBuilder instance',
        );
    }

    public function test_table_throws_for_unregistered(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Table 'unknown' is not registered");

        $this->manager->table('unknown');
    }

    // -------------------------------------------------------------------------
    // repository() / processor()
    // -------------------------------------------------------------------------

    public function test_repository_returns_cache_repository(): void
    {
        // Arrange
        $loader = new InMemoryLoader([['id' => 1, 'name' => 'Alice']]);
        $this->manager->register('users', $loader);

        // Act
        $repo = $this->manager->repository('users');

        // Assert
        $this->assertInstanceOf(
            CacheRepository::class,
            $repo,
            'repository() should return a CacheRepository instance',
        );
        $this->assertSame('users', $repo->table(), 'Repository should be for the correct table');
    }

    public function test_repository_returns_same_instance(): void
    {
        // Arrange
        $loader = new InMemoryLoader([['id' => 1, 'name' => 'Alice']]);
        $this->manager->register('users', $loader);

        // Act
        $repo1 = $this->manager->repository('users');
        $repo2 = $this->manager->repository('users');

        // Assert
        $this->assertSame(
            $repo1,
            $repo2,
            'repository() should return the same cached instance',
        );
    }

    public function test_processor_returns_cache_processor(): void
    {
        // Arrange
        $loader = new InMemoryLoader([['id' => 1, 'name' => 'Alice']]);
        $this->manager->register('users', $loader);

        // Act
        $processor = $this->manager->processor('users');

        // Assert
        $this->assertInstanceOf(
            CacheProcessor::class,
            $processor,
            'processor() should return a CacheProcessor instance',
        );
    }

    // -------------------------------------------------------------------------
    // register with factory closure
    // -------------------------------------------------------------------------

    public function test_register_with_factory_closure_returns_query_builder(): void
    {
        // Arrange
        $loader = new InMemoryLoader([['id' => 1, 'name' => 'Alice']]);
        $this->manager->register('users', fn () => $loader);

        // Act
        $builder = $this->manager->table('users');

        // Assert
        $this->assertInstanceOf(
            ReferenceQueryBuilder::class,
            $builder,
            'table() should work when loader was registered as a factory closure',
        );
    }

    public function test_factory_closure_is_not_invoked_until_first_access(): void
    {
        // Arrange
        $invoked = false;
        $loader = new InMemoryLoader([['id' => 1, 'name' => 'Alice']]);

        // Act — register only, do not access the table yet
        $this->manager->register('users', function () use ($loader, &$invoked): InMemoryLoader {
            $invoked = true;

            return $loader;
        });

        // Assert — closure must not have run yet
        $this->assertFalse(
            $invoked,
            'Factory closure should not be invoked at register() time',
        );

        // Act — first access triggers the closure
        $this->manager->table('users');

        // @phpstan-ignore method.impossibleType (PHPStan cannot track by-reference mutation inside a closure)
        $this->assertTrue(
            $invoked,
            'Factory closure should be invoked on first table access',
        );
    }

    public function test_factory_closure_is_invoked_only_once(): void
    {
        // Arrange
        $callCount = 0;
        $loader = new InMemoryLoader([['id' => 1, 'name' => 'Alice']]);
        $this->manager->register('users', function () use ($loader, &$callCount): InMemoryLoader {
            $callCount++;

            return $loader;
        });

        // Act — access the table three times
        $this->manager->table('users');
        $this->manager->table('users');
        $this->manager->table('users');

        // Assert — closure called exactly once despite multiple accesses
        $this->assertSame(
            1,
            $callCount,
            'Factory closure should be invoked only once regardless of how many times the table is accessed',
        );
    }

    // -------------------------------------------------------------------------
    // rebuild / rebuildAll
    // -------------------------------------------------------------------------

    public function test_rebuild_populates_cache(): void
    {
        // Arrange
        $loader = new InMemoryLoader([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);
        $this->manager->register('users', $loader);

        // Act
        $this->manager->rebuild('users');

        // Assert
        $ids = $this->store->getPks('users', 'v1');
        $this->assertIsArray($ids, 'rebuild should store ids in the cache');
        $this->assertSame(
            [1, 2],
            $ids,
            'rebuild should store all record IDs',
        );
    }

    public function test_rebuild_all_populates_all_tables(): void
    {
        // Arrange
        $usersLoader = new InMemoryLoader([['id' => 1, 'name' => 'Alice']]);
        $productsLoader = new InMemoryLoader([['id' => 1, 'title' => 'Widget']]);
        $this->manager->register('users', $usersLoader);
        $this->manager->register('products', $productsLoader);

        // Act
        $this->manager->rebuildAll();

        // Assert
        $this->assertIsArray(
            $this->store->getPks('users', 'v1'),
            'rebuildAll should populate users cache',
        );
        $this->assertIsArray(
            $this->store->getPks('products', 'v1'),
            'rebuildAll should populate products cache',
        );
    }

    // -------------------------------------------------------------------------
    // E2E: register → rebuild → query
    // -------------------------------------------------------------------------

    public function test_end_to_end_query_flow(): void
    {
        // Arrange
        $loader = new InMemoryLoader([
            ['id' => 1, 'name' => 'Alice', 'country' => 'JP'],
            ['id' => 2, 'name' => 'Bob', 'country' => 'US'],
            ['id' => 3, 'name' => 'Charlie', 'country' => 'JP'],
        ]);
        $this->manager->register('users', $loader);
        $this->manager->rebuild('users');

        // Act
        $results = $this->manager->table('users')
            ->where('country', 'JP')
            ->orderBy('name')
            ->get();

        // Assert
        $this->assertCount(2, $results, 'Should return 2 JP users');
        $this->assertSame('Alice', $results[0]['name'], 'Should be sorted by name ascending');
        $this->assertSame('Charlie', $results[1]['name'], 'Should be sorted by name ascending');
    }

    public function test_end_to_end_find(): void
    {
        // Arrange
        $loader = new InMemoryLoader([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);
        $this->manager->register('users', $loader);
        $this->manager->rebuild('users');

        // Act
        $record = $this->manager->table('users')->find(1);

        // Assert
        $this->assertNotNull($record, 'find should return the record');
        $this->assertSame('Alice', $record['name'], 'find should return the correct record');
    }

    public function test_end_to_end_self_healing(): void
    {
        // Arrange: register but don't rebuild — Self-Healing should trigger
        $loader = new InMemoryLoader([
            ['id' => 1, 'name' => 'Alice'],
        ]);
        $this->manager->register('users', $loader);

        // Act: find triggers rebuild via Self-Healing
        $record = $this->manager->table('users')->find(1);

        // Assert
        $this->assertNotNull($record, 'Self-Healing should rebuild and return the record');
        $this->assertSame('Alice', $record['name'], 'Should return correct data after Self-Healing');
    }

    public function test_end_to_end_aggregates(): void
    {
        // Arrange
        $loader = new InMemoryLoader([
            ['id' => 1, 'name' => 'Alice', 'score' => 80],
            ['id' => 2, 'name' => 'Bob', 'score' => 90],
            ['id' => 3, 'name' => 'Charlie', 'score' => 70],
        ]);
        $this->manager->register('users', $loader);
        $this->manager->rebuild('users');

        // Act & Assert
        $builder = $this->manager->table('users');
        $this->assertSame(3, $builder->count(), 'count() should return total records');
        $this->assertSame(240, $builder->sum('score'), 'sum() should return total of column');
        $this->assertSame(70, $builder->min('score'), 'min() should return minimum value');
        $this->assertSame(90, $builder->max('score'), 'max() should return maximum value');
    }

    // -------------------------------------------------------------------------
    // Per-table config override
    // -------------------------------------------------------------------------

    public function test_rebuild_uses_per_table_ttl_override(): void
    {
        // Arrange: per-table config with custom record TTL
        $manager = new KuraManager(
            store: $this->store,
            defaultTtl: ['pks' => 100, 'record' => 200],
            tableConfigs: [
                'products' => ['ttl' => ['record' => 9999]],
            ],
        );

        $loader = new InMemoryLoader([
            ['id' => 1, 'name' => 'Widget'],
        ]);
        $manager->register('products', $loader);

        // Act
        $manager->rebuild('products');

        // Assert: records should be stored (we can't easily verify TTL on ArrayStore,
        // but we verify the rebuild completed successfully with the merged config)
        $ids = $this->store->getPks('products', 'v1');
        $this->assertIsArray($ids, 'Per-table config rebuild should store ids');
        $this->assertSame([1], $ids, 'Per-table config rebuild should store correct ids');
    }

    // -------------------------------------------------------------------------
    // setVersionOverride
    // -------------------------------------------------------------------------

    public function test_set_version_override_changes_repository_version(): void
    {
        // Arrange
        $loader = new InMemoryLoader([['id' => 1, 'name' => 'Alice']], version: 'v1');
        $this->manager->register('users', $loader);

        // Act
        $this->manager->setVersionOverride('v2.0.0');

        // Assert
        $this->assertSame(
            'v2.0.0',
            $this->manager->repository('users')->version(),
            'setVersionOverride should override the Loader version',
        );
    }

    public function test_set_version_override_clears_cached_instances(): void
    {
        // Arrange
        $loader = new InMemoryLoader([['id' => 1, 'name' => 'Alice']], version: 'v1');
        $this->manager->register('users', $loader);
        $repoBefore = $this->manager->repository('users');

        // Act
        $this->manager->setVersionOverride('v2.0.0');
        $repoAfter = $this->manager->repository('users');

        // Assert
        $this->assertNotSame(
            $repoBefore,
            $repoAfter,
            'setVersionOverride should clear cached repository instances',
        );
    }

    public function test_set_version_override_rebuild_uses_overridden_version(): void
    {
        // Arrange
        $loader = new InMemoryLoader([['id' => 1, 'name' => 'Alice']], version: 'v1');
        $this->manager->register('users', $loader);
        $this->manager->setVersionOverride('v3.0.0');

        // Act
        $this->manager->rebuild('users');

        // Assert — ids stored under overridden version key
        $this->assertIsArray(
            $this->store->getPks('users', 'v3.0.0'),
            'rebuild should use overridden version for APCu keys',
        );
        $this->assertFalse(
            $this->store->getPks('users', 'v1'),
            'rebuild should NOT store under the original Loader version',
        );
    }

    // -------------------------------------------------------------------------
    // rebuildDispatcher injection
    // -------------------------------------------------------------------------

    public function test_rebuild_dispatcher_is_used_on_self_healing(): void
    {
        // Arrange
        $dispatched = false;
        $manager = new KuraManager(
            store: $this->store,
            rebuildDispatcher: function () use (&$dispatched) {
                $dispatched = true;
            },
        );

        $loader = new InMemoryLoader([['id' => 1, 'name' => 'Alice']]);
        $manager->register('users', $loader);

        // Act: get() with no cache triggers dispatchRebuild via CacheProcessor
        $manager->table('users')->get();

        // Assert
        $this->assertTrue($dispatched, 'rebuildDispatcher should be called on cache miss');
    }
}
