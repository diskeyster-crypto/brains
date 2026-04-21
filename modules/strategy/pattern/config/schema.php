<?php

declare(strict_types=1);

/**
 * Pattern Strategy — Config Schema
 *
 * Defines allowed config keys and their expected PHP types.
 * Used by PatternBootstrap to validate config on every startup.
 */

return [
    // Core identity
    'strategy_id'  => 'string',
    'enabled'      => 'bool',
    'mode'         => 'string',   // active | passive | disabled | smoke_demo
    'timeframe'    => 'string',   // H4

    // Universe
    'universe_mode'    => 'string',
    'allowed_symbols'  => 'array',
    'excluded_symbols' => 'array',

    // Side mode
    'side_mode'    => 'string',   // long_only | short_only | both

    // Market regime gate
    'market_regime_enabled'   => 'bool',
    'market_regime_gate_mode' => 'string',  // soft | hard
    // Market regime classification thresholds
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
    'allowed_short_buckets'   => 'array',

    // Wave
    'wave_required' => 'bool',

    // Patterns
    'enabled_patterns' => 'array',

    // Candidate quality filter
    'min_candidate_quality_score' => 'float',
    'min_neckline_score'          => 'float',

    // Confirmation
    'confirm_required'  => 'bool',
    'confirm_mode'      => 'string',  // candle_confirmation
    'confirm_max_bars'  => 'int',

    // Signal
    'signal_ttl_bars'                    => 'int',
    'max_active_signals_per_symbol_side' => 'int',

    // Stop / trailing
    'stop_mode'        => 'string',   // structure
    'trailing_profile' => 'string',

    // Fibonacci
    'fibo_enabled' => 'bool',

    // Batching
    'batch_size'           => 'int',
    'max_symbols_per_run'  => 'int',
    'max_runtime_seconds'  => 'int',

    // Candle data
    'lookback_candles'  => 'int',
    'bybit_base_url'    => 'string',
    'bybit_timeout_sec' => 'int',
];
