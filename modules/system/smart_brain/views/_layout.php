<?php
/**
 * Smart Brain Module - Unified Layout Template
 * 
 * Usage: 
 *   $pageTitle = 'Smart Brain - Page Name';
 *   $activeTab = 'tabname';
 *   $pageContent = function() { ... page content ... };
 *   require __DIR__ . '/_layout.php';
 */

$smartBrainUrl = $smartBrainUrl ?? '/admin/smart_brain';
$pageTitle = $pageTitle ?? 'Smart Brain';
$activeTab = $activeTab ?? 'dashboard';
$flash = $flash ?? null;
$extraStyles = $extraStyles ?? '';
$extraScripts = $extraScripts ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #3b82f6;
            --card-bg: #1e293b;
            --border-color: #334155;
            --hint-color: #94a3b8;
            --label-color: #cbd5e1;
            --heading-color: #f1f5f9;
        }
        body { background: #0f172a; color: #e2e8f0; }
        .card { background: var(--card-bg); border: 1px solid var(--border-color); }
        .card-header { background: rgba(0,0,0,0.2); border-bottom: 1px solid var(--border-color); }
        .card-header h5 { color: var(--heading-color); }
        .table-dark { --bs-table-bg: transparent; }
        .nav-tabs { border-bottom: 1px solid var(--border-color); }
        .nav-tabs .nav-link { color: #94a3b8; border: none; }
        .nav-tabs .nav-link:hover { color: #e2e8f0; border: none; }
        .nav-tabs .nav-link.active { color: #3b82f6; background: transparent; border-bottom: 2px solid #3b82f6; }
        .form-control, .form-select { background: #1e293b; border-color: var(--border-color); color: #f1f5f9; }
        .form-control:focus, .form-select:focus { background: #1e293b; border-color: var(--primary); color: #f1f5f9; box-shadow: 0 0 0 2px rgba(59,130,246,0.15); }
        .form-control::placeholder { color: #64748b; }
        .form-label { color: var(--label-color); }
        .form-label.fw-bold { color: var(--heading-color); }
        .form-check-label { color: var(--label-color); }
        .form-text, small.text-secondary { color: var(--hint-color) !important; }
        .table { color: #e2e8f0; }
        .table th { color: #94a3b8; font-weight: 600; }
        /* Config page: field hint/helper text */
        .cfg-hint { color: #94a3b8; font-size: 0.8rem; line-height: 1.3; margin-top: 0.25rem; }
        .cfg-hint code { color: #93c5fd; background: rgba(59,130,246,0.1); padding: 1px 4px; border-radius: 3px; font-size: 0.78rem; }
        /* Config page: info/tooltip icon */
        .cfg-info { cursor: help; color: #60a5fa; font-size: 0.75rem; margin-left: 4px; }
        .cfg-info:hover { color: #93c5fd; }
        /* Config page: explanation cards */
        .cfg-legend { background: rgba(15,23,42,0.6); border: 1px solid var(--border-color); border-radius: 0.5rem; padding: 1rem 1.25rem; margin-bottom: 1rem; }
        .cfg-legend h6 { color: var(--heading-color); margin-bottom: 0.5rem; }
        .cfg-legend p, .cfg-legend li { color: #cbd5e1; font-size: 0.82rem; line-height: 1.5; }
        .cfg-legend code { color: #93c5fd; background: rgba(59,130,246,0.1); padding: 1px 4px; border-radius: 3px; font-size: 0.8rem; }
        .cfg-legend .text-warning { color: #fbbf24 !important; }
        .cfg-legend .text-success { color: #4ade80 !important; }
        .cfg-legend .text-info { color: #38bdf8 !important; }
        .cfg-legend table { width: 100%; font-size: 0.8rem; }
        .cfg-legend table th { color: #94a3b8; font-weight: 600; padding: 4px 8px; border-bottom: 1px solid var(--border-color); }
        .cfg-legend table td { color: #cbd5e1; padding: 4px 8px; border-bottom: 1px solid rgba(51,65,85,0.5); }
        /* Mode-specific muted fields */
        .cfg-muted-field { opacity: 0.5; }
        .cfg-muted-field .form-label { color: #64748b; }
        .cfg-mode-note { font-size: 0.72rem; color: #f59e0b; font-style: italic; }
        <?= $extraStyles ?>
    </style>
</head>
<body>
<div class="container-fluid py-4">
    <!-- Navigation Tabs -->
    <?php require __DIR__ . '/_tabs.php'; ?>

    <!-- Flash Messages -->
    <?php if ($flash): ?>
    <div class="alert alert-<?= match($flash['type']) { 'success' => 'success', 'warning' => 'warning', default => 'danger' } ?> alert-dismissible fade show">
        <?= htmlspecialchars($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Page Content -->
    <?php if (isset($pageContent) && is_callable($pageContent)): ?>
        <?php $pageContent(); ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?= $extraScripts ?>
</body>
</html>
