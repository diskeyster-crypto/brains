<?php

declare(strict_types=1);

use Core\Auth\Auth;
use Core\System\System;

if (!Auth::check()) {
    header('Location: ' . System::adminUrl('login'));
    exit;
}

$moduleDir = dirname(__DIR__);
require_once $moduleDir . '/service.php';
$svc = \Modules\DynamicLearning\DynamicLearningService::instance($moduleDir);
$patterns = (array)json_decode((string)@file_get_contents($moduleDir . '/storage/patterns/pattern_stats.json'), true);
$outcomes = (array)json_decode((string)@file_get_contents($moduleDir . '/storage/closed_outcomes.json'), true);
$quarantine = (array)json_decode((string)@file_get_contents($moduleDir . '/storage/quarantine/rejected_rules.json'), true);
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<div style="max-width:1200px;display:grid;gap:12px;">
  <h3 style="margin:0;">Dynamic Learning — Stats</h3>
  <div><strong>Closed outcomes:</strong> <?= $e(count($outcomes)) ?></div>
  <div><strong>Pattern rows:</strong> <?= $e(count($patterns)) ?></div>
  <div><strong>Quarantined rules:</strong> <?= $e(count($quarantine)) ?></div>
  <pre style="margin:0;background:#0f172a;color:#cbd5e1;padding:12px;border-radius:8px;overflow:auto;"><?= $e(json_encode(array_slice($patterns, 0, 30), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
</div>
