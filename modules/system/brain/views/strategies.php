<?php
/**
 * Brain Module - Strategies View
 * 
 * Displays list of strategies with management options.
 */

use Core\System\System;

// Variables: $strategies, $config, $flash
$brainUrl = rtrim(System::web('admin/brain'), '/');
$pageTitle = 'Brain - Strategies';
$activeTab = 'strategies';

$extraScripts = <<<SCRIPT
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

function editStrategy(id) {
    // TODO: Implement edit modal
    alert('Edit strategy: ' + id);
}

function deleteStrategy(id) {
    if (confirm('Are you sure you want to delete this strategy?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '{$brainUrl}/strategy/delete';
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'id';
        input.value = id;
        
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }
}

// Toggle strategy for simulator
document.querySelectorAll('.simulator-toggle').forEach(toggle => {
    toggle.addEventListener('change', async function() {
        const id = this.dataset.id;
        const enabled = this.checked;
        
        try {
            const result = await fetchJson('{$brainUrl}/strategy/toggle-simulator', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ id, enabled })
            });
            
            if (!result.success) {
                alert('Failed to toggle simulator: ' + (result.error || 'Unknown error'));
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
            if (!confirm('⚠️ Enable live trading for this strategy?\\n\\nMake sure this strategy has been tested in Simulator first!')) {
                this.checked = false;
                return;
            }
        }
        
        try {
            const result = await fetchJson('{$brainUrl}/strategy/toggle-executor', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ id, enabled })
            });
            
            if (!result.success) {
                alert('Failed to toggle executor: ' + (result.error || 'Unknown error'));
                this.checked = !enabled;
            }
        } catch (e) {
            alert('Error: ' + e.message);
            this.checked = !enabled;
        }
    });
});
</script>
SCRIPT;

$pageContent = function() use ($strategies, $brainUrl) {
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-diagram-3 me-2"></i>Strategies Management</h4>
            <p class="text-secondary mb-0">Configure and manage your trading strategies</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#strategyModal">
            <i class="bi bi-plus me-1"></i> New Strategy
        </button>
    </div>

    <!-- Strategies Table -->
    <div class="card">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-table me-2"></i>All Strategies</h6>
        </div>
        <div class="card-body p-0">
            <?php if (empty($strategies)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox display-1 text-secondary"></i>
                <h5 class="mt-3">No Strategies Yet</h5>
                <p class="text-secondary">Create your first strategy to begin trading.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-dark table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>ROI Target</th>
                            <th>Max Drawdown</th>
                            <th>Session</th>
                            <th>Direction</th>
                            <th>Simulator</th>
                            <th>Executor</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($strategies as $id => $strategy): 
                            $constraints = $strategy['constraints'] ?? [];
                            $roiTarget = $constraints['target_roi_pct'] ?? null;
                            $maxDrawdown = $constraints['max_drawdown_pct'] ?? null;
                            $session = $constraints['session'] ?? 'any';
                            $direction = $constraints['direction'] ?? 'both';
                            $simEnabled = $strategy['enabled_for_simulator'] ?? false;
                            $execEnabled = $strategy['enabled_for_executor'] ?? false;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($strategy['name'] ?? $id) ?></strong></td>
                            <td><?= $roiTarget !== null ? htmlspecialchars($roiTarget) . '%' : 'N/A' ?></td>
                            <td><?= $maxDrawdown !== null ? htmlspecialchars($maxDrawdown) . '%' : 'N/A' ?></td>
                            <td><?= htmlspecialchars($session) ?></td>
                            <td><?= htmlspecialchars($direction) ?></td>
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
                                <button class="btn btn-sm btn-outline-primary" onclick="editStrategy('<?= htmlspecialchars($id) ?>')">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteStrategy('<?= htmlspecialchars($id) ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Strategy Modal -->
    <div class="modal fade" id="strategyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="background: var(--card-bg); color: #e2e8f0;">
                <div class="modal-header" style="border-color: var(--border-color);">
                    <h5 class="modal-title">New Strategy</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="<?= $brainUrl ?>/strategy/save" method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Strategy Name</label>
                            <input type="text" name="name" class="form-control bg-dark text-light" required>
                        </div>
                        <div class="row mb-3">
                            <div class="col">
                                <label class="form-label">ROI Target (%)</label>
                                <input type="number" name="roi_target" class="form-control bg-dark text-light" value="10">
                            </div>
                            <div class="col">
                                <label class="form-label">Max Drawdown (%)</label>
                                <input type="number" name="max_drawdown" class="form-control bg-dark text-light" value="5">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col">
                                <label class="form-label">Session</label>
                                <select name="session" class="form-select bg-dark text-light">
                                    <option value="any">Any</option>
                                    <option value="asia">Asia</option>
                                    <option value="europe">Europe</option>
                                    <option value="america">America</option>
                                </select>
                            </div>
                            <div class="col">
                                <label class="form-label">Direction</label>
                                <select name="direction" class="form-select bg-dark text-light">
                                    <option value="both">Both</option>
                                    <option value="long">Long Only</option>
                                    <option value="short">Short Only</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="border-color: var(--border-color);">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Strategy</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php
};

require \Core\System\SystemPaths::instance()->get('system.brain') . '/views/_layout.php';
?>

<?php /* RULES
 * Views must include partials via SystemPaths / PackMap ONLY (no local path computations)
 */ ?>
