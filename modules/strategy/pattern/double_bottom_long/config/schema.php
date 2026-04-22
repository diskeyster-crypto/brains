<?php

declare(strict_types=1);

/**
 * Double Bottom Long Strategy — Config Schema
 *
 * Long-only module. Short-side keys removed.
 * Used by DoubleBottomLongBootstrap to validate config on every startup.
 */

return [
    // Core identity
    'strategy_id'  => 'string',
    'enabled'      => 'bool',
    'mode'         => 'string',
    'timeframe'    => 'string',

    // Universe
    'universe_mode'    => 'string',
    'allowed_symbols'  => 'array',
    'excluded_symbols' => 'array',

    // Market regime gate
    'market_regime_enabled'   => 'bool',
    'market_regime_gate_mode' => 'string',
    'market_regime_min_sample_count'          => 'int',
    'market_regime_dominance_ratio'           => 'float',
    'market_regime_flat_dominance_ratio'      => 'float',
    'market_regime_transition_flip_threshold' => 'float',

    // Trend gate
    'trend_required' => 'bool',

    // Corridor
    'corridor_required'       => 'bool',
    'corridor_lookback_hours' => 'int',
    'corridor_bucket_count'   => 'int',
    'allowed_long_buckets'    => 'array',

    // Wave
    'wave_required' => 'bool',

    // Patterns
    'enabled_patterns' => 'array',

    // Candidate quality filter
    'min_candidate_quality_score' => 'float',
    'min_neckline_score'          => 'float',

    // Confirmation
    'confirm_required'  => 'bool',
    'confirm_mode'      => 'string',
    'confirm_max_bars'  => 'int',

    // Signal
    'signal_ttl_bars'                    => 'int',
    'max_active_signals_per_symbol_side' => 'int',

    // Stop / trailing
    'stop_mode'                     => 'string',
    'stop_from_liq_buffer_value'    => 'float',
    'stop_from_liq_buffer_type'     => 'string',
    'trailing_profile'              => 'string',
    'trailing_enabled'              => 'bool',
    'reverse_pattern_close_enabled' => 'bool',
    'tp_enabled'                    => 'bool',
    'tp_mode'                       => 'string',
    'tp_value'                      => 'float',

    // Bot execution parameters
    'bot_budget'   => 'float',
    'bot_leverage' => 'int',

    // Fibonacci
    'fibo_enabled' => 'bool',

    // Continuous scan
    'continuous_scan_enabled' => 'bool',

    // Batching
    'batch_size'           => 'int',
    'max_symbols_per_run'  => 'int',
    'max_runtime_seconds'  => 'int',

    // Candle data
    'lookback_candles'  => 'int',
    'bybit_base_url'    => 'string',
    'bybit_timeout_sec' => 'int',
];
