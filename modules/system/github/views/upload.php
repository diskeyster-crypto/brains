<?php
/**
 * GitHub Center - Upload Files View (RU) - Total Commander Style
 * 
 * @var string $title
 * @var array $settings
 * @var string $csrf_token
 * @var array $server_files
 * @var bool $include_all_files
 * @var string|null $error
 * @var string|null $success
 */

$includeAllFilesEnabled = !empty($include_all_files);
?>
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= \Core\System\System::web('admin/github') ?>">GitHub Center</a></li>
                    <li class="breadcrumb-item active">Загрузить файлы</li>
                </ol>
            </nav>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col">
            <h2 class="mb-0">
                <i class="bi bi-cloud-upload me-2"></i>
                Загрузка файлов на GitHub
            </h2>
            <p class="text-muted mb-0">Выберите файлы с сервера для загрузки в репозиторий</p>
        </div>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle me-2"></i>
        <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>
    
    <div class="row">
        <!-- Выбор репозитория и ветки -->
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-gear me-2"></i>
                    Настройки загрузки
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Репозиторий</label>
                        <select class="form-select" id="upload-repo">
                            <option value="">Загрузка...</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ветка</label>
                        <select class="form-select" id="upload-branch" disabled>
                            <option value="">Сначала выберите репозиторий</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Сообщение коммита</label>
                        <input type="text" class="form-control" id="commit-message" 
                               placeholder="Загрузка файлов с сервера" 
                               value="Загрузка файлов с сервера">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Путь назначения (в репозитории)</label>
                        <input type="text" class="form-control" id="dest-path" 
                               placeholder="/" value="">
                        <small class="text-muted">Оставьте пустым для корня репозитория</small>
                    </div>
                    <div class="form-check mb-2">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            id="include-all-files-toggle"
                            <?= $includeAllFilesEnabled ? 'checked' : '' ?>
                        >
                        <label class="form-check-label" for="include-all-files-toggle">
                            Показать и загружать все файлы проекта без исключений
                        </label>
                    </div>
                    <small class="text-warning d-block">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <span class="visually-hidden">Внимание:</span>
                        Режим может включать скрытые и чувствительные файлы.
                    </small>
                </div>
            </div>
            
            <!-- Выбранные файлы -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-check2-square me-2"></i>Выбрано файлов: <span id="selected-count">0</span></span>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="clear-selection">Очистить</button>
                </div>
                <div class="card-body" style="max-height: 250px; overflow-y: auto; padding: 0;">
                    <ul class="list-group list-group-flush" id="selected-files-list">
                        <li class="list-group-item text-muted py-3">Файлы не выбраны</li>
                    </ul>
                </div>
                <div class="card-footer">
                    <button type="button" class="btn btn-success w-100 btn-lg" id="upload-btn" disabled>
                        <i class="bi bi-cloud-upload me-2"></i>
                        Загрузить на GitHub
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Дерево файлов сервера - Total Commander Style -->
        <div class="col-md-8">
            <div class="file-tree-container">
                <div class="file-tree-header">
                    <span><i class="bi bi-folder2-open me-2"></i>Файлы на сервере</span>
                    <div>
                        <input type="text" class="form-control form-control-sm" 
                               id="file-search" placeholder="🔍 Поиск файлов..." 
                               style="width: 200px; background: #0d1117; border-color: #30363d; color: #e6edf3;">
                    </div>
                </div>
                <div class="file-tree-body" id="file-tree">
                    <?php if (empty($server_files)): ?>
                    <p class="text-muted p-4">Нет файлов для отображения</p>
                    <?php else: ?>
                    <?= renderFileTree($server_files, '') ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Прогресс загрузки (custom modal without Bootstrap JS) -->
    <div id="upload-progress-modal" class="upload-modal-overlay">
        <div class="upload-modal-dialog">
            <div class="upload-modal-content">
                <div class="upload-modal-header">
                    <h5 class="upload-modal-title">Загрузка файлов</h5>
                    <button type="button" class="btn-close-modal" id="modal-close-btn" title="Закрыть">&times;</button>
                </div>
                <div class="upload-modal-body">
                    <div class="upload-progress-wrapper">
                        <div class="upload-progress-bar" id="upload-progress-bar">0%</div>
                    </div>
                    <p class="upload-status" id="upload-status">Инициализация...</p>
                    <small class="upload-current-file" id="upload-current-file"></small>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
function renderFileTree(array $items, string $parentPath, int $level = 0): string {
    $html = '<ul class="file-tree-list" style="padding-left: ' . ($level > 0 ? '20px' : '0') . ';">';
    
    // Sort: folders first, then files alphabetically
    uksort($items, function($a, $b) use ($items) {
        $aIsDir = isset($items[$a]['type']) && $items[$a]['type'] === 'dir';
        $bIsDir = isset($items[$b]['type']) && $items[$b]['type'] === 'dir';
        if ($aIsDir && !$bIsDir) return -1;
        if (!$aIsDir && $bIsDir) return 1;
        return strcasecmp($a, $b);
    });
    
    foreach ($items as $name => $item) {
        $fullPath = ltrim($parentPath . '/' . $name, '/');
        $isDir = isset($item['type']) && $item['type'] === 'dir';
        
        $html .= '<li class="file-tree-item" data-path="' . htmlspecialchars($fullPath) . '" data-type="' . ($isDir ? 'dir' : 'file') . '">';
        
        if ($isDir) {
            $html .= '<div class="file-tree-toggle">';
            $html .= '<input type="checkbox" class="folder-checkbox" data-path="' . htmlspecialchars($fullPath) . '" title="Выбрать все файлы в папке">';
            $html .= '<i class="bi bi-chevron-right toggle-icon"></i>';
            $html .= '<i class="bi bi-folder-fill file-icon folder"></i>';
            $html .= '<span class="file-name">' . htmlspecialchars($name) . '</span>';
            $html .= '</div>';
            
            if (!empty($item['children'])) {
                $html .= '<div class="file-tree-children" style="display: none;">';
                $html .= renderFileTree($item['children'], $fullPath, $level + 1);
                $html .= '</div>';
            }
        } else {
            $size = isset($item['size']) ? formatSize($item['size']) : '';
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $iconClass = getFileIcon($ext);
            
            $html .= '<label class="file-tree-label">';
            $html .= '<input type="checkbox" class="file-checkbox" data-path="' . htmlspecialchars($fullPath) . '">';
            $html .= '<i class="bi ' . $iconClass . ' file-icon file"></i>';
            $html .= '<span class="file-name">' . htmlspecialchars($name) . '</span>';
            if ($size) {
                $html .= '<span class="file-size">' . $size . '</span>';
            }
            $html .= '</label>';
        }
        
        $html .= '</li>';
    }
    
    $html .= '</ul>';
    return $html;
}

function getFileIcon(string $ext): string {
    $icons = [
        'php' => 'bi-filetype-php',
        'js' => 'bi-filetype-js',
        'css' => 'bi-filetype-css',
        'html' => 'bi-filetype-html',
        'json' => 'bi-filetype-json',
        'xml' => 'bi-filetype-xml',
        'md' => 'bi-filetype-md',
        'txt' => 'bi-filetype-txt',
        'sql' => 'bi-filetype-sql',
        'png' => 'bi-filetype-png',
        'jpg' => 'bi-filetype-jpg',
        'jpeg' => 'bi-filetype-jpg',
        'gif' => 'bi-filetype-gif',
        'svg' => 'bi-filetype-svg',
        'pdf' => 'bi-filetype-pdf',
        'zip' => 'bi-file-earmark-zip',
        'tar' => 'bi-file-earmark-zip',
        'gz' => 'bi-file-earmark-zip',
    ];
    
    return $icons[strtolower($ext)] ?? 'bi-file-earmark';
}

function formatSize(int $bytes): string {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}
?>

<!-- Inline CSS is FORBIDDEN - all styles are in admin/assets/css/ui.css -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectedFiles = new Set();
    const csrfToken = '<?= htmlspecialchars($csrf_token) ?>';
    const includeAllFilesMode = <?= $includeAllFilesEnabled ? 'true' : 'false' ?>;
    const modalEl = document.getElementById('upload-progress-modal');
    
    // Modal close button handler
    document.getElementById('modal-close-btn').addEventListener('click', function() {
        modalEl.classList.remove('show');
    });
    
    // Close modal on overlay click (outside dialog)
    modalEl.addEventListener('click', function(e) {
        if (e.target === modalEl) {
            modalEl.classList.remove('show');
        }
    });

    const includeAllToggle = document.getElementById('include-all-files-toggle');
    if (includeAllToggle) {
        includeAllToggle.addEventListener('change', function() {
            const url = new URL(window.location.href);
            if (this.checked) {
                url.searchParams.set('include_all_files', '1');
            } else {
                url.searchParams.delete('include_all_files');
            }
            window.location.href = url.toString();
        });
    }
    
    // Загрузка репозиториев с таймаутом
    const repoController = new AbortController();
    const repoTimeout = setTimeout(() => repoController.abort(), 15000);
    
    fetch('<?= \Core\System\System::web('admin/github/api/repos') ?>', { signal: repoController.signal })
        .then(r => {
            clearTimeout(repoTimeout);
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(data => {
            const select = document.getElementById('upload-repo');
            select.innerHTML = '<option value="">Выберите репозиторий</option>';
            if (data.success && data.repos) {
                data.repos.forEach(repo => {
                    const opt = document.createElement('option');
                    opt.value = repo.full_name;
                    opt.textContent = repo.full_name + (repo.private ? ' 🔒' : '');
                    opt.dataset.branch = repo.default_branch;
                    select.appendChild(opt);
                });
            } else if (data.error) {
                select.innerHTML = '<option value="">Ошибка: ' + data.error + '</option>';
            }
        })
        .catch(err => {
            console.error('Failed to load repos:', err);
            const select = document.getElementById('upload-repo');
            select.innerHTML = '<option value="">Ошибка загрузки репозиториев</option>';
        });
    
    // При выборе репозитория загружаем ветки
    document.getElementById('upload-repo').addEventListener('change', function() {
        const branchSelect = document.getElementById('upload-branch');
        const repo = this.value;
        
        if (!repo) {
            branchSelect.disabled = true;
            branchSelect.innerHTML = '<option value="">Сначала выберите репозиторий</option>';
            return;
        }
        
        branchSelect.innerHTML = '<option value="">Загрузка...</option>';
        
        fetch('<?= \Core\System\System::web('admin/github/api/branches') ?>?repo=' + encodeURIComponent(repo))
            .then(r => r.json())
            .then(data => {
                branchSelect.innerHTML = '';
                if (data.success && data.branches) {
                    data.branches.forEach(branch => {
                        const opt = document.createElement('option');
                        opt.value = branch.name;
                        opt.textContent = branch.name + (branch.protected ? ' 🔒' : '');
                        branchSelect.appendChild(opt);
                    });
                }
                branchSelect.disabled = false;
            });
    });
    
    // Раскрытие папок (click on toggle icon only, not checkbox)
    document.querySelectorAll('.file-tree-toggle').forEach(toggle => {
        // Only toggle on icon or name click, not on checkbox
        const icon = toggle.querySelector('.toggle-icon');
        const folderIcon = toggle.querySelector('.file-icon.folder');
        const name = toggle.querySelector('.file-name');
        
        [icon, folderIcon, name].forEach(el => {
            if (el) {
                el.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const children = toggle.nextElementSibling;
                    const toggleIcon = toggle.querySelector('.toggle-icon');
                    if (children) {
                        children.style.display = children.style.display === 'none' ? 'block' : 'none';
                        toggleIcon.classList.toggle('open');
                    }
                });
            }
        });
    });
    
    // Выбор папок - выбрать все файлы внутри
    document.querySelectorAll('.folder-checkbox').forEach(folderCb => {
        folderCb.addEventListener('click', function(e) {
            e.stopPropagation(); // Don't trigger folder toggle
        });
        
        folderCb.addEventListener('change', function() {
            const folderPath = this.dataset.path;
            const isChecked = this.checked;
            
            // Find the children container (next sibling after toggle div)
            const toggle = this.closest('.file-tree-toggle');
            const childrenContainer = toggle.nextElementSibling;
            
            // Expand folder if checking
            if (isChecked && childrenContainer) {
                childrenContainer.style.display = 'block';
                const toggleIcon = toggle.querySelector('.toggle-icon');
                if (toggleIcon) toggleIcon.classList.add('open');
            }
            
            // Select/deselect all file checkboxes inside this folder
            if (childrenContainer) {
                childrenContainer.querySelectorAll('.file-checkbox').forEach(fileCb => {
                    fileCb.checked = isChecked;
                    const path = fileCb.dataset.path;
                    if (isChecked) {
                        selectedFiles.add(path);
                    } else {
                        selectedFiles.delete(path);
                    }
                });
                
                // Also check/uncheck nested folder checkboxes
                childrenContainer.querySelectorAll('.folder-checkbox').forEach(nestedFolderCb => {
                    nestedFolderCb.checked = isChecked;
                });
            }
            
            updateSelectedList();
        });
    });
    
    // Выбор файлов
    document.querySelectorAll('.file-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const path = this.dataset.path;
            if (this.checked) {
                selectedFiles.add(path);
            } else {
                selectedFiles.delete(path);
            }
            updateSelectedList();
        });
    });
    
    // Очистка выбора
    document.getElementById('clear-selection').addEventListener('click', function() {
        selectedFiles.clear();
        document.querySelectorAll('.file-checkbox').forEach(cb => cb.checked = false);
        document.querySelectorAll('.folder-checkbox').forEach(cb => cb.checked = false);
        updateSelectedList();
    });
    
    function updateSelectedList() {
        const list = document.getElementById('selected-files-list');
        const count = document.getElementById('selected-count');
        const btn = document.getElementById('upload-btn');
        
        count.textContent = selectedFiles.size;
        btn.disabled = selectedFiles.size === 0;
        
        if (selectedFiles.size === 0) {
            list.innerHTML = '<li class="list-group-item text-muted">Файлы не выбраны</li>';
            return;
        }
        
        list.innerHTML = '';
        selectedFiles.forEach(path => {
            const li = document.createElement('li');
            li.className = 'list-group-item d-flex justify-content-between align-items-center py-1';
            li.innerHTML = `
                <small class="text-truncate" style="max-width: 200px;">${path}</small>
                <button type="button" class="btn btn-sm btn-link text-danger p-0" data-remove="${path}">
                    <i class="bi bi-x"></i>
                </button>
            `;
            list.appendChild(li);
        });
        
        // Удаление из списка
        list.querySelectorAll('[data-remove]').forEach(btn => {
            btn.addEventListener('click', function() {
                const path = this.dataset.remove;
                selectedFiles.delete(path);
                const checkbox = document.querySelector(`.file-checkbox[data-path="${path}"]`);
                if (checkbox) checkbox.checked = false;
                updateSelectedList();
            });
        });
    }
    
    // Поиск файлов
    document.getElementById('file-search').addEventListener('input', function() {
        const query = this.value.toLowerCase();
        document.querySelectorAll('.file-tree-item').forEach(item => {
            const name = item.querySelector('.file-name').textContent.toLowerCase();
            const path = item.dataset.path.toLowerCase();
            const matches = name.includes(query) || path.includes(query);
            item.style.display = query === '' || matches ? '' : 'none';
            
            // Раскрыть родительские папки при поиске
            if (matches && query) {
                let parent = item.parentElement;
                while (parent) {
                    if (parent.classList.contains('file-tree-children')) {
                        parent.style.display = 'block';
                        const toggle = parent.previousElementSibling;
                        if (toggle) {
                            const icon = toggle.querySelector('.toggle-icon');
                            if (icon) icon.classList.add('open');
                        }
                    }
                    parent = parent.parentElement;
                }
            }
        });
    });
    
    // Загрузка файлов
    document.getElementById('upload-btn').addEventListener('click', async function() {
        const repo = document.getElementById('upload-repo').value;
        const branch = document.getElementById('upload-branch').value;
        const message = document.getElementById('commit-message').value || 'Загрузка файлов с сервера';
        const destPath = document.getElementById('dest-path').value.replace(/^\/+|\/+$/g, '');
        
        if (!repo || !branch) {
            alert('Выберите репозиторий и ветку');
            return;
        }
        
        if (selectedFiles.size === 0) {
            alert('Выберите файлы для загрузки');
            return;
        }
        
        // Показать прогресс (native modal - no Bootstrap)
        modalEl.classList.add('show');
        
        const progressBar = document.getElementById('upload-progress-bar');
        const status = document.getElementById('upload-status');
        const currentFile = document.getElementById('upload-current-file');
        
        // Reset progress
        progressBar.style.width = '0%';
        progressBar.textContent = '0%';
        progressBar.className = 'upload-progress-bar';
        status.textContent = 'Начинаем загрузку...';
        currentFile.textContent = '';
        
        const files = Array.from(selectedFiles);
        let uploaded = 0;
        let failed = 0;
        const errors = [];
        
        for (const file of files) {
            const progress = Math.round((uploaded / files.length) * 100);
            progressBar.style.width = progress + '%';
            progressBar.textContent = progress + '%';
            currentFile.textContent = file;
            status.textContent = `Загрузка: ${uploaded + 1} из ${files.length}`;
            
            try {
                const formData = new FormData();
                formData.append('_csrf', csrfToken);
                formData.append('repo', repo);
                formData.append('branch', branch);
                formData.append('message', message);
                formData.append('file_path', file);
                formData.append('dest_path', destPath);
                formData.append('include_all_files', includeAllFilesMode ? '1' : '0');
                
                // Добавляем таймаут 60 секунд на каждый файл
                const controller = new AbortController();
                const timeout = setTimeout(() => controller.abort(), 60000);
                
                const response = await fetch('<?= \Core\System\System::web('admin/github/api/upload') ?>', {
                    method: 'POST',
                    body: formData,
                    signal: controller.signal
                });
                
                clearTimeout(timeout);
                
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                
                const result = await response.json();
                if (result.success) {
                    uploaded++;
                } else {
                    failed++;
                    errors.push(file + ': ' + (result.error || 'Unknown error'));
                    console.error('Upload failed for ' + file + ':', result.error);
                }
            } catch (e) {
                failed++;
                const errMsg = e.name === 'AbortError' ? 'Timeout' : e.message;
                errors.push(file + ': ' + errMsg);
                console.error('Upload error for ' + file + ':', e);
            }
        }
        
        progressBar.style.width = '100%';
        progressBar.textContent = '100%';
        
        if (failed === 0) {
            progressBar.classList.add('success');
            status.textContent = `Готово! Загружено ${uploaded} файлов`;
        } else {
            progressBar.classList.add('warning');
            status.textContent = `Загружено: ${uploaded}, Ошибок: ${failed}`;
            if (errors.length > 0) {
                currentFile.textContent = errors.slice(0, 3).join('; ');
            }
        }
        
        // Закрыть через 3 секунды (или позже если есть ошибки)
        const closeDelay = failed > 0 ? 5000 : 3000;
        setTimeout(() => {
            modalEl.classList.remove('show');
            if (failed === 0) {
                selectedFiles.clear();
                document.querySelectorAll('.file-checkbox').forEach(cb => cb.checked = false);
                updateSelectedList();
            }
        }, 3000);
    });
});
</script>
