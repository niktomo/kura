<?php

namespace Kura\Version;

use Illuminate\Database\ConnectionInterface;
use Kura\Contracts\VersionsLoaderInterface;

/**
 * Loads all version rows from a database table.
 *
 * Table structure (example: reference_versions):
 *   id           INT PRIMARY KEY
 *   version      VARCHAR  — e.g. "v2.1.0"
 *   activated_at DATETIME — when this version becomes active
 */
final class DatabaseVersionResolver implements VersionsLoaderInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table = 'reference_versions',
        private readonly string $versionColumn = 'version',
        private readonly string $startAtColumn = 'activated_at',
    ) {}

    /**
     * @return list<array{version: string, activated_at: string}>
     */
    public function loadAll(): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->table($this->table)
            ->orderBy($this->startAtColumn)
            ->get([$this->versionColumn, $this->startAtColumn])
            ->all();

        return array_map(fn ($row) => [
            'version' => (string) $row->{$this->versionColumn},
            'activated_at' => (string) $row->{$this->startAtColumn},
        ], $rows);
    }
}
