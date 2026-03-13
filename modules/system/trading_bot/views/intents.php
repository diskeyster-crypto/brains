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
    <div class="card-header">
        <i class="bi bi-inbox me-2"></i>Rejected Intents
    </div>
    <div class="card-body">
        <?php if (empty($intents)): ?>
        <p class="text-muted mb-0">No rejected intents</p>
        <?php else: ?>
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Signal ID</th>
                    <th>Symbol</th>
                    <th>Side</th>
                    <th>Reason</th>
                    <th>Missing Fields</th>
                    <th>Rejected At</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($intents as $item): ?>
                <?php $intent = $item['intent'] ?? $item; ?>
                <tr>
                    <td class="small"><?= htmlspecialchars(substr($intent['id'] ?? $intent['signal_id'] ?? '', 0, 12)) ?>...</td>
                    <td><strong><?= htmlspecialchars($intent['symbol'] ?? '') ?></strong></td>
                    <td>
                        <span class="badge bg-<?= ($intent['side'] ?? '') === 'long' ? 'success' : 'danger' ?>">
                            <?= strtoupper($intent['side'] ?? '') ?>
                        </span>
                    </td>
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
