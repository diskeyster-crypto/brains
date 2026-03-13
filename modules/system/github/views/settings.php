<?php
/**
 * GitHub Center - Settings View with Dynamic Repo/Branch Selection
 * 
 * @var string $title
 * @var array $settings
 * @var string|null $error
 * @var string|null $success
 * @var string $csrf_token
 */
?>
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h2 class="mb-0">
                <i class="bi bi-gear me-2"></i>
                <?= htmlspecialchars($title) ?>
            </h2>
            <p class="text-muted mb-0">Configure GitHub integration</p>
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

    <div class="card">
        <div class="card-body">
            <form method="POST" action="<?= \Core\System\System::web('admin/github/settings') ?>" id="settingsForm">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token) ?>">
                
                <h5 class="mb-3">Authentication</h5>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="username" class="form-label">GitHub Username</label>
                        <input type="text" class="form-control" id="username" name="username" 
                               value="<?= htmlspecialchars($settings['username']) ?>"
                               placeholder="your-github-username">
                        <div class="form-text">Your GitHub username or organization name</div>
                    </div>
                    <div class="col-md-6">
                        <label for="token" class="form-label">Personal Access Token</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="token" name="token" 
                                   placeholder="<?= empty($settings['token']) ? 'ghp_xxxxxxxxxxxx' : '••••••••••••' ?>">
                            <?php if (!empty($settings['token'])): ?>
                            <button type="button" class="btn btn-outline-secondary" id="loadReposBtn" onclick="loadRepositories()">
                                <i class="bi bi-cloud-download me-1"></i> Load Repos
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="form-text">
                            <a href="https://github.com/settings/tokens/new" target="_blank">Generate token</a>
                            with <code>repo</code> scope. Leave empty to keep current token.
                        </div>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <h5 class="mb-3">Repositories</h5>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="update_repo" class="form-label">Update Repository</label>
                        <div class="input-group">
                            <select class="form-select" id="update_repo_select" onchange="selectRepo('update_repo')">
                                <option value="">-- Select repository --</option>
                            </select>
                            <input type="text" class="form-control" id="update_repo" name="update_repo" 
                                   value="<?= htmlspecialchars($settings['update_repo']) ?>"
                                   placeholder="owner/repository" style="display: none;">
                            <button type="button" class="btn btn-outline-secondary" onclick="toggleRepoInput('update_repo')">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </div>
                        <div class="form-text">Repository to pull updates from</div>
                    </div>
                    <div class="col-md-6">
                        <label for="backup_repo" class="form-label">Backup Repository (optional)</label>
                        <div class="input-group">
                            <select class="form-select" id="backup_repo_select" onchange="selectRepo('backup_repo')">
                                <option value="">-- Select repository --</option>
                            </select>
                            <input type="text" class="form-control" id="backup_repo" name="backup_repo" 
                                   value="<?= htmlspecialchars($settings['backup_repo']) ?>"
                                   placeholder="owner/repository" style="display: none;">
                            <button type="button" class="btn btn-outline-secondary" onclick="toggleRepoInput('backup_repo')">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </div>
                        <div class="form-text">Repository to push backups to (leave empty for local only)</div>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="branch" class="form-label">Branch</label>
                        <div class="input-group">
                            <select class="form-select" id="branch_select" onchange="selectBranch()">
                                <option value="">-- Select branch --</option>
                            </select>
                            <input type="text" class="form-control" id="branch" name="branch" 
                                   value="<?= htmlspecialchars($settings['branch']) ?>"
                                   placeholder="main" style="display: none;">
                            <button type="button" class="btn btn-outline-secondary" onclick="toggleBranchInput()">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </div>
                        <div class="form-text">Branch to use for updates</div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check mt-4 pt-2">
                            <input class="form-check-input" type="checkbox" id="auto_backup" name="auto_backup"
                                   <?= $settings['auto_backup'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="auto_backup">
                                Create backup before each update
                            </label>
                        </div>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <h5 class="mb-3">Upload Settings</h5>
                
                <div class="row mb-3">
                    <div class="col-md-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="include_all_files" name="include_all_files"
                                   <?= !empty($settings['include_all_files']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="include_all_files">
                                <i class="bi bi-unlock me-1"></i>
                                Include all files when uploading to GitHub
                            </label>
                            <div class="form-text text-warning">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                When enabled, config/, storage/, and runtime/ directories will be available for upload. 
                                Use with caution - these may contain sensitive data!
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Status indicator -->
                <div id="loadingStatus" class="alert alert-info d-none">
                    <span class="spinner-border spinner-border-sm me-2"></span>
                    <span id="loadingText">Loading...</span>
                </div>
                
                <hr class="my-4">
                
                <div class="d-flex justify-content-between">
                    <a href="<?= \Core\System\System::web('admin/github/test') ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-plug me-1"></i> Test Connection
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i> Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const baseUrl = '<?= \Core\System\System::web('admin/github') ?>';
const currentUpdateRepo = '<?= htmlspecialchars($settings['update_repo']) ?>';
const currentBackupRepo = '<?= htmlspecialchars($settings['backup_repo']) ?>';
const currentBranch = '<?= htmlspecialchars($settings['branch']) ?>';

let reposLoaded = false;
let reposList = [];

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // If we have a token configured, try to load repos
    <?php if (!empty($settings['token'])): ?>
    loadRepositories();
    <?php endif; ?>
    
    // Set initial values in manual inputs
    syncSelectWithInput('update_repo');
    syncSelectWithInput('backup_repo');
    syncSelectWithBranch();
});

function showLoading(text) {
    document.getElementById('loadingStatus').classList.remove('d-none');
    document.getElementById('loadingText').textContent = text;
}

function hideLoading() {
    document.getElementById('loadingStatus').classList.add('d-none');
}

function loadRepositories() {
    showLoading('Loading repositories...');
    
    fetch(baseUrl + '/api/repos')
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                reposList = data.repos;
                populateRepoSelects(data.repos);
                reposLoaded = true;
                
                // Load branches for current repo if set
                if (currentUpdateRepo) {
                    loadBranches(currentUpdateRepo);
                }
            } else {
                alert('Failed to load repositories: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            hideLoading();
            alert('Error loading repositories: ' + error);
        });
}

function populateRepoSelects(repos) {
    const updateSelect = document.getElementById('update_repo_select');
    const backupSelect = document.getElementById('backup_repo_select');
    
    // Clear existing options except first
    updateSelect.innerHTML = '<option value="">-- Select repository --</option>';
    backupSelect.innerHTML = '<option value="">-- Select repository --</option>';
    
    repos.forEach(repo => {
        const icon = repo.private ? '🔒 ' : '📁 ';
        const optionHtml = `<option value="${repo.full_name}" data-default-branch="${repo.default_branch}">${icon}${repo.full_name}</option>`;
        
        updateSelect.insertAdjacentHTML('beforeend', optionHtml);
        backupSelect.insertAdjacentHTML('beforeend', optionHtml);
    });
    
    // Select current values
    if (currentUpdateRepo) {
        updateSelect.value = currentUpdateRepo;
    }
    if (currentBackupRepo) {
        backupSelect.value = currentBackupRepo;
    }
}

function selectRepo(fieldName) {
    const select = document.getElementById(fieldName + '_select');
    const input = document.getElementById(fieldName);
    input.value = select.value;
    
    // If update repo changed, load branches
    if (fieldName === 'update_repo' && select.value) {
        loadBranches(select.value);
    }
}

function toggleRepoInput(fieldName) {
    const select = document.getElementById(fieldName + '_select');
    const input = document.getElementById(fieldName);
    
    if (input.style.display === 'none') {
        // Show input, hide select
        input.style.display = 'block';
        select.style.display = 'none';
        input.focus();
    } else {
        // Show select, hide input
        input.style.display = 'none';
        select.style.display = 'block';
        select.value = input.value;
    }
}

function syncSelectWithInput(fieldName) {
    const select = document.getElementById(fieldName + '_select');
    const input = document.getElementById(fieldName);
    
    if (input.value && select.querySelector(`option[value="${input.value}"]`)) {
        select.value = input.value;
    }
}

function loadBranches(repoFullName) {
    if (!repoFullName) return;
    
    showLoading('Loading branches for ' + repoFullName + '...');
    
    fetch(baseUrl + '/api/branches?repo=' + encodeURIComponent(repoFullName))
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                populateBranchSelect(data.branches);
            } else {
                console.error('Failed to load branches:', data.error);
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error loading branches:', error);
        });
}

function populateBranchSelect(branches) {
    const select = document.getElementById('branch_select');
    
    // Clear existing options except first
    select.innerHTML = '<option value="">-- Select branch --</option>';
    
    branches.forEach(branch => {
        const icon = branch.protected ? '🔒 ' : '';
        const optionHtml = `<option value="${branch.name}">${icon}${branch.name}</option>`;
        select.insertAdjacentHTML('beforeend', optionHtml);
    });
    
    // Select current value
    if (currentBranch) {
        select.value = currentBranch;
    }
}

function selectBranch() {
    const select = document.getElementById('branch_select');
    const input = document.getElementById('branch');
    input.value = select.value;
}

function toggleBranchInput() {
    const select = document.getElementById('branch_select');
    const input = document.getElementById('branch');
    
    if (input.style.display === 'none') {
        // Show input, hide select
        input.style.display = 'block';
        select.style.display = 'none';
        input.focus();
    } else {
        // Show select, hide input
        input.style.display = 'none';
        select.style.display = 'block';
        select.value = input.value;
    }
}

function syncSelectWithBranch() {
    const select = document.getElementById('branch_select');
    const input = document.getElementById('branch');
    
    if (input.value && select.querySelector(`option[value="${input.value}"]`)) {
        select.value = input.value;
    }
}
</script>