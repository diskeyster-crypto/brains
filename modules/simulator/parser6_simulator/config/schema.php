<?php
declare(strict_types=1);

/**
 * Parser 6: Simulator — Configuration
 *
 * ALL paths and parameters defined here. ZERO HARDCODE.
 *
 * PROFILES:
 * - light:  Wider evaluation windows
 * - medium: Balanced (default)
 * - high:   Strict evaluation criteria
 */
return [
    'enabled' => true,

    // -------------------------------------------------------
    // ACTIVE PROFILE: 'light' | 'medium' | 'high'
    // -------------------------------------------------------
    'profile' => 'medium',

    // -------------------------------------------------------
    // SENSITIVITY PROFILES
    // -------------------------------------------------------
    'profiles' => [
        // LIGHT — wider tolerance
        'light' => [
            'evaluation_window_minutes' => 15,
            'flat_min_move_pct' => 0.002,          // 0.2%
            'early_drawdown_pct' => 0.01,          // 1%
            'late_threshold_pct' => 0.7,
            'max_signals_per_tick' => 20,
        ],

        // MEDIUM — balanced (DEFAULT)
        'medium' => [
            'evaluation_window_minutes' => 10,
            'flat_min_move_pct' => 0.003,          // 0.3%
            'early_drawdown_pct' => 0.008,         // 0.8%
            'late_threshold_pct' => 0.6,
            'max_signals_per_tick' => 10,
        ],

        // HIGH — strict evaluation
        'high' => [
            'evaluation_window_minutes' => 5,
            'flat_min_move_pct' => 0.005,          // 0.5%
            'early_drawdown_pct' => 0.005,         // 0.5%
            'late_threshold_pct' => 0.5,
            'max_signals_per_tick' => 5,
        ],
    ],

    // -------------------------------------------------------
    // INPUT SOURCES
    // -------------------------------------------------------
    'sources' => [
        // Parser5 signals file (main input)
        'signals_key' => 'parser.parser5_signal_monitor.storage',
        'signals_filename' => 'signals.json',
    ],

    // -------------------------------------------------------
    // BYBIT API SETTINGS (Public Market Data)
    // -------------------------------------------------------
    'bybit' => [
        'base_url' => 'https://api.bybit.com',
        'kline_endpoint' => '/v5/market/kline',
        'category' => 'linear',
        'interval' => '1',                         // 1-minute candles
        'timeout' => 10,                           // HTTP timeout in seconds
        'max_retries' => 2,
    ],

    // -------------------------------------------------------
    // DEDUPLICATION
    // -------------------------------------------------------
    'dedup' => [
        // Maximum number of signal IDs to remember
        'max_evaluated_ids' => 1000,
        // Re-evaluate signal if older than N minutes (0 = never re-evaluate)
        're_eval_after_minutes' => 0,
    ],

    // -------------------------------------------------------
    // OUTPUT PATHS
    // -------------------------------------------------------
    'output' => [
        'ticks' => 'simulator/ticks.ndjson',
        'last_run' => 'simulator/last_run.json',
        'errors' => 'simulator/errors.json',
        'evaluated' => 'simulator/evaluated.json',
        'results_dir' => 'results',
    ],

    // -------------------------------------------------------
    // LOGGING
    // -------------------------------------------------------
    'logging' => [
        'max_errors' => 100,
        'debug' => false,
    ],

    // -------------------------------------------------------
    // UI FIELDS FOR PARSER MANAGER
    // -------------------------------------------------------
    'ui' => [
        'fields' => [
            [
                'key' => 'parser',
                'label' => 'PARSER',
                'type' => 'parser_name',
                'source' => ['_title', '_module'],
            ],
            [
                'key' => 'status',
                'label' => 'STATUS',
                'type' => 'status_badge',
                'source' => ['_enabled', 'status'],
            ],
            [
                'key' => 'last_run',
                'label' => 'LAST RUN',
                'type' => 'datetime',
                'source' => ['ts'],
            ],
            [
                'key' => 'duration',
                'label' => 'DURATION',
                'type' => 'duration_ms',
                'source' => ['duration_ms'],
            ],
            [
                'key' => 'signals_seen',
                'label' => 'SEEN',
                'type' => 'count',
                'source' => ['signals_seen'],
            ],
            [
                'key' => 'signals_evaluated',
                'label' => 'EVALUATED',
                'type' => 'count_success',
                'source' => ['signals_evaluated'],
            ],
            [
                'key' => 'errors',
                'label' => 'ERRORS',
                'type' => 'count_danger',
                'source' => ['errors'],
            ],
        ],
    ],
];


/* RULES (Tredercopis Architecture)
--------------------------------------------------
- CONFIG FIRST / ZERO-HARDCODE.
- No physical paths inside config.
- Inter-parser sources must be SystemPaths keys.
-------------------------------------------------- */
