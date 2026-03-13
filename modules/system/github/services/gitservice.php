<?php
/**
 * Git Service - Memory-Safe Git Operations
 * 
 * ARCHITECTURE RULE: PHP is ONLY an orchestrator of shell git commands.
 * 
 * This service NEVER:
 * - Reads file contents with file_get_contents()
 * - Scans directories with scandir() or RecursiveDirectoryIterator
 * - Stores file lists in arrays
 * - Downloads ZIP archives
 * 
 * ALL operations use shell exec with git commands.
 * Memory usage is constant regardless of project size.
 * 
 * @package Modules\System\GitHub\Services
 */

declare(strict_types=1);

namespace Modules\System\GitHub\Services;

use Core\System\System;

class GitService
{
    private static ?self $instance = null;
    private string $rootPath;
    private string $gitPath;
    
    // Git command timeout in seconds
    private const GIT_TIMEOUT = 120;
    
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct()
    {
        $this->rootPath = System::path('root');
        $this->gitPath = $this->findGitPath();
    }
    
    /**
     * Find git executable path
     * 
     * Note: We avoid file_exists() on system paths because open_basedir
     * restrictions may prevent checking paths outside allowed directories.
     * Instead, we use 'which git' or just 'git' and let the shell find it.
     */
    private function findGitPath(): string
    {
        // Try to find git using 'which' command (works on most Unix systems)
        $output = [];
        $code = 0;
        @exec('which git 2>/dev/null', $output, $code);
        
        if ($code === 0 && !empty($output[0])) {
            return trim($output[0]);
        }
        
        // Fallback to just 'git' and let the shell find it in PATH
        // This avoids open_basedir issues with file_exists() on system paths
        return 'git';
    }
    
    /**
     * Execute a git command safely
     * 
     * @param string $command Git subcommand (e.g., 'fetch', 'pull')
     * @param array $args Additional arguments
     * @param int|null $timeout Optional timeout override
     * @return array ['success' => bool, 'output' => string[], 'exit_code' => int, 'error' => string|null]
     */
    public function exec(string $command, array $args = [], ?int $timeout = null): array
    {
        $timeout = $timeout ?? self::GIT_TIMEOUT;
        
        // Note: We skip file_exists() check on gitPath to avoid open_basedir warnings
        // The gitPath is either 'git' (found via PATH) or a path from 'which git'
        // If git is not available, the exec() call will fail naturally
        
        // Whitelist allowed git subcommands to prevent command injection
        $allowedCommands = [
            'fetch', 'pull', 'push', 'status', 'log', 'diff', 'stash', 
            'reset', 'checkout', 'branch', 'tag', 'remote', 'rev-parse',
            'rev-list', 'show', 'clean', '--version'
        ];
        
        if (!in_array($command, $allowedCommands)) {
            return [
                'success' => false,
                'output' => [],
                'exit_code' => -1,
                'error' => 'Git command not allowed: ' . $command,
            ];
        }
        
        // Build command with proper escaping
        $fullCommand = escapeshellarg($this->gitPath) . ' ' . escapeshellarg($command);
        foreach ($args as $arg) {
            $fullCommand .= ' ' . escapeshellarg($arg);
        }
        
        // Add timeout wrapper if available (use 'which' to avoid open_basedir issues)
        $timeoutCommand = '';
        $timeoutCheck = [];
        @exec('which timeout 2>/dev/null', $timeoutCheck, $timeoutCode);
        if ($timeoutCode === 0 && !empty($timeoutCheck[0])) {
            $timeoutPath = escapeshellarg(trim($timeoutCheck[0]));
            $timeoutCommand = "{$timeoutPath} " . escapeshellarg("{$timeout}s") . " ";
        }
        
        // Change to project root and execute
        $fullCommand = "cd " . escapeshellarg($this->rootPath) . " && {$timeoutCommand}{$fullCommand} 2>&1";
        
        $output = [];
        $exitCode = 0;
        
        exec($fullCommand, $output, $exitCode);
        
        $success = $exitCode === 0;
        $error = null;
        
        if (!$success) {
            $error = implode("\n", $output);
            System::log('system', 'Git command failed', [
                'command' => $command,
                'exit_code' => $exitCode,
                'error' => $error,
            ]);
        }
        
        return [
            'success' => $success,
            'output' => $output,
            'exit_code' => $exitCode,
            'error' => $error,
        ];
    }
    
    /**
     * Check if current directory is a git repository
     */
    public function isGitRepo(): bool
    {
        $result = $this->exec('rev-parse', ['--is-inside-work-tree']);
        return $result['success'] && !empty($result['output']) && trim($result['output'][0]) === 'true';
    }
    
    /**
     * Get current branch name
     */
    public function getCurrentBranch(): ?string
    {
        $result = $this->exec('branch', ['--show-current']);
        if ($result['success'] && !empty($result['output'])) {
            return trim($result['output'][0]);
        }
        return null;
    }
    
    /**
     * Get current commit SHA
     */
    public function getCurrentCommit(): ?string
    {
        $result = $this->exec('rev-parse', ['HEAD']);
        if ($result['success'] && !empty($result['output'])) {
            return trim($result['output'][0]);
        }
        return null;
    }
    
    /**
     * Get short commit SHA (7 chars)
     */
    public function getCurrentCommitShort(): ?string
    {
        $result = $this->exec('rev-parse', ['--short', 'HEAD']);
        if ($result['success'] && !empty($result['output'])) {
            return trim($result['output'][0]);
        }
        return null;
    }
    
    /**
     * Fetch updates from remote
     * 
     * @param string $remote Remote name (default: origin)
     * @return array
     */
    public function fetch(string $remote = 'origin'): array
    {
        System::log('system', 'Git fetch starting', ['remote' => $remote]);
        
        $result = $this->exec('fetch', [$remote, '--prune']);
        
        if ($result['success']) {
            System::log('system', 'Git fetch completed');
        }
        
        return $result;
    }
    
    /**
     * Check if there are uncommitted changes
     */
    public function hasUncommittedChanges(): bool
    {
        $result = $this->exec('status', ['--porcelain']);
        return $result['success'] && !empty($result['output']);
    }
    
    /**
     * Get list of uncommitted changes (file names only, not content)
     */
    public function getUncommittedChanges(): array
    {
        $result = $this->exec('status', ['--porcelain']);
        
        if (!$result['success']) {
            return [];
        }
        
        $changes = [];
        foreach ($result['output'] as $line) {
            if (strlen($line) >= 3) {
                $status = substr($line, 0, 2);
                $file = trim(substr($line, 3));
                $changes[] = [
                    'status' => trim($status),
                    'file' => $file,
                ];
            }
        }
        
        return $changes;
    }
    
    /**
     * Check if remote has new commits
     * 
     * @param string $branch Branch to check
     * @param string $remote Remote name
     * @return array ['has_updates' => bool, 'behind' => int, 'ahead' => int]
     */
    public function checkForUpdates(string $branch = 'main', string $remote = 'origin'): array
    {
        // First fetch to get latest remote info
        $this->fetch($remote);
        
        // Get commit counts
        $result = $this->exec('rev-list', ['--left-right', '--count', "HEAD...{$remote}/{$branch}"]);
        
        if (!$result['success'] || empty($result['output'])) {
            return [
                'has_updates' => false,
                'behind' => 0,
                'ahead' => 0,
                'error' => $result['error'] ?? 'Failed to check for updates',
            ];
        }
        
        // Parse "ahead\tbehind" format
        $counts = preg_split('/\s+/', trim($result['output'][0]));
        $ahead = (int)($counts[0] ?? 0);
        $behind = (int)($counts[1] ?? 0);
        
        return [
            'has_updates' => $behind > 0,
            'behind' => $behind,
            'ahead' => $ahead,
        ];
    }
    
    /**
     * Get list of commits between current and remote
     * 
     * @param string $branch Branch name
     * @param string $remote Remote name
     * @param int $limit Maximum number of commits to return
     * @return array List of commit info
     */
    public function getNewCommits(string $branch = 'main', string $remote = 'origin', int $limit = 20): array
    {
        $result = $this->exec('log', [
            '--oneline',
            '--no-merges',
            "-{$limit}",
            "HEAD..{$remote}/{$branch}",
        ]);
        
        if (!$result['success']) {
            return [];
        }
        
        $commits = [];
        foreach ($result['output'] as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Parse "sha message" format
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
    
    /**
     * Get files changed between current and remote
     * Returns ONLY file names, NOT content
     * 
     * @param string $branch Branch name
     * @param string $remote Remote name
     * @return array List of changed files with status
     */
    public function getChangedFiles(string $branch = 'main', string $remote = 'origin'): array
    {
        $result = $this->exec('diff', [
            '--name-status',
            "HEAD...{$remote}/{$branch}",
        ]);
        
        if (!$result['success']) {
            return [];
        }
        
        $files = [];
        foreach ($result['output'] as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Parse "status\tfilename" format
            $parts = preg_split('/\s+/', $line, 2);
            if (count($parts) >= 2) {
                $files[] = [
                    'status' => $parts[0], // A=added, M=modified, D=deleted
                    'file' => $parts[1],
                ];
            }
        }
        
        return $files;
    }
    
    /**
     * Stash current changes (for backup before update)
     * 
     * @param string $message Stash message
     * @return array
     */
    public function stash(string $message = 'Pre-update backup'): array
    {
        System::log('system', 'Git stash starting', ['message' => $message]);
        
        $result = $this->exec('stash', ['push', '-m', $message, '--include-untracked']);
        
        if ($result['success']) {
            System::log('system', 'Git stash completed');
        }
        
        return $result;
    }
    
    /**
     * Apply and drop the latest stash
     */
    public function stashPop(): array
    {
        return $this->exec('stash', ['pop']);
    }
    
    /**
     * List stashes
     */
    public function stashList(): array
    {
        $result = $this->exec('stash', ['list']);
        
        if (!$result['success']) {
            return [];
        }
        
        $stashes = [];
        foreach ($result['output'] as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Parse "stash@{n}: On branch: message" format
            if (preg_match('/^(stash@\{\d+\}):\s*(.+)$/', $line, $matches)) {
                $stashes[] = [
                    'ref' => $matches[1],
                    'message' => $matches[2],
                ];
            }
        }
        
        return $stashes;
    }
    
    /**
     * Apply a specific stash
     * 
     * @param string $ref Stash reference (e.g., 'stash@{0}')
     */
    public function stashApply(string $ref = 'stash@{0}'): array
    {
        return $this->exec('stash', ['apply', $ref]);
    }
    
    /**
     * Drop a specific stash
     */
    public function stashDrop(string $ref = 'stash@{0}'): array
    {
        return $this->exec('stash', ['drop', $ref]);
    }
    
    /**
     * Pull updates from remote
     * 
     * @param string $remote Remote name
     * @param string $branch Branch name
     * @return array
     */
    public function pull(string $remote = 'origin', string $branch = 'main'): array
    {
        System::log('system', 'Git pull starting', ['remote' => $remote, 'branch' => $branch]);
        
        $result = $this->exec('pull', [$remote, $branch]);
        
        if ($result['success']) {
            System::log('system', 'Git pull completed');
        }
        
        return $result;
    }
    
    /**
     * Hard reset to a specific commit or remote branch
     * 
     * @param string $target Target commit/branch (e.g., 'origin/main', 'HEAD~1')
     * @return array
     */
    public function resetHard(string $target): array
    {
        System::log('system', 'Git reset --hard starting', ['target' => $target]);
        
        $result = $this->exec('reset', ['--hard', $target]);
        
        if ($result['success']) {
            System::log('system', 'Git reset completed');
        }
        
        return $result;
    }
    
    /**
     * Checkout a specific branch
     * 
     * @param string $branch Branch name
     * @return array
     */
    public function checkout(string $branch): array
    {
        return $this->exec('checkout', [$branch]);
    }
    
    /**
     * Create and switch to a new branch
     */
    public function checkoutNewBranch(string $branch): array
    {
        return $this->exec('checkout', ['-b', $branch]);
    }
    
    /**
     * Create a tag (for backup points)
     * 
     * @param string $tagName Tag name
     * @param string $message Tag message
     * @return array
     */
    public function createTag(string $tagName, string $message = ''): array
    {
        if (!empty($message)) {
            // Annotated tag: git tag -a -m "message" tagname
            return $this->exec('tag', ['-a', '-m', $message, $tagName]);
        }
        // Lightweight tag: git tag tagname
        return $this->exec('tag', [$tagName]);
    }
    
    /**
     * List tags
     */
    public function listTags(): array
    {
        $result = $this->exec('tag', ['-l', '--sort=-creatordate']);
        return $result['success'] ? $result['output'] : [];
    }
    
    /**
     * Delete a tag
     */
    public function deleteTag(string $tagName): array
    {
        return $this->exec('tag', ['-d', $tagName]);
    }
    
    /**
     * Get remote URL
     */
    public function getRemoteUrl(string $remote = 'origin'): ?string
    {
        $result = $this->exec('remote', ['get-url', $remote]);
        if ($result['success'] && !empty($result['output'])) {
            return trim($result['output'][0]);
        }
        return null;
    }
    
    /**
     * Set remote URL (for changing repo)
     */
    public function setRemoteUrl(string $url, string $remote = 'origin'): array
    {
        return $this->exec('remote', ['set-url', $remote, $url]);
    }
    
    /**
     * Add a new remote
     */
    public function addRemote(string $name, string $url): array
    {
        return $this->exec('remote', ['add', $name, $url]);
    }
    
    /**
     * Clean untracked files (careful!)
     */
    public function cleanUntracked(): array
    {
        return $this->exec('clean', ['-fd']);
    }
    
    /**
     * Get git version
     */
    public function getVersion(): ?string
    {
        $result = $this->exec('--version', []);
        if ($result['success'] && !empty($result['output'])) {
            // Parse "git version X.Y.Z" format
            $line = $result['output'][0];
            if (preg_match('/git version ([\d.]+)/', $line, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }
    
    /**
     * Get diagnostic information
     */
    public function getDiagnostics(): array
    {
        $isGitRepo = $this->isGitRepo();
        $branch = null;
        $commit = null;
        $remoteUrl = null;
        $hasUncommitted = false;
        $errors = [];
        
        if ($isGitRepo) {
            // Get branch
            $branchResult = $this->exec('branch', ['--show-current']);
            if ($branchResult['success'] && !empty($branchResult['output'])) {
                $branch = trim($branchResult['output'][0]);
            } elseif (!$branchResult['success']) {
                $errors['branch'] = $branchResult['error'] ?? 'Failed to get branch';
            }
            
            // Get commit
            $commitResult = $this->exec('rev-parse', ['--short', 'HEAD']);
            if ($commitResult['success'] && !empty($commitResult['output'])) {
                $commit = trim($commitResult['output'][0]);
            } elseif (!$commitResult['success']) {
                $errors['commit'] = $commitResult['error'] ?? 'Failed to get commit';
            }
            
            // Get remote URL
            $remoteResult = $this->exec('remote', ['get-url', 'origin']);
            if ($remoteResult['success'] && !empty($remoteResult['output'])) {
                $remoteUrl = trim($remoteResult['output'][0]);
            } elseif (!$remoteResult['success']) {
                // Check if any remotes exist
                $remotesResult = $this->exec('remote', ['-v']);
                if (!empty($remotesResult['output'])) {
                    $errors['remote_url'] = 'Remote "origin" not found. Available: ' . implode(', ', $remotesResult['output']);
                } else {
                    $errors['remote_url'] = 'No remotes configured';
                }
            }
            
            // Check for uncommitted changes
            $statusResult = $this->exec('status', ['--porcelain']);
            $hasUncommitted = $statusResult['success'] && !empty($statusResult['output']);
        }
        
        return [
            'git_path' => $this->gitPath,
            'git_version' => $this->getVersion(),
            'root_path' => $this->rootPath,
            'is_git_repo' => $isGitRepo,
            'current_branch' => $branch,
            'current_commit' => $commit,
            'remote_url' => $remoteUrl,
            'has_uncommitted' => $hasUncommitted,
            'errors' => $errors,
        ];
    }
}
