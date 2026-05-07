<?php

declare(strict_types=1);

/**
 * Confirmed Continuation Strategy — Admin AJAX Handler
 *
 * Handles: save_config, queue_run
 */

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

if (!Auth::check()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$moduleDir = SystemPaths::instance()->get('strategy.confirmed_continuation');

require_once $moduleDir . '/service.php';
require_once $moduleDir . '/bootstrap.php';

use Modules\Strategy\ConfirmedContinuation\ConfirmedContinuationService;
use Modules\Strategy\ConfirmedContinuation\ConfirmedContinuationBootstrap;

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

switch ($action) {
    case 'queue_run':
        $service = ConfirmedContinuationService::instance($moduleDir);
        $result  = $service->queueRun();
        echo json_encode(['ok' => true, 'result' => $result]);
        break;

    case 'tick_batch':
        $service = ConfirmedContinuationService::instance($moduleDir);
        $result  = $service->tickBatch();
        echo json_encode(['ok' => true, 'result' => $result]);
        break;

    case 'get_last_run':
        $path    = $moduleDir . '/storage/last_run.json';
        $content = is_file($path) ? @file_get_contents($path) : '{}';
        echo $content !== false ? $content : '{}';
        break;

    case 'get_signals':
        $path    = $moduleDir . '/storage/signals.json';
        $content = is_file($path) ? @file_get_contents($path) : '[]';
        echo $content !== false ? $content : '[]';
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => "Unknown action: {$action}"]);
        break;
}
