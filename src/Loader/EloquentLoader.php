<?php

namespace Kura\Loader;

use Illuminate\Database\Eloquent\Builder;
use Kura\Contracts\VersionResolverInterface;

/**
 * Loads records from an Eloquent model/query.
 *
 * Usage:
 *   new EloquentLoader(
 *       query: Product::query()->where('active', true),
 *       tableDirectory: base_path('kura/products'),
 *       resolver: app(VersionResolverInterface::class),
 *   )
 *
 * Column types and index definitions are read from:
 *   {tableDirectory}/defines.csv   — column,type,description
 *   {tableDirectory}/indexes.csv   — columns,unique
 *
 * Records are converted to arrays via toArray().
 * The query is executed via cursor() for low memory usage.
 *
 * Note: Unlike CsvLoader, this loader does NOT filter rows by version.
 * Version-based data scoping must be handled by the query itself.
 * The resolver is used solely to determine the APCu cache key.
 */
final class EloquentLoader implements LoaderInterface
{
    private readonly TableDefinitionReader $definitions;

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    public function __construct(
        private readonly Builder $query,
        string $tableDirectory,
        private readonly VersionResolverInterface $resolver = new StaticVersionResolver('v1'),
    ) {
        $this->definitions = new TableDefinitionReader($tableDirectory);
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function load(): \Generator
    {
        $index = 0;

        foreach ($this->query->cursor() as $model) {
            yield $index++ => $model->toArray();
        }
    }

    /** @return array<string, string> */
    public function columns(): array
    {
        return $this->definitions->columns();
    }

    /** @return list<array{columns: list<string>, unique: bool}> */
    public function indexes(): array
    {
        return $this->definitions->indexes();
    }

    public function version(): string
    {
        return $this->resolver->resolve() ?? '';
    }
}
