<?php
/**
 * Smart Brain Module - Runtime View
 * 
 * Shows runtime config snapshot, last run information, and full stats.json.
 */

/** @var string $smartBrainUrl */
/** @var array<string,mixed> $config */
/** @var array<string,mixed> $snapshot */
/** @var array<string,mixed> $last_run */
/** @var array<string,mixed> $stats */
/** @var list<string> $config_warnings */

$pageTitle = 'Smart Brain - Runtime';
$activeTab = 'runtime';

$config_warnings = $config_warnings ?? [];

$pageContent = function() use ($config, $snapshot, $last_run, $stats, $smartBrainUrl, $config_warnings) {
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-activity me-2 text-primary"></i>Runtime</h4>
            <p class="text-secondary mb-0">Current runtime state, config snapshot, statistics, and last run details</p>
        </div>
    </div>

    <!-- Config Conflict Guard: warnings (deduplicated) -->
    <?php
        $conflictDetected = (bool)($last_run['config_conflict_detected'] ?? false);
        $conflictMsg = (string)($last_run['config_conflict_message'] ?? '');
        $allWarnings = $config_warnings;
        if ($conflictDetected && $conflictMsg !== '') {
            array_unshift($allWarnings, $conflictMsg);
        }
        // Deduplicate warnings
        $seen = [];
        $uniqueWarnings = [];
        foreach ($allWarnings as $w) {
            $key = mb_strtolower(trim($w));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueWarnings[] = $w;
            }
        }
        if (!empty($uniqueWarnings)):
    ?>
    <div class="card mb-4" style="border-color: #f59e0b;">
        <div class="card-header" style="background: rgba(245,158,11,0.1);">
            <h5 style="margin: 0;"><i class="bi bi-exclamation-triangle-fill me-1 text-warning"></i> Config Conflict Guard</h5>
        </div>
        <div class="card-body">
            <?php foreach ($uniqueWarnings as $w): ?>
            <div class="alert alert-warning mb-2 py-1 px-2" style="font-size:0.85rem;">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <?= htmlspecialchars($w) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Live Trading Status -->
    <?php
        $liveEnabled = (bool)($last_run['live_trading_enabled'] ?? false);
        $liveMode = (string)($last_run['live_signal_selection_mode'] ?? 'n/a');
        $liveApproved = (int)($last_run['live_candidates_approved_count'] ?? 0);
        $liveRejected = (int)($last_run['live_candidates_rejected_count'] ?? 0);
        $liveIntents = (int)($last_run['live_intents_created_count'] ?? 0);
        $liveSent = (int)($last_run['live_intents_sent_to_bot_count'] ?? 0);
    ?>
    <div class="card mb-4" style="border-color: <?= $liveEnabled ? '#22c55e' : '#6b7280' ?>;">
        <div class="card-header" style="background: <?= $liveEnabled ? 'rgba(34,197,94,0.1)' : 'rgba(107,114,128,0.1)' ?>;">
            <h5 style="margin: 0;">
                <i class="bi bi-lightning-charge me-1"></i> Live Trading
                <?php if ($liveEnabled): ?>
                    <span class="badge bg-success ms-1">ENABLED</span>
                <?php else: ?>
                    <span class="badge bg-secondary ms-1">DISABLED</span>
                <?php endif; ?>
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 mb-2">
                    <strong>Selection Mode:</strong>
                    <span class="text-info"><?= htmlspecialchars($liveMode) ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>Approved:</strong>
                    <span class="text-success"><?= $liveApproved ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>Rejected:</strong>
                    <span class="text-danger"><?= $liveRejected ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>Intents:</strong>
                    <span class="text-warning"><?= $liveIntents ?></span>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>Sent to Bot:</strong>
                    <span class="text-primary"><?= $liveSent ?></span>
                </div>
            </div>
            <?php
                $filterStage = (string)($last_run['filter_stage_that_removed_all'] ?? 'none');
                $zeroReason = (string)($last_run['zero_output_reason'] ?? '');
                $siSkipped = (bool)($last_run['restrictive_si_skipped'] ?? false);
                $siSkipReason = (string)($last_run['restrictive_si_skip_reason'] ?? '');
                $brainControlledLiveMode = (bool)($last_run['brain_controlled_live_mode'] ?? $liveEnabled);
            ?>
            <?php if ($brainControlledLiveMode): ?>
            <div class="alert alert-success small mb-0 mt-2 py-1 px-2">
                <i class="bi bi-shield-check me-1"></i> <strong>Brain-Controlled Live Mode:</strong> Active — bot will only execute Brain-approved intents. Legacy fallback disabled.
            </div>
            <?php endif; ?>
            <?php if ($filterStage !== 'none' && $zeroReason !== ''): ?>
            <div class="alert alert-info small mb-0 mt-2 py-1 px-2">
                <i class="bi bi-info-circle me-1"></i> <strong>Filter stage:</strong> <?= htmlspecialchars($filterStage) ?> — <?= htmlspecialchars($zeroReason) ?>
            </div>
            <?php endif; ?>
            <?php if ($siSkipped && $siSkipReason !== ''): ?>
            <div class="alert alert-secondary small mb-0 mt-2 py-1 px-2">
                <i class="bi bi-skip-forward me-1"></i> <?= htmlspecialchars($siSkipReason) ?>
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
        <div class="card-header" style="background: <?= $botAvailable ? 'rgba(59,130,246,0.1)' : 'rgba(107,114,128,0.1)' ?>;">
            <h5 style="margin: 0;">
                <i class="bi bi-robot me-1"></i> Trading Bot Execution Mirror
                <?php if ($botAvailable && ($botMirror['bot_controlled_by_brain'] ?? false)): ?>
                    <span class="badge bg-success ms-1">Brain-Controlled</span>
                <?php elseif ($botAvailable): ?>
                    <span class="badge bg-warning text-dark ms-1">Legacy Mode</span>
                <?php else: ?>
                    <span class="badge bg-secondary ms-1">Unavailable</span>
                <?php endif; ?>
            </h5>
        </div>
        <div class="card-body">
            <?php if (!$botAvailable): ?>
            <p class="text-secondary small mb-0"><i class="bi bi-info-circle me-1"></i>
                Bot runtime unavailable<?= $botError !== '' ? ': ' . htmlspecialchars($botError) : '' ?>
            </p>
            <?php else: ?>
            <div class="row mb-2">
                <div class="col-md-3 mb-2">
                    <strong>Input Source:</strong>
                    <span class="text-info"><?= htmlspecialchars((string)($botMirror['bot_input_source'] ?? '-')) ?></span>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>Source Status:</strong>
                    <span class="text-info"><?= htmlspecialchars((string)($botMirror['source_status'] ?? '-')) ?></span>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>Brain Mode:</strong>
                    <?php if ($botMirror['brain_controlled_live_mode'] ?? false): ?>
                    <span class="text-success">Active</span>
                    <?php else: ?>
                    <span class="text-secondary">Inactive</span>
                    <?php endif; ?>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>Controlled by Brain:</strong>
                    <?php if ($botMirror['bot_controlled_by_brain'] ?? false): ?>
                    <span class="text-success">Yes</span>
                    <?php else: ?>
                    <span class="text-secondary">No</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row mb-2">
                <div class="col-md-2 mb-2">
                    <strong>Loaded:</strong>
                    <span class="text-primary"><?= (int)($botMirror['approved_intents_loaded'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>Duplicates:</strong>
                    <span class="text-secondary"><?= (int)($botMirror['duplicate_skipped'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>After Dedupe:</strong>
                    <span class="text-primary"><?= (int)($botMirror['executable_after_dedupe'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>Busy Skipped:</strong>
                    <span class="text-warning"><?= (int)($botMirror['busy_skipped'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>After Busy:</strong>
                    <span class="text-primary"><?= (int)($botMirror['executable_after_busy'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>Opened:</strong>
                    <span class="text-success"><?= (int)($botMirror['intents_opened'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>Rejected:</strong>
                    <span class="text-danger"><?= (int)($botMirror['intents_rejected'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>Failed / Deferred:</strong>
                    <span class="text-danger"><?= (int)($botMirror['intents_failed'] ?? 0) ?></span>
                    <span class="text-warning"> / <?= (int)($botMirror['intents_deferred'] ?? 0) ?></span>
                </div>
            </div>
            <!-- P0.3: Exchange Submit Visibility -->
            <div class="row mb-2" style="font-size: 0.85rem;">
                <div class="col-md-2 mb-1">
                    <strong>Guard Blocked:</strong>
                    <span class="text-warning"><?= (int)($botMirror['execution_guard_blocked_count'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-1">
                    <strong>Exch. Attempted:</strong>
                    <span class="text-info"><?= (int)($botMirror['exchange_submit_attempted_count'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-1">
                    <strong>Exch. Success:</strong>
                    <span class="text-success"><?= (int)($botMirror['exchange_submit_success_count'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-1">
                    <strong>Exch. Failed:</strong>
                    <span class="text-danger"><?= (int)($botMirror['exchange_submit_failed_count'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-1">
                    <strong>Protection Fail:</strong>
                    <span class="text-danger"><?= (int)($botMirror['protection_apply_failed_count'] ?? 0) ?></span>
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
            <div class="mb-2 small">
                <?php if ($botTopBlocker !== ''): ?>
                <span class="badge bg-danger bg-opacity-25 text-danger me-1"><i class="bi bi-exclamation-triangle me-1"></i>Top Blocker: <?= htmlspecialchars($botTopBlocker) ?></span>
                <?php endif; ?>
                <?php if ($botTopExecBlock !== ''): ?>
                <span class="badge bg-warning bg-opacity-25 text-warning me-1"><i class="bi bi-shield-exclamation me-1"></i>Guard: <?= htmlspecialchars($botTopExecBlock) ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($botExchErrCode !== null || $botExchErrMsg !== ''): ?>
            <div class="mb-2 small text-danger">
                <i class="bi bi-exclamation-octagon me-1"></i>
                <strong>Latest Exchange Error:</strong>
                <?= $botExchErrCode !== null ? 'code=' . htmlspecialchars((string)$botExchErrCode) : '' ?>
                <?= $botExchErrMsg !== '' ? ' — ' . htmlspecialchars($botExchErrMsg) : '' ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($botRejReasons)): ?>
            <div class="mb-2 small">
                <strong class="text-danger">Rejection Reasons:</strong>
                <?php foreach ($botRejReasons as $reason => $cnt): ?>
                <span class="badge bg-danger bg-opacity-25 text-danger me-1"><?= htmlspecialchars((string)$reason) ?>: <?= (int)$cnt ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($botSourceError !== ''): ?>
            <div class="text-danger small mb-2"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($botSourceError) ?></div>
            <?php endif; ?>
            <?php if (!empty($botNoOrderPreview)): ?>
            <details class="mb-2" style="font-size: 0.78rem;">
                <summary class="text-warning"><i class="bi bi-bug me-1"></i>No-Order Debug Preview (<?= count($botNoOrderPreview) ?> intents)</summary>
                <?php foreach ($botNoOrderPreview as $nop): ?>
                <div class="ps-3 text-muted"><?= htmlspecialchars((string)($nop['symbol'] ?? '')) ?> → <span class="text-danger"><?= htmlspecialchars((string)($nop['final_outcome'] ?? '')) ?></span> @ <?= htmlspecialchars((string)($nop['execution_stage'] ?? '')) ?> — <?= htmlspecialchars(substr((string)($nop['main_reason'] ?? ''), 0, 60)) ?> <?= !empty($nop['exchange_attempted']) ? '(exch: ✓)' : '(exch: ✗)' ?></div>
                <?php endforeach; ?>
            </details>
            <?php endif; ?>
            <!-- Active Protection State (Live Audit) -->
            <?php
                $rtActivePosCount = (int)($botMirror['active_positions_count'] ?? 0);
                $rtProtPosCount = (int)($botMirror['protected_positions_count'] ?? 0);
                $rtTrailActCount = (int)($botMirror['trailing_active_count'] ?? 0);
                $rtBeArmedCount = (int)($botMirror['break_even_armed_count'] ?? 0);
                $rtBeAppliedCount = (int)($botMirror['break_even_applied_count'] ?? 0);
                $rtProtErrCount = (int)($botMirror['protection_errors_count'] ?? 0);
            ?>
            <?php if ($rtActivePosCount > 0): ?>
            <div class="mb-2 small">
                <strong><i class="bi bi-shield-check me-1"></i>Active Protection State:</strong>
                <div class="row mt-1">
                    <div class="col-md-2"><small class="text-secondary">Positions</small><br><strong class="text-info"><?= $rtActivePosCount ?></strong></div>
                    <div class="col-md-2"><small class="text-secondary">Protected</small><br><strong class="text-success"><?= $rtProtPosCount ?></strong></div>
                    <div class="col-md-2"><small class="text-secondary">Trailing Active</small><br><strong class="text-primary"><?= $rtTrailActCount ?></strong></div>
                    <div class="col-md-2"><small class="text-secondary">BE Armed</small><br><strong class="text-warning"><?= $rtBeArmedCount ?></strong></div>
                    <div class="col-md-2"><small class="text-secondary">BE Applied</small><br><strong class="text-success"><?= $rtBeAppliedCount ?></strong></div>
                    <div class="col-md-2"><small class="text-secondary">Prot. Errors</small><br><strong class="text-danger"><?= $rtProtErrCount ?></strong></div>
                </div>
            </div>
            <?php endif; ?>
            <?php
                // Contract generation mix notice (Part 7: Brain mirror must not flatten mixed generations)
                $rtGenStats = is_array($botMirror['active_trade_contract_generation_stats'] ?? null) ? $botMirror['active_trade_contract_generation_stats'] : [];
                $rtMixedGen = (bool)($rtGenStats['mixed_generations'] ?? false);
                if ($rtMixedGen): ?>
            <div class="mb-2 small">
                <strong><i class="bi bi-exclamation-triangle me-1 text-warning"></i>Contract Generation Mix:</strong>
                <span class="badge bg-warning text-dark">Mixed generations detected</span>
                <?php foreach ((array)($rtGenStats['generation_counts'] ?? []) as $gen => $cnt): ?>
                    <span class="badge bg-secondary ms-1"><?= htmlspecialchars((string)$gen) ?>: <?= (int)$cnt ?></span>
                <?php endforeach; ?>
                <?php if ((int)($rtGenStats['migrated_active_trades_count'] ?? 0) > 0): ?>
                    <span class="badge bg-info ms-1">Migrated: <?= (int)$rtGenStats['migrated_active_trades_count'] ?></span>
                <?php endif; ?>
                <br><small class="text-muted">Some active trades were opened under a previous contract generation.</small>
            </div>
            <?php endif; ?>
            <?php
                // Effective Exit Contract Summary (Runtime)
                // Priority: bot mirror flat fields (always populated), then nested contract, then last_run
                $rtEffectiveContract = $botMirror['effective_trailing_contract'] ?? ($last_run['effective_trailing_contract'] ?? []);
                $mirrorExitMode = $botMirror['effective_exit_mode'] ?? ($rtEffectiveContract['exit_mode'] ?? null);
                $mirrorTrailingEnabled = $botMirror['effective_trailing_enabled'] ?? ($rtEffectiveContract['enabled'] ?? null);
                $mirrorBEEnabled = $botMirror['effective_break_even_enabled'] ?? ($rtEffectiveContract['break_even_enabled'] ?? null);
                $mirrorTrailingActivation = $botMirror['effective_trailing_activation'] ?? ($rtEffectiveContract['activation_roi_pct'] ?? ($rtEffectiveContract['trailing_activation_roi_pct'] ?? null));
                $mirrorBEActivation = $botMirror['effective_break_even_activation'] ?? ($rtEffectiveContract['break_even_activation_roi'] ?? ($rtEffectiveContract['break_even_activation_roi_pct'] ?? null));
                $mirrorDrawdown = $botMirror['effective_drawdown_factor'] ?? ($rtEffectiveContract['drawdown_factor'] ?? null);
                $mirrorTrailingMode = $rtEffectiveContract['trailing_mode'] ?? 'roi_giveback';
                $mirrorPriceDistPct = $rtEffectiveContract['trailing_price_distance_pct'] ?? null;
                $mirrorHybridShare = $botMirror['effective_hybrid_tp_share'] ?? ($rtEffectiveContract['hybrid_tp_share'] ?? null);
                $mirrorSource = $botMirror['effective_trailing_contract_source'] ?? 'unknown';
                $rtLegacyPresent = (bool)($rtEffectiveContract['legacy_trailing_fields_present'] ?? false);
                $hasContract = ($mirrorExitMode !== null);
                if ($hasContract):
            ?>
            <div class="mb-2 small">
                <strong><i class="bi bi-arrow-right-circle me-1"></i>Active Trailing Contract</strong>
                <span class="badge bg-primary ms-1" style="font-size:0.65rem;"><?= htmlspecialchars($mirrorTrailingMode) ?></span>
                <?php if ($rtLegacyPresent): ?>
                <span class="badge bg-secondary ms-1" style="font-size:0.55rem;">legacy fields preserved</span>
                <?php endif; ?>
                :
                <code><?= htmlspecialchars((string)$mirrorExitMode) ?></code>
                | trailing: <code><?= $mirrorTrailingEnabled ? 'ON' : 'OFF' ?></code>
                | mode: <code><?= htmlspecialchars((string)$mirrorTrailingMode) ?></code>
                | activation: <code><?= htmlspecialchars((string)($mirrorTrailingActivation ?? 'n/a')) ?>%</code>
                <?php if ($mirrorTrailingMode === 'price_distance' || $mirrorTrailingMode === 'price_distance_floor'): ?>
                | distance: <code><?= htmlspecialchars((string)($mirrorPriceDistPct ?? 'n/a')) ?></code> <small class="text-info">(<?= $mirrorPriceDistPct !== null ? round(((float)$mirrorPriceDistPct) * 100, 1) : '?' ?>% from price)</small>
                <?php
                    $rtExchangeDist = $rtEffectiveContract['exchange_trailing_distance'] ?? null;
                    $rtTheoStop = $rtEffectiveContract['theoretical_current_stop_price'] ?? null;
                ?>
                <?php if ($rtExchangeDist !== null): ?>
                | exch.dist: <code><?= round((float)$rtExchangeDist, 4) ?></code>
                <?php endif; ?>
                <?php if ($rtTheoStop !== null): ?>
                | implied stop: <code><?= round((float)$rtTheoStop, 6) ?></code>
                <?php endif; ?>
                <?php if ($mirrorTrailingMode === 'price_distance_floor'): ?>
                <?php
                    $rtFloorLockActive = $rtEffectiveContract['floor_lock_active'] ?? false;
                    $rtFloorLockedRoi = $rtEffectiveContract['floor_locked_roi'] ?? ($rtEffectiveContract['trailing_floor_lock_roi'] ?? null);
                    $rtFloorStopPrice = $rtEffectiveContract['floor_stop_price'] ?? null;
                    $rtStepMode = $rtEffectiveContract['trailing_step_mode'] ?? 'fixed';
                ?>
                | <span class="badge bg-<?= $rtFloorLockActive ? 'success' : 'secondary' ?>">floor <?= $rtFloorLockActive ? 'ON' : 'OFF' ?></span>
                <?php if ($rtFloorLockedRoi !== null): ?>
                lock: <code><?= round((float)$rtFloorLockedRoi, 1) ?>%</code>
                <?php endif; ?>
                <?php if ($rtFloorStopPrice !== null): ?>
                | floor stop: <code><?= round((float)$rtFloorStopPrice, 6) ?></code>
                <?php endif; ?>
                | step: <code><?= htmlspecialchars($rtStepMode) ?></code>
                <?php endif; ?>
                <?php else: ?>
                | drawdown: <code><?= htmlspecialchars((string)($mirrorDrawdown ?? 'n/a')) ?></code>
                <?php endif; ?>
                | BE: <code><?= $mirrorBEEnabled ? 'ON' : 'OFF' ?></code>
                <?php if ($mirrorBEEnabled): ?>
                @ <code><?= htmlspecialchars((string)($mirrorBEActivation ?? '')) ?>%</code>
                <?php endif; ?>
                <?php if ($mirrorExitMode === 'hybrid_tp'): ?>
                | hybrid: <code><?= $mirrorHybridShare !== null ? round(((float)$mirrorHybridShare) * 100) : 'n/a' ?>%</code> @ <code><?= htmlspecialchars((string)($rtEffectiveContract['fixed_take_profit_roi'] ?? '')) ?></code>
                <?php endif; ?>
                | logical stop: <code><?= htmlspecialchars((string)($rtEffectiveContract['logical_stop_roi'] ?? 'n/a')) ?></code>
                | source: <code><?= htmlspecialchars($mirrorSource) ?></code>
                <?php
                $mirrorStopControlMode = (string)($botMirror['effective_stop_control_mode'] ?? 'auto');
                $mirrorEntryRoi = $botMirror['effective_stop_loss_from_entry_roi'] ?? null;
                ?>
                | stop: <code><?= htmlspecialchars($mirrorStopControlMode) ?></code>
                <?php if ($mirrorStopControlMode === 'entry_roi' && $mirrorEntryRoi !== null): ?>
                    (<code><?= round((float)$mirrorEntryRoi * 100, 1) ?>%</code> from entry)
                <?php endif; ?>
                <?php $mirrorStopPrice = $botMirror['effective_stop_price'] ?? null; ?>
                <?php if ($mirrorStopPrice !== null): ?>
                    | stop price: <code><?= number_format((float)$mirrorStopPrice, 4) ?></code>
                <?php endif; ?>
                <?php
                $mirrorInitialStop = $botMirror['initial_computed_stop_price'] ?? null;
                $mirrorStopMoved = (bool)($botMirror['stop_moved_from_initial'] ?? false);
                ?>
                <?php if ($mirrorInitialStop !== null): ?>
                    | initial stop: <code><?= number_format((float)$mirrorInitialStop, 4) ?></code>
                <?php endif; ?>
                <?php if ($mirrorStopMoved): ?>
                    <span class="badge bg-warning text-dark" style="font-size:0.65rem;">moved</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php
            $protDetails = $botMirror['active_position_protection_details'] ?? [];
            if (!empty($protDetails)): ?>
            <div class="mb-2 small">
                <strong><i class="bi bi-shield-fill-check me-1"></i>Active Position Protection Details</strong>
                <div class="table-responsive mt-1">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Symbol</th>
                                <th>Side</th>
                                <th>Entry</th>
                                <th>SL</th>
                                <th>Protection</th>
                                <th>Exit Mode</th>
                                <th>Contract</th>
                                <th>Trailing</th>
                                <th>Break-Even</th>
                                <th>Stop Mode</th>
                                <th>Initial Stop</th>
                                <th>Current Stop</th>
                                <th>Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($protDetails as $pd): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)($pd['symbol'] ?? '')) ?></td>
                                <td><span class="badge bg-<?= ($pd['side'] ?? '') === 'long' ? 'success' : 'danger' ?>"><?= htmlspecialchars(strtoupper((string)($pd['side'] ?? ''))) ?></span></td>
                                <td><?= number_format((float)($pd['entry_price'] ?? 0), 4) ?></td>
                                <td><?= ($pd['stop_loss_applied'] ?? false) ? '✅' : '❌' ?></td>
                                <td><span class="badge bg-<?= ($pd['protection_state'] ?? '') === 'trailing_active' ? 'success' : (($pd['protection_state'] ?? '') === 'opened_protected' ? 'info' : 'warning') ?>"><?= htmlspecialchars((string)($pd['protection_state'] ?? 'unknown')) ?></span></td>
                                <td><small><?= htmlspecialchars((string)($pd['exit_mode'] ?? 'n/a')) ?></small></td>
                                <td>
                                    <small><?= htmlspecialchars((string)($pd['contract_generation'] ?? '')) ?></small>
                                    <?php if ($pd['contract_migrated'] ?? false): ?><span class="badge bg-info" style="font-size:0.6rem;">migrated</span><?php endif; ?>
                                </td>
                                <td>
                                    <?= ($pd['trailing_active'] ?? false) ? '🟢 Active' : (($pd['trailing_enabled'] ?? false) ? '⏳ Enabled' : '⚪ Off') ?>
                                    <?php if ($pd['trailing_activation_roi_pct'] ?? 0): ?>
                                        <small>(<?= number_format((float)($pd['trailing_activation_roi_pct'] ?? 0), 2) ?>%)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($pd['break_even_applied'] ?? false): ?>
                                        ✅ Applied
                                    <?php elseif ($pd['break_even_armed'] ?? false): ?>
                                        🔶 Armed
                                    <?php elseif ($pd['break_even_enabled'] ?? false): ?>
                                        ⏳ Enabled
                                    <?php else: ?>
                                        ⚪ Off
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small><?= htmlspecialchars((string)($pd['stop_control_mode'] ?? 'auto')) ?></small>
                                    <?php if (($pd['stop_control_mode'] ?? 'auto') === 'entry_roi' && ($pd['stop_loss_from_entry_roi'] ?? null) !== null): ?>
                                        <small>(<?= round((float)$pd['stop_loss_from_entry_roi'] * 100, 1) ?>%)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $pdInitialStop = $pd['initial_computed_stop_price'] ?? null; ?>
                                    <?php if ($pdInitialStop !== null): ?>
                                        <small><?= number_format((float)$pdInitialStop, 4) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">—</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $pdStopPrice = $pd['effective_stop_price'] ?? null; ?>
                                    <?php if ($pdStopPrice !== null): ?>
                                        <small><?= number_format((float)$pdStopPrice, 4) ?></small>
                                        <?php if ($pd['stop_moved_from_initial'] ?? false): ?>
                                            <span class="badge bg-warning text-dark" style="font-size:0.55rem;">moved</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <small class="text-muted">—</small>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= htmlspecialchars((string)($pd['effective_trailing_contract_source'] ?? 'unknown')) ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            <div>
                <a href="/admin/trading_bot" class="btn btn-outline-primary btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i>Trading Bot Dashboard</a>
                <a href="/admin/trading_bot/intents" class="btn btn-outline-secondary btn-sm ms-1"><i class="bi bi-list-task me-1"></i>Bot Intents</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- P7: Per-Symbol Exit Statistics (Runtime Detail) -->
    <?php
        $symbolExitStats = $botMirror['symbol_exit_stats'] ?? [];
        if (!empty($symbolExitStats)):
            uasort($symbolExitStats, function($a, $b) {
                return ($b['trades_count'] ?? 0) <=> ($a['trades_count'] ?? 0);
            });
    ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 style="margin: 0;"><i class="bi bi-bar-chart-line me-2"></i>Per-Symbol Exit Behavior</h5>
            <span class="badge bg-primary"><?= count($symbolExitStats) ?> symbols</span>
        </div>
        <div class="card-body">
            <div class="mb-2 small" style="background:rgba(15,23,42,0.5); border:1px solid var(--border-color, #334155); border-radius:0.5rem; padding:0.5rem 0.75rem;">
                <i class="bi bi-info-circle me-1 text-info"></i>
                <strong>Trailing activation rate</strong> = % trades where trailing became active |
                <strong>BE apply rate</strong> = % trades where stop moved to entry |
                <strong>Stop hit</strong> = closed by logical/emergency stop |
                <strong>Median ROI</strong> = robust central measure |
                <strong>MAE p75 Win</strong> = how much adverse move 75% of winning trades survived |
                <strong>Sug. Stop</strong> = recommended working stop for this symbol+side, clamped to [3%–8%]. Emergency stop remains separate and wider
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-sm table-hover mb-0" style="font-size:0.8rem;">
                    <thead>
                        <tr>
                            <th>Symbol</th>
                            <th class="text-center">Trades</th>
                            <th class="text-center">W/L</th>
                            <th class="text-center">WR</th>
                            <th class="text-end">Avg ROI</th>
                            <th class="text-end">Med ROI</th>
                            <th class="text-end">P25</th>
                            <th class="text-end">P75</th>
                            <th class="text-end">Expect</th>
                            <th class="text-center">Stop</th>
                            <th class="text-center">Trail%</th>
                            <th class="text-center">Trail Cl</th>
                            <th class="text-center">BE%</th>
                            <th class="text-end">MAE p75 Win</th>
                            <th class="text-end">Sug. Stop</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($symbolExitStats as $sym => $ss): ?>
                        <?php
                            $cnt = (int)($ss['trades_count'] ?? 0);
                            $wr = (float)($ss['winrate'] ?? 0);
                            $avgR = (float)($ss['avg_roi'] ?? 0);
                            $medR = (float)($ss['roi_stats']['median'] ?? 0);
                            $p25 = (float)($ss['roi_stats']['p25'] ?? 0);
                            $p75 = (float)($ss['roi_stats']['p75'] ?? 0);
                            $exp = (float)($ss['expectancy'] ?? 0);
                            $wrCls = $wr >= 0.5 ? 'text-success' : ($wr >= 0.35 ? 'text-warning' : 'text-danger');
                            $expCls = $exp > 0 ? 'text-success' : ($exp == 0 ? 'text-secondary' : 'text-danger');
                            // MAE-based stop
                            $maeP75W = (float)($ss['mae_winners_stats']['p75'] ?? 0);
                            $maeWinN = (int)($ss['mae_winners_stats']['count'] ?? 0);
                            $sugStop = ($maeWinN >= 5 && $maeP75W > 0)
                                ? max(0.03, min(0.08, $maeP75W)) : 0;
                            $fallback = ($maeWinN < 5 || $maeP75W <= 0);
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($sym) ?></strong>
                                <?php if ($cnt < 10): ?><span class="badge bg-secondary" style="font-size:0.6rem;">low sample</span><?php endif; ?>
                            </td>
                            <td class="text-center"><?= $cnt ?></td>
                            <td class="text-center"><span class="text-success"><?= (int)($ss['wins'] ?? 0) ?></span>/<span class="text-danger"><?= (int)($ss['losses'] ?? 0) ?></span></td>
                            <td class="text-center <?= $wrCls ?>"><?= round($wr * 100, 1) ?>%</td>
                            <td class="text-end"><code><?= number_format($avgR, 2) ?>%</code></td>
                            <td class="text-end"><code><?= number_format($medR, 2) ?>%</code></td>
                            <td class="text-end text-secondary"><code><?= number_format($p25, 2) ?>%</code></td>
                            <td class="text-end text-secondary"><code><?= number_format($p75, 2) ?>%</code></td>
                            <td class="text-end <?= $expCls ?>"><code><?= number_format($exp, 4) ?>%</code></td>
                            <td class="text-center"><?= (int)($ss['stop_hit_count'] ?? 0) ?></td>
                            <td class="text-center"><?= round((float)($ss['trailing_activation_rate'] ?? 0) * 100, 0) ?>%</td>
                            <td class="text-center"><?= (int)($ss['trailing_close_count'] ?? 0) ?></td>
                            <td class="text-center"><?= round((float)($ss['break_even_apply_rate'] ?? 0) * 100, 0) ?>%</td>
                            <td class="text-end"><?php if ($maeP75W > 0): ?><code><?= number_format($maeP75W * 100, 2) ?>%</code> <span class="text-secondary" style="font-size:0.65rem;">n=<?= $maeWinN ?></span><?php else: ?><span class="text-secondary">—</span><?php endif; ?></td>
                            <td class="text-end"><?php if ($sugStop > 0): ?><code class="text-info"><?= number_format($sugStop * 100, 2) ?>%</code><?php if ($fallback): ?> <span class="badge bg-warning text-dark" style="font-size:0.55rem;">fallback</span><?php endif; ?><?php else: ?><span class="text-secondary">—</span><?php endif; ?></td>
                        </tr>
                        <?php // Per-side MAE detail row (compact)
                        $hasSideData = false;
                        foreach (['long', 'short'] as $rtSideKey) {
                            $rtSd = $ss['by_side'][$rtSideKey] ?? null;
                            if ($rtSd && ($rtSd['trades'] ?? 0) > 0 && !empty($rtSd['mae_winners_stats'])) {
                                $hasSideData = true;
                                break;
                            }
                        }
                        if ($hasSideData): ?>
                        <tr style="font-size:0.72rem; background:rgba(15,23,42,0.4);">
                            <td colspan="13" class="text-end text-secondary" style="padding:2px 6px;">
                                <?php foreach (['long', 'short'] as $rtSideKey):
                                    $rtSd = $ss['by_side'][$rtSideKey] ?? null;
                                    if ($rtSd && ($rtSd['trades'] ?? 0) > 0):
                                        $rtSdMaeP75 = (float)($rtSd['mae_winners_stats']['p75'] ?? 0);
                                        $rtSdMaeN = (int)($rtSd['mae_winners_stats']['count'] ?? 0);
                                        $rtSdSugStop = ($rtSdMaeN >= 5 && $rtSdMaeP75 > 0) ? max(0.03, min(0.08, $rtSdMaeP75)) : 0;
                                ?>
                                <span class="badge <?= $rtSideKey === 'long' ? 'bg-success bg-opacity-25 text-success' : 'bg-danger bg-opacity-25 text-danger' ?>" style="font-size:0.65rem;"><?= strtoupper($rtSideKey) ?></span>
                                <?= (int)$rtSd['trades'] ?>t
                                <?php if ($rtSdMaeP75 > 0): ?>MAE p75=<code><?= number_format($rtSdMaeP75 * 100, 2) ?>%</code>(n=<?= $rtSdMaeN ?>)<?php endif; ?>
                                <?php if ($rtSdSugStop > 0): ?>→<code class="text-info"><?= number_format($rtSdSugStop * 100, 2) ?>%</code><?php endif; ?>
                                &nbsp;
                                <?php endif; endforeach; ?>
                            </td>
                            <td class="text-end text-secondary" style="padding:2px 6px;">&nbsp;</td>
                            <td class="text-end text-secondary" style="padding:2px 6px;">&nbsp;</td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Simulator Stats Card -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-bar-chart me-1"></i> Simulator Statistics</h5></div>
        <div class="card-body">
            <?php if (empty($stats)): ?>
                <p class="text-secondary">No stats recorded yet. Run the pipeline to generate metrics.</p>
            <?php else: ?>
                <pre style="background:#0f172a; padding:16px; border-radius:8px; font-size:0.85rem; max-height:400px; overflow:auto; color:#e2e8f0;"><?= htmlspecialchars(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php endif; ?>
        </div>
    </div>

    <!-- Last Run Card -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-clock-history me-1"></i> Last Run</h5></div>
        <div class="card-body">
            <?php if (empty($last_run)): ?>
                <p class="text-secondary">No runs recorded yet.</p>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($last_run as $key => $val): ?>
                    <div class="col-md-3 mb-2">
                        <strong><?= htmlspecialchars((string)$key) ?>:</strong>
                        <span class="text-warning"><?= htmlspecialchars(is_bool($val) ? ($val ? 'true' : 'false') : (string)$val) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Config Snapshot -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-camera me-1"></i> Config Snapshot</h5></div>
        <div class="card-body">
            <?php if (empty($snapshot)): ?>
                <p class="text-secondary">No snapshot recorded yet.</p>
            <?php else: ?>
                <pre style="background:#0f172a; padding:16px; border-radius:8px; font-size:0.85rem; max-height:600px; overflow:auto; color:#e2e8f0;"><?= htmlspecialchars(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php endif; ?>
        </div>
    </div>

    <!-- Current Config -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-gear me-1"></i> Current Config (effective)</h5></div>
        <div class="card-body">
            <pre style="background:#0f172a; padding:16px; border-radius:8px; font-size:0.85rem; max-height:600px; overflow:auto; color:#e2e8f0;"><?= htmlspecialchars(json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        </div>
    </div>
<?php
};

require __DIR__ . '/_layout.php';
