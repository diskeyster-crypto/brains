<?php
/**
 * Create Theme View
 * 
 * Form to create a new theme from scratch or by cloning.
 * 
 * @var array $baseThemes Available base themes (light/dark)
 * @var array $existingThemes Existing themes for cloning
 */

use Core\System\System;

$baseUrl = System::web('admin/theme');
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-plus-circle me-2"></i>
            Create New Theme
        </h1>
        <a href="<?= htmlspecialchars($baseUrl) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Themes
        </a>
    </div>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i>
            Failed to create theme. The name may already be in use.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <form method="POST">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Theme Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <label class="form-label">Theme Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control form-control-lg" 
                                   placeholder="My Custom Theme" required>
                            <div class="form-text">
                                A unique name for your theme. This will be used to generate the theme ID.
                            </div>
                        </div>

                        <hr class="my-4">

                        <h6 class="mb-3">Creation Method</h6>

                        <div class="row">
                            <!-- Create from Base -->
                            <div class="col-md-6 mb-3">
                                <label class="d-block">
                                    <input type="radio" name="create_mode" value="blank" checked 
                                           class="d-none" id="mode-blank">
                                    <div class="card h-100 create-option border-primary bg-primary-subtle" 
                                         style="cursor: pointer;" data-mode="blank">
                                        <div class="card-body text-center">
                                            <i class="bi bi-file-earmark-plus display-4 text-primary mb-3"></i>
                                            <h5>Start from Base</h5>
                                            <p class="text-muted small mb-0">
                                                Create a new theme based on light or dark defaults.
                                            </p>
                                        </div>
                                    </div>
                                </label>
                            </div>

                            <!-- Clone Existing -->
                            <div class="col-md-6 mb-3">
                                <label class="d-block">
                                    <input type="radio" name="create_mode" value="clone" 
                                           class="d-none" id="mode-clone">
                                    <div class="card h-100 create-option" 
                                         style="cursor: pointer;" data-mode="clone">
                                        <div class="card-body text-center">
                                            <i class="bi bi-files display-4 text-secondary mb-3"></i>
                                            <h5>Clone Existing</h5>
                                            <p class="text-muted small mb-0">
                                                Copy an existing theme and customize it.
                                            </p>
                                        </div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Base Theme Selection (for blank mode) -->
                        <div id="base-theme-section" class="mt-4">
                            <label class="form-label">Base Theme</label>
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="d-block">
                                        <input type="radio" name="base_theme" value="light" checked class="d-none">
                                        <div class="card base-theme-option border-primary" style="cursor: pointer;">
                                            <div class="card-body d-flex align-items-center">
                                                <div class="me-3" style="width: 40px; height: 40px; background: linear-gradient(135deg, #f8f9fa, #2c3e50); border-radius: 8px;"></div>
                                                <div>
                                                    <strong>Light Theme</strong>
                                                    <div class="text-muted small">Light background, dark sidebar</div>
                                                </div>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                                <div class="col-md-6">
                                    <label class="d-block">
                                        <input type="radio" name="base_theme" value="dark" class="d-none">
                                        <div class="card base-theme-option" style="cursor: pointer;">
                                            <div class="card-body d-flex align-items-center">
                                                <div class="me-3" style="width: 40px; height: 40px; background: linear-gradient(135deg, #0d1117, #161b22); border-radius: 8px;"></div>
                                                <div>
                                                    <strong>Dark Theme</strong>
                                                    <div class="text-muted small">Dark background, dark sidebar</div>
                                                </div>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Clone Selection (for clone mode) -->
                        <div id="clone-theme-section" class="mt-4" style="display: none;">
                            <label class="form-label">Clone From</label>
                            <select name="clone_from" class="form-select">
                                <?php foreach ($existingThemes as $id => $t): ?>
                                    <option value="<?= htmlspecialchars($id) ?>">
                                        <?= htmlspecialchars($t['name']) ?>
                                        (<?= htmlspecialchars($t['author'] ?? 'Unknown') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                All settings and CSS from the selected theme will be copied.
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100">
                    <i class="bi bi-plus-lg me-2"></i>
                    Create Theme
                </button>
            </form>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Tips</h5>
                </div>
                <div class="card-body">
                    <h6><i class="bi bi-lightbulb me-2 text-warning"></i>Getting Started</h6>
                    <p class="text-muted small">
                        After creating your theme, you'll be taken to the editor where you can customize 
                        colors, layout, and CSS.
                    </p>

                    <h6><i class="bi bi-palette me-2 text-primary"></i>Color Palette</h6>
                    <p class="text-muted small">
                        Start with a base theme that's closest to your desired look, then adjust 
                        individual colors in the editor.
                    </p>

                    <h6><i class="bi bi-files me-2 text-success"></i>Cloning</h6>
                    <p class="text-muted small mb-0">
                        If you want to make small changes to an existing theme, cloning is the fastest way 
                        to get started.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Inline CSS is FORBIDDEN - all styles are in admin/assets/css/ui.css -->

<script>
// Toggle creation mode sections
document.querySelectorAll('.create-option').forEach(card => {
    card.addEventListener('click', function() {
        const mode = this.dataset.mode;
        
        // Update cards
        document.querySelectorAll('.create-option').forEach(c => {
            c.classList.remove('border-primary', 'bg-primary-subtle');
        });
        this.classList.add('border-primary', 'bg-primary-subtle');
        
        // Toggle sections
        document.getElementById('base-theme-section').style.display = mode === 'blank' ? 'block' : 'none';
        document.getElementById('clone-theme-section').style.display = mode === 'clone' ? 'block' : 'none';
    });
});

// Base theme selection
document.querySelectorAll('.base-theme-option').forEach(card => {
    card.addEventListener('click', function() {
        document.querySelectorAll('.base-theme-option').forEach(c => {
            c.classList.remove('border-primary');
        });
        this.classList.add('border-primary');
    });
});
</script>
