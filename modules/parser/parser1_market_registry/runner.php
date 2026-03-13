<?php
declare(strict_types=1);

/**
 * Parser 1: Market Registry — Runner
 *
 * Manual run:
 *   php modules/parser1_market_registry/runner.php
 */

define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/core/bootstrap.php';

require_once __DIR__ . '/service.php';

if (!class_exists('Parser1MarketRegistryService')) {
    echo "Error: Parser1MarketRegistryService class not found\n";
    exit(1);
}

$svc = new Parser1MarketRegistryService();
$res = $svc->execute();

echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
