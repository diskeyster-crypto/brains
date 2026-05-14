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

  <!-- ── Rolling Quality Guard Summary ─────────────────────────────── -->
  <div style="border:1px solid #a5b4fc44;border-radius:10px;padding:14px;background:rgba(165,180,252,.05);">
    <div style="font-size:13px;font-weight:700;color:#a5b4fc;margin-bottom:10px;">
      <i class="bi bi-shield-check" style="margin-right:6px;"></i>Rolling Quality Guard — Decision
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin-bottom:8px;">
      <div><strong>rolling_learning_enabled:</strong> <?= $e((bool)($run['rolling_learning_enabled'] ?? false) ? 'true' : 'false') ?></div>
      <div><strong>window_minutes:</strong> <?= $e((int)($run['rolling_learning_window_minutes'] ?? 120)) ?></div>
      <div><strong>rolling_window_outcomes:</strong> <?= $e((int)($run['rolling_window_outcomes_total'] ?? 0)) ?></div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin-bottom:8px;">
      <div><strong>default_quality_score:</strong> <?= $e($run['default_quality_score'] ?? 'n/a') ?></div>
      <div><strong>active_dynamic_quality_score:</strong> <?= $e($run['active_dynamic_quality_score'] ?? 'none') ?></div>
      <div><strong>candidate_quality_score:</strong> <?= $e($run['candidate_quality_score'] ?? 'n/a') ?></div>
      <div><strong>candidate_vs_default_delta:</strong> <?= $e($run['candidate_vs_default_delta_pct'] ?? 'n/a') ?></div>
      <div><strong>no_change_band:</strong> ±<?= $e((float)($run['no_change_band_pct'] ?? 5.0)) ?></div>
      <div><strong>min_improvement:</strong> +<?= $e((float)($run['min_candidate_improvement_pct'] ?? 7.0)) ?></div>
    </div>
    <?php
    $promoDecision = (string)($run['promotion_decision'] ?? 'none');
    $promoColor = match($promoDecision) {
        'promote_candidate_demo', 'candidate_ready_but_apply_disabled' => '#86efac',
        'reject_candidate' => '#f87171',
        'keep_current' => '#fcd34d',
        default => '#94a3b8',
    };
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin-bottom:8px;">
      <div>
        <strong>candidate_status:</strong>
        <span style="color:#c4b5fd;"><?= $e((string)($run['candidate_status'] ?? 'pending')) ?></span>
      </div>
      <div>
        <strong>promotion_decision:</strong>
        <span style="color:<?= $promoColor ?>;"><?= $e($promoDecision) ?></span>
      </div>
      <div>
        <strong>promotion_reason:</strong>
        <span style="color:#94a3b8;font-size:12px;"><?= $e((string)($run['promotion_reason'] ?? '—')) ?></span>
      </div>
    </div>
    <?php if ((bool)($run['rollback_required'] ?? false)): ?>
    <div style="background:rgba(248,81,73,.10);border:1px solid #f8514955;border-radius:7px;padding:8px;color:#f87171;">
      <strong>⚠ ROLLBACK REQUIRED</strong> — <?= $e((string)($run['rollback_reason'] ?? '')) ?>
      <?php if (!empty($run['rollback_cooldown_until'])): ?>
        · cooldown until <?= $e((string)$run['rollback_cooldown_until']) ?>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="color:#86efac;font-size:12px;">rollback_required: no</div>
    <?php endif; ?>

    <!-- Default result summary -->
    <?php $defSummary = is_array($run['default_result_summary'] ?? null) ? $run['default_result_summary'] : null; ?>
    <?php if ($defSummary !== null && ($defSummary['benchmark_available'] ?? false)): ?>
    <div style="margin-top:10px;">
      <div style="font-size:12px;font-weight:600;color:#86efac;margin-bottom:4px;">Default benchmark (full epoch):</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:6px;font-size:12px;">
        <span>trades: <strong><?= $e($defSummary['trades_total']) ?></strong></span>
        <span>bad: <strong><?= $e($defSummary['bad_entry_total']) ?></strong></span>
        <span>good: <strong><?= $e($defSummary['good_or_do_not_touch_total']) ?></strong></span>
        <span>winrate: <strong><?= $e($defSummary['winrate_pct']) ?>%</strong></span>
        <span>bad_rate: <strong><?= $e($defSummary['bad_entry_rate_pct']) ?>%</strong></span>
        <span>avg_roi: <strong><?= $e($defSummary['avg_close_roi'] ?? 'n/a') ?></strong></span>
        <span>avg_dd: <strong><?= $e($defSummary['avg_max_drawdown_roi'] ?? 'n/a') ?></strong></span>
        <span>quality_score: <strong><?= $e($defSummary['quality_score']) ?></strong></span>
      </div>
    </div>
    <?php endif; ?>

    <!-- Candidate result summary -->
    <?php $candSummary = is_array($run['candidate_result_summary'] ?? null) ? $run['candidate_result_summary'] : null; ?>
    <?php if ($candSummary !== null && ($candSummary['benchmark_available'] ?? false)): ?>
    <div style="margin-top:8px;">
      <div style="font-size:12px;font-weight:600;color:#93c5fd;margin-bottom:4px;">Candidate benchmark (rolling window):</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:6px;font-size:12px;">
        <span>trades: <strong><?= $e($candSummary['trades_total']) ?></strong></span>
        <span>bad: <strong><?= $e($candSummary['bad_entry_total']) ?></strong></span>
        <span>good: <strong><?= $e($candSummary['good_or_do_not_touch_total']) ?></strong></span>
        <span>winrate: <strong><?= $e($candSummary['winrate_pct']) ?>%</strong></span>
        <span>bad_rate: <strong><?= $e($candSummary['bad_entry_rate_pct']) ?>%</strong></span>
        <span>avg_roi: <strong><?= $e($candSummary['avg_close_roi'] ?? 'n/a') ?></strong></span>
        <span>avg_dd: <strong><?= $e($candSummary['avg_max_drawdown_roi'] ?? 'n/a') ?></strong></span>
        <span>quality_score: <strong><?= $e($candSummary['quality_score']) ?></strong></span>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Candidate history -->
  <?php
  $candHistPath = $moduleDir . '/storage/profiles/early_impulse_growth_long/candidate_history.ndjson';
  $candHistLines = [];
  if (is_file($candHistPath)) {
      $raw = @file($candHistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
      $candHistLines = array_slice(array_reverse($raw), 0, 10);
  }
  if ($candHistLines !== []):
  ?>
  <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
    <div style="font-size:12px;font-weight:600;margin-bottom:6px;">Candidate History (last 10)</div>
    <table style="width:100%;font-size:11px;border-collapse:collapse;">
      <thead>
        <tr style="color:#94a3b8;border-bottom:1px solid #1e293b;">
          <th style="padding:3px 6px;text-align:left;">recorded_at</th>
          <th style="padding:3px 6px;text-align:left;">status</th>
          <th style="padding:3px 6px;text-align:left;">decision</th>
          <th style="padding:3px 6px;text-align:left;">reason</th>
          <th style="padding:3px 6px;text-align:right;">default_score</th>
          <th style="padding:3px 6px;text-align:right;">cand_score</th>
          <th style="padding:3px 6px;text-align:right;">delta</th>
          <th style="padding:3px 6px;text-align:right;">window</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($candHistLines as $line):
          $h = @json_decode($line, true);
          if (!is_array($h)) continue;
          $hdColor = match((string)($h['promotion_decision'] ?? '')) {
              'promote_candidate_demo', 'candidate_ready_but_apply_disabled' => '#86efac',
              'reject_candidate' => '#f87171',
              'keep_current' => '#fcd34d',
              default => '#94a3b8',
          };
        ?>
        <tr style="border-bottom:1px solid #1e293b;">
          <td style="padding:3px 6px;"><?= $e((string)($h['recorded_at'] ?? '')) ?></td>
          <td style="padding:3px 6px;color:#c4b5fd;"><?= $e((string)($h['candidate_status'] ?? '')) ?></td>
          <td style="padding:3px 6px;color:<?= $hdColor ?>;"><?= $e((string)($h['promotion_decision'] ?? '')) ?></td>
          <td style="padding:3px 6px;color:#94a3b8;"><?= $e((string)($h['promotion_reason'] ?? '')) ?></td>
          <td style="padding:3px 6px;text-align:right;"><?= $e($h['default_quality_score'] ?? '—') ?></td>
          <td style="padding:3px 6px;text-align:right;"><?= $e($h['candidate_quality_score'] ?? '—') ?></td>
          <td style="padding:3px 6px;text-align:right;"><?= $e($h['candidate_vs_default_delta_pct'] ?? '—') ?></td>
          <td style="padding:3px 6px;text-align:right;"><?= $e((int)($h['rolling_window_outcomes_total'] ?? 0)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ── Candidate Profile & Replay (compact) ───────────────────────────── -->
  <?php
  $cpId      = (string)($run['candidate_profile_id']    ?? '—');
  $cpStatus  = (string)($run['candidate_status']         ?? '—');
  $cpRules   = (int)($run['candidate_rules_total']       ?? 0);
  $cpComps   = (int)($run['candidate_weighted_components_total'] ?? 0);
  $cpReplay  = (bool)($run['candidate_replay_enabled']   ?? false);
  ?>
  <div style="border:1px solid #93c5fd44;border-radius:8px;padding:12px;background:rgba(147,197,253,.03);">
    <div style="font-size:12px;font-weight:700;color:#93c5fd;margin-bottom:8px;">
      <i class="bi bi-cpu" style="margin-right:5px;"></i>Candidate Profile &amp; Replay
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:6px;font-size:12px;">
      <div><strong>candidate_profile_id:</strong> <span style="color:#93c5fd;word-break:break-all;"><?= $e($cpId) ?></span></div>
      <div><strong>candidate_status:</strong> <span style="color:#c4b5fd;"><?= $e($cpStatus) ?></span></div>
      <div><strong>rules_total:</strong> <?= $e($cpRules) ?></div>
      <div><strong>risk_components:</strong> <?= $e($cpComps) ?></div>
      <div><strong>candidate_replay_enabled:</strong> <?= $e($cpReplay ? 'true' : 'false') ?></div>
      <div><strong>default_score:</strong> <?= $e($run['default_quality_score'] ?? 'n/a') ?></div>
      <div><strong>candidate_score:</strong> <?= $e($run['candidate_quality_score'] ?? 'n/a') ?></div>
      <div><strong>delta:</strong>
        <?php $dv = $run['candidate_vs_default_delta_pct'] ?? null; ?>
        <span style="color:<?= $dv !== null && (float)$dv > 0 ? '#86efac' : '#f87171' ?>;">
          <?= $e($dv !== null ? (((float)$dv >= 0 ? '+' : '') . round((float)$dv, 2)) : 'n/a') ?>
        </span>
      </div>
      <div><strong>bad_blocked:</strong> <?= $e((int)($run['replay_bad_blocked_total'] ?? 0)) ?></div>
      <div><strong>good_blocked:</strong> <?= $e((int)($run['replay_good_blocked_total'] ?? 0)) ?></div>
      <div><strong>bad_capture_rate:</strong> <?= $e($run['replay_bad_capture_rate_pct'] !== null ? round((float)$run['replay_bad_capture_rate_pct'], 1) . '%' : 'n/a') ?></div>
      <div><strong>good_block_rate:</strong> <?= $e($run['replay_good_block_rate_pct']  !== null ? round((float)$run['replay_good_block_rate_pct'],  1) . '%' : 'n/a') ?></div>
      <div><strong>promotion_decision:</strong>
        <?php
        $pd  = (string)($run['promotion_decision'] ?? '—');
        $pdc = match($pd) {
            'promote_candidate_demo', 'candidate_ready_but_apply_disabled' => '#86efac',
            'reject_candidate' => '#f87171',
            'keep_current' => '#fcd34d',
            default => '#94a3b8',
        };
        ?>
        <span style="color:<?= $pdc ?>;"><?= $e($pd) ?></span>
      </div>
      <div><strong>promotion_reason:</strong> <span style="color:#94a3b8;"><?= $e((string)($run['promotion_reason'] ?? '—')) ?></span></div>
    </div>
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
