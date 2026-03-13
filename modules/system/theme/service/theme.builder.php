<?php

declare(strict_types=1);

namespace Modules\System\Theme;

use Core\System\System;

/**
 * Theme Builder Service
 * 
 * Handles creation and updating of themes.
 * Generates CSS files based on color configuration.
 * 
 * @package Modules\System\Theme
 */
class ThemeBuilder
{
    private static ?self $instance = null;
    
    private string $themesPath;
    
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
        require_once __DIR__ . '/theme.storage.php';
    }
    
    /**
     * Create a new theme
     */
    public function create(array $data): ?string
    {
        // Validate required fields
        if (empty($data['name'])) {
            return null;
        }
        
        // Generate ID from name
        $themeId = $data['id'] ?? $this->generateId($data['name']);
        
        // Check if already exists
        if (is_dir($this->themesPath . '/' . $themeId)) {
            return null;
        }
        
        // Set defaults
        $data['id'] = $themeId;
        $data['version'] = $data['version'] ?? '1.0';
        $data['author'] = $data['author'] ?? 'Custom';
        $data['layout'] = $data['layout'] ?? '2-columns';
        $data['css_class'] = $data['css_class'] ?? 'theme-' . $themeId;
        
        // Set default colors if not provided
        if (empty($data['colors'])) {
            $data['colors'] = $this->getDefaultColors($data['base_theme'] ?? 'light');
        }
        
        // Generate CSS files
        $isDark = $this->isDarkTheme($data['colors']);
        $data['base_css'] = $data['base_css'] ?? $this->generateBaseCss($data['colors']);
        $data['layout_css'] = $data['layout_css'] ?? $this->generateLayoutCss($data['layout']);
        $data['components_css'] = $data['components_css'] ?? $this->generateComponentsCss($data['colors'], $isDark);
        
        // Save the theme
        ThemeStorage::instance()->saveTheme($themeId, $data);
        
        return $themeId;
    }
    
    /**
     * Update an existing theme
     */
    public function update(string $themeId, array $data): bool
    {
        $themePath = $this->themesPath . '/' . $themeId;
        
        if (!is_dir($themePath)) {
            return false;
        }
        
        // Check if it's a system theme
        $themeJson = $themePath . '/theme.json';
        if (file_exists($themeJson)) {
            $existingTheme = json_decode(file_get_contents($themeJson), true);
            if (!empty($existingTheme['system'])) {
                // System themes can only have layout changed
                if (isset($data['layout'])) {
                    $existingTheme['layout'] = $data['layout'];
                    file_put_contents($themeJson, json_encode($existingTheme, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    
                    // Regenerate layout CSS
                    $layoutCss = $this->generateLayoutCss($data['layout']);
                    file_put_contents($themePath . '/layout.css', $layoutCss);
                }
                return true;
            }
        }
        
        // Update custom theme
        $data['id'] = $themeId;
        
        // Regenerate CSS if colors changed
        if (!empty($data['colors'])) {
            $isDark = $this->isDarkTheme($data['colors']);
            $data['base_css'] = $data['base_css'] ?? $this->generateBaseCss($data['colors']);
            $data['components_css'] = $data['components_css'] ?? $this->generateComponentsCss($data['colors'], $isDark);
        }
        
        // Regenerate layout CSS if layout changed
        if (!empty($data['layout'])) {
            $data['layout_css'] = $data['layout_css'] ?? $this->generateLayoutCss($data['layout']);
        }
        
        ThemeStorage::instance()->saveTheme($themeId, $data);
        return true;
    }
    
    /**
     * Clone an existing theme
     */
    public function clone(string $sourceId, string $newName): ?string
    {
        require_once __DIR__ . '/theme.loader.php';
        
        $source = ThemeLoader::instance()->load($sourceId);
        if (!$source) {
            return null;
        }
        
        $newId = $this->generateId($newName);
        
        // Copy theme data
        $data = [
            'name' => $newName,
            'author' => 'Custom (cloned from ' . $source['name'] . ')',
            'version' => '1.0',
            'layout' => $source['layout'] ?? '2-columns',
            'css_class' => 'theme-' . $newId,
            'colors' => $source['colors'] ?? []
        ];
        
        // Copy CSS files
        $sourcePath = $source['path'];
        if (file_exists($sourcePath . '/base.css')) {
            $data['base_css'] = file_get_contents($sourcePath . '/base.css');
        }
        if (file_exists($sourcePath . '/layout.css')) {
            $data['layout_css'] = file_get_contents($sourcePath . '/layout.css');
        }
        if (file_exists($sourcePath . '/components.css')) {
            $data['components_css'] = file_get_contents($sourcePath . '/components.css');
        }
        
        return $this->create($data);
    }
    
    /**
     * Generate theme ID from name
     */
    private function generateId(string $name): string
    {
        $id = strtolower($name);
        $id = preg_replace('/[^a-z0-9]+/', '-', $id);
        $id = trim($id, '-');
        return $id ?: 'custom-theme';
    }
    
    /**
     * Check if theme is dark based on background color
     */
    private function isDarkTheme(array $colors): bool
    {
        $bg = $colors['bg'] ?? '#ffffff';
        
        // Convert hex to RGB
        $hex = ltrim($bg, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        // Calculate luminance
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        
        return $luminance < 0.5;
    }
    
    /**
     * Get default colors for a base theme
     */
    private function getDefaultColors(string $base): array
    {
        if ($base === 'dark') {
            return [
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
            ];
        }
        
        return [
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
        ];
    }
    
    /**
     * Generate base CSS
     */
    private function generateBaseCss(array $colors): string
    {
        return <<<CSS
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
    }
    
    /**
     * Generate layout CSS
     */
    private function generateLayoutCss(string $layout): string
    {
        $sidebarWidth = $layout === '3-columns' ? '200px' : '250px';
        
        return <<<CSS
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
    }
    
    /**
     * Generate components CSS
     */
    private function generateComponentsCss(array $colors, bool $isDark): string
    {
        $tableHover = $isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.02)';
        
        return <<<CSS
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
    }
}
