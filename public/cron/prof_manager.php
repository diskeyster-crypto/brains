<?php

declare(strict_types=1);

/**
 * Profit Manager — Cron Tick Endpoint
 *
 * Paper-only profit-lock manager cron handler.
 * Intended cron interval: 60 seconds (1 minute).
 *
 * PM tick interval should not be faster than market/position data refresh
 * frequency. Current intended interval is 1 minute.
 *
 * Usage (HTTP):
 *   curl -s "https://your-site.com/public/cron/prof_manager.php?token=YOUR_TOKEN"
 *
 * Usage (CLI):
 *   php /path/to/public/cron/prof_manager.php --token=YOUR_TOKEN
 *
 * Crontab example (every minute):
 *   * * * * * curl -s "https://your-site.com/public/cron/prof_manager.php?token=YOUR_TOKEN" > /dev/null 2>&1
 *
 * Security:
 *   A cron_token must be configured in modules/prof_manager/config/active.php.
 *   If no token is configured yet, this endpoint auto-generates one and saves it.
 *   All requests without a valid token are rejected with HTTP 401.
 *
 * SAFETY RULES:
 *   - This endpoint is paper-only. No real orders are placed.
 *   - PM only manages positive ROI profit-lock logic.
 *   - PM does not manage negative stop-loss (that is Stop Manager's domain).
 *   - PM does not call any exchange API.
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

// Auto-generate cron_token if missing
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
    // CLI: --token=VALUE or TOKEN env var
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

// ── Load PM service and tick ──────────────────────────────────────────────────

$serviceFile = $moduleDir . '/service.php';
if (!is_file($serviceFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_not_found']);
    exit;
}

try {
    // Load all dependencies via bootstrap (includes lib/ and profiles/)
    $bootstrapPm = $moduleDir . '/bootstrap.php';
    if (is_file($bootstrapPm)) {
        require_once $bootstrapPm;
    } else {
        // Fallback: glob lib files for backward compatibility
        $libFiles = glob($moduleDir . '/lib/*.php') ?: [];
        foreach ($libFiles as $libFile) {
            require_once $libFile;
        }
    }
    require_once $serviceFile;

    $svc    = new \Modules\ProfManager\ProfManagerService($moduleDir);
    $result = $svc->tick();
    $result['cron_interval_sec'] = 60;
    $result['endpoint']          = 'public/cron/prof_manager.php';

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
