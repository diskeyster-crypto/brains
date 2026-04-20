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
                    ['Symbols Total',          $diag['symbols_total']            ?? 0, false],
                    ['Symbols Scanned',        $diag['symbols_scanned']          ?? 0, false],
                    ['Skipped (no data)',      $diag['symbols_skipped_no_data']  ?? 0, true],
                    ['Skipped (API error)',    $diag['symbols_skipped_api_err']  ?? 0, true],
                    ['Structures Valid',       $diag['structures_valid']         ?? 0, false],
                    ['Structures Invalid',     $diag['structures_invalid']       ?? 0, true],
                    ['Levels Found',           $diag['levels_found']             ?? 0, false],
                    ['Levels Expired',         $diag['levels_expired']           ?? 0, false],
                    ['Candidates Valid',       $diag['candidates_valid']         ?? 0, false],
                    ['Candidates Rejected',    $diag['candidates_rejected']      ?? 0, true],
                    ['Signals Valid',          $diag['signals_valid']            ?? 0, false],
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
                        <th>TP</th><th>BE Trigger</th><th>Level Age</th>
                        <th>Trend</th><th>Bars</th><th>Detected</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($signals as $sig): ?>
                    <tr class="fish-signal-row">
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
</div>

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
$stats    = $service->getStats();
$lastRun  = $service->getLastRun();
$signals  = $service->loadStorage('signals.json');
$orders   = $service->loadStorage('active_orders.json');
$positions = $service->loadStorage('active_positions.json');

$fishUrl = rtrim(System::web('admin/strategy/fish'), '/');
?>
<div style="max-width: 860px;">
    <!-- Sub-page Nav -->
    <div class="d-flex gap-2 mb-4">
        <a href="<?= $fishUrl ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-house"></i> Overview</a>
        <a href="<?= $fishUrl ?>/config" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear"></i> Config</a>
        <a href="<?= $fishUrl ?>/stats" class="btn btn-primary btn-sm"><i class="bi bi-bar-chart"></i> Stats</a>
        <a href="<?= $fishUrl ?>/runtime" class="btn btn-outline-secondary btn-sm"><i class="bi bi-activity"></i> Runtime</a>
    </div>

    <h5 class="mb-3"><i class="bi bi-bar-chart me-1"></i> Fish — Stats</h5>

    <!-- Stats Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; margin-bottom: 20px;">
        <?php
        $statItems = [
            ['label' => 'Total Runs',        'key' => 'total_runs',           'default' => 0],
            ['label' => 'Successful Runs',   'key' => 'successful_runs',      'default' => 0],
            ['label' => 'Failed Runs',       'key' => 'failed_runs',          'default' => 0],
            ['label' => 'Signals Total',     'key' => 'signals_found_total',  'default' => 0],
            ['label' => 'Orders Placed',     'key' => 'orders_placed_total',  'default' => 0],
            ['label' => 'Errors',            'key' => 'errors_count',         'default' => 0],
            ['label' => 'Live Signals',      'value' => count($signals),      'default' => 0],
            ['label' => 'Active Orders',     'value' => count($orders),       'default' => 0],
            ['label' => 'Active Positions',  'value' => count($positions),    'default' => 0],
        ];
        foreach ($statItems as $item):
            $v = isset($item['value']) ? $item['value'] : ($stats[$item['key']] ?? $item['default']);
            $isError = ($item['label'] === 'Errors' || $item['key'] === 'errors_count') && $v > 0;
        ?>
        <div class="card text-center">
            <div class="card-body py-3">
                <div style="font-size: 26px; font-weight: 700; color: <?= $isError ? '#ef4444' : '#e2e8f0' ?>;">
                    <?= (int)$v ?>
                </div>
                <div style="font-size: 11px; color: #94a3b8; text-transform: uppercase; margin-top: 2px;">
                    <?= htmlspecialchars($item['label']) ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Last Run Detail -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-clock-history me-1"></i> Last Run Detail</div>
        <div class="card-body">
            <?php if (empty($lastRun)): ?>
                <span class="text-muted" style="font-size: 13px;">No run recorded yet.</span>
            <?php else: ?>
                <pre style="font-size: 11px; margin: 0; color: #94a3b8; max-height: 200px; overflow: auto;"><?= htmlspecialchars(json_encode($lastRun, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php endif; ?>
        </div>
    </div>

    <!-- Storage Overview -->
    <div class="card">
        <div class="card-header"><i class="bi bi-folder me-1"></i> Storage Overview</div>
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
</div>
