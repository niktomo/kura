<?php

namespace Kura\Tests\Version;

use DateTimeImmutable;
use Kura\Contracts\VersionsLoaderInterface;
use Kura\Tests\Support\FrozenClock;
use Kura\Version\CachedVersionResolver;
use PHPUnit\Framework\TestCase;

class CachedVersionResolverTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param  list<array{version: string, activated_at: string}>  $rows
     */
    private function makeLoader(array $rows): VersionsLoaderInterface&CountableLoader
    {
        return new CountableLoader($rows);
    }

    private function makeClock(string $now): FrozenClock
    {
        return new FrozenClock(new DateTimeImmutable($now));
    }

    /**
     * @param  list<array{version: string, activated_at: string}>  $rows
     */
    private function resolver(
        array $rows,
        string $now = '2024-09-01 00:00:00',
    ): CachedVersionResolver {
        return new CachedVersionResolver(
            inner: $this->makeLoader($rows),
            clock: $this->makeClock($now),
            useApcu: false,
        );
    }

    // -------------------------------------------------------------------------
    // Version filtering
    // -------------------------------------------------------------------------

    public function test_returns_latest_active_version(): void
    {
        // Arrange
        $resolver = $this->resolver([
            ['version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
            ['version' => 'v2.0.0', 'activated_at' => '2024-06-01 00:00:00'],
            ['version' => 'v3.0.0', 'activated_at' => '2024-12-01 00:00:00'],
        ], now: '2024-09-01 00:00:00');

        // Act
        $version = $resolver->resolve();

        // Assert — v3.0.0 is future, v2.0.0 is latest active
        $this->assertSame('v2.0.0', $version, 'Should return the latest version with activated_at <= now()');
    }

    public function test_returns_null_when_all_versions_are_future(): void
    {
        // Arrange
        $resolver = $this->resolver([
            ['version' => 'v1.0.0', 'activated_at' => '2025-01-01 00:00:00'],
        ], now: '2024-01-01 00:00:00');

        // Act
        $version = $resolver->resolve();

        // Assert
        $this->assertNull($version, 'Should return null when no version is active yet');
    }

    public function test_returns_null_when_rows_are_empty(): void
    {
        // Arrange
        $resolver = $this->resolver([]);

        // Act
        $version = $resolver->resolve();

        // Assert
        $this->assertNull($version, 'Should return null when there are no version rows');
    }

    public function test_version_becomes_active_exactly_at_activated_at(): void
    {
        // Arrange
        $resolver = $this->resolver([
            ['version' => 'v1.0.0', 'activated_at' => '2024-06-01 00:00:00'],
        ], now: '2024-06-01 00:00:00');

        // Act
        $version = $resolver->resolve();

        // Assert — boundary: activated_at == now is active
        $this->assertSame('v1.0.0', $version, 'Version should be active exactly at activated_at');
    }

    public function test_version_is_not_active_one_second_before_activated_at(): void
    {
        // Arrange
        $resolver = $this->resolver([
            ['version' => 'v1.0.0', 'activated_at' => '2024-06-01 00:00:00'],
        ], now: '2024-05-31 23:59:59');

        // Act
        $version = $resolver->resolve();

        // Assert
        $this->assertNull($version, 'Version should not be active before activated_at');
    }

    public function test_returns_latest_by_activated_at_regardless_of_row_order(): void
    {
        // Arrange — rows in reverse order
        $resolver = $this->resolver([
            ['version' => 'v2.0.0', 'activated_at' => '2024-06-01 00:00:00'],
            ['version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ], now: '2024-09-01 00:00:00');

        // Act
        $version = $resolver->resolve();

        // Assert — latest by activated_at, not by row order in array
        $this->assertSame('v2.0.0', $version, 'Should pick the version with the largest activated_at <= now()');
    }

    // -------------------------------------------------------------------------
    // Caching: loadAll() call count
    // -------------------------------------------------------------------------

    public function test_loadall_called_once_within_same_request(): void
    {
        // Arrange
        $loader = $this->makeLoader([
            ['version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $resolver = new CachedVersionResolver(
            inner: $loader,
            clock: $this->makeClock('2024-09-01 00:00:00'),
            useApcu: false,
        );

        // Act
        $resolver->resolve();
        $resolver->resolve();
        $resolver->resolve();

        // Assert — PHP var caches the resolved version; loadAll() called only once
        $this->assertSame(1, $loader->callCount, 'loadAll() should be called once; PHP var serves subsequent calls');
    }

    public function test_loadall_not_called_when_null_result_is_returned(): void
    {
        // Arrange — no active version
        $loader = $this->makeLoader([
            ['version' => 'v1.0.0', 'activated_at' => '2099-01-01 00:00:00'],
        ]);
        $resolver = new CachedVersionResolver(
            inner: $loader,
            clock: $this->makeClock('2024-01-01 00:00:00'),
            useApcu: false,
        );

        // Act
        $resolver->resolve();
        $resolver->resolve();

        // Assert — null is not cached in PHP var, so loadAll() is called each time
        $this->assertSame(2, $loader->callCount, 'null result is not cached; loadAll() called each resolve()');
    }

    // -------------------------------------------------------------------------
    // resetRequestCache
    // -------------------------------------------------------------------------

    public function test_reset_request_cache_causes_re_evaluation_on_next_resolve(): void
    {
        // Arrange
        $loader = $this->makeLoader([
            ['version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $resolver = new CachedVersionResolver(
            inner: $loader,
            clock: $this->makeClock('2024-09-01 00:00:00'),
            useApcu: false,
        );
        $resolver->resolve();

        // Act — simulate Octane request boundary
        $resolver->resetRequestCache();
        $version = $resolver->resolve();

        // Assert
        $this->assertSame('v1.0.0', $version, 'Should return the correct version after reset');
        $this->assertSame(2, $loader->callCount, 'loadAll() should be called again after resetRequestCache()');
    }

    public function test_reset_request_cache_does_not_clear_apcu(): void
    {
        // Arrange — APCu disabled; verify PHP var is cleared, source re-consulted
        $loader = $this->makeLoader([
            ['version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $resolver = new CachedVersionResolver(
            inner: $loader,
            clock: $this->makeClock('2024-09-01 00:00:00'),
            useApcu: false,
        );
        $resolver->resolve(); // call 1: loadAll() invoked, PHP var set
        $resolver->resetRequestCache(); // PHP var cleared
        $resolver->resolve(); // call 2: loadAll() invoked again
        $resolver->resolve(); // call 3: PHP var hit, loadAll() NOT invoked

        // Assert
        $this->assertSame(2, $loader->callCount, 'loadAll() called twice: initial + post-reset; third served from PHP var');
    }

    // -------------------------------------------------------------------------
    // clearCache
    // -------------------------------------------------------------------------

    public function test_clear_cache_forces_reload_from_source(): void
    {
        // Arrange
        $loader = $this->makeLoader([
            ['version' => 'v1.0.0', 'activated_at' => '2024-01-01 00:00:00'],
        ]);
        $resolver = new CachedVersionResolver(
            inner: $loader,
            clock: $this->makeClock('2024-09-01 00:00:00'),
            useApcu: false,
        );
        $resolver->resolve();

        // Act
        $resolver->clearCache();
        $resolver->resolve();

        // Assert
        $this->assertSame(2, $loader->callCount, 'clearCache() should force loadAll() to be called again');
    }
}

/**
 * @internal Test helper: VersionsLoaderInterface that counts loadAll() calls.
 */
class CountableLoader implements VersionsLoaderInterface
{
    public int $callCount = 0;

    /**
     * @param  list<array{version: string, activated_at: string}>  $rows
     */
    public function __construct(
        private readonly array $rows,
    ) {}

    /**
     * @return list<array{version: string, activated_at: string}>
     */
    public function loadAll(): array
    {
        $this->callCount++;

        return $this->rows;
    }
}
