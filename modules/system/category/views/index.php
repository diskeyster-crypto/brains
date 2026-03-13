<?php

declare(strict_types=1);

use Core\System\System;

/**
 * Category Modules Manager View
 * 
 * Generic view for module categories (signals, trading, etc.)
 * Variables from controller:
 *   - $modules: array of discovered modules
 *   - $flash: flash message or null
 *   - $category: category name (signal, trading, etc.)
 *   - $categoryTitle: display title (Signals, Trading, etc.)
 */

$actionUrl = System::web("admin/{$category}/action");
$apiBaseUrl = System::web("admin/{$category}/api");
?>

<div class="category-manager">
    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="section-header">
        <h2><?= htmlspecialchars($categoryTitle, ENT_QUOTES, 'UTF-8') ?></h2>
        <span class="badge"><?= count($modules) ?> modules</span>
    </div>

    <?php if (empty($modules)): ?>
        <div class="empty-state">
            <p>No <?= htmlspecialchars(strtolower($categoryTitle), ENT_QUOTES, 'UTF-8') ?> modules found.</p>
            <p class="text-muted">Add modules to <code>modules/<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>/</code></p>
        </div>
    <?php else: ?>
        <div class="modules-table">
            <table>
                <thead>
                    <tr>
                        <th>MODULE</th>
                        <th>STATUS</th>
                        <th>LAST RUN</th>
                        <th>DURATION</th>
                        <th>CRON</th>
                        <th>ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($modules as $module): ?>
                        <tr data-module="<?= htmlspecialchars($module['name'], ENT_QUOTES, 'UTF-8') ?>">
                            <td>
                                <div class="module-name">
                                    <strong><?= htmlspecialchars($module['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php if (!empty($module['description'])): ?>
                                        <small class="text-muted"><?= htmlspecialchars($module['description'], ENT_QUOTES, 'UTF-8') ?></small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($module['enabled']): ?>
                                    <span class="status-badge status-<?= $module['status'] ?>">
                                        <?= $module['status'] === 'ok' ? '✓ Active' : '⚠ Error' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge status-disabled">Disabled</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($module['last_run']): ?>
                                    <span class="datetime"><?= htmlspecialchars($module['last_run'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($module['duration_ms']): ?>
                                    <?= number_format($module['duration_ms'], 0) ?>ms
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code><?= htmlspecialchars($module['cron_interval'], ENT_QUOTES, 'UTF-8') ?></code>
                            </td>
                            <td class="actions">
                                <button type="button" class="btn btn-sm btn-primary" onclick="runModule('<?= htmlspecialchars($module['name'], ENT_QUOTES, 'UTF-8') ?>')">
                                    Run
                                </button>
                                <?php if ($module['has_config']): ?>
                                    <button type="button" class="btn btn-sm" onclick="openSettings('<?= htmlspecialchars($module['name'], ENT_QUOTES, 'UTF-8') ?>')">
                                        Settings
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Run Modal -->
<div id="runModal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Run: <span id="runModuleName"></span></h3>
            <button type="button" class="modal-close" onclick="closeModal('runModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div id="runStatus">Starting...</div>
            <div id="runResult" class="result-output" style="display: none;"></div>
        </div>
    </div>
</div>

<!-- Settings Modal -->
<div id="settingsModal" class="modal" style="display: none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3>Settings: <span id="settingsModuleName"></span></h3>
            <button type="button" class="modal-close" onclick="closeModal('settingsModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div id="settingsContent">Loading...</div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn" onclick="closeModal('settingsModal')">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="saveSettings()">Save</button>
        </div>
    </div>
</div>

<script>
const API_BASE = '<?= htmlspecialchars($apiBaseUrl, ENT_QUOTES, 'UTF-8') ?>';

function runModule(moduleName) {
    document.getElementById('runModuleName').textContent = moduleName;
    document.getElementById('runStatus').innerHTML = '<span class="loading">Running...</span>';
    document.getElementById('runResult').style.display = 'none';
    document.getElementById('runModal').style.display = 'flex';
    
    fetch(API_BASE + '/run', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ module: moduleName })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('runStatus').innerHTML = '<span class="text-success">✓ Success</span>';
        } else {
            document.getElementById('runStatus').innerHTML = '<span class="text-danger">✗ Failed</span>';
        }
        if (data.message) {
            document.getElementById('runResult').textContent = data.message;
            document.getElementById('runResult').style.display = 'block';
        }
    })
    .catch(e => {
        document.getElementById('runStatus').innerHTML = '<span class="text-danger">✗ Error</span>';
        document.getElementById('runResult').textContent = e.message;
        document.getElementById('runResult').style.display = 'block';
    });
}

function openSettings(moduleName) {
    document.getElementById('settingsModuleName').textContent = moduleName;
    document.getElementById('settingsContent').innerHTML = '<div class="loading">Loading...</div>';
    document.getElementById('settingsModal').style.display = 'flex';
    document.getElementById('settingsModal').dataset.module = moduleName;
    
    fetch(API_BASE + '/config?module=' + encodeURIComponent(moduleName))
    .then(r => r.json())
    .then(data => {
        if (data.success && data.config) {
            let html = '<form id="settingsForm">';
            for (const [key, value] of Object.entries(data.config)) {
                if (key === 'ui' || typeof value === 'object') continue;
                html += `<div class="form-group">
                    <label>${key}</label>
                    <input type="text" name="${key}" value="${value}" class="form-control">
                </div>`;
            }
            html += '</form>';
            document.getElementById('settingsContent').innerHTML = html;
        } else {
            document.getElementById('settingsContent').innerHTML = '<p class="text-danger">Failed to load config</p>';
        }
    })
    .catch(e => {
        document.getElementById('settingsContent').innerHTML = '<p class="text-danger">' + e.message + '</p>';
    });
}

function saveSettings() {
    const modal = document.getElementById('settingsModal');
    const moduleName = modal.dataset.module;
    const form = document.getElementById('settingsForm');
    
    if (!form) return;
    
    const config = {};
    const inputs = form.querySelectorAll('input, select, textarea');
    inputs.forEach(input => {
        config[input.name] = input.value;
    });
    
    fetch(API_BASE + '/config/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ module: moduleName, config: config })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeModal('settingsModal');
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Failed to save'));
        }
    })
    .catch(e => alert('Error: ' + e.message));
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

// Close modal on outside click
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', e => {
        if (e.target === modal) closeModal(modal.id);
    });
});
</script>

<style>
.category-manager { padding: 20px; }
.section-header { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
.section-header h2 { margin: 0; }
.badge { background: var(--primary); color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; }
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
.modules-table table { width: 100%; border-collapse: collapse; }
.modules-table th, .modules-table td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border); }
.modules-table th { font-weight: 600; font-size: 11px; text-transform: uppercase; color: var(--text-muted); }
.module-name strong { display: block; }
.module-name small { font-size: 12px; }
.status-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; }
.status-ok { background: #1a4d2e; color: #4ade80; }
.status-error { background: #4d1a1a; color: #f87171; }
.status-disabled { background: #333; color: #888; }
.actions { display: flex; gap: 8px; }
.modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-card); border-radius: 8px; min-width: 400px; max-width: 90%; max-height: 90%; overflow: auto; }
.modal-lg { min-width: 600px; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-bottom: 1px solid var(--border); }
.modal-header h3 { margin: 0; }
.modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted); }
.modal-body { padding: 20px; }
.modal-footer { padding: 15px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; }
.result-output { background: var(--bg); padding: 10px; border-radius: 4px; margin-top: 10px; font-family: monospace; font-size: 13px; white-space: pre-wrap; }
.form-group { margin-bottom: 15px; }
.form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
.form-control { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 4px; background: var(--bg); color: var(--text); }
.loading { color: var(--primary); }
.text-success { color: #4ade80; }
.text-danger { color: #f87171; }
.text-muted { color: var(--text-muted); }
</style>
