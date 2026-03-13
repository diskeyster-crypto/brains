<?php
/**
 * System Control Center - Logs
 * Shows REAL log files from System::path('logs').
 */

use Core\System\System;

// Variables: $logs_path, $log_files, $current_log, $log_content
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Лог файлы</div>
        <div class="stat-value"><?= count($log_files) ?></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Лог файлы</h3>
    </div>
    <div class="card-body">
        <?php if (empty($log_files)): ?>
            <p class="text-muted">Лог файлы отсутствуют</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Файл</th>
                        <th>Размер</th>
                        <th>Строк</th>
                        <th>Изменён</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($log_files as $file): ?>
                    <tr class="<?= $file['name'] === $current_log ? 'table-active' : '' ?>">
                        <td><code><?= htmlspecialchars($file['name']) ?></code></td>
                        <td><?= number_format($file['size']) ?> B</td>
                        <td><?= $file['lines'] ?></td>
                        <td><?= date('Y-m-d H:i:s', $file['modified']) ?></td>
                        <td>
                            <a href="<?= System::web('admin/system/logs') ?>?log=<?= urlencode($file['name']) ?>" class="btn btn-sm btn-primary">Просмотр</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($log_content)): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="bi bi-file-text"></i> <?= htmlspecialchars($current_log) ?></h3>
    </div>
    <div class="card-body">
        <div class="log-viewer">
            <pre><?= htmlspecialchars($log_content) ?></pre>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <a href="<?= System::web('admin/system') ?>" class="btn btn-secondary">← Назад к обзору</a>
    </div>
</div>
