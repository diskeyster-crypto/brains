<?php

declare(strict_types=1);

/**
 * Fish Strategy — Admin Runtime Page
 *
 * Displays the live runtime snapshot, current cycle status, signal TTL diagnostics,
 * and last run info.
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

$service   = FishService::instance($moduleDir);
$snapshot  = $service->getRuntimeSnapshot();
$lastRun   = $service->getLastRun();
$config    = $service->getConfig();
$runState  = $service->getRunState();

$fishUrl = rtrim(System::web('admin/strategy/fish'), '/');
?>
<div style="max-width: 860px;">
    <!-- Sub-page Nav -->
    <div class="d-flex gap-2 mb-4">
        <a href="<?= $fishUrl ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-house"></i> Overview</a>
        <a href="<?= $fishUrl ?>/config" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear"></i> Config</a>
        <a href="<?= $fishUrl ?>/stats" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bar-chart"></i> Stats</a>
        <a href="<?= $fishUrl ?>/runtime" class="btn btn-primary btn-sm"><i class="bi bi-activity"></i> Runtime</a>
    </div>

    <h5 class="mb-3"><i class="bi bi-activity me-1"></i> Fish — Runtime</h5>

    <!-- Cycle Status -->
    <?php
    $cycleId     = $runState['cycle_id']          ?? ($runState['run_id']      ?? null);
    $cycleStatus = $runState['cycle_status']       ?? ($runState['run_status']  ?? 'idle');
    $cycleStart  = $runState['cycle_started_at']   ?? ($runState['started_at']  ?? null);
    $cycleFinish = $runState['cycle_finished_at']  ?? ($runState['finished_at'] ?? null);
    $prevCycleId = $runState['prev_cycle_id']      ?? null;
    $totalSym    = $runState['total_symbols']      ?? 0;
    $processedSym= $runState['processed_symbols']  ?? 0;
    $remainSym   = $runState['remaining_symbols']  ?? 0;
    $batchesDone = $runState['batches_completed']  ?? 0;
    $cycleStatusColor = match($cycleStatus) {
        'running' => '#22c55e',
        'queued'  => '#f59e0b',
        'done'    => '#3b82f6',
        'failed'  => '#ef4444',
        default   => '#6b7280',
    };
    // Diagnostics from last_run diagnostics block
    $lastDiag    = $lastRun['diagnostics'] ?? [];
    $ttlMinutes  = $lastDiag['signal_ttl_minutes']          ?? ($config['signal_ttl_minutes'] ?? 180);
    $sigsActive  = $lastDiag['signals_active']              ?? null;
    $sigsExpired = $lastDiag['signals_expired_this_tick']   ?? null;
    $sigsRefresh = $lastDiag['signals_refreshed_this_tick'] ?? null;
    $nextReady   = $lastDiag['next_cycle_ready']            ?? null;
    ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-arrow-repeat me-1"></i> Scan Cycle Status</span>
            <span class="badge" style="background: <?= $cycleStatusColor ?>;"><?= htmlspecialchars(strtoupper($cycleStatus)) ?></span>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-dark mb-0" style="font-size: 12px;">
                <tbody>
                    <tr><td style="color:#94a3b8;width:220px;">current_cycle_id</td><td><code><?= htmlspecialchars($cycleId ?? '—') ?></code></td></tr>
                    <tr><td style="color:#94a3b8;">cycle_status</td><td><span class="badge" style="background:<?= $cycleStatusColor ?>;"><?= htmlspecialchars($cycleStatus) ?></span></td></tr>
                    <tr><td style="color:#94a3b8;">cycle_started_at</td><td><code><?= htmlspecialchars($cycleStart ?? '—') ?></code></td></tr>
                    <tr><td style="color:#94a3b8;">cycle_finished_at</td><td><code><?= htmlspecialchars($cycleFinish ?? '—') ?></code></td></tr>
                    <tr><td style="color:#94a3b8;">prev_cycle_id</td><td><code><?= htmlspecialchars($prevCycleId ?? '—') ?></code></td></tr>
                    <tr><td style="color:#94a3b8;">total_symbols</td><td><code><?= (int)$totalSym ?></code></td></tr>
                    <tr><td style="color:#94a3b8;">processed_symbols</td><td><code><?= (int)$processedSym ?></code></td></tr>
                    <tr><td style="color:#94a3b8;">remaining_symbols</td><td><code><?= (int)$remainSym ?></code></td></tr>
                    <tr><td style="color:#94a3b8;">batches_completed</td><td><code><?= (int)$batchesDone ?></code></td></tr>
                    <tr><td style="color:#94a3b8;">next_cycle_ready</td><td>
                        <?php if ($nextReady !== null): ?>
                            <span class="badge" style="background:<?= $nextReady ? '#22c55e' : '#6b7280' ?>;"><?= $nextReady ? 'true' : 'false' ?></span>
                        <?php else: ?><code>—</code><?php endif; ?>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Signal TTL Diagnostics (from last finalized cycle) -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-hourglass-split me-1"></i> Signal TTL Diagnostics</div>
        <div class="card-body p-0">
            <table class="table table-sm table-dark mb-0" style="font-size: 12px;">
                <tbody>
                    <tr><td style="color:#94a3b8;width:220px;">signal_ttl_minutes</td><td><code><?= (int)$ttlMinutes ?></code></td></tr>
                    <tr><td style="color:#94a3b8;">signals_active</td><td><code><?= $sigsActive !== null ? (int)$sigsActive : '—' ?></code></td></tr>
                    <tr><td style="color:#94a3b8;">signals_expired_last_cycle</td><td><code><?= $sigsExpired !== null ? (int)$sigsExpired : '—' ?></code></td></tr>
                    <tr><td style="color:#94a3b8;">signals_refreshed_last_cycle</td><td><code><?= $sigsRefresh !== null ? (int)$sigsRefresh : '—' ?></code></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Runtime Snapshot -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-cpu me-1"></i> Runtime Snapshot</span>
            <small class="text-muted">config/runtime_snapshot.php</small>
        </div>
        <div class="card-body">
            <?php if (empty($snapshot)): ?>
                <span class="text-muted" style="font-size: 13px;">Snapshot not generated yet. Run the service first.</span>
            <?php else: ?>
                <table class="table table-sm table-dark mb-0" style="font-size: 12px;">
                    <tbody>
                        <?php foreach ($snapshot as $k => $v):
                            if ($k === 'effective_config') continue;
                        ?>
                        <tr>
                            <td style="color: #94a3b8; width: 200px;"><?= htmlspecialchars($k) ?></td>
                            <td>
                                <?php if (is_array($v)): ?>
                                    <code><?= htmlspecialchars(json_encode($v)) ?></code>
                                <?php elseif (is_bool($v)): ?>
                                    <span class="badge" style="background: <?= $v ? '#22c55e' : '#6b7280' ?>;"><?= $v ? 'true' : 'false' ?></span>
                                <?php else: ?>
                                    <code><?= htmlspecialchars((string)($v ?? '—')) ?></code>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Feature Flags -->
    <?php $flags = $config['feature_flags'] ?? []; ?>
    <?php if (!empty($flags)): ?>
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-toggles me-1"></i> Feature Flags</div>
        <div class="card-body p-0">
            <table class="table table-sm table-dark mb-0" style="font-size: 12px;">
                <thead><tr><th>Flag</th><th>Value</th></tr></thead>
                <tbody>
                    <?php foreach ($flags as $flag => $val): ?>
                    <tr>
                        <td style="font-family: monospace;"><?= htmlspecialchars($flag) ?></td>
                        <td>
                            <span class="badge" style="background: <?= $val ? '#22c55e' : '#6b7280' ?>;">
                                <?= $val ? 'true' : 'false' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Last Run JSON -->
    <div class="card">
        <div class="card-header"><i class="bi bi-clock me-1"></i> Last Run JSON</div>
        <div class="card-body">
            <?php if (empty($lastRun)): ?>
                <span class="text-muted" style="font-size: 13px;">No run recorded yet.</span>
            <?php else: ?>
                <pre style="font-size: 11px; color: #94a3b8; margin: 0; max-height: 300px; overflow: auto;"><?= htmlspecialchars(json_encode($lastRun, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php endif; ?>
        </div>
    </div>
</div>
