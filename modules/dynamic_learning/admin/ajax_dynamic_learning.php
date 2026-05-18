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

$jsonOut = static function (bool $ok, array $data = [], string $error = ''): never {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => $ok], $data, $ok ? [] : ['error' => $error]));
    exit;
};

if ($action === 'use_latest_candidate') {
    $candidatePath = $moduleDir . '/storage/profiles/early_impulse_growth_long/candidate_profile.json';
    if (!is_file($candidatePath)) {
        $jsonOut(false, [], 'candidate_profile.json not found');
    }
    $rawCand = @file_get_contents($candidatePath);
    $candData = is_string($rawCand) ? json_decode($rawCand, true) : null;
    $profileId = trim((string)(is_array($candData) ? ($candData['profile_id'] ?? '') : ''));
    if ($profileId === '') {
        $jsonOut(false, [], 'candidate profile_id is empty');
    }
    $activePath = $moduleDir . '/config/active.php';
    $active = is_file($activePath) ? ((array)require $activePath) : [];
    $active['selected_candidate_profile_id'] = $profileId;
    $active['selected_candidate_locked'] = true;
    $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($active, true) . ";\n";
    @file_put_contents($activePath, $php, LOCK_EX);
    $jsonOut(true, ['profile_id' => $profileId, 'source' => 'candidate_profile']);
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
    $activePath = $moduleDir . '/config/active.php';
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
    $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($out, true) . ";\n";
    @file_put_contents($activePath, $php, LOCK_EX);
    $jsonOut(true, ['saved' => true]);
}

$jsonOut(false, [], 'Unknown action');
