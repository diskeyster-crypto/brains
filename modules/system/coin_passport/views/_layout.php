<?php
/**
 * Coin Passport Module - Layout Template
 *
 * Usage:
 *   $pageTitle   = 'Coin Passports - ...';
 *   $activeView  = 'index' | 'detail';
 *   $pageContent = function() { ... };
 *   $baseUrl     = '/admin/coin_passport';
 *   $extraStyles  = '';
 *   $extraScripts = '';
 *   require __DIR__ . '/_layout.php';
 */

$baseUrl      = $baseUrl      ?? '/admin/coin_passport';
$pageTitle    = $pageTitle    ?? 'Coin Passports';
$activeView   = $activeView   ?? 'index';
$extraStyles  = $extraStyles  ?? '';
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
        }
        body { background: #0f172a; color: #e2e8f0; }
        .card  { background: var(--card-bg); border: 1px solid var(--border-color); }
        .card-header { background: rgba(0,0,0,0.2); border-bottom: 1px solid var(--border-color); }
        .nav-tabs { border-bottom: 1px solid var(--border-color); }
        .nav-tabs .nav-link { color: #94a3b8; border: none; }
        .nav-tabs .nav-link:hover { color: #e2e8f0; border: none; }
        .nav-tabs .nav-link.active { color: #3b82f6; background: transparent; border-bottom: 2px solid #3b82f6; }
        .table-dark { --bs-table-bg: transparent; }
        .table th { color: #94a3b8; font-weight: 600; }
        .badge-conf-high   { background: #16a34a; }
        .badge-conf-medium { background: #d97706; }
        .badge-conf-low    { background: #dc2626; }
        .badge-conf-none   { background: #475569; }
        .corridor-bar { height: 6px; background: #334155; border-radius: 3px; position: relative; overflow: hidden; }
        .corridor-fill { height: 100%; background: linear-gradient(90deg, #1d4ed8, #3b82f6, #60a6fa); border-radius: 3px; }
        .stat-card { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 1rem; text-align: center; }
        .stat-value { font-size: 1.3rem; font-weight: 700; color: #3b82f6; }
        .stat-label { font-size: 0.72rem; color: #94a3b8; margin-top: 0.25rem; }
        .positive { color: #4ade80; }
        .negative { color: #f87171; }
        .neutral  { color: #94a3b8; }
        <?= $extraStyles ?>
    </style>
</head>
<body>
<div class="container-fluid py-4">

    <!-- Module header -->
    <div class="d-flex align-items-center gap-3 mb-3">
        <a href="<?= htmlspecialchars($baseUrl) ?>" class="text-decoration-none">
            <h5 class="mb-0 text-primary"><i class="bi bi-passport me-1"></i> Coin Passports</h5>
        </a>
        <span class="text-secondary small">per-symbol ROI corridor &amp; trailing intelligence</span>
        <div class="ms-auto">
            <a href="<?= htmlspecialchars($baseUrl) ?>" class="btn btn-sm <?= $activeView === 'index' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                <i class="bi bi-grid-3x3-gap me-1"></i>All Passports
            </a>
        </div>
    </div>

    <!-- Flash message -->
    <div id="flash-msg" style="display:none; position:fixed; top:1rem; right:1rem; z-index:9999; min-width:280px;">
        <div class="alert alert-dismissible shadow">
            <span id="flash-text"></span>
            <button type="button" class="btn-close" onclick="document.getElementById('flash-msg').style.display='none'"></button>
        </div>
    </div>

    <!-- Page content -->
    <?php if (isset($pageContent) && is_callable($pageContent)) { ($pageContent)(); } ?>

</div><!-- /container-fluid -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showFlash(msg, type) {
    const el = document.getElementById('flash-msg');
    const inner = el.querySelector('.alert');
    inner.className = 'alert alert-' + type + ' alert-dismissible shadow';
    document.getElementById('flash-text').textContent = msg;
    el.style.display = 'block';
    if (type === 'success') { setTimeout(() => { el.style.display = 'none'; }, 4000); }
}
</script>
<?= $extraScripts ?>
</body>
</html>
