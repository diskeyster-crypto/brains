<?php

declare(strict_types=1);

use Core\Cron\CronManager;

function renderCron(): string
{
    $cronManager = CronManager::instance();
    $tasks = $cronManager->getAll();
    $logs = $cronManager->getLog(20);
    
    $taskRows = '';
    if (empty($tasks)) {
        $taskRows = '<tr><td colspan="6" class="empty-state">Нет зарегистрированных задач.</td></tr>';
    } else {
        foreach ($tasks as $taskId => $task) {
            $statusBadge = $task['enabled'] 
                ? '<span class="badge badge-success">Включен</span>' 
                : '<span class="badge badge-danger">Выключен</span>';
            
            $actionBtn = $task['enabled']
                ? "<button onclick=\"toggleCron('{$taskId}', false)\" class=\"btn btn-danger btn-sm\">Выключить</button>"
                : "<button onclick=\"toggleCron('{$taskId}', true)\" class=\"btn btn-success btn-sm\">Включить</button>";
            
            $module = htmlspecialchars($task['module'], ENT_QUOTES, 'UTF-8');
            $handler = htmlspecialchars($task['handler'], ENT_QUOTES, 'UTF-8');
            $interval = (int)$task['interval'];
            $lastRun = $task['last_run'] ? date('Y-m-d H:i:s', (int)$task['last_run']) : 'Никогда';
            $nextRun = $task['next_run'] ? date('Y-m-d H:i:s', (int)$task['next_run']) : 'Н/Д';
            
            $taskRows .= <<<HTML
<tr>
    <td>{$module}</td>
    <td>{$handler}</td>
    <td>{$interval}с</td>
    <td>{$lastRun}</td>
    <td>{$statusBadge}</td>
    <td>{$actionBtn}</td>
</tr>
HTML;
        }
    }
    
    $logRows = '';
    if (empty($logs)) {
        $logRows = '<tr><td colspan="5" class="empty-state">Нет логов выполнения.</td></tr>';
    } else {
        foreach ($logs as $log) {
            $statusBadge = $log['success'] 
                ? '<span class="badge badge-success">Успех</span>' 
                : '<span class="badge badge-danger">Ошибка</span>';
            
            $taskId = htmlspecialchars($log['task_id'], ENT_QUOTES, 'UTF-8');
            $timestamp = htmlspecialchars($log['timestamp'], ENT_QUOTES, 'UTF-8');
            $message = htmlspecialchars($log['message'], ENT_QUOTES, 'UTF-8');
            $duration = round((float)$log['duration'], 4);
            
            $logRows .= <<<HTML
<tr>
    <td>{$taskId}</td>
    <td>{$timestamp}</td>
    <td>{$statusBadge}</td>
    <td>{$message}</td>
    <td>{$duration}с</td>
</tr>
HTML;
        }
    }
    
    return <<<HTML
<div class="card">
    <div class="card-header">
        Задачи планировщика
        <button onclick="runCron()" class="btn btn-primary btn-sm" style="float: right;">Запустить</button>
    </div>
    <div class="card-body">
        <table>
            <thead>
                <tr>
                    <th>Модуль</th>
                    <th>Обработчик</th>
                    <th>Интервал</th>
                    <th>Последний запуск</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                {$taskRows}
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">Лог выполнения</div>
    <div class="card-body">
        <table>
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
                {$logRows}
            </tbody>
        </table>
    </div>
</div>
HTML;
}
