<?php
/**
 * GitHub Center - Backups & Rollback View
 * 
 * @var string $title
 * @var array $backups
 * @var string|null $success
 * @var string|null $error
 * @var string $csrf_token
 */

function formatBytes(int $bytes, int $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h2 class="mb-0">
                <i class="bi bi-archive me-2"></i>
                <?= htmlspecialchars($title) ?>
            </h2>
            <p class="text-muted mb-0">Manage system backups and rollback</p>
        </div>
        <div class="col-auto">
            <a href="<?= \Core\System\System::web('admin/github') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-circle me-2"></i>
        <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle me-2"></i>
        <?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Backup Info -->
    <div class="alert alert-info mb-4">
        <h6><i class="bi bi-shield-check me-2"></i>Automatic Backup System</h6>
        <p class="mb-0 small">
            A backup is automatically created BEFORE every update. You can also create manual backups here.
            Backups include: config, core, modules, admin, and root PHP files.
        </p>
    </div>

    <!-- Create Backup -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-plus-circle me-2"></i>
            Create New Backup
        </div>
        <div class="card-body">
            <form method="POST" action="<?= \Core\System\System::web('admin/github/backup') ?>" class="row g-3">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token) ?>">
                <div class="col-md-6">
                    <label class="form-label">Label (optional)</label>
                    <input type="text" name="label" class="form-control" placeholder="e.g., before_major_change">
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-archive me-1"></i> Create Backup
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Backup List -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-list me-2"></i>
                Existing Backups (<?= count($backups) ?>)
            </span>
            <?php if (!empty($backups)): ?>
            <a href="<?= \Core\System\System::web('admin/github/rollback') ?>" class="btn btn-sm btn-warning">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Rollback
            </a>
            <?php endif; ?>
        </div>
        <?php if (empty($backups)): ?>
        <div class="card-body">
            <p class="text-muted mb-0">
                <i class="bi bi-info-circle me-1"></i>
                No backups found. Create your first backup above.
            </p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Filename</th>
                        <th>Label</th>
                        <th>Size</th>
                        <th>Files</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                    <tr>
                        <td>
                            <i class="bi bi-file-earmark-zip me-1"></i>
                            <code><?= htmlspecialchars($backup['filename']) ?></code>
                        </td>
                        <td>
                            <?php if (!empty($backup['label'])): ?>
                            <span class="badge bg-info"><?= htmlspecialchars($backup['label']) ?></span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?= formatBytes($backup['size']) ?></td>
                        <td><?= $backup['files_count'] ?? '-' ?></td>
                        <td><?= date('Y-m-d H:i', strtotime($backup['created_at'] ?? date('c', $backup['mtime']))) ?></td>
                        <td>
                            <a href="<?= \Core\System\System::web('admin/github/rollback') ?>?backup=<?= urlencode($backup['filename']) ?>" 
                               class="btn btn-sm btn-outline-warning" title="Rollback to this backup">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer text-muted small">
            <i class="bi bi-info-circle me-1"></i>
            System keeps the last 10 backups. Older backups are automatically removed.
        </div>
        <?php endif; ?>
    </div>
</div>
