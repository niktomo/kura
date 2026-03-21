<?php

namespace Kura\Version;

use Kura\Contracts\VersionResolverInterface;
use Kura\Contracts\VersionsLoaderInterface;
use Psr\Clock\ClockInterface;

/**
 * Resolves the active version by caching all version rows in APCu.
 *
 * All rows (v1, v2, v3, ...) are stored in APCu with a TTL.
 * When APCu expires, rows are reloaded from the source (DB/CSV).
 * The active version is determined by applying activated_at <= now()
 * against the cached rows at resolve time.
 *
 * This means version transitions happen automatically as time passes,
 * without requiring a DB re-query during the APCu TTL window.
 *
 * Request-level consistency is provided by a PHP var cache that pins
 * the resolved version for the duration of a single request.
 */
final class CachedVersionResolver implements VersionResolverInterface
{
    private ?string $cachedVersion = null;

    public function __construct(
        private readonly VersionsLoaderInterface $inner,
        private readonly ClockInterface $clock,
        private readonly int $ttl = 3600,
        private readonly string $cacheKey = 'kura:reference_versions',
        private readonly bool $useApcu = true,
    ) {}

    public function resolve(): ?string
    {
        // PHP var: pin version for this request
        if ($this->cachedVersion !== null) {
            return $this->cachedVersion;
        }

        $rows = $this->fetchRows();
        $version = $this->resolveFromRows($rows);

        if ($version !== null) {
            $this->cachedVersion = $version;
        }

        return $version;
    }

    /**
     * Clear the PHP-var cache only, leaving APCu intact.
     *
     * Call this at request boundaries (e.g. Octane RequestReceived) to
     * ensure each request re-evaluates which version is active.
     */
    public function resetRequestCache(): void
    {
        $this->cachedVersion = null;
    }

    /**
     * Force clear both PHP var and APCu caches.
     */
    public function clearCache(): void
    {
        $this->cachedVersion = null;

        if ($this->useApcu) {
            apcu_delete($this->cacheKey);
        }
    }

    /**
     * Fetch all version rows: from APCu if available, otherwise from the source.
     *
     * @return list<array{version: string, activated_at: string}>
     */
    private function fetchRows(): array
    {
        if ($this->useApcu) {
            /** @var list<array{version: string, activated_at: string}>|false $rows */
            $rows = apcu_fetch($this->cacheKey, $success);

            if ($success && is_array($rows)) {
                return $rows;
            }
        }

        $rows = $this->inner->loadAll();

        if ($this->useApcu && $rows !== []) {
            apcu_store($this->cacheKey, $rows, $this->ttl);
        }

        return $rows;
    }

    /**
     * @param  list<array{version: string, activated_at: string}>  $rows
     */
    private function resolveFromRows(array $rows): ?string
    {
        $now = $this->clock->now();
        $resolved = null;
        $resolvedAt = null;

        foreach ($rows as $row) {
            $activatedAt = new \DateTimeImmutable($row['activated_at']);

            if ($activatedAt > $now) {
                continue;
            }

            if ($resolvedAt === null || $activatedAt > $resolvedAt) {
                $resolved = $row['version'];
                $resolvedAt = $activatedAt;
            }
        }

        return $resolved;
    }
}
