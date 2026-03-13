<?php
/**
 * Theme Preview View
 * 
 * Full-page preview of a theme.
 * 
 * @var array $theme Theme data to preview
 */

use Core\System\System;

$baseUrl = System::web('admin/theme');
$colors = $theme['colors'] ?? [];
$themeId = $theme['id'] ?? 'system-light';

// Get CSS file content
$themePath = $theme['path'] ?? '';
$baseCss = '';
if (file_exists($themePath . '/base.css')) {
    $baseCss = file_get_contents($themePath . '/base.css');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Theme Preview: <?= htmlspecialchars($theme['name'] ?? 'Unknown') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        <?= $baseCss ?>
        
        .preview-banner {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: rgba(0,0,0,0.9);
            color: white;
            padding: 10px 20px;
            z-index: 9999;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .preview-content {
            margin-top: 60px;
        }
    </style>
</head>
<body class="<?= htmlspecialchars($theme['css_class'] ?? 'theme-light') ?>">
    <!-- Preview Banner -->
    <div class="preview-banner">
        <div>
            <strong>Preview Mode:</strong> <?= htmlspecialchars($theme['name'] ?? 'Unknown Theme') ?>
            <span class="ms-3 text-muted small">
                v<?= htmlspecialchars($theme['version'] ?? '1.0') ?> by <?= htmlspecialchars($theme['author'] ?? 'Unknown') ?>
            </span>
        </div>
        <div>
            <a href="<?= htmlspecialchars($baseUrl . '/activate?theme=' . urlencode($themeId)) ?>" 
               class="btn btn-success btn-sm me-2">
                <i class="bi bi-check-lg"></i> Activate This Theme
            </a>
            <a href="<?= htmlspecialchars($baseUrl) ?>" class="btn btn-outline-light btn-sm">
                <i class="bi bi-x-lg"></i> Close Preview
            </a>
        </div>
    </div>

    <div class="preview-content">
        <div class="d-flex">
            <!-- Sidebar Preview -->
            <div class="sidebar p-3" style="width: 250px; min-height: calc(100vh - 60px);">
                <h5 class="text-white mb-4">Tredercopis</h5>
                
                <nav class="nav flex-column">
                    <a class="nav-link active" href="#">
                        <i class="bi bi-speedometer2 me-2"></i> Dashboard
                    </a>
                    <a class="nav-link" href="#">
                        <i class="bi bi-box me-2"></i> Modules
                    </a>
                    <a class="nav-link" href="#">
                        <i class="bi bi-clock me-2"></i> Cron
                    </a>
                    <a class="nav-link" href="#">
                        <i class="bi bi-database me-2"></i> Storage
                    </a>
                    <a class="nav-link" href="#">
                        <i class="bi bi-file-text me-2"></i> Logs
                    </a>
                    <a class="nav-link" href="#">
                        <i class="bi bi-github me-2"></i> GitHub
                    </a>
                    <a class="nav-link" href="#">
                        <i class="bi bi-palette me-2"></i> Themes
                    </a>
                </nav>
            </div>

            <!-- Main Content Preview -->
            <div class="flex-grow-1 p-4">
                <h1 class="h3 mb-4">Dashboard Preview</h1>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Total Users</h6>
                                <h2 class="mb-0">1,234</h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Active Tasks</h6>
                                <h2 class="mb-0">56</h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Storage Used</h6>
                                <h2 class="mb-0">2.4 GB</h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="text-muted mb-1">Uptime</h6>
                                <h2 class="mb-0">99.9%</h2>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Buttons Preview -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Buttons</h5>
                    </div>
                    <div class="card-body">
                        <button class="btn btn-primary me-2">Primary</button>
                        <button class="btn btn-success me-2">Success</button>
                        <button class="btn btn-warning me-2">Warning</button>
                        <button class="btn btn-danger me-2">Danger</button>
                        <button class="btn btn-outline-primary me-2">Outline</button>
                    </div>
                </div>

                <!-- Table Preview -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Data Table</h5>
                        <button class="btn btn-primary btn-sm">Add New</button>
                    </div>
                    <div class="card-body">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Item One</td>
                                    <td><span class="badge bg-success">Active</span></td>
                                    <td class="text-muted">2026-01-26</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary">Edit</button>
                                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Item Two</td>
                                    <td><span class="badge bg-warning">Pending</span></td>
                                    <td class="text-muted">2026-01-25</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary">Edit</button>
                                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Item Three</td>
                                    <td><span class="badge bg-danger">Error</span></td>
                                    <td class="text-muted">2026-01-24</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary">Edit</button>
                                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Form Preview -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Form Elements</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Text Input</label>
                                <input type="text" class="form-control" value="Sample text">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Select</label>
                                <select class="form-select">
                                    <option>Option 1</option>
                                    <option>Option 2</option>
                                    <option>Option 3</option>
                                </select>
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Textarea</label>
                                <textarea class="form-control" rows="3">Sample textarea content...</textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Alerts Preview -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Alerts</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle me-2"></i>
                            Success alert message
                        </div>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            Warning alert message
                        </div>
                        <div class="alert alert-danger mb-0">
                            <i class="bi bi-x-circle me-2"></i>
                            Error alert message
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
