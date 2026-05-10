<?php

declare(strict_types=1);

/**
 * Early Impulse Growth Long — Admin Config Page
 */

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

if (!Auth::check()) {
    header('Location: ' . System::adminUrl('login'));
    exit;
}

$moduleDir = SystemPaths::instance()->get('strategy.early_impulse_growth_long');
$eigUrl    = rtrim(System::web('admin/strategy/early_impulse_growth_long'), '/');
$ajaxUrl   = $eigUrl . '/ajax';

// Load merged config (base + active)
$base   = is_file($moduleDir . '/config/base.php')   ? (@include $moduleDir . '/config/base.php')   : [];
$active = is_file($moduleDir . '/config/active.php') ? (@include $moduleDir . '/config/active.php') : [];
$config = array_merge(
    is_array($base)   ? $base   : [],
    is_array($active) ? $active : []
);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$flash = $_SESSION['eig_flash'] ?? null;
unset($_SESSION['eig_flash']);

$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$cfg = static fn(string $k, mixed $def = ''): string => (string)($config[$k] ?? $def);
$cfgBool = static fn(string $k, bool $def = false): string => ($config[$k] ?? $def) ? '1' : '0';
$cfgFloat = static fn(string $k, float $def = 0.0): string => (string)(float)($config[$k] ?? $def);
$cfgInt = static fn(string $k, int $def = 0): string => (string)(int)($config[$k] ?? $def);
?>
<style>
.eig-cfg-page { max-width: 860px; }
.cfg-section { background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.cfg-section h6 { color: #94a3b8; text-transform: uppercase; font-size: 11px; letter-spacing: .05em; margin-bottom: 14px; }
.cfg-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; margin-bottom: 14px; }
.cfg-grid .cfg-full { grid-column: 1 / -1; }
.cfg-label { font-size: 12px; color: var(--ui-text-muted); display: block; margin-bottom: 4px; }
</style>

<div class="eig-cfg-page">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:8px;">
    <h4 style="margin:0;font-size:18px;">Early Impulse Growth Long — Config</h4>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="<?= $e($eigUrl) ?>/config" class="btn btn-sm" style="background:rgba(88,166,255,.15);color:#58a6ff;border:1px solid #58a6ff44;">Config</a>
      <a href="<?= $e($eigUrl) ?>/runtime" class="btn btn-sm" style="background:rgba(56,189,248,.10);color:#38bdf8;border:1px solid #38bdf844;">Runtime</a>
      <a href="<?= $e($eigUrl) ?>/stats" class="btn btn-sm" style="background:rgba(167,139,250,.10);color:#a78bfa;border:1px solid #a78bfa44;">Stats</a>
    </div>
  </div>

  <?php if ($flash): ?>
  <div style="background:<?= $flash['type'] === 'success' ? 'rgba(63,185,80,.1)' : 'rgba(248,81,73,.1)' ?>;border:1px solid <?= $flash['type'] === 'success' ? '#3fb95044' : '#f8514944' ?>;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:13px;color:<?= $flash['type'] === 'success' ? '#3fb950' : '#f85149' ?>;">
    <?= $e($flash['msg']) ?>
  </div>
  <?php endif; ?>

  <form method="post" action="<?= $e($ajaxUrl) ?>">
    <input type="hidden" name="action" value="save_config">

    <!-- Core toggle -->
    <div class="cfg-section">
      <h6>Core Settings</h6>
      <div class="cfg-grid">
        <div>
          <label class="cfg-label">enabled</label>
          <select name="enabled" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <option value="1" <?= $cfgBool('enabled') === '1' ? 'selected' : '' ?>>true</option>
            <option value="0" <?= $cfgBool('enabled') === '0' ? 'selected' : '' ?>>false</option>
          </select>
        </div>
        <div>
          <label class="cfg-label">handoff_enabled</label>
          <select name="handoff_enabled" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <option value="1" <?= $cfgBool('handoff_enabled') === '1' ? 'selected' : '' ?>>true</option>
            <option value="0" <?= $cfgBool('handoff_enabled') === '0' ? 'selected' : '' ?>>false</option>
          </select>
        </div>
        <div>
          <label class="cfg-label">batch_size</label>
          <input type="number" name="batch_size" min="1" max="1000" value="<?= $e($cfgInt('batch_size', 100)) ?>"
            class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
        </div>
        <div>
          <label class="cfg-label">max_symbols_per_run</label>
          <input type="number" name="max_symbols_per_run" min="1" max="1000" value="<?= $e($cfgInt('max_symbols_per_run', 100)) ?>"
            class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
        </div>
        <div>
          <label class="cfg-label">continuous_scan_enabled</label>
          <select name="continuous_scan_enabled" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <option value="1" <?= $cfgBool('continuous_scan_enabled', true) === '1' ? 'selected' : '' ?>>true</option>
            <option value="0" <?= $cfgBool('continuous_scan_enabled', true) === '0' ? 'selected' : '' ?>>false</option>
          </select>
        </div>
        <div>
          <label class="cfg-label">auto_requeue_when_done</label>
          <select name="auto_requeue_when_done" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <option value="1" <?= $cfgBool('auto_requeue_when_done', true) === '1' ? 'selected' : '' ?>>true</option>
            <option value="0" <?= $cfgBool('auto_requeue_when_done', true) === '0' ? 'selected' : '' ?>>false</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Window -->
    <div class="cfg-section">
      <h6>Impulse Window</h6>
      <div class="cfg-grid">
        <div>
          <label class="cfg-label">impulse_window_minutes</label>
          <input type="number" name="impulse_window_minutes" min="1" max="60" value="<?= $e($cfgInt('impulse_window_minutes', 10)) ?>"
            class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
        </div>
        <div>
          <label class="cfg-label">impulse_min_window_minutes</label>
          <input type="number" name="impulse_min_window_minutes" min="1" max="60" value="<?= $e($cfgInt('impulse_min_window_minutes', 5)) ?>"
            class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
        </div>
        <div>
          <label class="cfg-label">impulse_max_window_minutes</label>
          <input type="number" name="impulse_max_window_minutes" min="1" max="60" value="<?= $e($cfgInt('impulse_max_window_minutes', 10)) ?>"
            class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
        </div>
      </div>
    </div>

    <!-- Price impulse -->
    <div class="cfg-section">
      <h6>Price Impulse Growth</h6>
      <div class="cfg-grid">
        <div>
          <label class="cfg-label">min_price_impulse_pct (%)</label>
          <input type="number" name="min_price_impulse_pct" step="0.01" min="0" max="100" value="<?= $e($cfgFloat('min_price_impulse_pct', 0.4)) ?>"
            class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
        </div>
        <div>
          <label class="cfg-label">max_price_impulse_pct (%)</label>
          <input type="number" name="max_price_impulse_pct" step="0.01" min="0" max="100" value="<?= $e($cfgFloat('max_price_impulse_pct', 4.0)) ?>"
            class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
        </div>
        <div>
          <label class="cfg-label">min_price_impulse_score (0–1)</label>
          <input type="number" name="min_price_impulse_score" step="0.01" min="0" max="1" value="<?= $e($cfgFloat('min_price_impulse_score', 0.55)) ?>"
            class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
        </div>
      </div>
    </div>

    <!-- Open interest -->
    <div class="cfg-section">
      <h6>Open Interest Growth</h6>
      <div class="cfg-grid">
        <div>
          <label class="cfg-label">open_interest_enabled</label>
          <select name="open_interest_enabled" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <option value="1" <?= $cfgBool('open_interest_enabled', true) === '1' ? 'selected' : '' ?>>true</option>
            <option value="0" <?= $cfgBool('open_interest_enabled', true) === '0' ? 'selected' : '' ?>>false</option>
          </select>
        </div>
        <div>
          <label class="cfg-label">allow_missing_open_interest</label>
          <select name="allow_missing_open_interest" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <option value="1" <?= $cfgBool('allow_missing_open_interest', true) === '1' ? 'selected' : '' ?>>true</option>
            <option value="0" <?= $cfgBool('allow_missing_open_interest', true) === '0' ? 'selected' : '' ?>>false</option>
          </select>
        </div>
        <div>
          <label class="cfg-label">min_open_interest_growth_pct (%)</label>
          <input type="number" name="min_open_interest_growth_pct" step="0.01" min="0" max="100" value="<?= $e($cfgFloat('min_open_interest_growth_pct', 1.0)) ?>"
            class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
        </div>
        <div>
          <label class="cfg-label">min_open_interest_growth_score (0–1)</label>
          <input type="number" name="min_open_interest_growth_score" step="0.01" min="0" max="1" value="<?= $e($cfgFloat('min_open_interest_growth_score', 0.55)) ?>"
            class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
        </div>
        <div>
          <label class="cfg-label">missing_open_interest_mode</label>
          <select name="missing_open_interest_mode" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <?php foreach (['diagnostic_only', 'block'] as $mMode): ?>
            <option value="<?= $e($mMode) ?>" <?= $cfg('missing_open_interest_mode', 'diagnostic_only') === $mMode ? 'selected' : '' ?>><?= $e($mMode) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <!-- Filter engine -->
    <div class="cfg-section">
      <h6>Filter Engine</h6>
      <div class="cfg-grid">
        <div>
          <label class="cfg-label">filter_engine_enabled</label>
          <select name="filter_engine_enabled" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <option value="1" <?= $cfgBool('filter_engine_enabled', true) === '1' ? 'selected' : '' ?>>true</option>
            <option value="0" <?= $cfgBool('filter_engine_enabled', true) === '0' ? 'selected' : '' ?>>false</option>
          </select>
        </div>
        <div>
          <label class="cfg-label">filter_enforcement_mode</label>
          <select name="filter_enforcement_mode" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <?php foreach (['diagnostic_only', 'soft', 'strict'] as $enfMode): ?>
            <option value="<?= $e($enfMode) ?>" <?= $cfg('filter_enforcement_mode', 'diagnostic_only') === $enfMode ? 'selected' : '' ?>><?= $e($enfMode) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <p style="font-size:12px;color:#64748b;margin:0 0 12px;">
        <strong>Default: diagnostic_only.</strong> All filters are disabled for this strategy on first run.
        Filters are managed by the external filter engine; this strategy only passes signal context.
      </p>
    </div>

    <!-- Data source knobs -->
    <div class="cfg-section">
      <h6>Data Source Knobs</h6>
      <div class="cfg-grid">
        <div>
          <label class="cfg-label">max_data_staleness_seconds</label>
          <input type="number" name="max_data_staleness_seconds" min="30" max="600" value="<?= $e($cfgInt('max_data_staleness_seconds', 180)) ?>"
            class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
        </div>
        <div>
          <label class="cfg-label">parser2_history_lookback_minutes</label>
          <input type="number" name="parser2_history_lookback_minutes" min="30" max="1440" value="<?= $e($cfgInt('parser2_history_lookback_minutes', 180)) ?>"
            class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
        </div>
        <div>
          <label class="cfg-label">bybit_kline_limit</label>
          <input type="number" name="bybit_kline_limit" min="20" max="200" value="<?= $e($cfgInt('bybit_kline_limit', 120)) ?>"
            class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
        </div>
        <div>
          <label class="cfg-label">bybit_timeout_sec</label>
          <input type="number" name="bybit_timeout_sec" min="3" max="30" value="<?= $e($cfgInt('bybit_timeout_sec', 6)) ?>"
            class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
        </div>
      </div>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:24px;">
      <button type="submit" class="btn btn-primary">Сохранить</button>
      <a href="<?= $e($eigUrl) ?>/config" class="btn" style="background:transparent;border:1px solid var(--ui-border);color:var(--ui-text-muted);">Отмена</a>
    </div>
  </form>

  <!-- Reset active -->
  <div class="cfg-section">
    <h6>Reset Overrides</h6>
    <p style="font-size:13px;color:#64748b;margin:0 0 10px;">
      Clears active.php overrides and reverts all settings to base.php defaults.
    </p>
    <form method="post" action="<?= $e($ajaxUrl) ?>" onsubmit="return confirm('Reset all active overrides to defaults?');">
      <input type="hidden" name="action" value="reset_active">
      <button type="submit" class="btn btn-sm" style="background:rgba(248,81,73,.10);color:#f85149;border:1px solid #f8514944;">
        Reset to defaults
      </button>
    </form>
  </div>

  <!-- Effective config dump -->
  <div class="cfg-section">
    <h6>Effective Config (merged base + active)</h6>
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
      <thead>
        <tr style="border-bottom:1px solid var(--border-color,#334155);">
          <th style="text-align:left;padding:4px 8px;color:#94a3b8;">Key</th>
          <th style="text-align:left;padding:4px 8px;color:#94a3b8;">Value</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($config as $k => $v): ?>
        <tr style="border-bottom:1px solid rgba(51,65,85,.5);">
          <td style="padding:3px 8px;color:#64748b;"><?= $e($k) ?></td>
          <td style="padding:3px 8px;">
            <?php if (is_bool($v)): ?>
              <span style="color:<?= $v ? '#22c55e' : '#94a3b8' ?>"><?= $v ? 'true' : 'false' ?></span>
            <?php elseif (is_array($v)): ?>
              <code><?= $e(json_encode($v, JSON_UNESCAPED_UNICODE)) ?></code>
            <?php else: ?>
              <code><?= $e((string)$v) ?></code>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
