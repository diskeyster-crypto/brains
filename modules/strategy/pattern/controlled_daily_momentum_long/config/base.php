<?php

declare(strict_types=1);

/**
 * Controlled Daily Momentum Long — Base Config
 *
 * Detects narrow controlled daily momentum continuation longs.
 * Override individual values in active.php without touching this file.
 *
 * Config keys read/written by the bot:
 *   signal_source_mode     — not set here; governed by bot/Governor config
 *   shadow_compare_enabled — not set here; governed by bot/Governor config
 */

return [
    // ── Identity ─────────────────────────────────────────────────────────────
    'strategy_id'     => 'controlled_daily_momentum_long',
    'enabled'         => true,
    'mode'            => 'demo',
    'handoff_enabled' => false,
    'side'            => 'long',

    // ── Runtime caps ──────────────────────────────────────────────────────────
    // NOTE: max_handoff_per_run is IGNORED for bot_handoff_queue building.
    // Handoff quantity limits are global (bot/Governor settings), not per-strategy.
    // Configure handoff quantity limits in bot config (max_orders_per_cycle,
    // max_active_positions, slot limits). This key is kept only for backward
    // compatibility; it does NOT gate handoff output.
    'max_handoff_per_run'  => 0,  // deprecated — quantity limits are global_bot_settings
    'max_signals_per_run'  => 10,
    'max_signals_per_day'  => 10,

    // ── Universe ──────────────────────────────────────────────────────────────
    'universe_mode'       => 'all',
    'allowed_symbols'     => [],
    'excluded_symbols'    => [],
    'max_symbols_per_run' => 200,

    // ── Candle data (Bybit 1m) ────────────────────────────────────────────────
    'kline_interval'    => '1',
    'lookback_candles'  => 1440,
    'bybit_base_url'    => 'https://api.bybit.com',
    'bybit_timeout_sec' => 10,
    'batch_size'        => 200,

    // ── Daily momentum gate ────────────────────────────────────────────────────
    'early_watch_min_daily_change_pct'  => 3.0,   // below this: ignore (ignore_low_momentum_below_3pct)
    'hard_min_daily_change_pct'         => 3.0,   // alias for early_watch_min (backward compat; was 5.0)
    'active_watch_min_daily_change_pct' => 5.0,   // 3–5 %: early_watch; 5–8 %: active_watch
    'min_daily_change_pct'              => 8.0,   // below this but ≥ active_watch_min: no signal
    'ideal_min_daily_change_pct'        => 10.0,  // ideal range lower bound
    'ideal_max_daily_change_pct'        => 18.0,  // ideal range upper bound (was 25.0)
    'caution_daily_change_pct'          => 18.0,  // ≥ this: caution_late_momentum class
    'late_momentum_warning_pct'         => 25.0,  // ≥ this: late_momentum_warning class
    'hard_max_daily_change_pct'         => 35.0,  // above this: daily_change_too_high (no valid signal)

    // ── Anti-blowoff gates ────────────────────────────────────────────────────
    'max_1m_pump_pct'                   => 3.0,  // max single 1m candle move (blowoff_1m_pump)
    'max_5m_pump_pct'                   => 8.0,  // max 5m aggregate move (blowoff_5m_pump)
    'max_single_candle_share_of_move_pct' => 35.0, // max % of total move in one candle
    'max_drawdown_from_24h_high_pct'    => 18.0, // max drawdown from 24h high (deep_drawdown_from_high)

    // ── Soft turnover ramp ────────────────────────────────────────────────────
    // Legacy keys kept for backward compatibility
    'min_turnover_1h_vs_avg_24h'       => 1.3,  // kept; maps to min_turnover_1h_vs_avg_24h_soft at runtime
    'max_turnover_1h_vs_avg_24h'       => 4.0,  // hard upper cap (turnover_spike_too_large)
    'min_turnover_ramp_15m_ratio'      => 1.15, // min 15m ramp ratio
    'volume_persistence_window'        => 4,    // bars to check for volume persistence
    'min_volume_persistence_candles'   => 3,    // kept; maps to min_persistence_candles_soft at runtime
    'max_volume_cliff_ratio'           => 0.45, // kept; maps to warning_volume_cliff_ratio at runtime
    // Hard/soft thresholds (adaptive entry diagnostics)
    'min_turnover_1h_vs_avg_24h_hard'  => 0.8,  // below this: hard reject (turnover_too_low)
    'min_turnover_1h_vs_avg_24h_soft'  => 1.3,  // below this but >= hard: warning (turnover_soft_below_target)
    'max_turnover_1h_vs_avg_24h_soft'  => 4.0,  // above this but <= hard max: warning (turnover_spike_warning)
    'max_turnover_1h_vs_avg_24h_hard'  => 7.0,  // above this: hard reject (turnover_spike_too_large)
    'hard_volume_cliff_ratio'          => 0.12, // below this: hard reject (volume_cliff_after_pump)
    'warning_volume_cliff_ratio'       => 0.45, // below this but >= hard: warning (volume_cliff_warning)
    'min_persistence_candles_hard'     => 1,    // below this: hard reject (turnover_not_persistent)
    'min_persistence_candles_soft'     => 3,    // below soft but >= hard: warning (turnover_persistence_marginal)
    // Strong-signal adaptive overrides
    'strong_ramp_override_ratio'       => 1.5,  // ramp >= this triggers adaptive persistence relaxation
    'strong_turnover_override_ratio'   => 2.0,  // turnover >= this (+ ramp >= strong_ramp) allows persistence >= 2
    // Recovery drift turnover tolerance
    'recovery_drift_turnover_warning_allowed' => true, // if true, recovery_drift=true bypasses soft turnover hard rejects

    // ── Structure (adaptive) ──────────────────────────────────────────────────
    'structure_mode'            => 'adaptive', // adaptive | classic
    'structure_lookback_candles'=> 90,    // bars to look back for higher-low detection
    'min_higher_lows_count'     => 2,     // minimum number of consecutive higher lows

    // Range/base hold structure
    'range_hold_lookback_candles'  => 60,  // lookback for range hold detection
    'max_range_hold_break_pct'     => 1.2, // max % close can be below range low to still qualify
    'min_range_hold_recovery_pct'  => 0.4, // min % close must be above range low

    // Base reclaim structure
    'base_hold_lookback_candles'   => 120, // lookback for base reclaim detection
    'max_base_break_pct'           => 1.5, // max % close can be below base low during dip
    'min_base_reclaim_pct'         => 0.5, // min % close must be above base low after reclaim

    // Recovery drift hold structure
    'recovery_structure_min_duration_minutes'              => 360,  // min drift window to evaluate
    'recovery_structure_max_recent_dump_pct'               => 5.0,  // max recent dump allowed
    'recovery_structure_min_hold_score'                    => 0.55, // min score to pass recovery structure
    'recovery_structure_allow_without_classic_higher_lows' => true, // allow pass even if no higher lows

    // ── Pullback reclaim ──────────────────────────────────────────────────────
    'min_pullback_depth_pct'    => 1.5,   // min pullback from impulse high (pullback_too_shallow)
    'max_pullback_depth_pct'    => 7.0,   // max pullback allowed (pullback_too_deep)
    'confirm_required'          => true,  // require candle confirmation after reclaim
    'confirm_mode'              => 'reclaim_2_of_3', // confirmation mode
    'confirm_max_bars'          => 3,     // max bars to look for confirmation

    // ── Entry quality gates ───────────────────────────────────────────────────
    'max_entry_distance_from_reclaim_pct'   => 2.5, // entry_too_late_after_reclaim
    'max_entry_distance_from_structure_pct' => 5.0, // entry_too_far_from_structure

    // ── Recovery drift detection ──────────────────────────────────────────────
    'recovery_drift_min_duration_minutes'                   => 720,  // ≥ 12 h of controlled rise
    'recovery_drift_max_slope_spike_pct'                    => 4.0,  // max single candle spike in drift window
    'recovery_drift_min_daily_change_pct'                   => 8.0,  // only evaluate in valid range
    'recovery_drift_max_daily_change_pct'                   => 25.0, // skip if daily move is extreme
    'max_entry_extension_from_recent_pullback_pct'          => 3.0,  // extension that triggers dump_risk_warning
    'max_entry_distance_from_24h_high_pct_for_late_warning' => 8.0,  // how far below 24 h high entry may be

    // ── Handoff readiness ─────────────────────────────────────────────────────
    // Diagnostic gate: determines whether a signal is eligible for future demo
    // handoff. Does NOT enable live trading. handoff_enabled must remain false.
    'handoff_readiness_enabled'           => true,
    'handoff_allow_warning_signals'       => false,
    'handoff_allow_late_entry'            => false,
    'handoff_min_upside_room_to_18pct'    => 3.0,
    'handoff_min_upside_room_to_25pct'    => 7.0,
    'handoff_allowed_entry_risk_contexts' => ['ok', 'caution'],
    'handoff_block_late_contexts'         => ['late', 'overextended'],
    'handoff_prefer_structure_types'      => ['higher_low'],
    'handoff_allow_structure_types'       => ['higher_low', 'range_hold', 'base_reclaim', 'recovery_drift_hold'],
    'handoff_require_fresh_reclaim'       => true,
    'handoff_min_pullback_score'          => 7.0,
    'handoff_min_reclaim_score'           => 8.0,
    'handoff_min_candidate_quality_score' => 7.0,
];
