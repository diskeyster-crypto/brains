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
    'regime_sample_size'                      => 'int',
    'regime_sample_lookback_candles'          => 'int',

    // Trend gate
    'trend_required'             => 'bool',
    'trend_long_require_bullish' => 'bool',

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

    // Pattern detection tolerances
    'pattern_similarity_tolerance'              => 'float',
    'double_bottom_similarity_tolerance_pct'    => 'float',
    'double_bottom_min_neckline_bounce_pct'     => 'float',
    'neckline_distance_tolerance_pct'               => 'float',
    'double_bottom_neckline_distance_tolerance_pct' => 'float',

    // Confirmation
    'confirm_required'  => 'bool',
    'confirm_mode'      => 'string',
    'confirm_max_bars'  => 'int',

    // Signal
    'signal_ttl_bars'                    => 'int',
    'max_active_signals_per_symbol_side' => 'int',

    // Stop / exit
    'stop_mode'                     => 'string',
    'stop_from_liq_buffer_value'    => 'float',
    'stop_from_liq_buffer_type'     => 'string',
    'stop_buffer_pct_below_lows'    => 'float',
    'max_stop_loss_pct'             => 'float',
    'reverse_pattern_close_enabled' => 'bool',
    'tp_enabled'                    => 'bool',
    'tp_mode'                       => 'string',
    'tp_value'                      => 'float',

    // Bot execution parameters
    'bot_budget'   => 'float',
    'bot_leverage' => 'int',
    'entry_mode'   => 'string',

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
