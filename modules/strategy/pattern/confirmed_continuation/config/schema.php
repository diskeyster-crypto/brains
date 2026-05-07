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
    'continuous_scan_enabled' => 'bool',
    'auto_requeue_when_done'  => 'bool',
    'max_handoff_signals_per_tick' => 'int',
    'max_handoff_signals_per_side_per_tick' => 'int',
    'max_active_confirmed_continuation_signals_total' => 'int',
    'prefer_highest_quality_handoff' => 'bool',

    // Pattern quality gates
    'min_structure_score'                   => 'float',
    'min_candidate_quality_score'           => 'float',
    'min_higher_lows_long'                  => 'int',
    'min_lower_highs_short'                 => 'int',
    'max_entry_distance_from_structure_pct' => 'float',
    'max_late_entry_extension_from_structure_pct' => 'float',
    'max_pullback_depth_pct'                => 'float',
    'max_extension_from_structure_pct'      => 'float',
    'max_extension_from_local_base_pct'     => 'float',
    'max_1m_blowoff_pct'                    => 'float',
    'min_volume_persistence_score'          => 'float',
    'post_structure_filter_enabled'         => 'bool',
    'ignore_pre_structure_impulse_for_anti_comb' => 'bool',
    'allow_mid_trend_entry'                 => 'bool',
    'reject_late_extended_entry'            => 'bool',
    'require_retest_near_structure'         => 'bool',
    'min_upside_room_to_resistance_pct_long' => 'float',
    'min_downside_room_to_support_pct_short' => 'float',
    'min_controlled_trend_score_for_signal' => 'float',
    'min_directional_consistency_for_signal' => 'float',
    'max_wick_chaos_for_signal'             => 'float',
    'max_structure_breaks_for_signal'       => 'int',
    'max_recent_swing_roi_for_signal'       => 'float',
    'max_opposite_swing_roi_for_signal'     => 'float',
    'max_1m_range_roi_for_signal'           => 'float',
    'max_3m_range_roi_for_signal'           => 'float',
    'smooth_trend_filter_enabled'           => 'bool',
    'smooth_trend_min_score'                => 'float',
    'smooth_trend_min_step_count'           => 'int',
    'smooth_trend_max_impulse_share'        => 'float',
    'smooth_trend_max_single_candle_contribution' => 'float',
    'smooth_trend_require_pullback_before_entry' => 'bool',
    'smooth_trend_reject_vertical_spike'    => 'bool',
    'pattern_123_enabled'                   => 'bool',
    'pattern_123_required_for_signal'       => 'bool',
    'pattern_123_allow_aggressive_point3_entry' => 'bool',
    'pattern_123_allow_conservative_point2_retest_entry' => 'bool',
    'pattern_123_min_point3_distance_from_point1_pct' => 'float',
    'pattern_123_max_entry_distance_from_point3_pct' => 'float',
    'pattern_123_max_late_distance_from_point2_pct' => 'float',
    'pattern_123_require_point3_turn_confirmation' => 'bool',
    'pattern_123_require_point3_above_point1_long' => 'bool',
    'pattern_123_require_point3_below_point1_short' => 'bool',

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
    'anti_comb_roi_equiv_leverage'                  => 'float',
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
    'wall_test_block_new_walls'                => 'bool',
    'wall_test_near_wall_pct'                  => 'float',
    'wall_test_min_wall_score'                 => 'float',
    'wall_test_require_break_and_retest'       => 'bool',

    // Candle data
    'lookback_candles'  => 'int',
    'bybit_base_url'    => 'string',
    'bybit_timeout_sec' => 'int',
];
