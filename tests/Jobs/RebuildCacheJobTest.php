<?php

namespace Kura\Tests\Jobs;

use Kura\Jobs\RebuildCacheJob;
use Kura\KuraManager;
use Kura\Store\ArrayStore;
use Kura\Tests\Support\InMemoryLoader;
use PHPUnit\Framework\TestCase;

class RebuildCacheJobTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function test_constructor_sets_table_name(): void
    {
        // Arrange & Act
        $job = new RebuildCacheJob('products');

        // Assert
        $this->assertSame('products', $job->table, 'table should be set from constructor');
    }

    public function test_default_tries_is_three(): void
    {
        // Arrange & Act
        $job = new RebuildCacheJob('products');

        // Assert
        $this->assertSame(3, $job->tries, 'Default tries should be 3');
    }

    // -------------------------------------------------------------------------
    // handle() delegates to KuraManager::rebuild()
    // -------------------------------------------------------------------------

    public function test_handle_calls_manager_rebuild(): void
    {
        // Arrange
        $store = new ArrayStore;
        $manager = new KuraManager(store: $store);
        $manager->register('products', new InMemoryLoader([
            ['id' => 1, 'name' => 'Widget'],
            ['id' => 2, 'name' => 'Gadget'],
        ]));

        $job = new RebuildCacheJob('products');

        // Act
        $job->handle($manager);

        // Assert
        $ids = $store->getIds('products', 'v1');
        $this->assertIsArray($ids, 'handle() should trigger rebuild and populate cache');
        $this->assertSame(
            [1, 2],
            $ids,
            'All records should be cached after job execution',
        );
    }

    public function test_handle_rebuilds_correct_table_only(): void
    {
        // Arrange
        $store = new ArrayStore;
        $manager = new KuraManager(store: $store);
        $manager->register('products', new InMemoryLoader([
            ['id' => 1, 'name' => 'Widget'],
        ]));
        $manager->register('users', new InMemoryLoader([
            ['id' => 1, 'name' => 'Alice'],
        ]));

        $job = new RebuildCacheJob('products');

        // Act
        $job->handle($manager);

        // Assert
        $this->assertIsArray(
            $store->getIds('products', 'v1'),
            'products should be rebuilt',
        );
        $this->assertFalse(
            $store->getIds('users', 'v1'),
            'users should NOT be rebuilt by a products job',
        );
    }

    // -------------------------------------------------------------------------
    // Version override
    // -------------------------------------------------------------------------

    public function test_version_defaults_to_null(): void
    {
        // Arrange & Act
        $job = new RebuildCacheJob('products');

        // Assert
        $this->assertNull($job->version, 'version should default to null');
    }

    public function test_handle_applies_version_override_when_set(): void
    {
        // Arrange
        $store = new ArrayStore;
        $manager = new KuraManager(store: $store);
        $manager->register('products', new InMemoryLoader([
            ['id' => 1, 'name' => 'Widget'],
        ]));

        $job = new RebuildCacheJob('products', 'v2.0.0');

        // Act
        $job->handle($manager);

        // Assert
        $ids = $store->getIds('products', 'v2.0.0');
        $this->assertIsArray(
            $ids,
            'handle() should rebuild under the overridden version key',
        );
        $this->assertSame(
            [1],
            $ids,
            'Records should be cached under v2.0.0 when version override is set',
        );
    }

    public function test_handle_does_not_set_version_when_null(): void
    {
        // Arrange
        $store = new ArrayStore;
        $manager = new KuraManager(store: $store);
        $manager->register('products', new InMemoryLoader([
            ['id' => 1, 'name' => 'Widget'],
        ]));

        $job = new RebuildCacheJob('products');  // version = null

        // Act
        $job->handle($manager);

        // Assert: rebuilt under the loader's default version ('v1')
        $ids = $store->getIds('products', 'v1');
        $this->assertIsArray(
            $ids,
            'Without version override, records should be cached under loader\'s version',
        );
    }

    // -------------------------------------------------------------------------
    // Queue configuration
    // -------------------------------------------------------------------------

    public function test_tries_can_be_overridden(): void
    {
        // Arrange
        $job = new RebuildCacheJob('products');

        // Act
        $job->tries = 5;

        // Assert
        $this->assertSame(5, $job->tries, 'tries should be overridable');
    }
}
