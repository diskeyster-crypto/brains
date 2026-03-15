<?php
/**
 * Smart Brain Module - Analyzer View
 * 
 * Phase 3: Parser4 Analyzer display.
 * Candidate fields: symbol, corridor_low, corridor_high, corridor_width, volatility, strength, trend_bias
 */

/** @var string $smartBrainUrl */
/** @var array<int,array<string,mixed>> $candidates */
/** @var array<int,array<string,mixed>> $signals */
/** @var array<int,array<string,mixed>> $monitors */
/** @var array<string,mixed> $last_run */

$pageTitle = 'Smart Brain - Analyzer';
$activeTab = 'analizator';

$extraStyles = '
.status-monitoring { background: rgba(59,130,246,0.15); color: #60a5fa; }
.status-entry_zone { background: rgba(245,158,11,0.15); color: #fbbf24; }
.status-triggered { background: rgba(16,185,129,0.15); color: #34d399; }
.status-invalidated { background: rgba(239,68,68,0.15); color: #f87171; }
.status-waiting { background: rgba(148,163,184,0.15); color: #94a3b8; }
';

$pageContent = function() use ($candidates, $signals, $monitors, $last_run, $smartBrainUrl) {
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
            <h4 class="mb-1"><i class="bi bi-graph-up me-2 text-primary"></i>Analyzer</h4>
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

    <!-- Candidates Table (Pattern-First Decision Flow) -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 style="margin: 0;"><i class="bi bi-search me-1"></i> Candidates</h5>
            <span class="badge bg-primary"><?= count($candidates) ?></span>
        </div>
        <div class="card-body p-0" style="overflow-x: auto;">
            <table class="table table-dark table-hover mb-0" style="font-size: 0.85rem;">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Symbol</th>
                        <th>Pattern</th>
                        <th>Pat.Conf</th>
                        <th>Trend Match</th>
                        <th>Corr.Fit</th>
                        <th>Entry Q.</th>
                        <th>Score</th>
                        <th>Pass</th>
                        <th>Trend</th>
                        <th>Corr.Low</th>
                        <th>Corr.High</th>
                        <th>Width</th>
                        <th>Vol</th>
                        <th>Str</th>
                        <th>Pts</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($candidates)): ?>
                        <tr><td colspan="16" class="text-center text-secondary py-4">No candidates in last cycle</td></tr>
                    <?php else: ?>
                        <?php foreach ($candidates as $i => $c): ?>
                        <?php
                            $algo = (string)($c['pattern_algorithm'] ?? 'none');
                            $conf = (float)($c['pattern_confidence'] ?? 0);
                            $tms = (float)($c['trend_match_score'] ?? 0);
                            $cfs = (float)($c['corridor_fit_score'] ?? 0);
                            $eqs = (float)($c['entry_quality_score'] ?? 0);
                            $as = (float)($c['analyzer_score'] ?? 0);
                            $ap = (bool)($c['analyzer_pass'] ?? false);
                            $str = (float)($c['strength'] ?? 0);
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong><?= htmlspecialchars((string)($c['symbol'] ?? '')) ?></strong></td>
                            <td>
                                <span class="badge <?= $algo !== 'none' ? 'bg-info' : 'bg-secondary' ?>">
                                    <?= htmlspecialchars($algo) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $conf >= 0.7 ? 'bg-success' : ($conf >= 0.4 ? 'bg-warning' : 'bg-secondary') ?>">
                                    <?= number_format($conf, 2) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $tms >= 0.7 ? 'bg-success' : ($tms >= 0.4 ? 'bg-warning' : 'bg-danger') ?>">
                                    <?= number_format($tms, 2) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $cfs >= 0.7 ? 'bg-success' : ($cfs >= 0.4 ? 'bg-warning' : 'bg-danger') ?>">
                                    <?= number_format($cfs, 2) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $eqs >= 0.7 ? 'bg-success' : ($eqs >= 0.4 ? 'bg-warning' : 'bg-danger') ?>">
                                    <?= number_format($eqs, 2) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $as >= 0.75 ? 'bg-success' : ($as >= 0.65 ? 'bg-warning' : 'bg-danger') ?>">
                                    <?= number_format($as, 4) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $ap ? 'bg-success' : 'bg-danger' ?>">
                                    <?= $ap ? '✓' : '✗' ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars((string)($c['trend_bias'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($c['corridor_low'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($c['corridor_high'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($c['corridor_width'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($c['volatility'] ?? '-')) ?></td>
                            <td>
                                <span class="badge <?= $str >= 0.7 ? 'bg-success' : ($str >= 0.5 ? 'bg-warning' : 'bg-secondary') ?>">
                                    <?= number_format($str, 2) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars((string)($c['history_points'] ?? '-')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Monitors Table -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 style="margin: 0;"><i class="bi bi-binoculars me-1"></i> Monitors</h5>
            <span class="badge bg-warning"><?= count($monitors) ?></span>
        </div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr><th>#</th><th>Symbol</th><th>Pattern</th><th>Corridor Low</th><th>Corridor High</th><th>Width</th><th>Entry Low</th><th>Entry High</th><th>Price Pos</th><th>Status</th><th>Rejection Reason</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($monitors)): ?>
                        <tr><td colspan="11" class="text-center text-secondary py-4">No monitors</td></tr>
                    <?php else: ?>
                        <?php foreach ($monitors as $i => $m): ?>
                        <?php
                            $st = (string)($m['status'] ?? 'waiting');
                            // Compute rejection reason for display
                            $rejReason = '';
                            if ($st !== 'entry_zone') {
                                $rejReason = 'status=' . $st;
                            }
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong><?= htmlspecialchars((string)($m['symbol'] ?? '')) ?></strong></td>
                            <td><span class="badge bg-info"><?= htmlspecialchars((string)($m['pattern_algorithm'] ?? 'none')) ?></span></td>
                            <td><?= htmlspecialchars((string)($m['corridor_low'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($m['corridor_high'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($m['corridor_width'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($m['entry_zone_low'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($m['entry_zone_high'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($m['price_position'] ?? '-')) ?></td>
                            <td><span class="badge <?= $statusClass($st) ?>"><?= htmlspecialchars($st) ?></span></td>
                            <td><small class="text-secondary"><?= htmlspecialchars($rejReason) ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Signals Table (Phase 7 fields) -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 style="margin: 0;"><i class="bi bi-broadcast me-1"></i> Signals</h5>
            <span class="badge bg-success"><?= count($signals) ?></span>
        </div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr><th>#</th><th>Symbol</th><th>Entry Zone</th><th>Corridor</th><th>Leverage</th><th>Budget</th><th>Stop Loss</th><th>Take Profit</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($signals)): ?>
                        <tr><td colspan="9" class="text-center text-secondary py-4">No signals generated</td></tr>
                    <?php else: ?>
                        <?php foreach ($signals as $i => $s): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong><?= htmlspecialchars((string)($s['symbol'] ?? '')) ?></strong></td>
                            <td><?= htmlspecialchars((string)($s['entry_zone_low'] ?? '')) ?> → <?= htmlspecialchars((string)($s['entry_zone_high'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($s['corridor_low'] ?? '')) ?> → <?= htmlspecialchars((string)($s['corridor_high'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($s['leverage'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($s['budget'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($s['stop_loss'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($s['take_profit'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($s['status'] ?? '-')) ?></td>
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
