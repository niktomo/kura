<?php

declare(strict_types=1);

/**
 * Kura Benchmark
 *
 * Measures query performance across dataset sizes and query patterns.
 * Requires APCu (apc.enable_cli=1).
 *
 * Usage:
 *   php benchmarks/benchmark.php
 *   php benchmarks/benchmark.php --size=10000
 *   php benchmarks/benchmark.php --size=1000,10000,100000 --iterations=200
 */

require_once __DIR__.'/../vendor/autoload.php';

use Kura\CacheProcessor;
use Kura\CacheRepository;
use Kura\Loader\LoaderInterface;
use Kura\ReferenceQueryBuilder;
use Kura\Store\ApcuStore;

// ---------------------------------------------------------------------------
// CLI options
// ---------------------------------------------------------------------------

$opts = getopt('', ['size:', 'iterations:']);
$sizes = array_map('intval', explode(',', $opts['size'] ?? '1000,10000,100000'));
$iterations = (int) ($opts['iterations'] ?? 500);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @param  list<array<string, mixed>>  $records
 * @param  list<array{columns: list<string>, unique: bool}>  $indexes
 */
function buildRepository(
    string $prefix,
    string $table,
    array $records,
    array $indexes,
): CacheRepository {
    $store = new ApcuStore(prefix: $prefix);
    $loader = new class($records, $indexes) implements LoaderInterface
    {
        /** @param list<array<string, mixed>> $records */
        /** @param list<array{columns: list<string>, unique: bool}> $indexes */
        public function __construct(
            private readonly array $records,
            private readonly array $indexes,
        ) {}

        public function load(): Generator
        {
            yield from $this->records;
        }

        public function columns(): array
        {
            return [];
        }

        public function indexes(): array
        {
            return $this->indexes;
        }

        public function version(): string
        {
            return 'bench';
        }

        public function primaryKey(): string
        {
            return 'id';
        }
    };

    $repo = new CacheRepository(
        table: $table,
        primaryKey: 'id',
        store: $store,
        loader: $loader,
    );
    $repo->rebuild();

    return $repo;
}

/**
 * Return a fresh ReferenceQueryBuilder from a pre-built repository.
 * ReferenceQueryBuilder is stateful — always create a fresh instance per query.
 */
function q(CacheRepository $repo, string $table): ReferenceQueryBuilder
{
    $store = new ApcuStore(prefix: 'unused');

    return new ReferenceQueryBuilder(
        table: $table,
        repository: $repo,
        processor: new CacheProcessor($repo, $repo->store()),
    );
}

/**
 * Run a closure N times and return [min, avg, p95, max] in microseconds.
 *
 * @return array{min: float, avg: float, max: float, p95: float}
 */
function measure(callable $fn, int $n): array
{
    $times = [];
    for ($i = 0; $i < $n; $i++) {
        $t = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $t) / 1_000; // ns → µs
    }
    sort($times);

    return [
        'min' => $times[0],
        'avg' => array_sum($times) / count($times),
        'p95' => $times[(int) floor(count($times) * 0.95)],
        'max' => $times[count($times) - 1],
    ];
}

function fmt(float $us): string
{
    if ($us < 1_000) {
        return sprintf('%6.1f µs', $us);
    }

    return sprintf('%6.2f ms', $us / 1_000);
}

function printRow(string $label, array $m): void
{
    printf(
        "  %-42s  min:%s  avg:%s  p95:%s  max:%s\n",
        $label,
        fmt($m['min']),
        fmt($m['avg']),
        fmt($m['p95']),
        fmt($m['max']),
    );
}

// ---------------------------------------------------------------------------
// Data generation
// ---------------------------------------------------------------------------

$countries = ['JP', 'US', 'GB', 'DE', 'FR'];
$categories = ['electronics', 'clothing', 'food', 'books', 'sports',
    'toys', 'furniture', 'tools', 'beauty', 'music'];

/**
 * @return list<array<string, mixed>>
 */
function generateRecords(int $n): array
{
    global $countries, $categories;

    $records = [];
    for ($i = 1; $i <= $n; $i++) {
        $records[] = [
            'id' => $i,
            'name' => 'Product '.$i,
            'country' => $countries[$i % count($countries)],
            'category' => $categories[$i % count($categories)],
            'price' => round(($i % 200) + 0.99, 2),
            'active' => $i % 3 !== 0,
        ];
    }

    return $records;
}

$indexes = [
    ['columns' => ['country'],             'unique' => false],
    ['columns' => ['price'],               'unique' => false],
    ['columns' => ['country', 'category'], 'unique' => false],
];

// ---------------------------------------------------------------------------
// Run benchmarks
// ---------------------------------------------------------------------------

$sep = str_repeat('-', 80);

echo "\n";
echo "Kura Benchmark\n";
echo 'PHP '.PHP_VERSION.'  |  APCu '.phpversion('apcu').'  |  '.php_uname('m')."\n";
echo "Iterations per scenario: {$iterations}\n";
echo $sep."\n";

foreach ($sizes as $size) {
    $records = generateRecords($size);
    $t = 'products';
    $middleId = (int) ($size / 2);
    $prefix = 'bench_'.$size.'_'.uniqid();

    echo "\n";
    echo "Dataset: {$size} records\n";
    echo $sep."\n";

    $rebuildStart = hrtime(true);
    $repo = buildRepository($prefix, $t, $records, $indexes);
    $rebuildMs = (hrtime(true) - $rebuildStart) / 1_000_000;
    printf("  Cache build time: %.2f ms\n", $rebuildMs);

    // 1. Full scan — get all
    $m = measure(fn () => q($repo, $t)->get(), $iterations);
    printRow("get() all ({$size} records)", $m);

    // 2. where (indexed, =)  — ~20% selectivity
    $m = measure(fn () => q($repo, $t)->where('country', 'JP')->get(), $iterations);
    printRow("where('country','JP')  [indexed =]", $m);

    // 3. where (non-indexed) — ~67% selectivity
    $m = measure(fn () => q($repo, $t)->where('active', true)->get(), $iterations);
    printRow("where('active',true)   [non-indexed]", $m);

    // 4. whereBetween narrow range
    $m = measure(fn () => q($repo, $t)->whereBetween('price', [50, 100])->get(), $iterations);
    printRow("whereBetween('price',[50,100]) [range/narrow]", $m);

    // 5. whereBetween wide range
    $m = measure(fn () => q($repo, $t)->whereBetween('price', [1, 180])->get(), $iterations);
    printRow("whereBetween('price',[1,180])  [range/wide]", $m);

    // 6. Composite index — AND equality
    $m = measure(
        fn () => q($repo, $t)->where('country', 'JP')->where('category', 'electronics')->get(),
        $iterations,
    );
    printRow('where country+category  [composite]', $m);

    // 7. OR — both branches indexed
    $m = measure(
        fn () => q($repo, $t)->where('country', 'JP')->orWhere('country', 'US')->get(),
        $iterations,
    );
    printRow('where JP orWhere US  [OR indexed]', $m);

    // 8. OR — one branch not indexed
    $m = measure(
        fn () => q($repo, $t)->where('country', 'JP')->orWhere('active', true)->get(),
        $iterations,
    );
    printRow('where JP orWhere active  [OR partial]', $m);

    // 9. orderBy indexed column — index walk
    $m = measure(fn () => q($repo, $t)->where('country', 'JP')->orderBy('price')->get(), $iterations);
    printRow("where+orderBy('price')  [index walk]", $m);

    // 10. orderBy non-indexed column — PHP sort fallback
    $m = measure(fn () => q($repo, $t)->where('country', 'JP')->orderBy('name')->get(), $iterations);
    printRow("where+orderBy('name')   [PHP sort]", $m);

    // 11. find — single record
    $m = measure(fn () => q($repo, $t)->find($middleId), $iterations);
    printRow("find({$middleId})  [single record]", $m);

    // 12. count
    $m = measure(fn () => q($repo, $t)->where('country', 'JP')->count(), $iterations);
    printRow("where('country','JP')->count()", $m);
}

// ===========================================================================
// Extra 1: whereIn — scaling with list size
// ===========================================================================

apcu_clear_cache();

echo "\n";
echo "═══ Extra: whereIn — scaling with IN list size ═══\n";
echo $sep."\n";

$size = max($sizes);
$records = generateRecords($size);
$prefix = 'wherein_'.$size.'_'.uniqid();
$t = 'products';
$repo = buildRepository($prefix, $t, $records, $indexes);

echo "\n";
echo "Dataset: {$size} records\n";
echo $sep."\n";

// country has 5 values: IN with 1, 2, 3, 5 values (indexed)
foreach ([1, 2, 3, 5] as $cnt) {
    $vals = array_slice(['JP', 'US', 'GB', 'DE', 'FR'], 0, $cnt);
    $list = implode(',', $vals);
    $m = measure(function () use ($repo, $t, $vals): array {
        return q($repo, $t)->whereIn('country', $vals)->get();
    }, $iterations);
    printRow("whereIn('country', [{$list}])  [indexed, {$cnt} vals]", $m);
}

// whereIn on non-indexed column (id subset — sequential IDs)
foreach ([10, 100, 1000] as $cnt) {
    $ids = range(1, $cnt);
    $m = measure(function () use ($repo, $t, $ids): array {
        return q($repo, $t)->whereIn('id', $ids)->get();
    }, $iterations);
    printRow("whereIn('id', [1..{$cnt}])  [non-indexed, {$cnt} vals]", $m);
}

// ===========================================================================
// Extra 2: paginate — page 1 vs deep pages
// ===========================================================================

apcu_clear_cache();

echo "\n";
echo "═══ Extra: paginate — page depth comparison ═══\n";
echo $sep."\n";

foreach ($sizes as $size) {
    $records = generateRecords($size);
    $prefix = 'page_'.$size.'_'.uniqid();
    $t = 'products';
    $repo = buildRepository($prefix, $t, $records, $indexes);

    $lastPage = (int) ceil($size / 20);

    echo "\n";
    echo "Dataset: {$size} records  (page size=20, last page={$lastPage})\n";
    echo $sep."\n";

    // Unsorted paginate (no orderBy) — offset-based, no sort cost
    $m = measure(fn () => q($repo, $t)->paginate(20, page: 1), $iterations);
    printRow('paginate(20) page=1       [no sort]', $m);

    $m = measure(function () use ($repo, $t, $lastPage) {
        return q($repo, $t)->paginate(20, page: $lastPage);
    }, $iterations);
    printRow("paginate(20) page={$lastPage}  [no sort, deep]", $m);

    // Sorted paginate via index walk — limit allows early exit
    $m = measure(fn () => q($repo, $t)->orderBy('price')->paginate(20, page: 1), $iterations);
    printRow('orderBy->paginate(20) page=1  [index walk]', $m);

    $m = measure(function () use ($repo, $t, $lastPage) {
        return q($repo, $t)->orderBy('price')->paginate(20, page: $lastPage);
    }, $iterations);
    printRow("orderBy->paginate(20) page={$lastPage}  [index walk, deep]", $m);
}

// ===========================================================================
// Extra 3: whereRowValuesIn — full scan vs composite index
// ===========================================================================

apcu_clear_cache();

echo "\n";
echo "═══ Extra: whereRowValuesIn — full scan vs composite index ═══\n";
echo $sep."\n";

$size = max($sizes);
$records = generateRecords($size);
$t = 'products';

// Build without composite index (full scan path)
$prefixNoIdx = 'rvi_noscan_'.$size.'_'.uniqid();
$repoNoIdx = buildRepository($prefixNoIdx, $t, $records, [
    ['columns' => ['country'],             'unique' => false],
    ['columns' => ['category'],            'unique' => false],
]);

// Build with composite index on (country, category)
$prefixCidx = 'rvi_cidx_'.$size.'_'.uniqid();
$repoCidx = buildRepository($prefixCidx, $t, $records, [
    ['columns' => ['country'],             'unique' => false],
    ['columns' => ['category'],            'unique' => false],
    ['columns' => ['country', 'category'], 'unique' => false],
]);

echo "\n";
echo "Dataset: {$size} records\n";
echo $sep."\n";

// Tuple lists of varying sizes
$tupleScenarios = [
    [1,  [['JP', 'electronics']]],
    [3,  [['JP', 'electronics'], ['US', 'books'], ['GB', 'food']]],
    [10, array_map(
        fn ($i) => [['JP', 'US', 'GB', 'DE', 'FR'][$i % 5], ['electronics', 'clothing', 'food', 'books', 'sports', 'toys', 'furniture', 'tools', 'beauty', 'music'][$i]],
        range(0, 9)
    )],
];

foreach ($tupleScenarios as [$cnt, $tuples]) {
    $m = measure(function () use ($repoNoIdx, $t, $tuples): array {
        return q($repoNoIdx, $t)->whereRowValuesIn(['country', 'category'], $tuples)->get();
    }, $iterations);
    printRow("whereRowValuesIn  {$cnt} tuples  [no cidx, full scan]", $m);

    $m = measure(function () use ($repoCidx, $t, $tuples): array {
        return q($repoCidx, $t)->whereRowValuesIn(['country', 'category'], $tuples)->get();
    }, $iterations);
    printRow("whereRowValuesIn  {$cnt} tuples  [cidx, hashmap]", $m);
}

echo "\n";
echo $sep."\n";
echo "Done.\n\n";
