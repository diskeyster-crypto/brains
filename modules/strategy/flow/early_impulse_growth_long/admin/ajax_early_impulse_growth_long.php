<?php

declare(strict_types=1);

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

if (!Auth::check()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$moduleDir = SystemPaths::instance()->get('strategy.early_impulse_growth_long');
require_once $moduleDir . '/service.php';
require_once dirname($moduleDir, 4) . '/modules/filter_engine/filter_engine.php';

use Modules\Strategy\EarlyImpulseGrowthLong\EarlyImpulseGrowthLongService;
use Modules\FilterEngine\FilterEngine;

$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));
$isJson = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
    || isset($_GET['json'])
);

$service = EarlyImpulseGrowthLongService::instance($moduleDir);
$configUrl = System::web('admin/strategy/early_impulse_growth_long/config');
$runtimeUrl = System::web('admin/strategy/early_impulse_growth_long/runtime');

function writeActiveConfig(string $moduleDir, array $overrides): ?string
{
    $path = $moduleDir . '/config/active.php';
    $lines = ["<?php\n\ndeclare(strict_types=1);\n\n"];
    $lines[] = "/**\n * Early Impulse Growth Long — Active Config Overrides\n * Written by the admin UI. Edit via the config page.\n */\n\n";
    $lines[] = 'return ' . var_export($overrides, true) . ";\n";
    return file_put_contents($path, implode('', $lines)) !== false ? null : 'Failed to write active.php';
}

function flashMessage(string $msg, string $type = 'success'): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['eig_flash'] = ['type' => $type, 'msg' => $msg];
}

function redirectTo(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function jsonResponse(bool $ok, mixed $data = [], string $error = ''): never
{
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => $ok], is_array($data) ? $data : [], $ok ? [] : ['error' => $error]), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'queue_run') {
    try {
        $result = $service->queueRun();
        if ($isJson) {
            jsonResponse(true, $result);
        }
        flashMessage('Запуск цикла поставлен в очередь. Window: ' . ($result['selected_window_total'] ?? 0) . ' symbols.');
        redirectTo($runtimeUrl);
    } catch (\Throwable $ex) {
        if ($isJson) {
            jsonResponse(false, [], $ex->getMessage());
        }
        flashMessage('Ошибка queue_run: ' . $ex->getMessage(), 'error');
        redirectTo($runtimeUrl);
    }
}

if ($action === 'tick_batch') {
    try {
        $result = $service->tickBatch();
        if ($isJson) {
            jsonResponse(true, $result);
        }
        flashMessage('Тик батча выполнен. Status: ' . ($result['status'] ?? '?') . ' · candidates: ' . ($result['candidates_total'] ?? 0) . ' · signals: ' . ($result['signals_total'] ?? 0));
        redirectTo($runtimeUrl);
    } catch (\Throwable $ex) {
        if ($isJson) {
            jsonResponse(false, [], $ex->getMessage());
        }
        flashMessage('Ошибка tick_batch: ' . $ex->getMessage(), 'error');
        redirectTo($runtimeUrl);
    }
}

if ($action === 'save_config') {
    $existing = [];
    $activePath = $moduleDir . '/config/active.php';
    if (is_file($activePath)) {
        $_loaded = @include $activePath;
        if (is_array($_loaded)) {
            $existing = $_loaded;
        }
    }

    $boolField = static fn(string $k, bool $def = false): bool => isset($_POST[$k]) ? (bool)(int)$_POST[$k] : $def;
    $intField = static fn(string $k, int $min, int $max, int $def): int => max($min, min($max, (int)($_POST[$k] ?? $def)));
    $floatField = static fn(string $k, float $min, float $max, float $def): float => max($min, min($max, (float)($_POST[$k] ?? $def)));
    $strField = static function (string $k, array $allowed, string $def): string {
        $v = trim((string)($_POST[$k] ?? $def));
        return in_array($v, $allowed, true) ? $v : $def;
    };

    $catalog = $service->getFilterCatalog();
    $profiles = $service->getFilterProfiles();
    $selectedProfile = trim((string)($_POST['filter_profile_active'] ?? 'raw_no_filters'));
    if ($selectedProfile === '' || !isset($profiles[$selectedProfile])) {
        $selectedProfile = isset($profiles['raw_no_filters']) ? 'raw_no_filters' : (array_key_first($profiles) ?? 'raw_no_filters');
    }
    $applyProfile = isset($_POST['apply_filter_profile']);
    $selectedProfileConfig = is_array($profiles[$selectedProfile] ?? null) ? (array)$profiles[$selectedProfile] : [];

    $filterConfig = [];
    foreach ($catalog as $filterId => $meta) {
        $filterConfig[$filterId] = FilterEngine::defaultConfigRow($meta);
    }

    if ($applyProfile) {
        $profileRows = is_array($profiles[$selectedProfile]['filter_config'] ?? null) ? (array)$profiles[$selectedProfile]['filter_config'] : [];
        foreach ($catalog as $filterId => $meta) {
            $row = is_array($profileRows[$filterId] ?? null) ? (array)$profileRows[$filterId] : [];
            $filterConfig[$filterId] = FilterEngine::normalizeConfigRow($meta, array_merge($filterConfig[$filterId], $row));
        }
    } else {
        $postedRows = is_array($_POST['filter_config'] ?? null) ? (array)$_POST['filter_config'] : [];
        foreach ($catalog as $filterId => $meta) {
            $row = is_array($postedRows[$filterId] ?? null) ? (array)$postedRows[$filterId] : [];
            $filterConfig[$filterId] = FilterEngine::normalizeConfigRow($meta, array_merge($filterConfig[$filterId], $row));
        }
    }

    if ($selectedProfile === 'raw_no_filters') {
        foreach ($catalog as $filterId => $meta) {
            $row = FilterEngine::normalizeConfigRow($meta, (array)($filterConfig[$filterId] ?? []));
            $row['enabled'] = false;
            $filterConfig[$filterId] = $row;
        }
    }

    $enabledFilters = [];
    $disabledFilters = [];
    foreach ($filterConfig as $filterId => $row) {
        if (!empty($row['enabled'])) {
            $enabledFilters[] = $filterId;
        } else {
            $disabledFilters[] = $filterId;
        }
    }

    $resolvedFilterEngineEnabled = $boolField('filter_engine_enabled', true);
    $resolvedFilterEnforcementMode = $strField('filter_enforcement_mode', ['diagnostic_only', 'soft', 'strict'], 'diagnostic_only');
    if ($selectedProfile === 'raw_no_filters') {
        $resolvedFilterEngineEnabled = true;
        $resolvedFilterEnforcementMode = 'diagnostic_only';
    } elseif ($applyProfile) {
        if (array_key_exists('filter_engine_enabled', $selectedProfileConfig)) {
            $resolvedFilterEngineEnabled = (bool)$selectedProfileConfig['filter_engine_enabled'];
        }
        if (isset($selectedProfileConfig['filter_enforcement_mode'])) {
            $resolvedFilterEnforcementMode = $strField(
                'filter_enforcement_mode',
                ['diagnostic_only', 'soft', 'strict'],
                (string)$selectedProfileConfig['filter_enforcement_mode']
            );
        }
    }

    $overrides = array_merge($existing, [
        'enabled' => $boolField('enabled'),
        'handoff_enabled' => $boolField('handoff_enabled'),
        'emit_bot_handoff' => $boolField('emit_bot_handoff'),
        'max_handoff_signals_per_tick' => $intField('max_handoff_signals_per_tick', 1, 100, 5),
        'bot_ready_ttl_minutes' => $intField('bot_ready_ttl_minutes', 1, 240, 10),
        'batch_size' => $intField('batch_size', 1, 1000, 100),
        'max_symbols_per_run' => $intField('max_symbols_per_run', 1, 1000, 100),
        'continuous_scan_enabled' => $boolField('continuous_scan_enabled', true),
        'auto_requeue_when_done' => $boolField('auto_requeue_when_done', true),
        'recovery_window_minutes' => $intField('recovery_window_minutes', 60, 360, 180),
        'recovery_min_window_minutes' => $intField('recovery_min_window_minutes', 30, 240, 120),
        'recovery_max_window_minutes' => $intField('recovery_max_window_minutes', 60, 360, 240),
        'recovery_min_duration_minutes' => $intField('recovery_min_duration_minutes', 30, 360, 120),
        'prior_decline_lookback_minutes' => $intField('prior_decline_lookback_minutes', 60, 720, 240),
        'min_prior_decline_pct' => $floatField('min_prior_decline_pct', 0.0, 50.0, 2.0),
        'recovery_score_mode' => $strField('recovery_score_mode', ['threshold', 'range'], 'threshold'),
        'min_recovery_growth_pct' => $floatField('min_recovery_growth_pct', 0.0, 100.0, 3.0),
        'min_recovery_score' => $floatField('min_recovery_score', 0.0, 1.0, 0.55),
        'min_combined_recovery_score' => $floatField('min_combined_recovery_score', 0.0, 1.0, 0.50),
        'recovery_score_target_pct' => $floatField('recovery_score_target_pct', 0.0, 100.0, 8.0),
        'max_recovery_growth_pct' => $floatField('max_recovery_growth_pct', 0.0, 200.0, 30.0),
        'open_interest_enabled' => $boolField('open_interest_enabled', true),
        'allow_missing_open_interest' => $boolField('allow_missing_open_interest', true),
        'min_open_interest_growth_pct' => $floatField('min_open_interest_growth_pct', -100.0, 100.0, 1.0),
        'min_open_interest_growth_score' => $floatField('min_open_interest_growth_score', 0.0, 1.0, 0.55),
        'open_interest_score_target_pct' => $floatField('open_interest_score_target_pct', -100.0, 100.0, 5.0),
        'missing_open_interest_mode' => $strField('missing_open_interest_mode', ['diagnostic_only', 'block'], 'diagnostic_only'),
        'current_acceleration_window_minutes' => $intField('current_acceleration_window_minutes', 1, 60, 10),
        'fast_spike_diagnostic_enabled' => $boolField('fast_spike_diagnostic_enabled', true),
        'fast_spike_window_minutes' => $intField('fast_spike_window_minutes', 1, 60, 10),
        'fast_spike_price_change_pct' => $floatField('fast_spike_price_change_pct', 0.0, 100.0, 2.0),
        'fast_spike_roi_equivalent_leverage' => $floatField('fast_spike_roi_equivalent_leverage', 1.0, 200.0, 5.0),
        'fast_spike_roi_equivalent_threshold' => $floatField('fast_spike_roi_equivalent_threshold', 0.0, 1000.0, 10.0),
        'filter_engine_enabled' => $resolvedFilterEngineEnabled,
        'filter_enforcement_mode' => $resolvedFilterEnforcementMode,
        'filter_profile' => $selectedProfile,
        'filter_profile_active' => $selectedProfile,
        'filter_config' => $filterConfig,
        'enabled_filters' => $enabledFilters,
        'disabled_filters' => $disabledFilters,
        'max_data_staleness_seconds' => $intField('max_data_staleness_seconds', 30, 600, 300),
        'parser2_history_lookback_minutes' => $intField('parser2_history_lookback_minutes', 30, 1440, 500),
        'bybit_kline_limit' => $intField('bybit_kline_limit', 20, 1000, 500),
        'bybit_timeout_sec' => $intField('bybit_timeout_sec', 3, 30, 6),
    ]);

    foreach ($catalog as $filterId => $_meta) {
        $overrides['eig_filter_' . $filterId . '_enabled'] = !empty($filterConfig[$filterId]['enabled']);
    }

    $err = writeActiveConfig($moduleDir, $overrides);
    if ($isJson) {
        if ($err !== null) {
            jsonResponse(false, [], $err);
        }
        jsonResponse(true, ['saved' => true, 'applied_profile' => $applyProfile ? $selectedProfile : null]);
    }
    if ($err !== null) {
        flashMessage('Ошибка сохранения: ' . $err, 'error');
    } else {
        flashMessage($applyProfile ? ('Профиль фильтров применён: ' . $selectedProfile) : 'Конфигурация сохранена.');
    }
    redirectTo($configUrl);
}

if ($action === 'reset_active') {
    $err = writeActiveConfig($moduleDir, []);
    if ($isJson) {
        if ($err !== null) {
            jsonResponse(false, [], $err);
        }
        jsonResponse(true, ['reset' => true]);
    }
    if ($err !== null) {
        flashMessage('Ошибка сброса: ' . $err, 'error');
    } else {
        flashMessage('Конфигурация сброшена к значениям по умолчанию.');
    }
    redirectTo($configUrl);
}

if ($isJson) {
    jsonResponse(false, [], 'Unknown action: ' . $action);
}
flashMessage('Неизвестное действие: ' . $action, 'error');
redirectTo($configUrl);
