<?php

declare(strict_types=1);

/**
 * Confirmed Continuation Strategy — Base Config
 *
 * Bidirectional narrow continuation strategy.
 * Only two setup classes:
 *   LONG:  higher_low_retest_continuation_long
 *   SHORT: lower_high_retest_continuation_short
 *
 * ARCHITECTURE: Strategies are environment-neutral signal producers.
 * Execution mode (demo/live) is owned by the Bot.
 * Do NOT add demo/live gates here.
 *
 * Override individual values in active.php without touching this file.
 */

return [
    // ── Identity ──────────────────────────────────────────────────────────────
    'strategy_id' => 'confirmed_continuation',
    'enabled'     => true,
    'mode'        => 'passive',  // kept for reference only — not used as execution gate
    'side_mode'   => 'all',      // all | long | short

    // ── Execution gates ───────────────────────────────────────────────────────
    'handoff_enabled'  => true,  // write executable rows to bot_handoff_queue.json

    // ── Signal quality ────────────────────────────────────────────────────────
    'max_active_signals_per_symbol_side' => 1,
    'signal_ttl_minutes'                 => 90,

    // ── Universe / batching ───────────────────────────────────────────────────
    'batch_size'          => 50,
    'max_symbols_per_run' => 50,
    'continuous_scan_enabled' => true,
    'auto_requeue_when_done'  => true,
    'max_handoff_signals_per_tick' => 2,
    'max_handoff_signals_per_side_per_tick' => 1,
    'max_active_confirmed_continuation_signals_total' => 5,
    'prefer_highest_quality_handoff' => true,

    // ── Pattern quality gates ─────────────────────────────────────────────────
    'min_structure_score'                   => 0.75,
    'min_candidate_quality_score'           => 0.75,
    'min_higher_lows_long'                  => 2,
    'min_lower_highs_short'                 => 2,
    'max_entry_distance_from_structure_pct' => 0.6,
    'max_late_entry_extension_from_structure_pct' => 1.2,
    'max_pullback_depth_pct'                => 2.0,
    'max_extension_from_structure_pct'      => 1.2,
    'max_extension_from_local_base_pct'     => 12.0,
    'max_1m_blowoff_pct'                    => 2.0,
    'min_volume_persistence_score'          => 0.50,
    'post_structure_filter_enabled'         => true,
    'ignore_pre_structure_impulse_for_anti_comb' => true,
    'allow_mid_trend_entry'                 => true,
    'reject_late_extended_entry'            => true,
    'require_retest_near_structure'         => true,
    'min_upside_room_to_resistance_pct_long' => 0.8,
    'min_downside_room_to_support_pct_short' => 0.8,
    'min_controlled_trend_score_for_signal' => 0.78,
    'min_directional_consistency_for_signal' => 0.72,
    'max_wick_chaos_for_signal'             => 0.35,
    'max_structure_breaks_for_signal'       => 0,
    'max_recent_swing_roi_for_signal'       => 18.0,
    'max_opposite_swing_roi_for_signal'     => 8.0,
    'max_1m_range_roi_for_signal'           => 10.0,
    'max_3m_range_roi_for_signal'           => 18.0,
    'smooth_trend_filter_enabled'           => true,
    'smooth_trend_min_score'                => 0.75,
    'smooth_trend_min_step_count'           => 2,
    'smooth_trend_max_impulse_share'        => 0.55,
    'smooth_trend_max_single_candle_contribution' => 0.45,
    'smooth_trend_require_pullback_before_entry' => true,
    'smooth_trend_reject_vertical_spike'    => true,
    'pattern_123_enabled'                   => true,
    'pattern_123_required_for_signal'       => true,
    'pattern_123_allow_aggressive_point3_entry' => true,
    'pattern_123_allow_conservative_point2_retest_entry' => true,
    'pattern_123_min_point3_distance_from_point1_pct' => 0.15,
    'pattern_123_max_entry_distance_from_point3_pct' => 0.7,
    'pattern_123_max_late_distance_from_point2_pct' => 1.2,
    'pattern_123_require_point3_turn_confirmation' => true,
    'pattern_123_require_point3_above_point1_long' => true,
    'pattern_123_require_point3_below_point1_short' => true,

    // ── Pattern filters (boolean gates) ──────────────────────────────────────
    'require_retest'                    => true,
    'require_structure_hold'            => true,
    'require_continuation_after_retest' => true,

    // ── 24h side-bias filter ───────────────────────────────────────────────────
    'day_regime_filter_enabled'         => true,
    'long_prefer_24h_change_max_pct'    => 8.0,
    'long_reject_24h_change_above_pct'  => 18.0,
    'long_reject_near_24h_high_pct'     => 2.0,
    'short_prefer_24h_change_min_pct'   => -8.0,
    'short_reject_24h_change_below_pct' => -18.0,
    'short_reject_near_24h_low_pct'     => 2.0,

    // ── OBC (OrderBook Context) ───────────────────────────────────────────────
    'orderbook_wall_gate_enabled'              => true,
    'orderbook_wall_gate_mode'                 => 'soft_demote',
    'orderbook_wall_soft_demote_blocks_handoff'=> true,
    'orderbook_wall_fetch_after_score'         => 0.75,
    'orderbook_wall_near_pct'                  => 1.2,
    'orderbook_wall_persistent_required'       => true,

    // ── Anti-comb / anti-chaos gates ──────────────────────────────────────────
    'anti_comb_enabled'                            => true,
    'anti_comb_lookback_minutes'                   => 20,
    'anti_comb_roi_equiv_leverage'                 => 15,   // 15x leverage equivalent: price_pct * leverage = ROI equivalent
    'anti_comb_max_1m_range_roi'                   => 10.0,
    'anti_comb_max_3m_range_roi'                   => 18.0,
    'anti_comb_max_recent_swing_roi'               => 22.0,
    'anti_comb_max_opposite_swing_roi'             => 10.0,
    'anti_comb_min_directional_consistency'        => 0.72,
    'anti_comb_max_wick_chaos_score'               => 0.35,
    'anti_comb_max_structure_breaks'               => 0,
    'anti_comb_reject_if_alternating_large_candles'=> true,

    // ── Wall decision test ─────────────────────────────────────────────────────
    'wall_decision_test_enabled'              => true,
    'wall_breakout_retest_required'           => true,
    'wall_rejection_can_create_opposite_context' => true,
    'wall_test_pending_enabled'               => true,
    'wall_test_pending_ttl_minutes'           => 10,
    'wall_test_block_new_walls'               => true,
    'wall_test_near_wall_pct'                 => 0.35,
    'wall_test_min_wall_score'                => 5.0,
    'wall_test_require_break_and_retest'      => true,

    // ── Candle data ───────────────────────────────────────────────────────────
    'lookback_candles'  => 60,
    'bybit_base_url'    => 'https://api.bybit.com',
    'bybit_timeout_sec' => 6,
];
