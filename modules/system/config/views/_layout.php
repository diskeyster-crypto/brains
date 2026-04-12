<?php
declare(strict_types=1);

use Core\System\System;

/**
 * Unified Config Module — Admin Layout
 * Consistent with project dark-theme style (Bootstrap 5.3.3 + Bootstrap Icons).
 */

$baseUrl = $baseUrl ?? '/admin/smart_brain/config_all';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Config Preview') ?> — Tredercopis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-dark:      #1a1d21;
            --bg-card:      #212529;
            --border-color: #2d3238;
            --text-muted:   #adb5bd;
        }
        body { background: var(--bg-dark); color: #e9ecef; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .card { background: var(--bg-card); border: 1px solid var(--border-color); }
        .card-header { background: rgba(0,0,0,0.2); border-bottom: 1px solid var(--border-color); }
        .table { --bs-table-bg: transparent; --bs-table-color: #e9ecef; }
        .table > thead { border-bottom: 2px solid var(--border-color); }
        .table > tbody > tr { border-bottom: 1px solid var(--border-color); }
        .nav-tabs .nav-link { color: var(--text-muted); border: none; border-bottom: 2px solid transparent; border-radius: 0; }
        .nav-tabs .nav-link.active { color: #fff; border-bottom-color: #0d6efd; background: transparent; }
        .nav-tabs .nav-link:hover { color: #fff; }
        .badge-operational { background: #0d6efd; }
        .badge-immutable   { background: #6c757d; }
        .badge-conflict    { background: #dc3545; }
        .badge-ok          { background: #198754; }
        .badge-shadow      { background: #fd7e14; color: #000; }
        .param-row:hover   { background: rgba(255,255,255,0.03); }
        .source-tag        { font-size: 0.75rem; color: var(--text-muted); font-family: monospace; }
        .conflict-badge    { font-size: 0.7rem; }
        .shadow-notice     { background: rgba(253,126,20,0.12); border: 1px solid rgba(253,126,20,0.4); border-radius: 6px; padding: 10px 14px; margin-bottom: 1rem; }
    </style>
</head>
<body>
<div class="container-fluid py-4">

    <!-- Header -->
    <div class="d-flex align-items-center mb-3">
        <i class="bi bi-sliders2 fs-4 me-2 text-warning"></i>
        <h4 class="mb-0"><?= htmlspecialchars($title ?? 'Unified Config Preview') ?></h4>
        <span class="badge badge-shadow ms-3">Shadow / Read-Only</span>
    </div>

    <!-- Shadow notice banner -->
    <div class="shadow-notice d-flex align-items-start gap-2">
        <i class="bi bi-eye-fill text-warning mt-1"></i>
        <div>
            <strong>Shadow system — preview only.</strong>
            This module extracts and displays config values from existing modules.
            It does <em>not</em> govern runtime behaviour. No writes to Smart Brain, Trading Bot, Profit Manager, or Coin Passport.
        </div>
    </div>

    <!-- Summary bar -->
    <?php if (!empty($summary)): ?>
    <div class="row g-2 mb-3">
        <div class="col-auto">
            <small class="text-muted">Last extract:</small>
            <strong class="ms-1"><?= $summary['extracted_at'] ? htmlspecialchars(date('Y-m-d H:i', strtotime($summary['extracted_at']))) : '<span class="text-danger">never</span>' ?></strong>
        </div>
        <div class="col-auto">
            <small class="text-muted">Params audited:</small>
            <strong class="ms-1"><?= (int)($summary['param_count'] ?? 0) ?></strong>
        </div>
        <div class="col-auto">
            <?php if (($summary['conflict_count'] ?? 0) > 0): ?>
                <span class="badge badge-conflict"><?= (int)$summary['conflict_count'] ?> conflicts</span>
            <?php else: ?>
                <span class="badge badge-ok">no conflicts</span>
            <?php endif; ?>
        </div>
        <div class="col-auto">
            <form method="post" action="<?= htmlspecialchars($baseUrl) ?>/api/extract" class="d-inline" id="extractForm">
                <button type="submit" class="btn btn-sm btn-outline-warning">
                    <i class="bi bi-arrow-clockwise me-1"></i>Re-extract
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <?php include __DIR__ . '/_tabs.php'; ?>

    <!-- Page content -->
    <?php echo $content ?? ''; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
