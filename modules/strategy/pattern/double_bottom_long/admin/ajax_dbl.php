<?php

declare(strict_types=1);

/**
 * Double Bottom Long Strategy — AJAX Handler
 *
 * Handles POST actions from admin pages:
 *   action=queue_run     → queue a batched scan run
 *   action=tick_batch    → advance one batch
 *   action=save_config   → write active.php overrides
 *   action=reset_active  → clear active.php back to empty
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

$moduleDir = SystemPaths::instance()->get('strategy.double_bottom_long');

require_once $moduleDir . '/service.php';
require_once $moduleDir . '/bootstrap.php';

use Modules\Strategy\DoubleBottomLong\DoubleBottomLongService;
use Modules\Strategy\DoubleBottomLong\DoubleBottomLongBootstrap;

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$isAjax = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
    isset($_GET['json'])
);

$service   = DoubleBottomLongService::instance($moduleDir);
$configUrl = System::web('admin/strategy/double_bottom_long/config');
$indexUrl  = System::web('admin/strategy/double_bottom_long');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function dblWriteActive(string $moduleDir, array $overrides): ?string
{
    $path  = $moduleDir . '/config/active.php';
    $lines = ["<?php\n\ndeclare(strict_types=1);\n\n"];
    $lines[] = "/**\n * Double Bottom Long Strategy — Active Config Overrides\n"
             . " * Written by the admin UI. Edit via the config page.\n */\n\n";
    $lines[] = "return " . var_export($overrides, true) . ";\n";
    $ok = file_put_contents($path, implode('', $lines));
    return $ok !== false ? null : 'Failed to write active.php';
}

function dblFlash(string $msg, string $type = 'success'): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['dbl_flash'] = ['msg' => $msg, 'type' => $type];
}

function dblRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

if ($action === 'queue_run') {
    $result = $service->queueRun();
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
    dblFlash($result['ok'] ? 'Запуск поставлен в очередь (' . ($result['total'] ?? 0) . ' символов).' : ($result['error'] ?? 'Ошибка'), $result['ok'] ? 'success' : 'danger');
    dblRedirect($indexUrl);
}

if ($action === 'tick_batch') {
    try {
        $service->tickBatch();
        $msg = 'Шаг батча выполнен.';
        $t   = 'success';
    } catch (\Throwable $e) {
        $msg = 'Ошибка: ' . $e->getMessage();
        $t   = 'danger';
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $t === 'success', 'msg' => $msg]);
        exit;
    }
    dblFlash($msg, $t);
    dblRedirect($indexUrl);
}

if ($action === 'save_config') {
    $p = $_POST;

    // Parse comma/newline separated symbol lists into arrays
    $parseSymbolList = static function (string $raw): array {
        $items = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_filter(array_map('strtoupper', $items))));
    };

    // Parse comma/newline separated integer list into int[]
    $parseIntList = static function (string $raw): array {
        $items = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_map('intval', array_filter($items, 'is_numeric'))));
    };

    $overrides = [
        // Core
        'enabled'                  => ($p['enabled']    ?? '0') === '1',
        'mode'                     => (string)($p['mode']     ?? 'passive'),
        // Market regime
        'market_regime_enabled'    => ($p['market_regime_enabled']    ?? '1') === '1',
        'market_regime_gate_mode'  => (string)($p['market_regime_gate_mode'] ?? 'soft'),
        // Trend
        'trend_required'           => ($p['trend_required']   ?? '1') === '1',
        // Corridor
        'corridor_required'        => ($p['corridor_required'] ?? '1') === '1',
        'corridor_lookback_hours'  => (int)($p['corridor_lookback_hours'] ?? 24),
        'corridor_bucket_count'    => (int)($p['corridor_bucket_count']   ?? 10),
        'allowed_long_buckets'     => $parseIntList((string)($p['allowed_long_buckets'] ?? '')),
        // Wave
        'wave_required'            => ($p['wave_required']    ?? '1') === '1',
        // Confirm
        'confirm_required'         => ($p['confirm_required'] ?? '1') === '1',
        'confirm_max_bars'         => (int)($p['confirm_max_bars'] ?? 2),
        // Signal
        'signal_ttl_bars'          => (int)($p['signal_ttl_bars']  ?? 2),
        // Universe
        'universe_mode'            => (string)($p['universe_mode'] ?? 'all'),
        'allowed_symbols'          => $parseSymbolList((string)($p['allowed_symbols']  ?? '')),
        'excluded_symbols'         => $parseSymbolList((string)($p['excluded_symbols'] ?? '')),
        // Scan
        'batch_size'               => max(1, (int)($p['batch_size']          ?? 50)),
        'max_symbols_per_run'      => max(0, (int)($p['max_symbols_per_run'] ?? 0)),
        'max_runtime_seconds'      => max(5, (int)($p['max_runtime_seconds'] ?? 55)),
        'continuous_scan_enabled'  => ($p['continuous_scan_enabled'] ?? '1') === '1',
        // Stop
        'stop_mode'                       => (string)($p['stop_mode'] ?? 'structure'),
        'stop_from_liq_buffer_value'      => (float)($p['stop_from_liq_buffer_value'] ?? 0.002),
        'stop_from_liq_buffer_type'       => in_array($p['stop_from_liq_buffer_type'] ?? '', ['absolute','percent'], true)
                                                ? (string)$p['stop_from_liq_buffer_type'] : 'percent',
        'bot_budget'                      => max(0.0, (float)($p['bot_budget']   ?? 0.0)),
        'bot_leverage'                    => max(1, (int)($p['bot_leverage']     ?? 1)),
        // Exit (strategy-owned; trailing removed from this module)
        'reverse_pattern_close_enabled'   => ($p['reverse_pattern_close_enabled'] ?? '0') === '1',
        'tp_enabled'                      => ($p['tp_enabled'] ?? '0') === '1',
        'tp_mode'                         => in_array($p['tp_mode'] ?? '', ['fixed_r','fixed_price'], true)
                                                ? (string)$p['tp_mode'] : 'fixed_r',
        'tp_value'                        => max(0.0, (float)($p['tp_value'] ?? 2.0)),
    ];

    try {
        $merged = array_merge(
            require $moduleDir . '/config/base.php',
            $overrides
        );
        $schema = require $moduleDir . '/config/schema.php';
        $errs   = [];
        foreach ($schema as $key => $type) {
            if (!array_key_exists($key, $merged)) {
                continue;
            }
            $v  = $merged[$key];
            $ok = match ($type) {
                'string' => is_string($v),
                'bool'   => is_bool($v),
                'int'    => is_int($v),
                'float'  => is_float($v) || is_int($v),
                'array'  => is_array($v),
                default  => true,
            };
            if (!$ok) {
                $errs[] = "Type mismatch for [{$key}]: expected {$type}";
            }
        }
        if (!empty($errs)) {
            $errMsg = 'Validation failed: ' . implode('; ', $errs);
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $errMsg]);
                exit;
            }
            dblFlash($errMsg, 'danger');
            dblRedirect($configUrl);
        }
    } catch (\Throwable $e) {
        dblFlash('Ошибка загрузки конфигурации: ' . $e->getMessage(), 'danger');
        dblRedirect($configUrl);
    }

    $writeErr = dblWriteActive($moduleDir, $overrides);
    if ($writeErr) {
        dblFlash($writeErr, 'danger');
    } else {
        dblFlash('Настройки сохранены.');
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $writeErr === null]);
        exit;
    }
    dblRedirect($configUrl);
}

if ($action === 'reset_active') {
    $err = dblWriteActive($moduleDir, []);
    dblFlash($err ? $err : 'Конфигурация сброшена к базовым значениям.', $err ? 'danger' : 'success');
    dblRedirect($configUrl);
}

// Fallback
if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
    exit;
}
dblFlash('Неизвестное действие.', 'danger');
dblRedirect($indexUrl);
