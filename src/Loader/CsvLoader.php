<?php

namespace Kura\Loader;

use Kura\Contracts\VersionResolverInterface;

/**
 * Loads records from a single data.csv file with version-based filtering.
 *
 * Directory layout:
 *   {tableDirectory}/
 *     data.csv      — all rows with a 'version' column
 *     defines.csv   — column,type,description
 *     indexes.csv   — columns,unique  (optional)
 *
 * Loading rule:
 *   version IS NULL (empty)  → always loaded (shared across all versions)
 *   version <= activeVersion → loaded (current and past version rows)
 *   version > activeVersion  → skipped (future version rows not yet active)
 *
 * Supported column types: int, float, bool, string (default)
 */
final class CsvLoader implements LoaderInterface
{
    private readonly TableDefinitionReader $definitions;

    public function __construct(
        private readonly string $tableDirectory,
        private readonly VersionResolverInterface $resolver,
        private readonly string $versionColumn = 'version',
    ) {
        $this->definitions = new TableDefinitionReader($tableDirectory);
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function load(): \Generator
    {
        $activeVersion = $this->resolver->resolve();
        if ($activeVersion === null) {
            return;
        }

        $dataFile = $this->tableDirectory.'/data.csv';
        if (! file_exists($dataFile)) {
            return;
        }

        $types = $this->definitions->columns();

        $fp = fopen($dataFile, 'r');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open data file: {$dataFile}");
        }

        try {
            $headers = fgetcsv($fp, escape: '');
            if ($headers === false) {
                return;
            }

            $versionIndex = array_search($this->versionColumn, $headers, true);
            if ($versionIndex === false) {
                return;
            }

            $index = 0;
            while (($row = fgetcsv($fp, escape: '')) !== false) {
                $rowVersion = isset($row[$versionIndex]) && $row[$versionIndex] !== ''
                    ? $row[$versionIndex]
                    : null;

                // Skip rows whose version is set and greater than the active version
                if ($rowVersion !== null && version_compare($rowVersion, $activeVersion, '>')) {
                    continue;
                }

                $record = [];
                foreach ($headers as $i => $column) {
                    $value = isset($row[$i]) && $row[$i] !== '' ? $row[$i] : null;
                    $record[$column] = $this->cast($value, $types[$column] ?? 'string');
                }

                yield $index++ => $record;
            }
        } finally {
            fclose($fp);
        }
    }

    /** @return array<string, string> */
    public function columns(): array
    {
        return $this->definitions->columns();
    }

    /**
     * @return list<array{columns: list<string>, unique: bool}>
     */
    public function indexes(): array
    {
        return $this->definitions->indexes();
    }

    public function version(): string
    {
        return $this->resolver->resolve() ?? '';
    }

    private function cast(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => $value === '1' || $value === 'true',
            default => (string) $value,
        };
    }
}
