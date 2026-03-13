<?php
/**
 * System Control Center - Dashboard
 * 
 * Shows REAL system metrics from Core services.
 * NO hardcoded or mock values allowed.
 */

use Core\System\System;

// Variables from controller (all REAL data):
// $version, $env, $php_version
// $keys_count, $keys_services
// $paths_count, $storage_size, $storage_backend
// $modules_count, $modules_enabled, $cron_tasks
// $cron_enabled, $cron_last_run, $cron_status
// $pages_total, $pages_system, $pages_module
// $health_status, $memory_usage, $last_update
?>

<!-- System Stats Row -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Версия</div>
        <div class="stat-value"><?= htmlspecialchars($version) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Режим</div>
        <div class="stat-value"><?= htmlspecialchars(strtoupper($env)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">API Ключи</div>
        <div class="stat-value"><?= $keys_count ?></div>
    </div>
    <div class="stat-card <?= $health_status === 'ok' ? 'stat-success' : ($health_status === 'warning' ? 'stat-warning' : 'stat-danger') ?>">
        <div class="stat-label">Статус</div>
        <div class="stat-value"><?= $health_status === 'ok' ? 'OK' : ($health_status === 'warning' ? '!' : '✕') ?></div>
    </div>
</div>

<!-- Feature Cards Grid -->
<div class="feature-cards-grid">
    <!-- Updates Card -->
    <a href="<?= System::web('admin/github') ?>" class="feature-card feature-card-primary">
        <div class="feature-icon"><i class="bi bi-cloud-download"></i></div>
        <div class="feature-title">Обновления</div>
        <div class="feature-desc">GitHub Center — загрузка и отправка кода</div>
        <?php if ($last_update): ?>
            <div class="feature-badge">Последнее: <?= htmlspecialchars($last_update) ?></div>
        <?php endif; ?>
    </a>

    <!-- Cron Card -->
    <a href="<?= System::web('admin/system/cron') ?>" class="feature-card feature-card-info">
        <div class="feature-icon"><i class="bi bi-clock-history"></i></div>
        <div class="feature-title">Cron Manager</div>
        <div class="feature-desc"><?= $cron_enabled ?> / <?= $cron_tasks ?> активных задач</div>
        <div class="feature-badge badge-<?= $cron_status ?>">
            <?= $cron_status === 'ok' ? 'Работает' : ($cron_status === 'warning' ? 'Внимание' : 'Ошибки') ?>
        </div>
    </a>

    <!-- Storage Card -->
    <a href="<?= System::web('admin/system/storage') ?>" class="feature-card feature-card-success">
        <div class="feature-icon"><i class="bi bi-database"></i></div>
        <div class="feature-title">Хранилище</div>
        <div class="feature-desc">Backend: <?= htmlspecialchars($storage_backend) ?></div>
        <div class="feature-badge"><?= htmlspecialchars($storage_size) ?></div>
    </a>

    <!-- Keys Card -->
    <a href="<?= System::web('admin/system/keys') ?>" class="feature-card feature-card-warning">
        <div class="feature-icon"><i class="bi bi-key"></i></div>
        <div class="feature-title">Ключи API</div>
        <div class="feature-desc">KeyCenter — управление учётными данными</div>
        <div class="feature-badge"><?= $keys_count ?> ключей</div>
    </a>

    <!-- Pages Card -->
    <a href="<?= System::web('admin/system/pages') ?>" class="feature-card feature-card-secondary">
        <div class="feature-icon"><i class="bi bi-file-earmark-text"></i></div>
        <div class="feature-title">Страницы</div>
        <div class="feature-desc"><?= $pages_system ?> системных, <?= $pages_module ?> модульных</div>
        <div class="feature-badge"><?= $pages_total ?> страниц</div>
    </a>

    <!-- Health Card -->
    <a href="<?= System::web('admin/system/health') ?>" class="feature-card feature-card-<?= $health_status === 'ok' ? 'success' : ($health_status === 'warning' ? 'warning' : 'danger') ?>">
        <div class="feature-icon"><i class="bi bi-heart-pulse"></i></div>
        <div class="feature-title">Диагностика</div>
        <div class="feature-desc">Проверка здоровья системы</div>
        <div class="feature-badge badge-<?= $health_status ?>"><?= $health_status === 'ok' ? 'Всё OK' : ($health_status === 'warning' ? 'Внимание' : 'Ошибки') ?></div>
    </a>
</div>

<!-- System Information -->
<div class="card">
    <div class="card-header">
        <h3>Информация о системе</h3>
    </div>
    <div class="card-body">
        <table class="table">
            <tbody>
                <tr>
                    <td><strong>PHP Версия</strong></td>
                    <td><?= htmlspecialchars($php_version) ?></td>
                </tr>
                <tr>
                    <td><strong>Модули</strong></td>
                    <td><?= $modules_enabled ?> / <?= $modules_count ?> активных</td>
                </tr>
                <tr>
                    <td><strong>Cron задачи</strong></td>
                    <td><?= $cron_enabled ?> / <?= $cron_tasks ?> активных</td>
                </tr>
                <tr>
                    <td><strong>Последний Cron</strong></td>
                    <td><?= $cron_last_run ?? 'Никогда' ?></td>
                </tr>
                <tr>
                    <td><strong>Страницы</strong></td>
                    <td><?= $pages_total ?> (<?= $pages_system ?> системных, <?= $pages_module ?> модульных)</td>
                </tr>
                <tr>
                    <td><strong>Память</strong></td>
                    <td><?= htmlspecialchars($memory_usage) ?></td>
                </tr>
                <tr>
                    <td><strong>Системные пути</strong></td>
                    <td><?= $paths_count ?> путей</td>
                </tr>
                <?php if (!empty($keys_services)): ?>
                <tr>
                    <td><strong>Сервисы</strong></td>
                    <td><?= htmlspecialchars(implode(', ', $keys_services)) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Quick Actions -->
<div class="card">
    <div class="card-header">
        <h3>Быстрые действия</h3>
    </div>
    <div class="card-body">
        <div class="quick-actions">
            <a href="<?= System::web('admin/system/paths') ?>" class="btn btn-primary">
                <i class="bi bi-signpost-2"></i> Пути
            </a>
            <a href="<?= System::web('admin/system/logs') ?>" class="btn btn-primary">
                <i class="bi bi-journal-text"></i> Логи
            </a>
            <a href="<?= System::adminUrl('modules') ?>" class="btn btn-primary">
                <i class="bi bi-puzzle"></i> Модули
            </a>
            <a href="<?= System::web('admin/system/cron') ?>" class="btn btn-primary">
                <i class="bi bi-clock-history"></i> Cron
            </a>
            <a href="<?= System::web('admin/system/pages') ?>" class="btn btn-primary">
                <i class="bi bi-file-earmark-text"></i> Страницы
            </a>
        </div>
    </div>
</div>
