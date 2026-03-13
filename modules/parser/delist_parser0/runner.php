<?php
declare(strict_types=1);

/**
 * Delist Parser 0 — CLI Runner
 * 
 * Usage:
 *   php runner.php
 * 
 * This script runs the DelistParser0Service and outputs the result.
 */

// Bootstrap
if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__, 3));
}
require_once ROOT . '/core/bootstrap.php';

use Core\System\System;

// Initialize system
System::init(['root' => ROOT, 'env' => 'dev']);

// Load service
require_once __DIR__ . '/service.php';

// Run
$service = new DelistParser0Service();
$result = $service->run();

// Output
echo "=== DelistParser0 Result ===\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

exit($result['success'] ? 0 : 1);
