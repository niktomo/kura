<?php

namespace Kura\Tests\Loader;

use DateTimeImmutable;
use Kura\Loader\CsvLoader;
use Kura\Loader\CsvVersionResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests (AAA format) for CsvLoader.
 *
 * Directory layout:
 *   {tableDir}/
 *     data.csv      — rows with a 'version' column
 *     defines.csv   — column,type,description
 *
 * Loading rule:
 *   version IS NULL (empty)  → always loaded
 *   version <= activeVersion → loaded
 *   version > activeVersion  → skipped
 */
class CsvLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/kura_csvloader_test_'.uniqid();
        mkdir($this->tmpDir.'/products', recursive: true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    private function removeDirectory(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $item) {
            is_dir($item) ? $this->removeDirectory($item) : unlink($item);
        }
        rmdir($dir);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @param list<array{id: int, version: string, activated_at: string}> $rows */
    private function writeVersionsCsv(array $rows): void
    {
        $fp = fopen($this->tmpDir.'/versions.csv', 'w');
        assert($fp !== false);
        fputcsv($fp, ['id', 'version', 'activated_at'], escape: '');
        foreach ($rows as $row) {
            fputcsv($fp, [$row['id'], $row['version'], $row['activated_at']], escape: '');
        }
        fclose($fp);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $fp = fopen($path, 'w');
        assert($fp !== false);
        fputcsv($fp, $headers, escape: '');
        foreach ($rows as $row) {
            fputcsv($fp, $row, escape: '');
        }
        fclose($fp);
    }

    private function makeResolver(DateTimeImmutable $now): CsvVersionResolver
    {
        return new CsvVersionResolver(
            $this->tmpDir.'/versions.csv',
            $now,
        );
    }

    // =========================================================================
    // Version filtering
    // =========================================================================

    public function test_loads_rows_matching_active_version(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v2.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $this->writeCsv($this->tmpDir.'/products/defines.csv',
            ['column', 'type', 'description'],
            [['id', 'int', 'PK'], ['name', 'string', 'Name'], ['version', 'string', 'Ver']],
        );
        $this->writeCsv($this->tmpDir.'/products/data.csv',
            ['id', 'name', 'version'],
            [
                [1, 'Alpha', 'v1.0.0'],   // past — loaded
                [2, 'Beta',  'v2.0.0'],   // current — loaded
                [3, 'Gamma', 'v3.0.0'],   // future — skipped
            ],
        );

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-06-01')),
        );

        // Act
        $records = iterator_to_array($loader->load(), false);

        // Assert — past + current loaded, future skipped
        $this->assertCount(2, $records);
        $names = array_column($records, 'name');
        $this->assertSame(['Alpha', 'Beta'], $names);
    }

    public function test_null_version_rows_are_always_loaded(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $this->writeCsv($this->tmpDir.'/products/defines.csv',
            ['column', 'type', 'description'],
            [['id', 'int', 'PK'], ['name', 'string', 'Name'], ['version', 'string', 'Ver']],
        );
        $this->writeCsv($this->tmpDir.'/products/data.csv',
            ['id', 'name', 'version'],
            [
                [1, 'Shared A', ''],         // null version — always loaded
                [2, 'Shared B', ''],         // null version — always loaded
                [3, 'Versioned', 'v1.0.0'],  // current — loaded
                [4, 'Future',    'v2.0.0'],  // future — skipped
            ],
        );

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-06-01')),
        );

        // Act
        $records = iterator_to_array($loader->load(), false);

        // Assert — null rows + current loaded; future skipped
        $this->assertCount(3, $records);
        $names = array_column($records, 'name');
        $this->assertSame(['Shared A', 'Shared B', 'Versioned'], $names);
    }

    public function test_active_version_is_the_latest_activated_one(): void
    {
        // Arrange — two versions registered; v1.1.0 is the active one at query time
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
            ['id' => 2, 'version' => 'v1.1.0', 'activated_at' => '2024-06-01 00:00:00'],
        ]);
        $this->writeCsv($this->tmpDir.'/products/defines.csv',
            ['column', 'type', 'description'],
            [['id', 'int', 'PK'], ['name', 'string', 'Name'], ['version', 'string', 'Ver']],
        );
        $this->writeCsv($this->tmpDir.'/products/data.csv',
            ['id', 'name', 'version'],
            [
                [1, 'Old Widget', 'v1.0.0'],   // past — loaded
                [2, 'New Widget', 'v1.1.0'],   // current — loaded
                [3, 'Next',       'v2.0.0'],   // future — skipped
            ],
        );

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-07-01')),
        );

        // Act
        $records = iterator_to_array($loader->load(), false);

        // Assert — v1.0.0 (past) + v1.1.0 (current) loaded
        $this->assertCount(2, $records);
        $names = array_column($records, 'name');
        $this->assertSame(['Old Widget', 'New Widget'], $names);
    }

    // =========================================================================
    // Type casting
    // =========================================================================

    public function test_casts_types_according_to_defines(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $this->writeCsv($this->tmpDir.'/products/defines.csv',
            ['column', 'type', 'description'],
            [
                ['id',      'int',    'PK'],
                ['price',   'float',  'Price'],
                ['active',  'bool',   'Active'],
                ['name',    'string', 'Name'],
                ['version', 'string', 'Ver'],
            ],
        );
        $this->writeCsv($this->tmpDir.'/products/data.csv',
            ['id', 'price', 'active', 'name', 'version'],
            [['1', '9.99', '1', 'Widget', 'v1.0.0']],
        );

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-06-01')),
        );

        // Act
        $record = iterator_to_array($loader->load(), false)[0];

        // Assert
        $this->assertSame(1, $record['id']);
        $this->assertSame(9.99, $record['price']);
        $this->assertTrue($record['active']);
        $this->assertSame('Widget', $record['name']);
    }

    public function test_empty_field_becomes_null(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $this->writeCsv($this->tmpDir.'/products/defines.csv',
            ['column', 'type', 'description'],
            [['id', 'int', 'PK'], ['note', 'string', 'Note'], ['version', 'string', 'Ver']],
        );
        $this->writeCsv($this->tmpDir.'/products/data.csv',
            ['id', 'note', 'version'],
            [[1, '', 'v1.0.0']],
        );

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-06-01')),
        );

        // Act
        $record = iterator_to_array($loader->load(), false)[0];

        // Assert
        $this->assertNull($record['note'], 'Empty CSV field should become null');
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function test_yields_nothing_when_no_version_is_active(): void
    {
        // Arrange — version starts in the future
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2025-01-01 00:00:00'],
        ]);
        $this->writeCsv($this->tmpDir.'/products/defines.csv',
            ['column', 'type', 'description'],
            [['id', 'int', 'PK'], ['version', 'string', 'Ver']],
        );

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-01-01')),
        );

        // Act / Assert
        $this->assertCount(0, iterator_to_array($loader->load(), false));
    }

    public function test_yields_nothing_when_data_csv_is_missing(): void
    {
        // Arrange — version resolved but data.csv does not exist
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $this->writeCsv($this->tmpDir.'/products/defines.csv',
            ['column', 'type', 'description'],
            [['id', 'int', 'PK']],
        );
        // data.csv intentionally not created

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-06-01')),
        );

        // Act / Assert
        $this->assertCount(0, iterator_to_array($loader->load(), false));
    }

    public function test_yields_nothing_when_version_column_is_absent(): void
    {
        // Arrange — data.csv has no version column
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $this->writeCsv($this->tmpDir.'/products/defines.csv',
            ['column', 'type', 'description'],
            [['id', 'int', 'PK']],
        );
        $this->writeCsv($this->tmpDir.'/products/data.csv',
            ['id', 'name'],   // no version column
            [[1, 'Widget']],
        );

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-06-01')),
        );

        // Act / Assert — version column required
        $this->assertCount(0, iterator_to_array($loader->load(), false));
    }

    // =========================================================================
    // indexes()
    // =========================================================================

    public function test_indexes_loads_from_indexes_csv(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $this->writeCsv($this->tmpDir.'/products/indexes.csv',
            ['columns', 'unique'],
            [['name', 'false'], ['code', 'false']],
        );

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-06-01')),
        );

        // Act
        $indexes = $loader->indexes();

        // Assert — both indexes loaded from CSV
        $this->assertCount(2, $indexes, 'indexes() should return 2 definitions from indexes.csv');
        $this->assertSame(['name'], $indexes[0]['columns'], 'First index should be on name column');
        $this->assertFalse($indexes[0]['unique'], 'name index should not be unique');
        $this->assertSame(['code'], $indexes[1]['columns'], 'Second index should be on code column');
    }

    public function test_indexes_csv_with_composite_columns(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $this->writeCsv($this->tmpDir.'/products/indexes.csv',
            ['columns', 'unique'],
            [['country|type', 'false']],
        );

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-06-01')),
        );

        // Act
        $indexes = $loader->indexes();

        // Assert — pipe-separated columns become a composite index
        $this->assertCount(1, $indexes, 'Should have one composite index');
        $this->assertSame(
            ['country', 'type'],
            $indexes[0]['columns'],
            'Pipe-separated columns should be split into a list',
        );
        $this->assertFalse($indexes[0]['unique'], 'Composite index should not be unique');
    }

    public function test_indexes_csv_with_unique_index(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $this->writeCsv($this->tmpDir.'/products/indexes.csv',
            ['columns', 'unique'],
            [['email', 'true']],
        );

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-06-01')),
        );

        // Act
        $indexes = $loader->indexes();

        // Assert — unique=true is correctly parsed
        $this->assertCount(1, $indexes, 'Should have one unique index');
        $this->assertTrue($indexes[0]['unique'], 'unique=true in CSV should result in unique:true');
    }

    public function test_constructor_index_definitions_take_precedence_over_csv(): void
    {
        // Arrange — both constructor arg and indexes.csv exist
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $this->writeCsv($this->tmpDir.'/products/indexes.csv',
            ['columns', 'unique'],
            [['from_csv', 'false']],
        );

        $constructorIndexes = [['columns' => ['from_constructor'], 'unique' => true]];
        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-06-01')),
            indexDefinitions: $constructorIndexes,
        );

        // Act
        $indexes = $loader->indexes();

        // Assert — constructor arg wins over indexes.csv
        $this->assertCount(1, $indexes, 'Should use constructor definitions, not CSV');
        $this->assertSame(
            ['from_constructor'],
            $indexes[0]['columns'],
            'Constructor indexDefinitions should take precedence over indexes.csv',
        );
    }

    public function test_indexes_returns_empty_when_no_csv_and_no_constructor_arg(): void
    {
        // Arrange — no indexes.csv, no constructor arg
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        // indexes.csv intentionally not created

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-06-01')),
        );

        // Act
        $indexes = $loader->indexes();

        // Assert
        $this->assertSame([], $indexes, 'indexes() should return empty array when neither source exists');
    }

    public function test_indexes_csv_is_read_only_once_per_instance(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $this->writeCsv($this->tmpDir.'/products/indexes.csv',
            ['columns', 'unique'],
            [['name', 'false']],
        );

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-06-01')),
        );

        // Act — call twice, then overwrite the file to verify result is cached
        $first = $loader->indexes();
        file_put_contents($this->tmpDir.'/products/indexes.csv', "columns,unique\nmodified,true\n");
        $second = $loader->indexes();

        // Assert — second call returns cached result, not re-read from disk
        $this->assertSame(
            $first,
            $second,
            'indexes() should return cached result without re-reading the file',
        );
        $this->assertSame(['name'], $first[0]['columns'], 'Should reflect original CSV, not modified version');
    }

    public function test_load_is_a_generator(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $this->writeCsv($this->tmpDir.'/products/defines.csv',
            ['column', 'type', 'description'],
            [['id', 'int', 'PK'], ['version', 'string', 'Ver']],
        );
        $this->writeCsv($this->tmpDir.'/products/data.csv',
            ['id', 'version'],
            [[1, 'v1.0.0']],
        );

        $loader = new CsvLoader(
            tableDirectory: $this->tmpDir.'/products',
            resolver: $this->makeResolver(new DateTimeImmutable('2024-06-01')),
        );

        // Act / Assert
        $this->assertInstanceOf(\Generator::class, $loader->load());
    }
}
