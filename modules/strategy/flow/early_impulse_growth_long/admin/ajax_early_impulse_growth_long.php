<?php

declare(strict_types=1);

/**
 * Early Impulse Growth Long — AJAX Handler
 *
 * Handles POST actions from admin pages:
 *   action=queue_run    → queue a batched scan run
 *   action=tick_batch   → advance one batch
 *   action=save_config  → write active.php overrides
 *   action=reset_active → clear active.php back to empty
 */

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

use Modules\Strategy\EarlyImpulseGrowthLong\EarlyImpulseGrowthLongService;

$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));
$isJson = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
    isset($_GET['json'])
);

$service   = EarlyImpulseGrowthLongService::instance($moduleDir);
$configUrl = System::web('admin/strategy/early_impulse_growth_long/config');
$runtimeUrl = System::web('admin/strategy/early_impulse_growth_long/runtime');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function eigWriteActive(string $moduleDir, array $overrides): ?string
{
    $path  = $moduleDir . '/config/active.php';
    $lines = ["<?php\n\ndeclare(strict_types=1);\n\n"];
    $lines[] = "/**\n * Early Impulse Growth Long — Active Config Overrides\n"
             . " * Written by the admin UI. Edit via the config page.\n */\n\n";
    $lines[] = "return " . var_export($overrides, true) . ";\n";
    $ok = file_put_contents($path, implode('', $lines));
    return $ok !== false ? null : 'Failed to write active.php';
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
    echo json_encode(array_merge(
        ['ok' => $ok],
        is_array($data) ? $data : [],
        $ok ? [] : ['error' => $error]
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------
// Action: queue_run
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Action: tick_batch
// ---------------------------------------------------------------------------

if ($action === 'tick_batch') {
    try {
        $result = $service->tickBatch();
        if ($isJson) {
            eigJsonExit(true, $result);
        }
        eigFlash('Тик батча выполнен. Status: ' . ($result['status'] ?? '?')
            . ' · candidates: ' . ($result['candidates_total'] ?? 0)
            . ' · signals: ' . ($result['signals_total'] ?? 0));
        eigRedirect($runtimeUrl);
    } catch (\Throwable $ex) {
        if ($isJson) {
            eigJsonExit(false, [], $ex->getMessage());
        }
        eigFlash('Ошибка tick_batch: ' . $ex->getMessage(), 'error');
        eigRedirect($runtimeUrl);
    }
}

// ---------------------------------------------------------------------------
// Action: save_config
// ---------------------------------------------------------------------------

if ($action === 'save_config') {
    // Load existing active to preserve unmanaged keys
    $existing = [];
    $activePath = $moduleDir . '/config/active.php';
    if (is_file($activePath)) {
        $_loaded = @include $activePath;
        if (is_array($_loaded)) {
            $existing = $_loaded;
        }
    }

    $boolField = static fn(string $k): bool => (bool)(int)($_POST[$k] ?? '0');
    $intField  = static fn(string $k, int $min, int $max, int $def): int =>
        max($min, min($max, (int)($_POST[$k] ?? $def)));
    $floatField = static fn(string $k, float $min, float $max, float $def): float =>
        max($min, min($max, (float)($_POST[$k] ?? $def)));
    $strField  = static function (string $k, array $allowed, string $def): string {
        $v = trim((string)($_POST[$k] ?? $def));
        return in_array($v, $allowed, true) ? $v : $def;
    };

    $overrides = array_merge($existing, [
        'enabled'                        => $boolField('enabled'),
        'handoff_enabled'                => $boolField('handoff_enabled'),
        'batch_size'                     => $intField('batch_size', 1, 1000, 100),
        'max_symbols_per_run'            => $intField('max_symbols_per_run', 1, 1000, 100),
        'continuous_scan_enabled'        => $boolField('continuous_scan_enabled'),
        'auto_requeue_when_done'         => $boolField('auto_requeue_when_done'),

        'impulse_window_minutes'         => $intField('impulse_window_minutes', 1, 60, 10),
        'impulse_min_window_minutes'     => $intField('impulse_min_window_minutes', 1, 60, 5),
        'impulse_max_window_minutes'     => $intField('impulse_max_window_minutes', 1, 60, 10),

        'min_price_impulse_pct'          => $floatField('min_price_impulse_pct', 0.0, 100.0, 0.4),
        'max_price_impulse_pct'          => $floatField('max_price_impulse_pct', 0.0, 100.0, 4.0),
        'min_price_impulse_score'        => $floatField('min_price_impulse_score', 0.0, 1.0, 0.55),

        'open_interest_enabled'          => $boolField('open_interest_enabled'),
        'allow_missing_open_interest'    => $boolField('allow_missing_open_interest'),
        'min_open_interest_growth_pct'   => $floatField('min_open_interest_growth_pct', 0.0, 100.0, 1.0),
        'min_open_interest_growth_score' => $floatField('min_open_interest_growth_score', 0.0, 1.0, 0.55),
        'missing_open_interest_mode'     => $strField('missing_open_interest_mode', ['diagnostic_only', 'block'], 'diagnostic_only'),

        'filter_engine_enabled'          => $boolField('filter_engine_enabled'),
        'filter_enforcement_mode'        => $strField('filter_enforcement_mode', ['diagnostic_only', 'soft', 'strict'], 'diagnostic_only'),

        'max_data_staleness_seconds'          => $intField('max_data_staleness_seconds', 30, 600, 180),
        'parser2_history_lookback_minutes'    => $intField('parser2_history_lookback_minutes', 30, 1440, 180),
        'bybit_kline_limit'                   => $intField('bybit_kline_limit', 20, 200, 120),
        'bybit_timeout_sec'                   => $intField('bybit_timeout_sec', 3, 30, 6),
    ]);

    $err = eigWriteActive($moduleDir, $overrides);
    if ($isJson) {
        if ($err !== null) {
            eigJsonExit(false, [], $err);
        }
        eigJsonExit(true, ['saved' => true]);
    }
    if ($err !== null) {
        eigFlash('Ошибка сохранения: ' . $err, 'error');
    } else {
        eigFlash('Конфигурация сохранена.');
    }
    eigRedirect($configUrl);
}

// ---------------------------------------------------------------------------
// Action: reset_active
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Unknown action
// ---------------------------------------------------------------------------

if ($isJson) {
    eigJsonExit(false, [], 'Unknown action: ' . $action);
}
eigFlash('Неизвестное действие: ' . $action, 'error');
eigRedirect($configUrl);
