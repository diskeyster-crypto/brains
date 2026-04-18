<?php
/**
 * Win Universe Module — Layout Template
 *
 * @var string $pageTitle
 * @var string $pageContent  callable that renders the page body
 * @var string $baseUrl
 * @var string $extraScripts
 */

$baseUrl     = $baseUrl     ?? '/admin/win_universe';
$pageTitle   = $pageTitle   ?? 'Win Universe';
$extraScripts = $extraScripts ?? '';
?>
<!DOCTYPE html>
<html lang="ru">
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
        body { background: #0f172a; color: #e2e8f0; font-size: 0.9rem; }
        .card { background: var(--card-bg); border: 1px solid var(--border-color); }
        .card-header { background: rgba(0,0,0,0.2); border-bottom: 1px solid var(--border-color); }
        .table-dark { --bs-table-bg: transparent; }
        .table th { color: #94a3b8; font-weight: 600; font-size: 0.78rem; text-transform: uppercase; }
        .positive { color: #4ade80; }
        .negative { color: #f87171; }
        .neutral  { color: #94a3b8; }
        .stat-card { background: var(--card-bg); border: 1px solid var(--border-color);
                     border-radius: 8px; padding: 1rem; text-align: center; }
        .stat-value { font-size: 1.5rem; font-weight: 700; color: var(--primary); }
        .stat-label { font-size: 0.75rem; color: #94a3b8; margin-top: 0.2rem; }
        #flash-msg { display: none; position: fixed; top: 1rem; right: 1rem;
                     z-index: 9999; min-width: 260px; }
    </style>
</head>
<body>
<div class="container-fluid py-4">

    <div id="flash-msg" class="alert" role="alert"></div>

    <?php if (is_callable($pageContent ?? null)): ?>
        <?php ($pageContent)(); ?>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showFlash(msg, type) {
    const el = document.getElementById('flash-msg');
    if (!el) return;
    el.className = 'alert alert-' + type;
    el.textContent = msg;
    el.style.display = 'block';
    setTimeout(function() { el.style.display = 'none'; }, 4000);
}
</script>
<?= $extraScripts ?>
</body>
</html>
