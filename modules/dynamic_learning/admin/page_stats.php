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
$microDistributions = (array)json_decode((string)@file_get_contents($moduleDir . '/storage/patterns/micro_feature_distributions.json'), true);
$microLabelPurity = (array)json_decode((string)@file_get_contents($moduleDir . '/storage/patterns/micro_label_purity.json'), true);
$microThresholdCandidates = (array)json_decode((string)@file_get_contents($moduleDir . '/storage/patterns/micro_threshold_candidates.json'), true);
$suspiciousMicroLabels = (array)json_decode((string)@file_get_contents($moduleDir . '/storage/patterns/suspicious_micro_labels.json'), true);
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
  <div><strong>hard_stop_reference_roi:</strong> <?= $e((float)($run['hard_stop_reference_roi'] ?? 0.0)) ?></div>
  <div><strong>bad_learning_zone_roi:</strong> <?= $e((float)($run['bad_learning_zone_roi'] ?? 0.0)) ?></div>
  <div><strong>good_learning_threshold_roi:</strong> <?= $e((float)($run['good_learning_threshold_roi'] ?? 0.0)) ?></div>
  <div><strong>pm_profit_reference_roi:</strong> <?= $e((float)($run['pm_profit_reference_roi'] ?? 0.0)) ?></div>
  <div><strong>real_learning_epoch_id:</strong> <?= $e((string)($run['real_learning_epoch_id'] ?? $run['micro_learning_epoch_id'] ?? 'n/a')) ?></div>
  <div><strong>real_learning_epoch_start_at:</strong> <?= $e((string)($run['real_learning_epoch_start_at'] ?? $run['micro_learning_epoch_start_at'] ?? 'n/a')) ?></div>
  <div><strong>previous_epoch_outcomes_excluded_total:</strong> <?= $e((int)($run['previous_epoch_outcomes_excluded_total'] ?? $run['outcomes_excluded_by_epoch_total'] ?? 0)) ?></div>
  <div><strong>active_epoch_outcomes_total:</strong> <?= $e((int)($run['active_epoch_outcomes_total'] ?? $run['epoch_outcomes_total'] ?? 0)) ?></div>
  <div><strong>closed_outcome_time_tolerance_enabled:</strong> <?= $e((bool)($run['closed_outcome_time_tolerance_enabled'] ?? false) ? 'true' : 'false') ?></div>
  <div><strong>closed_outcome_dedupe_closed_at_tolerance_seconds:</strong> <?= $e((int)($run['closed_outcome_dedupe_closed_at_tolerance_seconds'] ?? 0)) ?></div>
  <div><strong>closed_outcomes_near_time_duplicates_merged_total:</strong> <?= $e((int)($run['closed_outcomes_near_time_duplicates_merged_total'] ?? 0)) ?></div>
  <div><strong>Counts:</strong>
    bad=<?= $e((int)($run['bad_entry_total'] ?? 0)) ?> /
    good=<?= $e((int)($run['good_or_do_not_touch_total'] ?? 0)) ?> /
    exit_issue=<?= $e((int)($run['entry_ok_exit_issue_total'] ?? 0)) ?> /
    neutral=<?= $e((int)($run['neutral_total'] ?? 0)) ?>
  </div>
  <div><strong>micro_separability_enabled:</strong> <?= $e((bool)($run['micro_separability_enabled'] ?? false) ? 'true' : 'false') ?></div>
  <div><strong>micro_numeric_features_analyzed_total:</strong> <?= $e((int)($run['micro_numeric_features_analyzed_total'] ?? 0)) ?></div>
  <div><strong>micro_label_values_analyzed_total:</strong> <?= $e((int)($run['micro_label_values_analyzed_total'] ?? 0)) ?></div>
  <div><strong>micro_high_separation_features_total:</strong> <?= $e((int)($run['micro_high_separation_features_total'] ?? 0)) ?></div>
  <div><strong>micro_medium_separation_features_total:</strong> <?= $e((int)($run['micro_medium_separation_features_total'] ?? 0)) ?></div>
  <div><strong>micro_mixed_labels_total:</strong> <?= $e((int)($run['micro_mixed_labels_total'] ?? 0)) ?></div>
  <div><strong>micro_suspicious_labels_total:</strong> <?= $e((int)($run['micro_suspicious_labels_total'] ?? 0)) ?></div>
  <div><strong>outcomes_reclassified_total:</strong> <?= $e((int)($run['outcomes_reclassified_total'] ?? 0)) ?></div>
  <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
    <div style="font-size:12px;opacity:.8;margin-bottom:8px;">closed_outcomes_near_time_duplicate_examples</div>
    <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['closed_outcomes_near_time_duplicate_examples'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;">
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;margin-bottom:8px;">top micro bad separators</div>
      <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['top_micro_bad_separators'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;margin-bottom:8px;">top micro good separators</div>
      <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['top_micro_good_separators'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;margin-bottom:8px;">mixed labels (do not hard-block)</div>
      <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['micro_mixed_label_examples'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;margin-bottom:8px;">suspicious labels</div>
      <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['micro_suspicious_label_examples'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;margin-bottom:8px;">threshold candidates (observe_only)</div>
      <pre style="margin:0;overflow:auto;"><?= $e(json_encode(array_slice((array)($microThresholdCandidates['candidates'] ?? []), 0, 10), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;margin-bottom:8px;">top numeric separability features</div>
      <pre style="margin:0;overflow:auto;"><?= $e(json_encode(array_slice((array)($microDistributions['features'] ?? []), 0, 10), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;margin-bottom:8px;">top label purity rows</div>
      <pre style="margin:0;overflow:auto;"><?= $e(json_encode(array_slice((array)($microLabelPurity['labels'] ?? []), 0, 10), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;margin-bottom:8px;">suspicious labels with examples</div>
      <pre style="margin:0;overflow:auto;"><?= $e(json_encode(array_slice((array)($suspiciousMicroLabels['labels'] ?? []), 0, 6), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
  </div>
</div>
