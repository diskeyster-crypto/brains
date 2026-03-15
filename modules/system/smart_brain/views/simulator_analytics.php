<?php
/**
 * Smart Brain Module - Simulator Analytics View
 *
 * Full analytics: global stats, exit reasons, active/closed summaries,
 * visual trade table with badges and color-coded ROI, simple charts.
 */

/** @var string $smartBrainUrl */
/** @var array<int,array<string,mixed>> $waiting */
/** @var array<int,array<string,mixed>> $active */
/** @var array<int,array<string,mixed>> $closed */
/** @var array<string,mixed> $stats */
/** @var array<string,int> $exit_reasons */
/** @var float $avg_roi */
/** @var float $median_roi */
/** @var float $avg_mae */
/** @var float $avg_mfe */
/** @var float $avg_duration */
/** @var int $wins */
/** @var int $losses */
/** @var array<string,int> $roi_buckets */

$pageTitle = 'Smart Brain - Simulator Analytics';
$activeTab = 'simulator_analytics';

$extraStyles = '
    .roi-positive { color: #22c55e; }
    .roi-negative { color: #ef4444; }
    .badge-win { background: #22c55e; }
    .badge-loss { background: #ef4444; }
    .badge-active-trade { background: #3b82f6; }
    .badge-closed-trade { background: #64748b; }
    .stat-card { text-align: center; padding: 1rem; }
    .stat-card .stat-value { font-size: 1.5rem; font-weight: 700; }
    .stat-card .stat-label { font-size: 0.8rem; color: #94a3b8; }
    .chart-bar { display: inline-block; min-width: 4px; border-radius: 2px; vertical-align: bottom; }
    .reason-bar { height: 20px; border-radius: 3px; display: inline-block; }
';

/**
 * Helper: format ROI as colored percentage
 */
$fmtRoi = function($roi): string {
    $val = (float)$roi;
    $pct = number_format($val * 100, 2);
    $cls = $val >= 0 ? 'roi-positive' : 'roi-negative';
    return '<span class="' . $cls . '">' . ($val >= 0 ? '+' : '') . htmlspecialchars($pct) . '%</span>';
};

/**
 * Helper: Bybit symbol link
 */
$symbolLink = function(string $symbol): string {
    $safe = htmlspecialchars($symbol);
    return '<a href="https://www.bybit.com/trade/usdt/' . $safe . '" target="_blank" rel="noopener noreferrer" class="text-info text-decoration-none fw-bold">' . $safe . '</a>';
};

$totalClosed = count($closed);
$totalActive = count($active);
$totalWaiting = count($waiting);
$totalAll = $totalClosed + $totalActive + $totalWaiting;
$winrate = $totalClosed > 0 ? ($wins / $totalClosed) : 0;

$pageContent = function() use (
    $waiting, $active, $closed, $stats, $exit_reasons,
    $avg_roi, $median_roi, $avg_mae, $avg_mfe, $avg_duration,
    $wins, $losses, $roi_buckets, $fmtRoi, $symbolLink,
    $totalClosed, $totalActive, $totalWaiting, $totalAll, $winrate,
    $smartBrainUrl
) {
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Simulator Analytics</h4>
            <p class="text-secondary mb-0">Comprehensive analytics for paper-trading simulator</p>
        </div>
        <div>
            <a href="<?= htmlspecialchars($smartBrainUrl) ?>/simulator" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-joystick me-1"></i>Back to Simulator
            </a>
        </div>
    </div>

    <!-- ===== A. GLOBAL STATS ===== -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin:0;"><i class="bi bi-speedometer2 me-1"></i> Global Statistics</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value"><?= $totalAll ?></div><div class="stat-label">Total Signals</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value"><?= $totalWaiting ?></div><div class="stat-label">Waiting</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value text-primary"><?= $totalActive ?></div><div class="stat-label">Active</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value text-success"><?= $totalClosed ?></div><div class="stat-label">Closed</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value"><?= number_format($winrate * 100, 1) ?>%</div><div class="stat-label">Winrate</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value <?= $avg_roi >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format($avg_roi * 100, 2) ?>%</div><div class="stat-label">Avg ROI</div></div>
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value <?= $median_roi >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format($median_roi * 100, 2) ?>%</div><div class="stat-label">Median ROI</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value"><?= number_format($avg_mae * 100, 2) ?>%</div><div class="stat-label">Avg MAE</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value"><?= number_format($avg_mfe * 100, 2) ?>%</div><div class="stat-label">Avg MFE</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value"><?= number_format($avg_duration, 0) ?> min</div><div class="stat-label">Avg Duration</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value roi-positive"><?= $wins ?></div><div class="stat-label">Wins</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value roi-negative"><?= $losses ?></div><div class="stat-label">Losses</div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== D. EXIT REASONS SUMMARY + CHARTS ===== -->
    <div class="row mb-4">
        <!-- Exit Reasons Card -->
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header"><h5 style="margin:0;"><i class="bi bi-door-open me-1"></i> Exit Reasons</h5></div>
                <div class="card-body">
                    <?php if (empty($exit_reasons)): ?>
                        <p class="text-secondary text-center py-3">No closed trades yet</p>
                    <?php else: ?>
                        <table class="table table-dark table-sm mb-0">
                            <thead><tr><th>Reason</th><th class="text-end">Count</th><th class="text-end">%</th></tr></thead>
                            <tbody>
                            <?php
                            $reasonColors = [
                                'stop_loss' => '#ef4444',
                                'trailing_stop' => '#f59e0b',
                                'break_even_stop' => '#3b82f6',
                                'take_profit' => '#22c55e',
                                'early_failure' => '#f97316',
                            ];
                            foreach ($exit_reasons as $reason => $count):
                                $pct = $totalClosed > 0 ? ($count / $totalClosed) * 100 : 0;
                                $color = $reasonColors[$reason] ?? '#94a3b8';
                            ?>
                            <tr>
                                <td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= $color ?>;margin-right:6px;"></span><?= htmlspecialchars($reason) ?></td>
                                <td class="text-end"><?= $count ?></td>
                                <td class="text-end"><?= number_format($pct, 1) ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- State Distribution Chart -->
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header"><h5 style="margin:0;"><i class="bi bi-pie-chart me-1"></i> State Distribution</h5></div>
                <div class="card-body d-flex flex-column justify-content-center">
                    <?php if ($totalAll === 0): ?>
                        <p class="text-secondary text-center py-3">No data</p>
                    <?php else: ?>
                        <?php
                        $barTotal = max($totalAll, 1);
                        $wPct = ($totalWaiting / $barTotal) * 100;
                        $aPct = ($totalActive / $barTotal) * 100;
                        $cPct = ($totalClosed / $barTotal) * 100;
                        ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1"><small>Waiting</small><small><?= $totalWaiting ?></small></div>
                            <div class="progress" style="height:12px;background:#1e293b;">
                                <div class="progress-bar" style="width:<?= $wPct ?>%;background:#64748b;"></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1"><small>Active</small><small><?= $totalActive ?></small></div>
                            <div class="progress" style="height:12px;background:#1e293b;">
                                <div class="progress-bar" style="width:<?= $aPct ?>%;background:#3b82f6;"></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1"><small>Closed</small><small><?= $totalClosed ?></small></div>
                            <div class="progress" style="height:12px;background:#1e293b;">
                                <div class="progress-bar" style="width:<?= $cPct ?>%;background:#22c55e;"></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ROI Distribution Chart -->
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header"><h5 style="margin:0;"><i class="bi bi-bar-chart me-1"></i> ROI Distribution</h5></div>
                <div class="card-body d-flex flex-column justify-content-center">
                    <?php if ($totalClosed === 0): ?>
                        <p class="text-secondary text-center py-3">No closed trades</p>
                    <?php else: ?>
                        <?php
                        $maxBucket = max(1, max($roi_buckets));
                        $bucketColors = ['#ef4444', '#f97316', '#fbbf24', '#86efac', '#22c55e', '#15803d'];
                        $i = 0;
                        ?>
                        <div class="d-flex align-items-end justify-content-around" style="height:120px;">
                            <?php foreach ($roi_buckets as $label => $count):
                                $h = $maxBucket > 0 ? max(4, ($count / $maxBucket) * 100) : 4;
                                $color = $bucketColors[$i % count($bucketColors)];
                                $i++;
                            ?>
                            <div class="text-center" style="flex:1;max-width:60px;">
                                <div class="chart-bar" style="height:<?= $h ?>px;width:80%;background:<?= $color ?>;margin:0 auto;"></div>
                                <div style="font-size:0.65rem;color:#94a3b8;margin-top:4px;"><?= htmlspecialchars($label) ?></div>
                                <div style="font-size:0.7rem;"><?= $count ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== B. ACTIVE TRADES SUMMARY ===== -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 style="margin:0;"><i class="bi bi-lightning me-1 text-warning"></i> Active Trades (<?= $totalActive ?>)</h5>
        </div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead><tr>
                    <th>Symbol</th><th>Entry Price</th><th>Current Price</th><th>ROI</th>
                    <th>MAE</th><th>MFE</th><th>Exit Mode</th><th>Stop Mode</th><th>Trailing</th><th>BE</th>
                    <th>Leverage</th><th>Opened</th><th>State</th>
                </tr></thead>
                <tbody>
                    <?php if (empty($active)): ?>
                        <tr><td colspan="13" class="text-center text-secondary py-4">No active positions</td></tr>
                    <?php else: ?>
                        <?php foreach ($active as $row): ?>
                        <tr>
                            <td><?= $symbolLink((string)($row['symbol'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($row['entry_price'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['current_price'] ?? '-')) ?></td>
                            <td><?= $fmtRoi($row['roi'] ?? 0) ?></td>
                            <td><?= htmlspecialchars((string)($row['mae'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['mfe'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['exit_mode'] ?? '-')) ?></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars((string)($row['stop_mode'] ?? '-')) ?></span></td>
                            <td><?= !empty($row['trailing_active']) ? '<span class="badge bg-info">ON</span>' : '-' ?></td>
                            <td><?= !empty($row['break_even_active']) ? '<span class="badge bg-success">ON</span>' : '-' ?></td>
                            <td><?= htmlspecialchars((string)($row['leverage'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['opened_at'] ?? '-')) ?></td>
                            <td><span class="badge badge-active-trade">ACTIVE</span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ===== C. CLOSED TRADES SUMMARY ===== -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 style="margin:0;"><i class="bi bi-check-circle me-1 text-success"></i> Closed Trades (<?= $totalClosed ?>)</h5>
        </div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead><tr>
                    <th>Symbol</th><th>Entry</th><th>Exit</th><th>ROI</th>
                    <th>MAE</th><th>MFE</th><th>Exit Mode</th><th>Stop Mode</th><th>Reason</th>
                    <th>Duration</th><th>Opened</th><th>Closed</th><th>Result</th>
                </tr></thead>
                <tbody>
                    <?php if (empty($closed)): ?>
                        <tr><td colspan="13" class="text-center text-secondary py-4">No closed positions</td></tr>
                    <?php else: ?>
                        <?php foreach ($closed as $row):
                            $roi = (float)($row['roi'] ?? 0);
                            $isWin = $roi > 0;
                        ?>
                        <tr>
                            <td><?= $symbolLink((string)($row['symbol'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($row['entry_price'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['exit_price'] ?? '-')) ?></td>
                            <td><?= $fmtRoi($roi) ?></td>
                            <td><?= htmlspecialchars((string)($row['mae'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['mfe'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['exit_mode'] ?? '-')) ?></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars((string)($row['stop_mode'] ?? '-')) ?></span></td>
                            <td>
                                <?php
                                $reason = (string)($row['reason'] ?? '-');
                                $reasonBadge = match($reason) {
                                    'stop_loss' => 'bg-danger',
                                    'trailing_stop' => 'bg-warning text-dark',
                                    'break_even_stop' => 'bg-info',
                                    'take_profit' => 'bg-success',
                                    'early_failure' => 'bg-warning text-dark',
                                    default => 'bg-secondary',
                                };
                                ?>
                                <span class="badge <?= $reasonBadge ?>"><?= htmlspecialchars($reason) ?></span>
                            </td>
                            <td><?= ($row['duration'] ?? null) !== null ? htmlspecialchars((string)$row['duration']) . ' min' : '-' ?></td>
                            <td><?= htmlspecialchars((string)($row['opened_at'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['closed_at'] ?? '-')) ?></td>
                            <td><span class="badge <?= $isWin ? 'badge-win' : 'badge-loss' ?>"><?= $isWin ? 'WIN' : 'LOSS' ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php
};

require __DIR__ . '/_layout.php';
