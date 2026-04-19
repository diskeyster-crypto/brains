<?php

declare(strict_types=1);

/**
 * Fish Strategy — Admin Index Page
 *
 * Shows a summary of the module: current config, last run, storage counts.
 * Loaded via /admin/strategy/fish by the strategy route in public/index.php.
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

use Modules\Strategy\Fish\FishService;

$service  = FishService::instance($moduleDir);
$lastRun  = $service->getLastRun();
$config   = $service->getConfig();
$snapshot = $service->getRuntimeSnapshot();
$stats    = $service->getStats();

$signals   = $service->loadStorage('signals.json');
$orders    = $service->loadStorage('active_orders.json');
$positions = $service->loadStorage('active_positions.json');

$strategyId = $config['strategy_id'] ?? 'fish';
$mode       = $config['mode']        ?? 'passive';
$enabled    = $config['enabled']     ?? false;

$statusLabel = $enabled
    ? ($mode === 'active' ? 'active' : 'passive')
    : 'disabled';

$statusColor = match ($statusLabel) {
    'active'  => '#22c55e',
    'passive' => '#f59e0b',
    default   => '#6b7280',
};

$lastRunTime  = $lastRun['run_at']     ?? null;
$lastRunMs    = $lastRun['duration_ms'] ?? null;
$lastStatus   = $lastRun['status']     ?? '—';
$errorsCount  = $stats['errors_count'] ?? ($lastRun['errors_count'] ?? 0);

$fishUrl = rtrim(System::web('admin/strategy/fish'), '/');
?>
<style>
.strategy-page { max-width: 860px; }
.fish-card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; margin: 16px 0; }
.fish-stat-box { background: var(--card-bg, #1e293b); border: 1px solid var(--border-color, #334155); border-radius: 8px; padding: 16px; text-align: center; }
.fish-stat-value { font-size: 28px; font-weight: 700; color: #e2e8f0; }
.fish-stat-label { font-size: 11px; color: #94a3b8; text-transform: uppercase; margin-top: 4px; }
.fish-nav { display: flex; gap: 8px; margin-bottom: 20px; }
.fish-nav .btn { font-size: 13px; }
.status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 6px; }
</style>

<div class="strategy-page">
    <!-- Page Header -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="mb-0">
                <span class="status-dot" style="background: <?= $statusColor ?>;"></span>
                Fish Strategy
                <span class="badge ms-2" style="background: <?= $statusColor ?>; font-size: 11px;"><?= htmlspecialchars(strtoupper($statusLabel)) ?></span>
            </h4>
            <div style="font-size: 12px; color: #64748b;">
                strategy_id: <code><?= htmlspecialchars($strategyId) ?></code>
                &nbsp;|&nbsp; mode: <code><?= htmlspecialchars($mode) ?></code>
                &nbsp;|&nbsp; timeframe: <code><?= htmlspecialchars($config['timeframe'] ?? '—') ?></code>
                &nbsp;|&nbsp; market: <code><?= htmlspecialchars($config['market_type'] ?? '—') ?></code>
            </div>
        </div>
        <a href="<?= System::web('admin/brain') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Strategy Panel
        </a>
    </div>

    <!-- Sub-page Nav -->
    <div class="fish-nav">
        <a href="<?= $fishUrl ?>" class="btn btn-primary btn-sm"><i class="bi bi-house"></i> Overview</a>
        <a href="<?= $fishUrl ?>/config" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear"></i> Config</a>
        <a href="<?= $fishUrl ?>/stats" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bar-chart"></i> Stats</a>
        <a href="<?= $fishUrl ?>/runtime" class="btn btn-outline-secondary btn-sm"><i class="bi bi-activity"></i> Runtime</a>
    </div>

    <!-- Key Stats Grid -->
    <div class="fish-card-grid">
        <div class="fish-stat-box">
            <div class="fish-stat-value"><?= count($signals) ?></div>
            <div class="fish-stat-label">Signals Found</div>
        </div>
        <div class="fish-stat-box">
            <div class="fish-stat-value"><?= count($orders) ?></div>
            <div class="fish-stat-label">Active Orders</div>
        </div>
        <div class="fish-stat-box">
            <div class="fish-stat-value"><?= count($positions) ?></div>
            <div class="fish-stat-label">Active Positions</div>
        </div>
        <div class="fish-stat-box">
            <div class="fish-stat-value" style="color: <?= $errorsCount > 0 ? '#ef4444' : '#22c55e' ?>;">
                <?= (int)$errorsCount ?>
            </div>
            <div class="fish-stat-label">Errors</div>
        </div>
    </div>

    <!-- Last Run Info -->
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="bi bi-clock-history me-1"></i> Last Run</span>
            <form method="post" action="<?= $fishUrl ?>/ajax" style="margin: 0;">
                <input type="hidden" name="action" value="run">
                <button type="submit" class="btn btn-success btn-sm">
                    <i class="bi bi-play-fill"></i> Run Now
                </button>
            </form>
        </div>
        <div class="card-body" style="font-size: 13px;">
            <?php if (empty($lastRun)): ?>
                <span class="text-muted">No run recorded yet.</span>
            <?php else: ?>
                <div class="row g-2">
                    <div class="col-sm-3"><strong>Status:</strong>
                        <span class="badge" style="background: <?= $lastStatus === 'ok' ? '#22c55e' : '#ef4444' ?>;">
                            <?= htmlspecialchars($lastStatus) ?>
                        </span>
                    </div>
                    <div class="col-sm-4"><strong>Run at:</strong> <?= htmlspecialchars($lastRunTime ?? '—') ?></div>
                    <div class="col-sm-2"><strong>Duration:</strong> <?= $lastRunMs !== null ? $lastRunMs . ' ms' : '—' ?></div>
                    <div class="col-sm-3"><strong>Message:</strong> <span class="text-muted"><?= htmlspecialchars($lastRun['message'] ?? '—') ?></span></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Config Summary -->
    <div class="card">
        <div class="card-header"><i class="bi bi-sliders me-1"></i> Active Config Summary</div>
        <div class="card-body">
            <table class="table table-sm table-dark" style="font-size: 12px; margin: 0;">
                <tbody>
                    <?php foreach ($config as $k => $v): ?>
                    <tr>
                        <td style="color: #94a3b8; width: 200px;"><?= htmlspecialchars($k) ?></td>
                        <td>
                            <?php if (is_array($v)): ?>
                                <code><?= htmlspecialchars(json_encode($v)) ?></code>
                            <?php elseif (is_bool($v)): ?>
                                <span class="badge" style="background: <?= $v ? '#22c55e' : '#6b7280' ?>;"><?= $v ? 'true' : 'false' ?></span>
                            <?php else: ?>
                                <code><?= htmlspecialchars((string)$v) ?></code>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($config)): ?>
                    <tr><td colspan="2" class="text-muted">Config not loaded.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
