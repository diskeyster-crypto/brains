<?php

declare(strict_types=1);

namespace Modules\DynamicLearning;

require_once __DIR__ . '/analyzers/dl_helpers.php';
require_once __DIR__ . '/analyzers/dl_market_data.php';
require_once __DIR__ . '/analyzers/outcome/outcome_classifier.php';
require_once __DIR__ . '/analyzers/patterns/pattern_miner.php';
require_once __DIR__ . '/analyzers/candles/candle_micro_analyzer.php';
require_once __DIR__ . '/analyzers/dump/dump_micro_analyzer.php';
require_once __DIR__ . '/analyzers/impulse/impulse_birth_analyzer.php';
require_once __DIR__ . '/analyzers/trend/trend_context_analyzer.php';
require_once __DIR__ . '/analyzers/orderbook/orderbook_snapshot_analyzer.php';
require_once __DIR__ . '/profiles/profile_builder.php';
require_once __DIR__ . '/profiles/profile_comparator.php';
require_once __DIR__ . '/decision/dynamic_learning_decision.php';

use Modules\DynamicLearning\Analyzers\DlHelpers;
use Modules\DynamicLearning\Analyzers\DlMarketData;
use Modules\DynamicLearning\Analyzers\Outcome\OutcomeClassifier;
use Modules\DynamicLearning\Analyzers\Patterns\PatternMiner;
use Modules\DynamicLearning\Analyzers\Candles\CandleMicroAnalyzer;
use Modules\DynamicLearning\Analyzers\Dump\DumpMicroAnalyzer;
use Modules\DynamicLearning\Analyzers\Impulse\ImpulseBirthAnalyzer;
use Modules\DynamicLearning\Analyzers\Trend\TrendContextAnalyzer;
use Modules\DynamicLearning\Analyzers\Orderbook\OrderbookSnapshotAnalyzer;
use Modules\DynamicLearning\Profiles\ProfileBuilder;
use Modules\DynamicLearning\Profiles\ProfileComparator;
use Modules\DynamicLearning\Decision\DynamicLearningDecision;

/**
 * Dynamic Learning Service — Orchestrator
 *
 * Coordinates the analyzer pipeline:
 * 1. Collect entry snapshots (with feature extraction and feature_source tagging)
 * 2. Observe active positions
 * 3. Link closed outcomes (strong-link-only, timing correction, normalization)
 * 4. Feature extraction pipeline (features.json per strategy)
 * 5. Candle micro, dump micro, impulse birth, trend context, orderbook analyzers (scaffold)
 * 6. Pattern mining (strong-link, timing-valid outcomes only)
 * 7. Profile building (candidate only, no auto-apply)
 * 8. Default vs auto comparison scaffold
 * 9. Storage pruning
 *
 * Architecture: analyzer_pipeline_v1
 */
final class DynamicLearningService
{
    private const STRATEGY_ID = 'early_impulse_growth_long';

    private static ?self $instance = null;
    private string $moduleDir;
    private string $repoRoot;

    public function __construct(?string $moduleDir = null)
    {
        $this->moduleDir = rtrim($moduleDir ?? __DIR__, '/');
        $this->repoRoot = rtrim(dirname($this->moduleDir, 2), '/');
    }

    public static function instance(?string $moduleDir = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($moduleDir);
        }
        return self::$instance;
    }

    /** @return array<string,mixed> */
    public function getConfig(): array { return $this->loadConfig(); }
    /** @return array<string,mixed> */
    public function getLastRun(): array { return (array)$this->readJson($this->storagePath('last_run.json'), []); }

    /** @return array<string,mixed> */
    public function runCycle(): array
    {
        $cfg = $this->loadConfig();
        $runStartedAt = date('c');
        $result = [
            'architecture_version' => 'analyzer_pipeline_v1',
            'enabled' => (bool)$cfg['enabled'],
            'mode' => (string)$cfg['mode'],
            'supported_strategy_id' => (string)$cfg['supported_strategy_id'],
            'entry_snapshots_total' => 0,
            'entry_snapshots_new_total' => 0,
            'active_positions_seen_total' => 0,
            'active_positions_observed_total' => 0,
            'observations_written_total' => 0,
            'closed_trades_loaded_total' => 0,
            'closed_outcomes_matched_total' => 0,
            'closed_outcomes_raw_loaded_total' => 0,
            'closed_outcomes_effective_total' => 0,
            'closed_outcomes_unique_total' => 0,
            'closed_outcomes_duplicates_skipped_total' => 0,
            'closed_outcomes_merged_total' => 0,
            'closed_outcomes_near_time_duplicates_merged_total' => 0,
            'closed_outcomes_duplicate_examples' => [],
            'closed_outcomes_near_time_duplicate_examples' => [],
            'closed_outcomes_strong_link_total' => 0,
            'closed_outcomes_weak_link_skipped_total' => 0,
            'closed_outcomes_preserved_due_empty_source_total' => 0,
            'closed_outcomes_rebuilt_from_ndjson_total' => 0,
            'closed_outcomes_rebuild_skipped_due_reset' => 0,
            'closed_outcomes_time_mismatch_total' => 0,
            'closed_outcomes_opened_at_corrected_total' => 0,
            'closed_outcomes_timing_low_confidence_total' => 0,
            'closed_outcomes_time_mismatch_examples' => [],
            'bad_entry_total' => 0,
            'good_or_do_not_touch_total' => 0,
            'entry_ok_exit_issue_total' => 0,
            'neutral_total' => 0,
            'outcome_incomplete_total' => 0,
            'risk_profile_mode' => (string)($cfg['risk_profile_mode'] ?? 'working_real'),
            'outcome_classification_profile' => (string)($cfg['outcome_classification_profile'] ?? 'working_real_8_15'),
            'closed_outcome_dedupe_closed_at_tolerance_seconds' => (int)($cfg['closed_outcome_dedupe_closed_at_tolerance_seconds'] ?? 0),
            'closed_outcome_time_tolerance_enabled' => (bool)($cfg['closed_outcome_dedupe_use_time_tolerance'] ?? false),
            'effective_bad_drawdown_roi_threshold' => (float)($cfg['bad_drawdown_roi_threshold'] ?? -10.0),
            'effective_good_close_roi_threshold' => (float)($cfg['good_close_roi_threshold'] ?? 5.0),
            'effective_good_max_profit_roi_threshold' => (float)($cfg['good_max_profit_roi_threshold'] ?? 5.0),
            'hard_stop_reference_roi' => (float)($cfg['hard_stop_reference_roi'] ?? -10.0),
            'stop_slippage_buffer_roi' => (float)($cfg['stop_slippage_buffer_roi'] ?? 2.0),
            'pm_profit_reference_roi' => (float)($cfg['pm_profit_reference_roi'] ?? 10.0),
            'stop_profile_alignment' => (string)($cfg['stop_profile_alignment'] ?? ($cfg['outcome_classification_profile'] ?? 'standard_stop_10')),
            'learning_corridor_enabled' => (bool)($cfg['learning_corridor_enabled'] ?? false),
            'bad_learning_zone_roi' => (float)($cfg['bad_drawdown_roi_threshold'] ?? -10.0),
            'good_learning_threshold_roi' => (float)($cfg['good_close_roi_threshold'] ?? 5.0),
            'outcomes_reclassified_total' => 0,
            'outcomes_reclassified_examples' => [],
            'outcome_mfe_normalized_total' => 0,
            'outcome_mae_normalized_total' => 0,
            'outcome_roi_normalization_examples' => [],
            'outcome_excluded_from_pattern_mining_total' => 0,
            'outcome_excluded_reasons' => [],
            'feature_records_total' => 0,
            'feature_time_valid_total' => 0,
            'feature_time_invalid_total' => 0,
            'feature_source_counts' => [],
            'feature_time_invalid_examples' => [],
            'candle_micro_available_total' => 0,
            'dump_micro_available_total' => 0,
            'candle_micro_real_available_total' => 0,
            'candle_micro_proxy_available_total' => 0,
            'candle_micro_missing_total' => 0,
            'dump_micro_real_available_total' => 0,
            'dump_micro_proxy_available_total' => 0,
            'dump_micro_missing_total' => 0,
            'clean_learning_start_enabled' => false,
            'legacy_proxy_features_total' => 0,
            'new_micro_features_total' => 0,
            'bybit_kline_requests_total' => 0,
            'bybit_kline_success_total' => 0,
            'bybit_kline_error_total' => 0,
            'bybit_kline_cache_hit_total' => 0,
            'bybit_kline_unique_fetch_total' => 0,
            'bybit_kline_reused_window_total' => 0,
            'bybit_kline_fetch_cache_key_mode' => 'symbol_entry_interval_limit',
            'parser2_fallback_used_total' => 0,
            'micro_data_missing_total' => 0,
            'micro_impulse_shape_counts' => [],
            'micro_entry_timing_counts' => [],
            'micro_growth_distribution_counts' => [],
            'micro_rejection_risk_counts' => [],
            'dump_shape_counts' => [],
            'post_dump_state_counts' => [],
            'post_dump_impulse_type_counts' => [],
            'micro_pattern_examples' => [],
            'micro_bad_good_overlap_examples' => [],
            'micro_primary_window' => (string)($cfg['micro_primary_window'] ?? 'micro_window_10m'),
            'micro_primary_summary_available_total' => 0,
            'micro_primary_summary_missing_total' => 0,
            'weighted_score_calculated_total' => 0,
            'bad_patterns_total' => 0,
            'micro_separability_enabled' => true,
            'micro_numeric_features_analyzed_total' => 0,
            'micro_label_values_analyzed_total' => 0,
            'micro_high_separation_features_total' => 0,
            'micro_medium_separation_features_total' => 0,
            'micro_mixed_labels_total' => 0,
            'micro_suspicious_labels_total' => 0,
            'top_micro_bad_separators' => [],
            'top_micro_good_separators' => [],
            'micro_mixed_label_examples' => [],
            'micro_suspicious_label_examples' => [],
            'profile_generated' => false,
            'profile_id' => null,
            'profile_rules_total' => 0,
            'profile_rules_observe_only_total' => 0,
            'profile_rules_quarantined_total' => 0,
            'profile_compared_to_default' => false,
            'auto_not_worse_than_default' => false,
            'auto_improvement_score' => null,
            'auto_comparison_reason' => null,
            'real_learning_epoch_enabled' => false,
            'real_learning_epoch_id' => null,
            'real_learning_epoch_start_at' => null,
            'previous_epoch_outcomes_excluded_total' => 0,
            'active_epoch_outcomes_total' => 0,
            'micro_learning_epoch_enabled' => false,
            'micro_learning_epoch_id' => null,
            'micro_learning_epoch_start_at' => null,
            'legacy_outcomes_total' => 0,
            'epoch_outcomes_total' => 0,
            'outcomes_excluded_by_epoch_total' => 0,
            'epoch_start_source' => null,
            'epoch_start_missing_reason' => null,
            'storage_pruned_total' => 0,
            'storage_size_estimate_mb' => null,
            'storage_prune_examples' => [],
            'storage_prune_reason_counts' => [],
            'cycle_history_compact_enabled' => true,
            'cycle_history_last_line_bytes' => 0,
            'cycle_history_pruned_total' => 0,
            'active_observation_files_total' => 0,
            'active_observation_files_pruned_total' => 0,
            'reset_detected' => false,
            'manual_reset_respected' => false,
            'dynamic_filter_available' => is_file($this->repoRoot . '/modules/filter_engine/filters/dynamic_learning_filter.php'),
            'apply_learning_to_strategy_enabled' => (bool)$cfg['apply_learning_to_strategy_enabled'],
            'apply_learning_to_live_enabled' => (bool)$cfg['apply_learning_to_live_enabled'],
            'entry_snapshot_examples' => [],
            'active_observation_examples' => [],
            'bad_entry_examples' => [],
            'good_entry_examples' => [],
            'top_bad_pattern_examples' => [],
            'quarantined_rule_examples' => [],
            // Rolling quality guard defaults
            'rolling_learning_enabled' => (bool)($cfg['rolling_learning_enabled'] ?? true),
            'rolling_learning_window_minutes' => (int)($cfg['rolling_learning_window_minutes'] ?? 120),
            'rolling_retrain_interval_minutes' => (int)($cfg['rolling_retrain_interval_minutes'] ?? 60),
            'rolling_min_closed_outcomes' => (int)($cfg['rolling_min_closed_outcomes'] ?? 20),
            'rolling_window_outcomes_total' => 0,
            'rolling_window_bad_entry_total' => 0,
            'rolling_window_good_entry_total' => 0,
            'rolling_window_entry_ok_exit_issue_total' => 0,
            'default_quality_score' => null,
            'active_dynamic_quality_score' => null,
            'candidate_quality_score' => null,
            'candidate_vs_default_delta_pct' => null,
            'candidate_vs_active_delta_pct' => null,
            'no_change_band_pct' => (float)($cfg['no_change_band_pct'] ?? 5.0),
            'min_candidate_improvement_pct' => (float)($cfg['min_candidate_improvement_pct'] ?? 7.0),
            'default_result_summary' => null,
            'active_dynamic_result_summary' => null,
            'candidate_result_summary' => null,
            'candidate_status' => 'pending',
            'promotion_decision' => 'none',
            'promotion_reason' => null,
            'auto_apply_to_demo_enabled' => (bool)($cfg['auto_apply_to_demo_enabled'] ?? false),
            'auto_apply_to_live_enabled' => (bool)($cfg['auto_apply_to_live_enabled'] ?? false),
            'require_not_worse_than_default' => (bool)($cfg['require_not_worse_than_default'] ?? true),
            'rollback_guard_enabled' => (bool)($cfg['rollback_guard_enabled'] ?? true),
            'rollback_required' => false,
            'rollback_reason' => null,
            'rollback_cooldown_until' => null,
            'rollback_action' => null,
            // Candidate profile builder defaults
            'candidate_profile_generated' => false,
            'candidate_profile_written' => false,
            'candidate_profile_id' => null,
            'candidate_rules_total' => 0,
            'candidate_rules_missing_reason' => null,
            'candidate_weighted_components_total' => 0,
            'candidate_profile_available' => false,
            'candidate_profile_missing_reason' => null,
            // Candidate replay defaults
            'candidate_replay_enabled' => false,
            'replay_trades_total' => 0,
            'replay_bad_entries_total' => 0,
            'replay_good_entries_total' => 0,
            'replay_bad_blocked_total' => 0,
            'replay_good_blocked_total' => 0,
            'replay_entry_ok_exit_issue_blocked_total' => 0,
            'replay_neutral_blocked_total' => 0,
            'replay_bad_capture_rate_pct' => null,
            'replay_good_block_rate_pct' => null,
            'replay_net_score' => null,
            'replay_score_scale' => 'percent_of_max_matched_weight',
            'replay_risk_threshold_block_candidate' => 60.0,
            'replay_demo_only_threshold_candidate' => 30.0,
            'replay_expected_good_kept_total' => 0,
            'replay_expected_bad_avoided_total' => 0,
            'auto_apply_safety_blocked' => true,
            'auto_apply_safety_reason' => 'candidate_not_eligible_for_demo_apply',
            'candidate_bad_entry_rate_delta_pct' => null,
            'candidate_good_capture_delta_pct' => null,
            'candidate_avg_roi_delta_pct' => null,
            'candidate_drawdown_delta_pct' => null,
            'candidate_blocked_bad_examples' => [],
            'candidate_blocked_good_examples' => [],
            'candidate_kept_bad_examples' => [],
            'candidate_kept_good_examples' => [],
            'replay_skipped_feature_link_reason' => null,
            'created_at' => $runStartedAt,
        ];

        if (!(bool)$cfg['enabled']) {
            $this->writeJson($this->storagePath('last_run.json'), $result);
            $this->appendCycleHistoryCompact($result);
            return $result;
        }

        // 1. Collect entry snapshots
        $snapshots = $this->collectEntrySnapshots($cfg);
        $result['entry_snapshots_total'] = count($snapshots['all']);
        $result['entry_snapshots_new_total'] = $snapshots['new_total'];
        $result['entry_snapshot_examples'] = array_slice($snapshots['all'], 0, 8);

        // 2. Observe active positions
        $obs = $this->observeActivePositions($cfg, $snapshots['index']);
        $result['active_positions_seen_total'] = $obs['seen_total'];
        $result['active_positions_observed_total'] = $obs['observed_total'];
        $result['observations_written_total'] = $obs['written_total'];
        $result['active_observation_examples'] = $obs['examples'];

        // 3. Link closed outcomes
        $resetState = $this->detectManualReset($cfg);
        $result['reset_detected'] = (bool)($resetState['reset_detected'] ?? false);
        $result['manual_reset_respected'] = (bool)($resetState['manual_reset_respected'] ?? false);
        $outcomes = $this->linkClosedOutcomes($cfg, $snapshots['index'], $resetState);
        $result['closed_trades_loaded_total'] = $outcomes['loaded_total'];
        $result['closed_outcomes_raw_loaded_total'] = $outcomes['raw_loaded_total'];
        $result['closed_outcomes_effective_total'] = $outcomes['effective_total'];
        $result['closed_outcomes_unique_total'] = $outcomes['unique_total'];
        $result['closed_outcomes_duplicates_skipped_total'] = $outcomes['duplicates_skipped_total'];
        $result['closed_outcomes_merged_total'] = $outcomes['merged_total'];
        $result['closed_outcomes_near_time_duplicates_merged_total'] = $outcomes['near_time_duplicates_merged_total'];
        $result['closed_outcomes_duplicate_examples'] = $outcomes['duplicate_examples'];
        $result['closed_outcomes_near_time_duplicate_examples'] = $outcomes['near_time_duplicate_examples'];
        $result['closed_outcomes_strong_link_total'] = $outcomes['strong_link_total'];
        $result['closed_outcomes_weak_link_skipped_total'] = $outcomes['weak_link_skipped_total'];
        $result['closed_outcomes_preserved_due_empty_source_total'] = $outcomes['preserved_due_empty_source_total'];
        $result['closed_outcomes_rebuilt_from_ndjson_total'] = $outcomes['rebuilt_from_ndjson_total'];
        $result['closed_outcomes_rebuild_skipped_due_reset'] = $outcomes['rebuild_skipped_due_reset_total'];
        $result['closed_outcomes_time_mismatch_total'] = $outcomes['time_mismatch_total'];
        $result['closed_outcomes_opened_at_corrected_total'] = $outcomes['opened_at_corrected_total'];
        $result['closed_outcomes_timing_low_confidence_total'] = $outcomes['timing_low_confidence_total'];
        $result['closed_outcomes_time_mismatch_examples'] = $outcomes['time_mismatch_examples'];
        $result['closed_outcomes_matched_total'] = count($outcomes['all']);
        $result['bad_entry_total'] = count($outcomes['bad']);
        $result['good_or_do_not_touch_total'] = count($outcomes['good']);
        $result['entry_ok_exit_issue_total'] = count($outcomes['exit_issue']);
        $result['neutral_total'] = count($outcomes['neutral']);
        $result['outcome_incomplete_total'] = count($outcomes['incomplete']);
        $result['outcome_mfe_normalized_total'] = $outcomes['mfe_normalized_total'];
        $result['outcome_mae_normalized_total'] = $outcomes['mae_normalized_total'];
        $result['outcome_roi_normalization_examples'] = $outcomes['roi_normalization_examples'];
        $result['outcomes_reclassified_total'] = (int)($outcomes['reclassified_total'] ?? 0);
        $result['outcomes_reclassified_examples'] = (array)($outcomes['reclassified_examples'] ?? []);
        $result['outcome_excluded_from_pattern_mining_total'] = $outcomes['excluded_from_pattern_mining_total'];
        $result['outcome_excluded_reasons'] = $outcomes['excluded_reasons'];
        $result['bad_entry_examples'] = array_slice($outcomes['bad'], 0, 6);
        $result['good_entry_examples'] = array_slice($outcomes['good'], 0, 6);

        // 4. Feature extraction pipeline
        $featureResult = ['feature_by_snapshot' => []];
        if ((bool)($cfg['feature_pipeline_enabled'] ?? true)) {
            $featureResult = $this->runFeaturePipeline($cfg, $snapshots['all']);
            $result['feature_records_total'] = $featureResult['records_total'];
            $result['feature_time_valid_total'] = $featureResult['time_valid_total'];
            $result['feature_time_invalid_total'] = $featureResult['time_invalid_total'];
            $result['feature_source_counts'] = $featureResult['source_counts'];
            $result['feature_time_invalid_examples'] = $featureResult['time_invalid_examples'];
            $result['candle_micro_available_total'] = $featureResult['candle_micro_available_total'];
            $result['dump_micro_available_total'] = $featureResult['dump_micro_available_total'];
            $result['candle_micro_real_available_total'] = $featureResult['candle_micro_real_available_total'];
            $result['candle_micro_proxy_available_total'] = $featureResult['candle_micro_proxy_available_total'];
            $result['candle_micro_missing_total'] = $featureResult['candle_micro_missing_total'];
            $result['dump_micro_real_available_total'] = $featureResult['dump_micro_real_available_total'];
            $result['dump_micro_proxy_available_total'] = $featureResult['dump_micro_proxy_available_total'];
            $result['dump_micro_missing_total'] = $featureResult['dump_micro_missing_total'];
            $result['clean_learning_start_enabled'] = (bool)($featureResult['clean_learning_start_enabled'] ?? false);
            $result['legacy_proxy_features_total'] = (int)($featureResult['legacy_proxy_features_total'] ?? 0);
            $result['new_micro_features_total'] = (int)($featureResult['new_micro_features_total'] ?? 0);
            $result['bybit_kline_requests_total'] = (int)($featureResult['bybit_kline_requests_total'] ?? 0);
            $result['bybit_kline_success_total'] = (int)($featureResult['bybit_kline_success_total'] ?? 0);
            $result['bybit_kline_error_total'] = (int)($featureResult['bybit_kline_error_total'] ?? 0);
            $result['bybit_kline_cache_hit_total'] = (int)($featureResult['bybit_kline_cache_hit_total'] ?? 0);
            $result['bybit_kline_unique_fetch_total'] = (int)($featureResult['bybit_kline_unique_fetch_total'] ?? 0);
            $result['bybit_kline_reused_window_total'] = (int)($featureResult['bybit_kline_reused_window_total'] ?? 0);
            $result['bybit_kline_fetch_cache_key_mode'] = (string)($featureResult['bybit_kline_fetch_cache_key_mode'] ?? 'symbol_entry_interval_limit');
            $result['parser2_fallback_used_total'] = (int)($featureResult['parser2_fallback_used_total'] ?? 0);
            $result['micro_data_missing_total'] = (int)($featureResult['micro_data_missing_total'] ?? 0);
            $result['micro_impulse_shape_counts'] = (array)($featureResult['micro_impulse_shape_counts'] ?? []);
            $result['micro_entry_timing_counts'] = (array)($featureResult['micro_entry_timing_counts'] ?? []);
            $result['micro_growth_distribution_counts'] = (array)($featureResult['micro_growth_distribution_counts'] ?? []);
            $result['micro_rejection_risk_counts'] = (array)($featureResult['micro_rejection_risk_counts'] ?? []);
            $result['dump_shape_counts'] = (array)($featureResult['dump_shape_counts'] ?? []);
            $result['post_dump_state_counts'] = (array)($featureResult['post_dump_state_counts'] ?? []);
            $result['post_dump_impulse_type_counts'] = (array)($featureResult['post_dump_impulse_type_counts'] ?? []);
            $result['micro_pattern_examples'] = (array)($featureResult['micro_pattern_examples'] ?? []);
            $result['micro_bad_good_overlap_examples'] = (array)($featureResult['micro_bad_good_overlap_examples'] ?? []);
            $result['micro_primary_window'] = (string)($featureResult['micro_primary_window'] ?? ($cfg['micro_primary_window'] ?? 'micro_window_10m'));
            $result['micro_primary_summary_available_total'] = (int)($featureResult['micro_primary_summary_available_total'] ?? 0);
            $result['micro_primary_summary_missing_total'] = (int)($featureResult['micro_primary_summary_missing_total'] ?? 0);
            $result['weighted_score_calculated_total'] = $featureResult['weighted_score_calculated_total'];
        }

        // 4b. Real-learning epoch filter — exclude pre-epoch outcomes from active profile/pattern mining
        $epochFilter = $this->applyMicroLearningEpoch(
            $cfg,
            $outcomes,
            $snapshots['all'],
            (array)($featureResult['feature_by_snapshot'] ?? []),
            $runStartedAt
        );
        $result['real_learning_epoch_enabled'] = (bool)($epochFilter['epoch_enabled'] ?? false);
        $result['real_learning_epoch_id'] = $epochFilter['epoch_id'] ?? null;
        $result['real_learning_epoch_start_at'] = $epochFilter['epoch_start_at'] ?? null;
        $result['previous_epoch_outcomes_excluded_total'] = (int)($epochFilter['outcomes_excluded_by_epoch_total'] ?? 0);
        $result['active_epoch_outcomes_total'] = (int)($epochFilter['epoch_outcomes_total'] ?? 0);
        $result['micro_learning_epoch_enabled'] = (bool)($epochFilter['epoch_enabled'] ?? false);
        $result['micro_learning_epoch_id'] = $epochFilter['epoch_id'] ?? null;
        $result['micro_learning_epoch_start_at'] = $epochFilter['epoch_start_at'] ?? null;
        $result['legacy_outcomes_total'] = (int)($epochFilter['legacy_outcomes_total'] ?? 0);
        $result['epoch_outcomes_total'] = (int)($epochFilter['epoch_outcomes_total'] ?? 0);
        $result['outcomes_excluded_by_epoch_total'] = (int)($epochFilter['outcomes_excluded_by_epoch_total'] ?? 0);
        $result['epoch_start_source'] = $epochFilter['epoch_start_source'] ?? null;
        $result['epoch_start_missing_reason'] = $epochFilter['epoch_start_missing_reason'] ?? null;
        $result['bad_entry_total'] = (int)($epochFilter['active_bad_entry_total'] ?? $result['bad_entry_total']);
        $result['good_or_do_not_touch_total'] = (int)($epochFilter['active_good_or_do_not_touch_total'] ?? $result['good_or_do_not_touch_total']);
        $result['entry_ok_exit_issue_total'] = (int)($epochFilter['active_entry_ok_exit_issue_total'] ?? $result['entry_ok_exit_issue_total']);
        $result['neutral_total'] = (int)($epochFilter['active_neutral_total'] ?? $result['neutral_total']);
        $result['outcome_incomplete_total'] = (int)($epochFilter['active_outcome_incomplete_total'] ?? $result['outcome_incomplete_total']);
        $patternMiningOutcomes = $epochFilter['pattern_mining'];

        $excludedOutcomeKeys = array_fill_keys((array)($epochFilter['excluded_outcome_keys'] ?? []), true);
        if ($excludedOutcomeKeys !== []) {
            $allOutcomes = (array)($outcomes['all'] ?? []);
            foreach ($allOutcomes as &$existingOutcome) {
                if (!is_array($existingOutcome)) {
                    continue;
                }
                $outcomeKey = (string)($existingOutcome['outcome_key'] ?? '');
                if ($outcomeKey !== '' && isset($excludedOutcomeKeys[$outcomeKey])) {
                    $existingOutcome['epoch_excluded'] = true;
                    $existingOutcome['epoch_excluded_reason'] = 'previous_risk_profile_epoch';
                    $existingOutcome['excluded_from_epoch_id'] = $epochFilter['epoch_id'] ?? null;
                } else {
                    $existingOutcome['epoch_excluded'] = false;
                    $existingOutcome['epoch_excluded_reason'] = null;
                    $existingOutcome['excluded_from_epoch_id'] = null;
                }
            }
            unset($existingOutcome);
            $this->writeJson($this->storagePath('closed_outcomes.json'), $allOutcomes);
        }

        // 5. Pattern mining
        $patterns = PatternMiner::mine($patternMiningOutcomes, $cfg, (array)($featureResult['feature_by_snapshot'] ?? []));
        $this->writeJson($this->storagePath('patterns/bad_patterns.json'), $patterns['bad_patterns']);
        $this->writeJson($this->storagePath('patterns/pattern_stats.json'), $patterns['all']);
        $this->writeJson($this->storagePath('patterns/micro_feature_distributions.json'), (array)($patterns['micro_feature_distributions'] ?? []));
        $this->writeJson($this->storagePath('patterns/micro_label_purity.json'), (array)($patterns['micro_label_purity'] ?? []));
        $this->writeJson($this->storagePath('patterns/micro_threshold_candidates.json'), (array)($patterns['micro_threshold_candidates'] ?? []));
        $this->writeJson($this->storagePath('patterns/suspicious_micro_labels.json'), (array)($patterns['suspicious_micro_labels'] ?? []));
        $result['bad_patterns_total'] = count($patterns['all']);
        $result['top_bad_pattern_examples'] = array_slice($patterns['all'], 0, 10);
        $result['micro_separability_enabled'] = true;
        $result['micro_numeric_features_analyzed_total'] = (int)($patterns['micro_numeric_features_analyzed_total'] ?? 0);
        $result['micro_label_values_analyzed_total'] = (int)($patterns['micro_label_values_analyzed_total'] ?? 0);
        $result['micro_high_separation_features_total'] = (int)($patterns['micro_high_separation_features_total'] ?? 0);
        $result['micro_medium_separation_features_total'] = (int)($patterns['micro_medium_separation_features_total'] ?? 0);
        $result['micro_mixed_labels_total'] = (int)($patterns['micro_mixed_labels_total'] ?? 0);
        $result['micro_suspicious_labels_total'] = (int)($patterns['micro_suspicious_labels_total'] ?? 0);
        $result['top_micro_bad_separators'] = array_slice((array)($patterns['top_micro_bad_separators'] ?? []), 0, 10);
        $result['top_micro_good_separators'] = array_slice((array)($patterns['top_micro_good_separators'] ?? []), 0, 10);
        $result['micro_mixed_label_examples'] = array_slice((array)($patterns['micro_mixed_label_examples'] ?? []), 0, 10);
        $result['micro_suspicious_label_examples'] = array_slice((array)($patterns['micro_suspicious_label_examples'] ?? []), 0, 10);

        // 6. Profile building
        $epochMeta = [
            'real_learning_epoch_id' => $epochFilter['epoch_id'] ?? null,
            'real_learning_epoch_start_at' => $epochFilter['epoch_start_at'] ?? null,
            'micro_learning_epoch_id' => $epochFilter['epoch_id'] ?? null,
            'micro_learning_epoch_start_at' => $epochFilter['epoch_start_at'] ?? null,
            'epoch_start_source' => $epochFilter['epoch_start_source'] ?? null,
            'legacy_outcomes_total' => (int)($epochFilter['legacy_outcomes_total'] ?? 0),
            'outcomes_excluded_by_epoch_total' => (int)($epochFilter['outcomes_excluded_by_epoch_total'] ?? 0),
            'active_epoch_outcomes_total' => (int)($epochFilter['epoch_outcomes_total'] ?? 0),
        ];
        $profile = ProfileBuilder::build($cfg, $patternMiningOutcomes, $patterns['all'], fn(string $f): string => $this->storagePath($f), $epochMeta);
        $result['profile_generated'] = true;
        $result['profile_id'] = $profile['profile_id'];
        $result['profile_rules_total'] = count($profile['rules']);
        $result['profile_rules_observe_only_total'] = count($profile['rules']);
        $result['profile_rules_quarantined_total'] = count($profile['quarantined']);
        $result['quarantined_rule_examples'] = array_slice($profile['quarantined'], 0, 8);

        // 7. Default vs auto comparison scaffold
        $currentProfile = (array)$this->readJson($this->storagePath('profiles/early_impulse_growth_long/current_profile.json'), []);
        $comparison = ProfileComparator::compare($currentProfile, [], $cfg);
        $result['profile_compared_to_default'] = $comparison['compared_to_default'];
        $result['auto_not_worse_than_default'] = $comparison['auto_not_worse_than_default'];
        $result['auto_improvement_score'] = $comparison['auto_improvement_score'];
        $result['auto_comparison_reason'] = $comparison['comparison_note'] ?? null;
        $this->enrichCurrentProfileMetadata($cfg, $result, $comparison);

        // 7b. Rolling quality guard
        $rollingGuard = $this->runRollingQualityGuard($cfg, $patternMiningOutcomes, $result);
        $result['rolling_learning_enabled'] = $rollingGuard['rolling_learning_enabled'];
        $result['rolling_learning_window_minutes'] = $rollingGuard['rolling_learning_window_minutes'];
        $result['rolling_retrain_interval_minutes'] = $rollingGuard['rolling_retrain_interval_minutes'];
        $result['rolling_min_closed_outcomes'] = $rollingGuard['rolling_min_closed_outcomes'];
        $result['rolling_min_bad_entries'] = $rollingGuard['rolling_min_bad_entries'];
        $result['rolling_min_good_entries'] = $rollingGuard['rolling_min_good_entries'];
        $result['rolling_window_outcomes_total'] = $rollingGuard['rolling_window_outcomes_total'];
        $result['rolling_window_bad_entry_total'] = $rollingGuard['rolling_window_bad_entry_total'];
        $result['rolling_window_good_entry_total'] = $rollingGuard['rolling_window_good_entry_total'];
        $result['rolling_window_entry_ok_exit_issue_total'] = $rollingGuard['rolling_window_entry_ok_exit_issue_total'];
        $result['default_quality_score'] = $rollingGuard['default_quality_score'];
        $result['active_dynamic_quality_score'] = $rollingGuard['active_dynamic_quality_score'];
        $result['candidate_quality_score'] = $rollingGuard['candidate_quality_score'];
        $result['candidate_vs_default_delta_pct'] = $rollingGuard['candidate_vs_default_delta_pct'];
        $result['candidate_vs_active_delta_pct'] = $rollingGuard['candidate_vs_active_delta_pct'];
        $result['no_change_band_pct'] = $rollingGuard['no_change_band_pct'];
        $result['min_candidate_improvement_pct'] = $rollingGuard['min_candidate_improvement_pct'];
        $result['default_result_summary'] = $rollingGuard['default_result_summary'];
        $result['active_dynamic_result_summary'] = $rollingGuard['active_dynamic_result_summary'];
        $result['candidate_result_summary'] = $rollingGuard['candidate_result_summary'];
        $result['candidate_status'] = $rollingGuard['candidate_status'];
        $result['promotion_decision'] = $rollingGuard['promotion_decision'];
        $result['promotion_reason'] = $rollingGuard['promotion_reason'];
        $result['auto_apply_safety_blocked'] = (bool)($rollingGuard['auto_apply_safety_blocked'] ?? true);
        $result['auto_apply_safety_reason'] = $rollingGuard['auto_apply_safety_reason'] ?? null;
        $result['auto_apply_to_demo_enabled'] = $rollingGuard['auto_apply_to_demo_enabled'];
        $result['auto_apply_to_live_enabled'] = $rollingGuard['auto_apply_to_live_enabled'];
        $result['require_not_worse_than_default'] = $rollingGuard['require_not_worse_than_default'];
        $result['rollback_guard_enabled'] = $rollingGuard['rollback_guard_enabled'];
        $result['rollback_required'] = $rollingGuard['rollback_required'];
        $result['rollback_reason'] = $rollingGuard['rollback_reason'];
        $result['rollback_cooldown_until'] = $rollingGuard['rollback_cooldown_until'];
        $result['rollback_action'] = $rollingGuard['rollback_action'];
        $result['promotion_guard_scope'] = 'rolling_window';

        // 7c. Candidate profile builder from micro separability diagnostics
        $candidateBuild = $this->buildCandidateProfileFromSeparability($cfg, $patternMiningOutcomes, $patterns, $epochMeta);

        // Single-feature diagnostics
        $result['candidate_single_feature_candidates_total']   = (int)($candidateBuild['single_feature_candidates_total']    ?? 0);
        $result['candidate_single_feature_rejected_total']     = (int)($candidateBuild['single_feature_rejected_total']      ?? 0);
        $result['candidate_single_feature_reject_reason_counts'] = (array)($candidateBuild['single_feature_reject_reason_counts'] ?? []);

        // 7c-composite. If no single-feature rules found, attempt composite candidate builder
        $compositeResult = ['composite_candidate_enabled' => false, 'composite_candidate_selected' => false];
        if ((int)($candidateBuild['rules_total'] ?? 0) === 0 && (bool)($cfg['composite_candidate_enabled'] ?? true)) {
            $compositeResult  = $this->buildCompositeCandidates($cfg, $patternMiningOutcomes, $patterns, $epochMeta);
            if ((bool)($compositeResult['composite_candidate_selected'] ?? false) && isset($compositeResult['selected_candidate_build'])) {
                $candidateBuild = (array)$compositeResult['selected_candidate_build'];
            }
        }

        $result['composite_candidate_enabled']              = (bool)($compositeResult['composite_candidate_enabled']          ?? false);
        $result['composite_candidate_source']               = (string)($compositeResult['composite_candidate_source']        ?? 'missing');
        $result['composite_candidates_available_total']     = (int)($compositeResult['composite_candidates_available_total'] ?? 0);
        $result['composite_candidates_tested_total']        = (int)($compositeResult['composite_candidates_tested_total']     ?? 0);
        $result['composite_candidates_passed_total']        = (int)($compositeResult['composite_candidates_passed_total']     ?? 0);
        $result['composite_candidates_rejected_total']      = (int)($compositeResult['composite_candidates_rejected_total']   ?? 0);
        $result['composite_candidate_best_score']           = $compositeResult['composite_candidate_best_score']              ?? null;
        $result['composite_candidate_best_bad_capture_rate_pct'] = $compositeResult['composite_candidate_best_bad_capture_rate_pct'] ?? null;
        $result['composite_candidate_best_good_block_rate_pct']  = $compositeResult['composite_candidate_best_good_block_rate_pct']  ?? null;
        $result['composite_candidate_best_net_score']       = $compositeResult['composite_candidate_best_net_score']          ?? null;
        $result['composite_candidate_selected']             = (bool)($compositeResult['composite_candidate_selected']         ?? false);
        $result['composite_candidate_selected_id']          = $compositeResult['composite_candidate_selected_id']             ?? null;
        $result['composite_candidate_reject_reason_counts'] = (array)($compositeResult['composite_candidate_reject_reason_counts'] ?? []);
        $result['composite_candidate_no_selection_reason']  = $compositeResult['composite_candidate_no_selection_reason']     ?? null;

        $result['candidate_profile_written']  = (bool)($candidateBuild['profile_written'] ?? false);
        $result['candidate_profile_generated'] = (bool)($candidateBuild['profile_written'] ?? false);
        $result['candidate_profile_id']        = $candidateBuild['profile_id'];
        $result['candidate_rules_total']       = $candidateBuild['rules_total'];
        $compositeSelected = (bool)($compositeResult['composite_candidate_selected'] ?? false);
        $result['candidate_rules_missing_reason'] = ((int)($candidateBuild['rules_total'] ?? 0) > 0)
            ? null
            : ($compositeSelected
                ? null
                : $this->normalizeCandidateRulesMissingReason((string)($candidateBuild['missing_reason'] ?? '')));
        $result['candidate_weighted_components_total'] = $candidateBuild['components_total'];
        $result['candidate_profile_available'] = $candidateBuild['available'];
        $result['candidate_profile_missing_reason'] = ((bool)($candidateBuild['profile_written'] ?? false))
            ? null
            : ($candidateBuild['missing_reason'] ?? 'candidate_profile_not_written');

        // 7d. Candidate replay evaluator — observe-only, never applies
        $candidateReplay = ['candidate_replay_enabled' => false];
        if ((bool)($cfg['candidate_replay_enabled'] ?? true)) {
            $candidateReplay = $this->replayCandidateProfile($cfg, $candidateBuild['profile'], $patternMiningOutcomes);
        }
        $result['candidate_replay_enabled'] = (bool)($candidateReplay['candidate_replay_enabled'] ?? false);
        $result['replay_trades_total'] = (int)($candidateReplay['replay_trades_total'] ?? 0);
        $result['replay_bad_entries_total'] = (int)($candidateReplay['replay_bad_entries_total'] ?? 0);
        $result['replay_good_entries_total'] = (int)($candidateReplay['replay_good_entries_total'] ?? 0);
        $result['replay_bad_blocked_total'] = (int)($candidateReplay['replay_bad_blocked_total'] ?? 0);
        $result['replay_good_blocked_total'] = (int)($candidateReplay['replay_good_blocked_total'] ?? 0);
        $result['replay_entry_ok_exit_issue_blocked_total'] = (int)($candidateReplay['replay_entry_ok_exit_issue_blocked_total'] ?? 0);
        $result['replay_neutral_blocked_total'] = (int)($candidateReplay['replay_neutral_blocked_total'] ?? 0);
        $result['replay_bad_capture_rate_pct'] = $candidateReplay['replay_bad_capture_rate_pct'] ?? null;
        $result['replay_good_block_rate_pct'] = $candidateReplay['replay_good_block_rate_pct'] ?? null;
        $result['replay_net_score'] = $candidateReplay['replay_net_score'] ?? null;
        $result['replay_score_scale'] = (string)($candidateReplay['score_scale'] ?? 'percent_of_max_matched_weight');
        $result['replay_risk_threshold_block_candidate'] = (float)($candidateReplay['risk_threshold_block_candidate'] ?? 60.0);
        $result['replay_demo_only_threshold_candidate'] = (float)($candidateReplay['demo_only_threshold_candidate'] ?? 30.0);
        $result['replay_expected_good_kept_total'] = (int)($candidateReplay['replay_expected_good_kept_total'] ?? 0);
        $result['replay_expected_bad_avoided_total'] = (int)($candidateReplay['replay_expected_bad_avoided_total'] ?? 0);
        $result['auto_apply_safety_blocked'] = (bool)($candidateReplay['auto_apply_safety_blocked'] ?? true);
        $result['auto_apply_safety_reason'] = $candidateReplay['auto_apply_safety_reason'] ?? null;
        $result['candidate_bad_entry_rate_delta_pct'] = $candidateReplay['candidate_bad_entry_rate_delta_pct'] ?? null;
        $result['candidate_good_capture_delta_pct'] = $candidateReplay['candidate_good_capture_delta_pct'] ?? null;
        $result['candidate_avg_roi_delta_pct'] = $candidateReplay['candidate_avg_roi_delta_pct'] ?? null;
        $result['candidate_drawdown_delta_pct'] = $candidateReplay['candidate_drawdown_delta_pct'] ?? null;
        $result['candidate_blocked_bad_examples'] = (array)($candidateReplay['blocked_bad_examples'] ?? []);
        $result['candidate_blocked_good_examples'] = (array)($candidateReplay['blocked_good_examples'] ?? []);
        $result['candidate_kept_bad_examples'] = (array)($candidateReplay['kept_bad_examples'] ?? []);
        $result['candidate_kept_good_examples'] = (array)($candidateReplay['kept_good_examples'] ?? []);
        // Feature linking diagnostics
        $result['replay_features_loaded_total'] = (int)($candidateReplay['replay_features_loaded_total'] ?? 0);
        $result['replay_features_linked_total'] = (int)($candidateReplay['replay_features_linked_total'] ?? 0);
        $result['replay_features_missing_total'] = (int)($candidateReplay['replay_features_missing_total'] ?? 0);
        $result['replay_features_micro_available_total'] = (int)($candidateReplay['replay_features_micro_available_total'] ?? 0);
        $result['replay_features_micro_missing_total'] = (int)($candidateReplay['replay_features_micro_missing_total'] ?? 0);
        $result['replay_features_link_method_counts'] = (array)($candidateReplay['replay_features_link_method_counts'] ?? []);
        $result['replay_features_missing_examples'] = (array)($candidateReplay['replay_features_missing_examples'] ?? []);
        $result['replay_skipped_feature_link_reason'] = $candidateReplay['replay_skipped_feature_link_reason'] ?? null;
        $result['candidate_replay_summary'] = $this->buildCandidateReplaySummary($candidateReplay, $result);
        $result['candidate_build_scope'] = 'active_epoch';
        $result['candidate_replay_scope'] = 'active_epoch';
        $result['promotion_guard_scope'] = 'rolling_window';
        $result['scope_mismatch_allowed_for_diagnostics'] = true;
        $result['replay_diagnostic_available'] = (bool)($candidateReplay['candidate_replay_enabled'] ?? false);
        $result['replay_suggests_improvement'] = (bool)($candidateReplay['replay_suggests_improvement'] ?? false);
        $result['replay_result'] = (string)($candidateReplay['replay_result'] ?? ((bool)($candidateReplay['replay_suggests_improvement'] ?? false) ? 'improved_on_sample' : 'no_improvement'));
        $result['replay_candidate_status'] = (string)($candidateReplay['replay_candidate_status'] ?? 'insufficient_replay_data');
        $result['promotion_blocked_by_min_data'] = false;
        $result['promotion_blocked_reason'] = null;
        $result['candidate_can_apply'] = false;
        $result['candidate_eligible_for_demo_apply'] = false;

        $promotionGate = $this->evaluatePromotionMinDataGate($rollingGuard);
        $result['promotion_blocked_by_min_data'] = (bool)($promotionGate['blocked'] ?? false);
        $result['promotion_blocked_reason'] = $promotionGate['reason'] ?? null;

        // If replay produced a meaningful candidate decision, override rolling guard's candidate fields
        if ((bool)($candidateReplay['candidate_replay_enabled'] ?? false) && $candidateReplay['candidate_quality_score'] !== null) {
            $result['candidate_quality_score'] = $candidateReplay['candidate_quality_score'];
            $result['candidate_result_summary'] = $candidateReplay['candidate_result'] ?? $result['candidate_result_summary'];
            $result['candidate_vs_default_delta_pct'] = $candidateReplay['candidate_vs_default_delta_pct'] ?? $result['candidate_vs_default_delta_pct'];
            $result['candidate_status'] = (string)($candidateReplay['candidate_status'] ?? $result['candidate_status']);
            $result['promotion_decision'] = (string)($candidateReplay['promotion_decision'] ?? $result['promotion_decision']);
            $result['promotion_reason'] = $candidateReplay['promotion_reason'] ?? $result['promotion_reason'];
        }

        if ((bool)($result['promotion_blocked_by_min_data'] ?? false)) {
            $result['candidate_status'] = 'insufficient_data';
            $result['promotion_decision'] = 'keep_current';
            $result['promotion_reason'] = (string)($result['promotion_blocked_reason'] ?? 'rolling_window_below_min_outcomes');
            $result['candidate_can_apply'] = false;
            $result['candidate_eligible_for_demo_apply'] = false;
            $result['auto_apply_safety_blocked'] = true;
            $result['auto_apply_safety_reason'] = 'candidate_not_eligible_for_demo_apply';
        } else {
            $eligible = ((string)($result['candidate_status'] ?? '')) === 'eligible_for_demo_apply';
            $result['candidate_can_apply'] = $eligible;
            $result['candidate_eligible_for_demo_apply'] = $eligible;

            $noRules = ((int)($result['candidate_rules_total'] ?? 0) === 0);
            if ($noRules) {
                $result['candidate_status'] = 'no_safe_candidate_rules';
                $result['promotion_decision'] = 'keep_current';
                $result['promotion_reason'] = 'no_single_or_composite_rules_passed_guard';
                $result['candidate_rules_missing_reason'] = 'no_safe_candidate_rules_good_overlap';
                $result['candidate_can_apply'] = false;
                $result['candidate_eligible_for_demo_apply'] = false;
                $result['auto_apply_safety_blocked'] = true;
                $result['auto_apply_safety_reason'] = 'candidate_not_eligible_for_demo_apply';
            }
        }

        $result['final_candidate_status'] = (string)($result['candidate_status'] ?? 'pending');
        $result['final_promotion_decision'] = (string)($result['promotion_decision'] ?? 'keep_current');
        $result['final_promotion_reason'] = $result['promotion_reason'] ?? null;
        $result['final_candidate_eligible_for_demo_apply'] = (bool)($result['candidate_eligible_for_demo_apply'] ?? false);
        $result['candidate_replay_summary'] = $this->buildCandidateReplaySummary($candidateReplay, $result);

        $this->syncCandidateReplayFinalDiagnostics($cfg, $result, $candidateReplay);

        if ((bool)($result['replay_diagnostic_available'] ?? false)) {
            $this->appendCandidateHistory([
                'candidate_profile_id' => $result['candidate_profile_id'] ?? null,
                'candidate_status' => $result['candidate_status'] ?? 'pending',
                'promotion_decision' => $result['promotion_decision'] ?? 'keep_current',
                'promotion_reason' => $result['promotion_reason'] ?? null,
                'auto_apply_safety_blocked' => (bool)($result['auto_apply_safety_blocked'] ?? true),
                'auto_apply_safety_reason' => $result['auto_apply_safety_reason'] ?? 'candidate_not_eligible_for_demo_apply',
                'default_quality_score' => $result['default_quality_score'] ?? null,
                'candidate_quality_score' => $result['candidate_quality_score'] ?? null,
                'candidate_vs_default_delta_pct' => $result['candidate_vs_default_delta_pct'] ?? null,
                'rolling_window_outcomes_total' => $result['rolling_window_outcomes_total'] ?? 0,
                'rolling_window_bad_entry_total' => $result['rolling_window_bad_entry_total'] ?? 0,
                'rolling_window_good_entry_total' => $result['rolling_window_good_entry_total'] ?? 0,
                'replay_result' => $result['replay_result'] ?? null,
                'replay_candidate_status' => $result['replay_candidate_status'] ?? null,
                'replay_suggests_improvement' => (bool)($result['replay_suggests_improvement'] ?? false),
                'final_candidate_status' => $result['final_candidate_status'] ?? null,
                'final_promotion_decision' => $result['final_promotion_decision'] ?? null,
                'final_promotion_reason' => $result['final_promotion_reason'] ?? null,
                'final_candidate_eligible_for_demo_apply' => (bool)($result['final_candidate_eligible_for_demo_apply'] ?? false),
                'promotion_blocked_by_min_data' => (bool)($result['promotion_blocked_by_min_data'] ?? false),
                'promotion_blocked_reason' => $result['promotion_blocked_reason'] ?? null,
            ], max(1, (int)($cfg['max_candidate_history_records'] ?? 200)), 'replay_evaluator');
        }

        // 7e. Sync rolling guard + replay into current_profile.json
        $profileFileAvailability = $this->syncRollingGuardToCurrentProfile($cfg, $rollingGuard, $candidateBuild, $candidateReplay);
        $result['active_profile_available']              = $profileFileAvailability['active_profile_available'];
        $result['active_profile_missing_reason']         = $profileFileAvailability['active_profile_missing_reason'];
        $result['previous_good_profile_available']       = $profileFileAvailability['previous_good_profile_available'];
        $result['previous_good_profile_missing_reason']  = $profileFileAvailability['previous_good_profile_missing_reason'];
        $result['rollback_history_available']            = $profileFileAvailability['rollback_history_available'];
        $this->syncCurrentProfilePromotionDiagnostics($result);
        $this->syncCandidateProfileEligibilityDiagnostics($result);

        // 8. Storage pruning
        $prune = $this->pruneStorage($cfg, $snapshots['all']);
        $result['storage_pruned_total'] = $prune['pruned_total'];
        $result['storage_size_estimate_mb'] = $prune['size_estimate_mb'];
        $result['storage_prune_examples'] = $prune['prune_examples'];
        $result['storage_prune_reason_counts'] = $prune['prune_reason_counts'];
        $result['cycle_history_pruned_total'] = (int)($prune['cycle_history_pruned_total'] ?? 0);
        $result['active_observation_files_total'] = (int)($prune['active_observation_files_total'] ?? 0);
        $result['active_observation_files_pruned_total'] = (int)($prune['active_observation_files_pruned_total'] ?? 0);

        $cycleHistoryWrite = $this->appendCycleHistoryCompact($result);
        $result['cycle_history_compact_enabled'] = true;
        $result['cycle_history_last_line_bytes'] = (int)($cycleHistoryWrite['last_line_bytes'] ?? 0);

        $result = $this->limitLastRunExamples($result, max(1, (int)($cfg['max_examples_per_last_run_section'] ?? 10)));
        $this->writeJson($this->storagePath('last_run.json'), $result);
        return $result;
    }

    /** @return array<string,mixed> */
    public function evaluateSignalForStrategy(string $strategyId, array $signalPacket): array
    {
        $cfg = $this->loadConfig();
        $profilePath = $this->storagePath('profiles/early_impulse_growth_long/current_profile.json');
        $profile = (array)$this->readJson($profilePath, []);
        return DynamicLearningDecision::evaluate($strategyId, $signalPacket, $cfg, $profile !== [] ? $profile : null);
    }

    /** @param array<string,mixed> $comparison */
    private function enrichCurrentProfileMetadata(array $cfg, array $result, array $comparison): void
    {
        $profilePath = $this->storagePath('profiles/early_impulse_growth_long/current_profile.json');
        $profile = (array)$this->readJson($profilePath, []);
        if ($profile === []) {
            return;
        }

        $comparisonReason = (string)($comparison['comparison_note'] ?? '');
        $defaultSummary = (array)($comparison['default_result_summary'] ?? []);
        $autoSummary = (array)($comparison['auto_candidate_result_summary'] ?? []);

        $profile['risk_profile_mode'] = (string)($result['risk_profile_mode'] ?? ($cfg['risk_profile_mode'] ?? 'working_real'));
        $profile['outcome_classification_profile'] = (string)($result['outcome_classification_profile'] ?? ($cfg['outcome_classification_profile'] ?? 'working_real_8_15'));
        $profile['real_learning_epoch_id'] = $result['real_learning_epoch_id'] ?? ($profile['real_learning_epoch_id'] ?? null);
        $profile['real_learning_epoch_start_at'] = $result['real_learning_epoch_start_at'] ?? ($profile['real_learning_epoch_start_at'] ?? null);
        $profile['source_outcomes_total'] = (int)($result['active_epoch_outcomes_total'] ?? $result['closed_outcomes_unique_total'] ?? 0);
        $profile['previous_epoch_outcomes_excluded_total'] = (int)($result['previous_epoch_outcomes_excluded_total'] ?? 0);
        $profile['active_epoch_outcomes_total'] = (int)($result['active_epoch_outcomes_total'] ?? $result['closed_outcomes_unique_total'] ?? 0);
        $profile['feature_records_total'] = (int)($result['feature_records_total'] ?? 0);
        $profile['bad_entries_total'] = (int)($result['bad_entry_total'] ?? ($profile['bad_entries_total'] ?? 0));
        $profile['good_entries_total'] = (int)($result['good_or_do_not_touch_total'] ?? ($profile['good_entries_total'] ?? 0));
        $profile['entry_ok_exit_issue_total'] = (int)($result['entry_ok_exit_issue_total'] ?? 0);
        $profile['neutral_total'] = (int)($result['neutral_total'] ?? 0);
        $profile['outcome_incomplete_total'] = (int)($result['outcome_incomplete_total'] ?? 0);
        $profile['rules'] = array_values((array)($profile['rules'] ?? []));
        $profile['weights'] = $this->diagnosticWeightsConfig();
        $profile['status'] = 'candidate';
        $profile['apply_mode'] = 'observe_only';
        $profile['compared_to_default'] = (bool)($comparison['compared_to_default'] ?? false);
        $profile['default_benchmark'] = $profile['compared_to_default'] ? $defaultSummary : null;
        $profile['auto_benchmark'] = $profile['compared_to_default'] ? $autoSummary : null;
        $profile['auto_not_worse_than_default'] = (bool)($comparison['auto_not_worse_than_default'] ?? false);
        $profile['auto_improvement_score'] = $comparison['auto_improvement_score'] ?? null;

        if (!$profile['compared_to_default']) {
            $profile['auto_comparison_reason'] = $comparisonReason !== '' ? $comparisonReason : 'comparison_not_implemented';
            if ($profile['default_benchmark'] === null) {
                $profile['default_benchmark'] = null;
            }
            if ($profile['auto_benchmark'] === null) {
                $profile['auto_benchmark'] = null;
            }
        }

        $profile['compare_auto_vs_default_enabled'] = (bool)($cfg['compare_auto_vs_default_enabled'] ?? true);
        $profile['auto_profile_requires_not_worse_than_default'] = (bool)($cfg['auto_profile_requires_not_worse_than_default'] ?? true);
        $profile['auto_apply_enabled'] = (bool)($cfg['auto_apply_enabled'] ?? false);

        $this->writeJson($profilePath, $profile);
    }

    /**
     * Sync rolling quality guard results into current_profile.json.
     * Called after runRollingQualityGuard() so the profile file always
     * reflects the latest guard decision.
     *
     * Also probes the availability of active_profile.json,
     * previous_good_profile.json, and rollback_history.ndjson,
     * writing availability flags into the profile and returning them
     * for inclusion in last_run.
     *
     * @param array<string,mixed> $cfg
     * @param array<string,mixed> $guardResult
     * @param array<string,mixed> $candidateBuild  From buildCandidateProfileFromSeparability()
     * @param array<string,mixed> $candidateReplay  From replayCandidateProfile()
     * @return array<string,mixed> Availability flags for last_run
     */
    private function syncRollingGuardToCurrentProfile(array $cfg, array $guardResult, array $candidateBuild = [], array $candidateReplay = []): array
    {
        $profilesDir   = $this->storagePath('profiles/early_impulse_growth_long');
        $profilePath   = $profilesDir . '/current_profile.json';
        $activeFile    = $profilesDir . '/active_profile.json';
        $prevGoodFile  = $profilesDir . '/previous_good_profile.json';
        $rollbackFile  = $profilesDir . '/rollback_history.ndjson';

        $activeAvailable   = is_file($activeFile);
        $prevGoodAvailable = is_file($prevGoodFile);
        $rollbackAvailable = is_file($rollbackFile);

        $activeMissingReason   = $activeAvailable   ? null : 'auto_apply_disabled_or_no_active_profile_yet';
        $prevGoodMissingReason = $prevGoodAvailable ? null : 'no_previous_good_profile_yet';

        $availability = [
            'active_profile_available'             => $activeAvailable,
            'active_profile_missing_reason'        => $activeMissingReason,
            'previous_good_profile_available'      => $prevGoodAvailable,
            'previous_good_profile_missing_reason' => $prevGoodMissingReason,
            'rollback_history_available'           => $rollbackAvailable,
        ];

        $profile = (array)$this->readJson($profilePath, []);
        if ($profile === []) {
            // No profile exists yet — nothing to sync into
            return $availability;
        }

        // Rolling guard fields
        $profile['quality_score']              = $guardResult['candidate_quality_score']  ?? ($guardResult['default_quality_score'] ?? null);
        $profile['default_benchmark']          = $guardResult['default_result_summary']   ?? null;
        $profile['active_dynamic_benchmark']   = $guardResult['active_dynamic_result_summary'] ?? null;
        $profile['candidate_benchmark']        = $guardResult['candidate_result_summary'] ?? null;
        $profile['compared_to_default']        = $guardResult['default_result_summary'] !== null;
        $profile['auto_not_worse_than_default'] = (bool)($cfg['require_not_worse_than_default'] ?? true);
        $profile['no_change_band_pct']         = $guardResult['no_change_band_pct'];
        $profile['min_candidate_improvement_pct'] = $guardResult['min_candidate_improvement_pct'];
        $profile['candidate_status']           = $guardResult['candidate_status']   ?? 'pending';
        $profile['promotion_decision']         = $guardResult['promotion_decision'] ?? 'none';
        $profile['promotion_reason']           = $guardResult['promotion_reason']   ?? null;
        $profile['auto_apply_safety_blocked'] = (bool)($guardResult['auto_apply_safety_blocked'] ?? true);
        $profile['auto_apply_safety_reason']  = $guardResult['auto_apply_safety_reason'] ?? null;
        $profile['rollback_guard_enabled']     = $guardResult['rollback_guard_enabled'];
        $profile['rollback_required']          = $guardResult['rollback_required'];
        $profile['rollback_reason']            = $guardResult['rollback_reason']    ?? null;
        $profile['rollback_cooldown_until']    = $guardResult['rollback_cooldown_until'] ?? null;
        $profile['apply_mode']                 = 'observe_only';

        // Candidate profile + replay fields (overrides guard where replay has data)
        $replayAvailable = (bool)($candidateReplay['candidate_replay_enabled'] ?? false);
        $profile['candidate_profile_id'] = $candidateBuild['profile_id'] ?? null;
        $profile['candidate_profile_written'] = (bool)($candidateBuild['profile_written'] ?? false);
        $profile['candidate_profile_available'] = $candidateBuild['available'] ?? false;
        $profile['candidate_profile_missing_reason'] = ((bool)($candidateBuild['profile_written'] ?? false))
            ? null
            : ($candidateBuild['missing_reason'] ?? 'candidate_profile_not_written');
        $profile['candidate_rules_total'] = (int)($candidateBuild['rules_total'] ?? 0);
        $profile['candidate_rules_missing_reason'] = ((int)($candidateBuild['rules_total'] ?? 0) > 0)
            ? null
            : $this->normalizeCandidateRulesMissingReason((string)($candidateBuild['missing_reason'] ?? ''));
        $profile['compared_to_default'] = true;

        if ($replayAvailable) {
            $replayCandScore = $candidateReplay['candidate_quality_score'] ?? null;
            if ($replayCandScore !== null) {
                $profile['quality_score'] = $replayCandScore;
                $profile['candidate_benchmark'] = $candidateReplay['candidate_result'] ?? $profile['candidate_benchmark'];
            }
            $profile['default_benchmark'] = $candidateReplay['default_baseline'] ?? $profile['default_benchmark'];
            $profile['candidate_status'] = (string)($candidateReplay['candidate_status'] ?? $profile['candidate_status']);
            $profile['promotion_decision'] = (string)($candidateReplay['promotion_decision'] ?? $profile['promotion_decision']);
            $profile['promotion_reason'] = $candidateReplay['promotion_reason'] ?? $profile['promotion_reason'];
            $profile['candidate_vs_default_delta_pct'] = $candidateReplay['candidate_vs_default_delta_pct'] ?? null;
            $profile['candidate_bad_entry_rate_delta_pct'] = $candidateReplay['candidate_bad_entry_rate_delta_pct'] ?? null;
            $profile['candidate_good_capture_delta_pct'] = $candidateReplay['candidate_good_capture_delta_pct'] ?? null;
            $profile['candidate_avg_roi_delta_pct'] = $candidateReplay['candidate_avg_roi_delta_pct'] ?? null;
            $profile['candidate_drawdown_delta_pct'] = $candidateReplay['candidate_drawdown_delta_pct'] ?? null;
            $profile['candidate_replay_summary'] = $this->buildCandidateReplaySummary($candidateReplay, $guardResult);
            $profile['default_quality_score'] = $candidateReplay['default_quality_score'] ?? $guardResult['default_quality_score'] ?? null;
            $profile['candidate_quality_score'] = $replayCandScore;
            $profile['auto_apply_safety_blocked'] = (bool)($candidateReplay['auto_apply_safety_blocked'] ?? true);
            $profile['auto_apply_safety_reason'] = $candidateReplay['auto_apply_safety_reason'] ?? null;
        } else {
            $profile['candidate_replay_summary'] = null;
            $profile['default_quality_score'] = $guardResult['default_quality_score'] ?? null;
            $profile['candidate_quality_score'] = $guardResult['candidate_quality_score'] ?? null;
            $profile['candidate_vs_default_delta_pct'] = $guardResult['candidate_vs_default_delta_pct'] ?? null;
            $profile['auto_apply_safety_blocked'] = true;
            $profile['auto_apply_safety_reason'] = 'candidate_not_eligible_for_demo_apply';
        }

        // Derive status from final candidate_status
        $candidateStatus = (string)($profile['candidate_status'] ?? 'pending');
        if ($candidateStatus === 'eligible_for_demo_apply') {
            $profile['status'] = 'eligible_for_demo_apply';
        } elseif (in_array($candidateStatus, ['insufficient_data', 'insufficient_bad_capture', 'pending', 'no_score'], true)) {
            $profile['status'] = 'observe_only';
        } elseif (in_array($candidateStatus, ['rejected', 'rejected_worse_than_default', 'below_improvement_threshold', 'no_material_improvement'], true)) {
            $profile['status'] = 'rejected';
        } else {
            $profile['status'] = 'observe_only';
        }

        // If candidate_benchmark is null, explain why
        if ($profile['candidate_benchmark'] === null) {
            $missingReason = (string)($profile['promotion_reason'] ?? 'insufficient_data');
            $profile['candidate_benchmark'] = [
                'benchmark_available'        => false,
                'benchmark_missing_reason'   => $missingReason,
            ];
        }

        // Profile file availability
        $profile['active_profile_available']             = $activeAvailable;
        $profile['active_profile_missing_reason']        = $activeMissingReason;
        $profile['previous_good_profile_available']      = $prevGoodAvailable;
        $profile['previous_good_profile_missing_reason'] = $prevGoodMissingReason;
        $profile['rollback_history_available']           = $rollbackAvailable;

        $this->writeJson($profilePath, $profile);
        return $availability;
    }

    /**
     * Ensure current_profile.json reflects final promotion gating and diagnostic scope fields.
     *
     * @param array<string,mixed> $result
     */
    private function syncCurrentProfilePromotionDiagnostics(array $result): void
    {
        $profilePath = $this->storagePath('profiles/early_impulse_growth_long/current_profile.json');
        $profile = (array)$this->readJson($profilePath, []);
        if ($profile === []) {
            return;
        }

        $profile['candidate_status'] = (string)($result['candidate_status'] ?? ($profile['candidate_status'] ?? 'pending'));
        $profile['promotion_decision'] = (string)($result['promotion_decision'] ?? ($profile['promotion_decision'] ?? 'none'));
        $profile['promotion_reason'] = $result['promotion_reason'] ?? ($profile['promotion_reason'] ?? null);
        $profile['promotion_blocked_by_min_data'] = (bool)($result['promotion_blocked_by_min_data'] ?? false);
        $profile['promotion_blocked_reason'] = $result['promotion_blocked_reason'] ?? null;
        $profile['candidate_can_apply'] = (bool)($result['candidate_can_apply'] ?? false);
        $profile['candidate_eligible_for_demo_apply'] = (bool)($result['candidate_eligible_for_demo_apply'] ?? false);
        $profile['replay_diagnostic_available'] = (bool)($result['replay_diagnostic_available'] ?? false);
        $profile['replay_suggests_improvement'] = (bool)($result['replay_suggests_improvement'] ?? false);
        $profile['candidate_replay_summary'] = $this->buildCandidateReplaySummary(
            (array)($result['candidate_replay_summary'] ?? ($profile['candidate_replay_summary'] ?? [])),
            $result
        );
        $profile['candidate_rules_missing_reason'] = $result['candidate_rules_missing_reason'] ?? ($profile['candidate_rules_missing_reason'] ?? null);
        $profile['composite_candidate_source'] = (string)($result['composite_candidate_source'] ?? ($profile['composite_candidate_source'] ?? 'missing'));
        $profile['composite_candidates_available_total'] = (int)($result['composite_candidates_available_total'] ?? ($profile['composite_candidates_available_total'] ?? 0));
        $profile['composite_candidates_tested_total'] = (int)($result['composite_candidates_tested_total'] ?? ($profile['composite_candidates_tested_total'] ?? 0));
        $profile['composite_candidates_passed_total'] = (int)($result['composite_candidates_passed_total'] ?? ($profile['composite_candidates_passed_total'] ?? 0));
        $profile['composite_candidates_rejected_total'] = (int)($result['composite_candidates_rejected_total'] ?? ($profile['composite_candidates_rejected_total'] ?? 0));
        $profile['composite_candidate_reject_reason_counts'] = (array)($result['composite_candidate_reject_reason_counts'] ?? ($profile['composite_candidate_reject_reason_counts'] ?? []));
        $profile['composite_candidate_no_selection_reason'] = $result['composite_candidate_no_selection_reason'] ?? ($profile['composite_candidate_no_selection_reason'] ?? null);
        $profile['candidate_build_scope'] = (string)($result['candidate_build_scope'] ?? 'active_epoch');
        $profile['candidate_replay_scope'] = (string)($result['candidate_replay_scope'] ?? 'active_epoch');
        $profile['promotion_guard_scope'] = (string)($result['promotion_guard_scope'] ?? 'rolling_window');
        $profile['scope_mismatch_allowed_for_diagnostics'] = (bool)($result['scope_mismatch_allowed_for_diagnostics'] ?? true);
        $profile['auto_apply_safety_blocked'] = (bool)($result['auto_apply_safety_blocked'] ?? true);
        $profile['auto_apply_safety_reason'] = $result['auto_apply_safety_reason'] ?? ($profile['auto_apply_safety_reason'] ?? 'candidate_not_eligible_for_demo_apply');

        if ((bool)($profile['promotion_blocked_by_min_data'] ?? false)) {
            $profile['status'] = 'observe_only';
        }

        $this->writeJson($profilePath, $profile);
    }

    /**
     * Ensure candidate_profile.json carries eligibility diagnostics while staying observe-only.
     *
     * @param array<string,mixed> $result
     */
    private function syncCandidateProfileEligibilityDiagnostics(array $result): void
    {
        $candidatePath = $this->storagePath('profiles/early_impulse_growth_long/candidate_profile.json');
        $candidate = (array)$this->readJson($candidatePath, []);
        if ($candidate === []) {
            return;
        }

        $blockedByMinData = (bool)($result['promotion_blocked_by_min_data'] ?? false);
        $eligible = (bool)($result['candidate_eligible_for_demo_apply'] ?? false) && !$blockedByMinData;
        $noRules = ((int)($result['candidate_rules_total'] ?? 0) === 0);
        $status = $blockedByMinData
            ? 'insufficient_data'
            : ($noRules ? 'no_safe_candidate_rules' : 'candidate_diagnostic_only');

        $candidate['eligibility_status'] = $status;
        $candidate['eligible_for_demo_apply'] = $eligible;
        $candidate['promotion_blocked_by_min_data'] = $blockedByMinData;
        $candidate['promotion_blocked_reason'] = $result['promotion_blocked_reason'] ?? null;
        $candidate['promotion_decision'] = (string)($result['promotion_decision'] ?? 'keep_current');
        $candidate['promotion_reason'] = $result['promotion_reason'] ?? null;
        $candidate['apply_mode'] = 'observe_only';
        $candidate['status'] = $status;
        $candidate['replay_diagnostic_available'] = (bool)($result['replay_diagnostic_available'] ?? false);
        $candidate['replay_suggests_improvement'] = (bool)($result['replay_suggests_improvement'] ?? false);
        $candidate['candidate_build_scope'] = (string)($result['candidate_build_scope'] ?? 'active_epoch');
        $candidate['candidate_replay_scope'] = (string)($result['candidate_replay_scope'] ?? 'active_epoch');
        $candidate['promotion_guard_scope'] = (string)($result['promotion_guard_scope'] ?? 'rolling_window');
        $candidate['scope_mismatch_allowed_for_diagnostics'] = (bool)($result['scope_mismatch_allowed_for_diagnostics'] ?? true);

        $jsonStr = json_encode($candidate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($jsonStr)) {
            @file_put_contents($candidatePath, $jsonStr, LOCK_EX);
        }
    }

    /**
     * Build a current_profile-safe replay summary that keeps replay-only diagnostics
     * separate from final rolling-guard decision fields.
     *
     * @param array<string,mixed> $candidateReplay
     * @param array<string,mixed> $finalResult
     * @return array<string,mixed>|null
     */
    private function buildCandidateReplaySummary(array $candidateReplay, array $finalResult = []): ?array
    {
        $replayAvailable = (bool)($finalResult['replay_diagnostic_available']
            ?? $candidateReplay['replay_diagnostic_available']
            ?? $candidateReplay['candidate_replay_enabled']
            ?? false);

        if (!$replayAvailable
            && !isset($candidateReplay['replay_result'])
            && !isset($finalResult['replay_result'])
            && !isset($candidateReplay['replay_trades_total'])
            && !isset($finalResult['replay_trades_total'])) {
            return null;
        }

        return [
            'replay_result' => (string)($finalResult['replay_result'] ?? $candidateReplay['replay_result'] ?? 'no_improvement'),
            'replay_suggests_improvement' => (bool)($finalResult['replay_suggests_improvement'] ?? $candidateReplay['replay_suggests_improvement'] ?? false),
            'replay_candidate_status' => (string)($finalResult['replay_candidate_status'] ?? $candidateReplay['replay_candidate_status'] ?? 'insufficient_replay_data'),
            'replay_trades_total' => (int)($finalResult['replay_trades_total'] ?? $candidateReplay['replay_trades_total'] ?? 0),
            'replay_bad_blocked_total' => (int)($finalResult['replay_bad_blocked_total'] ?? $candidateReplay['replay_bad_blocked_total'] ?? $candidateReplay['bad_blocked'] ?? 0),
            'replay_good_blocked_total' => (int)($finalResult['replay_good_blocked_total'] ?? $candidateReplay['replay_good_blocked_total'] ?? $candidateReplay['good_blocked'] ?? 0),
            'replay_bad_capture_rate_pct' => $finalResult['replay_bad_capture_rate_pct'] ?? $candidateReplay['replay_bad_capture_rate_pct'] ?? $candidateReplay['bad_capture_rate_pct'] ?? null,
            'replay_good_block_rate_pct' => $finalResult['replay_good_block_rate_pct'] ?? $candidateReplay['replay_good_block_rate_pct'] ?? $candidateReplay['good_block_rate_pct'] ?? null,
            'replay_net_score' => $finalResult['replay_net_score'] ?? $candidateReplay['replay_net_score'] ?? null,
            'default_quality_score' => $finalResult['default_quality_score'] ?? $candidateReplay['default_quality_score'] ?? null,
            'candidate_quality_score' => $finalResult['candidate_quality_score'] ?? $candidateReplay['candidate_quality_score'] ?? null,
            'candidate_vs_default_delta_pct' => $finalResult['candidate_vs_default_delta_pct'] ?? $candidateReplay['candidate_vs_default_delta_pct'] ?? $candidateReplay['delta'] ?? null,
            'final_candidate_status' => (string)($finalResult['final_candidate_status'] ?? $finalResult['candidate_status'] ?? $candidateReplay['final_candidate_status'] ?? 'pending'),
            'final_promotion_decision' => (string)($finalResult['final_promotion_decision'] ?? $finalResult['promotion_decision'] ?? $candidateReplay['final_promotion_decision'] ?? 'keep_current'),
            'final_promotion_reason' => $finalResult['final_promotion_reason'] ?? $finalResult['promotion_reason'] ?? $candidateReplay['final_promotion_reason'] ?? null,
            'final_candidate_eligible_for_demo_apply' => (bool)($finalResult['final_candidate_eligible_for_demo_apply'] ?? $finalResult['candidate_eligible_for_demo_apply'] ?? $candidateReplay['final_candidate_eligible_for_demo_apply'] ?? false),
            'promotion_blocked_by_min_data' => (bool)($finalResult['promotion_blocked_by_min_data'] ?? $candidateReplay['promotion_blocked_by_min_data'] ?? false),
            'promotion_guard_scope' => (string)($finalResult['promotion_guard_scope'] ?? $candidateReplay['promotion_guard_scope'] ?? 'rolling_window'),
        ];
    }

    /**
     * Compute quality metrics for a set of classified outcomes.
     *
     * @param array<array<string,mixed>> $outcomes
     * @param array<string,float> $weights
     * @return array<string,mixed>
     */
    private function computeQualityMetrics(array $outcomes, array $weights): array
    {
        $total = count($outcomes);
        if ($total === 0) {
            return [
                'benchmark_available' => false,
                'benchmark_missing_reason' => 'no_outcomes',
                'trades_total' => 0,
            ];
        }

        $badTotal = 0;
        $goodTotal = 0;
        $exitIssueTotal = 0;
        $neutralTotal = 0;
        $sumCloseRoi = 0.0;
        $sumMaxDrawdown = 0.0;
        $sumMaxProfit = 0.0;
        $minDrawdown = 0.0;
        $maxProfit = 0.0;
        $hasCloseRoi = 0;
        $hasDrawdown = 0;
        $hasMaxProfit = 0;

        foreach ($outcomes as $o) {
            if (!is_array($o)) {
                continue;
            }
            $cls = (string)($o['outcome_class'] ?? 'neutral');
            switch ($cls) {
                case 'bad_entry':
                    $badTotal++;
                    break;
                case 'good_or_do_not_touch':
                    $goodTotal++;
                    break;
                case 'entry_ok_exit_issue':
                    $exitIssueTotal++;
                    break;
                default:
                    $neutralTotal++;
                    break;
            }
            $closeRoi = DlHelpers::toFloat($o['close_roi'] ?? null);
            if ($closeRoi !== null) {
                $sumCloseRoi += $closeRoi;
                $hasCloseRoi++;
            }
            $maxDd = DlHelpers::toFloat($o['normalized_max_drawdown_roi'] ?? ($o['max_drawdown_roi'] ?? null));
            if ($maxDd !== null) {
                $sumMaxDrawdown += $maxDd;
                $hasDrawdown++;
                if ($maxDd < $minDrawdown) {
                    $minDrawdown = $maxDd;
                }
            }
            $maxProfitLocal = DlHelpers::toFloat($o['normalized_max_profit_roi'] ?? ($o['max_profit_roi'] ?? null));
            if ($maxProfitLocal !== null) {
                $sumMaxProfit += $maxProfitLocal;
                $hasMaxProfit++;
                if ($maxProfitLocal > $maxProfit) {
                    $maxProfit = $maxProfitLocal;
                }
            }
        }

        $winrate = ($goodTotal + $exitIssueTotal) / $total * 100.0;
        $badRate = $badTotal / $total * 100.0;
        $goodCapture = ($goodTotal + $exitIssueTotal) / $total * 100.0;
        $exitIssueRate = $exitIssueTotal / $total * 100.0;
        $avgCloseRoi = $hasCloseRoi > 0 ? $sumCloseRoi / $hasCloseRoi : null;
        $avgMaxDrawdown = $hasDrawdown > 0 ? $sumMaxDrawdown / $hasDrawdown : null;
        $avgMaxProfit = $hasMaxProfit > 0 ? $sumMaxProfit / $hasMaxProfit : null;

        $wGood = (float)($weights['quality_weight_good_capture'] ?? 1.0);
        $wRoi = (float)($weights['quality_weight_avg_roi'] ?? 1.0);
        $wBad = (float)($weights['quality_weight_bad_entry'] ?? 1.5);
        $wDd = (float)($weights['quality_weight_drawdown'] ?? 1.0);
        $wExit = (float)($weights['quality_weight_entry_ok_exit_issue'] ?? 0.5);

        $qualityScore = $wGood * $goodCapture
            + $wRoi * ($avgCloseRoi !== null ? $avgCloseRoi : 0.0)
            - $wBad * $badRate
            - $wDd * ($avgMaxDrawdown !== null ? abs($avgMaxDrawdown) : 0.0)
            - $wExit * $exitIssueRate;

        return [
            'benchmark_available' => true,
            'benchmark_missing_reason' => null,
            'trades_total' => $total,
            'bad_entry_total' => $badTotal,
            'good_or_do_not_touch_total' => $goodTotal,
            'entry_ok_exit_issue_total' => $exitIssueTotal,
            'neutral_total' => $neutralTotal,
            'winrate_pct' => round($winrate, 2),
            'bad_entry_rate_pct' => round($badRate, 2),
            'good_capture_rate_pct' => round($goodCapture, 2),
            'avg_close_roi' => $avgCloseRoi !== null ? round($avgCloseRoi, 4) : null,
            'avg_max_drawdown_roi' => $avgMaxDrawdown !== null ? round($avgMaxDrawdown, 4) : null,
            'avg_max_profit_roi' => $avgMaxProfit !== null ? round($avgMaxProfit, 4) : null,
            'max_drawdown_roi' => round($minDrawdown, 4),
            'quality_score' => round($qualityScore, 4),
        ];
    }

    /**
     * Rolling quality guard: compute default/candidate quality scores,
     * apply no-change zone and promotion rules, rollback check,
     * and write to candidate_history.ndjson.
     *
     * @param array<array<string,mixed>> $epochOutcomes All active-epoch outcomes available for pattern mining
     * @param array<string,mixed> $result Current result array (used for epoch/apply flags)
     * @return array<string,mixed>
     */
    private function runRollingQualityGuard(array $cfg, array $epochOutcomes, array $result): array
    {
        $enabled = (bool)($cfg['rolling_learning_enabled'] ?? true);
        $windowMinutes = max(1, (int)($cfg['rolling_learning_window_minutes'] ?? 120));
        $retrainInterval = (int)($cfg['rolling_retrain_interval_minutes'] ?? 60);
        $minOutcomes = max(1, (int)($cfg['rolling_min_closed_outcomes'] ?? 20));
        $minBad = (int)($cfg['rolling_min_bad_entries'] ?? 3);
        $minGood = (int)($cfg['rolling_min_good_entries'] ?? 3);
        $minImprovementPct = (float)($cfg['min_candidate_improvement_pct'] ?? 7.0);
        $noChangeBandPct = (float)($cfg['no_change_band_pct'] ?? 5.0);
        $maxBadRateIncreasePct = (float)($cfg['max_allowed_bad_entry_rate_increase_pct'] ?? 10.0);
        $maxDrawdownIncreasePct = (float)($cfg['max_allowed_drawdown_increase_pct'] ?? 10.0);
        $rollbackEnabled = (bool)($cfg['rollback_guard_enabled'] ?? true);
        $rollbackCooldownMinutes = max(1, (int)($cfg['rollback_cooldown_minutes'] ?? 120));
        $requireNotWorse = (bool)($cfg['require_not_worse_than_default'] ?? true);
        $autoApplyDemo = (bool)($cfg['auto_apply_to_demo_enabled'] ?? false);
        $autoApplyLive = (bool)($cfg['auto_apply_to_live_enabled'] ?? false);
        $maxCandHistory = max(10, (int)($cfg['max_candidate_history_records'] ?? 500));

        $weights = [
            'quality_weight_good_capture' => (float)($cfg['quality_weight_good_capture'] ?? 1.0),
            'quality_weight_avg_roi' => (float)($cfg['quality_weight_avg_roi'] ?? 1.0),
            'quality_weight_bad_entry' => (float)($cfg['quality_weight_bad_entry'] ?? 1.5),
            'quality_weight_drawdown' => (float)($cfg['quality_weight_drawdown'] ?? 1.0),
            'quality_weight_entry_ok_exit_issue' => (float)($cfg['quality_weight_entry_ok_exit_issue'] ?? 0.5),
        ];

        $guardResult = [
            'rolling_learning_enabled' => $enabled,
            'rolling_learning_window_minutes' => $windowMinutes,
            'rolling_retrain_interval_minutes' => $retrainInterval,
            'rolling_min_closed_outcomes' => $minOutcomes,
            'rolling_min_bad_entries' => $minBad,
            'rolling_min_good_entries' => $minGood,
            'rolling_window_outcomes_total' => 0,
            'rolling_window_bad_entry_total' => 0,
            'rolling_window_good_entry_total' => 0,
            'rolling_window_entry_ok_exit_issue_total' => 0,
            'default_quality_score' => null,
            'active_dynamic_quality_score' => null,
            'candidate_quality_score' => null,
            'candidate_vs_default_delta_pct' => null,
            'candidate_vs_active_delta_pct' => null,
            'no_change_band_pct' => $noChangeBandPct,
            'min_candidate_improvement_pct' => $minImprovementPct,
            'default_result_summary' => null,
            'active_dynamic_result_summary' => null,
            'candidate_result_summary' => null,
            'candidate_status' => 'pending',
            'promotion_decision' => 'none',
            'promotion_reason' => null,
            'auto_apply_safety_blocked' => true,
            'auto_apply_safety_reason' => 'candidate_not_eligible_for_demo_apply',
            'auto_apply_to_demo_enabled' => $autoApplyDemo,
            'auto_apply_to_live_enabled' => $autoApplyLive,
            'require_not_worse_than_default' => $requireNotWorse,
            'rollback_guard_enabled' => $rollbackEnabled,
            'rollback_required' => false,
            'rollback_reason' => null,
            'rollback_cooldown_until' => null,
            'rollback_action' => null,
        ];

        if (!$enabled) {
            $guardResult['promotion_reason'] = 'rolling_learning_disabled';
            return $guardResult;
        }

        if (empty($epochOutcomes)) {
            $guardResult['promotion_reason'] = 'no_epoch_outcomes';
            return $guardResult;
        }

        // Default quality: full epoch outcomes
        $defaultMetrics = $this->computeQualityMetrics($epochOutcomes, $weights);
        $guardResult['default_result_summary'] = $defaultMetrics;
        $guardResult['default_quality_score'] = ($defaultMetrics['benchmark_available'] ?? false)
            ? $defaultMetrics['quality_score']
            : null;

        // Active dynamic quality: no active dynamic profile (observe_only mode)
        $guardResult['active_dynamic_result_summary'] = null;
        $guardResult['active_dynamic_quality_score'] = null;

        // Rollback check: only applicable when an active dynamic profile exists
        // (currently always false since apply is disabled)
        if ($rollbackEnabled && $guardResult['active_dynamic_quality_score'] !== null && $guardResult['default_quality_score'] !== null) {
            $activeDynScore = (float)$guardResult['active_dynamic_quality_score'];
            $defaultScore = (float)$guardResult['default_quality_score'];
            $degradation = $defaultScore - $activeDynScore;
            if ($degradation > (float)($cfg['max_allowed_quality_degradation_pct'] ?? 10.0)) {
                $guardResult['rollback_required'] = true;
                $guardResult['rollback_reason'] = 'quality_degradation';
                $guardResult['rollback_cooldown_until'] = date('c', time() + $rollbackCooldownMinutes * 60);
                $guardResult['rollback_action'] = 'diagnostic_only_not_applied';
            }
        }

        // Rolling window filter: outcomes closed within last N minutes
        $windowStart = time() - ($windowMinutes * 60);
        $rollingOutcomes = array_values(array_filter($epochOutcomes, static function (array $o) use ($windowStart): bool {
            $closedAt = (string)($o['closed_at'] ?? '');
            if ($closedAt === '') {
                return false;
            }
            $ts = @strtotime($closedAt) ?: 0;
            return $ts >= $windowStart;
        }));

        $guardResult['rolling_window_outcomes_total'] = count($rollingOutcomes);
        foreach ($rollingOutcomes as $ro) {
            if (!is_array($ro)) {
                continue;
            }
            switch ((string)($ro['outcome_class'] ?? '')) {
                case 'bad_entry':
                    $guardResult['rolling_window_bad_entry_total']++;
                    break;
                case 'good_or_do_not_touch':
                    $guardResult['rolling_window_good_entry_total']++;
                    break;
                case 'entry_ok_exit_issue':
                    $guardResult['rolling_window_entry_ok_exit_issue_total']++;
                    break;
            }
        }

        // Check minimum data requirements
        $rollingTotal = count($rollingOutcomes);
        if ($rollingTotal < $minOutcomes) {
            $guardResult['candidate_status'] = 'insufficient_data';
            $guardResult['promotion_decision'] = 'keep_current';
            $guardResult['promotion_reason'] = 'rolling_window_below_min_outcomes';
            $this->appendCandidateHistory($guardResult, $maxCandHistory);
            return $guardResult;
        }
        if ($guardResult['rolling_window_bad_entry_total'] < $minBad) {
            $guardResult['candidate_status'] = 'insufficient_data';
            $guardResult['promotion_decision'] = 'keep_current';
            $guardResult['promotion_reason'] = 'rolling_window_below_min_bad_entries';
            $this->appendCandidateHistory($guardResult, $maxCandHistory);
            return $guardResult;
        }
        if ($guardResult['rolling_window_good_entry_total'] < $minGood) {
            $guardResult['candidate_status'] = 'insufficient_data';
            $guardResult['promotion_decision'] = 'keep_current';
            $guardResult['promotion_reason'] = 'rolling_window_below_min_good_entries';
            $this->appendCandidateHistory($guardResult, $maxCandHistory);
            return $guardResult;
        }

        // Candidate quality from rolling window
        $candidateMetrics = $this->computeQualityMetrics($rollingOutcomes, $weights);
        $guardResult['candidate_result_summary'] = $candidateMetrics;
        $guardResult['candidate_quality_score'] = ($candidateMetrics['benchmark_available'] ?? false)
            ? $candidateMetrics['quality_score']
            : null;

        if ($guardResult['candidate_quality_score'] === null || $guardResult['default_quality_score'] === null) {
            $guardResult['candidate_status'] = 'no_score';
            $guardResult['promotion_decision'] = 'keep_current';
            $guardResult['promotion_reason'] = 'quality_score_unavailable';
            $this->appendCandidateHistory($guardResult, $maxCandHistory);
            return $guardResult;
        }

        $candidateScore = (float)$guardResult['candidate_quality_score'];
        $defaultScore = (float)$guardResult['default_quality_score'];
        $delta = $candidateScore - $defaultScore;
        $guardResult['candidate_vs_default_delta_pct'] = round($delta, 4);

        // No-change band check
        $defaultWinrate = (float)($defaultMetrics['winrate_pct'] ?? 0.0);
        $candidateWinrate = (float)($candidateMetrics['winrate_pct'] ?? 0.0);
        $defaultAvgRoi = (float)($defaultMetrics['avg_close_roi'] ?? 0.0);
        $candidateAvgRoi = (float)($candidateMetrics['avg_close_roi'] ?? 0.0);
        $isInNoChangeBand = abs($delta) < $noChangeBandPct
            && abs($candidateWinrate - $defaultWinrate) < $noChangeBandPct
            && abs($candidateAvgRoi - $defaultAvgRoi) < $noChangeBandPct;

        if ($isInNoChangeBand) {
            $guardResult['candidate_status'] = 'no_material_improvement';
            $guardResult['promotion_decision'] = 'keep_current';
            $guardResult['promotion_reason'] = 'improvement_inside_noise_band';
            $this->appendCandidateHistory($guardResult, $maxCandHistory);
            return $guardResult;
        }

        // Improvement threshold check
        if ($delta < $minImprovementPct) {
            $guardResult['candidate_status'] = 'below_improvement_threshold';
            $guardResult['promotion_decision'] = 'reject_candidate';
            $guardResult['promotion_reason'] = 'candidate_delta_below_min_improvement';
            $this->appendCandidateHistory($guardResult, $maxCandHistory);
            return $guardResult;
        }

        // Not-worse-than-default checks
        if ($requireNotWorse) {
            $defaultBadRate = (float)($defaultMetrics['bad_entry_rate_pct'] ?? 0.0);
            $candidateBadRate = (float)($candidateMetrics['bad_entry_rate_pct'] ?? 0.0);
            if (($candidateBadRate - $defaultBadRate) > $maxBadRateIncreasePct) {
                $guardResult['candidate_status'] = 'rejected_worse_than_default';
                $guardResult['promotion_decision'] = 'reject_candidate';
                $guardResult['promotion_reason'] = 'bad_entry_rate_worse_than_default';
                $this->appendCandidateHistory($guardResult, $maxCandHistory);
                return $guardResult;
            }
            $defaultAvgDd = abs((float)($defaultMetrics['avg_max_drawdown_roi'] ?? 0.0));
            $candidateAvgDd = abs((float)($candidateMetrics['avg_max_drawdown_roi'] ?? 0.0));
            if ($defaultAvgDd > 0.0) {
                $ddIncreasePct = ($candidateAvgDd - $defaultAvgDd) / $defaultAvgDd * 100.0;
                if ($ddIncreasePct > $maxDrawdownIncreasePct) {
                    $guardResult['candidate_status'] = 'rejected_worse_than_default';
                    $guardResult['promotion_decision'] = 'reject_candidate';
                    $guardResult['promotion_reason'] = 'drawdown_worse_than_default';
                    $this->appendCandidateHistory($guardResult, $maxCandHistory);
                    return $guardResult;
                }
            }
        }

        // Eligible for demo apply
        $guardResult['candidate_status'] = 'eligible_for_demo_apply';
        if ($autoApplyDemo && (bool)($cfg['apply_learning_to_strategy_enabled'] ?? false)) {
            $guardResult['promotion_decision'] = 'promote_candidate_demo';
            $guardResult['promotion_reason'] = 'candidate_passes_all_checks';
            $guardResult['auto_apply_safety_blocked'] = false;
            $guardResult['auto_apply_safety_reason'] = null;
        } elseif ($autoApplyDemo) {
            $guardResult['promotion_decision'] = 'candidate_ready_but_apply_disabled';
            $guardResult['promotion_reason'] = 'apply_learning_to_strategy_enabled_is_false';
            $guardResult['auto_apply_safety_blocked'] = true;
            $guardResult['auto_apply_safety_reason'] = 'apply_learning_to_strategy_enabled_is_false';
        } else {
            $guardResult['promotion_decision'] = 'candidate_ready_but_apply_disabled';
            $guardResult['promotion_reason'] = 'auto_apply_to_demo_enabled_is_false';
            $guardResult['auto_apply_safety_blocked'] = true;
            $guardResult['auto_apply_safety_reason'] = 'auto_apply_to_demo_enabled_is_false';
        }

        $this->appendCandidateHistory($guardResult, $maxCandHistory);
        return $guardResult;
    }

    /**
     * @param array<string,mixed> $guardResult
     * @return array{passed:bool,blocked:bool,reason:string|null}
     */
    private function evaluatePromotionMinDataGate(array $guardResult): array
    {
        $minOutcomes = (int)($guardResult['rolling_min_closed_outcomes'] ?? 0);
        $minBad = (int)($guardResult['rolling_min_bad_entries'] ?? 0);
        $minGood = (int)($guardResult['rolling_min_good_entries'] ?? 0);
        $total = (int)($guardResult['rolling_window_outcomes_total'] ?? 0);
        $bad = (int)($guardResult['rolling_window_bad_entry_total'] ?? 0);
        $good = (int)($guardResult['rolling_window_good_entry_total'] ?? 0);

        if ($total < $minOutcomes) {
            return ['passed' => false, 'blocked' => true, 'reason' => 'rolling_window_below_min_outcomes'];
        }
        if ($bad < $minBad || $good < $minGood) {
            return ['passed' => false, 'blocked' => true, 'reason' => 'rolling_window_below_min_bad_good_counts'];
        }
        return ['passed' => true, 'blocked' => false, 'reason' => null];
    }

    /**
     * Append a rolling guard decision entry to candidate_history.ndjson.
     * Keeps the file bounded to $maxRecords lines.
     *
     * @param array<string,mixed> $guardResult
     * @param int $maxRecords
     * @param string $source  'rolling_guard' or 'replay_evaluator'
     */
    private function appendCandidateHistory(array $guardResult, int $maxRecords, string $source = 'rolling_guard'): void
    {
        $histPath = $this->storagePath('profiles/early_impulse_growth_long/candidate_history.ndjson');
        $dir = dirname($histPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $entry = [
            'recorded_at' => date('c'),
            'generated_at' => date('c'),
            'source' => $source,
            'candidate_profile_id' => $guardResult['candidate_profile_id'] ?? $guardResult['profile_id'] ?? null,
            'candidate_status' => $guardResult['candidate_status'] ?? 'unknown',
            'promotion_decision' => $guardResult['promotion_decision'] ?? 'none',
            'promotion_reason' => $guardResult['promotion_reason'] ?? null,
            'auto_apply_safety_blocked' => (bool)($guardResult['auto_apply_safety_blocked'] ?? true),
            'auto_apply_safety_reason' => $guardResult['auto_apply_safety_reason'] ?? null,
            'default_quality_score' => $guardResult['default_quality_score'] ?? null,
            'candidate_quality_score' => $guardResult['candidate_quality_score'] ?? null,
            'candidate_vs_default_delta_pct' => $guardResult['candidate_vs_default_delta_pct'] ?? null,
            'rolling_window_outcomes_total' => $guardResult['rolling_window_outcomes_total'] ?? 0,
            'rolling_window_bad_entry_total' => $guardResult['rolling_window_bad_entry_total'] ?? 0,
            'rolling_window_good_entry_total' => $guardResult['rolling_window_good_entry_total'] ?? 0,
            'replay_result' => $guardResult['replay_result'] ?? null,
            'replay_candidate_status' => $guardResult['replay_candidate_status'] ?? null,
            'replay_suggests_improvement' => (bool)($guardResult['replay_suggests_improvement'] ?? false),
            'final_candidate_status' => $guardResult['final_candidate_status'] ?? null,
            'final_promotion_decision' => $guardResult['final_promotion_decision'] ?? null,
            'final_promotion_reason' => $guardResult['final_promotion_reason'] ?? null,
            'final_candidate_eligible_for_demo_apply' => (bool)($guardResult['final_candidate_eligible_for_demo_apply'] ?? false),
            'promotion_blocked_by_min_data' => (bool)($guardResult['promotion_blocked_by_min_data'] ?? false),
            'promotion_blocked_reason' => $guardResult['promotion_blocked_reason'] ?? null,
            'rollback_required' => $guardResult['rollback_required'] ?? false,
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($line)) {
            return;
        }

        @file_put_contents($histPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        // Prune to bounded size
        if ($maxRecords > 0 && is_file($histPath)) {
            $lines = @file($histPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines) && count($lines) > $maxRecords) {
                $lines = array_slice($lines, -$maxRecords);
                @file_put_contents($histPath, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
            }
        }
    }

    /** @return array<string,mixed> */
    private function diagnosticWeightsConfig(): array
    {
        return [
            'version' => 'v1',
            'source' => 'static_initial',
            'status' => 'diagnostic_only',
            'risk_components' => [
                'single_candle_dominance_high' => 20.0,
                'micro_entry_after_spike' => 15.0,
                'concentrated_growth' => 12.0,
                'high_rejection_wick' => 10.0,
                'knife_bounce' => 10.0,
                'choppy_dump' => 8.0,
                'fast_flip_chop' => 15.0,
                'ask_wall_high' => 15.0,
                'bid_support_weak' => 10.0,
                'oi_not_confirmed' => 5.0,
            ],
            'quality_components' => [
                'smooth_birth' => 18.0,
                'distributed_growth' => 14.0,
                'controlled_dump' => 10.0,
                'post_dump_stabilized' => 10.0,
                'higher_close_sequence' => 12.0,
                'higher_low_sequence' => 10.0,
                'bid_support_strong' => 10.0,
                'oi_confirmed' => 6.0,
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Candidate profile builder from micro separability diagnostics
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build a candidate profile using micro separability diagnostics
     * produced by PatternMiner.  Diagnostic / observe-only — never applied.
     *
     * @param array<string,mixed>         $cfg
     * @param array<array<string,mixed>>  $patternMiningOutcomes  Active-epoch outcomes
     * @param array<string,mixed>         $patternResult          PatternMiner::mine() output
     * @param array<string,mixed>         $epochMeta
     * @return array{profile:array<string,mixed>,profile_id:string,rules_total:int,components_total:int,available:bool,missing_reason:string|null,profile_written:bool}
     */
    private function buildCandidateProfileFromSeparability(
        array $cfg,
        array $patternMiningOutcomes,
        array $patternResult,
        array $epochMeta
    ): array {
        $minSepScore    = (float)($cfg['candidate_min_separation_score']     ?? 0.20);
        $maxGoodBlock   = (float)($cfg['candidate_max_good_block_rate_pct']  ?? 20.0);
        $minBadCapture  = (float)($cfg['candidate_min_bad_capture_rate_pct'] ?? 20.0);
        $maxRules       = max(1, (int)($cfg['candidate_max_rules']           ?? 10));
        $allowBroad     = (bool)($cfg['candidate_allow_broad_features']      ?? false);
        $minBadEntries  = max(1, (int)($cfg['rolling_min_bad_entries']       ?? 3));

        // Broad feature base names — excluded unless allowed
        $broadBases = ['context_phase', 'wave_regime', 'ask_wall_risk', 'open_interest_confirmed'];

        // Count outcome classes
        $totalBad = $totalGood = $totalExit = $totalNeutral = 0;
        foreach ($patternMiningOutcomes as $o) {
            if (!is_array($o)) {
                continue;
            }
            switch ((string)($o['outcome_class'] ?? '')) {
                case 'bad_entry':             $totalBad++;     break;
                case 'good_or_do_not_touch':  $totalGood++;    break;
                case 'entry_ok_exit_issue':   $totalExit++;    break;
                default:                      $totalNeutral++; break;
            }
        }

        $totalOutcomes = count($patternMiningOutcomes);
        if ($totalOutcomes === 0) {
            return $this->makeCandidateProfileEmpty($cfg, $epochMeta, $totalBad, $totalGood, $totalExit, $totalNeutral, 'insufficient_data');
        }

        // Index separation scores by feature from micro_feature_distributions
        $sepByFeature = [];
        foreach ((array)(($patternResult['micro_feature_distributions'] ?? [])['features'] ?? []) as $fd) {
            if (is_array($fd) && isset($fd['feature'])) {
                $sepByFeature[(string)$fd['feature']] = $fd;
            }
        }

        $selectedRules    = [];
        $excludedReasons  = [];
        $singleFeatureCandidatesTotal = 0;

        foreach ((array)(($patternResult['micro_threshold_candidates'] ?? [])['candidates'] ?? []) as $tc) {
            if (!is_array($tc)) {
                continue;
            }

            $feature    = (string)($tc['feature']              ?? '');
            $confidence = (string)($tc['confidence']           ?? 'low');
            $direction  = (string)($tc['direction']            ?? 'unclear');
            $badCount   = (int)($tc['bad_captured_count']      ?? 0);
            $goodCount  = (int)($tc['good_blocked_count']      ?? 0);
            $netScore   = (float)($tc['net_score']             ?? 0.0);
            $threshold  = $tc['candidate_threshold']           ?? null;

            if ($feature === '') {
                continue;
            }
            $singleFeatureCandidatesTotal++;
            if ($confidence === 'low') {
                $excludedReasons['low_confidence'] = ($excludedReasons['low_confidence'] ?? 0) + 1;
                continue;
            }
            if ($direction === 'unclear' || $threshold === null) {
                $excludedReasons['unclear_or_no_threshold'] = ($excludedReasons['unclear_or_no_threshold'] ?? 0) + 1;
                continue;
            }
            if (!$allowBroad) {
                $base = explode('.', $feature)[0];
                if (in_array($base, $broadBases, true)) {
                    $excludedReasons['broad_feature'] = ($excludedReasons['broad_feature'] ?? 0) + 1;
                    continue;
                }
            }
            if ($badCount < $minBadEntries) {
                $excludedReasons['insufficient_bad_captured'] = ($excludedReasons['insufficient_bad_captured'] ?? 0) + 1;
                continue;
            }

            $fd = $sepByFeature[$feature] ?? null;
            $sep = $fd !== null ? (float)($fd['separation_score'] ?? 0.0) : null;
            if ($sep !== null && $sep < $minSepScore) {
                $excludedReasons['separation_below_threshold'] = ($excludedReasons['separation_below_threshold'] ?? 0) + 1;
                continue;
            }

            $goodBlockPct = $totalGood > 0 ? round($goodCount / $totalGood * 100.0, 2) : 0.0;
            if ($goodBlockPct > $maxGoodBlock) {
                $excludedReasons['too_much_good_overlap'] = ($excludedReasons['too_much_good_overlap'] ?? 0) + 1;
                continue;
            }

            $badCapturePct = $totalBad > 0 ? round($badCount / $totalBad * 100.0, 2) : 0.0;
            if ($badCapturePct < $minBadCapture) {
                $excludedReasons['insufficient_bad_capture_rate'] = ($excludedReasons['insufficient_bad_capture_rate'] ?? 0) + 1;
                continue;
            }

            $op     = $direction === 'higher_bad_risk' ? 'gte' : 'lte';
            $weight = round(max(5.0, min(25.0, $netScore > 0 ? $netScore * 2.5 : 5.0)), 1);

            $selectedRules[] = [
                'rule_id'              => 'sep_' . substr(sha1($feature . '|' . $op . '|' . (string)$threshold), 0, 12),
                'feature'              => $feature,
                'direction'            => $direction,
                'op'                   => $op,
                'threshold'            => $threshold,
                'weight'               => $weight,
                'bad_captured_count'   => $badCount,
                'good_blocked_count'   => $goodCount,
                'bad_capture_rate_pct' => $badCapturePct,
                'good_block_rate_pct'  => $goodBlockPct,
                'net_score'            => round($netScore, 4),
                'confidence'           => $confidence,
                'separation_score'     => $sep,
                'action'               => 'observe_only',
                'scope'                => 'demo_only',
                'status'               => 'candidate',
            ];
        }

        // Sort by net_score descending, cap
        usort($selectedRules, static fn(array $a, array $b): int => ((float)($b['net_score'] ?? 0.0) <=> (float)($a['net_score'] ?? 0.0)));
        $selectedRules = array_slice($selectedRules, 0, $maxRules);

        // Build composite score config
        $riskComponents = [];
        foreach ($selectedRules as $rule) {
            $key = str_replace(['.', ' '], '_', (string)($rule['feature'] ?? ''));
            $riskComponents[$key] = (float)($rule['weight'] ?? 10.0);
        }
        $qualityComponents = [
            'smooth_birth_entry'    => 15.0,
            'distributed_growth'    => 12.0,
            'controlled_dump'       => 10.0,
            'post_dump_stabilized'  => 10.0,
            'bid_support_strong'    => 8.0,
            'oi_confirmed'          => 5.0,
        ];
        $compositeScoreConfig = [
            'version'                      => 'v1',
            'source'                       => 'micro_separability',
            'status'                       => 'diagnostic_only',
            'score_scale'                  => 'percent_of_max_matched_weight',
            'risk_components'              => $riskComponents,
            'quality_components'           => $qualityComponents,
            'risk_threshold_block_candidate' => 60.0,
            'demo_only_threshold_candidate'  => 30.0,
        ];

        $profileId = 'dl_cand_' . gmdate('Ymd_His');
        $profile = [
            'profile_id'                    => $profileId,
            'strategy_id'                   => self::STRATEGY_ID,
            'created_at'                    => date('c'),
            'risk_profile_mode'             => (string)($cfg['risk_profile_mode'] ?? 'working_real'),
            'outcome_classification_profile' => (string)($cfg['outcome_classification_profile'] ?? 'working_real_8_15'),
            'source_epoch_id'               => $epochMeta['real_learning_epoch_id'] ?? null,
            'source_window_minutes'         => (int)($cfg['rolling_learning_window_minutes'] ?? 120),
            'source_outcomes_total'         => $totalOutcomes,
            'source_bad_entries_total'      => $totalBad,
            'source_good_entries_total'     => $totalGood,
            'source_entry_ok_exit_issue_total' => $totalExit,
            'source_neutral_total'          => $totalNeutral,
            'rules'                         => $selectedRules,
            'score_scale'                   => 'percent_of_max_matched_weight',
            'weights'                       => $compositeScoreConfig,
            'composite_score_config'        => $compositeScoreConfig,
            'excluded_rules_total'          => array_sum($excludedReasons),
            'excluded_reason_counts'        => $excludedReasons,
            'status'                        => count($selectedRules) > 0 ? 'candidate' : 'no_safe_candidate_rules',
            'apply_mode'                    => 'observe_only',
        ];

        // Persist candidate_profile.json
        $profilePath = $this->storagePath('profiles/early_impulse_growth_long/candidate_profile.json');
        $profileDir  = dirname($profilePath);
        if (!is_dir($profileDir)) {
            @mkdir($profileDir, 0755, true);
        }
        $profileWritten = false;
        $jsonStr = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($jsonStr)) {
            $profileWritten = @file_put_contents($profilePath, $jsonStr, LOCK_EX) !== false;
        }

        $available = count($selectedRules) > 0;
        $singleFeatureRejectedTotal = array_sum($excludedReasons);
        return [
            'profile'          => $profile,
            'profile_id'       => $profileId,
            'rules_total'      => count($selectedRules),
            'components_total' => count($riskComponents),
            'available'        => $available,
            'missing_reason'   => $available ? null : 'no_safe_candidate_rules_good_overlap',
            'profile_written'  => $profileWritten,
            'single_feature_candidates_total' => $singleFeatureCandidatesTotal,
            'single_feature_rejected_total'   => $singleFeatureRejectedTotal,
            'single_feature_reject_reason_counts' => $excludedReasons,
        ];
    }

    /**
     * Return a minimal empty candidate profile result when there is no data.
     *
     * @param array<string,mixed> $cfg
     * @param array<string,mixed> $epochMeta
     * @return array{profile:array<string,mixed>,profile_id:string,rules_total:int,components_total:int,available:bool,missing_reason:string,profile_written:bool}
     */
    private function makeCandidateProfileEmpty(
        array $cfg,
        array $epochMeta,
        int $totalBad,
        int $totalGood,
        int $totalExit,
        int $totalNeutral,
        string $missingReason
    ): array {
        $profileId = 'dl_cand_empty_' . gmdate('Ymd_His');
        $profile = [
            'profile_id'                    => $profileId,
            'strategy_id'                   => self::STRATEGY_ID,
            'created_at'                    => date('c'),
            'risk_profile_mode'             => (string)($cfg['risk_profile_mode'] ?? 'working_real'),
            'outcome_classification_profile' => (string)($cfg['outcome_classification_profile'] ?? 'working_real_8_15'),
            'source_epoch_id'               => $epochMeta['real_learning_epoch_id'] ?? null,
            'source_outcomes_total'         => 0,
            'source_bad_entries_total'      => $totalBad,
            'source_good_entries_total'     => $totalGood,
            'source_entry_ok_exit_issue_total' => $totalExit,
            'source_neutral_total'          => $totalNeutral,
            'rules'                         => [],
            'score_scale'                   => 'percent_of_max_matched_weight',
            'weights'                       => [],
            'composite_score_config'        => [
                'score_scale' => 'percent_of_max_matched_weight',
                'risk_threshold_block_candidate' => 60.0,
                'demo_only_threshold_candidate' => 30.0,
            ],
            'status'                        => 'insufficient_data',
            'apply_mode'                    => 'observe_only',
            'candidate_profile_available'   => false,
            'candidate_profile_missing_reason' => null,
        ];

        $profilePath = $this->storagePath('profiles/early_impulse_growth_long/candidate_profile.json');
        $profileDir  = dirname($profilePath);
        if (!is_dir($profileDir)) {
            @mkdir($profileDir, 0755, true);
        }
        $profileWritten = false;
        $jsonStr = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($jsonStr)) {
            $profileWritten = @file_put_contents($profilePath, $jsonStr, LOCK_EX) !== false;
        }

        return [
            'profile'          => $profile,
            'profile_id'       => $profileId,
            'rules_total'      => 0,
            'components_total' => 0,
            'available'        => false,
            'missing_reason'   => $missingReason,
            'profile_written'  => $profileWritten,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Composite candidate builder
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build composite candidate profiles from 2–3 feature combinations.
     * Diagnostic / observe-only — never blocks real signals.
     *
     * @param array<string,mixed>        $cfg
     * @param array<array<string,mixed>> $patternMiningOutcomes
     * @param array<string,mixed>        $patternResult
     * @param array<string,mixed>        $epochMeta
     * @return array<string,mixed>
     */
    private function buildCompositeCandidates(
        array $cfg,
        array $patternMiningOutcomes,
        array $patternResult,
        array $epochMeta
    ): array {
        $compositeEnabled    = (bool)($cfg['composite_candidate_enabled'] ?? true);
        $maxComponents       = max(2, min(3, (int)($cfg['composite_candidate_max_components'] ?? 3)));
        $minComponents       = max(2, (int)($cfg['composite_candidate_min_components'] ?? 2));
        $maxToTest           = max(1, (int)($cfg['composite_candidate_max_candidates_to_test'] ?? 100));
        $minBadCapture       = (float)($cfg['composite_candidate_min_bad_capture_rate_pct'] ?? 20.0);
        $maxGoodBlock        = (float)($cfg['composite_candidate_max_good_block_rate_pct'] ?? 20.0);
        $minNetScore         = (float)($cfg['composite_candidate_min_net_score'] ?? 1.0);
        $allowBroadSecondary = (bool)($cfg['composite_candidate_allow_broad_secondary'] ?? true);
        $maxRules            = max(1, (int)($cfg['candidate_max_rules'] ?? 10));
        $blockThreshold      = 60.0;

        $storePath = $this->storagePath('profiles/early_impulse_growth_long/composite_candidates.json');
        $storeDir  = dirname($storePath);
        if (!is_dir($storeDir)) {
            @mkdir($storeDir, 0755, true);
        }

        $writeCompositeFile = function (
            string $source,
            int $testedTotal,
            int $passedTotal,
            int $rejectedTotal,
            array $rejectReasonCounts,
            ?array $bestCandidate,
            array $candidates,
            ?string $noCompositeReason
        ) use ($storePath): void {
            $storeData = [
                'generated_at'              => date('c'),
                'source'                    => $source,
                'tested_total'              => $testedTotal,
                'passed_total'              => $passedTotal,
                'rejected_total'            => $rejectedTotal,
                'reject_reason_counts'      => $rejectReasonCounts,
                'best_combo_id'             => $bestCandidate['combo_id'] ?? null,
                'best_bad_capture_rate_pct' => $bestCandidate['bad_capture_rate_pct'] ?? null,
                'best_good_block_rate_pct'  => $bestCandidate['good_block_rate_pct'] ?? null,
                'best_net_score'            => $bestCandidate['net_score'] ?? null,
                'candidates'                => array_slice($candidates, 0, 20),
                'no_composite_reason'       => $noCompositeReason,
            ];
            $jsonStr = json_encode($storeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($jsonStr)) {
                @file_put_contents($storePath, $jsonStr, LOCK_EX);
            }
        };

        $resultBase = [
            'composite_candidate_enabled'                  => $compositeEnabled,
            'composite_candidate_source'                   => 'missing',
            'composite_candidates_available_total'         => 0,
            'composite_candidates_tested_total'            => 0,
            'composite_candidates_passed_total'            => 0,
            'composite_candidates_rejected_total'          => 0,
            'composite_candidate_reject_reason_counts'     => [],
            'composite_candidate_best_score'               => null,
            'composite_candidate_best_bad_capture_rate_pct'=> null,
            'composite_candidate_best_good_block_rate_pct' => null,
            'composite_candidate_best_net_score'           => null,
            'composite_candidate_selected'                 => false,
            'composite_candidate_selected_id'              => null,
            'composite_candidate_no_selection_reason'      => null,
            'selected_candidate_build'                     => null,
        ];

        if (!$compositeEnabled) {
            $writeCompositeFile('disabled', 0, 0, 0, [], null, [], 'composite_candidate_disabled');
            $resultBase['composite_candidate_source'] = 'disabled';
            $resultBase['composite_candidate_no_selection_reason'] = 'composite_candidate_disabled';
            return $resultBase;
        }

        $primaryPool = [
            'single_candle_dominance_pct',
            'micro_window_5m.single_candle_dominance_pct',
            'micro_window_10m.single_candle_dominance_pct',
            'micro_window_15m.single_candle_dominance_pct',
            'largest_candle_change_pct',
            'largest_candle_share_pct',
            'avg_upper_wick_pct',
            'avg_lower_wick_pct',
            'max_upper_wick_pct',
            'max_lower_wick_pct',
            'direction_flip_count',
            'micro_window_5m.direction_flip_count',
            'micro_window_10m.direction_flip_count',
            'micro_window_15m.direction_flip_count',
            'pullback_max_pct',
            'micro_window_5m.pullback_max_pct',
            'micro_window_10m.pullback_max_pct',
            'micro_window_15m.pullback_max_pct',
            'late_spike_risk_score',
            'impulse_birth_score',
            'bounce_only_risk_score',
            'entry_quality_micro_score',
            'dump_verticality_score',
            'dump_rebound_after_low_pct',
            'bid_ask_notional_ratio',
            'nearest_ask_wall_distance_pct',
            'bid_support_score',
        ];
        $broadBases = ['wave_regime', 'context_phase', 'ask_wall_risk', 'open_interest_confirmed', 'bid_support_quality'];

        $thresholdEnvelope = (array)($patternResult['micro_threshold_candidates'] ?? []);
        $allCandidates = (array)($thresholdEnvelope['candidates'] ?? []);
        $source = 'memory';

        if (empty($allCandidates)) {
            $fallback = (array)$this->readJson($this->storagePath('patterns/micro_threshold_candidates.json'), []);
            $fallbackCandidates = (array)($fallback['candidates'] ?? []);
            if (!empty($fallbackCandidates)) {
                $allCandidates = $fallbackCandidates;
                $source = 'storage_file';
            } else {
                $source = 'missing';
            }
        }

        if ($source === 'missing') {
            $rejectReasonCounts = ['source_missing' => 1];
            $writeCompositeFile('missing', 0, 0, 0, $rejectReasonCounts, null, [], 'source_missing');
            $resultBase['composite_candidate_source'] = 'missing';
            $resultBase['composite_candidate_reject_reason_counts'] = $rejectReasonCounts;
            $resultBase['composite_candidate_no_selection_reason'] = 'source_missing';
            return $resultBase;
        }

        $resultBase['composite_candidate_source'] = $source;
        $resultBase['composite_candidates_available_total'] = count($allCandidates);

        $usablePrimary = [];
        $usableBroad = [];
        foreach ($allCandidates as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $feature = (string)($tc['feature'] ?? '');
            $direction = (string)($tc['direction'] ?? 'unclear');
            $threshold = $tc['candidate_threshold'] ?? null;
            if ($feature === '' || $direction === 'unclear' || $threshold === null) {
                continue;
            }
            $baseName = explode('.', $feature)[0];
            $isPrimary = in_array($feature, $primaryPool, true);
            $isBroad   = in_array($feature, $broadBases, true) || in_array($baseName, $broadBases, true);
            if ($isPrimary) {
                $usablePrimary[] = $tc;
            } elseif ($isBroad && $allowBroadSecondary) {
                $usableBroad[] = $tc;
            }
        }

        usort($usablePrimary, static fn(array $a, array $b): int => ((float)($b['net_score'] ?? 0.0) <=> (float)($a['net_score'] ?? 0.0)));
        usort($usableBroad, static fn(array $a, array $b): int => ((float)($b['net_score'] ?? 0.0) <=> (float)($a['net_score'] ?? 0.0)));
        $usablePrimary = array_slice($usablePrimary, 0, 15);

        $combos = [];
        $primaryCount = count($usablePrimary);
        if ($primaryCount >= 2) {
            for ($i = 0; $i < $primaryCount - 1; $i++) {
                for ($j = $i + 1; $j < $primaryCount; $j++) {
                    $combos[] = [$usablePrimary[$i], $usablePrimary[$j]];
                }
            }
        }
        if ($maxComponents >= 3 && $primaryCount >= 3) {
            $topPrimary = array_slice($usablePrimary, 0, 8);
            $topCount = count($topPrimary);
            for ($i = 0; $i < $topCount - 2; $i++) {
                for ($j = $i + 1; $j < $topCount - 1; $j++) {
                    for ($k = $j + 1; $k < $topCount; $k++) {
                        $combos[] = [$topPrimary[$i], $topPrimary[$j], $topPrimary[$k]];
                    }
                }
            }
        }
        if ($allowBroadSecondary && !empty($usableBroad) && !empty($usablePrimary)) {
            $topBroad = array_slice($usableBroad, 0, 5);
            $topPrimForBroad = array_slice($usablePrimary, 0, 5);
            foreach ($topPrimForBroad as $p) {
                foreach ($topBroad as $b) {
                    $combos[] = [$p, $b];
                }
            }
        }
        $combos = array_slice($combos, 0, $maxToTest);

        if (count($combos) === 0 || $minComponents > $maxComponents) {
            $reason = 'no_usable_threshold_candidates';
            $writeCompositeFile($source, 0, 0, 0, [], null, [], $reason);
            $resultBase['composite_candidate_no_selection_reason'] = $reason;
            return $resultBase;
        }

        $totalBad = 0;
        $totalGood = 0;
        foreach ($patternMiningOutcomes as $o) {
            if (!is_array($o)) {
                continue;
            }
            $cls = (string)($o['outcome_class'] ?? '');
            if ($cls === 'bad_entry') {
                $totalBad++;
            } elseif ($cls === 'good_or_do_not_touch') {
                $totalGood++;
            }
        }
        if ($totalBad === 0 && $totalGood === 0) {
            $writeCompositeFile($source, 0, 0, 0, [], null, [], 'no_outcomes');
            $resultBase['composite_candidate_no_selection_reason'] = 'no_outcomes';
            return $resultBase;
        }

        $featureIndex = $this->buildFeatureIndexForReplay();
        $outcomeFeatures = [];
        foreach ($patternMiningOutcomes as $idx => $o) {
            if (!is_array($o)) {
                continue;
            }
            $extracted = $this->extractFeaturesFromOutcomeForReplay($o, $featureIndex);
            $outcomeFeatures[$idx] = (array)($extracted['features'] ?? []);
        }

        $testedCandidates = [];
        $passed = [];
        $rejected = [];
        $rejectReasonCounts = [];

        foreach ($combos as $combo) {
            $rules = [];
            $featureNames = [];
            $broadOnly = true;
            foreach ($combo as $tc) {
                $feature = (string)($tc['feature'] ?? '');
                $direction = (string)($tc['direction'] ?? '');
                $threshold = $tc['candidate_threshold'] ?? null;
                $netScoreRaw = (float)($tc['net_score'] ?? 1.0);
                if ($feature === '' || $direction === 'unclear' || $threshold === null) {
                    continue;
                }
                $op = $direction === 'higher_bad_risk' ? 'gte' : 'lte';
                $weight = round(max(5.0, min(25.0, $netScoreRaw > 0 ? $netScoreRaw * 2.5 : 5.0)), 1);
                $ruleId = 'comp_' . substr(sha1($feature . '|' . $op . '|' . (string)$threshold), 0, 10);
                $rules[] = [
                    'rule_id' => $ruleId,
                    'feature' => $feature,
                    'direction' => $direction,
                    'op' => $op,
                    'threshold' => $threshold,
                    'weight' => $weight,
                    'net_score' => $netScoreRaw,
                    'confidence' => $tc['confidence'] ?? 'medium',
                    'action' => 'observe_only',
                    'scope' => 'demo_only',
                    'status' => 'candidate',
                ];
                $featureNames[] = $feature;
                $baseName = explode('.', $feature)[0];
                $isBroad = in_array($feature, $broadBases, true) || in_array($baseName, $broadBases, true);
                if (!$isBroad) {
                    $broadOnly = false;
                }
            }

            $comboId = 'comp_' . substr(sha1(implode('+', $featureNames)), 0, 12);
            $candidateRecord = [
                'combo_id' => $comboId,
                'components' => $featureNames,
                'rules_total' => count($rules),
                'bad_blocked_total' => 0,
                'good_blocked_total' => 0,
                'bad_capture_rate_pct' => 0.0,
                'good_block_rate_pct' => 0.0,
                'net_score' => 0.0,
                'replay_quality_score' => 0.0,
                'candidate_vs_default_delta_pct' => 0.0,
            ];

            if (count($rules) < $minComponents || $broadOnly) {
                $candidateRecord['status'] = 'rejected';
                $candidateRecord['reject_reason'] = 'broad_only_not_allowed';
                $rejected[] = $candidateRecord;
                $testedCandidates[] = $candidateRecord;
                $rejectReasonCounts['broad_only_not_allowed'] = ($rejectReasonCounts['broad_only_not_allowed'] ?? 0) + 1;
                continue;
            }

            $missingFeatureValues = false;
            foreach ($rules as $rule) {
                $feature = (string)($rule['feature'] ?? '');
                $found = false;
                foreach ($outcomeFeatures as $features) {
                    if (array_key_exists($feature, (array)$features)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $missingFeatureValues = true;
                    break;
                }
            }
            if ($missingFeatureValues) {
                $candidateRecord['status'] = 'rejected';
                $candidateRecord['reject_reason'] = 'missing_feature_values';
                $rejected[] = $candidateRecord;
                $testedCandidates[] = $candidateRecord;
                $rejectReasonCounts['missing_feature_values'] = ($rejectReasonCounts['missing_feature_values'] ?? 0) + 1;
                continue;
            }

            $badBlocked = 0;
            $goodBlocked = 0;
            foreach ($patternMiningOutcomes as $idx => $o) {
                if (!is_array($o)) {
                    continue;
                }
                $features = (array)($outcomeFeatures[$idx] ?? []);
                $details = $this->computeCandidateRiskScoreDetails($features, $rules);
                if ((float)($details['risk_score_pct'] ?? 0.0) >= $blockThreshold) {
                    $cls = (string)($o['outcome_class'] ?? '');
                    if ($cls === 'bad_entry') {
                        $badBlocked++;
                    } elseif ($cls === 'good_or_do_not_touch') {
                        $goodBlocked++;
                    }
                }
            }

            $badCapturePct = $totalBad > 0 ? round($badBlocked / $totalBad * 100.0, 2) : 0.0;
            $goodBlockPct = $totalGood > 0 ? round($goodBlocked / $totalGood * 100.0, 2) : 0.0;
            $netScore = round($badBlocked * 1.0 - $goodBlocked * 1.5, 4);
            $replayQuality = round($badCapturePct - $goodBlockPct, 4);

            $candidateRecord['bad_blocked_total'] = $badBlocked;
            $candidateRecord['good_blocked_total'] = $goodBlocked;
            $candidateRecord['bad_capture_rate_pct'] = $badCapturePct;
            $candidateRecord['good_block_rate_pct'] = $goodBlockPct;
            $candidateRecord['net_score'] = $netScore;
            $candidateRecord['replay_quality_score'] = $replayQuality;
            $candidateRecord['candidate_vs_default_delta_pct'] = $replayQuality;
            $candidateRecord['rules'] = $rules;

            $rejectReason = null;
            if ($badCapturePct < $minBadCapture) {
                $rejectReason = 'bad_capture_rate_below_min';
            } elseif ($goodBlockPct > $maxGoodBlock) {
                $rejectReason = 'good_block_rate_above_max';
            } elseif ($netScore < $minNetScore) {
                $rejectReason = 'net_score_below_min';
            }

            if ($rejectReason !== null) {
                $candidateRecord['status'] = 'rejected';
                $candidateRecord['reject_reason'] = $rejectReason;
                $rejected[] = $candidateRecord;
                $rejectReasonCounts[$rejectReason] = ($rejectReasonCounts[$rejectReason] ?? 0) + 1;
            } else {
                $candidateRecord['status'] = 'passed';
                $passed[] = $candidateRecord;
            }
            $testedCandidates[] = $candidateRecord;
        }

        usort($passed, static fn(array $a, array $b): int => ((float)($b['net_score'] ?? 0.0) <=> (float)($a['net_score'] ?? 0.0)));
        usort($rejected, static fn(array $a, array $b): int => ((float)($b['net_score'] ?? 0.0) <=> (float)($a['net_score'] ?? 0.0)));
        $bestCandidate = $passed[0] ?? null;

        $noSelectionReason = null;
        if (count($testedCandidates) === 0) {
            $noSelectionReason = 'no_usable_threshold_candidates';
        } elseif ($bestCandidate === null) {
            $noSelectionReason = 'no_composite_rules_passed_good_overlap_guard';
        }

        $storeCandidates = array_merge(array_slice($passed, 0, 20), array_slice($rejected, 0, max(0, 20 - count($passed))));
        $writeCompositeFile(
            $source,
            count($testedCandidates),
            count($passed),
            count($rejected),
            $rejectReasonCounts,
            $bestCandidate,
            $storeCandidates,
            $noSelectionReason
        );

        $selectedBuild = null;
        if ($bestCandidate !== null) {
            $compositeRules = (array)($bestCandidate['rules'] ?? []);
            $compositeRules = array_slice($compositeRules, 0, $maxRules);

            $riskComponents = [];
            foreach ($compositeRules as $rule) {
                $key = str_replace(['.', ' '], '_', (string)($rule['feature'] ?? ''));
                $riskComponents[$key] = (float)($rule['weight'] ?? 10.0);
            }
            $compositeScoreConfig = [
                'version'                          => 'v1',
                'source'                           => 'composite_separability',
                'status'                           => 'diagnostic_only',
                'score_scale'                      => 'percent_of_max_matched_weight',
                'risk_components'                  => $riskComponents,
                'quality_components'               => [],
                'risk_threshold_block_candidate'   => $blockThreshold,
                'demo_only_threshold_candidate'    => 30.0,
            ];

            $profileId = 'dl_comp_' . gmdate('Ymd_His');
            $totalOutcomes = count($patternMiningOutcomes);
            $profile = [
                'profile_id'                     => $profileId,
                'strategy_id'                    => self::STRATEGY_ID,
                'created_at'                     => date('c'),
                'risk_profile_mode'              => (string)($cfg['risk_profile_mode'] ?? 'working_real'),
                'outcome_classification_profile' => (string)($cfg['outcome_classification_profile'] ?? 'working_real_8_15'),
                'source_epoch_id'                => $epochMeta['real_learning_epoch_id'] ?? null,
                'source_outcomes_total'          => $totalOutcomes,
                'source_bad_entries_total'       => $totalBad,
                'source_good_entries_total'      => $totalGood,
                'rules'                          => $compositeRules,
                'score_scale'                    => 'percent_of_max_matched_weight',
                'weights'                        => $compositeScoreConfig,
                'composite_score_config'         => $compositeScoreConfig,
                'excluded_rules_total'           => 0,
                'excluded_reason_counts'         => [],
                'composite_combo_id'             => $bestCandidate['combo_id'] ?? null,
                'composite_components'           => (array)($bestCandidate['components'] ?? []),
                'composite_bad_capture_rate_pct' => $bestCandidate['bad_capture_rate_pct'] ?? null,
                'composite_good_block_rate_pct'  => $bestCandidate['good_block_rate_pct'] ?? null,
                'composite_net_score'            => $bestCandidate['net_score'] ?? null,
                'status'                         => 'candidate',
                'apply_mode'                     => 'observe_only',
                'candidate_type'                 => 'composite',
            ];

            // Write candidate_profile.json with composite rules
            $profilePath = $this->storagePath('profiles/early_impulse_growth_long/candidate_profile.json');
            $profileDir  = dirname($profilePath);
            if (!is_dir($profileDir)) {
                @mkdir($profileDir, 0755, true);
            }
            $profileWritten = false;
            $jsonStr = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($jsonStr)) {
                $profileWritten = @file_put_contents($profilePath, $jsonStr, LOCK_EX) !== false;
            }

            $selectedBuild = [
                'profile'          => $profile,
                'profile_id'       => $profileId,
                'rules_total'      => count($compositeRules),
                'components_total' => count($riskComponents),
                'available'        => true,
                'missing_reason'   => null,
                'profile_written'  => $profileWritten,
                'single_feature_candidates_total'     => 0,
                'single_feature_rejected_total'       => 0,
                'single_feature_reject_reason_counts' => [],
                'composite_selected'                  => true,
            ];
        }

        return [
            'composite_candidate_enabled'               => true,
            'composite_candidate_source'                => $source,
            'composite_candidates_available_total'      => count($allCandidates),
            'composite_candidates_tested_total'         => count($testedCandidates),
            'composite_candidates_passed_total'         => count($passed),
            'composite_candidates_rejected_total'       => count($rejected),
            'composite_candidate_reject_reason_counts'  => $rejectReasonCounts,
            'composite_candidate_best_score'            => $bestCandidate['net_score'] ?? null,
            'composite_candidate_best_bad_capture_rate_pct' => $bestCandidate['bad_capture_rate_pct'] ?? null,
            'composite_candidate_best_good_block_rate_pct'  => $bestCandidate['good_block_rate_pct'] ?? null,
            'composite_candidate_best_net_score'        => $bestCandidate['net_score'] ?? null,
            'composite_candidate_selected'              => $bestCandidate !== null,
            'composite_candidate_selected_id'           => $bestCandidate['combo_id'] ?? null,
            'composite_candidate_no_selection_reason'   => $noSelectionReason,
            'selected_candidate_build'                  => $selectedBuild,
        ];
    }


    private function normalizeCandidateRulesMissingReason(string $reason): string
    {
        $normalized = strtolower(trim($reason));
        if (in_array($normalized, [
            'insufficient_data',
            'no_epoch_outcomes',
            'rolling_window_below_min_outcomes',
            'rolling_window_below_min_bad_entries',
            'rolling_window_below_min_good_entries',
            'rolling_window_below_min_bad_good_counts',
        ], true)) {
            return 'insufficient_data';
        }
        if (in_array($normalized, [
            '',
            'no_candidate_rules_selected',
            'no_rules_passed_selection_criteria',
            'no_safe_candidate_rules',
            'no_safe_candidate_rules_good_overlap',
            'no_composite_rules_passed_good_overlap_guard',
            'no_single_or_composite_rules_passed_guard',
            'broad_only_not_allowed',
            'source_missing',
        ], true)) {
            return 'no_safe_candidate_rules_good_overlap';
        }
        return 'no_safe_candidate_rules_good_overlap';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Candidate replay evaluator
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Replay candidate profile against epoch outcomes.
     * Simulates which trades would be blocked and computes quality of kept trades.
     * Diagnostic / observe-only — never blocks real signals.
     *
     * @param array<string,mixed>         $cfg
     * @param array<string,mixed>         $candidateProfile  From buildCandidateProfileFromSeparability()
     * @param array<array<string,mixed>>  $epochOutcomes     Active-epoch outcomes
     * @return array<string,mixed>
     */
    private function replayCandidateProfile(array $cfg, array $candidateProfile, array $epochOutcomes): array
    {
        $minBadCapturePct  = (float)($cfg['candidate_min_bad_capture_rate_pct'] ?? 20.0);
        $maxGoodBlockPct   = (float)($cfg['candidate_max_good_block_rate_pct']  ?? 20.0);
        $minImprovementPct = (float)($cfg['min_candidate_improvement_pct']      ?? 7.0);
        $noChangeBandPct   = (float)($cfg['no_change_band_pct']                 ?? 5.0);
        $autoApplyDemo     = (bool)($cfg['auto_apply_to_demo_enabled']          ?? false);
        $applyLearningToStrategy = (bool)($cfg['apply_learning_to_strategy_enabled'] ?? false);

        $rules          = (array)($candidateProfile['rules'] ?? []);
        $scoreCfg       = (array)($candidateProfile['composite_score_config'] ?? []);
        $blockThreshold = (float)($scoreCfg['risk_threshold_block_candidate'] ?? 60.0);
        $demoThreshold  = (float)($scoreCfg['demo_only_threshold_candidate']  ?? 30.0);

        $weights = [
            'quality_weight_good_capture'       => (float)($cfg['quality_weight_good_capture']       ?? 1.0),
            'quality_weight_avg_roi'            => (float)($cfg['quality_weight_avg_roi']            ?? 1.0),
            'quality_weight_bad_entry'          => (float)($cfg['quality_weight_bad_entry']          ?? 1.5),
            'quality_weight_drawdown'           => (float)($cfg['quality_weight_drawdown']           ?? 1.0),
            'quality_weight_entry_ok_exit_issue' => (float)($cfg['quality_weight_entry_ok_exit_issue'] ?? 0.5),
        ];

        $total = count($epochOutcomes);
        $totalBad = $totalGood = 0;
        foreach ($epochOutcomes as $o) {
            if (!is_array($o)) {
                continue;
            }
            $cls = (string)($o['outcome_class'] ?? '');
            if ($cls === 'bad_entry') {
                $totalBad++;
            } elseif ($cls === 'good_or_do_not_touch') {
                $totalGood++;
            }
        }

        // Default baseline: all outcomes pass — use computeQualityMetrics on all
        $defaultMetrics = $this->computeQualityMetrics($epochOutcomes, $weights);
        $defaultScore   = ($defaultMetrics['benchmark_available'] ?? false) ? (float)$defaultMetrics['quality_score'] : null;

        // If no rules, candidate = default (nothing blocked)
        if ($total === 0 || count($rules) === 0) {
            $result = $this->buildReplayResultNoRules($total, $totalBad, $totalGood, $defaultMetrics, $defaultScore, count($rules));
            $this->writeReplayToFile($result, $cfg);
            return $result;
        }

        // Load and index feature records for micro-feature joining
        $featureIndex = $this->buildFeatureIndexForReplay();

        // Simulate replay
        $keptOutcomes      = [];
        $badBlocked = $goodBlocked = $exitIssueBlocked = $neutralBlocked = 0;
        $blockedBadEx = $blockedGoodEx = $keptBadEx = $keptGoodEx = [];

        // Feature linking diagnostics
        $featLinked = $featMissing = $featMicroAvail = $featMicroMissing = 0;
        $featLinkMethodCounts = [];
        $featMissingExamples  = [];

        foreach ($epochOutcomes as $o) {
            if (!is_array($o)) {
                continue;
            }
            $extracted = $this->extractFeaturesFromOutcomeForReplay($o, $featureIndex);
            $features  = $extracted['features'];
            $linkMethod = $extracted['link_method'];

            if ($linkMethod !== 'none' && $linkMethod !== 'coarse_fallback') {
                $featLinked++;
            } else {
                $featMissing++;
                if (count($featMissingExamples) < 10) {
                    $featMissingExamples[] = [
                        'symbol'           => (string)($o['symbol'] ?? ''),
                        'signal_id'        => (string)($o['signal_id'] ?? ''),
                        'snapshot_id'      => (string)($o['snapshot_id'] ?? ''),
                        'learning_opened_at' => (string)($o['learning_opened_at'] ?? $o['opened_at'] ?? ''),
                        'reason'           => $linkMethod,
                    ];
                }
            }
            $featLinkMethodCounts[$linkMethod] = ($featLinkMethodCounts[$linkMethod] ?? 0) + 1;
            if ($extracted['micro_available']) {
                $featMicroAvail++;
            } else {
                $featMicroMissing++;
            }

            $riskScoreDetails = $this->computeCandidateRiskScoreDetails($features, $rules);
            $riskScoreRaw = (float)($riskScoreDetails['risk_score_raw'] ?? 0.0);
            $riskScoreMax = (float)($riskScoreDetails['risk_score_max'] ?? 0.0);
            $riskScorePct = (float)($riskScoreDetails['risk_score_pct'] ?? 0.0);
            $matchedRules = (array)($riskScoreDetails['matched_rules'] ?? []);
            $cls       = (string)($o['outcome_class'] ?? '');
            $decision  = $riskScorePct >= $blockThreshold ? 'would_block' : ($riskScorePct >= $demoThreshold ? 'demo_only' : 'pass');

            $ex = [
                'symbol'       => (string)($o['symbol'] ?? ''),
                'outcome_class' => $cls,
                'close_roi'    => $o['close_roi'] ?? null,
                'max_drawdown_roi' => $o['normalized_max_drawdown_roi'] ?? $o['max_drawdown_roi'] ?? null,
                'risk_score'   => $riskScorePct,
                'risk_score_raw' => $riskScoreRaw,
                'risk_score_max' => $riskScoreMax,
                'risk_score_pct' => $riskScorePct,
                'matched_rules'  => $matchedRules,
                'decision'     => $decision,
                'replay_feature_quality' => $extracted['feature_quality'],
            ];

            if ($decision === 'would_block') {
                switch ($cls) {
                    case 'bad_entry':
                        $badBlocked++;
                        if (count($blockedBadEx) < 20) {
                            $blockedBadEx[] = $ex;
                        }
                        break;
                    case 'good_or_do_not_touch':
                        $goodBlocked++;
                        if (count($blockedGoodEx) < 20) {
                            $blockedGoodEx[] = $ex;
                        }
                        break;
                    case 'entry_ok_exit_issue': $exitIssueBlocked++; break;
                    default:                    $neutralBlocked++;   break;
                }
            } else {
                $keptOutcomes[] = $o;
                if ($cls === 'bad_entry' && count($keptBadEx) < 20) {
                    $keptBadEx[] = $ex;
                } elseif ($cls === 'good_or_do_not_touch' && count($keptGoodEx) < 20) {
                    $keptGoodEx[] = $ex;
                }
            }
        }

        $keptTotal      = count($keptOutcomes);
        $candMetrics    = $keptTotal > 0
            ? $this->computeQualityMetrics($keptOutcomes, $weights)
            : ['benchmark_available' => false, 'benchmark_missing_reason' => 'all_outcomes_blocked', 'trades_total' => 0];
        $candScore      = ($candMetrics['benchmark_available'] ?? false) ? (float)$candMetrics['quality_score'] : null;

        $badCapturePct  = $totalBad  > 0 ? round($badBlocked  / $totalBad  * 100.0, 2) : 0.0;
        $goodBlockPct   = $totalGood > 0 ? round($goodBlocked / $totalGood * 100.0, 2) : 0.0;
        $netScore       = round(($badBlocked * 1.0) - ($goodBlocked * 1.5), 4);

        $delta = $badRateDelta = $goodCapDelta = $avgRoiDelta = $ddDelta = null;
        if ($defaultScore !== null && $candScore !== null) {
            $delta        = round($candScore - $defaultScore, 4);
            $badRateDelta = round(
                ((float)($candMetrics['bad_entry_rate_pct'] ?? 0.0)) - ((float)($defaultMetrics['bad_entry_rate_pct'] ?? 0.0)),
                2
            );
            $goodCapDelta = round(
                ((float)($candMetrics['good_capture_rate_pct'] ?? 0.0)) - ((float)($defaultMetrics['good_capture_rate_pct'] ?? 0.0)),
                2
            );
            $avgRoiDelta  = round(
                ((float)($candMetrics['avg_close_roi'] ?? 0.0)) - ((float)($defaultMetrics['avg_close_roi'] ?? 0.0)),
                4
            );
            $ddDelta      = round(
                abs((float)($candMetrics['avg_max_drawdown_roi'] ?? 0.0)) - abs((float)($defaultMetrics['avg_max_drawdown_roi'] ?? 0.0)),
                4
            );
        }

        // Candidate decision
        [$candidateStatus, $promotionDecision, $promotionReason] = $this->evaluateCandidateDecision(
            $keptTotal,
            $candScore,
            $defaultScore,
            $badCapturePct,
            $goodBlockPct,
            $delta,
            $minBadCapturePct,
            $maxGoodBlockPct,
            $noChangeBandPct,
            $minImprovementPct,
            $autoApplyDemo,
            $applyLearningToStrategy
        );
        $replayDiag = $this->deriveReplayDiagnosticOutcome($candidateStatus);

        $replayPassedGuards = $candidateStatus === 'eligible_for_demo_apply';
        $autoApplySafetyBlocked = !($autoApplyDemo && $applyLearningToStrategy && $replayPassedGuards);
        $autoApplySafetyReason = null;
        if ($autoApplySafetyBlocked) {
            if (!$autoApplyDemo) {
                $autoApplySafetyReason = 'auto_apply_to_demo_enabled_is_false';
            } elseif (!$applyLearningToStrategy) {
                $autoApplySafetyReason = 'apply_learning_to_strategy_enabled_is_false';
            } elseif (!$replayPassedGuards) {
                $autoApplySafetyReason = 'candidate_not_eligible_for_demo_apply';
            } else {
                $autoApplySafetyReason = 'auto_apply_safety_conditions_not_met';
            }
        }

        $result = [
            'candidate_replay_enabled'              => true,
            'replay_generated_at'                   => date('c'),
            'score_scale'                           => 'percent_of_max_matched_weight',
            'risk_threshold_block_candidate'        => $blockThreshold,
            'demo_only_threshold_candidate'         => $demoThreshold,
            'rules_used_total'                      => count($rules),
            'replay_trades_total'                   => $total,
            'replay_bad_entries_total'              => $totalBad,
            'replay_good_entries_total'             => $totalGood,
            'replay_bad_blocked_total'              => $badBlocked,
            'replay_good_blocked_total'             => $goodBlocked,
            'replay_entry_ok_exit_issue_blocked_total' => $exitIssueBlocked,
            'replay_neutral_blocked_total'          => $neutralBlocked,
            'replay_bad_capture_rate_pct'           => $badCapturePct,
            'replay_good_block_rate_pct'            => $goodBlockPct,
            'replay_net_score'                      => $netScore,
            'replay_expected_good_kept_total'       => $totalGood - $goodBlocked,
            'replay_expected_bad_avoided_total'     => $badBlocked,
            'default_quality_score'                 => $defaultScore,
            'default_baseline'                      => $defaultMetrics,
            'candidate_quality_score'               => $candScore,
            'candidate_result'                      => $candMetrics,
            'candidate_vs_default_delta_pct'        => $delta,
            'candidate_bad_entry_rate_delta_pct'    => $badRateDelta,
            'candidate_good_capture_delta_pct'      => $goodCapDelta,
            'candidate_avg_roi_delta_pct'           => $avgRoiDelta,
            'candidate_drawdown_delta_pct'          => $ddDelta,
            'candidate_status'                      => $candidateStatus,
            'promotion_decision'                    => $promotionDecision,
            'promotion_reason'                      => $promotionReason,
            'replay_result'                         => $replayDiag['replay_result'],
            'replay_candidate_status'               => $replayDiag['replay_candidate_status'],
            'auto_apply_safety_blocked'             => $autoApplySafetyBlocked,
            'auto_apply_safety_reason'              => $autoApplySafetyReason,
            'blocked_bad_examples'                  => $blockedBadEx,
            'blocked_good_examples'                 => $blockedGoodEx,
            'kept_bad_examples'                     => $keptBadEx,
            'kept_good_examples'                    => $keptGoodEx,
            // Feature linking diagnostics
            'replay_features_loaded_total'          => $featureIndex['records_total'],
            'replay_features_linked_total'          => $featLinked,
            'replay_features_missing_total'         => $featMissing,
            'replay_features_micro_available_total' => $featMicroAvail,
            'replay_features_micro_missing_total'   => $featMicroMissing,
            'replay_features_link_method_counts'    => $featLinkMethodCounts,
            'replay_features_missing_examples'      => $featMissingExamples,
            'replay_skipped_feature_link_reason'    => null,
            'replay_diagnostic_available'           => true,
            'replay_suggests_improvement'           => (bool)$replayDiag['replay_suggests_improvement'],
            'replay_summary' => [
                'bad_blocked'            => $badBlocked,
                'good_blocked'           => $goodBlocked,
                'bad_capture_rate_pct'   => $badCapturePct,
                'good_block_rate_pct'    => $goodBlockPct,
                'replay_net_score'       => $netScore,
                'default_quality_score'  => $defaultScore,
                'candidate_quality_score' => $candScore,
                'delta'                  => $delta,
                'candidate_vs_default_delta_pct' => $delta,
                'candidate_status'       => $candidateStatus,
                'replay_candidate_status' => $replayDiag['replay_candidate_status'],
                'replay_result'          => $replayDiag['replay_result'],
                'promotion_decision'     => $promotionDecision,
            ],
        ];

        $this->writeReplayToFile($result, $cfg);
        return $result;
    }

    /**
     * Build a replay result for the case where no rules are selected.
     *
     * @param array<string,mixed> $defaultMetrics
     * @return array<string,mixed>
     */
    private function buildReplayResultNoRules(int $total, int $totalBad, int $totalGood, array $defaultMetrics, ?float $defaultScore, int $rulesTotal): array
    {
        $status = $rulesTotal === 0 ? 'no_safe_candidate_rules' : 'no_score';
        $reason = $rulesTotal === 0 ? 'no_safe_candidate_rules_good_overlap' : 'no_outcomes';
        $replayDiag = $this->deriveReplayDiagnosticOutcome($status);
        return [
            'candidate_replay_enabled'              => true,
            'replay_generated_at'                   => date('c'),
            'score_scale'                           => 'percent_of_max_matched_weight',
            'risk_threshold_block_candidate'        => 60.0,
            'demo_only_threshold_candidate'         => 30.0,
            'rules_used_total'                      => $rulesTotal,
            'replay_trades_total'                   => $total,
            'replay_bad_entries_total'              => $totalBad,
            'replay_good_entries_total'             => $totalGood,
            'replay_bad_blocked_total'              => 0,
            'replay_good_blocked_total'             => 0,
            'replay_entry_ok_exit_issue_blocked_total' => 0,
            'replay_neutral_blocked_total'          => 0,
            'replay_bad_capture_rate_pct'           => 0.0,
            'replay_good_block_rate_pct'            => 0.0,
            'replay_net_score'                      => 0.0,
            'replay_expected_good_kept_total'       => $totalGood,
            'replay_expected_bad_avoided_total'     => 0,
            'default_quality_score'                 => $defaultScore,
            'default_baseline'                      => $defaultMetrics,
            'candidate_quality_score'               => $defaultScore,
            'candidate_result'                      => $defaultMetrics,
            'candidate_vs_default_delta_pct'        => 0.0,
            'candidate_bad_entry_rate_delta_pct'    => 0.0,
            'candidate_good_capture_delta_pct'      => 0.0,
            'candidate_avg_roi_delta_pct'           => 0.0,
            'candidate_drawdown_delta_pct'          => 0.0,
            'candidate_status'                      => $status,
            'promotion_decision'                    => 'keep_current',
            'promotion_reason'                      => $reason,
            'replay_result'                         => $replayDiag['replay_result'],
            'replay_candidate_status'               => $replayDiag['replay_candidate_status'],
            'auto_apply_safety_blocked'             => true,
            'auto_apply_safety_reason'              => 'candidate_not_eligible_for_demo_apply',
            'blocked_bad_examples'                  => [],
            'blocked_good_examples'                 => [],
            'kept_bad_examples'                     => [],
            'kept_good_examples'                    => [],
            'replay_features_loaded_total'          => 0,
            'replay_features_linked_total'          => 0,
            'replay_features_missing_total'         => 0,
            'replay_features_micro_available_total' => 0,
            'replay_features_micro_missing_total'   => 0,
            'replay_features_link_method_counts'    => [],
            'replay_features_missing_examples'      => [],
            'replay_skipped_feature_link_reason'    => $rulesTotal === 0 ? 'no_candidate_rules' : null,
            'replay_diagnostic_available'           => true,
            'replay_suggests_improvement'           => (bool)$replayDiag['replay_suggests_improvement'],
            'replay_summary' => [
                'bad_blocked'             => 0,
                'good_blocked'            => 0,
                'bad_capture_rate_pct'    => 0.0,
                'good_block_rate_pct'     => 0.0,
                'default_quality_score'   => $defaultScore,
                'candidate_quality_score' => $defaultScore,
                'delta'                   => 0.0,
                'candidate_status'        => $status,
                'replay_candidate_status' => $replayDiag['replay_candidate_status'],
                'replay_result'           => $replayDiag['replay_result'],
                'promotion_decision'      => 'keep_current',
            ],
        ];
    }

    /**
     * @return array{replay_candidate_status:string,replay_result:string,replay_suggests_improvement:bool}
     */
    private function deriveReplayDiagnosticOutcome(string $candidateStatus): array
    {
        if ($candidateStatus === 'eligible_for_demo_apply') {
            return [
                'replay_candidate_status' => 'eligible_on_replay_only',
                'replay_result' => 'improved_on_sample',
                'replay_suggests_improvement' => true,
            ];
        }

        if (in_array($candidateStatus, ['insufficient_data', 'no_score', 'pending'], true)) {
            return [
                'replay_candidate_status' => 'insufficient_replay_data',
                'replay_result' => 'no_improvement',
                'replay_suggests_improvement' => false,
            ];
        }

        if (in_array($candidateStatus, ['no_material_improvement'], true)) {
            return [
                'replay_candidate_status' => 'rejected_on_replay',
                'replay_result' => 'no_improvement',
                'replay_suggests_improvement' => false,
            ];
        }

        return [
            'replay_candidate_status' => 'rejected_on_replay',
            'replay_result' => 'rejected_on_sample',
            'replay_suggests_improvement' => false,
        ];
    }

    /**
     * Determine candidate_status / promotion_decision / promotion_reason from replay metrics.
     *
     * @return array{0:string,1:string,2:string|null}
     */
    private function evaluateCandidateDecision(
        int $keptTotal,
        ?float $candScore,
        ?float $defaultScore,
        float $badCapturePct,
        float $goodBlockPct,
        ?float $delta,
        float $minBadCapturePct,
        float $maxGoodBlockPct,
        float $noChangeBandPct,
        float $minImprovementPct,
        bool $autoApplyDemo,
        bool $applyLearningToStrategy
    ): array {
        if ($keptTotal === 0) {
            return ['rejected', 'reject_candidate', 'all_outcomes_blocked'];
        }
        if ($candScore === null || $defaultScore === null) {
            return ['no_score', 'keep_current', 'quality_score_unavailable'];
        }
        if ($badCapturePct < $minBadCapturePct) {
            return ['insufficient_bad_capture', 'keep_current', 'bad_capture_rate_below_minimum'];
        }
        if ($goodBlockPct > $maxGoodBlockPct) {
            return ['rejected', 'reject_candidate', 'too_much_good_overlap'];
        }
        if ($delta !== null && abs($delta) < $noChangeBandPct) {
            return ['no_material_improvement', 'keep_current', 'improvement_inside_noise_band'];
        }
        if ($delta !== null && $delta < $minImprovementPct) {
            return ['below_improvement_threshold', 'reject_candidate', 'candidate_delta_below_min_improvement'];
        }
        $decision = 'candidate_ready_but_apply_disabled';
        $reason   = 'auto_apply_to_demo_enabled_is_false';
        if ($autoApplyDemo && $applyLearningToStrategy) {
            $decision = 'promote_candidate_demo';
            $reason   = 'candidate_passes_all_checks';
        } elseif ($autoApplyDemo && !$applyLearningToStrategy) {
            $decision = 'candidate_ready_but_apply_disabled';
            $reason   = 'apply_learning_to_strategy_enabled_is_false';
        }
        return ['eligible_for_demo_apply', $decision, $reason];
    }

    /**
     * Extract entry features from a closed outcome for replay scoring.
     * Joins with the feature index (from features.json) so that real Bybit
     * micro-analysis fields (candle windows, impulse scores, etc.) are available.
     *
     * Priority:
     *  1. Matching feature record from features.json (snapshot_id > signal_id > ssk > symbol+side+time)
     *  2. outcome.entry_snapshot.entry_features
     *  3. outcome.entry_snapshot.strategy_signal_context fallback
     *
     * @param array<string,mixed> $outcome
     * @param array<string,mixed> $featureIndex  From buildFeatureIndexForReplay()
     * @return array{features:array<string,mixed>,link_method:string,micro_available:bool,feature_quality:string}
     */
    private function extractFeaturesFromOutcomeForReplay(array $outcome, array $featureIndex = []): array
    {
        $featureRecord = null;
        $linkMethod    = 'none';

        if ($featureIndex !== []) {
            // Tier 1: snapshot_id
            $snapId = trim((string)($outcome['snapshot_id'] ?? ''));
            if ($snapId !== '' && isset($featureIndex['by_snapshot'][$snapId])) {
                $featureRecord = $featureIndex['by_snapshot'][$snapId];
                $linkMethod    = 'snapshot_id';
            }

            // Tier 2: signal_id
            if ($featureRecord === null) {
                $sigId = trim((string)($outcome['signal_id'] ?? ''));
                if ($sigId !== '' && isset($featureIndex['by_signal'][$sigId])) {
                    $featureRecord = $featureIndex['by_signal'][$sigId];
                    $linkMethod    = 'signal_id';
                }
            }

            // Tier 3: strategy_signal_key
            if ($featureRecord === null) {
                $ssk = trim((string)($outcome['strategy_signal_key'] ?? ''));
                if ($ssk !== '' && isset($featureIndex['by_ssk'][$ssk])) {
                    $featureRecord = $featureIndex['by_ssk'][$ssk];
                    $linkMethod    = 'ssk';
                }
            }

            // Tier 4: symbol + side + normalised time
            if ($featureRecord === null) {
                $sym  = strtoupper(trim((string)($outcome['symbol'] ?? '')));
                $side = strtolower(trim((string)($outcome['side'] ?? '')));
                $ts   = $this->normalizeTimestamp(
                    $outcome['learning_opened_at'] ?? $outcome['opened_at'] ?? ''
                );
                $timeKey = $sym . '|' . $side . '|' . $ts;
                if ($sym !== '' && $ts !== '' && isset($featureIndex['by_time'][$timeKey])) {
                    $featureRecord = $featureIndex['by_time'][$timeKey];
                    $linkMethod    = 'symbol_side_time';
                }
            }
        }

        // Build base feature packet from entry_snapshot
        $f   = (array)($outcome['entry_snapshot']['entry_features'] ?? []);
        $ctx = (array)($outcome['entry_snapshot']['strategy_signal_context'] ?? []);

        // Merge feature record on top (feature record wins for micro fields)
        if ($featureRecord !== null) {
            // Fields from the feature record that carry micro data
            $microFields = [
                'single_candle_dominance_pct', 'largest_candle_share_pct',
                'largest_candle_change_pct', 'higher_close_count', 'higher_low_count',
                'lower_close_count', 'lower_low_count', 'direction_flip_count',
                'pullback_max_pct', 'pullback_count', 'avg_body_pct',
                'avg_upper_wick_pct', 'avg_lower_wick_pct',
                'max_upper_wick_pct', 'max_lower_wick_pct',
                'smoothness_score', 'acceleration_score', 'impulse_birth_score',
                'late_spike_risk_score', 'bounce_only_risk_score',
                'entry_quality_micro_score', 'impulse_birth_after_dump_score',
                'dump_shape', 'post_dump_state', 'post_dump_impulse_type',
                'candle_micro_windows',
                'micro_impulse_shape', 'micro_entry_timing', 'micro_growth_distribution',
                'micro_rejection_risk', 'micro_primary_window',
                'micro_single_candle_dominance_pct', 'micro_pullback_max_pct',
                'micro_smoothness_score', 'micro_impulse_birth_score',
                'micro_late_spike_risk_score', 'micro_direction_flip_count',
                'micro_higher_close_count', 'micro_higher_low_count',
                'micro_largest_candle_share_pct',
                'dynamic_risk_score', 'dynamic_quality_score',
                'dump_verticality_score', 'dump_rebound_after_low_pct',
                // Coarse context preserved
                'context_phase', 'context_quality', 'wave_regime',
                'ask_wall_risk', 'bid_support_quality',
                'open_interest_confirmed', 'open_interest_growth_pct',
            ];
            foreach ($microFields as $field) {
                if (array_key_exists($field, $featureRecord) && $featureRecord[$field] !== null) {
                    $f[$field] = $featureRecord[$field];
                }
            }
            // Expose candle_micro_windows sub-keys as top-level aliases
            foreach ((array)($featureRecord['candle_micro_windows'] ?? []) as $window => $stats) {
                if (is_array($stats)) {
                    $f[(string)$window] = $stats;
                }
            }
        } else {
            // No feature record found — use coarse entry_snapshot only
            if ($f === [] && $ctx !== []) {
                require_once $this->moduleDir . '/analyzers/outcome/outcome_classifier.php';
                $f = \Modules\DynamicLearning\Analyzers\Outcome\OutcomeClassifier::extractEntryFeatures($ctx);
                $linkMethod = 'coarse_fallback';
            } elseif ($f !== []) {
                $linkMethod = 'coarse_fallback';
            }
        }

        // Normalise micro-window dot-paths already embedded in entry_features
        foreach ((array)($f['candle_micro_windows'] ?? []) as $window => $stats) {
            if (is_array($stats) && !array_key_exists((string)$window, $f)) {
                $f[(string)$window] = $stats;
            }
        }

        // Field alias: micro_single_candle_dominance_pct → single_candle_dominance_pct
        if (!array_key_exists('single_candle_dominance_pct', $f) && array_key_exists('micro_single_candle_dominance_pct', $f)) {
            $f['single_candle_dominance_pct'] = $f['micro_single_candle_dominance_pct'];
        }

        $microAvailable = isset($f['candle_micro_windows']) && is_array($f['candle_micro_windows']) && $f['candle_micro_windows'] !== []
            || isset($f['single_candle_dominance_pct'])
            || isset($f['late_spike_risk_score'])
            || isset($f['impulse_birth_score']);

        $featureQuality = ($featureRecord !== null)
            ? (((bool)($featureRecord['candle_micro_real'] ?? false) || (bool)($featureRecord['dump_micro_real'] ?? false))
                ? 'real_micro'
                : 'feature_record_proxy')
            : 'coarse_fallback';

        if ($f === []) {
            $linkMethod     = 'none';
            $featureQuality = 'no_features';
        }

        return [
            'features'        => $f,
            'link_method'     => $linkMethod,
            'micro_available' => $microAvailable,
            'feature_quality' => $featureQuality,
        ];
    }

    /**
     * Load storage/features/early_impulse_growth_long/features.json and build lookup indexes.
     *
     * Indexes built:
     *  - by_snapshot  : snapshot_id → record
     *  - by_signal    : signal_id → record (first seen wins)
     *  - by_ssk       : strategy_signal_key → record
     *  - by_time      : symbol|side|normalised_opened_at → record
     *
     * @return array{records_total:int,by_snapshot:array<string,array>,by_signal:array<string,array>,by_ssk:array<string,array>,by_time:array<string,array>}
     */
    private function buildFeatureIndexForReplay(): array
    {
        $index = [
            'records_total' => 0,
            'by_snapshot'   => [],
            'by_signal'     => [],
            'by_ssk'        => [],
            'by_time'       => [],
        ];

        $featurePath = $this->storagePath('features/early_impulse_growth_long/features.json');
        $rows        = (array)$this->readJson($featurePath, []);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index['records_total']++;

            $snapId = trim((string)($row['snapshot_id'] ?? ''));
            if ($snapId !== '') {
                $index['by_snapshot'][$snapId] = $row;
            }

            $sigId = trim((string)($row['signal_id'] ?? ''));
            if ($sigId !== '' && !isset($index['by_signal'][$sigId])) {
                $index['by_signal'][$sigId] = $row;
            }

            $ssk = trim((string)($row['strategy_signal_key'] ?? ''));
            if ($ssk !== '' && !isset($index['by_ssk'][$ssk])) {
                $index['by_ssk'][$ssk] = $row;
            }

            $sym  = strtoupper(trim((string)($row['symbol'] ?? '')));
            $side = strtolower(trim((string)($row['side'] ?? '')));
            $ts   = $this->normalizeTimestamp($row['learning_opened_at'] ?? $row['entry_time'] ?? '');
            if ($sym !== '' && $ts !== '') {
                $timeKey = $sym . '|' . $side . '|' . $ts;
                if (!isset($index['by_time'][$timeKey])) {
                    $index['by_time'][$timeKey] = $row;
                }
            }
        }

        return $index;
    }

    /**
     * Compute normalized composite risk score (percent of max matched weight).
     * Supports dot-notation feature paths (e.g. micro_window_10m.single_candle_dominance_pct).
     *
     * @param array<string,mixed>        $features
     * @param list<array<string,mixed>>  $rules
     */
    private function computeCandidateRiskScore(array $features, array $rules): float
    {
        $details = $this->computeCandidateRiskScoreDetails($features, $rules);
        return (float)($details['risk_score_pct'] ?? 0.0);
    }

    /**
     * @param array<string,mixed>        $features
     * @param list<array<string,mixed>>  $rules
     * @return array{risk_score_raw:float,risk_score_max:float,risk_score_pct:float,matched_rules:list<array<string,mixed>>}
     */
    private function computeCandidateRiskScoreDetails(array $features, array $rules): array
    {
        $rawScore = 0.0;
        $maxScore = 0.0;
        $matchedRules = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $feature   = (string)($rule['feature']    ?? '');
            $threshold = $rule['threshold']            ?? null;
            $op        = (string)($rule['op']          ?? 'gte');
            $weight    = (float)($rule['weight']       ?? 10.0);

            if ($feature === '' || $threshold === null) {
                continue;
            }

            $maxScore += $weight;
            $value = $this->getFeatureDotPath($features, $feature);
            if ($value === null || !is_numeric($value)) {
                continue;
            }

            $matches = $op === 'gte'
                ? (float)$value >= (float)$threshold
                : (float)$value <= (float)$threshold;

            if ($matches) {
                $rawScore += $weight;
                $matchedRules[] = [
                    'rule_id'   => $rule['rule_id'] ?? null,
                    'feature'   => $feature,
                    'op'        => $op,
                    'threshold' => (float)$threshold,
                    'value'     => (float)$value,
                    'weight'    => $weight,
                ];
            }
        }
        $scorePct = $maxScore > 0.0 ? ($rawScore / $maxScore) * 100.0 : 0.0;
        return [
            'risk_score_raw' => round($rawScore, 4),
            'risk_score_max' => round($maxScore, 4),
            'risk_score_pct' => round($scorePct, 4),
            'matched_rules'  => $matchedRules,
        ];
    }

    /**
     * Navigate a nested array using a dot-notation path.
     *
     * @param array<string,mixed> $data
     */
    private function getFeatureDotPath(array $data, string $path): mixed
    {
        if ($path === '') {
            return null;
        }
        if (array_key_exists($path, $data)) {
            return $data[$path];
        }
        $parts = explode('.', $path);
        $cur   = $data;
        foreach ($parts as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return null;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }

    /**
     * Persist candidate replay result to candidate_replay.json.
     *
     * @param array<string,mixed> $result
     * @param array<string,mixed> $cfg
     */
    private function writeReplayToFile(array $result, array $cfg): void
    {
        $maxEx = max(1, (int)($cfg['max_examples_per_last_run_section'] ?? 10));
        $out   = $result;
        $out['blocked_bad_examples']  = array_slice((array)($result['blocked_bad_examples']  ?? []), 0, $maxEx);
        $out['blocked_good_examples'] = array_slice((array)($result['blocked_good_examples'] ?? []), 0, $maxEx);
        $out['kept_bad_examples']     = array_slice((array)($result['kept_bad_examples']     ?? []), 0, $maxEx);
        $out['kept_good_examples']    = array_slice((array)($result['kept_good_examples']    ?? []), 0, $maxEx);
        // Trim large nested objects to avoid huge files
        unset($out['default_baseline'], $out['candidate_result']);

        $replayPath = $this->storagePath('profiles/early_impulse_growth_long/candidate_replay.json');
        $dir        = dirname($replayPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $jsonStr = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($jsonStr)) {
            @file_put_contents($replayPath, $jsonStr, LOCK_EX);
        }
    }

    /**
     * Sync final rolling-guard decision fields into candidate_replay.json
     * while preserving replay-only diagnostics.
     *
     * @param array<string,mixed> $cfg
     * @param array<string,mixed> $result
     * @param array<string,mixed> $candidateReplay
     */
    private function syncCandidateReplayFinalDiagnostics(array $cfg, array $result, array $candidateReplay): void
    {
        if (!((bool)($result['candidate_replay_enabled'] ?? false) || (bool)($candidateReplay['candidate_replay_enabled'] ?? false))) {
            return;
        }

        $replayPath = $this->storagePath('profiles/early_impulse_growth_long/candidate_replay.json');
        $payload = (array)$this->readJson($replayPath, []);
        if ($payload === []) {
            $payload = $candidateReplay;
        }

        $payload['replay_diagnostic_available'] = (bool)($result['replay_diagnostic_available'] ?? true);
        $payload['replay_suggests_improvement'] = (bool)($result['replay_suggests_improvement'] ?? false);
        $payload['replay_result'] = (string)($result['replay_result'] ?? ($payload['replay_result'] ?? 'no_improvement'));
        $payload['replay_candidate_status'] = (string)($result['replay_candidate_status'] ?? ($payload['replay_candidate_status'] ?? 'insufficient_replay_data'));

        $payload['final_candidate_status'] = (string)($result['final_candidate_status'] ?? ($result['candidate_status'] ?? 'pending'));
        $payload['final_promotion_decision'] = (string)($result['final_promotion_decision'] ?? ($result['promotion_decision'] ?? 'keep_current'));
        $payload['final_promotion_reason'] = $result['final_promotion_reason'] ?? ($result['promotion_reason'] ?? null);
        $payload['final_candidate_eligible_for_demo_apply'] = (bool)($result['final_candidate_eligible_for_demo_apply'] ?? ($result['candidate_eligible_for_demo_apply'] ?? false));
        $payload['promotion_blocked_by_min_data'] = (bool)($result['promotion_blocked_by_min_data'] ?? false);
        $payload['promotion_blocked_reason'] = $result['promotion_blocked_reason'] ?? null;
        $payload['promotion_guard_scope'] = (string)($result['promotion_guard_scope'] ?? 'rolling_window');

        // Keep legacy top-level fields aligned to final decision to avoid conflicts.
        $payload['candidate_status'] = $payload['final_candidate_status'];
        $payload['promotion_decision'] = $payload['final_promotion_decision'];
        $payload['promotion_reason'] = $payload['final_promotion_reason'];
        $payload['candidate_eligible_for_demo_apply'] = $payload['final_candidate_eligible_for_demo_apply'];

        $this->writeReplayToFile($payload, $cfg);
    }

    /** @return array{all:list<array<string,mixed>>,index:array<string,array<string,mixed>>,new_total:int} */
    private function collectEntrySnapshots(array $cfg): array
    {
        $stored = $this->readJson($this->storagePath('entry_snapshots.json'), []);
        $index = [];
        foreach ((array)$stored as $row) {
            if (is_array($row) && !empty($row['snapshot_id'])) {
                $index[(string)$row['snapshot_id']] = $row;
            }
        }

        $records = [];
        foreach ([
            $this->repoRoot . '/modules/bot/storage/active_positions.json',
            $this->repoRoot . '/modules/strategy/flow/early_impulse_growth_long/storage/signals.json',
            $this->repoRoot . '/modules/strategy/flow/early_impulse_growth_long/storage/bot_handoff_queue.json',
        ] as $path) {
            foreach ((array)$this->readJson($path, []) as $row) {
                if (is_array($row) && $this->isEigl($row, (string)$cfg['supported_strategy_id'])) {
                    $records[] = $row;
                }
            }
        }

        $newTotal = 0;
        foreach ($records as $row) {
            $snapshot = $this->makeSnapshot($row, (string)$cfg['mode']);
            if ($snapshot === null) { continue; }
            $id = (string)$snapshot['snapshot_id'];
            if (!isset($index[$id])) {
                $newTotal++;
                $this->appendNdjson($this->storagePath('entry_snapshots.ndjson'), $snapshot);
            }
            $index[$id] = $snapshot;
        }

        $all = array_values($index);
        usort($all, static fn(array $a, array $b): int => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        $this->writeJson($this->storagePath('entry_snapshots.json'), $all);
        return ['all' => $all, 'index' => $index, 'new_total' => $newTotal];
    }

    /** @return array<string,mixed>|null */
    private function makeSnapshot(array $row, string $mode): ?array
    {
        $ctx = is_array($row['strategy_signal_context'] ?? null) ? (array)$row['strategy_signal_context'] : [];
        $symbol = strtoupper(trim((string)($row['symbol'] ?? '')));
        $entryPrice = DlHelpers::toFloat($row['entry_price'] ?? null);
        if ($symbol === '' || $entryPrice === null) {
            return null;
        }
        $side = strtolower(trim((string)($row['side'] ?? 'long')));
        $openedAt = DlHelpers::normalizeTimestamp($row['opened_at'] ?? $row['entry_time'] ?? $row['detected_at'] ?? '');
        $detectedAt = DlHelpers::normalizeTimestamp($row['detected_at'] ?? $openedAt);
        $signalId = trim((string)($row['signal_id'] ?? ''));
        $strategySignalKey = trim((string)($row['strategy_signal_key'] ?? ''));
        $key = trim((string)($row['position_id'] ?? ''));
        if ($key === '') {
            $key = $signalId !== '' ? $signalId : $strategySignalKey;
        }
        if ($key === '') {
            $key = implode('|', [$symbol, $side, (string)$entryPrice, $openedAt]);
        }
        $snapshotId = 'snap_' . substr(sha1(self::STRATEGY_ID . '|' . $key), 0, 16);

        // Feature source: if strategy_signal_context is present and populated, features were
        // captured by the strategy at signal generation time — always temporally valid.
        $featureSource = ($ctx !== []) ? 'strategy_signal_context' : 'unknown';
        $featureTimeValid = $featureSource === 'strategy_signal_context';

        $entryFeatures = OutcomeClassifier::extractEntryFeatures($ctx);

        return [
            'snapshot_id' => $snapshotId,
            'created_at' => date('c'),
            'strategy_id' => self::STRATEGY_ID,
            'symbol' => $symbol,
            'side' => $side,
            'signal_id' => $signalId,
            'strategy_signal_key' => $strategySignalKey,
            'mode' => $mode,
            'entry_price' => $entryPrice,
            'opened_at' => $openedAt,
            'detected_at' => $detectedAt,
            'position_id' => trim((string)($row['position_id'] ?? '')),
            'strategy_signal_context' => $ctx,
            'entry_features' => $entryFeatures,
            'feature_source' => $featureSource,
            'feature_time_valid' => $featureTimeValid,
        ];
    }

    /** @return array{seen_total:int,observed_total:int,written_total:int,examples:list<array<string,mixed>>} */
    private function observeActivePositions(array $cfg, array $snapshotIndex): array
    {
        $rows = $this->readJson($this->repoRoot . '/modules/bot/storage/active_positions.json', []);
        $seen = 0;
        $observed = 0;
        $written = 0;
        $examples = [];
        foreach ((array)$rows as $row) {
            if (!is_array($row) || !$this->isEigl($row, (string)$cfg['supported_strategy_id'])) {
                continue;
            }
            $seen++;
            $snapshotId = $this->resolveSnapshotId($row, $snapshotIndex);
            if ($snapshotId === null) {
                continue;
            }
            $summaryPath = $this->storagePath('active_observations/' . $snapshotId . '.json');
            $summary = (array)$this->readJson($summaryPath, []);
            $count = (int)($summary['observations_total'] ?? 0);
            $lastTs = (int)($summary['last_observation_ts'] ?? 0);
            if ($count >= (int)$cfg['max_observations_per_position']) {
                continue;
            }
            if ($lastTs > 0 && (time() - $lastTs) < (int)$cfg['observation_interval_seconds']) {
                continue;
            }
            $ob = $this->buildObservation($row);
            if (!$this->appendNdjson($this->storagePath('active_observations/' . $snapshotId . '.ndjson'), $ob)) {
                continue;
            }
            $observed++;
            $written++;
            $summary['snapshot_id'] = $snapshotId;
            $summary['strategy_id'] = self::STRATEGY_ID;
            $summary['symbol'] = $ob['symbol'];
            $summary['observations_total'] = $count + 1;
            $summary['last_observation_ts'] = time();
            $summary['last_observation'] = $ob;
            $summary['max_profit_roi_so_far'] = max((float)($summary['max_profit_roi_so_far'] ?? $ob['max_profit_roi_so_far']), (float)$ob['max_profit_roi_so_far']);
            $summary['max_drawdown_roi_so_far'] = min((float)($summary['max_drawdown_roi_so_far'] ?? $ob['max_drawdown_roi_so_far']), (float)$ob['max_drawdown_roi_so_far']);
            $this->writeJson($summaryPath, $summary);
            if (count($examples) < 8) {
                $examples[] = $ob;
            }
        }
        return ['seen_total' => $seen, 'observed_total' => $observed, 'written_total' => $written, 'examples' => $examples];
    }

    /** @return array<string,mixed> */
    private function buildObservation(array $row): array
    {
        $entry = max(0.00000001, (float)($row['entry_price'] ?? 0));
        $price = (float)($row['current_price'] ?? $row['mark_price'] ?? $entry);
        $side = strtolower((string)($row['side'] ?? 'long'));
        $delta = (($price - $entry) / $entry) * 100.0;
        if ($side === 'short') {
            $delta *= -1.0;
        }
        $lev = max(1.0, (float)($row['leverage'] ?? 1.0));
        $roi = $delta * $lev;
        $ctx = is_array($row['strategy_signal_context'] ?? null) ? (array)$row['strategy_signal_context'] : [];
        $ob = is_array($ctx['orderbook_context'] ?? null) ? (array)$ctx['orderbook_context'] : [];
        return [
            'ts' => time(),
            'symbol' => strtoupper((string)($row['symbol'] ?? '')),
            'current_price' => $price,
            'entry_price' => $entry,
            'roi_now' => round($roi, 6),
            'max_profit_roi_so_far' => round((float)($row['max_profit_roi'] ?? $roi), 6),
            'max_drawdown_roi_so_far' => round((float)($row['max_drawdown_roi'] ?? $roi), 6),
            'price_change_since_entry_pct' => round((($price - $entry) / $entry) * 100.0, 6),
            'ask_wall_risk' => (string)($ob['ask_wall_risk'] ?? $ctx['ask_wall_risk'] ?? 'unknown'),
            'nearest_ask_wall_distance_pct' => DlHelpers::toFloat($ob['nearest_ask_wall_distance_pct'] ?? $ctx['nearest_ask_wall_distance_pct'] ?? null),
            'nearest_ask_wall_notional' => DlHelpers::toFloat($ob['nearest_ask_wall_notional'] ?? $ctx['nearest_ask_wall_notional'] ?? null),
            'bid_support_quality' => (string)($ob['bid_support_quality'] ?? $ctx['bid_support_quality'] ?? 'unknown'),
            'bid_ask_notional_ratio' => DlHelpers::toFloat($ob['bid_ask_notional_ratio'] ?? $ctx['bid_ask_notional_ratio'] ?? null),
            'open_interest_change_since_entry_pct' => DlHelpers::toFloat($row['open_interest_change_since_entry_pct'] ?? null),
            'observation_source_errors' => [],
        ];
    }

    /** @return array{all:list<array<string,mixed>>,pattern_mining:list<array<string,mixed>>,bad:list<array<string,mixed>>,good:list<array<string,mixed>>,exit_issue:list<array<string,mixed>>,neutral:list<array<string,mixed>>,incomplete:list<array<string,mixed>>,loaded_total:int,raw_loaded_total:int,effective_total:int,unique_total:int,duplicates_skipped_total:int,merged_total:int,near_time_duplicates_merged_total:int,duplicate_examples:list<array<string,mixed>>,near_time_duplicate_examples:list<array<string,mixed>>,strong_link_total:int,weak_link_skipped_total:int,preserved_due_empty_source_total:int,rebuilt_from_ndjson_total:int,rebuild_skipped_due_reset_total:int,time_mismatch_total:int,opened_at_corrected_total:int,timing_low_confidence_total:int,time_mismatch_examples:list<array<string,mixed>>,mfe_normalized_total:int,mae_normalized_total:int,roi_normalization_examples:list<array<string,mixed>>,excluded_from_pattern_mining_total:int,excluded_reasons:array<string,int>,reclassified_total:int,reclassified_examples:list<array<string,mixed>>} */
    private function linkClosedOutcomes(array $cfg, array $snapshotIndex, array $resetState): array
    {
        $stored = $this->readJson($this->storagePath('closed_outcomes.json'), []);
        $existingIndex = [];
        foreach ((array)$stored as $row) {
            if (is_array($row) && !empty($row['outcome_key'])) {
                $existingIndex[(string)$row['outcome_key']] = $row;
            }
        }

        $resetDetected = (bool)($resetState['reset_detected'] ?? false);
        $preserveWhenSourceEmpty = (bool)($cfg['preserve_outcomes_when_source_empty'] ?? true);
        $rebuildFromNdjsonEnabled = (bool)($cfg['rebuild_closed_outcomes_from_ndjson_enabled'] ?? true);

        $rawLoadedTotal = 0;
        $preservedDueEmptySourceTotal = 0;
        $rebuiltFromNdjsonTotal = 0;
        $rebuildSkippedDueResetTotal = 0;
        $sourceRows = [];
        $dedupeStats = [
            'duplicates_skipped_total' => 0,
            'merged_total' => 0,
            'near_time_duplicates_merged_total' => 0,
            'duplicate_examples' => [],
            'near_time_duplicate_examples' => [],
        ];

        foreach ([
            $this->repoRoot . '/modules/bot/storage/trades/closed_trades.json',
            $this->repoRoot . '/modules/bot/storage/closed_positions.json',
            $this->repoRoot . '/modules/bot/storage/closed_trades.json',
        ] as $path) {
            foreach ((array)$this->readJson($path, []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (!$this->isEigl($row, (string)$cfg['supported_strategy_id'])) {
                    continue;
                }

                $rawLoadedTotal++;
                $sourceRows[] = $row;
            }
        }

        $sourceHasRows = count($sourceRows) > 0;
        $effectiveRows = [];
        if ($resetDetected) {
            if ($sourceHasRows) {
                $dedupe = $this->dedupeClosedTradeRows($sourceRows, $cfg);
                $effectiveRows = $dedupe['rows'];
                $dedupeStats = $dedupe;
            } else {
                $effectiveRows = [];
            }
            if ($rebuildFromNdjsonEnabled) {
                $rebuildSkippedDueResetTotal = $this->countNdjsonLines($this->storagePath('closed_outcomes.ndjson'));
            }
        } elseif ($sourceHasRows) {
            $dedupe = $this->dedupeClosedTradeRows($sourceRows, $cfg);
            $effectiveRows = $dedupe['rows'];
            $dedupeStats = $dedupe;
        } elseif ($preserveWhenSourceEmpty && is_array($stored) && count($stored) > 0) {
            $preservedRows = array_values(array_filter((array)$stored, static fn(mixed $r): bool => is_array($r)));
            $dedupe = $this->dedupeClosedTradeRows($preservedRows, $cfg);
            $effectiveRows = $dedupe['rows'];
            $dedupeStats = $dedupe;
            $preservedDueEmptySourceTotal = count($effectiveRows);
        } elseif ($rebuildFromNdjsonEnabled) {
            $rebuilt = $this->rebuildOutcomesFromNdjson();
            if ($rebuilt !== []) {
                $dedupe = $this->dedupeClosedTradeRows($rebuilt, $cfg);
                $effectiveRows = $dedupe['rows'];
                $dedupeStats = $dedupe;
                $rebuiltFromNdjsonTotal = count($rebuilt);
            }
        }

        $reclassifiedTotal = 0;
        $reclassifiedExamples = [];
        $all = [];
        if ($sourceHasRows || $resetDetected) {
            foreach ($effectiveRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $identity = trim((string)($row['_dl_identity'] ?? ''));
                if ($identity === '') {
                    $identity = $this->buildClosedTradeIdentity($row);
                }
                $row['_dl_identity'] = $identity;
                $outcome = $this->makeOutcome($row, $cfg, $snapshotIndex);
                $existingOutcome = $existingIndex[(string)($outcome['outcome_key'] ?? '')] ?? null;
                if (is_array($existingOutcome)) {
                    $oldClass = (string)($existingOutcome['outcome_class'] ?? '');
                    $oldReason = (string)($existingOutcome['classification_reason'] ?? '');
                    $newClass = (string)($outcome['outcome_class'] ?? '');
                    $newReason = (string)($outcome['classification_reason'] ?? '');
                    if ($oldClass !== $newClass || $oldReason !== $newReason) {
                        $reclassifiedTotal++;
                        if (count($reclassifiedExamples) < 20) {
                            $reclassifiedExamples[] = [
                                'symbol' => (string)($outcome['symbol'] ?? ''),
                                'old_class' => $oldClass,
                                'new_class' => $newClass,
                                'close_roi' => $outcome['close_roi'] ?? null,
                                'normalized_max_drawdown_roi' => $outcome['normalized_max_drawdown_roi'] ?? null,
                                'normalized_max_profit_roi' => $outcome['normalized_max_profit_roi'] ?? null,
                                'close_reason' => (string)($outcome['close_reason'] ?? ''),
                                'classification_reason' => $newReason,
                            ];
                        }
                    }
                }
                $all[] = $outcome;
                if (!isset($existingIndex[(string)($outcome['outcome_key'] ?? '')])) {
                    $this->appendNdjson($this->storagePath('closed_outcomes.ndjson'), $outcome);
                }
            }
        } else {
            foreach ($effectiveRows as $row) {
                if (is_array($row)) {
                    $oldClass = (string)($row['outcome_class'] ?? '');
                    $oldReason = (string)($row['classification_reason'] ?? '');
                    $reclassified = $this->reclassifyStoredOutcome($row, $cfg);
                    $newClass = (string)($reclassified['outcome_class'] ?? '');
                    $newReason = (string)($reclassified['classification_reason'] ?? '');
                    if ($oldClass !== $newClass || $oldReason !== $newReason) {
                        $reclassifiedTotal++;
                        if (count($reclassifiedExamples) < 20) {
                            $reclassifiedExamples[] = [
                                'symbol' => (string)($reclassified['symbol'] ?? ''),
                                'old_class' => $oldClass,
                                'new_class' => $newClass,
                                'close_roi' => $reclassified['close_roi'] ?? null,
                                'normalized_max_drawdown_roi' => $reclassified['normalized_max_drawdown_roi'] ?? null,
                                'normalized_max_profit_roi' => $reclassified['normalized_max_profit_roi'] ?? null,
                                'close_reason' => (string)($reclassified['close_reason'] ?? ''),
                                'classification_reason' => $newReason,
                            ];
                        }
                    }
                    $all[] = $reclassified;
                }
            }
        }

        $strongLinkTotal = 0;
        $weakLinkSkippedTotal = 0;
        $timeMismatchTotal = 0;
        $openedAtCorrectedTotal = 0;
        $timingLowConfidenceTotal = 0;
        $timeMismatchExamples = [];
        $mfeNormalizedTotal = 0;
        $maeNormalizedTotal = 0;
        $roiNormalizationExamples = [];
        $excludedFromPatternMiningTotal = 0;
        $excludedReasons = [];
        $patternMining = [];
        foreach ($all as $outcome) {
            if (!is_array($outcome)) {
                continue;
            }
            if ((string)($outcome['link_strength'] ?? '') === 'strong') {
                $strongLinkTotal++;
            }
            if ((string)($outcome['link_strength'] ?? '') === 'weak' && (string)($outcome['pattern_mining_exclude_reason'] ?? '') === 'weak_symbol_only_match') {
                $weakLinkSkippedTotal++;
            }
            if ((bool)($outcome['opened_at_corrected'] ?? false)) {
                $openedAtCorrectedTotal++;
            }
            if ((string)($outcome['timing_confidence'] ?? '') === 'low') {
                $timingLowConfidenceTotal++;
            }
            if (($outcome['opened_at_mismatch_minutes'] ?? null) !== null) {
                $timeMismatchTotal++;
                if (count($timeMismatchExamples) < 20) {
                    $timeMismatchExamples[] = [
                        'symbol' => (string)($outcome['symbol'] ?? ''),
                        'signal_id' => (string)($outcome['signal_id'] ?? ''),
                        'strategy_signal_key' => (string)($outcome['strategy_signal_key'] ?? ''),
                        'raw_closed_opened_at' => (string)($outcome['raw_closed_opened_at'] ?? ''),
                        'learning_opened_at' => (string)($outcome['learning_opened_at'] ?? ''),
                        'closed_at' => (string)($outcome['closed_at'] ?? ''),
                        'opened_at_mismatch_minutes' => $outcome['opened_at_mismatch_minutes'],
                        'opened_at_source' => (string)($outcome['opened_at_source'] ?? ''),
                    ];
                }
            }
            if ((bool)($outcome['mfe_normalized'] ?? false)) {
                $mfeNormalizedTotal++;
            }
            if ((bool)($outcome['mae_normalized'] ?? false)) {
                $maeNormalizedTotal++;
            }
            if (((bool)($outcome['mfe_normalized'] ?? false) || (bool)($outcome['mae_normalized'] ?? false)) && count($roiNormalizationExamples) < 20) {
                $roiNormalizationExamples[] = [
                    'symbol' => (string)($outcome['symbol'] ?? ''),
                    'signal_id' => (string)($outcome['signal_id'] ?? ''),
                    'close_roi' => $outcome['close_roi'] ?? null,
                    'raw_max_profit_roi' => $outcome['raw_max_profit_roi'] ?? null,
                    'normalized_max_profit_roi' => $outcome['normalized_max_profit_roi'] ?? null,
                    'raw_max_drawdown_roi' => $outcome['raw_max_drawdown_roi'] ?? null,
                    'normalized_max_drawdown_roi' => $outcome['normalized_max_drawdown_roi'] ?? null,
                ];
            }
            if ((bool)($outcome['used_for_pattern_mining'] ?? false)) {
                $patternMining[] = $outcome;
            } else {
                $excludedFromPatternMiningTotal++;
                $reason = (string)($outcome['pattern_mining_exclude_reason'] ?? 'unknown');
                $excludedReasons[$reason] = (int)($excludedReasons[$reason] ?? 0) + 1;
            }
        }

        usort($all, static fn(array $a, array $b): int => strcmp((string)($b['closed_at'] ?? ''), (string)($a['closed_at'] ?? '')));
        $this->writeJson($this->storagePath('closed_outcomes.json'), $all);
        $pick = static fn(string $c): array => array_values(array_filter($all, static fn(array $r): bool => (string)($r['outcome_class'] ?? '') === $c));
        return [
            'all' => $all,
            'pattern_mining' => $patternMining,
            'bad' => $pick('bad_entry'),
            'good' => $pick('good_or_do_not_touch'),
            'exit_issue' => $pick('entry_ok_exit_issue'),
            'neutral' => $pick('neutral'),
            'incomplete' => $pick('outcome_incomplete'),
            'loaded_total' => $rawLoadedTotal,
            'raw_loaded_total' => $rawLoadedTotal,
            'effective_total' => count($all),
            'unique_total' => count($all),
            'duplicates_skipped_total' => (int)($dedupeStats['duplicates_skipped_total'] ?? 0),
            'merged_total' => (int)($dedupeStats['merged_total'] ?? 0),
            'near_time_duplicates_merged_total' => (int)($dedupeStats['near_time_duplicates_merged_total'] ?? 0),
            'duplicate_examples' => (array)($dedupeStats['duplicate_examples'] ?? []),
            'near_time_duplicate_examples' => (array)($dedupeStats['near_time_duplicate_examples'] ?? []),
            'strong_link_total' => $strongLinkTotal,
            'weak_link_skipped_total' => $weakLinkSkippedTotal,
            'preserved_due_empty_source_total' => $preservedDueEmptySourceTotal,
            'rebuilt_from_ndjson_total' => $rebuiltFromNdjsonTotal,
            'rebuild_skipped_due_reset_total' => $rebuildSkippedDueResetTotal,
            'time_mismatch_total' => $timeMismatchTotal,
            'opened_at_corrected_total' => $openedAtCorrectedTotal,
            'timing_low_confidence_total' => $timingLowConfidenceTotal,
            'time_mismatch_examples' => $timeMismatchExamples,
            'mfe_normalized_total' => $mfeNormalizedTotal,
            'mae_normalized_total' => $maeNormalizedTotal,
            'roi_normalization_examples' => $roiNormalizationExamples,
            'excluded_from_pattern_mining_total' => $excludedFromPatternMiningTotal,
            'excluded_reasons' => $excludedReasons,
            'reclassified_total' => $reclassifiedTotal,
            'reclassified_examples' => $reclassifiedExamples,
        ];
    }

    /** @return array{reset_detected:bool,manual_reset_respected:bool} */
    private function detectManualReset(array $cfg): array
    {
        $respectReset = (bool)($cfg['respect_manual_storage_reset'] ?? true);
        if (!$respectReset) {
            return ['reset_detected' => false, 'manual_reset_respected' => false];
        }

        $resetDetected = false;
        $markerRel = trim((string)($cfg['storage_reset_marker_file'] ?? 'storage/reset_marker.json'));
        $markerPath = str_starts_with($markerRel, '/')
            ? $markerRel
            : $this->moduleDir . '/' . ltrim($markerRel, '/');
        $outcomeNdjsonPath = $this->storagePath('closed_outcomes.ndjson');
        $marker = (array)$this->readJson($markerPath, []);
        $markerResetAt = strtotime((string)($marker['reset_at'] ?? '')) ?: 0;
        $outcomeNdjsonMtime = is_file($outcomeNdjsonPath) ? (int)@filemtime($outcomeNdjsonPath) : 0;
        if ($markerResetAt > 0 && $markerResetAt > $outcomeNdjsonMtime) {
            $resetDetected = true;
        }

        if (!$resetDetected) {
            $closedOutcomes = (array)$this->readJson($this->storagePath('closed_outcomes.json'), []);
            $entrySnapshots = (array)$this->readJson($this->storagePath('entry_snapshots.json'), []);
            $obsDir = $this->storagePath('active_observations');
            $obsFiles = is_dir($obsDir) ? (glob($obsDir . '/*.json') ?: []) : [];
            if ($closedOutcomes === [] && $entrySnapshots === [] && count($obsFiles) === 0) {
                $resetDetected = true;
            }
        }

        if (!$resetDetected) {
            $manualResetEpoch = $cfg['manual_reset_epoch'] ?? null;
            $manualResetEpochTs = is_numeric($manualResetEpoch) ? (int)round((float)$manualResetEpoch) : 0;
            if ($manualResetEpochTs > 0) {
                $persistedNewestTs = 0;
                foreach ((array)$this->readJson($this->storagePath('closed_outcomes.json'), []) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $ts = strtotime((string)($row['closed_at'] ?? $row['created_at'] ?? '')) ?: 0;
                    if ($ts > $persistedNewestTs) {
                        $persistedNewestTs = $ts;
                    }
                }
                if ($manualResetEpochTs > $persistedNewestTs) {
                    $resetDetected = true;
                }
            }
        }

        return [
            'reset_detected' => $resetDetected,
            'manual_reset_respected' => $resetDetected,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function rebuildOutcomesFromNdjson(): array
    {
        $path = $this->storagePath('closed_outcomes.ndjson');
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || $lines === []) {
            return [];
        }
        $rows = [];
        foreach ($lines as $line) {
            $decoded = json_decode((string)$line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['closed_at'] ?? ''), (string)($a['closed_at'] ?? '')));
        return $rows;
    }

    private function countNdjsonLines(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return is_array($lines) ? count($lines) : 0;
    }

    /** @return array{pruned_lines:int} */
    private function compactObservationNdjson(string $path, int $maxPerPosition): array
    {
        if (!is_file($path)) {
            return ['pruned_lines' => 0];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || count($lines) <= $maxPerPosition) {
            return ['pruned_lines' => 0];
        }

        $rows = [];
        foreach ($lines as $line) {
            $decoded = json_decode((string)$line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        if (count($rows) <= $maxPerPosition) {
            return ['pruned_lines' => 0];
        }

        $first = $rows[0];
        $lastRows = array_slice($rows, -max(1, $maxPerPosition - 2));
        $minRoi = null;
        $maxRoi = null;
        foreach ($rows as $row) {
            $roi = is_numeric($row['roi_now'] ?? null) ? (float)$row['roi_now'] : null;
            if ($roi === null) {
                continue;
            }
            $minRoi = $minRoi === null ? $roi : min($minRoi, $roi);
            $maxRoi = $maxRoi === null ? $roi : max($maxRoi, $roi);
        }
        $summary = [
            'ts' => time(),
            'observation_compact_summary' => true,
            'min_roi_now' => $minRoi,
            'max_roi_now' => $maxRoi,
            'bid_support_quality' => $lastRows[count($lastRows) - 1]['bid_support_quality'] ?? null,
            'ask_wall_risk' => $lastRows[count($lastRows) - 1]['ask_wall_risk'] ?? null,
            'open_interest_change_since_entry_pct' => $lastRows[count($lastRows) - 1]['open_interest_change_since_entry_pct'] ?? null,
        ];
        $kept = array_merge([$first], [$summary], $lastRows);

        $out = '';
        foreach ($kept as $row) {
            $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($line)) {
                $out .= $line . PHP_EOL;
            }
        }
        @file_put_contents($path, $out, LOCK_EX);
        return ['pruned_lines' => max(0, count($rows) - count($kept))];
    }

    private function limitLastRunExamples(array $result, int $maxExamples): array
    {
        foreach ($result as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if (!str_ends_with((string)$key, '_examples')) {
                continue;
            }
            $result[$key] = array_slice(array_values($value), 0, $maxExamples);
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function makeOutcome(array $row, array $cfg, array $snapshotIndex): array
    {
        $link = OutcomeClassifier::resolveLink($row, $snapshotIndex, $cfg);
        $snapshotId = $link['snapshot_id'];
        $snapshot = is_array($link['snapshot']) ? $link['snapshot'] : null;
        $summary = is_string($snapshotId) && $snapshotId !== '' ? (array)$this->readJson($this->storagePath('active_observations/' . $snapshotId . '.json'), []) : [];
        $closeRoi = DlHelpers::toFloat($row['close_roi'] ?? $row['roi'] ?? null);
        $rawMaxDd = DlHelpers::toFloat($row['max_drawdown_roi'] ?? $row['mae_roi'] ?? ($summary['max_drawdown_roi_so_far'] ?? null));
        $rawMaxProfit = DlHelpers::toFloat($row['max_profit_roi'] ?? $row['mfe_roi'] ?? ($summary['max_profit_roi_so_far'] ?? null));
        $normalizedMaxProfit = ($rawMaxProfit !== null && $closeRoi !== null) ? max($rawMaxProfit, $closeRoi) : $rawMaxProfit;
        $normalizedMaxDd = ($rawMaxDd !== null && $closeRoi !== null) ? min($rawMaxDd, $closeRoi) : $rawMaxDd;
        [$class, $reason] = OutcomeClassifier::classify($closeRoi, $normalizedMaxDd, $normalizedMaxProfit, $cfg);
        $composite = trim((string)($row['_dl_identity'] ?? ''));
        if ($composite === '') {
            $composite = $this->buildClosedTradeIdentity($row);
        }
        $timing = OutcomeClassifier::resolveTiming($row, $snapshot, $link, $cfg);
        $featureCheck = OutcomeClassifier::determineEligibility($snapshot, $timing, (string)$link['link_strength']);
        return [
            'outcome_key' => 'out_' . substr(sha1($composite), 0, 20),
            'snapshot_id' => $snapshotId,
            'symbol' => strtoupper((string)($row['symbol'] ?? '')),
            'side' => strtolower((string)($row['side'] ?? 'long')),
            'signal_id' => (string)($row['signal_id'] ?? ''),
            'strategy_signal_key' => (string)($row['strategy_signal_key'] ?? ''),
            'entry_price' => DlHelpers::toFloat($row['entry_price'] ?? null),
            'close_price' => DlHelpers::toFloat($row['close_price'] ?? $row['exit_price'] ?? null),
            'opened_at' => $timing['learning_opened_at'],
            'closed_at' => DlHelpers::extractClosedAt($row),
            'raw_closed_opened_at' => $timing['raw_closed_opened_at'],
            'learning_opened_at' => $timing['learning_opened_at'],
            'opened_at_source' => $timing['opened_at_source'],
            'opened_at_corrected' => $timing['opened_at_corrected'],
            'opened_at_mismatch_minutes' => $timing['opened_at_mismatch_minutes'],
            'timing_confidence' => $timing['timing_confidence'],
            'link_strength' => (string)$link['link_strength'],
            'close_roi' => $closeRoi,
            'raw_max_drawdown_roi' => $rawMaxDd,
            'raw_max_profit_roi' => $rawMaxProfit,
            'max_drawdown_roi' => $rawMaxDd,
            'max_profit_roi' => $rawMaxProfit,
            'normalized_max_drawdown_roi' => $normalizedMaxDd,
            'normalized_max_profit_roi' => $normalizedMaxProfit,
            'mae_normalized' => $rawMaxDd !== $normalizedMaxDd,
            'mfe_normalized' => $rawMaxProfit !== $normalizedMaxProfit,
            'close_reason' => (string)($row['close_reason'] ?? ''),
            'duration_sec' => $timing['duration_sec'],
            'entry_snapshot' => $snapshot,
            'observation_summary' => $summary,
            'duplicate_closed_at_values' => array_values((array)($row['duplicate_closed_at_values'] ?? [])),
            'duplicate_sources_count' => max(1, (int)($row['duplicate_sources_count'] ?? 1)),
            'outcome_class' => $class,
            'classification_reason' => $reason,
            'risk_profile_mode' => (string)($cfg['risk_profile_mode'] ?? ''),
            'outcome_classification_profile' => (string)($cfg['outcome_classification_profile'] ?? ''),
            'used_for_pattern_mining' => $featureCheck['used_for_pattern_mining'],
            'pattern_mining_exclude_reason' => $featureCheck['exclude_reason'],
        ];
    }

    /** @return array<string,mixed> */
    private function reclassifyStoredOutcome(array $row, array $cfg): array
    {
        $closeRoi = DlHelpers::toFloat($row['close_roi'] ?? $row['roi'] ?? null);
        $rawMaxDd = DlHelpers::toFloat($row['raw_max_drawdown_roi'] ?? $row['max_drawdown_roi'] ?? $row['normalized_max_drawdown_roi'] ?? null);
        $rawMaxProfit = DlHelpers::toFloat($row['raw_max_profit_roi'] ?? $row['max_profit_roi'] ?? $row['normalized_max_profit_roi'] ?? null);
        $normalizedMaxDd = DlHelpers::toFloat($row['normalized_max_drawdown_roi'] ?? null);
        $normalizedMaxProfit = DlHelpers::toFloat($row['normalized_max_profit_roi'] ?? null);
        if ($normalizedMaxDd === null) {
            $normalizedMaxDd = ($rawMaxDd !== null && $closeRoi !== null) ? min($rawMaxDd, $closeRoi) : $rawMaxDd;
        }
        if ($normalizedMaxProfit === null) {
            $normalizedMaxProfit = ($rawMaxProfit !== null && $closeRoi !== null) ? max($rawMaxProfit, $closeRoi) : $rawMaxProfit;
        }

        [$class, $reason] = OutcomeClassifier::classify($closeRoi, $normalizedMaxDd, $normalizedMaxProfit, $cfg);
        $row['close_roi'] = $closeRoi;
        if (array_key_exists('raw_max_drawdown_roi', $row)) {
            $row['raw_max_drawdown_roi'] = $rawMaxDd;
        }
        if (array_key_exists('raw_max_profit_roi', $row)) {
            $row['raw_max_profit_roi'] = $rawMaxProfit;
        }
        if (array_key_exists('normalized_max_drawdown_roi', $row)) {
            $row['normalized_max_drawdown_roi'] = $normalizedMaxDd;
        }
        if (array_key_exists('normalized_max_profit_roi', $row)) {
            $row['normalized_max_profit_roi'] = $normalizedMaxProfit;
        }
        $row['duplicate_closed_at_values'] = array_values((array)($row['duplicate_closed_at_values'] ?? []));
        $row['duplicate_sources_count'] = max(1, (int)($row['duplicate_sources_count'] ?? 1));
        $row['outcome_class'] = $class;
        $row['classification_reason'] = $reason;
        $row['risk_profile_mode'] = (string)($cfg['risk_profile_mode'] ?? ($row['risk_profile_mode'] ?? ''));
        $row['outcome_classification_profile'] = (string)($cfg['outcome_classification_profile'] ?? ($row['outcome_classification_profile'] ?? ''));
        return $row;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   duplicates_skipped_total:int,
     *   merged_total:int,
     *   near_time_duplicates_merged_total:int,
     *   duplicate_examples:list<array<string,mixed>>,
     *   near_time_duplicate_examples:list<array<string,mixed>>
     * }
     */
    private function dedupeClosedTradeRows(array $rows, array $cfg): array
    {
        $mergedRows = [];
        $duplicatesSkippedTotal = 0;
        $mergedTotal = 0;
        $nearTimeDuplicatesMergedTotal = 0;
        $duplicateExamples = [];
        $nearTimeDuplicateExamples = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $row = $this->initializeClosedTradeDedupeMetadata($row);
            $match = $this->findMatchingClosedTradeRowIndex($row, $mergedRows, $cfg);
            if ($match === null) {
                $mergedRows[] = $row;
                continue;
            }

            $duplicatesSkippedTotal++;
            $existing = (array)$mergedRows[$match['index']];
            if (count($duplicateExamples) < 20) {
                $duplicateExamples[] = [
                    'symbol' => strtoupper((string)($row['symbol'] ?? $existing['symbol'] ?? '')),
                    'signal_id' => (string)($row['signal_id'] ?? $existing['signal_id'] ?? ''),
                    'learning_opened_at' => $this->extractOpenedAt($row) ?: $this->extractOpenedAt($existing),
                    'close_roi' => DlHelpers::toFloat($row['close_roi'] ?? $row['roi'] ?? $existing['close_roi'] ?? $existing['roi'] ?? null),
                    'closed_at_values' => $this->mergeTimestampValues(
                        (array)($existing['duplicate_closed_at_values'] ?? []),
                        [(string)$this->extractClosedAt($row)]
                    ),
                    'closed_at_delta_seconds' => $match['closed_at_delta_seconds'],
                    'time_tolerance_match' => (bool)$match['near_time_duplicate'],
                ];
            }

            if ((bool)$match['near_time_duplicate']) {
                $nearTimeDuplicatesMergedTotal++;
            }

            $scoreRow = $this->closedTradeRichnessScore($row);
            $scoreExisting = $this->closedTradeRichnessScore($existing);
            [$primary, $secondary] = $scoreRow >= $scoreExisting
                ? [$row, $existing]
                : [$existing, $row];
            $merged = $this->mergeClosedTradeRecords($primary, $secondary);
            $merged = $this->mergeClosedTradeDuplicateMetadata($merged, $existing, $row, (bool)$match['near_time_duplicate'], $match['closed_at_delta_seconds']);

            if ($this->closedTradeRichnessScore($merged) >= $this->closedTradeRichnessScore($primary)) {
                $mergedTotal++;
            }

            $mergedRows[$match['index']] = $merged;
        }

        foreach ($mergedRows as &$row) {
            $row = $this->finalizeClosedTradeDedupeRow($row);
            if (!empty($row['_dl_near_time_example'])) {
                $nearTimeDuplicateExamples[] = $this->formatNearTimeDuplicateExample($row);
            }
        }
        unset($row);

        usort($mergedRows, static fn(array $a, array $b): int => strcmp((string)($b['closed_at'] ?? ''), (string)($a['closed_at'] ?? '')));

        return [
            'rows' => array_values($mergedRows),
            'duplicates_skipped_total' => $duplicatesSkippedTotal,
            'merged_total' => $mergedTotal,
            'near_time_duplicates_merged_total' => $nearTimeDuplicatesMergedTotal,
            'duplicate_examples' => $duplicateExamples,
            'near_time_duplicate_examples' => array_slice($nearTimeDuplicateExamples, 0, 20),
        ];
    }

    /** @param list<array<string,mixed>> $mergedRows */
    private function findMatchingClosedTradeRowIndex(array $candidate, array $mergedRows, array $cfg): ?array
    {
        $best = null;
        foreach ($mergedRows as $index => $existing) {
            $match = $this->closedTradeRowsMatch($candidate, (array)$existing, $cfg);
            if (!(bool)($match['match'] ?? false)) {
                continue;
            }
            if ($best === null) {
                $best = $match + ['index' => $index];
                continue;
            }
            $bestDelta = $best['closed_at_delta_seconds'] ?? PHP_INT_MAX;
            $currentDelta = $match['closed_at_delta_seconds'] ?? PHP_INT_MAX;
            if ($currentDelta < $bestDelta) {
                $best = $match + ['index' => $index];
            }
        }
        return $best;
    }

    /** @return array{match:bool,closed_at_delta_seconds:?int,near_time_duplicate:bool} */
    private function closedTradeRowsMatch(array $candidate, array $existing, array $cfg): array
    {
        $signalIdCandidate = trim((string)($candidate['signal_id'] ?? ''));
        $signalIdExisting = trim((string)($existing['signal_id'] ?? ''));
        $sskCandidate = trim((string)($candidate['strategy_signal_key'] ?? ''));
        $sskExisting = trim((string)($existing['strategy_signal_key'] ?? ''));
        $positionIdCandidate = trim((string)($candidate['position_id'] ?? ''));
        $positionIdExisting = trim((string)($existing['position_id'] ?? ''));
        $closedTradeIdCandidate = trim((string)($candidate['closed_trade_id'] ?? $candidate['id'] ?? ''));
        $closedTradeIdExisting = trim((string)($existing['closed_trade_id'] ?? $existing['id'] ?? ''));

        if ($closedTradeIdCandidate !== '' && $closedTradeIdCandidate === $closedTradeIdExisting) {
            $delta = $this->timestampDiffSeconds($this->extractClosedAt($candidate), $this->extractClosedAt($existing));
            return ['match' => true, 'closed_at_delta_seconds' => $delta, 'near_time_duplicate' => $delta !== null && $delta > 0];
        }
        if ($positionIdCandidate !== '' && $positionIdCandidate === $positionIdExisting) {
            $delta = $this->timestampDiffSeconds($this->extractClosedAt($candidate), $this->extractClosedAt($existing));
            return ['match' => true, 'closed_at_delta_seconds' => $delta, 'near_time_duplicate' => $delta !== null && $delta > 0];
        }

        $symbolCandidate = strtoupper(trim((string)($candidate['symbol'] ?? '')));
        $symbolExisting = strtoupper(trim((string)($existing['symbol'] ?? '')));
        $sideCandidate = strtolower(trim((string)($candidate['side'] ?? '')));
        $sideExisting = strtolower(trim((string)($existing['side'] ?? '')));
        if ($symbolCandidate === '' || $symbolExisting === '' || $symbolCandidate !== $symbolExisting || $sideCandidate === '' || $sideExisting === '' || $sideCandidate !== $sideExisting) {
            return ['match' => false, 'closed_at_delta_seconds' => null, 'near_time_duplicate' => false];
        }

        $closedAtMatch = $this->timestampsWithinTolerance($this->extractClosedAt($candidate), $this->extractClosedAt($existing), $cfg);
        if (!(bool)($closedAtMatch['match'] ?? false)) {
            return ['match' => false, 'closed_at_delta_seconds' => null, 'near_time_duplicate' => false];
        }

        $openedAtCandidate = $this->extractOpenedAt($candidate);
        $openedAtExisting = $this->extractOpenedAt($existing);
        if ($openedAtCandidate !== '' && $openedAtExisting !== '') {
            $openedAtMatch = $this->timestampsWithinTolerance($openedAtCandidate, $openedAtExisting, $cfg);
            if (!(bool)($openedAtMatch['match'] ?? false)) {
                return ['match' => false, 'closed_at_delta_seconds' => null, 'near_time_duplicate' => false];
            }
        }

        $closeRoiCandidate = DlHelpers::toFloat($candidate['close_roi'] ?? $candidate['roi'] ?? null);
        $closeRoiExisting = DlHelpers::toFloat($existing['close_roi'] ?? $existing['roi'] ?? null);
        if ($closeRoiCandidate !== null && $closeRoiExisting !== null && !$this->numericWithinTolerance($closeRoiCandidate, $closeRoiExisting, 0.0001)) {
            return ['match' => false, 'closed_at_delta_seconds' => null, 'near_time_duplicate' => false];
        }

        $sameSignal = $signalIdCandidate !== '' && $signalIdCandidate === $signalIdExisting;
        $sameSsk = $sskCandidate !== '' && $sskCandidate === $sskExisting;
        if ($sameSignal || $sameSsk) {
            return [
                'match' => true,
                'closed_at_delta_seconds' => $closedAtMatch['delta_seconds'],
                'near_time_duplicate' => (bool)($closedAtMatch['near_time_duplicate'] ?? false),
            ];
        }

        return ['match' => false, 'closed_at_delta_seconds' => null, 'near_time_duplicate' => false];
    }

    /** @return array{match:bool,delta_seconds:?int,near_time_duplicate:bool} */
    private function timestampsWithinTolerance(string $a, string $b, array $cfg): array
    {
        if ($a === '' || $b === '') {
            return ['match' => false, 'delta_seconds' => null, 'near_time_duplicate' => false];
        }
        $delta = $this->timestampDiffSeconds($a, $b);
        if ($delta === null) {
            return ['match' => false, 'delta_seconds' => null, 'near_time_duplicate' => false];
        }

        $toleranceEnabled = (bool)($cfg['closed_outcome_dedupe_use_time_tolerance'] ?? true);
        $tolerance = max(0, (int)($cfg['closed_outcome_dedupe_closed_at_tolerance_seconds'] ?? 3));
        $match = $toleranceEnabled ? $delta <= $tolerance : $delta === 0;

        return [
            'match' => $match,
            'delta_seconds' => $delta,
            'near_time_duplicate' => $match && $delta > 0,
        ];
    }

    private function numericWithinTolerance(float $a, float $b, float $tolerance): bool
    {
        return abs($a - $b) <= $tolerance;
    }

    private function initializeClosedTradeDedupeMetadata(array $row): array
    {
        $row['_dl_identity_raw'] = trim((string)($row['_dl_identity_raw'] ?? $this->buildClosedTradeIdentity($row)));
        $row['_dl_merged_identities'] = array_values(array_unique(array_filter(array_merge(
            (array)($row['_dl_merged_identities'] ?? []),
            [$row['_dl_identity_raw']]
        ), static fn(mixed $value): bool => is_string($value) && trim($value) !== '')));
        $row['duplicate_sources_count'] = max(1, (int)($row['duplicate_sources_count'] ?? 1));
        $row['duplicate_closed_at_values'] = $this->mergeTimestampValues(
            (array)($row['duplicate_closed_at_values'] ?? []),
            [$this->extractClosedAt($row)]
        );
        return $row;
    }

    private function mergeClosedTradeDuplicateMetadata(array $merged, array $existing, array $incoming, bool $nearTimeDuplicate, ?int $closedAtDeltaSeconds): array
    {
        $merged['duplicate_sources_count'] = max(1, (int)($existing['duplicate_sources_count'] ?? 1))
            + max(1, (int)($incoming['duplicate_sources_count'] ?? 1));
        $merged['duplicate_closed_at_values'] = $this->mergeTimestampValues(
            array_merge(
                (array)($existing['duplicate_closed_at_values'] ?? []),
                (array)($incoming['duplicate_closed_at_values'] ?? [])
            ),
            [$this->extractClosedAt($existing), $this->extractClosedAt($incoming)]
        );
        $merged['_dl_merged_identities'] = array_values(array_unique(array_filter(array_merge(
            (array)($existing['_dl_merged_identities'] ?? []),
            (array)($incoming['_dl_merged_identities'] ?? []),
            [(string)($existing['_dl_identity_raw'] ?? ''), (string)($incoming['_dl_identity_raw'] ?? '')]
        ), static fn(mixed $value): bool => is_string($value) && trim($value) !== '')));

        if ($nearTimeDuplicate) {
            $baseExample = is_array($existing['_dl_near_time_example'] ?? null) ? (array)$existing['_dl_near_time_example'] : [];
            $baseMergedKeys = array_values(array_unique(array_filter(array_merge(
                (array)($baseExample['merged_identities'] ?? []),
                (array)($existing['_dl_merged_identities'] ?? []),
                (array)($incoming['_dl_merged_identities'] ?? [])
            ), static fn(mixed $value): bool => is_string($value) && trim($value) !== '')));
            $merged['_dl_near_time_example'] = [
                'symbol' => strtoupper((string)($merged['symbol'] ?? $existing['symbol'] ?? $incoming['symbol'] ?? '')),
                'signal_id' => (string)($merged['signal_id'] ?? $existing['signal_id'] ?? $incoming['signal_id'] ?? ''),
                'learning_opened_at' => $this->extractOpenedAt($merged) ?: $this->extractOpenedAt($existing) ?: $this->extractOpenedAt($incoming),
                'close_roi' => DlHelpers::toFloat($merged['close_roi'] ?? $merged['roi'] ?? $existing['close_roi'] ?? $existing['roi'] ?? $incoming['close_roi'] ?? $incoming['roi'] ?? null),
                'closed_at_values' => $merged['duplicate_closed_at_values'],
                'closed_at_delta_seconds' => $closedAtDeltaSeconds,
                'merged_identities' => $baseMergedKeys,
            ];
        }

        return $merged;
    }

    private function finalizeClosedTradeDedupeRow(array $row): array
    {
        $closedAtValues = $this->mergeTimestampValues((array)($row['duplicate_closed_at_values'] ?? []), [$this->extractClosedAt($row)]);
        if ($closedAtValues !== []) {
            $row['duplicate_closed_at_values'] = $closedAtValues;
            $canonicalClosedAt = $closedAtValues[0];
            $row['closed_at'] = $canonicalClosedAt;
            if (array_key_exists('close_time', $row)) {
                $row['close_time'] = $canonicalClosedAt;
            }
            if (array_key_exists('closed_time', $row)) {
                $row['closed_time'] = $canonicalClosedAt;
            }
        }

        $openedAtValues = $this->mergeTimestampValues([], [$this->extractOpenedAt($row)]);
        if ($openedAtValues !== []) {
            $canonicalOpenedAt = $openedAtValues[0];
            if (array_key_exists('learning_opened_at', $row) || array_key_exists('opened_at', $row)) {
                $row['learning_opened_at'] = $canonicalOpenedAt;
                $row['opened_at'] = $canonicalOpenedAt;
            }
        }

        $row['duplicate_sources_count'] = max(1, (int)($row['duplicate_sources_count'] ?? 1));
        $row['_dl_identity'] = $this->buildClosedTradeIdentity($row);
        return $row;
    }

    /** @param array<int,string> $values */
    private function mergeTimestampValues(array $values, array $moreValues): array
    {
        $merged = [];
        foreach (array_merge($values, $moreValues) as $value) {
            $normalized = $this->normalizeTimestamp($value);
            if ($normalized !== '') {
                $merged[$normalized] = true;
            }
        }
        $timestamps = array_keys($merged);
        usort($timestamps, static function (string $a, string $b): int {
            $ta = strtotime($a);
            $tb = strtotime($b);
            if ($ta === false && $tb === false) {
                return strcmp($a, $b);
            }
            if ($ta === false) {
                return 1;
            }
            if ($tb === false) {
                return -1;
            }
            return $ta <=> $tb;
        });
        return $timestamps;
    }

    /** @return array<string,mixed> */
    private function formatNearTimeDuplicateExample(array $row): array
    {
        $example = (array)($row['_dl_near_time_example'] ?? []);
        $keptIdentity = trim((string)($row['_dl_identity'] ?? $this->buildClosedTradeIdentity($row)));
        $mergedIdentities = array_values(array_unique(array_filter(
            (array)($example['merged_identities'] ?? []),
            static fn(mixed $value): bool => is_string($value) && trim($value) !== '' && trim((string)$value) !== $keptIdentity
        )));

        return [
            'symbol' => (string)($example['symbol'] ?? strtoupper((string)($row['symbol'] ?? ''))),
            'signal_id' => (string)($example['signal_id'] ?? (string)($row['signal_id'] ?? '')),
            'learning_opened_at' => (string)($example['learning_opened_at'] ?? $this->extractOpenedAt($row)),
            'close_roi' => $example['close_roi'] ?? DlHelpers::toFloat($row['close_roi'] ?? $row['roi'] ?? null),
            'closed_at_values' => array_values((array)($example['closed_at_values'] ?? $row['duplicate_closed_at_values'] ?? [])),
            'closed_at_delta_seconds' => $example['closed_at_delta_seconds'] ?? null,
            'kept_outcome_key' => $this->buildOutcomeKeyFromIdentity($keptIdentity),
            'merged_outcome_keys' => array_values(array_map(fn(string $identity): string => $this->buildOutcomeKeyFromIdentity($identity), $mergedIdentities)),
        ];
    }

    private function buildOutcomeKeyFromIdentity(string $identity): string
    {
        return 'out_' . substr(sha1($identity), 0, 20);
    }

    private function buildClosedTradeIdentity(array $row): string
    {
        $closedTradeId = trim((string)($row['closed_trade_id'] ?? $row['id'] ?? ''));
        if ($closedTradeId !== '') {
            return 'closed_trade_id|' . $closedTradeId;
        }

        $positionId = trim((string)($row['position_id'] ?? ''));
        if ($positionId !== '') {
            return 'position_id|' . $positionId;
        }

        $signalId = trim((string)($row['signal_id'] ?? ''));
        $ssk = trim((string)($row['strategy_signal_key'] ?? ''));
        $openedAt = $this->extractOpenedAt($row);
        $closedAt = $this->extractClosedAt($row);

        if ($signalId !== '' && $openedAt !== '' && $closedAt !== '') {
            return 'signal_time|' . implode('|', [$signalId, $openedAt, $closedAt]);
        }

        if ($ssk !== '' && $openedAt !== '' && $closedAt !== '') {
            return 'ssk_time|' . implode('|', [$ssk, $openedAt, $closedAt]);
        }

        $symbol = strtoupper(trim((string)($row['symbol'] ?? '')));
        $side = strtolower(trim((string)($row['side'] ?? '')));
        if ($symbol !== '' && $side !== '' && $openedAt !== '' && $closedAt !== '') {
            return 'time|' . implode('|', [$symbol, $side, $openedAt, $closedAt]);
        }

        $entryPrice = DlHelpers::toFloat($row['entry_price'] ?? null);
        if ($symbol !== '' && $side !== '' && $entryPrice !== null && $openedAt !== '' && $closedAt !== '') {
            return 'price_time|' . implode('|', [$symbol, $side, (string)round($entryPrice, 8), $openedAt, $closedAt]);
        }

        return 'fallback|' . substr(sha1(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''), 0, 32);
    }

    private function closedTradeRichnessScore(array $row): int
    {
        $score = 0;
        if (trim((string)($row['closed_trade_id'] ?? $row['id'] ?? '')) !== '') {
            $score += 4;
        }
        if (trim((string)($row['position_id'] ?? '')) !== '') {
            $score += 4;
        }
        if (!empty($row['strategy_signal_context']) && is_array($row['strategy_signal_context'])) {
            $score += 4;
        }
        if (is_numeric($row['max_drawdown_roi'] ?? $row['mae_roi'] ?? null)) {
            $score += 3;
        }
        if (is_numeric($row['max_profit_roi'] ?? $row['mfe_roi'] ?? null)) {
            $score += 3;
        }
        if (trim((string)($row['close_reason'] ?? '')) !== '') {
            $score += 2;
        }
        if (trim((string)($row['signal_id'] ?? '')) !== '') {
            $score += 2;
        }
        if (trim((string)($row['strategy_signal_key'] ?? '')) !== '') {
            $score += 2;
        }
        if (DlHelpers::extractOpenedAt($row) !== '') {
            $score += 1;
        }
        if (DlHelpers::extractClosedAt($row) !== '') {
            $score += 1;
        }
        if (DlHelpers::toFloat($row['close_roi'] ?? $row['roi'] ?? null) !== null) {
            $score += 2;
        }
        return $score;
    }

    /** Merge two records describing the same closed trade, preferring non-empty fields. */
    private function mergeClosedTradeRecords(array $primary, array $secondary): array
    {
        $merged = $primary;

        // Scalar fields: take from secondary if primary value is missing/null/empty
        $fillFields = [
            'close_roi', 'roi',
            'close_price', 'exit_price',
            'max_drawdown_roi', 'mae_roi',
            'max_profit_roi', 'mfe_roi',
            'close_reason',
            'duration_sec',
            'signal_id',
            'strategy_signal_key',
            'position_id',
            'closed_trade_id',
            'opened_at', 'learning_opened_at', 'entry_time', 'created_at',
            'closed_at', 'close_time', 'closed_time',
            'entry_price',
            'raw_max_drawdown_roi',
            'raw_max_profit_roi',
            'normalized_max_drawdown_roi',
            'normalized_max_profit_roi',
        ];
        foreach ($fillFields as $field) {
            if (($merged[$field] ?? null) === null || (is_string($merged[$field]) && trim($merged[$field]) === '')) {
                if (($secondary[$field] ?? null) !== null && !(is_string($secondary[$field]) && trim($secondary[$field]) === '')) {
                    $merged[$field] = $secondary[$field];
                }
            }
        }

        // strategy_signal_context: keep whichever is non-empty and more detailed
        $primaryCtx = is_array($primary['strategy_signal_context'] ?? null) ? $primary['strategy_signal_context'] : null;
        $secondaryCtx = is_array($secondary['strategy_signal_context'] ?? null) ? $secondary['strategy_signal_context'] : null;
        if ($primaryCtx === null && $secondaryCtx !== null) {
            $merged['strategy_signal_context'] = $secondaryCtx;
        } elseif ($primaryCtx !== null && $secondaryCtx !== null && count($secondaryCtx) > count($primaryCtx)) {
            $merged['strategy_signal_context'] = $secondaryCtx;
        }

        foreach (['entry_snapshot', 'observation_summary'] as $arrayField) {
            $primaryArray = is_array($primary[$arrayField] ?? null) ? $primary[$arrayField] : null;
            $secondaryArray = is_array($secondary[$arrayField] ?? null) ? $secondary[$arrayField] : null;
            if ($primaryArray === null && $secondaryArray !== null) {
                $merged[$arrayField] = $secondaryArray;
            } elseif ($primaryArray !== null && $secondaryArray !== null && count($secondaryArray) > count($primaryArray)) {
                $merged[$arrayField] = $secondaryArray;
            }
        }

        return $merged;
    }

    private function extractOpenedAt(array $row): string
    {
        return $this->normalizeTimestamp($row['learning_opened_at'] ?? $row['opened_at'] ?? $row['entry_time'] ?? $row['created_at'] ?? '');
    }

    private function extractClosedAt(array $row): string
    {
        return $this->normalizeTimestamp($row['closed_at'] ?? $row['close_time'] ?? $row['closed_time'] ?? '');
    }

    private function normalizeTimestamp(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
            $num = (float)$value;
            if ($num > 1000000000000) {
                $num /= 1000.0;
            }
            if ($num > 0) {
                return gmdate('c', (int)round($num));
            }
        }
        $s = trim((string)$value);
        if ($s === '') {
            return '';
        }
        $ts = strtotime($s);
        if ($ts !== false) {
            return gmdate('c', $ts);
        }
        return $s;
    }

    private function extractSnapshotOpenedAt(array $snapshot): string
    {
        return $this->normalizeTimestamp($snapshot['opened_at'] ?? '');
    }

    private function extractSnapshotDetectedAt(array $snapshot): string
    {
        return $this->normalizeTimestamp($snapshot['detected_at'] ?? '');
    }

    private function extractSnapshotFeatureAvailableAt(array $snapshot): string
    {
        return $this->extractSnapshotOpenedAt($snapshot)
            ?: $this->extractSnapshotDetectedAt($snapshot)
            ?: $this->normalizeTimestamp($snapshot['created_at'] ?? '');
    }

    private function smallestTimestampDiffSeconds(string $base, array $candidates): ?int
    {
        $best = null;
        foreach ($candidates as $candidate) {
            $diff = $this->timestampDiffSeconds($base, (string)$candidate);
            if ($diff === null) {
                continue;
            }
            if ($best === null || $diff < $best) {
                $best = $diff;
            }
        }
        return $best;
    }

    private function timestampDiffSeconds(string $a, string $b): ?int
    {
        $signed = $this->signedTimestampDiffSeconds($a, $b);
        return $signed === null ? null : abs($signed);
    }

    private function signedTimestampDiffSeconds(string $a, string $b): ?int
    {
        $ta = strtotime($a);
        $tb = strtotime($b);
        if ($ta === false || $tb === false) {
            return null;
        }
        return $tb - $ta;
    }

    /**
     * Filter pattern-mining outcomes by active learning epoch.
     *
     * Real-learning mode keeps historical outcomes in storage but excludes pre-epoch outcomes
     * from active profile/pattern mining. This isolates fast-demo history from real-learning stats.
     *
     * @param array<string,mixed> $cfg
     * @param array{pattern_mining:list<array<string,mixed>>,...} $outcomes
     * @param list<array<string,mixed>> $snapshots
     * @param array<string,array<string,mixed>> $featureBySnapshot
     * @return array{epoch_enabled:bool,epoch_id:?string,epoch_start_at:?string,legacy_outcomes_total:int,epoch_outcomes_total:int,outcomes_excluded_by_epoch_total:int,epoch_start_source:string,epoch_start_missing_reason:?string,pattern_mining:list<array<string,mixed>>,excluded_outcome_keys:list<string>,active_bad_entry_total:int,active_good_or_do_not_touch_total:int,active_entry_ok_exit_issue_total:int,active_neutral_total:int,active_outcome_incomplete_total:int}
     */
    private function applyMicroLearningEpoch(array $cfg, array $outcomes, array $snapshots = [], array $featureBySnapshot = [], ?string $runStartedAt = null): array
    {
        $patternMining = $outcomes['pattern_mining'] ?? [];
        $allOutcomes = $outcomes['all'] ?? [];
        $countClass = static function (array $rows, string $class): int {
            return count(array_filter($rows, static fn(array $r): bool => (string)($r['outcome_class'] ?? '') === $class));
        };
        $noFilter = [
            'epoch_enabled' => false,
            'epoch_id' => null,
            'epoch_start_at' => null,
            'legacy_outcomes_total' => 0,
            'epoch_outcomes_total' => count($patternMining),
            'outcomes_excluded_by_epoch_total' => 0,
            'epoch_start_source' => 'not_enabled',
            'epoch_start_missing_reason' => null,
            'pattern_mining' => $patternMining,
            'excluded_outcome_keys' => [],
            'active_bad_entry_total' => $countClass($allOutcomes, 'bad_entry'),
            'active_good_or_do_not_touch_total' => $countClass($allOutcomes, 'good_or_do_not_touch'),
            'active_entry_ok_exit_issue_total' => $countClass($allOutcomes, 'entry_ok_exit_issue'),
            'active_neutral_total' => $countClass($allOutcomes, 'neutral'),
            'active_outcome_incomplete_total' => $countClass($allOutcomes, 'outcome_incomplete'),
        ];

        if (!(bool)($cfg['real_learning_epoch_enabled'] ?? false)) {
            return $noFilter;
        }
        $excludeLegacyByEpoch = (bool)($cfg['ignore_fast_demo_outcomes_in_real_profile'] ?? true);

        // Determine epoch start timestamp
        $epochStartTs = 0;
        $epochStartSource = 'config';

        $configuredEpoch = $cfg['real_learning_epoch_start_at'] ?? null;
        if ($configuredEpoch !== null && $configuredEpoch !== '') {
            if (is_numeric($configuredEpoch)) {
                $ts = (float)$configuredEpoch;
                if ($ts > 1_000_000_000_000) {
                    $ts /= 1000.0;
                }
                $epochStartTs = (int)round($ts);
            } else {
                $epochStartTs = strtotime((string)$configuredEpoch) ?: 0;
            }
            $epochStartSource = 'config';
        }

        if ($epochStartTs <= 0) {
            $markerRel = trim((string)($cfg['storage_reset_marker_file'] ?? 'storage/reset_marker.json'));
            $markerPath = str_starts_with($markerRel, '/')
                ? $markerRel
                : $this->moduleDir . '/' . ltrim($markerRel, '/');
            $marker = (array)$this->readJson($markerPath, []);
            $markerResetAt = strtotime((string)($marker['reset_at'] ?? '')) ?: 0;
            if ($markerResetAt > 0) {
                $epochStartTs = $markerResetAt;
                $epochStartSource = 'reset_marker';
            }
        }

        if ($epochStartTs <= 0) {
            $firstRealMicroTs = 0;
            foreach ($featureBySnapshot as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $isRealMicro = (bool)($f['candle_micro_real'] ?? false) || (bool)($f['micro_context_available'] ?? false);
                if (!$isRealMicro) {
                    continue;
                }
                $ts = strtotime((string)($f['learning_opened_at'] ?? '')) ?: 0;
                if ($ts <= 0) {
                    continue;
                }
                if ($firstRealMicroTs <= 0 || $ts < $firstRealMicroTs) {
                    $firstRealMicroTs = $ts;
                }
            }
            if ($firstRealMicroTs > 0) {
                $epochStartTs = $firstRealMicroTs;
                $epochStartSource = 'first_real_micro_feature';
            }
        }

        if ($epochStartTs <= 0) {
            $firstSnapshotTs = 0;
            foreach ($snapshots as $snap) {
                if (!is_array($snap)) {
                    continue;
                }
                $openedTs = strtotime((string)($snap['opened_at'] ?? '')) ?: 0;
                $detectedTs = strtotime((string)($snap['detected_at'] ?? '')) ?: 0;
                $candidateTs = 0;
                if ($openedTs > 0 && $detectedTs > 0) {
                    $candidateTs = min($openedTs, $detectedTs);
                } elseif ($openedTs > 0) {
                    $candidateTs = $openedTs;
                } elseif ($detectedTs > 0) {
                    $candidateTs = $detectedTs;
                }
                if ($candidateTs <= 0) {
                    continue;
                }
                if ($firstSnapshotTs <= 0 || $candidateTs < $firstSnapshotTs) {
                    $firstSnapshotTs = $candidateTs;
                }
            }
            if ($firstSnapshotTs > 0) {
                $epochStartTs = $firstSnapshotTs;
                $epochStartSource = 'first_entry_snapshot';
            }
        }

        if ($epochStartTs <= 0) {
            $runStartedTs = strtotime((string)$runStartedAt) ?: 0;
            if ($runStartedTs > 0) {
                $epochStartTs = $runStartedTs;
                $epochStartSource = 'current_run_started_at';
            }
        }

        if ($epochStartTs <= 0) {
            return [
                'epoch_enabled' => true,
                'epoch_id' => null,
                'epoch_start_at' => null,
                'legacy_outcomes_total' => 0,
                'epoch_outcomes_total' => count($patternMining),
                'outcomes_excluded_by_epoch_total' => 0,
                'epoch_start_source' => 'epoch_start_missing',
                'epoch_start_missing_reason' => 'no_epoch_start_derivable',
                'pattern_mining' => $patternMining,
                'excluded_outcome_keys' => [],
                'active_bad_entry_total' => $countClass($allOutcomes, 'bad_entry'),
                'active_good_or_do_not_touch_total' => $countClass($allOutcomes, 'good_or_do_not_touch'),
                'active_entry_ok_exit_issue_total' => $countClass($allOutcomes, 'entry_ok_exit_issue'),
                'active_neutral_total' => $countClass($allOutcomes, 'neutral'),
                'active_outcome_incomplete_total' => $countClass($allOutcomes, 'outcome_incomplete'),
            ];
        }

        $epochStatePath = $this->storagePath('real_learning_epoch.json');
        $epochState = (array)$this->readJson($epochStatePath, []);
        $activeProfile = (string)($cfg['outcome_classification_profile'] ?? '');
        $stateProfile = (string)($epochState['outcome_classification_profile'] ?? '');
        $configuredEpochId = trim((string)($cfg['real_learning_epoch_id'] ?? 'auto'));
        $stateEpochId = trim((string)($epochState['epoch_id'] ?? ''));
        $stateEpochTs = strtotime((string)($epochState['epoch_start_at'] ?? '')) ?: 0;

        $epochStartSourceResolved = $epochStartSource;
        if ($stateEpochId !== '' && $stateEpochTs > 0 && $stateProfile === $activeProfile) {
            $epochStartTs = $stateEpochTs;
            $epochStartSourceResolved = 'stored_epoch';
        } else {
            if ($configuredEpochId === '' || strtolower($configuredEpochId) === 'auto') {
                $configuredEpochId = 'eigl_real_' . gmdate('Ymd_His', $epochStartTs);
            }
            $epochState = [
                'epoch_id' => $configuredEpochId,
                'epoch_start_at' => gmdate('c', $epochStartTs),
                'risk_profile_mode' => (string)($cfg['risk_profile_mode'] ?? ''),
                'outcome_classification_profile' => $activeProfile,
                'created_at' => date('c'),
            ];
            $this->writeJson($epochStatePath, $epochState);
            $stateEpochId = $configuredEpochId;
            $epochStartSourceResolved = 'new_profile_epoch';
        }

        $epochId = $stateEpochId;
        $epochStartIso = gmdate('c', $epochStartTs);

        // Partition outcomes into epoch and legacy
        $epochOutcomes = [];
        $legacyCount = 0;
        $excludedOutcomeKeys = [];
        $activeAllOutcomes = [];
        foreach ($patternMining as $o) {
            $openedAtStr = (string)($o['opened_at'] ?? $o['learning_opened_at'] ?? '');
            $openedAtTs = $openedAtStr !== '' ? (strtotime($openedAtStr) ?: 0) : 0;
            if (!$excludeLegacyByEpoch || $openedAtTs === 0 || $openedAtTs >= $epochStartTs) {
                $epochOutcomes[] = $o;
            } else {
                $legacyCount++;
                $outcomeKey = (string)($o['outcome_key'] ?? '');
                if ($outcomeKey !== '') {
                    $excludedOutcomeKeys[] = $outcomeKey;
                }
            }
        }
        foreach ($allOutcomes as $o) {
            if (!is_array($o)) {
                continue;
            }
            $openedAtStr = (string)($o['opened_at'] ?? $o['learning_opened_at'] ?? '');
            $openedAtTs = $openedAtStr !== '' ? (strtotime($openedAtStr) ?: 0) : 0;
            if (!$excludeLegacyByEpoch || $openedAtTs === 0 || $openedAtTs >= $epochStartTs) {
                $activeAllOutcomes[] = $o;
            }
        }

        return [
            'epoch_enabled' => true,
            'epoch_id' => $epochId,
            'epoch_start_at' => $epochStartIso,
            'legacy_outcomes_total' => $legacyCount,
            'epoch_outcomes_total' => count($epochOutcomes),
            'outcomes_excluded_by_epoch_total' => $legacyCount,
            'epoch_start_source' => $epochStartSourceResolved,
            'epoch_start_missing_reason' => null,
            'pattern_mining' => $epochOutcomes,
            'excluded_outcome_keys' => $excludedOutcomeKeys,
            'active_bad_entry_total' => $countClass($activeAllOutcomes, 'bad_entry'),
            'active_good_or_do_not_touch_total' => $countClass($activeAllOutcomes, 'good_or_do_not_touch'),
            'active_entry_ok_exit_issue_total' => $countClass($activeAllOutcomes, 'entry_ok_exit_issue'),
            'active_neutral_total' => $countClass($activeAllOutcomes, 'neutral'),
            'active_outcome_incomplete_total' => $countClass($activeAllOutcomes, 'outcome_incomplete'),
        ];
    }

    private function isEigl(array $row, string $strategyId): bool
    {
        $sid = strtolower(trim((string)($row['strategy_id'] ?? $row['owner_strategy'] ?? '')));
        if ($sid === strtolower($strategyId)) {
            return true;
        }
        $ctx = is_array($row['strategy_signal_context'] ?? null) ? (array)$row['strategy_signal_context'] : [];
        if (strtolower(trim((string)($ctx['strategy_id'] ?? ''))) === strtolower($strategyId)) {
            return true;
        }
        $signalId = strtolower(trim((string)($row['signal_id'] ?? '')));
        $ssk = strtolower(trim((string)($row['strategy_signal_key'] ?? '')));
        return str_contains($signalId, 'early_impulse_growth_long') || str_contains($ssk, 'early_impulse_growth_long') || str_starts_with($signalId, 'eigl_');
    }

    /** @return array<string,mixed> */
    private function runFeaturePipeline(array $cfg, array $snapshots): array
    {
        $result = [
            'records_total' => 0,
            'time_valid_total' => 0,
            'time_invalid_total' => 0,
            'source_counts' => [],
            'time_invalid_examples' => [],
            'candle_micro_available_total' => 0,
            'dump_micro_available_total' => 0,
            'candle_micro_real_available_total' => 0,
            'candle_micro_proxy_available_total' => 0,
            'candle_micro_missing_total' => 0,
            'dump_micro_real_available_total' => 0,
            'dump_micro_proxy_available_total' => 0,
            'dump_micro_missing_total' => 0,
            'clean_learning_start_enabled' => (bool)($cfg['clean_learning_start_enabled'] ?? false),
            'legacy_proxy_features_total' => 0,
            'new_micro_features_total' => 0,
            'bybit_kline_requests_total' => 0,
            'bybit_kline_success_total' => 0,
            'bybit_kline_error_total' => 0,
            'bybit_kline_cache_hit_total' => 0,
            'parser2_fallback_used_total' => 0,
            'micro_data_missing_total' => 0,
            'micro_impulse_shape_counts' => [],
            'micro_entry_timing_counts' => [],
            'micro_growth_distribution_counts' => [],
            'micro_rejection_risk_counts' => [],
            'dump_shape_counts' => [],
            'post_dump_state_counts' => [],
            'post_dump_impulse_type_counts' => [],
            'micro_pattern_examples' => [],
            'micro_bad_good_overlap_examples' => [],
            'micro_primary_window' => (string)($cfg['micro_primary_window'] ?? 'micro_window_10m'),
            'micro_primary_summary_available_total' => 0,
            'micro_primary_summary_missing_total' => 0,
            'feature_by_snapshot' => [],
            'weighted_score_calculated_total' => 0,
        ];

        $featureJsonPath = $this->storagePath('features/early_impulse_growth_long/features.json');
        $existingFeatures = (array)$this->readJson($featureJsonPath, []);
        foreach ($existingFeatures as $row) {
            if (!is_array($row)) {
                continue;
            }
            $isLegacyProxy = (bool)($row['legacy_proxy_feature'] ?? false)
                || (
                    ((bool)($row['candle_micro_real'] ?? false) === false)
                    && ((bool)($row['dump_micro_real'] ?? false) === false)
                    && (((bool)($row['micro_proxy_available'] ?? false) === true) || ((bool)($row['dump_micro_proxy_available'] ?? false) === true))
                );
            if ($isLegacyProxy) {
                $result['legacy_proxy_features_total']++;
            }
        }

        $marketData = new DlMarketData($this->repoRoot, $cfg);
        $records = [];
        $outcomeRows = (array)$this->readJson($this->storagePath('closed_outcomes.json'), []);
        $outcomeBySnapshot = [];
        foreach ($outcomeRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sid = trim((string)($row['snapshot_id'] ?? ''));
            if ($sid !== '') {
                $outcomeBySnapshot[$sid] = $row;
            }
        }

        $countMap = static function (array &$map, string $value): void {
            $v = trim($value);
            if ($v === '') {
                $v = 'unknown';
            }
            $map[$v] = (int)($map[$v] ?? 0) + 1;
        };

        foreach ($snapshots as $snap) {
            if (!is_array($snap)) {
                continue;
            }
            $ctx = is_array($snap['strategy_signal_context'] ?? null) ? (array)$snap['strategy_signal_context'] : [];
            $featureSource = (string)($snap['feature_source'] ?? 'unknown');
            $featureTimeValid = (bool)($snap['feature_time_valid'] ?? ($featureSource === 'strategy_signal_context'));

            $featureTimeWarning = null;
            if (!$featureTimeValid) {
                $featureTimeWarning = 'feature_source_unknown_timing_unproven';
            }

            $entryTimeIso = $this->normalizeTimestamp($snap['opened_at'] ?? $snap['detected_at'] ?? '');
            $entryTs = strtotime($entryTimeIso);
            if ($entryTs === false) {
                $entryTs = time();
            }
            $symbol = strtoupper(trim((string)($snap['symbol'] ?? '')));

            $candleMicro = (bool)($cfg['candle_micro_analyzer_enabled'] ?? true)
                ? CandleMicroAnalyzer::analyze($ctx, $symbol, (int)$entryTs, $marketData, $cfg)
                : ['micro_context_available' => false];
            $dumpMicro = (bool)($cfg['dump_micro_analyzer_enabled'] ?? true)
                ? DumpMicroAnalyzer::analyze($ctx, $symbol, (int)$entryTs, $marketData, $cfg)
                : ['dump_micro_available' => false];
            $impulse = (bool)($cfg['impulse_birth_analyzer_enabled'] ?? true)
                ? ImpulseBirthAnalyzer::analyze($ctx, $candleMicro, $dumpMicro)
                : ['impulse_birth_available' => false];
            $trend = (bool)($cfg['trend_context_analyzer_enabled'] ?? true)
                ? TrendContextAnalyzer::analyze($ctx)
                : ['trend_context_available' => false];
            $ob = (bool)($cfg['orderbook_snapshot_analyzer_enabled'] ?? true)
                ? OrderbookSnapshotAnalyzer::analyze($ctx)
                : ['orderbook_context_available' => false];

            $weightedScore = null;
            $riskComponents = [];
            $qualityComponents = [];
            if ((bool)($cfg['weighted_scoring_enabled'] ?? true)) {
                $featuresForScore = array_merge(
                    is_array($snap['entry_features'] ?? null) ? (array)$snap['entry_features'] : OutcomeClassifier::extractEntryFeatures($ctx),
                    $candleMicro,
                    $dumpMicro,
                    $impulse,
                    $ob
                );
                [$weightedScore, $riskComponents, $qualityComponents] = $this->computeWeightedScore($featuresForScore);
            }

            $coin = is_array($ctx['coin_context'] ?? null) ? (array)$ctx['coin_context'] : [];
            $wave = is_array($ctx['wave_context'] ?? null) ? (array)$ctx['wave_context'] : [];
            $g = static fn(string $k, mixed $d = null) => $ctx[$k] ?? $coin[$k] ?? $wave[$k] ?? $d;

            $featureRecord = [
                'feature_id' => 'feat_' . substr(sha1(($snap['snapshot_id'] ?? '') . $featureSource . ($snap['created_at'] ?? '')), 0, 16),
                'snapshot_id' => $snap['snapshot_id'] ?? null,
                'strategy_id' => self::STRATEGY_ID,
                'symbol' => $snap['symbol'] ?? null,
                'side' => $snap['side'] ?? null,
                'signal_id' => $snap['signal_id'] ?? null,
                'entry_price' => $snap['entry_price'] ?? null,
                'learning_opened_at' => $snap['opened_at'] ?? null,
                'entry_time' => $entryTimeIso,
                'feature_source' => $featureSource,
                'feature_time_valid' => $featureTimeValid,
                'feature_time_warning' => $featureTimeWarning,
                'dump_pct' => $g('dump_pct'),
                'stabilization_duration_minutes' => $g('stabilization_duration_minutes'),
                'stabilization_range_pct' => $g('stabilization_range_pct'),
                'smooth_growth_pct' => $g('smooth_growth_pct'),
                'smooth_growth_duration_minutes' => $g('smooth_growth_duration_minutes'),
                'smooth_growth_higher_close_count' => $g('smooth_growth_higher_close_count'),
                'smooth_growth_higher_low_count' => $g('smooth_growth_higher_low_count'),
                'smooth_growth_single_candle_dominance_pct' => $g('smooth_growth_single_candle_dominance_pct'),
                'open_interest_growth_pct' => $g('open_interest_growth_pct'),
                'open_interest_confirmed' => $g('open_interest_confirmed'),
                'recovery_phase' => $g('recovery_phase'),
                'entry_timing' => $g('entry_timing'),
                'context_phase' => $trend['context_phase'] ?? $g('context_phase'),
                'context_quality' => $trend['context_quality'] ?? $g('context_quality'),
                'context_reasons' => is_array($g('context_reasons', [])) ? $g('context_reasons', []) : [],
                'trend_1h_direction' => $trend['trend_1h_direction'] ?? null,
                'trend_2h_direction' => $trend['trend_2h_direction'] ?? null,
                'wave_regime' => $trend['wave_regime'] ?? null,
                'trend_flip_count_2h' => $trend['trend_flip_count_2h'] ?? null,
                'avg_time_between_flips_minutes' => $trend['avg_time_between_flips_minutes'] ?? null,
                'trend_persistence_score' => $trend['trend_persistence_score'] ?? null,
                'ask_wall_risk' => $ob['ask_wall_risk'] ?? null,
                'nearest_ask_wall_distance_pct' => $ob['nearest_ask_wall_distance_pct'] ?? null,
                'nearest_ask_wall_notional' => $ob['nearest_ask_wall_notional'] ?? null,
                'bid_support_quality' => $ob['bid_support_quality'] ?? null,
                'bid_ask_notional_ratio' => $ob['bid_ask_notional_ratio'] ?? null,
                'micro_context_available' => (bool)($candleMicro['micro_context_available'] ?? false),
                'micro_proxy_available' => (bool)($candleMicro['micro_proxy_available'] ?? false),
                'candle_micro_real' => (bool)($candleMicro['candle_micro_real'] ?? false),
                'micro_source' => (string)($candleMicro['candle_micro_source'] ?? $candleMicro['micro_source'] ?? 'none'),
                'candle_micro_source' => (string)($candleMicro['candle_micro_source'] ?? $candleMicro['micro_source'] ?? 'none'),
                'candle_micro_windows' => (array)($candleMicro['candle_micro_windows'] ?? []),
                'micro_impulse_shape' => (string)($candleMicro['micro_impulse_shape'] ?? 'unknown'),
                'micro_entry_timing' => (string)($candleMicro['micro_entry_timing'] ?? 'unknown'),
                'micro_growth_distribution' => (string)($candleMicro['micro_growth_distribution'] ?? 'unknown'),
                'micro_rejection_risk' => (string)($candleMicro['micro_rejection_risk'] ?? 'unknown'),
                'micro_primary_window' => (string)($candleMicro['micro_primary_window'] ?? ($cfg['micro_primary_window'] ?? 'micro_window_10m')),
                'micro_primary_candles_count' => (int)($candleMicro['micro_primary_candles_count'] ?? 0),
                'micro_total_change_pct' => $candleMicro['micro_total_change_pct'] ?? null,
                'single_candle_dominance_pct' => $candleMicro['single_candle_dominance_pct'] ?? ($candleMicro['micro_single_candle_dominance_pct'] ?? null),
                'largest_candle_share_pct' => $candleMicro['largest_candle_share_pct'] ?? ($candleMicro['micro_largest_candle_share_pct'] ?? null),
                'largest_candle_change_pct' => $candleMicro['largest_candle_change_pct'] ?? null,
                'higher_close_count' => (int)($candleMicro['higher_close_count'] ?? ($candleMicro['micro_higher_close_count'] ?? 0)),
                'higher_low_count' => (int)($candleMicro['higher_low_count'] ?? ($candleMicro['micro_higher_low_count'] ?? 0)),
                'lower_close_count' => (int)($candleMicro['lower_close_count'] ?? 0),
                'lower_low_count' => (int)($candleMicro['lower_low_count'] ?? 0),
                'direction_flip_count' => (int)($candleMicro['direction_flip_count'] ?? 0),
                'pullback_max_pct' => $candleMicro['pullback_max_pct'] ?? ($candleMicro['micro_pullback_max_pct'] ?? null),
                'pullback_count' => (int)($candleMicro['pullback_count'] ?? 0),
                'avg_body_pct' => $candleMicro['avg_body_pct'] ?? null,
                'avg_upper_wick_pct' => $candleMicro['avg_upper_wick_pct'] ?? null,
                'avg_lower_wick_pct' => $candleMicro['avg_lower_wick_pct'] ?? null,
                'max_upper_wick_pct' => $candleMicro['max_upper_wick_pct'] ?? null,
                'max_lower_wick_pct' => $candleMicro['max_lower_wick_pct'] ?? null,
                'smoothness_score' => $candleMicro['smoothness_score'] ?? null,
                'acceleration_score' => $candleMicro['acceleration_score'] ?? null,
                'impulse_birth_score' => $candleMicro['impulse_birth_score'] ?? null,
                'late_spike_risk_score' => $candleMicro['late_spike_risk_score'] ?? null,
                'micro_higher_close_count' => (int)($candleMicro['micro_higher_close_count'] ?? ($candleMicro['higher_close_count'] ?? 0)),
                'micro_higher_low_count' => (int)($candleMicro['micro_higher_low_count'] ?? ($candleMicro['higher_low_count'] ?? 0)),
                'micro_largest_candle_share_pct' => $candleMicro['micro_largest_candle_share_pct'] ?? ($candleMicro['largest_candle_share_pct'] ?? null),
                'micro_single_candle_dominance_pct' => $candleMicro['micro_single_candle_dominance_pct'] ?? ($candleMicro['single_candle_dominance_pct'] ?? null),
                'micro_pullback_max_pct' => $candleMicro['micro_pullback_max_pct'] ?? ($candleMicro['pullback_max_pct'] ?? null),
                'micro_smoothness_score' => $candleMicro['micro_smoothness_score'] ?? ($candleMicro['smoothness_score'] ?? null),
                'micro_impulse_birth_score' => $candleMicro['micro_impulse_birth_score'] ?? ($candleMicro['impulse_birth_score'] ?? null),
                'micro_late_spike_risk_score' => $candleMicro['micro_late_spike_risk_score'] ?? ($candleMicro['late_spike_risk_score'] ?? null),
                'micro_direction_flip_count' => (int)($candleMicro['direction_flip_count'] ?? 0),
                'dump_micro_available' => (bool)($dumpMicro['dump_micro_available'] ?? false),
                'dump_micro_proxy_available' => (bool)($dumpMicro['dump_micro_proxy_available'] ?? false),
                'dump_micro_real' => (bool)($dumpMicro['dump_micro_real'] ?? false),
                'dump_source' => (string)($dumpMicro['dump_source'] ?? 'none'),
                'dump_micro_source' => (string)($dumpMicro['dump_source'] ?? 'none'),
                'dump_shape' => (string)($dumpMicro['dump_shape'] ?? 'unknown'),
                'post_dump_state' => (string)($dumpMicro['post_dump_state'] ?? 'unknown'),
                'post_dump_impulse_type' => (string)($impulse['post_dump_impulse_type'] ?? 'unknown'),
                'impulse_birth_after_dump_score' => $impulse['impulse_birth_after_dump_score'] ?? $dumpMicro['impulse_birth_after_dump_score'] ?? null,
                'bounce_only_risk_score' => $impulse['bounce_only_risk_score'] ?? $dumpMicro['bounce_only_risk_score'] ?? null,
                'entry_quality_micro_score' => $impulse['entry_quality_micro_score'] ?? null,
                'legacy_proxy_feature' => false,
                'micro_candle' => $candleMicro,
                'micro_dump' => $dumpMicro,
                'micro_impulse' => $impulse,
                'dynamic_risk_score' => is_array($weightedScore) ? ($weightedScore['risk'] ?? null) : null,
                'dynamic_quality_score' => is_array($weightedScore) ? ($weightedScore['quality'] ?? null) : null,
                'risk_components' => $riskComponents,
                'quality_components' => $qualityComponents,
                'created_at' => date('c'),
            ];

            $records[] = $featureRecord;
            $result['records_total']++;

            $src = $featureSource;
            $result['source_counts'][$src] = ($result['source_counts'][$src] ?? 0) + 1;

            if ($featureTimeValid) {
                $result['time_valid_total']++;
            } else {
                $result['time_invalid_total']++;
                if (count($result['time_invalid_examples']) < 10) {
                    $result['time_invalid_examples'][] = [
                        'snapshot_id' => $snap['snapshot_id'] ?? null,
                        'symbol' => $snap['symbol'] ?? null,
                        'feature_source' => $featureSource,
                        'feature_time_warning' => $featureTimeWarning,
                    ];
                }
            }

            if ((bool)($candleMicro['micro_context_available'] ?? false)) {
                $result['candle_micro_real_available_total']++;
                $result['candle_micro_available_total']++;
                $result['new_micro_features_total']++;
            } elseif ((bool)($candleMicro['micro_proxy_available'] ?? false)) {
                $result['candle_micro_proxy_available_total']++;
            } else {
                $result['candle_micro_missing_total']++;
            }
            if ((bool)($dumpMicro['dump_micro_available'] ?? false)) {
                $result['dump_micro_real_available_total']++;
                $result['dump_micro_available_total']++;
            } elseif ((bool)($dumpMicro['dump_micro_proxy_available'] ?? false)) {
                $result['dump_micro_proxy_available_total']++;
            } else {
                $result['dump_micro_missing_total']++;
            }

            $countMap($result['micro_impulse_shape_counts'], (string)($featureRecord['micro_impulse_shape'] ?? 'unknown'));
            $countMap($result['micro_entry_timing_counts'], (string)($featureRecord['micro_entry_timing'] ?? 'unknown'));
            $countMap($result['micro_growth_distribution_counts'], (string)($featureRecord['micro_growth_distribution'] ?? 'unknown'));
            $countMap($result['micro_rejection_risk_counts'], (string)($featureRecord['micro_rejection_risk'] ?? 'unknown'));
            $countMap($result['dump_shape_counts'], (string)($featureRecord['dump_shape'] ?? 'unknown'));
            $countMap($result['post_dump_state_counts'], (string)($featureRecord['post_dump_state'] ?? 'unknown'));
            $countMap($result['post_dump_impulse_type_counts'], (string)($featureRecord['post_dump_impulse_type'] ?? 'unknown'));

            $primarySummaryAvailable = (int)($featureRecord['micro_primary_candles_count'] ?? 0) > 0
                && (
                    is_numeric($featureRecord['single_candle_dominance_pct'] ?? null)
                    || is_numeric($featureRecord['higher_close_count'] ?? null)
                    || is_numeric($featureRecord['higher_low_count'] ?? null)
                );
            if ($primarySummaryAvailable) {
                $result['micro_primary_summary_available_total']++;
            } else {
                $result['micro_primary_summary_missing_total']++;
            }

            if (count($result['micro_pattern_examples']) < 12) {
                $out = is_array($outcomeBySnapshot[(string)($featureRecord['snapshot_id'] ?? '')] ?? null)
                    ? (array)$outcomeBySnapshot[(string)$featureRecord['snapshot_id']]
                    : [];
                $result['micro_pattern_examples'][] = [
                    'symbol' => $featureRecord['symbol'] ?? '',
                    'outcome_class' => $out['outcome_class'] ?? null,
                    'close_roi' => $out['close_roi'] ?? null,
                    'max_drawdown_roi' => $out['max_drawdown_roi'] ?? null,
                    'micro_impulse_shape' => $featureRecord['micro_impulse_shape'] ?? null,
                    'micro_entry_timing' => $featureRecord['micro_entry_timing'] ?? null,
                    'micro_growth_distribution' => $featureRecord['micro_growth_distribution'] ?? null,
                    'micro_primary_window' => $featureRecord['micro_primary_window'] ?? null,
                    'single_candle_dominance_pct' => $featureRecord['single_candle_dominance_pct'] ?? ($featureRecord['micro_single_candle_dominance_pct'] ?? null),
                    'largest_candle_share_pct' => $featureRecord['largest_candle_share_pct'] ?? ($featureRecord['micro_largest_candle_share_pct'] ?? null),
                    'higher_close_count' => $featureRecord['higher_close_count'] ?? ($featureRecord['micro_higher_close_count'] ?? null),
                    'higher_low_count' => $featureRecord['higher_low_count'] ?? ($featureRecord['micro_higher_low_count'] ?? null),
                    'dump_shape' => $featureRecord['dump_shape'] ?? null,
                    'post_dump_state' => $featureRecord['post_dump_state'] ?? null,
                    'post_dump_impulse_type' => $featureRecord['post_dump_impulse_type'] ?? null,
                    'ask_wall_risk' => $featureRecord['ask_wall_risk'] ?? null,
                    'wave_regime' => $featureRecord['wave_regime'] ?? null,
                    'oi_confirmed' => $ctx['open_interest_confirmed'] ?? null,
                ];
            }

            if ($weightedScore !== null) {
                $result['weighted_score_calculated_total']++;
            }

            $sid = trim((string)($featureRecord['snapshot_id'] ?? ''));
            if ($sid !== '') {
                $result['feature_by_snapshot'][$sid] = $featureRecord;
            }
        }

        $featureNdjsonPath = $this->storagePath('features/early_impulse_growth_long/features.ndjson');
        $featureDir = dirname($featureJsonPath);
        if (!is_dir($featureDir)) {
            @mkdir($featureDir, 0755, true);
        }
        if ((bool)($cfg['clean_learning_start_enabled'] ?? false) && !(bool)($cfg['clear_dynamic_learning_features_on_clean_start'] ?? true)) {
            foreach ($existingFeatures as $oldRow) {
                if (!is_array($oldRow)) {
                    continue;
                }
                $oldRow['legacy_proxy_feature'] = (bool)($oldRow['legacy_proxy_feature'] ?? true);
                $records[] = $oldRow;
            }
        }
        $json = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($json)) {
            @file_put_contents($featureJsonPath, $json, LOCK_EX);
        }
        $ndjsonContent = '';
        foreach ($records as $rec) {
            $line = json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($line)) {
                $ndjsonContent .= $line . PHP_EOL;
            }
        }
        @file_put_contents($featureNdjsonPath, $ndjsonContent, LOCK_EX);

        $mdStats = $marketData->getStats();
        $result['bybit_kline_requests_total'] = (int)($mdStats['bybit_kline_requests_total'] ?? 0);
        $result['bybit_kline_success_total'] = (int)($mdStats['bybit_kline_success_total'] ?? 0);
        $result['bybit_kline_error_total'] = (int)($mdStats['bybit_kline_error_total'] ?? 0);
        $result['bybit_kline_cache_hit_total'] = (int)($mdStats['bybit_kline_cache_hit_total'] ?? 0);
        $result['bybit_kline_unique_fetch_total'] = (int)($mdStats['bybit_kline_unique_fetch_total'] ?? 0);
        $result['bybit_kline_reused_window_total'] = (int)($mdStats['bybit_kline_reused_window_total'] ?? 0);
        $result['bybit_kline_fetch_cache_key_mode'] = (string)($mdStats['bybit_kline_fetch_cache_key_mode'] ?? 'symbol_entry_interval_limit');
        $result['parser2_fallback_used_total'] = (int)($mdStats['parser2_fallback_used_total'] ?? 0);
        $result['micro_data_missing_total'] = (int)($mdStats['micro_data_missing_total'] ?? 0);

        $overlap = [];
        foreach ($outcomeRows as $o) {
            if (!is_array($o)) {
                continue;
            }
            $sid = trim((string)($o['snapshot_id'] ?? ''));
            if ($sid === '' || !isset($result['feature_by_snapshot'][$sid])) {
                continue;
            }
            $fr = (array)$result['feature_by_snapshot'][$sid];
            $key = implode('|', [
                (string)($fr['micro_impulse_shape'] ?? 'unknown'),
                (string)($fr['micro_growth_distribution'] ?? 'unknown'),
                (string)($fr['dump_shape'] ?? 'unknown'),
            ]);
            $cls = (string)($o['outcome_class'] ?? 'unknown');
            $overlap[$key][$cls] = (int)($overlap[$key][$cls] ?? 0) + 1;
        }
        foreach ($overlap as $k => $classes) {
            if (count($result['micro_bad_good_overlap_examples']) >= 12) {
                break;
            }
            if ((int)($classes['bad_entry'] ?? 0) > 0 && (int)($classes['good_or_do_not_touch'] ?? 0) > 0) {
                $result['micro_bad_good_overlap_examples'][] = ['bucket' => $k, 'counts' => $classes];
            }
        }

        return $result;
    }

    /**
     * Compute weighted risk/quality score from features — diagnostic only.
     *
     * @return array{0:array{risk:float,quality:float}|null,1:array<string,mixed>,2:array<string,mixed>}
     */
    private function computeWeightedScore(array $f): array
    {
        $riskComponents = [];
        $qualityComponents = [];
        $riskScore = 0.0;
        $qualityScore = 0.0;

        if ((string)($f['micro_single_candle_dominance_pct'] ?? '') !== '' && is_numeric($f['micro_single_candle_dominance_pct'] ?? null) && (float)$f['micro_single_candle_dominance_pct'] >= 65) {
            $riskScore += 20.0;
            $riskComponents['single_candle_dominance_high'] = 20.0;
        }
        if ((string)($f['micro_entry_timing'] ?? '') === 'after_spike') {
            $riskScore += 15.0;
            $riskComponents['micro_entry_after_spike'] = 15.0;
        }
        if ((string)($f['micro_growth_distribution'] ?? '') === 'concentrated') {
            $riskScore += 12.0;
            $riskComponents['concentrated_growth'] = 12.0;
        }
        if ((string)($f['micro_rejection_risk'] ?? '') === 'high') {
            $riskScore += 10.0;
            $riskComponents['high_rejection_wick'] = 10.0;
        }
        if ((string)($f['post_dump_state'] ?? '') === 'knife_bounce') {
            $riskScore += 10.0;
            $riskComponents['knife_bounce'] = 10.0;
        }
        if ((string)($f['dump_shape'] ?? '') === 'choppy_dump') {
            $riskScore += 8.0;
            $riskComponents['choppy_dump'] = 8.0;
        }
        if ((string)($f['wave_regime'] ?? '') === 'fast_flip_chop') {
            $riskScore += 15.0;
            $riskComponents['fast_flip_chop'] = 15.0;
        }
        if ((string)($f['ask_wall_risk'] ?? '') === 'high') {
            $riskScore += 15.0;
            $riskComponents['ask_wall_high'] = 15.0;
        }
        if (in_array(strtolower((string)($f['bid_support_quality'] ?? '')), ['weak', 'none'], true)) {
            $riskScore += 10.0;
            $riskComponents['bid_support_weak'] = 10.0;
        }
        if ($f['open_interest_confirmed'] === false || $f['open_interest_confirmed'] === 'false') {
            $riskScore += 5.0;
            $riskComponents['oi_not_confirmed'] = 5.0;
        }
        if ((string)($f['micro_impulse_shape'] ?? '') === 'smooth_birth') {
            $qualityScore += 18.0;
            $qualityComponents['smooth_birth'] = 18.0;
        }
        if ((string)($f['micro_growth_distribution'] ?? '') === 'distributed') {
            $qualityScore += 14.0;
            $qualityComponents['distributed_growth'] = 14.0;
        }
        if ((string)($f['dump_shape'] ?? '') === 'controlled_dump') {
            $qualityScore += 10.0;
            $qualityComponents['controlled_dump'] = 10.0;
        }
        if ((string)($f['post_dump_state'] ?? '') === 'stabilized') {
            $qualityScore += 10.0;
            $qualityComponents['post_dump_stabilized'] = 10.0;
        }
        if (is_numeric($f['micro_higher_close_count'] ?? null) && (int)$f['micro_higher_close_count'] >= 3) {
            $qualityScore += 12.0;
            $qualityComponents['higher_close_sequence'] = 12.0;
        }
        if (is_numeric($f['micro_higher_low_count'] ?? null) && (int)$f['micro_higher_low_count'] >= 2) {
            $qualityScore += 10.0;
            $qualityComponents['higher_low_sequence'] = 10.0;
        }
        if (in_array(strtolower((string)($f['bid_support_quality'] ?? '')), ['strong', 'medium'], true)) {
            $qualityScore += 10.0;
            $qualityComponents['bid_support_strong'] = 10.0;
        }
        if ($f['open_interest_confirmed'] === true || $f['open_interest_confirmed'] === 'true') {
            $qualityScore += 6.0;
            $qualityComponents['oi_confirmed'] = 6.0;
        }

        return [
            ['risk' => round($riskScore, 2), 'quality' => round($qualityScore, 2)],
            $riskComponents,
            $qualityComponents,
        ];
    }

    /**
     * Prune old storage records to stay within configured limits.
     * Never deletes active position observations.
     *
     * @return array{pruned_total:int,size_estimate_mb:float|null,prune_examples:list<string>,prune_reason_counts:array<string,int>,cycle_history_pruned_total:int,active_observation_files_total:int,active_observation_files_pruned_total:int}
     */
    private function pruneStorage(array $cfg, array $currentSnapshots): array
    {
        $prunedTotal = 0;
        $pruneExamples = [];
        $reasonCounts = [];
        $cycleHistoryPrunedTotal = 0;
        $activeObservationFilesTotal = 0;
        $activeObservationFilesPrunedTotal = 0;

        $bump = static function (array &$map, string $reason, int $count = 1): void {
            $map[$reason] = (int)($map[$reason] ?? 0) + $count;
        };

        $activeIds = [];
        $snapshotIndex = [];
        foreach ($currentSnapshots as $snap) {
            if (!is_array($snap)) {
                continue;
            }
            $sid = (string)($snap['snapshot_id'] ?? '');
            if ($sid !== '') {
                $snapshotIndex[$sid] = $snap;
            }
        }
        $activeRows = (array)$this->readJson($this->repoRoot . '/modules/bot/storage/active_positions.json', []);
        foreach ($activeRows as $row) {
            if (!is_array($row) || !$this->isEigl($row, self::STRATEGY_ID)) {
                continue;
            }
            $sid = $this->resolveSnapshotId($row, $snapshotIndex);
            if ($sid !== null) {
                $activeIds[$sid] = true;
            }
        }
        $patternSnapshotIds = [];
        foreach ((array)$this->readJson($this->storagePath('closed_outcomes.json'), []) as $row) {
            if (!is_array($row) || !(bool)($row['used_for_pattern_mining'] ?? false)) {
                continue;
            }
            $sid = trim((string)($row['snapshot_id'] ?? ''));
            if ($sid !== '') {
                $patternSnapshotIds[$sid] = true;
            }
        }
        $protectedSnapshotIds = $activeIds + $patternSnapshotIds;

        $maxSnapshots = max(100, (int)($cfg['max_entry_snapshots'] ?? 2000));
        if (count($currentSnapshots) > $maxSnapshots) {
            $snapshotPath = $this->storagePath('entry_snapshots.json');
            $allSnaps = (array)$this->readJson($snapshotPath, []);
            if (count($allSnaps) > $maxSnapshots) {
                $protected = [];
                $unprotected = [];
                foreach ($allSnaps as $snap) {
                    if (!is_array($snap)) {
                        continue;
                    }
                    $sid = trim((string)($snap['snapshot_id'] ?? ''));
                    if ($sid !== '' && isset($protectedSnapshotIds[$sid])) {
                        $protected[] = $snap;
                    } else {
                        $unprotected[] = $snap;
                    }
                }
                $slots = max(0, $maxSnapshots - count($protected));
                $allSnaps = array_merge($protected, array_slice($unprotected, 0, $slots));
                $pruned = array_slice($unprotected, $slots);
                $trimCount = count($pruned);
                $prunedTotal += $trimCount;
                $bump($reasonCounts, 'entry_snapshots_limit', $trimCount);
                foreach (array_slice($pruned, 0, 5) as $p) {
                    $pruneExamples[] = 'snapshot:' . (is_array($p) ? (string)($p['snapshot_id'] ?? '?') : '?');
                }
                $this->writeJson($snapshotPath, array_values($allSnaps));
            }
        }

        $maxOutcomes = max(100, (int)($cfg['max_closed_outcomes'] ?? 1000));
        $outcomesPath = $this->storagePath('closed_outcomes.json');
        $allOutcomes = (array)$this->readJson($outcomesPath, []);
        if (count($allOutcomes) > $maxOutcomes) {
            $trimCount = count($allOutcomes) - $maxOutcomes;
            array_splice($allOutcomes, 0, $trimCount);
            $prunedTotal += $trimCount;
            $bump($reasonCounts, 'closed_outcomes_limit', $trimCount);
            $pruneExamples[] = 'closed_outcomes:' . $trimCount . '_pruned';
            $this->writeJson($outcomesPath, array_values($allOutcomes));
        }

        $maxFeatureRecords = max(100, (int)($cfg['max_feature_records'] ?? 2000));
        $featureJsonPath = $this->storagePath('features/early_impulse_growth_long/features.json');
        $featureNdjsonPath = $this->storagePath('features/early_impulse_growth_long/features.ndjson');
        $allFeatures = (array)$this->readJson($featureJsonPath, []);
        if (count($allFeatures) > $maxFeatureRecords) {
            $protectedFeatures = [];
            $unprotectedFeatures = [];
            foreach ($allFeatures as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sid = trim((string)($row['snapshot_id'] ?? ''));
                if ($sid !== '' && isset($protectedSnapshotIds[$sid])) {
                    $protectedFeatures[] = $row;
                } else {
                    $unprotectedFeatures[] = $row;
                }
            }
            $slots = max(0, $maxFeatureRecords - count($protectedFeatures));
            $allFeatures = array_merge($protectedFeatures, array_slice($unprotectedFeatures, 0, $slots));
            $trimCount = max(0, count($unprotectedFeatures) - $slots);
            $prunedTotal += $trimCount;
            $bump($reasonCounts, 'feature_records_limit', $trimCount);
            $pruneExamples[] = 'features:' . $trimCount . '_pruned';
            $this->writeJson($featureJsonPath, array_values($allFeatures));
            $lines = '';
            foreach ($allFeatures as $row) {
                $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($line)) {
                    $lines .= $line . PHP_EOL;
                }
            }
            @file_put_contents($featureNdjsonPath, $lines, LOCK_EX);
        }

        $historyPrune = $this->pruneCycleHistoryFile(
            max(100, (int)($cfg['max_cycle_history_lines'] ?? 1000)),
            max(1.0, (float)($cfg['max_cycle_history_size_mb'] ?? 20.0))
        );
        if ($historyPrune['pruned_total'] > 0) {
            $cycleHistoryPrunedTotal = $historyPrune['pruned_total'];
            $prunedTotal += $historyPrune['pruned_total'];
            $bump($reasonCounts, 'cycle_history_limit', $historyPrune['pruned_total']);
            foreach (array_slice($historyPrune['examples'], 0, 5) as $ex) {
                $pruneExamples[] = 'cycle_history:' . $ex;
            }
        }

        $obsDir = $this->storagePath('active_observations');
        $maxObsFiles = max(100, (int)($cfg['max_active_observation_files'] ?? 500));
        $maxObsPerPosition = max(1, (int)($cfg['max_observations_per_position'] ?? 40));
        if (is_dir($obsDir)) {
            $summaryFiles = glob($obsDir . '/*.json') ?: [];
            $activeObservationFilesTotal = count($summaryFiles);
            foreach ($summaryFiles as $file) {
                $sid = basename($file, '.json');
                $compact = $this->compactObservationNdjson($obsDir . '/' . $sid . '.ndjson', $maxObsPerPosition);
                if ($compact['pruned_lines'] > 0) {
                    $prunedTotal += $compact['pruned_lines'];
                    $bump($reasonCounts, 'max_observations_per_position', $compact['pruned_lines']);
                    $pruneExamples[] = 'observation_lines:' . $sid;
                }
            }
            if (count($summaryFiles) > $maxObsFiles) {
                $candidates = [];
                foreach ($summaryFiles as $file) {
                    $sid = basename($file, '.json');
                    if (isset($activeIds[$sid])) {
                        continue;
                    }
                    $candidates[] = ['sid' => $sid, 'json' => $file, 'ndjson' => $obsDir . '/' . $sid . '.ndjson', 'mtime' => (int)@filemtime($file)];
                }
                usort($candidates, static fn(array $a, array $b): int => ($a['mtime'] <=> $b['mtime']));
                $toDelete = max(0, count($summaryFiles) - $maxObsFiles);
                $deleted = 0;
                foreach ($candidates as $cand) {
                    if ($deleted >= $toDelete) {
                        break;
                    }
                    $removedOne = false;
                    if (is_file($cand['json']) && @unlink($cand['json'])) {
                        $removedOne = true;
                    }
                    if (is_file($cand['ndjson'])) {
                        @unlink($cand['ndjson']);
                    }
                    if ($removedOne) {
                        $deleted++;
                        $pruneExamples[] = 'active_observation:' . $cand['sid'];
                    }
                }
                if ($deleted > 0) {
                    $activeObservationFilesPrunedTotal += $deleted;
                    $prunedTotal += $deleted;
                    $bump($reasonCounts, 'active_observation_files_limit', $deleted);
                }
            }
        }

        $sizeEstimateMb = $this->estimateStorageSizeMb();
        $maxStorageSizeMb = max(50.0, (float)($cfg['max_storage_size_mb'] ?? 150.0));
        if ($sizeEstimateMb !== null && $sizeEstimateMb > $maxStorageSizeMb && is_dir($obsDir)) {
            $summaryFiles = glob($obsDir . '/*.json') ?: [];
            $candidates = [];
            foreach ($summaryFiles as $file) {
                $sid = basename($file, '.json');
                if (isset($activeIds[$sid])) {
                    continue;
                }
                $nd = $obsDir . '/' . $sid . '.ndjson';
                $bytes = (int)@filesize($file) + (is_file($nd) ? (int)@filesize($nd) : 0);
                $candidates[] = ['sid' => $sid, 'json' => $file, 'ndjson' => $nd, 'bytes' => $bytes, 'mtime' => (int)@filemtime($file)];
            }
            usort($candidates, static fn(array $a, array $b): int => ($a['mtime'] <=> $b['mtime']));
            foreach ($candidates as $cand) {
                if ($sizeEstimateMb === null || $sizeEstimateMb <= $maxStorageSizeMb) {
                    break;
                }
                $removed = false;
                if (is_file($cand['json']) && @unlink($cand['json'])) {
                    $removed = true;
                }
                if (is_file($cand['ndjson'])) {
                    @unlink($cand['ndjson']);
                }
                if ($removed) {
                    $prunedTotal++;
                    $bump($reasonCounts, 'max_storage_size_mb', 1);
                    $pruneExamples[] = 'size_prune_active_observation:' . $cand['sid'];
                    $sizeEstimateMb = $this->estimateStorageSizeMb();
                }
            }
        }

        $sizeEstimateMb = $this->estimateStorageSizeMb();
        return [
            'pruned_total' => $prunedTotal,
            'size_estimate_mb' => $sizeEstimateMb,
            'prune_examples' => array_slice($pruneExamples, 0, 20),
            'prune_reason_counts' => $reasonCounts,
            'cycle_history_pruned_total' => $cycleHistoryPrunedTotal,
            'active_observation_files_total' => $activeObservationFilesTotal,
            'active_observation_files_pruned_total' => $activeObservationFilesPrunedTotal,
        ];
    }

    /** @return array{pruned_total:int,examples:list<string>} */
    private function pruneCycleHistoryFile(int $maxLines, float $maxSizeMb): array
    {
        $path = $this->storagePath('cycle_history.ndjson');
        if (!is_file($path)) {
            return ['pruned_total' => 0, 'examples' => []];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return ['pruned_total' => 0, 'examples' => []];
        }
        $sizeBytes = (int)@filesize($path);
        $maxSizeBytes = (int)round($maxSizeMb * 1048576);
        $needByLines = max(0, count($lines) - $maxLines);
        $needBySize = 0;
        if ($sizeBytes > $maxSizeBytes) {
            $bytesToDrop = $sizeBytes - $maxSizeBytes;
            $acc = 0;
            foreach ($lines as $line) {
                $acc += strlen($line) + 1;
                $needBySize++;
                if ($acc >= $bytesToDrop) {
                    break;
                }
            }
        }
        $toDrop = max($needByLines, $needBySize);
        if ($toDrop <= 0) {
            return ['pruned_total' => 0, 'examples' => []];
        }
        $droppedLines = array_slice($lines, 0, $toDrop);
        $kept = array_slice($lines, $toDrop);
        @file_put_contents($path, implode(PHP_EOL, $kept) . (count($kept) > 0 ? PHP_EOL : ''), LOCK_EX);
        $examples = [];
        foreach (array_slice($droppedLines, 0, 3) as $line) {
            $decoded = json_decode((string)$line, true);
            if (is_array($decoded)) {
                $examples[] = (string)($decoded['created_at'] ?? 'unknown');
            }
        }
        return ['pruned_total' => $toDrop, 'examples' => $examples];
    }

    private function estimateStorageSizeMb(): ?float
    {
        $storageDir = $this->moduleDir . '/storage';
        if (!is_dir($storageDir)) {
            return null;
        }
        $total = 0;
        try {
            $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($storageDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iter as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $total += $file->getSize();
                }
            }
            return round($total / 1048576, 3);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{last_line_bytes:int} */
    private function appendCycleHistoryCompact(array $result): array
    {
        $compact = $this->buildCompactCycleHistoryRow($result);
        $json = json_encode($compact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return ['last_line_bytes' => 0];
        }
        $this->appendNdjson($this->storagePath('cycle_history.ndjson'), $compact);
        return ['last_line_bytes' => strlen($json)];
    }

    /** @return array<string,mixed> */
    private function buildCompactCycleHistoryRow(array $result): array
    {
        return [
            'created_at' => (string)($result['created_at'] ?? date('c')),
            'architecture_version' => (string)($result['architecture_version'] ?? 'analyzer_pipeline_v1'),
            'entry_snapshots_total' => (int)($result['entry_snapshots_total'] ?? 0),
            'active_positions_seen_total' => (int)($result['active_positions_seen_total'] ?? 0),
            'observations_written_total' => (int)($result['observations_written_total'] ?? 0),
            'closed_outcomes_effective_total' => (int)($result['closed_outcomes_effective_total'] ?? 0),
            'bad_entry_total' => (int)($result['bad_entry_total'] ?? 0),
            'good_or_do_not_touch_total' => (int)($result['good_or_do_not_touch_total'] ?? 0),
            'entry_ok_exit_issue_total' => (int)($result['entry_ok_exit_issue_total'] ?? 0),
            'feature_records_total' => (int)($result['feature_records_total'] ?? 0),
            'candle_micro_real_available_total' => (int)($result['candle_micro_real_available_total'] ?? 0),
            'candle_micro_proxy_available_total' => (int)($result['candle_micro_proxy_available_total'] ?? 0),
            'dump_micro_real_available_total' => (int)($result['dump_micro_real_available_total'] ?? 0),
            'dump_micro_proxy_available_total' => (int)($result['dump_micro_proxy_available_total'] ?? 0),
            'weighted_score_calculated_total' => (int)($result['weighted_score_calculated_total'] ?? 0),
            'profile_id' => $result['profile_id'] ?? null,
            'profile_rules_total' => (int)($result['profile_rules_total'] ?? 0),
            'apply_learning_to_strategy_enabled' => (bool)($result['apply_learning_to_strategy_enabled'] ?? false),
            'apply_learning_to_live_enabled' => (bool)($result['apply_learning_to_live_enabled'] ?? false),
            'storage_size_estimate_mb' => $result['storage_size_estimate_mb'] ?? null,
        ];
    }

    private function resolveSnapshotId(array $row, array $snapshotIndex): ?string
    {
        $signalId = trim((string)($row['signal_id'] ?? ''));
        $ssk = trim((string)($row['strategy_signal_key'] ?? ''));
        $pid = trim((string)($row['position_id'] ?? ''));
        $sym = strtoupper(trim((string)($row['symbol'] ?? '')));
        $opened = trim((string)($row['opened_at'] ?? $row['entry_time'] ?? ''));
        foreach ($snapshotIndex as $id => $snap) {
            if ($pid !== '' && $pid === (string)($snap['position_id'] ?? '')) {
                return $id;
            }
            if ($signalId !== '' && $signalId === (string)($snap['signal_id'] ?? '')) {
                return $id;
            }
            if ($ssk !== '' && $ssk === (string)($snap['strategy_signal_key'] ?? '')) {
                return $id;
            }
            if ($sym !== '' && $sym === (string)($snap['symbol'] ?? '') && $opened !== '' && $opened === (string)($snap['opened_at'] ?? '')) {
                return $id;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function loadConfig(): array
    {
        $base = $this->readPhpArray($this->moduleDir . '/config/base.php');
        $active = $this->readPhpArray($this->moduleDir . '/config/active.php');
        $cfg = array_merge($base, $active);
        $cfg['enabled'] = (bool)($cfg['enabled'] ?? true);
        $cfg['mode'] = (string)($cfg['mode'] ?? 'diagnostic_only');
        $cfg['supported_strategy_id'] = (string)($cfg['supported_strategy_id'] ?? self::STRATEGY_ID);
        $cfg['collect_entry_snapshots_enabled'] = (bool)($cfg['collect_entry_snapshots_enabled'] ?? true);
        $cfg['observe_active_positions_enabled'] = (bool)($cfg['observe_active_positions_enabled'] ?? true);
        $cfg['analyze_closed_outcomes_enabled'] = (bool)($cfg['analyze_closed_outcomes_enabled'] ?? true);
        $cfg['build_dynamic_profile_enabled'] = (bool)($cfg['build_dynamic_profile_enabled'] ?? true);
        $cfg['apply_learning_to_strategy_enabled'] = (bool)($cfg['apply_learning_to_strategy_enabled'] ?? false);
        $cfg['apply_learning_to_live_enabled'] = (bool)($cfg['apply_learning_to_live_enabled'] ?? false);
        $cfg['apply_learning_to_demo_enabled'] = (bool)($cfg['apply_learning_to_demo_enabled'] ?? false);
        $cfg['observation_interval_seconds'] = max(5, (int)($cfg['observation_interval_seconds'] ?? 30));
        $cfg['max_observations_per_position'] = max(1, (int)($cfg['max_observations_per_position'] ?? 40));
        $cfg['risk_profile_mode'] = strtolower(trim((string)($cfg['risk_profile_mode'] ?? 'working_real')));
        if (!in_array($cfg['risk_profile_mode'], ['fast_demo', 'working_normal', 'working_real', 'custom'], true)) {
            $cfg['risk_profile_mode'] = 'working_real';
        }
        $cfg['outcome_classification_profile'] = strtolower(trim((string)($cfg['outcome_classification_profile'] ?? 'working_real_8_15')));
        if (!in_array($cfg['outcome_classification_profile'], ['fast_demo_corridor_3_5', 'working_normal_8_10', 'working_real_8_15', 'custom'], true)) {
            $cfg['outcome_classification_profile'] = 'working_real_8_15';
        }
        $customBadDrawdown = (float)($cfg['bad_drawdown_roi_threshold'] ?? -10.0);
        $customHardStopReference = (float)($cfg['hard_stop_reference_roi'] ?? -10.0);
        $customGoodClose = (float)($cfg['good_close_roi_threshold'] ?? 5.0);
        $customGoodMaxProfit = (float)($cfg['good_max_profit_roi_threshold'] ?? 5.0);
        $customStopSlippageBuffer = (float)($cfg['stop_slippage_buffer_roi'] ?? 2.0);
        $customPmProfitReference = (float)($cfg['pm_profit_reference_roi'] ?? 10.0);
        if ($cfg['outcome_classification_profile'] === 'fast_demo_corridor_3_5') {
            $cfg['bad_drawdown_roi_threshold'] = -2.5;
            $cfg['hard_stop_reference_roi'] = -5.0;
            $cfg['good_close_roi_threshold'] = 3.0;
            $cfg['good_max_profit_roi_threshold'] = 3.0;
            $cfg['stop_slippage_buffer_roi'] = 2.5;
            $cfg['pm_profit_reference_roi'] = 3.0;
        } elseif ($cfg['outcome_classification_profile'] === 'working_normal_8_10') {
            $cfg['bad_drawdown_roi_threshold'] = -8.0;
            $cfg['hard_stop_reference_roi'] = -10.0;
            $cfg['good_close_roi_threshold'] = 8.0;
            $cfg['good_max_profit_roi_threshold'] = 8.0;
            $cfg['stop_slippage_buffer_roi'] = 2.0;
            $cfg['pm_profit_reference_roi'] = 10.0;
        } elseif ($cfg['outcome_classification_profile'] === 'working_real_8_15') {
            $cfg['bad_drawdown_roi_threshold'] = -12.0;
            $cfg['hard_stop_reference_roi'] = -15.0;
            $cfg['good_close_roi_threshold'] = 8.0;
            $cfg['good_max_profit_roi_threshold'] = 8.0;
            $cfg['stop_slippage_buffer_roi'] = 3.0;
            $cfg['pm_profit_reference_roi'] = 10.0;
        } else {
            $cfg['bad_drawdown_roi_threshold'] = $customBadDrawdown;
            $cfg['hard_stop_reference_roi'] = $customHardStopReference;
            $cfg['good_close_roi_threshold'] = $customGoodClose;
            $cfg['good_max_profit_roi_threshold'] = $customGoodMaxProfit;
            $cfg['stop_slippage_buffer_roi'] = $customStopSlippageBuffer;
            $cfg['pm_profit_reference_roi'] = $customPmProfitReference;
        }
        $cfg['learning_corridor_enabled'] = $cfg['outcome_classification_profile'] === 'fast_demo_corridor_3_5';
        $cfg['stop_profile_alignment'] = (string)$cfg['risk_profile_mode'];
        $cfg['neutral_close_roi_min'] = (float)($cfg['neutral_close_roi_min'] ?? -2.0);
        $cfg['neutral_close_roi_max'] = (float)($cfg['neutral_close_roi_max'] ?? 2.0);
        $cfg['outcome_opened_at_mismatch_tolerance_minutes'] = max(1, (int)($cfg['outcome_opened_at_mismatch_tolerance_minutes'] ?? 15));
        $cfg['prefer_entry_snapshot_time_on_signal_match'] = (bool)($cfg['prefer_entry_snapshot_time_on_signal_match'] ?? true);
        $cfg['min_closed_outcomes_for_profile'] = max(1, (int)($cfg['min_closed_outcomes_for_profile'] ?? 10));
        $cfg['min_bad_entries_for_rule'] = max(1, (int)($cfg['min_bad_entries_for_rule'] ?? 2));
        $cfg['min_bad_blocked_for_rule'] = max(1, (int)($cfg['min_bad_blocked_for_rule'] ?? 2));
        $cfg['max_good_blocked_for_rule'] = max(0, (int)($cfg['max_good_blocked_for_rule'] ?? 1));
        $cfg['min_rule_net_score'] = (float)($cfg['min_rule_net_score'] ?? 1.0);
        $cfg['rollback_guard_enabled'] = (bool)($cfg['rollback_guard_enabled'] ?? true);
        $cfg['rollback_drawdown_pct'] = (float)($cfg['rollback_drawdown_pct'] ?? 7.0);
        $cfg['rollback_bad_trade_streak'] = max(1, (int)($cfg['rollback_bad_trade_streak'] ?? 3));
        $cfg['profile_history_enabled'] = (bool)($cfg['profile_history_enabled'] ?? true);
        $cfg['compare_auto_vs_default_enabled'] = (bool)($cfg['compare_auto_vs_default_enabled'] ?? true);
        $cfg['auto_apply_enabled'] = (bool)($cfg['auto_apply_enabled'] ?? false);
        $cfg['preserve_outcomes_when_source_empty'] = (bool)($cfg['preserve_outcomes_when_source_empty'] ?? true);
        $cfg['rebuild_closed_outcomes_from_ndjson_enabled'] = (bool)($cfg['rebuild_closed_outcomes_from_ndjson_enabled'] ?? true);
        $cfg['closed_outcome_dedupe_use_time_tolerance'] = (bool)($cfg['closed_outcome_dedupe_use_time_tolerance'] ?? true);
        $cfg['closed_outcome_dedupe_closed_at_tolerance_seconds'] = max(0, (int)($cfg['closed_outcome_dedupe_closed_at_tolerance_seconds'] ?? 3));
        $cfg['respect_manual_storage_reset'] = (bool)($cfg['respect_manual_storage_reset'] ?? true);
        $cfg['storage_reset_marker_file'] = trim((string)($cfg['storage_reset_marker_file'] ?? 'storage/reset_marker.json'));
        $cfg['manual_reset_epoch'] = is_numeric($cfg['manual_reset_epoch'] ?? null) ? (float)$cfg['manual_reset_epoch'] : null;
        $cfg['feature_pipeline_enabled'] = (bool)($cfg['feature_pipeline_enabled'] ?? true);
        $cfg['candle_micro_analyzer_enabled'] = (bool)($cfg['candle_micro_analyzer_enabled'] ?? true);
        $cfg['dump_micro_analyzer_enabled'] = (bool)($cfg['dump_micro_analyzer_enabled'] ?? true);
        $cfg['impulse_birth_analyzer_enabled'] = (bool)($cfg['impulse_birth_analyzer_enabled'] ?? true);
        $cfg['trend_context_analyzer_enabled'] = (bool)($cfg['trend_context_analyzer_enabled'] ?? true);
        $cfg['orderbook_snapshot_analyzer_enabled'] = (bool)($cfg['orderbook_snapshot_analyzer_enabled'] ?? true);
        $cfg['weighted_scoring_enabled'] = (bool)($cfg['weighted_scoring_enabled'] ?? true);
        $cfg['clean_learning_start_enabled'] = (bool)($cfg['clean_learning_start_enabled'] ?? true);
        $cfg['clear_strategy_runtime_on_clean_start'] = (bool)($cfg['clear_strategy_runtime_on_clean_start'] ?? false);
        $cfg['clear_dynamic_learning_outcomes_on_clean_start'] = (bool)($cfg['clear_dynamic_learning_outcomes_on_clean_start'] ?? false);
        $cfg['clear_dynamic_learning_features_on_clean_start'] = (bool)($cfg['clear_dynamic_learning_features_on_clean_start'] ?? true);
        $cfg['clear_dynamic_learning_snapshots_on_clean_start'] = (bool)($cfg['clear_dynamic_learning_snapshots_on_clean_start'] ?? false);
        $cfg['clear_dynamic_learning_observations_on_clean_start'] = (bool)($cfg['clear_dynamic_learning_observations_on_clean_start'] ?? false);
        $cfg['micro_data_source_primary'] = strtolower((string)($cfg['micro_data_source_primary'] ?? 'bybit'));
        $cfg['micro_data_source_fallback'] = strtolower((string)($cfg['micro_data_source_fallback'] ?? 'parser2'));
        $cfg['bybit_base_url'] = (string)($cfg['bybit_base_url'] ?? 'https://api.bybit.com');
        $cfg['bybit_timeout_sec'] = max(3, (int)($cfg['bybit_timeout_sec'] ?? 6));
        $cfg['bybit_kline_interval'] = (string)($cfg['bybit_kline_interval'] ?? '1');
        $cfg['bybit_kline_limit'] = max(20, min(1000, (int)($cfg['bybit_kline_limit'] ?? 120)));
        $cfg['bybit_micro_fetch_enabled'] = (bool)($cfg['bybit_micro_fetch_enabled'] ?? true);
        $cfg['bybit_micro_fetch_only_for_strategy'] = (string)($cfg['bybit_micro_fetch_only_for_strategy'] ?? self::STRATEGY_ID);
        $cfg['dump_micro_lookback_minutes'] = max(15, (int)($cfg['dump_micro_lookback_minutes'] ?? 60));
        $cfg['dump_micro_min_candles'] = max(3, (int)($cfg['dump_micro_min_candles'] ?? 10));
        $cfg['micro_primary_window'] = strtolower(trim((string)($cfg['micro_primary_window'] ?? 'micro_window_10m')));
        if (!in_array($cfg['micro_primary_window'], ['micro_window_5m', 'micro_window_10m', 'micro_window_15m'], true)) {
            $cfg['micro_primary_window'] = 'micro_window_10m';
        }
        $cfg['real_learning_epoch_enabled'] = (bool)($cfg['real_learning_epoch_enabled'] ?? ($cfg['micro_learning_epoch_enabled'] ?? true));
        $cfg['real_learning_epoch_id'] = trim((string)($cfg['real_learning_epoch_id'] ?? ($cfg['micro_learning_epoch_id'] ?? 'auto')));
        if ($cfg['real_learning_epoch_id'] === '') {
            $cfg['real_learning_epoch_id'] = 'auto';
        }
        $cfg['real_learning_epoch_start_at'] = $cfg['real_learning_epoch_start_at'] ?? ($cfg['micro_learning_epoch_start_at'] ?? null);
        if (is_string($cfg['real_learning_epoch_start_at'])) {
            $cfg['real_learning_epoch_start_at'] = trim($cfg['real_learning_epoch_start_at']);
            if ($cfg['real_learning_epoch_start_at'] === '') {
                $cfg['real_learning_epoch_start_at'] = null;
            }
        }
        $cfg['ignore_fast_demo_outcomes_in_real_profile'] = (bool)($cfg['ignore_fast_demo_outcomes_in_real_profile'] ?? ($cfg['micro_learning_ignore_legacy_outcomes_before_epoch'] ?? true));
        $cfg['preserve_fast_demo_history'] = (bool)($cfg['preserve_fast_demo_history'] ?? true);
        // Backward-compatible aliases
        $cfg['micro_learning_epoch_enabled'] = $cfg['real_learning_epoch_enabled'];
        $cfg['micro_learning_epoch_id'] = (string)$cfg['real_learning_epoch_id'];
        $cfg['micro_learning_epoch_start_at'] = $cfg['real_learning_epoch_start_at'];
        $cfg['micro_learning_ignore_legacy_outcomes_before_epoch'] = $cfg['ignore_fast_demo_outcomes_in_real_profile'];
        $cfg['max_entry_snapshots'] = max(100, (int)($cfg['max_entry_snapshots'] ?? 2000));
        $cfg['max_feature_records'] = max(100, (int)($cfg['max_feature_records'] ?? 2000));
        $cfg['max_closed_outcomes'] = max(100, (int)($cfg['max_closed_outcomes'] ?? 1000));
        $cfg['max_active_observation_files'] = max(100, (int)($cfg['max_active_observation_files'] ?? 500));
        $cfg['max_storage_size_mb'] = max(50.0, (float)($cfg['max_storage_size_mb'] ?? 150.0));
        $cfg['max_cycle_history_lines'] = max(100, (int)($cfg['max_cycle_history_lines'] ?? 1000));
        $cfg['max_cycle_history_size_mb'] = max(1.0, (float)($cfg['max_cycle_history_size_mb'] ?? 20.0));
        $cfg['max_examples_per_last_run_section'] = max(1, (int)($cfg['max_examples_per_last_run_section'] ?? 10));
        return $cfg;
    }

    /** @return array<string,mixed> */
    private function readPhpArray(string $path): array
    {
        try {
            $v = is_file($path) ? require $path : [];
            return is_array($v) ? $v : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function storagePath(string $file): string { return $this->moduleDir . '/storage/' . $file; }
    private function readJson(string $path, mixed $default): mixed { if (!is_file($path)) { return $default; } $raw = @file_get_contents($path); if (!is_string($raw) || trim($raw) === '') { return $default; } $d = json_decode($raw, true); return $d === null ? $default : $d; }
    private function writeJson(string $path, mixed $data): bool { $dir = dirname($path); if (!is_dir($dir)) { @mkdir($dir, 0755, true); } $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); return is_string($json) && @file_put_contents($path, $json, LOCK_EX) !== false; }
    private function appendNdjson(string $path, array $row): bool { $dir = dirname($path); if (!is_dir($dir)) { @mkdir($dir, 0755, true); } $json = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); return is_string($json) && @file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX) !== false; }
    private function toFloat(mixed $v): ?float { return is_numeric($v) ? (float)$v : null; }
    private function avg(array $xs): ?float { return $xs === [] ? null : round(array_sum($xs) / count($xs), 6); }
}
