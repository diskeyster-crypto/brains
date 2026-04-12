<?php
declare(strict_types=1);

/**
 * Profit Manager tab — PM operational config preview.
 * READ-ONLY — shadow system.
 */

$params    = $data['params']              ?? [];
$pmPreview = ($preview['modules'] ?? [])['profit_manager'] ?? [];

$pmKeys = [
    'pm_enabled',
    'pm_trailing_owner',
    'trailing_enabled',
    'trailing_mode',
    'trailing_activation_roi',
    'trailing_activation_floor_roi',
    'break_even_enabled',
    'break_even_activation_roi',
];

ob_start();
?>
<div class="row g-3">
    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-cash-coin me-2 text-success"></i>Profit Manager — Operational Config (Draft)</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th style="width:40%">Parameter</th><th>Value</th><th>Source File</th></tr></thead>
                    <tbody>
                    <?php foreach ($pmKeys as $k):
                        if (!isset($params[$k])) continue;
                        $e      = $params[$k];
                        $val    = $e['value'];
                        $valStr = is_bool($val) ? ($val ? 'true' : 'false') : (string)$val;
                        $valStr = ($val === null || $valStr === '') ? '—' : $valStr;
                    ?>
                    <tr class="param-row">
                        <td><strong><?= htmlspecialchars($e['label'] ?? $k) ?></strong></td>
                        <td class="font-monospace"><?= htmlspecialchars($valStr) ?></td>
                        <td class="source-tag"><?= htmlspecialchars(basename($e['source_file'] ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-eye me-2 text-success"></i>Profit Manager — Effective Config Preview</div>
            <div class="card-body p-0">
                <?php if (empty($pmPreview)): ?>
                    <p class="p-3 text-muted">No data — run Re-extract first.</p>
                <?php else: ?>
                    <?php
                    $pmLayerMap = [
                        'module'    => ['label' => 'pm_config › bot_config', 'title' => 'PM module block — proxied from trading_bot config.php'],
                        'execution' => ['label' => 'pm_config › bot_runtime', 'title' => 'PM execution block — merged from bot.json + config.php'],
                    ];
                    ?>
                    <?php foreach ($pmPreview as $section => $block): ?>
                    <div class="p-2 border-bottom" style="border-color:var(--border-color)!important;">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="text-muted small text-uppercase"><?= htmlspecialchars($section) ?></span>
                            <?php $layer = $pmLayerMap[$section] ?? ['label' => 'operational_master', 'title' => '']; ?>
                            <span class="badge bg-secondary" title="<?= htmlspecialchars($layer['title']) ?>"><?= htmlspecialchars($layer['label']) ?></span>
                        </div>
                        <?php if (is_array($block)): ?>
                            <?php foreach ($block as $k => $v): ?>
                            <div class="small d-flex gap-2">
                                <span class="text-muted" style="min-width:200px"><?= htmlspecialchars($k) ?></span>
                                <span class="font-monospace"><?= htmlspecialchars(is_bool($v) ? ($v ? 'true' : 'false') : (string)$v) ?></span>
                            </div>
                            <?php endforeach; ?>
                        <?php elseif ($block !== null): ?>
                            <span class="font-monospace small"><?= htmlspecialchars((string)$block) ?></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <div class="p-2" style="font-size:0.78rem;color:#64748b;">
                        Source: <code>pm_config</code> (profit_manager/config/config.php) proxies from <code>bot_config</code> + <code>bot_runtime</code>.
                        Not yet governed by Config Center.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-2 text-muted"></i>Profit Manager Config Architecture</div>
            <div class="card-body small text-muted">
                Profit Manager reads its config via a proxy at
                <code>modules/system/profit_manager/config/config.php</code>.
                That proxy delegates to <code>modules/system/trading_bot/config/config.php</code>
                (profit_manager block) merged with runtime overrides from
                <code>modules/system/trading_bot/config/bot.json</code>.
                The Unified Config module reads all three but does not write them.
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
