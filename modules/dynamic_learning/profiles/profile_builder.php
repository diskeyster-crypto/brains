<?php

declare(strict_types=1);

namespace Modules\DynamicLearning\Profiles;

use Modules\DynamicLearning\Analyzers\DlHelpers;
use Modules\DynamicLearning\Analyzers\Outcome\OutcomeClassifier;

/**
 * Builds the dynamic learning profile from mined patterns.
 * Generates candidate rules and quarantines unsafe ones.
 * Profile apply_mode is always observe_only until explicitly promoted.
 */
final class ProfileBuilder
{
    /**
     * Build a candidate profile and write it to storage.
     *
     * @param array<string,mixed> $cfg
     * @param array<array<string,mixed>> $outcomes Pattern-mining-eligible outcomes (epoch-filtered when epoch is enabled)
     * @param array<array<string,mixed>> $patterns Mined pattern rows
     * @param callable(string):string $storagePath Function to resolve storage paths
     * @param array<string,mixed> $epochMeta Optional epoch metadata to embed in the profile
     * @return array{profile_id:string,rules:list<array<string,mixed>>,quarantined:list<array<string,mixed>>}
     */
    public static function build(array $cfg, array $outcomes, array $patterns, callable $storagePath, array $epochMeta = []): array
    {
        $profileId = 'dl_eigl_' . gmdate('Ymd_His');
        $rules = [];
        $quarantined = [];

        foreach ($patterns as $p) {
            if ((int)($p['bad_count'] ?? 0) < (int)$cfg['min_bad_entries_for_rule']) {
                continue;
            }
            $self = self::selfTest($p, $outcomes);
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
                $quarantined[] = $rule + [
                    'status' => 'quarantined',
                    'quarantine_reason' => ($self['good_blocked_total'] > (int)$cfg['max_good_blocked_for_rule']
                        ? 'too_much_good_overlap'
                        : 'no_improvement'),
                ];
            }
        }

        $profile = [
            'profile_id' => $profileId,
            'strategy_id' => 'early_impulse_growth_long',
            'created_at' => date('c'),
            'risk_profile_mode' => (string)($cfg['risk_profile_mode'] ?? ''),
            'outcome_classification_profile' => (string)($cfg['outcome_classification_profile'] ?? ''),
            'real_learning_epoch_id' => $epochMeta['real_learning_epoch_id'] ?? null,
            'real_learning_epoch_start_at' => $epochMeta['real_learning_epoch_start_at'] ?? null,
            'micro_learning_epoch_id' => $epochMeta['micro_learning_epoch_id'] ?? null,
            'micro_learning_epoch_start_at' => $epochMeta['micro_learning_epoch_start_at'] ?? null,
            'epoch_start_source' => $epochMeta['epoch_start_source'] ?? null,
            'source_window' => ['closed_outcomes_total' => count($outcomes)],
            'source_outcomes_total' => count($outcomes),
            'legacy_outcomes_total' => (int)($epochMeta['legacy_outcomes_total'] ?? 0),
            'previous_epoch_outcomes_excluded_total' => (int)($epochMeta['outcomes_excluded_by_epoch_total'] ?? 0),
            'active_epoch_outcomes_total' => (int)($epochMeta['active_epoch_outcomes_total'] ?? count($outcomes)),
            'outcomes_excluded_by_epoch_total' => (int)($epochMeta['outcomes_excluded_by_epoch_total'] ?? 0),
            'feature_records_total' => 0,
            'trades_total' => count($outcomes),
            'bad_entries_total' => count(array_filter($outcomes, static fn(array $o): bool => (string)($o['outcome_class'] ?? '') === 'bad_entry')),
            'good_entries_total' => count(array_filter($outcomes, static fn(array $o): bool => (string)($o['outcome_class'] ?? '') === 'good_or_do_not_touch')),
            'entry_ok_exit_issue_total' => count(array_filter($outcomes, static fn(array $o): bool => (string)($o['outcome_class'] ?? '') === 'entry_ok_exit_issue')),
            'neutral_total' => count(array_filter($outcomes, static fn(array $o): bool => (string)($o['outcome_class'] ?? '') === 'neutral')),
            'outcome_incomplete_total' => count(array_filter($outcomes, static fn(array $o): bool => (string)($o['outcome_class'] ?? '') === 'outcome_incomplete')),
            'rules' => $rules,
            'weights' => [
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
            ],
            'quarantined' => $quarantined,
            'status' => 'candidate',
            'apply_mode' => 'observe_only',
            'compare_auto_vs_default_enabled' => (bool)($cfg['compare_auto_vs_default_enabled'] ?? true),
            'compared_to_default' => false,
            'default_benchmark' => null,
            'auto_benchmark' => null,
            'auto_not_worse_than_default' => false,
            'auto_improvement_score' => null,
            'auto_comparison_reason' => 'comparison_not_implemented',
            'auto_apply_enabled' => (bool)($cfg['auto_apply_enabled'] ?? false),
        ];

        $profileFilePath = $storagePath('profiles/early_impulse_growth_long/current_profile.json');
        $dir = dirname($profileFilePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($json)) {
            @file_put_contents($profileFilePath, $json, LOCK_EX);
        }

        if ((bool)($cfg['profile_history_enabled'] ?? true)) {
            $historyPath = $storagePath('profiles/early_impulse_growth_long/profile_history.ndjson');
            $histDir = dirname($historyPath);
            if (!is_dir($histDir)) {
                @mkdir($histDir, 0755, true);
            }
            $historyJson = json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($historyJson)) {
                @file_put_contents($historyPath, $historyJson . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        }

        $quarantinePath = $storagePath('quarantine/rejected_rules.json');
        $quarantineDir = dirname($quarantinePath);
        if (!is_dir($quarantineDir)) {
            @mkdir($quarantineDir, 0755, true);
        }
        $qJson = json_encode($quarantined, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($qJson)) {
            @file_put_contents($quarantinePath, $qJson, LOCK_EX);
        }

        return ['profile_id' => $profileId, 'rules' => $rules, 'quarantined' => $quarantined];
    }

    /** @return array<string,mixed> */
    private static function selfTest(array $pattern, array $outcomes): array
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
                if (!DlHelpers::cond($f[(string)($c['field'] ?? '')] ?? null, (string)($c['op'] ?? 'eq'), $c['value'] ?? null)) {
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
}
