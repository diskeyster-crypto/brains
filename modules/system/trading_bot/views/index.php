<?php
/**
 * Trading Bot Dashboard
 * 
 * Main dashboard view.
 */

use Core\System\System;
use Core\System\SystemPaths;

$tab = $tab ?? 'dashboard';
$mode = $this->config['module']['mode'] ?? 'dry';
$enabled = $this->config['module']['enabled'] ?? false;
$modeClass = [
    'live' => 'badge-live',
    'dry' => 'badge-dry',
    'test' => 'badge-test',
][$mode] ?? 'badge-secondary';

// Include tabs via SystemPaths
$moduleBase = SystemPaths::instance()->get('system.trading_bot');
require $moduleBase . '/views/_tabs.php';
?>

<!-- Status Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="bi bi-robot me-2"></i>
            Trading Bot v1
            <span class="badge <?= $modeClass ?> ms-2"><?= strtoupper($mode) ?></span>
            <?php if ($enabled): ?>
                <span class="badge bg-success ms-1">ENABLED</span>
            <?php else: ?>
                <span class="badge bg-secondary ms-1">DISABLED</span>
            <?php endif; ?>
        </h4>
        <p class="text-muted mb-0">
            LIVE Executor — executes Brain decisions on exchange
        </p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-success" onclick="runBot()">
            <i class="bi bi-play-fill me-1"></i> Run
        </button>
        <button class="btn btn-warning" onclick="reconcile()">
            <i class="bi bi-arrow-repeat me-1"></i> Reconcile
        </button>
        <button class="btn btn-danger" onclick="stopBot()">
            <i class="bi bi-stop-fill me-1"></i> Stop
        </button>
    </div>
</div>

<!-- Brain-Controlled Status -->
<?php
$lastRunBot = $lastRun ?? [];
$controlledByBrain = (bool)($lastRunBot['controlled_by_brain'] ?? false);
$inputSourceBot = (string)($lastRunBot['input_source'] ?? 'unknown');
$selectionModeBot = (string)($lastRunBot['effective_selection_mode_from_brain'] ?? 'n/a');
?>
<div class="alert <?= $controlledByBrain ? 'alert-info' : 'alert-secondary' ?> mb-4 py-2" style="font-size: 0.85rem;">
    <i class="bi bi-<?= $controlledByBrain ? 'lightning-charge' : 'info-circle' ?> me-1"></i>
    <strong>Intent Source:</strong> <?= htmlspecialchars($inputSourceBot) ?>
    <?php if ($controlledByBrain): ?>
        — <span class="text-info">Brain-controlled</span> (selection mode: <code><?= htmlspecialchars($selectionModeBot) ?></code>)
        <br><small>Bot-local strategy overrides (reverse_side, force_side, symbol_overrides) are <b>skipped</b> — Brain owns strategy decisions.</small>
    <?php else: ?>
        — <span class="text-secondary">Legacy fallback mode</span> (bot-local overrides active)
    <?php endif; ?>
</div>


<!-- Active Positions (Exchange) -->
<?php
$exchangePositions = $exchangePositions ?? ['ok' => false, 'positions' => [], 'count' => 0];
$exPositions = is_array($exchangePositions['positions'] ?? null) ? $exchangePositions['positions'] : [];
$exCount = (int)($exchangePositions['count'] ?? count($exPositions));
$exShow = array_slice($exPositions, 0, 3);
?>
<?php if (!empty($exPositions)): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-lightning-charge me-2"></i>Active Positions (Exchange)</span>
        <div class="d-flex gap-2">
            <span class="badge bg-secondary"><?= $exCount ?></span>
            <a class="btn btn-sm btn-outline-light" href="<?= System::adminUrl('trading/trades?status=active') ?>">
                View All
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($exShow as $p): ?>
            <?php
                $pnl = (float)($p['unrealised_pnl'] ?? 0);
                $roi = (float)($p['roi_pct'] ?? 0);
                $roiStr = number_format($roi, 2);
                $pnlStr = number_format($pnl, 4);
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
                                    <?= $pnl >= 0 ? '+' : '' ?><?= $pnlStr ?> USDT
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="small text-muted">ROI (Bybit)</div>
                                <div class="<?= $roi >= 0 ? 'text-success' : 'text-danger' ?> fw-semibold">
                                    <?= $roi >= 0 ? '+' : '' ?><?= $roiStr ?>%
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


<!-- Stats Row -->
<?php
    $lookbackH = (int)($stats['lookback_hours'] ?? 48);
    $lookbackH = max(1, min(168, $lookbackH));
    $statsSource = (string)($stats['source'] ?? 'local_storage');
    $closedPnlError = (string)($stats['closed_pnl_error'] ?? '');
?>
<div class="row g-3 mb-4">
    <div class="col-md-2">
        <div class="stat-card">
            <div class="stat-value text-info"><?= $stats['open_positions'] ?? 0 ?></div>
            <div class="stat-label">Open Positions</div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="stat-card">
            <div class="stat-value"><?= $stats['closed_trades'] ?? 0 ?></div>
            <div class="stat-label">Closed Trades (<?= $lookbackH ?>h)</div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="stat-card">
            <div class="stat-value <?= ($stats['total_pnl'] ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>">
                <?= ($stats['total_pnl'] ?? 0) >= 0 ? '+' : '' ?><?= number_format($stats['total_pnl'] ?? 0, 4) ?>
            </div>
            <div class="stat-label">Total PnL (<?= $lookbackH ?>h)</div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="stat-card">
            <div class="stat-value text-success"><?= $stats['wins'] ?? 0 ?></div>
            <div class="stat-label">Wins (<?= $lookbackH ?>h)</div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="stat-card">
            <div class="stat-value text-danger"><?= $stats['losses'] ?? 0 ?></div>
            <div class="stat-label">Losses (<?= $lookbackH ?>h)</div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['win_rate'] ?? 0, 1) ?>%</div>
            <div class="stat-label">Win Rate (<?= $lookbackH ?>h)</div>
        </div>
    </div>
</div>

<div class="mb-4">
    <?php if ($statsSource === 'bybit_closed_pnl'): ?>
        <div class="text-muted small">Stats source: Bybit <code>/v5/position/closed-pnl</code>, lookback <?= $lookbackH ?>h</div>
    <?php else: ?>
        <div class="text-warning small">Stats source: local storage (fallback).<?= $closedPnlError !== '' ? ' closed-pnl error: ' . htmlspecialchars($closedPnlError) : '' ?></div>
    <?php endif; ?>
</div>

<!-- Last Run Info -->
<?php if (!empty($lastRun)): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-2"></i>Last Run</span>
        <span class="badge <?= ($lastRun['ok'] ?? false) ? 'bg-success' : 'bg-danger' ?>">
            <?= ($lastRun['status'] ?? 'unknown') ?>
        </span>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <small class="text-muted">Timestamp</small>
                <div><?= htmlspecialchars($lastRun['ts'] ?? 'N/A') ?></div>
            </div>
            <div class="col-md-2">
                <small class="text-muted">Duration</small>
                <div><?= ($lastRun['duration_ms'] ?? 0) ?>ms</div>
            </div>
            <div class="col-md-2">
                <small class="text-muted">Intents Loaded</small>
                <div><?= ($lastRun['intents_loaded'] ?? 0) ?></div>
            </div>
            <div class="col-md-2">
                <small class="text-muted">Intents Valid</small>
                <div class="text-success"><?= ($lastRun['intents_valid'] ?? 0) ?></div>
            </div>
            <div class="col-md-2">
                <small class="text-muted">Intents Rejected</small>
                <div class="text-danger"><?= ($lastRun['intents_rejected'] ?? 0) ?></div>
            </div>
            <div class="col-md-1">
                <small class="text-muted">Errors</small>
                <div class="<?= ($lastRun['errors_count'] ?? 0) > 0 ? 'text-danger' : '' ?>">
                    <?= ($lastRun['errors_count'] ?? 0) ?>
                </div>
            </div>
        </div>
        
        <?php if (!empty($lastRun['steps'])): ?>
        <hr>
        <h6>Execution Steps</h6>
        <div class="row g-2">
            <?php foreach ($lastRun['steps'] as $step): ?>
            <div class="col-md-2">
                <div class="small">
                    <span class="badge bg-<?= ($step['status'] ?? '') === 'ok' ? 'success' : 'warning' ?> me-1">
                        <?= htmlspecialchars($step['step'] ?? '') ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($lastRun['errors'])): ?>
        <hr>
        <h6 class="text-danger">Errors</h6>
        <ul class="mb-0">
            <?php foreach ($lastRun['errors'] as $error): ?>
            <li class="text-danger small"><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($lastRun)): ?>
<!-- P6.11: Explainability (no SSH needed) -->
<script>
    // Optional: auto reload dashboard every 30s, paused when JSON modal is open
    window.TRADING_BOT_AUTO_RELOAD_SEC = 30;
</script>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-info-circle me-2"></i>Explainability</span>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-light"
                    data-json="<?= htmlspecialchars(json_encode($lastRun, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
                    onclick="openJsonModalFromBtn(this, 'last_run.json')">
                <i class="bi bi-braces me-1"></i>RAW last_run
            </button>
        </div>
    </div>
    <div class="card-body">
        <!-- Warnings / Decisions -->
        <?php if (!empty($lastRun['warnings']) && is_array($lastRun['warnings'])): ?>
        <div class="mb-3">
            <small class="text-muted d-block mb-2">Warnings & Decisions</small>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($lastRun['warnings'] as $w): ?>
                    <?php
                        $badge = 'bg-secondary';
                        if (is_string($w)) {
                            if (str_starts_with($w, 'Deferred:')) { $badge = 'bg-warning text-dark'; }
                            elseif (str_starts_with($w, 'Rejected:')) { $badge = 'bg-danger'; }
                            elseif (str_starts_with($w, 'P5: Truncated')) { $badge = 'bg-secondary'; }
                            elseif (str_starts_with($w, 'P5: Execution stopped')) { $badge = 'bg-danger'; }
                        }
                    ?>
                    <span class="badge <?= $badge ?>"><?= htmlspecialchars($w) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="row g-3">
            <!-- Selected Intent -->
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-bullseye me-2"></i>Selected Intent</span>
                        <button class="btn btn-sm btn-outline-light"
                                data-json="<?= htmlspecialchars(json_encode($lastRun['selected_intent'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
                                onclick="openJsonModalFromBtn(this, 'selected_intent')">
                            RAW
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($lastRun['selected_intent'])): ?>
                            <p class="text-muted mb-0">No intent selected in last run.</p>
                        <?php else: ?>
                            <?php $si = $lastRun['selected_intent']; ?>
                            <div class="row small">
                                <div class="col-6">
                                    <div class="text-muted">Symbol</div>
                                    <div><strong><?= htmlspecialchars($si['symbol'] ?? '') ?></strong></div>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted">Side</div>
                                    <div><?= htmlspecialchars(strtoupper((string)($si['side'] ?? ''))) ?></div>
                                </div>
                                <div class="col-6 mt-2">
                                    <div class="text-muted">Entry Action</div>
                                    <div><?= htmlspecialchars((string)($si['entry_action'] ?? '')) ?></div>
                                </div>
                                <div class="col-6 mt-2">
                                    <div class="text-muted">Entry Price</div>
                                    <div><?= isset($si['entry_price']) ? htmlspecialchars((string)$si['entry_price']) : '-' ?></div>
                                </div>
                            </div>
                            <hr>
                            <?php $r = is_array($si['risk'] ?? null) ? $si['risk'] : []; ?>
                            <div class="row small">
                                <div class="col-6">
                                    <div class="text-muted">Budget</div>
                                    <div><?= htmlspecialchars((string)($r['budget_usdt_per_trade'] ?? '')) ?> USDT</div>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted">Leverage</div>
                                    <div><?= htmlspecialchars((string)($r['leverage'] ?? '')) ?>x</div>
                                </div>
                                <div class="col-6 mt-2">
                                    <div class="text-muted">Stop From Liq Range</div>
                                    <div><?= htmlspecialchars((string)($r['stop_from_liq_range_pct'] ?? '')) ?>%</div>
                                </div>
                                <div class="col-6 mt-2">
                                    <div class="text-muted">Order Type</div>
                                    <div><?= htmlspecialchars((string)($r['order_type'] ?? '')) ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Decision -->
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-check2-circle me-2"></i>Selected Decision</span>
                        <button class="btn btn-sm btn-outline-light"
                                data-json="<?= htmlspecialchars(json_encode($lastRun['selected_decision'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
                                onclick="openJsonModalFromBtn(this, 'selected_decision')">
                            RAW
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($lastRun['selected_decision'])): ?>
                            <p class="text-muted mb-0">No decision captured in last run.</p>
                        <?php else: ?>
                            <?php $sd = $lastRun['selected_decision']; ?>
                            <?php
                                $type = $sd['type'] ?? 'unknown';
                                $badge = 'bg-secondary';
                                if ($type === 'opened') { $badge = 'bg-success'; }
                                elseif ($type === 'deferred') { $badge = 'bg-warning text-dark'; }
                                elseif ($type === 'rejected') { $badge = 'bg-danger'; }
                                elseif ($type === 'error') { $badge = 'bg-danger'; }
                            ?>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge <?= $badge ?>"><?= htmlspecialchars(strtoupper((string)$type)) ?></span>
                                <span class="small text-muted"><?= htmlspecialchars((string)($sd['status'] ?? '')) ?></span>
                            </div>
                            <?php if (!empty($sd['reason'])): ?>
                                <div class="small">
                                    <span class="text-muted">Reason:</span>
                                    <span><?= htmlspecialchars((string)$sd['reason']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php $ctx = is_array($sd['context'] ?? null) ? $sd['context'] : []; ?>
                            <?php if (!empty($ctx)): ?>
                                <hr>
                                <small class="text-muted d-block mb-2">Context</small>
                                <pre class="small mb-0 p-2 rounded" style="white-space:pre-wrap;word-break:break-word;background:rgba(0,0,0,0.25);border:1px solid var(--border-color);"><?= htmlspecialchars(json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></pre>
                            <?php endif; ?>
                            <?php if (!empty($sd['rejected_item'])): ?>
                                <hr>
                                <button class="btn btn-sm btn-outline-light"
                                        data-json="<?= htmlspecialchars(json_encode($sd['rejected_item'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
                                        onclick="openJsonModalFromBtn(this, 'rejected_intent_file')">
                                    <i class="bi bi-file-earmark-text me-1"></i>Rejected file
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Balance Snapshot -->
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-wallet2 me-2"></i>Balance Snapshot (last)</span>
                        <button class="btn btn-sm btn-outline-light"
                                data-json="<?= htmlspecialchars(json_encode($lastRun['balance_snapshot_last'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
                                onclick="openJsonModalFromBtn(this, 'balance_snapshot_last')">
                            RAW
                        </button>
                    </div>
                    <div class="card-body">
                        <?php $bal = $lastRun['balance_snapshot_last'] ?? null; ?>
                        <?php if (empty($bal) || !is_array($bal)): ?>
                            <p class="text-muted mb-0">No balance snapshot captured in last run (may be deferred or dry mode).</p>
                        <?php else: ?>
                            <div class="row small">
                                <div class="col-md-3">
                                    <div class="text-muted">Available</div>
                                    <div><strong><?= htmlspecialchars((string)($bal['available_usd'] ?? $bal['available'] ?? '')) ?></strong></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-muted">Equity</div>
                                    <div><?= htmlspecialchars((string)($bal['total_equity_usd'] ?? $bal['equity_usd'] ?? '')) ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-muted">Source</div>
                                    <div><?= htmlspecialchars((string)($bal['source'] ?? '')) ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-muted">Coin</div>
                                    <div><?= htmlspecialchars((string)($bal['coin'] ?? $bal['balance_coin'] ?? 'USDT')) ?></div>
                                </div>
                            </div>
                            <?php if (!empty($bal['account_totals_missing'])): ?>
                                <div class="mt-2 small text-warning">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    account totals missing → used fallback (coin_equity_fallback)
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Intents Preview -->
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-list-ul me-2"></i>Intents Preview</span>
                        <span class="small text-muted">
                            Showing <?= is_array($lastRun['intents_preview'] ?? null) ? count($lastRun['intents_preview']) : 0 ?> /
                            <?= (int)($lastRun['intents_preview_total'] ?? 0) ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <?php $ip = $lastRun['intents_preview'] ?? []; ?>
                        <?php if (empty($ip) || !is_array($ip)): ?>
                            <p class="text-muted mb-0">No intents preview.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>Symbol</th>
                                            <th>Side</th>
                                            <th>Entry Action</th>
                                            <th>Budget</th>
                                            <th>Lev</th>
                                            <th>Stop%</th>
                                            <th>RAW</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ip as $row): ?>
                                            <?php $r = is_array($row['risk'] ?? null) ? $row['risk'] : []; ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars((string)($row['symbol'] ?? '')) ?></strong></td>
                                                <td><?= htmlspecialchars(strtoupper((string)($row['side'] ?? ''))) ?></td>
                                                <td><?= htmlspecialchars((string)($row['entry_action'] ?? '')) ?></td>
                                                <td><?= htmlspecialchars((string)($r['budget_usdt_per_trade'] ?? '')) ?></td>
                                                <td><?= htmlspecialchars((string)($r['leverage'] ?? '')) ?></td>
                                                <td><?= htmlspecialchars((string)($r['stop_from_liq_range_pct'] ?? '')) ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-light"
                                                            data-json="<?= htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
                                                            onclick="openJsonModalFromBtn(this, 'intent_preview_item')">
                                                        View
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
<?php endif; ?>


<!-- Active Trades -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-graph-up-arrow me-2"></i>Active Trades</span>
        <a href="<?= System::adminUrl('trading/trades') ?>" class="btn btn-sm btn-outline-light">View All</a>
    </div>
    <div class="card-body">
        <?php if (empty($activeTrades)): ?>
        <p class="text-muted mb-0">No active trades</p>
        <?php else: ?>
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Symbol</th>
                    <th>Side</th>
                    <th>Entry</th>
                    <th>Size</th>
                    <th>TP</th>
                    <th>SL</th>
                    <th>Opened</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($activeTrades, 0, 10) as $trade): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($trade['symbol'] ?? '') ?></strong></td>
                    <td>
                        <span class="badge bg-<?= ($trade['side'] ?? '') === 'long' ? 'success' : 'danger' ?>">
                            <?= strtoupper($trade['side'] ?? '') ?>
                        </span>
                    </td>
                    <td><?= number_format($trade['entry_price'] ?? 0, 6) ?></td>
                    <td><?= number_format($trade['position_size'] ?? 0, 4) ?></td>
                    <td class="text-success"><?= isset($trade['take_profit']) && $trade['take_profit'] ? number_format($trade['take_profit'], 6) : '-' ?></td>
                    <td class="text-danger"><?= isset($trade['stop_loss']) && $trade['stop_loss'] ? number_format($trade['stop_loss'], 6) : '-' ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($trade['opened_at'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Dashboard Widget: Recent Closed Trades (10) -->
<div class="row g-3 mt-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-x-circle me-2"></i>Recent Closed Trades</span>
                <a href="<?= System::adminUrl('trading/trades?status=closed') ?>" class="btn btn-sm btn-outline-light">View All</a>
            </div>
            <div class="card-body">
                <?php if (empty($recentClosedTrades)): ?>
                <p class="text-muted mb-0">No closed trades yet</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Symbol</th>
                                <th>Side</th>
                                <th>Close Price</th>
                                <th>PnL</th>
                                <th>Reason</th>
                                <th>Closed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentClosedTrades as $trade): ?>
                            <?php
                                $pnl = (float)($trade['pnl'] ?? $trade['realized_pnl'] ?? 0);
                                $closeReason = $trade['close_reason'] ?? 'unknown';
                                // Badge with icon for accessibility
                                $reasonConfig = match($closeReason) {
                                    'stop_loss' => ['class' => 'bg-danger', 'icon' => 'bi-x-octagon'],
                                    'trailing_stop' => ['class' => 'bg-warning text-dark', 'icon' => 'bi-arrow-up-right'],
                                    'manual_close' => ['class' => 'bg-info', 'icon' => 'bi-hand-index'],
                                    default => ['class' => 'bg-secondary', 'icon' => 'bi-question-circle'],
                                };
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($trade['symbol'] ?? '') ?></strong></td>
                                <td>
                                    <span class="badge bg-<?= ($trade['side'] ?? '') === 'long' ? 'success' : 'danger' ?>">
                                        <?= strtoupper(substr($trade['side'] ?? '', 0, 1)) ?>
                                    </span>
                                </td>
                                <td><?= isset($trade['close_price']) && $trade['close_price'] > 0 ? number_format((float)$trade['close_price'], 6) : '-' ?></td>
                                <td class="<?= $pnl >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= $pnl >= 0 ? '+' : '' ?><?= number_format($pnl, 4) ?>
                                </td>
                                <td><span class="badge <?= $reasonConfig['class'] ?> small"><i class="bi <?= $reasonConfig['icon'] ?> me-1"></i><?= htmlspecialchars($closeReason) ?></span></td>
                                <td class="small text-muted"><?= htmlspecialchars(substr($trade['closed_at'] ?? '', 0, 16)) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Dashboard Widget: ProfitManager Applied Events (10) -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-arrow-up-circle me-2"></i>ProfitManager Applied</span>
                <a href="<?= System::adminUrl('trading/profit') ?>" class="btn btn-sm btn-outline-light">View All</a>
            </div>
            <div class="card-body">
                <?php if (empty($profitManagerApplied)): ?>
                <p class="text-muted mb-0">No ProfitManager events yet</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Symbol</th>
                                <th>Action</th>
                                <th>ROI</th>
                                <th>Old SL</th>
                                <th>New SL</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($profitManagerApplied as $event): ?>
                            <?php
                                $details = $event['details'] ?? [];
                                $action = $event['action'] ?? 'unknown';
                                // Badge with icon for accessibility
                                $actionConfig = match($action) {
                                    'step_sl_update' => ['class' => 'bg-success', 'icon' => 'bi-stair'],
                                    'dumb_trailing_set' => ['class' => 'bg-info', 'icon' => 'bi-arrow-up-right-circle'],
                                    default => ['class' => 'bg-secondary', 'icon' => 'bi-gear'],
                                };
                                $roi = (float)($details['context']['roi_pct'] ?? $details['roi_pct'] ?? 0);
                                $oldSl = (float)($details['old_sl'] ?? 0);
                                $newSl = (float)($details['new_sl'] ?? 0);
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($event['symbol'] ?? '') ?></strong></td>
                                <td><span class="badge <?= $actionConfig['class'] ?> small"><i class="bi <?= $actionConfig['icon'] ?> me-1"></i><?= htmlspecialchars($action) ?></span></td>
                                <td><?= $roi > 0 ? number_format($roi, 2) . '%' : '-' ?></td>
                                <td><?= $oldSl > 0 ? number_format($oldSl, 6) : '-' ?></td>
                                <td><?= $newSl > 0 ? number_format($newSl, 6) : '-' ?></td>
                                <td class="small text-muted"><?= htmlspecialchars(substr($event['ts'] ?? '', 0, 16)) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
/* RULES
- Dashboard page for Trading Bot Admin UI
- Shows last_run status, steps, warnings, and P6.11 explainability blocks
- Shows dashboard widgets: 10 recent closed trades, 10 recent ProfitManager applied events
- No exchange actions here; only UI rendering
- Any dynamic data must be escaped via htmlspecialchars()
*/
?>
