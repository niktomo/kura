<?php

namespace Kura\Loader;

/**
 * Reads column type definitions and index definitions from CSV files.
 *
 * Expected files in the table directory:
 *   defines.csv   — column,type,description
 *   indexes.csv   — columns,unique
 *
 * Results are cached per instance (read once, reused).
 */
final class TableDefinitionReader
{
    /** @var array<string, string>|null */
    private ?array $columns = null;

    /** @var list<array{columns: list<string>, unique: bool}>|null */
    private ?array $indexes = null;

    public function __construct(private readonly string $tableDirectory) {}

    /**
     * @return array<string, string> column => type
     */
    public function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $file = $this->tableDirectory.'/defines.csv';

        if (! file_exists($file)) {
            return $this->columns = [];
        }

        $fp = fopen($file, 'r');
        if ($fp === false) {
            return $this->columns = [];
        }

        fgetcsv($fp, escape: ''); // skip header

        $types = [];
        while (($row = fgetcsv($fp, escape: '')) !== false) {
            if (count($row) >= 2 && $row[0] !== null && $row[1] !== null) {
                $types[$row[0]] = $row[1];
            }
        }

        fclose($fp);

        return $this->columns = $types;
    }

    /**
     * @return list<array{columns: list<string>, unique: bool}>
     */
    public function indexes(): array
    {
        if ($this->indexes !== null) {
            return $this->indexes;
        }

        $file = $this->tableDirectory.'/indexes.csv';

        if (! file_exists($file)) {
            return $this->indexes = [];
        }

        $fp = fopen($file, 'r');
        if ($fp === false) {
            return $this->indexes = [];
        }

        fgetcsv($fp, escape: ''); // skip header

        $indexes = [];
        while (($row = fgetcsv($fp, escape: '')) !== false) {
            if (count($row) < 2 || $row[0] === null || $row[1] === null) {
                continue;
            }

            $columns = array_values(array_filter(
                explode('|', $row[0]),
                fn (string $c) => $c !== '',
            ));

            if ($columns === []) {
                continue;
            }

            $indexes[] = [
                'columns' => $columns,
                'unique' => $row[1] === 'true',
            ];
        }

        fclose($fp);

        return $this->indexes = $indexes;
    }
}
