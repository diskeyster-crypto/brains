<?php
/**
 * Brain Module - Risk Profiles View
 * 
 * Per ТЗ: Brain Risk Profile v1 + Smart Trailing
 * Displays list of risk profiles with CRUD operations in modal.
 * Uses _layout.php for consistent navigation.
 */

use Core\System\System;

// Variables: $profiles, $activeProfileId, $config, $flash
$brainUrl = rtrim(System::web('admin/brain'), '/');
$pageTitle = 'Brain - Risk Profiles';
$activeTab = 'profiles';

// Get risk_defaults from config for showing defaults
$riskDefaults = $config['risk_defaults'] ?? [];

$extraScripts = <<<SCRIPT
<script>
const brainUrl = '{$brainUrl}';
let profileModal;
let isEditMode = false;

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

document.addEventListener('DOMContentLoaded', function() {
    profileModal = new bootstrap.Modal(document.getElementById('profileModal'));
    
    // Handle TP checkbox toggle
    document.getElementById('tpEnabled').addEventListener('change', function() {
        document.getElementById('tpRoi').disabled = !this.checked;
    });
    
    // Handle form submission
    document.getElementById('profileForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        await saveProfile();
    });
    
    // Handle active profile radio change
    document.querySelectorAll('.active-radio').forEach(radio => {
        radio.addEventListener('change', async function() {
            if (this.checked) {
                await setActiveProfile(this.dataset.id);
            }
        });
    });
});

function showCreateModal() {
    isEditMode = false;
    document.getElementById('profileModalTitle').textContent = 'Create Profile';
    document.getElementById('profileId').disabled = false;
    document.getElementById('profileForm').reset();
    document.getElementById('trailingEnabled').checked = true;
    document.getElementById('tpEnabled').checked = false;
    document.getElementById('tpRoi').disabled = true;
    profileModal.show();
}

async function editProfile(id) {
    isEditMode = true;
    
    try {
        const result = await fetchJson(brainUrl + '/api/profiles/get?id=' + encodeURIComponent(id));
        
        if (!result.success) {
            alert('Failed to load profile: ' + (result.error || 'Unknown error'));
            return;
        }
        
        const profile = result.profile;
        
        document.getElementById('profileModalTitle').textContent = 'Edit Profile';
        document.getElementById('profileId').value = profile.profile_id;
        document.getElementById('profileId').disabled = true;
        document.getElementById('profileTitle').value = profile.title || '';
        document.getElementById('profileBudget').value = profile.budget_usdt_per_trade || 50;
        document.getElementById('profileLeverage').value = profile.leverage || 10;
        document.getElementById('profileSlPct').value = profile.stop_from_liq_range_pct || 20;
        document.getElementById('profileSlippage').value = profile.slippage_bps || 20;
        
        document.getElementById('trailingEnabled').checked = profile.trailing?.enabled ?? true;
        document.getElementById('trailingActivationRoi').value = profile.trailing?.activation_roi_pct ?? 6;
        
        document.getElementById('tpEnabled').checked = profile.take_profit?.enabled ?? false;
        document.getElementById('tpRoi').value = profile.take_profit?.roi_pct ?? 10;
        document.getElementById('tpRoi').disabled = !profile.take_profit?.enabled;
        
        // C1: Load limits
        document.getElementById('maxOpenTrades').value = profile.limits?.max_open_trades ?? 0;
        document.getElementById('oneTradePerSymbol').checked = profile.limits?.one_trade_per_symbol ?? true;
        
        document.getElementById('profileNotes').value = profile.notes || '';
        
        profileModal.show();
    } catch (e) {
        alert('Error loading profile: ' + e.message);
    }
}

async function saveProfile() {
    const profileId = document.getElementById('profileId').value.trim();
    const data = {
        profile_id: profileId,
        title: document.getElementById('profileTitle').value.trim(),
        budget_usdt_per_trade: parseFloat(document.getElementById('profileBudget').value),
        leverage: parseInt(document.getElementById('profileLeverage').value),
        stop_from_liq_range_pct: parseFloat(document.getElementById('profileSlPct').value),
        slippage_bps: parseInt(document.getElementById('profileSlippage').value),
        trailing: {
            enabled: document.getElementById('trailingEnabled').checked,
            activation_roi_pct: parseFloat(document.getElementById('trailingActivationRoi').value)
        },
        take_profit: {
            enabled: document.getElementById('tpEnabled').checked,
            roi_pct: document.getElementById('tpEnabled').checked 
                ? parseFloat(document.getElementById('tpRoi').value) 
                : null
        },
        // C1: Limits section (Brain is single source of truth)
        limits: {
            max_open_trades: parseInt(document.getElementById('maxOpenTrades').value) || 0,
            one_trade_per_symbol: document.getElementById('oneTradePerSymbol').checked
        },
        notes: document.getElementById('profileNotes').value.trim()
    };
    
    const endpoint = isEditMode ? brainUrl + '/api/profiles/update' : brainUrl + '/api/profiles/create';
    
    try {
        const result = await fetchJson(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(data)
        });
        
        if (result.success) {
            profileModal.hide();
            location.reload();
        } else {
            alert('Failed to save profile: ' + (result.errors?.join(', ') || result.error || 'Unknown error'));
        }
    } catch (e) {
        alert('Error saving profile: ' + e.message);
    }
}

async function deleteProfile(id) {
    if (!confirm('Are you sure you want to delete this profile?')) {
        return;
    }
    
    try {
        const result = await fetchJson(brainUrl + '/api/profiles/delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ id })
        });
        
        if (result.success) {
            location.reload();
        } else {
            alert('Failed to delete profile: ' + (result.error || 'Unknown error'));
        }
    } catch (e) {
        alert('Error deleting profile: ' + e.message);
    }
}

async function setActiveProfile(id) {
    try {
        const result = await fetchJson(brainUrl + '/api/profiles/set_active', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ id })
        });
        
        if (result.success) {
            location.reload();
        } else {
            alert('Failed to set active profile: ' + (result.error || 'Unknown error'));
        }
    } catch (e) {
        alert('Error setting active profile: ' + e.message);
    }
}

async function clearActiveProfile() {
    if (!confirm('Clear active profile and use config defaults?')) {
        return;
    }
    
    try {
        const result = await fetchJson(brainUrl + '/api/profiles/set_active', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ id: null })
        });
        
        if (result.success) {
            location.reload();
        } else {
            alert('Failed to clear active profile: ' + (result.error || 'Unknown error'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}
</script>
SCRIPT;

$pageContent = function() use ($profiles, $activeProfileId, $riskDefaults, $brainUrl) {
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-shield-check me-2"></i>Risk Profiles</h4>
            <p class="text-secondary mb-0">Manage risk profiles for position sizing and risk management</p>
        </div>
        <div>
            <button class="btn btn-primary" onclick="showCreateModal()">
                <i class="bi bi-plus-lg me-1"></i>Create Profile
            </button>
        </div>
    </div>

    <div class="row">
        <!-- Profiles List -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>All Profiles</h6>
                    <span class="badge bg-secondary"><?= count($profiles) ?> profiles</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($profiles)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox display-4 text-secondary opacity-50"></i>
                        <p class="text-secondary mt-2">No risk profiles yet. Create one to start.</p>
                        <p><small class="text-muted">Using config defaults: budget <?= number_format((float)($riskDefaults['budget_usdt_per_trade'] ?? 50), 0) ?> USDT, leverage <?= (int)($riskDefaults['leverage'] ?? 10) ?>x</small></p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Active</th>
                                    <th>Profile</th>
                                    <th>Budget</th>
                                    <th>Leverage</th>
                                    <th>SL %</th>
                                    <th>Slippage</th>
                                    <th>Trailing</th>
                                    <th>TP</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($profiles as $profile): 
                                    $isActive = $profile['profile_id'] === $activeProfileId;
                                ?>
                                <tr class="<?= $isActive ? 'table-success' : '' ?>">
                                    <td>
                                        <div class="form-check">
                                            <input class="form-check-input active-radio" 
                                                   type="radio" 
                                                   name="activeProfile" 
                                                   data-id="<?= htmlspecialchars($profile['profile_id']) ?>"
                                                   <?= $isActive ? 'checked' : '' ?>>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($profile['title']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($profile['profile_id']) ?></small>
                                    </td>
                                    <td><?= number_format($profile['budget_usdt_per_trade'], 0) ?> USDT</td>
                                    <td><?= $profile['leverage'] ?>x</td>
                                    <td><?= $profile['stop_from_liq_range_pct'] ?>%</td>
                                    <td><?= $profile['slippage_bps'] ?> bps</td>
                                    <td>
                                        <?php if ($profile['trailing']['enabled'] ?? false): ?>
                                        <span class="badge bg-success">ON <?= $profile['trailing']['activation_roi_pct'] ?? 0 ?>%</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">OFF</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($profile['take_profit']['enabled'] ?? false): ?>
                                        <span class="badge bg-info"><?= $profile['take_profit']['roi_pct'] ?? 0 ?>%</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">OFF</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" onclick="editProfile('<?= htmlspecialchars($profile['profile_id']) ?>')" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-outline-danger" onclick="deleteProfile('<?= htmlspecialchars($profile['profile_id']) ?>')" title="Delete" <?= $isActive ? 'disabled' : '' ?>>
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Active Profile Info / Defaults -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Current Risk Settings</h6>
                </div>
                <div class="card-body">
                    <?php if ($activeProfileId && isset($profiles[$activeProfileId])): 
                        $active = $profiles[$activeProfileId];
                    ?>
                    <h6 class="text-success">Active Profile: <?= htmlspecialchars($active['title']) ?></h6>
                    <hr style="border-color: var(--border-color);">
                    <dl class="row mb-0">
                        <dt class="col-6">Budget:</dt>
                        <dd class="col-6"><?= number_format($active['budget_usdt_per_trade'], 0) ?> USDT</dd>
                        
                        <dt class="col-6">Leverage:</dt>
                        <dd class="col-6"><?= $active['leverage'] ?>x</dd>
                        
                        <dt class="col-6">SL Range:</dt>
                        <dd class="col-6"><?= $active['stop_from_liq_range_pct'] ?>% of Entry↔Liq</dd>
                        
                        <dt class="col-6">Slippage:</dt>
                        <dd class="col-6"><?= $active['slippage_bps'] ?> bps (<?= number_format($active['slippage_bps'] / 100, 2) ?>%)</dd>
                        
                        <dt class="col-6">Trailing:</dt>
                        <dd class="col-6">
                            <?php if ($active['trailing']['enabled'] ?? false): ?>
                            Activate at <?= $active['trailing']['activation_roi_pct'] ?>% ROI
                            <?php else: ?>
                            Disabled
                            <?php endif; ?>
                        </dd>
                        
                        <dt class="col-6">Take Profit:</dt>
                        <dd class="col-6">
                            <?php if ($active['take_profit']['enabled'] ?? false): ?>
                            <?= $active['take_profit']['roi_pct'] ?>% ROI
                            <?php else: ?>
                            Disabled
                            <?php endif; ?>
                        </dd>
                    </dl>
                    <hr style="border-color: var(--border-color);">
                    <button class="btn btn-outline-secondary btn-sm w-100" onclick="clearActiveProfile()">
                        <i class="bi bi-x-circle me-1"></i>Clear Active (Use Defaults)
                    </button>
                    <?php else: ?>
                    <h6 class="text-muted">Using Config Defaults</h6>
                    <hr style="border-color: var(--border-color);">
                    <dl class="row mb-0">
                        <dt class="col-6">Budget:</dt>
                        <dd class="col-6"><?= number_format($riskDefaults['budget_usdt_per_trade'] ?? 50, 0) ?> USDT</dd>
                        
                        <dt class="col-6">Leverage:</dt>
                        <dd class="col-6"><?= $riskDefaults['leverage'] ?? 10 ?>x</dd>
                        
                        <dt class="col-6">SL Range:</dt>
                        <dd class="col-6"><?= $riskDefaults['stop_from_liq_range_pct'] ?? 20 ?>%</dd>
                        
                        <dt class="col-6">Slippage:</dt>
                        <dd class="col-6"><?= $riskDefaults['slippage_bps'] ?? 20 ?> bps</dd>
                        
                        <dt class="col-6">Trailing:</dt>
                        <dd class="col-6">
                            <?php if ($riskDefaults['trailing']['enabled'] ?? true): ?>
                            <?= $riskDefaults['trailing']['activation_roi_pct'] ?? 6 ?>% ROI
                            <?php else: ?>
                            Disabled
                            <?php endif; ?>
                        </dd>
                        
                        <dt class="col-6">Take Profit:</dt>
                        <dd class="col-6">
                            <?php if ($riskDefaults['take_profit']['enabled'] ?? false): ?>
                            <?= $riskDefaults['take_profit']['roi_pct'] ?? 0 ?>% ROI
                            <?php else: ?>
                            Disabled
                            <?php endif; ?>
                        </dd>
                    </dl>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Help Card -->
            <div class="card mt-3">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-question-circle me-1"></i>About Risk Profiles</h6>
                </div>
                <div class="card-body small">
                    <p><strong>Budget:</strong> Margin per trade in USDT.</p>
                    <p><strong>Leverage:</strong> Position multiplier (1-125x).</p>
                    <p><strong>SL Range %:</strong> Stop Loss at X% of Entry↔Liquidation distance.</p>
                    <p><strong>Slippage:</strong> Simulated market slippage in basis points.</p>
                    <p><strong>Trailing:</strong> Smart trailing activates at ROI%, protects profits.</p>
                    <p class="mb-0"><strong>TP:</strong> Take Profit at ROI% (optional).</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Create/Edit Profile Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background: var(--card-bg); color: #e2e8f0;">
                <div class="modal-header" style="border-color: var(--border-color);">
                    <h5 class="modal-title" id="profileModalTitle">Create Profile</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="profileForm">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Profile ID <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="profile_id" id="profileId" 
                                           pattern="[a-zA-Z0-9_]+" required placeholder="e.g., scalp_usdt_10x">
                                    <small class="form-text text-muted">Letters, numbers, underscores only</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Title <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="title" id="profileTitle" 
                                           required placeholder="e.g., Scalp USDT 10x">
                                </div>
                            </div>
                        </div>
                        
                        <hr style="border-color: var(--border-color);">
                        <h6>Position Sizing</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Budget per Trade (USDT) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="budget_usdt_per_trade" 
                                           id="profileBudget" min="1" step="1" required value="50">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Leverage <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="leverage" 
                                           id="profileLeverage" min="1" max="125" required value="10">
                                </div>
                            </div>
                        </div>
                        
                        <hr style="border-color: var(--border-color);">
                        <h6>Stop Loss</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">SL from Liq Range (%) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="stop_from_liq_range_pct" 
                                           id="profileSlPct" min="1" max="90" step="1" required value="20">
                                    <small class="form-text text-muted">1-90% of Entry↔Liquidation distance</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Slippage (bps) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="slippage_bps" 
                                           id="profileSlippage" min="0" max="100" required value="20">
                                    <small class="form-text text-muted">20 bps = 0.2%</small>
                                </div>
                            </div>
                        </div>
                        
                        <hr style="border-color: var(--border-color);">
                        <h6>Trailing Stop (Smart)</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="trailingEnabled" checked>
                                    <label class="form-check-label" for="trailingEnabled">Enable Trailing</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Activation ROI (%)</label>
                                    <input type="number" class="form-control" name="trailing_activation_roi_pct" 
                                           id="trailingActivationRoi" min="0.1" step="0.1" value="6">
                                </div>
                            </div>
                        </div>
                        
                        <hr style="border-color: var(--border-color);">
                        <h6>Take Profit</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="tpEnabled">
                                    <label class="form-check-label" for="tpEnabled">Enable Take Profit</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">TP ROI (%)</label>
                                    <input type="number" class="form-control" name="take_profit_roi_pct" 
                                           id="tpRoi" min="0.1" step="0.1" value="10" disabled>
                                </div>
                            </div>
                        </div>
                        
                        <hr style="border-color: var(--border-color);">
                        <h6>Trading Limits</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Max Open Trades</label>
                                    <input type="number" class="form-control" name="max_open_trades" 
                                           id="maxOpenTrades" min="0" step="1" value="0">
                                    <small class="form-text text-muted">0 = unlimited</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3 form-check mt-4">
                                    <input type="checkbox" class="form-check-input" id="oneTradePerSymbol" checked>
                                    <label class="form-check-label" for="oneTradePerSymbol">One Trade Per Symbol</label>
                                </div>
                            </div>
                        </div>
                        
                        <hr style="border-color: var(--border-color);">
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" id="profileNotes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer" style="border-color: var(--border-color);">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="profileSubmitBtn">
                            <i class="bi bi-save me-1"></i>Save Profile
                        </button>
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
