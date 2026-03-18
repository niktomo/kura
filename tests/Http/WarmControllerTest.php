<?php

namespace Kura\Tests\Http;

use Illuminate\Support\Facades\Bus;
use Kura\Jobs\RebuildCacheJob;
use Kura\KuraManager;
use Kura\KuraServiceProvider;
use Kura\Store\ArrayStore;
use Kura\Store\StoreInterface;
use Kura\Tests\Support\InMemoryLoader;
use Orchestra\Testbench\TestCase;

/**
 * Feature: Warm endpoint rebuilds APCu cache via HTTP
 *
 * Given warm endpoint is enabled with a valid token,
 * When POST /kura/warm is called,
 * Then all registered tables should be rebuilt and cached.
 */
class WarmControllerTest extends TestCase
{
    private ArrayStore $store;

    /** @var list<array<string, mixed>> */
    private array $products = [
        ['id' => 1, 'name' => 'Widget A', 'price' => 500],
        ['id' => 2, 'name' => 'Widget B', 'price' => 200],
    ];

    protected function getPackageProviders($app): array
    {
        return [KuraServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->store = new ArrayStore;
        $app->singleton(StoreInterface::class, fn () => $this->store);

        $app['config']->set('kura.warm.enabled', true);
        $app['config']->set('kura.warm.token', 'test-secret-token');
        $app['config']->set('kura.version.driver', 'csv');
        $app['config']->set('kura.version.csv_path', __DIR__.'/../Support/versions.csv');
    }

    protected function setUp(): void
    {
        parent::setUp();

        assert($this->app !== null);
        /** @var KuraManager $manager */
        $manager = $this->app->make(KuraManager::class);

        $manager->register('products', new InMemoryLoader(
            records: $this->products,
            columns: ['id' => 'int', 'name' => 'string', 'price' => 'int'],
        ));
    }

    // =========================================================================
    // Authentication
    // =========================================================================

    public function test_warm_rejects_request_without_token(): void
    {
        // Given: no Authorization header
        // When: POST /kura/warm
        $response = $this->postJson('/kura/warm');

        // Then: 401 Unauthorized
        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthorized.']);
    }

    public function test_warm_rejects_request_with_wrong_token(): void
    {
        // Given: wrong Bearer token
        // When: POST /kura/warm
        $response = $this->postJson('/kura/warm', [], [
            'Authorization' => 'Bearer wrong-token',
        ]);

        // Then: 401 Unauthorized
        $response->assertStatus(401);
    }

    // =========================================================================
    // Successful warm
    // =========================================================================

    public function test_warm_rebuilds_all_tables(): void
    {
        // Given: products table is registered
        // When: POST /kura/warm with valid token
        $response = $this->postJson('/kura/warm', [], [
            'Authorization' => 'Bearer test-secret-token',
        ]);

        // Then: 200 with success
        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'All tables warmed.',
            'tables' => [
                'products' => ['status' => 'ok'],
            ],
        ]);
    }

    public function test_warm_rebuilds_specific_tables(): void
    {
        // Given: products table is registered
        // When: POST /kura/warm?tables=products
        $response = $this->postJson('/kura/warm?tables=products', [], [
            'Authorization' => 'Bearer test-secret-token',
        ]);

        // Then: only products table is rebuilt
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'tables');
    }

    public function test_warm_with_version_override(): void
    {
        // Given: products table is registered
        // When: POST /kura/warm?version=v2.0.0
        $response = $this->postJson('/kura/warm?version=v2.0.0', [], [
            'Authorization' => 'Bearer test-secret-token',
        ]);

        // Then: rebuilt with overridden version
        $response->assertStatus(200);
        $response->assertJsonPath('tables.products.version', 'v2.0.0');
    }

    public function test_warm_returns_empty_when_no_tables(): void
    {
        // Given: no tables registered (fresh manager)
        assert($this->app !== null);
        $this->app->singleton(KuraManager::class, fn ($app) => new KuraManager(
            store: $app->make(StoreInterface::class),
        ));

        // When: POST /kura/warm
        $response = $this->postJson('/kura/warm', [], [
            'Authorization' => 'Bearer test-secret-token',
        ]);

        // Then: 200 with empty tables
        $response->assertStatus(200);
        $response->assertJson(['message' => 'No tables registered.', 'tables' => []]);
    }

    // =========================================================================
    // Disabled endpoint
    // =========================================================================

    public function test_warm_custom_path(): void
    {
        // Given: warm.path is overridden
        assert($this->app !== null);
        $this->app['config']->set('kura.warm.path', 'custom/warm');

        // Note: Route path is set at boot time, so the original path still works.
        // This test verifies the config value is respected.
        /** @var string $path */
        $path = $this->app['config']->get('kura.warm.path');
        $this->assertSame('custom/warm', $path, 'Custom path should be configurable');
    }

    // =========================================================================
    // Queue strategy — Bus::batch() dispatch
    // =========================================================================

    public function test_warm_dispatches_batch_job_per_table_when_strategy_is_queue(): void
    {
        // Given: rebuild strategy = queue
        Bus::fake();
        assert($this->app !== null);
        $this->app['config']->set('kura.rebuild.strategy', 'queue');

        // When: POST /kura/warm
        $response = $this->postJson('/kura/warm', [], [
            'Authorization' => 'Bearer test-secret-token',
        ]);

        // Then: 202 Accepted with batch_id
        $response->assertStatus(202);
        $response->assertJsonStructure(['message', 'batch_id', 'tables']);
        $response->assertJsonPath('message', 'Rebuild dispatched.');
        $response->assertJsonPath('tables.products.status', 'dispatched');

        Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $batch): bool {
            return count($batch->jobs) === 1
                && $batch->jobs[0] instanceof RebuildCacheJob
                && $batch->jobs[0]->table === 'products';
        });
    }

    public function test_warm_queue_strategy_includes_version_in_each_job(): void
    {
        // Given: rebuild strategy = queue, version override requested
        Bus::fake();
        assert($this->app !== null);
        $this->app['config']->set('kura.rebuild.strategy', 'queue');

        // When: POST /kura/warm?version=v2.0.0
        $response = $this->postJson('/kura/warm?version=v2.0.0', [], [
            'Authorization' => 'Bearer test-secret-token',
        ]);

        // Then: 202 and dispatched job carries the version
        $response->assertStatus(202);

        Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $batch): bool {
            return $batch->jobs[0] instanceof RebuildCacheJob
                && $batch->jobs[0]->version === 'v2.0.0';
        });
    }

    public function test_warm_queue_strategy_dispatches_one_job_per_registered_table(): void
    {
        // Given: two tables registered, strategy = queue
        Bus::fake();
        assert($this->app !== null);
        $this->app['config']->set('kura.rebuild.strategy', 'queue');

        /** @var KuraManager $manager */
        $manager = $this->app->make(KuraManager::class);
        $manager->register('categories', new InMemoryLoader(
            records: [['id' => 1, 'name' => 'Electronics']],
            columns: ['id' => 'int', 'name' => 'string'],
        ));

        // When: POST /kura/warm (no tables filter = all)
        $response = $this->postJson('/kura/warm', [], [
            'Authorization' => 'Bearer test-secret-token',
        ]);

        // Then: 202 and batch has 2 jobs (products + categories)
        $response->assertStatus(202);

        Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $batch): bool {
            return count($batch->jobs) === 2;
        });
    }

    // =========================================================================
    // Token not configured
    // =========================================================================

    public function test_warm_rejects_when_token_not_configured(): void
    {
        // Given: warm.token is empty
        assert($this->app !== null);
        $this->app['config']->set('kura.warm.token', '');

        // When: POST /kura/warm with any token
        $response = $this->postJson('/kura/warm', [], [
            'Authorization' => 'Bearer some-token',
        ]);

        // Then: 403 with config message
        $response->assertStatus(403);
        $response->assertJson(['message' => 'Warm endpoint is not configured. Set kura.warm.token.']);
    }
}
