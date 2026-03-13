#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Bybit Gateway Diagnostic Script
 * 
 * Standalone CLI script for diagnosing Bybit API connectivity.
 * Can be run directly from shell: /usr/bin/php scripts/bybit_diagnostic.php
 * 
 * Features:
 * - Shows KeyCenter credentials status
 * - Tests public API (no auth required)
 * - Tests private API (auth required)
 * - Full error classification
 * 
 * Usage:
 *   php scripts/bybit_diagnostic.php [options]
 * 
 * Options:
 *   --json      Output full JSON diagnostic
 *   --account   Account name (default: default)
 */

// Determine ROOT path relative to this script
define('ROOT', dirname(__DIR__));

// Bootstrap core - explicit require, no autoloaders
require_once ROOT . '/core/bootstrap.php';

use Core\System\System;
use Core\Gateway\Bybit;
use Core\KeyCenter\KeyCenter;

// Initialize system - REQUIRED for all entry points
System::init([
    'root' => ROOT,
    'env' => 'dev',
]);

// Parse CLI arguments
$args = $_SERVER['argv'] ?? [];
$showJson = in_array('--json', $args);
$account = 'default';

// Find --account value
foreach ($args as $i => $arg) {
    if ($arg === '--account' && isset($args[$i + 1])) {
        $account = $args[$i + 1];
    }
}

// ASCII header
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║         TREDERCOPIS BYBIT GATEWAY DIAGNOSTIC                 ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  Mainnet: https://api.bybit.com                              ║\n";
echo "║  Account: {$account}                                             ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Run diagnostic
$diagnostic = Bybit::diagnostic($account);

// ============== KEY CENTER STATUS ==============
echo "📦 KEY CENTER STATUS\n";
echo str_repeat('─', 50) . "\n";
$kc = $diagnostic['keycenter'] ?? [];
echo "  Storage exists:     " . ($kc['storage_exists'] ? '✅ Yes' : '❌ No') . "\n";
echo "  Bybit credentials:  " . ($kc['has_bybit_credentials'] ? '✅ Set' : '❌ Not set') . "\n";
echo "  Credentials source: " . ($diagnostic['credentials_source'] ?? 'unknown') . "\n";

if (!empty($kc['services'])) {
    echo "  Services:           " . implode(', ', $kc['services']) . "\n";
}

if (!$kc['has_bybit_credentials']) {
    echo "\n  ⚠️  To set credentials:\n";
    echo "     php index.php keys set bybit <api_key> <api_secret>\n";
}

// ============== GATEWAY STATUS ==============
echo "\n📊 GATEWAY STATUS\n";
echo str_repeat('─', 50) . "\n";
echo "  Gateway:       {$diagnostic['gateway']}\n";
echo "  Base URL:      {$diagnostic['base_url']}\n";
echo "  Has creds:     " . ($diagnostic['has_credentials'] ? '✅ Yes' : '❌ No') . "\n";
if (isset($diagnostic['api_key_prefix'])) {
    echo "  API Key:       {$diagnostic['api_key_prefix']}\n";
}
echo "  PHP Version:   {$diagnostic['php_version']}\n";
echo "  cURL Version:  {$diagnostic['curl_version']}\n";
echo "  Timestamp:     {$diagnostic['timestamp']}\n";

// ============== TESTS ==============
echo "\n🧪 API TESTS\n";
echo str_repeat('─', 50) . "\n";

// Server Time Test
if (isset($diagnostic['tests']['server_time'])) {
    $test = $diagnostic['tests']['server_time'];
    $icon = $test['success'] ? '✅' : '❌';
    echo "\n{$icon} Server Time (market.time)\n";
    echo "   HTTP: " . ($test['http_code'] ?? 'N/A') . " | ";
    echo "Code: " . ($test['ret_code'] ?? 'N/A') . " | ";
    echo "Type: " . ($test['error_type'] ?? 'none') . "\n";
    if (isset($test['server_time'])) {
        echo "   Server time: {$test['server_time']}\n";
    }
    if (isset($test['error'])) {
        echo "   Error: {$test['error']}\n";
    }
    if (isset($test['raw_text_preview']) && !$test['success']) {
        echo "   Response: " . substr($test['raw_text_preview'], 0, 100) . "\n";
    }
}

// Public API Test
if (isset($diagnostic['tests']['public_api'])) {
    $test = $diagnostic['tests']['public_api'];
    $icon = $test['success'] ? '✅' : '❌';
    echo "\n{$icon} Public API (market.tickers BTCUSDT)\n";
    echo "   HTTP: " . ($test['http_code'] ?? 'N/A') . " | ";
    echo "Code: " . ($test['ret_code'] ?? 'N/A') . " | ";
    echo "Type: " . ($test['error_type'] ?? 'none') . "\n";
    if (isset($test['btc_price'])) {
        echo "   BTC/USDT: \${$test['btc_price']}\n";
    }
    if (isset($test['raw_text_length'])) {
        echo "   Response: {$test['raw_text_length']} bytes\n";
    }
    if (isset($test['error'])) {
        echo "   Error: {$test['error']}\n";
    }
}

// Private API Test - Positions
if (isset($diagnostic['tests']['positions'])) {
    $test = $diagnostic['tests']['positions'];
    
    if (isset($test['skipped'])) {
        echo "\n⏭️  Positions API (skipped)\n";
        echo "   Reason: {$test['reason']}\n";
    } else {
        $icon = $test['success'] ? '✅' : '❌';
        echo "\n{$icon} Positions API (positions.list category=linear)\n";
        echo "   HTTP: " . ($test['http_code'] ?? 'N/A') . " | ";
        echo "Code: " . ($test['ret_code'] ?? 'N/A') . " | ";
        echo "Type: " . ($test['error_type'] ?? 'none') . "\n";
        echo "   Message: " . ($test['ret_msg'] ?? 'N/A') . "\n";
        
        if (isset($test['raw_text_preview']) && !$test['success']) {
            echo "   Response: " . substr($test['raw_text_preview'], 0, 200) . "\n";
        }
        
        // Error type explanation with probable cause
        if (isset($test['error_type']) && $test['error_type'] !== 'none') {
            echo "\n   📋 Error type: {$test['error_type']}\n";
            if (isset($test['probable_cause'])) {
                echo "   → Probable cause: {$test['probable_cause']}\n";
            }
        }
    }
}

// Private API Test - Wallet
if (isset($diagnostic['tests']['wallet'])) {
    $test = $diagnostic['tests']['wallet'];
    
    if (isset($test['skipped'])) {
        echo "\n⏭️  Wallet API (skipped)\n";
        echo "   Reason: {$test['reason']}\n";
    } else {
        $icon = $test['success'] ? '✅' : '❌';
        echo "\n{$icon} Wallet API (account.wallet accountType=UNIFIED)\n";
        echo "   HTTP: " . ($test['http_code'] ?? 'N/A') . " | ";
        echo "Code: " . ($test['ret_code'] ?? 'N/A') . " | ";
        echo "Type: " . ($test['error_type'] ?? 'none') . "\n";
        echo "   Message: " . ($test['ret_msg'] ?? 'N/A') . "\n";
        
        if (isset($test['raw_text_preview']) && !$test['success']) {
            echo "   Response: " . substr($test['raw_text_preview'], 0, 200) . "\n";
        }
        
        // Error type explanation with probable cause
        if (isset($test['error_type']) && $test['error_type'] !== 'none') {
            echo "\n   📋 Error type: {$test['error_type']}\n";
            if (isset($test['probable_cause'])) {
                echo "   → Probable cause: {$test['probable_cause']}\n";
            }
        }
    }
}

// ============== SUMMARY ==============
echo "\n📋 SUMMARY\n";
echo str_repeat('─', 50) . "\n";
if (isset($diagnostic['summary'])) {
    $sum = $diagnostic['summary'];
    echo "  Server Time: " . ($sum['server_time_ok'] ? '✅ OK' : '❌ FAIL') . "\n";
    echo "  Public API:  " . ($sum['public_api_ok'] ? '✅ OK' : '❌ FAIL') . "\n";
    echo "  Positions:   " . ($sum['positions_ok'] ? '✅ OK' : '⏭️  SKIP/FAIL') . "\n";
    echo "  Wallet:      " . ($sum['wallet_ok'] ? '✅ OK' : '⏭️  SKIP/FAIL') . "\n";
    
    $allOk = $sum['server_time_ok'] && $sum['public_api_ok'] && $sum['positions_ok'] && $sum['wallet_ok'];
    echo "\n  Result: " . ($allOk ? '✅ ALL TESTS PASSED' : '⚠️  SOME TESTS INCOMPLETE') . "\n";
}

// Full JSON output
if ($showJson) {
    echo "\n📄 FULL JSON OUTPUT\n";
    echo str_repeat('─', 50) . "\n";
    echo json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "\n💡 Run with --json for full diagnostic output\n";
}

echo "\n";

/* RULES
- Purpose: Standalone Bybit diagnostic script
- Config sources: config/bybit.php for api_base
- Paths: Defines ROOT, then only System::path()
- Logs: None (outputs to stdout)
- Prohibitions:
  - Credentials ONLY from KeyCenter
  - NO hardcoded API keys
*/
