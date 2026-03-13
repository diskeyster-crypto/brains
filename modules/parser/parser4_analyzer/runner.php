<?php
declare(strict_types=1);

/**
 * Parser4 Analyzer (Runner)
 *
 * Run from project root:
 *   php modules/parser/parser4_analyzer/runner.php
 */

require_once 'core/bootstrap.php';
require_once 'modules/parser/parser4_analyzer/service.php';

$svc = new Parser4AnalyzerService();
$result = $svc->execute();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

/* RULES (Tredercopis Architecture)
--------------------------------------------------
- SystemPaths ONLY for real paths.
- No filesystem guessing. Use SystemPaths for paths inside service.
- Outputs only into module storage.
- PHP 8.2+ safe (no dynamic properties).
-------------------------------------------------- */
