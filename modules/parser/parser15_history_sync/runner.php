<?php
declare(strict_types=1);

/**
 * Parser 1.5: History Sync — CLI/Manual runner
 *
 * Usage:
 *   php modules/parser/parser15_history_sync/runner.php
 */

require_once __DIR__ . '/service.php';

$svc = new Parser15HistorySyncService();
$result = $svc->execute();

echo json_encode([
    'success' => (bool)($result['ok'] ?? false),
    'result' => $result,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

/* RULES
- Manual runner only
- Must not change cron/service contracts
*/
