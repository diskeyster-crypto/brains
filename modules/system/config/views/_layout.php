<?php
declare(strict_types=1);

use Core\System\System;

/**
 * Unified Config Module — Admin Layout (Russian UI)
 * Uses the same design system as the Smart Brain admin pages.
 */

$baseUrl = $baseUrl ?? '/admin/smart_brain/config_all';
$flash   = $flash   ?? [];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Центр Конфигурации') ?> — Tredercopis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary:      #3b82f6;
            --card-bg:      #1e293b;
            --border-color: #334155;
            --hint-color:   #94a3b8;
            --label-color:  #cbd5e1;
            --heading-color:#f1f5f9;
        }
        body { background: #0f172a; color: #e2e8f0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        /* Cards */
        .card { background: var(--card-bg); border: 1px solid var(--border-color); }
        .card-header { background: rgba(0,0,0,0.25); border-bottom: 1px solid var(--border-color); color: #f1f5f9; }
        .card-body { color: #e2e8f0; }
        /* Tables — ensure all text is readable */
        .table { --bs-table-bg: transparent; color: #e2e8f0; }
        .table th { color: #cbd5e1; font-weight: 600; background: rgba(0,0,0,0.15); }
        .table td { color: #e2e8f0; }
        .table > thead { border-bottom: 2px solid var(--border-color); }
        .table > tbody > tr { border-bottom: 1px solid var(--border-color); }
        /* Tabs */
        .nav-tabs { border-bottom: 1px solid var(--border-color); }
        .nav-tabs .nav-link { color: #94a3b8; border: none; border-bottom: 2px solid transparent; border-radius: 0; }
        .nav-tabs .nav-link.active { color: var(--primary); border-bottom-color: var(--primary); background: transparent; }
        .nav-tabs .nav-link:hover { color: #f1f5f9; border: none; }
        /* Badges */
        .badge-operational { background: var(--primary); color: #fff; }
        .badge-master      { background: #7c3aed; color: #fff; }
        .badge-immutable   { background: #475569; color: #e2e8f0; }
        .badge-conflict    { background: #dc2626; color: #fff; }
        .badge-ok          { background: #16a34a; color: #fff; }
        .badge-shadow      { background: #f59e0b; color: #000; }
        /* Param rows */
        .param-row:hover   { background: rgba(255,255,255,0.04); }
        .param-row td      { color: #e2e8f0; }
        /* Source tags: visible but subdued */
        .source-tag        { font-size: 0.75rem; color: #94a3b8; font-family: monospace; }
        /* Conflict badge */
        .conflict-badge    { font-size: 0.7rem; }
        /* Migration notice */
        .migration-notice  { background: rgba(59,130,246,0.10); border: 1px solid rgba(59,130,246,0.35); border-radius: 6px; padding: 10px 14px; margin-bottom: 1rem; font-size: 0.88rem; color: #e2e8f0; }
        .migration-notice strong { color: #f1f5f9; }
        /* Breadcrumb */
        .breadcrumb-back   { font-size: 0.82rem; color: #94a3b8; }
        .breadcrumb-back a { color: #60a5fa; text-decoration: none; }
        .breadcrumb-back a:hover { text-decoration: underline; }
        /* Forms */
        .form-control, .form-select {
            background: #0f172a; color: #e2e8f0; border-color: var(--border-color);
        }
        .form-control::placeholder { color: #64748b; }
        .form-control:focus, .form-select:focus {
            background: #1e293b; color: #e2e8f0; border-color: var(--primary); box-shadow: none;
        }
        .form-check-input { background-color: #0f172a; border-color: #64748b; }
        .form-check-input:checked { background-color: var(--primary); border-color: var(--primary); }
        .form-check-label { color: #e2e8f0; }
        .form-label { color: #cbd5e1; font-weight: 500; }
        /* Master badge */
        .master-badge { background: rgba(124,58,237,0.25); border: 1px solid rgba(124,58,237,0.6); color: #c4b5fd; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; font-family: monospace; }
        /* Helper / hint text */
        .form-text { color: #94a3b8; }
        small.text-secondary, .small.text-secondary { color: #94a3b8 !important; }
        /* Section headers inside cards */
        .card-header .text-muted { color: #94a3b8 !important; }
        /* Monospace values */
        .font-monospace { color: #e2e8f0; }
        /* fw-medium for param labels */
        .fw-medium { color: #f1f5f9; }
        /* Alert secondary */
        .alert-secondary { background: rgba(71,85,105,0.25); border-color: #475569; color: #e2e8f0; }
        /* code/pre */
        code { color: #93c5fd; background: rgba(59,130,246,0.08); padding: 1px 4px; border-radius: 3px; }
    </style>
</head>
<body>
<div class="container-fluid py-4">

    <!-- Breadcrumb / back link -->
    <div class="breadcrumb-back mb-2">
        <a href="/admin/smart_brain"><i class="bi bi-arrow-left me-1"></i>Smart Brain</a>
        <span class="mx-1">/</span>
        <a href="/admin/smart_brain/config">Конфигурация (устар.)</a>
        <span class="mx-1">/</span>
        <span class="text-secondary">Центр Конфигурации</span>
    </div>

    <!-- Flash message -->
    <?php if (!empty($flash)): ?>
    <?php $flashType = $flash['type'] ?? 'info'; $flashClass = ['success'=>'success','error'=>'danger','warning'=>'warning'][$flashType] ?? 'info'; ?>
    <div class="alert alert-<?= $flashClass ?> alert-dismissible fade show py-2" role="alert">
        <?php if ($flashType === 'success'): ?><i class="bi bi-check-circle me-1"></i>
        <?php elseif ($flashType === 'error'): ?><i class="bi bi-x-circle me-1"></i>
        <?php else: ?><i class="bi bi-exclamation-triangle me-1"></i><?php endif; ?>
        <?= htmlspecialchars($flash['message'] ?? '') ?>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div class="d-flex align-items-center">
            <i class="bi bi-sliders2 fs-4 me-2 text-primary"></i>
            <div>
                <h4 class="mb-0"><?= htmlspecialchars($title ?? 'Центр Конфигурации') ?></h4>
                <small style="color:#94a3b8;">
                    Единый Config —
                    <span class="text-success">Smart Brain</span>,
                    <span class="text-success">Trading Bot</span>,
                    <span class="text-warning">Менеджер Прибыли</span>,
                    <span class="text-warning">Coin Passport</span>: частично мигрированы (волна 1)
                </small>
            </div>
            <?php if (!empty($summary['master_saved_at'])): ?>
                <span class="badge badge-master ms-3"><i class="bi bi-floppy me-1"></i>Мастер сохранён: <?= htmlspecialchars(date('H:i', strtotime($summary['master_saved_at']))) ?></span>
            <?php else: ?>
                <span class="badge bg-secondary ms-3">Мастер: не сохранён</span>
            <?php endif; ?>
        </div>
        <?php if (!empty($summary)): ?>
        <div class="d-flex align-items-center gap-3">
            <span class="text-secondary" style="font-size:0.82rem;">
                Извлечено:
                <strong class="text-light ms-1"><?= $summary['extracted_at'] ? htmlspecialchars(date('Y-m-d H:i', strtotime($summary['extracted_at']))) : '<span class="text-danger">никогда</span>' ?></strong>
            </span>
            <span class="text-secondary" style="font-size:0.82rem;">
                Параметров: <strong class="text-light ms-1"><?= (int)($summary['param_count'] ?? 0) ?></strong>
            </span>
            <?php if (($summary['conflict_count'] ?? 0) > 0): ?>
                <span class="badge badge-conflict"><?= (int)$summary['conflict_count'] ?> конфликт(а)</span>
            <?php else: ?>
                <span class="badge badge-ok">без конфликтов</span>
            <?php endif; ?>
            <form method="post" action="<?= htmlspecialchars($baseUrl) ?>/api/extract" class="d-inline" id="extractForm">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-clockwise me-1"></i>Перечитать
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- Migration status notice -->
    <div class="migration-notice d-flex align-items-start gap-2">
        <i class="bi bi-shuffle text-primary mt-1 flex-shrink-0"></i>
        <div>
            <strong>Миграция в процессе — мягкое переключение активно.</strong>
            <span class="text-success">Smart Brain</span>, <span class="text-success">Trading Bot</span>,
            <span class="text-warning">Менеджер Прибыли</span>
            и <span class="text-warning">Coin Passport</span>
            используют Центр Конфигурации как основной источник операционных параметров (волна 1).
            Редактируемые параметры сохраняются в <code>config_operational_master.json</code> и применяются при следующем цикле.
        </div>
    </div>

    <!-- Tabs -->
    <?php include __DIR__ . '/_tabs.php'; ?>

    <!-- Page content -->
    <?php echo $content ?? ''; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('extractForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = this.querySelector('button');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Обработка...';
    fetch(this.action, { method: 'POST' })
        .then(r => r.json())
        .then(() => location.reload())
        .catch(() => location.reload());
});
</script>
</body>
</html>
