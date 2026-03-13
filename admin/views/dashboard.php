<?php

declare(strict_types=1);

use Core\System\System;
use Core\Module\ModuleManager;
use Core\Cron\CronManager;
use Core\Storage\StorageManager;

function renderDashboard(): string
{
    $modules = ModuleManager::instance()->getAll();
    $activeModules = ModuleManager::instance()->getActive();
    $cronTasks = CronManager::instance()->getAll();
    $storage = StorageManager::instance()->all();
    
    $runtime = System::runtime();
    $memoryUsage = round($runtime->getMemoryUsage() / 1024 / 1024, 2);
    $elapsedTime = round($runtime->getElapsedTime() * 1000, 2);
    
    $totalModules = count($modules);
    $activeModulesCount = count($activeModules);
    $totalCronTasks = count($cronTasks);
    $enabledCronTasks = count(array_filter($cronTasks, fn($t) => $t['enabled']));
    $storageKeys = count($storage);
    
    $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'CLI';
    $phpVersion = PHP_VERSION;
    $systemEnv = System::env();
    
    return <<<HTML
<div class="stats-grid">
    <div class="stat-card">
        <h3>Модули</h3>
        <div class="value">{$activeModulesCount}/{$totalModules}</div>
    </div>
    <div class="stat-card">
        <h3>Задачи Cron</h3>
        <div class="value">{$enabledCronTasks}/{$totalCronTasks}</div>
    </div>
    <div class="stat-card">
        <h3>Ключей в хранилище</h3>
        <div class="value">{$storageKeys}</div>
    </div>
    <div class="stat-card">
        <h3>Память</h3>
        <div class="value">{$memoryUsage} МБ</div>
    </div>
</div>

<div class="card">
    <div class="card-header">Информация о системе</div>
    <div class="card-body">
        <table>
            <tr>
                <th>Окружение</th>
                <td>{$serverSoftware}</td>
            </tr>
            <tr>
                <th>Версия PHP</th>
                <td>{$phpVersion}</td>
            </tr>
            <tr>
                <th>Режим системы</th>
                <td>{$systemEnv}</td>
            </tr>
            <tr>
                <th>Время ответа</th>
                <td>{$elapsedTime} мс</td>
            </tr>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">Быстрые действия</div>
    <div class="card-body">
        <a href="/admin/modules" class="btn btn-primary">Управление модулями</a>
        <a href="/admin/cron" class="btn btn-primary">Планировщик</a>
        <a href="/admin/storage" class="btn btn-primary">Хранилище</a>
        <a href="/admin/logs" class="btn btn-primary">Логи</a>
    </div>
</div>
HTML;
}
