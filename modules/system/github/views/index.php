<?php
/**
 * GitHub Center - Dashboard View (RU)
 * Beautiful cards layout with all functions
 * 
 * @var string $title
 * @var array $settings
 * @var bool $is_configured
 * @var array $status
 * @var string $csrf_token
 */
?>
<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col">
            <h2 class="mb-1">
                <i class="bi bi-github me-2"></i>
                GitHub Center
            </h2>
            <p class="text-muted mb-0">Управление обновлениями и резервными копиями</p>
        </div>
    </div>
    
    <?php if (!$is_configured): ?>
    <!-- Не настроено -->
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-gear-wide-connected display-1 text-muted mb-4"></i>
                    <h3>Требуется настройка</h3>
                    <p class="text-muted mb-4">
                        Интеграция с GitHub не настроена.<br>
                        Добавьте учётные данные для включения всех функций.
                    </p>
                    <a href="<?= \Core\System\System::web('admin/github/settings') ?>" class="btn btn-primary btn-lg">
                        <i class="bi bi-gear me-2"></i>
                        Настроить сейчас
                    </a>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-info-circle me-2"></i>
                    Что такое GitHub Center?
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i> Обновление системы из GitHub</li>
                        <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i> Загрузка файлов в репозиторий</li>
                        <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i> Резервное копирование</li>
                        <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i> Откат при ошибках</li>
                        <li><i class="bi bi-check-circle text-success me-2"></i> Классификация файлов</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <?php else: ?>
    
    <!-- Статус статистика -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-label">Соединение</div>
                <div class="stat-value">
                    <?php if (isset($status['connected']) && $status['connected']): ?>
                    <span class="text-success"><i class="bi bi-check-circle me-1"></i> OK</span>
                    <?php else: ?>
                    <span class="text-danger"><i class="bi bi-x-circle me-1"></i> Ошибка</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-label">Обновления</div>
                <div class="stat-value">
                    <?php if (isset($status['has_updates']) && $status['has_updates']): ?>
                    <span class="text-warning"><i class="bi bi-arrow-up-circle me-1"></i> Есть</span>
                    <?php else: ?>
                    <span class="text-success"><i class="bi bi-check2 me-1"></i> Актуально</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-label">Последнее обновление</div>
                <div class="stat-value">
                    <?= $settings['last_update'] ? date('d.m.Y', strtotime($settings['last_update'])) : '—' ?>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-label">Последний бэкап</div>
                <div class="stat-value">
                    <?= $settings['last_backup'] ? date('d.m.Y', strtotime($settings['last_backup'])) : '—' ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Основные карточки функций -->
    <div class="row mb-4">
        <!-- Обновления -->
        <div class="col-md-6 col-lg-3 mb-4">
            <div class="card h-100 feature-card">
                <div class="card-body text-center py-4">
                    <div class="feature-icon mb-3">
                        <i class="bi bi-cloud-download display-4 text-primary"></i>
                    </div>
                    <h5 class="card-title">Обновления</h5>
                    <p class="card-text text-muted small">
                        Проверить и установить обновления системы из GitHub
                    </p>
                    <?php if (isset($status['has_updates']) && $status['has_updates']): ?>
                    <span class="badge bg-warning text-dark mb-2">Доступны обновления</span>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-transparent border-0 text-center pb-4">
                    <a href="<?= \Core\System\System::web('admin/github/fetch') ?>" class="btn btn-primary">
                        <i class="bi bi-arrow-down-circle me-1"></i>
                        Проверить
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Загрузка файлов -->
        <div class="col-md-6 col-lg-3 mb-4">
            <div class="card h-100 feature-card">
                <div class="card-body text-center py-4">
                    <div class="feature-icon mb-3">
                        <i class="bi bi-cloud-upload display-4 text-success"></i>
                    </div>
                    <h5 class="card-title">Загрузка файлов</h5>
                    <p class="card-text text-muted small">
                        Загрузить файлы с сервера в репозиторий GitHub
                    </p>
                </div>
                <div class="card-footer bg-transparent border-0 text-center pb-4">
                    <a href="<?= \Core\System\System::web('admin/github/upload') ?>" class="btn btn-success">
                        <i class="bi bi-upload me-1"></i>
                        Загрузить
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Резервные копии -->
        <div class="col-md-6 col-lg-3 mb-4">
            <div class="card h-100 feature-card">
                <div class="card-body text-center py-4">
                    <div class="feature-icon mb-3">
                        <i class="bi bi-archive display-4 text-info"></i>
                    </div>
                    <h5 class="card-title">Резервные копии</h5>
                    <p class="card-text text-muted small">
                        Создание и управление резервными копиями системы
                    </p>
                </div>
                <div class="card-footer bg-transparent border-0 text-center pb-4">
                    <a href="<?= \Core\System\System::web('admin/github/backups') ?>" class="btn btn-info">
                        <i class="bi bi-archive me-1"></i>
                        Управление
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Настройки -->
        <div class="col-md-6 col-lg-3 mb-4">
            <div class="card h-100 feature-card">
                <div class="card-body text-center py-4">
                    <div class="feature-icon mb-3">
                        <i class="bi bi-gear display-4 text-secondary"></i>
                    </div>
                    <h5 class="card-title">Настройки</h5>
                    <p class="card-text text-muted small">
                        Учётные данные GitHub и параметры репозитория
                    </p>
                </div>
                <div class="card-footer bg-transparent border-0 text-center pb-4">
                    <a href="<?= \Core\System\System::web('admin/github/settings') ?>" class="btn btn-secondary">
                        <i class="bi bi-gear me-1"></i>
                        Настроить
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Информация о репозитории -->
    <?php if (isset($status['error']) || (isset($status['connected']) && !$status['connected'])): ?>
    <div class="row mb-4">
        <div class="col-lg-8">
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Ошибка соединения с GitHub
                </div>
                <div class="card-body">
                    <p class="mb-0 text-danger">
                        <?= htmlspecialchars($status['error'] ?? 'GitHub API недоступен') ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (isset($status['repo'])): ?>
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-folder me-2"></i>
                    Репозиторий: <?= htmlspecialchars($settings['update_repo']) ?>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm mb-0">
                                <tr>
                                    <th style="width: 140px;">Ветка:</th>
                                    <td><code><?= htmlspecialchars($settings['branch']) ?></code></td>
                                </tr>
                                <tr>
                                    <th>Звёзды:</th>
                                    <td><i class="bi bi-star-fill text-warning me-1"></i><?= number_format($status['repo']['stargazers_count'] ?? 0) ?></td>
                                </tr>
                                <tr>
                                    <th>Видимость:</th>
                                    <td>
                                        <?php if ($status['repo']['private'] ?? false): ?>
                                        <span class="badge bg-secondary"><i class="bi bi-lock me-1"></i>Приватный</span>
                                        <?php else: ?>
                                        <span class="badge bg-success"><i class="bi bi-globe me-1"></i>Публичный</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <?php if (isset($status['latest_commit'])): ?>
                            <div class="mb-2">
                                <strong>Последний коммит:</strong>
                            </div>
                            <div class="d-flex align-items-start">
                                <code class="me-2"><?= substr($status['latest_commit']['sha'] ?? '', 0, 7) ?></code>
                                <div>
                                    <p class="text-muted small mb-1">
                                        <?= htmlspecialchars(mb_strimwidth($status['latest_commit']['commit']['message'] ?? '', 0, 80, '...')) ?>
                                    </p>
                                    <small class="text-muted">
                                        <i class="bi bi-person me-1"></i>
                                        <?= htmlspecialchars($status['latest_commit']['commit']['author']['name'] ?? 'Неизвестно') ?>
                                    </small>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-lightning me-2"></i>
                    Быстрые действия
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="<?= \Core\System\System::web('admin/github/test') ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-plug me-1"></i> Проверить соединение
                        </a>
                        <a href="<?= \Core\System\System::web('admin/github/classify') ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-funnel me-1"></i> Классификация файлов
                        </a>
                        <?php if (isset($status['has_updates']) && $status['has_updates']): ?>
                        <a href="<?= \Core\System\System::web('admin/github/update') ?>" class="btn btn-warning btn-sm">
                            <i class="bi bi-download me-1"></i> Обновить сейчас
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php endif; ?>
</div>

<!-- Inline CSS is FORBIDDEN - all styles are in admin/assets/css/ui.css -->
