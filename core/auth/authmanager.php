<?php
/**
 * Auth Manager
 * 
 * Core authentication layer for System::auth() API.
 * Provides unified authentication interface for the entire system.
 * 
 * @package Core\Auth
 */

declare(strict_types=1);

namespace Core\Auth;

use Core\System\System;
use Core\Storage\StorageManager;

/**
 * AuthManager - System authentication service
 * 
 * API:
 *   System::auth()->login($username, $password): bool
 *   System::auth()->logout(): void
 *   System::auth()->check(): bool
 *   System::auth()->user(): array
 *   System::auth()->isAdmin(): bool
 *   System::auth()->createUser($username, $password, $role): bool
 */
final class AuthManager
{
    private static ?self $instance = null;
    
    private const SESSION_KEY = 'auth_user';
    private const CSRF_KEY = 'csrf_token';
    private const USERS_STORAGE = 'system/users';
    
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

    /**
     * Ensure session is started
     */
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
            
            // Security settings
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

    /**
     * Login user
     * 
     * @param string $username Username
     * @param string $password Password
     * @return bool True if login successful
     */
    public function login(string $username, string $password): bool
    {
        $users = $this->loadUsers();
        
        if (!isset($users[$username])) {
            System::log('security', 'Login failed: user not found', ['username' => $username]);
            return false;
        }

        $user = $users[$username];
        
        if (!password_verify($password, $user['password'])) {
            System::log('security', 'Login failed: invalid password', ['username' => $username]);
            return false;
        }

        // Regenerate session ID for security
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION[self::SESSION_KEY] = [
            'username' => $username,
            'role' => $user['role'] ?? 'user',
            'logged_in_at' => date('Y-m-d H:i:s'),
        ];

        // Generate new CSRF token
        $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));

        System::log('security', 'User logged in', ['username' => $username]);
        return true;
    }

    /**
     * Logout current user
     */
    public function logout(): void
    {
        $username = $_SESSION[self::SESSION_KEY]['username'] ?? 'unknown';
        
        unset($_SESSION[self::SESSION_KEY]);
        unset($_SESSION[self::CSRF_KEY]);
        
        // Regenerate session ID for security
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        
        System::log('security', 'User logged out', ['username' => $username]);
    }

    /**
     * Check if user is authenticated
     * 
     * @return bool True if authenticated
     */
    public function check(): bool
    {
        return isset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Get current user data
     * 
     * @return array User data or empty array
     */
    public function user(): array
    {
        return $_SESSION[self::SESSION_KEY] ?? [];
    }

    /**
     * Get current username
     * 
     * @return string|null Username or null
     */
    public function username(): ?string
    {
        return $_SESSION[self::SESSION_KEY]['username'] ?? null;
    }

    /**
     * Check if user has specific role
     * 
     * @param string $role Role to check
     * @return bool True if user has role
     */
    public function hasRole(string $role): bool
    {
        $user = $this->user();
        return ($user['role'] ?? '') === $role;
    }

    /**
     * Check if current user is admin
     * 
     * @return bool True if admin
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Get CSRF token
     * 
     * @return string CSRF token
     */
    public function csrfToken(): string
    {
        if (!isset($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CSRF_KEY];
    }

    /**
     * Validate CSRF token
     * 
     * @param string $token Token to validate
     * @return bool True if valid
     */
    public function validateCsrf(string $token): bool
    {
        $valid = isset($_SESSION[self::CSRF_KEY]) && hash_equals($_SESSION[self::CSRF_KEY], $token);
        if (!$valid) {
            System::log('security', 'CSRF validation failed');
        }
        return $valid;
    }

    /**
     * Render CSRF hidden input
     * 
     * @return string HTML hidden input
     */
    public function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($this->csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Create a new user
     * 
     * @param string $username Username
     * @param string $password Password (plain text)
     * @param string $role User role
     * @return bool True if created
     */
    public function createUser(string $username, string $password, string $role = 'user'): bool
    {
        $users = $this->loadUsers();
        
        if (isset($users[$username])) {
            return false; // User already exists
        }

        $users[$username] = [
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $this->saveUsers($users);
        System::log('security', 'User created', ['username' => $username, 'role' => $role]);
        
        return true;
    }

    /**
     * Update user password
     * 
     * @param string $username Username
     * @param string $newPassword New password (plain text)
     * @return bool True if updated
     */
    public function updatePassword(string $username, string $newPassword): bool
    {
        $users = $this->loadUsers();
        
        if (!isset($users[$username])) {
            return false;
        }

        $users[$username]['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $users[$username]['password_changed_at'] = date('Y-m-d H:i:s');
        
        $this->saveUsers($users);
        System::log('security', 'Password updated', ['username' => $username]);
        
        return true;
    }

    /**
     * Delete user
     * 
     * @param string $username Username
     * @return bool True if deleted
     */
    public function deleteUser(string $username): bool
    {
        $users = $this->loadUsers();
        
        if (!isset($users[$username])) {
            return false;
        }

        // Cannot delete last admin
        if ($users[$username]['role'] === 'admin') {
            $adminCount = count(array_filter($users, fn($u) => ($u['role'] ?? '') === 'admin'));
            if ($adminCount <= 1) {
                return false;
            }
        }

        unset($users[$username]);
        $this->saveUsers($users);
        System::log('security', 'User deleted', ['username' => $username]);
        
        return true;
    }

    /**
     * Get all users (without passwords)
     * 
     * @return array Users list
     */
    public function getAllUsers(): array
    {
        $users = $this->loadUsers();
        $result = [];
        
        foreach ($users as $username => $data) {
            $result[$username] = [
                'username' => $username,
                'role' => $data['role'] ?? 'user',
                'created_at' => $data['created_at'] ?? null,
            ];
        }
        
        return $result;
    }

    /**
     * Check if any users exist
     * 
     * @return bool True if users exist
     */
    public function hasUsers(): bool
    {
        $users = $this->loadUsers();
        return !empty($users);
    }

    /**
     * Load users from storage
     */
    private function loadUsers(): array
    {
        $path = $this->getUsersStoragePath();
        
        if (!file_exists($path)) {
            return [];
        }
        
        $users = include $path;
        return is_array($users) ? $users : [];
    }

    /**
     * Save users to storage
     */
    private function saveUsers(array $users): void
    {
        $path = $this->getUsersStoragePath();
        $dir = dirname($path);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $content = "<?php\n/**\n * Users storage (auto-generated)\n * DO NOT EDIT MANUALLY\n */\nreturn " . var_export($users, true) . ";\n";
        
        // Atomic write
        $tempPath = $path . '.tmp';
        file_put_contents($tempPath, $content, LOCK_EX);
        rename($tempPath, $path);
        
        // Secure permissions
        chmod($path, 0600);
    }

    /**
     * Get users storage path
     */
    private function getUsersStoragePath(): string
    {
        return System::path('storage') . '/system/users.php';
    }
}

/* RULES
- Purpose: Core authentication layer for System::auth() API
- Config sources: storage/system/users.php (auto-generated)
- Paths: Uses System::path('storage'), System::path('sessions')
- Logs: Security events via System::log('security', ...)
- Prohibitions:
  - NO plain-text password storage
  - NO session fixation (always regenerate ID)
  - NO CSRF bypass
*/
