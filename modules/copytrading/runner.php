<?php
declare(strict_types=1);

/**
 * Copytrading Parser — Runner
 *
 * Entry point for cron execution.
 *
 * @package Modules\Copytrading
 */

require_once __DIR__ . '/service.php';

$service = new CopytradingService();
$result = $service->execute();

// Output result for cron logging
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
