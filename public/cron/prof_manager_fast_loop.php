<?php

declare(strict_types=1);

/**
 * Profit Manager — Fast Loop Cron Endpoint
 *
 * Called by ISP cron once per minute. Internally runs lightweight PM fast ticks
 * every fast_loop_interval_seconds (default: 15 s) for up to
 * fast_loop_max_runtime_seconds (default: 55 s) per minute, executing up to
 * fast_loop_max_ticks_per_run (default: 4) ticks.
 *
 * This file does NOT call Cron Manager and does NOT trigger other modules.
 * It only bootstraps the project, loads ProfManagerService, and calls
 * ProfManagerService::tickFastLoop().
 *
 * Recommended ISP cron (run once per minute):
 *   wget -q -O /dev/null "http://your-domain.com/public/cron/prof_manager_fast_loop.php?token=YOUR_TOKEN"
 *   or
 *   curl -s -o /dev/null "http://your-domain.com/public/cron/prof_manager_fast_loop.php?token=YOUR_TOKEN"
 *
 * The internal loop handles 15-second PM ticks (3–4 ticks per minute).
 * Cron Manager remains unchanged. No other tasks are triggered.
 *
 * Runtime output files:
 *   modules/prof_manager/storage/runtime/last_fast_loop.json  — loop summary
 *   modules/prof_manager/storage/runtime/last_fast_run.json   — last tick result
 *
 * SAFETY:
 *   - Demo-only mode by default. No live orders are opened.
 *   - PM only manages positive-ROI profit-lock logic.
 *   - PM does not manage stop-loss (that is Stop Manager's domain).
 *   - No Cron Manager. No other modules.
 */

define('ROOT', dirname(__DIR__, 2));

header('Content-Type: application/json; charset=utf-8');

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$bootstrapFile = ROOT . '/core/bootstrap.php';
if (!is_file($bootstrapFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'bootstrap_not_found']);
    exit;
}

try {
    require_once $bootstrapFile;
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'bootstrap_failed', 'detail' => $e->getMessage()]);
    exit;
}

// ── Resolve token from config ────────────────────────────────────────────────

$moduleDir  = ROOT . '/modules/prof_manager';
$activeFile = $moduleDir . '/config/active.php';
$configFile = $moduleDir . '/config/config.php';

$activeConfig = [];
if (is_file($activeFile)) {
    try {
        $loaded = @include $activeFile;
        if (is_array($loaded)) {
            $activeConfig = $loaded;
        }
    } catch (\Throwable) {}
}

// Auto-generate cron_token if missing (same as prof_manager.php)
if (empty($activeConfig['cron_token'])) {
    $newToken = bin2hex(random_bytes(24));
    $activeConfig['cron_token'] = $newToken;

    $php  = "<?php\n\ndeclare(strict_types=1);\n\n";
    $php .= "/**\n * Profit Manager Module — Active Config Overrides\n";
    $php .= " * Written by the admin UI. Do not edit manually.\n */\n\n";
    $php .= "return ";
    $php .= var_export($activeConfig, true);
    $php .= ";\n";

    $dir = dirname($activeFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($activeFile, $php);
}

$configuredToken = (string) ($activeConfig['cron_token'] ?? '');

// ── Authenticate ──────────────────────────────────────────────────────────────

$providedToken = '';
if (php_sapi_name() === 'cli') {
    // CLI: --token=VALUE or PM_CRON_TOKEN env var
    foreach ($_SERVER['argv'] ?? [] as $arg) {
        if (str_starts_with($arg, '--token=')) {
            $providedToken = substr($arg, 8);
            break;
        }
    }
    if ($providedToken === '') {
        $providedToken = (string) getenv('PM_CRON_TOKEN');
    }
} else {
    $providedToken = $_GET['token'] ?? $_SERVER['HTTP_X_PM_CRON_TOKEN'] ?? '';
}

if ($configuredToken === '' || !hash_equals($configuredToken, (string) $providedToken)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_token']);
    exit;
}

// ── Load PM service and run fast loop ─────────────────────────────────────────

$serviceFile = $moduleDir . '/service.php';
if (!is_file($serviceFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_not_found']);
    exit;
}

try {
    $bootstrapPm = $moduleDir . '/bootstrap.php';
    if (is_file($bootstrapPm)) {
        require_once $bootstrapPm;
    } else {
        // Fallback: load lib files for backward compatibility
        $libFiles = glob($moduleDir . '/lib/*.php') ?: [];
        foreach ($libFiles as $libFile) {
            require_once $libFile;
        }
    }
    require_once $serviceFile;

    $svc    = new \Modules\ProfManager\ProfManagerService($moduleDir);
    $result = $svc->tickFastLoop();

    http_response_code(200);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);
}
