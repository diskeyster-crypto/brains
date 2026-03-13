<?php
/**
 * View Engine
 * 
 * Template rendering engine with layout support.
 * Provides clean separation between controllers and views.
 * 
 * @package Core\View
 */

declare(strict_types=1);

namespace Core\View;

use Core\System\System;

/**
 * View - Template rendering engine
 * 
 * Usage:
 *   View::render('installer/wizard', ['step' => 1]);
 *   View::render('admin/dashboard', $data, 'admin');
 *   View::partial('partials/header', $data);
 */
final class ViewEngine
{
    private static ?self $instance = null;
    
    /** @var string Current layout name */
    private string $layout = '';
    
    /** @var array Shared data for all views */
    private array $shared = [];
    
    /** @var array Layout sections */
    private array $sections = [];
    
    /** @var string|null Current section being captured */
    private ?string $currentSection = null;

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

    /**
     * Render a view template
     * 
     * @param string $view View path (e.g., 'admin/dashboard', 'installer/wizard')
     * @param array $data Data to pass to view
     * @param string|null $layout Layout to use (null = no layout, 'admin' = admin layout)
     * @return string Rendered HTML
     */
    public static function render(string $view, array $data = [], ?string $layout = null): string
    {
        $instance = self::instance();
        return $instance->renderView($view, $data, $layout);
    }

    /**
     * Render a partial (no layout)
     * 
     * @param string $partial Partial path
     * @param array $data Data to pass
     * @return string Rendered HTML
     */
    public static function partial(string $partial, array $data = []): string
    {
        $instance = self::instance();
        return $instance->renderView($partial, $data, null);
    }

    /**
     * Share data with all views
     * 
     * @param string|array $key Key or array of key-value pairs
     * @param mixed $value Value (if key is string)
     */
    public static function share(string|array $key, mixed $value = null): void
    {
        $instance = self::instance();
        
        if (is_array($key)) {
            $instance->shared = array_merge($instance->shared, $key);
        } else {
            $instance->shared[$key] = $value;
        }
    }

    /**
     * Internal render method
     */
    private function renderView(string $view, array $data, ?string $layout): string
    {
        $viewPath = $this->resolveViewPath($view);
        
        if (!file_exists($viewPath)) {
            throw new \RuntimeException("View not found: {$view} (looked in {$viewPath})");
        }

        // Merge shared data
        $data = array_merge($this->shared, $data);
        
        // Capture view content
        $content = $this->capture($viewPath, $data);

        // If layout requested, wrap content
        if ($layout !== null) {
            $layoutPath = $this->resolveLayoutPath($layout);
            
            if (file_exists($layoutPath)) {
                $data['content'] = $content;
                $data['sections'] = $this->sections;
                $content = $this->capture($layoutPath, $data);
            }
        }

        // Clear sections after render
        $this->sections = [];

        return $content;
    }

    /**
     * Capture output from a template file
     */
    private function capture(string $path, array $data): string
    {
        extract($data, EXTR_SKIP);
        
        ob_start();
        try {
            include $path;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        
        return ob_get_clean();
    }

    /**
     * Resolve view path
     * 
     * Search order:
     * 1. modules/{module}/views/{view}.php
     * 2. admin/views/{view}.php
     * 3. views/{view}.php
     */
    private function resolveViewPath(string $view): string
    {
        // Normalize path
        $view = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $view);
        $view = ltrim($view, DIRECTORY_SEPARATOR);
        
        // Check if it's a module view (module/view format)
        $parts = explode(DIRECTORY_SEPARATOR, $view, 2);
        
        if (count($parts) === 2) {
            $module = $parts[0];
            $viewName = $parts[1];
            
            // Check module views
            $modulePath = System::path('modules') . "/{$module}/views/{$viewName}.php";
            if (file_exists($modulePath)) {
                return $modulePath;
            }
            
            // Check admin views
            $adminPath = System::path('root') . "/admin/views/{$view}.php";
            if (file_exists($adminPath)) {
                return $adminPath;
            }
        }
        
        // Check global views
        $globalPath = System::path('root') . "/views/{$view}.php";
        if (file_exists($globalPath)) {
            return $globalPath;
        }
        
        // Check admin views as fallback
        $adminPath = System::path('root') . "/admin/views/{$view}.php";
        if (file_exists($adminPath)) {
            return $adminPath;
        }
        
        // Return most likely path for error message
        return System::path('root') . "/views/{$view}.php";
    }

    /**
     * Resolve layout path
     */
    private function resolveLayoutPath(string $layout): string
    {
        // Check views/layouts first
        $path = System::path('root') . "/views/layouts/{$layout}.php";
        if (file_exists($path)) {
            return $path;
        }
        
        // Check admin/views/layouts
        $path = System::path('root') . "/admin/views/layouts/{$layout}.php";
        if (file_exists($path)) {
            return $path;
        }
        
        return System::path('root') . "/views/layouts/{$layout}.php";
    }

    /**
     * Start capturing a section
     * 
     * @param string $name Section name
     */
    public static function section(string $name): void
    {
        $instance = self::instance();
        $instance->currentSection = $name;
        ob_start();
    }

    /**
     * End section capture
     */
    public static function endSection(): void
    {
        $instance = self::instance();
        
        if ($instance->currentSection !== null) {
            $instance->sections[$instance->currentSection] = ob_get_clean();
            $instance->currentSection = null;
        }
    }

    /**
     * Yield section content
     * 
     * @param string $name Section name
     * @param string $default Default content
     * @return string Section content
     */
    public static function yieldSection(string $name, string $default = ''): string
    {
        $instance = self::instance();
        return $instance->sections[$name] ?? $default;
    }

    /**
     * Escape HTML
     * 
     * @param string|null $value Value to escape
     * @return string Escaped value
     */
    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    /**
     * Generate URL using System::web()
     * 
     * @param string $path URL path
     * @return string Full URL
     */
    public static function url(string $path = ''): string
    {
        return System::web($path);
    }

    /**
     * Generate admin URL
     * 
     * @param string $path Admin path
     * @return string Full admin URL
     */
    public static function adminUrl(string $path = ''): string
    {
        return System::adminUrl($path);
    }

    /**
     * Generate asset URL
     * 
     * @param string $path Asset path
     * @return string Full asset URL
     */
    public static function asset(string $path): string
    {
        return System::adminAsset($path);
    }
}

// Convenience class alias
class_alias(ViewEngine::class, 'View');

/* RULES
- Purpose: Template rendering engine with layout support
- Config sources: None
- Paths: Uses System::path() for view resolution
- Logs: No direct logging
- Prohibitions:
  - NO inline HTML in controllers (use views)
  - NO direct file path construction (use ViewEngine methods)
  - Always escape output with View::e()
*/
