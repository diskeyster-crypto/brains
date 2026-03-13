<?php
/**
 * Brain Module - Run History View
 * 
 * Shows history of pipeline runs with details.
 */

use Core\System\System;

// Variables: $runs, $flash
$brainUrl = rtrim(System::web('admin/brain'), '/');
$pageTitle = 'Brain - History';
$activeTab = 'runs';

/**
 * Get status badge HTML for a pipeline step status
 */
function getStatusBadge(string $status): string
{
    $badges = [
        'ok' => '<span class="badge bg-success" style="font-size: 9px;">OK</span>',
        'error' => '<span class="badge bg-danger" style="font-size: 9px;">ERR</span>',
        'skip' => '<span class="badge bg-warning text-dark" style="font-size: 9px;">SKIP</span>',
        'empty' => '<span class="badge bg-secondary" style="font-size: 9px;">∅</span>',
    ];
    return $badges[$status] ?? '<span class="badge bg-secondary" style="font-size: 9px;">?</span>';
}

$pageContent = function() use ($runs, $brainUrl) {
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-clock-history me-2"></i>Pipeline Run History</h4>
            <p class="text-secondary mb-0">History of all pipeline runs with details</p>
        </div>
        <div>
            <form method="POST" action="<?= $brainUrl ?>/run" style="display: inline;">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-play-fill me-1"></i> Run Pipeline Now
                </button>
            </form>
        </div>
    </div>

    <!-- Runs List -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-table me-2"></i>All Runs</h6>
            <span class="badge bg-secondary"><?= count($runs) ?> runs</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($runs)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox display-1 text-secondary"></i>
                    <h5 class="mt-3">No Pipeline Runs Yet</h5>
                    <p class="text-secondary">Run the pipeline to generate signals from your strategies.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width: 80px;">Status</th>
                                <th>Run ID</th>
                                <th>Time</th>
                                <th>Duration</th>
                                <th>Strategies</th>
                                <th>Candidates</th>
                                <th>Signals</th>
                                <th>Steps</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($runs as $run): 
                                // Backward compatibility fallbacks for old run records
                                $strategiesApplied = $run['strategies_applied'] 
                                    ?? count($run['strategies'] ?? []) 
                                    ?? ($run['strategies_count'] ?? 0);
                                $candidatesLoaded = $run['candidates_loaded'] 
                                    ?? ($run['candidates_count'] ?? 0);
                                $signalsGenerated = $run['signals_generated'] 
                                    ?? ($run['signals_count'] ?? 0);
                            ?>
                                <tr>
                                    <td>
                                        <?php if ($run['success'] ?? false): ?>
                                            <span class="badge bg-success">OK</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">ERROR</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code style="font-size: 11px;"><?= htmlspecialchars($run['id'] ?? 'N/A') ?></code>
                                    </td>
                                    <td>
                                        <span style="font-size: 13px;"><?= htmlspecialchars($run['started_at'] ?? $run['finished_at'] ?? 'N/A') ?></span>
                                    </td>
                                    <td>
                                        <code><?= number_format($run['duration_ms'] ?? 0) ?>ms</code>
                                    </td>
                                    <td>
                                        <span style="font-weight: bold;"><?= $strategiesApplied ?></span>
                                    </td>
                                    <td>
                                        <span style="font-weight: bold;"><?= $candidatesLoaded ?></span>
                                    </td>
                                    <td>
                                        <span style="font-weight: bold; color: #10b981;"><?= $signalsGenerated ?></span>
                                    </td>
                                    <td>
                                        <?php 
                                        $steps = $run['steps'] ?? [];
                                        foreach ($steps as $step) {
                                            echo getStatusBadge($step['status'] ?? '?') . ' ';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php if (!empty($run['errors'])): ?>
                                <tr>
                                    <td colspan="8" style="background: rgba(239, 68, 68, 0.1); border-top: none;">
                                        <small style="color: #ef4444;">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            <?= htmlspecialchars(implode('; ', $run['errors'])) ?>
                                        </small>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php
};

require \Core\System\SystemPaths::instance()->get('system.brain') . '/views/_layout.php';
?>

<?php /* RULES
 * Views must include partials via SystemPaths / PackMap ONLY (no local path computations)
 */ ?>
