<?php

declare(strict_types=1);

/**
 * Fish Strategy — Admin Stats Page
 *
 * Displays cumulative stats, last-run diagnostics, and the current signal list.
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
$stats     = $service->getStats();
$lastRun   = $service->getLastRun();
$signals   = $service->loadStorage('signals.json');
$orders    = $service->loadStorage('active_orders.json');
$positions = $service->loadStorage('active_positions.json');

$diag = $lastRun['diagnostics'] ?? [];

// Bot runtime data
$config          = $service->getConfig();
$botEnabled      = (bool)($config['bot_enabled']    ?? false);
$botMode         = (string)($config['execution_mode'] ?? 'smoke');
$botLastRun      = $service->getBotLastRun();
$botStats        = $service->getBotStats();
$botQueue        = $service->getBotExecutionQueue();
$botOrders       = $service->getBotActiveOrders();
$botPositions    = $service->getBotActivePositions();

$fishUrl = rtrim(System::web('admin/strategy/fish'), '/');
?>
<style>
.fish-stat-card { background: var(--card-bg, #1e293b); border: 1px solid var(--border-color, #334155); border-radius: 8px; padding: 14px; text-align: center; }
.fish-stat-v { font-size: 24px; font-weight: 700; color: #e2e8f0; }
.fish-stat-l { font-size: 10px; color: #94a3b8; text-transform: uppercase; margin-top: 3px; }
.fish-stat-err { color: #ef4444; }
.fish-diag-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-bottom: 16px; }
.fish-signal-row td { font-size: 11px; padding: 5px 8px !important; }
</style>

<div style="max-width: 960px;">
    <!-- Nav -->
    <div class="d-flex gap-2 mb-4">
        <a href="<?= $fishUrl ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-house"></i> Overview</a>
        <a href="<?= $fishUrl ?>/config" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear"></i> Config</a>
        <a href="<?= $fishUrl ?>/stats" class="btn btn-primary btn-sm"><i class="bi bi-bar-chart"></i> Stats</a>
        <a href="<?= $fishUrl ?>/runtime" class="btn btn-outline-secondary btn-sm"><i class="bi bi-activity"></i> Runtime</a>
    </div>

    <h5 class="mb-3"><i class="bi bi-bar-chart me-1"></i> Fish — Stats</h5>

    <!-- Cumulative counters -->
    <div class="fish-diag-grid mb-3">
        <?php
        $cumItems = [
            ['Total Runs',       $stats['total_runs']           ?? 0, false],
            ['Successful',       $stats['successful_runs']      ?? 0, false],
            ['Failed',           $stats['failed_runs']          ?? 0, true],
            ['Signals (total)',  $stats['signals_found_total']  ?? 0, false],
            ['Geom Valid',       $stats['signals_geometry_valid_total']    ?? 0, false],
            ['Geom Rejected',    $stats['signals_geometry_rejected_total'] ?? 0, true],
            ['RR Below Min',     $stats['signals_rr_below_min_total']      ?? 0, true],
            ['Orders Placed',    $stats['orders_placed_total']  ?? 0, false],
            ['Errors',           $stats['errors_count']         ?? 0, true],
        ];
        foreach ($cumItems as [$label, $value, $isErr]):
        ?>
        <div class="fish-stat-card">
            <div class="fish-stat-v <?= ($isErr && $value > 0) ? 'fish-stat-err' : '' ?>"><?= (int)$value ?></div>
            <div class="fish-stat-l"><?= htmlspecialchars($label) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Last-run diagnostics (scanner output) -->
    <?php if (!empty($diag)): ?>
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-cpu me-1"></i> Last Scanner Run — Diagnostics</div>
        <div class="card-body p-0">
            <div class="fish-diag-grid p-3" style="margin-bottom: 0;">
                <?php
                $diagItems = [
                    ['Symbols Total',          $diag['symbols_total']             ?? 0, false],
                    ['Symbols Scanned',        $diag['symbols_scanned']           ?? 0, false],
                    ['Skipped (no data)',      $diag['symbols_skipped_no_data']   ?? 0, true],
                    ['Skipped (API error)',    $diag['symbols_skipped_api_err']   ?? 0, true],
                    ['Skipped (window)',       $diag['symbols_skipped_window']    ?? 0, false],
                    ['Structures Valid',       $diag['structures_valid']          ?? 0, false],
                    ['Structures Invalid',     $diag['structures_invalid']        ?? 0, true],
                    ['Levels Found',           $diag['levels_found']              ?? 0, false],
                    ['Levels Expired',         $diag['levels_expired']            ?? 0, false],
                    ['Candidates Valid',       $diag['candidates_valid']          ?? 0, false],
                    ['Candidates Rejected',    $diag['candidates_rejected']       ?? 0, true],
                    ['Signals Valid',          $diag['signals_valid']             ?? 0, false],
                ];
                foreach ($diagItems as [$label, $value, $isErr]):
                ?>
                <div class="fish-stat-card">
                    <div class="fish-stat-v <?= ($isErr && $value > 0) ? 'fish-stat-err' : '' ?>"><?= (int)$value ?></div>
                    <div class="fish-stat-l"><?= htmlspecialchars($label) ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Reject reason distribution -->
            <?php if (!empty($diag['reject_reasons'])): ?>
            <div class="px-3 pb-3">
                <div style="font-size: 11px; color: #94a3b8; margin-bottom: 6px; text-transform: uppercase;">Reject Reason Distribution</div>
                <table class="table table-sm table-dark mb-0" style="font-size: 11px;">
                    <thead><tr><th>Reason</th><th>Count</th></tr></thead>
                    <tbody>
                        <?php foreach ($diag['reject_reasons'] as $reason => $count): ?>
                        <tr>
                            <td><code><?= htmlspecialchars((string)$reason) ?></code></td>
                            <td><?= (int)$count ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Live signals -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-lightning me-1"></i> Live Signals (<?= count($signals) ?>)</span>
            <small class="text-muted">storage/signals.json</small>
        </div>
        <?php if (empty($signals)): ?>
        <div class="card-body"><span class="text-muted" style="font-size: 13px;">No signals yet.</span></div>
        <?php else: ?>
        <div class="card-body p-0" style="overflow-x: auto;">
            <table class="table table-sm table-dark mb-0">
                <thead style="font-size: 10px; text-transform: uppercase; color: #94a3b8;">
                    <tr>
                        <th>Symbol</th><th>Side</th><th>Entry</th><th>Stop</th>
                        <th>TP</th><th>BE Trigger</th><th>RR</th>
                        <th>Level Age</th><th>Trend</th><th>Bars</th><th>Detected</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($signals as $sig): ?>
                    <?php $geomOk = (bool)($sig['geometry_valid'] ?? true); ?>
                    <tr class="fish-signal-row<?= $geomOk ? '' : ' table-danger' ?>"
                        title="<?= $geomOk ? 'Geometry valid' : htmlspecialchars('Geometry issue: ' . ($sig['geometry_reject_reason'] ?? 'unknown')) ?>">
                        <td><code><?= htmlspecialchars($sig['symbol'] ?? '—') ?></code></td>
                        <td>
                            <span class="badge" style="background: <?= ($sig['side'] ?? '') === 'long' ? '#22c55e' : '#ef4444' ?>;">
                                <?= htmlspecialchars(strtoupper($sig['side'] ?? '')) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars((string)($sig['entry_price'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($sig['stop_price'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($sig['take_profit_price'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($sig['breakeven_trigger'] ?? '—')) ?></td>
                        <td>
                            <?php $rr = $sig['rr_ratio'] ?? null; ?>
                            <?php if ($rr !== null): ?>
                                <span style="color: <?= (float)$rr >= 2.0 ? '#22c55e' : '#ef4444' ?>; font-weight: 600;">
                                    <?= htmlspecialchars(number_format((float)$rr, 2)) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string)($sig['level_age_bars'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars($sig['trend_direction'] ?? '—') ?></td>
                        <td><?= htmlspecialchars((string)($sig['liquidity_pattern_bars'] ?? '—')) ?></td>
                        <td style="color: #64748b;"><?= htmlspecialchars(substr($sig['detected_at'] ?? '—', 0, 16)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Storage overview -->
    <div class="card">
        <div class="card-header"><i class="bi bi-folder me-1"></i> Storage Files</div>
        <div class="card-body p-0">
            <table class="table table-sm table-dark mb-0" style="font-size: 12px;">
                <thead><tr><th>File</th><th>Records</th><th>Last Modified</th></tr></thead>
                <tbody>
                    <?php
                    $storageFiles = [
                        'signals.json'          => $signals,
                        'active_orders.json'    => $orders,
                        'active_positions.json' => $positions,
                    ];
                    foreach ($storageFiles as $fname => $data):
                        $fpath = $moduleDir . '/storage/' . $fname;
                        $mtime = file_exists($fpath) ? date('Y-m-d H:i:s', filemtime($fpath)) : '—';
                    ?>
                    <tr>
                        <td><code><?= htmlspecialchars($fname) ?></code></td>
                        <td><?= count($data) ?></td>
                        <td style="color: #64748b;"><?= htmlspecialchars($mtime) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Fish Bot Status -->
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-robot me-1"></i> Fish Bot Runtime</span>
            <span class="badge" style="background: <?= $botEnabled ? ($botMode === 'live' ? '#ef4444' : '#f59e0b') : '#6b7280' ?>;">
                <?= $botEnabled ? strtoupper(htmlspecialchars($botMode)) : 'DISABLED' ?>
            </span>
        </div>
        <div class="card-body">
            <!-- Counters row -->
            <div class="fish-diag-grid mb-3">
                <?php
                $botItems = [
                    ['Execution Queue',    count($botQueue),                                    false],
                    ['Active Orders',      count(array_filter($botOrders,    fn($o) => ($o['status'] ?? '') === 'open')),   false],
                    ['Active Positions',   count(array_filter($botPositions, fn($p) => ($p['status'] ?? '') === 'open' && ($p['owner_strategy'] ?? '') === 'fish')), false],
                    ['Total Ticks',        $botStats['total_bot_ticks']        ?? 0,            false],
                    ['Orders Placed',      $botStats['orders_accepted_total']  ?? 0,            false],
                    ['Orders Rejected',    $botStats['orders_rejected_total']  ?? 0,            true],
                    ['Execution Errors',   $botStats['execution_errors_total'] ?? 0,            true],
                ];
                foreach ($botItems as [$label, $value, $isErr]):
                ?>
                <div class="fish-stat-card">
                    <div class="fish-stat-v <?= ($isErr && $value > 0) ? 'fish-stat-err' : '' ?>"><?= (int)$value ?></div>
                    <div class="fish-stat-l"><?= htmlspecialchars($label) ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Last bot tick info -->
            <?php if (!empty($botLastRun)): ?>
            <div style="font-size: 11px; color: #94a3b8; margin-bottom: 8px;">
                Last bot tick:
                <code><?= htmlspecialchars(substr($botLastRun['tick_at'] ?? '—', 0, 19)) ?></code>
                &nbsp;|&nbsp; Mode: <code><?= htmlspecialchars($botLastRun['mode'] ?? '—') ?></code>
                &nbsp;|&nbsp; Placed: <?= (int)($botLastRun['orders_placed'] ?? 0) ?>
                &nbsp;|&nbsp; Errors: <span<?= ($botLastRun['execution_errors'] ?? 0) > 0 ? ' style="color:#ef4444;"' : '' ?>><?= (int)($botLastRun['execution_errors'] ?? 0) ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($botStats['last_error'])): ?>
            <div style="font-size: 11px; color: #ef4444;">Last error: <?= htmlspecialchars($botStats['last_error']) ?></div>
            <?php endif; ?>

            <!-- Manual bot tick trigger -->
            <?php if ($botEnabled): ?>
            <form method="post" action="<?= $fishUrl ?>/ajax" class="mt-2">
                <input type="hidden" name="action" value="tick_bot">
                <button type="submit" class="btn btn-outline-warning btn-sm">
                    <i class="bi bi-play-circle me-1"></i> Trigger Bot Tick
                </button>
                <small class="text-muted ms-2">Cron auto-ticks every 120 s</small>
            </form>
            <?php else: ?>
            <div class="mt-2" style="font-size: 12px; color: #64748b;">
                Bot is disabled. Set <code>bot_enabled = true</code> in Fish config to enable execution.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bot Active Orders -->
    <?php if (!empty($botOrders)): ?>
    <div class="card mt-3">
        <div class="card-header"><i class="bi bi-list-check me-1"></i> Fish Bot — Active Orders (<?= count($botOrders) ?>)</div>
        <div class="card-body p-0" style="overflow-x: auto;">
            <table class="table table-sm table-dark mb-0" style="font-size: 11px;">
                <thead style="color: #94a3b8; text-transform: uppercase;">
                    <tr><th>Fish Order ID</th><th>Symbol</th><th>Side</th><th>Entry</th><th>Stop</th><th>TP</th><th>Status</th><th>Mode</th><th>Placed</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($botOrders as $ord): ?>
                    <tr>
                        <td><code style="font-size: 10px;"><?= htmlspecialchars($ord['fish_order_id'] ?? '—') ?></code></td>
                        <td><?= htmlspecialchars($ord['symbol'] ?? '—') ?></td>
                        <td><span class="badge" style="background: <?= ($ord['side'] ?? '') === 'long' ? '#22c55e' : '#ef4444' ?>;"><?= strtoupper(htmlspecialchars($ord['side'] ?? '')) ?></span></td>
                        <td><?= htmlspecialchars((string)($ord['entry_price'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($ord['stop_price'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($ord['take_profit_price'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars($ord['status'] ?? '—') ?></td>
                        <td><code><?= htmlspecialchars($ord['smoke'] ? 'smoke' : 'live') ?></code></td>
                        <td style="color: #64748b;"><?= htmlspecialchars(substr($ord['placed_at'] ?? '—', 0, 16)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Bot Active Positions -->
    <?php if (!empty($botPositions)): ?>
    <div class="card mt-3">
        <div class="card-header"><i class="bi bi-graph-up-arrow me-1"></i> Fish Bot — Active Positions (<?= count($botPositions) ?>)</div>
        <div class="card-body p-0" style="overflow-x: auto;">
            <table class="table table-sm table-dark mb-0" style="font-size: 11px;">
                <thead style="color: #94a3b8; text-transform: uppercase;">
                    <tr><th>Position ID</th><th>Symbol</th><th>Side</th><th>Entry</th><th>Stop</th><th>TP</th><th>BE Trigger</th><th>Status</th><th>SL/TP</th><th>Opened</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($botPositions as $pos): ?>
                    <tr>
                        <td><code style="font-size: 10px;"><?= htmlspecialchars($pos['fish_position_id'] ?? '—') ?></code></td>
                        <td><?= htmlspecialchars($pos['symbol'] ?? '—') ?></td>
                        <td><span class="badge" style="background: <?= ($pos['side'] ?? '') === 'long' ? '#22c55e' : '#ef4444' ?>;"><?= strtoupper(htmlspecialchars($pos['side'] ?? '')) ?></span></td>
                        <td><?= htmlspecialchars((string)($pos['entry_price'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($pos['stop_price'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($pos['take_profit_price'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($pos['breakeven_trigger'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars($pos['status'] ?? '—') ?></td>
                        <td><?= ($pos['sl_tp_attached'] ?? false) ? '<span style="color:#22c55e;">✓</span>' : '<span style="color:#94a3b8;">—</span>' ?></td>
                        <td style="color: #64748b;"><?= htmlspecialchars(substr($pos['opened_at'] ?? '—', 0, 16)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
