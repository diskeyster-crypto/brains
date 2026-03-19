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
            <?php if ($botMirror['bot_last_updated_at'] ?? ''): ?>
            <div class="text-secondary mb-2" style="font-size:0.75rem;">Bot last run: <?= htmlspecialchars((string)$botMirror['bot_last_updated_at']) ?></div>
            <?php endif; ?>
            <div>
                <a href="/admin/trading_bot" class="btn btn-outline-primary btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i>Trading Bot Dashboard</a>
                <a href="/admin/trading_bot/intents" class="btn btn-outline-secondary btn-sm ms-1"><i class="bi bi-list-task me-1"></i>Bot Intents</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

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
