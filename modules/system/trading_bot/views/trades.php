<?php
/**
 * Trading Bot Trades View
 * 
 * Shows active and closed trades.
 */

use Core\System\System;
use Core\System\SystemPaths;

$tab = 'trades';
$status = $status ?? 'active';

// Include tabs via SystemPaths
$moduleBase = SystemPaths::instance()->get('system.trading_bot');
require $moduleBase . '/views/_tabs.php';
?>

<div class="mb-3">
    <a href="<?= System::adminUrl('trading/trades?status=active') ?>" 
       class="btn btn-sm <?= $status === 'active' ? 'btn-primary' : 'btn-outline-primary' ?>">
        Active
    </a>
    <a href="<?= System::adminUrl('trading/trades?status=closed') ?>" 
       class="btn btn-sm <?= $status === 'closed' ? 'btn-primary' : 'btn-outline-primary' ?>">
        Closed
    </a>
</div>


<?php if ($status === 'active'): ?>
<?php
$exchangePositions = $exchangePositions ?? ['ok' => false, 'positions' => [], 'count' => 0];
$exPositions = is_array($exchangePositions['positions'] ?? null) ? $exchangePositions['positions'] : [];
$exCount = (int)($exchangePositions['count'] ?? count($exPositions));
?>
<?php if (!empty($exPositions)): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-lightning-charge me-2"></i>Active Positions (Exchange)</span>
        <span class="badge bg-secondary"><?= $exCount ?></span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($exPositions as $p): ?>
            <?php
                $pnl = (float)($p['unrealised_pnl'] ?? 0);
                $roi = (float)($p['roi_pct'] ?? 0);
                $side = (string)($p['side'] ?? '');
                $sideBadge = ($side === 'long') ? 'success' : 'danger';
            ?>
            <div class="col-md-4">
                <div class="card h-100 position-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <div class="d-flex align-items-center gap-2">
                                    <strong class="fs-6"><?= htmlspecialchars($p['symbol'] ?? '') ?></strong>
                                    <span class="badge bg-<?= $sideBadge ?>"><?= strtoupper($side) ?></span>
                                </div>
                                <div class="small text-muted">
                                    Size: <?= number_format((float)($p['size'] ?? 0), 4) ?> · Entry: <?= number_format((float)($p['avg_price'] ?? 0), 6) ?>
                                </div>
                            </div>
                            <button class="btn btn-sm btn-outline-danger" title="Close now" onclick="closePositionNow(<?= htmlspecialchars(json_encode([
                                'symbol' => $p['symbol'] ?? '',
                                'side' => $side,
                                'position_idx' => (int)($p['position_idx'] ?? 0),
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>)">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <div class="small text-muted">Unrealised PnL</div>
                                <div class="<?= $pnl >= 0 ? 'text-success' : 'text-danger' ?> fw-semibold">
                                    <?= $pnl >= 0 ? '+' : '' ?><?= number_format($pnl, 4) ?> USDT
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="small text-muted">ROI (Bybit)</div>
                                <div class="<?= $roi >= 0 ? 'text-success' : 'text-danger' ?> fw-semibold">
                                    <?= $roi >= 0 ? '+' : '' ?><?= number_format($roi, 2) ?>%
                                </div>
                            </div>
                        </div>

                        <div class="small">
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">SL</span>
                                <span><?= ($p['stop_loss'] ?? 0) > 0 ? number_format((float)$p['stop_loss'], 8) : '—' ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Trailing</span>
                                <span><?= ($p['trailing_stop'] ?? 0) > 0 ? number_format((float)$p['trailing_stop'], 8) : '—' ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Active Price</span>
                                <span><?= ($p['active_price'] ?? 0) > 0 ? number_format((float)$p['active_price'], 8) : '—' ?></span>
                            </div>
                        </div>

                        <div class="mt-3 d-flex justify-content-between align-items-center">
                            <button class="btn btn-sm btn-outline-info" onclick="openStopsModal(<?= htmlspecialchars(json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>)">
                                <i class="bi bi-sliders me-1"></i> Изменить
                            </button>
                            <button class="btn btn-sm btn-outline-light" onclick="openJsonModal('Position RAW', <?= htmlspecialchars(json_encode($p['raw'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>)">
                                <i class="bi bi-code-slash me-1"></i> RAW
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>


<div class="card">
    <div class="card-header">
        <i class="bi bi-graph-up-arrow me-2"></i><?= ucfirst($status) ?> Trades
    </div>
    <div class="card-body">
        <?php if (empty($trades)): ?>
        <p class="text-muted mb-0">No <?= $status ?> trades</p>
        <?php else: ?>
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Trade ID</th>
                    <th>Symbol</th>
                    <th>Side</th>
                    <th>Entry</th>
                    <th>Size</th>
                    <th>TP</th>
                    <th>SL</th>
                    <?php if ($status === 'closed'): ?>
                    <th>Close Price</th>
                    <th>PnL</th>
                    <th>Reason</th>
                    <?php endif; ?>
                    <th><?= $status === 'active' ? 'Opened' : 'Closed' ?></th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trades as $trade): ?>
                <tr>
                    <td class="small"><?= htmlspecialchars(substr($trade['trade_id'] ?? '', 0, 12)) ?>...</td>
                    <td><strong><?= htmlspecialchars($trade['symbol'] ?? '') ?></strong></td>
                    <td>
                        <span class="badge bg-<?= ($trade['side'] ?? '') === 'long' ? 'success' : 'danger' ?>">
                            <?= strtoupper($trade['side'] ?? '') ?>
                        </span>
                    </td>
                    <td><?= number_format($trade['entry_price'] ?? 0, 6) ?></td>
                    <td><?= number_format($trade['position_size'] ?? 0, 4) ?></td>
                    <td class="text-success"><?= isset($trade['take_profit']) ? number_format($trade['take_profit'], 6) : '-' ?></td>
                    <td class="text-danger"><?= isset($trade['stop_loss']) ? number_format($trade['stop_loss'], 6) : '-' ?></td>
                    <?php if ($status === 'closed'): ?>
                    <td><?= isset($trade['close_price']) ? number_format($trade['close_price'], 6) : '-' ?></td>
                    <td class="<?= ($trade['pnl'] ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>">
                        <?= ($trade['pnl'] ?? 0) >= 0 ? '+' : '' ?><?= number_format($trade['pnl'] ?? 0, 4) ?>
                    </td>
                    <td class="small"><?= htmlspecialchars($trade['close_reason'] ?? '') ?></td>
                    <?php endif; ?>
                    <td class="small text-muted">
                        <?= htmlspecialchars($status === 'active' ? ($trade['opened_at'] ?? '') : ($trade['closed_at'] ?? '')) ?>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-light"
                                data-json="<?= htmlspecialchars(json_encode($trade, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
                                onclick="openJsonModalFromBtn(this, 'trade')">
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
- Trades page shows active/closed trades from storage
- Provides JSON details via universal modal (P6.11)
- No business logic; view-only
- Any dynamic data must be escaped via htmlspecialchars()
*/
?>
