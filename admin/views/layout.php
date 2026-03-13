<?php

declare(strict_types=1);

use Core\Auth\Auth;
use Core\System\System;
use Core\Storage\StorageManager;

/**
 * Admin Layout Template
 * 
 * Uses System::web() for all URLs - no hardcoded paths.
 * Supports installation in subfolder (e.g., /myapp/admin).
 * 
 * CSS Architecture (HARD UI RESET TZ):
 * 1. style.css - base structure
 * 2. theme variables (CSS custom properties only)
 * 3. ui.css - ALL design (loaded LAST, overrides everything)
 * 
 * FORBIDDEN:
 * - Inline CSS in views (use ui.css only)
 * - Theme CSS files with component styles (use ui.css)
 * - New CSS classes (override existing ones in ui.css)
 * 
 * Extensibility (for system modules):
 * - $page_css: array of CSS file paths to include
 * - $page_js: array of JS file paths to include
 * - $page_title: custom page title (overrides $title)
 * - $page_theme: optional theme class for <body>
 */

// Prevent function redeclaration if layout is included multiple times
if (!function_exists('renderLayout')) {

function renderLayout(string $title, string $content, string $activePage = '', array $options = []): string
{
    // Extract extensibility options
    $page_css = $options['page_css'] ?? [];
    $page_js = $options['page_js'] ?? [];
    $page_title = $options['page_title'] ?? null;
    $page_theme = $options['page_theme'] ?? '';
    
    // Load ThemeApply - for body class only
    // Per HARD UI RESET TZ: theme CSS should contain ONLY variables
    // All component styles are in ui.css
    $themeApplyPath = System::path('root') . '/modules/system/theme/service/theme.apply.php';
    $themeBodyClass = '';
    $themeCssVariables = '';
    
    if (file_exists($themeApplyPath)) {
        require_once $themeApplyPath;
        
        // ThemeApply is the single source of truth for body class
        $themeApply = \Modules\System\Theme\ThemeApply::instance();
        
        // Get body class from ThemeApply
        $themeBodyClass = $themeApply->getBodyClass();
        
        // Get CSS variables ONLY from ThemeApply (no component styles)
        $themeCssVariables = $themeApply->getCssVariables();
        
        // DISABLED: theme CSS files and inline CSS are FORBIDDEN
        // All component styles must be in ui.css ONLY
    }
    
    // DISABLED: Theme CSS files are not loaded per HARD UI RESET TZ
    // Only ui.css provides component styles
    // $page_css remains as-is (module CSS only, no theme CSS)
    
    // Use page_theme if provided, otherwise use ThemeApply body class
    if (empty($page_theme) && !empty($themeBodyClass)) {
        $page_theme = $themeBodyClass;
    }
    
    // Use custom title if provided
    $displayTitle = $page_title ?? $title;
    $user = Auth::user();
    $username = htmlspecialchars($user['username'] ?? 'Гость', ENT_QUOTES, 'UTF-8');
    
    // Active page classes
    $dashboardActive = $activePage === 'dashboard' ? 'active' : '';
    $modulesActive = $activePage === 'modules' ? 'active' : '';
    $cronActive = $activePage === 'cron' ? 'active' : '';
    $storageActive = $activePage === 'storage' ? 'active' : '';
    $logsActive = $activePage === 'logs' ? 'active' : '';
    $updateActive = $activePage === 'update' ? 'active' : '';
    $githubActive = $activePage === 'github' ? 'active' : '';
    $themeActive = $activePage === 'theme' ? 'active' : '';
    $systemActive = $activePage === 'system' ? 'active' : '';
    
    // Generate URLs through System - supports subfolder installation
    $cssUrl = System::adminAsset('css/style.css');
    $uiCssUrl = System::adminAsset('css/ui.css');
    $jsUrl = System::adminAsset('js/app.js');
    $adminUrl = System::adminUrl();
    $modulesUrl = System::adminUrl('modules');
    $cronUrl = System::adminUrl('cron');
    $storageUrl = System::adminUrl('storage');
    $logsUrl = System::adminUrl('logs');
    $updateUrl = System::adminUrl('update');
    $githubUrl = System::web('admin/github');
    $themeUrl = System::web('admin/theme');
    $systemUrl = System::web('admin/system');
    $logoutUrl = System::adminUrl('logout');
    
    // Load PagesRegistry for sidebar (respects visibility settings)
    $sidebarItems = [];
    $pagesRegistryPath = System::path('root') . '/modules/system/pages/registry.php';
    if (file_exists($pagesRegistryPath)) {
        require_once $pagesRegistryPath;
        $pagesRegistry = \Modules\System\Pages\PagesRegistry::instance();
        $sidebarItems = $pagesRegistry->visible();
        // Sort by order
        usort($sidebarItems, fn($a, $b) => ($a['order'] ?? 100) <=> ($b['order'] ?? 100));
    }
    
    // Build custom CSS includes
    $customCssHtml = '';
    foreach ($page_css as $cssPath) {
        $safePath = htmlspecialchars($cssPath, ENT_QUOTES, 'UTF-8');
        $customCssHtml .= "    <link rel=\"stylesheet\" href=\"{$safePath}\">\n";
    }
    
    // Build custom JS includes
    $customJsHtml = '';
    foreach ($page_js as $jsPath) {
        $safePath = htmlspecialchars($jsPath, ENT_QUOTES, 'UTF-8');
        $customJsHtml .= "    <script src=\"{$safePath}\"></script>\n";
    }
    
    // Build body class - always include ui-dark for the design system
    $bodyClasses = ['ui-dark'];
    if ($page_theme) {
        $bodyClasses[] = htmlspecialchars($page_theme, ENT_QUOTES, 'UTF-8');
    }
    $bodyClass = ' class="' . implode(' ', $bodyClasses) . '"';
    
    // Build theme CSS variables ONLY (no component styles per TZ)
    // Theme CSS should contain ONLY variables and body { background, color }
    // All component styles must be in ui.css
    $themeCssVariablesHtml = '';
    if (!empty($themeCssVariables)) {
        $themeCssVariablesHtml = "<style id=\"theme-variables\">\n{$themeCssVariables}\n</style>\n";
    }
    
    // DISABLED: Inline theme CSS is FORBIDDEN by architecture
    // All component styles must be in ui.css ONLY
    // $themeInlineCssHtml removed per HARD UI RESET TZ
    
    // Build sidebar menu from PagesRegistry (respects visibility)
    $sidebarMenuHtml = '';
    if (!empty($sidebarItems)) {
        foreach ($sidebarItems as $item) {
            $itemTitle = htmlspecialchars($item['title'] ?? 'Untitled', ENT_QUOTES, 'UTF-8');
            $itemRoute = $item['route'] ?? '/admin';
            // Convert route to URL
            $itemUrl = System::web(ltrim($itemRoute, '/'));
            // Determine active state based on route
            $routeKey = trim(str_replace('/admin/', '', $itemRoute), '/');
            $routeKey = $routeKey ?: 'dashboard';
            $itemActive = ($activePage === $routeKey) ? 'active' : '';
            
            $sidebarMenuHtml .= "                <li class=\"nav-item\">\n";
            $sidebarMenuHtml .= "                    <a href=\"{$itemUrl}\" class=\"nav-link {$itemActive}\">{$itemTitle}</a>\n";
            $sidebarMenuHtml .= "                </li>\n";
        }
    } else {
        // Fallback to hardcoded menu if PagesRegistry not available
        $sidebarMenuHtml = <<<MENU
                <li class="nav-item">
                    <a href="{$adminUrl}" class="nav-link {$dashboardActive}">Панель</a>
                </li>
                <li class="nav-item">
                    <a href="{$systemUrl}" class="nav-link {$systemActive}">Система</a>
                </li>
                <li class="nav-item">
                    <a href="{$modulesUrl}" class="nav-link {$modulesActive}">Модули</a>
                </li>
                <li class="nav-item">
                    <a href="{$cronUrl}" class="nav-link {$cronActive}">Планировщик</a>
                </li>
                <li class="nav-item">
                    <a href="{$storageUrl}" class="nav-link {$storageActive}">Хранилище</a>
                </li>
                <li class="nav-item">
                    <a href="{$logsUrl}" class="nav-link {$logsActive}">Логи</a>
                </li>
MENU;
    }
    
    return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$displayTitle} - Tredercopis Админ</title>
    <link rel="stylesheet" href="{$cssUrl}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
{$customCssHtml}{$themeCssVariablesHtml}    <!-- UI Design System - loaded LAST to override all other styles -->
    <link rel="stylesheet" href="{$uiCssUrl}">
</head>
<body{$bodyClass}>
    <div class="app">
        <nav class="sidebar">
            <div class="sidebar-header">
                <h2>Tredercopis</h2>
            </div>
            <ul class="nav-menu">
{$sidebarMenuHtml}
            </ul>
        </nav>
        <main class="main-content">
            <header class="header">
                <h1>{$displayTitle}</h1>
                <div class="user-info">
                    <span>{$username}</span>
                    <a href="{$logoutUrl}" class="btn btn-sm">Выход</a>
                </div>
            </header>
            <div class="content">
                {$content}
            </div>
        </main>
    </div>
    <script src="{$jsUrl}"></script>
{$customJsHtml}</body>
</html>
HTML;
}

} // end if (!function_exists('renderLayout'))

// CSS Architecture (HARD UI RESET TZ):
// 1. style.css - base HTML structure
// 2. theme CSS variables - ONLY variables (no component styles)
// 3. ui.css - ALL visual design (loads LAST, overrides everything)
//
// FORBIDDEN:
// - Inline CSS in views
// - Theme CSS with .card, .btn, .sidebar, etc.
// - New CSS classes (override existing in ui.css)
