<?php

declare(strict_types=1);

namespace Modules\System\Theme;

use Core\Storage\StorageManager;
use Core\System\System;

/**
 * Theme Module - Main Orchestrator
 * 
 * Central class for theme management operations.
 * Delegates to specialized services for specific operations.
 * 
 * @package Modules\System\Theme
 */
class ThemeModule
{
    private static ?self $instance = null;
    
    private ThemeLoader $loader;
    private ThemeStorage $storage;
    private ThemeApply $apply;
    private ThemeBuilder $builder;
    private ThemeExport $export;
    
    /**
     * Get singleton instance
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct()
    {
        // Services are loaded lazily
    }
    
    /**
     * Get ThemeLoader service
     */
    public function loader(): ThemeLoader
    {
        if (!isset($this->loader)) {
            require_once __DIR__ . '/service/theme.loader.php';
            $this->loader = ThemeLoader::instance();
        }
        return $this->loader;
    }
    
    /**
     * Get ThemeStorage service
     */
    public function storage(): ThemeStorage
    {
        if (!isset($this->storage)) {
            require_once __DIR__ . '/service/theme.storage.php';
            $this->storage = ThemeStorage::instance();
        }
        return $this->storage;
    }
    
    /**
     * Get ThemeApply service
     */
    public function apply(): ThemeApply
    {
        if (!isset($this->apply)) {
            require_once __DIR__ . '/service/theme.apply.php';
            $this->apply = ThemeApply::instance();
        }
        return $this->apply;
    }
    
    /**
     * Get ThemeBuilder service
     */
    public function builder(): ThemeBuilder
    {
        if (!isset($this->builder)) {
            require_once __DIR__ . '/service/theme.builder.php';
            $this->builder = ThemeBuilder::instance();
        }
        return $this->builder;
    }
    
    /**
     * Get ThemeExport service
     */
    public function export(): ThemeExport
    {
        if (!isset($this->export)) {
            require_once __DIR__ . '/service/theme.export.php';
            $this->export = ThemeExport::instance();
        }
        return $this->export;
    }
    
    /**
     * Get active theme ID
     */
    public function getActiveThemeId(): string
    {
        return $this->storage()->getActiveTheme();
    }
    
    /**
     * Get active theme data
     */
    public function getActiveTheme(): ?array
    {
        $themeId = $this->getActiveThemeId();
        return $this->loader()->load($themeId);
    }
    
    /**
     * List all available themes
     */
    public function listThemes(): array
    {
        return $this->loader()->listAll();
    }
    
    /**
     * Activate a theme by ID
     */
    public function activateTheme(string $themeId): bool
    {
        if (!$this->loader()->exists($themeId)) {
            return false;
        }
        return $this->storage()->setActiveTheme($themeId);
    }
    
    /**
     * Create a new theme
     */
    public function createTheme(array $data): ?string
    {
        return $this->builder()->create($data);
    }
    
    /**
     * Update existing theme
     */
    public function updateTheme(string $themeId, array $data): bool
    {
        return $this->builder()->update($themeId, $data);
    }
    
    /**
     * Delete a theme
     */
    public function deleteTheme(string $themeId): bool
    {
        // Cannot delete active theme
        if ($themeId === $this->getActiveThemeId()) {
            return false;
        }
        // Cannot delete system themes
        if (in_array($themeId, ['system-light', 'system-dark'])) {
            return false;
        }
        return $this->storage()->delete($themeId);
    }
    
    /**
     * Export theme to ZIP
     */
    public function exportTheme(string $themeId): ?string
    {
        return $this->export()->toZip($themeId);
    }
    
    /**
     * Import theme from ZIP
     */
    public function importTheme(string $zipPath): ?string
    {
        return $this->export()->fromZip($zipPath);
    }
    
    /**
     * Get CSS for layout
     */
    public function getLayoutCss(): string
    {
        return $this->apply()->getLayoutCss();
    }
    
    /**
     * Get CSS for active theme
     */
    public function getThemeCss(): string
    {
        return $this->apply()->getThemeCss();
    }
}
