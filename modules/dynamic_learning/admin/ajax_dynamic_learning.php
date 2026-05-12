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
        'bad_drawdown_roi_threshold', 'good_close_roi_threshold', 'good_max_profit_roi_threshold', 'neutral_close_roi_min', 'neutral_close_roi_max',
        'min_closed_outcomes_for_profile', 'min_bad_entries_for_rule', 'min_bad_blocked_for_rule', 'max_good_blocked_for_rule', 'min_rule_net_score',
        'rollback_guard_enabled', 'rollback_drawdown_pct', 'rollback_bad_trade_streak', 'profile_history_enabled',
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
    $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($out, true) . ";\n";
    @file_put_contents($activePath, $php, LOCK_EX);
    $jsonOut(true, ['saved' => true]);
}

$jsonOut(false, [], 'Unknown action');
