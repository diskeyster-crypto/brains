<?php

declare(strict_types=1);

namespace Modules\System\Theme;

use Core\System\System;

/**
 * Theme Loader Service
 * 
 * Responsible for discovering and loading theme configurations.
 * 
 * @package Modules\System\Theme
 */
class ThemeLoader
{
    private static ?self $instance = null;
    
    private string $themesPath;
    private array $loadedThemes = [];
    
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
        $this->ensureSystemThemesExist();
    }
    
    /**
     * Get themes directory path
     */
    public function getThemesPath(): string
    {
        return $this->themesPath;
    }
    
    /**
     * Load theme by ID
     */
    public function load(string $themeId): ?array
    {
        if (isset($this->loadedThemes[$themeId])) {
            return $this->loadedThemes[$themeId];
        }
        
        $themePath = $this->themesPath . '/' . $themeId;
        $manifestPath = $themePath . '/theme.json';
        
        if (!file_exists($manifestPath)) {
            return null;
        }
        
        $json = file_get_contents($manifestPath);
        $theme = json_decode($json, true);
        
        if (!$theme || !is_array($theme)) {
            return null;
        }
        
        // Add computed paths
        $theme['path'] = $themePath;
        $theme['css_files'] = $this->getCssFiles($themePath);
        
        $this->loadedThemes[$themeId] = $theme;
        return $theme;
    }
    
    /**
     * Check if theme exists
     */
    public function exists(string $themeId): bool
    {
        return file_exists($this->themesPath . '/' . $themeId . '/theme.json');
    }
    
    /**
     * List all available themes
     */
    public function listAll(): array
    {
        $themes = [];
        
        if (!is_dir($this->themesPath)) {
            return $themes;
        }
        
        $dirs = scandir($this->themesPath);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            
            $theme = $this->load($dir);
            if ($theme) {
                $themes[$dir] = $theme;
            }
        }
        
        return $themes;
    }
    
    /**
     * Get CSS files for a theme
     */
    private function getCssFiles(string $themePath): array
    {
        $cssFiles = [];
        $expectedFiles = ['base.css', 'layout.css', 'components.css'];
        
        foreach ($expectedFiles as $file) {
            $path = $themePath . '/' . $file;
            if (file_exists($path)) {
                $cssFiles[] = $file;
            }
        }
        
        return $cssFiles;
    }
    
    /**
     * Ensure system themes exist
     */
    private function ensureSystemThemesExist(): void
    {
        if (!is_dir($this->themesPath)) {
            mkdir($this->themesPath, 0755, true);
        }
        
        // Create system-light theme if missing
        $lightPath = $this->themesPath . '/system-light';
        if (!file_exists($lightPath . '/theme.json')) {
            $this->createSystemLightTheme($lightPath);
        }
        
        // Create system-dark theme if missing
        $darkPath = $this->themesPath . '/system-dark';
        if (!file_exists($darkPath . '/theme.json')) {
            $this->createSystemDarkTheme($darkPath);
        }
    }
    
    /**
     * Create system light theme
     */
    private function createSystemLightTheme(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        
        $theme = [
            'id' => 'system-light',
            'name' => 'System Light',
            'author' => 'Tredercopis',
            'version' => '1.0',
            'layout' => '2-columns',
            'css_class' => 'theme-light',
            'system' => true,
            'colors' => [
                'bg' => '#f8f9fa',
                'sidebar' => '#2c3e50',
                'card' => '#ffffff',
                'text' => '#212529',
                'text_muted' => '#6c757d',
                'accent' => '#3498db',
                'success' => '#28a745',
                'warning' => '#ffc107',
                'danger' => '#dc3545',
                'border' => '#dee2e6'
            ]
        ];
        
        file_put_contents($path . '/theme.json', json_encode($theme, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        // Create base CSS
        $baseCss = $this->generateBaseCss($theme['colors'], false);
        file_put_contents($path . '/base.css', $baseCss);
        
        // Create layout CSS
        $layoutCss = $this->generateLayoutCss('2-columns');
        file_put_contents($path . '/layout.css', $layoutCss);
        
        // Create components CSS
        $componentsCss = $this->generateComponentsCss($theme['colors'], false);
        file_put_contents($path . '/components.css', $componentsCss);
    }
    
    /**
     * Create system dark theme
     */
    private function createSystemDarkTheme(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        
        $theme = [
            'id' => 'system-dark',
            'name' => 'System Dark',
            'author' => 'Tredercopis',
            'version' => '1.0',
            'layout' => '2-columns',
            'css_class' => 'theme-dark',
            'system' => true,
            'colors' => [
                'bg' => '#0d1117',
                'sidebar' => '#161b22',
                'card' => '#161b22',
                'text' => '#c9d1d9',
                'text_muted' => '#8b949e',
                'accent' => '#238636',
                'success' => '#238636',
                'warning' => '#d29922',
                'danger' => '#f85149',
                'border' => '#30363d'
            ]
        ];
        
        file_put_contents($path . '/theme.json', json_encode($theme, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        // Create base CSS
        $baseCss = $this->generateBaseCss($theme['colors'], true);
        file_put_contents($path . '/base.css', $baseCss);
        
        // Create layout CSS
        $layoutCss = $this->generateLayoutCss('2-columns');
        file_put_contents($path . '/layout.css', $layoutCss);
        
        // Create components CSS
        $componentsCss = $this->generateComponentsCss($theme['colors'], true);
        file_put_contents($path . '/components.css', $componentsCss);
    }
    
    /**
     * Generate base CSS from colors
     */
    private function generateBaseCss(array $colors, bool $isDark): string
    {
        $css = <<<CSS
/* Tredercopis Theme - Base CSS */
:root {
    --theme-bg: {$colors['bg']};
    --theme-sidebar: {$colors['sidebar']};
    --theme-card: {$colors['card']};
    --theme-text: {$colors['text']};
    --theme-text-muted: {$colors['text_muted']};
    --theme-accent: {$colors['accent']};
    --theme-success: {$colors['success']};
    --theme-warning: {$colors['warning']};
    --theme-danger: {$colors['danger']};
    --theme-border: {$colors['border']};
}

body {
    background-color: var(--theme-bg);
    color: var(--theme-text);
}

.sidebar {
    background-color: var(--theme-sidebar);
}

.card {
    background-color: var(--theme-card);
    border-color: var(--theme-border);
}

.text-muted {
    color: var(--theme-text-muted) !important;
}

a {
    color: var(--theme-accent);
}

a:hover {
    color: var(--theme-accent);
    filter: brightness(1.2);
}
CSS;
        return $css;
    }
    
    /**
     * Generate layout CSS
     */
    private function generateLayoutCss(string $layout): string
    {
        $sidebarWidth = $layout === '3-columns' ? '200px' : '250px';
        
        $css = <<<CSS
/* Tredercopis Theme - Layout CSS */
.app-container {
    display: flex;
    min-height: 100vh;
}

.sidebar {
    width: {$sidebarWidth};
    min-height: 100vh;
    position: fixed;
    left: 0;
    top: 0;
}

.main-content {
    flex: 1;
    margin-left: {$sidebarWidth};
    padding: 20px;
}

@media (max-width: 768px) {
    .sidebar {
        width: 100%;
        position: relative;
    }
    
    .main-content {
        margin-left: 0;
    }
}
CSS;
        return $css;
    }
    
    /**
     * Generate components CSS
     */
    private function generateComponentsCss(array $colors, bool $isDark): string
    {
        $tableHover = $isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.02)';
        
        $css = <<<CSS
/* Tredercopis Theme - Components CSS */
.btn-primary {
    background-color: var(--theme-accent);
    border-color: var(--theme-accent);
}

.btn-success {
    background-color: var(--theme-success);
    border-color: var(--theme-success);
}

.btn-warning {
    background-color: var(--theme-warning);
    border-color: var(--theme-warning);
}

.btn-danger {
    background-color: var(--theme-danger);
    border-color: var(--theme-danger);
}

.table {
    color: var(--theme-text);
}

.table thead th {
    border-color: var(--theme-border);
}

.table td, .table th {
    border-color: var(--theme-border);
}

.table-hover tbody tr:hover {
    background-color: {$tableHover};
}

.form-control {
    background-color: var(--theme-card);
    border-color: var(--theme-border);
    color: var(--theme-text);
}

.form-control:focus {
    background-color: var(--theme-card);
    border-color: var(--theme-accent);
    color: var(--theme-text);
}

.badge {
    font-weight: 500;
}

.alert {
    border-radius: 8px;
}

.nav-link {
    color: rgba(255,255,255,0.8);
}

.nav-link:hover, .nav-link.active {
    color: #fff;
    background-color: var(--theme-accent);
}
CSS;
        return $css;
    }
}
