<?php
declare(strict_types=1);

/**
 * Advanced / Expert tab — full ownership map, conflict report, and immutable config.
 * READ-ONLY — shadow system.
 */

$ownershipParams = $ownership['parameters']       ?? [];
$ownershipTs     = $ownership['generated_at']     ?? null;
$conflictList    = $conflicts['conflicts']         ?? [];
$duplicateList   = $conflicts['duplicates']        ?? [];
$immutableParams = $immutable['params']            ?? [];

ob_start();
?>
<div class="row g-3">

    <!-- Conflict report -->
    <div class="col-12">
        <div class="card <?= !empty($conflictList) ? 'border-danger' : '' ?>">
            <div class="card-header d-flex justify-content-between">
                <span>
                    <i class="bi bi-exclamation-triangle-fill me-2 <?= !empty($conflictList) ? 'text-danger' : 'text-muted' ?>"></i>
                    Conflict Report
                </span>
                <span>
                    <?php if (!empty($conflictList)): ?>
                        <span class="badge badge-conflict"><?= count($conflictList) ?> conflict(s)</span>
                    <?php else: ?>
                        <span class="badge badge-ok">no conflicts</span>
                    <?php endif; ?>
                    <?php if (!empty($duplicateList)): ?>
                        <span class="badge bg-secondary ms-1"><?= count($duplicateList) ?> duplicate(s)</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($conflictList)): ?>
                    <div class="p-3 text-muted">No value conflicts detected in audited parameters.</div>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead><tr><th>Parameter</th><th>Type</th><th>Source</th><th>File</th><th>Value</th></tr></thead>
                    <tbody>
                    <?php foreach ($conflictList as $cf): ?>
                        <?php foreach ($cf['sources'] as $i => $src): ?>
                        <tr class="<?= $i === 0 ? 'table-danger' : '' ?>">
                            <?php if ($i === 0): ?>
                                <td rowspan="<?= count($cf['sources']) ?>"><strong><?= htmlspecialchars($cf['key']) ?></strong></td>
                                <td rowspan="<?= count($cf['sources']) ?>"><span class="badge badge-<?= htmlspecialchars($cf['type']) ?>"><?= htmlspecialchars($cf['type']) ?></span></td>
                            <?php endif; ?>
                            <td class="source-tag"><?= htmlspecialchars($src['source']) ?></td>
                            <td class="source-tag"><?= htmlspecialchars(basename($src['file'] ?? '')) ?></td>
                            <td class="font-monospace small"><?= htmlspecialchars(is_array($src['value']) ? json_encode($src['value']) : (string)$src['value']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Full ownership map -->
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="bi bi-map me-2 text-info"></i>Full Config Ownership Map</span>
                <small class="text-muted"><?= $ownershipTs ? htmlspecialchars('Generated: ' . date('Y-m-d H:i', strtotime($ownershipTs))) : '' ?></small>
            </div>
            <div class="card-body p-0">
                <?php if (empty($ownershipParams)): ?>
                    <div class="p-3 text-muted">No data — run Re-extract first.</div>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th style="width:22%">Key</th>
                            <th style="width:10%">Type</th>
                            <th style="width:28%">Primary Source</th>
                            <th style="width:10%">Conflict?</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ownershipParams as $entry):
                        $conflict = $entry['conflict'] ?? false;
                        $unused   = $entry['unused']   ?? false;
                        $type     = $entry['type']     ?? 'operational';
                        $primarySrc = $entry['sources'][0] ?? null;
                    ?>
                    <tr class="param-row <?= $conflict ? 'table-danger' : ($unused ? 'opacity-50' : '') ?>">
                        <td class="font-monospace small"><?= htmlspecialchars($entry['key']) ?></td>
                        <td><span class="badge badge-<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></span></td>
                        <td class="source-tag">
                            <?= $primarySrc ? htmlspecialchars(basename($primarySrc['file'] ?? '') . ' → ' . $primarySrc['source']) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td>
                            <?php if ($conflict): ?>
                                <span class="badge badge-conflict conflict-badge">conflict</span>
                            <?php elseif (count($entry['sources'] ?? []) > 1): ?>
                                <span class="badge bg-secondary conflict-badge">dup</span>
                            <?php elseif ($unused): ?>
                                <span class="text-muted small">unused</span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= htmlspecialchars($entry['notes'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Immutable / internal config -->
    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-lock me-2 text-secondary"></i>Immutable / Internal Config (Draft)</div>
            <div class="card-body p-0">
                <?php if (empty($immutableParams)): ?>
                    <div class="p-3 text-muted">No data — run Re-extract first.</div>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead><tr><th style="width:24%">Key</th><th style="width:30%">Label</th><th>Value</th><th style="width:22%">Source</th></tr></thead>
                    <tbody>
                    <?php foreach ($immutableParams as $key => $entry):
                        $val = $entry['value'];
                        $valStr = is_array($val) ? json_encode($val) : (is_bool($val) ? ($val ? 'true' : 'false') : (string)$val);
                        $valStr = ($val === null || $valStr === '') ? '—' : $valStr;
                    ?>
                    <tr class="param-row">
                        <td class="font-monospace small"><?= htmlspecialchars($key) ?></td>
                        <td><?= htmlspecialchars($entry['label'] ?? '') ?></td>
                        <td class="font-monospace small"><?= htmlspecialchars(strlen($valStr) > 120 ? substr($valStr, 0, 117) . '…' : $valStr) ?></td>
                        <td class="source-tag"><?= htmlspecialchars(basename($entry['source_file'] ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
