<?php
/**
 * Rollback View
 * 
 * Allows user to rollback system from a backup
 */
?>

<div class="card mb-4">
    <div class="card-header bg-warning text-dark">
        <h5 class="mb-0">⚠ System Rollback</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-warning">
            <strong>Warning:</strong> Rolling back will restore system files from the selected backup.
            This action cannot be undone. Current changes will be lost.
        </div>
        
        <?php if ($selected_backup): ?>
            <!-- Rollback Confirmation -->
            <div class="card border-danger mb-4">
                <div class="card-header bg-danger text-white">
                    <strong>Selected Backup: <?= htmlspecialchars($selected_backup['filename']) ?></strong>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th>Created:</th>
                                    <td><?= date('Y-m-d H:i:s', strtotime($selected_backup['created_at'])) ?></td>
                                </tr>
                                <tr>
                                    <th>Size:</th>
                                    <td><?= number_format($selected_backup['size'] / 1024, 1) ?> KB</td>
                                </tr>
                                <tr>
                                    <th>Files:</th>
                                    <td><?= $selected_backup['files_count'] ?? 'Unknown' ?></td>
                                </tr>
                                <?php if ($selected_backup['label'] ?? null): ?>
                                <tr>
                                    <th>Label:</th>
                                    <td><?= htmlspecialchars($selected_backup['label']) ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th>Core Version:</th>
                                    <td><?= htmlspecialchars($selected_backup['core_version'] ?? 'Unknown') ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <?php if (isset($selected_backup['validation'])): ?>
                            <h6>Validation:</h6>
                            <?php if ($selected_backup['validation']['valid']): ?>
                                <div class="alert alert-success py-2">
                                    ✓ Backup is valid and can be restored
                                </div>
                            <?php else: ?>
                                <div class="alert alert-danger py-2">
                                    ✕ <?= htmlspecialchars($selected_backup['validation']['error'] ?? 'Invalid backup') ?>
                                </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($selected_backup['files']) && count($selected_backup['files']) <= 50): ?>
                    <details class="mb-3">
                        <summary class="text-primary" style="cursor: pointer;">
                            View files in backup (<?= count($selected_backup['files']) ?>)
                        </summary>
                        <div class="mt-2" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>File</th>
                                        <th>Size</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($selected_backup['files'] as $file): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($file['name']) ?></code></td>
                                        <td><?= number_format($file['size'] / 1024, 1) ?> KB</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                    <?php elseif (!empty($selected_backup['files'])): ?>
                    <p class="text-muted small">
                        This backup contains <?= count($selected_backup['files']) ?> files.
                    </p>
                    <?php endif; ?>
                    
                    <?php if ($selected_backup['validation']['valid'] ?? false): ?>
                    <form method="post" action="<?= htmlspecialchars(\Core\System\System::web('admin/github/rollback')) ?>" 
                          onsubmit="return confirm('Are you absolutely sure you want to rollback? This cannot be undone!');">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="backup" value="<?= htmlspecialchars($selected_backup['filename']) ?>">
                        
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="confirm_rollback" required>
                            <label class="form-check-label" for="confirm_rollback">
                                I understand that this will overwrite current system files
                            </label>
                        </div>
                        
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            Rollback to This Backup
                        </button>
                        <a href="<?= htmlspecialchars(\Core\System\System::web('admin/github/backups')) ?>" class="btn btn-secondary">
                            Cancel
                        </a>
                    </form>
                    <?php else: ?>
                    <div class="alert alert-danger">
                        This backup cannot be used for rollback.
                    </div>
                    <a href="<?= htmlspecialchars(\Core\System\System::web('admin/github/backups')) ?>" class="btn btn-secondary">
                        ← Back to Backups
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- Backup Selection -->
            <h6>Select a backup to rollback to:</h6>
            
            <?php if (empty($backups)): ?>
                <div class="alert alert-info">
                    No backups available for rollback.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Backup</th>
                                <th>Date</th>
                                <th>Size</th>
                                <th>Label</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backups as $backup): ?>
                            <tr>
                                <td>
                                    <code><?= htmlspecialchars($backup['filename']) ?></code>
                                </td>
                                <td><?= date('Y-m-d H:i', strtotime($backup['created_at'])) ?></td>
                                <td><?= number_format($backup['size'] / 1024, 1) ?> KB</td>
                                <td>
                                    <?php if ($backup['label'] ?? null): ?>
                                        <span class="badge bg-info"><?= htmlspecialchars($backup['label']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= htmlspecialchars(\Core\System\System::web('admin/github/rollback') . '?backup=' . urlencode($backup['filename'])) ?>" 
                                       class="btn btn-sm btn-warning">
                                        Select
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="mt-3">
    <a href="<?= htmlspecialchars(\Core\System\System::web('admin/github/backups')) ?>" class="btn btn-secondary">
        ← Back to Backups
    </a>
</div>
