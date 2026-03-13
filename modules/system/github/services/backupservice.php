<?php
/**
 * Backup Service - Memory-Safe Git-Based Backups
 * 
 * ARCHITECTURE RULE: PHP is ONLY an orchestrator of shell git commands.
 * 
 * This service NEVER:
 * - Reads file contents with file_get_contents()
 * - Scans directories with scandir() or RecursiveDirectoryIterator
 * - Creates ZIP files by scanning the entire project
 * - Stores file lists in arrays
 * 
 * ALL backup operations use git commands:
 * - git stash - for local changes backup
 * - git tag - for marking restore points
 * - git reflog - for recovery
 * 
 * Memory usage is constant regardless of project size.
 * 
 * @package Modules\System\GitHub\Services
 */

declare(strict_types=1);

namespace Modules\System\GitHub\Services;

use Core\System\System;
use Core\Storage\StorageManager;

require_once __DIR__ . '/gitservice.php';

class BackupService
{
    // Backup tag prefix
    private const BACKUP_TAG_PREFIX = 'backup/';
    private const MAX_BACKUPS = 10;
    
    private static ?self $instance = null;
    private GitService $git;
    
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
    }
    
    /**
     * Create a backup point using git
     * 
     * This creates:
     * 1. A stash of uncommitted changes (if any)
     * 2. A tag at the current commit
     * 
     * @param string|null $label Optional label for the backup
     * @return array Result with success status and backup info
     */
    public function createBackup(?string $label = null): array
    {
        if (!$this->git->isGitRepo()) {
            return [
                'success' => false,
                'error' => 'Not a git repository',
            ];
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $labelPart = $label ? '_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $label) : '';
        $tagName = self::BACKUP_TAG_PREFIX . $timestamp . $labelPart;
        
        $currentCommit = $this->git->getCurrentCommit();
        $hasUncommitted = $this->git->hasUncommittedChanges();
        
        System::log('system', 'Creating git backup', [
            'tag' => $tagName,
            'commit' => substr($currentCommit ?? '', 0, 7),
            'has_uncommitted' => $hasUncommitted,
        ]);
        
        // Stash uncommitted changes if any
        $stashRef = null;
        if ($hasUncommitted) {
            $stashMessage = "Backup {$timestamp}" . ($label ? " - {$label}" : '');
            $stashResult = $this->git->stash($stashMessage);
            
            if ($stashResult['success']) {
                $stashes = $this->git->stashList();
                if (!empty($stashes)) {
                    $stashRef = $stashes[0]['ref'];
                }
            }
        }
        
        // Create tag at current commit
        $tagMessage = "Backup created at {$timestamp}" . ($label ? " - {$label}" : '');
        $tagResult = $this->git->createTag($tagName, $tagMessage);
        
        if (!$tagResult['success']) {
            // Try to restore stash if tag creation failed
            if ($stashRef) {
                $this->git->stashPop();
            }
            
            return [
                'success' => false,
                'error' => 'Failed to create backup tag: ' . ($tagResult['error'] ?? 'Unknown error'),
            ];
        }
        
        // Update settings
        $settings = StorageManager::instance()->get('system/github') ?? [];
        $settings['last_backup'] = date('c');
        StorageManager::instance()->set('system/github', $settings);
        
        // Cleanup old backups
        $this->cleanupOldBackups();
        
        System::log('system', 'Backup created successfully', ['tag' => $tagName]);
        
        return [
            'success' => true,
            'tag' => $tagName,
            'commit' => $currentCommit,
            'commit_short' => substr($currentCommit ?? '', 0, 7),
            'stash_ref' => $stashRef,
            'has_uncommitted' => $hasUncommitted,
            'created_at' => date('c'),
            // For backward compatibility with old code
            'filename' => $tagName,
        ];
    }
    
    /**
     * Create a pre-update backup (labeled)
     */
    public function createPreUpdateBackup(string $commitSha = ''): array
    {
        $label = 'pre_update_' . substr($commitSha, 0, 7);
        return $this->createBackup($label);
    }
    
    /**
     * List all backups (git tags with backup/ prefix)
     */
    public function listBackups(): array
    {
        if (!$this->git->isGitRepo()) {
            return [];
        }
        
        $backups = [];
        
        // Get backup tags
        $result = $this->git->exec('tag', ['-l', self::BACKUP_TAG_PREFIX . '*', '--sort=-creatordate']);
        
        if (!$result['success']) {
            return [];
        }
        
        foreach ($result['output'] as $tag) {
            $tag = trim($tag);
            if (empty($tag)) continue;
            
            // Get tag info
            $infoResult = $this->git->exec('show', ['--no-patch', '--format=%H|%ci|%s', $tag]);
            
            $commit = '';
            $createdAt = '';
            $message = '';
            
            if ($infoResult['success'] && !empty($infoResult['output'])) {
                $parts = explode('|', $infoResult['output'][0], 3);
                if (count($parts) >= 3) {
                    $commit = $parts[0];
                    $createdAt = $parts[1];
                    $message = $parts[2];
                }
            }
            
            // Extract label from tag name
            $label = str_replace(self::BACKUP_TAG_PREFIX, '', $tag);
            
            $backups[] = [
                'tag' => $tag,
                'filename' => $tag, // For backward compatibility
                'commit' => $commit,
                'commit_short' => substr($commit, 0, 7),
                'created_at' => $createdAt,
                'label' => $label,
                'message' => $message,
                'type' => 'git_tag',
            ];
        }
        
        // Also list stashes as potential restore points
        $stashes = $this->git->stashList();
        foreach ($stashes as $stash) {
            $backups[] = [
                'tag' => $stash['ref'],
                'filename' => $stash['ref'],
                'commit' => '',
                'commit_short' => '',
                'created_at' => '',
                'label' => $stash['message'],
                'message' => $stash['message'],
                'type' => 'git_stash',
            ];
        }
        
        return $backups;
    }
    
    /**
     * Get backup info
     */
    public function getBackupInfo(string $tagName): ?array
    {
        $backups = $this->listBackups();
        
        foreach ($backups as $backup) {
            if ($backup['tag'] === $tagName || $backup['filename'] === $tagName) {
                return $backup;
            }
        }
        
        return null;
    }
    
    /**
     * Delete a backup (tag)
     */
    public function deleteBackup(string $tagName): array
    {
        if (strpos($tagName, 'stash@') === 0) {
            // It's a stash, drop it
            $result = $this->git->stashDrop($tagName);
        } else {
            // It's a tag, delete it
            $result = $this->git->deleteTag($tagName);
        }
        
        if ($result['success']) {
            System::log('system', 'Backup deleted', ['tag' => $tagName]);
        }
        
        return [
            'success' => $result['success'],
            'error' => $result['error'] ?? null,
        ];
    }
    
    /**
     * Cleanup old backups, keeping only MAX_BACKUPS
     */
    private function cleanupOldBackups(): void
    {
        $backups = $this->listBackups();
        
        // Filter only git_tag type
        $tagBackups = array_filter($backups, fn($b) => ($b['type'] ?? '') === 'git_tag');
        
        if (count($tagBackups) <= self::MAX_BACKUPS) {
            return;
        }
        
        // Remove oldest tags (they're already sorted by date desc)
        $toRemove = array_slice($tagBackups, self::MAX_BACKUPS);
        
        foreach ($toRemove as $backup) {
            $this->deleteBackup($backup['tag']);
        }
    }
    
    /**
     * Validate a backup (check if tag/stash exists)
     */
    public function validateBackup(string $tagName): array
    {
        if (strpos($tagName, 'stash@') === 0) {
            // Check if stash exists
            $stashes = $this->git->stashList();
            foreach ($stashes as $stash) {
                if ($stash['ref'] === $tagName) {
                    return ['valid' => true, 'type' => 'stash'];
                }
            }
            return ['valid' => false, 'error' => 'Stash not found'];
        }
        
        // Check if tag exists
        $result = $this->git->exec('tag', ['-l', $tagName]);
        
        if ($result['success'] && !empty($result['output'])) {
            return ['valid' => true, 'type' => 'tag'];
        }
        
        return ['valid' => false, 'error' => 'Backup tag not found'];
    }
    
    /**
     * Check if backup exists
     */
    public function backupExists(string $tagName): bool
    {
        $validation = $this->validateBackup($tagName);
        return $validation['valid'] ?? false;
    }
    
    /**
     * Get backup path (for backward compatibility - returns tag name)
     */
    public function getBackupPath(): string
    {
        return 'git://tags/' . self::BACKUP_TAG_PREFIX;
    }
    
    /**
     * Get diagnostic info
     */
    public function getDiagnostics(): array
    {
        return [
            'is_git_repo' => $this->git->isGitRepo(),
            'backup_count' => count($this->listBackups()),
            'max_backups' => self::MAX_BACKUPS,
            'backup_prefix' => self::BACKUP_TAG_PREFIX,
        ];
    }
}
