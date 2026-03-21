<?php

namespace Kura\Contracts;

interface VersionsLoaderInterface
{
    /**
     * Load all version rows from the source (DB, CSV, etc.).
     *
     * @return list<array{version: string, activated_at: string}>
     */
    public function loadAll(): array;
}
