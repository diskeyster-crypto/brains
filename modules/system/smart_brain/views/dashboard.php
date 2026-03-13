<?php
/**
 * Smart Brain Module - Dashboard View
 * 
 * Displays last run info, signals, monitors, and simulator state.
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

$pageContent = function() use ($last_run, $signals, $monitors, $waiting, $active, $closed, $smartBrainUrl) {
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-cpu me-2 text-primary"></i>Smart Brain Dashboard</h4>
            <p class="text-secondary mb-0">Overview of the last cycle, signals, monitors, and simulator state</p>
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
                    <h3 style="font-size: 2rem; font-weight: bold; color: #f59e0b;"><?= htmlspecialchars((string)($last_run['monitors'] ?? '0')) ?></h3>
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

    <!-- Last Run Info -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 style="margin: 0;"><i class="bi bi-clock-history me-1"></i> Last Run</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3"><strong>Updated:</strong> <?= htmlspecialchars((string)($last_run['updated_at'] ?? '-')) ?></div>
                <div class="col-md-3"><strong>Candidates:</strong> <?= htmlspecialchars((string)($last_run['candidates'] ?? '0')) ?></div>
                <div class="col-md-3"><strong>Monitors:</strong> <?= htmlspecialchars((string)($last_run['monitors'] ?? '0')) ?></div>
                <div class="col-md-3"><strong>Signals:</strong> <?= htmlspecialchars((string)($last_run['signals'] ?? '0')) ?></div>
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
                    <tr><th>Symbol</th><th>Corridor</th><th>Entry Zone</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($monitors)): ?>
                        <tr><td colspan="4" class="text-center text-secondary py-4">No monitors</td></tr>
                    <?php else: ?>
                        <?php foreach ($monitors as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)($row['symbol'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($row['corridor_low'] ?? '')) ?> → <?= htmlspecialchars((string)($row['corridor_high'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($row['entry_zone_low'] ?? '')) ?> → <?= htmlspecialchars((string)($row['entry_zone_high'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($row['status'] ?? '')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Simulator Section -->
    <div class="row">
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-hourglass-split me-1"></i> Waiting (<?= count($waiting) ?>)</h5></div>
                <div class="card-body p-0">
                    <table class="table table-dark table-hover mb-0">
                        <thead><tr><th>Symbol</th><th>Budget</th><th>Lev</th></tr></thead>
                        <tbody>
                            <?php if (empty($waiting)): ?>
                                <tr><td colspan="3" class="text-center text-secondary py-3">—</td></tr>
                            <?php else: ?>
                                <?php foreach ($waiting as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)($row['symbol'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($row['budget'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($row['leverage'] ?? '')) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-lightning me-1 text-warning"></i> Active (<?= count($active) ?>)</h5></div>
                <div class="card-body p-0">
                    <table class="table table-dark table-hover mb-0">
                        <thead><tr><th>Symbol</th><th>Budget</th><th>Lev</th></tr></thead>
                        <tbody>
                            <?php if (empty($active)): ?>
                                <tr><td colspan="3" class="text-center text-secondary py-3">—</td></tr>
                            <?php else: ?>
                                <?php foreach ($active as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)($row['symbol'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($row['budget'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($row['leverage'] ?? '')) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-check-circle me-1 text-success"></i> Closed (<?= count($closed) ?>)</h5></div>
                <div class="card-body p-0">
                    <table class="table table-dark table-hover mb-0">
                        <thead><tr><th>Symbol</th><th>Budget</th><th>Lev</th></tr></thead>
                        <tbody>
                            <?php if (empty($closed)): ?>
                                <tr><td colspan="3" class="text-center text-secondary py-3">—</td></tr>
                            <?php else: ?>
                                <?php foreach ($closed as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)($row['symbol'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($row['budget'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($row['leverage'] ?? '')) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php
};

require __DIR__ . '/_layout.php';
