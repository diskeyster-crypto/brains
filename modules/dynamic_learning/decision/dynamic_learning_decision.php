<?php

declare(strict_types=1);

namespace Modules\DynamicLearning\Decision;

use Modules\DynamicLearning\Analyzers\DlHelpers;
use Modules\DynamicLearning\Analyzers\Outcome\OutcomeClassifier;

final class DynamicLearningDecision
{
    private const STRATEGY_ID = 'early_impulse_growth_long';

    /**
     * @param array<string,mixed> $cfg
     * @param array<string,mixed>|null $selectedProfile
     * @param array<string,mixed> $candidateReplay
     * @param array<string,mixed> $lastRun
     * @param array<string,mixed> $selection
     * @return array<string,mixed>
     */
    public static function evaluate(
        string $strategyId,
        array $signalPacket,
        array $cfg,
        ?array $selectedProfile,
        array $candidateReplay = [],
        array $lastRun = [],
        array $selection = []
    ): array {
        $executionMode = (string)($cfg['dynamic_learning_execution_mode'] ?? 'observe');
        $blockThreshold = (float)($cfg['replay_risk_threshold_block_candidate'] ?? 60.0);
        $riskThreshold = (float)($cfg['replay_demo_only_threshold_candidate'] ?? 30.0);

        $response = [
            'enabled' => (bool)($cfg['enabled'] ?? false),
            'decision' => 'pass',
            'mode' => (string)($cfg['mode'] ?? 'diagnostic_only'),
            'execution_mode' => $executionMode,
            'apply_scope' => 'demo_only',
            'candidate_profile_id' => $selection['selected_candidate_profile_id'] ?? null,
            'candidate_status' => (string)($lastRun['final_candidate_status'] ?? $lastRun['candidate_status'] ?? ($selection['candidate_status'] ?? 'pending')),
            'candidate_manual_demo_gate_eligible' => (bool)($lastRun['final_candidate_manual_demo_gate_eligible'] ?? $lastRun['candidate_manual_demo_gate_eligible'] ?? ($selectedProfile['candidate_manual_demo_gate_eligible'] ?? false)),
            'candidate_auto_demo_eligible' => (bool)($lastRun['final_candidate_auto_demo_eligible'] ?? $lastRun['candidate_auto_demo_eligible'] ?? ($selectedProfile['candidate_auto_demo_eligible'] ?? false)),
            'candidate_live_eligible' => false,
            'selected_candidate_profile_id' => $selection['selected_candidate_profile_id'] ?? null,
            'selected_candidate_locked' => (bool)($selection['selected_candidate_locked'] ?? true),
            'selected_candidate_source' => $selection['selected_candidate_source'] ?? null,
            'matched_rules' => [],
            'reason' => 'dynamic_learning_pass',
            'confidence' => 'low',
            'profile_status' => $selectedProfile['status'] ?? null,
            'apply_learning_to_live_enabled' => (bool)($cfg['apply_learning_to_live_enabled'] ?? false),
            'risk_score_raw' => 0.0,
            'risk_score_max' => 0.0,
            'risk_score_pct' => 0.0,
            'risk_threshold_pct' => $riskThreshold,
            'threshold_used' => $blockThreshold,
            'final_candidate_eligible_for_demo_apply' => (bool)($lastRun['final_candidate_eligible_for_demo_apply'] ?? $lastRun['candidate_eligible_for_demo_apply'] ?? false),
            'allow_manual_demo_gate_with_insufficient_data' => (bool)($cfg['allow_manual_demo_gate_with_insufficient_data'] ?? true),
            'manual_gate_override_applied' => false,
            'manual_gate_reason' => null,
            'selected_candidate_exists' => is_array($selectedProfile) && $selectedProfile !== [],
            'selected_candidate_rules_total' => 0,
        ];

        if (strtolower(trim($strategyId)) !== self::STRATEGY_ID) {
            $response['reason'] = 'unsupported_strategy';
            return $response;
        }
        if (!($cfg['enabled'] ?? false)) {
            $response['reason'] = 'module_disabled';
            return $response;
        }
        if ($executionMode === 'off') {
            $response['reason'] = 'execution_mode_off';
            return $response;
        }
        if ($signalPacket === []) {
            $response['reason'] = 'signal_packet_missing';
            return $response;
        }

        $ctx = is_array($signalPacket['strategy_signal_context'] ?? null) ? (array)$signalPacket['strategy_signal_context'] : [];
        $features = OutcomeClassifier::extractEntryFeatures($ctx);
        foreach ($ctx as $key => $value) {
            if (!array_key_exists((string)$key, $features)) {
                $features[(string)$key] = $value;
            }
        }
        if ($features === []) {
            $response['reason'] = $executionMode === 'observe' ? 'observe_only_entry_features_missing' : 'entry_features_missing';
            $response['decision'] = $executionMode === 'observe' ? 'observe_only' : 'pass';
            return $response;
        }

        if (!is_array($selectedProfile) || $selectedProfile === []) {
            if ($executionMode === 'observe') {
                $response['decision'] = 'observe_only';
                $response['reason'] = 'observe_only_no_selected_candidate_profile';
            } else {
                $response['decision'] = 'no_candidate';
                $response['reason'] = trim((string)($selection['selected_candidate_profile_id'] ?? '')) === ''
                    ? 'no_selected_candidate_profile'
                    : 'selected_candidate_profile_not_found';
            }
            return $response;
        }

        $response['candidate_profile_id'] = $selectedProfile['profile_id'] ?? ($selection['selected_candidate_profile_id'] ?? null);
        $response['profile_status'] = (string)($selectedProfile['status'] ?? '');
        $response['candidate_status'] = (string)($lastRun['final_candidate_status']
            ?? $lastRun['candidate_status']
            ?? ($selectedProfile['candidate_status'] ?? $selectedProfile['status'] ?? 'pending'));

        $rules = array_values(array_filter((array)($selectedProfile['rules'] ?? []), static fn(mixed $rule): bool => is_array($rule)));
        $response['selected_candidate_rules_total'] = count($rules);
        if ($rules === []) {
            if ($executionMode === 'observe') {
                $response['decision'] = 'observe_only';
                $response['reason'] = 'observe_only_no_profile_rules';
            } else {
                $response['decision'] = 'no_candidate';
                $response['reason'] = 'no_profile_rules';
            }
            return $response;
        }

        $riskDetails = self::computeRiskScoreDetails($features, $rules);
        $response['risk_score_raw'] = (float)($riskDetails['risk_score_raw'] ?? 0.0);
        $response['risk_score_max'] = (float)($riskDetails['risk_score_max'] ?? 0.0);
        $response['risk_score_pct'] = (float)($riskDetails['risk_score_pct'] ?? 0.0);
        $response['matched_rules'] = (array)($riskDetails['matched_rules'] ?? []);

        if ($executionMode === 'observe') {
            $response['decision'] = 'observe_only';
            $response['reason'] = 'observe_mode';
            return $response;
        }

        $signalMode = strtolower(trim((string)($signalPacket['mode'] ?? 'demo')));
        if ($signalMode === 'live') {
            $response['reason'] = 'live_scope_disabled';
            return $response;
        }
        if (!($cfg['manual_gate_demo_enabled'] ?? false)) {
            $response['reason'] = 'manual_gate_demo_disabled';
            return $response;
        }
        if (!($cfg['apply_learning_to_strategy_enabled'] ?? false)) {
            $response['reason'] = 'apply_learning_to_strategy_disabled';
            return $response;
        }
        if ((bool)($cfg['apply_learning_to_live_enabled'] ?? false) || (bool)($cfg['auto_apply_to_live_enabled'] ?? false)) {
            $response['reason'] = 'live_apply_safety_violation';
            return $response;
        }

        $allowOverride = (bool)($cfg['allow_manual_demo_gate_with_insufficient_data'] ?? true);
        $requiresUserSelection = (bool)($cfg['manual_demo_gate_requires_user_selection'] ?? true);
        $selectedId = trim((string)($selection['selected_candidate_profile_id'] ?? ''));
        $overrideApplied = false;

        if ($allowOverride && (!$requiresUserSelection || $selectedId !== '')) {
            // User explicitly configured manual demo gate with insufficient data allowed.
            // Rules already confirmed non-empty and selectedProfile non-null above.
            $response['candidate_manual_demo_gate_eligible'] = true;
            $response['manual_gate_override_applied'] = true;
            $response['manual_gate_reason'] = 'user_selected_demo_test_candidate';
            $overrideApplied = true;
        } else {
            $allowedStatuses = ['eligible_for_manual_demo_gate', 'eligible_for_demo_apply', 'eligible_for_auto_demo'];
            if (!in_array($response['candidate_status'], $allowedStatuses, true)) {
                $response['decision'] = 'no_candidate';
                $response['reason'] = 'candidate_status_not_eligible';
                return $response;
            }

            $manualEligible = (bool)$response['candidate_manual_demo_gate_eligible'];
            $finalDemoEligible = (bool)$response['final_candidate_eligible_for_demo_apply'];
            if (!$manualEligible && !$finalDemoEligible) {
                $response['decision'] = 'no_candidate';
                $response['reason'] = 'candidate_not_demo_eligible';
                return $response;
            }
        }

        if ($candidateReplay === []) {
            $response['decision'] = 'no_candidate';
            $response['reason'] = 'candidate_replay_missing';
            return $response;
        }

        if (!$overrideApplied) {
            $replayStatus = (string)($candidateReplay['replay_candidate_status'] ?? '');
            if (in_array($replayStatus, ['rejected_on_replay', 'insufficient_replay_data'], true)) {
                $response['decision'] = 'no_candidate';
                $response['reason'] = 'candidate_replay_failed_safety_guard';
                return $response;
            }
        }

        if ((float)$response['risk_score_pct'] >= $blockThreshold) {
            $response['decision'] = 'block_demo';
            $response['reason'] = 'risk_score_above_block_threshold';
            $response['confidence'] = 'high';
            return $response;
        }

        $response['decision'] = 'pass';
        $response['reason'] = 'risk_score_below_block_threshold';
        return $response;
    }

    /**
     * @param array<string,mixed> $features
     * @param list<array<string,mixed>> $rules
     * @return array{risk_score_raw:float,risk_score_max:float,risk_score_pct:float,matched_rules:list<array<string,mixed>>}
     */
    private static function computeRiskScoreDetails(array $features, array $rules): array
    {
        $rawScore = 0.0;
        $maxScore = 0.0;
        $matchedRules = [];

        foreach ($rules as $rule) {
            $weight = (float)($rule['weight'] ?? 10.0);
            $maxScore += $weight;

            if (isset($rule['feature'], $rule['threshold'])) {
                $feature = (string)$rule['feature'];
                $threshold = $rule['threshold'];
                $op = strtolower(trim((string)($rule['op'] ?? 'gte')));
                $value = self::getFeatureDotPath($features, $feature);
                if ($feature === '' || $value === null || !is_numeric($value)) {
                    continue;
                }
                $matches = $op === 'lte'
                    ? (float)$value <= (float)$threshold
                    : (float)$value >= (float)$threshold;
                if (!$matches) {
                    continue;
                }
                $rawScore += $weight;
                $matchedRules[] = [
                    'rule_id' => $rule['rule_id'] ?? null,
                    'feature' => $feature,
                    'op' => $op,
                    'threshold' => (float)$threshold,
                    'value' => (float)$value,
                    'weight' => $weight,
                ];
                continue;
            }

            $conditions = array_values(array_filter((array)($rule['conditions'] ?? []), static fn(mixed $cond): bool => is_array($cond)));
            if ($conditions === []) {
                continue;
            }

            $allMatched = true;
            foreach ($conditions as $condition) {
                $field = (string)($condition['field'] ?? $condition['f'] ?? '');
                $op = (string)($condition['op'] ?? 'eq');
                $value = $condition['value'] ?? $condition['v'] ?? null;
                if ($field === '' || !DlHelpers::cond(self::getFeatureDotPath($features, $field), $op, $value)) {
                    $allMatched = false;
                    break;
                }
            }
            if (!$allMatched) {
                continue;
            }

            $rawScore += $weight;
            $matchedRules[] = [
                'rule_id' => $rule['rule_id'] ?? null,
                'feature' => 'compound_conditions',
                'op' => 'match_all',
                'threshold' => null,
                'value' => null,
                'weight' => $weight,
            ];
        }

        $scorePct = $maxScore > 0.0 ? ($rawScore / $maxScore) * 100.0 : 0.0;
        return [
            'risk_score_raw' => round($rawScore, 4),
            'risk_score_max' => round($maxScore, 4),
            'risk_score_pct' => round($scorePct, 4),
            'matched_rules' => $matchedRules,
        ];
    }

    private static function getFeatureDotPath(array $features, string $path): mixed
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        if (array_key_exists($path, $features)) {
            return $features[$path];
        }

        $segments = explode('.', $path);
        $cursor = $features;
        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
