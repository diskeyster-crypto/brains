<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\System\System;
use Core\Storage\StorageManager;
use Core\Logger\Logger;

final class Auth
{
    private const STORAGE_KEY = 'users';
    private const SESSION_KEY = 'auth_user';
    private const CSRF_KEY = 'csrf_token';
    
    private static ?self $instance = null;
    private bool $sessionStarted = false;

    private function __construct()
    {
        $this->ensureSession();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function ensureSession(): void
    {
        if ($this->sessionStarted) {
            return;
        }

        if (php_sapi_name() === 'cli') {
            $this->sessionStarted = true;
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            $sessionPath = System::path('sessions');
            if (!is_dir($sessionPath)) {
                mkdir($sessionPath, 0755, true);
            }
            
            session_save_path($sessionPath);
            
            // Security: httponly and samesite cookies
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            
            session_start();
        }
        
        $this->sessionStarted = true;
    }

    public static function login(string $username, string $password): bool
    {
        $instance = self::instance();
        
        $users = $instance->getUsers();
        
        if (!isset($users[$username])) {
            Logger::security("Login failed: user not found", ['username' => $username]);
            return false;
        }

        $user = $users[$username];
        
        if (!password_verify($password, $user['password'])) {
            Logger::security("Login failed: invalid password", ['username' => $username]);
            return false;
        }

        // Security: regenerate session ID on login
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION[self::SESSION_KEY] = [
            'username' => $username,
            'role' => $user['role'] ?? 'user',
            'logged_in_at' => date('Y-m-d H:i:s'),
        ];

        // Generate new CSRF token on login
        $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));

        Logger::security("User logged in", ['username' => $username]);
        return true;
    }

    public static function logout(): void
    {
        $instance = self::instance();
        
        $username = $_SESSION[self::SESSION_KEY]['username'] ?? 'unknown';
        unset($_SESSION[self::SESSION_KEY]);
        unset($_SESSION[self::CSRF_KEY]);
        
        // Regenerate session ID on logout
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        
        Logger::security("User logged out", ['username' => $username]);
    }

    public static function check(): bool
    {
        self::instance();
        return isset($_SESSION[self::SESSION_KEY]);
    }

    public static function user(): array
    {
        self::instance();
        return $_SESSION[self::SESSION_KEY] ?? [];
    }

    public static function hasRole(string $role): bool
    {
        self::instance();
        $user = self::user();
        return ($user['role'] ?? '') === $role;
    }

    public static function isAdmin(): bool
    {
        return self::hasRole('admin');
    }

    /**
     * Get CSRF token for forms
     */
    public static function csrfToken(): string
    {
        self::instance();
        if (!isset($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CSRF_KEY];
    }

    /**
     * Validate CSRF token from form submission
     */
    public static function validateCsrf(string $token): bool
    {
        self::instance();
        $valid = isset($_SESSION[self::CSRF_KEY]) && hash_equals($_SESSION[self::CSRF_KEY], $token);
        if (!$valid) {
            Logger::security("CSRF validation failed");
        }
        return $valid;
    }

    /**
     * Render CSRF hidden input for forms
     */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    private function getUsers(): array
    {
        $storage = StorageManager::instance();
        $users = $storage->get(self::STORAGE_KEY);
        
        if (empty($users)) {
            $users = $this->createDefaultAdmin();
        }
        
        return $users;
    }

    /**
     * Create default admin with RANDOM password (not admin/admin!)
     */
    private function createDefaultAdmin(): array
    {
        $storage = StorageManager::instance();
        
        // Generate random secure password
        $randomPassword = bin2hex(random_bytes(8)); // 16 char hex password
        
        $users = [
            'admin' => [
                'password' => password_hash($randomPassword, PASSWORD_DEFAULT),
                'role' => 'admin',
                'created_at' => date('Y-m-d H:i:s'),
                'initial_password' => true, // Flag to force password change
            ],
        ];
        
        $storage->set(self::STORAGE_KEY, $users);
        
        // Store initial password in a separate file for first-time setup
        $credentialsFile = System::path('storage') . '/initial_credentials.txt';
        file_put_contents($credentialsFile, "Admin credentials (DELETE THIS FILE AFTER FIRST LOGIN!):\nUsername: admin\nPassword: {$randomPassword}\n", LOCK_EX);
        chmod($credentialsFile, 0600);
        
        Logger::security("Default admin user created with random password", ['credentials_file' => $credentialsFile]);
        
        return $users;
    }

    /**
     * Check if this is the initial password that needs to be changed
     */
    public static function needsPasswordChange(): bool
    {
        self::instance();
        $user = self::user();
        if (empty($user['username'])) {
            return false;
        }
        
        $storage = StorageManager::instance();
        $users = $storage->get(self::STORAGE_KEY) ?? [];
        
        return $users[$user['username']]['initial_password'] ?? false;
    }

    public static function createUser(string $username, string $password, string $role = 'user'): bool
    {
        $instance = self::instance();
        $storage = StorageManager::instance();
        
        $users = $instance->getUsers();
        
        if (isset($users[$username])) {
            return false;
        }

        $users[$username] = [
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $storage->set(self::STORAGE_KEY, $users);
        Logger::security("User created", ['username' => $username, 'role' => $role]);
        
        return true;
    }

    public static function deleteUser(string $username): bool
    {
        $instance = self::instance();
        $storage = StorageManager::instance();
        
        $users = $instance->getUsers();
        
        if (!isset($users[$username])) {
            return false;
        }

        if ($username === 'admin') {
            return false;
        }

        unset($users[$username]);
        $storage->set(self::STORAGE_KEY, $users);
        Logger::security("User deleted", ['username' => $username]);
        
        return true;
    }

    public static function updatePassword(string $username, string $newPassword): bool
    {
        $instance = self::instance();
        $storage = StorageManager::instance();
        
        $users = $instance->getUsers();
        
        if (!isset($users[$username])) {
            return false;
        }

        $users[$username]['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $users[$username]['initial_password'] = false; // Clear the initial password flag
        $storage->set(self::STORAGE_KEY, $users);
        Logger::security("Password updated", ['username' => $username]);
        
        // Remove initial credentials file if it exists
        $credentialsFile = System::path('storage') . '/initial_credentials.txt';
        if (file_exists($credentialsFile)) {
            unlink($credentialsFile);
        }
        
        return true;
    }

    public static function getAllUsers(): array
    {
        $instance = self::instance();
        $users = $instance->getUsers();
        
        $result = [];
        foreach ($users as $username => $data) {
            $result[$username] = [
                'username' => $username,
                'role' => $data['role'],
                'created_at' => $data['created_at'] ?? 'unknown',
            ];
        }
        
        return $result;
    }
}

/* RULES
- Purpose: Admin authentication with session management
- Config sources: Uses StorageManager for user data
- Paths: Uses System::path('storage') for credentials
- Logs: Security events to Logger::CHANNEL_SECURITY
- Prohibitions:
  - NO plain-text password storage (bcrypt only)
  - NO admin/admin default credentials
  - NO session fixation (regenerate on login/logout)
*/
