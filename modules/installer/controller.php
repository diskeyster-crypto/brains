<?php
/**
 * Installer Controller
 * 
 * Handles web-based installation wizard for Tredercopis Core OS
 * 
 * Routes:
 *   GET  /install         - Show installation wizard
 *   POST /install         - Process installation step
 *   GET  /install/complete - Show completion page
 * 
 * @package Modules\Installer
 */

namespace Modules\Installer;

use Core\System\System;
use Core\Router\Router;
use Core\KeyCenter\KeyCenter;
use Core\Storage\StorageManager;

class InstallerController
{
    private static ?self $instance = null;
    
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Check if system is already installed (uses SystemState)
     */
    public static function isInstalled(): bool
    {
        return System::state()->isInstalled();
    }
    
    /**
     * Main installer page
     */
    public function index(): string
    {
        // INSTALLER GUARD: If already installed, redirect to admin
        if (self::isInstalled()) {
            Router::redirect(System::web('admin'));
            return '';
        }
        
        // Start session for wizard state
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Generate CSRF token
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        $step = (int)($_SESSION['install_step'] ?? 1);
        $error = $_SESSION['install_error'] ?? null;
        unset($_SESSION['install_error']);
        
        return $this->renderStep($step, $error);
    }
    
    /**
     * Process installation step (POST)
     */
    public function process(): void
    {
        // INSTALLER GUARD: If already installed, redirect to admin
        if (self::isInstalled()) {
            Router::redirect(System::web('admin'));
            return;
        }
        
        // Start session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Validate CSRF
        $token = $_POST['_csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            $_SESSION['install_error'] = 'Ошибка безопасности. Попробуйте еще раз.';
            Router::redirect(System::web('install'));
            return;
        }
        
        $step = (int)($_SESSION['install_step'] ?? 1);
        $action = $_POST['action'] ?? 'next';
        
        if ($action === 'back' && $step > 1) {
            $_SESSION['install_step'] = $step - 1;
            Router::redirect(System::web('install'));
            return;
        }
        
        // Process current step
        $result = $this->processStep($step);
        
        if ($result['success']) {
            if ($step >= 5) {
                // Installation complete
                $this->finalize();
                Router::redirect(System::web('admin'));
                return;
            }
            $_SESSION['install_step'] = $step + 1;
        } else {
            $_SESSION['install_error'] = $result['error'];
        }
        
        Router::redirect(System::web('install'));
    }
    
    /**
     * Process individual step
     */
    private function processStep(int $step): array
    {
        switch ($step) {
            case 1: // Requirements check
                $requirements = $this->checkRequirements();
                $allPassed = !in_array(false, array_column($requirements, 'passed'));
                return ['success' => $allPassed, 'error' => $allPassed ? null : 'Не все требования выполнены'];
                
            case 2: // Create directories
                return $this->createDirectories();
                
            case 3: // Admin account
                return $this->createAdminAccount();
                
            case 4: // Bybit API (optional)
                return $this->configureBybitApi();
                
            case 5: // Finalize
                return ['success' => true];
                
            default:
                return ['success' => false, 'error' => 'Неизвестный шаг'];
        }
    }
    
    /**
     * Check system requirements
     */
    public function checkRequirements(): array
    {
        $requirements = [];
        
        // PHP version
        $requirements['php_version'] = [
            'name' => 'PHP версия',
            'required' => '8.2.0',
            'current' => PHP_VERSION,
            'passed' => version_compare(PHP_VERSION, '8.2.0', '>='),
        ];
        
        // Extensions
        $extensions = ['json', 'curl', 'openssl', 'mbstring'];
        foreach ($extensions as $ext) {
            $requirements['ext_' . $ext] = [
                'name' => 'Расширение ' . $ext,
                'required' => 'Установлено',
                'current' => extension_loaded($ext) ? 'Установлено' : 'Не установлено',
                'passed' => extension_loaded($ext),
            ];
        }
        
        // Writable directories
        $dirs = [
            'storage' => System::path('storage'),
            'runtime' => System::path('runtime'),
        ];
        
        foreach ($dirs as $name => $path) {
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);
            $requirements['dir_' . $name] = [
                'name' => 'Директория ' . $name,
                'required' => 'Доступна для записи',
                'current' => $writable ? 'Доступна' : ($exists ? 'Только чтение' : 'Не существует'),
                'passed' => $writable,
            ];
        }
        
        return $requirements;
    }
    
    /**
     * Create required directories
     */
    private function createDirectories(): array
    {
        $dirs = [
            System::path('storage'),
            System::path('storage') . '/system',
            System::path('runtime'),
            System::path('logs'),
            System::path('cache'),
            System::path('sessions'),
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true)) {
                    return ['success' => false, 'error' => "Не удалось создать директорию: {$dir}"];
                }
            }
        }
        
        return ['success' => true];
    }
    
    /**
     * Create admin account using AuthManager
     */
    private function createAdminAccount(): array
    {
        $username = trim($_POST['admin_username'] ?? '');
        $password = $_POST['admin_password'] ?? '';
        $passwordConfirm = $_POST['admin_password_confirm'] ?? '';
        
        if (empty($username)) {
            return ['success' => false, 'error' => 'Введите имя пользователя'];
        }
        
        if (strlen($username) < 3) {
            return ['success' => false, 'error' => 'Имя пользователя должно быть не менее 3 символов'];
        }
        
        if (empty($password)) {
            return ['success' => false, 'error' => 'Введите пароль'];
        }
        
        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'Пароль должен быть не менее 6 символов'];
        }
        
        if ($password !== $passwordConfirm) {
            return ['success' => false, 'error' => 'Пароли не совпадают'];
        }
        
        // Create admin user via AuthManager
        $result = System::auth()->createUser($username, $password, 'admin');
        
        if (!$result) {
            return ['success' => false, 'error' => 'Пользователь уже существует'];
        }
        
        // Store admin username in session for state
        $_SESSION['admin_username'] = $username;
        
        System::log('security', 'Admin account created during installation', ['username' => $username]);
        
        return ['success' => true];
    }
    
    /**
     * Configure Bybit API (optional)
     */
    private function configureBybitApi(): array
    {
        $apiKey = trim($_POST['bybit_api_key'] ?? '');
        $apiSecret = trim($_POST['bybit_api_secret'] ?? '');
        $skip = isset($_POST['skip_bybit']);
        
        if ($skip || (empty($apiKey) && empty($apiSecret))) {
            return ['success' => true];
        }
        
        if (empty($apiKey) || empty($apiSecret)) {
            return ['success' => false, 'error' => 'Введите оба ключа API или пропустите этот шаг'];
        }
        
        // Save to KeyCenter
        $keyCenter = KeyCenter::instance();
        $result = $keyCenter->setCredentials('bybit', 'default', [
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
        ]);
        
        if (!$result) {
            return ['success' => false, 'error' => 'Не удалось сохранить ключи API'];
        }
        
        System::log('security', 'Bybit API keys configured during installation');
        
        return ['success' => true];
    }
    
    /**
     * Finalize installation using SystemState
     */
    private function finalize(): void
    {
        // Mark system as installed via SystemState
        System::state()->markInstalled([
            'core_version' => '1.0.0',
            'installer_version' => '1.0.0',
            'admin_user_created' => true,
            'admin_username' => $_SESSION['admin_username'] ?? 'admin',
        ]);
        
        // Clear session
        unset($_SESSION['install_step']);
        unset($_SESSION['csrf_token']);
        unset($_SESSION['admin_username']);
        
        System::log('system', 'Tredercopis Core installation completed');
    }
    
    /**
     * Render step HTML
     */
    private function renderStep(int $step, ?string $error = null): string
    {
        $csrf = $_SESSION['csrf_token'] ?? '';
        $requirements = $step === 1 ? $this->checkRequirements() : [];
        
        ob_start();
        include __DIR__ . '/views/wizard.php';
        return ob_get_clean();
    }
}

/* RULES
- Purpose: Web installation wizard controller
- Config sources: None (creates initial configs)
- Paths: Uses System::path() exclusively
- Logs: Writes to system and security logs
- Prohibitions:
  - NO hardcoded paths
  - NO direct file redirects (use System::web())
  - Must validate CSRF on all POST requests
  - Uses System::state() for installation status
*/
