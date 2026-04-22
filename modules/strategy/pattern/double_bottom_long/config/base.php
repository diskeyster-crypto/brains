<?php

declare(strict_types=1);

/**
 * Double Bottom Long Strategy — Base Config
 *
 * Long-only module. All short-side parameters removed.
 * Override individual values in active.php without touching this file.
 */

return [
    // Core identity
    'strategy_id' => 'double_bottom_long',
    'enabled'     => false,
    'mode'        => 'passive',   // active | passive | disabled | smoke_demo
    'timeframe'   => 'H4',

    // Universe selection
    'universe_mode'    => 'all',
    'allowed_symbols'  => [],
    'excluded_symbols' => [],

    // Market regime gate
    'market_regime_enabled'   => true,
    'market_regime_gate_mode' => 'soft',  // soft = warn only; hard = block signal
    // Market regime classification thresholds
    'market_regime_min_sample_count'          => 5,
    'market_regime_dominance_ratio'           => 0.55,
    'market_regime_flat_dominance_ratio'      => 0.65,
    'market_regime_transition_flip_threshold' => 0.20,

    // Trend gate
    'trend_required' => true,

    // Corridor
    'corridor_required'       => true,
    'corridor_lookback_hours' => 24,
    'corridor_bucket_count'   => 10,
    'allowed_long_buckets'    => [1, 2, 3],

    // Wave
    'wave_required' => true,

    // Patterns enabled — long-only: double_bottom only
    'enabled_patterns' => ['double_bottom'],

    // Candidate quality filter
    'min_candidate_quality_score' => 0.62,

    // Neckline floor
    'min_neckline_score' => 0.45,

    // Pattern detection tolerances (long / double_bottom)
    'pattern_similarity_tolerance'              => 0.07,
    'double_bottom_similarity_tolerance_pct'    => 0.07,
    'double_bottom_min_neckline_bounce_pct'     => 0.005,

    // Neckline distance gate (long only)
    'neckline_distance_tolerance_pct'               => 0.02,
    'double_bottom_neckline_distance_tolerance_pct' => 0.02,

    // Confirmation
    'confirm_required' => true,
    'confirm_mode'     => 'candle_confirmation',
    'confirm_max_bars' => 2,

    // Signal lifetime
    'signal_ttl_bars'                    => 2,
    'max_active_signals_per_symbol_side' => 1,

    // Stop / trailing
    'stop_mode'                    => 'structure',
    'stop_from_liq_buffer_value'   => 0.002,
    'stop_from_liq_buffer_type'    => 'percent',   // absolute | percent
    'trailing_profile'             => 'oldbot_soft',
    'trailing_enabled'             => false,
    'reverse_pattern_close_enabled'=> false,
    'tp_enabled'                   => false,
    'tp_mode'                      => 'fixed_r',   // fixed_r | fixed_price
    'tp_value'                     => 2.0,

    // Bot execution parameters
    'bot_budget'   => 0.0,
    'bot_leverage' => 1,

    // Fibonacci extensions
    'fibo_enabled' => false,

    // Continuous scan
    'continuous_scan_enabled' => true,

    // Batching / scan run controls
    'batch_size'           => 50,
    'max_symbols_per_run'  => 0,
    'max_runtime_seconds'  => 55,

    // Candle data
    'lookback_candles'  => 120,
    'bybit_base_url'    => 'https://api.bybit.com',
    'bybit_timeout_sec' => 10,
];
