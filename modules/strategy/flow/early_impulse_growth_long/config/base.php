<?php

declare(strict_types=1);

return [
    'strategy_id' => 'early_impulse_growth_long',
    'enabled' => false,
    'handoff_enabled' => false,
    'emit_bot_handoff' => false,
    'max_handoff_signals_per_tick' => 5,
    'bot_ready_ttl_minutes' => 10,
    'mode' => 'passive',
    'side' => 'long',

    'batch_size' => 100,
    'max_symbols_per_run' => 100,
    'continuous_scan_enabled' => true,
    'auto_requeue_when_done' => true,

    // Legacy recovery window diagnostics (kept for compatibility)
    'recovery_window_minutes' => 180,
    'recovery_min_window_minutes' => 120,
    'recovery_max_window_minutes' => 240,
    'recovery_min_duration_minutes' => 120,
    'recovery_duration_rule_mode' => 'phase_based',

    // Legacy prior decline diagnostics
    'prior_decline_lookback_minutes' => 240,
    'min_prior_decline_pct' => 2.0,

    // Legacy recovery growth diagnostics
    'recovery_score_mode' => 'threshold',
    'min_recovery_growth_pct' => 3.0,
    'min_recovery_score' => 0.55,
    'min_combined_recovery_score' => 0.50,
    'recovery_score_target_pct' => 8.0,
    'max_recovery_growth_pct' => 30.0,

    // Phase-based entry: dump detection
    'dump_lookback_minutes' => 120,
    'min_dump_pct' => 2.0,
    'max_dump_age_minutes' => 240,

    // Phase-based entry: stabilization
    'stabilization_min_minutes' => 10,
    'stabilization_max_minutes' => 45,
    'stabilization_max_range_pct' => 1.5,
    'stabilization_allow_slight_growth_pct' => 1.0,
    'stabilization_max_new_low_break_pct' => 0.3,

    // Phase-based entry: smooth growth
    'smooth_growth_window_minutes' => 10,
    'smooth_growth_min_minutes' => 5,
    'smooth_growth_min_pct' => 0.5,
    'smooth_growth_max_pct' => 2.5,
    'smooth_growth_min_higher_close_count' => 2,
    'smooth_growth_min_higher_low_count' => 1,
    'smooth_growth_max_single_candle_dominance_pct' => 65.0,

    // Open interest confirmation
    'open_interest_enabled' => true,
    'min_open_interest_growth_pct' => 1.0,
    'min_open_interest_growth_score' => 0.55,
    'open_interest_score_target_pct' => 5.0,
    'allow_missing_open_interest' => true,
    'missing_open_interest_mode' => 'diagnostic_only',

    // Late / extended guard
    'late_spike_price_change_10m_pct' => 2.0,
    'late_spike_roi_equivalent_leverage' => 5.0,
    'late_spike_roi_equivalent_threshold' => 10.0,
    'block_late_spike_handoff' => true,
    'extended_recovery_growth_pct' => 8.0,
    'block_extended_recovery_handoff' => true,

    // Current acceleration (diagnostic only — not a reject condition)
    'current_acceleration_window_minutes' => 10,
    'fast_spike_diagnostic_enabled' => true,
    'fast_spike_window_minutes' => 10,
    'fast_spike_price_change_pct' => 2.0,
    'fast_spike_roi_equivalent_leverage' => 5,
    'fast_spike_roi_equivalent_threshold' => 10.0,

    // FilterEngine
    'filter_engine_enabled' => true,
    'filter_enforcement_mode' => 'diagnostic_only',
    'filter_profile' => 'raw_no_filters',
    'filter_profile_active' => 'raw_no_filters',
    'enabled_filters' => [],
    'disabled_filters' => [],
    'filter_config' => [],

    // Coin context (diagnostic-only enrichment)
    'coin_context_enabled' => true,
    'coin_context_attach_to_candidates' => true,
    'coin_context_attach_to_signals' => true,
    'coin_context_fail_open' => true,

    // Data sources
    'parser2_history_lookback_minutes' => 500,
    'max_data_staleness_seconds' => 300,
    'bybit_base_url' => 'https://api.bybit.com',
    'bybit_timeout_sec' => 6,
    'bybit_kline_limit' => 500,
    'bybit_oi_interval' => '5min',
    'bybit_oi_limit' => 2,

    // Storage caps
    'max_evaluated_store' => 4000,
    'max_candidates_store' => 2000,
    'max_near_pass_store' => 2000,
    'max_rejects_store' => 2000,
    'max_signals_store' => 1000,
];
