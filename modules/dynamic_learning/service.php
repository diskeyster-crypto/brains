<?php

declare(strict_types=1);

namespace Modules\DynamicLearning;

require_once __DIR__ . '/analyzers/dl_helpers.php';
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
            'closed_outcomes_duplicate_examples' => [],
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
            'weighted_score_calculated_total' => 0,
            'bad_patterns_total' => 0,
            'profile_generated' => false,
            'profile_id' => null,
            'profile_rules_total' => 0,
            'profile_rules_observe_only_total' => 0,
            'profile_rules_quarantined_total' => 0,
            'profile_compared_to_default' => false,
            'auto_not_worse_than_default' => false,
            'auto_improvement_score' => null,
            'auto_comparison_reason' => null,
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
            'created_at' => date('c'),
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
        $result['closed_outcomes_duplicate_examples'] = $outcomes['duplicate_examples'];
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
        $result['outcome_excluded_from_pattern_mining_total'] = $outcomes['excluded_from_pattern_mining_total'];
        $result['outcome_excluded_reasons'] = $outcomes['excluded_reasons'];
        $result['bad_entry_examples'] = array_slice($outcomes['bad'], 0, 6);
        $result['good_entry_examples'] = array_slice($outcomes['good'], 0, 6);

        // 4. Feature extraction pipeline
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
            $result['weighted_score_calculated_total'] = $featureResult['weighted_score_calculated_total'];
        }

        // 5. Pattern mining
        $patterns = PatternMiner::mine($outcomes['pattern_mining'], $cfg);
        $this->writeJson($this->storagePath('patterns/bad_patterns.json'), $patterns['bad_patterns']);
        $this->writeJson($this->storagePath('patterns/pattern_stats.json'), $patterns['all']);
        $result['bad_patterns_total'] = count($patterns['all']);
        $result['top_bad_pattern_examples'] = array_slice($patterns['all'], 0, 10);

        // 6. Profile building
        $profile = ProfileBuilder::build($cfg, $outcomes['pattern_mining'], $patterns['all'], fn(string $f): string => $this->storagePath($f));
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

        $profile['source_outcomes_total'] = (int)($result['closed_outcomes_unique_total'] ?? 0);
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

    /** @return array<string,mixed> */
    private function diagnosticWeightsConfig(): array
    {
        return [
            'version' => 'v1',
            'source' => 'static_initial',
            'status' => 'diagnostic_only',
            'risk_components' => [
                'micro_single_candle_dominance_high' => 20.0,
                'fast_flip_chop' => 15.0,
                'ask_wall_high' => 15.0,
                'bid_support_weak' => 10.0,
                'oi_not_confirmed' => 5.0,
            ],
            'quality_components' => [
                'smooth_growth_sequence_good' => 15.0,
                'bid_support_strong' => 10.0,
                'context_quality_good' => 10.0,
            ],
        ];
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

    /** @return array{all:list<array<string,mixed>>,pattern_mining:list<array<string,mixed>>,bad:list<array<string,mixed>>,good:list<array<string,mixed>>,exit_issue:list<array<string,mixed>>,neutral:list<array<string,mixed>>,incomplete:list<array<string,mixed>>,loaded_total:int,raw_loaded_total:int,effective_total:int,unique_total:int,duplicates_skipped_total:int,merged_total:int,duplicate_examples:list<array<string,mixed>>,strong_link_total:int,weak_link_skipped_total:int,preserved_due_empty_source_total:int,rebuilt_from_ndjson_total:int,rebuild_skipped_due_reset_total:int,time_mismatch_total:int,opened_at_corrected_total:int,timing_low_confidence_total:int,time_mismatch_examples:list<array<string,mixed>>,mfe_normalized_total:int,mae_normalized_total:int,roi_normalization_examples:list<array<string,mixed>>,excluded_from_pattern_mining_total:int,excluded_reasons:array<string,int>} */
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
        $sourceUniqueMap = [];
        $duplicatesSkippedTotal = 0;
        $mergedTotal = 0;
        $duplicateExamples = [];
        $preservedDueEmptySourceTotal = 0;
        $rebuiltFromNdjsonTotal = 0;
        $rebuildSkippedDueResetTotal = 0;

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
                $identity = $this->buildClosedTradeIdentity($row);
                $existing = $sourceUniqueMap[$identity] ?? null;
                if ($existing === null) {
                    $sourceUniqueMap[$identity] = $row;
                    continue;
                }

                $duplicatesSkippedTotal++;
                if (count($duplicateExamples) < 20) {
                    $duplicateExamples[] = [
                        'identity' => $identity,
                        'symbol' => strtoupper((string)($row['symbol'] ?? '')),
                        'side' => strtolower((string)($row['side'] ?? '')),
                        'opened_at' => $this->extractOpenedAt($row),
                        'closed_at' => $this->extractClosedAt($row),
                        'close_roi_incoming' => $this->toFloat($row['close_roi'] ?? $row['roi'] ?? null),
                        'close_roi_existing' => $this->toFloat(((array)$existing)['close_roi'] ?? ((array)$existing)['roi'] ?? null),
                    ];
                }

                $scoreRow = $this->closedTradeRichnessScore($row);
                $scoreExisting = $this->closedTradeRichnessScore((array)$existing);
                [$primary, $secondary] = $scoreRow >= $scoreExisting
                    ? [$row, (array)$existing]
                    : [(array)$existing, $row];
                $merged = $this->mergeClosedTradeRecords($primary, $secondary);
                if ($this->closedTradeRichnessScore($merged) > $this->closedTradeRichnessScore($primary)) {
                    $mergedTotal++;
                }
                $sourceUniqueMap[$identity] = $merged;
            }
        }

        $sourceHasRows = count($sourceUniqueMap) > 0;
        $effectiveRows = [];
        if ($resetDetected) {
            $effectiveRows = $sourceHasRows ? array_values($sourceUniqueMap) : [];
            if ($rebuildFromNdjsonEnabled) {
                $rebuildSkippedDueResetTotal = $this->countNdjsonLines($this->storagePath('closed_outcomes.ndjson'));
            }
        } elseif ($sourceHasRows) {
            $effectiveRows = array_values($sourceUniqueMap);
        } elseif ($preserveWhenSourceEmpty && is_array($stored) && count($stored) > 0) {
            $effectiveRows = array_values(array_filter((array)$stored, static fn(mixed $r): bool => is_array($r)));
            $preservedDueEmptySourceTotal = count($effectiveRows);
        } elseif ($rebuildFromNdjsonEnabled) {
            $rebuilt = $this->rebuildOutcomesFromNdjson();
            if ($rebuilt !== []) {
                $effectiveRows = $rebuilt;
                $rebuiltFromNdjsonTotal = count($rebuilt);
            }
        }

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
                $all[] = $outcome;
                if (!isset($existingIndex[(string)($outcome['outcome_key'] ?? '')])) {
                    $this->appendNdjson($this->storagePath('closed_outcomes.ndjson'), $outcome);
                }
            }
        } else {
            foreach ($effectiveRows as $row) {
                if (is_array($row)) {
                    $all[] = $row;
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
            'duplicates_skipped_total' => $duplicatesSkippedTotal,
            'merged_total' => $mergedTotal,
            'duplicate_examples' => $duplicateExamples,
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
            'outcome_class' => $class,
            'classification_reason' => $reason,
            'used_for_pattern_mining' => $featureCheck['used_for_pattern_mining'],
            'pattern_mining_exclude_reason' => $featureCheck['exclude_reason'],
        ];
    }

    private function buildClosedTradeIdentity(array $row): string
    {
        $signalId = trim((string)($row['signal_id'] ?? ''));
        $ssk = trim((string)($row['strategy_signal_key'] ?? ''));
        $openedAt = DlHelpers::extractOpenedAt($row);
        $closedAt = DlHelpers::extractClosedAt($row);

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

        $positionId = trim((string)($row['position_id'] ?? ''));
        if ($positionId !== '') {
            return 'position_id|' . $positionId;
        }

        $closedTradeId = trim((string)($row['closed_trade_id'] ?? $row['id'] ?? ''));
        if ($closedTradeId !== '') {
            return 'closed_trade_id|' . $closedTradeId;
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
            'opened_at', 'entry_time', 'created_at',
            'closed_at', 'close_time', 'closed_time',
            'entry_price',
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

        return $merged;
    }

    private function extractOpenedAt(array $row): string
    {
        return $this->normalizeTimestamp($row['opened_at'] ?? $row['entry_time'] ?? $row['created_at'] ?? '');
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
            'weighted_score_calculated_total' => 0,
        ];

        $records = [];
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

            $candleMicro = (bool)($cfg['candle_micro_analyzer_enabled'] ?? true)
                ? CandleMicroAnalyzer::analyze($ctx)
                : ['micro_context_available' => false];
            $dumpMicro = (bool)($cfg['dump_micro_analyzer_enabled'] ?? true)
                ? DumpMicroAnalyzer::analyze($ctx)
                : ['dump_micro_available' => false];
            $impulse = (bool)($cfg['impulse_birth_analyzer_enabled'] ?? true)
                ? ImpulseBirthAnalyzer::analyze($ctx)
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
                'micro_source' => (string)($candleMicro['micro_source'] ?? 'none'),
                'dump_micro_available' => (bool)($dumpMicro['dump_micro_available'] ?? false),
                'dump_micro_proxy_available' => (bool)($dumpMicro['dump_micro_proxy_available'] ?? false),
                'dump_micro_real' => (bool)($dumpMicro['dump_micro_real'] ?? false),
                'dump_source' => (string)($dumpMicro['dump_source'] ?? 'none'),
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
            if ($weightedScore !== null) {
                $result['weighted_score_calculated_total']++;
            }
        }

        $featureJsonPath = $this->storagePath('features/early_impulse_growth_long/features.json');
        $featureNdjsonPath = $this->storagePath('features/early_impulse_growth_long/features.ndjson');
        $featureDir = dirname($featureJsonPath);
        if (!is_dir($featureDir)) {
            @mkdir($featureDir, 0755, true);
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
            $riskComponents['micro_single_candle_dominance_high'] = 20.0;
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
        if (is_numeric($f['smooth_growth_higher_close_count'] ?? null) && (int)$f['smooth_growth_higher_close_count'] >= 3) {
            $qualityScore += 15.0;
            $qualityComponents['smooth_growth_sequence_good'] = 15.0;
        }
        if (in_array(strtolower((string)($f['bid_support_quality'] ?? '')), ['strong', 'medium'], true)) {
            $qualityScore += 10.0;
            $qualityComponents['bid_support_strong'] = 10.0;
        }
        if ((string)($f['context_quality'] ?? '') === 'good') {
            $qualityScore += 10.0;
            $qualityComponents['context_quality_good'] = 10.0;
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
        $cfg['bad_drawdown_roi_threshold'] = (float)($cfg['bad_drawdown_roi_threshold'] ?? -10.0);
        $cfg['good_close_roi_threshold'] = (float)($cfg['good_close_roi_threshold'] ?? 5.0);
        $cfg['good_max_profit_roi_threshold'] = (float)($cfg['good_max_profit_roi_threshold'] ?? 5.0);
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
