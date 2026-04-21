<?php

declare(strict_types=1);

/**
 * Pattern Strategy — Admin Index Page
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

$service  = PatternService::instance($moduleDir);
$lastRun  = $service->getLastRun();
$config   = $service->getConfig();
$stats    = $service->getStats();
$signals  = $service->getSignals();
$regime   = $service->getMarketRegime();

$strategyId  = $config['strategy_id'] ?? 'pattern';
$mode        = $config['mode']         ?? 'passive';
$enabled     = $config['enabled']      ?? false;

$statusLabel = $enabled
    ? ($mode === 'active' ? 'active' : 'passive')
    : 'disabled';

$statusColor = match ($statusLabel) {
    'active'  => '#22c55e',
    'passive' => '#f59e0b',
    default   => '#6b7280',
};

$lastRunTime = $lastRun['run_at']       ?? null;
$lastStatus  = $lastRun['status']       ?? '—';
$errCount    = $lastRun['errors_count'] ?? 0;

$patternUrl = rtrim(System::web('admin/strategy/pattern'), '/');
?>
<style>
.pattern-page { max-width: 880px; }
.pt-card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px,1fr)); gap: 12px; margin: 16px 0; }
.pt-stat-box  { background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 16px; text-align: center; }
.pt-stat-val  { font-size: 28px; font-weight: 700; color: #e2e8f0; }
.pt-stat-lbl  { font-size: 11px; color: #94a3b8; text-transform: uppercase; margin-top: 4px; }
.pt-nav       { display: flex; gap: 8px; margin-bottom: 20px; }
.status-dot   { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 6px; }
</style>

<div class="pattern-page">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="mb-0">
                <span class="status-dot" style="background: <?= $statusColor ?>;"></span>
                Pattern Strategy
                <span class="badge ms-2" style="background: <?= $statusColor ?>; font-size: 11px;"><?= htmlspecialchars(strtoupper($statusLabel)) ?></span>
            </h4>
            <div style="font-size: 12px; color: #64748b;">
                strategy_id: <code><?= htmlspecialchars($strategyId) ?></code>
                &nbsp;|&nbsp; mode: <code><?= htmlspecialchars($mode) ?></code>
                &nbsp;|&nbsp; timeframe: <code><?= htmlspecialchars($config['timeframe'] ?? 'H4') ?></code>
                &nbsp;|&nbsp; side_mode: <code><?= htmlspecialchars($config['side_mode'] ?? 'both') ?></code>
            </div>
        </div>
        <div class="pt-nav">
            <a href="<?= $patternUrl ?>/config" class="btn btn-sm btn-outline-secondary">Config</a>
            <a href="<?= $patternUrl ?>/stats"  class="btn btn-sm btn-outline-secondary">Stats</a>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="pt-card-grid">
        <div class="pt-stat-box">
            <div class="pt-stat-val"><?= count($signals) ?></div>
            <div class="pt-stat-lbl">Active Signals</div>
        </div>
        <div class="pt-stat-box">
            <div class="pt-stat-val"><?= (int)($stats['final_signals_total'] ?? 0) ?></div>
            <div class="pt-stat-lbl">Signals (total)</div>
        </div>
        <div class="pt-stat-box">
            <div class="pt-stat-val"><?= (int)($stats['double_bottom_found_total'] ?? 0) ?></div>
            <div class="pt-stat-lbl">Double Bottoms</div>
        </div>
        <div class="pt-stat-box">
            <div class="pt-stat-val"><?= (int)($stats['double_top_found_total'] ?? 0) ?></div>
            <div class="pt-stat-lbl">Double Tops</div>
        </div>
        <div class="pt-stat-box">
            <div class="pt-stat-val"><?= htmlspecialchars($regime['regime'] ?? '—') ?></div>
            <div class="pt-stat-lbl">Market Regime</div>
        </div>
        <div class="pt-stat-box">
            <div class="pt-stat-val" style="color: <?= $errCount > 0 ? '#ef4444' : '#22c55e' ?>;"><?= $errCount ?></div>
            <div class="pt-stat-lbl">Last Run Errors</div>
        </div>
    </div>

    <!-- Last run info -->
    <div style="background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 16px; margin-bottom: 16px; font-size: 13px;">
        <strong>Last Run</strong>
        <span class="ms-3">Status: <code><?= htmlspecialchars($lastStatus) ?></code></span>
        <?php if ($lastRunTime): ?>
        <span class="ms-3">At: <code><?= htmlspecialchars($lastRunTime) ?></code></span>
        <?php endif; ?>
        <span class="ms-3">Processed: <code><?= htmlspecialchars((string)($lastRun['processed'] ?? '—')) ?></code></span>
        <span class="ms-3">Found: <code><?= htmlspecialchars((string)($lastRun['found'] ?? '—')) ?></code></span>
    </div>

    <!-- Actions -->
    <form method="post" action="<?= $patternUrl ?>/ajax" class="d-inline-block me-2">
        <input type="hidden" name="action" value="queue_run">
        <button class="btn btn-sm btn-primary" type="submit">Queue Run</button>
    </form>
    <form method="post" action="<?= $patternUrl ?>/ajax" class="d-inline-block me-2">
        <input type="hidden" name="action" value="tick_batch">
        <button class="btn btn-sm btn-outline-secondary" type="submit">Tick Batch</button>
    </form>
</div>
