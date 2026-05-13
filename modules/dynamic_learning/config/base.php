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

    'bad_drawdown_roi_threshold' => -10.0,
    'good_close_roi_threshold' => 5.0,
    'good_max_profit_roi_threshold' => 5.0,
    'outcome_classification_profile' => 'fast_demo_stop_5',
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

    // Micro-learning epoch: separate pre-reset outcomes from new post-reset micro features
    'micro_learning_epoch_enabled' => true,
    'micro_learning_epoch_id' => 'auto',
    'micro_learning_ignore_legacy_outcomes_before_epoch' => true,
    'micro_learning_epoch_start_at' => null,

    // Supported strategies
    'supported_strategy_ids' => ['early_impulse_growth_long'],
    'active_strategy_id' => 'early_impulse_growth_long',

    // Storage limits
    'max_storage_size_mb' => 150,
    'max_cycle_history_lines' => 1000,
    'max_cycle_history_size_mb' => 20,
    'max_entry_snapshots' => 2000,
    'max_feature_records' => 2000,
    'max_closed_outcomes' => 1000,
    'max_active_observation_files' => 500,
    'max_examples_per_last_run_section' => 10,
];
