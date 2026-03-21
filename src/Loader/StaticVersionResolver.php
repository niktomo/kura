<?php

namespace Kura\Loader;

use Kura\Contracts\VersionResolverInterface;

/**
 * Resolves a fixed version string.
 *
 * Useful for simple setups where version management is not needed,
 * or for tests where a predictable version string is required.
 *
 * Usage:
 *   new StaticVersionResolver('v1.0.0')
 */
final class StaticVersionResolver implements VersionResolverInterface
{
    public function __construct(private readonly string $version) {}

    public function resolve(): string
    {
        return $this->version;
    }
}
