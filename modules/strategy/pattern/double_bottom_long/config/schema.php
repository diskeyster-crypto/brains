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

    // OrderBook wall context entry gate
    'orderbook_entry_wall_gate_enabled'              => 'bool',
    'orderbook_entry_wall_gate_mode'                 => 'string',
    'orderbook_entry_wall_fetch_after_quality_score' => 'float',
    'orderbook_entry_wall_near_pct'                  => 'float',
    'orderbook_entry_wall_persistent_required'       => 'bool',
    'orderbook_entry_wall_support_bonus_enabled'     => 'bool',
    'orderbook_entry_wall_ask_eaten_bonus_enabled'   => 'bool',
    'orderbook_entry_wall_pending_enabled'           => 'bool',
    'orderbook_entry_wall_soft_demote_blocks_handoff' => 'bool',

    // Handoff signal freshness gates
    'handoff_signal_max_age_minutes'           => 'int',
    'signal_requires_current_run_for_handoff'  => 'bool',
    'require_revalidation_after_symbol_block'  => 'bool',

    // Scan suppression cache
    'scan_suppression_enabled'             => 'bool',
    'scan_suppression_default_ttl_minutes' => 'int',
    'scan_suppression_short_ttl_minutes'   => 'int',
    'scan_suppression_medium_ttl_minutes'  => 'int',
    'scan_suppression_long_ttl_minutes'    => 'int',
    'scan_suppression_storage_file'        => 'string',
    'max_entry_context_fetch_per_run'      => 'int',

    // Candle data
    'lookback_candles'  => 'int',
    'bybit_base_url'    => 'string',
    'bybit_timeout_sec' => 'int',

    // DBL garbage veto
    'dbl_garbage_veto_enabled'                            => 'bool',
    'dbl_garbage_low_quality_max_score'                   => 'float',
    'dbl_garbage_low_quality_requires_generic_warning'    => 'bool',
    'dbl_garbage_low_quality_requires_obc_missing_or_skipped' => 'bool',
    'dbl_garbage_block_obc_quality_skip'                  => 'bool',
    'dbl_garbage_obc_quality_skip_max_score'              => 'float',
    'dbl_garbage_daily_extension_enabled'                 => 'bool',
    'dbl_garbage_day_change_hot_pct'                      => 'float',
    'dbl_garbage_position_in_24h_range_max_pct'           => 'float',
    'dbl_garbage_min_room_to_24h_high_roi'                => 'float',
    'dbl_garbage_whipsaw_enabled'                         => 'bool',
    'dbl_garbage_whipsaw_max_10m_range_roi'               => 'float',
    'dbl_garbage_whipsaw_max_60m_direction_flips'         => 'int',
    'dbl_garbage_whipsaw_requires_weak_quality'           => 'bool',
    'dbl_garbage_whipsaw_weak_quality_max_score'          => 'float',
];
