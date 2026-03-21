<?php

namespace Kura\Loader;

use Illuminate\Database\Query\Builder;
use Kura\Contracts\VersionResolverInterface;

/**
 * Loads records from a database table via Laravel's Query Builder.
 *
 * Usage:
 *   new QueryBuilderLoader(
 *       query: DB::table('products')->where('active', true),
 *       columns: ['id' => 'int', 'name' => 'string', 'price' => 'int'],
 *       indexDefinitions: [['columns' => ['category'], 'unique' => false]],
 *       resolver: app(VersionResolverInterface::class),
 *   )
 *
 * The query is executed via cursor() for low memory usage.
 *
 * Note: Unlike CsvLoader, this loader does NOT filter rows by version.
 * Version-based data scoping must be handled by the query itself
 * (e.g. ->where('version', $resolver->resolve())).
 * The resolver is used solely to determine the APCu cache key.
 */
final class QueryBuilderLoader implements LoaderInterface
{
    /**
     * @param  array<string, string>  $columns  column => type
     * @param  list<array{columns: list<string>, unique: bool}>  $indexDefinitions
     */
    public function __construct(
        private readonly Builder $query,
        private readonly array $columns = [],
        private readonly array $indexDefinitions = [],
        private readonly VersionResolverInterface $resolver = new StaticVersionResolver('v1'),
    ) {}

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function load(): \Generator
    {
        $index = 0;

        foreach ($this->query->cursor() as $row) {
            yield $index++ => (array) $row;
        }
    }

    /** @return array<string, string> */
    public function columns(): array
    {
        return $this->columns;
    }

    /** @return list<array{columns: list<string>, unique: bool}> */
    public function indexes(): array
    {
        return $this->indexDefinitions;
    }

    public function version(): string
    {
        return $this->resolver->resolve() ?? '';
    }
}
