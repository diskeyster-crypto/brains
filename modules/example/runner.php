<?php

declare(strict_types=1);

/**
 * Example Module Runner
 * 
 * Can be run independently via: php modules/example/runner.php
 * Modules don't require other modules - only core.
 */

// Determine ROOT from module location
define('ROOT', dirname(__DIR__, 2));

// Bootstrap core
require_once ROOT . '/core/bootstrap.php';

use Core\System\System;
use Core\Logger\Logger;
use Core\Cron\CronManager;

// Initialize system
System::init([
    'root' => ROOT,
    'env' => 'dev',
]);

echo "Example Module Runner\n";
echo "=====================\n\n";

// Register this module's cron task
CronManager::register('example', 'execute', 60);
echo "Cron task registered: example:execute (60s interval)\n";

// Load and execute service
require_once __DIR__ . '/service.php';

if (!class_exists('ExampleService')) {
    echo "Error: ExampleService class not found\n";
    exit(1);
}

$service = new ExampleService();
echo "Executing service handler...\n";
$service->execute();

echo "\nModule runner completed.\n";
Logger::info('Example module runner executed', ['module' => 'example']);
