<?php
/**
 * Brain — Strategy Control Panel
 *
 * Replaced the old Brain meta-orchestrator dashboard.
 * Brain is now a Strategy Control Panel: it discovers and displays
 * all registered strategy modules from modules/strategy/*.
 *
 * Variables provided by BrainController::index():
 *   $strategyModules  — array of strategy cards (from discoverStrategyModules())
 *   $flash            — optional flash message
 */

use Core\System\System;

$brainUrl = rtrim(System::web('admin/brain'), '/');
$stratUrl = rtrim(System::web('admin/strategy'), '/');

$totalModules  = count($strategyModules ?? []);
$activeCount   = count(array_filter($strategyModules ?? [], fn($m) => $m['status'] === 'active'));
$passiveCount  = count(array_filter($strategyModules ?? [], fn($m) => $m['status'] === 'passive'));
$disabledCount = count(array_filter($strategyModules ?? [], fn($m) => $m['status'] === 'disabled'));
$errorCount    = count(array_filter($strategyModules ?? [], fn($m) => ($m['errors_count'] ?? 0) > 0));
?>
<style>
/* Strategy Control Panel card layout */
.scp-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 14px;
    margin-top: 16px;
}
.strategy-card {
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: 10px;
    padding: 16px;
    transition: box-shadow 0.2s;
}
.strategy-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.3); }
.strategy-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid #334155;
}
.strategy-title { font-weight: 700; font-size: 15px; color: #e2e8f0; }
.strategy-desc  { font-size: 11px; color: #64748b; margin-top: 3px; }
.strategy-meta  { font-size: 11px; color: #94a3b8; margin-top: 4px; }
.strategy-meta code { color: #7dd3fc; font-size: 11px; }

.scp-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 6px;
    margin: 10px 0;
}
.scp-stat-box {
    background: #0f172a;
    border: 1px solid #1e293b;
    border-radius: 6px;
    padding: 8px 6px;
    text-align: center;
}
.scp-stat-val   { font-size: 18px; font-weight: 700; color: #e2e8f0; }
.scp-stat-label { font-size: 9px; color: #64748b; text-transform: uppercase; margin-top: 2px; }

.strategy-timing {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    color: #64748b;
    padding: 8px 0;
    border-top: 1px solid #334155;
    border-bottom: 1px solid #334155;
    margin: 8px 0;
}
.strategy-actions { display: flex; gap: 6px; margin-top: 10px; }
.strategy-actions .btn { flex: 1; font-size: 11px; padding: 5px 8px; }

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 999px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.status-badge-active   { background: rgba(34,197,94,.15); color: #22c55e; border: 1px solid rgba(34,197,94,.3); }
.status-badge-passive  { background: rgba(245,158,11,.15); color: #f59e0b; border: 1px solid rgba(245,158,11,.3); }
.status-badge-disabled { background: rgba(107,114,128,.15); color: #6b7280; border: 1px solid rgba(107,114,128,.3); }

.summary-strip {
    display: flex;
    gap: 20px;
    align-items: center;
    padding: 12px 16px;
    background: #1e293b;
    border: 1px solid #334155;
    border-radius: 8px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.summary-strip-item { display: flex; align-items: center; gap: 6px; font-size: 13px; }
.summary-strip-item .num { font-size: 20px; font-weight: 700; color: #e2e8f0; }
.summary-strip-item.active-item .num { color: #22c55e; }
.summary-strip-item.error-item  .num { color: #ef4444; }
</style>

<?php if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" style="font-size: 13px;">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Page header -->
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h4 class="mb-0"><i class="bi bi-grid-3x2-gap me-2"></i>Strategy Control Panel</h4>
        <div style="font-size: 12px; color: #64748b;">Brain · strategy module registry and health overview</div>
    </div>
</div>

<!-- Summary strip -->
<div class="summary-strip">
    <div class="summary-strip-item">
        <span class="num"><?= $totalModules ?></span>
        <span>Total</span>
    </div>
    <div class="summary-strip-item active-item">
        <i class="bi bi-check-circle-fill" style="color:#22c55e;"></i>
        <span class="num"><?= $activeCount ?></span>
        <span>Active</span>
    </div>
    <div class="summary-strip-item">
        <i class="bi bi-pause-circle" style="color:#f59e0b;"></i>
        <span class="num"><?= $passiveCount ?></span>
        <span>Passive</span>
    </div>
    <div class="summary-strip-item">
        <i class="bi bi-dash-circle" style="color:#6b7280;"></i>
        <span class="num"><?= $disabledCount ?></span>
        <span>Disabled</span>
    </div>
    <?php if ($errorCount > 0): ?>
    <div class="summary-strip-item error-item">
        <i class="bi bi-exclamation-triangle-fill" style="color:#ef4444;"></i>
        <span class="num"><?= $errorCount ?></span>
        <span>With errors</span>
    </div>
    <?php endif; ?>
</div>

<!-- Strategy Cards -->
<?php if (empty($strategyModules)): ?>
<div class="alert alert-warning" style="font-size: 13px;">
    No strategy modules found. Create a module in <code>modules/strategy/</code> with a <code>manifest.json</code> file.
</div>
<?php else: ?>
<div class="scp-grid">
    <?php foreach ($strategyModules as $mod):
        $statusClass = match ($mod['status']) {
            'active'  => 'status-badge-active',
            'passive' => 'status-badge-passive',
            default   => 'status-badge-disabled',
        };
        $statusIcon = match ($mod['status']) {
            'active'  => 'bi-check-circle-fill',
            'passive' => 'bi-pause-circle',
            default   => 'bi-dash-circle',
        };
        $modUrl = rtrim(System::web('admin/strategy/' . $mod['name']), '/');
        $errColor = $mod['errors_count'] > 0 ? '#ef4444' : '#22c55e';
    ?>
    <div class="strategy-card">
        <!-- Card Header -->
        <div class="strategy-card-header">
            <div>
                <div class="strategy-title">
                    <i class="bi bi-layers me-1" style="color: #7dd3fc;"></i>
                    <?= htmlspecialchars($mod['title']) ?>
                </div>
                <?php if ($mod['description']): ?>
                <div class="strategy-desc"><?= htmlspecialchars($mod['description']) ?></div>
                <?php endif; ?>
                <div class="strategy-meta">
                    id: <code><?= htmlspecialchars($mod['strategy_id']) ?></code>
                    &nbsp;&middot;&nbsp;
                    mode: <code><?= htmlspecialchars($mod['mode']) ?></code>
                    <?php if ($mod['version']): ?>
                    &nbsp;&middot;&nbsp; v<?= htmlspecialchars($mod['version']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <span class="status-badge <?= $statusClass ?>">
                    <i class="bi <?= $statusIcon ?>"></i>
                    <?= htmlspecialchars($mod['status']) ?>
                </span>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="scp-stats">
            <div class="scp-stat-box">
                <div class="scp-stat-val"><?= (int)$mod['signals_found'] ?></div>
                <div class="scp-stat-label">Signals</div>
            </div>
            <div class="scp-stat-box">
                <div class="scp-stat-val"><?= (int)$mod['active_orders'] ?></div>
                <div class="scp-stat-label">Orders</div>
            </div>
            <div class="scp-stat-box">
                <div class="scp-stat-val"><?= (int)$mod['active_positions'] ?></div>
                <div class="scp-stat-label">Positions</div>
            </div>
            <div class="scp-stat-box">
                <div class="scp-stat-val" style="color: <?= $errColor ?>;">
                    <?= (int)$mod['errors_count'] ?>
                </div>
                <div class="scp-stat-label">Errors</div>
            </div>
        </div>

        <!-- Timing -->
        <div class="strategy-timing">
            <span>
                <i class="bi bi-clock"></i>
                <?= $mod['last_run'] ? date('H:i d.m', strtotime($mod['last_run'])) : '—' ?>
            </span>
            <span>
                Status:
                <span style="color: <?= $mod['last_status'] === 'ok' ? '#22c55e' : ($mod['last_status'] === '—' ? '#64748b' : '#f59e0b') ?>;">
                    <?= htmlspecialchars($mod['last_status']) ?>
                </span>
            </span>
            <span>
                Config:
                <span style="color: <?= $mod['config_valid'] ? '#22c55e' : '#6b7280' ?>;">
                    <?= $mod['config_valid'] ? 'valid' : 'not run' ?>
                </span>
            </span>
        </div>

        <!-- Action Buttons -->
        <div class="strategy-actions">
            <a href="<?= $modUrl ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-box-arrow-up-right"></i> Open
            </a>
            <a href="<?= $modUrl ?>/config" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-gear"></i> Config
            </a>
            <a href="<?= $modUrl ?>/stats" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-bar-chart"></i> Stats
            </a>
            <a href="<?= $modUrl ?>/runtime" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-activity"></i> Runtime
            </a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
