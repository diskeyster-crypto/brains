<?php

declare(strict_types=1);

use Core\Auth\Auth;
use Core\System\System;

if (!Auth::check()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$moduleDir = dirname(__DIR__);
require_once $moduleDir . '/service.php';
$svc = \Modules\DynamicLearning\DynamicLearningService::instance($moduleDir);
$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));
$activePath = $moduleDir . '/config/active.php';

$jsonOut = static function (bool $ok, array $data = [], string $error = ''): never {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => $ok], $data, $ok ? [] : ['error' => $error]));
    exit;
};

$loadActive = static function () use ($activePath): array {
    return is_file($activePath) ? ((array)require $activePath) : [];
};
$saveActive = static function (array $active) use ($activePath): void {
    $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($active, true) . ";\n";
    if (@file_put_contents($activePath, $php, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write active config');
    }
};
$getLatestCandidate = static function () use ($moduleDir): array {
    $candidatePath = $moduleDir . '/storage/profiles/early_impulse_growth_long/candidate_profile.json';
    if (!is_file($candidatePath)) {
        return ['exists' => false, 'profile' => [], 'profile_id' => '', 'rules_total' => 0];
    }
    $rawCand = @file_get_contents($candidatePath);
    $candData = is_string($rawCand) ? json_decode($rawCand, true) : null;
    $profileId = trim((string)(is_array($candData) ? ($candData['profile_id'] ?? '') : ''));
    $rulesTotal = is_array($candData) ? count(array_values(array_filter((array)($candData['rules'] ?? []), static fn(mixed $r): bool => is_array($r)))) : 0;
    return [
        'exists' => $profileId !== '',
        'profile' => is_array($candData) ? $candData : [],
        'profile_id' => $profileId,
        'rules_total' => $rulesTotal,
    ];
};
$candidateReplayExists = static function () use ($moduleDir): bool {
    return is_file($moduleDir . '/storage/profiles/early_impulse_growth_long/candidate_replay.json');
};
$readiness = static function (array $active, array $latestCandidate, bool $replayExists): array {
    $selectedId = trim((string)($active['selected_candidate_profile_id'] ?? ''));
    $selectedExists = $latestCandidate['exists'] && $selectedId !== '' && $selectedId === (string)$latestCandidate['profile_id'];
    $selectedRulesTotal = $selectedExists ? (int)$latestCandidate['rules_total'] : 0;
    $reasons = [];
    if ((string)($active['dynamic_learning_execution_mode'] ?? 'observe') !== 'gate_demo') {
        $reasons[] = 'execution mode is not gate_demo';
    }
    if (!((bool)($active['manual_gate_demo_enabled'] ?? false))) {
        $reasons[] = 'manual_gate_demo_enabled is false';
    }
    if (!((bool)($active['apply_learning_to_strategy_enabled'] ?? false))) {
        $reasons[] = 'apply_learning_to_strategy_enabled is false';
    }
    if ($selectedId === '') {
        $reasons[] = 'selected_candidate_profile_id is empty';
    }
    if (!$selectedExists) {
        $reasons[] = 'selected candidate profile does not exist';
    }
    if ($selectedRulesTotal <= 0) {
        $reasons[] = 'selected candidate rules_total is 0';
    }
    if (!$replayExists) {
        $reasons[] = 'candidate_replay.json is missing';
    }
    if ((bool)($active['apply_learning_to_live_enabled'] ?? false)) {
        $reasons[] = 'apply_learning_to_live_enabled must be false';
    }
    if ((bool)($active['auto_apply_to_demo_enabled'] ?? false)) {
        $reasons[] = 'auto_apply_to_demo_enabled must be false';
    }
    if ((bool)($active['auto_apply_to_live_enabled'] ?? false)) {
        $reasons[] = 'auto_apply_to_live_enabled must be false';
    }
    return [
        'selected_candidate_profile_id' => $selectedId,
        'selected_candidate_exists' => $selectedExists,
        'selected_candidate_rules_total' => $selectedRulesTotal,
        'gate_demo_ready' => $reasons === [],
        'gate_demo_not_ready_reason' => $reasons === [] ? null : implode('; ', $reasons),
        'warnings' => $reasons,
    ];
};

if ($action === 'use_latest_candidate' || $action === 'use_latest_candidate_for_demo_gate') {
    $latest = $getLatestCandidate();
    if (!$latest['exists']) {
        $jsonOut(false, [], 'Latest candidate profile is missing or has empty profile_id');
    }
    try {
        $active = $loadActive();
        $active['selected_candidate_profile_id'] = (string)$latest['profile_id'];
        $active['selected_candidate_source'] = 'candidate_profile';
        $active['selected_candidate_locked'] = true;
        $active['apply_learning_to_live_enabled'] = false;
        $active['auto_apply_to_live_enabled'] = false;
        $saveActive($active);
        $ready = $readiness($active, $latest, $candidateReplayExists());
        $jsonOut(true, [
            'selected_candidate_profile_id' => (string)$latest['profile_id'],
            'selected_candidate_source' => 'candidate_profile',
            'gate_demo_ready_after_save' => (bool)$ready['gate_demo_ready'],
            'warnings' => (array)$ready['warnings'],
        ]);
    } catch (\Throwable $e) {
        $jsonOut(false, [], $e->getMessage());
    }
}

if ($action === 'enable_manual_demo_gate_latest') {
    $latest = $getLatestCandidate();
    if (!$latest['exists']) {
        $jsonOut(false, [], 'Latest candidate profile is missing or has empty profile_id');
    }
    try {
        $active = $loadActive();
        $active['dynamic_learning_execution_mode'] = 'gate_demo';
        $active['mode'] = 'gate_demo';
        $active['manual_gate_demo_enabled'] = true;
        $active['apply_learning_to_demo_enabled'] = true;
        $active['apply_learning_to_strategy_enabled'] = true;
        $active['selected_candidate_profile_id'] = (string)$latest['profile_id'];
        $active['selected_candidate_source'] = 'candidate_profile';
        $active['selected_candidate_locked'] = true;
        $active['allow_manual_demo_gate_with_insufficient_data'] = true;
        $active['manual_demo_gate_requires_user_selection'] = true;
        $active['apply_learning_to_live_enabled'] = false;
        $active['auto_apply_to_demo_enabled'] = false;
        $active['auto_apply_to_live_enabled'] = false;
        $saveActive($active);
        $ready = $readiness($active, $latest, $candidateReplayExists());
        $jsonOut(true, [
            'selected_candidate_profile_id' => (string)$latest['profile_id'],
            'selected_candidate_source' => 'candidate_profile',
            'gate_demo_ready_after_save' => (bool)$ready['gate_demo_ready'],
            'warnings' => (array)$ready['warnings'],
        ]);
    } catch (\Throwable $e) {
        $jsonOut(false, [], $e->getMessage());
    }
}

if ($action === 'disable_dynamic_learning_gate') {
    try {
        $active = $loadActive();
        $active['dynamic_learning_execution_mode'] = 'observe';
        $active['mode'] = 'observe';
        $active['manual_gate_demo_enabled'] = false;
        $active['apply_learning_to_demo_enabled'] = false;
        $active['apply_learning_to_strategy_enabled'] = false;
        $active['apply_learning_to_live_enabled'] = false;
        $active['auto_apply_to_demo_enabled'] = false;
        $active['auto_apply_to_live_enabled'] = false;
        $saveActive($active);
        $latest = $getLatestCandidate();
        $ready = $readiness($active, $latest, $candidateReplayExists());
        $jsonOut(true, [
            'selected_candidate_profile_id' => (string)($active['selected_candidate_profile_id'] ?? ''),
            'selected_candidate_source' => (string)($active['selected_candidate_source'] ?? ''),
            'gate_demo_ready_after_save' => (bool)$ready['gate_demo_ready'],
            'warnings' => (array)$ready['warnings'],
        ]);
    } catch (\Throwable $e) {
        $jsonOut(false, [], $e->getMessage());
    }
}

if ($action === 'run_cycle') {
    try {
        $res = $svc->runCycle();
        $jsonOut(true, ['result' => $res]);
    } catch (\Throwable $e) {
        $jsonOut(false, [], $e->getMessage());
    }
}

if ($action === 'save_config') {
    $cfg = $svc->getConfig();
    $keys = [
        'enabled', 'mode', 'supported_strategy_id',
        'collect_entry_snapshots_enabled', 'observe_active_positions_enabled', 'analyze_closed_outcomes_enabled', 'build_dynamic_profile_enabled',
        'apply_learning_to_strategy_enabled', 'apply_learning_to_live_enabled', 'apply_learning_to_demo_enabled',
        'observation_interval_seconds', 'max_observations_per_position',
        'risk_profile_mode', 'outcome_classification_profile',
        'bad_drawdown_roi_threshold', 'hard_stop_reference_roi', 'good_close_roi_threshold', 'good_max_profit_roi_threshold', 'stop_slippage_buffer_roi', 'neutral_close_roi_min', 'neutral_close_roi_max',
        'min_closed_outcomes_for_profile', 'min_bad_entries_for_rule', 'min_bad_blocked_for_rule', 'max_good_blocked_for_rule', 'min_rule_net_score',
        'rollback_guard_enabled', 'rollback_drawdown_pct', 'rollback_bad_trade_streak', 'profile_history_enabled',
        // Rolling learning guard
        'rolling_learning_enabled', 'rolling_learning_window_minutes', 'rolling_retrain_interval_minutes',
        'rolling_min_closed_outcomes', 'rolling_min_bad_entries', 'rolling_min_good_entries',
        // Quality guard thresholds
        'min_candidate_improvement_pct', 'no_change_band_pct',
        'max_allowed_quality_degradation_pct', 'max_allowed_winrate_degradation_pct',
        'max_allowed_avg_roi_degradation_pct', 'max_allowed_bad_entry_rate_increase_pct',
        'max_allowed_drawdown_increase_pct',
        // Quality score weights
        'quality_weight_good_capture', 'quality_weight_avg_roi', 'quality_weight_bad_entry',
        'quality_weight_drawdown', 'quality_weight_entry_ok_exit_issue',
        // Rollback guard
        'rollback_cooldown_minutes', 'rollback_to',
        // Apply guard
        'auto_apply_to_demo_enabled', 'require_not_worse_than_default',
        // Manual demo gate override
        'allow_manual_demo_gate_with_insufficient_data', 'manual_demo_gate_requires_user_selection',
        'dynamic_learning_execution_mode', 'manual_gate_demo_enabled', 'selected_candidate_profile_id',
        'selected_candidate_source', 'selected_candidate_locked',
        'log_passed_demo_signals_enabled', 'max_blocked_demo_signals',
        'max_blocked_demo_signals_ndjson_size_mb', 'max_passed_demo_signals_ndjson_size_mb',
    ];
    $out = [];
    foreach ($keys as $k) {
        if (!array_key_exists($k, $cfg)) {
            continue;
        }
        $default = $cfg[$k];
        if (is_bool($default)) {
            $out[$k] = isset($_POST[$k]) ? (bool)(int)$_POST[$k] : $default;
        } elseif (is_int($default)) {
            $out[$k] = (int)($_POST[$k] ?? $default);
        } elseif (is_float($default)) {
            $out[$k] = (float)($_POST[$k] ?? $default);
        } else {
            $out[$k] = trim((string)($_POST[$k] ?? $default));
        }
    }
    $out['apply_learning_to_strategy_enabled'] = isset($_POST['apply_learning_to_strategy_enabled']) && (string)$_POST['apply_learning_to_strategy_enabled'] === '1';
    $out['apply_learning_to_live_enabled'] = isset($_POST['apply_learning_to_live_enabled']) && (string)$_POST['apply_learning_to_live_enabled'] === '1';
    $out['auto_apply_to_demo_enabled'] = isset($_POST['auto_apply_to_demo_enabled']) && (string)$_POST['auto_apply_to_demo_enabled'] === '1';
    $out['auto_apply_to_live_enabled'] = false;
    $out['apply_learning_to_live_enabled'] = false;
    $out['selected_candidate_profile_id'] = trim((string)($out['selected_candidate_profile_id'] ?? ''));
    $out['selected_candidate_source'] = trim((string)($out['selected_candidate_source'] ?? 'manual_selection'));
    $out['selected_candidate_locked'] = true;
    $saveActive($out);
    $latest = $getLatestCandidate();
    $ready = $readiness($out, $latest, $candidateReplayExists());
    $jsonOut(true, [
        'saved' => true,
        'selected_candidate_profile_id' => (string)$ready['selected_candidate_profile_id'],
        'gate_demo_ready_after_save' => (bool)$ready['gate_demo_ready'],
        'warnings' => (array)$ready['warnings'],
    ]);
}

$jsonOut(false, [], 'Unknown action');
