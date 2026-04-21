<?php

declare(strict_types=1);

/**
 * Pattern Strategy — Admin Config Page
 */

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

if (!Auth::check()) {
    header('Location: ' . System::adminUrl('login'));
    exit;
}

$moduleDir = SystemPaths::instance()->get('strategy.pattern');

require_once $moduleDir . '/service.php';
require_once $moduleDir . '/bootstrap.php';

use Modules\Strategy\Pattern\PatternService;
use Modules\Strategy\Pattern\PatternBootstrap;

$service = PatternService::instance($moduleDir);

try {
    $boot         = PatternBootstrap::instance($moduleDir)->load();
    $config       = $boot['config'];
    $configValid  = $boot['valid'];
    $configErrors = $boot['errors'];
} catch (\Throwable $e) {
    $config       = [];
    $configValid  = false;
    $configErrors = [$e->getMessage()];
}

$schema = require $moduleDir . '/config/schema.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$flash = $_SESSION['pattern_flash'] ?? null;
unset($_SESSION['pattern_flash']);

$patternUrl = rtrim(System::web('admin/strategy/pattern'), '/');
$ajaxUrl    = System::web('admin/strategy/pattern/ajax');

// Form defaults
$fEnabled    = (bool)($config['enabled']    ?? false);
$fMode       = (string)($config['mode']     ?? 'passive');
$fSideMode   = (string)($config['side_mode'] ?? 'both');
$fBudget     = 0; // no bot budget in v1 foundation
?>
<style>
.pattern-config-page { max-width: 860px; }
.cfg-section { background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.cfg-section h6 { color: #94a3b8; text-transform: uppercase; font-size: 11px; letter-spacing: .05em; margin-bottom: 14px; }
.effective-cfg th, .effective-cfg td { font-size: 12px; padding: 4px 8px; }
</style>

<div class="pattern-config-page">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h4 class="mb-0">Pattern — Config</h4>
        <div>
            <a href="<?= $patternUrl ?>" class="btn btn-sm btn-outline-secondary">← Index</a>
            <a href="<?= $patternUrl ?>/stats" class="btn btn-sm btn-outline-secondary ms-1">Stats</a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?? 'info' ?> py-2"><?= htmlspecialchars($flash['msg'] ?? '') ?></div>
    <?php endif; ?>

    <?php if (!$configValid): ?>
    <div class="alert alert-danger py-2">
        <strong>Config errors:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($configErrors as $err): ?>
            <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Edit form -->
    <form method="post" action="<?= htmlspecialchars($ajaxUrl) ?>">
        <input type="hidden" name="action" value="save_config">

        <div class="cfg-section">
            <h6>Core</h6>
            <div class="row g-3">
                <div class="col-sm-4">
                    <label class="form-label">Enabled</label>
                    <select name="enabled" class="form-select form-select-sm">
                        <option value="1" <?= $fEnabled ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= !$fEnabled ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="col-sm-4">
                    <label class="form-label">Mode</label>
                    <select name="mode" class="form-select form-select-sm">
                        <?php foreach (['passive','active','disabled','smoke_demo'] as $m): ?>
                        <option value="<?= $m ?>" <?= $fMode === $m ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-4">
                    <label class="form-label">Side Mode</label>
                    <select name="side_mode" class="form-select form-select-sm">
                        <?php foreach (['both','long_only','short_only'] as $sm): ?>
                        <option value="<?= $sm ?>" <?= $fSideMode === $sm ? 'selected' : '' ?>><?= $sm ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="cfg-section">
            <h6>Gates</h6>
            <div class="row g-3">
                <div class="col-sm-3">
                    <label class="form-label">Market Regime Enabled</label>
                    <select name="market_regime_enabled" class="form-select form-select-sm">
                        <option value="1" <?= ($config['market_regime_enabled'] ?? true) ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= !($config['market_regime_enabled'] ?? true) ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Regime Gate Mode</label>
                    <select name="market_regime_gate_mode" class="form-select form-select-sm">
                        <?php foreach (['soft','hard'] as $g): ?>
                        <option value="<?= $g ?>" <?= ($config['market_regime_gate_mode'] ?? 'soft') === $g ? 'selected' : '' ?>><?= $g ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Trend Required</label>
                    <select name="trend_required" class="form-select form-select-sm">
                        <option value="1" <?= ($config['trend_required'] ?? true) ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= !($config['trend_required'] ?? true) ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Wave Required</label>
                    <select name="wave_required" class="form-select form-select-sm">
                        <option value="1" <?= ($config['wave_required'] ?? true) ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= !($config['wave_required'] ?? true) ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="cfg-section">
            <h6>Corridor</h6>
            <div class="row g-3">
                <div class="col-sm-3">
                    <label class="form-label">Corridor Required</label>
                    <select name="corridor_required" class="form-select form-select-sm">
                        <option value="1" <?= ($config['corridor_required'] ?? true) ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= !($config['corridor_required'] ?? true) ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Lookback Hours</label>
                    <input type="number" name="corridor_lookback_hours" class="form-control form-control-sm"
                           value="<?= (int)($config['corridor_lookback_hours'] ?? 24) ?>">
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Bucket Count</label>
                    <input type="number" name="corridor_bucket_count" class="form-control form-control-sm"
                           value="<?= (int)($config['corridor_bucket_count'] ?? 10) ?>">
                </div>
            </div>
        </div>

        <div class="cfg-section">
            <h6>Confirmation</h6>
            <div class="row g-3">
                <div class="col-sm-3">
                    <label class="form-label">Confirm Required</label>
                    <select name="confirm_required" class="form-select form-select-sm">
                        <option value="1" <?= ($config['confirm_required'] ?? true) ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= !($config['confirm_required'] ?? true) ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Confirm Max Bars</label>
                    <input type="number" name="confirm_max_bars" class="form-control form-control-sm"
                           value="<?= (int)($config['confirm_max_bars'] ?? 2) ?>">
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Signal TTL Bars</label>
                    <input type="number" name="signal_ttl_bars" class="form-control form-control-sm"
                           value="<?= (int)($config['signal_ttl_bars'] ?? 2) ?>">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">Save Config</button>
        <a href="<?= $ajaxUrl ?>?action=reset_active" class="btn btn-sm btn-outline-danger ms-2"
           onclick="return confirm('Reset all overrides to base defaults?')">Reset to Base</a>
    </form>

    <!-- Effective config table -->
    <div class="cfg-section mt-4">
        <h6>Effective Config</h6>
        <table class="table table-sm effective-cfg">
            <thead><tr><th>Key</th><th>Value</th><th>Type</th></tr></thead>
            <tbody>
            <?php foreach ($schema as $k => $type): ?>
            <tr>
                <td><code><?= htmlspecialchars($k) ?></code></td>
                <td>
                    <?php
                    $v = $config[$k] ?? null;
                    if (is_array($v)) { echo '<code>' . htmlspecialchars(json_encode($v)) . '</code>'; }
                    elseif (is_bool($v)) { echo $v ? '<span class="text-success">true</span>' : '<span class="text-danger">false</span>'; }
                    else { echo '<code>' . htmlspecialchars((string)$v) . '</code>'; }
                    ?>
                </td>
                <td><small class="text-muted"><?= htmlspecialchars($type) ?></small></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
