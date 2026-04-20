<?php

declare(strict_types=1);

/**
 * Fish Strategy — Admin Config Page
 *
 * Editable form for operator overrides stored in config/active.php.
 * Also shows the full merged effective config below the form.
 */

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

if (!Auth::check()) {
    header('Location: ' . System::adminUrl('login'));
    exit;
}

$moduleDir = SystemPaths::instance()->get('strategy.fish');

require_once $moduleDir . '/service.php';
require_once $moduleDir . '/bootstrap.php';

use Modules\Strategy\Fish\FishService;
use Modules\Strategy\Fish\FishBootstrap;

$service  = FishService::instance($moduleDir);
$runState = $service->getRunState();

// Load effective merged config
try {
    $boot         = FishBootstrap::instance($moduleDir)->load();
    $config       = $boot['config'];
    $configValid  = $boot['valid'];
    $configErrors = $boot['errors'];
} catch (\Throwable $e) {
    $config       = [];
    $configValid  = false;
    $configErrors = [$e->getMessage()];
}

// Load schema for effective config table
$schema = require $moduleDir . '/config/schema.php';

// Consume session flash
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$flash = $_SESSION['fish_flash'] ?? null;
unset($_SESSION['fish_flash']);

$fishUrl  = rtrim(System::web('admin/strategy/fish'), '/');
$ajaxUrl  = System::web('admin/strategy/fish/ajax');
$statsUrl = System::web('admin/strategy/fish/stats');

// Form values — default to effective config so the form shows what is active
$fEnabled        = (bool)($config['enabled']       ?? false);
$fMode           = (string)($config['mode']        ?? 'passive');
$fUniverseMode   = (string)($config['universe_mode'] ?? 'all');
$fAllowedSymbols = implode("\n", (array)($config['allowed_symbols']  ?? []));
$fExcludedSymbols= implode("\n", (array)($config['excluded_symbols'] ?? []));
$fWindowEnabled  = (bool)($config['window_enabled'] ?? false);
$fWindowStart    = (string)($config['window_start'] ?? '08:00');
$fWindowEnd      = (string)($config['window_end']   ?? '22:00');
$fBudget         = (string)($config['budget']        ?? '0');
$fLeverage       = (string)($config['leverage']      ?? '1');
$fSlProfile      = (string)($config['sl_profile']    ?? 'default');
$fPmProfile      = (string)($config['pm_profile']    ?? 'default');

// Bot execution config
$fBotEnabled    = (bool)($config['bot_enabled']    ?? false);
$fExecMode      = (string)($config['execution_mode'] ?? 'smoke');
$fBotBudget     = (string)($config['bot_budget']   ?? '0');
$fBotLeverage   = (string)($config['bot_leverage'] ?? '1');
$fBotSlProfile  = (string)($config['bot_sl_profile'] ?? 'default');
$fBotPmProfile  = (string)($config['bot_pm_profile'] ?? 'default');
?>
<style>
.fish-label  { font-size: 12px; color: #94a3b8; margin-bottom: 3px; }
.fish-form-card .card-body { padding: 18px 20px; }
.config-table td { font-size: 12px; padding: 6px 10px !important; }
.config-table td:first-child { color: #94a3b8; width: 220px; font-family: monospace; }
.config-table td:nth-child(2) { color: #6b7280; width: 80px; font-size: 11px; }
.fish-section-title { font-size: 11px; text-transform: uppercase; color: #64748b; letter-spacing: .05em; margin: 16px 0 8px; }
</style>

<div style="max-width: 920px;">

    <!-- Nav -->
    <div class="d-flex gap-2 mb-4">
        <a href="<?= $fishUrl ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-house"></i> Overview</a>
        <a href="<?= $fishUrl ?>/config" class="btn btn-primary btn-sm"><i class="bi bi-gear"></i> Config</a>
        <a href="<?= $fishUrl ?>/stats" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bar-chart"></i> Stats</a>
        <a href="<?= $fishUrl ?>/runtime" class="btn btn-outline-secondary btn-sm"><i class="bi bi-activity"></i> Runtime</a>
    </div>

    <h5 class="mb-3"><i class="bi bi-gear me-1"></i> Fish — Config</h5>

    <!-- Flash message -->
    <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" style="font-size: 13px;" role="alert">
        <?= htmlspecialchars($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Config validation status -->
    <?php if (!$configValid): ?>
    <div class="alert alert-danger" style="font-size: 13px;">
        <strong>Config validation failed:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($configErrors as $err): ?>
            <li><?= htmlspecialchars((string)$err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php else: ?>
    <div class="alert alert-success" style="font-size: 12px; padding: 8px 12px;">
        <i class="bi bi-check-circle me-1"></i> Config is valid — base + active merged successfully.
    </div>
    <?php endif; ?>

    <!-- ------------------------------------------------------------------ -->
    <!-- Editable Config Form                                                 -->
    <!-- ------------------------------------------------------------------ -->
    <div class="card fish-form-card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-pencil me-1"></i> Edit Config Overrides</span>
            <small class="text-muted">Saves to config/active.php — base.php is never modified.</small>
        </div>
        <div class="card-body">
            <form method="POST" action="<?= htmlspecialchars($ajaxUrl) ?>">
                <input type="hidden" name="action" value="save_config">

                <!-- Module state -->
                <div class="fish-section-title">Module State</div>
                <div class="row g-3 mb-2">
                    <div class="col-auto">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="f_enabled" name="enabled"
                                <?= $fEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label fish-label" for="f_enabled">Enabled</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="fish-label">Mode</div>
                        <select name="mode" class="form-select form-select-sm">
                            <?php foreach (['active', 'passive', 'disabled', 'smoke_demo'] as $m): ?>
                            <option value="<?= $m ?>" <?= $fMode === $m ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <div class="fish-label">Timeframe</div>
                        <input type="text" class="form-control form-control-sm" value="H4" disabled
                               title="Timeframe is fixed at H4 in v1.">
                    </div>
                </div>

                <!-- Universe -->
                <div class="fish-section-title">Symbol Universe</div>
                <div class="row g-3 mb-2">
                    <div class="col-md-3">
                        <div class="fish-label">Universe Mode</div>
                        <select name="universe_mode" id="f_universe_mode" class="form-select form-select-sm"
                                onchange="toggleAllowedSymbols(this.value)">
                            <option value="all"         <?= $fUniverseMode === 'all'         ? 'selected' : '' ?>>all</option>
                            <option value="manual_list" <?= $fUniverseMode === 'manual_list' ? 'selected' : '' ?>>manual_list</option>
                        </select>
                    </div>
                    <div class="col-md-4" id="allowed_symbols_wrap">
                        <div class="fish-label">Allowed Symbols <span class="text-muted">(one per line or comma-sep)</span></div>
                        <textarea name="allowed_symbols" class="form-control form-control-sm"
                                  rows="4" style="font-family: monospace; font-size: 11px;"
                                  placeholder="BTCUSDT&#10;ETHUSDT"><?= htmlspecialchars($fAllowedSymbols) ?></textarea>
                    </div>
                    <div class="col-md-4">
                        <div class="fish-label">Excluded Symbols <span class="text-muted">(always applied)</span></div>
                        <textarea name="excluded_symbols" class="form-control form-control-sm"
                                  rows="4" style="font-family: monospace; font-size: 11px;"
                                  placeholder="LUNAUSDT"><?= htmlspecialchars($fExcludedSymbols) ?></textarea>
                    </div>
                </div>

                <!-- Trading Window -->
                <div class="fish-section-title">Trading Window</div>
                <div class="row g-3 mb-2 align-items-center">
                    <div class="col-auto">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="f_window_enabled" name="window_enabled"
                                <?= $fWindowEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label fish-label" for="f_window_enabled">Window Enabled</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="fish-label">Window Start (UTC HH:MM)</div>
                        <input type="text" name="window_start" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($fWindowStart) ?>" placeholder="08:00" maxlength="5">
                    </div>
                    <div class="col-md-2">
                        <div class="fish-label">Window End (UTC HH:MM)</div>
                        <input type="text" name="window_end" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($fWindowEnd) ?>" placeholder="22:00" maxlength="5">
                    </div>
                </div>

                <!-- Execution -->
                <div class="fish-section-title">Execution Parameters</div>
                <div class="row g-3 mb-2">
                    <div class="col-md-3">
                        <div class="fish-label">Budget</div>
                        <input type="number" name="budget" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($fBudget) ?>" step="0.01" min="0">
                    </div>
                    <div class="col-md-2">
                        <div class="fish-label">Leverage</div>
                        <input type="number" name="leverage" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($fLeverage) ?>" step="1" min="1">
                    </div>
                    <div class="col-md-2">
                        <div class="fish-label">SL Profile</div>
                        <input type="text" name="sl_profile" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($fSlProfile) ?>">
                    </div>
                    <div class="col-md-2">
                        <div class="fish-label">PM Profile</div>
                        <input type="text" name="pm_profile" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($fPmProfile) ?>">
                    </div>
                </div>

                <!-- Bot Execution Runtime -->
                <div class="fish-section-title">Bot Execution Runtime</div>
                <div class="row g-3 mb-2">
                    <div class="col-md-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="f_bot_enabled" name="bot_enabled"
                                   <?= $fBotEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="f_bot_enabled" style="font-size: 13px;">
                                Bot Enabled &nbsp;
                                <small class="text-muted">(enable Fish bot execution; must be combined with execution_mode)</small>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="fish-label">Execution Mode</div>
                        <select name="execution_mode" class="form-select form-select-sm">
                            <?php foreach (['smoke', 'demo', 'live'] as $em): ?>
                            <option value="<?= $em ?>" <?= $fExecMode === $em ? 'selected' : '' ?>>
                                <?= strtoupper($em) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="font-size: 11px; color: #64748b; margin-top: 3px;">
                            smoke = log-only &nbsp;|&nbsp; demo = testnet &nbsp;|&nbsp; live = real orders
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="fish-label">Bot Budget (USDT)</div>
                        <input type="number" name="bot_budget" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($fBotBudget) ?>" step="0.01" min="0">
                    </div>
                    <div class="col-md-2">
                        <div class="fish-label">Bot Leverage</div>
                        <input type="number" name="bot_leverage" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($fBotLeverage) ?>" step="1" min="1">
                    </div>
                    <div class="col-md-2">
                        <div class="fish-label">Bot SL Profile</div>
                        <input type="text" name="bot_sl_profile" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($fBotSlProfile) ?>">
                    </div>
                    <div class="col-md-2">
                        <div class="fish-label">Bot PM Profile</div>
                        <input type="text" name="bot_pm_profile" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($fBotPmProfile) ?>">
                    </div>
                </div>

                <!-- Actions -->
                <div class="d-flex gap-2 mt-3 pt-2" style="border-top: 1px solid #334155;">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-floppy me-1"></i> Save Config
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ------------------------------------------------------------------ -->
    <!-- Run Smoke Test                                                        -->
    <!-- ------------------------------------------------------------------ -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-play-circle me-1"></i> Smoke Test</div>
        <div class="card-body" style="font-size: 13px;">
            <?php $curMode = $config['universe_mode'] ?? 'all'; ?>
            <p class="mb-2 text-muted" style="font-size: 12px;">
                <?php if ($curMode === 'all'): ?>
                    Universe mode is <strong>all</strong> — clicking Run will <strong>queue</strong> a batched
                    run. The centralized cron will advance it automatically every minute.
                <?php else: ?>
                    Universe mode is <strong><?= htmlspecialchars($curMode) ?></strong> — clicking Run will
                    execute synchronously (small list).
                <?php endif; ?>
                Does <strong>not</strong> place any orders.
            </p>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <form method="POST" action="<?= htmlspecialchars($ajaxUrl) ?>">
                    <input type="hidden" name="action" value="run">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-lightning-charge me-1"></i>
                        <?= ($curMode === 'all') ? 'Queue Smoke Test' : 'Run Smoke Test' ?>
                    </button>
                </form>
                <a href="<?= htmlspecialchars($statsUrl) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-bar-chart me-1"></i> View Stats
                </a>
                <a href="<?= htmlspecialchars(System::web('admin/strategy/fish/runtime')) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-activity me-1"></i> View Runtime
                </a>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------------------------ -->
    <!-- Run State Progress                                                    -->
    <!-- ------------------------------------------------------------------ -->
    <?php $rs = $runState['run_status'] ?? 'idle'; ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-speedometer2 me-1"></i> Smoke Test Run State</span>
            <small class="text-muted">storage/run_state.json</small>
        </div>
        <div class="card-body" style="font-size: 13px;">
            <?php if ($rs === 'idle' || empty($runState)): ?>
                <span class="text-muted" style="font-size: 12px;">No run recorded. Queue a smoke test to begin.</span>
            <?php else: ?>
                <div class="mb-2 d-flex align-items-center gap-2">
                    <span class="badge <?= match($rs) {
                        'running' => 'bg-warning text-dark',
                        'done'    => 'bg-success',
                        'failed'  => 'bg-danger',
                        'queued'  => 'bg-info text-dark',
                        default   => 'bg-secondary'
                    } ?>"><?= htmlspecialchars(strtoupper($rs)) ?></span>
                    <code style="font-size: 11px; color: #94a3b8;"><?= htmlspecialchars($runState['run_id'] ?? '—') ?></code>
                </div>

                <?php if (in_array($rs, ['running', 'done'])): ?>
                <?php
                    $rsTotalSym = (int)($runState['total_symbols']     ?? 0);
                    $rsDoneSym  = (int)($runState['processed_symbols'] ?? 0);
                    $rsPct      = $rsTotalSym > 0 ? min(100, (int)round($rsDoneSym / $rsTotalSym * 100)) : 0;
                ?>
                <div class="progress mb-1" style="height: 7px;">
                    <div class="progress-bar <?= $rs === 'done' ? 'bg-success' : 'bg-warning' ?>"
                         style="width: <?= $rsPct ?>%;"></div>
                </div>
                <div style="font-size: 11px; color: #94a3b8;" class="mb-2">
                    <?= $rsDoneSym ?> / <?= $rsTotalSym ?> symbols
                    &nbsp;·&nbsp; <?= (int)($runState['remaining_symbols'] ?? 0) ?> remaining
                    &nbsp;·&nbsp; <?= $rsPct ?>%
                </div>
                <?php endif; ?>

                <table class="table table-sm table-dark mb-2" style="font-size: 11px;">
                    <tbody>
                    <?php
                    $rsFields = [
                        'universe_mode'   => 'Universe Mode',
                        'batch_size'      => 'Batch Size',
                        'batches_completed' => 'Batches Completed',
                        'current_symbol'  => 'Current Symbol',
                        'signals_found'   => 'Signals Found',
                        'signals_geometry_valid'    => 'Geometry Valid',
                        'signals_geometry_rejected' => 'Geometry Rejected',
                        'signals_rr_below_min'      => 'RR Below Min',
                        'api_errors'      => 'API Errors',
                        'last_tick_at'    => 'Last Cron Tick',
                        'last_tick_result' => 'Last Tick Result',
                        'started_at'      => 'Started At',
                        'updated_at'      => 'Updated At',
                        'finished_at'     => 'Finished At',
                        'last_error'      => 'Last Error',
                    ];
                    foreach ($rsFields as $rsKey => $rsLabel):
                        $rsVal = $runState[$rsKey] ?? null;
                        if ($rsVal === null || $rsVal === '') continue;
                    ?>
                    <tr>
                        <td style="color: #94a3b8; width: 160px;"><?= htmlspecialchars($rsLabel) ?></td>
                        <td><?= $rsKey === 'last_error'
                            ? '<span class="text-danger">' . htmlspecialchars((string)$rsVal) . '</span>'
                            : '<code>' . htmlspecialchars((string)$rsVal) . '</code>'
                        ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (in_array($rs, ['queued', 'running'])): ?>
                <p class="text-muted mb-0" style="font-size: 11px;">
                    <i class="bi bi-clock me-1"></i>
                    Cron advances this run automatically every 60 s
                    (next batch: <?= htmlspecialchars((string)($runState['batch_size'] ?? 20)) ?> symbols).
                </p>
                <?php endif; ?>

                <?php if ($rs === 'done'): ?>
                <a href="<?= htmlspecialchars($statsUrl) ?>" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-bar-chart me-1"></i> View Results
                </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ------------------------------------------------------------------ -->
    <!-- Reset active overrides                                               -->
    <!-- ------------------------------------------------------------------ -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-arrow-counterclockwise me-1"></i> Reset Active Overrides</div>
        <div class="card-body" style="font-size: 13px;">
            <p class="mb-2 text-muted" style="font-size: 12px;">
                Clears <code>config/active.php</code> back to an empty array. Base defaults will be in effect.
            </p>
            <form method="POST" action="<?= htmlspecialchars($ajaxUrl) ?>">
                <input type="hidden" name="action" value="reset_active">
                <button type="submit" class="btn btn-outline-warning btn-sm"
                        onclick="return confirm('Clear all active overrides and revert to base defaults?')">
                    <i class="bi bi-x-circle me-1"></i> Reset to Base Defaults
                </button>
            </form>
        </div>
    </div>

    <!-- ------------------------------------------------------------------ -->
    <!-- Effective Config (read-only reference)                               -->
    <!-- ------------------------------------------------------------------ -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-list-check me-1"></i> Effective Config (base.php + active.php merged)
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-dark config-table mb-0">
                <thead>
                    <tr>
                        <th style="font-size: 11px;">Key</th>
                        <th style="font-size: 11px;">Type</th>
                        <th style="font-size: 11px;">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schema as $key => $expectedType): ?>
                    <tr>
                        <td><?= htmlspecialchars($key) ?></td>
                        <td><span class="badge bg-secondary" style="font-size: 10px;"><?= htmlspecialchars($expectedType) ?></span></td>
                        <td>
                            <?php
                            if (!array_key_exists($key, $config)) {
                                echo '<span class="text-danger">MISSING</span>';
                            } elseif (is_array($config[$key])) {
                                echo '<code style="font-size: 11px;">' . htmlspecialchars(json_encode($config[$key], JSON_UNESCAPED_UNICODE)) . '</code>';
                            } elseif (is_bool($config[$key])) {
                                $v = $config[$key];
                                echo '<span class="badge" style="background: ' . ($v ? '#22c55e' : '#6b7280') . ';">' . ($v ? 'true' : 'false') . '</span>';
                            } else {
                                echo '<code style="font-size: 11px;">' . htmlspecialchars((string)$config[$key]) . '</code>';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
function toggleAllowedSymbols(mode) {
    var wrap = document.getElementById('allowed_symbols_wrap');
    if (wrap) {
        wrap.style.opacity = (mode === 'manual_list') ? '1' : '0.4';
    }
}
// Init on page load
document.addEventListener('DOMContentLoaded', function () {
    var sel = document.getElementById('f_universe_mode');
    if (sel) { toggleAllowedSymbols(sel.value); }
});
</script>
