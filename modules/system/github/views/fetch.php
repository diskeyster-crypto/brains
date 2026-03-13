<?php
/**
 * GitHub Center - Fetch Updates View (with Repo/Branch Selection)
 * 
 * @var string $title
 * @var array $commits
 * @var bool $has_updates
 * @var string|null $current_commit
 * @var string|null $latest_commit
 * @var array $settings
 * @var array $files
 * @var array $preview
 * @var string $csrf_token
 * @var string|null $error
 */
?>
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h2 class="mb-0">
                <i class="bi bi-cloud-download me-2"></i>
                <?= htmlspecialchars($title) ?>
            </h2>
            <p class="text-muted mb-0">Check for available updates from GitHub</p>
        </div>
        <div class="col-auto">
            <a href="<?= \Core\System\System::web('admin/github') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    <?php if (isset($error)): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-circle me-2"></i>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php else: ?>

    <!-- Repository & Branch Selector -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-folder2-open me-2"></i>
            Update Source
        </div>
        <div class="card-body">
            <form method="POST" action="<?= \Core\System\System::web('admin/github/settings') ?>" class="row g-3" id="quickSettingsForm">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="username" value="<?= htmlspecialchars($settings['username']) ?>">
                <input type="hidden" name="auto_backup" value="<?= $settings['auto_backup'] ? '1' : '0' ?>">
                <input type="hidden" name="backup_repo" value="<?= htmlspecialchars($settings['backup_repo']) ?>">
                
                <div class="col-md-5">
                    <label for="update_repo_quick" class="form-label">Repository</label>
                    <div class="input-group">
                        <select class="form-select" id="update_repo_quick" name="update_repo" onchange="onRepoChange()">
                            <option value="">-- Loading repositories... --</option>
                        </select>
                        <span class="input-group-text" id="repoLoadingIndicator" style="display: none;">
                            <span class="spinner-border spinner-border-sm"></span>
                        </span>
                    </div>
                </div>
                <div class="col-md-4">
                    <label for="branch_quick" class="form-label">Branch</label>
                    <div class="input-group">
                        <select class="form-select" id="branch_quick" name="branch">
                            <option value="<?= htmlspecialchars($settings['branch']) ?>"><?= htmlspecialchars($settings['branch']) ?></option>
                        </select>
                        <span class="input-group-text" id="branchLoadingIndicator" style="display: none;">
                            <span class="spinner-border spinner-border-sm"></span>
                        </span>
                    </div>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-arrow-repeat me-1"></i> Apply & Refresh
                    </button>
                </div>
            </form>
            
            <div class="mt-3">
                <small class="text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    Currently fetching from: <strong><?= htmlspecialchars($settings['update_repo'] ?: 'Not configured') ?></strong>
                    (branch: <strong><?= htmlspecialchars($settings['branch']) ?></strong>)
                </small>
            </div>
        </div>
    </div>

    <!-- Update Status -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-info-circle me-2"></i>
            Update Status
        </div>
        <div class="card-body">
            <?php if ($has_updates): ?>
            <div class="alert alert-warning mb-3">
                <i class="bi bi-arrow-up-circle me-2"></i>
                <strong>Updates Available!</strong>
                New commits are available for download.
            </div>
            <?php else: ?>
            <div class="alert alert-success mb-3">
                <i class="bi bi-check-circle me-2"></i>
                <strong>Up to Date!</strong>
                Your system is running the latest version.
            </div>
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-6">
                    <small class="text-muted">Current commit:</small><br>
                    <code><?= $current_commit ? substr($current_commit, 0, 7) : 'Unknown' ?></code>
                </div>
                <div class="col-md-6">
                    <small class="text-muted">Latest commit:</small><br>
                    <code><?= $latest_commit ? substr($latest_commit, 0, 7) : 'Unknown' ?></code>
                </div>
            </div>
            
            <?php if ($has_updates): ?>
            <hr>
            <a href="<?= \Core\System\System::web('admin/github/update') ?>" class="btn btn-warning">
                <i class="bi bi-download me-1"></i> Review & Update
            </a>
            <a href="<?= \Core\System\System::web('admin/github/classify') ?>" class="btn btn-outline-info">
                <i class="bi bi-list-check me-1"></i> View Classification Rules
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- File Changes Summary -->
    <?php if (!empty($files) && ($files['total'] ?? 0) > 0): ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-files me-2"></i>
                Changed Files (<?= $files['total'] ?? 0 ?>)
            </span>
            <div>
                <span class="badge bg-success"><?= $files['safe'] ?? 0 ?> Safe</span>
                <span class="badge bg-warning text-dark"><?= $files['verify'] ?? 0 ?> Verify</span>
                <span class="badge bg-danger"><?= $files['protected'] ?? 0 ?> Protected</span>
            </div>
        </div>
        <div class="card-body">
            <?php if (($files['protected'] ?? 0) > 0): ?>
            <div class="alert alert-info py-2">
                <i class="bi bi-shield-check me-1"></i>
                <strong><?= $files['protected'] ?></strong> protected file(s) will NOT be modified.
            </div>
            <?php endif; ?>
            
            <?php if (($files['verify'] ?? 0) > 0): ?>
            <div class="alert alert-warning py-2">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <strong><?= $files['verify'] ?></strong> file(s) require confirmation before update.
            </div>
            <?php endif; ?>
            
            <?php if (!empty($files['list'])): ?>
            <details>
                <summary class="text-primary" style="cursor: pointer;">
                    View all changed files
                </summary>
                <div class="mt-2" style="max-height: 300px; overflow-y: auto;">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>File</th>
                                <th>Status</th>
                                <th>Classification</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($files['list'] as $file): ?>
                            <tr>
                                <td><code class="small"><?= htmlspecialchars($file['filename']) ?></code></td>
                                <td>
                                    <?php
                                    $statusClass = match($file['status'] ?? 'modified') {
                                        'added' => 'success',
                                        'removed' => 'danger',
                                        default => 'warning',
                                    };
                                    ?>
                                    <span class="badge bg-<?= $statusClass ?>"><?= ucfirst($file['status'] ?? 'modified') ?></span>
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
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Latest Release -->
    <?php if (!empty($release)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-tag me-2"></i>
            Latest Release
        </div>
        <div class="card-body">
            <h5><?= htmlspecialchars($release['name'] ?? $release['tag_name'] ?? 'Unknown') ?></h5>
            <p class="text-muted">
                <i class="bi bi-calendar me-1"></i>
                Released: <?= isset($release['published_at']) ? date('M j, Y', strtotime($release['published_at'])) : 'Unknown' ?>
            </p>
            <?php if (!empty($release['body'])): ?>
            <hr>
            <div class="release-notes">
                <?= nl2br(htmlspecialchars($release['body'])) ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($release['html_url'])): ?>
            <a href="<?= htmlspecialchars($release['html_url']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary mt-2">
                <i class="bi bi-box-arrow-up-right me-1"></i> View on GitHub
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- API Mode Notice -->
    <?php if (!empty($result['api_mode_notice'])): ?>
    <div class="alert alert-info mb-4">
        <i class="bi bi-info-circle me-2"></i>
        <?= htmlspecialchars($result['api_mode_notice']) ?>
    </div>
    <?php endif; ?>

    <!-- Recent Commits -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-git me-2"></i>
            Recent Commits
        </div>
        <?php if (!empty($commits)): ?>
        <div class="list-group list-group-flush">
            <?php foreach ($commits as $commit): ?>
            <div class="list-group-item">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <code class="me-2"><?= htmlspecialchars(substr($commit['sha'] ?? '', 0, 7)) ?></code>
                        <?php 
                        // Handle both nested (GitHub API raw) and flat (processed) commit formats
                        $message = $commit['message'] ?? $commit['commit']['message'] ?? '';
                        ?>
                        <span><?= htmlspecialchars($message) ?></span>
                    </div>
                    <small class="text-muted text-nowrap">
                        <?php 
                        // Handle both date formats
                        $date = $commit['date'] ?? $commit['commit']['author']['date'] ?? null;
                        echo $date ? date('M j', strtotime($date)) : '';
                        ?>
                    </small>
                </div>
                <small class="text-muted">
                    <?php
                    // Handle both author formats
                    $author = $commit['author'] ?? $commit['commit']['author']['name'] ?? 'Unknown';
                    ?>
                    by <?= htmlspecialchars($author) ?>
                </small>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="card-body">
            <p class="text-muted mb-0">No commits found.</p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
const apiBaseUrl = '<?= \Core\System\System::web('admin/github') ?>';
const currentRepo = '<?= htmlspecialchars($settings['update_repo']) ?>';
const currentBranch = '<?= htmlspecialchars($settings['branch']) ?>';

document.addEventListener('DOMContentLoaded', function() {
    loadRepositories();
});

function loadRepositories() {
    const select = document.getElementById('update_repo_quick');
    const indicator = document.getElementById('repoLoadingIndicator');
    
    indicator.style.display = 'inline-block';
    
    fetch(apiBaseUrl + '/api/repos')
        .then(response => response.json())
        .then(data => {
            indicator.style.display = 'none';
            
            if (data.success) {
                select.innerHTML = '<option value="">-- Select repository --</option>';
                
                data.repos.forEach(repo => {
                    const icon = repo.private ? '🔒 ' : '';
                    const selected = repo.full_name === currentRepo ? 'selected' : '';
                    select.insertAdjacentHTML('beforeend', 
                        `<option value="${repo.full_name}" ${selected}>${icon}${repo.full_name}</option>`
                    );
                });
                
                // Load branches for current repo
                if (currentRepo) {
                    loadBranches(currentRepo);
                }
            } else {
                select.innerHTML = `<option value="${currentRepo}">${currentRepo}</option>`;
                console.error('Failed to load repos:', data.error);
            }
        })
        .catch(error => {
            indicator.style.display = 'none';
            select.innerHTML = `<option value="${currentRepo}">${currentRepo}</option>`;
            console.error('Error:', error);
        });
}

function onRepoChange() {
    const select = document.getElementById('update_repo_quick');
    if (select.value) {
        loadBranches(select.value);
    }
}

function loadBranches(repoFullName) {
    const select = document.getElementById('branch_quick');
    const indicator = document.getElementById('branchLoadingIndicator');
    
    indicator.style.display = 'inline-block';
    
    fetch(apiBaseUrl + '/api/branches?repo=' + encodeURIComponent(repoFullName))
        .then(response => response.json())
        .then(data => {
            indicator.style.display = 'none';
            
            if (data.success) {
                select.innerHTML = '';
                
                data.branches.forEach(branch => {
                    const icon = branch.protected ? '🔒 ' : '';
                    const selected = branch.name === currentBranch ? 'selected' : '';
                    select.insertAdjacentHTML('beforeend', 
                        `<option value="${branch.name}" ${selected}>${icon}${branch.name}</option>`
                    );
                });
            } else {
                console.error('Failed to load branches:', data.error);
            }
        })
        .catch(error => {
            indicator.style.display = 'none';
            console.error('Error:', error);
        });
}
</script>
