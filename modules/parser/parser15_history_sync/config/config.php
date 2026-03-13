<?php
/**
 * Parser 1.5 History Sync - Configuration
 * 
 * Gap repair and backfill settings for Parser2 history
 */

return [
    'enabled' => true,

    'sources' => [
        'parser1_active_key' => 'parser.parser1_market_registry.storage',
        'parser2_history_key' => 'parser.parser2_history_accumulator.storage',
    ],

    'sync' => [
        'interval_sec' => 120,           // Run every 2 minutes
        'gap_threshold_sec' => 180,      // 3 minutes gap triggers repair
        'max_backfill_minutes' => 60,    // Max 1 hour backfill per request
    ],

    'bybit' => [
        'base_url' => 'https://api.bybit.com',
        'endpoint' => '/v5/market/kline',
        'interval' => '1',               // 1 minute candles
        'timeout_sec' => 10,
        'max_points_per_request' => 1000,
    ],

    'output' => [
        'state' => 'storage/state.json',
        'last_run' => 'storage/last_run.json',
        'log' => 'storage/logs/sync.log',
        'stats' => 'storage/stats.json',
    ],
];
