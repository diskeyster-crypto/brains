<?php

declare(strict_types=1);

use Core\Logger\Logger;

function renderLogs(): string
{
    $logger = Logger::instance();
    $logs = $logger->readLogs(100);
    
    if (empty($logs)) {
        return <<<HTML
<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <p>Логи отсутствуют.</p>
        </div>
    </div>
</div>
HTML;
    }
    
    $logLines = '';
    foreach (array_reverse($logs) as $log) {
        $logHtml = htmlspecialchars(trim($log), ENT_QUOTES, 'UTF-8');
        $logLines .= "<div class=\"log-line\">{$logHtml}</div>\n";
    }
    
    return <<<HTML
<div class="card">
    <div class="card-header">
        Системные логи
        <button onclick="clearLogs()" class="btn btn-danger btn-sm" style="float: right;">Очистить</button>
    </div>
    <div class="card-body">
        <div class="log-viewer">
            {$logLines}
        </div>
    </div>
</div>
HTML;
}
