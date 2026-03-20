<?php

return [
    /*
     * APCu key prefix.
     */
    'prefix' => 'kura',

    /*
     * TTL in seconds per cache type.
     *
     * ids is the rebuild trigger — its expiry causes the next query to rebuild.
     * index defaults to ids TTL (including jitter) when omitted, so they expire together.
     * meta and record are longer to survive across rebuilds.
     *
     * ids_jitter: random 0–N seconds added to both ids and index TTL to prevent thundering herd.
     */
    'ttl' => [
        'ids' => 3600,
        'record' => 4800,
        // 'index' — omit to match ids TTL (recommended), or set explicitly to override
        'ids_jitter' => 600,  // applied to both ids and index TTL
    ],

    /*
     * Rebuild lock TTL in seconds.
     * Set to 1.5–2x the expected Loader execution time.
     */
    'lock_ttl' => 60,

    /*
     * Rebuild strategy.
     *
     * 'sync'     — synchronous rebuild (no queue needed)
     * 'queue'    — async rebuild via queue job
     * 'callback' — custom callback (register via ServiceProvider)
     */
    'rebuild' => [
        'strategy' => 'sync',
        'queue' => [
            'connection' => null,
            'queue' => null,
            'retry' => 3,
        ],
    ],

    /*
     * Reference data version resolution.
     *
     * driver:    'database' — resolve from DB table
     * table:     DB table name for version records
     * columns:   column name mapping
     * cache_ttl: seconds to cache the resolved version in APCu (0 = no cache)
     */
    'version' => [
        'driver' => 'database',       // 'database' or 'csv'
        'table' => 'reference_versions',
        'columns' => [
            'version' => 'version',
            'activated_at' => 'activated_at',
        ],
        'csv_path' => '',              // path to versions.csv (for 'csv' driver)
        'cache_ttl' => 300,            // seconds to cache in APCu (0 = no cache)
    ],

    /*
     * Cache warm endpoint.
     *
     * enabled:           register the POST /kura/warm route
     * token:             Bearer token for authentication (required)
     * path:              URL path (default: kura/warm)
     * controller:        invokable controller for POST /kura/warm
     *                    publish with: php artisan vendor:publish --tag=kura-controllers
     * status_controller: invokable controller for GET /kura/warm/status/{batchId}
     */
    'warm' => [
        'enabled' => false,
        'token' => env('KURA_WARM_TOKEN', ''),
        'path' => 'kura/warm',
        'controller' => \Kura\Http\Controllers\WarmController::class,
        'status_controller' => \Kura\Http\Controllers\WarmStatusController::class,
    ],

    /*
     * CSV auto-discovery.
     *
     * When auto_discover is true, KuraServiceProvider scans base_path for subdirectories
     * and registers each as a CsvLoader table automatically.
     *
     * Directory layout:
     *   {base_path}/
     *     versions.csv          — shared version file (id, version, activated_at)
     *     {table}/
     *       data.csv            — required; directory is skipped if absent
     *       defines.csv         — column type definitions
     *       indexes.csv         — index definitions
     *
     * To override primary_key or TTL for a specific table, use the 'tables' section below.
     */
    'csv' => [
        'base_path' => '',
        'auto_discover' => false,
    ],

    /*
     * Per-table overrides.
     *
     * 'tables' => [
     *     'products' => [
     *         'ttl' => ['record' => 7200],
     *     ],
     * ],
     */
    'tables' => [],
];
