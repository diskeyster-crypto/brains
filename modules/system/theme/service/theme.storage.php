<?php

declare(strict_types=1);

namespace Modules\System\Theme;

use Core\Storage\StorageManager;
use Core\System\System;

/**
 * Theme Storage Service
 * 
 * Handles persistence of theme configuration and active theme selection.
 * 
 * @package Modules\System\Theme
 */
class ThemeStorage
{
    private static ?self $instance = null;
    
    private string $themesPath;
    private string $storageKey = 'system/theme';
    
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct()
    {
        $this->themesPath = System::path('root') . '/storage/system/themes';
    }
    
    /**
     * Get active theme ID
     */
    public function getActiveTheme(): string
    {
        $config = StorageManager::instance()->get($this->storageKey);
        return $config['active_theme'] ?? 'system-light';
    }
    
    /**
     * Set active theme
     */
    public function setActiveTheme(string $themeId): bool
    {
        $config = StorageManager::instance()->get($this->storageKey) ?? [];
        $config['active_theme'] = $themeId;
        $config['updated_at'] = date('Y-m-d H:i:s');
        
        StorageManager::instance()->set($this->storageKey, $config);
        return true;
    }
    
    /**
     * Get layout mode
     */
    public function getLayoutMode(): string
    {
        $config = StorageManager::instance()->get($this->storageKey);
        return $config['layout_mode'] ?? '2-columns';
    }
    
    /**
     * Set layout mode
     */
    public function setLayoutMode(string $mode): bool
    {
        if (!in_array($mode, ['2-columns', '3-columns'])) {
            return false;
        }
        
        $config = StorageManager::instance()->get($this->storageKey) ?? [];
        $config['layout_mode'] = $mode;
        $config['updated_at'] = date('Y-m-d H:i:s');
        
        StorageManager::instance()->set($this->storageKey, $config);
        return true;
    }
    
    /**
     * Save theme data to filesystem
     */
    public function saveTheme(string $themeId, array $data): bool
    {
        $themePath = $this->themesPath . '/' . $themeId;
        
        if (!is_dir($themePath)) {
            mkdir($themePath, 0755, true);
        }
        
        // Save theme.json
        $themeJson = [
            'id' => $themeId,
            'name' => $data['name'] ?? $themeId,
            'author' => $data['author'] ?? 'Custom',
            'version' => $data['version'] ?? '1.0',
            'layout' => $data['layout'] ?? '2-columns',
            'css_class' => $data['css_class'] ?? 'theme-' . $themeId,
            'system' => false,
            'colors' => $data['colors'] ?? []
        ];
        
        file_put_contents(
            $themePath . '/theme.json',
            json_encode($themeJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        
        // Save CSS files if provided
        if (!empty($data['base_css'])) {
            file_put_contents($themePath . '/base.css', $data['base_css']);
        }
        
        if (!empty($data['layout_css'])) {
            file_put_contents($themePath . '/layout.css', $data['layout_css']);
        }
        
        if (!empty($data['components_css'])) {
            file_put_contents($themePath . '/components.css', $data['components_css']);
        }
        
        return true;
    }
    
    /**
     * Delete a theme
     */
    public function delete(string $themeId): bool
    {
        $themePath = $this->themesPath . '/' . $themeId;
        
        if (!is_dir($themePath)) {
            return false;
        }
        
        // Don't allow deleting system themes
        $themeJson = $themePath . '/theme.json';
        if (file_exists($themeJson)) {
            $theme = json_decode(file_get_contents($themeJson), true);
            if (!empty($theme['system'])) {
                return false;
            }
        }
        
        // Recursively delete directory
        $this->deleteDirectory($themePath);
        return true;
    }
    
    /**
     * Recursively delete directory
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
    
    /**
     * Get full storage config
     */
    public function getConfig(): array
    {
        return StorageManager::instance()->get($this->storageKey) ?? [
            'active_theme' => 'system-light',
            'layout_mode' => '2-columns',
            'updated_at' => null
        ];
    }
}
