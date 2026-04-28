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
    'max_handoff_per_run'  => 0,
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
    'hard_min_daily_change_pct'  => 5.0,   // below this: ignore (ignore_low_momentum_below_5pct)
    'min_daily_change_pct'       => 8.0,   // below this but ≥ hard_min: watch_only_weak_momentum
    'ideal_min_daily_change_pct' => 10.0,  // ideal range lower bound
    'ideal_max_daily_change_pct' => 25.0,  // ideal range upper bound
    'hard_max_daily_change_pct'  => 35.0,  // above this: daily_change_too_high (no valid signal)

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
    'hard_volume_cliff_ratio'          => 0.15, // below this: hard reject (volume_cliff_after_pump)
    'warning_volume_cliff_ratio'       => 0.45, // below this but >= hard: warning (volume_cliff_warning)
    'min_persistence_candles_hard'     => 1,    // below this: hard reject (turnover_not_persistent)
    'min_persistence_candles_soft'     => 3,    // below soft but >= hard: warning (turnover_persistence_marginal)
    // Strong-signal adaptive overrides
    'strong_ramp_override_ratio'       => 1.5,  // ramp >= this triggers adaptive persistence relaxation
    'strong_turnover_override_ratio'   => 2.0,  // turnover >= this (+ ramp >= strong_ramp) allows persistence >= 2

    // ── Structure ─────────────────────────────────────────────────────────────
    'min_higher_lows_count'     => 2,     // minimum number of consecutive higher lows

    // ── Pullback reclaim ──────────────────────────────────────────────────────
    'min_pullback_depth_pct'    => 1.5,   // min pullback from impulse high (pullback_too_shallow)
    'max_pullback_depth_pct'    => 7.0,   // max pullback allowed (pullback_too_deep)
    'confirm_required'          => true,  // require candle confirmation after reclaim
    'confirm_mode'              => 'reclaim_2_of_3', // confirmation mode
    'confirm_max_bars'          => 3,     // max bars to look for confirmation

    // ── Entry quality gates ───────────────────────────────────────────────────
    'max_entry_distance_from_reclaim_pct'   => 2.5, // entry_too_late_after_reclaim
    'max_entry_distance_from_structure_pct' => 5.0, // entry_too_far_from_structure
];
