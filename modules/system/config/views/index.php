<?php
declare(strict_types=1);

/**
 * Quick Control tab — key operational parameters at a glance.
 *
 * Shows the most frequently-changed operational params extracted from
 * Smart Brain, Trading Bot, and Profit Manager configs.
 * Groups params by category for clarity.
 * READ-ONLY — shadow system.
 */

$params = $data['params'] ?? [];

/**
 * Grouped Quick Control categories.
 * Only the most-used operational fields appear here.
 * Immutable/internal params are in the Advanced tab.
 */
$groups = [
    'Live Trading' => [
        'icon'   => 'bi-activity',
        'color'  => 'text-success',
        'keys'   => ['live_trading_enabled', 'live_max_positions', 'live_signal_selection_mode', 'live_entry_policy', 'live_one_trade_per_symbol'],
    ],
    'Leverage' => [
        'icon'   => 'bi-speedometer2',
        'color'  => 'text-warning',
        'keys'   => ['leverage_mode', 'manual_leverage', 'max_leverage'],
    ],
    'Budget' => [
        'icon'   => 'bi-wallet2',
        'color'  => 'text-info',
        'keys'   => ['max_budget_per_coin'],
    ],
    'Exit Management' => [
        'icon'   => 'bi-sign-stop',
        'color'  => 'text-danger',
        'keys'   => ['stop_control_mode', 'stop_loss_from_entry_roi', 'trailing_enabled', 'trailing_mode', 'trailing_activation_roi', 'break_even_enabled'],
    ],
    'Patterns' => [
        'icon'   => 'bi-diagram-3',
        'color'  => 'text-primary',
        'keys'   => ['execution_profile', 'patterns_enabled', 'patterns_mode'],
    ],
    'Bot Control' => [
        'icon'   => 'bi-robot',
        'color'  => 'text-warning',
        'keys'   => ['bot_enabled', 'bot_mode', 'max_intents_per_run', 'max_concurrent_positions', 'bot_brain_controlled'],
    ],
    'Profit Manager' => [
        'icon'   => 'bi-cash-coin',
        'color'  => 'text-success',
        'keys'   => ['pm_enabled', 'pm_trailing_owner'],
    ],
];

// Find any conflicted params across all groups
$allGroupKeys = [];
foreach ($groups as $g) {
    $allGroupKeys = array_merge($allGroupKeys, $g['keys']);
}
$allGroupKeys = array_unique($allGroupKeys);
$conflicts = array_filter($params, static fn($e, $k) => in_array($k, $allGroupKeys, true) && isset($e['all_values']) && count($e['all_values']) > 1, ARRAY_FILTER_USE_BOTH);

$renderVal = static function($val): string {
    if ($val === null) { return '—'; }
    if (is_bool($val)) { return $val ? 'true' : 'false'; }
    if (is_array($val)) { return implode(', ', array_map('strval', $val)); }
    $s = (string)$val;
    return $s === '' ? '—' : $s;
};

ob_start();
?>
<div class="row g-3">

    <?php foreach ($groups as $groupName => $groupDef): ?>
    <?php
    $groupParams = [];
    foreach ($groupDef['keys'] as $k) {
        if (isset($params[$k])) {
            $groupParams[$k] = $params[$k];
        }
    }
    if (empty($groupParams)) continue;
    ?>
    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center">
                <i class="bi <?= htmlspecialchars($groupDef['icon']) ?> me-2 <?= htmlspecialchars($groupDef['color']) ?>"></i>
                <strong><?= htmlspecialchars($groupName) ?></strong>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th style="width:45%">Parameter</th>
                            <th style="width:25%">Value</th>
                            <th>Source</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($groupParams as $key => $entry): ?>
                        <?php
                        $val      = $entry['value'];
                        $valStr   = $renderVal($val);
                        $srcShort = basename($entry['source_file'] ?? '');
                        $conflict = isset($entry['all_values']) && count($entry['all_values']) > 1;
                        ?>
                        <tr class="param-row <?= $conflict ? 'table-danger' : '' ?>">
                            <td>
                                <?= htmlspecialchars($entry['label'] ?? $key) ?>
                                <?php if ($conflict): ?>
                                    <span class="badge badge-conflict conflict-badge ms-1">conflict</span>
                                <?php endif; ?>
                            </td>
                            <td class="font-monospace"><?= htmlspecialchars($valStr) ?></td>
                            <td class="source-tag"><?= htmlspecialchars($srcShort ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Conflict summary (only shown if any Quick Control param has conflicts) -->
    <?php if (!empty($conflicts)): ?>
    <div class="col-12">
        <div class="card border-danger">
            <div class="card-header text-danger">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                Conflicts detected in Quick Control parameters — check Advanced tab for details
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Parameter</th><th>Source</th><th>Value</th></tr></thead>
                    <tbody>
                    <?php foreach ($conflicts as $key => $entry): ?>
                        <?php foreach ($entry['all_values'] as $srcName => $srcVal): ?>
                        <tr>
                            <td><?= htmlspecialchars($entry['label'] ?? $key) ?></td>
                            <td class="source-tag"><?= htmlspecialchars($srcName) ?></td>
                            <td class="font-monospace small"><?= htmlspecialchars(is_array($srcVal) ? json_encode($srcVal) : (string)$srcVal) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($params)): ?>
    <div class="col-12">
        <div class="card">
            <div class="card-body text-muted">
                No data yet. Click <strong>Re-extract</strong> to run the audit pass.
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
