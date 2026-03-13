<?php
/**
 * Profit Manager - Index View
 * 
 * Standalone dashboard for Profit Manager module (P2 контур).
 * Shows trailing stop monitoring, step trailing, and dumb trailing status.
 */

use Core\System\System;

// Extract data passed from controller
$title = $title ?? 'Profit Manager';
$moduleBase = $module_base ?? null;
$configError = $config_error ?? null;
$config = $config ?? [];
$lastRun = $last_run ?? null;
$status = $status ?? null;
$appliedIndex = $applied_index ?? null;

// Config shortcuts
$stepEnabled = $config['step_trailing']['enabled'] ?? false;
$dumbEnabled = $config['dumb_trailing']['enabled'] ?? false;
$mode = $config['selector']['mode'] ?? 'exchange_only';

// Determine module status
$isOk = !empty($lastRun) && ($lastRun['ok'] ?? false);
$lastTs = $lastRun['ts'] ?? null;
$lastDuration = $lastRun['duration_ms'] ?? 0;
$managedCount = $lastRun['positions_managed'] ?? 0;
$totalCount = $lastRun['positions_total'] ?? 0;

// Step trailing stats
$stepApplied = $lastRun['step_trailing']['applied'] ?? 0;
$stepSkipped = $lastRun['step_trailing']['skip'] ?? 0;
$stepFailed = $lastRun['step_trailing']['failed'] ?? 0;

// Dumb trailing stats
$dumbApplied = $lastRun['dumb_trailing']['applied'] ?? 0;
$dumbSkipped = $lastRun['dumb_trailing']['skip'] ?? 0;
$dumbFailed = $lastRun['dumb_trailing']['failed'] ?? 0;

// Errors/warnings
$errors = $lastRun['errors'] ?? [];
$warnings = $lastRun['warnings'] ?? [];

// Items (position details)
$items = $lastRun['items'] ?? [];
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #0d1117; color: #c9d1d9; }
        .navbar { background: #161b22 !important; border-bottom: 1px solid #30363d; }
        .card { background: #161b22; border: 1px solid #30363d; }
        .card-header { background: #21262d; border-bottom: 1px solid #30363d; }
        .table { color: #c9d1d9; }
        .badge-success { background-color: #238636; }
        .badge-warning { background-color: #9e6a03; }
        .badge-danger { background-color: #da3633; }
        .stat-card {
            background: #21262d;
            border: 1px solid #30363d;
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
        }
        .stat-value { font-size: 1.5rem; font-weight: bold; }
        .stat-label { font-size: 0.85rem; color: #8b949e; }
        pre { background: #0d1117; border: 1px solid #30363d; }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?= System::adminUrl('') ?>">
                <i class="bi bi-graph-up me-2"></i>Trading Admin
            </a>
            <div class="d-flex gap-2">
                <a href="<?= System::adminUrl('trading/bot') ?>" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-robot me-1"></i>Bot
                </a>
                <a href="<?= System::adminUrl('trading/profit') ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-percent me-1"></i>Profit
                </a>
                <a href="<?= System::adminUrl('trading/settings') ?>" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-gear me-1"></i>Settings
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1">
                    <i class="bi bi-percent me-2"></i>
                    Profit Manager
                    <?php if ($stepEnabled): ?>
                        <span class="badge bg-success ms-2">Step Trailing ON</span>
                    <?php else: ?>
                        <span class="badge bg-secondary ms-2">Step Trailing OFF</span>
                    <?php endif; ?>
                    <?php if ($dumbEnabled): ?>
                        <span class="badge bg-info ms-1">Dumb Trailing ON</span>
                    <?php else: ?>
                        <span class="badge bg-secondary ms-1">Dumb Trailing OFF</span>
                    <?php endif; ?>
                </h4>
                <p class="text-muted mb-0">
                    P2 контур — Trailing / Profit Lock (Mode: <?= htmlspecialchars($mode) ?>)
                </p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-light" onclick="location.reload()">
                    <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                </button>
            </div>
        </div>

        <?php if ($configError): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle me-2"></i>
            Configuration Error: <?= htmlspecialchars($configError) ?>
        </div>
        <?php endif; ?>

        <!-- Stats Row -->
        <div class="row g-3 mb-4">
            <div class="col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $isOk ? 'text-success' : 'text-danger' ?>">
                        <?= $isOk ? 'OK' : 'ERR' ?>
                    </div>
                    <div class="stat-label">Last Run</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card">
                    <div class="stat-value text-info"><?= $managedCount ?></div>
                    <div class="stat-label">Managed</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card">
                    <div class="stat-value"><?= $totalCount ?></div>
                    <div class="stat-label">Total Positions</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card">
                    <div class="stat-value text-success"><?= $stepApplied ?></div>
                    <div class="stat-label">Step Applied</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?= $stepSkipped ?></div>
                    <div class="stat-label">Step Skipped</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card">
                    <div class="stat-value"><?= $lastDuration ?>ms</div>
                    <div class="stat-label">Duration</div>
                </div>
            </div>
        </div>

        <!-- Last Run Details -->
        <?php if ($lastRun): ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-2"></i>Last Run</span>
                <span class="badge <?= $isOk ? 'bg-success' : 'bg-danger' ?>">
                    <?= $lastRun['status'] ?? 'unknown' ?>
                </span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <small class="text-muted">Timestamp</small>
                        <div><?= htmlspecialchars($lastTs ?? 'N/A') ?></div>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted">Mode</small>
                        <div><?= htmlspecialchars($lastRun['mode'] ?? $mode) ?></div>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted">Positions Total</small>
                        <div><?= $totalCount ?></div>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted">Positions Managed</small>
                        <div><?= $managedCount ?></div>
                    </div>
                </div>
                
                <hr>
                
                <div class="row">
                    <div class="col-md-6">
                        <h6>Step Trailing</h6>
                        <div class="d-flex gap-3">
                            <span class="text-success">Applied: <?= $stepApplied ?></span>
                            <span class="text-warning">Skip: <?= $stepSkipped ?></span>
                            <span class="text-danger">Failed: <?= $stepFailed ?></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6>Dumb Trailing</h6>
                        <div class="d-flex gap-3">
                            <span class="text-success">Applied: <?= $dumbApplied ?></span>
                            <span class="text-warning">Skip: <?= $dumbSkipped ?></span>
                            <span class="text-danger">Failed: <?= $dumbFailed ?></span>
                        </div>
                    </div>
                </div>

                <?php if (!empty($errors)): ?>
                <hr>
                <h6 class="text-danger">Errors</h6>
                <ul class="mb-0">
                    <?php foreach ($errors as $err): ?>
                    <li class="text-danger small"><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <?php if (!empty($warnings)): ?>
                <hr>
                <h6 class="text-warning">Warnings</h6>
                <ul class="mb-0">
                    <?php foreach ($warnings as $warn): ?>
                    <li class="text-warning small"><?= htmlspecialchars($warn) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="card mb-4">
            <div class="card-body text-center text-muted">
                <i class="bi bi-hourglass-split fs-1 mb-2"></i>
                <p>No last run data available. The cron job may not have run yet.</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Position Items -->
        <?php if (!empty($items)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-list-ul me-2"></i>Position Details (<?= count($items) ?>)
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Symbol</th>
                                <th>Side</th>
                                <th>ROI %</th>
                                <th>Old SL</th>
                                <th>New SL</th>
                                <th>Action</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($item['symbol'] ?? '') ?></strong></td>
                                <td>
                                    <span class="badge bg-<?= ($item['side'] ?? '') === 'long' ? 'success' : 'danger' ?>">
                                        <?= strtoupper($item['side'] ?? '') ?>
                                    </span>
                                </td>
                                <td><?= number_format($item['roi_pct'] ?? 0, 2) ?>%</td>
                                <td><?= $item['oldSL'] ?? '-' ?></td>
                                <td><?= $item['newSL'] ?? '-' ?></td>
                                <td>
                                    <?php
                                    $action = $item['action'] ?? 'skip';
                                    $actionBadge = 'bg-secondary';
                                    if ($action === 'step_sl_update') $actionBadge = 'bg-success';
                                    elseif ($action === 'dumb_trailing_set') $actionBadge = 'bg-info';
                                    elseif ($action === 'skip') $actionBadge = 'bg-warning text-dark';
                                    ?>
                                    <span class="badge <?= $actionBadge ?>"><?= htmlspecialchars($action) ?></span>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars($item['reason'] ?? '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Status (Per-Symbol) -->
        <?php if (!empty($status)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-diagram-3 me-2"></i>Symbol Status (from status.json)
            </div>
            <div class="card-body">
                <pre class="mb-0 p-3" style="max-height: 300px; overflow: auto;"><?= htmlspecialchars(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            </div>
        </div>
        <?php endif; ?>

        <!-- Applied Index (History) -->
        <?php if (!empty($appliedIndex)): ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-journal-text me-2"></i>Applied History (from applied_index.json)</span>
                <span class="badge bg-secondary"><?= count($appliedIndex) ?> entries</span>
            </div>
            <div class="card-body">
                <div class="table-responsive" style="max-height: 400px; overflow: auto;">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Symbol</th>
                                <th>Action</th>
                                <th>ROI</th>
                                <th>Old SL</th>
                                <th>New SL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_reverse(array_slice($appliedIndex, -50)) as $entry): ?>
                            <tr>
                                <td class="small"><?= htmlspecialchars($entry['ts'] ?? '') ?></td>
                                <td><strong><?= htmlspecialchars($entry['symbol'] ?? '') ?></strong></td>
                                <td><?= htmlspecialchars($entry['action'] ?? '') ?></td>
                                <td><?= number_format($entry['details']['roi'] ?? $entry['roi'] ?? 0, 2) ?>%</td>
                                <td><?= $entry['details']['old'] ?? $entry['oldSL'] ?? '-' ?></td>
                                <td><?= $entry['details']['new'] ?? $entry['newSL'] ?? '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Config Summary -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-gear me-2"></i>Configuration Summary
            </div>
            <div class="card-body">
                <pre class="mb-0 p-3" style="max-height: 300px; overflow: auto;"><?= htmlspecialchars(json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
