<?php
/**
 * Brain — Profit Manager Placeholder Tab
 *
 * PM logic is not implemented yet.
 * This tab exists so the operator can see that PM is a separate module/layer
 * and understand its intended role. No PM functionality is faked here.
 *
 * Variables provided by BrainController::botPm():
 *   $flash — optional flash message
 */

use Core\System\System;

$activeTab = 'bot-pm';
require __DIR__ . '/_tabs.php';
?>

<?php if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="font-size:13px;">
    <?= htmlspecialchars($flash['message'] ?? '') ?>
</div>
<?php endif; ?>

<div class="d-flex align-items-center mb-3">
    <h5 class="mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Profit Manager</h5>
</div>

<div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:24px;max-width:640px;">
    <div style="font-size:20px;font-weight:700;color:#f59e0b;margin-bottom:6px;">
        <i class="bi bi-hourglass-split me-2"></i>Not implemented yet
    </div>
    <p style="color:#94a3b8;font-size:13px;margin-bottom:16px;">
        The Profit Manager (PM) is a separate module/layer that will handle trailing, profit lock, and position management after a bot order has been submitted to the exchange.
    </p>
    <p style="color:#64748b;font-size:12px;margin-bottom:0;">
        It does not exist yet. This tab is a placeholder so the architecture is visible.<br>
        PM will be built after exchange execution is wired.
    </p>
    <hr style="border-color:#334155;margin:16px 0;">
    <p style="font-size:12px;color:#64748b;margin-bottom:0;">
        <strong>Planned scope:</strong><br>
        — Trailing stop management per position<br>
        — Profit lock presets (R-multiple, %)<br>
        — Position close on reverse signal<br>
        — Per-strategy PM config (stop_preset, exit_preset)
    </p>
</div>
