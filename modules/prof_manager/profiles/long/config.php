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
    // Minimum ROI required before hybrid pattern detection / confirmation / close
    // is allowed.  Prevents premature exits at low profit (e.g. +2-3% ROI).
    // Independent of init_roi: init_roi gates the full PM lifecycle; this gate
    // is specific to the hybrid exit layer only.
    'hybrid_min_close_roi'              => 5.0,

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

    // ── Lock-touch grace ──────────────────────────────────────────────────────
    // Prevents PM from closing immediately on the first minor lock_touch by
    // granting a short observation window.  Applies when roi is above the
    // grace minimum and momentum has not broken.
    //
    // lock_touch_grace_enabled              — master switch
    // lock_touch_grace_min_roi              — minimum ROI% before grace activates
    // lock_touch_grace_window_seconds       — how long (sec) the grace window lasts
    // lock_touch_grace_max_overrides        — maximum number of grace overrides per position
    // lock_touch_grace_require_no_momentum_break — close immediately if momentum broken
    // lock_touch_grace_hard_profit_floor_roi — ROI% floor; close immediately if below
    // lock_touch_grace_max_giveback_roi     — max peak→current ROI drawdown before closing
    'lock_touch_grace_enabled'                   => true,
    'lock_touch_grace_min_roi'                   => 6.0,
    'lock_touch_grace_window_seconds'            => 180,
    'lock_touch_grace_max_overrides'             => 2,
    'lock_touch_grace_require_no_momentum_break' => true,
    'lock_touch_grace_hard_profit_floor_roi'     => 4.0,
    'lock_touch_grace_max_giveback_roi'          => 8.0,

    // ── Impulse-aware hold mode ───────────────────────────────────────────────
    // When a long position shows strong price/OI/turnover momentum, PM widens
    // the effective lock buffer and overrides the first lock_touch(es) to allow
    // the move to continue.
    //
    // impulse_hold_enabled             — master switch
    // impulse_hold_min_roi             — minimum ROI% required to compute impulse context
    // impulse_hold_strong_score        — score threshold for 'strong' classification
    // impulse_hold_very_strong_score   — score threshold for 'very_strong' classification
    // impulse_hold_max_override_count  — maximum lock_touch overrides per position
    // impulse_hold_max_minutes         — maximum age (min) of impulse hold state
    // impulse_hold_hard_profit_floor_roi — ROI% floor; close immediately if below
    // impulse_hold_max_giveback_roi    — max peak→current ROI drawdown during impulse hold
    'impulse_hold_enabled'               => true,
    'impulse_hold_min_roi'               => 8.0,
    'impulse_hold_strong_score'          => 4,
    'impulse_hold_very_strong_score'     => 5,
    'impulse_hold_max_override_count'    => 3,
    'impulse_hold_max_minutes'           => 45,
    'impulse_hold_hard_profit_floor_roi' => 6.0,
    'impulse_hold_max_giveback_roi'      => 12.0,

    // ── Impulse momentum windows ──────────────────────────────────────────────
    // Price-change thresholds over rolling windows used to score the impulse.
    // Values are percentages (not fractions).
    'impulse_price_window_minutes'        => 30,
    'impulse_short_window_minutes'        => 5,
    'impulse_min_price_change_1m_pct'     => 0.05,
    'impulse_min_price_change_3m_pct'     => 0.12,
    'impulse_min_price_change_5m_pct'     => 0.25,
    'impulse_min_price_change_15m_pct'    => 0.50,
    'impulse_min_price_change_30m_pct'    => 0.80,
    'impulse_peak_roi_min'                => 10.0,

    // ── Impulse turnover / open-interest thresholds ───────────────────────────
    'impulse_use_turnover24h'                    => true,
    'impulse_use_open_interest'                  => true,
    'impulse_min_turnover_growth_pct'            => 1.5,
    'impulse_min_open_interest_value_growth_pct' => 1.5,
    'impulse_metrics_window_minutes'             => 30,

    // ── Lock behavior during impulse hold ─────────────────────────────────────
    // impulse_hold_skip_first_lock_touch         — override the first lock_touch
    // impulse_hold_widen_lock_buffer_roi         — minimum widened buffer (ROI%)
    // impulse_hold_lock_buffer_multiplier        — multiply normal buffer by this factor
    // impulse_hold_require_momentum_break_to_close — only close on lock_touch when
    //                                               momentum has clearly broken
    'impulse_hold_skip_first_lock_touch'           => true,
    'impulse_hold_widen_lock_buffer_roi'           => 5.0,
    'impulse_hold_lock_buffer_multiplier'          => 2.0,
    'impulse_hold_require_momentum_break_to_close' => true,

    // ── ROI staircase trailing ────────────────────────────────────────────────
    // When roi_staircase_enabled = true and peak_roi >= min_peak_roi, PM computes
    // a dynamic floor ROI that increases in steps as peak ROI climbs.  The floor
    // is applied as the effective lock_floor_roi, so the profit lock will never be
    // placed below the current step floor.  Strong impulse (strong / very_strong)
    // may still allow deeper hold by widening the buffer, but the floor prevents
    // unlimited giveback at high ROI levels.
    //
    // Formula: staircase_floor = max(base_floor_roi, floor(peak/step)*step - buffer)
    // Clamp  : staircase_floor <= max_floor_roi  and  staircase_floor < peak_roi
    //
    // Example trajectory:
    //   peak 10–15 → floor ~6–8    peak 15–20 → floor ~10–13
    //   peak 20–25 → floor ~13–16  peak 25–30 → floor ~16–20
    'roi_staircase_enabled'          => true,
    'roi_staircase_step_roi'         => 5.0,
    'roi_staircase_base_floor_roi'   => 5.0,
    'roi_staircase_floor_buffer_roi' => 3.0,
    'roi_staircase_min_peak_roi'     => 10.0,
    'roi_staircase_max_floor_roi'    => 50.0,

    // ── Chop / indecision exit ────────────────────────────────────────────────
    // Closes a profitable long position when price is chopping up and down in a
    // roughly 3–5 ROI band after the peak without making a new high.  This pattern
    // indicates distribution / indecision before a dump.
    //
    // chop_exit_enabled              — master switch
    // chop_exit_min_peak_roi         — only active once peak ROI reached this level
    // chop_exit_window_seconds       — observation window for swing detection (sec)
    // chop_exit_min_swings           — minimum alternating swings inside window
    // chop_exit_swing_roi            — minimum size (ROI%) of one swing to count
    // chop_exit_max_swing_roi        — maximum swing size (ROI%) to count as chop
    // chop_exit_no_new_peak_seconds  — trigger only if no new peak for this many sec
    // chop_exit_close_reason         — close reason string written to registry
    // chop_exit_require_profit       — only close when current ROI >= min_close_roi
    // chop_exit_min_close_roi        — minimum ROI required to allow a chop close
    'chop_exit_enabled'              => true,
    'chop_exit_min_peak_roi'         => 8.0,
    'chop_exit_window_seconds'       => 180,
    'chop_exit_min_swings'           => 3,
    'chop_exit_swing_roi'            => 3.0,
    'chop_exit_max_swing_roi'        => 5.0,
    'chop_exit_no_new_peak_seconds'  => 180,
    'chop_exit_close_reason'         => 'roi_chop_indecision_exit',
    'chop_exit_require_profit'       => true,
    'chop_exit_min_close_roi'        => 4.0,

    // ── Trend birth hold ─────────────────────────────────────────────────
    // Protective close-veto layer that prevents PM from closing too early when
    // a long position is entered near the start of a new recovery trend.
    // Detects early_trend_birth by checking higher lows in PM price samples,
    // position age, peak/current ROI conditions, and giveback limits.
    //
    // When active, vetoes: lock_touch, staircase_floor_lost, chop_exit, and
    // hybrid_close_confirmed (weak high without confirmed support break).
    // Never vetoes: wall_exit_close or closes triggered by hard-floor / giveback breach.
    //
    // Extra buffers widen the effective lock_buffer_roi and staircase floor
    // buffer while early trend birth is active — applied before staircase/lock
    // planning, without mutating the base config.
    //
    // trend_birth_hold_enabled               — master switch
    // trend_birth_min_peak_roi               — minimum peak ROI% before hold is considered
    // trend_birth_min_current_roi            — minimum current ROI% required
    // trend_birth_max_age_minutes            — position must be younger than this
    // trend_birth_min_samples                — minimum roi_samples required
    // trend_birth_min_higher_lows            — number of consecutive higher lows needed
    // trend_birth_higher_low_tolerance_pct   — % tolerance for higher-low detection (0.12 = 0.12%)
    // trend_birth_structure_break_pct        — % below last higher low = structure broken
    // trend_birth_max_giveback_roi           — peak→current ROI giveback hard limit
    // trend_birth_hard_floor_roi             — close if ROI falls below this regardless
    // trend_birth_extra_lock_buffer_roi      — added to lock_buffer_roi when hold active
    // trend_birth_extra_staircase_buffer_roi — added to staircase floor buffer when hold active
    // trend_birth_veto_lock_touch            — veto would_close_on_lock_touch
    // trend_birth_veto_staircase_floor_lost  — veto staircase_floor_lost close hint
    // trend_birth_veto_chop_exit             — veto roi_chop_indecision_exit
    // trend_birth_veto_hybrid_weak_high      — veto hybrid_close_confirmed (weak high, no support break)
    // trend_birth_allow_wall_exit            — never veto wall_exit_close
    'trend_birth_hold_enabled'               => true,
    'trend_birth_min_peak_roi'               => 8.0,
    'trend_birth_min_current_roi'            => 5.0,
    'trend_birth_max_age_minutes'            => 25.0,
    'trend_birth_min_samples'                => 5,
    'trend_birth_min_higher_lows'            => 2,
    'trend_birth_higher_low_tolerance_pct'   => 0.12,
    'trend_birth_structure_break_pct'        => 0.18,
    'trend_birth_max_giveback_roi'           => 12.0,
    'trend_birth_hard_floor_roi'             => 4.0,
    'trend_birth_extra_lock_buffer_roi'      => 2.0,
    'trend_birth_extra_staircase_buffer_roi' => 3.0,
    'trend_birth_veto_lock_touch'            => true,
    'trend_birth_veto_staircase_floor_lost'  => true,
    'trend_birth_veto_chop_exit'             => true,
    'trend_birth_veto_hybrid_weak_high'      => true,
    'trend_birth_allow_wall_exit'            => true,
];
