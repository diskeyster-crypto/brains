<?php
/**
 * Admin UI - GitHub Update Center
 * 
 * Страница управления обновлениями и резервным копированием
 */

// Data is passed from public/index.php
$systemStatus = $data['status'] ?? [];
$backups = $data['backups'] ?? [];
$settings = $data['settings'] ?? [];
$updateLog = $data['update_log'] ?? [];
$message = $data['message'] ?? null;
$error = $data['error'] ?? null;
$updateCheck = $data['update_check'] ?? null;
$testResult = $data['test_result'] ?? null;
?>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">Центр обновлений</h5>
    </div>
    <div class="card-body">
        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <strong>Статус:</strong>
                    <?php if ($systemStatus['status'] === 'ok'): ?>
                        <span class="badge bg-success">Готово</span>
                    <?php elseif ($systemStatus['status'] === 'warning'): ?>
                        <span class="badge bg-warning">Внимание</span>
                    <?php else: ?>
                        <span class="badge bg-danger">Ошибка</span>
                    <?php endif; ?>
                    <span class="text-muted ms-2"><?= htmlspecialchars($systemStatus['status_message'] ?? '') ?></span>
                </div>
                
                <div class="mb-3">
                    <strong>Credentials:</strong>
                    <?php if ($systemStatus['has_credentials'] ?? false): ?>
                        <span class="badge bg-success">Настроены</span>
                    <?php else: ?>
                        <span class="badge bg-warning">Не настроены</span>
                    <?php endif; ?>
                </div>
                
                <?php if ($systemStatus['last_backup'] ?? null): ?>
                    <div class="mb-3">
                        <strong>Последний бэкап:</strong>
                        <span class="text-muted">
                            <?= htmlspecialchars($systemStatus['last_backup']['id'] ?? 'N/A') ?>
                            (<?= htmlspecialchars($systemStatus['last_backup']['created_at'] ?? '') ?>)
                        </span>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="col-md-6">
                <div class="d-flex gap-2 flex-wrap">
                    <form method="POST" action="/admin/update" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">
                        <input type="hidden" name="action" value="check_updates">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-cloud-download"></i> Проверить обновления
                        </button>
                    </form>
                    
                    <form method="POST" action="/admin/update" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">
                        <input type="hidden" name="action" value="create_backup">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-archive"></i> Создать бэкап
                        </button>
                    </form>
                    
                    <form method="POST" action="/admin/update" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">
                        <input type="hidden" name="action" value="test_connection">
                        <button type="submit" class="btn btn-outline-secondary">
                            <i class="bi bi-wifi"></i> Тест подключения
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <?php if ($testResult): ?>
            <div class="alert <?= $testResult['status'] === 'success' ? 'alert-success' : 'alert-danger' ?> mt-3">
                <strong>Тест подключения:</strong> <?= htmlspecialchars($testResult['message'] ?? '') ?>
                <?php if (isset($testResult['user'])): ?>
                    <br>Пользователь: <strong><?= htmlspecialchars($testResult['user']) ?></strong>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($updateCheck && $updateCheck['status'] === 'success'): ?>
            <div class="card mt-3">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0">Доступные обновления</h6>
                </div>
                <div class="card-body">
                    <p>
                        Найдено файлов: <strong><?= $updateCheck['summary']['total'] ?? 0 ?></strong>
                        (Безопасных: <?= $updateCheck['summary']['safe'] ?? 0 ?>,
                        Требуют проверки: <?= $updateCheck['summary']['review'] ?? 0 ?>,
                        Защищённых: <?= $updateCheck['summary']['protected'] ?? 0 ?>)
                    </p>
                    
                    <?php if (!empty($updateCheck['files']['safe']) || !empty($updateCheck['files']['review'])): ?>
                        <form method="POST" action="/admin/update">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">
                            <input type="hidden" name="action" value="install_update">
                            
                            <?php if (!empty($updateCheck['files']['safe'])): ?>
                                <h6>Безопасные файлы (будут обновлены автоматически):</h6>
                                <ul class="list-unstyled small">
                                    <?php foreach (array_slice($updateCheck['files']['safe'], 0, 10) as $file): ?>
                                        <li class="text-success">
                                            <i class="bi bi-check-circle"></i> <?= htmlspecialchars($file['path']) ?>
                                            (<?= $file['action'] ?>)
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if (count($updateCheck['files']['safe']) > 10): ?>
                                        <li class="text-muted">... и ещё <?= count($updateCheck['files']['safe']) - 10 ?> файлов</li>
                                    <?php endif; ?>
                                </ul>
                            <?php endif; ?>
                            
                            <?php if (!empty($updateCheck['files']['review'])): ?>
                                <h6>Файлы, требующие подтверждения:</h6>
                                <ul class="list-unstyled small">
                                    <?php foreach ($updateCheck['files']['review'] as $file): ?>
                                        <li class="text-warning">
                                            <input type="checkbox" name="confirm_files[]" value="<?= htmlspecialchars($file['path']) ?>" class="form-check-input">
                                            <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($file['path']) ?>
                                            (<?= $file['action'] ?>)
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            
                            <?php if (!empty($updateCheck['files']['protected'])): ?>
                                <h6>Защищённые файлы (не будут изменены):</h6>
                                <ul class="list-unstyled small">
                                    <?php foreach ($updateCheck['files']['protected'] as $file): ?>
                                        <li class="text-danger">
                                            <i class="bi bi-shield-lock"></i> <?= htmlspecialchars($file['path']) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            
                            <button type="submit" class="btn btn-warning mt-3">
                                <i class="bi bi-download"></i> Установить обновление
                            </button>
                        </form>
                    <?php else: ?>
                        <p class="text-success">Система актуальна, обновлений нет.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Настройки GitHub</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="/admin/update">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">
                    <input type="hidden" name="action" value="save_settings">
                    
                    <div class="mb-3">
                        <label class="form-label">GitHub Username</label>
                        <input type="text" name="github_username" class="form-control" 
                               value="<?= htmlspecialchars($settings['github_username'] ?? '') ?>"
                               placeholder="your-username">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Personal Access Token</label>
                        <input type="password" name="token" class="form-control" 
                               placeholder="<?= !empty($settings['token']) ? '••••••••' : 'ghp_xxxx...' ?>">
                        <small class="text-muted">
                            <a href="https://github.com/settings/tokens" target="_blank">Создать токен</a>
                        </small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Репозиторий обновлений</label>
                        <input type="text" name="update_repo" class="form-control" 
                               value="<?= htmlspecialchars($settings['update_repo'] ?? '') ?>"
                               placeholder="username/project">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Репозиторий бэкапов</label>
                        <input type="text" name="backup_repo" class="form-control" 
                               value="<?= htmlspecialchars($settings['backup_repo'] ?? '') ?>"
                               placeholder="username/project-backup">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Ветка</label>
                        <input type="text" name="branch" class="form-control" 
                               value="<?= htmlspecialchars($settings['branch'] ?? 'main') ?>"
                               placeholder="main">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Сохранить настройки
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Резервные копии</h5>
            </div>
            <div class="card-body">
                <?php if (empty($backups)): ?>
                    <p class="text-muted">Нет резервных копий</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Тип</th>
                                    <th>Дата</th>
                                    <th>Файлов</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($backups, 0, 10) as $backup): ?>
                                    <tr>
                                        <td class="small"><?= htmlspecialchars($backup['id']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $backup['type'] === 'manual' ? 'primary' : 'secondary' ?>">
                                                <?= htmlspecialchars($backup['type']) ?>
                                            </span>
                                        </td>
                                        <td class="small"><?= htmlspecialchars($backup['created_at'] ?? '') ?></td>
                                        <td><?= $backup['files_count'] ?? 0 ?></td>
                                        <td>
                                            <form method="POST" action="/admin/update" style="display:inline">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">
                                                <input type="hidden" name="action" value="rollback">
                                                <input type="hidden" name="backup_id" value="<?= htmlspecialchars($backup['id']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-warning" 
                                                        onclick="return confirm('Восстановить из этой резервной копии?')">
                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Лог обновлений</h5>
            </div>
            <div class="card-body">
                <?php if (empty($updateLog)): ?>
                    <p class="text-muted">Нет записей</p>
                <?php else: ?>
                    <pre class="log-viewer small" style="max-height: 200px; overflow-y: auto; font-size: 11px;"><?php
                        foreach (array_slice($updateLog, 0, 20) as $line) {
                            echo htmlspecialchars($line) . "\n";
                        }
                    ?></pre>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
