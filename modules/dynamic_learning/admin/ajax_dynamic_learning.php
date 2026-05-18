<?php

declare(strict_types=1);

use Core\Auth\Auth;

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
$lastRunPath = $moduleDir . '/storage/last_run.json';
$currentProfilePath = $moduleDir . '/storage/profiles/early_impulse_growth_long/current_profile.json';
$candidateProfilePath = $moduleDir . '/storage/profiles/early_impulse_growth_long/candidate_profile.json';
$candidateReplayPath = $moduleDir . '/storage/profiles/early_impulse_growth_long/candidate_replay.json';

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

$readJson = static function (string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
};

$writeJson = static function (string $path, array $payload): void {
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || @file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write JSON file: ' . basename($path));
    }
};

$countRules = static function (array $profile): int {
    return count(array_values(array_filter((array)($profile['rules'] ?? []), static fn (mixed $r): bool => is_array($r))));
};

$resolveCandidateDiagnostics = static function (array $cfg) use ($readJson, $countRules, $lastRunPath, $currentProfilePath, $candidateProfilePath, $candidateReplayPath): array {
    $lastRun = $readJson($lastRunPath);
    $currentProfile = $readJson($currentProfilePath);
    $candidateProfile = $readJson($candidateProfilePath);
    $candidateReplay = $readJson($candidateReplayPath);

    $latestCandidateProfileId = trim((string)($candidateProfile['profile_id'] ?? ''));
    $latestCandidateRulesTotal = $latestCandidateProfileId !== '' ? $countRules($candidateProfile) : 0;
    $currentCandidateProfileId = trim((string)($currentProfile['candidate_profile_id'] ?? ''));
    if ($currentCandidateProfileId === '') {
        $currentCandidateProfileId = trim((string)($currentProfile['profile_id'] ?? ''));
    }

    $replayExists = $candidateReplay !== [];
    $replayResult = strtolower(trim((string)($candidateReplay['replay_result'] ?? '')));
    $replaySuggestsImprovement = (bool)($candidateReplay['replay_suggests_improvement'] ?? false);
    $replayGoodBlockRate = $candidateReplay['replay_good_block_rate_pct'] ?? null;
    $replayGoodBlockRateValue = is_numeric($replayGoodBlockRate) ? (float)$replayGoodBlockRate : null;
    $goodBlockRateGuard = $cfg['candidate_max_good_block_rate_pct'] ?? null;
    $goodBlockRateGuardValue = is_numeric($goodBlockRateGuard) ? (float)$goodBlockRateGuard : null;
    $goodBlockRateWithinGuard = $goodBlockRateGuardValue === null || $replayGoodBlockRateValue === null || $replayGoodBlockRateValue <= $goodBlockRateGuardValue;

    $latestUsableCandidateProfileId = '';
    $latestUsableReason = '';
    if ($latestCandidateProfileId === '') {
        $latestUsableReason = 'latest candidate profile_id is empty';
    } elseif ($latestCandidateRulesTotal <= 0) {
        $latestUsableReason = 'latest candidate has no rules';
    } elseif (!$replayExists) {
        $latestUsableReason = 'candidate_replay.json is missing';
    } elseif (!($replayResult === 'improved_on_sample' || $replaySuggestsImprovement)) {
        $latestUsableReason = 'candidate replay does not suggest improvement';
    } elseif (!$goodBlockRateWithinGuard) {
        $latestUsableReason = 'candidate replay good block rate exceeds guard';
    } else {
        $latestUsableCandidateProfileId = $latestCandidateProfileId;
    }

    return [
        'last_run' => $lastRun,
        'current_profile' => $currentProfile,
        'candidate_profile' => $candidateProfile,
        'candidate_replay' => $candidateReplay,
        'latest_candidate_profile_id' => $latestCandidateProfileId,
        'latest_candidate_rules_total' => $latestCandidateRulesTotal,
        'latest_candidate_status' => (string)($lastRun['final_candidate_status'] ?? $lastRun['candidate_status'] ?? ($candidateProfile['candidate_status'] ?? 'pending')),
        'latest_candidate_replay_status' => (string)($candidateReplay['replay_candidate_status'] ?? 'missing'),
        'latest_candidate_vs_default_delta' => $lastRun['candidate_vs_default_delta_pct'] ?? null,
        'latest_candidate_replay_bad_blocked' => (int)($lastRun['replay_bad_blocked_total'] ?? 0),
        'latest_candidate_replay_good_blocked' => (int)($lastRun['replay_good_blocked_total'] ?? 0),
        'latest_usable_candidate_profile_id' => $latestUsableCandidateProfileId,
        'latest_usable_candidate_reason' => $latestUsableReason,
        'current_profile_candidate_profile_id' => $currentCandidateProfileId,
        'candidate_replay_exists' => $replayExists,
        'candidate_replay_result' => $replayResult,
        'candidate_replay_suggests_improvement' => $replaySuggestsImprovement,
    ];
};

$resolveSelectedState = static function (array $active, array $diag) use ($countRules): array {
    $selectedId = trim((string)($active['selected_candidate_profile_id'] ?? ''));
    $selectedExists = false;
    $selectedRulesTotal = 0;
    if ($selectedId !== '') {
        $candidateProfile = (array)($diag['candidate_profile'] ?? []);
        $candidateProfileId = trim((string)($candidateProfile['profile_id'] ?? ''));
        $currentProfile = (array)($diag['current_profile'] ?? []);
        $currentProfileId = trim((string)($currentProfile['profile_id'] ?? ''));
        $currentCandidateId = trim((string)($currentProfile['candidate_profile_id'] ?? ''));

        if ($candidateProfileId !== '' && $selectedId === $candidateProfileId) {
            $selectedExists = true;
            $selectedRulesTotal = $countRules($candidateProfile);
        } elseif ($currentCandidateId !== '' && $selectedId === $currentCandidateId) {
            $selectedExists = true;
            $selectedRulesTotal = max(0, (int)($currentProfile['rules_total'] ?? 0));
        } elseif ($currentProfileId !== '' && $selectedId === $currentProfileId) {
            $selectedExists = true;
            $selectedRulesTotal = max(0, (int)($currentProfile['rules_total'] ?? 0));
        }
    }
    return [
        'selected_candidate_profile_id' => $selectedId,
        'selected_candidate_exists' => $selectedExists,
        'selected_candidate_rules_total' => $selectedRulesTotal,
    ];
};

$buildReadiness = static function (array $active, array $diag, array $selected): array {
    $checks = [
        'mode_is_gate_demo' => (string)($active['dynamic_learning_execution_mode'] ?? 'observe') === 'gate_demo',
        'manual_gate_demo_enabled' => (bool)($active['manual_gate_demo_enabled'] ?? false),
        'apply_learning_to_strategy_enabled' => (bool)($active['apply_learning_to_strategy_enabled'] ?? false),
        'selected_candidate_not_empty' => trim((string)($selected['selected_candidate_profile_id'] ?? '')) !== '',
        'selected_candidate_exists' => (bool)($selected['selected_candidate_exists'] ?? false),
        'selected_candidate_rules_total_gt_0' => (int)($selected['selected_candidate_rules_total'] ?? 0) > 0,
        'candidate_replay_exists' => (bool)($diag['candidate_replay_exists'] ?? false),
        'live_apply_disabled' => !((bool)($active['apply_learning_to_live_enabled'] ?? false)),
        'auto_apply_disabled' => !((bool)($active['auto_apply_to_demo_enabled'] ?? false) || (bool)($active['auto_apply_to_live_enabled'] ?? false)),
    ];

    $warnings = [];
    if (!$checks['mode_is_gate_demo']) {
        $warnings[] = 'execution mode is ' . (string)($active['dynamic_learning_execution_mode'] ?? 'observe');
    }
    if (!$checks['manual_gate_demo_enabled']) {
        $warnings[] = 'manual_gate_demo_enabled is false';
    }
    if (!$checks['apply_learning_to_strategy_enabled']) {
        $warnings[] = 'apply_learning_to_strategy_enabled is false';
    }
    if (!$checks['selected_candidate_not_empty']) {
        $warnings[] = 'selected_candidate_profile_id is empty';
    }
    if (!$checks['selected_candidate_exists']) {
        $warnings[] = 'selected candidate profile does not exist';
    }
    if (!$checks['selected_candidate_rules_total_gt_0']) {
        $warnings[] = 'selected candidate rules_total is 0';
    }
    if (!$checks['candidate_replay_exists']) {
        $warnings[] = 'candidate_replay.json is missing';
    }
    if (!$checks['live_apply_disabled']) {
        $warnings[] = 'apply_learning_to_live_enabled must be false';
    }
    if (!$checks['auto_apply_disabled']) {
        $warnings[] = 'auto apply flags must be false';
    }

    $ready = $warnings === [];
    return [
        'gate_demo_ready' => $ready,
        'gate_demo_not_ready_reason' => $ready ? null : implode('; ', $warnings),
        'gate_demo_readiness_checks' => $checks,
        'warnings' => $warnings,
    ];
};

$ensureDemoSafety = static function (array $active): array {
    $active['apply_learning_to_live_enabled'] = false;
    $active['auto_apply_to_demo_enabled'] = false;
    $active['auto_apply_to_live_enabled'] = false;
    return $active;
};

$updateLastRunDiagnostics = static function (array $active, array $diag, array $selected, array $readiness) use ($readJson, $writeJson, $lastRunPath): void {
    $lastRun = $readJson($lastRunPath);
    $liveSafetyOk = !((bool)($active['apply_learning_to_live_enabled'] ?? false) || (bool)($active['auto_apply_to_live_enabled'] ?? false));

    $lastRun['dynamic_learning_execution_mode'] = (string)($active['dynamic_learning_execution_mode'] ?? 'observe');
    $lastRun['manual_gate_demo_enabled'] = (bool)($active['manual_gate_demo_enabled'] ?? false);
    $lastRun['apply_learning_to_strategy_enabled'] = (bool)($active['apply_learning_to_strategy_enabled'] ?? false);
    $lastRun['apply_learning_to_live_enabled'] = false;
    $lastRun['auto_apply_to_demo_enabled'] = false;
    $lastRun['auto_apply_to_live_enabled'] = false;
    $lastRun['selected_candidate_profile_id'] = (string)($selected['selected_candidate_profile_id'] ?? '');
    $lastRun['selected_candidate_source'] = (string)($active['selected_candidate_source'] ?? 'manual_selection');
    $lastRun['selected_candidate_locked'] = (bool)($active['selected_candidate_locked'] ?? true);
    $lastRun['selected_candidate_exists'] = (bool)($selected['selected_candidate_exists'] ?? false);
    $lastRun['selected_candidate_rules_total'] = (int)($selected['selected_candidate_rules_total'] ?? 0);
    $lastRun['latest_candidate_profile_id'] = (string)($diag['latest_candidate_profile_id'] ?? '');
    $lastRun['latest_usable_candidate_profile_id'] = (string)($diag['latest_usable_candidate_profile_id'] ?? '');
    $lastRun['gate_demo_ready'] = (bool)($readiness['gate_demo_ready'] ?? false);
    $lastRun['gate_demo_not_ready_reason'] = $readiness['gate_demo_not_ready_reason'] ?? null;
    $lastRun['gate_demo_readiness_checks'] = (array)($readiness['gate_demo_readiness_checks'] ?? []);
    $lastRun['live_apply_safety_ok'] = $liveSafetyOk;
    $lastRun['live_apply_safety_reason'] = $liveSafetyOk ? 'live_apply_disabled' : 'live_apply_flag_detected';

    $writeJson($lastRunPath, $lastRun);
};

$buildResponse = static function (array $active, array $diag, array $selected, array $readiness, array $extra = []): array {
    $pid = (string)($selected['selected_candidate_profile_id'] ?? '');
    return array_merge([
        'profile_id' => $pid,
        'selected_candidate_profile_id' => $pid,
        'selected_candidate_source' => (string)($active['selected_candidate_source'] ?? 'manual_selection'),
        'selected_candidate_locked' => (bool)($active['selected_candidate_locked'] ?? true),
        'dynamic_learning_execution_mode' => (string)($active['dynamic_learning_execution_mode'] ?? 'observe'),
        'manual_gate_demo_enabled' => (bool)($active['manual_gate_demo_enabled'] ?? false),
        'apply_learning_to_strategy_enabled' => (bool)($active['apply_learning_to_strategy_enabled'] ?? false),
        'apply_learning_to_live_enabled' => false,
        'auto_apply_to_demo_enabled' => false,
        'auto_apply_to_live_enabled' => false,
        'selected_candidate_exists' => (bool)($selected['selected_candidate_exists'] ?? false),
        'selected_candidate_rules_total' => (int)($selected['selected_candidate_rules_total'] ?? 0),
        'latest_candidate_profile_id' => (string)($diag['latest_candidate_profile_id'] ?? ''),
        'latest_usable_candidate_profile_id' => (string)($diag['latest_usable_candidate_profile_id'] ?? ''),
        'gate_demo_ready_after_save' => (bool)($readiness['gate_demo_ready'] ?? false),
        'gate_demo_not_ready_reason' => $readiness['gate_demo_not_ready_reason'] ?? null,
        'warnings' => (array)($readiness['warnings'] ?? []),
    ], $extra);
};

$runGateAction = static function (callable $mutator) use (
    $svc,
    $loadActive,
    $saveActive,
    $resolveCandidateDiagnostics,
    $resolveSelectedState,
    $buildReadiness,
    $updateLastRunDiagnostics,
    $buildResponse,
    $jsonOut
): void {
    try {
        $active = $loadActive();
        $cfg = $svc->getConfig();
        $diag = $resolveCandidateDiagnostics($cfg);
        [$ok, $active, $extra, $error] = $mutator($active, $diag);
        if (!$ok) {
            $jsonOut(false, $extra, $error);
        }
        $saveActive($active);
        $selected = $resolveSelectedState($active, $diag);
        $readiness = $buildReadiness($active, $diag, $selected);
        $updateLastRunDiagnostics($active, $diag, $selected, $readiness);
        $jsonOut(true, $buildResponse($active, $diag, $selected, $readiness, $extra));
    } catch (\Throwable $e) {
        $jsonOut(false, [], $e->getMessage());
    }
};

if ($action === 'use_latest_candidate' || $action === 'use_latest_candidate_for_demo_gate' || $action === 'use_latest_usable_candidate_for_demo_gate') {
    $runGateAction(static function (array $active, array $diag) use ($ensureDemoSafety): array {
        $usableId = (string)($diag['latest_usable_candidate_profile_id'] ?? '');
        if ($usableId === '') {
            $reason = trim((string)($diag['latest_usable_candidate_reason'] ?? 'no_usable_candidate'));
            return [false, $active, ['error_code' => 'no_usable_candidate', 'warning' => $reason, 'warnings' => [$reason]], 'No usable candidate for DEMO gate'];
        }
        $active['selected_candidate_profile_id'] = $usableId;
        $active['selected_candidate_source'] = 'candidate_profile';
        $active['selected_candidate_locked'] = true;
        $active = $ensureDemoSafety($active);
        return [true, $active, [], ''];
    });
}

if ($action === 'enable_manual_demo_gate_latest') {
    $runGateAction(static function (array $active, array $diag) use ($ensureDemoSafety): array {
        $usableId = (string)($diag['latest_usable_candidate_profile_id'] ?? '');
        if ($usableId === '') {
            $reason = trim((string)($diag['latest_usable_candidate_reason'] ?? 'candidate has no rules or replay not improved'));
            return [false, $active, ['error_code' => 'no_usable_candidate', 'warning' => $reason, 'warnings' => [$reason]], 'No usable candidate'];
        }
        $active['dynamic_learning_execution_mode'] = 'gate_demo';
        $active['mode'] = 'gate_demo';
        $active['manual_gate_demo_enabled'] = true;
        $active['apply_learning_to_demo_enabled'] = true;
        $active['apply_learning_to_strategy_enabled'] = true;
        $active['selected_candidate_profile_id'] = $usableId;
        $active['selected_candidate_source'] = 'candidate_profile';
        $active['selected_candidate_locked'] = true;
        $active['allow_manual_demo_gate_with_insufficient_data'] = true;
        $active['manual_demo_gate_requires_user_selection'] = true;
        $active = $ensureDemoSafety($active);
        return [true, $active, [], ''];
    });
}

if ($action === 'disable_dynamic_learning_gate') {
    $runGateAction(static function (array $active, array $diag) use ($ensureDemoSafety): array {
        $active['dynamic_learning_execution_mode'] = 'observe';
        $active['mode'] = 'observe';
        $active['manual_gate_demo_enabled'] = false;
        $active['apply_learning_to_demo_enabled'] = false;
        $active['apply_learning_to_strategy_enabled'] = false;
        $active = $ensureDemoSafety($active);
        return [true, $active, [], ''];
    });
}

if ($action === 'run_cycle') {
    try {
        $jsonOut(true, ['result' => $svc->runCycle()]);
    } catch (\Throwable $e) {
        $jsonOut(false, [], $e->getMessage());
    }
}

if ($action === 'save_config') {
    try {
        $cfg = $svc->getConfig();
        $keys = [
            'dynamic_learning_execution_mode',
            'manual_gate_demo_enabled',
            'apply_learning_to_strategy_enabled',
            'selected_candidate_profile_id',
            'selected_candidate_source',
            'selected_candidate_locked',
            'allow_manual_demo_gate_with_insufficient_data',
            'manual_demo_gate_requires_user_selection',
            'log_passed_demo_signals_enabled',
            'max_blocked_demo_signals',
            'max_blocked_demo_signals_ndjson_size_mb',
            'max_passed_demo_signals_ndjson_size_mb',
        ];

        $active = $loadActive();
        foreach ($keys as $key) {
            $default = $cfg[$key] ?? ($active[$key] ?? null);
            if (is_bool($default)) {
                $active[$key] = isset($_POST[$key]) && (string)$_POST[$key] === '1';
            } elseif (is_int($default)) {
                $active[$key] = (int)($_POST[$key] ?? $default);
            } elseif (is_float($default)) {
                $active[$key] = (float)($_POST[$key] ?? $default);
            } else {
                $active[$key] = trim((string)($_POST[$key] ?? (string)$default));
            }
        }

        $mode = strtolower(trim((string)($active['dynamic_learning_execution_mode'] ?? 'observe')));
        if (!in_array($mode, ['off', 'observe', 'gate_demo'], true)) {
            $mode = 'observe';
        }
        $active['dynamic_learning_execution_mode'] = $mode;
        $active['mode'] = $mode;
        $active['manual_gate_demo_enabled'] = (bool)($active['manual_gate_demo_enabled'] ?? false);
        $active['apply_learning_to_demo_enabled'] = $active['manual_gate_demo_enabled'];
        $active['apply_learning_to_strategy_enabled'] = (bool)($active['apply_learning_to_strategy_enabled'] ?? false);
        $active['selected_candidate_profile_id'] = trim((string)($active['selected_candidate_profile_id'] ?? ''));
        $active['selected_candidate_source'] = trim((string)($active['selected_candidate_source'] ?? 'manual_selection'));
        $active['selected_candidate_locked'] = (bool)($active['selected_candidate_locked'] ?? true);
        $active['allow_manual_demo_gate_with_insufficient_data'] = (bool)($active['allow_manual_demo_gate_with_insufficient_data'] ?? true);
        $active['manual_demo_gate_requires_user_selection'] = (bool)($active['manual_demo_gate_requires_user_selection'] ?? true);
        $active['log_passed_demo_signals_enabled'] = (bool)($active['log_passed_demo_signals_enabled'] ?? true);
        $active['max_blocked_demo_signals'] = max(1, (int)($active['max_blocked_demo_signals'] ?? 1000));
        $active['max_blocked_demo_signals_ndjson_size_mb'] = max(1.0, (float)($active['max_blocked_demo_signals_ndjson_size_mb'] ?? 20.0));
        $active['max_passed_demo_signals_ndjson_size_mb'] = max(1.0, (float)($active['max_passed_demo_signals_ndjson_size_mb'] ?? 20.0));
        $active = $ensureDemoSafety($active);

        $diag = $resolveCandidateDiagnostics($cfg);
        $saveActive($active);
        $selected = $resolveSelectedState($active, $diag);
        $readiness = $buildReadiness($active, $diag, $selected);
        $updateLastRunDiagnostics($active, $diag, $selected, $readiness);

        $jsonOut(true, $buildResponse($active, $diag, $selected, $readiness, ['saved' => true]));
    } catch (\Throwable $e) {
        $jsonOut(false, [], $e->getMessage());
    }
}

$jsonOut(false, [], 'Unknown action');
