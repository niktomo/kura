<?php

namespace Kura\Loader;

use Kura\Contracts\VersionsLoaderInterface;

/**
 * Loads all version rows from a versions.csv file.
 *
 * versions.csv format:
 *   id,version,activated_at
 *   1,v1.0.0,2024-01-01 00:00:00
 */
final class CsvVersionResolver implements VersionsLoaderInterface
{
    public function __construct(
        private readonly string $versionsFilePath,
    ) {}

    /**
     * @return list<array{version: string, activated_at: string}>
     */
    public function loadAll(): array
    {
        if (! file_exists($this->versionsFilePath)) {
            return [];
        }

        $fp = fopen($this->versionsFilePath, 'r');
        if ($fp === false) {
            return [];
        }

        // Skip header row
        fgetcsv($fp, escape: '');

        $rows = [];

        while (($row = fgetcsv($fp, escape: '')) !== false) {
            if (count($row) < 3 || $row[2] === null) {
                continue;
            }

            $rows[] = [
                'version' => (string) $row[1],
                'activated_at' => (string) $row[2],
            ];
        }

        fclose($fp);

        return $rows;
    }
}
