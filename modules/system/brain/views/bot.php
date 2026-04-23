<?php
/**
 * Brain — Bot Runtime Tab
 *
 * Shows bot runtime truth from last_run.json / stats.json.
 * This is the primary proof that the bot tick/runtime is alive.
 *
 * Variables provided by BrainController::bot():
 *   $lastRun  — array from modules/bot/storage/last_run.json
 *   $stats    — array from modules/bot/storage/stats.json
 *   $botEnabled — bool (from bot config)
 *   $botMode    — string
 *   $flash      — optional flash message
 */

use Core\System\System;

$activeTab = 'bot';
require __DIR__ . '/_tabs.php';

$brainUrl = rtrim(System::web('admin/brain'), '/');

$status     = $lastRun['status']    ?? 'never_run';
$tickAt     = $lastRun['tick_at']   ?? null;
$elapsed    = $lastRun['elapsed_sec'] ?? 0;
$mode       = htmlspecialchars($lastRun['bot_mode'] ?? $botMode ?? 'passive', ENT_QUOTES, 'UTF-8');
$enabled    = $lastRun['bot_enabled'] ?? $botEnabled ?? false;

$statusColor = match ($status) {
    'ok'       => '#22c55e',
    'disabled' => '#f59e0b',
    default    => '#64748b',
};

$lr = function (string $key, mixed $default = 0) use ($lastRun): mixed {
    return $lastRun[$key] ?? $default;
};
$st = function (string $key, mixed $default = 0) use ($stats): mixed {
    return $stats[$key] ?? $default;
};
?>

<?php if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="font-size:13px;">
    <?= htmlspecialchars($flash['message'] ?? '') ?>
</div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="mb-0"><i class="bi bi-cpu me-2"></i>Bot Runtime</h5>
    <div>
        <small class="text-muted me-2">Source: <code>modules/bot/storage/last_run.json</code></small>
        <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()">↺ Refresh</button>
    </div>
</div>

<!-- Runtime truth panel -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-md-3">
        <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:14px;text-align:center;">
            <div style="font-size:22px;font-weight:700;color:<?= $statusColor ?>;"><?= htmlspecialchars($status) ?></div>
            <div style="font-size:11px;color:#64748b;margin-top:2px;">Last tick status</div>
        </div>
    </div>
    <div class="col-sm-6 col-md-3">
        <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:14px;text-align:center;">
            <div style="font-size:18px;font-weight:700;color:<?= $enabled ? '#22c55e' : '#f59e0b' ?>;"><?= $enabled ? 'Enabled' : 'Disabled' ?></div>
            <div style="font-size:11px;color:#64748b;margin-top:2px;">Bot state · mode: <code><?= $mode ?></code></div>
        </div>
    </div>
    <div class="col-sm-6 col-md-3">
        <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:14px;text-align:center;">
            <div style="font-size:18px;font-weight:700;color:#e2e8f0;"><?= $tickAt ? htmlspecialchars(date('H:i:s d.m', strtotime($tickAt))) : '—' ?></div>
            <div style="font-size:11px;color:#64748b;margin-top:2px;">Last tick · <?= $elapsed ?>s</div>
        </div>
    </div>
    <div class="col-sm-6 col-md-3">
        <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:14px;text-align:center;">
            <div style="font-size:22px;font-weight:700;color:#e2e8f0;"><?= (int)$st('ticks_total') ?></div>
            <div style="font-size:11px;color:#64748b;margin-top:2px;">Total ticks</div>
        </div>
    </div>
</div>

<!-- Discovery & signals -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:16px;">
            <h6 style="font-size:12px;color:#94a3b8;text-transform:uppercase;margin-bottom:12px;">Strategy Discovery</h6>
            <table class="table table-sm mb-0" style="font-size:13px;">
                <tr><th>Discovered</th><td><?= (int)$lr('strategies_discovered_total') ?></td></tr>
                <tr><th>Enabled</th><td style="color:#22c55e;"><?= (int)$lr('strategies_enabled_total') ?></td></tr>
                <tr><th>Disabled</th><td style="color:#f59e0b;"><?= (int)$lr('strategies_disabled_total') ?></td></tr>
                <tr><th>Handoff sources active</th><td><?= (int)$lr('handoff_sources_active_total') ?></td></tr>
            </table>
        </div>
    </div>
    <div class="col-md-6">
        <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:16px;">
            <h6 style="font-size:12px;color:#94a3b8;text-transform:uppercase;margin-bottom:12px;">Signal Ingestion (this tick)</h6>
            <table class="table table-sm mb-0" style="font-size:13px;">
                <tr><th>Signals processed</th><td><?= (int)$lr('handoff_signals_processed') ?></td></tr>
                <tr><th>Ignored (disabled strat.)</th><td style="color:#64748b;"><?= (int)$lr('handoff_signals_ignored_disabled_strategy') ?></td></tr>
                <tr><th>Signals seen (total)</th><td><?= (int)$st('handoff_signals_seen_total') ?></td></tr>
                <tr><th>Ignored total</th><td><?= (int)$st('handoff_signals_ignored_disabled_strategy_total') ?></td></tr>
            </table>
        </div>
    </div>
</div>

<!-- Queue state -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:16px;">
            <h6 style="font-size:12px;color:#94a3b8;text-transform:uppercase;margin-bottom:12px;">Order Queue (this tick)</h6>
            <table class="table table-sm mb-0" style="font-size:13px;">
                <tr><th>New</th>      <td style="color:#22c55e;"><?= (int)$lr('order_queue_new_total') ?></td></tr>
                <tr><th>Refreshed</th><td><?= (int)$lr('order_queue_refreshed_total') ?></td></tr>
                <tr><th>Expired</th>  <td style="color:#f59e0b;"><?= (int)$lr('order_queue_expired_total') ?></td></tr>
                <tr><th>Withdrawn</th><td style="color:#64748b;"><?= (int)$lr('order_queue_withdrawn_total') ?></td></tr>
                <tr><th>Active queue size</th><td><strong><?= (int)$lr('order_queue_total') ?></strong></td></tr>
            </table>
        </div>
    </div>
    <div class="col-md-6">
        <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:16px;">
            <h6 style="font-size:12px;color:#94a3b8;text-transform:uppercase;margin-bottom:12px;">Positions &amp; Orders</h6>
            <table class="table table-sm mb-0" style="font-size:13px;">
                <tr><th>Active orders</th>   <td><?= (int)$lr('active_orders_count') ?></td></tr>
                <tr><th>Active positions</th><td><?= (int)$lr('active_positions_count') ?></td></tr>
            </table>
            <div style="margin-top:10px;padding-top:10px;border-top:1px solid #334155;">
                <small style="color:#64748b;">Exchange execution is not active yet. Orders are queued only.</small>
            </div>
        </div>
    </div>
</div>

<!-- Cumulative stats -->
<div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:16px;margin-bottom:16px;">
    <h6 style="font-size:12px;color:#94a3b8;text-transform:uppercase;margin-bottom:12px;">Cumulative Stats (stats.json)</h6>
    <div class="row g-2" style="font-size:12px;">
        <?php
        $cumulKeys = [
            'order_queue_new_total'       => 'Queue new',
            'order_queue_refreshed_total' => 'Queue refreshed',
            'order_queue_expired_total'   => 'Queue expired',
            'order_queue_withdrawn_total' => 'Queue withdrawn',
        ];
        foreach ($cumulKeys as $key => $label): ?>
        <div class="col-6 col-md-3">
            <div style="background:#0f172a;border:1px solid #1e293b;border-radius:6px;padding:8px;text-align:center;">
                <div style="font-size:18px;font-weight:700;color:#e2e8f0;"><?= (int)$st($key) ?></div>
                <div style="font-size:10px;color:#64748b;"><?= $label ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<details>
    <summary style="cursor:pointer;font-size:12px;color:#64748b;margin-bottom:6px;">Raw last_run.json</summary>
    <pre style="background:#0f172a;border:1px solid #1e293b;border-radius:4px;padding:10px;font-size:11px;overflow:auto;"><?= htmlspecialchars(json_encode($lastRun, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
</details>
