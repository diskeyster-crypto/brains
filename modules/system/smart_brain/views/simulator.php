<?php
/**
 * Smart Brain Module - Simulator View
 * 
 * Phase 8: Simulator Engine display.
 * Tables: Waiting, Active, Closed with full fields per TZ.
 */

/** @var string $smartBrainUrl */
/** @var array<int,array<string,mixed>> $waiting */
/** @var array<int,array<string,mixed>> $active */
/** @var array<int,array<string,mixed>> $closed */
/** @var array<string,mixed> $last_run */

$pageTitle = 'Smart Brain - Simulator';
$activeTab = 'simulator';

$pageContent = function() use ($waiting, $active, $closed, $last_run, $smartBrainUrl) {
    $totalPositions = count($waiting) + count($active) + count($closed);
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-joystick me-2 text-primary"></i>Simulator</h4>
            <p class="text-secondary mb-0">Test signals without live trading — always running</p>
        </div>
        <div>
            <span class="badge bg-secondary fs-6"><?= count($waiting) ?> Waiting</span>
            <span class="badge bg-warning fs-6 ms-1"><?= count($active) ?> Active</span>
            <span class="badge bg-success fs-6 ms-1"><?= count($closed) ?> Closed</span>
        </div>
    </div>

    <!-- Waiting Table -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-hourglass-split me-1"></i> Waiting (<?= count($waiting) ?>)</h5></div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead><tr><th>Symbol</th><th>Entry Zone</th><th>Budget</th><th>Leverage</th><th>Stop Loss</th><th>Take Profit</th></tr></thead>
                <tbody>
                    <?php if (empty($waiting)): ?>
                        <tr><td colspan="6" class="text-center text-secondary py-4">No waiting positions</td></tr>
                    <?php else: ?>
                        <?php foreach ($waiting as $row): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string)($row['symbol'] ?? '')) ?></strong></td>
                            <td><?= htmlspecialchars((string)($row['entry_zone_low'] ?? '')) ?> → <?= htmlspecialchars((string)($row['entry_zone_high'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($row['budget'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['leverage'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['stoploss'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['takeprofit'] ?? '-')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Active Table -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-lightning me-1 text-warning"></i> Active (<?= count($active) ?>)</h5></div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead><tr><th>Symbol</th><th>Entry Price</th><th>Current Price</th><th>ROI</th><th>Leverage</th><th>Stop Loss</th><th>Take Profit</th></tr></thead>
                <tbody>
                    <?php if (empty($active)): ?>
                        <tr><td colspan="7" class="text-center text-secondary py-4">No active positions</td></tr>
                    <?php else: ?>
                        <?php foreach ($active as $row): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string)($row['symbol'] ?? '')) ?></strong></td>
                            <td><?= htmlspecialchars((string)($row['entry_price'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['current_price'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['roi'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['leverage'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['stoploss'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['takeprofit'] ?? '-')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Closed Table -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-check-circle me-1 text-success"></i> Closed (<?= count($closed) ?>)</h5></div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead><tr><th>Symbol</th><th>Entry</th><th>Exit</th><th>ROI</th><th>Reason</th><th>Duration</th></tr></thead>
                <tbody>
                    <?php if (empty($closed)): ?>
                        <tr><td colspan="6" class="text-center text-secondary py-4">No closed positions</td></tr>
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
