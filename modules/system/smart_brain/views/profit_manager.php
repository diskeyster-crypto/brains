<?php
/**
 * Smart Brain — Profit Manager
 *
 * Separate UI section for Profit Manager configuration.
 * Source of truth: /modules/system/trading_bot/config/bot.json
 *
 * @var string               $smartBrainUrl
 * @var bool                 $bot_available
 * @var string|null          $bot_error
 * @var string               $bot_mode
 * @var bool                 $bot_enabled
 * @var array<string,mixed>  $bot_config
 */

$pageTitle = 'Smart Brain — Profit Manager';
$activeTab = 'profit_manager';

$PM_URL = $smartBrainUrl . '/profit_manager';

$trailingOwner = (string)($bot_config['execution']['trailing_owner'] ?? 'bot');

$extraStyles = '
.pm-section { background: #0f172a; border: 1px solid #1e293b; border-radius:8px; padding: 1.2rem 1.4rem; margin-bottom:1.4rem; }
.pm-section h6 { color:#60a5fa; font-size:.8rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; margin-bottom:.9rem; }
.owner-card { background:#1e293b; border:1px solid #334155; border-radius:8px; padding:1rem 1.2rem; cursor:pointer; transition:border-color .15s; }
.owner-card:hover, .owner-card.selected { border-color:#3b82f6; }
.owner-card.selected { background:#1e3a5f; }
.owner-card .oc-title { font-weight:600; font-size:.95rem; color:#e2e8f0; }
.owner-card .oc-desc  { font-size:.8rem; color:#94a3b8; margin-top:.25rem; }
.badge-owner-bot    { background:#1d4ed8; color:#fff; padding:2px 8px; border-radius:4px; font-size:.72rem; font-weight:700; }
.badge-owner-shadow { background:#7c3aed; color:#fff; padding:2px 8px; border-radius:4px; font-size:.72rem; font-weight:700; }
.badge-owner-pm     { background:#059669; color:#fff; padding:2px 8px; border-radius:4px; font-size:.72rem; font-weight:700; }
#flash-msg { display:none; position:fixed; top:1rem; right:1rem; z-index:9999; min-width:280px; }
';

$extraScripts = <<<JS
<script>
const PM_URL = '{$PM_URL}';
let selectedOwner = '{$trailingOwner}';

function showFlash(msg, type) {
    const el = document.getElementById('flash-msg');
    el.className = 'alert alert-' + type + ' alert-dismissible shadow';
    el.innerHTML = msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    el.style.display = 'block';
    setTimeout(() => { el.style.display = 'none'; }, 4000);
}

function selectOwner(val) {
    selectedOwner = val;
    document.querySelectorAll('.owner-card').forEach(c => {
        c.classList.toggle('selected', c.dataset.owner === val);
    });
    document.getElementById('selected-owner-badge').innerHTML = ownerBadge(val);
}

function ownerBadge(val) {
    if (val === 'bot')                    return '<span class="badge-owner-bot">Bot Trailing</span>';
    if (val === 'profit_manager_shadow')  return '<span class="badge-owner-shadow">PM Shadow</span>';
    if (val === 'profit_manager')         return '<span class="badge-owner-pm">PM Active</span>';
    return val;
}

function saveTrailingOwner() {
    const btn = document.getElementById('btn-save-owner');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    fetch(PM_URL + '/save_config', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ trailing_owner: selectedOwner })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.textContent = 'Save';
        if (data.ok) {
            showFlash('✓ trailing_owner saved as <strong>' + selectedOwner + '</strong>', 'success');
        } else {
            showFlash('Error: ' + (data.error || 'unknown'), 'danger');
        }
    })
    .catch(e => {
        btn.disabled = false;
        btn.textContent = 'Save';
        showFlash('Network error: ' + e.message, 'danger');
    });
}

document.addEventListener('DOMContentLoaded', function() {
    selectOwner(selectedOwner);
});
</script>
JS;

include __DIR__ . '/_layout.php';
?>

<div id="flash-msg" role="alert"></div>

<h4 class="mb-3">
    <i class="bi bi-cash-coin me-2 text-info"></i>Profit Manager
    <small class="text-muted fs-6 ms-2">— trailing ownership &amp; shadow mode</small>
</h4>

<?php if (!$bot_available): ?>
<div class="alert alert-warning">
    Bot config unavailable: <?= htmlspecialchars((string)($bot_error ?? 'unknown')) ?>
</div>
<?php else: ?>

<!-- Trailing Owner Section -->
<div class="pm-section">
    <h6><i class="bi bi-diagram-3 me-1"></i>Trailing Ownership</h6>
    <p class="text-secondary small mb-3">
        Controls which module performs post-entry dynamic trailing stop updates.<br>
        <strong>Source of truth:</strong> <code>execution.trailing_owner</code> in
        <code>trading_bot/config/bot.json</code>
    </p>

    <div class="row g-3 mb-3">
        <!-- Bot Trailing -->
        <div class="col-md-4">
            <div class="owner-card" data-owner="bot" onclick="selectOwner('bot')">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge-owner-bot">Bot Trailing</span>
                </div>
                <div class="oc-title">Bot trailing</div>
                <div class="oc-desc">
                    Current behaviour stays unchanged.<br>
                    Bot applies all real trailing stop updates on exchange.
                </div>
            </div>
        </div>

        <!-- PM Shadow -->
        <div class="col-md-4">
            <div class="owner-card" data-owner="profit_manager_shadow" onclick="selectOwner('profit_manager_shadow')">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge-owner-shadow">PM Shadow</span>
                </div>
                <div class="oc-title">Profit Manager shadow</div>
                <div class="oc-desc">
                    Bot still owns all real trailing updates.<br>
                    PM runs in shadow: computes diagnostics only, no exchange writes.
                </div>
            </div>
        </div>

        <!-- PM Active -->
        <div class="col-md-4">
            <div class="owner-card" data-owner="profit_manager" onclick="selectOwner('profit_manager')">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge-owner-pm">PM Active</span>
                </div>
                <div class="oc-title">Profit Manager active</div>
                <div class="oc-desc">
                    Bot places initial protective stop at open.<br>
                    Bot skips post-entry dynamic trailing updates — PM owns them.
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex align-items-center gap-3">
        <button id="btn-save-owner" class="btn btn-primary btn-sm" onclick="saveTrailingOwner()">
            Save
        </button>
        <span class="text-secondary small">
            Current: <span id="selected-owner-badge"></span>
        </span>
    </div>
</div>

<!-- Current Config Snapshot -->
<div class="pm-section">
    <h6><i class="bi bi-code-square me-1"></i>Current bot.json — execution.trailing_owner</h6>
    <table class="table table-sm table-dark table-bordered mb-0" style="font-size:.82rem; max-width:500px;">
        <tbody>
            <tr>
                <td class="text-secondary" style="width:220px;">trailing_owner</td>
                <td>
                    <?php
                    $o = htmlspecialchars($trailingOwner);
                    if ($trailingOwner === 'bot') {
                        echo '<span class="badge-owner-bot">bot</span>';
                    } elseif ($trailingOwner === 'profit_manager_shadow') {
                        echo '<span class="badge-owner-shadow">profit_manager_shadow</span>';
                    } elseif ($trailingOwner === 'profit_manager') {
                        echo '<span class="badge-owner-pm">profit_manager</span>';
                    } else {
                        echo '<code>' . $o . '</code>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <td class="text-secondary">trailing_enabled</td>
                <td><?= ($bot_config['execution']['trailing_enabled'] ?? false) ? '<span class="text-success">true</span>' : '<span class="text-secondary">false</span>' ?></td>
            </tr>
            <tr>
                <td class="text-secondary">step_trailing_enabled</td>
                <td><?= ($bot_config['execution']['step_trailing_enabled'] ?? false) ? '<span class="text-success">true</span>' : '<span class="text-secondary">false</span>' ?></td>
            </tr>
            <tr>
                <td class="text-secondary">break_even_enabled</td>
                <td><?= ($bot_config['execution']['break_even_enabled'] ?? false) ? '<span class="text-success">true</span>' : '<span class="text-secondary">false</span>' ?></td>
            </tr>
        </tbody>
    </table>
</div>

<!-- PM Shadow Mode Info -->
<div class="pm-section">
    <h6><i class="bi bi-info-circle me-1"></i>Profit Manager shadow mode — observability fields</h6>
    <p class="text-secondary small mb-2">
        When <code>trailing_owner = profit_manager_shadow</code>: PM runs each tick, computes shadow trailing state,
        and writes diagnostics to its runtime storage. No exchange stop updates are made by PM.
        Check <code>profit_manager/storage/runtime/</code> for per-trade shadow state files.
    </p>
    <p class="text-secondary small mb-0">
        Runtime journal fields exposed per tick:
        <code>trailing_owner</code> · <code>pm_shadow_active</code> · <code>pm_shadow_trade_id</code> ·
        <code>pm_shadow_proposed_stop</code> · <code>pm_shadow_proposed_lock_roi</code> ·
        <code>pm_shadow_proposed_action</code> · <code>pm_shadow_peak_roi</code>
    </p>
</div>

<?php endif; ?>
