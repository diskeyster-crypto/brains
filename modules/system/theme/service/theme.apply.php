<?php

declare(strict_types=1);

namespace Modules\System\Theme;

use Core\System\System;

/**
 * Theme Apply Service
 * 
 * Responsible for applying the active theme to the layout.
 * Generates CSS includes and body classes for the current theme.
 * 
 * @package Modules\System\Theme
 */
class ThemeApply
{
    private static ?self $instance = null;
    
    private ?array $activeTheme = null;
    
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct()
    {
        require_once __DIR__ . '/theme.loader.php';
        require_once __DIR__ . '/theme.storage.php';
    }
    
    /**
     * Get the active theme data
     */
    public function getActiveTheme(): ?array
    {
        if ($this->activeTheme === null) {
            $themeId = ThemeStorage::instance()->getActiveTheme();
            $this->activeTheme = ThemeLoader::instance()->load($themeId);
        }
        return $this->activeTheme;
    }
    
    /**
     * Get body class for active theme
     */
    public function getBodyClass(): string
    {
        $theme = $this->getActiveTheme();
        $classes = [];
        
        if ($theme) {
            $classes[] = $theme['css_class'] ?? 'theme-light';
        }
        
        // Add layout class
        $layout = ThemeStorage::instance()->getLayoutMode();
        $classes[] = 'layout-' . $layout;
        
        return implode(' ', $classes);
    }
    
    /**
     * Get CSS file paths for active theme
     */
    public function getCssFiles(): array
    {
        $theme = $this->getActiveTheme();
        if (!$theme) {
            return [];
        }
        
        $themeId = $theme['id'] ?? 'system-light';
        $basePath = '/storage/system/themes/' . $themeId;
        $files = [];
        
        foreach ($theme['css_files'] ?? [] as $file) {
            $files[] = System::web($basePath . '/' . $file);
        }
        
        return $files;
    }
    
    /**
     * Get combined theme CSS
     */
    public function getThemeCss(): string
    {
        $theme = $this->getActiveTheme();
        if (!$theme) {
            return '';
        }
        
        $themePath = $theme['path'] ?? '';
        if (!$themePath || !is_dir($themePath)) {
            return '';
        }
        
        $css = '';
        $files = ['base.css', 'layout.css', 'components.css'];
        
        foreach ($files as $file) {
            $filePath = $themePath . '/' . $file;
            if (file_exists($filePath)) {
                $css .= "/* {$file} */\n";
                $css .= file_get_contents($filePath) . "\n\n";
            }
        }
        
        return $css;
    }
    
    /**
     * Get layout CSS only
     */
    public function getLayoutCss(): string
    {
        $theme = $this->getActiveTheme();
        if (!$theme) {
            return '';
        }
        
        $themePath = $theme['path'] ?? '';
        $layoutFile = $themePath . '/layout.css';
        
        if (file_exists($layoutFile)) {
            return file_get_contents($layoutFile);
        }
        
        return '';
    }
    
    /**
     * Get CSS variables from active theme
     */
    public function getCssVariables(): string
    {
        $theme = $this->getActiveTheme();
        if (!$theme || empty($theme['colors'])) {
            return '';
        }
        
        $css = ":root {\n";
        foreach ($theme['colors'] as $name => $value) {
            $varName = '--theme-' . str_replace('_', '-', $name);
            $css .= "    {$varName}: {$value};\n";
        }
        $css .= "}\n";
        
        return $css;
    }
    
    /**
     * Generate inline style tag for theme
     */
    public function getInlineStyles(): string
    {
        $css = $this->getCssVariables();
        if (empty($css)) {
            return '';
        }
        return '<style id="theme-variables">' . $css . '</style>';
    }
    
    /**
     * Generate link tags for theme CSS files
     */
    public function getCssLinks(): string
    {
        $files = $this->getCssFiles();
        if (empty($files)) {
            return '';
        }
        
        $html = '';
        foreach ($files as $file) {
            $html .= '<link rel="stylesheet" href="' . htmlspecialchars($file) . '">' . "\n";
        }
        return $html;
    }
}
