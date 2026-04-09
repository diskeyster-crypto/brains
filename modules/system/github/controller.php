<?php
/**
 * GitHub Center Controller
 * 
 * THE ONLY system update manager.
 * Main controller for GitHub system module with transactional updates.
 * 
 * Routes:
 *   GET  /admin/github             - Dashboard / status overview
 *   GET  /admin/github/settings    - Settings form
 *   POST /admin/github/settings    - Save settings
 *   GET  /admin/github/test        - Test connection
 *   GET  /admin/github/fetch       - Fetch updates preview
 *   GET  /admin/github/classify    - File classification view
 *   GET  /admin/github/update      - Update confirmation page
 *   POST /admin/github/update      - Perform transactional update
 *   GET  /admin/github/backups     - List backups
 *   POST /admin/github/backup      - Create new backup
 *   GET  /admin/github/rollback    - Rollback page
 *   POST /admin/github/rollback    - Perform rollback
 * 
 * STRICT RULES:
 * - NO direct file writes outside services
 * - ALL updates must be transactional
 * - Backup BEFORE update is mandatory
 * - Rollback on ANY failure
 * - Protected files are NEVER overwritten
 * 
 * @package Modules\System\GitHub
 */

declare(strict_types=1);

namespace Modules\System\GitHub;

use Core\System\System;
use Core\Router\Router;
use Core\Storage\StorageManager;

// Load gateway and services
require_once __DIR__ . '/gateway.php';
require_once __DIR__ . '/services/classifyservice.php';
require_once __DIR__ . '/services/fetchservice.php';
require_once __DIR__ . '/services/backupservice.php';
require_once __DIR__ . '/services/rollbackservice.php';
require_once __DIR__ . '/services/updateservice.php';

use Modules\System\GitHub\Services\ClassifyService;
use Modules\System\GitHub\Services\FetchService;
use Modules\System\GitHub\Services\BackupService;
use Modules\System\GitHub\Services\RollbackService;
use Modules\System\GitHub\Services\UpdateService;

class GithubController
{
    private const STORAGE_KEY = 'system/github';
    
    private static ?self $instance = null;
    
    // Services
    private FetchService $fetchService;
    private ClassifyService $classifyService;
    private BackupService $backupService;
    private UpdateService $updateService;
    private RollbackService $rollbackService;
    
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct()
    {
        $this->fetchService = FetchService::instance();
        $this->classifyService = ClassifyService::instance();
        $this->backupService = BackupService::instance();
        $this->updateService = UpdateService::instance();
        $this->rollbackService = RollbackService::instance();
    }
    
    /**
     * Get GitHub settings from storage
     */
    private function getSettings(): array
    {
        $storage = StorageManager::instance();
        $settings = $storage->get(self::STORAGE_KEY);
        
        if (!is_array($settings)) {
            $settings = $this->getDefaultSettings();
        }
        
        return array_merge($this->getDefaultSettings(), $settings);
    }
    
    /**
     * Save GitHub settings to storage
     */
    private function saveSettingsToStorage(array $settings): bool
    {
        $storage = StorageManager::instance();
        $storage->set(self::STORAGE_KEY, $settings);
        return true;
    }
    
    /**
     * Get default settings
     */
    private function getDefaultSettings(): array
    {
        return [
            'username' => '',
            'token' => '',
            'update_repo' => '',
            'backup_repo' => '',
            'branch' => 'main',
            'auto_backup' => true,
            'include_all_files' => false,
            'last_fetch' => null,
            'last_update' => null,
            'last_backup' => null,
            'last_commit' => null,
        ];
    }
    
    /**
     * Get configured gateway instance
     */
    private function getGateway(): GitHubGateway
    {
        $settings = $this->getSettings();
        return GitHubGateway::instance()->configure(
            $settings['username'],
            $settings['token']
        );
    }
    
    /**
     * Parse repository string (owner/repo format)
     */
    private function parseRepo(string $repo): ?array
    {
        if (empty($repo)) {
            return null;
        }
        
        $parts = explode('/', $repo);
        if (count($parts) !== 2) {
            return null;
        }
        
        return [
            'owner' => trim($parts[0]),
            'repo' => trim($parts[1]),
        ];
    }
    
    // =========================================================================
    // ROUTE HANDLERS
    // =========================================================================
    
    /**
     * Main dashboard - status overview
     */
    public function index(): string
    {
        // Auth check
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        $settings = $this->getSettings();
        $isConfigured = !empty($settings['username']) && !empty($settings['token']) && !empty($settings['update_repo']);
        
        $data = [
            'title' => 'GitHub Center',
            'settings' => $settings,
            'is_configured' => $isConfigured,
            'csrf_token' => $this->getCsrfToken(),
        ];
        
        // If configured, try to get status
        if ($isConfigured) {
            try {
                $data['status'] = $this->fetchStatus();
            } catch (\Throwable $e) {
                System::log('system', 'GitHub status fetch failed', ['error' => $e->getMessage()]);
                $data['status'] = [
                    'connected' => false,
                    'error'     => 'Status unavailable: ' . $e->getMessage(),
                ];
            }
        }
        
        return $this->render('index', $data);
    }
    
    /**
     * Settings form
     */
    public function settings(): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        $settings = $this->getSettings();
        $error = null;
        $success = null;
        
        // Check for flash messages
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['github_success'])) {
            $success = $_SESSION['github_success'];
            unset($_SESSION['github_success']);
        }
        
        if (isset($_SESSION['github_error'])) {
            $error = $_SESSION['github_error'];
            unset($_SESSION['github_error']);
        }
        
        return $this->render('settings', [
            'title' => 'GitHub Settings',
            'settings' => $settings,
            'error' => $error,
            'success' => $success,
            'csrf_token' => $this->getCsrfToken(),
        ]);
    }
    
    /**
     * Save settings (POST)
     */
    public function saveSettings(): void
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return;
        }
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // CSRF validation
        $token = $_POST['_csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            $_SESSION['github_error'] = 'Security error. Please try again.';
            Router::redirect(System::web('admin/github/settings'));
            return;
        }
        
        $settings = $this->getSettings();
        
        // Update settings from POST
        $settings['username'] = trim($_POST['username'] ?? '');
        $settings['update_repo'] = trim($_POST['update_repo'] ?? '');
        $settings['backup_repo'] = trim($_POST['backup_repo'] ?? '');
        $settings['branch'] = trim($_POST['branch'] ?? 'main');
        $settings['auto_backup'] = isset($_POST['auto_backup']);
        $settings['include_all_files'] = isset($_POST['include_all_files']);
        
        // Only update token if provided (don't clear existing)
        $newToken = trim($_POST['token'] ?? '');
        if (!empty($newToken)) {
            $settings['token'] = $newToken;
        }
        
        // Validate repository format
        if (!empty($settings['update_repo']) && !$this->parseRepo($settings['update_repo'])) {
            $_SESSION['github_error'] = 'Invalid repository format. Use owner/repo format.';
            Router::redirect(System::web('admin/github/settings'));
            return;
        }
        
        // Save
        $this->saveSettingsToStorage($settings);
        
        System::log('system', 'GitHub settings updated', ['username' => $settings['username']]);
        
        $_SESSION['github_success'] = 'Settings saved successfully.';
        Router::redirect(System::web('admin/github/settings'));
    }
    
    /**
     * Test connection
     */
    public function test(): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        $settings = $this->getSettings();
        
        if (empty($settings['token'])) {
            return $this->render('test', [
                'title' => 'Test Connection',
                'success' => false,
                'error' => 'GitHub token is not configured.',
            ]);
        }
        
        $gateway = $this->getGateway();
        $result = $gateway->testConnection();
        
        $data = [
            'title' => 'Test Connection',
            'success' => $result['success'],
            'result' => $result,
            'error' => $result['error'] ?? null,
        ];
        
        // Test repository access if configured
        if ($result['success'] && !empty($settings['update_repo'])) {
            $repoInfo = $this->parseRepo($settings['update_repo']);
            if ($repoInfo) {
                $repoResult = $gateway->getRepo($repoInfo['owner'], $repoInfo['repo']);
                $data['repo_access'] = $repoResult['success'];
                $data['repo_info'] = $repoResult['success'] ? $repoResult['result'] : null;
                $data['repo_error'] = $repoResult['success'] ? null : $gateway->getProbableCause($repoResult);
            }
        }
        
        System::log('system', 'GitHub connection test', [
            'success' => $result['success'],
            'user' => $result['user'] ?? 'unknown',
        ]);
        
        return $this->render('test', $data);
    }
    
    /**
     * Fetch latest updates info - uses FetchService
     */
    public function fetch(): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        $settings = $this->getSettings();
        
        if (empty($settings['update_repo'])) {
            return $this->render('fetch', [
                'title' => 'Fetch Updates',
                'error' => 'Update repository is not configured.',
            ]);
        }
        
        // Use FetchService for all fetch operations
        $result = $this->fetchService->getUpdatePreview();
        
        if (!$result['success']) {
            return $this->render('fetch', [
                'title' => 'Fetch Updates',
                'error' => $result['error'] ?? 'Failed to fetch updates',
            ]);
        }
        
        return $this->render('fetch', [
            'title' => 'Fetch Updates',
            'result' => $result,
            'preview' => $result['preview'] ?? [],
            'commits' => $result['commits']['list'] ?? [],
            'has_updates' => $result['has_updates'],
            'files' => $result['files'] ?? [],
            'release' => $result['release'],
            'current_commit' => $result['current_commit'],
            'latest_commit' => $result['latest_commit'],
            'settings' => $settings,
            'csrf_token' => $this->getCsrfToken(),
        ]);
    }
    
    /**
     * Update confirmation page with file classification
     */
    public function update(): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        $settings = $this->getSettings();
        
        if (empty($settings['update_repo'])) {
            return $this->render('update', [
                'title' => 'Update System',
                'error' => 'Update repository is not configured.',
                'mode' => 'api',
                'has_updates' => false,
                'settings' => $settings,
                'preview' => [],
                'diagnostics' => [],
                'commits' => [],
                'csrf_token' => $this->getCsrfToken(),
            ]);
        }
        
        // Get update preview with file classification
        $preview = $this->fetchService->getUpdatePreview();
        $diagnostics = $this->fetchService->getDiagnostics();
        
        // Determine mode based on diagnostics
        $mode = ($diagnostics['is_git_repo'] ?? false) ? 'git' : 'api';
        $hasUpdates = $preview['success'] && ($preview['has_updates'] ?? false);
        
        return $this->render('update', [
            'title' => 'Update System',
            'mode' => $mode,
            'is_git_repo' => $diagnostics['is_git_repo'] ?? false,
            'settings' => $settings,
            'preview' => $preview,
            'has_updates' => $hasUpdates,
            'files' => $preview['success'] ? ($preview['files'] ?? []) : [],
            'commits' => $preview['success'] ? ($preview['commits']['list'] ?? []) : [],
            'can_update' => $preview['success'] && ($preview['preview']['can_update'] ?? ($mode === 'git')),
            'requires_confirmation' => $preview['success'] && ($preview['preview']['requires_confirmation'] ?? false),
            'warnings' => $preview['preview']['warnings'] ?? [],
            'blocked' => $preview['preview']['blocked'] ?? [],
            'diagnostics' => $diagnostics,
            'api_mode_notice' => $preview['api_mode_notice'] ?? null,
            'error' => $preview['error'] ?? null,
            'csrf_token' => $this->getCsrfToken(),
        ]);
    }
    
    /**
     * Perform transactional update (POST)
     * 
     * Uses UpdateService with:
     * - MANDATORY backup before update
     * - File classification (safe/verify/protected)
     * - Rollback on ANY failure
     */
    public function doUpdate(): void
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return;
        }
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // CSRF validation
        $token = $_POST['_csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            $_SESSION['github_error'] = 'Security error. Please try again.';
            Router::redirect(System::web('admin/github/update'));
            return;
        }
        
        // Check if force update was requested
        $force = isset($_POST['force']) && $_POST['force'] === '1';
        
        // Check if this is API mode update (non-git deployment)
        $apiMode = isset($_POST['api_mode']) && $_POST['api_mode'] === '1';
        
        // Execute update
        if ($apiMode) {
            // API-based update for non-git deployments
            $result = $this->updateService->executeApiUpdate();
        } else {
            // Git-based update (preferred)
            $result = $this->updateService->executeUpdate($force);
        }
        
        if ($result['success']) {
            // Calculate file counts - use either git or API mode result keys
            $filesUpdated = $result['files_updated'] ?? $result['files_changed'] ?? 0;
            $filesSkipped = $result['files_skipped'] ?? 0;
            $filesProtected = $result['files_protected'] ?? 0;
            
            // Determine backup status
            $backupStatus = 'none';
            if (!empty($result['backup'])) {
                $backupStatus = $result['backup'];
            } elseif (!empty($result['stash_created'])) {
                $backupStatus = 'stash';
            }
            
            $_SESSION['github_success'] = sprintf(
                'System updated successfully! Files: %d updated, %d skipped, %d protected. Backup: %s',
                $filesUpdated,
                $filesSkipped,
                $filesProtected,
                $backupStatus
            );
        } else {
            $rollbackMsg = '';
            if (isset($result['rollback'])) {
                $rollbackMsg = $result['rollback']['success'] 
                    ? ' System rolled back successfully.'
                    : ' Rollback attempted but may have failed.';
            }
            $_SESSION['github_error'] = 'Update failed: ' . ($result['error'] ?? 'Unknown error') . $rollbackMsg;
        }
        
        Router::redirect(System::web('admin/github'));
    }
    
    /**
     * List backups - uses BackupService
     */
    public function backups(): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        // Get backups from BackupService
        $backups = $this->backupService->listBackups();
        
        // Check for flash messages
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $success = null;
        $error = null;
        
        if (isset($_SESSION['github_success'])) {
            $success = $_SESSION['github_success'];
            unset($_SESSION['github_success']);
        }
        
        if (isset($_SESSION['github_error'])) {
            $error = $_SESSION['github_error'];
            unset($_SESSION['github_error']);
        }
        
        return $this->render('backups', [
            'title' => 'Backups & Rollback',
            'backups' => $backups,
            'success' => $success,
            'error' => $error,
            'csrf_token' => $this->getCsrfToken(),
        ]);
    }
    
    /**
     * Create new backup (POST) - uses BackupService
     */
    public function createBackup(): void
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return;
        }
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // CSRF validation
        $token = $_POST['_csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            $_SESSION['github_error'] = 'Security error. Please try again.';
            Router::redirect(System::web('admin/github/backups'));
            return;
        }
        
        $label = trim($_POST['label'] ?? '');
        $result = $this->backupService->createBackup($label ?: null);
        
        if ($result['success']) {
            $_SESSION['github_success'] = 'Backup created: ' . $result['filename'];
        } else {
            $_SESSION['github_error'] = 'Backup failed: ' . ($result['error'] ?? 'Unknown error');
        }
        
        Router::redirect(System::web('admin/github/backups'));
    }
    
    /**
     * Rollback page
     */
    public function rollback(): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        $backups = $this->rollbackService->getAvailableRollbacks();
        $backupFilename = $_GET['backup'] ?? null;
        $selectedBackup = null;
        
        if ($backupFilename) {
            $selectedBackup = $this->backupService->getBackupInfo($backupFilename);
            
            if ($selectedBackup) {
                // Get validation info
                $validation = $this->backupService->validateBackup($backupFilename);
                $selectedBackup['validation'] = $validation;
                
                // Get file list
                $fileList = $this->backupService->getBackupFileList($backupFilename);
                $selectedBackup['files'] = $fileList['success'] ? $fileList['files'] : [];
            }
        }
        
        return $this->render('rollback', [
            'title' => 'Rollback System',
            'backups' => $backups,
            'selected_backup' => $selectedBackup,
            'csrf_token' => $this->getCsrfToken(),
        ]);
    }
    
    /**
     * Perform rollback (POST)
     */
    public function doRollback(): void
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return;
        }
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // CSRF validation
        $token = $_POST['_csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            $_SESSION['github_error'] = 'Security error. Please try again.';
            Router::redirect(System::web('admin/github/backups'));
            return;
        }
        
        $backupFilename = $_POST['backup'] ?? '';
        
        if (empty($backupFilename)) {
            $_SESSION['github_error'] = 'No backup selected for rollback.';
            Router::redirect(System::web('admin/github/backups'));
            return;
        }
        
        $result = $this->rollbackService->rollbackFromBackup($backupFilename);
        
        if ($result['success']) {
            $_SESSION['github_success'] = sprintf(
                'System rolled back successfully! %d files restored.',
                $result['files_restored'] ?? 0
            );
        } else {
            $_SESSION['github_error'] = 'Rollback failed: ' . ($result['error'] ?? 'Unknown error');
        }
        
        Router::redirect(System::web('admin/github/backups'));
    }
    
    /**
     * File classification view
     */
    public function classify(): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        // Get classification rules
        $rules = $this->classifyService->getRules();
        
        // Get files from latest fetch if available
        $preview = $this->fetchService->getUpdatePreview();
        $files = [];
        
        if ($preview['success'] && isset($preview['files']['list'])) {
            $files = $preview['files']['list'];
        }
        
        return $this->render('classify', [
            'title' => 'File Classification',
            'rules' => $rules,
            'files' => $files,
            'classified' => $preview['files']['classified'] ?? [],
        ]);
    }
    
    /**
     * API: Get list of repositories (AJAX)
     */
    public function apiRepos(): string
    {
        if (!System::auth()->check()) {
            header('Content-Type: application/json');
            return json_encode(['success' => false, 'error' => 'Unauthorized']);
        }
        
        $gateway = $this->getGateway();
        $result = $gateway->getAuthenticatedUserRepos();
        
        header('Content-Type: application/json');
        
        if (!$result['success']) {
            return json_encode([
                'success' => false,
                'error' => $gateway->getProbableCause($result),
            ]);
        }
        
        $repos = [];
        foreach ($result['result'] ?? [] as $repo) {
            $repos[] = [
                'full_name' => $repo['full_name'],
                'name' => $repo['name'],
                'owner' => $repo['owner']['login'] ?? '',
                'private' => $repo['private'] ?? false,
                'description' => $repo['description'] ?? '',
                'default_branch' => $repo['default_branch'] ?? 'main',
            ];
        }
        
        return json_encode(['success' => true, 'repos' => $repos]);
    }
    
    /**
     * API: Get branches for a repository (AJAX)
     */
    public function apiBranches(): string
    {
        if (!System::auth()->check()) {
            header('Content-Type: application/json');
            return json_encode(['success' => false, 'error' => 'Unauthorized']);
        }
        
        $repo = $_GET['repo'] ?? '';
        if (empty($repo)) {
            header('Content-Type: application/json');
            return json_encode(['success' => false, 'error' => 'Repository not specified']);
        }
        
        $repoInfo = $this->parseRepo($repo);
        if (!$repoInfo) {
            header('Content-Type: application/json');
            return json_encode(['success' => false, 'error' => 'Invalid repository format']);
        }
        
        $gateway = $this->getGateway();
        $result = $gateway->getBranches($repoInfo['owner'], $repoInfo['repo']);
        
        header('Content-Type: application/json');
        
        if (!$result['success']) {
            return json_encode([
                'success' => false,
                'error' => $gateway->getProbableCause($result),
            ]);
        }
        
        $branches = [];
        foreach ($result['result'] ?? [] as $branch) {
            $branches[] = [
                'name' => $branch['name'],
                'protected' => $branch['protected'] ?? false,
            ];
        }
        
        return json_encode(['success' => true, 'branches' => $branches]);
    }
    
    /**
     * Upload file page
     */
    public function upload(): string
    {
        if (!System::auth()->check()) {
            Router::redirect(System::web('admin/login'));
            return '';
        }
        
        $settings = $this->getSettings();
        
        // Get server files tree
        $serverFiles = $this->getServerFileTree(System::path('root'));
        
        // Check for flash messages
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $success = null;
        $error = null;
        
        if (isset($_SESSION['github_success'])) {
            $success = $_SESSION['github_success'];
            unset($_SESSION['github_success']);
        }
        
        if (isset($_SESSION['github_error'])) {
            $error = $_SESSION['github_error'];
            unset($_SESSION['github_error']);
        }
        
        return $this->render('upload', [
            'title' => 'Загрузка файлов',
            'settings' => $settings,
            'server_files' => $serverFiles,
            'success' => $success,
            'error' => $error,
            'csrf_token' => $this->getCsrfToken(),
        ]);
    }
    
    /**
     * API: Upload file to GitHub (AJAX)
     */
    public function apiUpload(): string
    {
        header('Content-Type: application/json');
        
        if (!System::auth()->check()) {
            return json_encode(['success' => false, 'error' => 'Unauthorized']);
        }
        
        // CSRF validation
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $token = $_POST['_csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            return json_encode(['success' => false, 'error' => 'CSRF validation failed']);
        }
        
        $repo = $_POST['repo'] ?? '';
        $branch = $_POST['branch'] ?? 'main';
        $message = $_POST['message'] ?? 'Upload from server';
        $filePath = $_POST['file_path'] ?? '';
        $destPath = trim($_POST['dest_path'] ?? '', '/');
        
        if (empty($repo) || empty($filePath)) {
            return json_encode(['success' => false, 'error' => 'Missing required parameters']);
        }
        
        $repoInfo = $this->parseRepo($repo);
        if (!$repoInfo) {
            return json_encode(['success' => false, 'error' => 'Invalid repository format']);
        }
        
        // Read file from server
        $fullPath = System::path('root') . '/' . ltrim($filePath, '/');
        
        if (!file_exists($fullPath) || !is_readable($fullPath)) {
            return json_encode(['success' => false, 'error' => 'File not found or not readable']);
        }
        
        // Security check - block sensitive files UNLESS include_all_files is enabled
        // When include_all_files is checked, user explicitly wants to upload everything
        $settings = $this->getSettings();
        $includeAll = !empty($settings['include_all_files']);
        
        if (!$includeAll) {
            // Only block truly sensitive files like .htaccess, .htpasswd, .env
            // Config folders/files are allowed for modules
            $filename = basename($filePath);
            if (in_array($filename, ['.htaccess', '.htpasswd', '.env'])) {
                return json_encode([
                    'success' => false, 
                    'error' => "Cannot upload sensitive file '{$filename}'. Enable \"Include all files\" in settings to override."
                ]);
            }
        }
        
        $content = file_get_contents($fullPath);
        if ($content === false) {
            return json_encode(['success' => false, 'error' => 'Failed to read file']);
        }
        
        // Build destination path
        $githubPath = $destPath ? $destPath . '/' . $filePath : $filePath;
        $githubPath = ltrim($githubPath, '/');
        
        $gateway = $this->getGateway();
        $result = $gateway->uploadFile(
            $repoInfo['owner'],
            $repoInfo['repo'],
            $githubPath,
            $content,
            $message . ' - ' . $filePath,
            $branch
        );
        
        if (!$result['success']) {
            return json_encode([
                'success' => false,
                'error' => $gateway->getProbableCause($result),
            ]);
        }
        
        System::log('system', 'File uploaded to GitHub', [
            'repo' => $repo,
            'file' => $filePath,
            'github_path' => $githubPath,
        ]);
        
        return json_encode([
            'success' => true,
            'file' => $filePath,
            'github_path' => $githubPath,
        ]);
    }
    
    /**
     * Build server file tree for upload page
     */
    private function getServerFileTree(string $rootPath, string $subPath = '', int $depth = 0): array
    {
        $tree = [];
        $maxDepth = 4; // Limit recursion depth
        
        if ($depth > $maxDepth) {
            return $tree;
        }
        
        $currentPath = $rootPath . ($subPath ? '/' . $subPath : '');
        
        if (!is_dir($currentPath) || !is_readable($currentPath)) {
            return $tree;
        }
        
        // Check if include_all_files is enabled in settings
        $settings = $this->getSettings();
        $includeAll = !empty($settings['include_all_files']);
        
        // Directories to always skip (regardless of setting)
        $alwaysSkipDirs = ['vendor', 'node_modules', '.git', '.idea', '.vscode'];
        
        // Additional directories to skip when include_all_files is disabled
        $protectedDirs = ['storage', 'runtime', 'config'];
        
        // Combine skip lists based on setting
        $skipDirs = $includeAll ? $alwaysSkipDirs : array_merge($alwaysSkipDirs, $protectedDirs);
        
        $items = scandir($currentPath);
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            // Skip hidden files/dirs (but allow if include_all_files is enabled and not .git)
            if (strpos($item, '.') === 0) {
                if (!$includeAll || $item === '.git') {
                    continue;
                }
            }
            
            $itemPath = $currentPath . '/' . $item;
            $relativePath = $subPath ? $subPath . '/' . $item : $item;
            
            if (is_dir($itemPath)) {
                // Skip certain directories
                if (in_array($item, $skipDirs)) {
                    continue;
                }
                
                $children = $this->getServerFileTree($rootPath, $relativePath, $depth + 1);
                
                $tree[$item] = [
                    'type' => 'dir',
                    'path' => $relativePath,
                    'children' => $children,
                ];
            } else {
                // Skip very large files
                $size = filesize($itemPath);
                if ($size > 10 * 1024 * 1024) { // 10MB limit
                    continue;
                }
                
                $tree[$item] = [
                    'type' => 'file',
                    'path' => $relativePath,
                    'size' => $size,
                ];
            }
        }
        
        // Sort: directories first, then files
        uksort($tree, function($a, $b) use ($tree) {
            $aIsDir = ($tree[$a]['type'] ?? '') === 'dir';
            $bIsDir = ($tree[$b]['type'] ?? '') === 'dir';
            
            if ($aIsDir && !$bIsDir) return -1;
            if (!$aIsDir && $bIsDir) return 1;
            return strcasecmp($a, $b);
        });
        
        return $tree;
    }
    
    // =========================================================================
    // HELPER METHODS (Non-file operations only)
    // =========================================================================
    
    /**
     * Fetch current status for dashboard
     */
    private function fetchStatus(): array
    {
        $settings = $this->getSettings();
        $repoInfo = $this->parseRepo($settings['update_repo']);
        
        if (!$repoInfo) {
            return ['error' => 'Invalid repository configuration'];
        }
        
        $gateway = $this->getGateway();
        $repoResult = $gateway->getRepo($repoInfo['owner'], $repoInfo['repo']);
        
        if (!$repoResult['success']) {
            return [
                'error' => $gateway->getProbableCause($repoResult),
                'connected' => false,
            ];
        }
        
        // Get latest commit
        $commitsResult = $gateway->getCommits($repoInfo['owner'], $repoInfo['repo'], $settings['branch'], 1);
        $latestCommit = null;
        if ($commitsResult['success'] && !empty($commitsResult['result'])) {
            $latestCommit = $commitsResult['result'][0];
        }
        
        $hasUpdates = $latestCommit && ($latestCommit['sha'] ?? null) !== $settings['last_commit'];
        
        return [
            'connected' => true,
            'repo' => $repoResult['result'],
            'latest_commit' => $latestCommit,
            'has_updates' => $hasUpdates,
            'current_commit' => $settings['last_commit'],
        ];
    }
    
    /**
     * Get or generate CSRF token
     */
    private function getCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Render a view with admin layout
     */
    private function render(string $view, array $data = []): string
    {
        $viewFile = __DIR__ . '/views/' . $view . '.php';
        
        if (!file_exists($viewFile)) {
            return "View not found: {$view}";
        }
        
        extract($data);
        
        ob_start();
        include $viewFile;
        $content = ob_get_clean();
        
        // Use admin layout
        require_once System::path('root') . '/admin/views/layout.php';
        return renderLayout($data['title'] ?? 'GitHub Center', $content, 'github');
    }
}

/* RULES
- Purpose: THE ONLY system update manager (GitHub Center)
- Architecture:
  - ALL file operations through Services (NO direct file writes in controller)
  - FetchService: Fetch updates, diff, file list
  - ClassifyService: Classify files (safe/verify/protected)
  - BackupService: Create and manage backups
  - UpdateService: Transactional updates with mandatory backup
  - RollbackService: Rollback on failure
- Strict Requirements:
  - Backup BEFORE update is MANDATORY
  - Rollback on ANY failure
  - Protected files are NEVER overwritten
  - Verify files require manual confirmation
- CSRF validation on all POST requests
- Auth check on all routes
*/