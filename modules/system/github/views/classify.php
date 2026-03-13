<?php
/**
 * File Classification View
 * 
 * Shows file classification rules and current files
 */
?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">File Classification Rules</h5>
    </div>
    <div class="card-body">
        <p class="text-muted mb-4">
            Files are classified into three categories that determine how they are handled during updates:
        </p>
        
        <div class="row">
            <div class="col-md-4">
                <div class="card border-success mb-3">
                    <div class="card-header bg-success text-white">
                        <strong>✓ SAFE</strong> - Auto-update
                    </div>
                    <div class="card-body">
                        <p class="small">Files that can be updated automatically without confirmation.</p>
                        <strong>Paths:</strong>
                        <ul class="small mb-0">
                            <?php foreach ($rules['safe_paths'] ?? [] as $path): ?>
                                <li><code><?= htmlspecialchars($path) ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card border-warning mb-3">
                    <div class="card-header bg-warning text-dark">
                        <strong>⚠ VERIFY</strong> - Requires Confirmation
                    </div>
                    <div class="card-body">
                        <p class="small">Files that require manual confirmation before update.</p>
                        <strong>Paths:</strong>
                        <ul class="small">
                            <?php foreach ($rules['verify_paths'] ?? [] as $path): ?>
                                <li><code><?= htmlspecialchars($path) ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                        <strong>Files:</strong>
                        <ul class="small mb-0">
                            <?php foreach ($rules['verify_files'] ?? [] as $file): ?>
                                <li><code><?= htmlspecialchars($file) ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card border-danger mb-3">
                    <div class="card-header bg-danger text-white">
                        <strong>✕ PROTECTED</strong> - Never Touch
                    </div>
                    <div class="card-body">
                        <p class="small">Files that are NEVER modified during updates.</p>
                        <strong>Paths:</strong>
                        <ul class="small">
                            <?php foreach ($rules['protected_paths'] ?? [] as $path): ?>
                                <li><code><?= htmlspecialchars($path) ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                        <strong>Files:</strong>
                        <ul class="small mb-0">
                            <?php foreach ($rules['protected_files'] ?? [] as $file): ?>
                                <li><code><?= htmlspecialchars($file) ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($files)): ?>
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Changed Files (from last fetch)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Status</th>
                        <th>Classification</th>
                        <th>Changes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($files as $file): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($file['filename']) ?></code></td>
                        <td>
                            <?php
                            $statusClass = match($file['status'] ?? 'modified') {
                                'added' => 'success',
                                'removed' => 'danger',
                                default => 'warning',
                            };
                            $statusText = ucfirst($file['status'] ?? 'modified');
                            ?>
                            <span class="badge bg-<?= $statusClass ?>"><?= $statusText ?></span>
                        </td>
                        <td>
                            <?php
                            $classClass = match($file['classification']) {
                                'safe' => 'success',
                                'verify' => 'warning',
                                'protected' => 'danger',
                                default => 'secondary',
                            };
                            ?>
                            <span class="badge bg-<?= $classClass ?>"><?= strtoupper($file['classification']) ?></span>
                        </td>
                        <td>
                            <?php if (($file['additions'] ?? 0) > 0 || ($file['deletions'] ?? 0) > 0): ?>
                                <span class="text-success">+<?= $file['additions'] ?? 0 ?></span>
                                <span class="text-danger">-<?= $file['deletions'] ?? 0 ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (!empty($classified)): ?>
        <div class="mt-3">
            <h6>Summary:</h6>
            <ul class="list-inline mb-0">
                <li class="list-inline-item">
                    <span class="badge bg-success"><?= count($classified['safe'] ?? []) ?> SAFE</span>
                </li>
                <li class="list-inline-item">
                    <span class="badge bg-warning"><?= count($classified['verify'] ?? []) ?> VERIFY</span>
                </li>
                <li class="list-inline-item">
                    <span class="badge bg-danger"><?= count($classified['protected'] ?? []) ?> PROTECTED</span>
                </li>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle"></i>
    No changed files to display. <a href="<?= htmlspecialchars(\Core\System\System::web('admin/github/fetch')) ?>">Fetch updates</a> to see file changes.
</div>
<?php endif; ?>

<div class="mt-3">
    <a href="<?= htmlspecialchars(\Core\System\System::web('admin/github')) ?>" class="btn btn-secondary">
        ← Back to Dashboard
    </a>
</div>
