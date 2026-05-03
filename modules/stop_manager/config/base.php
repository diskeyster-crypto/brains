<?php

declare(strict_types=1);

/**
 * Stop Manager Module — Base Config
 *
 * Stop Manager is the sole owner of stop-loss computation.
 * Strategy modules provide signal profile references only.
 * Bot module provides position state only.
 * This module owns the stop math.
 *
 * Override individual values in active.php without touching this file.
 *
 * Stop modes:
 *   liq_distance_percent — stop placed between liquidation price and entry price.
 *
 *   liq_distance_percent is the % of the way from liquidation toward entry where
 *   the stop is placed.  Value is clamped 1..99.
 *
 *   For long:  stop = liq_price + (liq_distance_percent / 100) * (entry_price - liq_price)
 *   For short: stop = liq_price - (liq_distance_percent / 100) * (liq_price - entry_price)
 *
 *   Examples:
 *     liq_distance_percent = 90  → stop is 90% of the way from liq to entry (close to entry)
 *     liq_distance_percent = 10  → stop is 10% of the way from liq to entry (close to liq)
 *
 * Execution modes:
 *   demo  — stop computation for Bybit Demo positions + call setTradingStop on Bybit Demo
 *           (set demo_execute_stops=false to suppress the exchange call)
 *   live  — stop computation for live positions + call setTradingStop on live account
 */

return [
    // Core identity
    'module_id' => 'stop_manager',
    'enabled'   => false,
    'mode'      => 'demo',  // demo | live
                            // demo = stop computation for Bybit Demo positions
                            //        + setTradingStop on Bybit Demo (if demo_execute_stops=true)
                            // live = stop computation for live positions

    // Stop computation
    'stop_mode'            => 'liq_distance_percent',
    'liq_distance_percent' => 90,  // % of way from liq to entry where stop is placed (1..99)

    // Breakeven / profit-lock rule
    'breakeven_enabled'          => false,
    'breakeven_trigger_roi'      => 10.0,  // ROI% that triggers the shift
    'breakeven_profit_lock_roi'  => 3.0,   // ROI% locked after shift

    // Bot positions source path (relative to repo root)
    'bot_positions_path' => 'modules/bot/storage/active_positions.json',

    // Demo execution: when mode=demo, actually call Bybit Demo setTradingStop API.
    // Credentials are read from the bot module config (demo_api_key / demo_api_secret).
    // Set to false to keep demo mode as local-only computation.
    'demo_execute_stops' => true,

    // Path to the bot module directory (relative to repo root).
    // Used to locate the bot config when reading demo API credentials.
    'bot_module_dir' => 'modules/bot',

    // Cron / batch
    'tick_interval_sec'   => 60,
    'max_runtime_seconds' => 25,

    // ── double_bottom_long early-fail guard ───────────────────────────────────
    //
    // Closes demo positions from the double_bottom_long strategy early when the
    // original setup breaks after entry, preventing deep losses without touching
    // the normal stop profile.
    //
    // This guard is DEMO-ONLY. It never affects live positions.
    // It only closes when price action confirms the setup is broken — adverse ROI
    // alone is not sufficient when require_setup_break = true.
    //
    // Setup failure conditions (any one triggers):
    //   A) Neckline or reclaim level lost by neckline_break_pct / reclaim_break_pct
    //   B) Price below entry by entry_break_pct AND adverse ROI <= soft threshold
    //   C) Fast dump >= fast_drop_pct within fast_drop_window_minutes AND ROI <= soft
    //      (requires per-tick price history; skipped when history unavailable)
    //   D) Adverse ROI <= hard threshold AND signal trace contains a known warning
    //
    // Override individual values in active.php without touching this file.
    'double_bottom_early_fail_enabled'             => true,
    'double_bottom_early_fail_mode'                => 'demo',           // enforcement in service: only 'demo' is accepted; live is never used
    'double_bottom_early_fail_strategy'            => 'double_bottom_long',

    // Timing
    'double_bottom_early_fail_watch_minutes'       => 30,   // only check within this window after entry
    'double_bottom_early_fail_min_age_seconds'     => 60,   // skip positions younger than this

    // ROI thresholds (negative; long position adverse move expressed as ROI%)
    'double_bottom_early_fail_adverse_roi_soft'    => -20.0, // soft threshold for conditions B/C
    'double_bottom_early_fail_adverse_roi_hard'    => -35.0, // hard threshold for condition D

    // Structure break thresholds (percent of the level price)
    'double_bottom_early_fail_neckline_break_pct'  => 0.35,  // % below neckline_level to confirm loss
    'double_bottom_early_fail_reclaim_break_pct'   => 0.35,  // % below reclaim_level to confirm loss
    'double_bottom_early_fail_entry_break_pct'     => 1.8,   // % below entry_price for condition B

    // Fast dump thresholds (condition C — requires per-tick price history in position record)
    'double_bottom_early_fail_fast_drop_pct'               => 1.2,  // % price drop over the window
    'double_bottom_early_fail_fast_drop_window_minutes'    => 3,    // look-back window in minutes

    // Safety
    'double_bottom_early_fail_require_setup_break' => true,  // adverse ROI alone must not close
    'double_bottom_early_fail_close_reason'        => 'double_bottom_setup_failed_after_entry',

    // Legacy stop path control.
    //   false (default) — do NOT initialize/recalculate/set exchange stop from
    //     liq_distance_percent; do NOT apply breakeven logic; do NOT call
    //     setTradingStop due to legacy liq_distance logic alone.
    //     Side-specific ROI emergency stops continue to work normally.
    //   true — re-enable the legacy liq_distance_percent + breakeven runtime path.
    'legacy_liq_distance_stop_enabled' => false,

    // ── Side-specific stop profiles ───────────────────────────────────────────
    //
    // profiles.long — controls long-side generic ROI emergency stop behavior.
    //   Applies a hard ROI cap to demo long positions when normalized_roi
    //   drops to or below emergency_stop_roi. Only 'demo' mode is supported.
    //   The double_bottom_long early-fail guard continues to run independently.
    //
    // profiles.short — controls short-side emergency stop.
    //   Applies a hard ROI cap to demo short positions when normalized_roi
    //   drops to or below emergency_stop_roi.  Only 'demo' mode is supported.
    //   applies_to_strategies accepts strategy_id / owner_strategy values plus
    //   '*' as a wildcard that matches any strategy.
    //
    // Override individual values in active.php without touching this file.
    'profiles' => [
        'long' => [
            'enabled'                => true,
            'emergency_stop_enabled' => true,
            'emergency_stop_roi'     => -30.0,   // trigger ROI% (negative)
            'min_age_seconds'        => 60,
            'applies_to_strategies'  => ['double_bottom_long', '*'],
            'close_reason'           => 'long_emergency_roi_cap',
            'close_guard'            => 'long_emergency_stop',
        ],
        'short' => [
            'enabled'                => true,
            'emergency_stop_enabled' => true,
            'emergency_stop_roi'     => -20.0,   // trigger ROI% (negative)
            'min_age_seconds'        => 60,
            'applies_to_strategies'  => ['dynamic_strategies'],
            'close_reason'           => 'short_emergency_roi_cap',
            'close_guard'            => 'short_emergency_stop',
        ],
    ],
];
