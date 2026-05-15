<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'mode' => 'diagnostic_only',
    'supported_strategy_id' => 'early_impulse_growth_long',

    'collect_entry_snapshots_enabled' => true,
    'observe_active_positions_enabled' => true,
    'analyze_closed_outcomes_enabled' => true,
    'build_dynamic_profile_enabled' => true,

    'apply_learning_to_strategy_enabled' => false,
    'apply_learning_to_live_enabled' => false,
    'apply_learning_to_demo_enabled' => false,

    'observation_interval_seconds' => 30,
    'max_observations_per_position' => 40,
    'observe_orderbook_enabled' => true,
    'observe_open_interest_enabled' => true,
    'observe_price_enabled' => true,

    'risk_profile_mode' => 'working_real',
    'bad_drawdown_roi_threshold' => -12.0,
    'hard_stop_reference_roi' => -15.0,
    'good_close_roi_threshold' => 8.0,
    'good_max_profit_roi_threshold' => 8.0,
    'stop_slippage_buffer_roi' => 3.0,
    'pm_profit_reference_roi' => 10.0,
    'outcome_classification_profile' => 'working_real_8_15',
    'neutral_close_roi_min' => -2.0,
    'neutral_close_roi_max' => 2.0,

    'min_closed_outcomes_for_profile' => 10,
    'min_bad_entries_for_rule' => 2,
    'min_bad_blocked_for_rule' => 2,
    'max_good_blocked_for_rule' => 1,
    'min_rule_net_score' => 1.0,

    'rollback_guard_enabled' => true,
    'rollback_drawdown_pct' => 7.0,
    'rollback_bad_trade_streak' => 3,
    'profile_history_enabled' => true,

    // Timing tolerance for outcome opened_at correction
    'outcome_opened_at_mismatch_tolerance_minutes' => 15,
    'prefer_entry_snapshot_time_on_signal_match' => true,

    // Profile comparison: default vs auto-generated
    'compare_auto_vs_default_enabled' => true,
    'auto_profile_requires_not_worse_than_default' => true,
    'auto_apply_enabled' => false,

    // Reset-aware persistence
    'preserve_outcomes_when_source_empty' => true,
    'rebuild_closed_outcomes_from_ndjson_enabled' => true,
    'respect_manual_storage_reset' => true,
    'storage_reset_marker_file' => 'storage/reset_marker.json',
    'manual_reset_epoch' => null,

    // Feature pipeline
    'feature_pipeline_enabled' => true,
    'candle_micro_analyzer_enabled' => true,
    'dump_micro_analyzer_enabled' => true,
    'impulse_birth_analyzer_enabled' => true,
    'trend_context_analyzer_enabled' => true,
    'orderbook_snapshot_analyzer_enabled' => true,
    'weighted_scoring_enabled' => true,
    'clean_learning_start_enabled' => true,
    'clear_strategy_runtime_on_clean_start' => false,
    'clear_dynamic_learning_outcomes_on_clean_start' => false,
    'clear_dynamic_learning_features_on_clean_start' => true,
    'clear_dynamic_learning_snapshots_on_clean_start' => false,
    'clear_dynamic_learning_observations_on_clean_start' => false,
    'micro_data_source_primary' => 'bybit',
    'micro_data_source_fallback' => 'parser2',
    'bybit_base_url' => 'https://api.bybit.com',
    'bybit_timeout_sec' => 6,
    'bybit_kline_interval' => 1,
    'bybit_kline_limit' => 120,
    'bybit_micro_fetch_enabled' => true,
    'bybit_micro_fetch_only_for_strategy' => 'early_impulse_growth_long',
    'dump_micro_lookback_minutes' => 60,
    'dump_micro_min_candles' => 10,
    'micro_primary_window' => 'micro_window_10m',

    // Real-learning epoch: keep fast-demo history but exclude it from active real profile mining
    'real_learning_epoch_enabled' => true,
    'real_learning_epoch_start_at' => null,
    'real_learning_epoch_id' => 'auto',
    'ignore_fast_demo_outcomes_in_real_profile' => true,
    'preserve_fast_demo_history' => true,

    // Backward-compatible micro-learning keys (kept for existing runtime consumers)
    'micro_learning_epoch_enabled' => true,
    'micro_learning_epoch_id' => 'auto',
    'micro_learning_ignore_legacy_outcomes_before_epoch' => true,
    'micro_learning_epoch_start_at' => null,

    // Supported strategies
    'supported_strategy_ids' => ['early_impulse_growth_long'],
    'active_strategy_id' => 'early_impulse_growth_long',

    // Rolling learning guard
    'rolling_learning_enabled' => true,
    'rolling_learning_window_minutes' => 120,
    'rolling_retrain_interval_minutes' => 60,
    'rolling_min_closed_outcomes' => 20,
    'rolling_min_bad_entries' => 3,
    'rolling_min_good_entries' => 3,

    // Quality guard thresholds
    'min_candidate_improvement_pct' => 7.0,
    'no_change_band_pct' => 5.0,
    'max_allowed_quality_degradation_pct' => 10.0,
    'max_allowed_winrate_degradation_pct' => 10.0,
    'max_allowed_avg_roi_degradation_pct' => 10.0,
    'max_allowed_bad_entry_rate_increase_pct' => 10.0,
    'max_allowed_drawdown_increase_pct' => 10.0,

    // Quality score weights
    'quality_weight_good_capture' => 1.0,
    'quality_weight_avg_roi' => 1.0,
    'quality_weight_bad_entry' => 1.5,
    'quality_weight_drawdown' => 1.0,
    'quality_weight_entry_ok_exit_issue' => 0.5,

    // Rollback guard
    'rollback_cooldown_minutes' => 120,
    'rollback_to' => 'previous_good_or_default',

    // Candidate profile builder + replay evaluator
    'candidate_min_separation_score' => 0.20,
    'candidate_max_good_block_rate_pct' => 20.0,
    'candidate_min_bad_capture_rate_pct' => 20.0,
    'candidate_max_rules' => 10,
    'candidate_allow_broad_features' => false,
    'candidate_replay_enabled' => true,

    // Composite candidate builder (diagnostic only)
    'composite_candidate_enabled' => true,
    'composite_candidate_max_components' => 3,
    'composite_candidate_min_components' => 2,
    'composite_candidate_max_candidates_to_test' => 100,
    'composite_candidate_min_bad_capture_rate_pct' => 20.0,
    'composite_candidate_max_good_block_rate_pct' => 20.0,
    'composite_candidate_min_net_score' => 1.0,
    'composite_candidate_allow_broad_secondary' => true,
    'composite_candidate_primary_features_only_micro' => true,

    // Apply guard
    'auto_apply_to_demo_enabled' => false,
    'auto_apply_to_live_enabled' => false,
    'require_not_worse_than_default' => true,

    // Storage limits
    'max_storage_size_mb' => 100,
    'max_cycle_history_lines' => 1000,
    'max_cycle_history_size_mb' => 20,
    'max_entry_snapshots' => 700,
    'max_entry_snapshots_ndjson_size_mb' => 10,
    'max_feature_records' => 700,
    'max_features_ndjson_size_mb' => 10,
    'max_closed_outcomes' => 1000,
    'max_active_observation_files' => 500,
    'max_examples_per_last_run_section' => 10,
    'max_candidate_history_records' => 300,
    'max_rollback_history_records' => 200,
    'max_profile_history_lines' => 300,
    'max_profile_history_size_mb' => 3,
];
