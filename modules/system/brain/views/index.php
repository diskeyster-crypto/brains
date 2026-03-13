<?php
/**
 * Brain Module - Meta-Orchestrator Dashboard
 * 
 * Brain is a CONTROLLER, not an analytics engine.
 * It manages strategies and orchestrates the pipeline:
 *   Parser4 → Parser5 → Simulator → Executor
 */

use Core\System\System;

// Variables: $strategies, $stats, $lastRun, $config, $moduleStatus, $flash
$totalStrategies = $stats['total_strategies'] ?? 0;
$simulatorEnabled = $stats['simulator_enabled'] ?? 0;
$executorEnabled = $stats['executor_enabled'] ?? 0;
$successfulRuns = $stats['successful_runs'] ?? 0;

$uiConfig = $config['ui'] ?? [];
$sessions = $uiConfig['sessions'] ?? ['any' => 'Any', 'asia' => 'Asia', 'europe' => 'Europe', 'america' => 'America'];
$directions = $uiConfig['directions'] ?? ['both' => 'Both', 'long' => 'Long Only', 'short' => 'Short Only'];

$brainUrl = rtrim(System::web('admin/brain'), '/');
$pageTitle = 'Brain - Meta-Orchestrator';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #3b82f6;
            --card-bg: #1e293b;
            --border-color: #334155;
        }
        body { background: #0f172a; color: #e2e8f0; }
        .card { background: var(--card-bg); border: 1px solid var(--border-color); }
        .card-header { background: rgba(0,0,0,0.2); border-bottom: 1px solid var(--border-color); }
        .table-dark { --bs-table-bg: transparent; }
        .form-control, .form-select { background: #1e293b; border-color: var(--border-color); color: #e2e8f0; }
        .form-control:focus, .form-select:focus { background: #1e293b; border-color: var(--primary); color: #e2e8f0; }
        .nav-tabs { border-bottom: 1px solid var(--border-color); }
        .nav-tabs .nav-link { color: #94a3b8; border: none; }
        .nav-tabs .nav-link:hover { color: #e2e8f0; border: none; }
        .nav-tabs .nav-link.active { color: #3b82f6; background: transparent; border-bottom: 2px solid #3b82f6; }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <!-- Header with Navigation -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1">
                    <i class="bi bi-cpu text-primary me-2"></i>
                    Brain: Meta-Orchestrator
                </h1>
                <p class="text-muted mb-0">Manages strategies and orchestrates the pipeline: Parser4 → Parser5 → Simulator → Executor</p>
            </div>
            <div class="btn-group">
                <a href="/admin" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>
                <form method="POST" action="<?= $brainUrl ?>/run" style="display: inline;">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-play-fill me-1"></i> Run
                    </button>
                </form>
                <button class="btn btn-primary" onclick="showCreateModal()">
                    <i class="bi bi-plus-lg me-1"></i> New Strategy
                </button>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <?php require \Core\System\SystemPaths::instance()->get('system.brain') . '/views/_tabs.php'; ?>

<!-- Flash Message -->
<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom: 20px;">
    <?= htmlspecialchars($flash['message']) ?>
</div>
<?php endif; ?>

<!-- Architecture Info -->
<div class="alert alert-info" style="margin-bottom: 20px; background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.3);">
    <strong><i class="bi bi-info-circle"></i> Brain = Meta-Orchestrator</strong><br>
    Brain manages strategies and orchestrates the pipeline. It does NOT calculate prices or generate signals.<br>
    <small>Pipeline: Parser4 (candidates) → Parser5 (signals) → Simulator (feedback) → Executor (live)</small>
</div>

<!-- Stats Cards -->
<div class="row" style="margin-bottom: 24px;">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h3 style="font-size: 2rem; font-weight: bold; color: var(--primary);"><?= $totalStrategies ?></h3>
                <p style="margin: 0; color: #94a3b8;">Total Strategies</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h3 style="font-size: 2rem; font-weight: bold; color: #f59e0b;"><?= $simulatorEnabled ?></h3>
                <p style="margin: 0; color: #94a3b8;">Simulator Enabled</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h3 style="font-size: 2rem; font-weight: bold; color: #10b981;"><?= $executorEnabled ?></h3>
                <p style="margin: 0; color: #94a3b8;">Executor Enabled</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h3 style="font-size: 2rem; font-weight: bold; color: #3b82f6;"><?= $successfulRuns ?></h3>
                <p style="margin: 0; color: #94a3b8;">Successful Runs</p>
            </div>
        </div>
    </div>
</div>

<!-- Module Status -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h5 style="margin: 0;"><i class="bi bi-diagram-3"></i> Pipeline Modules Status</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <?php foreach ($moduleStatus as $name => $status): ?>
            <div class="col-md-3">
                <div style="padding: 12px; border-radius: 8px; background: <?= ($status['available'] ?? false) ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)' ?>;">
                    <div style="display: flex; align-items: center; margin-bottom: 8px;">
                        <span style="color: <?= ($status['available'] ?? false) ? '#10b981' : '#ef4444' ?>; margin-right: 8px;">
                            <i class="bi bi-<?= ($status['available'] ?? false) ? 'check-circle-fill' : 'x-circle-fill' ?>"></i>
                        </span>
                        <strong><?= ucfirst($name) ?></strong>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Last Run Info -->
<?php if ($lastRun): ?>
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h5 style="margin: 0;"><i class="bi bi-lightning-charge"></i> Last Orchestration Run</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <strong>Status:</strong><br>
                <?php if ($lastRun['ok'] ?? false): ?>
                    <span class="badge bg-success">OK</span>
                <?php else: ?>
                    <span class="badge bg-danger">ERROR</span>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <strong>Time:</strong><br>
                <code><?= htmlspecialchars($lastRun['timestamp'] ?? 'N/A') ?></code>
            </div>
            <div class="col-md-2">
                <strong>Duration:</strong><br>
                <code><?= number_format($lastRun['duration_ms'] ?? 0) ?>ms</code>
            </div>
            <div class="col-md-3">
                <strong>Steps Completed:</strong><br>
                <span style="font-weight: bold;"><?= $lastRun['steps'] ?? 0 ?></span>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Strategies List -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 style="margin: 0;"><i class="bi bi-list-check"></i> Strategy Profiles</h5>
        <span class="badge bg-secondary"><?= $totalStrategies ?> strategies</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($strategies)): ?>
            <div style="padding: 40px; text-align: center; color: #64748b;">
                <i class="bi bi-inbox" style="font-size: 3rem; opacity: 0.5;"></i>
                <p style="margin-top: 16px;">No strategies yet. Create your first strategy profile.</p>
                <button type="button" class="btn btn-primary" onclick="showCreateModal()">
                    <i class="bi bi-plus-lg"></i> Create Strategy
                </button>
            </div>
        <?php else: ?>
            <table class="table table-dark table-hover" style="margin: 0;">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Target ROI</th>
                        <th>Max DD</th>
                        <th>Direction</th>
                        <th>Simulator</th>
                        <th>Executor</th>
                        <th>Performance</th>
                        <th style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($strategies as $id => $strategy): ?>
                        <?php 
                        $constraints = $strategy['constraints'] ?? [];
                        $performance = $strategy['performance'] ?? [];
                        $simEnabled = $strategy['enabled_for_simulator'] ?? false;
                        $execEnabled = $strategy['enabled_for_executor'] ?? false;
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($strategy['name'] ?? 'Unnamed') ?></strong>
                                <?php if (!empty($strategy['description'])): ?>
                                    <br><small style="color: #64748b;"><?= htmlspecialchars($strategy['description']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="color: #10b981; font-weight: bold;">
                                    <?= number_format($constraints['target_roi_pct'] ?? 5, 1) ?>%
                                </span>
                            </td>
                            <td>
                                <span style="color: #ef4444;">
                                    <?= number_format($constraints['max_drawdown_pct'] ?? 30, 1) ?>%
                                </span>
                            </td>
                            <td>
                                <?php 
                                $dir = $constraints['direction'] ?? 'both';
                                $dirLabel = $directions[$dir] ?? ucfirst($dir);
                                $dirColor = $dir === 'long' ? '#10b981' : ($dir === 'short' ? '#ef4444' : '#64748b');
                                ?>
                                <span style="color: <?= $dirColor ?>;"><?= htmlspecialchars($dirLabel) ?></span>
                            </td>
                            <td>
                                <div class="form-check form-switch">
                                    <input type="checkbox" 
                                           class="form-check-input simulator-toggle" 
                                           data-id="<?= htmlspecialchars($id) ?>"
                                           <?= $simEnabled ? 'checked' : '' ?>>
                                </div>
                            </td>
                            <td>
                                <div class="form-check form-switch">
                                    <input type="checkbox" 
                                           class="form-check-input executor-toggle" 
                                           data-id="<?= htmlspecialchars($id) ?>"
                                           <?= $execEnabled ? 'checked' : '' ?>>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($performance['win_rate'])): ?>
                                    <span style="color: #10b981; font-weight: bold;"><?= number_format($performance['win_rate'], 1) ?>%</span> WR
                                    <br><small style="color: #64748b;"><?= $performance['total_signals'] ?? 0 ?> signals</small>
                                <?php else: ?>
                                    <small style="color: #64748b;">No data</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-primary btn-sm" 
                                            onclick="editStrategy('<?= htmlspecialchars($id) ?>')"
                                            title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm" 
                                            onclick="deleteStrategy('<?= htmlspecialchars($id) ?>')"
                                            title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Create/Edit Strategy Modal -->
<div class="modal fade" id="strategyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background: var(--card-bg); border: 1px solid var(--border-color);">
            <div class="modal-header" style="border-bottom-color: var(--border-color);">
                <h5 class="modal-title" id="modalTitle">New Strategy Profile</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="strategyForm" method="POST" action="<?= $brainUrl ?>/strategy/save">
                <div class="modal-body">
                    <input type="hidden" name="id" id="strategyId">
                    <input type="hidden" name="created_at" id="strategyCreatedAt">
                    
                    <!-- Info Alert -->
                    <div class="alert alert-secondary" style="font-size: 0.85rem; padding: 10px;">
                        <i class="bi bi-info-circle"></i> 
                        Strategy = trader's wishes/constraints. Brain passes these to Parser5 as-is, without interpreting them mathematically.
                    </div>
                    
                    <!-- Basic Info -->
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label">Strategy Name *</label>
                            <input type="text" name="name" id="strategyName" class="form-control" required 
                                   placeholder="e.g., Conservative Daily Trading">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="strategyDescription" class="form-control" rows="2"
                                  placeholder="Brief description of this strategy profile..."></textarea>
                    </div>
                    
                    <hr style="border-color: var(--border-color);">
                    
                    <!-- Constraints (wishes) -->
                    <h6 style="margin-bottom: 16px;"><i class="bi bi-sliders"></i> Constraints (passed to Parser5)</h6>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Target ROI (%)</label>
                            <input type="number" name="target_roi_pct" id="targetRoi" class="form-control" 
                                   step="0.1" min="0.5" max="100" value="5.0">
                            <small class="text-muted">Desired profit target (Parser5 will use this)</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Max Drawdown (%)</label>
                            <input type="number" name="max_drawdown_pct" id="maxDrawdown" class="form-control" 
                                   step="0.1" min="1" max="100" value="30.0">
                            <small class="text-muted">Maximum acceptable drawdown</small>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Direction</label>
                            <select name="direction" id="direction" class="form-select">
                                <?php foreach ($directions as $key => $label): ?>
                                    <option value="<?= htmlspecialchars($key) ?>" <?= $key === 'both' ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Session</label>
                            <select name="session" id="session" class="form-select">
                                <?php foreach ($sessions as $key => $label): ?>
                                    <option value="<?= htmlspecialchars($key) ?>" <?= $key === 'any' ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Max Duration (min)</label>
                            <input type="number" name="duration_max_min" id="durationMax" class="form-control" 
                                   min="5" max="10080" value="120">
                        </div>
                    </div>
                    
                    <hr style="border-color: var(--border-color);">
                    
                    <!-- Enable Flags -->
                    <h6 style="margin-bottom: 16px;"><i class="bi bi-toggle-on"></i> Enable For</h6>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input type="checkbox" name="enabled_for_simulator" id="enabledSimulator" class="form-check-input" checked>
                                <label class="form-check-label" for="enabledSimulator">
                                    <strong>Simulator (Parser6)</strong><br>
                                    <small class="text-muted">Run in simulation mode to test performance</small>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input type="checkbox" name="enabled_for_executor" id="enabledExecutor" class="form-check-input">
                                <label class="form-check-label" for="enabledExecutor">
                                    <strong>Executor (Live Trading)</strong><br>
                                    <small class="text-muted text-warning">⚠️ Enable only after successful simulation</small>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top-color: var(--border-color);">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Strategy</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content" style="background: var(--card-bg); border: 1px solid var(--border-color);">
            <div class="modal-header" style="border-bottom-color: var(--border-color);">
                <h5 class="modal-title">Delete Strategy</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this strategy?</p>
                <p><strong id="deleteStrategyName"></strong></p>
                <p class="text-danger">This action cannot be undone.</p>
            </div>
            <div class="modal-footer" style="border-top-color: var(--border-color);">
                <form method="POST" action="<?= $brainUrl ?>/strategy/delete" id="deleteForm">
                    <input type="hidden" name="id" id="deleteStrategyId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// C-3: Unified fetchJson helper for safe JSON response handling
async function fetchJson(url, options) {
    const resp = await fetch(url, options || {});
    const text = await resp.text();

    if (!resp.ok) {
        throw new Error('HTTP ' + resp.status + ': ' + text);
    }

    try {
        return JSON.parse(text);
    } catch (e) {
        throw new Error('Invalid JSON (' + resp.status + '): ' + text);
    }
}

// Strategy data for editing (escaped for safe embedding in script)
const strategies = <?= json_encode($strategies, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// Show create modal
function showCreateModal() {
    document.getElementById('modalTitle').textContent = 'New Strategy Profile';
    document.getElementById('strategyForm').reset();
    document.getElementById('strategyId').value = '';
    document.getElementById('strategyCreatedAt').value = '';
    
    // Set defaults
    document.getElementById('targetRoi').value = '5.0';
    document.getElementById('maxDrawdown').value = '30.0';
    document.getElementById('durationMax').value = '120';
    document.getElementById('enabledSimulator').checked = true;
    document.getElementById('enabledExecutor').checked = false;
    
    const modal = new bootstrap.Modal(document.getElementById('strategyModal'));
    modal.show();
}

// Edit strategy
function editStrategy(id) {
    const strategy = strategies[id];
    if (!strategy) return;
    
    document.getElementById('modalTitle').textContent = 'Edit Strategy Profile';
    document.getElementById('strategyId').value = id;
    document.getElementById('strategyCreatedAt').value = strategy.created_at || '';
    document.getElementById('strategyName').value = strategy.name || '';
    document.getElementById('strategyDescription').value = strategy.description || '';
    
    const constraints = strategy.constraints || {};
    document.getElementById('targetRoi').value = constraints.target_roi_pct || 5.0;
    document.getElementById('maxDrawdown').value = constraints.max_drawdown_pct || 30.0;
    document.getElementById('direction').value = constraints.direction || 'both';
    document.getElementById('session').value = constraints.session || 'any';
    document.getElementById('durationMax').value = constraints.duration_max_min || 120;
    
    document.getElementById('enabledSimulator').checked = strategy.enabled_for_simulator || false;
    document.getElementById('enabledExecutor').checked = strategy.enabled_for_executor || false;
    
    const modal = new bootstrap.Modal(document.getElementById('strategyModal'));
    modal.show();
}

// Delete strategy
function deleteStrategy(id) {
    const strategy = strategies[id];
    if (!strategy) return;
    
    document.getElementById('deleteStrategyId').value = id;
    document.getElementById('deleteStrategyName').textContent = strategy.name || 'Unnamed';
    
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}

// Toggle strategy for simulator
document.querySelectorAll('.simulator-toggle').forEach(toggle => {
    toggle.addEventListener('change', async function() {
        const id = this.dataset.id;
        const enabled = this.checked;
        
        try {
            const result = await fetchJson('<?= $brainUrl ?>/strategy/toggle-simulator', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ id, enabled })
            });
            
            if (!result.success) {
                alert('Failed to toggle: ' + (result.error || 'Unknown error'));
                this.checked = !enabled;
            }
        } catch (e) {
            alert('Error: ' + e.message);
            this.checked = !enabled;
        }
    });
});

// Toggle strategy for executor
document.querySelectorAll('.executor-toggle').forEach(toggle => {
    toggle.addEventListener('change', async function() {
        const id = this.dataset.id;
        const enabled = this.checked;
        
        // Confirm if enabling for executor
        if (enabled) {
            if (!confirm('⚠️ Enable live trading for this strategy?\n\nMake sure this strategy has been tested in Simulator first!')) {
                this.checked = false;
                return;
            }
        }
        
        try {
            const result = await fetchJson('<?= $brainUrl ?>/strategy/toggle-executor', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ id, enabled })
            });
            
            if (!result.success) {
                alert('Failed to toggle: ' + (result.error || 'Unknown error'));
                this.checked = !enabled;
            }
        } catch (e) {
            alert('Error: ' + e.message);
            this.checked = !enabled;
        }
    });
});
</script>

    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php /* RULES
 * Views must include partials via SystemPaths / PackMap ONLY (no local path computations)
 */ ?>
