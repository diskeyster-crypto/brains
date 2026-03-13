<?php
declare(strict_types=1);

/**
 * Parser 6: Simulator (Runner)
 *
 * Manual run (from project root):
 *   php modules/simulator/parser6_simulator/runner.php
 *
 * Runner contains no business logic. It only boots the system and executes the service.
 */

// Prefer ROOT from environment; otherwise assume current working directory is project root.
if (!defined('ROOT')) {
    $cwd = getcwd() ?: '';
    if ($cwd !== '' && is_file($cwd . '/core/bootstrap.php')) {
        define('ROOT', $cwd);
    } else {
        // Try parent directories
        $dir = __DIR__;
        while ($dir !== '/' && $dir !== '') {
            if (is_file($dir . '/core/bootstrap.php')) {
                define('ROOT', $dir);
                break;
            }
            $dir = dirname($dir);
        }
        if (!defined('ROOT')) {
            echo "Error: ROOT is not defined and core/bootstrap.php not found.\n";
            exit(1);
        }
    }
}

require_once ROOT . '/core/bootstrap.php';

// Initialize system paths
\Core\System\System::init();

// Try to find module via SystemPaths or fallback
$moduleDir = null;
$candidates = ['simulator.parser6_simulator', 'parser.parser6_simulator'];
$paths = \Core\System\SystemPaths::instance();

foreach ($candidates as $key) {
    try {
        $p = $paths->get($key);
        if (is_string($p) && $p !== '') {
            $moduleDir = $p;
            break;
        }
    } catch (\Throwable $e) {
        // ignore
    }
}

if ($moduleDir === null) {
    $moduleDir = __DIR__;
}

$servicePath = rtrim($moduleDir, '/') . '/service.php';
if (!is_file($servicePath)) {
    echo "Error: service.php not found at: {$servicePath}\n";
    exit(1);
}

require_once $servicePath;

if (!class_exists(Parser6SimulatorService::class)) {
    echo "Error: Parser6SimulatorService class not found\n";
    exit(1);
}

$service = new Parser6SimulatorService();
$result = $service->execute();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit(($result['ok'] ?? false) ? 0 : 2);

/* RULES (Tredercopis Architecture)
--------------------------------------------------
- Runner is for CLI/manual execution only.
- Boots via ROOT and SystemPaths, then executes service.
- Uses Parser2 NDJSON stream, NOT Bybit API
-------------------------------------------------- */
