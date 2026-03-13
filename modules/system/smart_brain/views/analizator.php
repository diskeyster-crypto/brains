<?php
/**
 * Smart Brain Module - Analizator View
 * 
 * Displays analysis data: candidates, signals, and monitor details.
 */

/** @var string $smartBrainUrl */
/** @var array<int,array<string,mixed>> $candidates */
/** @var array<int,array<string,mixed>> $signals */
/** @var array<int,array<string,mixed>> $monitors */
/** @var array<string,mixed> $last_run */

$pageTitle = 'Smart Brain - Analizator';
$activeTab = 'analizator';

$pageContent = function() use ($candidates, $signals, $monitors, $last_run, $smartBrainUrl) {
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-graph-up me-2 text-primary"></i>Analizator</h4>
            <p class="text-secondary mb-0">Analysis pipeline: Candidates → Monitors → Signals</p>
        </div>
        <div>
            <span class="badge bg-primary fs-6"><?= count($candidates) ?> Candidates</span>
            <span class="badge bg-warning fs-6 ms-1"><?= count($monitors) ?> Monitors</span>
            <span class="badge bg-success fs-6 ms-1"><?= count($signals) ?> Signals</span>
        </div>
    </div>

    <!-- Pipeline Summary -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 style="margin: 0;"><i class="bi bi-diagram-3 me-1"></i> Pipeline Overview</h5>
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col">
                    <div style="padding:12px; border-radius:8px; background:rgba(59,130,246,0.1);">
                        <h3 style="color:var(--primary); margin:0;"><?= count($candidates) ?></h3>
                        <small class="text-secondary">Parser4 Candidates</small>
                    </div>
                </div>
                <div class="col-auto d-flex align-items-center"><i class="bi bi-arrow-right text-secondary fs-4"></i></div>
                <div class="col">
                    <div style="padding:12px; border-radius:8px; background:rgba(245,158,11,0.1);">
                        <h3 style="color:#f59e0b; margin:0;"><?= count($monitors) ?></h3>
                        <small class="text-secondary">Corridor Monitors</small>
                    </div>
                </div>
                <div class="col-auto d-flex align-items-center"><i class="bi bi-arrow-right text-secondary fs-4"></i></div>
                <div class="col">
                    <div style="padding:12px; border-radius:8px; background:rgba(16,185,129,0.1);">
                        <h3 style="color:#10b981; margin:0;"><?= count($signals) ?></h3>
                        <small class="text-secondary">Signals Built</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Candidates Table -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 style="margin: 0;"><i class="bi bi-search me-1"></i> Candidates</h5>
            <span class="badge bg-primary"><?= count($candidates) ?></span>
        </div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr><th>#</th><th>Symbol</th><th>Strength</th><th>Direction</th><th>Price</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($candidates)): ?>
                        <tr><td colspan="5" class="text-center text-secondary py-4">No candidates in last cycle</td></tr>
                    <?php else: ?>
                        <?php foreach ($candidates as $i => $c): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong><?= htmlspecialchars((string)($c['symbol'] ?? '')) ?></strong></td>
                            <td>
                                <?php $str = (float)($c['strength'] ?? 0); ?>
                                <span class="badge <?= $str >= 0.7 ? 'bg-success' : ($str >= 0.5 ? 'bg-warning' : 'bg-secondary') ?>">
                                    <?= number_format($str, 2) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars((string)($c['direction'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($c['price'] ?? '-')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Signals Table -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 style="margin: 0;"><i class="bi bi-broadcast me-1"></i> Signals</h5>
            <span class="badge bg-success"><?= count($signals) ?></span>
        </div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr><th>#</th><th>Symbol</th><th>Direction</th><th>Budget</th><th>Leverage</th><th>Entry Zone</th><th>Stop Loss</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($signals)): ?>
                        <tr><td colspan="7" class="text-center text-secondary py-4">No signals generated</td></tr>
                    <?php else: ?>
                        <?php foreach ($signals as $i => $s): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong><?= htmlspecialchars((string)($s['symbol'] ?? '')) ?></strong></td>
                            <td><?= htmlspecialchars((string)($s['direction'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($s['budget'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($s['leverage'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($s['entry_zone_low'] ?? '')) ?> → <?= htmlspecialchars((string)($s['entry_zone_high'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($s['stop_loss'] ?? '-')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Monitors Detail Table -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 style="margin: 0;"><i class="bi bi-binoculars me-1"></i> Monitors (detailed)</h5>
            <span class="badge bg-warning"><?= count($monitors) ?></span>
        </div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr><th>#</th><th>Symbol</th><th>Corridor Low</th><th>Corridor High</th><th>Entry Low</th><th>Entry High</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($monitors)): ?>
                        <tr><td colspan="7" class="text-center text-secondary py-4">No monitors</td></tr>
                    <?php else: ?>
                        <?php foreach ($monitors as $i => $m): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong><?= htmlspecialchars((string)($m['symbol'] ?? '')) ?></strong></td>
                            <td><?= htmlspecialchars((string)($m['corridor_low'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($m['corridor_high'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($m['entry_zone_low'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($m['entry_zone_high'] ?? '')) ?></td>
                            <td>
                                <?php $st = (string)($m['status'] ?? ''); ?>
                                <span class="badge <?= $st === 'active' ? 'bg-success' : ($st === 'waiting' ? 'bg-warning' : 'bg-secondary') ?>">
                                    <?= htmlspecialchars($st ?: '-') ?>
                                </span>
                            </td>
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
