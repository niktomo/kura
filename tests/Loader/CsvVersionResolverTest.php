<?php

namespace Kura\Tests\Loader;

use Kura\Loader\CsvVersionResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests (AAA format) for CsvVersionResolver.
 *
 * versions.csv format:  id,version,activated_at
 * loadAll() returns all rows without filtering — filtering is done by CachedVersionResolver.
 */
class CsvVersionResolverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/kura_version_test_'.uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir.'/*') ?: []);
        rmdir($this->tmpDir);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @param list<array{id: int, version: string, activated_at: string}> $rows */
    private function writeVersionsCsv(array $rows): void
    {
        $path = $this->tmpDir.'/versions.csv';
        $fp = fopen($path, 'w');
        assert($fp !== false);
        fputcsv($fp, ['id', 'version', 'activated_at'], escape: '');
        foreach ($rows as $row) {
            fputcsv($fp, [$row['id'], $row['version'], $row['activated_at']], escape: '');
        }
        fclose($fp);
    }

    private function resolver(): CsvVersionResolver
    {
        return new CsvVersionResolver($this->tmpDir.'/versions.csv');
    }

    // =========================================================================
    // loadAll
    // =========================================================================

    public function test_returns_all_rows(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
            ['id' => 2, 'version' => 'v1.1.0', 'activated_at' => '2024-03-01 00:00:00'],
            ['id' => 3, 'version' => 'v2.0.0', 'activated_at' => '2024-07-01 00:00:00'],
        ]);

        // Act
        $rows = $this->resolver()->loadAll();

        // Assert — all rows returned regardless of activated_at
        $this->assertCount(3, $rows, 'Should return all 3 rows without filtering');
        $this->assertSame('v1.0.0', $rows[0]['version'], 'First row version should match');
        $this->assertSame('v2.0.0', $rows[2]['version'], 'Third row version should match');
    }

    public function test_rows_contain_version_and_activated_at_keys(): void
    {
        // Arrange
        $this->writeVersionsCsv([
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);

        // Act
        $rows = $this->resolver()->loadAll();

        // Assert
        $this->assertArrayHasKey('version', $rows[0], 'Each row should have a version key');
        $this->assertArrayHasKey('activated_at', $rows[0], 'Each row should have an activated_at key');
        $this->assertSame('v1.0.0', $rows[0]['version'], 'version value should match');
        $this->assertSame('2024-01-01 00:00:00', $rows[0]['activated_at'], 'activated_at value should match');
    }

    public function test_returns_empty_array_for_header_only_csv(): void
    {
        // Arrange — header only, no data rows
        $this->writeVersionsCsv([]);

        // Act
        $rows = $this->resolver()->loadAll();

        // Assert
        $this->assertSame([], $rows, 'Should return empty array when CSV has no data rows');
    }

    public function test_returns_empty_array_when_file_does_not_exist(): void
    {
        // Arrange — resolver pointing at non-existent file
        $resolver = new CsvVersionResolver($this->tmpDir.'/nonexistent.csv');

        // Act
        $rows = $resolver->loadAll();

        // Assert
        $this->assertSame([], $rows, 'Should return empty array when file is missing');
    }

    public function test_rows_preserve_file_order(): void
    {
        // Arrange — rows stored in reverse id order
        $this->writeVersionsCsv([
            ['id' => 3, 'version' => 'v2.0.0', 'activated_at' => '2024-09-01 00:00:00'],
            ['id' => 1, 'version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
            ['id' => 2, 'version' => 'v1.1.0', 'activated_at' => '2024-04-01 00:00:00'],
        ]);

        // Act
        $rows = $this->resolver()->loadAll();

        // Assert — file order preserved, no sorting
        $this->assertSame('v2.0.0', $rows[0]['version'], 'First row in file order');
        $this->assertSame('v1.0.0', $rows[1]['version'], 'Second row in file order');
        $this->assertSame('v1.1.0', $rows[2]['version'], 'Third row in file order');
    }
}
