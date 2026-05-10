<?php

declare(strict_types=1);

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

if (!Auth::check()) {
    header('Location: ' . System::adminUrl('login'));
    exit;
}

$moduleDir = SystemPaths::instance()->get('strategy.early_impulse_growth_long');
$eigUrl = rtrim(System::web('admin/strategy/early_impulse_growth_long'), '/');
$ajaxUrl = $eigUrl . '/ajax';

require_once $moduleDir . '/service.php';
$service = \Modules\Strategy\EarlyImpulseGrowthLong\EarlyImpulseGrowthLongService::instance($moduleDir);
$config = $service->getConfig();
$filterCatalog = $service->getFilterCatalog();
$filterProfiles = $service->getFilterProfiles();
$filterConfig = is_array($config['filter_config'] ?? null) ? (array)$config['filter_config'] : [];
$currentProfile = (string)($config['filter_profile_active'] ?? $config['filter_profile'] ?? 'raw_no_filters');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$flash = $_SESSION['eig_flash'] ?? null;
unset($_SESSION['eig_flash']);

$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$cfg = static fn(string $k, mixed $def = ''): mixed => $config[$k] ?? $def;
$severityOptions = ['warning', 'soft_block', 'hard_block', 'fatal'];
?>
<style>
.eig-cfg-page { max-width: 1180px; }
.cfg-section { background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.cfg-section h6 { color: #94a3b8; text-transform: uppercase; font-size: 11px; letter-spacing: .05em; margin-bottom: 14px; }
.cfg-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 16px; margin-bottom: 14px; }
.cfg-label { font-size: 12px; color: var(--ui-text-muted); display: block; margin-bottom: 4px; }
.filter-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:16px; }
.filter-card { border:1px solid rgba(51,65,85,.8); border-radius:8px; padding:14px; background:rgba(15,23,42,.35); }
.filter-card h5 { margin:0; font-size:14px; }
.filter-meta { display:flex; gap:8px; flex-wrap:wrap; margin:8px 0 10px; }
.filter-badge { font-size:11px; border-radius:999px; padding:2px 8px; border:1px solid rgba(148,163,184,.35); color:#cbd5e1; }
.filter-desc { font-size:12px; color:#94a3b8; margin-bottom:12px; min-height:32px; }
.filter-fields { display:grid; grid-template-columns: 1fr 1fr; gap:10px 12px; }
.filter-fields .cfg-full { grid-column:1 / -1; }
.note { font-size:12px; color:#64748b; }
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

    <div class="cfg-section">
      <h6>Filter profile presets</h6>
      <div class="cfg-grid">
        <div>
          <label class="cfg-label">filter_profile_active</label>
          <select name="filter_profile_active" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <?php foreach ($filterProfiles as $profileId => $profile): ?>
            <option value="<?= $e($profileId) ?>" <?= $currentProfile === $profileId ? 'selected' : '' ?>><?= $e($profileId) ?> — <?= $e($profile['title'] ?? $profileId) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <div class="note" style="padding-top:22px;">Profiles are presets/templates only. Click <strong>Apply preset</strong> to copy preset values into active config. Runtime always reads active config.</div>
        </div>
      </div>
      <button type="submit" name="apply_filter_profile" value="1" class="btn btn-sm" style="background:rgba(168,85,247,.15);color:#c084fc;border:1px solid #c084fc44;">Apply preset</button>
    </div>

    <div class="cfg-section">
      <h6>Core settings</h6>
      <div class="cfg-grid">
        <div>
          <label class="cfg-label">enabled</label>
          <select name="enabled" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <option value="1" <?= (bool)$cfg('enabled') ? 'selected' : '' ?>>true</option>
            <option value="0" <?= !(bool)$cfg('enabled') ? 'selected' : '' ?>>false</option>
          </select>
        </div>
        <div>
          <label class="cfg-label">handoff_enabled</label>
          <select name="handoff_enabled" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <option value="1" <?= (bool)$cfg('handoff_enabled') ? 'selected' : '' ?>>true</option>
            <option value="0" <?= !(bool)$cfg('handoff_enabled') ? 'selected' : '' ?>>false</option>
          </select>
        </div>
        <div>
          <label class="cfg-label">batch_size</label>
          <input type="number" name="batch_size" min="1" max="1000" value="<?= $e((int)$cfg('batch_size', 100)) ?>" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
        </div>
        <div>
          <label class="cfg-label">max_symbols_per_run</label>
          <input type="number" name="max_symbols_per_run" min="1" max="1000" value="<?= $e((int)$cfg('max_symbols_per_run', 100)) ?>" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
        </div>
        <div>
          <label class="cfg-label">continuous_scan_enabled</label>
          <select name="continuous_scan_enabled" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <option value="1" <?= (bool)$cfg('continuous_scan_enabled', true) ? 'selected' : '' ?>>true</option>
            <option value="0" <?= !(bool)$cfg('continuous_scan_enabled', true) ? 'selected' : '' ?>>false</option>
          </select>
        </div>
        <div>
          <label class="cfg-label">auto_requeue_when_done</label>
          <select name="auto_requeue_when_done" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <option value="1" <?= (bool)$cfg('auto_requeue_when_done', true) ? 'selected' : '' ?>>true</option>
            <option value="0" <?= !(bool)$cfg('auto_requeue_when_done', true) ? 'selected' : '' ?>>false</option>
          </select>
        </div>
      </div>
    </div>

    <div class="cfg-section">
      <h6>Recovery window — primary signal (2–4 hours post-dump)</h6>
      <p class="note" style="margin:0 0 12px;">Finds sustained recovery/growth after a prior decline. Not 5–10 min pump spikes.</p>
      <div class="cfg-grid">
        <div><label class="cfg-label">recovery_window_minutes <span class="note">(primary scan window)</span></label><input type="number" name="recovery_window_minutes" min="10" max="480" value="<?= $e((int)$cfg('recovery_window_minutes', 180)) ?>" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;"></div>
        <div><label class="cfg-label">recovery_min_window_minutes</label><input type="number" name="recovery_min_window_minutes" min="10" max="480" value="<?= $e((int)$cfg('recovery_min_window_minutes', 120)) ?>" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;"></div>
        <div><label class="cfg-label">recovery_max_window_minutes</label><input type="number" name="recovery_max_window_minutes" min="10" max="480" value="<?= $e((int)$cfg('recovery_max_window_minutes', 240)) ?>" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;"></div>
        <div><label class="cfg-label">min_recovery_growth_pct</label><input type="number" name="min_recovery_growth_pct" step="0.01" min="0" max="100" value="<?= $e((float)$cfg('min_recovery_growth_pct', 3.0)) ?>" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;"></div>
        <div><label class="cfg-label">min_recovery_score <span class="note">(0–1)</span></label><input type="number" name="min_recovery_score" step="0.01" min="0" max="1" value="<?= $e((float)$cfg('min_recovery_score', 0.55)) ?>" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;"></div>
        <div><label class="cfg-label">max_recovery_growth_pct <span class="note">(diagnostic only)</span></label><input type="number" name="max_recovery_growth_pct" step="0.1" min="0" max="200" value="<?= $e((float)$cfg('max_recovery_growth_pct', 30.0)) ?>" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;"></div>
      </div>
    </div>

    <div class="cfg-section">
      <h6>Prior decline detection — required before recovery</h6>
      <p class="note" style="margin:0 0 12px;">Recovery must happen after a real dump. Both thresholds must pass.</p>
      <div class="cfg-grid">
        <div><label class="cfg-label">prior_decline_lookback_minutes</label><input type="number" name="prior_decline_lookback_minutes" min="30" max="1440" value="<?= $e((int)$cfg('prior_decline_lookback_minutes', 240)) ?>" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;"></div>
        <div><label class="cfg-label">min_prior_decline_pct</label><input type="number" name="min_prior_decline_pct" step="0.1" min="0" max="100" value="<?= $e((float)$cfg('min_prior_decline_pct', 2.0)) ?>" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;"></div>
      </div>
    </div>

    <div class="cfg-section">
      <h6>Open interest growth over recovery window</h6>
      <div class="cfg-grid">
        <div>
          <label class="cfg-label">open_interest_enabled</label>
          <select name="open_interest_enabled" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <option value="1" <?= (bool)$cfg('open_interest_enabled', true) ? 'selected' : '' ?>>true</option>
            <option value="0" <?= !(bool)$cfg('open_interest_enabled', true) ? 'selected' : '' ?>>false</option>
          </select>
        </div>
        <div>
          <label class="cfg-label">allow_missing_open_interest</label>
          <select name="allow_missing_open_interest" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <option value="1" <?= (bool)$cfg('allow_missing_open_interest', true) ? 'selected' : '' ?>>true</option>
            <option value="0" <?= !(bool)$cfg('allow_missing_open_interest', true) ? 'selected' : '' ?>>false</option>
          </select>
        </div>
        <div><label class="cfg-label">min_open_interest_growth_pct</label><input type="number" name="min_open_interest_growth_pct" step="0.01" min="0" max="100" value="<?= $e((float)$cfg('min_open_interest_growth_pct', 1.0)) ?>" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;"></div>
        <div><label class="cfg-label">min_open_interest_growth_score</label><input type="number" name="min_open_interest_growth_score" step="0.01" min="0" max="1" value="<?= $e((float)$cfg('min_open_interest_growth_score', 0.55)) ?>" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;"></div>
        <div>
          <label class="cfg-label">missing_open_interest_mode</label>
          <select name="missing_open_interest_mode" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <?php foreach (['diagnostic_only', 'block'] as $mode): ?>
            <option value="<?= $e($mode) ?>" <?= (string)$cfg('missing_open_interest_mode', 'diagnostic_only') === $mode ? 'selected' : '' ?>><?= $e($mode) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label class="cfg-label">current_acceleration_window_minutes <span class="note">(diagnostic only)</span></label><input type="number" name="current_acceleration_window_minutes" min="1" max="60" value="<?= $e((int)$cfg('current_acceleration_window_minutes', 10)) ?>" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;"></div>
      </div>
    </div>

    <div class="cfg-section">
      <h6>Filter engine runtime</h6>
      <div class="cfg-grid">
        <div>
          <label class="cfg-label">filter_engine_enabled</label>
          <select name="filter_engine_enabled" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <option value="1" <?= (bool)$cfg('filter_engine_enabled', true) ? 'selected' : '' ?>>true</option>
            <option value="0" <?= !(bool)$cfg('filter_engine_enabled', true) ? 'selected' : '' ?>>false</option>
          </select>
        </div>
        <div>
          <label class="cfg-label">filter_enforcement_mode</label>
          <select name="filter_enforcement_mode" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
            <?php foreach (['diagnostic_only', 'soft', 'strict'] as $mode): ?>
            <option value="<?= $e($mode) ?>" <?= (string)$cfg('filter_enforcement_mode', 'diagnostic_only') === $mode ? 'selected' : '' ?>><?= $e($mode) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label class="cfg-label">parser2_history_lookback_minutes</label><input type="number" name="parser2_history_lookback_minutes" min="30" max="1440" value="<?= $e((int)$cfg('parser2_history_lookback_minutes', 180)) ?>" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;"></div>
        <div><label class="cfg-label">max_data_staleness_seconds</label><input type="number" name="max_data_staleness_seconds" min="30" max="600" value="<?= $e((int)$cfg('max_data_staleness_seconds', 180)) ?>" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;"></div>
        <div><label class="cfg-label">bybit_kline_limit</label><input type="number" name="bybit_kline_limit" min="20" max="200" value="<?= $e((int)$cfg('bybit_kline_limit', 120)) ?>" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;"></div>
        <div><label class="cfg-label">bybit_timeout_sec</label><input type="number" name="bybit_timeout_sec" min="3" max="30" value="<?= $e((int)$cfg('bybit_timeout_sec', 6)) ?>" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;"></div>
      </div>
    </div>

    <div class="cfg-section">
      <h6>Auto-detected filters</h6>
      <p class="note" style="margin-bottom:14px;">Discovered from <code>modules/filter_engine/filters/*.php</code>. Newly discovered filters default to disabled until this strategy saves explicit values.</p>
      <div class="filter-grid">
        <?php foreach ($filterCatalog as $filterId => $meta): $row = is_array($filterConfig[$filterId] ?? null) ? (array)$filterConfig[$filterId] : []; ?>
        <div class="filter-card">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;">
            <div>
              <h5><?= $e($meta['title'] ?? $filterId) ?></h5>
              <div class="note"><code><?= $e($filterId) ?></code></div>
            </div>
            <div class="filter-badge">mode: <?= $e((string)$cfg('filter_enforcement_mode', 'diagnostic_only')) ?></div>
          </div>
          <div class="filter-meta">
            <span class="filter-badge">default severity: <?= $e($meta['default_severity'] ?? 'warning') ?></span>
            <span class="filter-badge">editable fields: <?= $e((string)count((array)($meta['configurable_fields'] ?? []))) ?></span>
          </div>
          <div class="filter-desc"><?= $e($meta['description'] ?? '') ?></div>
          <div class="filter-fields">
            <div>
              <label class="cfg-label">enabled</label>
              <input type="hidden" name="filter_config[<?= $e($filterId) ?>][enabled]" value="0">
              <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
                <input type="checkbox" name="filter_config[<?= $e($filterId) ?>][enabled]" value="1" <?= !empty($row['enabled']) ? 'checked' : '' ?>>
                enabled
              </label>
            </div>
            <div>
              <label class="cfg-label">severity</label>
              <select name="filter_config[<?= $e($filterId) ?>][severity]" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
                <?php foreach ($severityOptions as $severity): ?>
                <option value="<?= $e($severity) ?>" <?= (string)($row['severity'] ?? ($meta['default_severity'] ?? 'warning')) === $severity ? 'selected' : '' ?>><?= $e($severity) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php $fields = (array)($meta['configurable_fields'] ?? []); ?>
            <?php if ($fields === []): ?>
            <div class="cfg-full note">No editable fields.</div>
            <?php else: ?>
              <?php foreach ($fields as $field): if (!is_array($field)) { continue; }
                $fieldKey = (string)($field['key'] ?? '');
                if ($fieldKey === '') { continue; }
                $type = (string)($field['type'] ?? 'string');
                $value = $row[$fieldKey] ?? ($field['default'] ?? '');
              ?>
              <div class="<?= !empty($field['help']) ? 'cfg-full' : '' ?>">
                <label class="cfg-label"><?= $e($field['label'] ?? $fieldKey) ?></label>
                <?php if ($type === 'bool'): ?>
                  <input type="hidden" name="filter_config[<?= $e($filterId) ?>][<?= $e($fieldKey) ?>]" value="0">
                  <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
                    <input type="checkbox" name="filter_config[<?= $e($filterId) ?>][<?= $e($fieldKey) ?>]" value="1" <?= !empty($value) ? 'checked' : '' ?>>
                    <?= $e($field['label'] ?? $fieldKey) ?>
                  </label>
                <?php elseif ($type === 'select'): ?>
                  <select name="filter_config[<?= $e($filterId) ?>][<?= $e($fieldKey) ?>]" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
                    <?php foreach ((array)($field['allowed_values'] ?? []) as $allowed): ?>
                    <option value="<?= $e($allowed) ?>" <?= (string)$value === (string)$allowed ? 'selected' : '' ?>><?= $e($allowed) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <input type="<?= $type === 'int' || $type === 'float' ? 'number' : 'text' ?>" name="filter_config[<?= $e($filterId) ?>][<?= $e($fieldKey) ?>]" value="<?= $e($value) ?>" <?= $type === 'float' ? 'step="0.01"' : '' ?> <?= $type === 'int' ? 'step="1"' : '' ?> <?= array_key_exists('min', $field) ? 'min="' . $e($field['min']) . '"' : '' ?> <?= array_key_exists('max', $field) ? 'max="' . $e($field['max']) . '"' : '' ?> class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
                <?php endif; ?>
                <?php if (!empty($field['help'])): ?><div class="note" style="margin-top:4px;"><?= $e($field['help']) ?></div><?php endif; ?>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:24px;">
      <button type="submit" class="btn btn-primary">Save config</button>
      <a href="<?= $e($eigUrl) ?>/config" class="btn" style="background:transparent;border:1px solid var(--ui-border);color:var(--ui-text-muted);">Cancel</a>
    </div>
  </form>

  <div class="cfg-section">
    <h6>Reset overrides</h6>
    <p class="note" style="margin:0 0 10px;">Clears <code>active.php</code> overrides and reverts the strategy to base defaults.</p>
    <form method="post" action="<?= $e($ajaxUrl) ?>" onsubmit="return confirm('Reset all active overrides to defaults?');">
      <input type="hidden" name="action" value="reset_active">
      <button type="submit" class="btn btn-sm" style="background:rgba(248,81,73,.10);color:#f85149;border:1px solid #f8514944;">Reset to defaults</button>
    </form>
  </div>

  <div class="cfg-section">
    <h6>Effective config (runtime)</h6>
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
