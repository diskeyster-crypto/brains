<?php

declare(strict_types=1);

use Core\Auth\Auth;
use Core\System\System;
use Core\System\SystemPaths;

if (!Auth::check()) {
    header('Location: ' . System::adminUrl('login'));
    exit;
}

$moduleDir = SystemPaths::instance()->get('strategy.early_impulse_growth_long');
$storageDir = $moduleDir . '/storage';
$eigUrl = rtrim(System::web('admin/strategy/early_impulse_growth_long'), '/');

$readJson = static function (string $file, mixed $default = []) use ($storageDir): mixed {
    $path = $storageDir . '/' . $file;
    if (!is_file($path)) {
        return $default;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $default;
    }
    $decoded = json_decode($raw, true);
    return $decoded !== null ? $decoded : $default;
};

$lastRun = $readJson('last_run.json', []);
$cycleHistory = [];
$cyclePath = $storageDir . '/cycle_history.ndjson';
if (is_file($cyclePath)) {
    $fh = @fopen($cyclePath, 'r');
    if (is_resource($fh)) {
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row)) {
                $cycleHistory[] = $row;
            }
        }
        fclose($fh);
    }
}
$cycleHistory = array_slice($cycleHistory, -200);

$outcomeAnalyzerEnabled = (bool)($lastRun['outcome_analyzer_enabled'] ?? false);
$outcomeBadTotal = (int)($lastRun['outcome_bad_entry_total'] ?? 0);
$outcomeGoodTotal = (int)($lastRun['outcome_good_or_do_not_touch_total'] ?? 0);
$outcomeExitIssueTotal = (int)($lastRun['outcome_entry_ok_exit_issue_total'] ?? 0);
$outcomeNeutralTotal = (int)($lastRun['outcome_neutral_total'] ?? 0);
$outcomeIncompleteTotal = (int)($lastRun['outcome_incomplete_total'] ?? 0);
$outcomePatternCandidates = (int)($lastRun['outcome_bad_pattern_candidates_total'] ?? 0);
$outcomeTopPatterns = is_array($lastRun['outcome_top_bad_patterns'] ?? null) ? (array)$lastRun['outcome_top_bad_patterns'] : [];
$outcomeBadExamples = is_array($lastRun['outcome_bad_order_examples'] ?? null) ? (array)$lastRun['outcome_bad_order_examples'] : [];
$outcomeGoodExamples = is_array($lastRun['outcome_good_order_examples'] ?? null) ? (array)$lastRun['outcome_good_order_examples'] : [];
$outcomeExitIssueExamples = is_array($lastRun['outcome_entry_ok_exit_issue_examples'] ?? null) ? (array)$lastRun['outcome_entry_ok_exit_issue_examples'] : [];

// Also read from storage files directly for completeness
$readOutcomeJson = static function (string $file, mixed $default = []) use ($storageDir): mixed {
    $path = $storageDir . '/outcome_analyzer/' . $file;
    if (!is_file($path)) {
        return $default;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $default;
    }
    $decoded = json_decode($raw, true);
    return $decoded !== null ? $decoded : $default;
};

$outcomePatterns = $readOutcomeJson('outcome_patterns.json', []);
if ($outcomePatterns !== [] && $outcomeTopPatterns === []) {
    $outcomeTopPatterns = array_slice((array)$outcomePatterns, 0, 15);
}

$acceptedExamples = is_array($lastRun['accepted_examples'] ?? null) ? (array)$lastRun['accepted_examples'] : [];
$bestRecoveryExamples = is_array($lastRun['best_recovery_examples'] ?? null) ? (array)$lastRun['best_recovery_examples'] : [];
$oiGrowthExamples = is_array($lastRun['open_interest_growth_examples'] ?? null) ? (array)$lastRun['open_interest_growth_examples'] : [];
$rejectReasonCounts = is_array($lastRun['reject_reason_counts'] ?? null) ? (array)$lastRun['reject_reason_counts'] : [];

$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmtNum = static fn(mixed $v, int $p = 4): string => is_numeric($v) ? number_format((float)$v, $p) : '—';

$avgRecoveryGrowth = null;
$avgOiGrowth = null;
$recoveryVals = [];
$oiVals = [];
foreach ($acceptedExamples as $row) {
    if (is_numeric($row['recovery_growth_pct'] ?? null)) {
        $recoveryVals[] = (float)$row['recovery_growth_pct'];
    }
    if (is_numeric($row['open_interest_growth_pct'] ?? null)) {
        $oiVals[] = (float)$row['open_interest_growth_pct'];
    }
}
if ($recoveryVals !== []) {
    $avgRecoveryGrowth = array_sum($recoveryVals) / count($recoveryVals);
}
if ($oiVals !== []) {
    $avgOiGrowth = array_sum($oiVals) / count($oiVals);
}
?>
<style>
.eig-st-page { max-width: 1220px; }
.st-section { background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 20px; margin-bottom: 18px; }
.st-section h6 { color: #94a3b8; text-transform: uppercase; font-size: 11px; letter-spacing: .06em; margin-bottom: 12px; }
.st-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; margin-bottom: 10px; }
.st-box { background: rgba(255,255,255,.03); border: 1px solid var(--border-color,#334155); border-radius: 6px; padding: 10px 12px; }
.st-val { font-size: 20px; font-weight: 700; color: #e2e8f0; }
.st-lbl { font-size: 11px; color: #64748b; margin-top: 2px; }
</style>

<div class="eig-st-page">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:18px;">
    <h4 style="margin:0;font-size:18px;">Early Impulse Growth Long — Stats</h4>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="<?= $e($eigUrl) ?>/config" class="btn btn-sm" style="background:rgba(88,166,255,.10);color:#58a6ff;border:1px solid #58a6ff44;">Config</a>
      <a href="<?= $e($eigUrl) ?>/runtime" class="btn btn-sm" style="background:rgba(56,189,248,.10);color:#38bdf8;border:1px solid #38bdf844;">Runtime</a>
      <a href="<?= $e($eigUrl) ?>/stats" class="btn btn-sm" style="background:rgba(167,139,250,.15);color:#a78bfa;border:1px solid #a78bfa44;">Stats</a>
    </div>
  </div>

  <div class="st-section">
    <h6>Cycle overview</h6>
    <div class="st-grid">
      <div class="st-box"><div class="st-val"><?= $e(count($cycleHistory)) ?></div><div class="st-lbl">cycle_history_records</div></div>
      <div class="st-box"><div class="st-val"><?= $e((int)($lastRun['current_run_candidates_total'] ?? 0)) ?></div><div class="st-lbl">candidates_per_last_cycle</div></div>
      <div class="st-box"><div class="st-val"><?= $e((int)($lastRun['current_run_signals_total'] ?? 0)) ?></div><div class="st-lbl">signals_per_last_cycle</div></div>
      <div class="st-box"><div class="st-val"><?= $e((int)($lastRun['current_run_rejects_total'] ?? 0)) ?></div><div class="st-lbl">rejects_per_last_cycle</div></div>
      <div class="st-box"><div class="st-val"><?= $e($fmtNum($avgRecoveryGrowth, 4)) ?></div><div class="st-lbl">average_recovery_growth_pct</div></div>
      <div class="st-box"><div class="st-val"><?= $e($fmtNum($avgOiGrowth, 4)) ?></div><div class="st-lbl">average_open_interest_growth_pct</div></div>
    </div>
  </div>

  <div class="st-section">
    <h6>Cycle history (recent)</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color,#334155);">
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">finished_at</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">current_run_candidates_total</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">current_run_signals_total</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">current_run_rejects_total</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">raw_strategy_passed_total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (array_reverse(array_slice($cycleHistory, -30)) as $row): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);">
            <td style="padding:3px 8px;"><?= $e(substr((string)($row['finished_at'] ?? '—'), 0, 19)) ?></td>
            <td style="padding:3px 8px;"><?= $e((int)($row['current_run_candidates_total'] ?? 0)) ?></td>
            <td style="padding:3px 8px;"><?= $e((int)($row['current_run_signals_total'] ?? 0)) ?></td>
            <td style="padding:3px 8px;"><?= $e((int)($row['current_run_rejects_total'] ?? 0)) ?></td>
            <td style="padding:3px 8px;"><?= $e((int)($row['raw_strategy_passed_total'] ?? 0)) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="st-section">
    <h6>Reject reason counts</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead><tr style="border-bottom:1px solid var(--border-color,#334155);"><th style="text-align:left;padding:4px 8px;color:#94a3b8;">reason</th><th style="text-align:left;padding:4px 8px;color:#94a3b8;">count</th></tr></thead>
        <tbody>
          <?php foreach ($rejectReasonCounts as $reason => $cnt): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);"><td style="padding:3px 8px;"><code><?= $e($reason) ?></code></td><td style="padding:3px 8px;"><?= $e((int)$cnt) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($rejectReasonCounts === []): ?><div class="note" style="padding-top:8px;">No reject reasons in last_run.</div><?php endif; ?>
    </div>
  </div>

  <div class="st-section">
    <h6>Best accepted examples by combined_recovery_score</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead><tr style="border-bottom:1px solid var(--border-color,#334155);"><th style="text-align:left;padding:4px 8px;color:#94a3b8;">symbol</th><th style="text-align:left;padding:4px 8px;color:#94a3b8;">combined_recovery_score</th><th style="text-align:left;padding:4px 8px;color:#94a3b8;">recovery_growth_pct</th><th style="text-align:left;padding:4px 8px;color:#94a3b8;">open_interest_growth_pct</th></tr></thead>
        <tbody>
          <?php foreach ($bestRecoveryExamples as $row): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);"><td style="padding:3px 8px;font-weight:600;"><?= $e($row['symbol'] ?? '—') ?></td><td style="padding:3px 8px;"><?= $e($fmtNum($row['combined_recovery_score'] ?? null, 4)) ?></td><td style="padding:3px 8px;"><?= $e($fmtNum($row['recovery_growth_pct'] ?? null, 4)) ?></td><td style="padding:3px 8px;"><?= $e($fmtNum($row['open_interest_growth_pct'] ?? null, 4)) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($bestRecoveryExamples === []): ?><div class="note" style="padding-top:8px;">No best_recovery_examples in last_run.</div><?php endif; ?>
    </div>
  </div>

  <div class="st-section">
    <h6>Top recovery_growth_pct examples</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead><tr style="border-bottom:1px solid var(--border-color,#334155);"><th style="text-align:left;padding:4px 8px;color:#94a3b8;">symbol</th><th style="text-align:left;padding:4px 8px;color:#94a3b8;">recovery_growth_pct</th><th style="text-align:left;padding:4px 8px;color:#94a3b8;">recovery_duration_minutes</th></tr></thead>
        <tbody>
          <?php foreach ($bestRecoveryExamples as $row): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);"><td style="padding:3px 8px;font-weight:600;"><?= $e($row['symbol'] ?? '—') ?></td><td style="padding:3px 8px;"><?= $e($fmtNum($row['recovery_growth_pct'] ?? null, 4)) ?></td><td style="padding:3px 8px;"><?= $e((string)($row['recovery_duration_minutes'] ?? '—')) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="st-section">
    <h6>Top open_interest_growth_pct examples</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead><tr style="border-bottom:1px solid var(--border-color,#334155);"><th style="text-align:left;padding:4px 8px;color:#94a3b8;">symbol</th><th style="text-align:left;padding:4px 8px;color:#94a3b8;">open_interest_growth_pct</th><th style="text-align:left;padding:4px 8px;color:#94a3b8;">combined_recovery_score</th></tr></thead>
        <tbody>
          <?php foreach ($oiGrowthExamples as $row): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);"><td style="padding:3px 8px;font-weight:600;"><?= $e($row['symbol'] ?? '—') ?></td><td style="padding:3px 8px;"><?= $e($fmtNum($row['open_interest_growth_pct'] ?? null, 4)) ?></td><td style="padding:3px 8px;"><?= $e($fmtNum($row['combined_recovery_score'] ?? null, 4)) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($outcomeAnalyzerEnabled || $outcomeBadTotal + $outcomeGoodTotal + $outcomeExitIssueTotal + $outcomeNeutralTotal + $outcomeIncompleteTotal > 0 || $outcomePatterns !== []): ?>

  <div class="st-section" style="border-left:3px solid #a78bfa;">
    <h6>Outcome analyzer — bad-entry pattern mining (diagnostic only)</h6>
    <div class="st-grid">
      <div class="st-box"><div class="st-val" style="color:<?= $outcomeAnalyzerEnabled ? '#22c55e' : '#94a3b8' ?>"><?= $outcomeAnalyzerEnabled ? 'enabled' : 'disabled' ?></div><div class="st-lbl">outcome_analyzer_enabled</div></div>
      <div class="st-box"><div class="st-val" style="color:#f87171;"><?= $e($outcomeBadTotal) ?></div><div class="st-lbl">bad_entry_total</div></div>
      <div class="st-box"><div class="st-val" style="color:#22c55e;"><?= $e($outcomeGoodTotal) ?></div><div class="st-lbl">good_or_do_not_touch_total</div></div>
      <div class="st-box"><div class="st-val" style="color:#f59e0b;"><?= $e($outcomeExitIssueTotal) ?></div><div class="st-lbl">entry_ok_exit_issue_total</div></div>
      <div class="st-box"><div class="st-val"><?= $e($outcomeNeutralTotal) ?></div><div class="st-lbl">neutral_total</div></div>
      <div class="st-box"><div class="st-val" style="color:#64748b;"><?= $e($outcomeIncompleteTotal) ?></div><div class="st-lbl">outcome_incomplete_total</div></div>
      <div class="st-box"><div class="st-val" style="color:#a78bfa;"><?= $e($outcomePatternCandidates) ?></div><div class="st-lbl">bad_pattern_candidates_total</div></div>
      <div class="st-box"><div class="st-val"><?= $e((int)($lastRun['outcome_trades_matched_total'] ?? 0)) ?></div><div class="st-lbl">trades_matched_total</div></div>
      <div class="st-box"><div class="st-val"><?= $e((int)($lastRun['outcome_trades_loaded_total'] ?? 0)) ?></div><div class="st-lbl">trades_loaded_total</div></div>
    </div>
    <div style="margin-top:8px;padding:8px 12px;background:rgba(167,139,250,.05);border:1px solid rgba(167,139,250,.15);border-radius:4px;font-size:11px;color:#94a3b8;">
      ⚠ This is read-only diagnostic analytics. It does not change filters, block trades, or auto-apply anything.
    </div>
  </div>

  <?php if ($outcomeTopPatterns !== []): ?>
  <div class="st-section">
    <h6>Top bad-entry pattern candidates</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color,#334155);">
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">bucket_id</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">feature_group</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">bad</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">good</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">bad_share</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">avg_bad_roi</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">avg_bad_dd</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">confidence</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">suggested_action</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">note</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($outcomeTopPatterns as $pat): ?>
          <?php
            $suggAction = (string)($pat['suggested_action'] ?? 'observe_only');
            $noHardBlock = (bool)($pat['do_not_use_for_hard_block'] ?? false);
            $conf = (string)($pat['confidence'] ?? 'low');
            $actionColor = match ($suggAction) {
                'candidate_hard_block' => '#f87171',
                'candidate_soft_block' => '#f59e0b',
                default => '#64748b',
            };
            $confColor = match ($conf) {
                'high' => '#22c55e',
                'medium' => '#f59e0b',
                default => '#64748b',
            };
          ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);">
            <td style="padding:3px 8px;"><code style="font-size:11px;"><?= $e($pat['bucket_id'] ?? '—') ?></code></td>
            <td style="padding:3px 8px;color:#64748b;"><?= $e($pat['feature_group'] ?? '—') ?></td>
            <td style="padding:3px 8px;font-weight:600;color:#f87171;"><?= $e((int)($pat['bad_count'] ?? 0)) ?></td>
            <td style="padding:3px 8px;font-weight:600;color:#22c55e;"><?= $e((int)($pat['good_count'] ?? 0)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($pat['bad_share'] ?? null, 3)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($pat['avg_bad_close_roi'] ?? null, 2)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($pat['avg_bad_drawdown_roi'] ?? null, 2)) ?></td>
            <td style="padding:3px 8px;color:<?= $confColor ?>"><?= $e($conf) ?></td>
            <td style="padding:3px 8px;color:<?= $actionColor ?>;font-weight:600;"><?= $e($suggAction) ?></td>
            <td style="padding:3px 8px;color:#94a3b8;font-size:10px;"><?= $noHardBlock ? '⚠ do not use for hard block (high good overlap)' : '' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($outcomeBadExamples !== []): ?>
  <div class="st-section">
    <h6>Bad entry examples</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color,#334155);">
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">symbol</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">close_roi</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">max_drawdown_roi</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">close_reason</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">context_phase</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">wave_regime</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">ask_wall_risk</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">oi_growth_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">matched_patterns</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($outcomeBadExamples as $ex): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);">
            <td style="padding:3px 8px;font-weight:600;"><?= $e($ex['symbol'] ?? '—') ?></td>
            <td style="padding:3px 8px;color:#f87171;"><?= $e($fmtNum($ex['close_roi'] ?? null, 2)) ?></td>
            <td style="padding:3px 8px;color:#f87171;"><?= $e($fmtNum($ex['max_drawdown_roi'] ?? null, 2)) ?></td>
            <td style="padding:3px 8px;font-size:10px;"><?= $e($ex['close_reason'] ?? '—') ?></td>
            <td style="padding:3px 8px;"><?= $e($ex['context_phase'] ?? '—') ?></td>
            <td style="padding:3px 8px;"><?= $e($ex['wave_regime'] ?? '—') ?></td>
            <td style="padding:3px 8px;"><?= $e($ex['ask_wall_risk'] ?? '—') ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($ex['open_interest_growth_pct'] ?? null, 2)) ?></td>
            <td style="padding:3px 8px;font-size:10px;color:#a78bfa;"><?= $e(implode(', ', (array)($ex['matched_bad_pattern_labels'] ?? []))) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($outcomeGoodExamples !== []): ?>
  <div class="st-section">
    <h6>Good / do-not-touch examples</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color,#334155);">
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">symbol</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">close_roi</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">context_phase</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">wave_regime</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">ask_wall_risk</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">smooth_growth_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">oi_growth_pct</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($outcomeGoodExamples as $ex): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);">
            <td style="padding:3px 8px;font-weight:600;"><?= $e($ex['symbol'] ?? '—') ?></td>
            <td style="padding:3px 8px;color:#22c55e;"><?= $e($fmtNum($ex['close_roi'] ?? null, 2)) ?></td>
            <td style="padding:3px 8px;"><?= $e($ex['context_phase'] ?? '—') ?></td>
            <td style="padding:3px 8px;"><?= $e($ex['wave_regime'] ?? '—') ?></td>
            <td style="padding:3px 8px;"><?= $e($ex['ask_wall_risk'] ?? '—') ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($ex['smooth_growth_pct'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($ex['open_interest_growth_pct'] ?? null, 2)) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($outcomeExitIssueExamples !== []): ?>
  <div class="st-section">
    <h6>Entry OK / exit issue examples</h6>
    <p style="font-size:11px;color:#94a3b8;margin-bottom:10px;">These trades had good max_profit_roi but closed badly — entry was likely fine, issue is exit management. Do not use as bad-entry evidence.</p>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color,#334155);">
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">symbol</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">close_roi</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">max_profit_roi</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">context_phase</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">wave_regime</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($outcomeExitIssueExamples as $ex): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);">
            <td style="padding:3px 8px;font-weight:600;"><?= $e($ex['symbol'] ?? '—') ?></td>
            <td style="padding:3px 8px;color:#f87171;"><?= $e($fmtNum($ex['close_roi'] ?? null, 2)) ?></td>
            <td style="padding:3px 8px;color:#22c55e;"><?= $e($fmtNum($ex['max_profit_roi'] ?? null, 2)) ?></td>
            <td style="padding:3px 8px;"><?= $e($ex['context_phase'] ?? '—') ?></td>
            <td style="padding:3px 8px;"><?= $e($ex['wave_regime'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>

</div>
