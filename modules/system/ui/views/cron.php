<?php
/**
 * System Control Center - Cron Manager
 * Shows REAL cron tasks and execution log from CronManager.
 */

use Core\System\System;

// Variables: $tasks, $log, $due, $total_count, $enabled_count, $flash
$baseUrl = rtrim(System::baseUrl(), '/');
$cronUrl = $baseUrl . '/public/cron.php';
$adminCronUrl = System::web('admin/system/cron');
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Всего задач</div>
        <div class="stat-value"><?= $total_count ?></div>
    </div>
    <div class="stat-card stat-success">
        <div class="stat-label">Активных</div>
        <div class="stat-value"><?= $enabled_count ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Ожидают</div>
        <div class="stat-value"><?= count($due) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Неактивных</div>
        <div class="stat-value"><?= $total_count - $enabled_count ?></div>
    </div>
</div>

<?php if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom: 20px;">
    <?= htmlspecialchars($flash['message']) ?>
</div>
<?php endif; ?>

<!-- ISP / Crontab Setup Help -->
<div class="card" style="margin-bottom: 20px; border-left: 4px solid #17a2b8;">
    <div class="card-header" style="background: linear-gradient(135deg, #1a1d21 0%, #2d3238 100%);">
        <h3><i class="bi bi-terminal"></i> 🕐 ISP Scheduler / Crontab Setup</h3>
    </div>
    <div class="card-body">
        <p style="margin-bottom: 15px; color: #9ca3af;">Добавьте эти команды в ISP Manager → Планировщик или в crontab:</p>
        
        <div style="margin-bottom: 15px;">
            <label style="font-weight: bold; color: #10b981; display: block; margin-bottom: 5px;">
                <i class="bi bi-clock"></i> Запуск каждую минуту (рекомендуется, через PHP CLI):
            </label>
            <div style="display: flex; gap: 10px; align-items: center;">
                <code id="cron-cmd-1" style="flex: 1; background: #161b22; padding: 12px 15px; border-radius: 6px; font-size: 13px; overflow-x: auto;">* * * * * /usr/bin/php <?= System::path('root') ?>/index.php cron run >> /dev/null 2>&1</code>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="copyCronCmd('cron-cmd-1')" title="Копировать">
                    <i class="bi bi-clipboard"></i>
                </button>
            </div>
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="font-weight: bold; color: #f59e0b; display: block; margin-bottom: 5px;">
                <i class="bi bi-globe"></i> Через wget (если нет доступа к PHP CLI):
            </label>
            <div style="display: flex; gap: 10px; align-items: center;">
                <code id="cron-cmd-2" style="flex: 1; background: #161b22; padding: 12px 15px; border-radius: 6px; font-size: 13px; overflow-x: auto;">* * * * * /usr/bin/wget -q -O /dev/null "<?= $cronUrl ?>"</code>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="copyCronCmd('cron-cmd-2')" title="Копировать">
                    <i class="bi bi-clipboard"></i>
                </button>
            </div>
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="font-weight: bold; color: #8b5cf6; display: block; margin-bottom: 5px;">
                <i class="bi bi-link-45deg"></i> Через curl:
            </label>
            <div style="display: flex; gap: 10px; align-items: center;">
                <code id="cron-cmd-3" style="flex: 1; background: #161b22; padding: 12px 15px; border-radius: 6px; font-size: 13px; overflow-x: auto;">* * * * * /usr/bin/curl -s "<?= $cronUrl ?>" > /dev/null</code>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="copyCronCmd('cron-cmd-3')" title="Копировать">
                    <i class="bi bi-clipboard"></i>
                </button>
            </div>
        </div>
        
        <div style="background: #1e293b; padding: 12px 15px; border-radius: 6px; font-size: 12px; color: #94a3b8;">
            <strong>💡 Подсказка:</strong> В ISP Manager используйте формат: <code>* * * * *</code> = каждую минуту. 
            Или <code>*/5 * * * *</code> = каждые 5 минут.
        </div>
    </div>
</div>

<!-- Tasks Table -->
<div class="card">
    <div class="card-header" style="display: flex; align-items: center; gap: 10px;">
        <h3 style="margin: 0;"><i class="bi bi-clock-history"></i> Задачи Cron</h3>
        <div style="margin-left: auto; display: flex; gap: 10px;">
            <button type="button" class="btn btn-sm btn-success" onclick="showAddTaskForm()">
                <i class="bi bi-plus-lg"></i> Добавить задачу
            </button>
            <form method="POST" action="<?= System::web('admin/system/cron/run') ?>" style="display: inline;">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-play-fill"></i> Запустить все
                </button>
            </form>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($tasks)): ?>
            <p class="text-muted">Нет зарегистрированных задач</p>
        <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Модуль</th>
                    <th>Handler</th>
                    <th>Интервал</th>
                    <th>Последний запуск</th>
                    <th>Следующий</th>
                    <th>Статус</th>
                    <th style="width: 200px;">Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tasks as $taskId => $task): ?>
                <tr>
                    <td><code><?= htmlspecialchars($task['module'] ?? $taskId) ?></code></td>
                    <td><?= htmlspecialchars($task['handler'] ?? '-') ?></td>
                    <td><?= ($task['interval'] ?? 60) ?>s</td>
                    <td>
                        <?php if (!empty($task['last_run'])): ?>
                            <?= date('Y-m-d H:i:s', $task['last_run']) ?>
                        <?php else: ?>
                            <span class="text-muted">Никогда</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($task['next_run'])): ?>
                            <?= date('Y-m-d H:i:s', $task['next_run']) ?>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($task['enabled'] ?? false): ?>
                            <span class="badge badge-success">Активна</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Отключена</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <!-- Edit Button -->
                        <button type="button" class="btn btn-sm btn-outline-primary" 
                            onclick="showEditTaskForm('<?= htmlspecialchars($taskId) ?>', '<?= htmlspecialchars($task['module'] ?? '') ?>', '<?= htmlspecialchars($task['handler'] ?? '') ?>', <?= $task['interval'] ?? 60 ?>)"
                            title="Редактировать">
                            <i class="bi bi-pencil"></i>
                        </button>
                        
                        <!-- Toggle Enable/Disable -->
                        <form method="POST" action="<?= System::web('admin/system/cron/toggle') ?>" style="display: inline;">
                            <input type="hidden" name="task_id" value="<?= htmlspecialchars($taskId) ?>">
                            <?php if ($task['enabled'] ?? false): ?>
                                <button type="submit" class="btn btn-sm btn-outline-warning" title="Отключить">
                                    <i class="bi bi-pause-fill"></i>
                                </button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-sm btn-outline-success" title="Включить">
                                    <i class="bi bi-play-fill"></i>
                                </button>
                            <?php endif; ?>
                        </form>
                        
                        <!-- Run Now Button -->
                        <form method="POST" action="<?= System::web('admin/system/cron/run-task') ?>" style="display: inline;">
                            <input type="hidden" name="task_id" value="<?= htmlspecialchars($taskId) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-info" title="Запустить сейчас">
                                <i class="bi bi-lightning"></i>
                            </button>
                        </form>
                        
                        <!-- Delete Button -->
                        <form method="POST" action="<?= System::web('admin/system/cron/delete') ?>" style="display: inline;" 
                            onsubmit="return confirm('Удалить задачу <?= htmlspecialchars($taskId) ?>?')">
                            <input type="hidden" name="task_id" value="<?= htmlspecialchars($taskId) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Удалить">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Task Form (hidden by default) -->
<div id="task-form-card" class="card" style="display: none; margin-bottom: 20px;">
    <div class="card-header">
        <h3 id="task-form-title"><i class="bi bi-plus-lg"></i> Добавить задачу</h3>
    </div>
    <div class="card-body">
        <form id="task-form" method="POST" action="<?= System::web('admin/system/cron/save') ?>">
            <input type="hidden" name="original_task_id" id="original_task_id" value="">
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label for="module">Модуль:</label>
                <input type="text" name="module" id="task-module" class="form-control" required 
                    placeholder="example" style="max-width: 400px;">
            </div>
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label for="handler">Handler:</label>
                <input type="text" name="handler" id="task-handler" class="form-control" required 
                    placeholder="execute" style="max-width: 400px;">
            </div>
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label for="interval">Интервал (секунды):</label>
                <input type="number" name="interval" id="task-interval" class="form-control" required 
                    min="10" value="60" style="max-width: 200px;">
                <small style="color: #6c757d;">Минимум 10 секунд. Рекомендуется: 60 (1 мин), 300 (5 мин), 3600 (1 час)</small>
            </div>
            
            <div class="form-group">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-lg"></i> Сохранить
                </button>
                <button type="button" class="btn btn-secondary" onclick="hideTaskForm()">
                    Отмена
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Execution Log -->
<div class="card">
    <div class="card-header" style="display: flex; align-items: center; gap: 10px;">
        <h3 style="margin: 0;"><i class="bi bi-journal-text"></i> Лог выполнения</h3>
        <form method="POST" action="<?= System::web('admin/system/cron/clear-log') ?>" style="margin-left: auto;"
            onsubmit="return confirm('Очистить весь лог выполнения?')">
            <button type="submit" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-trash"></i> Очистить лог
            </button>
        </form>
    </div>
    <div class="card-body">
        <?php if (empty($log)): ?>
            <p class="text-muted">Нет записей в логе выполнения</p>
        <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Задача</th>
                    <th>Время</th>
                    <th>Статус</th>
                    <th>Сообщение</th>
                    <th>Длительность</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($log as $entry): ?>
                <tr>
                    <td><code><?= htmlspecialchars($entry['task_id'] ?? '-') ?></code></td>
                    <td><?= htmlspecialchars($entry['timestamp'] ?? '-') ?></td>
                    <td>
                        <?php if ($entry['success'] ?? false): ?>
                            <span class="badge badge-success">OK</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Ошибка</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(substr($entry['message'] ?? '', 0, 50)) ?><?= strlen($entry['message'] ?? '') > 50 ? '...' : '' ?></td>
                    <td><?= round($entry['duration'] ?? 0, 4) ?>s</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Actions -->
<div class="card">
    <div class="card-body">
        <a href="<?= System::web('admin/system') ?>" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Назад к обзору
        </a>
    </div>
</div>

<script>
function copyCronCmd(elementId) {
    const codeEl = document.getElementById(elementId);
    const text = codeEl.textContent || codeEl.innerText;
    
    navigator.clipboard.writeText(text).then(() => {
        // Temporary visual feedback
        const btn = codeEl.nextElementSibling;
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check"></i>';
        btn.classList.add('btn-success');
        btn.classList.remove('btn-outline-primary');
        
        setTimeout(() => {
            btn.innerHTML = originalHtml;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-primary');
        }, 1500);
    }).catch(err => {
        alert('Не удалось скопировать: ' + err);
    });
}

function showAddTaskForm() {
    document.getElementById('task-form-card').style.display = 'block';
    document.getElementById('task-form-title').innerHTML = '<i class="bi bi-plus-lg"></i> Добавить задачу';
    document.getElementById('original_task_id').value = '';
    document.getElementById('task-module').value = '';
    document.getElementById('task-handler').value = '';
    document.getElementById('task-interval').value = '60';
    document.getElementById('task-module').readOnly = false;
    document.getElementById('task-handler').readOnly = false;
    
    // Scroll to form
    document.getElementById('task-form-card').scrollIntoView({ behavior: 'smooth' });
}

function showEditTaskForm(taskId, module, handler, interval) {
    document.getElementById('task-form-card').style.display = 'block';
    document.getElementById('task-form-title').innerHTML = '<i class="bi bi-pencil"></i> Редактировать задачу';
    document.getElementById('original_task_id').value = taskId;
    document.getElementById('task-module').value = module;
    document.getElementById('task-handler').value = handler;
    document.getElementById('task-interval').value = interval;
    // For editing, module and handler are typically readonly (changing them = new task)
    document.getElementById('task-module').readOnly = true;
    document.getElementById('task-handler').readOnly = true;
    
    // Scroll to form
    document.getElementById('task-form-card').scrollIntoView({ behavior: 'smooth' });
}

function hideTaskForm() {
    document.getElementById('task-form-card').style.display = 'none';
}
</script>
