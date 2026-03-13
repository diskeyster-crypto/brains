<?php
/**
 * System Control Center - Storage
 * Shows REAL storage data from StorageManager.
 */

use Core\System\System;

// Variables: $backend, $size, $keys
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Backend</div>
        <div class="stat-value"><?= htmlspecialchars(strtoupper($backend)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Размер</div>
        <div class="stat-value"><?= htmlspecialchars($size) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Ключей</div>
        <div class="stat-value"><?= count($keys) ?></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Данные в хранилище</h3>
    </div>
    <div class="card-body">
        <?php if (empty($keys)): ?>
            <p class="text-muted">Хранилище пустое</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Ключ</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keys as $key): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($key) ?></code></td>
                        <td>
                            <a href="<?= System::adminUrl('storage') ?>?view=<?= urlencode($key) ?>" class="btn btn-sm btn-primary">Просмотр</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <a href="<?= System::web('admin/system') ?>" class="btn btn-secondary">← Назад к обзору</a>
    </div>
</div>
