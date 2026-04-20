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
$botBudget       = $config['bot_budget']   ?? 0.0;
$botLeverage     = $config['bot_leverage'] ?? 1;
$botLastRun      = $service->getBotLastRun();
$botStats        = $service->getBotStats();
$botQueue        = $service->getBotExecutionQueue();
$botOrders       = $service->getBotActiveOrders();
$botPositions    = $service->getBotActivePositions();
$botStorageReady = !empty($botLastRun);

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
                    ['Expired (too old)',      $diag['signals_expired_freshness'] ?? 0, true],
                    ['Before Dedupe',          $diag['signals_before_dedupe']     ?? ($diag['signals_valid'] ?? 0), false],
                    ['After Dedupe (active)',  $diag['signals_after_dedupe']      ?? ($diag['signals_active'] ?? 0), false],
                    ['Dupes Rejected',         $diag['duplicate_signals_rejected_total'] ?? ($diag['duplicate_signals_rejected'] ?? 0), true],
                    ['Expired Total',          $diag['expired_signals_rejected_total']   ?? 0, true],
                    ['TTL (bars)',             $diag['signal_ttl_bars']           ?? '—', false],
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
                        <th>Level Age</th><th>TTL Bars</th><th>Fresh?</th>
                        <th>Trend</th><th>Bars</th><th>Detected</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($signals as $sig): ?>
                    <?php
                        $geomOk    = (bool)($sig['geometry_valid'] ?? true);
                        $freshOk   = (bool)($sig['freshness_valid'] ?? true);
                        $freshClass = $freshOk ? '' : ' table-warning';
                    ?>
                    <tr class="fish-signal-row<?= ($geomOk ? '' : ' table-danger') . $freshClass ?>"
                        title="<?= $geomOk ? '' : htmlspecialchars('Geometry: ' . ($sig['geometry_reject_reason'] ?? 'unknown')) ?>">
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
                        <td style="color: #64748b;"><?= htmlspecialchars((string)($sig['signal_ttl_bars'] ?? '—')) ?></td>
                        <td>
                            <?php if ($freshOk): ?>
                                <span style="color:#22c55e; font-size:10px;">✓</span>
                            <?php else: ?>
                                <span style="color:#f59e0b; font-size:10px;" title="<?= htmlspecialchars((string)($sig['freshness_reject_reason'] ?? '')) ?>">✗</span>
                            <?php endif; ?>
                        </td>
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

            <?php if (!$botStorageReady): ?>
            <div style="font-size: 12px; color: #64748b; margin-bottom: 12px; padding: 8px 12px; background: #1e293b; border-radius: 6px; border: 1px solid #334155;">
                <i class="bi bi-info-circle me-1"></i>
                Bot storage not yet initialized.
                <?php if ($botEnabled): ?>
                    Trigger a bot tick to initialize runtime files.
                <?php else: ?>
                    Enable <code>bot_enabled</code> in Fish config, then run a bot tick.
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Config summary row -->
            <div style="font-size: 11px; color: #64748b; margin-bottom: 10px;">
                Config &rarr;
                <code>execution_mode=<?= htmlspecialchars($botMode) ?></code>
                &nbsp;|&nbsp;<code>bot_budget=<?= htmlspecialchars((string)$botBudget) ?></code>
                &nbsp;|&nbsp;<code>bot_leverage=<?= htmlspecialchars((string)$botLeverage) ?>x</code>
                &nbsp;&mdash;&nbsp;
                <a href="<?= $fishUrl ?>/config" style="color: #60a5fa; font-size: 11px;">Edit in Config &rarr;</a>
            </div>

            <!-- Counters row -->
            <div class="fish-diag-grid mb-3">
                <?php
                // Use live last_run counts when available, fall back to storage reads
                $queueDepth   = (int)($botLastRun['queue_depth']      ?? count($botQueue));
                $activeOrders = (int)($botLastRun['active_orders']     ?? count(array_filter($botOrders,    fn($o) => ($o['status'] ?? '') === 'open')));
                $activePos    = (int)($botLastRun['active_positions']  ?? count(array_filter($botPositions, fn($p) => ($p['status'] ?? '') === 'open' && ($p['owner_strategy'] ?? '') === 'fish')));
                $botItems = [
                    ['Execution Queue',     $queueDepth,                                                       false],
                    ['Active Orders',       $activeOrders,                                                     false],
                    ['Active Positions',    $activePos,                                                        false],
                    ['Total Ticks',         $botStats['total_bot_ticks']            ?? 0,                     false],
                    ['Orders Placed',       $botStats['orders_accepted_total']      ?? 0,                     false],
                    ['Orders Filled',       $botStats['orders_filled_total']        ?? 0,                     false],
                    ['Orders Rejected',     $botStats['orders_rejected_total']      ?? 0,                     true],
                    ['Orders Cancelled',    $botStats['orders_cancelled_total']     ?? 0,                     true],
                    ['Positions Opened',    $botStats['positions_opened_total']     ?? 0,                     false],
                    ['SL/TP Attached',      $botStats['sltp_attach_success_total']  ?? 0,                     false],
                    ['SL/TP Failed',        $botStats['sltp_attach_failed_total']   ?? 0,                     true],
                    ['Execution Errors',    $botStats['execution_errors_total']     ?? 0,                     true],
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
            <?php if ($botStorageReady): ?>
            <div style="font-size: 11px; color: #94a3b8; margin-bottom: 8px;">
                Last bot tick:
                <code><?= htmlspecialchars($botLastRun['last_tick'] ?? ($botLastRun['tick_at'] ?? '—')) ?></code>
                &nbsp;|&nbsp; Mode: <code><?= htmlspecialchars($botLastRun['execution_mode'] ?? ($botLastRun['mode'] ?? '—')) ?></code>
                &nbsp;|&nbsp; Status: <code><?= htmlspecialchars($botLastRun['status'] ?? '—') ?></code>
                &nbsp;|&nbsp; Enqueued: <?= (int)($botLastRun['signals_enqueued'] ?? 0) ?>
                &nbsp;|&nbsp; Placed: <?= (int)($botLastRun['orders_accepted'] ?? $botLastRun['orders_placed'] ?? 0) ?>
                &nbsp;|&nbsp; Rejected: <span<?= ($botLastRun['orders_rejected'] ?? 0) > 0 ? ' style="color:#f59e0b;"' : '' ?>><?= (int)($botLastRun['orders_rejected'] ?? 0) ?></span>
            </div>

            <?php
            // Fill-detection diagnostics from last tick
            $fillResult = $botLastRun['last_fill_detect_result'] ?? null;
            if (is_array($fillResult)):
            ?>
            <div style="font-size: 11px; color: #94a3b8; margin-bottom: 8px;">
                Fill detect (last tick):
                checked=<code><?= (int)($fillResult['orders_checked'] ?? 0) ?></code>
                &nbsp;|&nbsp; filled=<code style="color:#22c55e;"><?= (int)($fillResult['orders_filled'] ?? 0) ?></code>
                &nbsp;|&nbsp; cancelled=<code<?= ($fillResult['orders_cancelled'] ?? 0) > 0 ? ' style="color:#f59e0b;"' : '' ?>><?= (int)($fillResult['orders_cancelled'] ?? 0) ?></code>
                &nbsp;|&nbsp; positions_opened=<code><?= (int)($fillResult['positions_opened'] ?? 0) ?></code>
            </div>
            <?php endif; ?>

            <?php
            // SL/TP diagnostics from last tick
            $posOpenedTick  = (int)($botLastRun['positions_opened_this_tick']     ?? 0);
            $sltpAttTick    = (int)($botLastRun['sltp_attach_attempts_this_tick'] ?? 0);
            $sltpMissing    = (int)($botLastRun['positions_missing_sltp_total']   ?? 0);
            if ($posOpenedTick > 0 || $sltpAttTick > 0 || $sltpMissing > 0):
            ?>
            <div style="font-size: 11px; color: #94a3b8; margin-bottom: 8px;">
                SL/TP (last tick):
                positions_opened=<code><?= $posOpenedTick ?></code>
                &nbsp;|&nbsp; attach_attempts=<code><?= $sltpAttTick ?></code>
                &nbsp;|&nbsp; missing_sltp=<code<?= $sltpMissing > 0 ? ' style="color:#f59e0b;"' : '' ?>><?= $sltpMissing ?></code>
            </div>
            <?php endif; ?>

            <?php
            // Gateway diagnostics (safe, no secrets)
            $gwDiag = $botLastRun['gateway'] ?? null;
            if (is_array($gwDiag)):
                $gwInit   = $gwDiag['client_init'] ?? '—';
                $gwAcct   = $gwDiag['account_id_used'] ?? '—';
                $gwErr    = $gwDiag['init_error'] ?? null;
                $gwMode   = $gwDiag['execution_mode'] ?? '—';
            ?>
            <div style="font-size: 11px; color: #64748b; margin-bottom: 8px;">
                Gateway:
                <code><?= htmlspecialchars($gwMode) ?></code>
                &nbsp;|&nbsp; Account: <code><?= htmlspecialchars($gwAcct) ?></code>
                &nbsp;|&nbsp; Client init: <span style="color: <?= $gwInit === 'ok' ? '#22c55e' : '#ef4444' ?>"><?= htmlspecialchars($gwInit) ?></span>
                <?php if ($gwErr !== null): ?>
                &nbsp;|&nbsp; <span style="color: #ef4444;"><?= htmlspecialchars($gwErr) ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php
            $lastErr = $botLastRun['last_error'] ?? ($botStats['last_error'] ?? null);
            if (!empty($lastErr)): ?>
            <div style="font-size: 11px; color: #ef4444; margin-bottom: 8px;">
                <i class="bi bi-exclamation-circle me-1"></i>Last error: <?= htmlspecialchars($lastErr) ?>
            </div>
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
                Bot is disabled. Enable <code>bot_enabled</code> in <a href="<?= $fishUrl ?>/config" style="color:#60a5fa;">Fish Config</a> to activate execution.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bot Active Orders -->
    <div class="card mt-3">
        <div class="card-header"><i class="bi bi-list-check me-1"></i> Fish Bot — Active Orders (<?= count($botOrders) ?>)</div>
        <?php if (empty($botOrders)): ?>
        <div class="card-body" style="font-size: 12px; color: #64748b;">
            <?= $botStorageReady ? 'No active orders.' : 'Storage not initialized — trigger a bot tick first.' ?>
        </div>
        <?php else: ?>
        <div class="card-body p-0" style="overflow-x: auto;">
            <table class="table table-sm table-dark mb-0" style="font-size: 11px;">
                <thead style="color: #94a3b8; text-transform: uppercase;">
                    <tr><th>Fish Order ID</th><th>Symbol</th><th>Side</th><th>Entry</th><th>Stop</th><th>TP</th><th>Status</th><th>Fill Price</th><th>Mode</th><th>Placed</th><th>Filled</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($botOrders as $ord): ?>
                    <?php
                    $ordStatus  = $ord['status'] ?? '—';
                    $statusColor = match($ordStatus) {
                        'open'     => '#94a3b8',
                        'filled'   => '#22c55e',
                        'cancelled', 'rejected', 'expired' => '#f59e0b',
                        default    => '#64748b',
                    };
                    ?>
                    <tr>
                        <td><code style="font-size: 10px;"><?= htmlspecialchars($ord['fish_order_id'] ?? '—') ?></code></td>
                        <td><?= htmlspecialchars($ord['symbol'] ?? '—') ?></td>
                        <td><span class="badge" style="background: <?= ($ord['side'] ?? '') === 'long' ? '#22c55e' : '#ef4444' ?>;"><?= strtoupper(htmlspecialchars($ord['side'] ?? '')) ?></span></td>
                        <td><?= htmlspecialchars((string)($ord['entry_price'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($ord['stop_price'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($ord['take_profit_price'] ?? '—')) ?></td>
                        <td><span style="color:<?= $statusColor ?>;"><?= htmlspecialchars($ordStatus) ?></span></td>
                        <td><?= isset($ord['fill_avg_price']) && $ord['fill_avg_price'] > 0 ? htmlspecialchars((string)$ord['fill_avg_price']) : '<span style="color:#64748b;">—</span>' ?></td>
                        <td><code><?= htmlspecialchars(isset($ord['smoke']) && $ord['smoke'] ? 'smoke' : 'live') ?></code></td>
                        <td style="color: #64748b;"><?= htmlspecialchars(substr($ord['placed_at'] ?? '—', 0, 16)) ?></td>
                        <td style="color: #64748b;"><?= isset($ord['filled_at']) ? htmlspecialchars(substr($ord['filled_at'], 0, 16)) : '<span style="color:#64748b;">—</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Bot Active Positions -->
    <div class="card mt-3">
        <div class="card-header"><i class="bi bi-graph-up-arrow me-1"></i> Fish Bot — Active Positions (<?= count($botPositions) ?>)</div>
        <?php if (empty($botPositions)): ?>
        <div class="card-body" style="font-size: 12px; color: #64748b;">
            <?= $botStorageReady ? 'No active positions.' : 'Storage not initialized — trigger a bot tick first.' ?>
        </div>
        <?php else: ?>
        <div class="card-body p-0" style="overflow-x: auto;">
            <table class="table table-sm table-dark mb-0" style="font-size: 11px;">
                <thead style="color: #94a3b8; text-transform: uppercase;">
                    <tr><th>Position ID</th><th>Symbol</th><th>Side</th><th>Entry</th><th>Stop</th><th>TP</th><th>BE Trigger</th><th>Status</th><th>SL/TP</th><th>Attempts</th><th>SL/TP Error</th><th>Opened</th></tr>
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
                        <td><?= ($pos['sl_tp_attached'] ?? false) ? '<span style="color:#22c55e;">✓</span>' : '<span style="color:#f59e0b;">✗</span>' ?></td>
                        <td><?= isset($pos['sl_tp_attach_attempts']) ? (int)$pos['sl_tp_attach_attempts'] : '<span style="color:#64748b;">—</span>' ?></td>
                        <td style="color:#ef4444;font-size:10px;"><?= htmlspecialchars($pos['sl_tp_last_error'] ?? '') ?></td>
                        <td style="color: #64748b;"><?= htmlspecialchars(substr($pos['opened_at'] ?? '—', 0, 16)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
