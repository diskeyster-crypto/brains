<?php

declare(strict_types=1);

use Core\Storage\StorageManager;

function renderStorage(): string
{
    $storage = StorageManager::instance();
    $data = $storage->all();
    
    if (empty($data)) {
        return <<<HTML
<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <p>Нет данных в хранилище.</p>
        </div>
    </div>
</div>
HTML;
    }
    
    $rows = '';
    foreach ($data as $key => $value) {
        $keyHtml = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        $valueJson = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $valueHtml = htmlspecialchars($valueJson, ENT_QUOTES, 'UTF-8');
        $valuePreview = htmlspecialchars(
            strlen($valueJson) > 100 ? substr($valueJson, 0, 100) . '...' : $valueJson,
            ENT_QUOTES,
            'UTF-8'
        );
        
        $rows .= <<<HTML
<tr>
    <td><code>{$keyHtml}</code></td>
    <td><pre style="margin:0;white-space:pre-wrap;max-width:500px;">{$valuePreview}</pre></td>
    <td>
        <button onclick="deleteStorageKey('{$keyHtml}')" class="btn btn-danger btn-sm">Удалить</button>
    </td>
</tr>
HTML;
    }
    
    return <<<HTML
<div class="card">
    <div class="card-header">Данные хранилища</div>
    <div class="card-body">
        <table>
            <thead>
                <tr>
                    <th>Ключ</th>
                    <th>Значение</th>
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
