<?php

declare(strict_types=1);

namespace Modules\System\Pages;

use Core\System\System;
use Core\Storage\StorageManager;

/**
 * Pages Registry
 * 
 * Admin routes registry for Tredercopis.
 * NOT CMS content - only admin routes management.
 */
final class PagesRegistry
{
    private const STORAGE_KEY = 'system/pages_registry';
    
    private static ?self $instance = null;
    private array $pages = [];
    private bool $loaded = false;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $storage = StorageManager::instance();
        $stored = $storage->get(self::STORAGE_KEY);
        
        if ($stored) {
            $this->pages = $stored;
        } else {
            // Initialize with system defaults
            $this->pages = $this->getDefaultPages();
            $this->save();
        }
        
        $this->loaded = true;
    }

    private function save(): void
    {
        $storage = StorageManager::instance();
        $storage->set(self::STORAGE_KEY, $this->pages);
    }

    /**
     * Get all registered pages
     */
    public function all(): array
    {
        $this->load();
        return $this->pages;
    }

    /**
     * Get visible pages (for menu)
     */
    public function visible(): array
    {
        $this->load();
        return array_filter($this->pages, fn($p) => $p['visible'] ?? true);
    }

    /**
     * Get page by ID
     */
    public function get(int $id): ?array
    {
        $this->load();
        return $this->pages[$id] ?? null;
    }

    /**
     * Get page by route
     */
    public function getByRoute(string $route): ?array
    {
        $this->load();
        foreach ($this->pages as $page) {
            if ($page['route'] === $route) {
                return $page;
            }
        }
        return null;
    }

    /**
     * Register a new page
     */
    public function register(array $page): int
    {
        $this->load();
        
        $id = max(array_keys($this->pages) ?: [0]) + 1;
        
        $this->pages[$id] = [
            'id' => $id,
            'title' => $page['title'] ?? 'Untitled',
            'route' => $page['route'] ?? '/admin/unknown',
            'type' => $page['type'] ?? 'custom',
            'roles' => $page['roles'] ?? ['admin'],
            'visible' => $page['visible'] ?? true,
            'icon' => $page['icon'] ?? 'bi-circle',
            'order' => $page['order'] ?? 100,
            'locked' => $page['locked'] ?? false,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        
        $this->save();
        return $id;
    }

    /**
     * Update page visibility
     */
    public function setVisible(int $id, bool $visible): void
    {
        $this->load();
        
        if (!isset($this->pages[$id])) {
            throw new \InvalidArgumentException("Page not found: {$id}");
        }
        
        if ($this->pages[$id]['locked'] ?? false) {
            throw new \RuntimeException("Cannot modify locked page: {$id}");
        }
        
        $this->pages[$id]['visible'] = $visible;
        $this->save();
    }

    /**
     * Update page roles
     */
    public function setRoles(int $id, array $roles): void
    {
        $this->load();
        
        if (!isset($this->pages[$id])) {
            throw new \InvalidArgumentException("Page not found: {$id}");
        }
        
        $this->pages[$id]['roles'] = $roles;
        $this->save();
    }

    /**
     * Lock a page (prevent visibility changes)
     */
    public function lock(int $id): void
    {
        $this->load();
        
        if (!isset($this->pages[$id])) {
            throw new \InvalidArgumentException("Page not found: {$id}");
        }
        
        $this->pages[$id]['locked'] = true;
        $this->save();
    }

    /**
     * Unlock a page
     */
    public function unlock(int $id): void
    {
        $this->load();
        
        if (!isset($this->pages[$id])) {
            throw new \InvalidArgumentException("Page not found: {$id}");
        }
        
        $this->pages[$id]['locked'] = false;
        $this->save();
    }

    /**
     * Count pages by type
     */
    public function countByType(string $type): int
    {
        $this->load();
        return count(array_filter($this->pages, fn($p) => ($p['type'] ?? '') === $type));
    }

    /**
     * Count total pages
     */
    public function count(): int
    {
        $this->load();
        return count($this->pages);
    }

    /**
     * Delete a page (only custom pages)
     */
    public function delete(int $id): void
    {
        $this->load();
        
        if (!isset($this->pages[$id])) {
            throw new \InvalidArgumentException("Page not found: {$id}");
        }
        
        $page = $this->pages[$id];
        
        if (($page['type'] ?? '') === 'system') {
            throw new \RuntimeException("Cannot delete system page: {$id}");
        }
        
        unset($this->pages[$id]);
        $this->save();
    }

    /**
     * Get default system pages
     */
    private function getDefaultPages(): array
    {
        return [
            1 => [
                'id' => 1,
                'title' => 'Dashboard',
                'route' => '/admin',
                'type' => 'system',
                'roles' => ['admin'],
                'visible' => true,
                'icon' => 'bi-speedometer2',
                'order' => 1,
                'locked' => true,
            ],
            2 => [
                'id' => 2,
                'title' => 'Система',
                'route' => '/admin/system',
                'type' => 'system',
                'roles' => ['admin'],
                'visible' => true,
                'icon' => 'bi-gear',
                'order' => 2,
                'locked' => true,
            ],
            3 => [
                'id' => 3,
                'title' => 'GitHub Center',
                'route' => '/admin/github',
                'type' => 'module',
                'roles' => ['admin'],
                'visible' => true,
                'icon' => 'bi-github',
                'order' => 10,
                'locked' => false,
            ],
            4 => [
                'id' => 4,
                'title' => 'Темы',
                'route' => '/admin/theme',
                'type' => 'module',
                'roles' => ['admin'],
                'visible' => true,
                'icon' => 'bi-palette',
                'order' => 20,
                'locked' => false,
            ],
            5 => [
                'id' => 5,
                'title' => 'Модули',
                'route' => '/admin/modules',
                'type' => 'system',
                'roles' => ['admin'],
                'visible' => true,
                'icon' => 'bi-puzzle',
                'order' => 30,
                'locked' => false,
            ],
            6 => [
                'id' => 6,
                'title' => 'Cron',
                'route' => '/admin/cron',
                'type' => 'system',
                'roles' => ['admin'],
                'visible' => true,
                'icon' => 'bi-clock-history',
                'order' => 40,
                'locked' => false,
            ],
            7 => [
                'id' => 7,
                'title' => 'Storage',
                'route' => '/admin/storage',
                'type' => 'system',
                'roles' => ['admin'],
                'visible' => true,
                'icon' => 'bi-database',
                'order' => 50,
                'locked' => false,
            ],
            8 => [
                'id' => 8,
                'title' => 'Логи',
                'route' => '/admin/logs',
                'type' => 'system',
                'roles' => ['admin'],
                'visible' => true,
                'icon' => 'bi-journal-text',
                'order' => 60,
                'locked' => false,
            ],
            9 => [
                'id' => 9,
                'title' => 'Страницы',
                'route' => '/admin/pages',
                'type' => 'system',
                'roles' => ['admin'],
                'visible' => true,
                'icon' => 'bi-file-earmark-text',
                'order' => 70,
                'locked' => true,
            ],
            10 => [
                'id' => 10,
                'title' => 'Парсеры',
                'route' => '/admin/parser',
                'type' => 'category',
                'roles' => ['admin'],
                'visible' => true,
                'icon' => 'bi-cpu',
                'order' => 35,
                'locked' => false,
            ],
            11 => [
                'id' => 11,
                'title' => 'Сигналы',
                'route' => '/admin/signal',
                'type' => 'category',
                'roles' => ['admin'],
                'visible' => true,
                'icon' => 'bi-lightning-charge',
                'order' => 36,
                'locked' => false,
            ],
            12 => [
                'id' => 12,
                'title' => 'Торговля',
                'route' => '/admin/trading',
                'type' => 'category',
                'roles' => ['admin'],
                'visible' => true,
                'icon' => 'bi-graph-up-arrow',
                'order' => 37,
                'locked' => false,
            ],
            13 => [
                'id' => 13,
                'title' => 'Brain',
                'route' => '/admin/brain',
                'type' => 'module',
                'roles' => ['admin'],
                'visible' => false,
                'icon' => 'bi-cpu',
                'order' => 5,
                'locked' => false,
            ],
            14 => [
                'id' => 14,
                'title' => 'Copytrading',
                'route' => '/admin/copytrading',
                'type' => 'module',
                'roles' => ['admin'],
                'visible' => true,
                'icon' => 'bi-people',
                'order' => 38,
                'locked' => false,
            ],
            15 => [
                'id' => 15,
                'title' => 'Оперативный центр',
                'route' => '/admin/dashboard',
                'type' => 'module',
                'roles' => ['admin'],
                'visible' => true,
                'icon' => 'bi-layout-text-sidebar-reverse',
                'order' => 3,
                'locked' => false,
            ],
        ];
    }
}
