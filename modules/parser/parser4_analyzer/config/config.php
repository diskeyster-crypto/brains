<?php
declare(strict_types=1);

/**
 * Tredercopis — Parser4 Analyzer (Two-Contour)
 *
 * CONFIG FIRST / ZERO-HARDCODE
 * SystemPaths ONLY.
 *
 * @return array<string,mixed>
 */
return [

    /* ======================================================
       CORE
       ====================================================== */

    'enabled' => true,
    'debug'   => false,

    /* ======================================================
       ACTIVE PROFILE
       ====================================================== */

    // 'light' | 'medium' | 'high'
    'profile' => 'medium',

    /* ======================================================
       SENSITIVITY PROFILES
       ====================================================== */

    'profiles' => [
        'light' => [
            'thresholds' => [
                'block_std_return'   => 0.07,
                'watch_std_return'   => 0.05,
                'pump_abs_return'    => 0.005,
                'min_abs_return'     => 0.002,
                'confirm_abs_return' => 0.003, // informational only
            ],
            'limits' => [
                'max_candidates'          => 400,
                'max_candidates_per_side' => 100,
            ],
            'min_score'        => 0.10,
            'min_volatility'   => 0.002,
            'min_volume_delta' => 1.05,
            'min_impulse'      => 0.15,
        ],

        'medium' => [
            'thresholds' => [
                'block_std_return'   => 0.05,
                'watch_std_return'   => 0.03,
                'pump_abs_return'    => 0.015,
                'min_abs_return'     => 0.005,
                'confirm_abs_return' => 0.008, // informational only
            ],
            'limits' => [
                'max_candidates'          => 500,
                'max_candidates_per_side' => 500,
            ],
            'min_score'        => 0.25,
            'min_volatility'   => 0.005,
            'min_volume_delta' => 1.20,
            'min_impulse'      => 0.30,
        ],

        'high' => [
            'thresholds' => [
                'block_std_return'   => 0.04,
                'watch_std_return'   => 0.02,
                'pump_abs_return'    => 0.025,
                'min_abs_return'     => 0.01,
                'confirm_abs_return' => 0.015, // informational only
            ],
            'limits' => [
                'max_candidates'          => 40,
                'max_candidates_per_side' => 20,
            ],
            'min_score'        => 0.40,
            'min_volatility'   => 0.01,
            'min_volume_delta' => 1.50,
            'min_impulse'      => 0.50,
        ],
    ],

    /* ======================================================
       INPUT SOURCES (SystemPaths KEYS ONLY)
       ====================================================== */

    'sources' => [
        'history_key'  => 'parser.parser2_history_accumulator.storage',
        'profiles_key' => 'parser.parser3_manager_behavior.storage',
        'module_key'   => 'parser.parser4_analyzer',
    ],

    /* ======================================================
       ROLLING WINDOWS
       ====================================================== */

    'rolling_windows' => [2,3,4,5,8,10,13,15,20,25,30,40,50,60,120,180,240,360,480,720,1440],

    'window_groups' => [
        'block' => [20,25,30,40,50,60],
        'watch' => [8,10,13,15,20],
        'pump'  => [2,3,4,5,8,10],
    ],

    // informational only
    'confirm_windows' => [8, 10],

    // false => watchlist can still be candidates (marked in meta)
    'watch_is_terminal' => false,

    /* ======================================================
       SCORE WEIGHTS
       ====================================================== */

    'score_weights' => [
        'abs_return' => 2.0,
        'confirm'    => 1.0,
        'std'        => 0.5,
    ],

    /* ======================================================
       MINIMUM DATA REQUIREMENTS
       ====================================================== */

    'min_history_points'    => 10,
    'min_points_per_window' => 3,

    /* ======================================================
       HISTORY READING
       ====================================================== */

    'history' => [
        // 0 = UNLIMITED (read all *.ndjson in symbol dir)
        'days_back' => 0,
        'max_points_per_symbol' => 5000,
    ],

    /* ======================================================
       OUTPUT (RELATIVE, INSIDE MODULE ONLY)
       ====================================================== */

    'output' => [
        'blocklist'  => 'storage/blocklist.json',
        'watchlist'  => 'storage/watchlist.json',
        'candidates' => 'storage/candidates.json',
        'last_run'   => 'storage/last_run.json',
        'errors'     => 'storage/errors.json',
        'log'        => 'storage/logs/analyzer.log',
    ],
];
