<?php

declare(strict_types=1);

/**
 * Confirmed Continuation Strategy — Config Schema
 *
 * Bidirectional (long + short). Used by ConfirmedContinuationBootstrap
 * to validate the merged effective config on every startup.
 */

return [
    // Core identity
    'strategy_id' => 'string',
    'enabled'     => 'bool',
    'mode'        => 'string',
    'side_mode'   => 'string',

    // Execution gates
    'handoff_enabled' => 'bool',

    // Signal lifecycle
    'max_active_signals_per_symbol_side' => 'int',
    'signal_ttl_minutes'                 => 'int',

    // Universe / batching
    'batch_size'          => 'int',
    'max_symbols_per_run' => 'int',

    // Pattern quality gates
    'min_structure_score'                   => 'float',
    'min_candidate_quality_score'           => 'float',
    'min_higher_lows_long'                  => 'int',
    'min_lower_highs_short'                 => 'int',
    'max_entry_distance_from_structure_pct' => 'float',
    'max_pullback_depth_pct'                => 'float',
    'max_extension_from_structure_pct'      => 'float',
    'max_extension_from_local_base_pct'     => 'float',
    'max_1m_blowoff_pct'                    => 'float',
    'min_volume_persistence_score'          => 'float',

    // Pattern filters
    'require_retest'                    => 'bool',
    'require_structure_hold'            => 'bool',
    'require_continuation_after_retest' => 'bool',

    // 24h side-bias
    'day_regime_filter_enabled'         => 'bool',
    'long_prefer_24h_change_max_pct'    => 'float',
    'long_reject_24h_change_above_pct'  => 'float',
    'long_reject_near_24h_high_pct'     => 'float',
    'short_prefer_24h_change_min_pct'   => 'float',
    'short_reject_24h_change_below_pct' => 'float',
    'short_reject_near_24h_low_pct'     => 'float',

    // OBC gate
    'orderbook_wall_gate_enabled'               => 'bool',
    'orderbook_wall_gate_mode'                  => 'string',
    'orderbook_wall_soft_demote_blocks_handoff' => 'bool',
    'orderbook_wall_fetch_after_score'          => 'float',
    'orderbook_wall_near_pct'                   => 'float',
    'orderbook_wall_persistent_required'        => 'bool',

    // Anti-comb
    'anti_comb_enabled'                             => 'bool',
    'anti_comb_lookback_minutes'                    => 'int',
    'anti_comb_max_1m_range_roi'                    => 'float',
    'anti_comb_max_3m_range_roi'                    => 'float',
    'anti_comb_max_recent_swing_roi'                => 'float',
    'anti_comb_max_opposite_swing_roi'              => 'float',
    'anti_comb_min_directional_consistency'         => 'float',
    'anti_comb_max_wick_chaos_score'                => 'float',
    'anti_comb_max_structure_breaks'                => 'int',
    'anti_comb_reject_if_alternating_large_candles' => 'bool',

    // Wall decision test
    'wall_decision_test_enabled'               => 'bool',
    'wall_breakout_retest_required'            => 'bool',
    'wall_rejection_can_create_opposite_context' => 'bool',
    'wall_test_pending_enabled'                => 'bool',
    'wall_test_pending_ttl_minutes'            => 'int',

    // Candle data
    'lookback_candles'  => 'int',
    'bybit_base_url'    => 'string',
    'bybit_timeout_sec' => 'int',
];
