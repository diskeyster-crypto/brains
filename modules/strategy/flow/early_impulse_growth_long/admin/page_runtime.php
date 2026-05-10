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
$ajaxUrl = $eigUrl . '/ajax';

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

$runState = $readJson('run_state.json', []);
$lastRun = $readJson('last_run.json', []);
$storageExists = is_dir($storageDir);

$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmtNum = static fn(mixed $v, int $p = 3): string => is_numeric($v) ? number_format((float)$v, $p) : '—';
$fmtBool = static fn(mixed $v): string => $v ? '<span style="color:#22c55e">true</span>' : '<span style="color:#94a3b8">false</span>';

$status = (string)($runState['status'] ?? 'idle');
$statusColor = match ($status) {
    'running' => '#22c55e',
    'queued' => '#f59e0b',
    'done' => '#3b82f6',
    default => '#64748b',
};

$acceptedExamples = is_array($lastRun['accepted_examples'] ?? null) ? (array)$lastRun['accepted_examples'] : [];
$nearPassExamples = is_array($lastRun['near_pass_examples'] ?? null) ? (array)$lastRun['near_pass_examples'] : [];
$rejectedExamples = is_array($lastRun['rejected_examples'] ?? null) ? (array)$lastRun['rejected_examples'] : [];
$bestRecoveryExamples = is_array($lastRun['best_recovery_examples'] ?? null) ? (array)$lastRun['best_recovery_examples'] : [];
$openInterestExamples = is_array($lastRun['open_interest_growth_examples'] ?? null) ? (array)$lastRun['open_interest_growth_examples'] : [];
$accelExamples = is_array($lastRun['current_acceleration_examples'] ?? null) ? (array)$lastRun['current_acceleration_examples'] : [];
?>
<style>
.eig-rt-page { max-width: 1220px; }
.rt-section { background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 20px; margin-bottom: 18px; }
.rt-section h6 { color: #94a3b8; text-transform: uppercase; font-size: 11px; letter-spacing: .06em; margin-bottom: 12px; }
.rt-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px; margin-bottom: 10px; }
.rt-box { background: rgba(255,255,255,.03); border: 1px solid var(--border-color,#334155); border-radius: 6px; padding: 10px 12px; }
.rt-val { font-size: 20px; font-weight: 700; color: #e2e8f0; }
.rt-lbl { font-size: 11px; color: #64748b; margin-top: 2px; }
.rt-kv td { font-size: 12px; padding: 4px 8px; }
</style>

<div class="eig-rt-page">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:18px;">
    <h4 style="margin:0;font-size:18px;">Early Impulse Growth Long — Runtime</h4>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="<?= $e($eigUrl) ?>/config" class="btn btn-sm" style="background:rgba(88,166,255,.10);color:#58a6ff;border:1px solid #58a6ff44;">Config</a>
      <a href="<?= $e($eigUrl) ?>/runtime" class="btn btn-sm" style="background:rgba(56,189,248,.15);color:#38bdf8;border:1px solid #38bdf844;">Runtime</a>
      <a href="<?= $e($eigUrl) ?>/stats" class="btn btn-sm" style="background:rgba(167,139,250,.10);color:#a78bfa;border:1px solid #a78bfa44;">Stats</a>
    </div>
  </div>

  <?php if (!$storageExists): ?>
  <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.3);border-radius:6px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#f59e0b;">
    Storage directory does not exist yet. Run the first tick to initialise runtime files.
  </div>
  <?php endif; ?>

  <div class="rt-section">
    <h6>Runtime summary (visual review)</h6>
    <div class="rt-grid">
      <div class="rt-box"><div class="rt-val" style="color:<?= $e($statusColor) ?>;font-size:16px;"><?= $e($status) ?></div><div class="rt-lbl">status</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($runState['registry_cursor'] ?? 0)) ?></div><div class="rt-lbl">current_batch_cursor</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['current_run_processed_total'] ?? 0)) ?></div><div class="rt-lbl">processed_symbols</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['current_run_evaluated_total'] ?? ($lastRun['current_run_processed_total'] ?? 0))) ?></div><div class="rt-lbl">current_run_evaluated_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($runState['universe_total'] ?? $lastRun['universe_total'] ?? 0)) ?></div><div class="rt-lbl">universe_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['current_run_candidates_total'] ?? 0)) ?></div><div class="rt-lbl">current_run_candidates_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['current_run_near_pass_total'] ?? 0)) ?></div><div class="rt-lbl">current_run_near_pass_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['current_run_signals_total'] ?? 0)) ?></div><div class="rt-lbl">current_run_signals_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['current_run_rejects_total'] ?? 0)) ?></div><div class="rt-lbl">current_run_rejects_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['stored_evaluated_total'] ?? 0)) ?></div><div class="rt-lbl">stored_evaluated_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['stored_candidates_total'] ?? 0)) ?></div><div class="rt-lbl">stored_candidates_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['stored_near_pass_total'] ?? 0)) ?></div><div class="rt-lbl">stored_near_pass_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['stored_signals_total'] ?? 0)) ?></div><div class="rt-lbl">stored_signals_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['stored_rejects_total'] ?? 0)) ?></div><div class="rt-lbl">stored_rejects_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['recovery_window_minutes'] ?? 0)) ?></div><div class="rt-lbl">selected_recovery_window</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['prior_decline_passed_total'] ?? 0)) ?></div><div class="rt-lbl">prior_decline_pass_count</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['recovery_growth_passed_total'] ?? 0)) ?></div><div class="rt-lbl">recovery_growth_pass_count</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['open_interest_growth_passed_total'] ?? 0)) ?></div><div class="rt-lbl">oi_growth_pass_count</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['raw_strategy_passed_total'] ?? 0)) ?></div><div class="rt-lbl">raw_strategy_pass_count</div></div>
    </div>
    <table class="rt-kv">
      <tr><td style="color:#64748b">filter_engine_available_filters_total</td><td><?= $e((int)($lastRun['filter_engine_available_filters_total'] ?? 0)) ?></td><td style="color:#64748b;padding-left:16px;">filter_engine_enabled_filters_total</td><td><?= $e((int)($lastRun['filter_engine_enabled_filters_total'] ?? 0)) ?></td></tr>
      <tr><td style="color:#64748b">filter_engine_enabled_filter_ids</td><td colspan="3"><code><?= $e(json_encode((array)($lastRun['filter_engine_enabled_filter_ids'] ?? []), JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
      <tr><td style="color:#64748b">filter_engine_results_by_filter</td><td colspan="3"><code><?= $e(json_encode((array)($lastRun['filter_engine_results_by_filter'] ?? []), JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
    </table>
    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
      <form method="post" action="<?= $e($ajaxUrl) ?>" style="margin:0;">
        <input type="hidden" name="action" value="queue_run">
        <button type="submit" class="btn btn-sm" style="background:rgba(63,185,80,.12);color:#3fb950;border:1px solid #3fb95055;">Запуск цикла</button>
      </form>
      <form method="post" action="<?= $e($ajaxUrl) ?>" style="margin:0;">
        <input type="hidden" name="action" value="tick_batch">
        <button type="submit" class="btn btn-sm" style="background:rgba(88,166,255,.12);color:#58a6ff;border:1px solid #58a6ff55;">Тик батча</button>
      </form>
    </div>
  </div>

  <div class="rt-section">
    <h6>Accepted examples</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color,#334155);">
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">symbol</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">entry_price</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">prior_decline_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">recovery_growth_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">recovery_duration_minutes</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">recovery_score</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">open_interest_growth_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">combined_recovery_score</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">current_price_change_pct_10m</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">raw_strategy_passed</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">handoff_ready</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($acceptedExamples as $row): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);">
            <td style="padding:3px 8px;font-weight:600;"><?= $e($row['symbol'] ?? '—') ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['entry_price'] ?? null, 6)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['prior_decline_pct'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['recovery_growth_pct'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e((string)($row['recovery_duration_minutes'] ?? '—')) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['recovery_score'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['open_interest_growth_pct'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['combined_recovery_score'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['current_price_change_pct_10m'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $fmtBool((bool)($row['raw_strategy_passed'] ?? false)) ?></td>
            <td style="padding:3px 8px;"><?= $fmtBool((bool)($row['handoff_ready'] ?? false)) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($acceptedExamples === []): ?><div class="note" style="padding:8px 2px;">No accepted examples in last_run yet.</div><?php endif; ?>
    </div>
  </div>

  <div class="rt-section">
    <h6>Near-pass examples (visual review)</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color,#334155);">
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">symbol</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">entry_price</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">prior_decline_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">recovery_growth_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">open_interest_growth_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">combined_recovery_score</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">raw_reject_reason</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($nearPassExamples as $row): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);">
            <td style="padding:3px 8px;font-weight:600;"><?= $e($row['symbol'] ?? '—') ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['entry_price'] ?? null, 6)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['prior_decline_pct'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['recovery_growth_pct'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['open_interest_growth_pct'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['combined_recovery_score'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><code><?= $e($row['raw_reject_reason'] ?? $row['reject_reason'] ?? '—') ?></code></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($nearPassExamples === []): ?><div class="note" style="padding:8px 2px;">No near-pass examples in last_run.</div><?php endif; ?>
    </div>
  </div>

  <div class="rt-section">
    <h6>Rejected examples</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color,#334155);">
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">symbol</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">raw_reject_reason</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">prior_decline_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">recovery_growth_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">open_interest_growth_pct</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rejectedExamples as $row): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);">
            <td style="padding:3px 8px;font-weight:600;"><?= $e($row['symbol'] ?? '—') ?></td>
            <td style="padding:3px 8px;"><code><?= $e($row['raw_reject_reason'] ?? '—') ?></code></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['prior_decline_pct'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['recovery_growth_pct'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['open_interest_growth_pct'] ?? null, 4)) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($rejectedExamples === []): ?><div class="note" style="padding:8px 2px;">No rejected examples in last_run yet.</div><?php endif; ?>
    </div>
  </div>

  <div class="rt-section">
    <h6>Best recovery / OI / acceleration examples</h6>
    <table class="rt-kv">
      <tr><td style="color:#64748b">best_recovery_examples</td><td><code><?= $e(json_encode($bestRecoveryExamples, JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
      <tr><td style="color:#64748b">open_interest_growth_examples</td><td><code><?= $e(json_encode($openInterestExamples, JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
      <tr><td style="color:#64748b">current_acceleration_examples</td><td><code><?= $e(json_encode($accelExamples, JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
    </table>
  </div>
</div>
