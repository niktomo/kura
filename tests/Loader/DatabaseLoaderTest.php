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
    private string $tmpDir;

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

        $this->tmpDir = sys_get_temp_dir().'/kura_dbloader_test_'.uniqid();
        mkdir($this->tmpDir.'/products', recursive: true);

        $this->writeDefinesCsv($this->tmpDir.'/products', [
            ['id', 'int', 'PK'],
            ['name', 'string', 'Name'],
            ['country', 'string', 'Country'],
            ['price', 'int', 'Price'],
        ]);

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

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tmpDir);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @param list<list<string>> $rows */
    private function writeDefinesCsv(string $dir, array $rows): void
    {
        $fp = fopen($dir.'/defines.csv', 'w');
        assert($fp !== false);
        fputcsv($fp, ['column', 'type', 'description'], escape: '');
        foreach ($rows as $row) {
            fputcsv($fp, $row, escape: '');
        }
        fclose($fp);
    }

    /** @param list<list<string>> $rows */
    private function writeIndexesCsv(string $dir, array $rows): void
    {
        $fp = fopen($dir.'/indexes.csv', 'w');
        assert($fp !== false);
        fputcsv($fp, ['columns', 'unique'], escape: '');
        foreach ($rows as $row) {
            fputcsv($fp, $row, escape: '');
        }
        fclose($fp);
    }

    private function removeDirectory(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $item) {
            is_dir($item) ? $this->removeDirectory($item) : unlink($item);
        }
        rmdir($dir);
    }

    // =========================================================================
    // EloquentLoader
    // =========================================================================

    public function test_eloquent_loader_yields_all_records(): void
    {
        // Given: a products table with 3 records
        $loader = new EloquentLoader(
            query: ProductModel::query(),
            tableDirectory: $this->tmpDir.'/products',
            resolver: new StaticVersionResolver('v1.0.0'),
        );

        // When: loading records
        $records = iterator_to_array($loader->load(), preserve_keys: false);

        // Then: all 3 records should be returned
        $this->assertCount(3, $records, 'EloquentLoader should yield all records');
        $this->assertSame('Widget A', $records[0]['name'], 'First record should be Widget A');
    }

    public function test_eloquent_loader_returns_columns_from_defines_csv(): void
    {
        // Given
        $loader = new EloquentLoader(
            query: ProductModel::query(),
            tableDirectory: $this->tmpDir.'/products',
        );

        // When / Then
        $this->assertSame(
            ['id' => 'int', 'name' => 'string', 'country' => 'string', 'price' => 'int'],
            $loader->columns(),
            'columns() should return definitions read from defines.csv',
        );
    }

    public function test_eloquent_loader_returns_indexes_from_indexes_csv(): void
    {
        // Given
        $this->writeIndexesCsv($this->tmpDir.'/products', [
            ['country', 'false'],
        ]);
        $loader = new EloquentLoader(
            query: ProductModel::query(),
            tableDirectory: $this->tmpDir.'/products',
        );

        // When / Then
        $this->assertSame(
            [['columns' => ['country'], 'unique' => false]],
            $loader->indexes(),
            'indexes() should return definitions read from indexes.csv',
        );
    }

    public function test_eloquent_loader_returns_empty_indexes_when_no_csv(): void
    {
        // Given — no indexes.csv in directory
        $loader = new EloquentLoader(
            query: ProductModel::query(),
            tableDirectory: $this->tmpDir.'/products',
        );

        // When / Then
        $this->assertSame([], $loader->indexes(), 'indexes() should return empty array when indexes.csv is absent');
    }

    public function test_eloquent_loader_returns_version_from_resolver(): void
    {
        $loader = new EloquentLoader(
            query: ProductModel::query(),
            tableDirectory: $this->tmpDir.'/products',
            resolver: new StaticVersionResolver('v2.0.0'),
        );

        $this->assertSame('v2.0.0', $loader->version(), 'version() should return the version resolved by the resolver');
    }

    public function test_eloquent_loader_with_query_scope(): void
    {
        // Given: a query scoped to country=JP
        $loader = new EloquentLoader(
            query: ProductModel::query()->where('country', 'JP'),
            tableDirectory: $this->tmpDir.'/products',
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
            tableDirectory: $this->tmpDir.'/products',
            resolver: new StaticVersionResolver('v1.0.0'),
        );

        // When: loading records
        $records = iterator_to_array($loader->load(), preserve_keys: false);

        // Then: all 3 records should be returned
        $this->assertCount(3, $records, 'QueryBuilderLoader should yield all records');
        $this->assertSame('Widget A', $records[0]['name'], 'First record should be Widget A');
    }

    public function test_query_builder_loader_returns_columns_from_defines_csv(): void
    {
        // Given
        assert($this->app !== null);
        $loader = new QueryBuilderLoader(
            query: $this->app['db']->table('products'),
            tableDirectory: $this->tmpDir.'/products',
        );

        // When / Then
        $this->assertSame(
            ['id' => 'int', 'name' => 'string', 'country' => 'string', 'price' => 'int'],
            $loader->columns(),
            'columns() should return definitions read from defines.csv',
        );
    }

    public function test_query_builder_loader_returns_indexes_from_indexes_csv(): void
    {
        // Given
        $this->writeIndexesCsv($this->tmpDir.'/products', [
            ['price', 'false'],
        ]);
        assert($this->app !== null);
        $loader = new QueryBuilderLoader(
            query: $this->app['db']->table('products'),
            tableDirectory: $this->tmpDir.'/products',
        );

        // When / Then
        $this->assertSame(
            [['columns' => ['price'], 'unique' => false]],
            $loader->indexes(),
            'indexes() should return definitions read from indexes.csv',
        );
    }

    public function test_query_builder_loader_returns_version_from_resolver(): void
    {
        assert($this->app !== null);
        $loader = new QueryBuilderLoader(
            query: $this->app['db']->table('products'),
            tableDirectory: $this->tmpDir.'/products',
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
            tableDirectory: $this->tmpDir.'/products',
        );

        // When: loading
        $records = iterator_to_array($loader->load(), preserve_keys: false);

        // Then: only records with price > 150
        $this->assertCount(2, $records, 'Filtered query should yield only matching records');
    }
}
