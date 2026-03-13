<?php
/**
 * Rollback Service - Memory-Safe Git-Based Rollbacks
 * 
 * ARCHITECTURE RULE: PHP is ONLY an orchestrator of shell git commands.
 * 
 * This service NEVER:
 * - Reads file contents with file_get_contents()
 * - Extracts ZIP files and scans directories
 * - Stores file lists in arrays
 * 
 * ALL rollback operations use git commands:
 * - git checkout - to restore specific files
 * - git reset --hard - to restore entire state
 * - git stash apply - to restore uncommitted changes
 * 
 * Memory usage is constant regardless of project size.
 * 
 * @package Modules\System\GitHub\Services
 */

declare(strict_types=1);

namespace Modules\System\GitHub\Services;

use Core\System\System;

require_once __DIR__ . '/gitservice.php';
require_once __DIR__ . '/backupservice.php';

class RollbackService
{
    private static ?self $instance = null;
    private GitService $git;
    private BackupService $backupService;
    
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
        $this->backupService = BackupService::instance();
    }
    
    /**
     * Rollback to a backup point (tag or stash)
     * 
     * @param string $backupRef Tag name or stash reference
     * @param bool $verify If true, only verify rollback would succeed
     * @return array Result of rollback operation
     */
    public function rollbackFromBackup(string $backupRef, bool $verify = false): array
    {
        System::log('system', 'Starting rollback operation', [
            'backup_ref' => $backupRef,
            'verify_only' => $verify,
        ]);
        
        // Validate backup exists
        $validation = $this->backupService->validateBackup($backupRef);
        
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => 'Invalid backup: ' . ($validation['error'] ?? 'Unknown error'),
            ];
        }
        
        if ($verify) {
            return $this->verifyRollback($backupRef, $validation['type'] ?? 'tag');
        }
        
        try {
            $result = $this->performRollback($backupRef, $validation['type'] ?? 'tag');
            
            if ($result['success']) {
                System::log('system', 'Rollback completed successfully', [
                    'backup_ref' => $backupRef,
                ]);
            }
            
            return $result;
            
        } catch (\Throwable $e) {
            System::log('system', 'Rollback failed', [
                'backup_ref' => $backupRef,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => 'Rollback failed: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Verify that rollback would succeed without actually performing it
     */
    private function verifyRollback(string $backupRef, string $type): array
    {
        // Check git status
        $hasChanges = $this->git->hasUncommittedChanges();
        
        $issues = [];
        
        if ($hasChanges) {
            $issues[] = 'You have uncommitted changes. They will be lost during rollback.';
        }
        
        if ($type === 'tag') {
            // Check if we can reach the tag
            $result = $this->git->exec('rev-parse', [$backupRef]);
            if (!$result['success']) {
                $issues[] = 'Cannot find backup tag: ' . $backupRef;
            }
        } elseif ($type === 'stash') {
            // Verify stash exists
            $stashes = $this->git->stashList();
            $found = false;
            foreach ($stashes as $stash) {
                if ($stash['ref'] === $backupRef) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $issues[] = 'Stash not found: ' . $backupRef;
            }
        }
        
        return [
            'success' => empty($issues),
            'verified' => true,
            'issues' => $issues,
            'has_uncommitted_changes' => $hasChanges,
        ];
    }
    
    /**
     * Perform the actual rollback
     */
    private function performRollback(string $backupRef, string $type): array
    {
        if ($type === 'stash') {
            // Apply stash
            $result = $this->git->stashApply($backupRef);
            
            return [
                'success' => $result['success'],
                'error' => $result['error'] ?? null,
                'type' => 'stash_apply',
            ];
        }
        
        // For tags, we need to reset to that commit
        // First, get the commit SHA for the tag
        $commitResult = $this->git->exec('rev-parse', [$backupRef]);
        
        if (!$commitResult['success'] || empty($commitResult['output'])) {
            return [
                'success' => false,
                'error' => 'Cannot resolve backup tag to commit',
            ];
        }
        
        $commitSha = trim($commitResult['output'][0]);
        
        // Hard reset to the backup commit
        $resetResult = $this->git->resetHard($commitSha);
        
        return [
            'success' => $resetResult['success'],
            'error' => $resetResult['error'] ?? null,
            'type' => 'reset_hard',
            'commit' => $commitSha,
        ];
    }
    
    /**
     * Rollback specific files from a backup commit
     * 
     * @param string $backupRef Backup tag or commit
     * @param array $files List of files to restore
     * @return array Result
     */
    public function rollbackFiles(string $backupRef, array $files): array
    {
        if (empty($files)) {
            return [
                'success' => false,
                'error' => 'No files specified for rollback',
            ];
        }
        
        // Get commit SHA
        $commitResult = $this->git->exec('rev-parse', [$backupRef]);
        
        if (!$commitResult['success'] || empty($commitResult['output'])) {
            return [
                'success' => false,
                'error' => 'Cannot resolve backup reference',
            ];
        }
        
        $commitSha = trim($commitResult['output'][0]);
        
        $filesRestored = 0;
        $errors = [];
        
        foreach ($files as $file) {
            // Checkout specific file from backup commit
            $result = $this->git->exec('checkout', [$commitSha, '--', $file]);
            
            if ($result['success']) {
                $filesRestored++;
            } else {
                $errors[] = "Failed to restore: {$file}";
            }
        }
        
        System::log('system', 'Partial rollback completed', [
            'backup_ref' => $backupRef,
            'requested_files' => count($files),
            'restored' => $filesRestored,
        ]);
        
        return [
            'success' => empty($errors),
            'files_restored' => $filesRestored,
            'errors' => $errors,
        ];
    }
    
    /**
     * Get the latest backup (for quick rollback)
     */
    public function getLatestBackup(): ?array
    {
        $backups = $this->backupService->listBackups();
        
        if (empty($backups)) {
            return null;
        }
        
        // Return the most recent backup (first in sorted list)
        return $backups[0];
    }
    
    /**
     * Quick rollback to latest backup
     */
    public function quickRollback(): array
    {
        $latestBackup = $this->getLatestBackup();
        
        if (!$latestBackup) {
            return [
                'success' => false,
                'error' => 'No backups available for rollback',
            ];
        }
        
        return $this->rollbackFromBackup($latestBackup['tag']);
    }
    
    /**
     * Rollback to previous commit (undo last update)
     */
    public function rollbackOneCommit(): array
    {
        System::log('system', 'Rolling back one commit');
        
        $result = $this->git->resetHard('HEAD~1');
        
        return [
            'success' => $result['success'],
            'error' => $result['error'] ?? null,
            'commit' => $this->git->getCurrentCommitShort(),
        ];
    }
    
    /**
     * Get list of backups available for rollback
     */
    public function getAvailableRollbacks(): array
    {
        return $this->backupService->listBackups();
    }
    
    /**
     * Get recent commits for rollback selection
     */
    public function getRecentCommits(int $limit = 10): array
    {
        $result = $this->git->exec('log', [
            '--oneline',
            '-n', (string)$limit,
        ]);
        
        if (!$result['success']) {
            return [];
        }
        
        $commits = [];
        foreach ($result['output'] as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $parts = explode(' ', $line, 2);
            if (count($parts) >= 2) {
                $commits[] = [
                    'sha' => $parts[0],
                    'message' => $parts[1],
                ];
            }
        }
        
        return $commits;
    }
}
