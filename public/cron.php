<?php

declare(strict_types=1);

/**
 * Tredercopis Cron Entry Point
 * 
 * This file is for ISP Manager / crontab integration.
 * Call it via wget/curl or as PHP CLI:
 * 
 * Via HTTP:
 *   wget -q -O /dev/null "http://your-site.com/public/cron.php"
 *   curl -s "http://your-site.com/public/cron.php"
 * 
 * Via CLI:
 *   php /path/to/public/cron.php
 * 
 * With secret key (if configured):
 *   wget -q -O /dev/null "http://your-site.com/public/cron.php?key=YOUR_SECRET"
 */

define('ROOT', dirname(__DIR__));

// Explicit bootstrap - no autoloaders
require_once ROOT . '/core/bootstrap.php';

use Core\System\System;
use Core\Cron\CronManager;
use Core\Storage\StorageManager;
use Core\Logger\Logger;

// Initialize system
System::init([
    'root' => ROOT,
    'env' => 'production',
]);

// Check if system is installed
if (!System::state()->isInstalled()) {
    if (php_sapi_name() === 'cli') {
        echo "System not installed. Please complete installation first.\n";
    } else {
        header('Content-Type: application/json');
        http_response_code(503);
        echo json_encode(['success' => false, 'error' => 'System not installed']);
    }
    exit(1);
}

// Check secret key if configured (for HTTP requests)
if (php_sapi_name() !== 'cli') {
    $storage = StorageManager::instance();
    $settings = $storage->get('system_settings') ?? [];
    $cronSecret = $settings['cron_secret'] ?? '';
    
    if (!empty($cronSecret)) {
        $providedKey = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
        if ($providedKey !== $cronSecret) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Invalid cron key']);
            exit(1);
        }
    }
}

// Run cron
try {
    $cron = CronManager::instance();
    $results = $cron->run();
    
    $successCount = 0;
    $failCount = 0;
    foreach ($results as $result) {
        if ($result['success'] ?? false) {
            $successCount++;
        } else {
            $failCount++;
        }
    }
    
    if (php_sapi_name() === 'cli') {
        // CLI output
        $total = count($results);
        echo "Cron executed at " . date('Y-m-d H:i:s') . "\n";
        echo "Tasks run: {$total} (Success: {$successCount}, Failed: {$failCount})\n";
        
        foreach ($results as $taskId => $result) {
            $status = ($result['success'] ?? false) ? '✓ OK' : '✗ FAIL';
            $duration = $result['duration'] ?? 0;
            echo "  - {$taskId}: {$status} ({$duration}s)\n";
            if (!($result['success'] ?? false) && isset($result['message'])) {
                echo "    Error: {$result['message']}\n";
            }
        }
    } else {
        // HTTP JSON output
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'timestamp' => date('Y-m-d H:i:s'),
            'tasks_run' => count($results),
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'results' => $results
        ], JSON_PRETTY_PRINT);
    }
    
    exit(0);
    
} catch (\Throwable $e) {
    Logger::error('Cron execution failed', ['error' => $e->getMessage()]);
    
    if (php_sapi_name() === 'cli') {
        echo "Cron execution failed: {$e->getMessage()}\n";
    } else {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    exit(1);
}
