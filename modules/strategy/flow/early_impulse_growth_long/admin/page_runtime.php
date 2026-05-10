<?php

declare(strict_types=1);

/**
 * Early Impulse Growth Long — Admin Runtime Page
 */

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

if (!Auth::check()) {
    header('Location: ' . System::adminUrl('login'));
    exit;
}

$moduleDir  = SystemPaths::instance()->get('strategy.early_impulse_growth_long');
$storageDir = $moduleDir . '/storage';
$eigUrl     = rtrim(System::web('admin/strategy/early_impulse_growth_long'), '/');
$ajaxUrl    = $eigUrl . '/ajax';

// Load config (base + active override)
$config = [];
foreach ([$moduleDir . '/config/base.php', $moduleDir . '/config/active.php'] as $_cfgFile) {
    if (is_file($_cfgFile)) {
        $_cfgData = @include $_cfgFile;
        if (is_array($_cfgData)) {
            $config = array_merge($config, $_cfgData);
        }
    }
}

$readJson = static function (string $file, mixed $default = []) use ($storageDir): mixed {
    $path = $storageDir . '/' . $file;
    if (!file_exists($path)) {
        return $default;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }
    $decoded = json_decode($raw, true);
    return $decoded !== null ? $decoded : $default;
};

$runState = $readJson('run_state.json', []);
$lastRun  = $readJson('last_run.json', []);

$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$fmtBool = static function (mixed $v): string {
    if ($v === true || $v === 1) {
        return '<span style="color:#22c55e">true</span>';
    }
    if ($v === false || $v === 0) {
        return '<span style="color:#94a3b8">false</span>';
    }
    return '<span style="color:#94a3b8">—</span>';
};

$runStatus   = (string)($runState['status'] ?? 'idle');
$batchOffset = (int)($runState['batch_offset'] ?? 0);
$selTotal    = (int)($runState['selected_window_total'] ?? 0);
$unvTotal    = (int)($runState['universe_total'] ?? 0);
$batchSize   = (int)($runState['batch_size'] ?? $config['batch_size'] ?? 100);
$regCursor   = (int)($runState['registry_cursor'] ?? 0);
$nextCursor  = (int)($runState['next_registry_cursor'] ?? 0);
$prevCursor  = (int)($runState['previous_registry_cursor'] ?? 0);
$winStart    = (int)($runState['registry_window_start'] ?? 0);
$winEnd      = (int)($runState['registry_window_end'] ?? 0);
$winWrapped  = (bool)($runState['registry_window_wrapped'] ?? false);
$updatedAt   = (string)($runState['updated_at'] ?? '—');
$queuedAt    = (string)($runState['queued_at'] ?? '—');

$lrCandidates   = (int)($lastRun['candidates_total'] ?? 0);
$lrSignals      = (int)($lastRun['signals_total'] ?? 0);
$lrHandoff      = (int)($lastRun['handoff_ready_total'] ?? 0);
$lrRejects      = (int)($lastRun['rejects_total'] ?? 0);
$lrDurationMs   = (int)($lastRun['duration_ms'] ?? 0);
$lrBatchTotal   = (int)($lastRun['batch_symbols_total'] ?? 0);
$lrFinishedAt   = (string)($lastRun['finished_at'] ?? '—');
$lrStartedAt    = (string)($lastRun['started_at'] ?? '—');
$lrFilterEn     = (bool)($lastRun['filter_engine_enabled'] ?? false);
$lrFiltersCount = (int)($lastRun['enabled_filters_count'] ?? 0);
$lrEnforcement  = (string)($lastRun['filter_enforcement_mode'] ?? 'diagnostic_only');
$lrRejectCounts = (array)($lastRun['reject_reason_counts'] ?? []);
$lrOiMissingAllow = (int)($lastRun['oi_missing_allowed_total'] ?? 0);
$lrPricePass    = (int)($lastRun['price_impulse_pass_total'] ?? 0);
$lrOiGrowthPass = (int)($lastRun['oi_growth_pass_total'] ?? 0);
$lrInsuffData   = (int)($lastRun['insufficient_data_total'] ?? 0);
$lrStaleData    = (int)($lastRun['stale_data_total'] ?? 0);
$lrSrcError     = (int)($lastRun['data_source_error_total'] ?? 0);

$statusColor = match ($runStatus) {
    'running' => '#22c55e',
    'queued'  => '#f59e0b',
    'done'    => '#3b82f6',
    'idle'    => '#64748b',
    default   => '#64748b',
};

$storageExists = is_dir($storageDir);
?>
<style>
.eig-rt-page { max-width: 860px; }
.rt-section { background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.rt-section h6 { color: #94a3b8; text-transform: uppercase; font-size: 11px; letter-spacing: .05em; margin-bottom: 14px; }
.rt-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px,1fr)); gap: 10px; margin-bottom: 14px; }
.rt-box  { background: rgba(255,255,255,.03); border: 1px solid var(--border-color,#334155); border-radius: 6px; padding: 10px 14px; }
.rt-val  { font-size: 20px; font-weight: 700; color: #e2e8f0; word-break: break-all; }
.rt-lbl  { font-size: 11px; color: #64748b; margin-top: 2px; }
.rt-kv td { font-size: 12px; padding: 4px 8px; }
</style>

<div class="eig-rt-page">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:8px;">
    <h4 style="margin:0;font-size:18px;">Early Impulse Growth Long — Runtime</h4>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="<?= $e($eigUrl) ?>/config" class="btn btn-sm" style="background:rgba(88,166,255,.10);color:#58a6ff;border:1px solid #58a6ff44;">Config</a>
      <a href="<?= $e($eigUrl) ?>/runtime" class="btn btn-sm" style="background:rgba(56,189,248,.15);color:#38bdf8;border:1px solid #38bdf844;">Runtime</a>
      <a href="<?= $e($eigUrl) ?>/stats" class="btn btn-sm" style="background:rgba(167,139,250,.10);color:#a78bfa;border:1px solid #a78bfa44;">Stats</a>
    </div>
  </div>

  <?php if (!$storageExists): ?>
  <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.3);border-radius:6px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#f59e0b;">
    Storage directory does not exist yet. Run the first tick to initialise.
  </div>
  <?php endif; ?>

  <!-- Run state -->
  <div class="rt-section">
    <h6>Run State</h6>
    <div class="rt-grid">
      <div class="rt-box">
        <div class="rt-val" style="color:<?= $e($statusColor) ?>;font-size:16px;"><?= $e($runStatus) ?></div>
        <div class="rt-lbl">status</div>
      </div>
      <div class="rt-box">
        <div class="rt-val"><?= $e($unvTotal) ?></div>
        <div class="rt-lbl">universe_total</div>
      </div>
      <div class="rt-box">
        <div class="rt-val"><?= $e($selTotal) ?></div>
        <div class="rt-lbl">selected_window</div>
      </div>
      <div class="rt-box">
        <div class="rt-val"><?= $e($batchSize) ?></div>
        <div class="rt-lbl">batch_size</div>
      </div>
      <div class="rt-box">
        <div class="rt-val"><?= $e($batchOffset) ?></div>
        <div class="rt-lbl">batch_offset</div>
      </div>
      <div class="rt-box">
        <div class="rt-val"><?= $e($regCursor) ?></div>
        <div class="rt-lbl">registry_cursor</div>
      </div>
    </div>
    <table class="rt-kv">
      <tr><td style="color:#64748b">next_registry_cursor</td><td><?= $e($nextCursor) ?></td>
          <td style="color:#64748b;padding-left:16px">previous_registry_cursor</td><td><?= $e($prevCursor) ?></td></tr>
      <tr><td style="color:#64748b">registry_window_start</td><td><?= $e($winStart) ?></td>
          <td style="color:#64748b;padding-left:16px">registry_window_end</td><td><?= $e($winEnd) ?></td></tr>
      <tr><td style="color:#64748b">registry_window_wrapped</td><td><?= $fmtBool($winWrapped) ?></td>
          <td style="color:#64748b;padding-left:16px">queued_at</td><td><?= $e($queuedAt) ?></td></tr>
      <tr><td style="color:#64748b">updated_at</td><td><?= $e($updatedAt) ?></td></tr>
    </table>

    <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
      <form method="post" action="<?= $e($ajaxUrl) ?>" style="margin:0;">
        <input type="hidden" name="action" value="queue_run">
        <button type="submit" class="btn btn-sm" style="background:rgba(63,185,80,.12);color:#3fb950;border:1px solid #3fb95055;">
          Запуск цикла
        </button>
      </form>
      <form method="post" action="<?= $e($ajaxUrl) ?>" style="margin:0;">
        <input type="hidden" name="action" value="tick_batch">
        <button type="submit" class="btn btn-sm" style="background:rgba(88,166,255,.12);color:#58a6ff;border:1px solid #58a6ff55;">
          Тик батча
        </button>
      </form>
    </div>
  </div>

  <!-- Last Run -->
  <div class="rt-section">
    <h6>Last Run</h6>
    <div class="rt-grid">
      <div class="rt-box">
        <div class="rt-val"><?= $e($lrCandidates) ?></div>
        <div class="rt-lbl">candidates_total</div>
      </div>
      <div class="rt-box">
        <div class="rt-val"><?= $e($lrSignals) ?></div>
        <div class="rt-lbl">signals_total</div>
      </div>
      <div class="rt-box">
        <div class="rt-val"><?= $e($lrHandoff) ?></div>
        <div class="rt-lbl">handoff_ready</div>
      </div>
      <div class="rt-box">
        <div class="rt-val"><?= $e($lrRejects) ?></div>
        <div class="rt-lbl">rejects_total</div>
      </div>
      <div class="rt-box">
        <div class="rt-val"><?= $e($lrBatchTotal) ?></div>
        <div class="rt-lbl">batch_symbols</div>
      </div>
      <div class="rt-box">
        <div class="rt-val"><?= $e($lrDurationMs) ?>ms</div>
        <div class="rt-lbl">duration</div>
      </div>
    </div>
    <table class="rt-kv">
      <tr><td style="color:#64748b">started_at</td><td><?= $e($lrStartedAt) ?></td>
          <td style="color:#64748b;padding-left:16px">finished_at</td><td><?= $e($lrFinishedAt) ?></td></tr>
      <tr><td style="color:#64748b">price_impulse_pass_total</td><td><?= $e($lrPricePass) ?></td>
          <td style="color:#64748b;padding-left:16px">oi_growth_pass_total</td><td><?= $e($lrOiGrowthPass) ?></td></tr>
      <tr><td style="color:#64748b">oi_missing_allowed_total</td><td><?= $e($lrOiMissingAllow) ?></td>
          <td style="color:#64748b;padding-left:16px">insufficient_data_total</td><td><?= $e($lrInsuffData) ?></td></tr>
      <tr><td style="color:#64748b">stale_data_total</td><td><?= $e($lrStaleData) ?></td>
          <td style="color:#64748b;padding-left:16px">data_source_error_total</td><td><?= $e($lrSrcError) ?></td></tr>
      <tr><td style="color:#64748b">filter_engine_enabled</td><td><?= $fmtBool($lrFilterEn) ?></td>
          <td style="color:#64748b;padding-left:16px">enabled_filters_count</td><td><?= $e($lrFiltersCount) ?></td></tr>
      <tr><td style="color:#64748b">filter_enforcement_mode</td><td><code><?= $e($lrEnforcement) ?></code></td></tr>
    </table>

    <?php if (!empty($lrRejectCounts)): ?>
    <div style="margin-top:12px;">
      <div style="font-size:11px;color:#94a3b8;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em;">Reject Reason Counts</div>
      <table class="rt-kv">
        <?php foreach ($lrRejectCounts as $reason => $cnt): ?>
        <tr><td style="color:#64748b"><?= $e($reason) ?></td><td><?= $e($cnt) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Active config summary -->
  <div class="rt-section">
    <h6>Active Config</h6>
    <table class="rt-kv">
      <tr><td style="color:#64748b">enabled</td><td><?= $fmtBool($config['enabled'] ?? false) ?></td>
          <td style="color:#64748b;padding-left:16px">handoff_enabled</td><td><?= $fmtBool($config['handoff_enabled'] ?? false) ?></td></tr>
      <tr><td style="color:#64748b">impulse_window_minutes</td><td><code><?= $e($config['impulse_window_minutes'] ?? 10) ?></code></td>
          <td style="color:#64748b;padding-left:16px">batch_size</td><td><code><?= $e($config['batch_size'] ?? 100) ?></code></td></tr>
      <tr><td style="color:#64748b">min_price_impulse_pct</td><td><code><?= $e($config['min_price_impulse_pct'] ?? 0.4) ?></code></td>
          <td style="color:#64748b;padding-left:16px">max_price_impulse_pct</td><td><code><?= $e($config['max_price_impulse_pct'] ?? 4.0) ?></code></td></tr>
      <tr><td style="color:#64748b">open_interest_enabled</td><td><?= $fmtBool($config['open_interest_enabled'] ?? true) ?></td>
          <td style="color:#64748b;padding-left:16px">allow_missing_open_interest</td><td><?= $fmtBool($config['allow_missing_open_interest'] ?? true) ?></td></tr>
      <tr><td style="color:#64748b">filter_engine_enabled</td><td><?= $fmtBool($config['filter_engine_enabled'] ?? true) ?></td>
          <td style="color:#64748b;padding-left:16px">filter_enforcement_mode</td><td><code><?= $e($config['filter_enforcement_mode'] ?? 'diagnostic_only') ?></code></td></tr>
    </table>
  </div>
</div>
