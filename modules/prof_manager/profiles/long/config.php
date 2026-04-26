<?php

declare(strict_types=1);

/**
 * Long Profile — Default Config
 *
 * Mapped from legacy_safe profile values (config migration: legacy_safe → long).
 * Override via modules/prof_manager/config/active.php under profiles['long'].
 *
 * profile_name: legacy_safe_long
 */

return [
    'profile_name'            => 'legacy_safe_long',
    'init_roi'                => 2.0,
    'activation_roi'          => 10.0,
    'step_roi'                => 3.0,
    'lock_buffer_roi'         => 2.0,
    'lock_floor_roi'          => 5.0,
    'min_update_interval_sec' => 30,
    'min_price_distance_pct'  => 0.15,
    'min_roi_step'            => 1.0,
    'max_updates_per_run'     => 20,
    'default_tick_size'       => 0.0001,

    // ── Hybrid Long overlay ───────────────────────────────────────────────────
    // When hybrid_enabled = true, LongProfile runs a pattern-confirmation layer
    // on top of the baseline step-trailing lock logic.
    'hybrid_enabled'                    => true,
    'pattern_confirmation_window_sec'   => 300,
    'pattern_confirmation_min_ticks'    => 2,
    'guard_roi_distance'                => 3.0,
    'breathing_roi_distance_min'        => 5.0,
    'breathing_roi_distance_max'        => 10.0,

    // ── Real exit pattern detector ───────────────────────────────────────────
    // Parameters for the long_structure_weak_high detector.
    // All detection uses parser2_history_accumulator NDJSON price series.
    // '' parser2_storage_path = auto-detect from module directory structure.
    'detection_min_price_points'       => 15,    // min data points to attempt detection
    'detection_max_price_points'       => 60,    // max points to read from NDJSON
    'detection_lookback_sec'           => 3600,  // look back at most 1 hour
    'detection_weak_high_margin_pct'   => 0.10,  // % tolerance for "failed higher high"
    'detection_min_score'              => 3,     // score threshold (max 5)
    'confirm_support_break_pct'        => 0.15,  // % below support = confirmed (support_break)
    'confirm_new_high_margin_pct'      => 0.20,  // % above prev high = rejected (new_higher_high)
    'confirm_strong_drop_pct'          => 2.0,   // % drop from detected high = confirmed
    'parser2_storage_path'             => '',    // '' = auto-detect

    // ── PM-owned price history ────────────────────────────────────────────────
    // When price_history_enabled = true, LongProfile records every processed tick
    // price into profiles/long/storage/price_history.json.
    // The hybrid detector reads from this store first; parser2 CandleReader is the
    // fallback when PM history does not yet have enough points.
    'price_history_enabled'                 => true,
    'price_history_max_points'              => 120,   // max points retained per symbol
    'price_history_min_points_for_detector' => 10,    // min PM history points before using PM source

    // ── Hybrid simulation/test mode ───────────────────────────────────────────
    // FOR DEMO/DEV ONLY — never enable in production.
    // Allows manual testing of the full hybrid confirmation chain without
    // waiting for a real market pattern to appear.
    //
    // hybrid_simulation_enabled        — master switch; must be false in prod
    // hybrid_simulation_pattern_symbol — symbol to simulate (e.g. 'BTCUSDT'); ''=all
    // hybrid_simulation_force_detect   — inject detected=true into detectExitPattern()
    // hybrid_simulation_force_confirm  — make confirmExitPattern() return confirmed=true
    // hybrid_simulation_force_reject   — make confirmExitPattern() return rejected=true
    //   (force_confirm takes precedence over force_reject if both are true)
    'hybrid_simulation_enabled'        => false,
    'hybrid_simulation_pattern_symbol' => '',
    'hybrid_simulation_force_detect'   => false,
    'hybrid_simulation_force_confirm'  => false,
    'hybrid_simulation_force_reject'   => false,
];
