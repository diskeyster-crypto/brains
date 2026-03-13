<?php
/**
 * System Control Center - Paths (PathMan)
 * Shows REAL system paths from System::path().
 */

use Core\System\System;

// Variables: $paths, $base_url, $admin_url
?>

<div class="card">
    <div class="card-header">
        <h3>Файловые пути</h3>
    </div>
    <div class="card-body">
        <table class="table">
            <thead>
                <tr>
                    <th>Ключ</th>
                    <th>Путь</th>
                    <th>Статус</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($paths as $key => $path): ?>
                <tr>
                    <td><code><?= htmlspecialchars($key) ?></code></td>
                    <td><code><?= htmlspecialchars($path) ?></code></td>
                    <td>
                        <?php if (is_dir($path)): ?>
                            <?php if (is_writable($path)): ?>
                                <span class="badge badge-success">OK</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Readonly</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-danger">Не существует</span>
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
        <h3>URL пути</h3>
    </div>
    <div class="card-body">
        <table class="table">
            <tbody>
                <tr>
                    <td><strong>Base URL</strong></td>
                    <td><code><?= htmlspecialchars($base_url ?: '(пусто)') ?></code></td>
                </tr>
                <tr>
                    <td><strong>Admin URL</strong></td>
                    <td><code><?= htmlspecialchars($admin_url) ?></code></td>
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
