<?php
declare(strict_types=1);

/**
 * Trading Bot tab — Bot operational config preview + migration status.
 * READ-ONLY — soft-switch migration panel + shadow config preview.
 */

$params          = $data['params']             ?? [];
$botPreview      = ($preview['modules'] ?? [])['trading_bot'] ?? [];
$migrationStatus = $migrationStatus            ?? [];

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

    <?php
    // ── Migration Status Banner ──────────────────────────────────────────────
    $msAvail      = !empty($migrationStatus['available']);
    $msWave       = htmlspecialchars($migrationStatus['switch_wave']       ?? 'v1_operational_params');
    $msPartial    = (bool)($migrationStatus['partially_migrated']           ?? false);
    $msUnified    = (bool)($migrationStatus['unified_config_available']    ?? false);
    $msMigCount   = (int)($migrationStatus['migrated_count']               ?? count($migrationStatus['switched_params'] ?? []));
    $msFbCount    = (int)($migrationStatus['fallback_count']               ?? count($migrationStatus['fallback_params'] ?? []));
    $msTotalCount = (int)($migrationStatus['first_wave_total']             ?? ($msMigCount + $msFbCount));
    $msSwitched   = (array)($migrationStatus['switched_params']            ?? []);
    $msFallback   = (array)($migrationStatus['fallback_params']            ?? []);
    $msFbDetail   = (array)($migrationStatus['fallback_params_detail']     ?? []);
    $msSwDetail   = (array)($migrationStatus['switched_params_detail']     ?? []);
    $msRecAt      = htmlspecialchars($migrationStatus['recorded_at']       ?? '—');
    ?>
    <div class="col-12">
        <div class="card border-<?= $msAvail ? ($msFbCount > 0 ? 'warning' : 'success') : 'secondary' ?>">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span>
                    <i class="bi bi-shuffle me-2 <?= $msAvail ? 'text-warning' : 'text-secondary' ?>"></i>
                    <strong>Trading Bot — Config Migration Status</strong>
                </span>
                <span class="badge <?= $msAvail ? ($msPartial ? 'bg-warning text-dark' : 'bg-success') : 'bg-secondary' ?>">
                    <?php if (!$msAvail): ?>not available
                    <?php elseif ($msPartial): ?>partially migrated / soft-switched
                    <?php elseif ($msMigCount > 0): ?>fully migrated (wave 1)
                    <?php else: ?>legacy only
                    <?php endif; ?>
                </span>
            </div>
            <div class="card-body">
                <?php if (!$msAvail): ?>
                <p class="text-muted mb-0 small">Migration status not available — run Trading Bot at least once to generate <code>runtime/config_source_status.json</code>.</p>
                <?php else: ?>
                <div class="row g-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="small text-muted mb-1">Migration Wave</div>
                        <code class="fs-6"><?= $msWave ?></code>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="small text-muted mb-1">Unified Config Available</div>
                        <span class="badge <?= $msUnified ? 'bg-success' : 'bg-danger' ?>"><?= $msUnified ? 'yes' : 'no' ?></span>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="small text-muted mb-1">Params via Unified Config</div>
                        <strong class="text-success"><?= $msMigCount ?></strong>
                        <span class="text-muted small"> / <?= $msTotalCount ?></span>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="small text-muted mb-1">Params on Legacy Fallback</div>
                        <strong class="<?= $msFbCount > 0 ? 'text-warning' : 'text-muted' ?>"><?= $msFbCount ?></strong>
                        <span class="text-muted small"> / <?= $msTotalCount ?></span>
                    </div>
                </div>

                <?php if (!empty($msSwitched)): ?>
                <div class="mt-3">
                    <div class="small fw-bold text-success mb-1"><i class="bi bi-check-circle me-1"></i>Using Unified Config (<?= $msMigCount ?> params)</div>
                    <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($msSwitched as $sp):
                        $spd = $msSwDetail[$sp] ?? [];
                        $spVal = $spd['value'] ?? '—';
                        if (is_bool($spVal)) $spVal = $spVal ? 'true' : 'false';
                        $spSrcOwner = $spd['source_owner'] ?? 'trading_bot';
                        $spSrcLayer = $spd['source_layer'] ?? 'unified_config';
                        $spOrigSrc  = $spd['original_source'] ?? '—';
                        $spOrigFile = basename($spd['original_source_file'] ?? '');
                        $spTitle = "value={$spVal} | source_owner={$spSrcOwner} | source_layer={$spSrcLayer} | original_source={$spOrigSrc}" . ($spOrigFile ? " ({$spOrigFile})" : '');
                    ?>
                        <span class="badge bg-success bg-opacity-25 border border-success text-success"
                              title="<?= htmlspecialchars($spTitle) ?>">
                            <?= htmlspecialchars($sp) ?>
                            <span class="ms-1 opacity-75 font-monospace" style="font-size:0.7em"><?= htmlspecialchars((string)$spVal) ?></span>
                        </span>
                    <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($msFallback)): ?>
                <div class="mt-2">
                    <div class="small fw-bold text-warning mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Legacy Fallback in use (<?= $msFbCount ?> params) — explicit, not silent</div>
                    <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($msFallback as $fp):
                        $fpd = $msFbDetail[$fp] ?? [];
                        $fpVal = $fpd['value'] ?? '—';
                        if (is_bool($fpVal)) $fpVal = $fpVal ? 'true' : 'false';
                        $fpReason  = $fpd['fallback_reason'] ?? '—';
                        $fpSrc     = $fpd['fallback_source'] ?? '—';
                        $fpSrcOwner= $fpd['source_owner'] ?? 'trading_bot';
                        $fpTitle   = "value={$fpVal} | source_owner={$fpSrcOwner} | source_layer=legacy_bot_runtime | fallback_reason={$fpReason} | fallback_source={$fpSrc}";
                    ?>
                        <span class="badge bg-warning bg-opacity-25 border border-warning text-warning"
                              title="<?= htmlspecialchars($fpTitle) ?>">
                            <?= htmlspecialchars($fp) ?>
                            <span class="ms-1 opacity-75 font-monospace" style="font-size:0.7em"><?= htmlspecialchars((string)$fpVal) ?></span>
                        </span>
                    <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Per-param detail table -->
                <?php
                $allMigDetail = array_merge(
                    array_map(fn($k) => array_merge(['_key' => $k, '_status' => 'unified'], $msSwDetail[$k] ?? []), $msSwitched),
                    array_map(fn($k) => array_merge(['_key' => $k, '_status' => 'fallback'], $msFbDetail[$k] ?? []), $msFallback)
                );
                ?>
                <?php if (!empty($allMigDetail)): ?>
                <div class="mt-3">
                    <div class="small fw-bold mb-2">Per-Parameter Source Proof</div>
                    <div class="table-responsive">
                    <table class="table table-sm mb-0" style="font-size:0.8rem;">
                        <thead>
                            <tr>
                                <th>Parameter</th>
                                <th>Final Value</th>
                                <th>Source Owner</th>
                                <th>Source Layer</th>
                                <th>Unified Config</th>
                                <th>Legacy Fallback</th>
                                <th>Fallback Reason / Original Source</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($allMigDetail as $row):
                            $isUnified = ($row['_status'] === 'unified');
                            $val = $row['value'] ?? '—';
                            if (is_bool($val)) $val = $val ? 'true' : 'false';
                            $srcOwner = $row['source_owner'] ?? 'trading_bot';
                            $srcLayer = $row['source_layer'] ?? ($isUnified ? 'unified_config' : 'legacy_bot_runtime');
                            $ucUsed   = (bool)($row['unified_config_used'] ?? $isUnified);
                            $lfUsed   = (bool)($row['legacy_fallback_used'] ?? !$isUnified);
                            $fbReason = $row['fallback_reason'] ?? ($isUnified ? ('via: ' . ($row['via'] ?? 'unified_config_operational_draft')) : '—');
                            $origSrc  = $row['original_source'] ?? ($row['fallback_source'] ?? '—');
                        ?>
                        <tr>
                            <td><code><?= htmlspecialchars($row['_key']) ?></code></td>
                            <td class="font-monospace"><?= htmlspecialchars((string)$val) ?></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($srcOwner) ?></span></td>
                            <td>
                                <?php if ($isUnified): ?>
                                    <span class="badge bg-success">unified_config</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">legacy_bot_runtime</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($ucUsed): ?>
                                    <span class="badge bg-success"><i class="bi bi-check"></i> yes</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">no</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($lfUsed): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> yes</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">no</span>
                                <?php endif; ?>
                            </td>
                            <td class="source-tag"><?= htmlspecialchars($fbReason) ?><br><span class="text-secondary"><?= htmlspecialchars($origSrc) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mt-2 text-secondary" style="font-size:0.78rem;">
                    Last recorded: <code><?= $msRecAt ?></code>
                    &nbsp;·&nbsp;
                    Source artifact: <code>trading_bot/runtime/config_source_status.json</code>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Operational Config Draft table -->
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
                    <?php
                    // Annotate each section with its source layer
                    $layerMap = [
                        'module'    => ['label' => 'unified_config › bot_runtime › bot_config', 'title' => 'First-wave params via unified Config Module; remainder: bot.json overrides merged with config.php'],
                        'execution' => ['label' => 'unified_config › bot_runtime › bot_config', 'title' => 'First-wave execution params via unified Config Module; remainder from bot.json'],
                        'exchange'  => ['label' => 'bot_config (immutable)',                     'title' => 'exchange defaults from config.php — internal, not in first-wave migration'],
                    ];
                    ?>
                    <?php foreach ($botPreview as $section => $block): ?>
                    <div class="p-2 border-bottom" style="border-color: var(--border-color) !important;">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="text-muted small text-uppercase" style="letter-spacing:.05em"><?= htmlspecialchars($section) ?></span>
                            <?php $layer = $layerMap[$section] ?? ['label' => 'operational_master', 'title' => '']; ?>
                            <span class="badge bg-secondary" title="<?= htmlspecialchars($layer['title']) ?>"><?= htmlspecialchars($layer['label']) ?></span>
                        </div>
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
                    <div class="p-2" style="font-size:0.78rem;color:#64748b;">
                        First-wave params (<code>bot_enabled</code>, <code>bot_mode</code>, <code>max_intents_per_run</code>, <code>max_concurrent_positions</code>, <code>bot_brain_controlled</code>, <code>pm_trailing_owner</code>)
                        now resolved via unified Config Module. Remaining params: <code>bot_runtime</code> (bot.json) overrides <code>bot_config</code> (config.php).
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
