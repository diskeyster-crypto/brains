<?php
declare(strict_types=1);

use Core\System\System;

/**
 * Unified Config Module — Admin Layout
 * Uses the same design system as the Smart Brain admin pages.
 */

$baseUrl = $baseUrl ?? '/admin/smart_brain/config_all';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Config Center') ?> — Tredercopis</title>
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
        .card { background: var(--card-bg); border: 1px solid var(--border-color); }
        .card-header { background: rgba(0,0,0,0.2); border-bottom: 1px solid var(--border-color); }
        .table { --bs-table-bg: transparent; color: #e2e8f0; }
        .table th { color: #94a3b8; font-weight: 600; }
        .table > thead { border-bottom: 2px solid var(--border-color); }
        .table > tbody > tr { border-bottom: 1px solid var(--border-color); }
        .nav-tabs { border-bottom: 1px solid var(--border-color); }
        .nav-tabs .nav-link { color: #94a3b8; border: none; border-bottom: 2px solid transparent; border-radius: 0; }
        .nav-tabs .nav-link.active { color: var(--primary); border-bottom-color: var(--primary); background: transparent; }
        .nav-tabs .nav-link:hover { color: #e2e8f0; border: none; }
        .badge-operational { background: var(--primary); }
        .badge-immutable   { background: #475569; }
        .badge-conflict    { background: #dc2626; }
        .badge-ok          { background: #16a34a; }
        .badge-shadow      { background: #f59e0b; color: #000; }
        .param-row:hover   { background: rgba(255,255,255,0.03); }
        .source-tag        { font-size: 0.75rem; color: #94a3b8; font-family: monospace; }
        .conflict-badge    { font-size: 0.7rem; }
        .shadow-notice     { background: rgba(245,158,11,0.10); border: 1px solid rgba(245,158,11,0.35); border-radius: 6px; padding: 10px 14px; margin-bottom: 1rem; font-size: 0.88rem; }
        .breadcrumb-back   { font-size: 0.82rem; color: #64748b; }
        .breadcrumb-back a { color: #60a5fa; text-decoration: none; }
        .breadcrumb-back a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="container-fluid py-4">

    <!-- Breadcrumb / back link -->
    <div class="breadcrumb-back mb-2">
        <a href="/admin/smart_brain"><i class="bi bi-arrow-left me-1"></i>Smart Brain</a>
        <span class="mx-1">/</span>
        <a href="/admin/smart_brain/config">Global Config</a>
        <span class="mx-1">/</span>
        <span class="text-secondary">Config Center</span>
    </div>

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div class="d-flex align-items-center">
            <i class="bi bi-sliders2 fs-4 me-2 text-warning"></i>
            <div>
                <h4 class="mb-0"><?= htmlspecialchars($title ?? 'Config Center') ?></h4>
                <small class="text-secondary">Unified Config — shadow preview, not yet runtime authority</small>
            </div>
            <span class="badge badge-shadow ms-3">Shadow / Read-Only</span>
        </div>
        <?php if (!empty($summary)): ?>
        <div class="d-flex align-items-center gap-3">
            <span class="text-secondary" style="font-size:0.82rem;">
                Last extract:
                <strong class="text-light ms-1"><?= $summary['extracted_at'] ? htmlspecialchars(date('Y-m-d H:i', strtotime($summary['extracted_at']))) : '<span class="text-danger">never</span>' ?></strong>
            </span>
            <span class="text-secondary" style="font-size:0.82rem;">
                Params: <strong class="text-light ms-1"><?= (int)($summary['param_count'] ?? 0) ?></strong>
            </span>
            <?php if (($summary['conflict_count'] ?? 0) > 0): ?>
                <span class="badge badge-conflict"><?= (int)$summary['conflict_count'] ?> conflicts</span>
            <?php else: ?>
                <span class="badge badge-ok">no conflicts</span>
            <?php endif; ?>
            <form method="post" action="<?= htmlspecialchars($baseUrl) ?>/api/extract" class="d-inline" id="extractForm">
                <button type="submit" class="btn btn-sm btn-outline-warning">
                    <i class="bi bi-arrow-clockwise me-1"></i>Re-extract
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- Shadow notice banner -->
    <div class="shadow-notice d-flex align-items-start gap-2">
        <i class="bi bi-eye-fill text-warning mt-1 flex-shrink-0"></i>
        <div>
            <strong>Preview only — migration in progress.</strong>
            This module extracts and audits config values from existing modules.
            It does <em>not</em> govern runtime behaviour.
            Smart Brain, Trading Bot, Profit Manager, and Coin Passport continue to read their own configs.
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
    fetch(this.action, { method: 'POST' })
        .then(r => r.json())
        .then(() => location.reload())
        .catch(() => location.reload());
});
</script>
</body>
</html>
