<?php
declare(strict_types=1);

/**
 * Parser 5: Signal Monitor Engine — CLI Runner
 *
 * Usage:
 *   php runner.php
 *   php runner.php --debug
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once 'service.php';

echo "=== Parser5 Signal Monitor Engine ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $service = new Parser5SignalMonitorService();
    $result = $service->execute();

    echo "Status: " . ($result['ok'] ? 'OK' : 'ERROR') . "\n";
    echo "Candidates loaded: " . ($result['candidates_loaded'] ?? 0) . "\n";
    echo "New to monitor: " . ($result['new_candidates'] ?? 0) . "\n";
    echo "Currently monitored: " . ($result['monitored_count'] ?? 0) . "\n";
    echo "Confirmed: " . ($result['confirmed_count'] ?? 0) . "\n";
    echo "Signals published: " . ($result['signals_published'] ?? 0) . "\n";
    echo "Expired: " . ($result['expired_count'] ?? 0) . "\n";
    echo "Duration: " . ($result['duration_ms'] ?? 0) . " ms\n";

    if (!empty($result['errors'])) {
        echo "\nErrors:\n";
        foreach ($result['errors'] as $error) {
            echo "  - {$error}\n";
        }
    }

    echo "\nDone.\n";
    exit($result['ok'] ? 0 : 1);

} catch (Throwable $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

/* ==========================================================
   RULES (Tredercopis / Parser5)
   ==========================================================
   - CLI-only runner.
   - No business logic here: only call Parser5SignalMonitorService->execute().
   - LF only.
   ========================================================== */

/* RULES
- LF only
- CLI helper only
- Do not compute project paths here; service.php uses SystemPaths
*/
