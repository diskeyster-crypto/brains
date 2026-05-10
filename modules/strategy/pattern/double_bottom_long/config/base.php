<?php

declare(strict_types=1);

/**
 * Double Bottom Long Strategy — Base Config
 *
 * Long-only module. All short-side parameters removed.
 * Override individual values in active.php without touching this file.
 */

return [
    // Core identity
    'strategy_id' => 'double_bottom_long',
    'enabled'     => false,
    'mode'        => 'passive',   // active | passive | disabled | smoke_demo
    'timeframe'   => 'H4',

    // Universe selection
    'universe_mode'    => 'all',
    'allowed_symbols'  => [],
    'excluded_symbols' => [],

    // Market regime gate
    'market_regime_enabled'   => true,
    'market_regime_gate_mode' => 'hard',  // soft = warn only; hard = block signal
    // Market regime classification thresholds
    'market_regime_min_sample_count'          => 5,
    'market_regime_dominance_ratio'           => 0.55,
    'market_regime_flat_dominance_ratio'      => 0.65,
    'market_regime_transition_flip_threshold' => 0.20,
    // How many symbols to use for regime classification (must be > trend SLOW_PERIOD=21)
    'regime_sample_size'                      => 50,
    // Number of candles to fetch per symbol when computing the regime
    'regime_sample_lookback_candles'          => 30,

    // Trend gate
    'trend_required'          => true,
    'trend_long_require_bullish' => true,  // long entries only when trend is bullish

    // Corridor
    'corridor_required'       => true,
    'corridor_lookback_hours' => 24,
    'corridor_bucket_count'   => 10,
    'allowed_long_buckets'    => [1, 2],   // bottom 20% of 24 h range only

    // Wave
    'wave_required' => true,

    // Patterns enabled — long-only: double_bottom only
    'enabled_patterns' => ['double_bottom'],

    // Candidate quality filter
    'min_candidate_quality_score' => 0.68,  // raised from 0.62 — reduces false entries

    // Neckline floor
    'min_neckline_score' => 0.55,  // raised from 0.45 — requires meaningful W-depth

    // Pattern detection tolerances (long / double_bottom)
    'pattern_similarity_tolerance'              => 0.05,   // tightened from 0.07
    'double_bottom_similarity_tolerance_pct'    => 0.05,   // tightened from 0.07 — cleaner patterns
    'double_bottom_min_neckline_bounce_pct'     => 0.015,  // raised from 0.005 — requires 1.5% W-depth

    // Neckline distance gate (long only)
    'neckline_distance_tolerance_pct'               => 0.02,
    'double_bottom_neckline_distance_tolerance_pct' => 0.02,

    // Confirmation
    'confirm_required' => true,
    'confirm_mode'     => 'candle_confirmation',
    'confirm_max_bars' => 2,

    // Signal lifetime
    'signal_ttl_bars'                    => 2,
    'max_active_signals_per_symbol_side' => 1,

    // Stop — fixed_from_liq_zone model (no live order execution yet)
    'stop_mode'                    => 'fixed_from_liq_zone',
    'stop_from_liq_buffer_value'   => 0.002,
    'stop_from_liq_buffer_type'    => 'percent',   // absolute | percent
    // Pattern-based SL: place stop this fraction below the lower of the two lows
    'stop_buffer_pct_below_lows'   => 0.005,  // 0.5% below the lowest low
    // Maximum allowed SL as % of entry; reject signals whose pattern SL is too wide (classic/non-synthetic).
    // For synthetic/intraday A/B setups, the adaptive gate below is used instead.
    'max_stop_loss_pct'            => 0.05,   // skip entries where SL > 5% from entry (classic path)

    // Final stop-width gate — adaptive mode for synthetic/intraday A/B setup classes.
    // 'adaptive' = warn-only for moderate excess; hard-block only at emergency cap.
    // 'strict'   = use max_stop_loss_pct as hard cap for all setups (legacy behaviour).
    'final_stop_width_gate_mode'                       => 'adaptive',
    'final_stop_width_warning_pct'                     => 0.05,   // > this → warning (not reject) for A/B synthetic
    'final_stop_width_hard_pct'                        => 0.12,   // > this → hard reject for all classes
    'final_stop_width_warning_for_synthetic_setup'     => true,
    'final_stop_width_hard_block_for_synthetic_setup'  => true,
    // Set of setup classes that use the adaptive (warn-then-cap) stop-width gate.
    'final_stop_width_warn_setup_classes' => [
        'classic_intraday_double_bottom_reclaim',
        'post_dump_base_reclaim',
    ],
    // Minimum quality score required to allow adaptive wide-stop bypass for A/B synthetic setups.
    // 0.0 = disabled (allow any quality); set to e.g. 0.72 to require strong quality.
    'final_stop_width_adaptive_min_quality_score' => 0.72,

    // ── OrderBook Wall Context entry gate ─────────────────────────────────────
    // Fetches OBC wall context only after cheap filters pass (serious candidates only).
    // Default mode: soft_demote — tag signal with wall risk, do not hard-reject.
    'orderbook_entry_wall_gate_enabled'              => false,
    'orderbook_entry_wall_gate_mode'                 => 'soft_demote',   // soft_demote | hard_reject
    // Only fetch OBC for candidates with quality_score >= this value (0 = always fetch)
    'orderbook_entry_wall_fetch_after_quality_score' => 0.0,
    // Proximity threshold: ask/bid wall within this % of entry = risk/support
    'orderbook_entry_wall_near_pct'                  => 1.5,
    // Require wall to be 'persistent' (tracked across ticks) before treating as risk
    'orderbook_entry_wall_persistent_required'       => true,
    // Add support bonus when a persistent bid wall is below entry
    'orderbook_entry_wall_support_bonus_enabled'     => true,
    // Add continuation bonus when the nearest ask wall was eaten/broken
    'orderbook_entry_wall_ask_eaten_bonus_enabled'   => true,
    // Mark signal as pending (not hard-reject) when ask wall risk detected
    'orderbook_entry_wall_pending_enabled'           => false,
    // When ob_soft_demoted=true: prevent executable handoff (default true for safe demo testing).
    // Set false to revert to annotation-only mode (old behaviour: tag signal but allow handoff).
    'orderbook_entry_wall_soft_demote_blocks_handoff' => true,
    'reverse_pattern_close_enabled'=> false,
    'tp_enabled'                   => true,   // take-profit enabled
    'tp_mode'                      => 'fixed_r',   // fixed_r | fixed_price
    'tp_value'                     => 2.5,    // 2.5 R take-profit target

    // Bot execution parameters
    'bot_budget'   => 0.0,
    'bot_leverage' => 1,
    'entry_mode'   => 'limit',    // limit | market

    // Fibonacci extensions
    'fibo_enabled' => false,

    // Continuous scan
    'continuous_scan_enabled' => true,

    // Batching / scan run controls
    'batch_size'           => 50,
    'max_symbols_per_run'  => 0,

    // Coin trend context and entry quality gate
    'coin_trend_context_enabled'         => true,
    'trend_lookback_short_candles'       => 60,
    'trend_lookback_mid_candles'         => 240,
    'trend_lookback_long_candles'        => 720,

    'active_downtrend_block_enabled'     => true,
    'max_recent_lower_low_count'         => 2,
    'max_recent_down_slope_pct'          => -1.5,
    'max_recent_dump_15m_pct'            => 5.0,
    'max_recent_dump_1h_pct'             => 9.0,

    'post_dump_stabilization_enabled'           => true,
    'post_dump_min_drop_from_recent_high_pct'   => 4.0,
    'post_dump_max_drop_from_recent_high_pct'   => 25.0,
    'post_dump_lookback_candles'                => 240,

    'stabilization_min_bars'             => 12,
    'stabilization_max_range_width_pct'  => 4.0,
    'stabilization_max_down_slope_pct'   => 0.8,
    'stabilization_min_low_hold_bars'    => 6,
    'stabilization_allow_minor_low_break_pct' => 0.6,

    'flat_base_enabled'                  => true,
    'flat_base_lookback_candles'         => 48,
    'flat_base_max_width_pct'            => 3.5,
    'flat_base_min_touches'              => 2,
    'flat_base_volume_cooling_required'  => false,

    'support_resistance_enabled'         => true,
    'sr_lookback_candles'                => 120,
    'support_touch_tolerance_pct'        => 0.4,
    'resistance_touch_tolerance_pct'     => 0.4,
    'min_support_touches'                => 2,
    'min_resistance_touches'             => 1,

    'reclaim_after_flat_required'        => true,
    'reclaim_min_close_above_base_pct'   => 0.4,  // legacy alias
    'reclaim_min_close_above_level_pct'  => 0.4,
    'reclaim_confirm_bars'               => 2,
    'max_entry_distance_from_reclaim_pct' => 2.5,

    'bearish_reversal_exception_enabled'                   => true,
    'bearish_reversal_requires_post_dump_stabilization'    => true,
    'bearish_reversal_requires_flat_base'                  => true,
    'bearish_reversal_requires_reclaim'                    => true,

    'min_reversal_context_score'         => 7.0,
    'min_entry_context_score'            => 7.5,

    // ── Intraday setup classification ────────────────────────────────────────
    // Classifies each symbol into one of three setup classes:
    //   A: classic_intraday_double_bottom_reclaim — IDB + neckline reclaim
    //   B: post_dump_base_reclaim                — dump → stab → flat → support hold → reclaim
    //   C: diagnostic_recovery_context           — recovery context but no confirmed reclaim/IDB
    // Signal eligibility is controlled by setup_class_handoff_allowed (see below).
    // C-class symbols are always in diagnostic_setup_classes and never emit signals.
    'intraday_setup_classification_enabled'              => true,
    // Only symbols whose setup_class is in this list may emit signals.
    'setup_class_handoff_allowed'                        => ['classic_intraday_double_bottom_reclaim', 'post_dump_base_reclaim'],
    // Classes that are diagnostic only (never allowed to emit signals, regardless of other config).
    'diagnostic_setup_classes'                           => ['diagnostic_recovery_context'],

    // A-class score threshold (reuses intraday double-bottom score)
    'intraday_double_bottom_min_score'                   => 7.5,

    // B-class: post-dump base reclaim requirements
    'post_dump_base_reclaim_enabled'                     => true,
    'post_dump_base_reclaim_min_score'                   => 7.5,
    'post_dump_base_reclaim_requires_support_hold'       => true,
    'post_dump_base_reclaim_requires_reclaim'            => true,
    // max entry distance reuses max_entry_distance_from_reclaim_pct = 2.5

    // Allow B-class signal without a strict classic double-bottom pattern on H4
    'allow_post_dump_base_reclaim_without_classic_double_bottom' => true,

    // Allow A-class (classic_intraday_double_bottom_reclaim) to skip the H4 PatternDoubleBottom gate.
    // Default false means H4 pattern is still required even for A-class.
    // Set to false to let the intraday DB + neckline reclaim alone suffice for A-class.
    'require_h4_double_bottom_after_intraday_setup' => false,

    // ── Synthetic setup quality scorer ───────────────────────────────────────
    // When a synthetic candidate was built (H4 PatternDoubleBottom bypassed),
    // use this dedicated quality scorer instead of the old H4 PatternCandidateQuality.
    // The old scorer expects classic H4 structure fields; synthetic candidates are built
    // from intraday fields and would be killed by quality_weak_structure spuriously.
    'synthetic_setup_quality_enabled'                      => true,
    // Minimum scores for A-class (classic_intraday_double_bottom_reclaim)
    'synthetic_quality_min_intraday_db_score'              => 7.5,
    'synthetic_quality_intraday_db_min_score'              => 7.5,    // alias used by scorer
    'synthetic_quality_intraday_db_min_setup_score'        => 7.5,    // A-class setup_class_score threshold
    'synthetic_quality_intraday_db_max_entry_distance_from_neckline_pct' => 2.0,  // A-class neckline distance
    'synthetic_quality_min_setup_class_score'              => 7.5,
    'synthetic_quality_min_entry_context_score'            => 7.5,
    'synthetic_quality_max_entry_distance_from_neckline_pct' => 2.0,
    // A-class: whether to require generic entry_context_score gate.
    // false = downgrade low generic ctx score to a warning, not a hard block.
    // The A-class primary quality metrics are intraday_double_bottom_score and setup_class_score.
    'synthetic_quality_require_generic_entry_context_score_for_intraday_db' => false,
    // Minimum scores for B-class (post_dump_base_reclaim)
    'synthetic_quality_max_entry_distance_from_reclaim_pct' => 2.5,
    // Safety requirements (both A and B class)
    'synthetic_quality_require_neckline_reclaim'           => true,
    'synthetic_quality_require_reclaim_after_flat'         => true,
    'synthetic_quality_require_support_not_broken'         => true,
    'synthetic_quality_require_no_falling_knife'           => true,
    // When true, old H4 structure score is not used to reject synthetic candidates
    'synthetic_quality_bypass_old_h4_structure_score'      => true,

    // ── Pending confirmation storage ─────────────────────────────────────────
    // When a setup passes quality but control check waits for confirm bar,
    // store as pending and recheck on the next tick instead of treating as a final reject.
    'pending_confirmation_enabled'          => true,
    'pending_confirmation_ttl_minutes'      => 15,
    'pending_confirmation_max_items'        => 20,
    'pending_confirmation_recheck_first'    => true,

    // Late good setup (Task 4): high-quality A-class setups where entry is slightly beyond
    // the hard distance cap but within the soft cap.  Instead of rejecting, classify as
    // late_good_setup and create a pending-better-entry entry.
    // Example: GENIUSUSDT — great scores, but 2.2% above neckline when hard cap is 2.0%.
    'synthetic_quality_intraday_db_soft_entry_distance_from_neckline_pct' => 2.5,   // soft cap (late_good_setup zone)
    'synthetic_quality_intraday_db_borderline_distance_pending_enabled'   => true,   // create pending for late_good setups
    'synthetic_quality_intraday_db_borderline_min_score'                  => 8.5,    // min db_score AND setup_score to be late_good

    // H4 final gate mode for setup_allowed A/B signals (Task 5).
    // 'warning' = final_trend_mismatch / final_context_inconsistent become warning-only.
    // 'strict'  = keep hard-reject behaviour (legacy).
    'final_old_h4_gates_mode_for_setup_allowed'  => 'warning',

    // ── Intraday double-bottom detection on entry-context candles ────────────
    // Detects: dump → bottom_1 → neckline bounce → bottom_2/low hold → reclaim
    'intraday_double_bottom_enabled'                     => true,
    'double_bottom_min_separation_bars'                  => 5,     // min bars between b1 and b2
    'double_bottom_max_separation_bars'                  => 80,    // max bars between b1 and b2
    'double_bottom_pivot_window'                         => 2,     // pivot low detection window (bars each side)
    'double_bottom_low_tolerance_pct'                    => 3.0,   // max % difference between the two lows
    'double_bottom_max_second_low_break_pct'             => 1.5,   // max % bottom_2 may be lower than bottom_1
    'double_bottom_neckline_min_bounce_pct'              => 1.5,   // min % neckline is above avg of two lows
    'double_bottom_reclaim_confirm_bars'                 => 2,     // min bars closing above neckline after b2
    'double_bottom_max_entry_distance_from_neckline_pct' => 3.0,   // max % last-close is above neckline

    // Entry context candles — separate short-timeframe feed for
    // dump detection / stabilization / flat-base / reclaim.
    // H4 candles are still used for pattern / trend / corridor / wave.
    'entry_context_enabled'                   => true,
    'entry_context_interval'                  => '1',    // Bybit kline interval (minutes)
    'entry_context_lookback_candles'          => 180,    // 3 h on 1m
    'entry_context_fallback_interval'         => '5',    // used if 1m fetch fails
    'entry_context_fallback_lookback_candles' => 180,    // 15 h on 5m

    // Lazy entry-context fetch: only fetch 1m/5m candles when a symbol
    // survives cheap H4 filters (trend / corridor / wave).
    // This avoids API load for symbols that will be hard-rejected anyway.
    'entry_context_lazy_fetch_enabled'             => true,   // master toggle for lazy fetch
    'entry_context_fetch_after_prefilters'         => true,   // skip if all H4 gates fail
    'entry_context_fetch_for_rejected_diagnostics' => false,  // also fetch for already-rejected symbols
    'entry_context_max_symbols_per_tick'           => 50,     // max ctx fetches per cron tick

    // Cheap entry-context prefilter: score H4 signals to decide whether a symbol
    // is a plausible reversal candidate before paying for the 1m/5m API call.
    // Bearish regime alone must NOT trigger a fetch — only concrete H4 signals do.
    'entry_context_prefilter_enabled'                        => true,
    'entry_context_prefilter_min_score'                      => 2.0,
    'entry_context_prefilter_allow_bullish_trend'            => true,
    'entry_context_prefilter_allow_corridor_bottom'          => true,
    'entry_context_prefilter_allow_corrective_wave'          => true,
    'entry_context_prefilter_allow_h4_dump_candidate'        => true,
    'entry_context_prefilter_max_distance_from_corridor_low_pct' => 8.0,
    'entry_context_prefilter_min_h4_drop_from_recent_high_pct'   => 3.0,
    'entry_context_prefilter_h4_dump_lookback_candles'       => 24,

    'max_runtime_seconds'  => 55,

    // Handoff signal freshness gates
    // Signals older than this many minutes will not be written to bot_handoff_queue.
    'handoff_signal_max_age_minutes'           => 10,
    // Only signals produced or revalidated in the current run window (5-min) are handoff-eligible.
    'signal_requires_current_run_for_handoff'  => true,
    // A signal blocked by Symbol Freeze / Symbol Blacklist must be revalidated by a fresh run
    // before it can become handoff-ready again.
    'require_revalidation_after_symbol_block'  => true,

    // Strategy-local scan suppression cache.
    // Skips expensive 1m entry-context fetches for symbols recently confirmed as bad setups.
    // This is NOT a trading blacklist — it only reduces repeated API load for unsuitable symbols.
    'scan_suppression_enabled'             => true,
    'scan_suppression_default_ttl_minutes' => 60,
    'scan_suppression_short_ttl_minutes'   => 15,
    'scan_suppression_medium_ttl_minutes'  => 30,
    'scan_suppression_long_ttl_minutes'    => 60,
    'scan_suppression_storage_file'        => 'storage/scan_suppression.json',
    // Per-run hard cap on expensive 1m entry-context fetches.
    // Cheap H4/ticker prefilters may inspect larger batches; only ctx fetches are capped.
    'max_entry_context_fetch_per_run'      => 35,

    // bad_accept diagnostics: detect high-score emitted signals that moved adversely.
    // Reads the active bot_handoff_queue entries and compares current H4 close vs entry_price.
    // This is diagnostic-only — does not affect signal emission or suppression.
    'bad_accept_diagnostic_enabled'        => true,
    'bad_accept_adverse_pct_threshold'     => 3.0,   // current price < entry_price * (1 - threshold/100)
    'bad_accept_quality_score_threshold'   => 0.6,   // minimum candidate_quality_score or pattern_score

    // Candle data
    'lookback_candles'  => 120,
    'bybit_base_url'    => 'https://api.bybit.com',
    'bybit_timeout_sec' => 10,

    // ── Hourly performance statistics ────────────────────────────────────────
    // Reads closed_trades.json and groups double_bottom_long trade outcomes by
    // the hour (UTC) the trade was opened. Diagnostics only — no trading behavior
    // is changed by this output.
    'hourly_stats_enabled'                  => true,
    'hourly_stats_file'                     => 'storage/hourly_stats.json',
    'hourly_stats_min_samples_for_signal'   => 10,
    'hourly_stats_bad_avg_roi_threshold'    => -5.0,
    'hourly_stats_bad_winrate_threshold'    => 40.0,
    'hourly_stats_good_avg_roi_threshold'   => 5.0,
    'hourly_stats_good_winrate_threshold'   => 60.0,

    // ── DBL garbage veto — conservative pre-handoff trash filter ─────────────
    // Runs after normal DBL detection. Blocks obvious trash signals before they
    // reach bot_handoff_queue. Does NOT change detection logic; only gates handoff.
    'dbl_garbage_veto_enabled'                            => true,

    // Hard veto 1: low quality + generic warning + no OBC confirmation
    'dbl_garbage_low_quality_max_score'                   => 0.70,   // block if candidate_quality_score <= this
    'dbl_garbage_low_quality_requires_generic_warning'    => true,   // also require generic_entry_context_score_low warning
    'dbl_garbage_low_quality_requires_obc_missing_or_skipped' => true,  // also require OBC not checked / skipped

    // Hard veto 2: OBC skipped specifically due to quality_below_threshold
    'dbl_garbage_block_obc_quality_skip'                  => true,
    'dbl_garbage_obc_quality_skip_max_score'              => 0.72,   // block if ob_skip_reason=quality_below_threshold AND score <= this

    // Hard veto 3: late daily extension long
    // Block if already strongly extended on 24h and entry is near the upper daily range.
    'dbl_garbage_daily_extension_enabled'                 => true,
    'dbl_garbage_day_change_hot_pct'                      => 35.0,   // 24h price change >= this → extended
    'dbl_garbage_position_in_24h_range_max_pct'           => 80.0,   // position in 24h range >= this → near top
    'dbl_garbage_min_room_to_24h_high_roi'                => 10.0,   // room to 24h high < this (with day_change >= 45) → block

    // Hard veto 4: whipsaw + weak quality
    // Blocks only if whipsaw metrics exceed threshold AND quality is weak.
    // Strong/high-quality signals survive even when volatility is high.
    'dbl_garbage_whipsaw_enabled'                         => true,
    'dbl_garbage_whipsaw_max_10m_range_roi'               => 15.0,   // recent 10m range ROI > this → whipsaw flag
    'dbl_garbage_whipsaw_max_60m_direction_flips'         => 10,     // recent 60m direction flip count > this → whipsaw flag
    'dbl_garbage_whipsaw_requires_weak_quality'           => true,   // hard block only if quality is also weak
    'dbl_garbage_whipsaw_weak_quality_max_score'          => 0.72,   // "weak quality" threshold

    // Hard veto 5: late local entry after DBL recovery leg already spent.
    // Quality-aware mode:
    //   low quality  (q < low_quality_max)         -> block on >=2 flags
    //   mid quality  (low_quality_max <= q < high) -> block on >=3 flags
    //   high quality (q >= high_quality_min)       -> block on >=3 flags
    //                                                AND near_high=true OR tiny_room=true
    'dbl_garbage_late_local_entry_enabled'                => true,
    'dbl_garbage_max_entry_distance_from_point3_pct'      => 1.2,
    'dbl_garbage_max_post_point3_impulse_spent_pct'       => 70.0,
    'dbl_garbage_min_room_to_recent_swing_high_roi'       => 5.0,
    'dbl_garbage_near_recent_swing_high_pct'              => 0.35,
    'dbl_garbage_late_local_quality_aware_enabled'        => true,
    'dbl_garbage_late_local_low_quality_max'              => 0.78,
    'dbl_garbage_late_local_high_quality_min'             => 0.82,
    'dbl_garbage_late_local_flags_required_low_quality'   => 2,
    'dbl_garbage_late_local_flags_required_mid_quality'   => 3,
    'dbl_garbage_late_local_flags_required_high_quality'  => 3,
    'dbl_garbage_late_local_high_quality_requires_near_high_or_tiny_room' => true,
    'dbl_garbage_tiny_room_to_recent_swing_high_roi'      => 2.0,

    // Hard veto 6: missing critical DBL trace (point3 / entry-distance null).
    // Blocks handoff when the signal lacks point3/neckline/entry-distance metrics
    // that are required to evaluate late-entry risk, unless the signal is very
    // strong and OBC was checked with no ask-wall risk.
    // Bypass requires: quality >= min_quality_to_bypass AND OBC checked AND no ask risk AND no generic warning.
    'dbl_garbage_block_missing_critical_trace'              => true,
    'dbl_garbage_missing_trace_min_quality_to_bypass'       => 0.82,
    'dbl_garbage_missing_trace_requires_obc_confirmed_to_bypass' => true,

    // Hard veto 7: reclaim not confirmed for medium-quality signals.
    // Blocks handoff when quality is at or below the threshold and no reclaim
    // confirmation is available (reclaim_confirmed, neckline_reclaim_confirmed,
    // or reclaim_after_flat_detected).
    'dbl_garbage_require_reclaim_confirmation_for_medium_quality' => true,
    'dbl_garbage_reclaim_confirmation_medium_quality_max'         => 0.78,

    // Veto 8: late-local + tiny-room + no reclaim confirmation.
    // Targets entries far from point3, with tiny room to recent swing high,
    // and no reclaim evidence (reclaim_confirmed, neckline_reclaim_confirmed,
    // reclaim_after_flat_detected, or reclaim_retest_held all false).
    // diagnostic_only=true means only a soft warning is written; the signal still passes.
    // Set diagnostic_only=false to enable the hard block once the pattern is confirmed.
    'dbl_garbage_late_local_tiny_room_diagnostic_only' => true,
    'dbl_garbage_late_local_tiny_room_roi'             => 2.0,
    'dbl_garbage_late_local_far_point3_pct'            => 1.8,

    // ── Throughput health thresholds ──────────────────────────────────────────
    // Diagnostics only — no auto-loosening based on these.
    'dbl_expected_min_handoff_per_6h'                  => 3,

    // ── Trend-shift confirmation gate (final gate before Bot handoff) ─────────
    // Runs after garbage veto. Blocks handoff for signals where the DBL reversal
    // is not yet confirmed by at least one strong confirmation path.
    // Confirmed paths: reclaim_hold | retest_hold | higher_low_after_point3 | short_structure_break
    // Unconfirmed high/mid-quality signals become pending/watch (not hard rejected)
    // and are rechecked on each subsequent tick until confirmed or TTL expires.
    'dbl_trend_shift_gate_enabled'                             => true,
    'dbl_trend_shift_required_for_handoff'                     => true,
    'dbl_trend_shift_min_closes_above_reclaim'                 => 2,
    'dbl_trend_shift_reclaim_hold_minutes'                     => 2,
    'dbl_trend_shift_max_reclaim_loss_pct'                     => 0.20,
    'dbl_trend_shift_require_no_fresh_lower_low'               => true,
    'dbl_trend_shift_point3_break_tolerance_pct'               => 0.20,
    'dbl_trend_shift_allow_high_quality_pending'               => true,
    'dbl_trend_shift_high_quality_threshold'                   => 0.82,
    'dbl_trend_shift_pending_ttl_minutes'                      => 10,
    'dbl_trend_shift_pending_recheck_enabled'                  => true,
    // ── DBL pattern-status state machine (primary handoff gate) ───────────────
    // Flow:
    //   raw_candidate -> active -> confirmed -> (then) garbage_veto -> handoff_ready
    //   raw/active    -> invalid
    // Only confirmed patterns can proceed to garbage veto / Bot handoff.
    'dbl_pattern_status_enabled'                               => true,
    'dbl_pattern_confirmation_required_for_handoff'            => true,
    'dbl_pattern_min_closes_above_neckline'                    => 2,
    'dbl_pattern_reclaim_hold_bars'                            => 2,
    'dbl_pattern_reclaim_hold_minutes'                         => 2,
    'dbl_pattern_point3_break_tolerance_pct'                   => 0.20,
    'dbl_pattern_higher_low_tolerance_pct'                     => 0.15,
    'dbl_pattern_pending_enabled'                              => true,
    'dbl_pattern_pending_ttl_minutes'                          => 10,
    'dbl_pattern_pending_recheck_enabled'                      => true,
    'dbl_pattern_pending_max_items'                            => 100,
    'dbl_pattern_allow_active_to_pending'                      => true,
    'dbl_pattern_allow_confirmed_to_handoff'                   => true,
    // Point3 break refinement (diagnostics + pending recovery watch)
    'dbl_point3_recovery_watch_enabled'                        => true,
    'dbl_point3_break_terminal_requires_current_below_point3'  => true,
    'dbl_point3_break_recovery_requires_reclaim_recovered'     => true,
    'dbl_point3_recovery_watch_ttl_minutes'                    => 10,
    'dbl_point3_recovery_min_confirm_bars'                     => 2,
    // ── DBL filter-audit calibration mode ─────────────────────────────────────
    'dbl_filter_audit_mode_enabled'                            => true,
    'dbl_filter_audit_mode_demo_only'                          => true,
    'dbl_filter_audit_send_to_bot'                             => true,
    'dbl_filter_audit_only_when_no_normal_ready'               => true,
    'dbl_filter_audit_max_signals_per_cycle'                   => 5,
    'dbl_filter_audit_max_signals_per_30m'                     => 15,
    'dbl_filter_audit_max_signals_per_6h'                      => 999,
    'dbl_filter_audit_min_quality_score'                       => 0.65,
    'dbl_filter_audit_positive_roi_threshold'                  => 2.0,
    'dbl_filter_audit_negative_roi_threshold'                  => -2.0,
    'dbl_filter_audit_require_no_fatal_break'                  => true,
    'dbl_filter_audit_ready_ttl_minutes'                       => 60,
    'dbl_filter_audit_stale_ready_action'                      => 'withdraw',
    // ── DBL confirmed-pattern freshness override ──────────────────────────────
    // For signals with dbl_pattern_status=confirmed, bypass the generic current-run
    // freshness window (300 s) and use confirmed-pattern TTL + validity check instead.
    // This prevents valid confirmed patterns from being withdrawn by the generic gate.
    'dbl_confirmed_pattern_ttl_minutes'                        => 10,
    'dbl_confirmed_pattern_require_price_still_valid'          => true,
    'dbl_confirmed_pattern_max_age_before_handoff_minutes'     => 10,
];
