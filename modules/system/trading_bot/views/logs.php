<?php
/**
 * Trading Bot Logs View
 * 
 * Shows error logs.
 */

use Core\System\System;
use Core\System\SystemPaths;

$tab = 'logs';

// Include tabs via SystemPaths
$moduleBase = SystemPaths::instance()->get('system.trading_bot');
require $moduleBase . '/views/_tabs.php';
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-journal-text me-2"></i>Error Log</span>
        <span class="badge bg-secondary"><?= count($errorLog ?? []) ?> entries</span>
    </div>
    <div class="card-body">
        <?php if (empty($errorLog)): ?>
        <p class="text-muted mb-0">No error log entries</p>
        <?php else: ?>
        <div class="log-container" style="max-height: 600px; overflow-y: auto;">
            <?php foreach ($errorLog as $entry): ?>
            <div class="log-line log-error">
                <span class="text-muted"><?= htmlspecialchars($entry['ts'] ?? '') ?></span>
                <span class="badge bg-danger ms-2"><?= htmlspecialchars($entry['context'] ?? '') ?></span>
                <span class="ms-2"><?= htmlspecialchars($entry['message'] ?? '') ?></span>
                <?php if (!empty($entry['data'])): ?>
                <details class="mt-1">
                    <summary class="small text-muted">Details</summary>
                    <pre class="small mb-0"><?= htmlspecialchars(json_encode($entry['data'], JSON_PRETTY_PRINT)) ?></pre>
                </details>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
