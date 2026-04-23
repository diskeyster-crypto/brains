<?php
/**
 * Brain — Control Tab
 *
 * High-level system overrides only.
 * Operator can see and set global bot state and strategy override summary.
 * Deep strategy internals (pattern thresholds, corridors, etc.) are NOT shown here.
 *
 * Variables provided by BrainController::botControl():
 *   $botConfig    — array: effective bot config
 *   $overrides    — array: all operator overrides keyed by strategy_id
 *   $registry     — array: strategy_registry.json records
 *   $saveUrl      — POST URL for /admin/brain/bot/overrides/save
 *   $flash        — optional flash message
 */

use Core\System\System;

$activeTab = 'bot-control';
require __DIR__ . '/_tabs.php';

$brainUrl = rtrim(System::web('admin/brain'), '/');

$globalEnabled = (bool)($botConfig['enabled'] ?? false);
$globalMode    = (string)($botConfig['mode'] ?? 'passive');
$allowedModes  = (array)($botConfig['allowed_entry_modes'] ?? ['limit', 'market']);
?>

<?php if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="font-size:13px;">
    <?= htmlspecialchars($flash['message'] ?? '') ?>
</div>
<?php endif; ?>

<div class="d-flex align-items-center mb-3">
    <h5 class="mb-0"><i class="bi bi-sliders me-2"></i>Control</h5>
</div>

<!-- Global bot state (read-only display; config is in active.php) -->
<div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:16px;max-width:600px;margin-bottom:16px;">
    <h6 style="font-size:12px;color:#94a3b8;text-transform:uppercase;margin-bottom:12px;">Global Bot Config</h6>
    <table class="table table-sm mb-0" style="font-size:13px;">
        <tr>
            <th>Bot enabled</th>
            <td>
                <?php if ($globalEnabled): ?>
                    <span style="color:#22c55e;font-weight:600;">Yes</span>
                <?php else: ?>
                    <span style="color:#ef4444;font-weight:600;">No</span>
                    <small style="color:#64748b;margin-left:8px;">Set <code>enabled = true</code> in <code>modules/bot/config/active.php</code> to enable</small>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Mode</th>
            <td><code><?= htmlspecialchars($globalMode) ?></code></td>
        </tr>
        <tr>
            <th>Allowed entry modes</th>
            <td><?= implode(', ', array_map(fn($m) => '<code>' . htmlspecialchars($m) . '</code>', $allowedModes)) ?></td>
        </tr>
    </table>
    <div style="margin-top:10px;font-size:11px;color:#64748b;">
        Global config is set in <code>modules/bot/config/active.php</code>. Per-strategy overrides are below.
    </div>
</div>

<!-- Per-strategy override summary -->
<div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:16px;margin-bottom:16px;">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 style="font-size:12px;color:#94a3b8;text-transform:uppercase;margin-bottom:0;">Strategy Override Summary</h6>
        <a href="<?= $brainUrl ?>/bot/strategies" class="btn btn-sm btn-outline-primary" style="font-size:11px;">
            <i class="bi bi-pencil me-1"></i> Edit overrides
        </a>
    </div>
    <?php if (empty($registry)): ?>
    <small class="text-muted">No strategies in registry yet.</small>
    <?php else: ?>
    <table class="table table-sm mb-0" style="font-size:12px;">
        <thead>
            <tr>
                <th>Strategy</th>
                <th>Enabled</th>
                <th>Entry mode</th>
                <th>Budget</th>
                <th>Leverage</th>
                <th>Max pos.</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($registry as $rec):
            $sid  = (string)($rec['strategy_id'] ?? '');
            $op   = (array)($overrides[$sid] ?? []);
            $opEnabled  = isset($op['enabled']) ? (bool)$op['enabled'] : true;
            $opMode     = (string)($op['entry_mode'] ?? '');
            $opBudget   = (float)($op['bot_budget'] ?? 0);
            $opLev      = (int)($op['bot_leverage'] ?? 0);
            $opMax      = (int)($op['max_active_positions'] ?? 0);
        ?>
        <tr>
            <td>
                <code><?= htmlspecialchars($sid) ?></code><br>
                <small class="text-muted"><?= htmlspecialchars($rec['title'] ?? '') ?></small>
            </td>
            <td><?= $opEnabled ? '<span style="color:#22c55e;">✓</span>' : '<span style="color:#ef4444;">off</span>' ?></td>
            <td><code><?= $opMode !== '' ? htmlspecialchars($opMode) : '—' ?></code></td>
            <td><code><?= $opBudget > 0 ? $opBudget : '—' ?></code></td>
            <td><code><?= $opLev   > 0 ? $opLev   : '—' ?></code></td>
            <td><code><?= $opMax   > 0 ? $opMax   : '—' ?></code></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div style="font-size:12px;color:#64748b;max-width:600px;">
    <strong>Note:</strong> Deep strategy internals (pattern tolerances, corridor config, wave config, confirmation internals, quality weights, TTL internals) are managed inside each strategy module, not here.
</div>
