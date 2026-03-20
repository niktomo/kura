<?php

namespace Kura\Tests;

use Kura\Contracts\VersionResolverInterface;
use Kura\Facades\Kura;
use Kura\KuraManager;
use Kura\KuraServiceProvider;
use Kura\Store\ArrayStore;
use Kura\Store\StoreInterface;
use Orchestra\Testbench\TestCase;

/**
 * Feature: KuraServiceProvider registers bindings, config, and commands
 *
 * Given a Laravel application with KuraServiceProvider loaded,
 * When resolving services from the container,
 * Then the correct singletons, config, facade, and commands should be available.
 */
class KuraServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [KuraServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->singleton(StoreInterface::class, fn () => new ArrayStore);

        $app['config']->set('kura.version.driver', 'csv');
        $app['config']->set('kura.version.csv_path', '');
        $app['config']->set('kura.version.cache_ttl', 0);
    }

    public function test_store_interface_is_bound_as_singleton(): void
    {
        // Given: the service provider is loaded
        assert($this->app !== null);

        // When: resolving StoreInterface twice
        $first = $this->app->make(StoreInterface::class);
        $second = $this->app->make(StoreInterface::class);

        // Then: both references should be the same instance
        $this->assertSame($first, $second, 'StoreInterface should be a singleton');
    }

    public function test_kura_manager_is_bound_as_singleton(): void
    {
        assert($this->app !== null);

        // When: resolving KuraManager twice
        $first = $this->app->make(KuraManager::class);
        $second = $this->app->make(KuraManager::class);

        // Then: same instance
        $this->assertSame($first, $second, 'KuraManager should be a singleton');
    }

    public function test_facade_resolves_to_kura_manager(): void
    {
        assert($this->app !== null);

        // When: calling a method via Facade
        $tables = Kura::registeredTables();

        // Then: returns empty array (no tables registered)
        $this->assertSame([], $tables, 'Facade should resolve to KuraManager');
    }

    public function test_config_is_merged(): void
    {
        assert($this->app !== null);

        // When: reading kura config
        $prefix = $this->app['config']->get('kura.prefix');

        // Then: default prefix
        $this->assertSame('kura', $prefix, 'Config should be merged with default prefix');
    }

    public function test_config_publish_path_is_registered(): void
    {
        assert($this->app !== null);

        // When: checking registered publish groups
        $publishes = KuraServiceProvider::$publishes[KuraServiceProvider::class] ?? [];

        // Then: kura config should be in publish list
        $this->assertNotEmpty($publishes, 'ServiceProvider should register config for publishing');

        $configTarget = config_path('kura.php');
        $this->assertContains($configTarget, $publishes, 'Published config should target config_path("kura.php")');
    }

    public function test_rebuild_command_is_registered(): void
    {
        // When: running kura:rebuild with no tables
        $result = $this->artisan('kura:rebuild');
        assert($result instanceof \Illuminate\Testing\PendingCommand);

        // Then: command should exist and succeed
        $result->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // CSV auto-discovery
    // -------------------------------------------------------------------------

    public function test_auto_discover_registers_table_directories(): void
    {
        assert($this->app !== null);

        // Given: a base_path with two table subdirectories each containing data.csv
        $tmpDir = sys_get_temp_dir().'/kura_autodiscover_'.uniqid();
        mkdir($tmpDir.'/products', recursive: true);
        mkdir($tmpDir.'/countries', recursive: true);
        touch($tmpDir.'/products/data.csv');
        touch($tmpDir.'/countries/data.csv');
        touch($tmpDir.'/versions.csv');

        $this->app->make('config')->set('kura.csv.auto_discover', true);
        $this->app->make('config')->set('kura.csv.base_path', $tmpDir);
        $this->app->make('config')->set('kura.version.cache_ttl', 0);

        // When: booting the service provider with the new config
        (new KuraServiceProvider($this->app))->boot();

        // Then: both tables are registered
        $manager = $this->app->make(KuraManager::class);
        $tables = $manager->registeredTables();
        sort($tables);

        $this->assertSame(
            ['countries', 'products'],
            $tables,
            'Auto-discovery should register all subdirectories containing data.csv',
        );

        $this->removeDirectory($tmpDir);
    }

    public function test_auto_discover_skips_directories_without_data_csv(): void
    {
        assert($this->app !== null);

        // Given: base_path with one valid table and one empty directory
        $tmpDir = sys_get_temp_dir().'/kura_autodiscover_'.uniqid();
        mkdir($tmpDir.'/products', recursive: true);
        mkdir($tmpDir.'/not_a_table', recursive: true); // no data.csv
        touch($tmpDir.'/products/data.csv');
        touch($tmpDir.'/versions.csv');

        $this->app->make('config')->set('kura.csv.auto_discover', true);
        $this->app->make('config')->set('kura.csv.base_path', $tmpDir);
        $this->app->make('config')->set('kura.version.cache_ttl', 0);

        (new KuraServiceProvider($this->app))->boot();

        $manager = $this->app->make(KuraManager::class);

        $this->assertSame(
            ['products'],
            $manager->registeredTables(),
            'Directories without data.csv should not be registered',
        );

        $this->removeDirectory($tmpDir);
    }

    public function test_auto_discover_respects_primary_key_override(): void
    {
        assert($this->app !== null);

        // Given: a table with a non-default primary key override in config
        $tmpDir = sys_get_temp_dir().'/kura_autodiscover_'.uniqid();
        mkdir($tmpDir.'/products', recursive: true);
        touch($tmpDir.'/products/data.csv');
        touch($tmpDir.'/versions.csv');

        $this->app->make('config')->set('kura.csv.auto_discover', true);
        $this->app->make('config')->set('kura.csv.base_path', $tmpDir);
        $this->app->make('config')->set('kura.version.cache_ttl', 0);
        $this->app->make('config')->set('kura.tables.products.primary_key', 'product_code');

        (new KuraServiceProvider($this->app))->boot();

        $manager = $this->app->make(KuraManager::class);

        $this->assertSame(
            'product_code',
            $manager->repository('products')->primaryKey(),
            'primary_key override in config.tables should be respected',
        );

        $this->removeDirectory($tmpDir);
    }

    public function test_auto_discover_does_nothing_when_disabled(): void
    {
        assert($this->app !== null);

        // Given: auto_discover is false (default)
        $this->app->make('config')->set('kura.csv.auto_discover', false);

        (new KuraServiceProvider($this->app))->boot();

        $manager = $this->app->make(KuraManager::class);

        $this->assertSame(
            [],
            $manager->registeredTables(),
            'No tables should be registered when auto_discover is false',
        );
    }

    private function removeDirectory(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $item) {
            is_dir($item) ? $this->removeDirectory($item) : unlink($item);
        }
        rmdir($dir);
    }

    public function test_version_resolver_is_bound(): void
    {
        assert($this->app !== null);

        // When: resolving VersionResolverInterface
        $resolver = $this->app->make(VersionResolverInterface::class);

        // Then: non-null, correct type
        $this->assertInstanceOf(VersionResolverInterface::class, $resolver, 'VersionResolverInterface should be bound');
    }
}
