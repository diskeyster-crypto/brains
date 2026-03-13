<?php

declare(strict_types=1);

use Core\Module\ModuleManager;

function renderModules(): string
{
    $moduleManager = ModuleManager::instance();
    $modules = $moduleManager->getAll();
    $active = $moduleManager->getActive();
    
    if (empty($modules)) {
        return <<<HTML
<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <p>Модули не найдены.</p>
            <p>Создайте модуль в директории /modules/ с файлом manifest.json.</p>
        </div>
    </div>
</div>
HTML;
    }
    
    $rows = '';
    foreach ($modules as $name => $module) {
        $isActive = isset($active[$name]);
        $statusBadge = $isActive 
            ? '<span class="badge badge-success">Включен</span>' 
            : '<span class="badge badge-danger">Выключен</span>';
        
        $actionBtn = $isActive
            ? "<button onclick=\"toggleModule('{$name}', false)\" class=\"btn btn-danger btn-sm\">Выключить</button>"
            : "<button onclick=\"toggleModule('{$name}', true)\" class=\"btn btn-success btn-sm\">Включить</button>";
        
        $moduleName = htmlspecialchars($module['name'], ENT_QUOTES, 'UTF-8');
        $version = htmlspecialchars($module['version'], ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars($module['description'], ENT_QUOTES, 'UTF-8');
        
        $rows .= <<<HTML
<tr>
    <td>{$moduleName}</td>
    <td>{$version}</td>
    <td>{$description}</td>
    <td>{$statusBadge}</td>
    <td>{$actionBtn}</td>
</tr>
HTML;
    }
    
    return <<<HTML
<div class="card">
    <div class="card-header">Установленные модули</div>
    <div class="card-body">
        <table>
            <thead>
                <tr>
                    <th>Название</th>
                    <th>Версия</th>
                    <th>Описание</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                {$rows}
            </tbody>
        </table>
    </div>
</div>
HTML;
}
