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

// Safe bounded JSON read helper — never loads files larger than $maxBytes into memory.
$safeReadJson = static function (string $path, int $maxBytes = 5242880, mixed $default = []): mixed {
    if (!is_file($path)) {
        return $default;
    }
    $size = (int)@filesize($path);
    if ($size > $maxBytes) {
        return ['_file_too_large_for_ui' => true, '_file_size_mb' => round($size / 1048576, 1), '_max_allowed_mb' => round($maxBytes / 1048576, 1)];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $default;
    }
    $decoded = json_decode($raw, true);
    return $decoded !== null ? $decoded : $default;
};

$safeFileSizeMb = static function (string $path): ?float {
    return is_file($path) ? round((int)@filesize($path) / 1048576, 1) : null;
};

// 5 MB safe limit for UI reads of potentially-large files
$maxUiBytes = 5 * 1024 * 1024;

$patterns = (array)$safeReadJson($moduleDir . '/storage/patterns/pattern_stats.json', $maxUiBytes, []);
// closed_outcomes.json can be large — load safely
$outcomesRaw = $safeReadJson($moduleDir . '/storage/closed_outcomes.json', $maxUiBytes, []);
$outcomesTooLarge = is_array($outcomesRaw) && isset($outcomesRaw['_file_too_large_for_ui']);
$outcomes = $outcomesTooLarge ? [] : (array)$outcomesRaw;
$outcomesSizeMb = $safeFileSizeMb($moduleDir . '/storage/closed_outcomes.json');

$quarantine = (array)$safeReadJson($moduleDir . '/storage/quarantine/rejected_rules.json', $maxUiBytes, []);
$microDistributions = (array)$safeReadJson($moduleDir . '/storage/patterns/micro_feature_distributions.json', $maxUiBytes, []);
$microLabelPurity = (array)$safeReadJson($moduleDir . '/storage/patterns/micro_label_purity.json', $maxUiBytes, []);
$microThresholdCandidates = (array)$safeReadJson($moduleDir . '/storage/patterns/micro_threshold_candidates.json', $maxUiBytes, []);
$suspiciousMicroLabels = (array)$safeReadJson($moduleDir . '/storage/patterns/suspicious_micro_labels.json', $maxUiBytes, []);
$run = $svc->getLastRun();
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<div style="max-width:1200px;display:grid;gap:12px;">
  <h3 style="margin:0;">Dynamic Learning — Stats</h3>
  <?php if ($outcomesTooLarge): ?>
  <div style="background:rgba(251,191,36,.10);border:1px solid #fbbf2455;border-radius:6px;padding:8px 12px;font-size:12px;color:#fbbf24;">
    ⚠ closed_outcomes.json is too large to load in UI (<?= $e($outcomesSizeMb) ?> MB). Showing count from last_run.
  </div>
  <?php endif; ?>
  <div><strong>Closed outcomes:</strong> <?= $e($outcomesTooLarge ? (int)($run['closed_outcomes_unique_total'] ?? '?') : count($outcomes)) ?></div>
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
      <div><strong>rolling_guard_mode:</strong> <?= $e((string)($run['rolling_guard_mode'] ?? 'sliding_window')) ?></div>
      <div><strong>learning_window:</strong> <?= $e((int)($run['rolling_learning_window_minutes'] ?? 1440)) ?> min</div>
      <div><strong>rebuild_interval:</strong> <?= $e((int)($run['rolling_retrain_interval_minutes'] ?? 120)) ?> min</div>
      <div><strong>rolling_window_start_at:</strong> <?= $e((string)($run['rolling_window_start_at'] ?? '—')) ?></div>
      <div><strong>rolling_window_end_at:</strong> <?= $e((string)($run['rolling_window_end_at'] ?? '—')) ?></div>
      <div><strong>rolling_window_outcomes:</strong> <?= $e((int)($run['rolling_window_outcomes_total'] ?? 0)) ?></div>
      <div><strong>rolling_window_raw_outcomes:</strong> <?= $e((int)($run['rolling_window_raw_outcomes_total'] ?? 0)) ?></div>
      <div><strong>rolling_min_closed_outcomes:</strong> <?= $e((int)($run['rolling_min_closed_outcomes'] ?? 0)) ?></div>
      <div><strong>rolling_min_bad_entries:</strong> <?= $e((int)($run['rolling_min_bad_entries'] ?? 0)) ?></div>
      <div><strong>rolling_min_good_entries:</strong> <?= $e((int)($run['rolling_min_good_entries'] ?? 0)) ?></div>
      <div><strong>rolling_window_bad_entry_total:</strong> <?= $e((int)($run['rolling_window_bad_entry_total'] ?? 0)) ?></div>
      <div><strong>rolling_window_good_entry_total:</strong> <?= $e((int)($run['rolling_window_good_entry_total'] ?? 0)) ?></div>
      <div><strong>rolling_selected_sample_type:</strong> <?= $e((string)($run['rolling_selected_sample_type'] ?? 'insufficient')) ?></div>
      <div><strong>rolling_fallback_used:</strong> <?= $e((bool)($run['rolling_fallback_used'] ?? false) ? 'yes' : 'no') ?></div>
      <div><strong>rolling_sample_min_counts_passed:</strong> <?= $e((bool)($run['rolling_sample_min_counts_passed'] ?? false) ? 'true' : 'false') ?></div>
      <div><strong>rolling_last_retrain_at:</strong> <?= $e((string)($run['rolling_last_retrain_at'] ?? '—')) ?></div>
      <div><strong>rolling_next_retrain_at:</strong> <?= $e((string)($run['rolling_next_retrain_at'] ?? '—')) ?></div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin-bottom:8px;">
      <div><strong>Outcome Linking Health · raw outcomes:</strong> <?= $e((int)($run['active_epoch_raw_outcomes_total'] ?? 0)) ?></div>
      <div><strong>usable outcomes:</strong> <?= $e((int)($run['active_epoch_usable_outcomes_total'] ?? 0)) ?></div>
      <div><strong>excluded outcomes:</strong> <?= $e((int)($run['active_epoch_excluded_from_learning_total'] ?? 0)) ?></div>
      <div><strong>rebuilt features:</strong> <?= $e((int)($run['outcome_feature_rebuilt_total'] ?? 0)) ?></div>
      <div><strong>relinked after rebuild:</strong> <?= $e((int)($run['outcome_relink_after_rebuild_linked_total'] ?? 0)) ?></div>
      <div><strong>failed rebuilds:</strong> <?= $e((int)($run['outcome_feature_rebuild_failed_total'] ?? 0)) ?></div>
      <div><strong>top exclusion reasons:</strong> <?= $e(implode(', ', array_slice(array_map(static fn($k, $v) => $k . ':' . $v, array_keys((array)($run['active_epoch_excluded_reasons'] ?? [])), array_values((array)($run['active_epoch_excluded_reasons'] ?? []))), 0, 3)) ?: '—') ?></div>
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
    $promoDecision = (string)($run['final_promotion_decision'] ?? ($run['promotion_decision'] ?? 'none'));
    $finalStatus = (string)($run['final_candidate_status'] ?? ($run['candidate_status'] ?? 'pending'));
    $finalReason = (string)($run['final_promotion_reason'] ?? ($run['promotion_reason'] ?? '—'));
    $replayResult = (string)($run['replay_result'] ?? ((bool)($run['replay_suggests_improvement'] ?? false) ? 'improved_on_sample' : 'no_improvement'));
    $promoColor = match($promoDecision) {
        'promote_candidate_demo', 'candidate_ready_but_apply_disabled' => '#86efac',
        'reject_candidate' => '#f87171',
        'keep_current' => '#fcd34d',
        default => '#94a3b8',
    };
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin-bottom:8px;">
      <div>
        <strong>final_candidate_status:</strong>
        <span style="color:#c4b5fd;"><?= $e($finalStatus) ?></span>
      </div>
      <div>
        <strong>final_promotion_decision:</strong>
        <span style="color:<?= $promoColor ?>;"><?= $e($promoDecision) ?></span>
      </div>
      <div>
        <strong>final_promotion_reason:</strong>
        <span style="color:#94a3b8;font-size:12px;"><?= $e($finalReason) ?></span>
      </div>
      <div><strong>replay_result:</strong> <?= $e($replayResult) ?></div>
      <div><strong>replay_diagnostic_available:</strong> <?= $e((bool)($run['replay_diagnostic_available'] ?? false) ? 'true' : 'false') ?></div>
      <div><strong>replay_suggests_improvement:</strong> <?= $e((bool)($run['replay_suggests_improvement'] ?? false) ? 'true' : 'false') ?></div>
      <div><strong>promotion_blocked_by_min_data:</strong> <?= $e((bool)($run['promotion_blocked_by_min_data'] ?? false) ? 'true' : 'false') ?></div>
      <div><strong>promotion_blocked_reason:</strong> <?= $e((string)($run['promotion_blocked_reason'] ?? '—')) ?></div>
      <div><strong>final_candidate_eligible_for_demo_apply:</strong> <?= $e((bool)($run['final_candidate_eligible_for_demo_apply'] ?? $run['candidate_eligible_for_demo_apply'] ?? false) ? 'true' : 'false') ?></div>
      <div><strong>candidate_build_scope:</strong> <?= $e((string)($run['candidate_build_scope'] ?? '—')) ?></div>
      <div><strong>candidate_replay_scope:</strong> <?= $e((string)($run['candidate_replay_scope'] ?? '—')) ?></div>
      <div><strong>promotion_guard_scope:</strong> <?= $e((string)($run['promotion_guard_scope'] ?? '—')) ?></div>
      <div><strong>scope_mismatch_allowed_for_diagnostics:</strong> <?= $e((bool)($run['scope_mismatch_allowed_for_diagnostics'] ?? false) ? 'true' : 'false') ?></div>
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
      $candHistSizeBytes = (int)@filesize($candHistPath);
      if ($candHistSizeBytes < 5 * 1024 * 1024) {
          $raw = @file($candHistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
          $candHistLines = array_slice(array_reverse($raw), 0, 10);
      } else {
          // Too large — tail-read last 100 KB only
          $fh = @fopen($candHistPath, 'r');
          if (is_resource($fh)) {
              fseek($fh, -102400, SEEK_END);
              fgets($fh); // skip partial line
              $tailLines = [];
              while (($line = fgets($fh)) !== false) {
                  $line = trim($line);
                  if ($line !== '') {
                      $tailLines[] = $line;
                  }
              }
              fclose($fh);
              $candHistLines = array_slice(array_reverse($tailLines), 0, 10);
          }
      }
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
          <th style="padding:3px 6px;text-align:left;">replay_result</th>
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
          $histDecision = (string)($h['final_promotion_decision'] ?? ($h['promotion_decision'] ?? ''));
          $hdColor = match($histDecision) {
              'promote_candidate_demo', 'candidate_ready_but_apply_disabled' => '#86efac',
              'reject_candidate' => '#f87171',
              'keep_current' => '#fcd34d',
              default => '#94a3b8',
          };
          $histStatus = (string)($h['final_candidate_status'] ?? ($h['candidate_status'] ?? ''));
          $histReason = (string)($h['final_promotion_reason'] ?? ($h['promotion_reason'] ?? ''));
        ?>
        <tr style="border-bottom:1px solid #1e293b;">
          <td style="padding:3px 6px;"><?= $e((string)($h['recorded_at'] ?? '')) ?></td>
          <td style="padding:3px 6px;color:#c4b5fd;"><?= $e($histStatus) ?></td>
          <td style="padding:3px 6px;color:<?= $hdColor ?>;"><?= $e($histDecision) ?></td>
          <td style="padding:3px 6px;color:#93c5fd;"><?= $e((string)($h['replay_result'] ?? '—')) ?></td>
          <td style="padding:3px 6px;color:#94a3b8;"><?= $e($histReason) ?></td>
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
  $cpStatus  = (string)($run['final_candidate_status'] ?? ($run['candidate_status'] ?? '—'));
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
      <div><strong>final_candidate_status:</strong> <span style="color:#c4b5fd;"><?= $e($cpStatus) ?></span></div>
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
      <div><strong>final_promotion_decision:</strong>
        <?php
        $pd  = (string)($run['final_promotion_decision'] ?? ($run['promotion_decision'] ?? '—'));
        $pdc = match($pd) {
            'promote_candidate_demo', 'candidate_ready_but_apply_disabled' => '#86efac',
            'reject_candidate' => '#f87171',
            'keep_current' => '#fcd34d',
            default => '#94a3b8',
        };
        ?>
        <span style="color:<?= $pdc ?>;"><?= $e($pd) ?></span>
      </div>
      <div><strong>final_promotion_reason:</strong> <span style="color:#94a3b8;"><?= $e((string)($run['final_promotion_reason'] ?? ($run['promotion_reason'] ?? '—'))) ?></span></div>
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

  <!-- ── Candidate Selection: Single-Feature + Composite ───────────────────── -->
  <?php
  $sfTotal    = (int)($run['candidate_single_feature_candidates_total']    ?? 0);
  $sfRejected = (int)($run['candidate_single_feature_rejected_total']      ?? 0);
  $sfReasons  = (array)($run['candidate_single_feature_reject_reason_counts'] ?? []);
  $compEnabled  = (bool)($run['composite_candidate_enabled']               ?? false);
  $compSource   = (string)($run['composite_candidate_source']              ?? 'missing');
  $compAvail    = (int)($run['composite_candidates_available_total']       ?? 0);
  $compTested   = (int)($run['composite_candidates_tested_total']          ?? 0);
  $compPassed   = (int)($run['composite_candidates_passed_total']          ?? 0);
  $compRejected = (int)($run['composite_candidates_rejected_total']        ?? 0);
  $compBestBad  = $run['composite_candidate_best_bad_capture_rate_pct']    ?? null;
  $compBestGood = $run['composite_candidate_best_good_block_rate_pct']     ?? null;
  $compBestNet  = $run['composite_candidate_best_net_score']               ?? null;
  $compSelected = (bool)($run['composite_candidate_selected']              ?? false);
  $compSelId    = (string)($run['composite_candidate_selected_id']         ?? '—');
  $compReject   = (array)($run['composite_candidate_reject_reason_counts'] ?? []);
  $compNoSelReason = (string)($run['composite_candidate_no_selection_reason'] ?? '');

  // Load composite_candidates.json for top composites — size-guarded
  $compositeCandidatesPath = $moduleDir . '/storage/profiles/early_impulse_growth_long/composite_candidates.json';
  $compositeCandidatesData = (array)$safeReadJson($compositeCandidatesPath, $maxUiBytes, []);
  if (isset($compositeCandidatesData['_file_too_large_for_ui'])) {
      $compositeCandidatesData = [];
  }
  $allComposites = (array)($compositeCandidatesData['candidates'] ?? []);
  $topPassed   = array_values(array_filter($allComposites, static fn($c) => ($c['status'] ?? '') === 'passed'));
  $topRejected = array_values(array_filter($allComposites, static fn($c) => ($c['status'] ?? '') === 'rejected'));
  $topPassed   = array_slice($topPassed,   0, 10);
  $topRejected = array_slice($topRejected, 0, 10);
  ?>
  <?php if ($sfTotal > 0 || $compTested > 0 || !empty($sfReasons)): ?>
  <div style="border:1px solid #fb923c44;border-radius:8px;padding:12px;background:rgba(251,146,60,.03);margin-top:8px;">
    <div style="font-size:12px;font-weight:700;color:#fb923c;margin-bottom:8px;">
      <i class="bi bi-diagram-3" style="margin-right:5px;"></i>Candidate Selection — Single-Feature &amp; Composite
    </div>

    <!-- Single-feature summary -->
    <div style="font-size:11px;font-weight:600;color:#94a3b8;margin-bottom:6px;">Single-Feature Candidates</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:6px;font-size:12px;margin-bottom:10px;">
      <div><strong>candidates_evaluated:</strong> <?= $e($sfTotal) ?></div>
      <div><strong>rejected:</strong> <span style="color:<?= $sfRejected > 0 ? '#fcd34d' : '#86efac' ?>;"><?= $e($sfRejected) ?></span></div>
      <div><strong>selected:</strong> <span style="color:<?= ($sfTotal > 0 && $sfRejected < $sfTotal) ? '#86efac' : '#6b7280' ?>;"><?= $e($sfTotal > 0 && $sfRejected < $sfTotal ? ($sfTotal - $sfRejected) : 0) ?></span></div>
    </div>
    <?php if (!empty($sfReasons)): ?>
    <div style="font-size:11px;color:#94a3b8;margin-bottom:10px;">
      <strong>reject_reasons:</strong>
      <?php foreach ($sfReasons as $reason => $count): ?>
        <span style="background:#1e293b;padding:2px 6px;border-radius:4px;margin-right:4px;"><?= $e($reason) ?>: <?= $e($count) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Composite summary -->
    <div style="font-size:11px;font-weight:600;color:#94a3b8;margin-bottom:6px;">Composite Candidates (2–3 feature combos)</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:6px;font-size:12px;margin-bottom:10px;">
      <div><strong>enabled:</strong> <span style="color:<?= $compEnabled ? '#86efac' : '#6b7280' ?>;"><?= $e($compEnabled ? 'true' : 'false') ?></span></div>
      <div><strong>source:</strong> <span style="color:#93c5fd;"><?= $e($compSource) ?></span></div>
      <div><strong>available:</strong> <?= $e($compAvail) ?></div>
      <div><strong>tested:</strong> <?= $e($compTested) ?></div>
      <div><strong>passed:</strong> <span style="color:<?= $compPassed > 0 ? '#86efac' : '#6b7280' ?>;"><?= $e($compPassed) ?></span></div>
      <div><strong>rejected:</strong> <span style="color:<?= $compRejected > 0 ? '#fcd34d' : '#6b7280' ?>;"><?= $e($compRejected) ?></span></div>
      <?php if ($compBestBad !== null): ?>
      <div><strong>best_bad_capture:</strong> <span style="color:<?= (float)$compBestBad >= 20.0 ? '#86efac' : '#f87171' ?>;"><?= $e(round((float)$compBestBad, 1)) ?>%</span></div>
      <?php endif; ?>
      <?php if ($compBestGood !== null): ?>
      <div><strong>best_good_block:</strong> <span style="color:<?= (float)$compBestGood <= 20.0 ? '#86efac' : '#f87171' ?>;"><?= $e(round((float)$compBestGood, 1)) ?>%</span></div>
      <?php endif; ?>
      <?php if ($compBestNet !== null): ?>
      <div><strong>best_net_score:</strong> <span style="color:<?= (float)$compBestNet > 0 ? '#86efac' : '#f87171' ?>;"><?= $e(round((float)$compBestNet, 2)) ?></span></div>
      <?php endif; ?>
      <div><strong>selected:</strong> <span style="color:<?= $compSelected ? '#86efac' : '#6b7280' ?>;"><?= $e($compSelected ? 'YES — ' . $compSelId : 'none') ?></span></div>
    </div>
    <?php if ($compNoSelReason !== ''): ?>
    <div style="font-size:11px;color:#fb923c;margin-bottom:8px;">
      <strong>no_selection_reason:</strong> <?= $e($compNoSelReason) ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($compReject)): ?>
    <div style="font-size:11px;color:#94a3b8;margin-bottom:10px;">
      <strong>composite_reject_reasons:</strong>
      <?php foreach ($compReject as $reason => $count): ?>
        <span style="background:#1e293b;padding:2px 6px;border-radius:4px;margin-right:4px;"><?= $e($reason) ?>: <?= $e($count) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Top passed composites -->
    <?php if (!empty($topPassed)): ?>
    <div style="background:#0f172a;border-radius:7px;padding:8px;margin-bottom:8px;">
      <div style="font-size:11px;color:#86efac;margin-bottom:6px;font-weight:600;">Top Passed Composites (<?= $e(count($topPassed)) ?>)</div>
      <?php foreach ($topPassed as $i => $c): ?>
      <div style="background:#111827;border-radius:5px;padding:5px 8px;margin-bottom:4px;font-size:11px;">
        <span style="color:#93c5fd;">#<?= $e($i + 1) ?></span>
        <span style="color:#e5e7eb;margin-left:6px;"><?= $e(implode(' + ', (array)($c['components'] ?? []))) ?></span>
        &nbsp;
        <span style="color:#86efac;">bad: <?= $e(round((float)($c['bad_capture_rate_pct'] ?? 0), 1)) ?>%</span>
        &nbsp;<span style="color:#fcd34d;">good_blk: <?= $e(round((float)($c['good_block_rate_pct'] ?? 0), 1)) ?>%</span>
        &nbsp;<span style="color:#94a3b8;">net: <?= $e(round((float)($c['net_score'] ?? 0), 2)) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Top rejected composites -->
    <?php if (!empty($topRejected)): ?>
    <div style="background:#0f172a;border-radius:7px;padding:8px;">
      <div style="font-size:11px;color:#fcd34d;margin-bottom:6px;font-weight:600;">Top Rejected Composites (<?= $e(count($topRejected)) ?>)</div>
      <?php foreach ($topRejected as $i => $c): ?>
      <div style="background:#111827;border-radius:5px;padding:5px 8px;margin-bottom:4px;font-size:11px;">
        <span style="color:#6b7280;">#<?= $e($i + 1) ?></span>
        <span style="color:#e5e7eb;margin-left:6px;"><?= $e(implode(' + ', (array)($c['components'] ?? []))) ?></span>
        &nbsp;
        <span style="color:<?= (float)($c['bad_capture_rate_pct'] ?? 0) >= 20.0 ? '#86efac' : '#f87171' ?>;">bad: <?= $e(round((float)($c['bad_capture_rate_pct'] ?? 0), 1)) ?>%</span>
        &nbsp;<span style="color:<?= (float)($c['good_block_rate_pct'] ?? 0) <= 20.0 ? '#86efac' : '#f87171' ?>;">good_blk: <?= $e(round((float)($c['good_block_rate_pct'] ?? 0), 1)) ?>%</span>
        &nbsp;<span style="color:#94a3b8;">net: <?= $e(round((float)($c['net_score'] ?? 0), 2)) ?> — <?= $e((string)($c['reject_reason'] ?? '')) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
