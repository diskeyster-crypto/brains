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

    <!-- Config Conflict Guard: warnings -->
    <?php
        $conflictDetected = (bool)($last_run['config_conflict_detected'] ?? false);
        $conflictMsg = (string)($last_run['config_conflict_message'] ?? '');
        $allWarnings = $config_warnings;
        if ($conflictDetected && $conflictMsg !== '') {
            array_unshift($allWarnings, $conflictMsg);
        }
        if (!empty($allWarnings)):
    ?>
    <div class="card mb-4" style="border-color: #f59e0b;">
        <div class="card-header" style="background: rgba(245,158,11,0.1);">
            <h5 style="margin: 0;"><i class="bi bi-exclamation-triangle-fill me-1 text-warning"></i> Config Conflict Guard</h5>
        </div>
        <div class="card-body">
            <?php foreach ($allWarnings as $w): ?>
            <div class="alert alert-warning mb-2 py-1 px-2" style="font-size:0.85rem;">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <?= htmlspecialchars($w) ?>
            </div>
            <?php endforeach; ?>
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
