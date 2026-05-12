<?php

declare(strict_types=1);

namespace Modules\DynamicLearning\Decision;

use Modules\DynamicLearning\Analyzers\DlHelpers;
use Modules\DynamicLearning\Analyzers\Outcome\OutcomeClassifier;

/**
 * Evaluates whether a strategy signal should be passed, blocked, or observed
 * based on the current dynamic learning profile and generated rules.
 *
 * Safety contract:
 * - If apply_learning_to_live_enabled = false: always pass (with diagnostics)
 * - If no profile or no rules: always pass
 * - Blocking is only possible when apply flags are active and rules are promoted beyond observe_only
 */
final class DynamicLearningDecision
{
    private const STRATEGY_ID = 'early_impulse_growth_long';

    /**
     * Evaluate a signal packet for the given strategy.
     *
     * @param array<string,mixed> $cfg Dynamic learning config
     * @param array<string,mixed> $signalPacket Signal data from strategy
     * @param array<string,mixed>|null $profile Current profile or null
     * @return array<string,mixed>
     */
    public static function evaluate(string $strategyId, array $signalPacket, array $cfg, ?array $profile): array
    {
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
        $features = OutcomeClassifier::extractEntryFeatures($ctx);
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
                if ($field === '' || !DlHelpers::cond($features[$field] ?? null, $op, $value)) {
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
}
