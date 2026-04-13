<?php
declare(strict_types=1);

/**
 * Расширенный — полная карта владения, отчёт о конфликтах и immutable конфиг.
 * Только чтение — immutable/internal параметры не редактируются.
 */

$ownershipParams = $ownership['parameters']       ?? [];
$ownershipTs     = $ownership['generated_at']     ?? null;
$conflictList    = $conflicts['conflicts']         ?? [];
$duplicateList   = $conflicts['duplicates']        ?? [];
$immutableParams = $immutable['params']            ?? [];
$masterParams    = $master['params']               ?? [];

// Разбивка карты владения по типу
$opParams  = array_filter($ownershipParams, static fn($e) => ($e['type'] ?? '') === 'operational');
$immParams = array_filter($ownershipParams, static fn($e) => ($e['type'] ?? '') === 'immutable');

// Значок готовности к миграции
$readinessBadge = static function(string $status): string {
    return match ($status) {
        'ready_for_soft_switch'       => '<span class="badge bg-success" title="Нет конфликтов; чёткий runtime-владелец">✓ готов</span>',
        'blocked_by_conflict'         => '<span class="badge badge-conflict" title="Значения различаются">⚡ конфликт</span>',
        'blocked_by_missing_owner'    => '<span class="badge bg-secondary" title="Не найден ни в одном источнике">— не используется</span>',
        'blocked_by_legacy_dependency'=> '<span class="badge bg-warning text-dark" title="Только в статических файлах конфига">⚠ legacy</span>',
        default                       => '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>',
    };
};

ob_start();
?>
<div class="row g-3">

    <!-- ── Отчёт о конфликтах ────────────────────────────────────────────── -->
    <div class="col-12">
        <div class="card <?= !empty($conflictList) ? 'border-danger' : '' ?>">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-exclamation-triangle-fill me-2 <?= !empty($conflictList) ? 'text-danger' : 'text-muted' ?>"></i>
                    Отчёт о конфликтах
                </span>
                <span class="d-flex gap-2">
                    <?php if (!empty($conflictList)): ?>
                        <span class="badge badge-conflict"><?= count($conflictList) ?> конфликт(а)</span>
                    <?php else: ?>
                        <span class="badge badge-ok">без конфликтов</span>
                    <?php endif; ?>
                    <?php if (!empty($duplicateList)): ?>
                        <span class="badge bg-secondary"><?= count($duplicateList) ?> одинак. дубликат(а)</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($conflictList)): ?>
                    <div class="p-3 text-muted">Конфликты значений не обнаружены среди проверяемых параметров.</div>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th style="width:18%">Параметр</th>
                            <th style="width:8%">Тип</th>
                            <th style="width:14%">Источник</th>
                            <th style="width:16%">Файл источника</th>
                            <th style="width:16%">Значение</th>
                            <th style="width:12%">Победитель</th>
                            <th>Причина / Цель</th>
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
                                    <span class="badge badge-ok">✓ эффективен</span>
                                <?php else: ?>
                                    <span class="text-muted small">перекрыт</span>
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
                <i class="bi bi-copy me-2"></i>Одинаковые дубликаты (не конфликты — только для housekeeping миграции)
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Параметр</th><th>Тип</th><th>Источники</th><th>Цель миграции</th></tr></thead>
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

    <!-- ── Карта владения — Операционные параметры ──────────────────────── -->
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-map me-2 text-info"></i>Карта владения — Операционные параметры</span>
                <small class="text-muted"><?= $ownershipTs ? htmlspecialchars('Создано: ' . date('Y-m-d H:i', strtotime($ownershipTs))) : '' ?></small>
            </div>
            <div class="card-body p-0">
                <?php if (empty($opParams)): ?>
                    <div class="p-3 text-muted">Нет данных — нажмите «Перечитать».</div>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th style="width:22%">Ключ</th>
                            <th style="width:22%">Runtime-владелец</th>
                            <th style="width:22%">Файл источника</th>
                            <th style="width:12%">Источников</th>
                            <th style="width:10%">Мастер</th>
                            <th style="width:10%">Готовность</th>
                            <th>Примечания</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($opParams as $entry):
                        $readiness  = $entry['migration_readiness'] ?? 'blocked_by_missing_owner';
                        $primarySrc = $entry['sources'][0] ?? null;
                        $srcCount   = count($entry['sources'] ?? []);
                        $hasMaster  = isset($masterParams[$entry['key']]);
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
                                <span class="badge bg-secondary" title="<?= htmlspecialchars(implode(', ', array_column($entry['sources'], 'source'))) ?>"><?= $srcCount ?> источн.</span>
                            <?php elseif ($srcCount === 1): ?>
                                <span class="badge bg-dark border border-secondary">1 источн.</span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="align-middle">
                            <?php if ($hasMaster): ?>
                                <span class="master-badge">мастер</span>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
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

    <!-- ── Карта владения — Immutable/Internal параметры ────────────────── -->
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-lock me-2 text-secondary"></i>Карта владения — Immutable / Internal параметры</span>
                <span class="badge bg-secondary">только чтение</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($immParams)): ?>
                    <div class="p-3 text-muted">Нет данных — нажмите «Перечитать».</div>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th style="width:22%">Ключ</th>
                            <th style="width:22%">Runtime-владелец</th>
                            <th style="width:22%">Файл источника</th>
                            <th style="width:10%">Готовность</th>
                            <th>Примечания</th>
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

    <!-- ── Immutable конфиг — текущие значения (только чтение) ─────────── -->
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="bi bi-lock-fill me-2 text-secondary"></i>Immutable / Internal конфиг — текущие значения</span>
                <span class="badge bg-secondary">только чтение</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($immutableParams)): ?>
                    <div class="p-3 text-muted">Нет данных — нажмите «Перечитать».</div>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead><tr><th style="width:24%">Ключ</th><th style="width:30%">Описание</th><th>Значение</th><th style="width:22%">Файл источника</th></tr></thead>
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

