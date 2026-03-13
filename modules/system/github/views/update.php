<?php
/**
 * GitHub Center - Update View
 * 
 * Supports two modes:
 * 1. Git Mode: Uses native git commands for updates (when .git exists)
 * 2. API Mode: Shows guidance for non-git deployments
 * 
 * @var string $title
 * @var string $mode - 'git' or 'api'
 * @var bool $is_git_repo
 * @var array $settings
 * @var array $preview
 * @var bool $has_updates
 * @var string $csrf_token
 * @var string|null $error
 * @var array $diagnostics
 * @var string|null $api_mode_notice
 * @var array $commits
 */
?>
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h2 class="mb-0">
                <i class="bi bi-download me-2"></i>
                <?= htmlspecialchars($title) ?>
            </h2>
            <p class="text-muted mb-0">
                <?php if (($mode ?? 'git') === 'git'): ?>
                Pull updates from GitHub using git (memory-safe)
                <?php else: ?>
                Check for available updates
                <?php endif; ?>
            </p>
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
    <?php endif; ?>

    <?php if (($mode ?? 'git') === 'api'): ?>
    <!-- API MODE: Non-git deployment -->
    
    <div class="alert alert-warning mb-4">
        <h5><i class="bi bi-exclamation-triangle me-2"></i>Non-Git Deployment Detected</h5>
        <p class="mb-2"><?= htmlspecialchars($api_mode_notice ?? 'This server was deployed without git.') ?></p>
        <p class="mb-0 small">Automatic updates via git pull are not available. See deployment options below.</p>
    </div>
    
    <!-- Deployment Status -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-info-circle me-2"></i>
            Deployment Status
        </div>
        <div class="card-body">
            <table class="table table-sm mb-0">
                <tr>
                    <th width="150">Git Available:</th>
                    <td>
                        <?php if (!empty($diagnostics['git_version'])): ?>
                            <span class="badge bg-success">Yes</span>
                            <code class="ms-2"><?= htmlspecialchars($diagnostics['git_version']) ?></code>
                        <?php else: ?>
                            <span class="badge bg-warning">Not detected</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Git Repository:</th>
                    <td><span class="badge bg-danger">Not Initialized</span></td>
                </tr>
                <tr>
                    <th>Tracked Commit:</th>
                    <td>
                        <?php $lastCommit = $settings['last_commit'] ?? null; ?>
                        <?php if ($lastCommit): ?>
                            <code><?= htmlspecialchars(substr($lastCommit, 0, 7)) ?></code>
                        <?php else: ?>
                            <span class="text-muted">Not set</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Update Repo:</th>
                    <td>
                        <?php if (!empty($settings['update_repo'])): ?>
                            <a href="https://github.com/<?= htmlspecialchars($settings['update_repo']) ?>" target="_blank">
                                <?= htmlspecialchars($settings['update_repo']) ?>
                                <i class="bi bi-box-arrow-up-right ms-1"></i>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">Not configured</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>
    
    <!-- Available Updates (via API) -->
    <?php if (!empty($preview) && ($has_updates ?? false)): ?>
    <div class="card mb-4">
        <div class="card-header bg-warning text-dark">
            <i class="bi bi-arrow-down-circle me-2"></i>
            Updates Available
        </div>
        <div class="card-body">
            <p>There are <strong><?= count($commits ?? []) ?></strong> new commits available.</p>
            
            <?php if (!empty($commits)): ?>
            <h6 class="mt-3"><i class="bi bi-git me-1"></i> New Commits</h6>
            <div style="max-height: 200px; overflow-y: auto;" class="mb-3 border rounded p-2">
                <table class="table table-sm table-hover mb-0">
                    <?php foreach (array_slice($commits, 0, 10) as $commit): ?>
                    <tr>
                        <td width="80"><code><?= htmlspecialchars($commit['sha'] ?? '') ?></code></td>
                        <td><?= htmlspecialchars($commit['message'] ?? $commit['commit']['message'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($commits) > 10): ?>
                    <tr>
                        <td colspan="2" class="text-muted">... and <?= count($commits) - 10 ?> more commits</td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php endif; ?>
            
            <?php 
            // Get files from preview
            $files = $preview['files']['list'] ?? [];
            $classified = $preview['files']['classified'] ?? [];
            $safeFiles = $classified['safe'] ?? [];
            $verifyFiles = $classified['verify'] ?? [];
            $protectedFiles = $classified['protected'] ?? [];
            ?>
            
            <?php if (!empty($files)): ?>
            <!-- Files to Update -->
            <h6 class="mt-3"><i class="bi bi-files me-1"></i> Files to Update (<?= count($files) ?>)</h6>
            
            <!-- File classification legend -->
            <div class="mb-2 small">
                <span class="badge bg-success me-2"><?= count($safeFiles) ?> Safe</span>
                <span class="badge bg-warning text-dark me-2"><?= count($verifyFiles) ?> Verify</span>
                <span class="badge bg-danger me-2"><?= count($protectedFiles) ?> Protected</span>
            </div>
            
            <div style="max-height: 300px; overflow-y: auto;" class="border rounded mb-3">
                <table class="table table-sm table-hover mb-0">
                    <thead class="sticky-top bg-light">
                        <tr>
                            <th width="40">
                                <input type="checkbox" id="selectAllFiles" checked onclick="toggleAllFiles(this)">
                            </th>
                            <th>File</th>
                            <th width="80">Status</th>
                            <th width="80">Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($files as $filename => $file): ?>
                        <?php 
                            $classification = $file['classification'] ?? 'safe';
                            $isProtected = $classification === 'protected';
                            $badgeClass = match($classification) {
                                'safe' => 'bg-success',
                                'verify' => 'bg-warning text-dark',
                                'protected' => 'bg-danger',
                                default => 'bg-secondary',
                            };
                            $statusLabel = match($file['status'] ?? 'M') {
                                'A' => 'Added',
                                'D' => 'Deleted',
                                'R' => 'Renamed',
                                default => 'Modified',
                            };
                        ?>
                        <tr class="<?= $isProtected ? 'table-danger' : '' ?>">
                            <td>
                                <input type="checkbox" class="file-checkbox" 
                                       name="selected_files[]" 
                                       value="<?= htmlspecialchars($filename) ?>"
                                       <?= $isProtected ? 'disabled' : 'checked' ?>>
                            </td>
                            <td>
                                <code class="small"><?= htmlspecialchars($filename) ?></code>
                                <?php if (!empty($file['additions']) || !empty($file['deletions'])): ?>
                                <small class="text-muted ms-2">
                                    <span class="text-success">+<?= $file['additions'] ?? 0 ?></span>
                                    <span class="text-danger">-<?= $file['deletions'] ?? 0 ?></span>
                                </small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-secondary"><?= $statusLabel ?></span></td>
                            <td><span class="badge <?= $badgeClass ?>"><?= ucfirst($classification) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <script>
            function toggleAllFiles(checkbox) {
                document.querySelectorAll('.file-checkbox:not(:disabled)').forEach(cb => {
                    cb.checked = checkbox.checked;
                });
                updateSelectedCount();
            }
            
            function updateSelectedCount() {
                const total = document.querySelectorAll('.file-checkbox').length;
                const selected = document.querySelectorAll('.file-checkbox:checked').length;
                const countEl = document.getElementById('selectedFileCount');
                if (countEl) {
                    countEl.textContent = selected + ' / ' + total;
                }
            }
            
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('.file-checkbox').forEach(cb => {
                    cb.addEventListener('change', updateSelectedCount);
                });
                updateSelectedCount();
            });
            </script>
            <?php endif; ?>
            
            <!-- API Update Form -->
            <hr>
            <form method="POST" action="<?= \Core\System\System::web('admin/github/update') ?>" 
                  onsubmit="return confirm('Download and apply update? This will download files from GitHub and overwrite existing files (except protected paths like storage/, .env, etc).');" 
                  id="apiUpdateForm">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token ?? '') ?>">
                <input type="hidden" name="api_mode" value="1">
                
                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="confirm_api_update" required>
                    <label class="form-check-label" for="confirm_api_update">
                        I understand that this will download a ZIP from GitHub and update files
                    </label>
                </div>
                
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-muted me-3">
                            <i class="bi bi-shield-check me-1"></i>
                            Protected paths are skipped automatically
                        </small>
                        <?php if (!empty($files)): ?>
                        <small class="text-primary">
                            <i class="bi bi-check2-square me-1"></i>
                            Selected: <span id="selectedFileCount"><?= count($files) ?></span>
                        </small>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-download me-1"></i> Download & Apply Update
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php elseif (!empty($preview)): ?>
    <div class="card mb-4">
        <div class="card-header bg-success text-white">
            <i class="bi bi-check-circle me-2"></i>
            Up to Date
        </div>
        <div class="card-body">
            <p class="mb-0">Your tracked version matches the latest available.</p>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Deployment Options -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-gear me-2"></i>
            Update Options
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3 mb-md-0">
                    <div class="card h-100 border-primary">
                        <div class="card-header bg-primary text-white">
                            <i class="bi bi-git me-2"></i>
                            Option 1: Convert to Git (Recommended)
                        </div>
                        <div class="card-body">
                            <p class="small">Enable automatic updates by initializing git:</p>
                            <ol class="small mb-3">
                                <li>SSH into your server</li>
                                <li>Navigate to your web root</li>
                                <li>Run the commands below</li>
                            </ol>
                            <pre class="bg-dark text-light p-2 rounded small mb-0"><code>cd /path/to/your/site
git init
git remote add origin https://github.com/<?= htmlspecialchars($settings['update_repo'] ?? 'owner/repo') ?>.git
git fetch origin
git reset --hard origin/<?= htmlspecialchars($settings['branch'] ?? 'main') ?></code></pre>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <i class="bi bi-cloud-download me-2"></i>
                            Option 2: Manual Download
                        </div>
                        <div class="card-body">
                            <p class="small">Download and upload files manually:</p>
                            <ol class="small mb-3">
                                <li>Download latest from GitHub</li>
                                <li>Extract and upload via FTP</li>
                                <li>Update "Last Commit" in settings</li>
                            </ol>
                            <?php if (!empty($settings['update_repo'])): ?>
                            <a href="https://github.com/<?= htmlspecialchars($settings['update_repo']) ?>/archive/refs/heads/<?= htmlspecialchars($settings['branch'] ?? 'main') ?>.zip" 
                               class="btn btn-outline-secondary btn-sm" target="_blank">
                                <i class="bi bi-download me-1"></i> Download ZIP
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php else: ?>
    <!-- GIT MODE: Full functionality -->
    
    <!-- Git Status -->
    <?php if (!empty($diagnostics)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-git me-2"></i>
            Git Repository Status
        </div>
        <div class="card-body">
            <table class="table table-sm mb-0">
                <tr>
                    <th width="150">Git Version:</th>
                    <td><code><?= htmlspecialchars($diagnostics['git_version'] ?? 'unknown') ?></code></td>
                </tr>
                <tr>
                    <th>Repository:</th>
                    <td><span class="badge bg-success">Valid Git Repo</span></td>
                </tr>
                <tr>
                    <th>Current Branch:</th>
                    <td>
                        <?php if (!empty($diagnostics['current_branch'])): ?>
                            <code><?= htmlspecialchars($diagnostics['current_branch']) ?></code>
                        <?php else: ?>
                            <span class="text-warning">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Not set
                                <?php if (!empty($diagnostics['errors']['branch'])): ?>
                                    <small class="text-muted d-block"><?= htmlspecialchars($diagnostics['errors']['branch']) ?></small>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Current Commit:</th>
                    <td>
                        <?php if (!empty($diagnostics['current_commit'])): ?>
                            <code><?= htmlspecialchars($diagnostics['current_commit']) ?></code>
                        <?php else: ?>
                            <span class="text-warning">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Not available
                                <?php if (!empty($diagnostics['errors']['commit'])): ?>
                                    <small class="text-muted d-block"><?= htmlspecialchars($diagnostics['errors']['commit']) ?></small>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Remote URL:</th>
                    <td>
                        <?php if (!empty($diagnostics['remote_url'])): ?>
                            <code class="text-muted small"><?= htmlspecialchars($diagnostics['remote_url']) ?></code>
                        <?php else: ?>
                            <span class="text-danger">
                                <i class="bi bi-x-circle me-1"></i>
                                Not configured
                                <?php if (!empty($diagnostics['errors']['remote_url'])): ?>
                                    <small class="text-muted d-block"><?= htmlspecialchars($diagnostics['errors']['remote_url']) ?></small>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Local Changes:</th>
                    <td>
                        <?php if ($diagnostics['has_uncommitted'] ?? false): ?>
                            <span class="badge bg-warning text-dark">Has uncommitted changes</span>
                            <small class="text-muted">(will be stashed during update)</small>
                        <?php else: ?>
                            <span class="badge bg-success">Clean working tree</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            
            <?php 
            // Show setup warning if remote is not configured
            $needsSetup = empty($diagnostics['remote_url']) || empty($diagnostics['current_branch']);
            ?>
            <?php if ($needsSetup): ?>
            <hr>
            <div class="alert alert-warning mb-0">
                <h6><i class="bi bi-exclamation-triangle me-2"></i>Git Repository Needs Configuration</h6>
                <p class="mb-2 small">The git repository is initialized but not fully configured. Run these commands via SSH:</p>
                <pre class="bg-dark text-light p-2 rounded small mb-0"><code><?php if (empty($diagnostics['remote_url'])): ?>
# Add remote origin
git remote add origin https://github.com/<?= htmlspecialchars($settings['update_repo'] ?? 'owner/repo') ?>.git
<?php endif; ?>
<?php if (empty($diagnostics['current_branch'])): ?>
# Fetch and checkout main branch
git fetch origin
git checkout -b <?= htmlspecialchars($settings['branch'] ?? 'main') ?> origin/<?= htmlspecialchars($settings['branch'] ?? 'main') ?>
<?php endif; ?></code></pre>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Update Info -->
    <div class="alert alert-info mb-4">
        <h5><i class="bi bi-shield-check me-2"></i>Memory-Safe Update Process</h5>
        <ul class="mb-0">
            <li><strong>Git-based:</strong> Uses native git commands, not ZIP downloads</li>
            <li><strong>Memory-safe:</strong> Works with any project size (even hundreds of GB)</li>
            <li><strong>Auto-backup:</strong> Local changes are stashed before update</li>
            <li><strong>Easy rollback:</strong> Can restore from stash if needed</li>
        </ul>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-<?= ($has_updates ?? false) ? 'warning text-dark' : 'success text-white' ?>">
            <i class="bi bi-<?= ($has_updates ?? false) ? 'arrow-down-circle' : 'check-circle' ?> me-2"></i>
            <?= ($has_updates ?? false) ? 'Updates Available' : 'Up to Date' ?>
        </div>
        <div class="card-body">
            <!-- Repository Info -->
            <table class="table table-sm">
                <tr>
                    <th width="150">Repository:</th>
                    <td><?= htmlspecialchars($settings['update_repo'] ?? 'Not configured') ?></td>
                </tr>
                <tr>
                    <th>Branch:</th>
                    <td><?= htmlspecialchars($settings['branch'] ?? 'main') ?></td>
                </tr>
                <tr>
                    <th>Current Commit:</th>
                    <td><code><?= htmlspecialchars($preview['current_commit'] ?? 'unknown') ?></code></td>
                </tr>
                <?php if ($has_updates ?? false): ?>
                <tr>
                    <th>Commits Behind:</th>
                    <td><span class="badge bg-warning text-dark"><?= $preview['commits']['behind'] ?? 0 ?> commits</span></td>
                </tr>
                <?php endif; ?>
            </table>
            
            <?php if ($has_updates ?? false): ?>
            <!-- New Commits -->
            <?php if (!empty($preview['commits']['list'])): ?>
            <hr>
            <h6><i class="bi bi-clock-history me-2"></i>New Commits</h6>
            <div style="max-height: 200px; overflow-y: auto;" class="mb-3">
                <table class="table table-sm table-hover mb-0">
                    <?php foreach (array_slice($preview['commits']['list'] ?? [], 0, 10) as $commit): ?>
                    <tr>
                        <td width="80"><code><?= htmlspecialchars($commit['sha'] ?? '') ?></code></td>
                        <td><?= htmlspecialchars($commit['message'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($preview['commits']['list'] ?? []) > 10): ?>
                    <tr>
                        <td colspan="2" class="text-muted">... and <?= count($preview['commits']['list']) - 10 ?> more commits</td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- File Changes Summary -->
            <?php if (!empty($preview['files']) && ($preview['files']['total'] ?? 0) > 0): ?>
            <hr>
            <h6><i class="bi bi-files me-2"></i>Changed Files</h6>
            <div class="row mb-3">
                <div class="col">
                    <span class="badge bg-success fs-6"><?= $preview['files']['safe'] ?? 0 ?> Safe</span>
                    <span class="badge bg-warning text-dark fs-6"><?= $preview['files']['verify'] ?? 0 ?> Config</span>
                    <span class="badge bg-secondary fs-6"><?= $preview['files']['protected'] ?? 0 ?> Protected</span>
                    <span class="ms-2 text-muted">Total: <?= $preview['files']['total'] ?? 0 ?> files</span>
                </div>
            </div>
            
            <!-- Files List -->
            <?php if (!empty($preview['files']['list'])): ?>
            <div style="max-height: 150px; overflow-y: auto;" class="mb-3 bg-dark p-2 rounded">
                <?php foreach (array_slice($preview['files']['list'], 0, 20) as $file): ?>
                <div class="small">
                    <span class="badge bg-<?= $file['status'] === 'A' ? 'success' : ($file['status'] === 'D' ? 'danger' : 'info') ?> me-1">
                        <?= $file['status'] === 'A' ? '+' : ($file['status'] === 'D' ? '-' : 'M') ?>
                    </span>
                    <code><?= htmlspecialchars($file['file'] ?? $file['filename'] ?? '') ?></code>
                </div>
                <?php endforeach; ?>
                <?php if (count($preview['files']['list']) > 20): ?>
                <div class="text-muted small">... and <?= count($preview['files']['list']) - 20 ?> more files</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            
            <!-- Update Form -->
            <form method="POST" action="<?= \Core\System\System::web('admin/github/update') ?>" 
                  onsubmit="return confirm('Start update? Local changes will be stashed for backup.');" id="updateForm">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token ?? '') ?>">
                
                <!-- Final Confirmation -->
                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="confirm_update" required>
                    <label class="form-check-label" for="confirm_update">
                        I understand that the system will be updated via <code>git pull</code>
                    </label>
                </div>
                
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <a href="<?= \Core\System\System::web('admin/github/backups') ?>" class="btn btn-outline-info">
                            <i class="bi bi-archive me-1"></i> View Backups
                        </a>
                    </div>
                    <div>
                        <?php if ($has_updates ?? false): ?>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-download me-1"></i> Pull Updates
                        </button>
                        <button type="submit" name="force" value="1" class="btn btn-danger ms-2"
                                onclick="return confirm('FORCE SYNC will discard ALL local changes. Are you sure?');">
                            <i class="bi bi-exclamation-triangle me-1"></i> Force Sync
                        </button>
                        <?php else: ?>
                        <span class="text-success">
                            <i class="bi bi-check-circle me-1"></i> Already up to date
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
            <?php endif; ?>
            
            <?php if (!($has_updates ?? false)): ?>
            <p class="text-muted mb-0">
                <i class="bi bi-check-circle text-success me-1"></i>
                Your system is up to date with the latest commit.
            </p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
