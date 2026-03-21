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
 *       columns: ['id' => 'int', 'name' => 'string', 'price' => 'int'],
 *       indexDefinitions: [['columns' => ['category'], 'unique' => false]],
 *       resolver: app(VersionResolverInterface::class),
 *   )
 *
 * Records are converted to arrays via toArray().
 * The query is executed via cursor() for low memory usage.
 *
 * Note: Unlike CsvLoader, this loader does NOT filter rows by version.
 * Version-based data scoping must be handled by the query itself
 * (e.g. ->where('version', $resolver->resolve())).
 * The resolver is used solely to determine the APCu cache key.
 */
final class EloquentLoader implements LoaderInterface
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
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

        foreach ($this->query->cursor() as $model) {
            yield $index++ => $model->toArray();
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
