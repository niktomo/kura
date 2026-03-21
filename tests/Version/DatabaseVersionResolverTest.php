<?php

namespace Kura\Tests\Version;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Kura\KuraServiceProvider;
use Kura\Version\DatabaseVersionResolver;
use Orchestra\Testbench\TestCase;

/**
 * Feature: Load all version rows from reference_versions table.
 *
 * Given a reference_versions table with id, version, activated_at,
 * When loading all rows,
 * Then all rows are returned as an array (no filtering — filtering is done by CachedVersionResolver).
 */
class DatabaseVersionResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [KuraServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    private function resolver(
        string $table = 'reference_versions',
        string $versionColumn = 'version',
        string $startAtColumn = 'activated_at',
    ): DatabaseVersionResolver {
        return new DatabaseVersionResolver(
            connection: DB::connection(),
            table: $table,
            versionColumn: $versionColumn,
            startAtColumn: $startAtColumn,
        );
    }

    public function test_loads_all_rows_ordered_by_activated_at(): void
    {
        // Arrange
        DB::table('reference_versions')->insert([
            ['version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
            ['version' => 'v2.0.0', 'activated_at' => '2024-06-01 00:00:00'],
            ['version' => 'v3.0.0', 'activated_at' => '2024-12-01 00:00:00'],
        ]);

        // Act
        $rows = $this->resolver()->loadAll();

        // Assert
        $this->assertCount(3, $rows, 'Should return all 3 rows regardless of activated_at');
        $this->assertSame('v1.0.0', $rows[0]['version'], 'First row should be earliest activated_at');
        $this->assertSame('v2.0.0', $rows[1]['version'], 'Second row in order');
        $this->assertSame('v3.0.0', $rows[2]['version'], 'Third row should be latest activated_at');
    }

    public function test_returns_empty_array_when_table_is_empty(): void
    {
        // Arrange — empty table
        // Act
        $rows = $this->resolver()->loadAll();

        // Assert
        $this->assertSame([], $rows, 'Should return empty array when no rows exist');
    }

    public function test_rows_contain_version_and_activated_at_keys(): void
    {
        // Arrange
        DB::table('reference_versions')->insert([
            ['version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);

        // Act
        $rows = $this->resolver()->loadAll();

        // Assert
        $this->assertArrayHasKey('version', $rows[0], 'Each row should have a version key');
        $this->assertArrayHasKey('activated_at', $rows[0], 'Each row should have an activated_at key');
        $this->assertSame('v1.0.0', $rows[0]['version'], 'version value should match');
        $this->assertSame('2024-01-01 00:00:00', $rows[0]['activated_at'], 'activated_at value should match');
    }

    public function test_uses_custom_column_names(): void
    {
        // Arrange
        DB::table('reference_versions')->insert([
            ['version' => 'v5.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);

        // Act
        $rows = $this->resolver(
            table: 'reference_versions',
            versionColumn: 'version',
            startAtColumn: 'activated_at',
        )->loadAll();

        // Assert
        $this->assertCount(1, $rows, 'Should return one row');
        $this->assertSame('v5.0.0', $rows[0]['version'], 'Should work with explicitly specified column names');
    }
}
