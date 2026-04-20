<?php

declare(strict_types=1);

/**
 * Fish Strategy — Config Schema (v1)
 *
 * Defines allowed config keys and their expected PHP types.
 * Used by FishBootstrap to validate config on every startup.
 * Adding a new key here is mandatory before using it anywhere in logic.
 */

return [
    // Core identity
    'strategy_id'      => 'string',
    'enabled'          => 'bool',
    'mode'             => 'string',   // active | passive | disabled

    // Timeframe
    'timeframe'        => 'string',   // H4

    // Universe — v1 supports: all | manual_list
    'universe_mode'    => 'string',
    'allowed_symbols'  => 'array',    // used when universe_mode = manual_list
    'excluded_symbols' => 'array',    // always applied

    // Trading window (single window only in v1)
    'window_enabled'   => 'bool',
    'window_start'     => 'string',   // HH:MM
    'window_end'       => 'string',   // HH:MM

    // Execution parameters
    'budget'           => 'float',    // supports decimal amounts (e.g. 250.50)
    'leverage'         => 'int',

    // Profile references
    'sl_profile'       => 'string',
    'pm_profile'       => 'string',

    // Ownership contract
    'owner_strategy'   => 'string',

    // Strategy-specific logic parameters
    'structure_pivot_window'          => 'int',
    'liquidity_pattern_min_bars'      => 'int',
    'liquidity_pattern_max_bars'      => 'int',
    'liquidity_level_tolerance'       => 'float',
    'confirm_bar_required'            => 'bool',
    'level_max_age_bars'              => 'int',
    'tp_multiplier'                   => 'float',
    'breakeven_trigger_multiplier'    => 'float',
    'lookback_candles'                => 'int',
    'bybit_base_url'                  => 'string',
    'bybit_timeout_sec'               => 'int',

    // Batched scan / smoke-test run controls
    'batch_size'                      => 'int',
    'max_symbols_per_run'             => 'int',
    'max_runtime_seconds'             => 'int',

    // Risk / reward geometry validation
    'min_rr_ratio'                    => 'float',

    // Fish bot execution runtime
    'bot_enabled'        => 'bool',
    'execution_mode'     => 'string',  // smoke | demo | live
    'bot_budget'         => 'float',
    'bot_leverage'       => 'int',
    'bot_sl_profile'     => 'string',
    'bot_pm_profile'     => 'string',
];
