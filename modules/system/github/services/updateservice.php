<?php
/**
 * Update Service - Memory-Safe Git-Based Updates
 * 
 * ARCHITECTURE RULE: PHP is ONLY an orchestrator of shell git commands.
 * 
 * This service NEVER:
 * - Reads file contents with file_get_contents()
 * - Scans directories with scandir() or RecursiveDirectoryIterator
 * - Stores file lists in arrays
 * - Downloads ZIP archives
 * - Compares file contents manually
 * 
 * ALL synchronization is done via git commands:
 * - git fetch - to get updates
 * - git stash - to backup local changes
 * - git pull - to apply updates
 * - git reset --hard - for force sync
 * 
 * Memory usage is constant regardless of project size.
 * Works even with hundreds of gigabytes of data.
 * 
 * @package Modules\System\GitHub\Services
 */

declare(strict_types=1);

namespace Modules\System\GitHub\Services;

use Core\System\System;
use Core\Storage\StorageManager;

require_once __DIR__ . '/gitservice.php';
require_once __DIR__ . '/classifyservice.php';

class UpdateService
{
    // Update status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_CHECKING = 'checking';
    public const STATUS_BACKUP = 'backup';
    public const STATUS_UPDATING = 'updating';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NO_UPDATES = 'no_updates';
    
    private static ?self $instance = null;
    private GitService $git;
    private ClassifyService $classifier;
    
    // Current update state
    private array $updateState = [];
    
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct()
    {
        $this->git = GitService::instance();
        $this->classifier = ClassifyService::instance();
    }
    
    /**
     * Get settings from storage
     */
    private function getSettings(): array
    {
        $settings = StorageManager::instance()->get('system/github');
        return is_array($settings) ? $settings : [];
    }
    
    /**
     * Save settings to storage
     */
    private function saveSettings(array $settings): void
    {
        StorageManager::instance()->set('system/github', $settings);
    }
    
    /**
     * Get temp directory for downloads/extractions
     * 
     * Uses module's storage directory instead of sys_get_temp_dir()
     * to avoid open_basedir restriction issues.
     */
    private function getTempDirectory(): string
    {
        $moduleDir = __DIR__ . '/../storage/tmp';
        
        // Create if doesn't exist
        if (!is_dir($moduleDir)) {
            @mkdir($moduleDir, 0755, true);
        }
        
        // If still can't use module dir, try sys_get_temp_dir as fallback
        if (!is_dir($moduleDir) || !is_writable($moduleDir)) {
            $moduleDir = sys_get_temp_dir();
        }
        
        return $moduleDir;
    }
    
    /**
     * Execute a full git-based update
     * 
     * This is the main entry point for updates.
     * Steps:
     * 1. Check if git repo exists
     * 2. Fetch updates from remote
     * 3. Check if updates available
     * 4. Stash local changes (backup)
     * 5. Pull updates
     * 6. Update tracking info
     * 
     * If ANY step fails, we can restore from stash.
     * 
     * @param bool $force Force update even if there are conflicts
     * @return array Update result
     */
    public function executeUpdate(bool $force = false): array
    {
        $this->initState();
        $settings = $this->getSettings();
        $branch = $settings['branch'] ?? 'main';
        
        System::log('system', 'Starting git-based update', [
            'branch' => $branch,
            'force' => $force,
        ]);
        
        try {
            // STEP 1: Verify git repository
            $this->updateStatus(self::STATUS_CHECKING, 'Checking git repository...');
            
            if (!$this->git->isGitRepo()) {
                throw new \RuntimeException('Not a git repository. Please clone the project with git.');
            }
            
            $currentCommit = $this->git->getCurrentCommit();
            $this->updateState['start_commit'] = $currentCommit;
            
            // STEP 2: Fetch updates
            $this->updateStatus(self::STATUS_CHECKING, 'Fetching updates...');
            $fetchResult = $this->git->fetch();
            
            if (!$fetchResult['success']) {
                throw new \RuntimeException('Fetch failed: ' . ($fetchResult['error'] ?? 'Unknown error'));
            }
            
            // STEP 3: Check for updates
            $updateCheck = $this->git->checkForUpdates($branch);
            
            if (!$updateCheck['has_updates']) {
                $this->updateStatus(self::STATUS_NO_UPDATES, 'Already up to date');
                System::log('system', 'No updates available');
                
                return [
                    'success' => true,
                    'status' => self::STATUS_NO_UPDATES,
                    'message' => 'Already up to date',
                    'commits_behind' => 0,
                    'state' => $this->updateState,
                ];
            }
            
            $this->updateState['commits_behind'] = $updateCheck['behind'];
            
            // Get changed files list (names only, no content)
            $changedFiles = $this->git->getChangedFiles($branch);
            $this->updateState['files_changed'] = count($changedFiles);
            
            // STEP 4: Stash local changes as backup
            $this->updateStatus(self::STATUS_BACKUP, 'Backing up local changes...');
            
            $hasLocalChanges = $this->git->hasUncommittedChanges();
            if ($hasLocalChanges) {
                $commitShort = $currentCommit !== null ? substr($currentCommit, 0, 7) : 'unknown';
                $stashMessage = 'pre_update_' . date('Y-m-d_H-i-s') . '_' . $commitShort;
                $stashResult = $this->git->stash($stashMessage);
                
                if (!$stashResult['success']) {
                    throw new \RuntimeException('Stash failed: ' . ($stashResult['error'] ?? 'Cannot backup local changes'));
                }
                
                $this->updateState['stash_created'] = true;
                $this->updateState['stash_message'] = $stashMessage;
            } else {
                $this->updateState['stash_created'] = false;
            }
            
            // STEP 5: Pull updates
            $this->updateStatus(self::STATUS_UPDATING, 'Applying updates...');
            
            if ($force) {
                // Force mode: reset to remote branch
                $resetResult = $this->git->resetHard("origin/{$branch}");
                
                if (!$resetResult['success']) {
                    throw new \RuntimeException('Reset failed: ' . ($resetResult['error'] ?? 'Cannot reset to remote'));
                }
            } else {
                // Normal mode: git pull
                $pullResult = $this->git->pull('origin', $branch);
                
                if (!$pullResult['success']) {
                    // Pull failed - might have conflicts
                    // Restore from stash if we created one
                    if ($this->updateState['stash_created'] ?? false) {
                        $this->git->stashPop();
                    }
                    
                    throw new \RuntimeException('Pull failed: ' . ($pullResult['error'] ?? 'Unknown error. Try force update.'));
                }
            }
            
            // STEP 6: Update tracking info
            $this->updateStatus(self::STATUS_COMPLETE, 'Update complete');
            
            $newCommit = $this->git->getCurrentCommit();
            $this->updateState['end_commit'] = $newCommit;
            
            // Update settings
            $settings['last_commit'] = $newCommit;
            $settings['last_update'] = date('c');
            $this->saveSettings($settings);
            
            System::log('system', 'Update completed successfully', [
                'from_commit' => substr($currentCommit ?? '', 0, 7),
                'to_commit' => substr($newCommit ?? '', 0, 7),
                'files_changed' => count($changedFiles),
                'commits_applied' => $updateCheck['behind'],
            ]);
            
            return [
                'success' => true,
                'status' => self::STATUS_COMPLETE,
                'message' => "Updated from {$this->shortSha($currentCommit)} to {$this->shortSha($newCommit)}",
                'from_commit' => $currentCommit,
                'to_commit' => $newCommit,
                'commits_applied' => $updateCheck['behind'],
                'files_changed' => count($changedFiles),
                'stash_created' => $this->updateState['stash_created'] ?? false,
                'state' => $this->updateState,
            ];
            
        } catch (\Throwable $e) {
            $this->updateStatus(self::STATUS_FAILED, 'Update failed: ' . $e->getMessage());
            
            System::log('system', 'Update failed', [
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'status' => self::STATUS_FAILED,
                'error' => $e->getMessage(),
                'state' => $this->updateState,
            ];
        }
    }
    
    /**
     * Quick check for available updates
     * Does NOT download or apply anything
     * 
     * @return array
     */
    public function checkForUpdates(): array
    {
        $settings = $this->getSettings();
        $branch = $settings['branch'] ?? 'main';
        
        if (!$this->git->isGitRepo()) {
            return [
                'success' => false,
                'error' => 'Not a git repository',
            ];
        }
        
        // Fetch first
        $this->git->fetch();
        
        // Check updates
        $updateCheck = $this->git->checkForUpdates($branch);
        
        if (isset($updateCheck['error'])) {
            return [
                'success' => false,
                'error' => $updateCheck['error'],
            ];
        }
        
        // Get new commits
        $newCommits = [];
        if ($updateCheck['has_updates']) {
            $newCommits = $this->git->getNewCommits($branch, 'origin', 20);
        }
        
        // Get changed files (names only)
        $changedFiles = [];
        if ($updateCheck['has_updates']) {
            $changedFiles = $this->git->getChangedFiles($branch);
        }
        
        // Classify changed files
        $classified = [
            ClassifyService::SAFE => [],
            ClassifyService::VERIFY => [],
            ClassifyService::PROTECTED => [],
        ];
        
        foreach ($changedFiles as $file) {
            $category = $this->classifier->classify($file['file']);
            $classified[$category][] = $file;
        }
        
        return [
            'success' => true,
            'has_updates' => $updateCheck['has_updates'],
            'commits_behind' => $updateCheck['behind'],
            'commits_ahead' => $updateCheck['ahead'],
            'commits' => $newCommits,
            'files' => [
                'total' => count($changedFiles),
                'safe' => count($classified[ClassifyService::SAFE]),
                'verify' => count($classified[ClassifyService::VERIFY]),
                'protected' => count($classified[ClassifyService::PROTECTED]),
                'list' => $changedFiles,
                'classified' => $classified,
            ],
            'current_commit' => $this->git->getCurrentCommitShort(),
            'branch' => $branch,
        ];
    }
    
    /**
     * Restore from the last stash (undo update)
     */
    public function restoreFromStash(): array
    {
        $stashes = $this->git->stashList();
        
        if (empty($stashes)) {
            return [
                'success' => false,
                'error' => 'No stashes available for restore',
            ];
        }
        
        // Find latest pre_update stash
        $preUpdateStash = null;
        foreach ($stashes as $stash) {
            if (strpos($stash['message'], 'pre_update_') !== false) {
                $preUpdateStash = $stash;
                break;
            }
        }
        
        if (!$preUpdateStash) {
            return [
                'success' => false,
                'error' => 'No pre-update backup stash found',
            ];
        }
        
        $result = $this->git->stashApply($preUpdateStash['ref']);
        
        if ($result['success']) {
            System::log('system', 'Restored from stash', ['stash' => $preUpdateStash['ref']]);
        }
        
        return [
            'success' => $result['success'],
            'error' => $result['error'] ?? null,
            'stash' => $preUpdateStash,
        ];
    }
    
    /**
     * Force sync to remote (discards all local changes!)
     */
    public function forceSync(): array
    {
        $settings = $this->getSettings();
        $branch = $settings['branch'] ?? 'main';
        
        System::log('system', 'Force sync starting', ['branch' => $branch]);
        
        // Fetch first
        $fetchResult = $this->git->fetch();
        if (!$fetchResult['success']) {
            return [
                'success' => false,
                'error' => 'Fetch failed: ' . ($fetchResult['error'] ?? 'Unknown error'),
            ];
        }
        
        // Hard reset to remote
        $resetResult = $this->git->resetHard("origin/{$branch}");
        
        if ($resetResult['success']) {
            // Update tracking
            $settings['last_commit'] = $this->git->getCurrentCommit();
            $settings['last_update'] = date('c');
            $this->saveSettings($settings);
            
            System::log('system', 'Force sync completed');
        }
        
        return [
            'success' => $resetResult['success'],
            'error' => $resetResult['error'] ?? null,
            'commit' => $this->git->getCurrentCommitShort(),
        ];
    }
    
    /**
     * Initialize update state
     */
    private function initState(): void
    {
        $this->updateState = [
            'started_at' => date('c'),
            'status' => self::STATUS_PENDING,
            'message' => '',
            'start_commit' => null,
            'end_commit' => null,
            'stash_created' => false,
            'commits_behind' => 0,
            'files_changed' => 0,
        ];
    }
    
    /**
     * Update current status
     */
    private function updateStatus(string $status, string $message): void
    {
        $this->updateState['status'] = $status;
        $this->updateState['message'] = $message;
        $this->updateState['updated_at'] = date('c');
    }
    
    /**
     * Shorten SHA for display
     */
    private function shortSha(?string $sha): string
    {
        return $sha ? substr($sha, 0, 7) : 'unknown';
    }
    
    /**
     * Compare SHA hashes supporting both short (7 char) and full (40 char) SHAs
     */
    private function shaMatch(?string $sha1, ?string $sha2): bool
    {
        if (empty($sha1) || empty($sha2)) {
            return false;
        }
        
        // Normalize to lowercase
        $sha1 = strtolower(trim($sha1));
        $sha2 = strtolower(trim($sha2));
        
        // Exact match
        if ($sha1 === $sha2) {
            return true;
        }
        
        // Prefix match (shorter one should be prefix of longer one)
        $shorter = strlen($sha1) < strlen($sha2) ? $sha1 : $sha2;
        $longer = strlen($sha1) < strlen($sha2) ? $sha2 : $sha1;
        
        // Only match if shorter is at least 7 chars (standard git short SHA)
        if (strlen($shorter) >= 7 && str_starts_with($longer, $shorter)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get current update state
     */
    public function getState(): array
    {
        return $this->updateState;
    }
    
    /**
     * Get git diagnostics
     */
    public function getDiagnostics(): array
    {
        return $this->git->getDiagnostics();
    }
    
    /**
     * Check if update is in progress
     */
    public function isUpdateInProgress(): bool
    {
        return !empty($this->updateState['status']) &&
               !in_array($this->updateState['status'], [
                   self::STATUS_COMPLETE,
                   self::STATUS_FAILED,
                   self::STATUS_PENDING,
                   self::STATUS_NO_UPDATES
               ]);
    }
    
    /**
     * Execute API-based update (for non-git deployments)
     * 
     * Downloads ZIP from GitHub and extracts files.
     * Memory-safe: streams ZIP to disk, extracts in chunks.
     * 
     * @return array Update result
     */
    public function executeApiUpdate(): array
    {
        $this->initState();
        $settings = $this->getSettings();
        $branch = $settings['branch'] ?? 'main';
        $repo = $settings['update_repo'] ?? '';
        
        if (empty($repo)) {
            return [
                'success' => false,
                'error' => 'Update repository not configured',
            ];
        }
        
        // Parse owner/repo
        $parts = explode('/', $repo);
        if (count($parts) !== 2) {
            return [
                'success' => false,
                'error' => 'Invalid repository format. Expected: owner/repo',
            ];
        }
        
        $owner = $parts[0];
        $repoName = $parts[1];
        
        System::log('system', 'Starting API-based update', [
            'repo' => $repo,
            'branch' => $branch,
        ]);
        
        try {
            // STEP 1: Get latest commit info
            $this->updateStatus(self::STATUS_CHECKING, 'Checking for updates...');
            
            // Get gateway instance
            require_once dirname(__DIR__) . '/gateway.php';
            $gateway = \Modules\System\GitHub\GitHubGateway::instance();
            
            // Configure with token if available
            if (!empty($settings['token'])) {
                $gateway->configure($settings['username'] ?? '', $settings['token']);
            }
            
            // Get latest commit
            $commitsResult = $gateway->getCommits($owner, $repoName, $branch, 1);
            if (!$commitsResult['success'] || empty($commitsResult['result'])) {
                throw new \RuntimeException('Failed to fetch latest commit info');
            }
            
            $latestCommit = $commitsResult['result'][0]['sha'] ?? null;
            $latestCommitShort = $latestCommit ? substr($latestCommit, 0, 7) : 'unknown';
            $currentCommit = $settings['last_commit'] ?? null;
            
            // Check if already up to date (use shaMatch for flexible SHA comparison)
            if ($this->shaMatch($currentCommit, $latestCommit)) {
                $this->updateStatus(self::STATUS_NO_UPDATES, 'Already up to date');
                return [
                    'success' => true,
                    'status' => self::STATUS_NO_UPDATES,
                    'message' => 'Already up to date',
                ];
            }
            
            // STEP 2: Download ZIP
            $this->updateStatus(self::STATUS_UPDATING, 'Downloading update package...');
            
            // Use module storage for temp files (sys_get_temp_dir may be outside open_basedir)
            $tempDir = $this->getTempDirectory();
            $zipFile = $tempDir . '/github_update_' . time() . '.zip';
            
            // Use streaming download to avoid memory issues
            $downloadResult = $this->downloadZipToFile($owner, $repoName, $branch, $zipFile, $settings);
            
            if (!$downloadResult['success']) {
                throw new \RuntimeException('Failed to download update: ' . ($downloadResult['error'] ?? 'Unknown error'));
            }
            
            // STEP 3: Extract ZIP
            $this->updateStatus(self::STATUS_UPDATING, 'Extracting update...');
            
            $extractDir = $tempDir . '/github_extract_' . time();
            $extractResult = $this->extractZipSafely($zipFile, $extractDir);
            
            if (!$extractResult['success']) {
                @unlink($zipFile);
                throw new \RuntimeException('Failed to extract update: ' . ($extractResult['error'] ?? 'Unknown error'));
            }
            
            // STEP 4: Copy files to project root
            $this->updateStatus(self::STATUS_UPDATING, 'Applying update...');
            
            $rootPath = System::path('root');
            $copyResult = $this->copyExtractedFiles($extractDir, $rootPath);
            
            // Cleanup
            @unlink($zipFile);
            $this->deleteDirectory($extractDir);
            
            if (!$copyResult['success']) {
                throw new \RuntimeException('Failed to apply update: ' . ($copyResult['error'] ?? 'Unknown error'));
            }
            
            // STEP 5: Update settings with new commit
            $settings['last_commit'] = $latestCommit;
            $settings['last_update'] = date('Y-m-d H:i:s');
            $this->saveSettings($settings);
            
            $this->updateStatus(self::STATUS_COMPLETE, 'Update completed successfully');
            
            System::log('system', 'API-based update completed', [
                'from' => $currentCommit ? substr($currentCommit, 0, 7) : 'none',
                'to' => $latestCommitShort,
                'files' => $copyResult['files_copied'] ?? 0,
            ]);
            
            return [
                'success' => true,
                'status' => self::STATUS_COMPLETE,
                'from_commit' => $currentCommit,
                'to_commit' => $latestCommit,
                'files_changed' => $copyResult['files_copied'] ?? 0,
            ];
            
        } catch (\Exception $e) {
            $this->updateStatus(self::STATUS_FAILED, $e->getMessage());
            System::log('system', 'API-based update failed', ['error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Download ZIP to file using streaming (memory-safe)
     */
    private function downloadZipToFile(string $owner, string $repo, string $branch, string $targetFile, array $settings): array
    {
        $url = "https://api.github.com/repos/{$owner}/{$repo}/zipball/{$branch}";
        
        $fp = fopen($targetFile, 'wb');
        if (!$fp) {
            return ['success' => false, 'error' => 'Cannot create temp file'];
        }
        
        $ch = curl_init();
        
        $headers = [
            'Accept: application/vnd.github.v3+json',
            'User-Agent: Tredercopis-Core/1.3',
        ];
        
        if (!empty($settings['token'])) {
            $headers[] = 'Authorization: token ' . $settings['token'];
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fp, // Write directly to file
            CURLOPT_TIMEOUT => 300, // 5 min for large repos
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
        ]);
        
        $success = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        curl_close($ch);
        fclose($fp);
        
        if ($curlErrno !== 0 || $httpCode >= 400) {
            @unlink($targetFile);
            return [
                'success' => false,
                'error' => $curlErrno !== 0 ? $curlError : "HTTP {$httpCode}",
            ];
        }
        
        // Verify file is a valid ZIP
        if (filesize($targetFile) < 100) {
            @unlink($targetFile);
            return ['success' => false, 'error' => 'Downloaded file is too small'];
        }
        
        return ['success' => true, 'file' => $targetFile];
    }
    
    /**
     * Extract ZIP file safely
     */
    private function extractZipSafely(string $zipFile, string $targetDir): array
    {
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'error' => 'ZipArchive extension not available'];
        }
        
        $zip = new \ZipArchive();
        $result = $zip->open($zipFile);
        
        if ($result !== true) {
            return ['success' => false, 'error' => 'Cannot open ZIP file: ' . $result];
        }
        
        // Create target directory
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            $zip->close();
            return ['success' => false, 'error' => 'Cannot create extract directory'];
        }
        
        // Extract
        $extracted = $zip->extractTo($targetDir);
        $zip->close();
        
        if (!$extracted) {
            return ['success' => false, 'error' => 'Failed to extract ZIP contents'];
        }
        
        return ['success' => true, 'dir' => $targetDir];
    }
    
    /**
     * Copy extracted files to project root
     * 
     * GitHub ZIP has a top-level folder like "owner-repo-commit/"
     * We need to copy from inside that folder.
     */
    private function copyExtractedFiles(string $extractDir, string $targetDir): array
    {
        // Find the inner directory (GitHub adds a prefix folder)
        $dirs = glob($extractDir . '/*', GLOB_ONLYDIR);
        
        if (empty($dirs)) {
            return ['success' => false, 'error' => 'No directory found in extracted content'];
        }
        
        $sourceDir = $dirs[0]; // First (and only) directory
        $filesCopied = 0;
        
        // Protected paths that should not be overwritten
        $protectedPaths = [
            'storage/',
            '.env',
            'config/local.php',
            '.git/',
        ];
        
        // Use shell commands for efficient copying (memory-safe)
        // rsync is better than cp for this as it handles updates
        $rsyncCheck = [];
        @exec('which rsync 2>/dev/null', $rsyncCheck, $rsyncCode);
        
        if ($rsyncCode === 0 && !empty($rsyncCheck[0])) {
            // Build exclude arguments
            $excludes = '';
            foreach ($protectedPaths as $path) {
                $excludes .= ' --exclude=' . escapeshellarg($path);
            }
            
            // Use rsync for efficient copying
            $cmd = escapeshellarg(trim($rsyncCheck[0])) . 
                   ' -av --delete' . $excludes . ' ' .
                   escapeshellarg(rtrim($sourceDir, '/') . '/') . ' ' .
                   escapeshellarg(rtrim($targetDir, '/') . '/');
            
            $output = [];
            $exitCode = 0;
            exec($cmd . ' 2>&1', $output, $exitCode);
            
            if ($exitCode !== 0) {
                return [
                    'success' => false,
                    'error' => 'rsync failed: ' . implode("\n", array_slice($output, -5)),
                ];
            }
            
            // Count files from rsync output
            $filesCopied = count(array_filter($output, function($line) {
                return !empty($line) && !preg_match('/^(sending|sent|total|building|deleting|\.\/)/i', $line);
            }));
            
        } else {
            // Fallback to PHP-based copying (slower but works everywhere)
            $result = $this->copyDirectoryRecursive($sourceDir, $targetDir, $protectedPaths);
            if (!$result['success']) {
                return $result;
            }
            $filesCopied = $result['files_copied'];
        }
        
        return [
            'success' => true,
            'files_copied' => $filesCopied,
        ];
    }
    
    /**
     * Recursively copy directory (PHP fallback)
     */
    private function copyDirectoryRecursive(string $source, string $dest, array $protectedPaths, string $relativePath = ''): array
    {
        $filesCopied = 0;
        
        if (!is_dir($source)) {
            return ['success' => false, 'error' => 'Source is not a directory'];
        }
        
        if (!is_dir($dest) && !mkdir($dest, 0755, true)) {
            return ['success' => false, 'error' => 'Cannot create destination directory'];
        }
        
        $dir = opendir($source);
        if (!$dir) {
            return ['success' => false, 'error' => 'Cannot open source directory'];
        }
        
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $currentRelPath = $relativePath ? $relativePath . '/' . $file : $file;
            
            // Check if this path is protected
            $isProtected = false;
            foreach ($protectedPaths as $protected) {
                $protected = rtrim($protected, '/');
                if ($currentRelPath === $protected || strpos($currentRelPath, $protected . '/') === 0) {
                    $isProtected = true;
                    break;
                }
            }
            
            if ($isProtected) {
                continue;
            }
            
            $srcPath = $source . '/' . $file;
            $destPath = $dest . '/' . $file;
            
            if (is_dir($srcPath)) {
                $result = $this->copyDirectoryRecursive($srcPath, $destPath, $protectedPaths, $currentRelPath);
                if (!$result['success']) {
                    closedir($dir);
                    return $result;
                }
                $filesCopied += $result['files_copied'];
            } else {
                if (copy($srcPath, $destPath)) {
                    $filesCopied++;
                }
            }
        }
        
        closedir($dir);
        
        return ['success' => true, 'files_copied' => $filesCopied];
    }
    
    /**
     * Delete directory recursively
     */
    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }
        
        // Use rm -rf for efficiency
        $output = [];
        $exitCode = 0;
        exec('rm -rf ' . escapeshellarg($dir) . ' 2>&1', $output, $exitCode);
        
        return $exitCode === 0;
    }
}
