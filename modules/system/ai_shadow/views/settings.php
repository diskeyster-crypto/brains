<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Shadow — Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --primary: #3b82f6; --card-bg: #1e293b; --border-color: #334155; }
        body { background: #0f172a; color: #e2e8f0; font-size: 0.9rem; }
        .card { background: var(--card-bg); border: 1px solid var(--border-color); }
        .card-header { background: rgba(0,0,0,0.2); border-bottom: 1px solid var(--border-color); }
        .form-control, .form-select {
            background: #1e293b; border-color: var(--border-color); color: #e2e8f0;
        }
        .form-control:focus, .form-select:focus {
            background: #1e293b; border-color: var(--primary); color: #e2e8f0; box-shadow: none;
        }
        .form-check-input { background-color: #334155; border-color: var(--border-color); }
        .form-check-input:checked { background-color: var(--primary); border-color: var(--primary); }
        pre.config-preview {
            background: #0d1117; color: #58a6ff; border: 1px solid var(--border-color);
            border-radius: 6px; padding: 1rem; font-size: 0.8rem; white-space: pre-wrap;
        }
        #save-result { display: none; margin-top: 0.75rem; }
    </style>
</head>
<body>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">
                <i class="bi bi-gear text-primary me-2"></i>
                AI Shadow — Settings
            </h4>
            <p class="text-muted mb-0">Configure the AI shadow simulation module</p>
        </div>
        <a href="/admin/smart_brain/ai_shadow" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Dashboard
        </a>
    </div>

    <div class="row g-4">
        <!-- Settings Form -->
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header py-2">
                    <i class="bi bi-sliders me-1"></i> Configuration
                </div>
                <div class="card-body">
                    <form id="settings-form">

                        <!-- Enable / Mode -->
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label">Module</label>
                                <div class="form-check form-switch mt-1">
                                    <input class="form-check-input" type="checkbox" id="cfg-enabled"
                                           <?= ($config['enabled'] ?? false) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="cfg-enabled">Enabled</label>
                                </div>
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="cfg-provider">AI Provider</label>
                                <select class="form-select form-select-sm" id="cfg-provider" onchange="toggleProviderFields()">
                                    <option value="mock"   <?= ($config['provider'] ?? 'mock') === 'mock'   ? 'selected' : '' ?>>mock (deterministic stub)</option>
                                    <option value="openai" <?= ($config['provider'] ?? 'mock') === 'openai' ? 'selected' : '' ?>>openai (real provider)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Simulate on -->
                        <div class="row g-3 mb-3" id="provider-openai-fields" style="<?= ($config['provider'] ?? 'mock') !== 'openai' ? 'display:none' : '' ?>">
                            <div class="col-6">
                                <label class="form-label" for="cfg-model">Model</label>
                                <input type="text" class="form-control form-control-sm" id="cfg-model"
                                       placeholder="gpt-4o-mini"
                                       value="<?= htmlspecialchars((string)($config['model'] ?? '')) ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="cfg-credential-id">Credential ID (KeyCenter)</label>
                                <input type="text" class="form-control form-control-sm" id="cfg-credential-id"
                                       placeholder="e.g. openai_main"
                                       value="<?= htmlspecialchars((string)($config['credential_id'] ?? '')) ?>">
                                <div class="form-text text-muted" style="font-size:0.75rem;">
                                    References KeyCenter — no raw API keys stored here.
                                </div>
                            </div>
                        </div>

                        <!-- Simulate on -->
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="cfg-sim-signals"
                                           <?= ($config['simulate_on_live_signals'] ?? true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="cfg-sim-signals">Simulate on Live Signals</label>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="cfg-sim-trades"
                                           <?= ($config['simulate_on_live_trades'] ?? true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="cfg-sim-trades">Simulate on Live Trades</label>
                                </div>
                            </div>
                        </div>

                        <!-- Limits -->
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label" for="cfg-max-signals">Max Signals / Run</label>
                                <input type="number" class="form-control form-control-sm" id="cfg-max-signals"
                                       value="<?= (int)($config['max_signals_per_run'] ?? 50) ?>" min="1" max="500">
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="cfg-max-trades">Max Trades / Run</label>
                                <input type="number" class="form-control form-control-sm" id="cfg-max-trades"
                                       value="<?= (int)($config['max_trades_per_run'] ?? 20) ?>" min="1" max="200">
                            </div>
                        </div>

                        <!-- Thresholds -->
                        <div class="row g-3 mb-3">
                            <div class="col-4">
                                <label class="form-label" for="cfg-thresh-enter">Confidence Enter</label>
                                <input type="number" class="form-control form-control-sm" id="cfg-thresh-enter"
                                       value="<?= number_format((float)($config['confidence_threshold_enter'] ?? 0.55), 2) ?>"
                                       step="0.01" min="0" max="1">
                            </div>
                            <div class="col-4">
                                <label class="form-label" for="cfg-thresh-skip">Confidence Skip</label>
                                <input type="number" class="form-control form-control-sm" id="cfg-thresh-skip"
                                       value="<?= number_format((float)($config['confidence_threshold_skip'] ?? 0.35), 2) ?>"
                                       step="0.01" min="0" max="1">
                            </div>
                            <div class="col-4">
                                <label class="form-label" for="cfg-thresh-quality">Quality Score</label>
                                <input type="number" class="form-control form-control-sm" id="cfg-thresh-quality"
                                       value="<?= number_format((float)($config['quality_score_threshold'] ?? 0.40), 2) ?>"
                                       step="0.01" min="0" max="1">
                            </div>
                        </div>

                        <!-- Logging -->
                        <div class="row g-3 mb-4">
                            <div class="col-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="cfg-log-enabled"
                                           <?= ($config['log_enabled'] ?? true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="cfg-log-enabled">Log Enabled</label>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="cfg-log-decisions"
                                           <?= ($config['log_decisions'] ?? true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="cfg-log-decisions">Log Decisions</label>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="cfg-log-rejections"
                                           <?= ($config['log_rejections'] ?? false) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="cfg-log-rejections">Log Rejections</label>
                                </div>
                            </div>
                        </div>

                        <button type="button" class="btn btn-primary btn-sm" onclick="saveSettings()">
                            <i class="bi bi-floppy me-1"></i> Save Settings
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm ms-2" onclick="clearStorage()">
                            <i class="bi bi-trash me-1"></i> Clear Storage
                        </button>

                        <div id="save-result" class="alert" role="alert"></div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Live Config Preview -->
        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header py-2">
                    <i class="bi bi-code-slash me-1"></i> Current ai_shadow.json
                </div>
                <div class="card-body p-3">
                    <pre class="config-preview mb-0"><?= htmlspecialchars(
                        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
                    ) ?></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleProviderFields() {
    const provider = document.getElementById('cfg-provider').value;
    const fields   = document.getElementById('provider-openai-fields');
    if (fields) {
        fields.style.display = provider === 'openai' ? '' : 'none';
    }
}

function saveSettings() {
    const provider = document.getElementById('cfg-provider').value;
    const cfg = {
        enabled:                      document.getElementById('cfg-enabled').checked,
        mode:                         'shadow',
        provider:                     provider,
        model:                        document.getElementById('cfg-model')         ? document.getElementById('cfg-model').value.trim()          : '',
        credential_id:                document.getElementById('cfg-credential-id') ? document.getElementById('cfg-credential-id').value.trim()   : '',
        allowed_patterns:             <?= json_encode((array)($config['allowed_patterns'] ?? [])) ?>,
        allowed_sides:                <?= json_encode((array)($config['allowed_sides'] ?? ['short'])) ?>,
        simulate_on_live_signals:     document.getElementById('cfg-sim-signals').checked,
        simulate_on_live_trades:      document.getElementById('cfg-sim-trades').checked,
        store_prototypes:             <?= json_encode((bool)($config['store_prototypes'] ?? true)) ?>,
        store_images:                 <?= json_encode((bool)($config['store_images'] ?? false)) ?>,
        max_signals_per_run:          parseInt(document.getElementById('cfg-max-signals').value, 10),
        max_trades_per_run:           parseInt(document.getElementById('cfg-max-trades').value, 10),
        confidence_threshold_enter:   parseFloat(document.getElementById('cfg-thresh-enter').value),
        confidence_threshold_skip:    parseFloat(document.getElementById('cfg-thresh-skip').value),
        quality_score_threshold:      parseFloat(document.getElementById('cfg-thresh-quality').value),
        log_enabled:                  document.getElementById('cfg-log-enabled').checked,
        log_decisions:                document.getElementById('cfg-log-decisions').checked,
        log_rejections:               document.getElementById('cfg-log-rejections').checked,
    };

    fetch('/admin/smart_brain/ai_shadow/save_settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(cfg)
    })
    .then(r => r.json())
    .then(d => {
        const el = document.getElementById('save-result');
        el.style.display = 'block';
        if (d.ok) {
            el.className = 'alert alert-success mt-2';
            el.textContent = 'Settings saved successfully.';
            setTimeout(() => location.reload(), 1000);
        } else {
            el.className = 'alert alert-danger mt-2';
            el.textContent = 'Error: ' + (d.error || 'unknown');
        }
    })
    .catch(() => {
        const el = document.getElementById('save-result');
        el.style.display = 'block';
        el.className = 'alert alert-danger mt-2';
        el.textContent = 'Network error saving settings.';
    });
}

function clearStorage() {
    if (!confirm('Clear all virtual signals and virtual trades? This cannot be undone.')) return;
    fetch('/admin/smart_brain/ai_shadow/clear_storage', { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            const el = document.getElementById('save-result');
            el.style.display = 'block';
            el.className = 'alert alert-warning mt-2';
            el.textContent = d.ok ? 'Storage cleared.' : 'Error clearing storage.';
        });
}
</script>
</body>
</html>
