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
        }
        body { background: #0f172a; color: #e2e8f0; }
        .card { background: var(--card-bg); border: 1px solid var(--border-color); }
        .card-header { background: rgba(0,0,0,0.2); border-bottom: 1px solid var(--border-color); }
        .table-dark { --bs-table-bg: transparent; }
        .nav-tabs { border-bottom: 1px solid var(--border-color); }
        .nav-tabs .nav-link { color: #94a3b8; border: none; }
        .nav-tabs .nav-link:hover { color: #e2e8f0; border: none; }
        .nav-tabs .nav-link.active { color: #3b82f6; background: transparent; border-bottom: 2px solid #3b82f6; }
        .form-control, .form-select { background: #1e293b; border-color: var(--border-color); color: #e2e8f0; }
        .form-control:focus, .form-select:focus { background: #1e293b; border-color: var(--primary); color: #e2e8f0; }
        .table { color: #e2e8f0; }
        .table th { color: #94a3b8; font-weight: 600; }
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
