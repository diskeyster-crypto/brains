<?php
/**
 * GitHub Center - Test Connection View
 * 
 * @var string $title
 * @var bool $success
 * @var array $result
 * @var string|null $error
 * @var bool $repo_access
 * @var array|null $repo_info
 * @var string|null $repo_error
 */
?>
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h2 class="mb-0">
                <i class="bi bi-plug me-2"></i>
                <?= htmlspecialchars($title) ?>
            </h2>
            <p class="text-muted mb-0">Test GitHub API connection</p>
        </div>
        <div class="col-auto">
            <a href="<?= \Core\System\System::web('admin/github') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    <!-- API Connection Test -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-cloud me-2"></i>
            API Connection
        </div>
        <div class="card-body">
            <?php if ($success): ?>
            <div class="alert alert-success mb-3">
                <i class="bi bi-check-circle me-2"></i>
                <strong>Success!</strong> Connected to GitHub API.
            </div>
            
            <table class="table table-sm">
                <tr>
                    <th width="200">Authenticated User:</th>
                    <td><?= htmlspecialchars($result['user'] ?? 'Unknown') ?></td>
                </tr>
                <?php if (!empty($result['name'])): ?>
                <tr>
                    <th>Name:</th>
                    <td><?= htmlspecialchars($result['name']) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>API Rate Limit Remaining:</th>
                    <td><?= htmlspecialchars($result['rate_limit'] ?? 'Unknown') ?></td>
                </tr>
                <?php if (!empty($result['rate_limit_reset'])): ?>
                <tr>
                    <th>Rate Limit Resets:</th>
                    <td><?= date('Y-m-d H:i:s', (int)$result['rate_limit_reset']) ?></td>
                </tr>
                <?php endif; ?>
            </table>
            <?php else: ?>
            <div class="alert alert-danger mb-0">
                <i class="bi bi-x-circle me-2"></i>
                <strong>Connection Failed!</strong>
                <p class="mb-0 mt-2"><?= htmlspecialchars($error ?? 'Unknown error') ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($success && isset($repo_access)): ?>
    <!-- Repository Access Test -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-folder me-2"></i>
            Repository Access
        </div>
        <div class="card-body">
            <?php if ($repo_access): ?>
            <div class="alert alert-success mb-3">
                <i class="bi bi-check-circle me-2"></i>
                <strong>Success!</strong> Repository is accessible.
            </div>
            
            <?php if ($repo_info): ?>
            <table class="table table-sm">
                <tr>
                    <th width="200">Full Name:</th>
                    <td><?= htmlspecialchars($repo_info['full_name'] ?? '') ?></td>
                </tr>
                <tr>
                    <th>Description:</th>
                    <td><?= htmlspecialchars($repo_info['description'] ?? 'No description') ?></td>
                </tr>
                <tr>
                    <th>Visibility:</th>
                    <td><?= ($repo_info['private'] ?? false) ? 'Private' : 'Public' ?></td>
                </tr>
                <tr>
                    <th>Default Branch:</th>
                    <td><?= htmlspecialchars($repo_info['default_branch'] ?? 'main') ?></td>
                </tr>
                <tr>
                    <th>Last Push:</th>
                    <td><?= $repo_info['pushed_at'] ? date('Y-m-d H:i', strtotime($repo_info['pushed_at'])) : 'Unknown' ?></td>
                </tr>
            </table>
            <?php endif; ?>
            <?php else: ?>
            <div class="alert alert-danger mb-0">
                <i class="bi bi-x-circle me-2"></i>
                <strong>Repository Access Failed!</strong>
                <p class="mb-0 mt-2"><?= htmlspecialchars($repo_error ?? 'Unknown error') ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Actions -->
    <div class="d-flex gap-2">
        <a href="<?= \Core\System\System::web('admin/github/test') ?>" class="btn btn-primary">
            <i class="bi bi-arrow-clockwise me-1"></i> Test Again
        </a>
        <a href="<?= \Core\System\System::web('admin/github/settings') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-gear me-1"></i> Settings
        </a>
    </div>
</div>
