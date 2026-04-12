<?php
declare(strict_types=1);

/**
 * Patterns tab — pattern algorithm configuration preview.
 * READ-ONLY — shadow system.
 */

$params = $data['params'] ?? [];
$patternsEnabled = $params['patterns_enabled']['value'] ?? null;
$patternsMode    = $params['patterns_mode']['value']    ?? null;

$knownPatterns = [
    'double_bottom_contextual_v2' => 'Double Bottom Contextual V2',
    'double_bottom_contextual_v3' => 'Double Bottom Contextual V3',
    'double_top_contextual_v2'    => 'Double Top Contextual V2',
    'double_top_contextual_v3'    => 'Double Top Contextual V3',
    'double_bottom'               => 'Double Bottom (Classic)',
    'double_top'                  => 'Double Top (Classic)',
    'double_bottom_confirm_v2'    => 'Double Bottom Confirm V2',
    'double_top_confirm_v2'       => 'Double Top Confirm V2',
    'pullback_trend_continue'     => 'Pullback Trend Continue',
];

ob_start();
?>
<div class="row g-3">
    <div class="col-12 col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-diagram-3 me-2 text-info"></i>Active Patterns</div>
            <div class="card-body">
                <?php if ($patternsEnabled === null): ?>
                    <p class="text-muted">No data — run Re-extract first.</p>
                <?php else: ?>
                    <p class="mb-2">
                        <strong>Mode:</strong>
                        <span class="badge bg-secondary ms-1"><?= htmlspecialchars((string)($patternsMode ?? 'any')) ?></span>
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                    <?php
                    $enabledList = is_array($patternsEnabled) ? $patternsEnabled : [$patternsEnabled];
                    foreach ($enabledList as $pid):
                        $label = $knownPatterns[$pid] ?? $pid;
                    ?>
                        <span class="badge bg-primary px-3 py-2">
                            <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($label) ?>
                        </span>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-list-check me-2 text-muted"></i>All Known Patterns</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Pattern ID</th><th>Label</th><th>Active</th></tr></thead>
                    <tbody>
                    <?php
                    $enabledSet = is_array($patternsEnabled) ? array_flip($patternsEnabled) : [];
                    foreach ($knownPatterns as $pid => $label):
                        $active = isset($enabledSet[$pid]);
                    ?>
                    <tr>
                        <td class="font-monospace small"><?= htmlspecialchars($pid) ?></td>
                        <td><?= htmlspecialchars($label) ?></td>
                        <td>
                            <?php if ($active): ?>
                                <span class="badge badge-ok"><i class="bi bi-check"></i> enabled</span>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-2 text-muted"></i>Source</div>
            <div class="card-body small text-muted">
                Pattern selection is configured in
                <code>modules/system/smart_brain/runtime/user_config.json</code> (patterns.enabled + patterns.mode)
                and reflected in <code>runtime/effective_config.json</code> (pattern_selection block).
                The Unified Config module reads but does not write these files.
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
