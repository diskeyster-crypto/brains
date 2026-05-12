<?php

declare(strict_types=1);

namespace Modules\FilterEngine\Filters;

use Modules\FilterEngine\FilterResult;

final class DynamicLearningFilter
{
    public function id(): string { return 'dynamic_learning_filter'; }

    public function metadata(): array
    {
        return [
            'filter_id' => $this->id(),
            'title' => 'Dynamic learning filter',
            'description' => 'Reads dynamic-learning profile rules and evaluates signal context.',
            'default_severity' => 'soft_block',
            'configurable_fields' => [
                ['key' => 'allow_missing_profile', 'type' => 'bool', 'default' => true, 'label' => 'Allow missing profile'],
                ['key' => 'min_rule_confidence', 'type' => 'select', 'default' => 'low', 'allowed_values' => ['low', 'medium', 'high'], 'label' => 'Min rule confidence'],
                ['key' => 'max_rules_to_check', 'type' => 'int', 'default' => 100, 'min' => 1, 'max' => 500, 'label' => 'Max rules to check'],
            ],
        ];
    }

    public function evaluate(array $signalContext, array $filterConfig): FilterResult
    {
        $enabled = (bool)($filterConfig['enabled'] ?? false);
        if (!$enabled) {
            return new FilterResult($this->id(), false, true);
        }

        $strategyId = strtolower(trim((string)($signalContext['strategy_id'] ?? '')));
        if ($strategyId === '') {
            $ctx = is_array($signalContext['strategy_signal_context'] ?? null) ? (array)$signalContext['strategy_signal_context'] : [];
            $strategyId = strtolower(trim((string)($ctx['strategy_id'] ?? '')));
        }
        if ($strategyId !== 'early_impulse_growth_long') {
            return new FilterResult($this->id(), true, true, 'warning', 'strategy_mismatch', ['strategy_id' => $strategyId !== '' ? $strategyId : null]);
        }

        $repoRoot = dirname(__DIR__, 3);
        $moduleDir = $repoRoot . '/modules/dynamic_learning';
        $cfg = $this->loadConfig($moduleDir);
        if (!(bool)($cfg['apply_learning_to_strategy_enabled'] ?? false)) {
            return new FilterResult($this->id(), true, true, 'warning', 'apply_disabled');
        }

        $profilePath = $moduleDir . '/storage/profiles/early_impulse_growth_long/current_profile.json';
        $allowMissing = (bool)($filterConfig['allow_missing_profile'] ?? true);
        if (!is_file($profilePath)) {
            return $allowMissing
                ? new FilterResult($this->id(), true, true, 'warning', 'profile_missing', ['profile_path' => $profilePath])
                : new FilterResult($this->id(), true, false, (string)($filterConfig['severity'] ?? 'soft_block'), 'profile_missing', [], true);
        }

        $raw = @file_get_contents($profilePath);
        $profile = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($profile)) {
            return new FilterResult($this->id(), true, true, 'warning', 'profile_invalid');
        }
        if (strtolower((string)($profile['strategy_id'] ?? '')) !== 'early_impulse_growth_long') {
            return new FilterResult($this->id(), true, true, 'warning', 'profile_strategy_mismatch');
        }
        $status = strtolower((string)($profile['status'] ?? 'candidate'));
        if (!in_array($status, ['approved', 'active', 'candidate'], true)) {
            return new FilterResult($this->id(), true, true, 'warning', 'profile_not_active', ['profile_status' => $status, 'profile_id' => $profile['profile_id'] ?? null]);
        }

        $rules = is_array($profile['rules'] ?? null) ? (array)$profile['rules'] : [];
        $maxRules = max(1, (int)($filterConfig['max_rules_to_check'] ?? 100));
        $minConfidence = (string)($filterConfig['min_rule_confidence'] ?? 'low');
        $confidenceRank = ['low' => 1, 'medium' => 2, 'high' => 3];
        $minRank = $confidenceRank[$minConfidence] ?? 1;

        $matchedRules = [];
        $wouldBlock = false;
        $failed = false;
        $severity = (string)($filterConfig['severity'] ?? 'soft_block');
        $checked = 0;

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $checked++;
            if ($checked > $maxRules) {
                break;
            }
            $ruleConfidence = (string)($rule['confidence'] ?? 'low');
            if (($confidenceRank[$ruleConfidence] ?? 1) < $minRank) {
                continue;
            }
            if (!$this->ruleMatches($rule, $signalContext)) {
                continue;
            }
            $matchedRules[] = [
                'rule_id' => $rule['rule_id'] ?? null,
                'source_pattern' => $rule['source_pattern'] ?? null,
                'action' => $rule['action'] ?? 'observe_only',
                'confidence' => $ruleConfidence,
            ];
            $action = (string)($rule['action'] ?? 'observe_only');
            if ($action !== 'observe_only') {
                $wouldBlock = true;
                $failed = true;
                if (str_contains($action, 'hard')) {
                    $severity = 'hard_block';
                }
            }
        }

        return new FilterResult(
            $this->id(),
            true,
            !$failed,
            $severity,
            $failed ? 'dynamic_profile_rule_match' : 'no_blocking_rule_match',
            [
                'matched_rules' => $matchedRules,
                'profile_id' => $profile['profile_id'] ?? null,
                'profile_status' => $status,
            ],
            $wouldBlock
        );
    }

    private function ruleMatches(array $rule, array $signalContext): bool
    {
        $features = is_array($signalContext['strategy_signal_context'] ?? null) ? (array)$signalContext['strategy_signal_context'] : [];
        $features = array_merge($features, $signalContext);
        foreach ((array)($rule['conditions'] ?? []) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $field = (string)($c['field'] ?? $c['f'] ?? '');
            $op = (string)($c['op'] ?? 'eq');
            $value = $c['value'] ?? ($c['v'] ?? null);
            if (!$this->conditionPasses($features[$field] ?? null, $op, $value)) {
                return false;
            }
        }
        return true;
    }

    private function conditionPasses(mixed $actual, string $op, mixed $value): bool
    {
        return match ($op) {
            'eq' => $actual === $value || strtolower((string)$actual) === strtolower((string)$value),
            'in' => is_array($value) && in_array(strtolower((string)$actual), array_map(static fn($v): string => strtolower((string)$v), $value), true),
            'contains' => (is_array($actual) && in_array((string)$value, array_map('strval', $actual), true)) || (is_string($actual) && str_contains(strtolower($actual), strtolower((string)$value))),
            'gte' => is_numeric($actual) && is_numeric($value) && (float)$actual >= (float)$value,
            'gt' => is_numeric($actual) && is_numeric($value) && (float)$actual > (float)$value,
            'lte' => is_numeric($actual) && is_numeric($value) && (float)$actual <= (float)$value,
            'lt' => is_numeric($actual) && is_numeric($value) && (float)$actual < (float)$value,
            default => false,
        };
    }

    /** @return array<string,mixed> */
    private function loadConfig(string $moduleDir): array
    {
        $base = is_file($moduleDir . '/config/base.php') ? require $moduleDir . '/config/base.php' : [];
        $active = is_file($moduleDir . '/config/active.php') ? require $moduleDir . '/config/active.php' : [];
        return array_merge(is_array($base) ? $base : [], is_array($active) ? $active : []);
    }
}
