<?php

declare(strict_types=1);

/**
 * Fish Strategy — Cron Entry Point
 *
 * Called by the system cron runner (or directly from CLI / HTTP).
 * Processes one batch of the current queued/running batched run.
 * If no run is queued, exits cleanly with a no-op result.
 *
 * For small manual_list runs the admin UI calls service->run() directly.
 * This cron handler is designed for the batched all-universe smoke-test path.
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
$result  = $service->tickBatch();

if (PHP_SAPI === 'cli') {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
