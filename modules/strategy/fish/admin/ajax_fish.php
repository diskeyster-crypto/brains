<?php

declare(strict_types=1);

/**
 * Fish Strategy — AJAX Handler
 *
 * Handles POST actions from admin pages:
 *   action=run  → trigger a service run
 *
 * Responds with JSON or redirects depending on caller context.
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

$moduleDir = SystemPaths::instance()->get('strategy.fish');

require_once $moduleDir . '/service.php';

use Modules\Strategy\Fish\FishService;

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$isAjax = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
    isset($_GET['json'])
);

$service = FishService::instance($moduleDir);

switch ($action) {
    case 'run':
        try {
            $result = $service->run();
        } catch (\Throwable $e) {
            $result = [
                'ok'     => false,
                'status' => 'error',
                'error'  => $e->getMessage(),
            ];
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
        } else {
            // Browser form POST — redirect back to overview
            header('Location: ' . System::web('admin/strategy/fish'));
        }
        break;

    default:
        http_response_code(400);
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
        } else {
            header('Location: ' . System::web('admin/strategy/fish'));
        }
        break;
}
exit;
