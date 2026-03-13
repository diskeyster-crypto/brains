<?php
/**
 * Import Theme View
 * 
 * Upload and import a theme from ZIP file.
 */

use Core\System\System;

$baseUrl = System::web('admin/theme');
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-upload me-2"></i>
            Import Theme
        </h1>
        <a href="<?= htmlspecialchars($baseUrl) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Themes
        </a>
    </div>

    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Upload Theme Package</h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-4">
                            <label class="form-label">Theme ZIP File</label>
                            <input type="file" name="theme_zip" class="form-control form-control-lg" 
                                   accept=".zip" required>
                            <div class="form-text">
                                Select a theme package (.zip file) exported from another Tredercopis installation.
                            </div>
                        </div>

                        <div class="alert alert-info">
                            <h6 class="alert-heading">
                                <i class="bi bi-info-circle me-2"></i>
                                ZIP File Requirements
                            </h6>
                            <ul class="mb-0 small">
                                <li>Must contain a <code>theme.json</code> manifest file</li>
                                <li>Should include CSS files: base.css, layout.css, components.css</li>
                                <li>Maximum file size: 10MB</li>
                            </ul>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="bi bi-upload me-2"></i>
                            Import Theme
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Expected Structure</h5>
                </div>
                <div class="card-body">
                    <pre class="bg-dark text-light p-3 rounded small mb-0"><code>theme-name/
├── theme.json         # Required: Theme manifest
├── base.css           # Base styles & CSS variables
├── layout.css         # Layout (sidebar, content)
├── components.css     # UI components
└── preview.png        # Optional: Theme preview image</code></pre>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0">theme.json Format</h5>
                </div>
                <div class="card-body">
                    <pre class="bg-dark text-light p-3 rounded small mb-0"><code>{
  "id": "my-theme",
  "name": "My Custom Theme",
  "author": "Your Name",
  "version": "1.0",
  "layout": "2-columns",
  "css_class": "theme-custom",
  "colors": {
    "bg": "#f8f9fa",
    "sidebar": "#2c3e50",
    "card": "#ffffff",
    "text": "#212529",
    "accent": "#3498db"
  }
}</code></pre>
                </div>
            </div>
        </div>
    </div>
</div>
