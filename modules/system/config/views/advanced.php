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

// Group ownership map by type
$opParams  = array_filter($ownershipParams, static fn($e) => ($e['type'] ?? '') === 'operational');
$immParams = array_filter($ownershipParams, static fn($e) => ($e['type'] ?? '') === 'immutable');

// Readiness badge helper
$readinessBadge = static function(string $status): string {
    return match ($status) {
        'ready_for_soft_switch'       => '<span class="badge bg-success" title="No conflicts; clear runtime owner">✓ ready</span>',
        'blocked_by_conflict'         => '<span class="badge badge-conflict" title="Values differ across sources">⚡ conflict</span>',
        'blocked_by_missing_owner'    => '<span class="badge bg-secondary" title="Not found in any source">— unused</span>',
        'blocked_by_legacy_dependency'=> '<span class="badge bg-warning text-dark" title="Only defined in static config files">⚠ legacy</span>',
        default                       => '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>',
    };
};

ob_start();
?>
<div class="row g-3">

    <!-- ── Conflict Report ──────────────────────────────────────────────────── -->
    <div class="col-12">
        <div class="card <?= !empty($conflictList) ? 'border-danger' : '' ?>">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-exclamation-triangle-fill me-2 <?= !empty($conflictList) ? 'text-danger' : 'text-muted' ?>"></i>
                    Conflict Report
                </span>
                <span class="d-flex gap-2">
                    <?php if (!empty($conflictList)): ?>
                        <span class="badge badge-conflict"><?= count($conflictList) ?> conflict(s)</span>
                    <?php else: ?>
                        <span class="badge badge-ok">no conflicts</span>
                    <?php endif; ?>
                    <?php if (!empty($duplicateList)): ?>
                        <span class="badge bg-secondary"><?= count($duplicateList) ?> same-value dup(s)</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($conflictList)): ?>
                    <div class="p-3 text-muted">No value conflicts detected in audited parameters.</div>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th style="width:18%">Parameter</th>
                            <th style="width:8%">Type</th>
                            <th style="width:14%">Source</th>
                            <th style="width:16%">Source File</th>
                            <th style="width:16%">Value</th>
                            <th style="width:12%">Winner</th>
                            <th>Win Reason &amp; Target</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($conflictList as $cf): ?>
                        <?php $rowCount = count($cf['sources']); ?>
                        <?php foreach ($cf['sources'] as $i => $src): ?>
                        <?php $isWinner = ($src['source'] === $cf['primary']); ?>
                        <tr class="<?= $isWinner ? 'table-danger' : '' ?>">
                            <?php if ($i === 0): ?>
                                <td rowspan="<?= $rowCount ?>" class="align-middle">
                                    <strong><?= htmlspecialchars($cf['key']) ?></strong>
                                </td>
                                <td rowspan="<?= $rowCount ?>" class="align-middle">
                                    <span class="badge badge-<?= htmlspecialchars($cf['type']) ?>"><?= htmlspecialchars($cf['type']) ?></span>
                                </td>
                            <?php endif; ?>
                            <td class="source-tag"><?= htmlspecialchars($src['source']) ?></td>
                            <td class="source-tag"><?= htmlspecialchars(basename($src['file'] ?? '')) ?></td>
                            <td class="font-monospace small"><?= htmlspecialchars(is_array($src['value']) ? json_encode($src['value']) : (string)$src['value']) ?></td>
                            <td class="align-middle">
                                <?php if ($isWinner): ?>
                                    <span class="badge badge-ok">✓ effective</span>
                                <?php else: ?>
                                    <span class="text-muted small">overridden</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($i === 0): ?>
                                <td rowspan="<?= $rowCount ?>" class="small align-middle">
                                    <span class="text-muted"><?= htmlspecialchars($cf['win_reason'] ?? '') ?></span><br>
                                    <span class="badge bg-secondary mt-1"><?= htmlspecialchars($cf['migration_target'] ?? '') ?></span>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($duplicateList)): ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header text-muted">
                <i class="bi bi-copy me-2"></i>Same-value Duplicates (not conflicts — migration housekeeping only)
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Parameter</th><th>Type</th><th>Sources</th><th>Migration Target</th></tr></thead>
                    <tbody>
                    <?php foreach ($duplicateList as $dup): ?>
                    <tr class="param-row">
                        <td class="font-monospace small"><?= htmlspecialchars($dup['key']) ?></td>
                        <td><span class="badge badge-<?= htmlspecialchars($dup['type']) ?>"><?= htmlspecialchars($dup['type']) ?></span></td>
                        <td class="source-tag"><?= htmlspecialchars(implode(', ', $dup['sources'])) ?></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($dup['migration_target'] ?? '') ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Operational Ownership Map ────────────────────────────────────────── -->
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-map me-2 text-info"></i>Ownership Map — Operational Parameters</span>
                <small class="text-muted"><?= $ownershipTs ? htmlspecialchars('Generated: ' . date('Y-m-d H:i', strtotime($ownershipTs))) : '' ?></small>
            </div>
            <div class="card-body p-0">
                <?php if (empty($opParams)): ?>
                    <div class="p-3 text-muted">No data — run Re-extract first.</div>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th style="width:22%">Key</th>
                            <th style="width:22%">Runtime Owner</th>
                            <th style="width:22%">Source File</th>
                            <th style="width:12%">Sources</th>
                            <th style="width:10%">Readiness</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($opParams as $entry):
                        $readiness  = $entry['migration_readiness'] ?? 'blocked_by_missing_owner';
                        $primarySrc = $entry['sources'][0] ?? null;
                        $srcCount   = count($entry['sources'] ?? []);
                    ?>
                    <tr class="param-row <?= ($readiness === 'blocked_by_conflict') ? 'table-danger' : (($readiness === 'blocked_by_missing_owner') ? 'opacity-50' : '') ?>">
                        <td class="font-monospace small align-middle"><?= htmlspecialchars($entry['key']) ?></td>
                        <td class="source-tag align-middle">
                            <?= $primarySrc ? htmlspecialchars($primarySrc['source']) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td class="source-tag align-middle">
                            <?= $primarySrc ? htmlspecialchars(basename($primarySrc['file'] ?? '')) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td class="align-middle">
                            <?php if ($srcCount > 1): ?>
                                <span class="badge bg-secondary" title="<?= htmlspecialchars(implode(', ', array_column($entry['sources'], 'source'))) ?>"><?= $srcCount ?> sources</span>
                            <?php elseif ($srcCount === 1): ?>
                                <span class="badge bg-dark border border-secondary">1 source</span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="align-middle"><?= $readinessBadge($readiness) ?></td>
                        <td class="small text-muted align-middle"><?= htmlspecialchars($entry['notes'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Immutable / Internal Ownership Map ───────────────────────────────── -->
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-lock me-2 text-secondary"></i>Ownership Map — Immutable / Internal Parameters</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($immParams)): ?>
                    <div class="p-3 text-muted">No data — run Re-extract first.</div>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th style="width:22%">Key</th>
                            <th style="width:22%">Runtime Owner</th>
                            <th style="width:22%">Source File</th>
                            <th style="width:10%">Readiness</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($immParams as $entry):
                        $readiness  = $entry['migration_readiness'] ?? 'blocked_by_missing_owner';
                        $primarySrc = $entry['sources'][0] ?? null;
                    ?>
                    <tr class="param-row <?= ($readiness === 'blocked_by_missing_owner') ? 'opacity-50' : '' ?>">
                        <td class="font-monospace small align-middle"><?= htmlspecialchars($entry['key']) ?></td>
                        <td class="source-tag align-middle">
                            <?= $primarySrc ? htmlspecialchars($primarySrc['source']) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td class="source-tag align-middle">
                            <?= $primarySrc ? htmlspecialchars(basename($primarySrc['file'] ?? '')) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td class="align-middle"><?= $readinessBadge($readiness) ?></td>
                        <td class="small text-muted align-middle"><?= htmlspecialchars($entry['notes'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Immutable Config Draft (values) ──────────────────────────────────── -->
    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-lock-fill me-2 text-secondary"></i>Immutable / Internal Config Draft — Current Values</div>
            <div class="card-body p-0">
                <?php if (empty($immutableParams)): ?>
                    <div class="p-3 text-muted">No data — run Re-extract first.</div>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead><tr><th style="width:24%">Key</th><th style="width:30%">Label</th><th>Value</th><th style="width:22%">Source File</th></tr></thead>
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
