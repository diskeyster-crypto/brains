<?php
/**
 * Trading Bot Orders View
 * 
 * Shows active and closed orders.
 */

use Core\System\System;
use Core\System\SystemPaths;

$tab = 'orders';
$status = $status ?? 'active';

// Include tabs via SystemPaths
$moduleBase = SystemPaths::instance()->get('system.trading_bot');
require $moduleBase . '/views/_tabs.php';
?>

<div class="mb-3">
    <a href="<?= System::adminUrl('trading/orders?status=active') ?>" 
       class="btn btn-sm <?= $status === 'active' ? 'btn-primary' : 'btn-outline-primary' ?>">
        Active
    </a>
    <a href="<?= System::adminUrl('trading/orders?status=closed') ?>" 
       class="btn btn-sm <?= $status === 'closed' ? 'btn-primary' : 'btn-outline-primary' ?>">
        Closed
    </a>
</div>

<div class="card">
    <div class="card-header">
        <i class="bi bi-list-check me-2"></i><?= ucfirst($status) ?> Orders
    </div>
    <div class="card-body">
        <?php if (empty($orders)): ?>
        <p class="text-muted mb-0">No <?= $status ?> orders</p>
        <?php else: ?>
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Symbol</th>
                    <th>Side</th>
                    <th>Type</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Status</th>
                    <th>Saved At</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $item): ?>
                <?php $order = $item['order'] ?? $item; ?>
                <?php $result = $item['result'] ?? []; ?>
                <tr>
                    <td class="small"><?= htmlspecialchars(substr($result['order_id'] ?? '', 0, 12)) ?>...</td>
                    <td><strong><?= htmlspecialchars($order['symbol'] ?? '') ?></strong></td>
                    <td>
                        <span class="badge bg-<?= ($order['side'] ?? '') === 'Buy' ? 'success' : 'danger' ?>">
                            <?= htmlspecialchars($order['side'] ?? '') ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($order['order_type'] ?? '') ?></td>
                    <td><?= number_format($order['qty'] ?? 0, 4) ?></td>
                    <td><?= number_format($order['price'] ?? 0, 6) ?></td>
                    <td>
                        <?php if ($result['ok'] ?? false): ?>
                            <span class="badge bg-success">
                                <?= ($result['filled'] ?? false) ? 'Filled' : 'Open' ?>
                            </span>
                        <?php else: ?>
                            <span class="badge bg-danger">Failed</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= htmlspecialchars($item['saved_at'] ?? '') ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-light"
                                data-json="<?= htmlspecialchars(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
                                onclick="openJsonModalFromBtn(this, 'order_record')">
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
- Orders page shows order records from storage
- Provides JSON details via universal modal (P6.11)
- No business logic; view-only
- Any dynamic data must be escaped via htmlspecialchars()
*/
?>
