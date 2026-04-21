<?php

declare(strict_types=1);

/**
 * Pattern Strategy — AJAX Handler
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

$moduleDir = SystemPaths::instance()->get('strategy.pattern');

require_once $moduleDir . '/service.php';
require_once $moduleDir . '/bootstrap.php';

use Modules\Strategy\Pattern\PatternService;
use Modules\Strategy\Pattern\PatternBootstrap;

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$isAjax = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
    isset($_GET['json'])
);

$service    = PatternService::instance($moduleDir);
$configUrl  = System::web('admin/strategy/pattern/config');
$indexUrl   = System::web('admin/strategy/pattern');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function patternWriteActive(string $moduleDir, array $overrides): ?string
{
    $path  = $moduleDir . '/config/active.php';
    $lines = ["<?php\n\ndeclare(strict_types=1);\n\n"];
    $lines[] = "/**\n * Pattern Strategy — Active Config Overrides\n"
             . " * Written by the admin UI. Edit via the config page.\n */\n\n";
    $lines[] = "return " . var_export($overrides, true) . ";\n";
    $ok = file_put_contents($path, implode('', $lines));
    return $ok !== false ? null : 'Failed to write active.php';
}

function patternFlash(string $msg, string $type = 'success'): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['pattern_flash'] = ['msg' => $msg, 'type' => $type];
}

function patternRedirect(string $url): void
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
    patternFlash($result['ok'] ? 'Запуск поставлен в очередь (' . ($result['total'] ?? 0) . ' символов).' : ($result['error'] ?? 'Ошибка'), $result['ok'] ? 'success' : 'danger');
    patternRedirect($indexUrl);
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
    patternFlash($msg, $t);
    patternRedirect($indexUrl);
}

if ($action === 'save_config') {
    $p = $_POST;

    $overrides = [
        'enabled'                  => ($p['enabled']    ?? '0') === '1',
        'mode'                     => (string)($p['mode']     ?? 'passive'),
        'side_mode'                => (string)($p['side_mode'] ?? 'both'),
        'market_regime_enabled'    => ($p['market_regime_enabled']    ?? '1') === '1',
        'market_regime_gate_mode'  => (string)($p['market_regime_gate_mode'] ?? 'soft'),
        'trend_required'           => ($p['trend_required']   ?? '1') === '1',
        'corridor_required'        => ($p['corridor_required'] ?? '1') === '1',
        'corridor_lookback_hours'  => (int)($p['corridor_lookback_hours'] ?? 24),
        'corridor_bucket_count'    => (int)($p['corridor_bucket_count']   ?? 10),
        'wave_required'            => ($p['wave_required']    ?? '1') === '1',
        'confirm_required'         => ($p['confirm_required'] ?? '1') === '1',
        'confirm_max_bars'         => (int)($p['confirm_max_bars'] ?? 2),
        'signal_ttl_bars'          => (int)($p['signal_ttl_bars']  ?? 2),
    ];

    // Validate against schema before writing
    try {
        $boot   = PatternBootstrap::instance($moduleDir);
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
            patternFlash($errMsg, 'danger');
            patternRedirect($configUrl);
        }
    } catch (\Throwable $e) {
        patternFlash('Ошибка загрузки конфигурации: ' . $e->getMessage(), 'danger');
        patternRedirect($configUrl);
    }

    $writeErr = patternWriteActive($moduleDir, $overrides);
    if ($writeErr) {
        patternFlash($writeErr, 'danger');
    } else {
        patternFlash('Настройки сохранены.');
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $writeErr === null]);
        exit;
    }
    patternRedirect($configUrl);
}

if ($action === 'reset_active') {
    $err = patternWriteActive($moduleDir, []);
    patternFlash($err ? $err : 'Конфигурация сброшена к базовым значениям.', $err ? 'danger' : 'success');
    patternRedirect($configUrl);
}

// Fallback
if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
    exit;
}
patternFlash('Неизвестное действие.', 'danger');
patternRedirect($indexUrl);
