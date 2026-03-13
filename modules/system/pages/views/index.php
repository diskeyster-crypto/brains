<?php
/**
 * Pages Manager - Index
 * 
 * Admin routes registry management.
 */

use Core\System\System;

// Variables from controller:
// $pages, $total, $system_count, $module_count, $custom_count
?>

<!-- Stats Row -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Всего страниц</div>
        <div class="stat-value"><?= $total ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Системные</div>
        <div class="stat-value"><?= $system_count ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Модульные</div>
        <div class="stat-value"><?= $module_count ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Пользовательские</div>
        <div class="stat-value"><?= $custom_count ?></div>
    </div>
</div>

<!-- Add Page Form -->
<div class="card" id="add-page-card" style="display: none;">
    <div class="card-header">
        <h3>Добавить страницу</h3>
    </div>
    <div class="card-body">
        <form id="add-page-form">
            <div class="form-group">
                <label>Название:</label>
                <input type="text" name="title" class="form-control" placeholder="Моя страница" required>
            </div>
            <div class="form-group">
                <label>Маршрут:</label>
                <input type="text" name="route" class="form-control" placeholder="/admin/my-page" required>
                <small class="text-muted">Должен начинаться с /admin/</small>
            </div>
            <div class="form-group">
                <label>Иконка (Bootstrap Icons):</label>
                <input type="text" name="icon" class="form-control" value="bi-circle" placeholder="bi-circle">
            </div>
            <div class="form-group">
                <label>Порядок сортировки:</label>
                <input type="number" name="order" class="form-control" value="100">
            </div>
            <div class="form-group">
                <button type="submit" class="btn btn-success">Добавить</button>
                <button type="button" class="btn btn-secondary" onclick="toggleAddForm()">Отмена</button>
            </div>
        </form>
    </div>
</div>

<!-- Pages Table -->
<div class="card">
    <div class="card-header">
        <h3>Реестр страниц</h3>
        <button class="btn btn-success btn-sm" onclick="toggleAddForm()">+ Добавить страницу</button>
    </div>
    <div class="card-body">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Название</th>
                    <th>Маршрут</th>
                    <th>Тип</th>
                    <th>Роли</th>
                    <th>Видимость</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pages as $page): ?>
                <tr data-id="<?= $page['id'] ?>">
                    <td><?= $page['id'] ?></td>
                    <td>
                        <i class="bi <?= htmlspecialchars($page['icon'] ?? 'bi-circle') ?>"></i>
                        <?= htmlspecialchars($page['title']) ?>
                    </td>
                    <td>
                        <code><?= htmlspecialchars($page['route']) ?></code>
                    </td>
                    <td>
                        <?php
                        $typeClass = match($page['type'] ?? 'custom') {
                            'system' => 'badge-danger',
                            'module' => 'badge-primary',
                            default => 'badge-secondary'
                        };
                        $typeLabel = match($page['type'] ?? 'custom') {
                            'system' => 'Системная',
                            'module' => 'Модуль',
                            default => 'Пользов.'
                        };
                        ?>
                        <span class="badge <?= $typeClass ?>"><?= $typeLabel ?></span>
                    </td>
                    <td>
                        <?php foreach ($page['roles'] ?? [] as $role): ?>
                            <span class="badge badge-secondary"><?= htmlspecialchars($role) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php if ($page['visible'] ?? true): ?>
                            <span class="badge badge-success">Видима</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Скрыта</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!($page['locked'] ?? false)): ?>
                            <?php if ($page['visible'] ?? true): ?>
                                <button onclick="toggleVisibility(<?= $page['id'] ?>, false)" class="btn btn-warning btn-sm">
                                    Скрыть
                                </button>
                            <?php else: ?>
                                <button onclick="toggleVisibility(<?= $page['id'] ?>, true)" class="btn btn-success btn-sm">
                                    Показать
                                </button>
                            <?php endif; ?>
                            <button onclick="lockPage(<?= $page['id'] ?>)" class="btn btn-secondary btn-sm" title="Заблокировать">
                                <i class="bi bi-lock"></i>
                            </button>
                            <?php if (($page['type'] ?? 'custom') === 'custom'): ?>
                                <button onclick="deletePage(<?= $page['id'] ?>)" class="btn btn-danger btn-sm" title="Удалить">
                                    <i class="bi bi-trash"></i>
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-danger">
                                <i class="bi bi-lock-fill"></i> Заблокирована
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Info Card -->
<div class="card">
    <div class="card-header">
        <h3>О реестре страниц</h3>
    </div>
    <div class="card-body">
        <p><strong>Pages Registry</strong> — централизованный реестр административных маршрутов Tredercopis.</p>
        <ul>
            <li><strong>Системные страницы</strong> — страницы ядра, не могут быть удалены</li>
            <li><strong>Модульные страницы</strong> — страницы модулей, могут быть скрыты</li>
            <li><strong>Пользовательские</strong> — созданные вручную страницы (можно удалить)</li>
        </ul>
        <p class="text-muted">Заблокированные страницы нельзя скрыть. Скрытые страницы не отображаются в боковом меню.</p>
    </div>
</div>

<script>
function toggleAddForm() {
    const card = document.getElementById('add-page-card');
    card.style.display = card.style.display === 'none' ? 'block' : 'none';
}

document.getElementById('add-page-form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('<?= System::web('admin/pages/add') ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Ошибка: ' + data.error);
        }
    });
});

function toggleVisibility(id, visible) {
    fetch('<?= System::web('admin/pages/toggle') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}&visible=${visible}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Ошибка: ' + data.error);
        }
    });
}

function lockPage(id) {
    if (!confirm('Заблокировать страницу?')) return;
    
    fetch('<?= System::web('admin/pages/lock') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}&lock=true`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Ошибка: ' + data.error);
        }
    });
}

function deletePage(id) {
    if (!confirm('Удалить страницу? Это действие нельзя отменить.')) return;
    
    fetch('<?= System::web('admin/pages/delete') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Ошибка: ' + data.error);
        }
    });
}
</script>
