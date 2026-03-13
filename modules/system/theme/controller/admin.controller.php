<?php

declare(strict_types=1);

namespace Modules\System\Theme\Controller;

use Core\System\System;
use Core\Auth\Auth;
use Core\Router\Router;
use Modules\System\Theme\ThemeModule;

// Require the module using System::path
$modulePath = System::path('root') . '/modules/system/theme';
require_once $modulePath . '/theme.module.php';
require_once $modulePath . '/service/theme.loader.php';
require_once $modulePath . '/service/theme.storage.php';
require_once $modulePath . '/service/theme.apply.php';
require_once $modulePath . '/service/theme.builder.php';
require_once $modulePath . '/service/theme.export.php';

/**
 * Theme Admin Controller
 * 
 * Handles all admin panel routes for theme management.
 * All pages are rendered through admin/views/layout.php using renderLayout().
 * 
 * @package Modules\System\Theme
 */
class ThemeController
{
    private static ?self $instance = null;
    
    private ThemeModule $module;
    
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct()
    {
        $this->module = ThemeModule::instance();
    }
    
    /**
     * Dashboard - list all themes
     */
    public function dashboard(): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        $themes = $this->module->listThemes();
        $activeThemeId = $this->module->getActiveThemeId();
        $config = $this->module->storage()->getConfig();
        
        return $this->render('dashboard', [
            'title' => 'Theme Manager',
            'themes' => $themes,
            'activeThemeId' => $activeThemeId,
            'config' => $config
        ]);
    }
    
    /**
     * Theme editor
     */
    public function editor(?string $themeId = null): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return $this->handleEditorSave();
        }
        
        $theme = null;
        if ($themeId) {
            $theme = $this->module->loader()->load($themeId);
        }
        
        $isNew = ($theme === null);
        
        return $this->render('editor', [
            'title' => $isNew ? 'Create Theme' : 'Edit Theme',
            'theme' => $theme,
            'isNew' => $isNew
        ]);
    }
    
    /**
     * Layout configuration
     */
    public function layouts(): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $layout = $_POST['layout'] ?? '2-columns';
            $this->module->storage()->setLayoutMode($layout);
            Router::redirect(System::web('admin/theme/layouts') . '?saved=1');
            return '';
        }
        
        $currentLayout = $this->module->storage()->getLayoutMode();
        
        return $this->render('layouts', [
            'title' => 'Layout Settings',
            'currentLayout' => $currentLayout
        ]);
    }
    
    /**
     * Preview theme
     * 
     * Note: Preview renders as a standalone page, not wrapped in admin layout,
     * because it needs to show the theme in full isolation.
     */
    public function preview(): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        $themeId = $_GET['theme'] ?? $this->module->getActiveThemeId();
        $theme = $this->module->loader()->load($themeId);
        
        if (!$theme) {
            Router::redirect(System::web('admin/theme'));
            return '';
        }
        
        // Preview renders as standalone page (not wrapped in layout)
        $viewFile = System::path('root') . '/modules/system/theme/views/preview.php';
        
        ob_start();
        include $viewFile;
        return ob_get_clean();
    }
    
    /**
     * Create new theme
     */
    public function create(): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return $this->handleCreate();
        }
        
        $baseThemes = [
            'light' => 'Light Base',
            'dark' => 'Dark Base'
        ];
        
        $existingThemes = $this->module->listThemes();
        
        return $this->render('create', [
            'title' => 'Create New Theme',
            'baseThemes' => $baseThemes,
            'existingThemes' => $existingThemes
        ]);
    }
    
    /**
     * Activate theme
     */
    public function activate(): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        $themeId = $_GET['theme'] ?? $_POST['theme'] ?? null;
        
        if ($themeId && $this->module->activateTheme($themeId)) {
            Router::redirect(System::web('admin/theme') . '?activated=1');
        } else {
            Router::redirect(System::web('admin/theme') . '?error=activate');
        }
        
        return '';
    }
    
    /**
     * Delete theme
     */
    public function delete(): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        $themeId = $_GET['theme'] ?? $_POST['theme'] ?? null;
        
        if ($themeId && $this->module->deleteTheme($themeId)) {
            Router::redirect(System::web('admin/theme') . '?deleted=1');
        } else {
            Router::redirect(System::web('admin/theme') . '?error=delete');
        }
        
        return '';
    }
    
    /**
     * Save theme changes
     */
    public function save(): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        return $this->handleEditorSave();
    }
    
    /**
     * Export theme
     */
    public function export(): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        $themeId = $_GET['theme'] ?? null;
        
        if (!$themeId) {
            Router::redirect(System::web('admin/theme') . '?error=export');
            return '';
        }
        
        $zipPath = $this->module->exportTheme($themeId);
        
        if (!$zipPath || !file_exists($zipPath)) {
            Router::redirect(System::web('admin/theme') . '?error=export');
            return '';
        }
        
        // Send file download
        $headers = $this->module->export()->getDownloadHeaders($zipPath);
        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }
        
        readfile($zipPath);
        
        // Clean up temp file
        unlink($zipPath);
        
        exit;
    }
    
    /**
     * Import theme
     */
    public function import(): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        // Handle file upload
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['theme_zip'])) {
            $file = $_FILES['theme_zip'];
            
            if ($file['error'] === UPLOAD_ERR_OK && $file['type'] === 'application/zip') {
                $themeId = $this->module->importTheme($file['tmp_name']);
                
                if ($themeId) {
                    Router::redirect(System::web('admin/theme') . '?imported=' . urlencode($themeId));
                    return '';
                }
            }
            
            Router::redirect(System::web('admin/theme') . '?error=import');
            return '';
        }
        
        // Show import form
        return $this->render('import', [
            'title' => 'Import Theme'
        ]);
    }
    
    /**
     * Handle editor form save
     */
    private function handleEditorSave(): string
    {
        $themeId = $_POST['theme_id'] ?? null;
        $isNew = empty($themeId);
        
        $data = [
            'name' => $_POST['name'] ?? 'Custom Theme',
            'author' => $_POST['author'] ?? 'Custom',
            'version' => $_POST['version'] ?? '1.0',
            'layout' => $_POST['layout'] ?? '2-columns',
            'css_class' => $_POST['css_class'] ?? '',
            'colors' => [
                'bg' => $_POST['color_bg'] ?? '#f8f9fa',
                'sidebar' => $_POST['color_sidebar'] ?? '#2c3e50',
                'card' => $_POST['color_card'] ?? '#ffffff',
                'text' => $_POST['color_text'] ?? '#212529',
                'text_muted' => $_POST['color_text_muted'] ?? '#6c757d',
                'accent' => $_POST['color_accent'] ?? '#3498db',
                'success' => $_POST['color_success'] ?? '#28a745',
                'warning' => $_POST['color_warning'] ?? '#ffc107',
                'danger' => $_POST['color_danger'] ?? '#dc3545',
                'border' => $_POST['color_border'] ?? '#dee2e6'
            ]
        ];
        
        // Handle custom CSS
        if (!empty($_POST['base_css'])) {
            $data['base_css'] = $_POST['base_css'];
        }
        if (!empty($_POST['layout_css'])) {
            $data['layout_css'] = $_POST['layout_css'];
        }
        if (!empty($_POST['components_css'])) {
            $data['components_css'] = $_POST['components_css'];
        }
        
        if ($isNew) {
            $newId = $this->module->createTheme($data);
            if ($newId) {
                Router::redirect(System::web('admin/theme/editor/' . $newId) . '?saved=1');
            } else {
                Router::redirect(System::web('admin/theme/create') . '?error=create');
            }
        } else {
            if ($this->module->updateTheme($themeId, $data)) {
                Router::redirect(System::web('admin/theme/editor/' . $themeId) . '?saved=1');
            } else {
                Router::redirect(System::web('admin/theme/editor/' . $themeId) . '?error=save');
            }
        }
        
        return '';
    }
    
    /**
     * Handle create form
     */
    private function handleCreate(): string
    {
        $mode = $_POST['create_mode'] ?? 'blank';
        
        $data = [
            'name' => $_POST['name'] ?? 'New Theme'
        ];
        
        if ($mode === 'clone' && !empty($_POST['clone_from'])) {
            $newId = $this->module->builder()->clone($_POST['clone_from'], $data['name']);
        } else {
            $data['base_theme'] = $_POST['base_theme'] ?? 'light';
            $newId = $this->module->createTheme($data);
        }
        
        if ($newId) {
            Router::redirect(System::web('admin/theme/editor/' . $newId));
        } else {
            Router::redirect(System::web('admin/theme/create') . '?error=create');
        }
        
        return '';
    }
    
    /**
     * Render view with admin layout
     * 
     * Uses renderLayout() from admin/views/layout.php to maintain
     * visual consistency with other Tredercopis system modules.
     */
    private function render(string $view, array $data = []): string
    {
        $viewFile = System::path('root') . '/modules/system/theme/views/' . $view . '.php';
        
        if (!file_exists($viewFile)) {
            return "View not found: {$view}";
        }
        
        extract($data);
        
        ob_start();
        include $viewFile;
        $content = ob_get_clean();
        
        // Use admin layout - same as GitHub and other system modules
        require_once System::path('root') . '/admin/views/layout.php';
        return renderLayout($data['title'] ?? 'Theme Manager', $content, 'theme');
    }
}
