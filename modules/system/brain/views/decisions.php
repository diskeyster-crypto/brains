<?php
/**
 * Brain Module - Decisions View
 * 
 * Displays decision history and orchestration results.
 */

use Core\System\System;

// Variables: $runs, $stats, $flash
$brainUrl = rtrim(System::web('admin/brain'), '/');
$pageTitle = 'Brain - Decisions';
$activeTab = 'decisions';
$totalDecisions = $stats['successful_runs'] ?? 0;

$pageContent = function() use ($runs, $stats, $brainUrl, $totalDecisions) {
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-check2-circle me-2"></i>Orchestration Decisions</h4>
            <p class="text-secondary mb-0">History of pipeline orchestration decisions and outcomes</p>
        </div>
        <div>
            <span class="badge bg-success fs-6"><?= $totalDecisions ?> Successful Runs</span>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <h3 class="text-primary mb-0"><?= count($runs) ?></h3>
                    <small class="text-secondary">Recent Runs</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <h3 class="text-success mb-0"><?= $totalDecisions ?></h3>
                    <small class="text-secondary">Successful</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <h3 class="text-info mb-0"><?= $stats['total_strategies'] ?? 0 ?></h3>
                    <small class="text-secondary">Strategies</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body text-center">
                    <h3 class="text-warning mb-0"><?= $stats['executor_enabled'] ?? 0 ?></h3>
                    <small class="text-secondary">Active Executors</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Decisions Table -->
    <div class="card">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-table me-2"></i>Decision History</h6>
        </div>
        <div class="card-body p-0">
            <?php if (empty($runs)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox display-1 text-secondary"></i>
                <h5 class="mt-3">No Decisions Yet</h5>
                <p class="text-secondary">Run the orchestration pipeline to generate decisions.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-dark table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Status</th>
                            <th>Duration</th>
                            <th>Strategies</th>
                            <th>Candidates</th>
                            <th>Signals</th>
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
                            $runTimestamp = $run['timestamp'] 
                                ?? strtotime($run['started_at'] ?? '') 
                                ?: time();
                        ?>
                        <tr>
                            <td><?= date('Y-m-d H:i:s', $runTimestamp) ?></td>
                            <td>
                                <?php if ($run['success'] ?? false): ?>
                                <span class="badge bg-success">Success</span>
                                <?php else: ?>
                                <span class="badge bg-danger">Failed</span>
                                <?php endif; ?>
                            </td>
                            <td><?= ($run['duration_ms'] ?? 0) ?>ms</td>
                            <td><span class="fw-bold"><?= $strategiesApplied ?></span></td>
                            <td><span class="fw-bold"><?= $candidatesLoaded ?></span></td>
                            <td><span class="fw-bold text-success"><?= $signalsGenerated ?></span></td>
                        </tr>
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
