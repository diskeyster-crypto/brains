<?php
/**
 * System Control Center - Health / Diagnostics
 * Shows REAL system health information.
 */

use Core\System\System;

// Variables: $php_version, $php_sapi, $memory_limit, $max_execution_time
// $system_version, $system_env, $system_initialized
// $extensions, $directories, $routing
// $memory_usage, $memory_peak
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">PHP</div>
        <div class="stat-value"><?= htmlspecialchars($php_version) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Память</div>
        <div class="stat-value"><?= htmlspecialchars($memory_limit) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Используется</div>
        <div class="stat-value"><?= round($memory_usage / 1024 / 1024, 1) ?> MB</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Пик</div>
        <div class="stat-value"><?= round($memory_peak / 1024 / 1024, 1) ?> MB</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>PHP Расширения</h3>
    </div>
    <div class="card-body">
        <table class="table">
            <thead>
                <tr>
                    <th>Расширение</th>
                    <th>Статус</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($extensions as $ext => $loaded): ?>
                <tr>
                    <td><code><?= htmlspecialchars($ext) ?></code></td>
                    <td>
                        <?php if ($loaded): ?>
                            <span class="badge badge-success">Загружено</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Отсутствует</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Директории</h3>
    </div>
    <div class="card-body">
        <table class="table">
            <thead>
                <tr>
                    <th>Директория</th>
                    <th>Путь</th>
                    <th>Существует</th>
                    <th>Запись</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($directories as $name => $info): ?>
                <tr>
                    <td><code><?= htmlspecialchars($name) ?></code></td>
                    <td><small><?= htmlspecialchars($info['path']) ?></small></td>
                    <td>
                        <?php if ($info['exists']): ?>
                            <span class="badge badge-success">Да</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Нет</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($info['writable']): ?>
                            <span class="badge badge-success">Да</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Нет</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Маршрутизация</h3>
    </div>
    <div class="card-body">
        <table class="table">
            <tbody>
                <tr>
                    <td><strong>Base URL</strong></td>
                    <td><code><?= htmlspecialchars($routing['base_url'] ?? '') ?></code></td>
                </tr>
                <tr>
                    <td><strong>Docroot Mode</strong></td>
                    <td><code><?= htmlspecialchars($routing['docroot_mode'] ?? '') ?></code></td>
                </tr>
                <tr>
                    <td><strong>Admin Path</strong></td>
                    <td><code><?= htmlspecialchars($routing['admin_path'] ?? '') ?></code></td>
                </tr>
                <tr>
                    <td><strong>Install URL</strong></td>
                    <td><code><?= htmlspecialchars($routing['effective_install_url'] ?? '') ?></code></td>
                </tr>
                <tr>
                    <td><strong>Admin URL</strong></td>
                    <td><code><?= htmlspecialchars($routing['effective_admin_url'] ?? '') ?></code></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>PHP Информация</h3>
    </div>
    <div class="card-body">
        <table class="table">
            <tbody>
                <tr>
                    <td><strong>SAPI</strong></td>
                    <td><code><?= htmlspecialchars($php_sapi) ?></code></td>
                </tr>
                <tr>
                    <td><strong>Memory Limit</strong></td>
                    <td><code><?= htmlspecialchars($memory_limit) ?></code></td>
                </tr>
                <tr>
                    <td><strong>Max Execution Time</strong></td>
                    <td><code><?= htmlspecialchars($max_execution_time) ?>s</code></td>
                </tr>
                <tr>
                    <td><strong>System Initialized</strong></td>
                    <td><?= $system_initialized ? '<span class="badge badge-success">Да</span>' : '<span class="badge badge-danger">Нет</span>' ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <a href="<?= System::web('admin/system') ?>" class="btn btn-secondary">← Назад к обзору</a>
    </div>
</div>
