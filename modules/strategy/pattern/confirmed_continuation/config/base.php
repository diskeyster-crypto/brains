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

    // ── Pattern quality gates ─────────────────────────────────────────────────
    'min_structure_score'                   => 0.75,
    'min_candidate_quality_score'           => 0.75,
    'min_higher_lows_long'                  => 2,
    'min_lower_highs_short'                 => 2,
    'max_entry_distance_from_structure_pct' => 0.6,
    'max_pullback_depth_pct'                => 2.0,
    'max_extension_from_structure_pct'      => 1.2,
    'max_extension_from_local_base_pct'     => 12.0,
    'max_1m_blowoff_pct'                    => 2.0,
    'min_volume_persistence_score'          => 0.50,

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
    'anti_comb_roi_equiv_leverage'                 => 15,   // price_pct * leverage = ROI equivalent
    'anti_comb_max_1m_range_roi'                   => 18.0,
    'anti_comb_max_3m_range_roi'                   => 30.0,
    'anti_comb_max_recent_swing_roi'               => 35.0,
    'anti_comb_max_opposite_swing_roi'             => 25.0,
    'anti_comb_min_directional_consistency'        => 0.62,
    'anti_comb_max_wick_chaos_score'               => 0.55,
    'anti_comb_max_structure_breaks'               => 1,
    'anti_comb_reject_if_alternating_large_candles'=> true,

    // ── Wall decision test ─────────────────────────────────────────────────────
    'wall_decision_test_enabled'              => true,
    'wall_breakout_retest_required'           => true,
    'wall_rejection_can_create_opposite_context' => true,
    'wall_test_pending_enabled'               => true,
    'wall_test_pending_ttl_minutes'           => 10,

    // ── Candle data ───────────────────────────────────────────────────────────
    'lookback_candles'  => 60,
    'bybit_base_url'    => 'https://api.bybit.com',
    'bybit_timeout_sec' => 6,
];
