<?php

declare(strict_types=1);

/**
 * Fish Strategy — Admin Stats Page
 *
 * Displays cumulative stats from storage/stats.json and run history.
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
