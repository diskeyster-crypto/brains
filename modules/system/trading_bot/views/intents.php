<?php
/**
 * Trading Bot Intents View
 * 
 * Shows rejected intents / signals.
 */

use Core\System\System;
use Core\System\SystemPaths;

$tab = 'intents';

// Include tabs via SystemPaths
$moduleBase = SystemPaths::instance()->get('system.trading_bot');
require $moduleBase . '/views/_tabs.php';
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-inbox me-2"></i>Rejected Intents</span>
<?php
$lastRunBot = $lastRun ?? [];
$brainSourceBadge = (bool)($lastRunBot['controlled_by_brain'] ?? false)
    ? '<span class="badge bg-info">Brain-controlled</span>'
    : '<span class="badge bg-secondary">Legacy signals</span>';
$brainControlledIntents = (bool)($lastRunBot['controlled_by_brain'] ?? false);
$inputSourceIntents = (string)($lastRunBot['input_source'] ?? 'unknown');
$sourceStatusIntents = (string)($lastRunBot['source_status'] ?? 'n/a');
$legacyFallbackAllowedIntents = (bool)($lastRunBot['legacy_fallback_allowed'] ?? true);
$legacyFallbackUsedIntents = (bool)($lastRunBot['legacy_fallback_used'] ?? false);
$executionKeyBasisIntents = (string)($lastRunBot['execution_identity_key'] ?? 'n/a');
$trailingByBrainIntents = (bool)($lastRunBot['trailing_controlled_by_brain'] ?? false);
$localTrailingOverriddenIntents = (bool)($lastRunBot['local_trailing_toggles_overridden'] ?? false);
?>
        <?= $brainSourceBadge ?>
    </div>
    <div class="card-body">
        <!-- Brain-controlled runtime summary -->
        <div class="alert <?= $brainControlledIntents ? 'alert-info' : 'alert-secondary' ?> py-2 mb-3" style="font-size: 0.82rem;">
            <strong>Brain-controlled mode:</strong> <?= $brainControlledIntents ? 'ON' : 'OFF' ?>
            | <strong>Input source:</strong> <code><?= htmlspecialchars($inputSourceIntents) ?></code>
            | <strong>Source status:</strong> <code><?= htmlspecialchars($sourceStatusIntents) ?></code>
            <br>
            <strong>Legacy fallback allowed:</strong> <?= $legacyFallbackAllowedIntents ? 'yes' : 'no' ?>
            | <strong>Legacy fallback used:</strong> <?= $legacyFallbackUsedIntents ? 'yes' : 'no' ?>
            | <strong>Execution key basis:</strong> <code><?= htmlspecialchars($executionKeyBasisIntents) ?></code>
            <br>
            <strong>Trailing controlled by Brain:</strong> <?= $trailingByBrainIntents ? 'yes' : 'no' ?>
            | <strong>Local trailing toggles overridden:</strong> <?= $localTrailingOverriddenIntents ? 'yes' : 'no' ?>
        </div>
        <?php if (empty($intents)): ?>
        <p class="text-muted mb-0">No rejected intents</p>
        <?php else: ?>
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Execution Key</th>
                    <th>Symbol</th>
                    <th>Side</th>
                    <th>Dedupe</th>
                    <th>Reason</th>
                    <th>Missing Fields</th>
                    <th>Rejected At</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($intents as $item): ?>
                <?php $intent = $item['intent'] ?? $item; ?>
                <?php
                    $execKey = $intent['intent_id'] ?? $intent['id'] ?? $intent['signal_id'] ?? '';
                    $dedupe = !empty($intent['brain_controlled']) ? 'intent_id' : 'signal_id';
                ?>
                <tr>
                    <td class="small" title="<?= htmlspecialchars($execKey) ?>"><?= htmlspecialchars(substr($execKey, 0, 12)) ?>...</td>
                    <td><strong><?= htmlspecialchars($intent['symbol'] ?? '') ?></strong></td>
                    <td>
                        <span class="badge bg-<?= ($intent['side'] ?? '') === 'long' ? 'success' : 'danger' ?>">
                            <?= strtoupper($intent['side'] ?? '') ?>
                        </span>
                    </td>
                    <td class="small"><span class="badge bg-<?= $dedupe === 'intent_id' ? 'info' : 'secondary' ?>"><?= htmlspecialchars($dedupe) ?></span></td>
                    <td class="text-danger small"><?= htmlspecialchars($item['reason'] ?? '') ?></td>
                    <td class="small">
                        <?php if (!empty($item['missing_fields'])): ?>
                            <?= htmlspecialchars(implode(', ', $item['missing_fields'])) ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= htmlspecialchars($item['rejected_at'] ?? '') ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-light"
                                data-json="<?= htmlspecialchars(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
                                onclick="openJsonModalFromBtn(this, 'rejected_intent')">
                            View
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php
/* RULES
- Intents page shows rejected intents history
- Provides JSON details via universal modal (P6.11)
- No business logic; view-only
- Any dynamic data must be escaped via htmlspecialchars()
*/
?>
