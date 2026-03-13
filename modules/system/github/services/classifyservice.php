<?php
/**
 * File Classification Service
 * 
 * Classifies files into categories:
 * - SAFE: Can be updated automatically
 * - VERIFY: Requires manual confirmation before update
 * - PROTECTED: NEVER overwritten
 * 
 * @package Modules\System\GitHub\Services
 */

declare(strict_types=1);

namespace Modules\System\GitHub\Services;

use Core\System\System;
use Core\Storage\StorageManager;

class ClassifyService
{
    // Classification constants
    public const SAFE = 'safe';
    public const VERIFY = 'verify';
    public const PROTECTED = 'protected';
    
    private static ?self $instance = null;
    
    /**
     * Protected paths - NEVER touched
     */
    private array $protectedPaths = [
        'storage/',
        'runtime/',
        '.env',
    ];
    
    /**
     * Protected files - NEVER touched
     */
    private array $protectedFiles = [
        'config/system.php',
        'config/bybit.php',
        'storage/system/state.php',
        'storage/system/users.php',
        'storage/system/github.php',
    ];
    
    /**
     * Verify paths - require confirmation
     */
    private array $verifyPaths = [
        'config/',
        'modules/',
        '.htaccess',
    ];
    
    /**
     * Verify files - require confirmation
     */
    private array $verifyFiles = [
        'public/.htaccess',
        '.htaccess',
        'index.php',
        'public/index.php',
    ];
    
    /**
     * Safe paths - can update automatically
     */
    private array $safePaths = [
        'core/',
        'admin/views/',
        'admin/assets/',
    ];
    
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct()
    {
        // Load custom classification rules from storage if exists
        $this->loadCustomRules();
    }
    
    /**
     * Load custom classification rules from storage
     */
    private function loadCustomRules(): void
    {
        $customRules = StorageManager::instance()->get('system/github_classify');
        
        if (is_array($customRules)) {
            if (isset($customRules['protected_paths'])) {
                $this->protectedPaths = array_merge($this->protectedPaths, $customRules['protected_paths']);
            }
            if (isset($customRules['protected_files'])) {
                $this->protectedFiles = array_merge($this->protectedFiles, $customRules['protected_files']);
            }
            if (isset($customRules['verify_paths'])) {
                $this->verifyPaths = array_merge($this->verifyPaths, $customRules['verify_paths']);
            }
            if (isset($customRules['verify_files'])) {
                $this->verifyFiles = array_merge($this->verifyFiles, $customRules['verify_files']);
            }
            if (isset($customRules['safe_paths'])) {
                $this->safePaths = array_merge($this->safePaths, $customRules['safe_paths']);
            }
        }
    }
    
    /**
     * Classify a single file
     */
    public function classify(string $path): string
    {
        // Normalize path
        $path = ltrim($path, '/');
        
        // Check protected files first (exact match)
        foreach ($this->protectedFiles as $protected) {
            if ($path === $protected) {
                return self::PROTECTED;
            }
        }
        
        // Check protected paths (prefix match)
        foreach ($this->protectedPaths as $protectedPath) {
            if (strpos($path, $protectedPath) === 0) {
                return self::PROTECTED;
            }
        }
        
        // Check verify files (exact match)
        foreach ($this->verifyFiles as $verifyFile) {
            if ($path === $verifyFile) {
                return self::VERIFY;
            }
        }
        
        // Check verify paths (prefix match)
        foreach ($this->verifyPaths as $verifyPath) {
            if (strpos($path, $verifyPath) === 0) {
                return self::VERIFY;
            }
        }
        
        // Check safe paths (prefix match)
        foreach ($this->safePaths as $safePath) {
            if (strpos($path, $safePath) === 0) {
                return self::SAFE;
            }
        }
        
        // Default to verify for unknown files
        return self::VERIFY;
    }
    
    /**
     * Classify multiple files
     * 
     * @param array $files List of file paths
     * @return array Classified files grouped by category
     */
    public function classifyFiles(array $files): array
    {
        $result = [
            self::SAFE => [],
            self::VERIFY => [],
            self::PROTECTED => [],
        ];
        
        foreach ($files as $file) {
            $category = $this->classify($file);
            $result[$category][] = $file;
        }
        
        return $result;
    }
    
    /**
     * Get classification rules
     */
    public function getRules(): array
    {
        return [
            'protected_paths' => $this->protectedPaths,
            'protected_files' => $this->protectedFiles,
            'verify_paths' => $this->verifyPaths,
            'verify_files' => $this->verifyFiles,
            'safe_paths' => $this->safePaths,
        ];
    }
    
    /**
     * Check if file is protected
     */
    public function isProtected(string $path): bool
    {
        return $this->classify($path) === self::PROTECTED;
    }
    
    /**
     * Check if file requires verification
     */
    public function requiresVerification(string $path): bool
    {
        return $this->classify($path) === self::VERIFY;
    }
    
    /**
     * Check if file is safe to update
     */
    public function isSafe(string $path): bool
    {
        return $this->classify($path) === self::SAFE;
    }
    
    /**
     * Filter out protected files from a list
     * 
     * @param array $files List of file paths
     * @return array Files that are NOT protected
     */
    public function filterProtected(array $files): array
    {
        return array_filter($files, fn($file) => !$this->isProtected($file));
    }
    
    /**
     * Get files requiring verification from a list
     */
    public function getFilesRequiringVerification(array $files): array
    {
        return array_filter($files, fn($file) => $this->requiresVerification($file));
    }
    
    /**
     * Get safe files from a list
     */
    public function getSafeFiles(array $files): array
    {
        return array_filter($files, fn($file) => $this->isSafe($file));
    }
    
    /**
     * Get classification summary for files
     */
    public function getSummary(array $files): array
    {
        $classified = $this->classifyFiles($files);
        
        return [
            'total' => count($files),
            'safe_count' => count($classified[self::SAFE]),
            'verify_count' => count($classified[self::VERIFY]),
            'protected_count' => count($classified[self::PROTECTED]),
            'can_auto_update' => count($classified[self::PROTECTED]) === 0,
            'requires_confirmation' => count($classified[self::VERIFY]) > 0,
        ];
    }
}
