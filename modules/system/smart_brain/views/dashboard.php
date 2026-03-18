<?php
/**
 * Smart Brain Module - Dashboard View
 * 
 * Phase 1: Stable visual interface.
 * Phase 8: Real metrics display.
 * Cards: Candidates, Monitors, Signals, Simulator Positions, Metrics
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
/** @var array<string,mixed> $stats */
/** @var list<string> $config_warnings */

$pageTitle = 'Smart Brain - Dashboard';
$activeTab = 'dashboard';

$config_warnings = $config_warnings ?? [];

$extraStyles = '
.status-monitoring { background: rgba(59,130,246,0.15); color: #60a5fa; }
.status-entry_zone { background: rgba(245,158,11,0.15); color: #fbbf24; }
.status-triggered { background: rgba(16,185,129,0.15); color: #34d399; }
.status-invalidated { background: rgba(239,68,68,0.15); color: #f87171; }
.status-waiting { background: rgba(148,163,184,0.15); color: #94a3b8; }
';

$pageContent = function() use ($last_run, $signals, $monitors, $waiting, $active, $closed, $stats, $smartBrainUrl, $config_warnings) {
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
        <div class="d-flex align-items-center gap-3">
            <small class="text-secondary">Last update: <?= htmlspecialchars((string)($last_run['updated_at'] ?? '-')) ?></small>
            <button id="btn-run-now" class="btn btn-primary btn-sm" onclick="runSmartBrain()">
                <i class="bi bi-play-fill me-1"></i> Run now
            </button>
        </div>
    </div>

    <!-- Runtime Status Card -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row text-center">
                <div class="col-md-2">
                    <small class="text-secondary d-block">Status</small>
                    <?php
                        $runStatus = (string)($last_run['status'] ?? 'unknown');
                        $statusColor = match($runStatus) {
                            'ok' => '#10b981',
                            'error' => '#ef4444',
                            'skipped' => '#f59e0b',
                            default => '#94a3b8',
                        };
                    ?>
                    <strong style="color: <?= $statusColor ?>;"><?= htmlspecialchars($runStatus) ?></strong>
                </div>
                <div class="col-md-2">
                    <small class="text-secondary d-block">Source</small>
                    <strong><?= htmlspecialchars((string)($last_run['source'] ?? '-')) ?></strong>
                </div>
                <div class="col-md-2">
                    <small class="text-secondary d-block">Duration</small>
                    <strong><?= isset($last_run['duration_ms']) ? htmlspecialchars((string)$last_run['duration_ms']) . ' ms' : '-' ?></strong>
                </div>
                <div class="col-md-2">
                    <small class="text-secondary d-block">Candidates</small>
                    <strong><?= htmlspecialchars((string)($last_run['candidates'] ?? '0')) ?></strong>
                </div>
                <div class="col-md-2">
                    <small class="text-secondary d-block">Monitors</small>
                    <strong><?= htmlspecialchars((string)($last_run['monitors'] ?? '0')) ?></strong>
                </div>
                <div class="col-md-2">
                    <small class="text-secondary d-block">Signals</small>
                    <strong><?= htmlspecialchars((string)($last_run['signals'] ?? '0')) ?></strong>
                </div>
            </div>
            <?php $errMsg = (string)($last_run['error_message'] ?? ''); if ($errMsg !== ''): ?>
            <div class="mt-2 text-danger"><small><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($errMsg) ?></small></div>
            <?php endif; ?>
            <?php
                // Config Conflict Guard: deduplicated warnings from last_run + current config
                $conflictDetected = (bool)($last_run['config_conflict_detected'] ?? false);
                $conflictMsg = (string)($last_run['config_conflict_message'] ?? '');
                $dashWarnings = [];
                if ($conflictDetected && $conflictMsg !== '') {
                    $dashWarnings[] = $conflictMsg;
                }
                foreach ($config_warnings as $cw) {
                    $dashWarnings[] = $cw;
                }
                // Deduplicate
                $dashSeen = [];
                $dashUniqueWarnings = [];
                foreach ($dashWarnings as $dw) {
                    $dwKey = mb_strtolower(trim($dw));
                    if (!isset($dashSeen[$dwKey])) {
                        $dashSeen[$dwKey] = true;
                        $dashUniqueWarnings[] = $dw;
                    }
                }
                foreach ($dashUniqueWarnings as $duw):
            ?>
            <div class="mt-2 alert alert-warning mb-0 py-1 px-2" style="font-size:0.85rem;">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <?= htmlspecialchars($duw) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Live Trading Status Card -->
    <?php
        $liveEnabled = (bool)($last_run['live_trading_enabled'] ?? false);
        $liveMode = (string)($last_run['live_signal_selection_mode'] ?? 'n/a');
        $liveApproved = (int)($last_run['live_candidates_approved_count'] ?? 0);
        $liveRejected = (int)($last_run['live_candidates_rejected_count'] ?? 0);
        $liveIntentsCount = (int)($last_run['live_intents_created_count'] ?? 0);
    ?>
    <div class="card mb-4" style="border-color: <?= $liveEnabled ? '#22c55e' : '#6b7280' ?>;">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 style="margin: 0;"><i class="bi bi-lightning-charge me-1"></i> Live Trading</h5>
            <?php if ($liveEnabled): ?>
            <span class="badge bg-success">ENABLED — <?= htmlspecialchars($liveMode) ?></span>
            <?php else: ?>
            <span class="badge bg-secondary">DISABLED</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3"><small class="text-secondary d-block">Selection Mode</small><strong><?= htmlspecialchars($liveMode) ?></strong></div>
                <div class="col-md-2"><small class="text-secondary d-block">Approved</small><strong class="text-success"><?= $liveApproved ?></strong></div>
                <div class="col-md-2"><small class="text-secondary d-block">Rejected</small><strong class="text-danger"><?= $liveRejected ?></strong></div>
                <div class="col-md-2"><small class="text-secondary d-block">Live Intents</small><strong class="text-warning"><?= $liveIntentsCount ?></strong></div>
                <div class="col-md-3"><small class="text-secondary d-block">Status</small><strong><?= $liveEnabled ? '<span class="text-success">Active</span>' : '<span class="text-secondary">Inactive</span>' ?></strong></div>
            </div>
        </div>
    </div>

    <!-- Signal Diagnostics Card (Phase B) -->
    <?php
        $signalCount = (int)($last_run['signals'] ?? 0);
        $monitoringCnt = (int)($last_run['monitoring_count'] ?? 0);
        $entryZoneCnt = (int)($last_run['entry_zone_count'] ?? 0);
        $triggeredCnt = (int)($last_run['triggered_count'] ?? 0);
        $invalidatedCnt = (int)($last_run['invalidated_count'] ?? 0);
        $rejNotEntry = (int)($last_run['rejected_not_entry_zone'] ?? 0);
        $rejLowRel = (int)($last_run['rejected_low_reliability'] ?? 0);
        $rejMissPass = (int)($last_run['rejected_missing_passport'] ?? 0);
        $rejMissPrice = (int)($last_run['rejected_missing_price'] ?? 0);
        $bootstrapSig = (int)($last_run['bootstrap_signals_count'] ?? 0);
        $normalSig = (int)($last_run['normal_signals_count'] ?? 0);
        $warmupSym = (int)($last_run['warmup_symbols_count'] ?? 0);
    ?>
    <div class="card mb-4" style="border-color: <?= $signalCount === 0 ? '#f59e0b' : '#10b981' ?>;">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 style="margin: 0;"><i class="bi bi-bug me-1"></i> Signal Diagnostics</h5>
            <?php if ($signalCount === 0): ?>
            <span class="badge bg-warning text-dark">0 signals — see reasons below</span>
            <?php else: ?>
            <span class="badge bg-success"><?= $signalCount ?> signals generated</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="row">
                <!-- Monitor Status Distribution -->
                <div class="col-md-4">
                    <h6 class="text-secondary mb-2"><i class="bi bi-pie-chart me-1"></i>Monitor Status Distribution</h6>
                    <table class="table table-sm table-dark mb-0" style="font-size:0.9rem;">
                        <tr><td><span class="badge status-monitoring">monitoring</span></td><td class="text-end"><strong><?= $monitoringCnt ?></strong></td></tr>
                        <tr><td><span class="badge status-entry_zone">entry_zone</span></td><td class="text-end"><strong><?= $entryZoneCnt ?></strong></td></tr>
                        <tr><td><span class="badge status-triggered">triggered</span></td><td class="text-end"><strong><?= $triggeredCnt ?></strong></td></tr>
                        <tr><td><span class="badge status-invalidated">invalidated</span></td><td class="text-end"><strong><?= $invalidatedCnt ?></strong></td></tr>
                    </table>
                </div>
                <!-- Rejection Counters -->
                <div class="col-md-4">
                    <h6 class="text-secondary mb-2"><i class="bi bi-funnel me-1"></i>Rejection Counters</h6>
                    <table class="table table-sm table-dark mb-0" style="font-size:0.9rem;">
                        <tr><td>Not in entry_zone</td><td class="text-end"><strong><?= $rejNotEntry ?></strong></td></tr>
                        <tr><td>Low reliability</td><td class="text-end"><strong><?= $rejLowRel ?></strong></td></tr>
                        <tr><td>Missing passport</td><td class="text-end"><strong><?= $rejMissPass ?></strong></td></tr>
                        <tr><td>Missing price</td><td class="text-end"><strong><?= $rejMissPrice ?></strong></td></tr>
                    </table>
                </div>
                <!-- Signal Mode Breakdown -->
                <div class="col-md-4">
                    <h6 class="text-secondary mb-2"><i class="bi bi-lightning me-1"></i>Signal Mode</h6>
                    <table class="table table-sm table-dark mb-0" style="font-size:0.9rem;">
                        <tr><td><span class="badge bg-info">bootstrap</span></td><td class="text-end"><strong><?= $bootstrapSig ?></strong></td></tr>
                        <tr><td><span class="badge bg-success">normal</span></td><td class="text-end"><strong><?= $normalSig ?></strong></td></tr>
                        <tr><td>Warmup symbols</td><td class="text-end"><strong><?= $warmupSym ?></strong></td></tr>
                    </table>
                </div>
            </div>
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

    <!-- Metrics Cards (Phase 8) -->
    <?php if (!empty($stats)): ?>
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card">
                <div class="card-body text-center">
                    <h3 style="font-size: 1.6rem; font-weight: bold; color: #10b981;"><?= htmlspecialchars((string)($stats['total_trades'] ?? '0')) ?></h3>
                    <p style="margin: 0; color: #94a3b8; font-size: 0.85rem;">Total Trades</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card">
                <div class="card-body text-center">
                    <?php $wr = (float)($stats['winrate'] ?? 0); ?>
                    <h3 style="font-size: 1.6rem; font-weight: bold; color: <?= $wr >= 0.7 ? '#10b981' : ($wr >= 0.5 ? '#f59e0b' : '#ef4444') ?>;"><?= number_format($wr * 100, 1) ?>%</h3>
                    <p style="margin: 0; color: #94a3b8; font-size: 0.85rem;">Winrate</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card">
                <div class="card-body text-center">
                    <?php $avgRoi = (float)($stats['average_roi'] ?? 0); ?>
                    <h3 style="font-size: 1.6rem; font-weight: bold; color: <?= $avgRoi >= 0 ? '#10b981' : '#ef4444' ?>;"><?= number_format($avgRoi * 100, 2) ?>%</h3>
                    <p style="margin: 0; color: #94a3b8; font-size: 0.85rem;">Avg ROI</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card">
                <div class="card-body text-center">
                    <?php $conv = (float)($stats['signal_to_entry_conversion'] ?? 0); ?>
                    <h3 style="font-size: 1.6rem; font-weight: bold; color: #8b5cf6;"><?= number_format($conv * 100, 1) ?>%</h3>
                    <p style="margin: 0; color: #94a3b8; font-size: 0.85rem;">Conversion</p>
                </div>
            </div>
        </div>
        <div class="col-md-1">
            <div class="card">
                <div class="card-body text-center">
                    <h3 style="font-size: 1.6rem; font-weight: bold; color: #94a3b8;"><?= count($waiting) ?></h3>
                    <p style="margin: 0; color: #94a3b8; font-size: 0.85rem;">Waiting</p>
                </div>
            </div>
        </div>
        <div class="col-md-1">
            <div class="card">
                <div class="card-body text-center">
                    <h3 style="font-size: 1.6rem; font-weight: bold; color: #f59e0b;"><?= count($active) ?></h3>
                    <p style="margin: 0; color: #94a3b8; font-size: 0.85rem;">Active</p>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card">
                <div class="card-body text-center">
                    <h3 style="font-size: 1.6rem; font-weight: bold; color: #10b981;"><?= count($closed) ?></h3>
                    <p style="margin: 0; color: #94a3b8; font-size: 0.85rem;">Closed</p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Monitors Table -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 style="margin: 0;"><i class="bi bi-binoculars me-1"></i> Monitors (<?= count($monitors) ?>)</h5>
        </div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr><th>Symbol</th><th>Corridor</th><th>Width</th><th>Entry Zone</th><th>Price Pos</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($monitors)): ?>
                        <tr><td colspan="6" class="text-center text-secondary py-4">No monitors</td></tr>
                    <?php else: ?>
                        <?php foreach ($monitors as $row): ?>
                        <?php $st = (string)($row['status'] ?? 'waiting'); ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string)($row['symbol'] ?? '')) ?></strong></td>
                            <td><?= htmlspecialchars((string)($row['corridor_low'] ?? '')) ?> → <?= htmlspecialchars((string)($row['corridor_high'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($row['corridor_width'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['entry_zone_low'] ?? '')) ?> → <?= htmlspecialchars((string)($row['entry_zone_high'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($row['price_position'] ?? '-')) ?></td>
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
                <thead><tr><th>Symbol</th><th>Entry Price</th><th>Current Price</th><th>ROI</th><th>MAE</th><th>MFE</th><th>Leverage</th><th>SL</th><th>TP</th></tr></thead>
                <tbody>
                    <?php if (empty($active)): ?>
                        <tr><td colspan="9" class="text-center text-secondary py-3">—</td></tr>
                    <?php else: ?>
                        <?php foreach ($active as $row): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string)($row['symbol'] ?? '')) ?></strong></td>
                            <td><?= htmlspecialchars((string)($row['entry_price'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['current_price'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['roi'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['mae'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['mfe'] ?? '-')) ?></td>
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
                <thead><tr><th>Symbol</th><th>Entry</th><th>Exit</th><th>ROI</th><th>MAE</th><th>MFE</th><th>Reason</th><th>Duration</th></tr></thead>
                <tbody>
                    <?php if (empty($closed)): ?>
                        <tr><td colspan="8" class="text-center text-secondary py-3">—</td></tr>
                    <?php else: ?>
                        <?php foreach ($closed as $row): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string)($row['symbol'] ?? '')) ?></strong></td>
                            <td><?= htmlspecialchars((string)($row['entry_price'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['exit_price'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['roi'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['mae'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['mfe'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['reason'] ?? '-')) ?></td>
                            <td><?= ($row['duration'] ?? null) !== null ? htmlspecialchars((string)$row['duration']) . ' min' : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php
};

$extraScripts = '
<script>
var smartBrainRunUrl = ' . json_encode($smartBrainUrl . '/run') . ';
function runSmartBrain() {
    var btn = document.getElementById("btn-run-now");
    btn.disabled = true;
    btn.innerHTML = "<i class=\"bi bi-arrow-repeat me-1 spin\"></i> Running...";

    fetch(smartBrainRunUrl, { method: "POST" })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = "<i class=\"bi bi-play-fill me-1\"></i> Run now";
            if (data.ok) {
                location.reload();
            } else {
                alert("Run finished with status: " + (data.status || "error") + "\\n" + (data.error_message || ""));
                location.reload();
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.innerHTML = "<i class=\"bi bi-play-fill me-1\"></i> Run now";
            alert("Request failed: " + err.message);
        });
}
</script>
<style>.spin { animation: spin 1s linear infinite; } @keyframes spin { 100% { transform: rotate(360deg); } }</style>
';

require __DIR__ . '/_layout.php';
