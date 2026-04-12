<?php
declare(strict_types=1);

/**
 * Trading Bot tab — Bot operational config preview.
 * READ-ONLY — shadow system.
 */

$params     = $data['params']             ?? [];
$botPreview = ($preview['modules'] ?? [])['trading_bot'] ?? [];

$botKeys = [
    'bot_enabled',
    'bot_mode',
    'bot_brain_controlled',
    'max_concurrent_positions',
    'max_intents_per_run',
    'trailing_enabled',
    'break_even_enabled',
    'pm_trailing_owner',
    'pm_enabled',
];

ob_start();
?>
<div class="row g-3">
    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-robot me-2 text-warning"></i>Trading Bot — Operational Config (Draft)</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th style="width:40%">Parameter</th><th>Value</th><th>Source File</th><th>Conflict?</th></tr></thead>
                    <tbody>
                    <?php foreach ($botKeys as $k):
                        if (!isset($params[$k])) continue;
                        $e      = $params[$k];
                        $val    = $e['value'];
                        $valStr = is_array($val) ? json_encode($val) : (is_bool($val) ? ($val ? 'true' : 'false') : (string)$val);
                        $valStr = ($val === null || $valStr === '') ? '—' : $valStr;
                        $conflict = isset($e['all_values']) && count($e['all_values']) > 1;
                    ?>
                    <tr class="param-row">
                        <td><strong><?= htmlspecialchars($e['label'] ?? $k) ?></strong></td>
                        <td class="font-monospace"><?= htmlspecialchars($valStr) ?></td>
                        <td class="source-tag"><?= htmlspecialchars(basename($e['source_file'] ?? '')) ?></td>
                        <td><?php if ($conflict): ?><span class="badge badge-conflict conflict-badge">conflict</span><?php else: ?>—<?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-eye me-2 text-success"></i>Trading Bot — Effective Config Preview</div>
            <div class="card-body p-0">
                <?php if (empty($botPreview)): ?>
                    <div class="p-3 text-muted">No data — run Re-extract first.</div>
                <?php else: ?>
                    <?php foreach ($botPreview as $section => $block): ?>
                    <div class="p-2 border-bottom" style="border-color: var(--border-color) !important;">
                        <div class="text-muted small mb-1 text-uppercase" style="letter-spacing:.05em"><?= htmlspecialchars($section) ?></div>
                        <?php if (is_array($block)): ?>
                            <?php foreach ($block as $k => $v): ?>
                            <div class="small d-flex gap-2">
                                <span class="text-muted" style="min-width:200px"><?= htmlspecialchars($k) ?></span>
                                <span class="font-monospace"><?= htmlspecialchars(is_array($v) ? json_encode($v) : (is_bool($v) ? ($v ? 'true' : 'false') : (string)$v)) ?></span>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
