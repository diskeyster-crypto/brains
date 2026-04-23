<?php
/**
 * Brain — Bot Strategies Tab
 *
 * Shows discovered strategies from bot strategy_registry.json.
 * Operator can enable/disable a strategy and set budget/leverage/entry_mode.
 *
 * Variables provided by BrainController::botStrategies():
 *   $registry   — array from modules/bot/storage/strategy_registry.json
 *   $overrides  — array from modules/bot/storage/operator_overrides.json
 *   $saveUrl    — POST URL for /admin/brain/bot/overrides/save
 *   $flash      — optional flash message
 */

use Core\System\System;

$activeTab = 'bot-strategies';
require __DIR__ . '/_tabs.php';

$brainUrl = rtrim(System::web('admin/brain'), '/');
?>

<?php if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="font-size:13px;">
    <?= htmlspecialchars($flash['message'] ?? '') ?>
</div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="mb-0"><i class="bi bi-robot me-2"></i>Bot Strategies</h5>
    <small class="text-muted">Source: <code>modules/bot/storage/strategy_registry.json</code></small>
</div>

<?php if (empty($registry)): ?>
<div class="alert alert-warning" style="font-size:13px;">
    No strategies discovered yet. The bot must complete at least one tick with <code>enabled=true</code> to populate the registry.
    <br>You can trigger a tick manually via the cron runner, or enable the bot in <code>modules/bot/config/active.php</code>.
</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-sm" style="font-size:13px;">
    <thead>
        <tr>
            <th>Strategy</th>
            <th>Status</th>
            <th>Long</th>
            <th>Short</th>
            <th>Handoff</th>
            <th>Enabled</th>
            <th>Budget</th>
            <th>Leverage</th>
            <th>Entry mode</th>
            <th>Max pos.</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($registry as $rec):
        $sid   = htmlspecialchars($rec['strategy_id'] ?? '', ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($rec['title']       ?? $sid,  ENT_QUOTES, 'UTF-8');
        $status= $rec['status'] ?? 'discovered';
        $op    = (array)($overrides[$rec['strategy_id'] ?? ''] ?? []);
        $opEnabled  = isset($op['enabled']) ? (bool)$op['enabled'] : true;
        $opBudget   = (float)($op['bot_budget']      ?? 0);
        $opLev      = (int)($op['bot_leverage']      ?? 0);
        $opMode     = (string)($op['entry_mode']     ?? '');
        $opMax      = (int)($op['max_active_positions'] ?? 0);

        $statusBadge = $status === 'bot_ready'
            ? '<span style="color:#22c55e;">●</span> bot_ready'
            : '<span style="color:#64748b;">○</span> ' . htmlspecialchars($status);
        $enabledBadge = $opEnabled
            ? '<span style="color:#22c55e;font-weight:600;">on</span>'
            : '<span style="color:#ef4444;font-weight:600;">off</span>';
        $hasHandoff = $rec['handoff_queue_path'] ? '✓' : '—';
        $supLong  = $rec['supports_long']  ? '✓' : '—';
        $supShort = $rec['supports_short'] ? '✓' : '—';
        $dataEnabled = $opEnabled ? '1' : '0';
    ?>
    <tr id="row-<?= $sid ?>">
        <td>
            <strong><?= $title ?></strong><br>
            <code style="font-size:11px;color:#7dd3fc;"><?= $sid ?></code>
        </td>
        <td><?= $statusBadge ?></td>
        <td><?= $supLong ?></td>
        <td><?= $supShort ?></td>
        <td><?= $hasHandoff ?></td>
        <td><?= $enabledBadge ?></td>
        <td><code><?= $opBudget > 0 ? $opBudget : '—' ?></code></td>
        <td><code><?= $opLev   > 0 ? $opLev   : '—' ?></code></td>
        <td><code><?= $opMode !== '' ? $opMode : '—' ?></code></td>
        <td><code><?= $opMax   > 0 ? $opMax   : '—' ?></code></td>
        <td>
            <button class="btn btn-sm btn-outline-primary" style="font-size:11px;"
                onclick="botStratEdit('<?= $sid ?>','<?= addslashes($title) ?>',<?= $dataEnabled ?>,<?= $opBudget ?>,<?= $opLev ?>,'<?= $opMode ?>',<?= $opMax ?>)">
                Edit
            </button>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<!-- Inline edit modal -->
<div id="bsEditModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.65);z-index:1050;align-items:center;justify-content:center;">
    <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:22px;width:460px;max-width:96vw;">
        <h6 id="bsEditTitle" class="mb-3">Edit strategy overrides</h6>
        <form id="bsEditForm" method="post" action="<?= htmlspecialchars($saveUrl) ?>">
            <input type="hidden" name="strategy_id" id="bsEditStratId">
            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label" style="font-size:12px;">Enabled</label>
                    <select name="enabled" id="bsEditEnabled" class="form-select form-select-sm">
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label" style="font-size:12px;">Entry mode</label>
                    <select name="entry_mode" id="bsEditEntryMode" class="form-select form-select-sm">
                        <option value="">— from signal —</option>
                        <option value="limit">limit</option>
                        <option value="market">market</option>
                    </select>
                </div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-4">
                    <label class="form-label" style="font-size:12px;">Budget (0=signal)</label>
                    <input type="number" step="0.01" min="0" name="bot_budget" id="bsEditBudget" class="form-control form-control-sm">
                </div>
                <div class="col-4">
                    <label class="form-label" style="font-size:12px;">Leverage (0=signal)</label>
                    <input type="number" step="1" min="0" name="bot_leverage" id="bsEditLeverage" class="form-control form-control-sm">
                </div>
                <div class="col-4">
                    <label class="form-label" style="font-size:12px;">Max positions (0=∞)</label>
                    <input type="number" step="1" min="0" name="max_active_positions" id="bsEditMaxPos" class="form-control form-control-sm">
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('bsEditModal').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function botStratEdit(sid, title, enabled, budget, leverage, entryMode, maxPos) {
    document.getElementById('bsEditTitle').textContent = 'Edit: ' + title;
    document.getElementById('bsEditStratId').value = sid;
    document.getElementById('bsEditEnabled').value = enabled ? '1' : '0';
    document.getElementById('bsEditBudget').value = budget;
    document.getElementById('bsEditLeverage').value = leverage;
    document.getElementById('bsEditEntryMode').value = entryMode || '';
    document.getElementById('bsEditMaxPos').value = maxPos;
    var m = document.getElementById('bsEditModal');
    m.style.display = 'flex';
}
document.getElementById('bsEditModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>
