<?php

declare(strict_types=1);

/**
 * Tredercopis Core Web Entry Point
 * 
 * Single entry point for all web requests.
 * All routes handled via Router - no direct file access.
 */

define('ROOT', dirname(__DIR__));

// Explicit bootstrap - no autoloaders
require_once ROOT . '/core/bootstrap.php';

use Core\System\System;
use Core\Router\Router;
use Core\Auth\Auth;
use Core\Logger\Logger;
use Core\Module\ModuleManager;
use Core\Cron\CronManager;
use Core\Storage\StorageManager;
use Core\Gateway\Bybit;

// Initialize system
System::init([
    'root' => ROOT,
    'env' => 'dev',
]);

// Start session for web mode
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load installer controller
require_once ROOT . '/modules/installer/controller.php';
use Modules\Installer\InstallerController;

// ============================================================
// INSTALLER ROUTES (before auth check)
// ============================================================

Router::get('/install', function () {
    $controller = InstallerController::instance();
    echo $controller->index();
});

Router::post('/install', function () {
    $controller = InstallerController::instance();
    $controller->process();
});

// ============================================================
// LEGACY URL REDIRECTS (for backward compatibility)
// ============================================================

// Fix double admin path from legacy redirects
Router::get('/admin/admin/login', function () {
    Router::redirect(System::web('admin/login'));
});

Router::get('/admin/admin/logout', function () {
    Router::redirect(System::web('admin/logout'));
});

Router::get('/admin/admin', function () {
    Router::redirect(System::web('admin'));
});

// Handle /admin/index.php access (legacy entrypoint)
// The Router will match this based on normalized REQUEST_URI
Router::get('/admin/index.php', function () {
    // Redirect to proper /admin route
    Router::redirect(System::web('admin'));
});

// ============================================================
// ROOT REDIRECT
// ============================================================

Router::get('/', function () {
    if (!System::state()->isInstalled()) {
        Router::redirect(System::web('install'));
        return;
    }
    Router::redirect(System::web('admin'));
});

// ============================================================
// GLOBAL INSTALLER GUARD (Core-level middleware)
// ============================================================
// This middleware ensures:
// 1. If NOT installed → all routes except /install redirect to /install
// 2. If installed → /install redirects to /admin

Router::middleware(function (string $method, string $uri): bool {
    // Always allow installer routes
    if (strpos($uri, '/install') === 0) {
        // But if installed, redirect away from installer
        if (System::state()->isInstalled()) {
            Router::redirect(System::web('admin'));
            return false;
        }
        return true;
    }
    
    // If NOT installed, redirect everything to installer
    if (!System::state()->isInstalled()) {
        Router::redirect(System::web('install'));
        return false;
    }
    
    return true;
});

// ============================================================
// PUBLIC CRON ENDPOINT (no auth required, for ISP Manager)
// ============================================================
// This endpoint can be called from ISP Manager crontab or wget/curl
// Without authentication - uses a secret key for security
// URL: /cron/run?key=YOUR_SECRET_KEY
// Or: /cron/run (GET/POST without key if cron_secret is not set)

Router::get('/cron/run', function () {
    header('Content-Type: application/json');
    
    // Check secret key if configured
    $storage = StorageManager::instance();
    $settings = $storage->get('system_settings') ?? [];
    $cronSecret = $settings['cron_secret'] ?? '';
    
    if (!empty($cronSecret)) {
        $providedKey = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
        if ($providedKey !== $cronSecret) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Invalid cron key']);
            return;
        }
    }
    
    try {
        $results = CronManager::instance()->run();
        echo json_encode([
            'success' => true,
            'count' => count($results),
            'executed' => array_keys($results),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
});

Router::post('/cron/run', function () {
    header('Content-Type: application/json');
    
    // Check secret key if configured
    $storage = StorageManager::instance();
    $settings = $storage->get('system_settings') ?? [];
    $cronSecret = $settings['cron_secret'] ?? '';
    
    if (!empty($cronSecret)) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $providedKey = $input['key'] ?? $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
        if ($providedKey !== $cronSecret) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Invalid cron key']);
            return;
        }
    }
    
    try {
        $results = CronManager::instance()->run();
        echo json_encode([
            'success' => true,
            'count' => count($results),
            'results' => $results,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
});

// Single task run
Router::get('/cron/task/{task}', function (string $task) {
    header('Content-Type: application/json');
    
    // Check secret key if configured
    $storage = StorageManager::instance();
    $settings = $storage->get('system_settings') ?? [];
    $cronSecret = $settings['cron_secret'] ?? '';
    
    if (!empty($cronSecret)) {
        $providedKey = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
        if ($providedKey !== $cronSecret) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Invalid cron key']);
            return;
        }
    }
    
    try {
        $result = CronManager::instance()->runTask($task);
        echo json_encode($result);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
});

// ============================================================
// ADMIN ROUTES
// ============================================================

// Load admin views
require_once ROOT . '/admin/views/layout.php';
require_once ROOT . '/admin/views/login.php';
require_once ROOT . '/admin/views/dashboard.php';
require_once ROOT . '/admin/views/dashboard_hub.php';
require_once ROOT . '/admin/views/modules.php';
require_once ROOT . '/admin/views/cron.php';
require_once ROOT . '/admin/views/storage.php';
require_once ROOT . '/admin/views/logs.php';

// GitHub Update functionality is now in modules/system/github/
// Old github_update module was removed in favor of the new GitHub Center

// Auth middleware for admin routes
Router::middleware(function (string $method, string $uri): bool {
    if (strpos($uri, '/admin') === 0 && $uri !== '/admin/login') {
        if (!Auth::check()) {
            Router::redirect(System::web('admin/login'));
            return false;
        }
    }
    return true;
});

Router::get('/admin/login', function () {
    if (Auth::check()) {
        Router::redirect(System::web('admin'));
    }
    echo renderLogin();
});

Router::post('/admin/login', function () {
    $csrfToken = $_POST['_csrf'] ?? '';
    if (!Auth::validateCsrf($csrfToken)) {
        echo renderLogin('Ошибка безопасности. Попробуйте еще раз.');
        return;
    }
    
    $username = trim((string)($_POST['username'] ?? ''));
    $password = trim((string)($_POST['password'] ?? ''));
if (Auth::login($username, $password)) {
        Router::redirect(System::web('admin'));
    } else {
        echo renderLogin('Неверное имя пользователя или пароль');
    }
});

Router::get('/admin/logout', function () {
    Auth::logout();
    Router::redirect(System::web('admin/login'));
});

Router::get('/admin', function () {
    echo renderLayout('Панель управления', renderDashboard(), 'dashboard');
});

Router::get('/admin/dashboard', function () {
    if (!Auth::check()) {
        header('Location: ' . System::adminUrl('login'));
        exit;
    }
    echo renderLayout('Оперативный центр', renderDashboardHub(), 'dashboard');
});

Router::post('/admin/dashboard/overrides/save', function () {
    if (!Auth::check()) {
        http_response_code(403);
        exit;
    }
    handleDashboardOverridesSave();
});

Router::post('/admin/dashboard/global/save', function () {
    if (!Auth::check()) {
        http_response_code(403);
        exit;
    }
    handleDashboardGlobalSave();
});

Router::post('/admin/dashboard/strategy/action', function () {
    if (!Auth::check()) {
        http_response_code(403);
        exit;
    }
    handleDashboardStrategyAction();
});

Router::post('/admin/dashboard/bot/tick', function () {
    if (!Auth::check()) {
        http_response_code(403);
        exit;
    }
    handleDashboardBotTick();
});

Router::get('/admin/modules', function () {
    echo renderLayout('Модули', renderModules(), 'modules');
});

Router::get('/admin/cron', function () {
    echo renderLayout('Планировщик', renderCron(), 'cron');
});

Router::get('/admin/storage', function () {
    echo renderLayout('Хранилище', renderStorage(), 'storage');
});

Router::get('/admin/logs', function () {
    echo renderLayout('Логи', renderLogs(), 'logs');
});

// Legacy /admin/update routes redirect to new GitHub Center
Router::get('/admin/update', function () {
    // Redirect to new GitHub Center
    Router::redirect(System::web('admin/github'));
});

Router::post('/admin/update', function () {
    // Redirect to new GitHub Center
    Router::redirect(System::web('admin/github'));
});

// ============================================================
// GITHUB CENTER MODULE ROUTES
// ============================================================

// Load GitHub Center module
require_once ROOT . '/modules/system/github/controller.php';
use Modules\System\GitHub\GithubController;

Router::get('/admin/github', function () {
    $controller = GithubController::instance();
    echo $controller->index();
});

Router::get('/admin/github/settings', function () {
    $controller = GithubController::instance();
    echo $controller->settings();
});

Router::post('/admin/github/settings', function () {
    $controller = GithubController::instance();
    $controller->saveSettings();
});

Router::get('/admin/github/test', function () {
    $controller = GithubController::instance();
    echo $controller->test();
});

Router::get('/admin/github/fetch', function () {
    $controller = GithubController::instance();
    echo $controller->fetch();
});

Router::get('/admin/github/update', function () {
    $controller = GithubController::instance();
    echo $controller->update();
});

Router::post('/admin/github/update', function () {
    $controller = GithubController::instance();
    $controller->doUpdate();
});

Router::get('/admin/github/backups', function () {
    $controller = GithubController::instance();
    echo $controller->backups();
});

Router::post('/admin/github/backup', function () {
    $controller = GithubController::instance();
    $controller->createBackup();
});

Router::get('/admin/github/rollback', function () {
    $controller = GithubController::instance();
    echo $controller->rollback();
});

Router::post('/admin/github/rollback', function () {
    $controller = GithubController::instance();
    $controller->doRollback();
});

Router::get('/admin/github/classify', function () {
    $controller = GithubController::instance();
    echo $controller->classify();
});

// GitHub API routes for repo/branch selection
Router::get('/admin/github/api/repos', function () {
    $controller = GithubController::instance();
    echo $controller->apiRepos();
});

Router::get('/admin/github/api/branches', function () {
    $controller = GithubController::instance();
    echo $controller->apiBranches();
});

Router::get('/admin/github/upload', function () {
    $controller = GithubController::instance();
    echo $controller->upload();
});

Router::post('/admin/github/api/upload', function () {
    $controller = GithubController::instance();
    echo $controller->apiUpload();
});

Router::post('/admin/github/api/skip-commits', function () {
    $controller = GithubController::instance();
    $controller->skipCommits();
});

Router::post('/admin/github/api/clear-skipped', function () {
    $controller = GithubController::instance();
    $controller->clearSkippedCommits();
});

// ============================================================
// THEME MANAGER MODULE ROUTES
// ============================================================

// Load Theme Manager module
require_once ROOT . '/modules/system/theme/controller/admin.controller.php';
use Modules\System\Theme\Controller\ThemeController;

Router::get('/admin/theme', function () {
    $controller = ThemeController::instance();
    echo $controller->dashboard();
});

Router::get('/admin/theme/editor', function () {
    $controller = ThemeController::instance();
    echo $controller->editor();
});

Router::get('/admin/theme/editor/{id}', function (string $id) {
    $controller = ThemeController::instance();
    echo $controller->editor($id);
});

Router::post('/admin/theme/save', function () {
    $controller = ThemeController::instance();
    $controller->save();
});

Router::get('/admin/theme/layouts', function () {
    $controller = ThemeController::instance();
    echo $controller->layouts();
});

Router::post('/admin/theme/layouts', function () {
    $controller = ThemeController::instance();
    $controller->layouts();
});

Router::get('/admin/theme/preview', function () {
    $controller = ThemeController::instance();
    echo $controller->preview();
});

Router::get('/admin/theme/create', function () {
    $controller = ThemeController::instance();
    echo $controller->create();
});

Router::post('/admin/theme/create', function () {
    $controller = ThemeController::instance();
    $controller->create();
});

Router::get('/admin/theme/activate', function () {
    $controller = ThemeController::instance();
    $controller->activate();
});

Router::get('/admin/theme/delete', function () {
    $controller = ThemeController::instance();
    $controller->delete();
});

Router::get('/admin/theme/export', function () {
    $controller = ThemeController::instance();
    $controller->export();
});

Router::get('/admin/theme/import', function () {
    $controller = ThemeController::instance();
    echo $controller->import();
});

Router::post('/admin/theme/import', function () {
    $controller = ThemeController::instance();
    $controller->import();
});

// ============================================================
// SYSTEM CONTROL CENTER ROUTES
// ============================================================

// Load System UI controller
require_once ROOT . '/modules/system/ui/controller.php';
use Modules\System\UI\SystemUIController;

Router::get('/admin/system', function () {
    $controller = SystemUIController::instance();
    $controller->dashboard();
});

Router::get('/admin/system/updates', function () {
    $controller = SystemUIController::instance();
    $controller->updates();
});

Router::get('/admin/system/storage', function () {
    $controller = SystemUIController::instance();
    $controller->storage();
});

Router::get('/admin/system/paths', function () {
    $controller = SystemUIController::instance();
    $controller->paths();
});

Router::get('/admin/system/keys', function () {
    $controller = SystemUIController::instance();
    $controller->keys();
});

// KeyCenter API routes
Router::post('/admin/system/keys/add', function () {
    $controller = SystemUIController::instance();
    $controller->keysAdd();
});

Router::post('/admin/system/keys/delete', function () {
    $controller = SystemUIController::instance();
    $controller->keysDelete();
});

Router::post('/admin/system/keys/test', function () {
    $controller = SystemUIController::instance();
    $controller->keysTest();
});

Router::get('/admin/system/logs', function () {
    $controller = SystemUIController::instance();
    $controller->logs();
});

Router::get('/admin/system/health', function () {
    $controller = SystemUIController::instance();
    $controller->health();
});

// System Cron (redirects to main cron page)
Router::get('/admin/system/cron', function () {
    // Show cron management within System UI
    $controller = SystemUIController::instance();
    $controller->cron();
});

// Cron: Toggle enable/disable
Router::post('/admin/system/cron/toggle', function () {
    $controller = SystemUIController::instance();
    $controller->cronToggle();
});

// Cron: Run specific task
Router::post('/admin/system/cron/run-task', function () {
    $controller = SystemUIController::instance();
    $controller->cronRunTask();
});

// Cron: Run all due tasks
Router::post('/admin/system/cron/run', function () {
    $controller = SystemUIController::instance();
    $controller->cronRun();
});

// Cron: Save (add/edit) task
Router::post('/admin/system/cron/save', function () {
    $controller = SystemUIController::instance();
    $controller->cronSave();
});

// Cron: Delete task
Router::post('/admin/system/cron/delete', function () {
    $controller = SystemUIController::instance();
    $controller->cronDelete();
});

// Cron: Clear execution log
Router::post('/admin/system/cron/clear-log', function () {
    $controller = SystemUIController::instance();
    $controller->cronClearLog();
});

// System Pages (within System UI)
Router::get('/admin/system/pages', function () {
    // Redirect to pages manager
    header('Location: ' . \Core\System\System::web('admin/pages'));
    exit;
});

// ============================================================
// PAGES MANAGER ROUTES
// ============================================================

require_once ROOT . '/modules/system/pages/controller.php';
use Modules\System\Pages\PagesController;

Router::get('/admin/pages', function () {
    $controller = PagesController::instance();
    $controller->index();
});

Router::post('/admin/pages/toggle', function () {
    $controller = PagesController::instance();
    $controller->toggle();
});

Router::post('/admin/pages/add', function () {
    $controller = PagesController::instance();
    $controller->add();
});

Router::post('/admin/pages/delete', function () {
    $controller = PagesController::instance();
    $controller->delete();
});

Router::post('/admin/pages/lock', function () {
    $controller = PagesController::instance();
    $controller->lock();
});

Router::post('/admin/pages/roles', function () {
    $controller = PagesController::instance();
    $controller->roles();
});

// ============================================================
// PARSER MANAGER ROUTES
// ============================================================

require_once ROOT . '/modules/system/parser/controller.php';
use Modules\System\Parser\ParserController;

Router::get('/admin/parser', function () {
    $controller = ParserController::instance();
    echo $controller->index();
});

Router::post('/admin/parser/action', function () {
    $controller = ParserController::instance();
    $controller->handleAction();
});

// Parser API routes
Router::get('/admin/parser/api/list', function () {
    $controller = ParserController::instance();
    $controller->apiList();
});

Router::post('/admin/parser/api/run', function () {
    $controller = ParserController::instance();
    $controller->apiRun();
});

Router::post('/admin/parser/api/clear', function () {
    $controller = ParserController::instance();
    $controller->apiClear();
});

Router::get('/admin/parser/api/config', function () {
    $controller = ParserController::instance();
    $controller->apiConfig();
});

Router::post('/admin/parser/api/config/save', function () {
    $controller = ParserController::instance();
    $controller->apiConfigSave();
});

// ============================================================
// SIGNAL MANAGER ROUTES (uses generic CategoryController)
// ============================================================

require_once ROOT . '/modules/system/category/controller.php';
use Modules\System\Category\CategoryController;

Router::get('/admin/signal', function () {
    $controller = CategoryController::instance();
    $controller->setCategory('signal', 'Signals');
    echo $controller->index();
});

Router::post('/admin/signal/action', function () {
    $controller = CategoryController::instance();
    $controller->setCategory('signal', 'Signals');
    $controller->handleAction();
});

Router::get('/admin/signal/api/list', function () {
    $controller = CategoryController::instance();
    $controller->setCategory('signal', 'Signals');
    $controller->apiList();
});

Router::post('/admin/signal/api/run', function () {
    $controller = CategoryController::instance();
    $controller->setCategory('signal', 'Signals');
    $controller->apiRun();
});

Router::post('/admin/signal/api/clear', function () {
    $controller = CategoryController::instance();
    $controller->setCategory('signal', 'Signals');
    $controller->apiClear();
});

Router::get('/admin/signal/api/config', function () {
    $controller = CategoryController::instance();
    $controller->setCategory('signal', 'Signals');
    $controller->apiConfig();
});

Router::post('/admin/signal/api/config/save', function () {
    $controller = CategoryController::instance();
    $controller->setCategory('signal', 'Signals');
    $controller->apiConfigSave();
});


// ============================================================
// PROFIT MANAGER MODULE ROUTES (P2 контур - Trailing / Profit Lock)
// ============================================================


use Modules\System\ProfitManager\ProfitManagerController;

Router::get('/admin/trading/profit', function () {
    $controller = new ProfitManagerController();
    echo $controller->index();
});

Router::get('/admin/trading/profit/api/status', function () {
    $controller = new ProfitManagerController();
    $controller->apiStatus();
});

Router::get('/admin/trading/profit/api/history', function () {
    $controller = new ProfitManagerController();
    $controller->apiAppliedIndex();
});

// ============================================================
// COPYTRADING MODULE ROUTES
// ============================================================

require_once ROOT . '/modules/copytrading/controller.php';

Router::get('/admin/copytrading', function () {
    $controller = new CopytradingController();
    $controller->index();
});

Router::post('/admin/copytrading/run', function () {
    $controller = new CopytradingController();
    $controller->run();
});

Router::post('/admin/copytrading/add', function () {
    $controller = new CopytradingController();
    $controller->addTrader();
});

Router::post('/admin/copytrading/remove', function () {
    $controller = new CopytradingController();
    $controller->removeTrader();
});

Router::get('/admin/copytrading/api/positions', function () {
    $controller = new CopytradingController();
    $controller->apiPositions();
});

Router::get('/admin/copytrading/api/history', function () {
    $controller = new CopytradingController();
    $controller->apiHistory();
});

// ============================================================
// BOT MODULE ROUTES (Operator Control Layer)
// ============================================================

require_once ROOT . '/modules/bot/admin/controller.php';
use Modules\Bot\Admin\BotAdminController;

Router::get('/admin/bot', function () {
    $controller = BotAdminController::instance();
    echo renderLayout('Bot — Управление', $controller->dashboard(), 'bot');
});

Router::get('/admin/bot/api/status', function () {
    $controller = BotAdminController::instance();
    $controller->apiStatus();
});

Router::post('/admin/bot/api/overrides/save', function () {
    $controller = BotAdminController::instance();
    $controller->saveOverrides();
});

// ============================================================
// STOP MANAGER MODULE ROUTES (Operator Control Layer)
// ============================================================

require_once ROOT . '/modules/stop_manager/admin/controller.php';
use Modules\StopManager\Admin\StopManagerAdminController;

Router::get('/admin/stop-manager', function () {
    if (!Auth::check()) {
        Router::redirect(System::web('admin/login'));
        return;
    }
    $controller = StopManagerAdminController::instance();
    echo renderLayout('Stop Manager', $controller->dashboard(), 'stop_manager');
});

Router::post('/admin/stop-manager/tick', function () {
    $controller = StopManagerAdminController::instance();
    $controller->tick();
});

Router::post('/admin/stop-manager/config/save', function () {
    $controller = StopManagerAdminController::instance();
    $controller->configSave();
});

Router::post('/admin/dashboard/stop-manager/tick', function () {
    if (!Auth::check()) {
        http_response_code(403);
        exit;
    }
    handleDashboardStopManagerTick();
});

Router::post('/admin/dashboard/strategy/toggle', function () {
    if (!Auth::check()) {
        http_response_code(403);
        exit;
    }
    handleDashboardStrategyToggle();
});

Router::post('/admin/dashboard/bot/toggle', function () {
    if (!Auth::check()) {
        http_response_code(403);
        exit;
    }
    handleDashboardBotToggle();
});

Router::post('/admin/dashboard/stop-manager/toggle', function () {
    if (!Auth::check()) {
        http_response_code(403);
        exit;
    }
    handleDashboardSmToggle();
});

Router::post('/admin/dashboard/profit-manager/tick', function () {
    if (!Auth::check()) {
        http_response_code(403);
        exit;
    }
    handleDashboardPmTick();
});

Router::post('/admin/dashboard/profit-manager/toggle', function () {
    if (!Auth::check()) {
        http_response_code(403);
        exit;
    }
    handleDashboardPmToggle();
});

Router::post('/admin/dashboard/profit-manager/config/save', function () {
    if (!Auth::check()) {
        http_response_code(403);
        exit;
    }
    handleDashboardPmConfigSave();
});

Router::post('/admin/dashboard/chain-run', function () {
    if (!Auth::check()) {
        http_response_code(403);
        exit;
    }
    handleDashboardChainRun();
});

// ============================================================
// ADMIN API ROUTES
// ============================================================

Router::post('/admin/api/modules/enable', function () {
    $data = json_decode(file_get_contents('php://input'), true);
    $module = $data['module'] ?? '';
    
    try {
        ModuleManager::instance()->enable($module);
        Router::json(['success' => true]);
    } catch (\Throwable $e) {
        Router::json(['success' => false, 'message' => $e->getMessage()], 400);
    }
});

Router::post('/admin/api/modules/disable', function () {
    $data = json_decode(file_get_contents('php://input'), true);
    $module = $data['module'] ?? '';
    
    try {
        ModuleManager::instance()->disable($module);
        Router::json(['success' => true]);
    } catch (\Throwable $e) {
        Router::json(['success' => false, 'message' => $e->getMessage()], 400);
    }
});

Router::post('/admin/api/cron/enable', function () {
    $data = json_decode(file_get_contents('php://input'), true);
    $taskId = $data['task_id'] ?? '';
    
    try {
        CronManager::instance()->enable($taskId);
        Router::json(['success' => true]);
    } catch (\Throwable $e) {
        Router::json(['success' => false, 'message' => $e->getMessage()], 400);
    }
});

Router::post('/admin/api/cron/disable', function () {
    $data = json_decode(file_get_contents('php://input'), true);
    $taskId = $data['task_id'] ?? '';
    
    try {
        CronManager::instance()->disable($taskId);
        Router::json(['success' => true]);
    } catch (\Throwable $e) {
        Router::json(['success' => false, 'message' => $e->getMessage()], 400);
    }
});

Router::post('/admin/api/cron/run', function () {
    try {
        $results = CronManager::instance()->run();
        Router::json(['success' => true, 'count' => count($results), 'results' => $results]);
    } catch (\Throwable $e) {
        Router::json(['success' => false, 'message' => $e->getMessage()], 400);
    }
});

Router::post('/admin/api/logs/clear', function () {
    try {
        Logger::instance()->clear();
        Router::json(['success' => true]);
    } catch (\Throwable $e) {
        Router::json(['success' => false, 'message' => $e->getMessage()], 400);
    }
});

Router::post('/admin/api/storage/delete', function () {
    $data = json_decode(file_get_contents('php://input'), true);
    $key = $data['key'] ?? '';
    
    try {
        StorageManager::instance()->delete($key);
        Router::json(['success' => true]);
    } catch (\Throwable $e) {
        Router::json(['success' => false, 'message' => $e->getMessage()], 400);
    }
});

// ============================================================
// STATIC ASSETS
// ============================================================

Router::get('/admin/assets/css/style.css', function () {
    header('Content-Type: text/css');
    readfile(ROOT . '/admin/assets/css/style.css');
});

Router::get('/admin/assets/js/app.js', function () {
    header('Content-Type: application/javascript');
    readfile(ROOT . '/admin/assets/js/app.js');
});

// ============================================================
// COIN PASSPORT MODULE ROUTES
// ============================================================

require_once ROOT . '/modules/system/coin_passport/controller.php';

// UI
Router::get('/admin/coin_passport', function () {
    $controller = new CoinPassportController();
    $controller->index();
});

Router::get('/admin/coin_passport/symbol/{symbol}', function (string $symbol) {
    $controller = new CoinPassportController();
    $controller->detail($symbol);
});

// Actions
Router::post('/admin/coin_passport/rebuild', function () {
    $controller = new CoinPassportController();
    $controller->rebuildAll();
});

Router::post('/admin/coin_passport/rebuild/{symbol}', function (string $symbol) {
    $controller = new CoinPassportController();
    $controller->rebuildSymbol($symbol);
});

// API (read-only, for future Brain/Bot integration)
Router::get('/admin/coin_passport/api/passports', function () {
    $controller = new CoinPassportController();
    $controller->apiPassports();
});

Router::get('/admin/coin_passport/api/passport/{symbol}', function (string $symbol) {
    $controller = new CoinPassportController();
    $controller->apiPassport($symbol);
});

Router::get('/admin/coin_passport/api/guidance/{symbol}', function (string $symbol) {
    $controller = new CoinPassportController();
    $controller->apiGuidance($symbol);
});

// ============================================================
// PATTERN ENGINE MODULE ROUTES
// Pattern Engine is a backend/API module only.
// Pattern Engine is a backend/API module only.
// No standalone GET page routes are registered here.
// ============================================================

require_once ROOT . '/modules/system/pattern_engine/controller.php';

// Actions
Router::post('/admin/pattern_engine/settings/save', function () {
    $controller = new PatternEngineController();
    $controller->saveSettings();
});

Router::post('/admin/pattern_engine/clear', function () {
    $controller = new PatternEngineController();
    $controller->clearStorage();
});

Router::post('/admin/pattern_engine/run', function () {
    $controller = new PatternEngineController();
    $controller->runNow();
});

// API (read-only, for Demo Execution / AI Shadow / Simulator integration)
Router::get('/admin/pattern_engine/api/signals', function () {
    $controller = new PatternEngineController();
    $controller->apiSignals();
});

Router::get('/admin/pattern_engine/api/scenarios', function () {
    $controller = new PatternEngineController();
    $controller->apiScenarios();
});

Router::get('/admin/pattern_engine/api/demo_signals', function () {
    $controller = new PatternEngineController();
    $controller->apiDemoSignals();
});

Router::get('/admin/pattern_engine/api/shadow_signals', function () {
    $controller = new PatternEngineController();
    $controller->apiShadowSignals();
});

Router::get('/admin/pattern_engine/api/sim_signals', function () {
    $controller = new PatternEngineController();
    $controller->apiSimSignals();
});

// ============================================================
// STRATEGY MODULE ROUTES — Fish
// ============================================================
// Admin UI routes for the fish strategy module.
// Discovery: strategy.fish SystemPaths key (from manifest.json category=strategy)
// All pages load from modules/strategy/fish/admin/ and wrap via renderLayout.

Router::get('/admin/strategy/fish', function () {
    if (!\Core\Auth\Auth::check()) {
        Router::redirect(System::web('admin/login'));
        return;
    }
    $moduleDir = \Core\System\SystemPaths::instance()->get('strategy.fish');
    ob_start();
    require $moduleDir . '/admin/page_index.php';
    $content = ob_get_clean();
    require_once System::path('root') . '/admin/views/layout.php';
    echo renderLayout('Fish Strategy', $content, 'strategy', []);
});

Router::get('/admin/strategy/fish/config', function () {
    if (!\Core\Auth\Auth::check()) {
        Router::redirect(System::web('admin/login'));
        return;
    }
    $moduleDir = \Core\System\SystemPaths::instance()->get('strategy.fish');
    ob_start();
    require $moduleDir . '/admin/page_config.php';
    $content = ob_get_clean();
    require_once System::path('root') . '/admin/views/layout.php';
    echo renderLayout('Fish — Config', $content, 'strategy', []);
});

Router::get('/admin/strategy/fish/stats', function () {
    if (!\Core\Auth\Auth::check()) {
        Router::redirect(System::web('admin/login'));
        return;
    }
    $moduleDir = \Core\System\SystemPaths::instance()->get('strategy.fish');
    ob_start();
    require $moduleDir . '/admin/page_stats.php';
    $content = ob_get_clean();
    require_once System::path('root') . '/admin/views/layout.php';
    echo renderLayout('Fish — Stats', $content, 'strategy', []);
});

Router::get('/admin/strategy/fish/runtime', function () {
    if (!\Core\Auth\Auth::check()) {
        Router::redirect(System::web('admin/login'));
        return;
    }
    $moduleDir = \Core\System\SystemPaths::instance()->get('strategy.fish');
    ob_start();
    require $moduleDir . '/admin/page_runtime.php';
    $content = ob_get_clean();
    require_once System::path('root') . '/admin/views/layout.php';
    echo renderLayout('Fish — Runtime', $content, 'strategy', []);
});

Router::post('/admin/strategy/fish/ajax', function () {
    if (!\Core\Auth\Auth::check()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        return;
    }
    $moduleDir = \Core\System\SystemPaths::instance()->get('strategy.fish');
    require $moduleDir . '/admin/ajax_fish.php';
});

// ============================================================
// strategy.pattern routes
// ============================================================

Router::get('/admin/strategy/pattern', function () {
    if (!\Core\Auth\Auth::check()) {
        Router::redirect(System::web('admin/login'));
        return;
    }
    $moduleDir = \Core\System\SystemPaths::instance()->get('strategy.pattern');
    ob_start();
    require $moduleDir . '/admin/page_index.php';
    $content = ob_get_clean();
    require_once System::path('root') . '/admin/views/layout.php';
    echo renderLayout('Pattern Strategy', $content, 'strategy', []);
});

Router::get('/admin/strategy/pattern/config', function () {
    if (!\Core\Auth\Auth::check()) {
        Router::redirect(System::web('admin/login'));
        return;
    }
    $moduleDir = \Core\System\SystemPaths::instance()->get('strategy.pattern');
    ob_start();
    require $moduleDir . '/admin/page_config.php';
    $content = ob_get_clean();
    require_once System::path('root') . '/admin/views/layout.php';
    echo renderLayout('Pattern — Config', $content, 'strategy', []);
});

Router::get('/admin/strategy/pattern/stats', function () {
    if (!\Core\Auth\Auth::check()) {
        Router::redirect(System::web('admin/login'));
        return;
    }
    $moduleDir = \Core\System\SystemPaths::instance()->get('strategy.pattern');
    ob_start();
    require $moduleDir . '/admin/page_stats.php';
    $content = ob_get_clean();
    require_once System::path('root') . '/admin/views/layout.php';
    echo renderLayout('Pattern — Stats', $content, 'strategy', []);
});

Router::post('/admin/strategy/pattern/ajax', function () {
    if (!\Core\Auth\Auth::check()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        return;
    }
    $moduleDir = \Core\System\SystemPaths::instance()->get('strategy.pattern');
    require $moduleDir . '/admin/ajax_pattern.php';
});

// ============================================================
// strategy.double_bottom_long routes
// ============================================================

Router::get('/admin/strategy/double_bottom_long', function () {
    if (!\Core\Auth\Auth::check()) {
        Router::redirect(System::web('admin/login'));
        return;
    }
    $moduleDir = \Core\System\SystemPaths::instance()->get('strategy.double_bottom_long');
    ob_start();
    require $moduleDir . '/admin/page_index.php';
    $content = ob_get_clean();
    require_once System::path('root') . '/admin/views/layout.php';
    echo renderLayout('Double Bottom Long', $content, 'strategy', []);
});

Router::get('/admin/strategy/double_bottom_long/config', function () {
    if (!\Core\Auth\Auth::check()) {
        Router::redirect(System::web('admin/login'));
        return;
    }
    $moduleDir = \Core\System\SystemPaths::instance()->get('strategy.double_bottom_long');
    ob_start();
    require $moduleDir . '/admin/page_config.php';
    $content = ob_get_clean();
    require_once System::path('root') . '/admin/views/layout.php';
    echo renderLayout('Double Bottom Long — Config', $content, 'strategy', []);
});

Router::get('/admin/strategy/double_bottom_long/stats', function () {
    if (!\Core\Auth\Auth::check()) {
        Router::redirect(System::web('admin/login'));
        return;
    }
    $moduleDir = \Core\System\SystemPaths::instance()->get('strategy.double_bottom_long');
    ob_start();
    require $moduleDir . '/admin/page_stats.php';
    $content = ob_get_clean();
    require_once System::path('root') . '/admin/views/layout.php';
    echo renderLayout('Double Bottom Long — Stats', $content, 'strategy', []);
});

Router::post('/admin/strategy/double_bottom_long/ajax', function () {
    if (!\Core\Auth\Auth::check()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        return;
    }
    $moduleDir = \Core\System\SystemPaths::instance()->get('strategy.double_bottom_long');
    require $moduleDir . '/admin/ajax_dbl.php';
});

Router::get('/admin/strategy/double_bottom_long/runtime', function () {
    if (!\Core\Auth\Auth::check()) {
        Router::redirect(System::web('admin/login'));
        return;
    }
    $moduleDir = \Core\System\SystemPaths::instance()->get('strategy.double_bottom_long');
    ob_start();
    require $moduleDir . '/admin/page_runtime.php';
    $content = ob_get_clean();
    require_once System::path('root') . '/admin/views/layout.php';
    echo renderLayout('Double Bottom Long — Runtime', $content, 'strategy', []);
});

// ============================================================
// DISPATCH
// ============================================================

Router::dispatch();

/* RULES
- Purpose: Single web entry point - all routes via Router
- Config sources: Uses System::init() to load configs
- Paths: Defines ROOT, then only System::path() / System::web()
- Logs: Requests may write to Logger
- Prohibitions:
  - NO direct file includes for routing (use Router)
  - NO hardcoded redirects (use System::web())
  - NO header("Location: /path") - use Router::redirect(System::web())
*/
