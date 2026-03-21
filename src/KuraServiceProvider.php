<?php

namespace Kura;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Kura\Console\RebuildCommand;
use Kura\Console\TokenCommand;
use Kura\Contracts\VersionResolverInterface;
use Kura\Http\Batch\BatchFinderInterface;
use Kura\Http\Batch\LaravelBatchFinder;
use Kura\Http\Controllers\WarmController;
use Kura\Http\Controllers\WarmStatusController;
use Kura\Http\Middleware\KuraAuthMiddleware;
use Kura\Jobs\RebuildCacheJob;
use Kura\Loader\CsvLoader;
use Kura\Loader\CsvVersionResolver;
use Kura\Store\ApcuStore;
use Kura\Store\StoreInterface;
use Kura\Version\CachedVersionResolver;
use Kura\Version\DatabaseVersionResolver;
use Kura\Version\SystemClock;
use Laravel\Octane\Events\RequestReceived;

class KuraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/kura.php', 'kura');

        $this->app->bind(BatchFinderInterface::class, LaravelBatchFinder::class);

        $this->app->singleton(StoreInterface::class, function ($app) {
            /** @var string $prefix */
            $prefix = $app['config']->get('kura.prefix', 'kura');

            return new ApcuStore($prefix);
        });

        $this->app->singleton(VersionResolverInterface::class, function ($app) {
            /** @var Repository $config */
            $config = $app->make('config');

            /** @var string $driver */
            $driver = $config->get('kura.version.driver', 'database');
            /** @var int $cacheTtl */
            $cacheTtl = $config->get('kura.version.cache_ttl', 300);

            $inner = match ($driver) {
                'database' => new DatabaseVersionResolver(
                    connection: $app['db']->connection(),
                    table: $config->get('kura.version.table', 'reference_versions'),
                    versionColumn: $config->get('kura.version.columns.version', 'version'),
                    startAtColumn: $config->get('kura.version.columns.activated_at', 'activated_at'),
                ),
                'csv' => new CsvVersionResolver(
                    versionsFilePath: $config->get('kura.version.csv_path', ''),
                ),
                default => throw new \InvalidArgumentException("Unknown version driver: {$driver}"),
            };

            return new CachedVersionResolver(
                inner: $inner,
                clock: new SystemClock,
                ttl: $cacheTtl,
                useApcu: $cacheTtl > 0,
            );
        });

        $this->app->singleton(KuraManager::class, function ($app) {
            /** @var array{ids?: int, record?: int, index?: int, ids_jitter?: int} $ttl */
            $ttl = $app['config']->get('kura.ttl', []);
            /** @var int $lockTtl */
            $lockTtl = $app['config']->get('kura.lock_ttl', 60);
            /** @var array<string, array{ttl?: array{ids?: int, record?: int, index?: int, ids_jitter?: int}}> $tableConfigs */
            $tableConfigs = $app['config']->get('kura.tables', []);

            return new KuraManager(
                store: $app->make(StoreInterface::class),
                defaultTtl: $ttl,
                lockTtl: $lockTtl,
                rebuildDispatcher: $this->buildRebuildDispatcher(),
                tableConfigs: $tableConfigs,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/kura.php' => config_path('kura.php'),
            ], 'kura-config');

            $this->publishes([
                __DIR__.'/../stubs/WarmController.php' => app_path('Http/Controllers/Kura/WarmController.php'),
                __DIR__.'/../stubs/WarmStatusController.php' => app_path('Http/Controllers/Kura/WarmStatusController.php'),
            ], 'kura-controllers');

            $this->commands([RebuildCommand::class, TokenCommand::class]);
        }

        $this->registerWarmRoute();
        $this->autoDiscoverCsvTables();
        $this->registerOctaneListeners();
    }

    /**
     * Scan base_path for table subdirectories and register each as a CsvLoader.
     *
     * Only directories that contain data.csv are registered.
     * versions.csv is shared at base_path/versions.csv.
     * Per-table primary_key can be overridden via config('kura.tables.{table}.primary_key').
     */
    private function autoDiscoverCsvTables(): void
    {
        /** @var Repository $config */
        $config = $this->app->make('config');

        if (! $config->get('kura.csv.auto_discover', false)) {
            return;
        }

        /** @var string $basePath */
        $basePath = $config->get('kura.csv.base_path', '');

        if ($basePath === '' || ! is_dir($basePath)) {
            return;
        }

        $resolver = $this->app->make(VersionResolverInterface::class);

        $manager = $this->app->make(KuraManager::class);
        /** @var array<string, array{primary_key?: string}> $tableOverrides */
        $tableOverrides = $config->get('kura.tables', []);

        $entries = scandir($basePath);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $tableDir = $basePath.'/'.$entry;

            if (! is_dir($tableDir) || ! file_exists($tableDir.'/data.csv')) {
                continue;
            }

            $primaryKey = $tableOverrides[$entry]['primary_key'] ?? 'id';

            $manager->register(
                $entry,
                function () use ($tableDir, $resolver): CsvLoader {
                    return new CsvLoader(
                        tableDirectory: $tableDir,
                        resolver: $resolver,
                    );
                },
                $primaryKey,
            );
        }
    }

    /**
     * Register Octane request lifecycle listeners to reset per-request state.
     *
     * In a persistent process (Octane, RoadRunner), singletons survive across
     * requests. We reset the PHP-var version cache and KuraManager state at the
     * start of each request so that versionOverride and cached repositories do
     * not bleed from one request into the next.
     */
    private function registerOctaneListeners(): void
    {
        if (! class_exists(RequestReceived::class)) {
            return;
        }

        $this->app->make(Dispatcher::class)->listen(
            RequestReceived::class,
            function (): void {
                $resolver = $this->app->make(VersionResolverInterface::class);
                if ($resolver instanceof CachedVersionResolver) {
                    $resolver->resetRequestCache();
                }
                $this->app->make(KuraManager::class)->resetForRequest();
            },
        );
    }

    private function registerWarmRoute(): void
    {
        /** @var Repository $config */
        $config = $this->app->make('config');

        if (! $config->get('kura.warm.enabled', false)) {
            return;
        }

        /** @var string $path */
        $path = $config->get('kura.warm.path', 'kura/warm');

        /** @var class-string $warmController */
        $warmController = $config->get('kura.warm.controller', WarmController::class);

        /** @var class-string $statusController */
        $statusController = $config->get('kura.warm.status_controller', WarmStatusController::class);

        Route::post($path, $warmController)
            ->middleware(KuraAuthMiddleware::class)
            ->name('kura.warm');

        Route::get($path.'/status/{batchId}', $statusController)
            ->middleware(KuraAuthMiddleware::class)
            ->name('kura.warm.status');
    }

    /**
     * Build the rebuild dispatcher Closure based on config strategy.
     *
     * sync:     null (CacheProcessor falls back to synchronous rebuild)
     * queue:    dispatches RebuildCacheJob
     * callback: user registers their own via config (not built here)
     *
     * @return (\Closure(CacheRepository): void)|null
     */
    private function buildRebuildDispatcher(): ?\Closure
    {
        /** @var Repository $config */
        $config = $this->app->make('config');

        /** @var string $strategy */
        $strategy = $config->get('kura.rebuild.strategy', 'sync');

        if ($strategy === 'callback') {
            /** @var callable(CacheRepository): void|null $callback */
            $callback = $config->get('kura.rebuild.callback');

            if ($callback === null) {
                throw new \InvalidArgumentException(
                    "kura.rebuild.strategy is 'callback' but kura.rebuild.callback is not set.",
                );
            }

            return \Closure::fromCallable($callback);
        }

        if ($strategy !== 'queue') {
            return null;
        }

        /** @var string|null $connection */
        $connection = $config->get('kura.rebuild.queue.connection');
        /** @var string|null $queue */
        $queue = $config->get('kura.rebuild.queue.queue');
        /** @var int $retry */
        $retry = $config->get('kura.rebuild.queue.retry', 3);

        return function (CacheRepository $repository) use ($connection, $queue, $retry): void {
            $job = new RebuildCacheJob($repository->table());
            $job->tries = $retry;

            if ($connection !== null) {
                $job->onConnection($connection);
            }
            if ($queue !== null) {
                $job->onQueue($queue);
            }

            dispatch($job);
        };
    }
}
