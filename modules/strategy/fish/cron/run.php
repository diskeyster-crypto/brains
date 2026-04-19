<?php

declare(strict_types=1);

/**
 * Fish Strategy — Cron Entry Point
 *
 * Called by the system cron runner.
 * Bootstraps the module and delegates to FishService::run().
 */

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__, 4)); // modules/strategy/fish/cron → root
}

require_once ROOT . '/core/bootstrap.php';

use Core\System\SystemPaths;

$moduleDir = SystemPaths::instance()->get('strategy.fish');

require_once $moduleDir . '/service.php';

use Modules\Strategy\Fish\FishService;

$service = FishService::instance($moduleDir);
$result  = $service->run();

// Output result as JSON when called from CLI or HTTP cron endpoint
if (PHP_SAPI === 'cli') {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
