<?php
declare(strict_types=1);

/**
 * Parser 2: History Accumulator — CLI/Manual runner
 *
 * Usage:
 *   php modules/parser2_history_accumulator/runner.php
 */

require_once __DIR__ . '/service.php';

$svc = new Parser2HistoryAccumulatorService();
$result = $svc->execute();

echo json_encode([
    'success' => (bool)($result['success'] ?? false),
    'result' => $result,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

/* RULES
- Manual runner only
- Must not change cron/service contracts
*/
