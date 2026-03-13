<?php
/**
 * Theme Editor View
 * 
 * Edit theme colors, CSS, and metadata.
 * 
 * @var array|null $theme Theme data (null for new theme)
 * @var bool $isNew Whether creating a new theme
 */

use Core\System\System;

$baseUrl = System::web('admin/theme');
$themeId = $theme['id'] ?? '';
$isSystem = !empty($theme['system']);
$colors = $theme['colors'] ?? [];

// Default colors if not set
$defaultColors = [
    'bg' => '#f8f9fa',
    'sidebar' => '#2c3e50',
    'card' => '#ffffff',
    'text' => '#212529',
    'text_muted' => '#6c757d',
    'accent' => '#3498db',
    'success' => '#28a745',
    'warning' => '#ffc107',
    'danger' => '#dc3545',
    'border' => '#dee2e6'
];

$colors = array_merge($defaultColors, $colors);
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-brush me-2"></i>
            <?= $isNew ? 'Create Theme' : 'Edit Theme: ' . htmlspecialchars($theme['name'] ?? '') ?>
        </h1>
        <a href="<?= htmlspecialchars($baseUrl) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Themes
        </a>
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            Theme saved successfully!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i>
            Failed to save theme. Please try again.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($isSystem): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            This is a system theme. Only layout settings can be modified. To customize colors, create a new theme based on this one.
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= htmlspecialchars($baseUrl . '/save') ?>">
        <input type="hidden" name="theme_id" value="<?= htmlspecialchars($themeId) ?>">
        
        <div class="row">
            <!-- Left Column: Settings -->
            <div class="col-lg-6">
                <!-- Basic Info -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Basic Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Theme Name</label>
                            <input type="text" name="name" class="form-control" 
                                   value="<?= htmlspecialchars($theme['name'] ?? '') ?>"
                                   <?= $isSystem ? 'readonly' : '' ?> required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Author</label>
                                <input type="text" name="author" class="form-control" 
                                       value="<?= htmlspecialchars($theme['author'] ?? 'Custom') ?>"
                                       <?= $isSystem ? 'readonly' : '' ?>>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Version</label>
                                <input type="text" name="version" class="form-control" 
                                       value="<?= htmlspecialchars($theme['version'] ?? '1.0') ?>"
                                       <?= $isSystem ? 'readonly' : '' ?>>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Layout</label>
                                <select name="layout" class="form-select">
                                    <option value="2-columns" <?= ($theme['layout'] ?? '2-columns') === '2-columns' ? 'selected' : '' ?>>
                                        2 Columns (Standard)
                                    </option>
                                    <option value="3-columns" <?= ($theme['layout'] ?? '') === '3-columns' ? 'selected' : '' ?>>
                                        3 Columns (Compact)
                                    </option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">CSS Class</label>
                                <input type="text" name="css_class" class="form-control" 
                                       value="<?= htmlspecialchars($theme['css_class'] ?? 'theme-custom') ?>"
                                       <?= $isSystem ? 'readonly' : '' ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Colors -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Color Palette</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($isSystem): ?>
                            <p class="text-muted">Color editing is disabled for system themes.</p>
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-6 col-md-4 mb-3">
                                <label class="form-label small">Background</label>
                                <div class="input-group">
                                    <input type="color" name="color_bg" class="form-control form-control-color" 
                                           value="<?= htmlspecialchars($colors['bg']) ?>"
                                           <?= $isSystem ? 'disabled' : '' ?>>
                                    <input type="text" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars($colors['bg']) ?>" 
                                           readonly style="max-width: 80px;">
                                </div>
                            </div>
                            <div class="col-6 col-md-4 mb-3">
                                <label class="form-label small">Sidebar</label>
                                <div class="input-group">
                                    <input type="color" name="color_sidebar" class="form-control form-control-color" 
                                           value="<?= htmlspecialchars($colors['sidebar']) ?>"
                                           <?= $isSystem ? 'disabled' : '' ?>>
                                    <input type="text" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars($colors['sidebar']) ?>" 
                                           readonly style="max-width: 80px;">
                                </div>
                            </div>
                            <div class="col-6 col-md-4 mb-3">
                                <label class="form-label small">Card</label>
                                <div class="input-group">
                                    <input type="color" name="color_card" class="form-control form-control-color" 
                                           value="<?= htmlspecialchars($colors['card']) ?>"
                                           <?= $isSystem ? 'disabled' : '' ?>>
                                    <input type="text" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars($colors['card']) ?>" 
                                           readonly style="max-width: 80px;">
                                </div>
                            </div>
                            <div class="col-6 col-md-4 mb-3">
                                <label class="form-label small">Text</label>
                                <div class="input-group">
                                    <input type="color" name="color_text" class="form-control form-control-color" 
                                           value="<?= htmlspecialchars($colors['text']) ?>"
                                           <?= $isSystem ? 'disabled' : '' ?>>
                                    <input type="text" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars($colors['text']) ?>" 
                                           readonly style="max-width: 80px;">
                                </div>
                            </div>
                            <div class="col-6 col-md-4 mb-3">
                                <label class="form-label small">Muted Text</label>
                                <div class="input-group">
                                    <input type="color" name="color_text_muted" class="form-control form-control-color" 
                                           value="<?= htmlspecialchars($colors['text_muted']) ?>"
                                           <?= $isSystem ? 'disabled' : '' ?>>
                                    <input type="text" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars($colors['text_muted']) ?>" 
                                           readonly style="max-width: 80px;">
                                </div>
                            </div>
                            <div class="col-6 col-md-4 mb-3">
                                <label class="form-label small">Accent</label>
                                <div class="input-group">
                                    <input type="color" name="color_accent" class="form-control form-control-color" 
                                           value="<?= htmlspecialchars($colors['accent']) ?>"
                                           <?= $isSystem ? 'disabled' : '' ?>>
                                    <input type="text" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars($colors['accent']) ?>" 
                                           readonly style="max-width: 80px;">
                                </div>
                            </div>
                            <div class="col-6 col-md-4 mb-3">
                                <label class="form-label small">Success</label>
                                <div class="input-group">
                                    <input type="color" name="color_success" class="form-control form-control-color" 
                                           value="<?= htmlspecialchars($colors['success']) ?>"
                                           <?= $isSystem ? 'disabled' : '' ?>>
                                    <input type="text" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars($colors['success']) ?>" 
                                           readonly style="max-width: 80px;">
                                </div>
                            </div>
                            <div class="col-6 col-md-4 mb-3">
                                <label class="form-label small">Warning</label>
                                <div class="input-group">
                                    <input type="color" name="color_warning" class="form-control form-control-color" 
                                           value="<?= htmlspecialchars($colors['warning']) ?>"
                                           <?= $isSystem ? 'disabled' : '' ?>>
                                    <input type="text" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars($colors['warning']) ?>" 
                                           readonly style="max-width: 80px;">
                                </div>
                            </div>
                            <div class="col-6 col-md-4 mb-3">
                                <label class="form-label small">Danger</label>
                                <div class="input-group">
                                    <input type="color" name="color_danger" class="form-control form-control-color" 
                                           value="<?= htmlspecialchars($colors['danger']) ?>"
                                           <?= $isSystem ? 'disabled' : '' ?>>
                                    <input type="text" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars($colors['danger']) ?>" 
                                           readonly style="max-width: 80px;">
                                </div>
                            </div>
                            <div class="col-6 col-md-4 mb-3">
                                <label class="form-label small">Border</label>
                                <div class="input-group">
                                    <input type="color" name="color_border" class="form-control form-control-color" 
                                           value="<?= htmlspecialchars($colors['border']) ?>"
                                           <?= $isSystem ? 'disabled' : '' ?>>
                                    <input type="text" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars($colors['border']) ?>" 
                                           readonly style="max-width: 80px;">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Preview -->
            <div class="col-lg-6">
                <!-- Live Preview -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Live Preview</h5>
                    </div>
                    <div class="card-body p-0">
                        <div id="theme-preview" class="p-3" style="min-height: 400px; background: <?= htmlspecialchars($colors['bg']) ?>;">
                            <div class="d-flex" style="height: 350px;">
                                <!-- Mini Sidebar -->
                                <div style="width: 60px; background: <?= htmlspecialchars($colors['sidebar']) ?>; border-radius: 8px 0 0 8px;">
                                    <div class="p-2">
                                        <div class="mb-2" style="width: 100%; height: 8px; background: rgba(255,255,255,0.3); border-radius: 4px;"></div>
                                        <div class="mb-2" style="width: 100%; height: 8px; background: <?= htmlspecialchars($colors['accent']) ?>; border-radius: 4px;"></div>
                                        <div class="mb-2" style="width: 100%; height: 8px; background: rgba(255,255,255,0.2); border-radius: 4px;"></div>
                                        <div class="mb-2" style="width: 100%; height: 8px; background: rgba(255,255,255,0.2); border-radius: 4px;"></div>
                                    </div>
                                </div>
                                <!-- Main Content -->
                                <div class="flex-grow-1 p-3" style="background: <?= htmlspecialchars($colors['bg']) ?>; border-radius: 0 8px 8px 0;">
                                    <h6 style="color: <?= htmlspecialchars($colors['text']) ?>;">Dashboard</h6>
                                    <!-- Stats Cards -->
                                    <div class="d-flex gap-2 mb-3">
                                        <div class="p-2 flex-grow-1" style="background: <?= htmlspecialchars($colors['card']) ?>; border: 1px solid <?= htmlspecialchars($colors['border']) ?>; border-radius: 4px;">
                                            <small style="color: <?= htmlspecialchars($colors['text_muted']) ?>;">Users</small>
                                            <div style="color: <?= htmlspecialchars($colors['text']) ?>; font-weight: bold;">1,234</div>
                                        </div>
                                        <div class="p-2 flex-grow-1" style="background: <?= htmlspecialchars($colors['card']) ?>; border: 1px solid <?= htmlspecialchars($colors['border']) ?>; border-radius: 4px;">
                                            <small style="color: <?= htmlspecialchars($colors['text_muted']) ?>;">Tasks</small>
                                            <div style="color: <?= htmlspecialchars($colors['text']) ?>; font-weight: bold;">56</div>
                                        </div>
                                    </div>
                                    <!-- Buttons -->
                                    <div class="mb-3">
                                        <span class="badge" style="background: <?= htmlspecialchars($colors['accent']) ?>;">Primary</span>
                                        <span class="badge" style="background: <?= htmlspecialchars($colors['success']) ?>;">Success</span>
                                        <span class="badge" style="background: <?= htmlspecialchars($colors['warning']) ?>;">Warning</span>
                                        <span class="badge" style="background: <?= htmlspecialchars($colors['danger']) ?>;">Danger</span>
                                    </div>
                                    <!-- Table Preview -->
                                    <div style="background: <?= htmlspecialchars($colors['card']) ?>; border: 1px solid <?= htmlspecialchars($colors['border']) ?>; border-radius: 4px; padding: 8px;">
                                        <div class="d-flex justify-content-between py-1" style="border-bottom: 1px solid <?= htmlspecialchars($colors['border']) ?>;">
                                            <small style="color: <?= htmlspecialchars($colors['text']) ?>;">Item 1</small>
                                            <small style="color: <?= htmlspecialchars($colors['success']) ?>;">Active</small>
                                        </div>
                                        <div class="d-flex justify-content-between py-1" style="border-bottom: 1px solid <?= htmlspecialchars($colors['border']) ?>;">
                                            <small style="color: <?= htmlspecialchars($colors['text']) ?>;">Item 2</small>
                                            <small style="color: <?= htmlspecialchars($colors['warning']) ?>;">Pending</small>
                                        </div>
                                        <div class="d-flex justify-content-between py-1">
                                            <small style="color: <?= htmlspecialchars($colors['text']) ?>;">Item 3</small>
                                            <small style="color: <?= htmlspecialchars($colors['danger']) ?>;">Error</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="card">
                    <div class="card-body">
                        <button type="submit" class="btn btn-primary btn-lg w-100 mb-2">
                            <i class="bi bi-check-lg me-2"></i>
                            Save Theme
                        </button>
                        
                        <?php if (!$isNew && !$isSystem): ?>
                            <div class="d-flex gap-2">
                                <a href="<?= htmlspecialchars($baseUrl . '/preview?theme=' . urlencode($themeId)) ?>" 
                                   class="btn btn-outline-secondary flex-grow-1" target="_blank">
                                    <i class="bi bi-eye me-1"></i> Full Preview
                                </a>
                                <a href="<?= htmlspecialchars($baseUrl . '/export?theme=' . urlencode($themeId)) ?>" 
                                   class="btn btn-outline-info flex-grow-1">
                                    <i class="bi bi-download me-1"></i> Export
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
// Update preview when colors change
document.querySelectorAll('input[type="color"]').forEach(input => {
    input.addEventListener('input', function() {
        // Update adjacent text input
        this.nextElementSibling.value = this.value;
        // Refresh preview (simplified - in production would update specific elements)
        console.log('Color changed:', this.name, this.value);
    });
});
</script>
