<?php

namespace Kura\Tests\Loader;

use Illuminate\Database\Schema\Blueprint;
use Kura\KuraServiceProvider;
use Kura\Loader\EloquentLoader;
use Kura\Loader\QueryBuilderLoader;
use Kura\Loader\StaticVersionResolver;
use Kura\Store\ArrayStore;
use Kura\Store\StoreInterface;
use Kura\Tests\Support\ProductModel;
use Orchestra\Testbench\TestCase;

/**
 * Feature: EloquentLoader and QueryBuilderLoader load records from DB
 *
 * Given a products table with test records in SQLite,
 * When loading via EloquentLoader or QueryBuilderLoader,
 * Then records should be yielded as associative arrays via generator.
 */
class DatabaseLoaderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [KuraServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->singleton(StoreInterface::class, fn () => new ArrayStore);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        assert($this->app !== null);
        $schema = $this->app['db']->connection()->getSchemaBuilder();
        $schema->create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('country');
            $table->integer('price');
        });

        $this->app['db']->table('products')->insert([
            ['name' => 'Widget A', 'country' => 'JP', 'price' => 500],
            ['name' => 'Widget B', 'country' => 'US', 'price' => 200],
            ['name' => 'Widget C', 'country' => 'JP', 'price' => 100],
        ]);
    }

    // =========================================================================
    // EloquentLoader
    // =========================================================================

    public function test_eloquent_loader_yields_all_records(): void
    {
        // Given: a products table with 3 records
        $loader = new EloquentLoader(
            query: ProductModel::query(),
            columns: ['id' => 'int', 'name' => 'string', 'country' => 'string', 'price' => 'int'],
            indexDefinitions: [['columns' => ['country'], 'unique' => false]],
            resolver: new StaticVersionResolver('v1.0.0'),
        );

        // When: loading records
        $records = iterator_to_array($loader->load(), preserve_keys: false);

        // Then: all 3 records should be returned
        $this->assertCount(3, $records, 'EloquentLoader should yield all records');
        $this->assertSame('Widget A', $records[0]['name'], 'First record should be Widget A');
    }

    public function test_eloquent_loader_returns_columns(): void
    {
        $loader = new EloquentLoader(
            query: ProductModel::query(),
            columns: ['id' => 'int', 'name' => 'string'],
        );

        $this->assertSame(['id' => 'int', 'name' => 'string'], $loader->columns(), 'columns() should return configured columns');
    }

    public function test_eloquent_loader_returns_indexes(): void
    {
        $indexes = [['columns' => ['country'], 'unique' => false]];
        $loader = new EloquentLoader(
            query: ProductModel::query(),
            indexDefinitions: $indexes,
        );

        $this->assertSame($indexes, $loader->indexes(), 'indexes() should return configured indexes');
    }

    public function test_eloquent_loader_returns_version_from_resolver(): void
    {
        $loader = new EloquentLoader(
            query: ProductModel::query(),
            resolver: new StaticVersionResolver('v2.0.0'),
        );

        $this->assertSame('v2.0.0', $loader->version(), 'version() should return the version resolved by the resolver');
    }

    public function test_eloquent_loader_with_query_scope(): void
    {
        // Given: a query scoped to country=JP
        $loader = new EloquentLoader(
            query: ProductModel::query()->where('country', 'JP'),
        );

        // When: loading
        $records = iterator_to_array($loader->load(), preserve_keys: false);

        // Then: only JP records
        $this->assertCount(2, $records, 'Scoped query should yield only matching records');
    }

    // =========================================================================
    // QueryBuilderLoader
    // =========================================================================

    public function test_query_builder_loader_yields_all_records(): void
    {
        // Given: a products table with 3 records
        assert($this->app !== null);
        $loader = new QueryBuilderLoader(
            query: $this->app['db']->table('products'),
            columns: ['id' => 'int', 'name' => 'string', 'country' => 'string', 'price' => 'int'],
            indexDefinitions: [['columns' => ['country'], 'unique' => false]],
            resolver: new StaticVersionResolver('v1.0.0'),
        );

        // When: loading records
        $records = iterator_to_array($loader->load(), preserve_keys: false);

        // Then: all 3 records should be returned
        $this->assertCount(3, $records, 'QueryBuilderLoader should yield all records');
        $this->assertSame('Widget A', $records[0]['name'], 'First record should be Widget A');
    }

    public function test_query_builder_loader_returns_columns(): void
    {
        assert($this->app !== null);
        $loader = new QueryBuilderLoader(
            query: $this->app['db']->table('products'),
            columns: ['id' => 'int', 'price' => 'int'],
        );

        $this->assertSame(['id' => 'int', 'price' => 'int'], $loader->columns(), 'columns() should return configured columns');
    }

    public function test_query_builder_loader_returns_indexes(): void
    {
        assert($this->app !== null);
        $indexes = [['columns' => ['price'], 'unique' => false]];
        $loader = new QueryBuilderLoader(
            query: $this->app['db']->table('products'),
            indexDefinitions: $indexes,
        );

        $this->assertSame($indexes, $loader->indexes(), 'indexes() should return configured indexes');
    }

    public function test_query_builder_loader_returns_version_from_resolver(): void
    {
        assert($this->app !== null);
        $loader = new QueryBuilderLoader(
            query: $this->app['db']->table('products'),
            resolver: new StaticVersionResolver('v3.0.0'),
        );

        $this->assertSame('v3.0.0', $loader->version(), 'version() should return the version resolved by the resolver');
    }

    public function test_query_builder_loader_with_where_clause(): void
    {
        // Given: query with price > 150
        assert($this->app !== null);
        $loader = new QueryBuilderLoader(
            query: $this->app['db']->table('products')->where('price', '>', 150),
        );

        // When: loading
        $records = iterator_to_array($loader->load(), preserve_keys: false);

        // Then: only records with price > 150
        $this->assertCount(2, $records, 'Filtered query should yield only matching records');
    }
}
