<?php

namespace Kura\Http\Controllers;

use Illuminate\Bus\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Bus;
use Kura\Jobs\RebuildCacheJob;
use Kura\KuraManager;

class WarmController extends Controller
{
    /**
     * Rebuild cache for specified tables (or all registered tables).
     *
     * POST /kura/warm
     * POST /kura/warm?tables=products,categories
     * POST /kura/warm?version=v2.0.0
     *
     * When rebuild.strategy = 'queue': dispatches one RebuildCacheJob per table
     * and returns immediately with batch_id for status tracking.
     *
     * When rebuild.strategy = 'sync' (or 'callback'): rebuilds sequentially
     * in the same request and returns final status.
     */
    public function __invoke(Request $request, KuraManager $manager): JsonResponse
    {
        /** @var string|null $version */
        $version = $request->query('version');

        if ($version !== null && $version !== '') {
            $manager->setVersionOverride($version);
        }

        /** @var string|null $tablesParam */
        $tablesParam = $request->query('tables');
        $tables = ($tablesParam !== null && $tablesParam !== '')
            ? explode(',', $tablesParam)
            : $manager->registeredTables();

        if ($tables === []) {
            return new JsonResponse(['message' => 'No tables registered.', 'tables' => []], 200);
        }

        /** @var string $strategy */
        $strategy = config('kura.rebuild.strategy', 'sync');

        if ($strategy === 'queue') {
            return $this->dispatchBatch($manager, $tables, $version);
        }

        return $this->rebuildSync($manager, $tables);
    }

    /**
     * Dispatch one RebuildCacheJob per table as a Bus batch.
     * Returns immediately — workers process tables in parallel.
     *
     * @param  list<string>  $tables
     */
    private function dispatchBatch(KuraManager $manager, array $tables, ?string $version): JsonResponse
    {
        /** @var string|null $connection */
        $connection = config('kura.rebuild.queue.connection');
        /** @var string|null $queue */
        $queue = config('kura.rebuild.queue.queue');

        $jobs = array_map(
            fn (string $table) => (new RebuildCacheJob($table, $version))
                ->onConnection($connection)
                ->onQueue($queue),
            $tables,
        );

        $batch = Bus::batch($jobs)->dispatch();

        $tableVersions = [];
        foreach ($tables as $table) {
            $tableVersions[$table] = ['status' => 'dispatched', 'version' => $manager->repository($table)->version()];
        }

        return new JsonResponse([
            'message' => 'Rebuild dispatched.',
            'batch_id' => $batch->id,
            'tables' => $tableVersions,
        ], 202);
    }

    /**
     * Rebuild all tables synchronously in the current request.
     *
     * @param  list<string>  $tables
     */
    private function rebuildSync(KuraManager $manager, array $tables): JsonResponse
    {
        $results = [];

        foreach ($tables as $table) {
            try {
                $repo = $manager->repository($table);
                $manager->rebuild($table);
                $results[$table] = ['status' => 'ok', 'version' => $repo->version()];
            } catch (\Throwable $e) {
                $results[$table] = ['status' => 'error', 'message' => $e->getMessage()];
            }
        }

        $hasError = in_array('error', array_column($results, 'status'), true);

        return new JsonResponse([
            'message' => $hasError ? 'Some tables failed.' : 'All tables warmed.',
            'tables' => $results,
        ], $hasError ? 500 : 200);
    }
}
