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
            <?php if ($liveEnabled): ?>
            <div class="alert alert-success small mb-0 mt-2 py-1 px-2">
                <i class="bi bi-shield-check me-1"></i> <strong>Brain-Controlled Live Mode:</strong> Active — bot will only execute Brain-approved intents. Legacy fallback disabled.
                <?php if ($liveIntentsCount === 0 && $liveApproved === 0): ?>
                <br><i class="bi bi-info-circle me-1"></i> Zero approved intents — no live execution expected this run.
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- P1: Trading Bot Execution Mirror -->
    <?php
        $botMirror = $bot_execution_mirror ?? [];
        $botAvailable = (bool)($botMirror['available'] ?? false);
        $botError = (string)($botMirror['error'] ?? '');
    ?>
    <div class="card mb-4" style="border-color: <?= $botAvailable ? '#3b82f6' : '#6b7280' ?>;">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 style="margin: 0;"><i class="bi bi-robot me-1"></i> Trading Bot Execution Mirror</h5>
            <?php if ($botAvailable): ?>
                <?php if ($botMirror['bot_controlled_by_brain'] ?? false): ?>
                <span class="badge bg-success">Brain-Controlled</span>
                <?php else: ?>
                <span class="badge bg-warning text-dark">Legacy Mode</span>
                <?php endif; ?>
            <?php else: ?>
            <span class="badge bg-secondary">Unavailable</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (!$botAvailable): ?>
            <div class="text-secondary small"><i class="bi bi-info-circle me-1"></i>
                Bot runtime unavailable<?= $botError !== '' ? ': ' . htmlspecialchars($botError) : '' ?>
            </div>
            <?php else: ?>
            <div class="row text-center">
                <div class="col-md-2">
                    <small class="text-secondary d-block">Input Source</small>
                    <strong><?= htmlspecialchars((string)($botMirror['bot_input_source'] ?? '-')) ?></strong>
                </div>
                <div class="col-md-2">
                    <small class="text-secondary d-block">Intents Loaded</small>
                    <strong><?= (int)($botMirror['approved_intents_loaded'] ?? 0) ?></strong>
                </div>
                <div class="col-md-1">
                    <small class="text-secondary d-block">Dupes</small>
                    <strong class="text-secondary"><?= (int)($botMirror['duplicate_skipped'] ?? 0) ?></strong>
                </div>
                <div class="col-md-1">
                    <small class="text-secondary d-block">Exch. Try</small>
                    <strong class="text-info"><?= (int)($botMirror['exchange_submit_attempted_count'] ?? 0) ?></strong>
                </div>
                <div class="col-md-2">
                    <small class="text-secondary d-block">Opened</small>
                    <strong class="text-success"><?= (int)($botMirror['intents_opened'] ?? 0) ?></strong>
                </div>
                <div class="col-md-2">
                    <small class="text-secondary d-block">Rejected</small>
                    <strong class="text-danger"><?= (int)($botMirror['intents_rejected'] ?? 0) ?></strong>
                </div>
                <div class="col-md-2">
                    <small class="text-secondary d-block">Failed / Deferred</small>
                    <strong class="text-danger"><?= (int)($botMirror['intents_failed'] ?? 0) ?></strong>
                    <strong class="text-warning"> / <?= (int)($botMirror['intents_deferred'] ?? 0) ?></strong>
                </div>
            </div>
            <?php
                $botRejReasons = (array)($botMirror['rejection_reason_stats'] ?? []);
                $botSourceError = (string)($botMirror['source_error_message'] ?? '');
                $botTopBlocker = (string)($botMirror['top_rejection_reason'] ?? '');
                $botTopExecBlock = (string)($botMirror['top_execution_block_reason'] ?? '');
                $botExchErrCode = $botMirror['latest_exchange_error_code'] ?? null;
                $botExchErrMsg = (string)($botMirror['latest_exchange_error_message'] ?? '');
                $botNoOrderPreview = is_array($botMirror['no_order_path_preview'] ?? null) ? $botMirror['no_order_path_preview'] : [];
            ?>
            <?php if ($botTopBlocker !== '' || $botTopExecBlock !== ''): ?>
            <div class="mt-2 small">
                <?php if ($botTopBlocker !== ''): ?>
                <span class="badge bg-danger bg-opacity-25 text-danger me-1"><i class="bi bi-exclamation-triangle me-1"></i>Top Blocker: <?= htmlspecialchars($botTopBlocker) ?></span>
                <?php endif; ?>
                <?php if ($botTopExecBlock !== ''): ?>
                <span class="badge bg-warning bg-opacity-25 text-warning me-1"><i class="bi bi-shield-exclamation me-1"></i>Guard: <?= htmlspecialchars($botTopExecBlock) ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($botExchErrCode !== null || $botExchErrMsg !== ''): ?>
            <div class="mt-1 small text-danger">
                <i class="bi bi-exclamation-octagon me-1"></i>
                <strong>Exchange Error:</strong>
                <?= $botExchErrCode !== null ? 'code=' . htmlspecialchars((string)$botExchErrCode) : '' ?>
                <?= $botExchErrMsg !== '' ? ' — ' . htmlspecialchars($botExchErrMsg) : '' ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($botRejReasons)): ?>
            <div class="mt-2 small">
                <strong class="text-danger">Rejection Reasons:</strong>
                <?php foreach ($botRejReasons as $reason => $cnt): ?>
                <span class="badge bg-danger bg-opacity-25 text-danger me-1"><?= htmlspecialchars((string)$reason) ?>: <?= (int)$cnt ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($botSourceError !== ''): ?>
            <div class="mt-1 text-danger small"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($botSourceError) ?></div>
            <?php endif; ?>
            <?php if (!empty($botNoOrderPreview)): ?>
            <details class="mt-2" style="font-size: 0.78rem;">
                <summary class="text-warning"><i class="bi bi-bug me-1"></i>No-Order Debug (<?= count($botNoOrderPreview) ?> intents)</summary>
                <?php foreach ($botNoOrderPreview as $nop): ?>
                <div class="ps-3 text-muted"><?= htmlspecialchars((string)($nop['symbol'] ?? '')) ?> → <span class="text-danger"><?= htmlspecialchars((string)($nop['final_outcome'] ?? '')) ?></span> @ <?= htmlspecialchars((string)($nop['execution_stage'] ?? '')) ?> — <?= htmlspecialchars(substr((string)($nop['main_reason'] ?? ''), 0, 60)) ?> <?= !empty($nop['exchange_attempted']) ? '(exch: ✓)' : '(exch: ✗)' ?></div>
                <?php endforeach; ?>
            </details>
            <?php endif; ?>
            <?php if ($botMirror['bot_last_updated_at'] ?? ''): ?>
            <div class="mt-1 text-secondary" style="font-size:0.75rem;">Bot last run: <?= htmlspecialchars((string)$botMirror['bot_last_updated_at']) ?></div>
            <?php endif; ?>
            <div class="mt-2">
                <a href="/admin/trading_bot" class="btn btn-outline-primary btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i>Trading Bot Dashboard</a>
                <a href="/admin/trading_bot/intents" class="btn btn-outline-secondary btn-sm ms-1"><i class="bi bi-list-task me-1"></i>Bot Intents</a>
            </div>
            <?php endif; ?>
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
