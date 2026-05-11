<?php

declare(strict_types=1);

return [
    'strategy_id' => 'string',
    'enabled' => 'bool',
    'handoff_enabled' => 'bool',
    'emit_bot_handoff' => 'bool',
    'max_handoff_signals_per_tick' => 'int',
    'bot_ready_ttl_minutes' => 'int',
    'mode' => 'string',
    'side' => 'string',

    'batch_size' => 'int',
    'max_symbols_per_run' => 'int',
    'continuous_scan_enabled' => 'bool',
    'auto_requeue_when_done' => 'bool',

    'recovery_window_minutes' => 'int',
    'recovery_min_window_minutes' => 'int',
    'recovery_max_window_minutes' => 'int',
    'recovery_min_duration_minutes' => 'int',
    'recovery_duration_rule_mode' => 'string',

    'prior_decline_lookback_minutes' => 'int',
    'min_prior_decline_pct' => 'float',

    'recovery_score_mode' => 'string',
    'min_recovery_growth_pct' => 'float',
    'min_recovery_score' => 'float',
    'min_combined_recovery_score' => 'float',
    'recovery_score_target_pct' => 'float',
    'max_recovery_growth_pct' => 'float',

    'dump_lookback_minutes' => 'int',
    'min_dump_pct' => 'float',
    'max_dump_age_minutes' => 'int',

    'stabilization_min_minutes' => 'int',
    'stabilization_max_minutes' => 'int',
    'stabilization_max_range_pct' => 'float',
    'stabilization_allow_slight_growth_pct' => 'float',
    'stabilization_max_new_low_break_pct' => 'float',

    'smooth_growth_window_minutes' => 'int',
    'smooth_growth_min_minutes' => 'int',
    'smooth_growth_min_pct' => 'float',
    'smooth_growth_max_pct' => 'float',
    'smooth_growth_min_higher_close_count' => 'int',
    'smooth_growth_min_higher_low_count' => 'int',
    'smooth_growth_max_single_candle_dominance_pct' => 'float',

    'open_interest_enabled' => 'bool',
    'min_open_interest_growth_pct' => 'float',
    'min_open_interest_growth_score' => 'float',
    'open_interest_score_target_pct' => 'float',
    'allow_missing_open_interest' => 'bool',
    'missing_open_interest_mode' => 'string',

    'late_spike_price_change_10m_pct' => 'float',
    'late_spike_roi_equivalent_leverage' => 'float',
    'late_spike_roi_equivalent_threshold' => 'float',
    'block_late_spike_handoff' => 'bool',
    'extended_recovery_growth_pct' => 'float',
    'block_extended_recovery_handoff' => 'bool',

    'current_acceleration_window_minutes' => 'int',
    'fast_spike_diagnostic_enabled' => 'bool',
    'fast_spike_window_minutes' => 'int',
    'fast_spike_price_change_pct' => 'float',
    'fast_spike_roi_equivalent_leverage' => 'float',
    'fast_spike_roi_equivalent_threshold' => 'float',

    'filter_engine_enabled' => 'bool',
    'filter_enforcement_mode' => 'string',
    'filter_profile' => 'string',
    'filter_profile_active' => 'string',
    'enabled_filters' => 'array',
    'disabled_filters' => 'array',
    'filter_config' => 'array',

    'parser2_history_lookback_minutes' => 'int',
    'max_data_staleness_seconds' => 'int',
    'bybit_base_url' => 'string',
    'bybit_timeout_sec' => 'int',
    'bybit_kline_limit' => 'int',
    'bybit_oi_interval' => 'string',
    'bybit_oi_limit' => 'int',

    'max_evaluated_store' => 'int',
    'max_candidates_store' => 'int',
    'max_near_pass_store' => 'int',
    'max_rejects_store' => 'int',
    'max_signals_store' => 'int',
];
