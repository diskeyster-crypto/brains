<?php

declare(strict_types=1);

namespace Modules\DynamicLearning;

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
            'closed_outcomes_unique_total' => 0,
            'closed_outcomes_duplicates_skipped_total' => 0,
            'closed_outcomes_merged_total' => 0,
            'closed_outcomes_duplicate_examples' => [],
            'bad_entry_total' => 0,
            'good_or_do_not_touch_total' => 0,
            'entry_ok_exit_issue_total' => 0,
            'neutral_total' => 0,
            'outcome_incomplete_total' => 0,
            'bad_patterns_total' => 0,
            'profile_generated' => false,
            'profile_id' => null,
            'profile_rules_total' => 0,
            'profile_rules_observe_only_total' => 0,
            'profile_rules_quarantined_total' => 0,
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
            $this->appendNdjson($this->storagePath('cycle_history.ndjson'), $result);
            return $result;
        }

        $snapshots = $this->collectEntrySnapshots($cfg);
        $result['entry_snapshots_total'] = count($snapshots['all']);
        $result['entry_snapshots_new_total'] = $snapshots['new_total'];
        $result['entry_snapshot_examples'] = array_slice($snapshots['all'], 0, 8);

        $obs = $this->observeActivePositions($cfg, $snapshots['index']);
        $result['active_positions_seen_total'] = $obs['seen_total'];
        $result['active_positions_observed_total'] = $obs['observed_total'];
        $result['observations_written_total'] = $obs['written_total'];
        $result['active_observation_examples'] = $obs['examples'];

        $outcomes = $this->linkClosedOutcomes($cfg, $snapshots['index']);
        $result['closed_trades_loaded_total'] = $outcomes['loaded_total'];
        $result['closed_outcomes_raw_loaded_total'] = $outcomes['raw_loaded_total'];
        $result['closed_outcomes_unique_total'] = $outcomes['unique_total'];
        $result['closed_outcomes_duplicates_skipped_total'] = $outcomes['duplicates_skipped_total'];
        $result['closed_outcomes_merged_total'] = $outcomes['merged_total'];
        $result['closed_outcomes_duplicate_examples'] = $outcomes['duplicate_examples'];
        $result['closed_outcomes_matched_total'] = count($outcomes['all']);
        $result['bad_entry_total'] = count($outcomes['bad']);
        $result['good_or_do_not_touch_total'] = count($outcomes['good']);
        $result['entry_ok_exit_issue_total'] = count($outcomes['exit_issue']);
        $result['neutral_total'] = count($outcomes['neutral']);
        $result['outcome_incomplete_total'] = count($outcomes['incomplete']);
        $result['bad_entry_examples'] = array_slice($outcomes['bad'], 0, 6);
        $result['good_entry_examples'] = array_slice($outcomes['good'], 0, 6);

        $patterns = $this->minePatterns($cfg, $outcomes['all']);
        $this->writeJson($this->storagePath('patterns/bad_patterns.json'), $patterns['bad_patterns']);
        $this->writeJson($this->storagePath('patterns/pattern_stats.json'), $patterns['all']);
        $result['bad_patterns_total'] = count($patterns['all']);
        $result['top_bad_pattern_examples'] = array_slice($patterns['all'], 0, 10);

        $profile = $this->buildProfile($cfg, $outcomes['all'], $patterns['all']);
        $result['profile_generated'] = true;
        $result['profile_id'] = $profile['profile_id'];
        $result['profile_rules_total'] = count($profile['rules']);
        $result['profile_rules_observe_only_total'] = count($profile['rules']);
        $result['profile_rules_quarantined_total'] = count($profile['quarantined']);
        $result['quarantined_rule_examples'] = array_slice($profile['quarantined'], 0, 8);

        $this->writeJson($this->storagePath('last_run.json'), $result);
        $this->appendNdjson($this->storagePath('cycle_history.ndjson'), $result);
        return $result;
    }

    /** @return array<string,mixed> */
    public function evaluateSignalForStrategy(string $strategyId, array $signalPacket): array
    {
        $cfg = $this->loadConfig();
        $response = [
            'enabled' => (bool)$cfg['enabled'],
            'decision' => 'pass',
            'mode' => (string)$cfg['mode'],
            'profile_id' => null,
            'matched_rules' => [],
            'reason' => 'no_profile_rules',
            'confidence' => 'low',
            'profile_status' => null,
            'apply_learning_to_live_enabled' => (bool)$cfg['apply_learning_to_live_enabled'],
        ];

        if (strtolower(trim($strategyId)) !== self::STRATEGY_ID) {
            $response['decision'] = 'no_signal';
            $response['reason'] = 'unsupported_strategy';
            return $response;
        }
        if (!$cfg['enabled']) {
            $response['reason'] = 'module_disabled';
            return $response;
        }
        if ($signalPacket === []) {
            $response['decision'] = 'no_signal';
            $response['reason'] = 'signal_packet_missing';
            return $response;
        }

        $profilePath = $this->storagePath('profiles/early_impulse_growth_long/current_profile.json');
        $profile = $this->readJson($profilePath, []);
        if (!is_array($profile) || $profile === []) {
            $response['decision'] = 'pass';
            $response['profile_status'] = 'missing';
            $response['reason'] = 'no_profile_rules';
            return $response;
        }

        $response['profile_id'] = $profile['profile_id'] ?? null;
        $response['profile_status'] = (string)($profile['status'] ?? '');
        if (strtolower(trim((string)($profile['strategy_id'] ?? ''))) !== self::STRATEGY_ID) {
            $response['decision'] = 'no_profile';
            $response['reason'] = 'profile_strategy_mismatch';
            return $response;
        }

        $rules = array_values(array_filter((array)($profile['rules'] ?? []), static fn(mixed $r): bool => is_array($r)));
        if ($rules === []) {
            $response['reason'] = 'no_profile_rules';
            return $response;
        }

        $ctx = is_array($signalPacket['strategy_signal_context'] ?? null) ? (array)$signalPacket['strategy_signal_context'] : [];
        $features = $this->extractEntryFeatures($ctx);
        if ($features === []) {
            $response['decision'] = 'insufficient_data';
            $response['reason'] = 'entry_features_missing';
            return $response;
        }
        foreach ($ctx as $k => $v) {
            if (!array_key_exists((string)$k, $features)) {
                $features[(string)$k] = $v;
            }
        }

        $matched = [];
        $strongestAction = 'observe_only';
        $hasDemoOnlyAction = false;
        $confidenceRank = ['low' => 1, 'medium' => 2, 'high' => 3];
        $bestConfidence = 'low';

        foreach ($rules as $rule) {
            $status = strtolower(trim((string)($rule['status'] ?? 'candidate')));
            if ($status === 'quarantined') {
                continue;
            }
            $conditions = is_array($rule['conditions'] ?? null) ? (array)$rule['conditions'] : [];
            if ($conditions === []) {
                continue;
            }
            $ok = true;
            foreach ($conditions as $cond) {
                if (!is_array($cond)) {
                    $ok = false;
                    break;
                }
                $field = (string)($cond['field'] ?? $cond['f'] ?? '');
                $op = (string)($cond['op'] ?? 'eq');
                $value = $cond['value'] ?? $cond['v'] ?? null;
                if ($field === '' || !$this->cond($features[$field] ?? null, $op, $value)) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }

            $action = strtolower(trim((string)($rule['action'] ?? 'observe_only')));
            $scope = strtolower(trim((string)($rule['scope'] ?? 'demo_only')));
            $confidence = strtolower(trim((string)($rule['confidence'] ?? 'low')));
            if (!isset($confidenceRank[$confidence])) {
                $confidence = 'low';
            }

            if (in_array($action, ['hard_block', 'hard'], true)) {
                $strongestAction = 'hard_block';
            } elseif (in_array($action, ['soft_block', 'soft'], true) && $strongestAction !== 'hard_block') {
                $strongestAction = 'soft_block';
            }
            if (in_array($scope, ['demo_only', 'demo'], true) && in_array($action, ['hard_block', 'hard', 'soft_block', 'soft'], true)) {
                $hasDemoOnlyAction = true;
            }
            if ($confidenceRank[$confidence] > $confidenceRank[$bestConfidence]) {
                $bestConfidence = $confidence;
            }

            $matched[] = [
                'rule_id' => $rule['rule_id'] ?? null,
                'source_pattern' => $rule['source_pattern'] ?? null,
                'action' => $action,
                'scope' => $scope,
                'confidence' => $confidence,
            ];
        }

        if ($matched === []) {
            $response['reason'] = 'no_rule_match';
            return $response;
        }

        $response['matched_rules'] = $matched;
        $response['confidence'] = $bestConfidence;

        if ($strongestAction === 'observe_only') {
            $response['decision'] = 'pass';
            $response['reason'] = 'observe_only_rules';
            return $response;
        }

        if ($hasDemoOnlyAction) {
            $response['decision'] = 'demo_only';
            $response['reason'] = 'demo_only_rule_match';
            return $response;
        }

        $response['decision'] = 'block';
        $response['reason'] = 'rule_match_block';
        return $response;
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
        $entryPrice = $this->toFloat($row['entry_price'] ?? null);
        if ($symbol === '' || $entryPrice === null) {
            return null;
        }
        $side = strtolower(trim((string)($row['side'] ?? 'long')));
        $openedAt = trim((string)($row['opened_at'] ?? $row['entry_time'] ?? $row['detected_at'] ?? ''));
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
            'detected_at' => trim((string)($row['detected_at'] ?? $openedAt)),
            'position_id' => trim((string)($row['position_id'] ?? '')),
            'strategy_signal_context' => $ctx,
            'entry_features' => $this->extractEntryFeatures($ctx),
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
            'nearest_ask_wall_distance_pct' => $this->toFloat($ob['nearest_ask_wall_distance_pct'] ?? $ctx['nearest_ask_wall_distance_pct'] ?? null),
            'nearest_ask_wall_notional' => $this->toFloat($ob['nearest_ask_wall_notional'] ?? $ctx['nearest_ask_wall_notional'] ?? null),
            'bid_support_quality' => (string)($ob['bid_support_quality'] ?? $ctx['bid_support_quality'] ?? 'unknown'),
            'bid_ask_notional_ratio' => $this->toFloat($ob['bid_ask_notional_ratio'] ?? $ctx['bid_ask_notional_ratio'] ?? null),
            'open_interest_change_since_entry_pct' => $this->toFloat($row['open_interest_change_since_entry_pct'] ?? null),
            'observation_source_errors' => [],
        ];
    }

    /** @return array{all:list<array<string,mixed>>,bad:list<array<string,mixed>>,good:list<array<string,mixed>>,exit_issue:list<array<string,mixed>>,neutral:list<array<string,mixed>>,incomplete:list<array<string,mixed>>,loaded_total:int,raw_loaded_total:int,unique_total:int,duplicates_skipped_total:int,merged_total:int,duplicate_examples:list<array<string,mixed>>} */
    private function linkClosedOutcomes(array $cfg, array $snapshotIndex): array
    {
        $stored = $this->readJson($this->storagePath('closed_outcomes.json'), []);
        $existingIndex = [];
        foreach ((array)$stored as $row) {
            if (is_array($row) && !empty($row['outcome_key'])) {
                $existingIndex[(string)$row['outcome_key']] = $row;
            }
        }

        $rawLoadedTotal = 0;
        $uniqueMap = [];
        $duplicatesSkippedTotal = 0;
        $mergedTotal = 0;
        $duplicateExamples = [];

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
                $existing = $uniqueMap[$identity] ?? null;
                if ($existing === null) {
                    $uniqueMap[$identity] = $row;
                    continue;
                }

                $duplicatesSkippedTotal++;
                if (count($duplicateExamples) < 20) {
                    $duplicateExamples[] = [
                        'identity' => $identity,
                        'symbol' => strtoupper((string)($row['symbol'] ?? '')),
                        'side' => strtolower((string)($row['side'] ?? '')),
                        'opened_at' => (string)($row['opened_at'] ?? $row['entry_time'] ?? ''),
                        'closed_at' => (string)($row['closed_at'] ?? $row['close_time'] ?? ''),
                        'close_roi_incoming' => $this->toFloat($row['close_roi'] ?? $row['roi'] ?? null),
                        'close_roi_existing' => $this->toFloat(((array)$existing)['close_roi'] ?? ((array)$existing)['roi'] ?? null),
                    ];
                }

                // Merge: use richer as base, fill missing fields from secondary
                $scoreRow = $this->closedTradeRichnessScore($row);
                $scoreExisting = $this->closedTradeRichnessScore((array)$existing);
                [$primary, $secondary] = $scoreRow >= $scoreExisting
                    ? [$row, (array)$existing]
                    : [(array)$existing, $row];
                $merged = $this->mergeClosedTradeRecords($primary, $secondary);
                if ($this->closedTradeRichnessScore($merged) > $this->closedTradeRichnessScore($primary)) {
                    $mergedTotal++;
                }
                $uniqueMap[$identity] = $merged;
            }
        }

        $all = [];
        foreach ($uniqueMap as $identity => $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['_dl_identity'] = $identity;
            $outcome = $this->makeOutcome($row, $cfg, $snapshotIndex);
            $all[] = $outcome;
            if (!isset($existingIndex[(string)$outcome['outcome_key']])) {
                $this->appendNdjson($this->storagePath('closed_outcomes.ndjson'), $outcome);
            }
        }

        usort($all, static fn(array $a, array $b): int => strcmp((string)($b['closed_at'] ?? ''), (string)($a['closed_at'] ?? '')));
        $this->writeJson($this->storagePath('closed_outcomes.json'), $all);
        $pick = static fn(string $c): array => array_values(array_filter($all, static fn(array $r): bool => (string)($r['outcome_class'] ?? '') === $c));
        return [
            'all' => $all,
            'bad' => $pick('bad_entry'),
            'good' => $pick('good_or_do_not_touch'),
            'exit_issue' => $pick('entry_ok_exit_issue'),
            'neutral' => $pick('neutral'),
            'incomplete' => $pick('outcome_incomplete'),
            'loaded_total' => $rawLoadedTotal,
            'raw_loaded_total' => $rawLoadedTotal,
            'unique_total' => count($all),
            'duplicates_skipped_total' => $duplicatesSkippedTotal,
            'merged_total' => $mergedTotal,
            'duplicate_examples' => $duplicateExamples,
        ];
    }

    /** @return array<string,mixed> */
    private function makeOutcome(array $row, array $cfg, array $snapshotIndex): array
    {
        $snapshotId = $this->resolveSnapshotId($row, $snapshotIndex);
        $summary = $snapshotId ? (array)$this->readJson($this->storagePath('active_observations/' . $snapshotId . '.json'), []) : [];
        $closeRoi = $this->toFloat($row['close_roi'] ?? $row['roi'] ?? null);
        $maxDd = $this->toFloat($row['max_drawdown_roi'] ?? $row['mae_roi'] ?? ($summary['max_drawdown_roi_so_far'] ?? null));
        $maxProfit = $this->toFloat($row['max_profit_roi'] ?? $row['mfe_roi'] ?? ($summary['max_profit_roi_so_far'] ?? null));
        [$class, $reason] = $this->classify($closeRoi, $maxDd, $maxProfit, $cfg);
        $composite = trim((string)($row['_dl_identity'] ?? ''));
        if ($composite === '') {
            $composite = $this->buildClosedTradeIdentity($row);
        }
        return [
            'outcome_key' => 'out_' . substr(sha1($composite), 0, 20),
            'snapshot_id' => $snapshotId,
            'symbol' => strtoupper((string)($row['symbol'] ?? '')),
            'side' => strtolower((string)($row['side'] ?? 'long')),
            'signal_id' => (string)($row['signal_id'] ?? ''),
            'strategy_signal_key' => (string)($row['strategy_signal_key'] ?? ''),
            'entry_price' => $this->toFloat($row['entry_price'] ?? null),
            'close_price' => $this->toFloat($row['close_price'] ?? $row['exit_price'] ?? null),
            'opened_at' => (string)($row['opened_at'] ?? $row['entry_time'] ?? ''),
            'closed_at' => (string)($row['closed_at'] ?? $row['close_time'] ?? ''),
            'close_roi' => $closeRoi,
            'max_drawdown_roi' => $maxDd,
            'max_profit_roi' => $maxProfit,
            'close_reason' => (string)($row['close_reason'] ?? ''),
            'duration_sec' => (int)($row['duration_sec'] ?? 0),
            'entry_snapshot' => $snapshotId && isset($snapshotIndex[$snapshotId]) ? $snapshotIndex[$snapshotId] : null,
            'observation_summary' => $summary,
            'outcome_class' => $class,
            'classification_reason' => $reason,
        ];
    }

    /** @return array{0:string,1:string} */
    private function classify(?float $closeRoi, ?float $maxDd, ?float $maxProfit, array $cfg): array
    {
        if ($closeRoi === null) {
            return ['outcome_incomplete', 'close_roi_missing'];
        }
        if ($closeRoi >= (float)$cfg['good_close_roi_threshold']) {
            return ['good_or_do_not_touch', 'close_roi_good'];
        }
        if ($maxProfit !== null && $maxProfit >= (float)$cfg['good_max_profit_roi_threshold']) {
            return ['entry_ok_exit_issue', 'max_profit_good_but_close_bad'];
        }
        if ($maxDd === null) {
            return ['outcome_incomplete', 'max_drawdown_missing'];
        }
        if ($maxDd <= (float)$cfg['bad_drawdown_roi_threshold'] && $closeRoi < (float)$cfg['good_close_roi_threshold'] && ($maxProfit === null || $maxProfit < (float)$cfg['good_max_profit_roi_threshold'])) {
            return ['bad_entry', 'deep_drawdown_and_bad_close'];
        }
        if ($closeRoi >= (float)$cfg['neutral_close_roi_min'] && $closeRoi <= (float)$cfg['neutral_close_roi_max']) {
            return ['neutral', 'close_roi_neutral_range'];
        }
        return ['neutral', 'no_strong_signal'];
    }

    /** @return array{all:list<array<string,mixed>>,bad_patterns:list<array<string,mixed>>} */
    private function minePatterns(array $cfg, array $outcomes): array
    {
        $defs = [
            ['id' => 'context_phase_chaotic', 'label' => 'context_phase = chaotic', 'f' => 'context_phase', 'op' => 'eq', 'v' => 'chaotic'],
            ['id' => 'context_quality_bad', 'label' => 'context_quality = bad', 'f' => 'context_quality', 'op' => 'eq', 'v' => 'bad'],
            ['id' => 'wave_regime_fast_flip_chop', 'label' => 'wave_regime = fast_flip_chop', 'f' => 'wave_regime', 'op' => 'eq', 'v' => 'fast_flip_chop'],
            ['id' => 'ask_wall_risk_high', 'label' => 'ask_wall_risk = high', 'f' => 'ask_wall_risk', 'op' => 'eq', 'v' => 'high'],
            ['id' => 'bid_support_quality_weak_or_none', 'label' => 'bid_support_quality weak/none', 'f' => 'bid_support_quality', 'op' => 'in', 'v' => ['weak', 'none']],
            ['id' => 'oi_not_confirmed', 'label' => 'open_interest_confirmed = false', 'f' => 'open_interest_confirmed', 'op' => 'eq', 'v' => false],
            ['id' => 'single_candle_dominance_ge_65', 'label' => 'single_candle_dominance >= 65', 'f' => 'smooth_growth_single_candle_dominance_pct', 'op' => 'gte', 'v' => 65],
            ['id' => 'combo_bad_context_high_ask_wall', 'label' => 'context_quality bad + ask_wall_risk high', 'combo' => [['f' => 'context_quality', 'op' => 'eq', 'v' => 'bad'], ['f' => 'ask_wall_risk', 'op' => 'eq', 'v' => 'high']]],
            ['id' => 'combo_fast_flip_weak_bid', 'label' => 'wave fast_flip_chop + bid weak/none', 'combo' => [['f' => 'wave_regime', 'op' => 'eq', 'v' => 'fast_flip_chop'], ['f' => 'bid_support_quality', 'op' => 'in', 'v' => ['weak', 'none']]]],
        ];
        $rows = [];
        foreach ($defs as $d) {
            $bad = 0;
            $good = 0;
            $neutral = 0;
            $badClose = [];
            $badDd = [];
            $goodClose = [];
            foreach ($outcomes as $o) {
                $f = (array)($o['entry_snapshot']['entry_features'] ?? []);
                if ($f === []) {
                    $f = $this->extractEntryFeatures((array)($o['entry_snapshot']['strategy_signal_context'] ?? []));
                }
                $match = isset($d['combo']) ? $this->matchCombo($f, (array)$d['combo']) : $this->cond($f[$d['f']] ?? null, (string)$d['op'], $d['v']);
                if (!$match) {
                    continue;
                }
                $c = (string)($o['outcome_class'] ?? '');
                if ($c === 'bad_entry') {
                    $bad++;
                    if (is_numeric($o['close_roi'] ?? null)) {
                        $badClose[] = (float)$o['close_roi'];
                    }
                    if (is_numeric($o['max_drawdown_roi'] ?? null)) {
                        $badDd[] = (float)$o['max_drawdown_roi'];
                    }
                } elseif ($c === 'good_or_do_not_touch') {
                    $good++;
                    if (is_numeric($o['close_roi'] ?? null)) {
                        $goodClose[] = (float)$o['close_roi'];
                    }
                } elseif ($c === 'neutral') {
                    $neutral++;
                }
            }
            if (($bad + $good + $neutral) === 0) {
                continue;
            }
            $confidence = ($bad >= 5 && $good === 0) ? 'high' : (($bad >= 3 && $bad > $good) ? 'medium' : 'low');
            $avgBadDd = $this->avg($badDd);
            $goodOverlapHigh = $good > 0 && $good >= $bad;
            $suggested = 'observe_only';
            if ($bad >= 2 && $bad > $good && !$goodOverlapHigh && $avgBadDd !== null && $avgBadDd <= (float)$cfg['bad_drawdown_roi_threshold']) {
                $suggested = $confidence === 'high' ? 'candidate_hard_block_demo' : 'candidate_soft_block';
            }
            $rows[] = [
                'pattern_id' => $d['id'],
                'pattern_label' => $d['label'],
                'bad_count' => $bad,
                'good_count' => $good,
                'neutral_count' => $neutral,
                'bad_share' => round($bad / max(1, $bad + $good + $neutral), 4),
                'good_overlap_count' => $good,
                'avg_bad_close_roi' => $this->avg($badClose),
                'avg_bad_drawdown_roi' => $avgBadDd,
                'avg_good_close_roi' => $this->avg($goodClose),
                'confidence' => $confidence,
                'suggested_action' => $suggested,
                'good_overlap_note' => $goodOverlapHigh ? 'do_not_use_for_hard_block' : '',
                'conditions' => isset($d['combo']) ? array_values($d['combo']) : [['field' => $d['f'], 'op' => $d['op'], 'value' => $d['v']]],
            ];
        }
        usort($rows, static fn(array $a, array $b): int => (int)$b['bad_count'] <=> (int)$a['bad_count']);
        return ['all' => $rows, 'bad_patterns' => array_values(array_filter($rows, static fn(array $r): bool => (int)$r['bad_count'] > 0))];
    }

    /** @return array<string,mixed> */
    private function buildProfile(array $cfg, array $outcomes, array $patterns): array
    {
        $profileId = 'dl_eigl_' . gmdate('Ymd_His');
        $rules = [];
        $quarantined = [];
        foreach ($patterns as $p) {
            if ((int)($p['bad_count'] ?? 0) < (int)$cfg['min_bad_entries_for_rule']) {
                continue;
            }
            $self = $this->selfTest($p, $outcomes);
            $pass = $self['bad_blocked_total'] >= (int)$cfg['min_bad_blocked_for_rule']
                && $self['bad_blocked_total'] > $self['good_blocked_total']
                && $self['good_blocked_total'] <= (int)$cfg['max_good_blocked_for_rule']
                && $self['net_score'] > (float)$cfg['min_rule_net_score'];
            $rule = [
                'rule_id' => 'rule_' . substr(sha1((string)$p['pattern_id']), 0, 12),
                'source_pattern' => $p['pattern_id'],
                'conditions' => $p['conditions'],
                'action' => 'observe_only',
                'scope' => 'demo_only',
                'confidence' => $p['confidence'],
                'bad_count' => $p['bad_count'],
                'good_count' => $p['good_count'],
                'expected_bad_blocked' => $self['bad_blocked_total'],
                'expected_good_blocked' => $self['good_blocked_total'],
                'self_test_result' => $self,
            ];
            if ($pass) {
                $rules[] = $rule + ['status' => 'candidate'];
            } else {
                $quarantined[] = $rule + ['status' => 'quarantined', 'quarantine_reason' => ($self['good_blocked_total'] > (int)$cfg['max_good_blocked_for_rule'] ? 'too_much_good_overlap' : 'no_improvement')];
            }
        }
        $profile = [
            'profile_id' => $profileId,
            'strategy_id' => self::STRATEGY_ID,
            'created_at' => date('c'),
            'source_window' => ['closed_outcomes_total' => count($outcomes)],
            'trades_total' => count($outcomes),
            'bad_entries_total' => count(array_filter($outcomes, static fn(array $o): bool => (string)($o['outcome_class'] ?? '') === 'bad_entry')),
            'good_entries_total' => count(array_filter($outcomes, static fn(array $o): bool => (string)($o['outcome_class'] ?? '') === 'good_or_do_not_touch')),
            'rules' => $rules,
            'status' => 'candidate',
            'apply_mode' => 'observe_only',
        ];
        $this->writeJson($this->storagePath('profiles/early_impulse_growth_long/current_profile.json'), $profile);
        if ((bool)$cfg['profile_history_enabled']) {
            $this->appendNdjson($this->storagePath('profiles/early_impulse_growth_long/profile_history.ndjson'), $profile);
        }
        $this->writeJson($this->storagePath('quarantine/rejected_rules.json'), $quarantined);
        return ['profile_id' => $profileId, 'rules' => $rules, 'quarantined' => $quarantined];
    }

    /** @return array<string,mixed> */
    private function selfTest(array $pattern, array $outcomes): array
    {
        $tested = 0;
        $bad = 0;
        $good = 0;
        $neutral = 0;
        foreach ($outcomes as $o) {
            $tested++;
            $f = (array)($o['entry_snapshot']['entry_features'] ?? []);
            $ok = true;
            foreach ((array)$pattern['conditions'] as $c) {
                if (!$this->cond($f[(string)($c['field'] ?? '')] ?? null, (string)($c['op'] ?? 'eq'), $c['value'] ?? null)) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }
            $cls = (string)($o['outcome_class'] ?? '');
            if ($cls === 'bad_entry') {
                $bad++;
            } elseif ($cls === 'good_or_do_not_touch') {
                $good++;
            } elseif ($cls === 'neutral') {
                $neutral++;
            }
        }
        $net = ($bad * 1.0) - ($good * 1.5) - ($neutral * 0.25);
        return [
            'tested_trades_total' => $tested,
            'bad_blocked_total' => $bad,
            'good_blocked_total' => $good,
            'neutral_blocked_total' => $neutral,
            'bad_block_rate' => round($bad / max(1, $tested), 6),
            'good_block_rate' => round($good / max(1, $tested), 6),
            'net_score' => round($net, 6),
        ];
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

        $ssk = trim((string)($row['strategy_signal_key'] ?? ''));
        $closedAt = trim((string)($row['closed_at'] ?? $row['close_time'] ?? ''));
        if ($ssk !== '' && $closedAt !== '') {
            return 'ssk_closed|' . implode('|', [$ssk, $closedAt]);
        }

        $signalId = trim((string)($row['signal_id'] ?? ''));
        $openedAt = trim((string)($row['opened_at'] ?? $row['entry_time'] ?? ''));
        if ($signalId !== '' && $openedAt !== '' && $closedAt !== '') {
            return 'signal_time|' . implode('|', [$signalId, $openedAt, $closedAt]);
        }

        $symbol = strtoupper(trim((string)($row['symbol'] ?? '')));
        $side = strtolower(trim((string)($row['side'] ?? '')));
        if ($symbol !== '' && $side !== '' && $openedAt !== '' && $closedAt !== '') {
            return 'time|' . implode('|', [$symbol, $side, $openedAt, $closedAt]);
        }

        $entryPrice = $this->toFloat($row['entry_price'] ?? null);
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
        if (trim((string)($row['opened_at'] ?? $row['entry_time'] ?? '')) !== '') {
            $score += 1;
        }
        if (trim((string)($row['closed_at'] ?? $row['close_time'] ?? '')) !== '') {
            $score += 1;
        }
        if ($this->toFloat($row['close_roi'] ?? $row['roi'] ?? null) !== null) {
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
            'opened_at', 'entry_time',
            'closed_at', 'close_time',
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

    private function matchCombo(array $features, array $conds): bool
    {
        foreach ($conds as $c) {
            if (!$this->cond($features[(string)($c['f'] ?? '')] ?? null, (string)($c['op'] ?? 'eq'), $c['v'] ?? null)) {
                return false;
            }
        }
        return true;
    }

    private function cond(mixed $actual, string $op, mixed $value): bool
    {
        return match ($op) {
            'eq' => $actual === $value || strtolower((string)$actual) === strtolower((string)$value),
            'in' => is_array($value) && in_array(strtolower((string)$actual), array_map(static fn($v): string => strtolower((string)$v), $value), true),
            'gte' => is_numeric($actual) && is_numeric($value) && (float)$actual >= (float)$value,
            'lt' => is_numeric($actual) && is_numeric($value) && (float)$actual < (float)$value,
            default => false,
        };
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
    private function extractEntryFeatures(array $ctx): array
    {
        $coin = is_array($ctx['coin_context'] ?? null) ? (array)$ctx['coin_context'] : [];
        $wave = is_array($ctx['wave_context'] ?? null) ? (array)$ctx['wave_context'] : [];
        $ob = is_array($ctx['orderbook_context'] ?? null) ? (array)$ctx['orderbook_context'] : [];
        $f = static fn(string $k, mixed $d = null) => $ctx[$k] ?? $coin[$k] ?? $wave[$k] ?? $ob[$k] ?? $d;
        return [
            'dump_pct' => $f('dump_pct'),
            'stabilization_duration_minutes' => $f('stabilization_duration_minutes'),
            'stabilization_range_pct' => $f('stabilization_range_pct'),
            'stabilization_price_change_pct' => $f('stabilization_price_change_pct'),
            'smooth_growth_pct' => $f('smooth_growth_pct'),
            'smooth_growth_duration_minutes' => $f('smooth_growth_duration_minutes'),
            'smooth_growth_higher_close_count' => $f('smooth_growth_higher_close_count'),
            'smooth_growth_higher_low_count' => $f('smooth_growth_higher_low_count'),
            'smooth_growth_single_candle_dominance_pct' => $f('smooth_growth_single_candle_dominance_pct'),
            'open_interest_growth_pct' => $f('open_interest_growth_pct'),
            'open_interest_confirmed' => $f('open_interest_confirmed'),
            'recovery_phase' => $f('recovery_phase'),
            'entry_timing' => $f('entry_timing'),
            'late_spike_detected' => $f('late_spike_detected'),
            'extended_recovery_detected' => $f('extended_recovery_detected'),
            'context_phase' => $f('context_phase'),
            'context_quality' => $f('context_quality'),
            'context_reasons' => is_array($f('context_reasons', [])) ? $f('context_reasons', []) : [],
            'trend_1h_direction' => $f('trend_1h_direction'),
            'trend_2h_direction' => $f('trend_2h_direction'),
            'trend_4h_direction' => $f('trend_4h_direction'),
            'price_change_1h_pct' => $f('price_change_1h_pct'),
            'corridor_position_pct' => $f('corridor_position_pct'),
            'room_to_recent_high_pct' => $f('room_to_recent_high_pct'),
            'distance_from_recent_low_pct' => $f('distance_from_recent_low_pct'),
            'wave_regime' => $f('wave_regime'),
            'trend_flip_count_2h' => $f('trend_flip_count_2h'),
            'avg_time_between_flips_minutes' => $f('avg_time_between_flips_minutes'),
            'wave_amplitude_avg_pct' => $f('wave_amplitude_avg_pct'),
            'wave_noise_score' => $f('wave_noise_score'),
            'trend_persistence_score' => $f('trend_persistence_score'),
            'ask_wall_risk' => $f('ask_wall_risk'),
            'nearest_ask_wall_distance_pct' => $f('nearest_ask_wall_distance_pct'),
            'nearest_ask_wall_notional' => $f('nearest_ask_wall_notional'),
            'ask_wall_strength_score' => $f('ask_wall_strength_score'),
            'bid_support_quality' => $f('bid_support_quality'),
            'bid_support_score' => $f('bid_support_score'),
            'bid_ask_notional_ratio' => $f('bid_ask_notional_ratio'),
            'filter_results' => is_array($f('filter_results', [])) ? $f('filter_results', []) : [],
            'would_have_blocked_by_filters' => is_array($f('would_have_blocked_by_filters', [])) ? $f('would_have_blocked_by_filters', []) : [],
            'handoff_block_reason' => $f('handoff_block_reason'),
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
        $cfg['min_closed_outcomes_for_profile'] = max(1, (int)($cfg['min_closed_outcomes_for_profile'] ?? 10));
        $cfg['min_bad_entries_for_rule'] = max(1, (int)($cfg['min_bad_entries_for_rule'] ?? 2));
        $cfg['min_bad_blocked_for_rule'] = max(1, (int)($cfg['min_bad_blocked_for_rule'] ?? 2));
        $cfg['max_good_blocked_for_rule'] = max(0, (int)($cfg['max_good_blocked_for_rule'] ?? 1));
        $cfg['min_rule_net_score'] = (float)($cfg['min_rule_net_score'] ?? 1.0);
        $cfg['rollback_guard_enabled'] = (bool)($cfg['rollback_guard_enabled'] ?? true);
        $cfg['rollback_drawdown_pct'] = (float)($cfg['rollback_drawdown_pct'] ?? 7.0);
        $cfg['rollback_bad_trade_streak'] = max(1, (int)($cfg['rollback_bad_trade_streak'] ?? 3));
        $cfg['profile_history_enabled'] = (bool)($cfg['profile_history_enabled'] ?? true);
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
