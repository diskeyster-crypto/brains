<?php
/**
 * Layout Settings View
 * 
 * Configure layout mode (2 or 3 columns).
 * 
 * @var string $currentLayout Current layout mode
 */

use Core\System\System;

$baseUrl = System::web('admin/theme');
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-layout-split me-2"></i>
            Layout Settings
        </h1>
        <a href="<?= htmlspecialchars($baseUrl) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Themes
        </a>
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            Layout settings saved successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <form method="POST">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Select Layout Mode</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- 2 Columns Layout -->
                            <div class="col-md-6 mb-4">
                                <label class="d-block">
                                    <input type="radio" name="layout" value="2-columns" 
                                           <?= $currentLayout === '2-columns' ? 'checked' : '' ?>
                                           class="d-none" id="layout-2col">
                                    <div class="card h-100 layout-option <?= $currentLayout === '2-columns' ? 'border-primary bg-primary-subtle' : '' ?>"
                                         style="cursor: pointer;">
                                        <div class="card-body">
                                            <h5 class="card-title">
                                                <i class="bi bi-layout-sidebar me-2"></i>
                                                2 Columns (Standard)
                                            </h5>
                                            <p class="card-text text-muted small">
                                                Standard layout with wider sidebar (250px). Best for desktop use with plenty of screen space.
                                            </p>
                                            <!-- Preview -->
                                            <div class="border rounded p-2" style="height: 120px; background: #f8f9fa;">
                                                <div class="d-flex h-100">
                                                    <div style="width: 80px; background: #2c3e50; border-radius: 4px;"></div>
                                                    <div class="flex-grow-1 ms-2">
                                                        <div class="bg-white h-100 rounded"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </label>
                            </div>

                            <!-- 3 Columns Layout -->
                            <div class="col-md-6 mb-4">
                                <label class="d-block">
                                    <input type="radio" name="layout" value="3-columns" 
                                           <?= $currentLayout === '3-columns' ? 'checked' : '' ?>
                                           class="d-none" id="layout-3col">
                                    <div class="card h-100 layout-option <?= $currentLayout === '3-columns' ? 'border-primary bg-primary-subtle' : '' ?>"
                                         style="cursor: pointer;">
                                        <div class="card-body">
                                            <h5 class="card-title">
                                                <i class="bi bi-layout-three-columns me-2"></i>
                                                3 Columns (Compact)
                                            </h5>
                                            <p class="card-text text-muted small">
                                                Compact layout with narrower sidebar (200px). Better for smaller screens or when more content space is needed.
                                            </p>
                                            <!-- Preview -->
                                            <div class="border rounded p-2" style="height: 120px; background: #f8f9fa;">
                                                <div class="d-flex h-100">
                                                    <div style="width: 50px; background: #2c3e50; border-radius: 4px;"></div>
                                                    <div class="flex-grow-1 ms-2">
                                                        <div class="bg-white h-100 rounded"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100">
                            <i class="bi bi-check-lg me-2"></i>
                            Save Layout Settings
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">About Layouts</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small">
                        Layout mode controls the width of the sidebar and overall content distribution. 
                        The layout setting is global and affects all themes.
                    </p>
                    
                    <h6>2 Columns (Standard)</h6>
                    <ul class="small text-muted">
                        <li>Sidebar width: 250px</li>
                        <li>Best for: Desktop displays</li>
                        <li>More room for navigation labels</li>
                    </ul>
                    
                    <h6>3 Columns (Compact)</h6>
                    <ul class="small text-muted">
                        <li>Sidebar width: 200px</li>
                        <li>Best for: Smaller screens</li>
                        <li>More content area</li>
                    </ul>

                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Changes take effect immediately after saving.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Inline CSS is FORBIDDEN - all styles are in admin/assets/css/ui.css -->

<script>
document.querySelectorAll('.layout-option').forEach(card => {
    card.addEventListener('click', function() {
        document.querySelectorAll('.layout-option').forEach(c => {
            c.classList.remove('border-primary', 'bg-primary-subtle');
        });
        this.classList.add('border-primary', 'bg-primary-subtle');
    });
});
</script>
