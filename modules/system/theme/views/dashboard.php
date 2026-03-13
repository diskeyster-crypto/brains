<?php
/**
 * Theme Manager Dashboard View (RU)
 * 
 * Lists all available themes with activate/edit/delete actions.
 * 
 * @var array $themes All available themes
 * @var string $activeThemeId Currently active theme ID
 * @var array $config Theme configuration
 */

use Core\System\System;

$baseUrl = System::web('admin/theme');
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-palette me-2"></i>
            Менеджер тем
        </h1>
        <div>
            <a href="<?= htmlspecialchars($baseUrl . '/create') ?>" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i> Новая тема
            </a>
            <a href="<?= htmlspecialchars($baseUrl . '/import') ?>" class="btn btn-outline-secondary ms-2">
                <i class="bi bi-upload me-1"></i> Импорт
            </a>
        </div>
    </div>

    <?php if (isset($_GET['activated'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            Тема успешно активирована!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            Тема успешно удалена!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['imported'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            Тема "<?= htmlspecialchars($_GET['imported']) ?>" успешно импортирована!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i>
            Операция не выполнена. Пожалуйста, попробуйте снова.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Статистика -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Всего тем</h6>
                    <h3 class="mb-0"><?= count($themes) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Активная тема</h6>
                    <h3 class="mb-0"><?= htmlspecialchars($themes[$activeThemeId]['name'] ?? $activeThemeId) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Режим макета</h6>
                    <h3 class="mb-0"><?= htmlspecialchars($config['layout_mode'] ?? '2 колонки') ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="text-muted mb-1">Пользовательских тем</h6>
                    <h3 class="mb-0"><?= count(array_filter($themes, fn($t) => empty($t['system']))) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Быстрые действия -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Быстрые действия</h5>
        </div>
        <div class="card-body">
            <a href="<?= htmlspecialchars($baseUrl . '/layouts') ?>" class="btn btn-outline-primary me-2">
                <i class="bi bi-layout-split me-1"></i> Настройки макета
            </a>
            <a href="<?= htmlspecialchars($baseUrl . '/preview') ?>" class="btn btn-outline-secondary me-2">
                <i class="bi bi-eye me-1"></i> Просмотр активной темы
            </a>
        </div>
    </div>

    <!-- Сетка тем -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Доступные темы</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <?php foreach ($themes as $id => $theme): ?>
                    <div class="col-md-4 mb-4">
                        <div class="card h-100 <?= $id === $activeThemeId ? 'border-primary' : '' ?>">
                            <!-- Превью темы -->
                            <div class="card-img-top p-3" style="height: 150px; background: linear-gradient(135deg, <?= htmlspecialchars($theme['colors']['bg'] ?? '#f8f9fa') ?>, <?= htmlspecialchars($theme['colors']['sidebar'] ?? '#2c3e50') ?>);">
                                <div class="d-flex h-100">
                                    <div style="width: 25%; background: <?= htmlspecialchars($theme['colors']['sidebar'] ?? '#2c3e50') ?>; border-radius: 4px;"></div>
                                    <div class="ms-2 flex-grow-1">
                                        <div class="mb-2" style="height: 20px; background: <?= htmlspecialchars($theme['colors']['card'] ?? '#fff') ?>; border-radius: 4px;"></div>
                                        <div class="d-flex gap-1">
                                            <div style="width: 30px; height: 30px; background: <?= htmlspecialchars($theme['colors']['accent'] ?? '#3498db') ?>; border-radius: 4px;"></div>
                                            <div style="width: 30px; height: 30px; background: <?= htmlspecialchars($theme['colors']['success'] ?? '#28a745') ?>; border-radius: 4px;"></div>
                                            <div style="width: 30px; height: 30px; background: <?= htmlspecialchars($theme['colors']['danger'] ?? '#dc3545') ?>; border-radius: 4px;"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h5 class="card-title mb-0">
                                        <?= htmlspecialchars($theme['name']) ?>
                                        <?php if ($id === $activeThemeId): ?>
                                            <span class="badge bg-primary ms-2">Активна</span>
                                        <?php endif; ?>
                                    </h5>
                                    <?php if (!empty($theme['system'])): ?>
                                        <span class="badge bg-secondary">Системная</span>
                                    <?php endif; ?>
                                </div>
                                <p class="card-text text-muted small mb-2">
                                    <i class="bi bi-person me-1"></i> <?= htmlspecialchars($theme['author'] ?? 'Неизвестно') ?>
                                    &nbsp;|&nbsp;
                                    <i class="bi bi-tag me-1"></i> v<?= htmlspecialchars($theme['version'] ?? '1.0') ?>
                                </p>
                                <p class="card-text text-muted small">
                                    <i class="bi bi-layout-text-window me-1"></i> <?= htmlspecialchars($theme['layout'] ?? '2 колонки') ?>
                                </p>
                            </div>
                            
                            <div class="card-footer bg-transparent border-top-0">
                                <div class="btn-group w-100">
                                    <?php if ($id !== $activeThemeId): ?>
                                        <a href="<?= htmlspecialchars($baseUrl . '/activate?theme=' . urlencode($id)) ?>" 
                                           class="btn btn-sm btn-outline-success">
                                            <i class="bi bi-check-lg"></i> Активировать
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-success" disabled>
                                            <i class="bi bi-check-circle"></i> Активна
                                        </button>
                                    <?php endif; ?>
                                    
                                    <a href="<?= htmlspecialchars($baseUrl . '/editor/' . urlencode($id)) ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil"></i> Изменить
                                    </a>
                                    
                                    <a href="<?= htmlspecialchars($baseUrl . '/preview?theme=' . urlencode($id)) ?>" 
                                       class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    
                                    <a href="<?= htmlspecialchars($baseUrl . '/export?theme=' . urlencode($id)) ?>" 
                                       class="btn btn-sm btn-outline-info">
                                        <i class="bi bi-download"></i>
                                    </a>
                                    
                                    <?php if (empty($theme['system']) && $id !== $activeThemeId): ?>
                                        <a href="<?= htmlspecialchars($baseUrl . '/delete?theme=' . urlencode($id)) ?>" 
                                           class="btn btn-sm btn-outline-danger"
                                           onclick="return confirm('Вы уверены, что хотите удалить эту тему?');">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
