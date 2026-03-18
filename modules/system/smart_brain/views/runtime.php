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
