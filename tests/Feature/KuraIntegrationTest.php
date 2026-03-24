<?php

namespace Kura\Tests\Feature;

use Kura\Contracts\VersionResolverInterface;
use Kura\Facades\Kura;
use Kura\KuraManager;
use Kura\KuraServiceProvider;
use Kura\Store\ArrayStore;
use Kura\Store\StoreInterface;
use Kura\Tests\Support\InMemoryLoader;
use Kura\Version\CachedVersionResolver;
use Orchestra\Testbench\TestCase;

/**
 * Feature: Full Laravel integration via ServiceProvider, Manager, and Facade.
 *
 * Given a Laravel application with Kura installed,
 * When tables are registered and queried,
 * Then the fluent API should work end-to-end just like DB::table().
 */
class KuraIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [KuraServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Kura' => Kura::class];
    }

    protected function defineEnvironment($app): void
    {
        // Use ArrayStore instead of ApcuStore for testing
        $app->singleton(StoreInterface::class, fn () => new ArrayStore);

        $app['config']->set('kura.prefix', 'test');
        $app['config']->set('kura.ttl', [
            'pks' => 3600,
            'meta' => 4800,
            'record' => 4800,
            'index' => 4800,
        ]);
    }

    private function manager(): KuraManager
    {
        assert($this->app !== null);

        return $this->app->make(KuraManager::class);
    }

    private function registerSampleData(): void
    {
        $manager = $this->manager();

        $manager->register('users', new InMemoryLoader([
            ['id' => 1, 'name' => 'Alice', 'country' => 'JP', 'age' => 28, 'score' => 85],
            ['id' => 2, 'name' => 'Bob', 'country' => 'US', 'age' => 35, 'score' => 72],
            ['id' => 3, 'name' => 'Charlie', 'country' => 'JP', 'age' => 22, 'score' => 91],
            ['id' => 4, 'name' => 'Diana', 'country' => 'DE', 'age' => 30, 'score' => 88],
            ['id' => 5, 'name' => 'Eve', 'country' => 'US', 'age' => 27, 'score' => 65],
        ]));

        $manager->rebuild('users');
    }

    // =========================================================================
    // ServiceProvider boots correctly
    // =========================================================================

    public function test_service_provider_registers_manager(): void
    {
        // Given the ServiceProvider is loaded
        // When resolving KuraManager from the container
        $manager = $this->manager();

        // Then it should be a KuraManager instance
        $this->assertInstanceOf(KuraManager::class, $manager);
    }

    public function test_service_provider_registers_singleton(): void
    {
        // Given the ServiceProvider is loaded
        // When resolving KuraManager twice
        $m1 = $this->manager();
        $m2 = $this->manager();

        // Then it should be the same instance
        $this->assertSame($m1, $m2, 'KuraManager should be a singleton');
    }

    public function test_service_provider_registers_version_resolver(): void
    {
        // Given the ServiceProvider is loaded
        // When resolving VersionResolverInterface from the container
        assert($this->app !== null);
        $resolver = $this->app->make(VersionResolverInterface::class);

        // Then it should implement VersionResolverInterface
        $this->assertInstanceOf(
            VersionResolverInterface::class,
            $resolver,
            'ServiceProvider should bind VersionResolverInterface',
        );
    }

    public function test_version_resolver_is_cached_by_default(): void
    {
        // Given the default config with cache_ttl > 0
        assert($this->app !== null);
        $resolver = $this->app->make(VersionResolverInterface::class);

        // Then it should be wrapped in CachedVersionResolver
        $this->assertInstanceOf(
            CachedVersionResolver::class,
            $resolver,
            'VersionResolver should be wrapped with CachedVersionResolver by default',
        );
    }

    public function test_config_is_loaded(): void
    {
        // Given the ServiceProvider is loaded
        // Then kura config should be available
        $this->assertSame('test', config('kura.prefix'));
        $this->assertSame(3600, config('kura.ttl.pks'));
    }

    // =========================================================================
    // Facade works
    // =========================================================================

    public function test_facade_resolves_to_manager(): void
    {
        $this->assertInstanceOf(KuraManager::class, Kura::getFacadeRoot());
    }

    // =========================================================================
    // E2E: DB::table() 風のクエリ
    // =========================================================================

    public function test_where_get(): void
    {
        // Given: users registered and cached
        $this->registerSampleData();

        // When: where('country', 'JP')->get()
        $results = Kura::table('users')->where('country', 'JP')->get();

        // Then: Alice and Charlie
        $this->assertCount(2, $results);
        $names = array_column($results, 'name');
        sort($names);
        $this->assertSame(['Alice', 'Charlie'], $names);
    }

    public function test_where_with_operator(): void
    {
        $this->registerSampleData();

        // When: where('age', '>=', 30)->get()
        $results = Kura::table('users')->where('age', '>=', 30)->get();

        // Then: Bob(35) and Diana(30)
        $this->assertCount(2, $results);
        $names = array_column($results, 'name');
        sort($names);
        $this->assertSame(['Bob', 'Diana'], $names);
    }

    public function test_find_by_id(): void
    {
        $this->registerSampleData();

        // When: find(3)
        $record = Kura::table('users')->find(3);

        // Then: Charlie
        $this->assertNotNull($record);
        $this->assertSame('Charlie', $record['name']);
    }

    public function test_find_returns_null_for_missing(): void
    {
        $this->registerSampleData();

        $record = Kura::table('users')->find(999);

        $this->assertNull($record);
    }

    public function test_first(): void
    {
        $this->registerSampleData();

        $record = Kura::table('users')
            ->where('country', 'US')
            ->orderBy('name')
            ->first();

        $this->assertNotNull($record);
        $this->assertSame('Bob', $record['name']);
    }

    public function test_order_by_and_limit(): void
    {
        $this->registerSampleData();

        // When: orderBy('score', 'desc')->limit(3)->get()
        $results = Kura::table('users')
            ->orderBy('score', 'desc')
            ->limit(3)
            ->get();

        // Then: top 3 scores: Charlie(91), Diana(88), Alice(85)
        $this->assertCount(3, $results);
        $this->assertSame('Charlie', $results[0]['name']);
        $this->assertSame('Diana', $results[1]['name']);
        $this->assertSame('Alice', $results[2]['name']);
    }

    public function test_where_in(): void
    {
        $this->registerSampleData();

        $results = Kura::table('users')
            ->whereIn('country', ['JP', 'DE'])
            ->get();

        $this->assertCount(3, $results);
        $names = array_column($results, 'name');
        sort($names);
        $this->assertSame(['Alice', 'Charlie', 'Diana'], $names);
    }

    public function test_where_between(): void
    {
        $this->registerSampleData();

        $results = Kura::table('users')
            ->whereBetween('age', [25, 30])
            ->get();

        // Alice(28), Diana(30), Eve(27)
        $this->assertCount(3, $results);
    }

    public function test_where_null(): void
    {
        $manager = $this->manager();

        $manager->register('items', new InMemoryLoader([
            ['id' => 1, 'name' => 'A', 'deleted_at' => null],
            ['id' => 2, 'name' => 'B', 'deleted_at' => '2024-01-01'],
            ['id' => 3, 'name' => 'C', 'deleted_at' => null],
        ]));
        $manager->rebuild('items');

        $results = Kura::table('items')->whereNull('deleted_at')->get();

        $this->assertCount(2, $results);
        $names = array_column($results, 'name');
        sort($names);
        $this->assertSame(['A', 'C'], $names);
    }

    // =========================================================================
    // Aggregates
    // =========================================================================

    public function test_count(): void
    {
        $this->registerSampleData();

        $this->assertSame(5, Kura::table('users')->count());
        $this->assertSame(2, Kura::table('users')->where('country', 'JP')->count());
    }

    public function test_sum(): void
    {
        $this->registerSampleData();

        // 85 + 72 + 91 + 88 + 65 = 401
        $this->assertSame(401, Kura::table('users')->sum('score'));
    }

    public function test_min_max(): void
    {
        $this->registerSampleData();

        $this->assertSame(65, Kura::table('users')->min('score'));
        $this->assertSame(91, Kura::table('users')->max('score'));
    }

    public function test_avg(): void
    {
        $this->registerSampleData();

        // 401 / 5 = 80.2
        $this->assertSame(80.2, Kura::table('users')->avg('score'));
    }

    // =========================================================================
    // pluck / value / implode
    // =========================================================================

    public function test_pluck(): void
    {
        $this->registerSampleData();

        $names = Kura::table('users')
            ->where('country', 'JP')
            ->orderBy('name')
            ->pluck('name');

        $this->assertSame(['Alice', 'Charlie'], $names);
    }

    public function test_pluck_with_key(): void
    {
        $this->registerSampleData();

        $nameById = Kura::table('users')
            ->where('country', 'JP')
            ->pluck('name', 'id');

        $this->assertSame([1 => 'Alice', 3 => 'Charlie'], $nameById);
    }

    public function test_value(): void
    {
        $this->registerSampleData();

        $name = Kura::table('users')
            ->where('id', 1)
            ->value('name');

        $this->assertSame('Alice', $name);
    }

    // =========================================================================
    // Existence checks
    // =========================================================================

    public function test_exists_and_doesnt_exist(): void
    {
        $this->registerSampleData();

        $this->assertTrue(Kura::table('users')->where('country', 'JP')->exists());
        $this->assertFalse(Kura::table('users')->where('country', 'BR')->exists());
        $this->assertTrue(Kura::table('users')->where('country', 'BR')->doesntExist());
    }

    // =========================================================================
    // Chaining and cloning
    // =========================================================================

    public function test_chaining_doesnt_mutate(): void
    {
        $this->registerSampleData();

        $base = Kura::table('users')->where('country', 'JP');
        $withAge = $base->clone()->where('age', '>=', 25);

        // base should still return 2, withAge should return 1 (only Alice, 28)
        $this->assertSame(2, $base->count(), 'Original builder should not be mutated');
        $this->assertSame(1, $withAge->count(), 'Cloned builder should have additional condition');
    }

    // =========================================================================
    // Self-Healing: query without explicit rebuild
    // =========================================================================

    public function test_self_healing_on_get(): void
    {
        $manager = $this->manager();

        $manager->register('users', new InMemoryLoader([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]));

        // No rebuild() called — CacheProcessor should fall back to Loader
        $results = Kura::table('users')->get();

        $this->assertCount(2, $results, 'Self-Healing should load data on cache miss');
    }

    // =========================================================================
    // Complex WHERE: Closure nesting, orWhere, combinations
    // =========================================================================

    /**
     * WHERE country = 'JP' OR country = 'DE'
     */
    public function test_or_where(): void
    {
        $this->registerSampleData();

        $results = Kura::table('users')
            ->where('country', 'JP')
            ->orWhere('country', 'DE')
            ->get();

        $this->assertCount(3, $results);
        $names = array_column($results, 'name');
        sort($names);
        $this->assertSame(['Alice', 'Charlie', 'Diana'], $names);
    }

    /**
     * WHERE (country = 'JP' OR country = 'DE') AND age >= 25
     * Closure creates a nested group for the OR.
     */
    public function test_closure_nested_or_with_and(): void
    {
        $this->registerSampleData();

        $results = Kura::table('users')
            ->where(function ($q) {
                $q->where('country', 'JP')
                    ->orWhere('country', 'DE');
            })
            ->where('age', '>=', 25)
            ->get();

        // JP: Alice(28), Charlie(22→除外). DE: Diana(30)
        $this->assertCount(2, $results);
        $names = array_column($results, 'name');
        sort($names);
        $this->assertSame(['Alice', 'Diana'], $names);
    }

    /**
     * WHERE country = 'US' AND (age < 30 OR score > 80)
     */
    public function test_and_with_nested_or(): void
    {
        $this->registerSampleData();

        $results = Kura::table('users')
            ->where('country', 'US')
            ->where(function ($q) {
                $q->where('age', '<', 30)
                    ->orWhere('score', '>', 80);
            })
            ->get();

        // US: Bob(35,72), Eve(27,65). age<30 → Eve. score>80 → none of US.
        $this->assertCount(1, $results);
        $this->assertSame('Eve', $results[0]['name']);
    }

    /**
     * WHERE (country = 'JP' AND age >= 25) OR (country = 'US' AND score >= 70)
     * Two nested groups connected by OR.
     */
    public function test_multiple_nested_groups_with_or(): void
    {
        $this->registerSampleData();

        $results = Kura::table('users')
            ->where(function ($q) {
                $q->where('country', 'JP')
                    ->where('age', '>=', 25);
            })
            ->orWhere(function ($q) {
                $q->where('country', 'US')
                    ->where('score', '>=', 70);
            })
            ->get();

        // JP & age>=25: Alice(28). US & score>=70: Bob(72).
        $this->assertCount(2, $results);
        $names = array_column($results, 'name');
        sort($names);
        $this->assertSame(['Alice', 'Bob'], $names);
    }

    /**
     * WHERE NOT (country = 'JP')
     * whereNot with Closure negates the entire group.
     */
    public function test_where_not_closure(): void
    {
        $this->registerSampleData();

        $results = Kura::table('users')
            ->whereNot(function ($q) {
                $q->where('country', 'JP');
            })
            ->get();

        // Not JP: Bob(US), Diana(DE), Eve(US)
        $this->assertCount(3, $results);
        $names = array_column($results, 'name');
        sort($names);
        $this->assertSame(['Bob', 'Diana', 'Eve'], $names);
    }

    /**
     * Deep nesting:
     * WHERE ((country = 'JP' OR country = 'DE') AND score >= 85)
     *    OR (country = 'US' AND age < 30)
     */
    public function test_deep_nested_closure(): void
    {
        $this->registerSampleData();

        $results = Kura::table('users')
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('country', 'JP')
                        ->orWhere('country', 'DE');
                })->where('score', '>=', 85);
            })
            ->orWhere(function ($q) {
                $q->where('country', 'US')
                    ->where('age', '<', 30);
            })
            ->get();

        // (JP|DE) & score>=85: Alice(JP,85), Charlie(JP,91), Diana(DE,88)
        // US & age<30: Eve(US,27)
        $this->assertCount(4, $results);
        $names = array_column($results, 'name');
        sort($names);
        $this->assertSame(['Alice', 'Charlie', 'Diana', 'Eve'], $names);
    }

    /**
     * whereAny — OR across multiple columns.
     * WHERE (name = 'Alice' OR country = 'Alice')
     */
    public function test_where_any(): void
    {
        $this->registerSampleData();

        $results = Kura::table('users')
            ->whereAny(['name', 'country'], 'Alice')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results[0]['name']);
    }

    /**
     * whereNone — none of the columns match.
     * WHERE NOT (country = 'JP' OR country = 'US')
     */
    public function test_where_none(): void
    {
        $this->registerSampleData();

        $results = Kura::table('users')
            ->whereNone(['country'], 'JP')
            ->get();

        // Not JP: Bob, Diana, Eve
        $this->assertCount(3, $results);
    }

    /**
     * orWhereIn combined with where.
     * WHERE country = 'DE' OR country IN ('JP')
     */
    public function test_or_where_in(): void
    {
        $this->registerSampleData();

        $results = Kura::table('users')
            ->where('country', 'DE')
            ->orWhereIn('country', ['JP'])
            ->get();

        $this->assertCount(3, $results);
        $names = array_column($results, 'name');
        sort($names);
        $this->assertSame(['Alice', 'Charlie', 'Diana'], $names);
    }

    /**
     * whereFilter — raw PHP closure.
     * WHERE strlen(name) <= 3
     */
    public function test_where_filter(): void
    {
        $this->registerSampleData();

        $results = Kura::table('users')
            ->whereFilter(fn ($r) => strlen($r['name']) <= 3)
            ->get();

        // Bob, Eve (3 chars each)
        $this->assertCount(2, $results);
        $names = array_column($results, 'name');
        sort($names);
        $this->assertSame(['Bob', 'Eve'], $names);
    }

    // =========================================================================
    // Multiple tables
    // =========================================================================

    public function test_multiple_tables_independent(): void
    {
        $manager = $this->manager();

        $manager->register('users', new InMemoryLoader([
            ['id' => 1, 'name' => 'Alice'],
        ]));
        $manager->register('products', new InMemoryLoader([
            ['id' => 1, 'title' => 'Widget'],
            ['id' => 2, 'title' => 'Gadget'],
        ]));
        $manager->rebuildAll();

        $this->assertSame(1, Kura::table('users')->count());
        $this->assertSame(2, Kura::table('products')->count());
    }
}
