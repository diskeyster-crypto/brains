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
                <div class="col-md-1">
                    <small class="text-secondary d-block">Source</small>
                    <strong><?= htmlspecialchars((string)($last_run['source'] ?? '-')) ?></strong>
                </div>
                <div class="col-md-2">
                    <small class="text-secondary d-block">Duration</small>
                    <strong><?= isset($last_run['duration_ms']) ? htmlspecialchars((string)$last_run['duration_ms']) . ' ms' : '-' ?></strong>
                </div>
                <div class="col-md-1">
                    <small class="text-secondary d-block">Candidates</small>
                    <strong><?= htmlspecialchars((string)($last_run['candidates'] ?? '0')) ?></strong>
                </div>
                <div class="col-md-1">
                    <small class="text-secondary d-block">Monitors</small>
                    <strong><?= htmlspecialchars((string)($last_run['monitors'] ?? '0')) ?></strong>
                </div>
                <div class="col-md-1">
                    <small class="text-secondary d-block">Signals</small>
                    <strong><?= htmlspecialchars((string)($last_run['signals'] ?? '0')) ?></strong>
                </div>
                <div class="col-md-4">
                    <small class="text-secondary d-block">Execution Profile</small>
                    <?php
                        $dashProfile = (string)($last_run['execution_profile'] ?? 'custom');
                        $dashProfileLabel = (string)($last_run['execution_profile_label'] ?? 'Custom');
                        $dashProfileIsPreset = $dashProfile !== 'custom';
                        $dashPatternMode = (string)($last_run['pattern_profile_mode'] ?? 'manual_override');
                        $dashIsPatternControlled = $dashProfileIsPreset && $dashPatternMode === 'profile_controlled';
                    ?>
                    <span class="badge <?= $dashProfileIsPreset ? 'bg-primary' : 'bg-secondary' ?>"><?= htmlspecialchars($dashProfileLabel) ?></span>
                    <?php
                        $dashPatternPolicy = is_array($last_run['pattern_policy'] ?? null) ? $last_run['pattern_policy'] : null;
                        $dashPatLabels = [
                            'double_bottom_contextual_v2' => 'V2 Ctx',
                            'double_bottom_contextual_v3' => 'V3 Ctx',
                            'double_top_contextual_v2' => 'V2 Top Ctx',
                            'double_top_contextual_v3' => 'V3 Top Ctx',
                        ];
                        if ($dashPatternPolicy !== null) {
                            $dashLivePatterns = (array)($dashPatternPolicy['live_patterns'] ?? []);
                        } elseif ($dashIsPatternControlled) {
                            $dashRoutingBundles = SmartBrainConfig::getProfilePatternRoutingBundles();
                            $dashRouting = $dashRoutingBundles[$dashProfile] ?? ['live_patterns' => []];
                            $dashLivePatterns = (array)($dashRouting['live_patterns'] ?? []);
                        } else {
                            $dashLivePatterns = [];
                        }
                    ?>
                    <?php if (!empty($dashLivePatterns)): ?>
                    <small class="text-success ms-1">Live: <?= implode(', ', array_map(fn($p) => $dashPatLabels[$p] ?? $p, $dashLivePatterns)) ?></small>
                    <?php endif; ?>
                    <?php if (!empty($dashPatternPolicy['fallback_used'])): ?>
                    <small class="text-warning ms-1" title="Pattern routing fallback used"><i class="bi bi-exclamation-triangle"></i></small>
                    <?php endif; ?>
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
        $liveTerminalRetainedDash = (int)($last_run['live_terminal_retained_count'] ?? 0);
        $dashLifecycle = $last_run['lifecycle_summary'] ?? [];
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
                <div class="col-md-2"><small class="text-secondary d-block">Live Intents</small><strong class="text-warning"><?= $liveIntentsCount ?></strong><?php if ($liveTerminalRetainedDash > 0): ?><small class="text-secondary"> (+<?= $liveTerminalRetainedDash ?> retained)</small><?php endif; ?></div>
                <div class="col-md-3"><small class="text-secondary d-block">Status</small><strong><?= $liveEnabled ? '<span class="text-success">Active</span>' : '<span class="text-secondary">Inactive</span>' ?></strong></div>
            </div>
            <?php if (!empty($dashLifecycle)): ?>
            <div class="row mt-2" style="border-top: 1px solid rgba(255,255,255,0.1); padding-top: 6px;">
                <div class="col-12 mb-1"><small class="text-secondary"><i class="bi bi-arrow-repeat me-1"></i>Lifecycle</small></div>
                <div class="col"><small class="text-secondary d-block">Pending</small><strong class="text-info"><?= (int)($dashLifecycle['pending'] ?? 0) ?></strong></div>
                <div class="col"><small class="text-secondary d-block">Claimed</small><strong class="text-primary"><?= (int)($dashLifecycle['claimed'] ?? 0) ?></strong></div>
                <div class="col"><small class="text-secondary d-block">Executed</small><strong class="text-success"><?= (int)($dashLifecycle['executed'] ?? 0) ?></strong></div>
                <div class="col"><small class="text-secondary d-block">Rejected</small><strong class="text-danger"><?= (int)($dashLifecycle['rejected'] ?? 0) ?></strong></div>
                <div class="col"><small class="text-secondary d-block">Expired</small><strong class="text-secondary"><?= (int)($dashLifecycle['expired'] ?? 0) ?></strong></div>
            </div>
            <?php endif; ?>
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

    <!-- Manual Blacklist Indicator -->
    <?php
        $dashBlActive = (bool)($last_run['manual_blacklist_active'] ?? false);
        $dashBlCount = (int)($last_run['manual_blacklist_count'] ?? 0);
        $dashBlRejected = (int)($last_run['manual_blacklist_rejected_count'] ?? 0);
    ?>
    <?php if ($dashBlActive): ?>
    <div class="card mb-4" style="border-color: #dc3545;">
        <div class="card-header d-flex justify-content-between align-items-center" style="background: rgba(220,53,69,0.08);">
            <h5 style="margin: 0;"><i class="bi bi-shield-x me-1 text-danger"></i> Manual Live Blacklist</h5>
            <span class="badge bg-danger"><?= $dashBlCount ?> symbol(s)</span>
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col-md-4"><small class="text-secondary d-block">Blacklisted Symbols</small><strong class="text-danger"><?= $dashBlCount ?></strong></div>
                <div class="col-md-4"><small class="text-secondary d-block">Live Rejected (last run)</small><strong class="<?= $dashBlRejected > 0 ? 'text-warning' : 'text-secondary' ?>"><?= $dashBlRejected ?></strong></div>
                <div class="col-md-4"><small class="text-secondary d-block">Scope</small><strong class="text-info">LIVE only</strong></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Sniper V3 Live Filter Analytics -->
    <?php
        $dashIsSniperProfile = in_array($dashProfile, ['sniper_75_attempt', 'sniper_lite'], true);
        $sniperStructural = (int)($last_run['structural_v3_signal_count'] ?? 0);
        $sniperEligible = (int)($last_run['sniper_v3_live_eligible_count'] ?? 0);
        $sniperShortEligible = (int)($last_run['sniper_v3_short_live_eligible_count'] ?? 0);
        $sniperRejected = (int)($last_run['sniper_v3_live_rejected_count'] ?? 0);
        $sniperShadow = (int)($last_run['sniper_v3_shadow_only_count'] ?? 0);
        $sniperRejectDist = is_array($last_run['sniper_v3_reject_reason_distribution'] ?? null)
            ? $last_run['sniper_v3_reject_reason_distribution'] : [];
    ?>
    <?php if ($dashIsSniperProfile): ?>
    <div class="card mb-4" style="border-color: <?= $dashProfile === 'sniper_lite' ? '#06b6d4' : '#f59e0b' ?>;">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 style="margin: 0;"><i class="bi bi-crosshair me-1"></i> Sniper V3 Live Filter</h5>
            <span class="badge <?= $dashProfile === 'sniper_lite' ? 'bg-info' : 'bg-warning text-dark' ?>"><?= htmlspecialchars($dashProfileLabel) ?></span>
        </div>
        <div class="card-body">
            <div class="row text-center mb-2">
                <div class="col-md-3">
                    <small class="text-secondary d-block">Structural V3 Signals</small>
                    <strong><?= $sniperStructural ?></strong>
                </div>
                <div class="col-md-2">
                    <small class="text-secondary d-block">Live Eligible</small>
                    <strong class="text-success"><?= $sniperEligible ?></strong>
                    <?php if ($sniperShortEligible > 0): ?>
                    <small class="text-info d-block">(<?= $sniperShortEligible ?> short)</small>
                    <?php endif; ?>
                </div>
                <div class="col-md-2">
                    <small class="text-secondary d-block">Live Rejected</small>
                    <strong class="text-danger"><?= $sniperRejected ?></strong>
                </div>
                <div class="col-md-2">
                    <small class="text-secondary d-block">Shadow Only</small>
                    <strong class="text-secondary"><?= $sniperShadow ?></strong>
                </div>
                <div class="col-md-3">
                    <small class="text-secondary d-block">Pass Rate</small>
                    <strong><?= $sniperStructural > 0 ? round(($sniperEligible / $sniperStructural) * 100, 1) . '%' : '—' ?></strong>
                </div>
            </div>
            <?php if ($sniperStructural === 0 && $sniperEligible === 0): ?>
            <div class="alert alert-secondary small mb-0 py-1 px-2">
                <i class="bi bi-info-circle me-1"></i>
                No structural V3 signals this cycle. This is normal — V3 detection may not produce signals every run.
            </div>
            <?php endif; ?>
            <?php if (!empty($sniperRejectDist)): ?>
            <hr class="my-2">
            <small class="text-secondary d-block mb-1">Top Reject Reasons</small>
            <div class="d-flex flex-wrap gap-1">
                <?php
                    arsort($sniperRejectDist);
                    foreach (array_slice($sniperRejectDist, 0, 8, true) as $reason => $cnt):
                ?>
                <span class="badge bg-dark text-light"><?= htmlspecialchars(str_replace('sniper_reject_', '', (string)$reason)) ?>: <?= $cnt ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($dashProfile === 'sniper_lite'): ?>
            <div class="alert alert-info small mb-0 mt-2 py-1 px-2">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Sniper Lite:</strong> Allows strong <em>and selected medium</em> V3 confirmations.
                Higher signal count than strict Sniper, lower target precision.
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

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
                    <small class="text-secondary d-block">Busy</small>
                    <strong class="text-warning"><?= (int)($botMirror['busy_skipped'] ?? 0) ?></strong>
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
            <!-- Active Protection State -->
            <?php
                $activePosCount = (int)($botMirror['active_positions_count'] ?? 0);
                $protPosCount = (int)($botMirror['protected_positions_count'] ?? 0);
                $trailActCount = (int)($botMirror['trailing_active_count'] ?? 0);
                $beArmedCount = (int)($botMirror['break_even_armed_count'] ?? 0);
                $beAppliedCount = (int)($botMirror['break_even_applied_count'] ?? 0);
                $protErrCount = (int)($botMirror['protection_errors_count'] ?? 0);
            ?>
            <?php if ($activePosCount > 0): ?>
            <div class="mt-2 small">
                <strong><i class="bi bi-shield-check me-1"></i>Protection State:</strong>
                <span class="badge bg-info bg-opacity-25 text-info me-1">Active: <?= $activePosCount ?></span>
                <span class="badge bg-success bg-opacity-25 text-success me-1">Protected: <?= $protPosCount ?></span>
                <span class="badge bg-primary bg-opacity-25 text-primary me-1">Trailing: <?= $trailActCount ?></span>
                <span class="badge bg-warning bg-opacity-25 text-warning me-1">BE Armed: <?= $beArmedCount ?></span>
                <?php if ($beAppliedCount > 0): ?>
                <span class="badge bg-success bg-opacity-25 text-success me-1">BE Applied: <?= $beAppliedCount ?></span>
                <?php endif; ?>
                <?php if ($protErrCount > 0): ?>
                <span class="badge bg-danger bg-opacity-25 text-danger me-1">Errors: <?= $protErrCount ?></span>
                <?php endif; ?>
            </div>
            <?php
                // Effective Exit Contract Summary
                // Priority: bot mirror flat fields (always populated), then nested contract, then last_run
                $effectiveContract = $botMirror['effective_trailing_contract'] ?? ($last_run['effective_trailing_contract'] ?? []);
                $dashExitMode = $botMirror['effective_exit_mode'] ?? ($effectiveContract['exit_mode'] ?? null);
                $dashTrailingEnabled = $botMirror['effective_trailing_enabled'] ?? ($effectiveContract['enabled'] ?? null);
                $dashBEEnabled = $botMirror['effective_break_even_enabled'] ?? ($effectiveContract['break_even_enabled'] ?? null);
                $dashTrailingActivation = $botMirror['effective_trailing_activation'] ?? ($effectiveContract['activation_roi_pct'] ?? ($effectiveContract['trailing_activation_roi_pct'] ?? null));
                $dashBEActivation = $botMirror['effective_break_even_activation'] ?? ($effectiveContract['break_even_activation_roi'] ?? ($effectiveContract['break_even_activation_roi_pct'] ?? null));
                $dashDrawdown = $botMirror['effective_drawdown_factor'] ?? ($effectiveContract['drawdown_factor'] ?? null);
                $dashTrailingMode = $effectiveContract['trailing_mode'] ?? 'roi_giveback';
                $dashPriceDistPct = $effectiveContract['trailing_price_distance_pct'] ?? null;
                $dashHybridShare = $botMirror['effective_hybrid_tp_share'] ?? ($effectiveContract['hybrid_tp_share'] ?? null);
                $dashSource = $botMirror['effective_trailing_contract_source'] ?? 'unknown';
                $dashLegacyPresent = (bool)($effectiveContract['legacy_trailing_fields_present'] ?? false);
                $dashHasContract = ($dashExitMode !== null);
                if ($dashHasContract):
            ?>
            <div class="mt-2 small">
                <strong><i class="bi bi-arrow-right-circle me-1"></i>Active Trailing Contract</strong>
                <span class="badge bg-primary ms-1" style="font-size:0.65rem;"><?= htmlspecialchars($dashTrailingMode) ?></span>
                <?php if ($dashLegacyPresent): ?>
                <span class="badge bg-secondary ms-1" style="font-size:0.55rem;">legacy fields preserved</span>
                <?php endif; ?>
                :
                <code><?= htmlspecialchars((string)$dashExitMode) ?></code>
                | trailing: <code><?= $dashTrailingEnabled ? 'ON' : 'OFF' ?></code>
                | mode: <code><?= htmlspecialchars((string)$dashTrailingMode) ?></code>
                | activation: <code><?= htmlspecialchars((string)($dashTrailingActivation ?? 'n/a')) ?>%</code>
                <?php if ($dashTrailingMode === 'price_distance' || $dashTrailingMode === 'price_distance_floor'): ?>
                | distance: <code><?= htmlspecialchars((string)($dashPriceDistPct ?? 'n/a')) ?></code> <small class="text-info">(<?= $dashPriceDistPct !== null ? round(((float)$dashPriceDistPct) * 100, 1) : '?' ?>% from price)</small>
                <?php
                    $dashExchangeDist = $effectiveContract['exchange_trailing_distance'] ?? null;
                    $dashTheoStop = $effectiveContract['theoretical_current_stop_price'] ?? null;
                ?>
                <?php if ($dashExchangeDist !== null): ?>
                | exch.dist: <code><?= round((float)$dashExchangeDist, 4) ?></code>
                <?php endif; ?>
                <?php if ($dashTheoStop !== null): ?>
                | implied stop: <code><?= round((float)$dashTheoStop, 6) ?></code>
                <?php endif; ?>
                <?php if ($dashTrailingMode === 'price_distance_floor'): ?>
                <?php
                    $dashFloorLockActive = $effectiveContract['floor_lock_active'] ?? false;
                    $dashFloorLockedRoi = $effectiveContract['floor_locked_roi'] ?? ($effectiveContract['trailing_floor_lock_roi'] ?? null);
                    $dashFloorStopPrice = $effectiveContract['floor_stop_price'] ?? null;
                    $dashStepMode = $effectiveContract['trailing_step_mode'] ?? 'fixed';
                ?>
                | <span class="badge bg-<?= $dashFloorLockActive ? 'success' : 'secondary' ?>">floor <?= $dashFloorLockActive ? 'ON' : 'OFF' ?></span>
                <?php if ($dashFloorLockedRoi !== null): ?>
                lock: <code><?= round((float)$dashFloorLockedRoi, 1) ?>%</code>
                <?php endif; ?>
                <?php if ($dashFloorStopPrice !== null): ?>
                | floor stop: <code><?= round((float)$dashFloorStopPrice, 6) ?></code>
                <?php endif; ?>
                | step: <code><?= htmlspecialchars($dashStepMode) ?></code>
                <?php endif; ?>
                <?php else: ?>
                | drawdown: <code><?= htmlspecialchars((string)($dashDrawdown ?? 'n/a')) ?></code>
                <?php endif; ?>
                | BE: <code><?= $dashBEEnabled ? 'ON' : 'OFF' ?></code>
                <?php if ($dashBEEnabled): ?>
                @ <code><?= htmlspecialchars((string)($dashBEActivation ?? '')) ?>%</code>
                <?php endif; ?>
                <?php if ($dashExitMode === 'hybrid_tp'): ?>
                | hybrid: <code><?= $dashHybridShare !== null ? round(((float)$dashHybridShare) * 100) : 'n/a' ?>%</code> @ <code><?= htmlspecialchars((string)($effectiveContract['fixed_take_profit_roi'] ?? '')) ?></code>
                <?php endif; ?>
                | logical stop: <code><?= htmlspecialchars((string)($effectiveContract['logical_stop_roi'] ?? 'n/a')) ?></code>
                | source: <code><?= htmlspecialchars($dashSource) ?></code>
                <?php
                $dashStopControlMode = (string)($botMirror['effective_stop_control_mode'] ?? 'auto');
                $dashEntryRoi = $botMirror['effective_stop_loss_from_entry_roi'] ?? null;
                $dashStopPrice = $botMirror['effective_stop_price'] ?? null;
                ?>
                | stop: <code><?= htmlspecialchars($dashStopControlMode) ?></code>
                <?php if ($dashStopControlMode === 'entry_roi' && $dashEntryRoi !== null): ?>
                    (<code><?= round((float)$dashEntryRoi * 100, 1) ?>%</code> from entry)
                <?php endif; ?>
                <?php if ($dashStopPrice !== null): ?>
                    | stop price: <code><?= number_format((float)$dashStopPrice, 4) ?></code>
                <?php endif; ?>
                <?php
                $dashInitialStop = $botMirror['initial_computed_stop_price'] ?? null;
                $dashStopMoved = (bool)($botMirror['stop_moved_from_initial'] ?? false);
                ?>
                <?php if ($dashInitialStop !== null): ?>
                    | initial stop: <code><?= number_format((float)$dashInitialStop, 4) ?></code>
                <?php endif; ?>
                <?php if ($dashStopMoved): ?>
                    <span class="badge bg-warning text-dark" style="font-size:0.65rem;">moved</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php
            $dashProtDetails = $botMirror['active_position_protection_details'] ?? [];
            if (!empty($dashProtDetails)): ?>
            <details class="mt-1">
                <summary class="text-secondary" style="cursor:pointer;"><small>Per-Trade Protection Details (<?= count($dashProtDetails) ?>)</small></summary>
                <div class="table-responsive mt-1">
                    <table class="table table-sm table-striped mb-0" style="font-size:0.8rem;">
                        <thead><tr><th>Symbol</th><th>Side</th><th>Protection</th><th>Exit Mode</th><th>Trailing</th><th>BE</th><th>Stop Mode</th><th>Initial Stop</th><th>Current Stop</th><th>Source</th></tr></thead>
                        <tbody>
                        <?php foreach ($dashProtDetails as $dpd): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)($dpd['symbol'] ?? '')) ?></td>
                                <td><span class="badge bg-<?= ($dpd['side'] ?? '') === 'long' ? 'success' : 'danger' ?>"><?= htmlspecialchars(strtoupper((string)($dpd['side'] ?? ''))) ?></span></td>
                                <td><span class="badge bg-<?= ($dpd['protection_state'] ?? '') === 'trailing_active' ? 'success' : (($dpd['protection_state'] ?? '') === 'opened_protected' ? 'info' : 'warning') ?>"><?= htmlspecialchars((string)($dpd['protection_state'] ?? 'unknown')) ?></span></td>
                                <td><small><?= htmlspecialchars((string)($dpd['exit_mode'] ?? '')) ?></small></td>
                                <td><?= ($dpd['trailing_active'] ?? false) ? '🟢' : (($dpd['trailing_enabled'] ?? false) ? '⏳' : '⚪') ?></td>
                                <td><?= ($dpd['break_even_applied'] ?? false) ? '✅' : (($dpd['break_even_armed'] ?? false) ? '🔶' : '⚪') ?></td>
                                <td>
                                    <small><?= htmlspecialchars((string)($dpd['stop_control_mode'] ?? 'auto')) ?></small>
                                    <?php if (($dpd['stop_control_mode'] ?? 'auto') === 'entry_roi' && ($dpd['stop_loss_from_entry_roi'] ?? null) !== null): ?>
                                        <small>(<?= round((float)$dpd['stop_loss_from_entry_roi'] * 100, 1) ?>%)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $dpdInitialStop = $dpd['initial_computed_stop_price'] ?? null; ?>
                                    <?php if ($dpdInitialStop !== null): ?>
                                        <small><?= number_format((float)$dpdInitialStop, 4) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">—</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $dpdStopPrice = $dpd['effective_stop_price'] ?? null; ?>
                                    <?php if ($dpdStopPrice !== null): ?>
                                        <small><?= number_format((float)$dpdStopPrice, 4) ?></small>
                                        <?php if ($dpd['stop_moved_from_initial'] ?? false): ?>
                                            <span class="badge bg-warning text-dark" style="font-size:0.55rem;">moved</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <small class="text-muted">—</small>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= htmlspecialchars((string)($dpd['effective_trailing_contract_source'] ?? '')) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
            <?php endif; ?>
            <?php endif; ?>
            <?php
                // P6: Expectancy Metrics Display
                $expectancyMetrics = $botMirror['expectancy_metrics'] ?? [];
                $totalClosed = (int)($expectancyMetrics['total_closed'] ?? 0);
                if ($totalClosed > 0):
            ?>
            <div class="mt-2 small">
                <strong><i class="bi bi-graph-up me-1"></i>Expectancy Metrics (<?= $totalClosed ?> trades):</strong>
                <span class="badge bg-success bg-opacity-25 text-success me-1">Wins: <?= (int)($expectancyMetrics['wins'] ?? 0) ?></span>
                <span class="badge bg-danger bg-opacity-25 text-danger me-1">Losses: <?= (int)($expectancyMetrics['losses'] ?? 0) ?></span>
                | WR: <code><?= round(((float)($expectancyMetrics['winrate'] ?? 0)) * 100, 1) ?>%</code>
                | Avg Win: <code><?= number_format((float)($expectancyMetrics['average_win'] ?? 0), 2) ?>%</code>
                | Avg Loss: <code><?= number_format((float)($expectancyMetrics['average_loss'] ?? 0), 2) ?>%</code>
                | <strong>Expectancy: <code><?= number_format((float)($expectancyMetrics['expectancy'] ?? 0), 4) ?>%</code></strong>
            </div>
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
        $rejPriceBelowZone = (int)($last_run['rejected_price_below_zone'] ?? 0);
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
                        <?php if ($rejPriceBelowZone > 0): ?>
                        <tr><td>Short: price below zone</td><td class="text-end"><strong><?= $rejPriceBelowZone ?></strong></td></tr>
                        <?php endif; ?>
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

    <!-- ================================================ -->
    <!-- P7: Per-Symbol Exit Statistics Panel -->
    <!-- ================================================ -->
    <?php
        $symbolExitStats = $botMirror['symbol_exit_stats'] ?? [];
        if (!empty($symbolExitStats)):
            // Sort by trades count desc
            uasort($symbolExitStats, function($a, $b) {
                return ($b['trades_count'] ?? 0) <=> ($a['trades_count'] ?? 0);
            });
    ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 style="margin: 0;"><i class="bi bi-bar-chart-line me-2"></i>Per-Symbol Exit Statistics</h5>
            <span class="badge bg-primary"><?= count($symbolExitStats) ?> symbols</span>
        </div>
        <div class="card-body">
            <!-- Explanation Legend -->
            <div class="mb-3 small" style="background:rgba(15,23,42,0.5); border:1px solid var(--border-color, #334155); border-radius:0.5rem; padding:0.75rem 1rem;">
                <strong><i class="bi bi-info-circle me-1"></i>Как читать:</strong>
                <span class="text-secondary">WR</span> = винрейт |
                <span class="text-secondary">Exp</span> = ожидание (expectancy) |
                <span class="text-secondary">Stop Hit</span> = закрытий по стопу |
                <span class="text-secondary">Trail Act%</span> = % активации трейлинга |
                <span class="text-secondary">Trail Close</span> = закрытий трейлингом |
                <span class="text-secondary">BE Apply%</span> = % применения безубытка |
                <span class="text-secondary">MAE p75 Win</span> = 75-й перцентиль неблагоприятного хода выигрышных сделок |
                <span class="text-secondary">Sug. Stop</span> = рекомендуемый адаптивный логический стоп (на основе MAE, по символу+стороне). Аварийный стоп остаётся отдельно
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-sm table-hover mb-0" style="font-size:0.82rem;">
                    <thead>
                        <tr>
                            <th>Symbol</th>
                            <th class="text-center">Trades</th>
                            <th class="text-center">W / L</th>
                            <th class="text-center">WR</th>
                            <th class="text-end">Avg ROI</th>
                            <th class="text-end">Med ROI</th>
                            <th class="text-end">Exp</th>
                            <th class="text-center">Stop Hit</th>
                            <th class="text-center">Trail Act%</th>
                            <th class="text-center">Trail Close</th>
                            <th class="text-center">BE Apply%</th>
                            <th class="text-end">MAE p75 Win</th>
                            <th class="text-end">Sug. Stop</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($symbolExitStats as $sym => $ss): ?>
                        <?php
                            $ssCount = (int)($ss['trades_count'] ?? 0);
                            $ssWins = (int)($ss['wins'] ?? 0);
                            $ssLosses = (int)($ss['losses'] ?? 0);
                            $ssWR = (float)($ss['winrate'] ?? 0);
                            $ssAvg = (float)($ss['avg_roi'] ?? 0);
                            $ssMed = (float)($ss['roi_stats']['median'] ?? 0);
                            $ssExp = (float)($ss['expectancy'] ?? 0);
                            $ssStopHit = (int)($ss['stop_hit_count'] ?? 0);
                            $ssTrailRate = (float)($ss['trailing_activation_rate'] ?? 0);
                            $ssTrailClose = (int)($ss['trailing_close_count'] ?? 0);
                            $ssBERate = (float)($ss['break_even_apply_rate'] ?? 0);
                            $wrClass = $ssWR >= 0.5 ? 'text-success' : ($ssWR >= 0.35 ? 'text-warning' : 'text-danger');
                            $expClass = $ssExp > 0 ? 'text-success' : ($ssExp == 0 ? 'text-secondary' : 'text-danger');
                            // MAE-based stop data
                            $maeP75Win = (float)($ss['mae_winners_stats']['p75'] ?? 0);
                            $maeWinnersCount = (int)($ss['mae_winners_stats']['count'] ?? 0);
                            $maeStopFloor = 0.03;
                            $maeStopCap = 0.08;
                            $sugStop = ($maeWinnersCount >= 5 && $maeP75Win > 0)
                                ? max($maeStopFloor, min($maeStopCap, $maeP75Win)) : 0;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($sym) ?></strong></td>
                            <td class="text-center"><?= $ssCount ?></td>
                            <td class="text-center"><span class="text-success"><?= $ssWins ?></span> / <span class="text-danger"><?= $ssLosses ?></span></td>
                            <td class="text-center <?= $wrClass ?>"><?= round($ssWR * 100, 1) ?>%</td>
                            <td class="text-end"><code><?= number_format($ssAvg, 2) ?>%</code></td>
                            <td class="text-end"><code><?= number_format($ssMed, 2) ?>%</code></td>
                            <td class="text-end <?= $expClass ?>"><code><?= number_format($ssExp, 4) ?>%</code></td>
                            <td class="text-center"><?= $ssStopHit > 0 ? '<span class="text-danger">' . $ssStopHit . '</span>' : '<span class="text-secondary">0</span>' ?></td>
                            <td class="text-center"><?= round($ssTrailRate * 100, 0) ?>%</td>
                            <td class="text-center"><?= $ssTrailClose ?></td>
                            <td class="text-center"><?= round($ssBERate * 100, 0) ?>%</td>
                            <td class="text-end"><?php if ($maeP75Win > 0): ?><code><?= number_format($maeP75Win * 100, 2) ?>%</code><?php else: ?><span class="text-secondary">—</span><?php endif; ?></td>
                            <td class="text-end"><?php if ($sugStop > 0): ?><code class="text-info"><?= number_format($sugStop * 100, 2) ?>%</code><?php if ($maeWinnersCount < 5): ?><span class="badge bg-secondary" style="font-size:0.55rem;">low</span><?php endif; ?><?php else: ?><span class="text-secondary">—</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Per-symbol detail (expandable) -->
            <?php foreach ($symbolExitStats as $sym => $ss): ?>
            <?php if (($ss['trades_count'] ?? 0) >= 3): // Only show detail for symbols with enough trades ?>
            <details class="mt-2" style="font-size:0.8rem;">
                <summary class="text-secondary" style="cursor:pointer;"><strong><?= htmlspecialchars($sym) ?></strong> — side split, exit reasons &amp; MAE stop profile</summary>
                <div class="row mt-2 ms-2">
                    <!-- Side split -->
                    <div class="col-md-6">
                        <table class="table table-dark table-sm mb-2" style="font-size:0.78rem;">
                            <thead><tr><th>Side</th><th>Trades</th><th>WR</th><th>Avg ROI</th><th>Med ROI</th><th>MAE Win p75</th><th>Sug. Stop</th></tr></thead>
                            <tbody>
                            <?php foreach (['long', 'short'] as $sideKey):
                                $sd = $ss['by_side'][$sideKey] ?? null;
                                if ($sd && ($sd['trades'] ?? 0) > 0):
                                    $sdWR = (float)($sd['winrate'] ?? 0);
                                    $sdMaeP75 = (float)($sd['mae_winners_stats']['p75'] ?? 0);
                                    $sdMaeCount = (int)($sd['mae_winners_stats']['count'] ?? 0);
                                    $sdSugStop = ($sdMaeCount >= 5 && $sdMaeP75 > 0) ? max(0.03, min(0.08, $sdMaeP75)) : 0;
                            ?>
                                <tr>
                                    <td><span class="badge <?= $sideKey === 'long' ? 'bg-success bg-opacity-25 text-success' : 'bg-danger bg-opacity-25 text-danger' ?>"><?= strtoupper($sideKey) ?></span></td>
                                    <td><?= (int)$sd['trades'] ?></td>
                                    <td class="<?= $sdWR >= 0.5 ? 'text-success' : 'text-warning' ?>"><?= round($sdWR * 100, 1) ?>%</td>
                                    <td><code><?= number_format((float)($sd['avg_roi'] ?? 0), 2) ?>%</code></td>
                                    <td><code><?= number_format((float)($sd['roi_stats']['median'] ?? 0), 2) ?>%</code></td>
                                    <td><?php if ($sdMaeP75 > 0): ?><code><?= number_format($sdMaeP75 * 100, 2) ?>%</code> <span class="text-secondary">(n=<?= $sdMaeCount ?>)</span><?php else: ?><span class="text-secondary">—</span><?php endif; ?></td>
                                    <td><?php if ($sdSugStop > 0): ?><code class="text-info"><?= number_format($sdSugStop * 100, 2) ?>%</code><?php else: ?><span class="text-secondary">—</span><?php endif; ?></td>
                                </tr>
                            <?php endif; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Exit reasons -->
                    <div class="col-md-6">
                        <div class="mb-1"><strong class="text-secondary">Exit Reasons:</strong></div>
                        <?php foreach (($ss['close_reason_distribution'] ?? []) as $reason => $cnt): ?>
                            <span class="badge bg-secondary bg-opacity-25 text-light me-1 mb-1"><?= htmlspecialchars($reason) ?>: <?= $cnt ?></span>
                        <?php endforeach; ?>
                        <?php if (!empty($ss['stop_distance_stats'])): ?>
                        <div class="mt-1 text-secondary">Stop distance: med <code><?= number_format((float)($ss['stop_distance_stats']['median'] ?? 0) * 100, 2) ?>%</code>, p25-p75: <code><?= number_format((float)($ss['stop_distance_stats']['p25'] ?? 0) * 100, 2) ?>% – <?= number_format((float)($ss['stop_distance_stats']['p75'] ?? 0) * 100, 2) ?>%</code></div>
                        <?php endif; ?>
                    </div>
                </div>
            </details>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
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
