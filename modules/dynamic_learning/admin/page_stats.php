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
$run = $svc->getLastRun();
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<div style="max-width:1200px;display:grid;gap:12px;">
  <h3 style="margin:0;">Dynamic Learning — Stats</h3>
  <div><strong>Closed outcomes:</strong> <?= $e(count($outcomes)) ?></div>
  <div><strong>Pattern rows:</strong> <?= $e(count($patterns)) ?></div>
  <div><strong>Quarantined rules:</strong> <?= $e(count($quarantine)) ?></div>
  <div><strong>risk_profile_mode:</strong> <?= $e((string)($run['risk_profile_mode'] ?? 'n/a')) ?></div>
  <div><strong>outcome_classification_profile:</strong> <?= $e((string)($run['outcome_classification_profile'] ?? 'n/a')) ?></div>
  <div><strong>Counts:</strong>
    bad=<?= $e((int)($run['bad_entry_total'] ?? 0)) ?> /
    good=<?= $e((int)($run['good_or_do_not_touch_total'] ?? 0)) ?> /
    exit_issue=<?= $e((int)($run['entry_ok_exit_issue_total'] ?? 0)) ?> /
    neutral=<?= $e((int)($run['neutral_total'] ?? 0)) ?>
  </div>
  <div><strong>outcomes_reclassified_total:</strong> <?= $e((int)($run['outcomes_reclassified_total'] ?? 0)) ?></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;">
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;margin-bottom:8px;">micro shape counts</div>
      <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['micro_impulse_shape_counts'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;margin-bottom:8px;">dump shape counts</div>
      <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['dump_shape_counts'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;margin-bottom:8px;">good vs bad overlap by micro features</div>
      <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['micro_bad_good_overlap_examples'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;">
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;margin-bottom:8px;">top micro bad/good pattern examples</div>
      <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['micro_pattern_examples'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
  </div>
  <pre style="margin:0;background:#0f172a;color:#cbd5e1;padding:12px;border-radius:8px;overflow:auto;"><?= $e(json_encode(array_slice($patterns, 0, 30), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
</div>
