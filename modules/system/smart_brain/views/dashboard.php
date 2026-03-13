<?php
/**
 * Smart Brain Module - Dashboard View
 * 
 * Phase 1: Stable visual interface.
 * Cards: Candidates, Monitors, Signals, Simulator Positions
 * Tables: Monitors, Simulator Waiting/Active/Closed
 * Status values: monitoring, entry_zone, triggered, invalidated
 */

/** @var string $smartBrainUrl */
/** @var array<string,mixed> $last_run */
/** @var array<int,array<string,mixed>> $signals */
/** @var array<int,array<string,mixed>> $monitors */
/** @var array<int,array<string,mixed>> $waiting */
/** @var array<int,array<string,mixed>> $active */
/** @var array<int,array<string,mixed>> $closed */

$pageTitle = 'Smart Brain - Dashboard';
$activeTab = 'dashboard';

$extraStyles = '
.status-monitoring { background: rgba(59,130,246,0.15); color: #60a5fa; }
.status-entry_zone { background: rgba(245,158,11,0.15); color: #fbbf24; }
.status-triggered { background: rgba(16,185,129,0.15); color: #34d399; }
.status-invalidated { background: rgba(239,68,68,0.15); color: #f87171; }
.status-waiting { background: rgba(148,163,184,0.15); color: #94a3b8; }
';

$pageContent = function() use ($last_run, $signals, $monitors, $waiting, $active, $closed, $smartBrainUrl) {
    $statusClass = function(string $status): string {
        return match($status) {
            'monitoring' => 'status-monitoring',
            'entry_zone' => 'status-entry_zone',
            'triggered' => 'status-triggered',
            'invalidated' => 'status-invalidated',
            default => 'status-waiting',
        };
    };
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-cpu me-2 text-primary"></i>Smart Brain Dashboard</h4>
            <p class="text-secondary mb-0">Overview of the last cycle, signals, monitors, and simulator state</p>
        </div>
        <div>
            <small class="text-secondary">Last update: <?= htmlspecialchars((string)($last_run['updated_at'] ?? '-')) ?></small>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <h3 style="font-size: 2rem; font-weight: bold; color: var(--primary);"><?= htmlspecialchars((string)($last_run['candidates'] ?? '0')) ?></h3>
                    <p style="margin: 0; color: #94a3b8;">Candidates</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <h3 style="font-size: 2rem; font-weight: bold; color: #f59e0b;"><?= count($monitors) ?></h3>
                    <p style="margin: 0; color: #94a3b8;">Monitors</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <h3 style="font-size: 2rem; font-weight: bold; color: #10b981;"><?= count($signals) ?></h3>
                    <p style="margin: 0; color: #94a3b8;">Signals</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <h3 style="font-size: 2rem; font-weight: bold; color: #8b5cf6;"><?= count($waiting) + count($active) + count($closed) ?></h3>
                    <p style="margin: 0; color: #94a3b8;">Simulator Positions</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Monitors Table -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 style="margin: 0;"><i class="bi bi-binoculars me-1"></i> Monitors (<?= count($monitors) ?>)</h5>
        </div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr><th>Symbol</th><th>Corridor Low</th><th>Corridor High</th><th>Entry Zone Low</th><th>Entry Zone High</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($monitors)): ?>
                        <tr><td colspan="6" class="text-center text-secondary py-4">No monitors</td></tr>
                    <?php else: ?>
                        <?php foreach ($monitors as $row): ?>
                        <?php $st = (string)($row['status'] ?? 'waiting'); ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string)($row['symbol'] ?? '')) ?></strong></td>
                            <td><?= htmlspecialchars((string)($row['corridor_low'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($row['corridor_high'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($row['entry_zone_low'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($row['entry_zone_high'] ?? '')) ?></td>
                            <td><span class="badge <?= $statusClass($st) ?>"><?= htmlspecialchars($st) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Simulator: Waiting -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-hourglass-split me-1"></i> Simulator — Waiting (<?= count($waiting) ?>)</h5></div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead><tr><th>Symbol</th><th>Entry Zone</th><th>Budget</th><th>Leverage</th><th>Stop Loss</th><th>Take Profit</th></tr></thead>
                <tbody>
                    <?php if (empty($waiting)): ?>
                        <tr><td colspan="6" class="text-center text-secondary py-3">—</td></tr>
                    <?php else: ?>
                        <?php foreach ($waiting as $row): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string)($row['symbol'] ?? '')) ?></strong></td>
                            <td><?= htmlspecialchars((string)($row['entry_zone_low'] ?? '')) ?> → <?= htmlspecialchars((string)($row['entry_zone_high'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($row['budget'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($row['leverage'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($row['stoploss'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['takeprofit'] ?? '-')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Simulator: Active -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-lightning me-1 text-warning"></i> Simulator — Active (<?= count($active) ?>)</h5></div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead><tr><th>Symbol</th><th>Entry Price</th><th>Current Price</th><th>ROI</th><th>Leverage</th><th>Stop Loss</th><th>Take Profit</th></tr></thead>
                <tbody>
                    <?php if (empty($active)): ?>
                        <tr><td colspan="7" class="text-center text-secondary py-3">—</td></tr>
                    <?php else: ?>
                        <?php foreach ($active as $row): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string)($row['symbol'] ?? '')) ?></strong></td>
                            <td><?= htmlspecialchars((string)($row['entry_price'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['current_price'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['roi'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['leverage'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($row['stoploss'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['takeprofit'] ?? '-')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Simulator: Closed -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-check-circle me-1 text-success"></i> Simulator — Closed (<?= count($closed) ?>)</h5></div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead><tr><th>Symbol</th><th>Entry</th><th>Exit</th><th>ROI</th><th>Reason</th><th>Duration</th></tr></thead>
                <tbody>
                    <?php if (empty($closed)): ?>
                        <tr><td colspan="6" class="text-center text-secondary py-3">—</td></tr>
                    <?php else: ?>
                        <?php foreach ($closed as $row): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string)($row['symbol'] ?? '')) ?></strong></td>
                            <td><?= htmlspecialchars((string)($row['entry'] ?? $row['entry_price'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['exit'] ?? $row['exit_price'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['roi'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['reason'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['duration'] ?? '-')) ?></td>
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
