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
require_once dirname($moduleDir, 4) . '/filter_engine/filter_engine.php';

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

function eigWriteActive(string $moduleDir, array $overrides): ?string
{
    $path = $moduleDir . '/config/active.php';
    $lines = ["<?php\n\ndeclare(strict_types=1);\n\n"];
    $lines[] = "/**\n * Early Impulse Growth Long — Active Config Overrides\n * Written by the admin UI. Edit via the config page.\n */\n\n";
    $lines[] = 'return ' . var_export($overrides, true) . ";\n";
    return file_put_contents($path, implode('', $lines)) !== false ? null : 'Failed to write active.php';
}

function eigFlash(string $msg, string $type = 'success'): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['eig_flash'] = ['type' => $type, 'msg' => $msg];
}

function eigRedirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function eigJsonExit(bool $ok, mixed $data = [], string $error = ''): never
{
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => $ok], is_array($data) ? $data : [], $ok ? [] : ['error' => $error]), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'queue_run') {
    try {
        $result = $service->queueRun();
        if ($isJson) {
            eigJsonExit(true, $result);
        }
        eigFlash('Запуск цикла поставлен в очередь. Window: ' . ($result['selected_window_total'] ?? 0) . ' symbols.');
        eigRedirect($runtimeUrl);
    } catch (\Throwable $ex) {
        if ($isJson) {
            eigJsonExit(false, [], $ex->getMessage());
        }
        eigFlash('Ошибка queue_run: ' . $ex->getMessage(), 'error');
        eigRedirect($runtimeUrl);
    }
}

if ($action === 'tick_batch') {
    try {
        $result = $service->tickBatch();
        if ($isJson) {
            eigJsonExit(true, $result);
        }
        eigFlash('Тик батча выполнен. Status: ' . ($result['status'] ?? '?') . ' · candidates: ' . ($result['candidates_total'] ?? 0) . ' · signals: ' . ($result['signals_total'] ?? 0));
        eigRedirect($runtimeUrl);
    } catch (\Throwable $ex) {
        if ($isJson) {
            eigJsonExit(false, [], $ex->getMessage());
        }
        eigFlash('Ошибка tick_batch: ' . $ex->getMessage(), 'error');
        eigRedirect($runtimeUrl);
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

    $enabledFilters = [];
    $disabledFilters = [];
    foreach ($filterConfig as $filterId => $row) {
        if (!empty($row['enabled'])) {
            $enabledFilters[] = $filterId;
        } else {
            $disabledFilters[] = $filterId;
        }
    }

    $overrides = array_merge($existing, [
        'enabled' => $boolField('enabled'),
        'handoff_enabled' => $boolField('handoff_enabled'),
        'batch_size' => $intField('batch_size', 1, 1000, 100),
        'max_symbols_per_run' => $intField('max_symbols_per_run', 1, 1000, 100),
        'continuous_scan_enabled' => $boolField('continuous_scan_enabled', true),
        'auto_requeue_when_done' => $boolField('auto_requeue_when_done', true),
        'impulse_window_minutes' => $intField('impulse_window_minutes', 1, 60, 10),
        'impulse_min_window_minutes' => $intField('impulse_min_window_minutes', 1, 60, 5),
        'impulse_max_window_minutes' => $intField('impulse_max_window_minutes', 1, 60, 10),
        'min_price_impulse_pct' => $floatField('min_price_impulse_pct', 0.0, 100.0, 0.4),
        'max_price_impulse_pct' => $floatField('max_price_impulse_pct', 0.0, 100.0, 4.0),
        'min_price_impulse_score' => $floatField('min_price_impulse_score', 0.0, 1.0, 0.55),
        'open_interest_enabled' => $boolField('open_interest_enabled', true),
        'allow_missing_open_interest' => $boolField('allow_missing_open_interest', true),
        'min_open_interest_growth_pct' => $floatField('min_open_interest_growth_pct', 0.0, 100.0, 1.0),
        'min_open_interest_growth_score' => $floatField('min_open_interest_growth_score', 0.0, 1.0, 0.55),
        'missing_open_interest_mode' => $strField('missing_open_interest_mode', ['diagnostic_only', 'block'], 'diagnostic_only'),
        'filter_engine_enabled' => $boolField('filter_engine_enabled', true),
        'filter_enforcement_mode' => $strField('filter_enforcement_mode', ['diagnostic_only', 'soft', 'strict'], 'diagnostic_only'),
        'filter_profile' => $selectedProfile,
        'filter_profile_active' => $selectedProfile,
        'filter_config' => $filterConfig,
        'enabled_filters' => $enabledFilters,
        'disabled_filters' => $disabledFilters,
        'max_data_staleness_seconds' => $intField('max_data_staleness_seconds', 30, 600, 180),
        'parser2_history_lookback_minutes' => $intField('parser2_history_lookback_minutes', 30, 1440, 180),
        'bybit_kline_limit' => $intField('bybit_kline_limit', 20, 200, 120),
        'bybit_timeout_sec' => $intField('bybit_timeout_sec', 3, 30, 6),
    ]);

    foreach ($catalog as $filterId => $_meta) {
        $overrides['eig_filter_' . $filterId . '_enabled'] = !empty($filterConfig[$filterId]['enabled']);
    }

    $err = eigWriteActive($moduleDir, $overrides);
    if ($isJson) {
        if ($err !== null) {
            eigJsonExit(false, [], $err);
        }
        eigJsonExit(true, ['saved' => true, 'applied_profile' => $applyProfile ? $selectedProfile : null]);
    }
    if ($err !== null) {
        eigFlash('Ошибка сохранения: ' . $err, 'error');
    } else {
        eigFlash($applyProfile ? ('Профиль фильтров применён: ' . $selectedProfile) : 'Конфигурация сохранена.');
    }
    eigRedirect($configUrl);
}

if ($action === 'reset_active') {
    $err = eigWriteActive($moduleDir, []);
    if ($isJson) {
        if ($err !== null) {
            eigJsonExit(false, [], $err);
        }
        eigJsonExit(true, ['reset' => true]);
    }
    if ($err !== null) {
        eigFlash('Ошибка сброса: ' . $err, 'error');
    } else {
        eigFlash('Конфигурация сброшена к значениям по умолчанию.');
    }
    eigRedirect($configUrl);
}

if ($isJson) {
    eigJsonExit(false, [], 'Unknown action: ' . $action);
}
eigFlash('Неизвестное действие: ' . $action, 'error');
eigRedirect($configUrl);
