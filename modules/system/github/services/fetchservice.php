<?php
/**
 * Fetch Service - Hybrid Git/API Fetch Operations
 * 
 * This service supports TWO modes:
 * 
 * 1. GIT MODE (preferred): Uses native git commands when .git folder exists
 *    - git fetch, git diff, git log
 *    - Memory-safe, works with any project size
 * 
 * 2. API MODE (fallback): Uses GitHub API when git is not available
 *    - For servers deployed via FTP/upload without .git folder
 *    - Only fetches commit info (lightweight)
 *    - Cannot show file diffs (would require downloading entire repo)
 * 
 * @package Modules\System\GitHub\Services
 */

declare(strict_types=1);

namespace Modules\System\GitHub\Services;

use Core\System\System;
use Core\Storage\StorageManager;
use Modules\System\GitHub\GitHubGateway;

require_once __DIR__ . '/gitservice.php';
require_once __DIR__ . '/classifyservice.php';
require_once dirname(__DIR__) . '/gateway.php';

class FetchService
{
    private static ?self $instance = null;
    private GitService $git;
    private ClassifyService $classifier;
    private ?GitHubGateway $gateway = null;
    
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
     * Parse repository string (owner/repo)
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
        return ['owner' => trim($parts[0]), 'repo' => trim($parts[1])];
    }
    
    /**
     * Get configured gateway
     */
    private function getGateway(): GitHubGateway
    {
        if ($this->gateway === null) {
            $settings = $this->getSettings();
            $this->gateway = GitHubGateway::instance();
            $this->gateway->configure($settings['username'] ?? '', $settings['token'] ?? '');
        }
        return $this->gateway;
    }
    
    /**
     * Compare SHA hashes supporting both short (7 char) and full (40 char) SHAs
     * 
     * @param string $sha1 First SHA
     * @param string $sha2 Second SHA
     * @return bool True if SHAs match (prefix match for short SHAs)
     */
    private function shaMatch(string $sha1, string $sha2): bool
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
     * Configure is no longer needed - git uses local config
     * Kept for backward compatibility
     */
    public function configure(string $username, string $token): self
    {
        return $this;
    }
    
    /**
     * Fetch available updates
     * 
     * Uses git if available, otherwise falls back to GitHub API
     */
    public function fetchUpdates(): array
    {
        // Try git mode first
        if ($this->git->isGitRepo()) {
            return $this->fetchUpdatesViaGit();
        }
        
        // Fallback to API mode
        return $this->fetchUpdatesViaApi();
    }
    
    /**
     * Fetch updates using native git commands (preferred)
     */
    private function fetchUpdatesViaGit(): array
    {
        $settings = $this->getSettings();
        $branch = $settings['branch'] ?? 'main';
        
        // Fetch from remote
        $fetchResult = $this->git->fetch();
        
        if (!$fetchResult['success']) {
            return [
                'success' => false,
                'error' => 'Fetch failed: ' . ($fetchResult['error'] ?? 'Cannot connect to remote'),
                'mode' => 'git',
            ];
        }
        
        // Check for updates
        $updateCheck = $this->git->checkForUpdates($branch);
        
        if (isset($updateCheck['error'])) {
            return [
                'success' => false,
                'error' => $updateCheck['error'],
                'mode' => 'git',
            ];
        }
        
        $hasUpdates = $updateCheck['has_updates'];
        $newCommits = [];
        $changedFiles = [];
        $classified = [
            ClassifyService::SAFE => [],
            ClassifyService::VERIFY => [],
            ClassifyService::PROTECTED => [],
        ];
        
        if ($hasUpdates) {
            // Get new commits
            $newCommits = $this->git->getNewCommits($branch, 'origin', 50);
            
            // Get changed files (names only, no content!)
            $files = $this->git->getChangedFiles($branch);
            
            foreach ($files as $file) {
                $filename = $file['file'];
                $status = $file['status'];
                
                $category = $this->classifier->classify($filename);
                
                $fileInfo = [
                    'filename' => $filename,
                    'status' => $status,
                    'classification' => $category,
                ];
                
                $changedFiles[$filename] = $fileInfo;
                $classified[$category][] = $fileInfo;
            }
        }
        
        // Update last fetch time
        $settings['last_fetch'] = date('c');
        StorageManager::instance()->set('system/github', $settings);
        
        $currentCommit = $this->git->getCurrentCommitShort();
        
        System::log('system', 'Git fetch completed', [
            'mode' => 'git',
            'branch' => $branch,
            'has_updates' => $hasUpdates,
            'commits_behind' => $updateCheck['behind'] ?? 0,
            'files_changed' => count($changedFiles),
        ]);
        
        return [
            'success' => true,
            'mode' => 'git',
            'has_updates' => $hasUpdates,
            'commits' => [
                'total' => count($newCommits),
                'new' => count($newCommits),
                'behind' => $updateCheck['behind'] ?? 0,
                'ahead' => $updateCheck['ahead'] ?? 0,
                'list' => $newCommits,
            ],
            'files' => [
                'total' => count($changedFiles),
                'safe' => count($classified[ClassifyService::SAFE]),
                'verify' => count($classified[ClassifyService::VERIFY]),
                'protected' => count($classified[ClassifyService::PROTECTED]),
                'list' => $changedFiles,
                'classified' => $classified,
            ],
            'current_commit' => $currentCommit,
            'branch' => $branch,
            'remote_url' => $this->git->getRemoteUrl(),
            'release' => null,
            'latest_commit' => null,
        ];
    }
    
    /**
     * Fetch updates using GitHub API (fallback for non-git deployments)
     * 
     * This mode:
     * - Gets commit history from GitHub API
     * - Compares with stored last_commit
     * - Cannot show file diffs (would require downloading entire repo)
     */
    private function fetchUpdatesViaApi(): array
    {
        $settings = $this->getSettings();
        $branch = $settings['branch'] ?? 'main';
        $lastCommit = $settings['last_commit'] ?? null;
        
        // Validate configuration
        if (empty($settings['update_repo']) || empty($settings['token'])) {
            return [
                'success' => false,
                'error' => 'GitHub not configured. Please set repository and token in settings.',
                'mode' => 'api',
            ];
        }
        
        $repoInfo = $this->parseRepo($settings['update_repo']);
        if (!$repoInfo) {
            return [
                'success' => false,
                'error' => 'Invalid repository format. Use owner/repo format.',
                'mode' => 'api',
            ];
        }
        
        $gateway = $this->getGateway();
        
        // Get commits from GitHub API
        $commitsResult = $gateway->getCommits(
            $repoInfo['owner'],
            $repoInfo['repo'],
            $branch,
            50
        );
        
        if (!$commitsResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to fetch commits: ' . ($gateway->getProbableCause($commitsResult)),
                'mode' => 'api',
            ];
        }
        
        $commits = $commitsResult['result'] ?? [];
        
        // Find new commits since last update
        $newCommits = [];
        $latestCommit = null;
        
        if (!empty($commits)) {
            $latestCommit = $commits[0];
            
            if ($lastCommit) {
                foreach ($commits as $commit) {
                    $commitSha = $commit['sha'] ?? '';
                    // Compare using prefix matching (support both short and full SHAs)
                    if ($this->shaMatch($commitSha, $lastCommit)) {
                        break;
                    }
                    $newCommits[] = [
                        'sha' => substr($commitSha, 0, 7),
                        'full_sha' => $commitSha,
                        'message' => $commit['commit']['message'] ?? '',
                        'author' => $commit['commit']['author']['name'] ?? 'Unknown',
                        'date' => $commit['commit']['author']['date'] ?? '',
                    ];
                }
            } else {
                // No last commit recorded - show latest commits
                foreach (array_slice($commits, 0, 10) as $commit) {
                    $newCommits[] = [
                        'sha' => substr($commit['sha'] ?? '', 0, 7),
                        'message' => $commit['commit']['message'] ?? '',
                        'author' => $commit['commit']['author']['name'] ?? 'Unknown',
                        'date' => $commit['commit']['author']['date'] ?? '',
                    ];
                }
            }
        }
        
        $hasUpdates = !empty($newCommits);
        
        // Get list of changed files using GitHub Compare API
        $changedFiles = [];
        $classified = [
            ClassifyService::SAFE => [],
            ClassifyService::VERIFY => [],
            ClassifyService::PROTECTED => [],
        ];
        
        if ($hasUpdates && $lastCommit && $latestCommit) {
            // Use Compare API to get file changes
            $compareResult = $gateway->compareCommits(
                $repoInfo['owner'],
                $repoInfo['repo'],
                $lastCommit,
                $latestCommit['sha'] ?? $branch
            );
            
            if ($compareResult['success'] && !empty($compareResult['result']['files'])) {
                foreach ($compareResult['result']['files'] as $file) {
                    $filename = $file['filename'] ?? '';
                    if (empty($filename)) continue;
                    
                    // Map GitHub status to our status
                    $status = match($file['status'] ?? 'modified') {
                        'added' => 'A',
                        'removed' => 'D',
                        'renamed' => 'R',
                        default => 'M',
                    };
                    
                    $category = $this->classifier->classify($filename);
                    
                    $fileInfo = [
                        'filename' => $filename,
                        'status' => $status,
                        'classification' => $category,
                        'additions' => $file['additions'] ?? 0,
                        'deletions' => $file['deletions'] ?? 0,
                        'changes' => $file['changes'] ?? 0,
                    ];
                    
                    $changedFiles[$filename] = $fileInfo;
                    $classified[$category][] = $fileInfo;
                }
            }
        }
        
        // Get latest release (optional)
        $release = null;
        $releaseResult = $gateway->getLatestRelease($repoInfo['owner'], $repoInfo['repo']);
        if ($releaseResult['success']) {
            $release = $releaseResult['result'];
        }
        
        // Update last fetch time
        $settings['last_fetch'] = date('c');
        StorageManager::instance()->set('system/github', $settings);
        
        System::log('system', 'GitHub API fetch completed', [
            'mode' => 'api',
            'repo' => $settings['update_repo'],
            'has_updates' => $hasUpdates,
            'new_commits' => count($newCommits),
            'files_changed' => count($changedFiles),
        ]);
        
        return [
            'success' => true,
            'mode' => 'api',
            'has_updates' => $hasUpdates,
            'commits' => [
                'total' => count($commits),
                'new' => count($newCommits),
                'behind' => count($newCommits),
                'ahead' => 0,
                'list' => $newCommits,
            ],
            'files' => [
                'total' => count($changedFiles),
                'safe' => count($classified[ClassifyService::SAFE]),
                'verify' => count($classified[ClassifyService::VERIFY]),
                'protected' => count($classified[ClassifyService::PROTECTED]),
                'list' => $changedFiles,
                'classified' => $classified,
            ],
            'current_commit' => $lastCommit ? substr($lastCommit, 0, 7) : 'unknown',
            'branch' => $branch,
            'remote_url' => "https://github.com/{$settings['update_repo']}",
            'release' => $release,
            'latest_commit' => $latestCommit ? substr($latestCommit['sha'] ?? '', 0, 7) : null,
            'latest_commit_full' => $latestCommit['sha'] ?? null,
            'api_mode_notice' => 'Running in API mode (no .git folder). Files shown via GitHub Compare API.',
        ];
    }
    
    /**
     * Get update preview
     * 
     * Returns a summary suitable for UI display before update
     */
    public function getUpdatePreview(): array
    {
        $updates = $this->fetchUpdates();
        
        if (!$updates['success']) {
            return $updates;
        }
        
        // Build preview
        $preview = [
            'can_update' => ($updates['mode'] ?? 'git') === 'git', // Can only auto-update in git mode
            'requires_confirmation' => ($updates['files']['verify'] ?? 0) > 0,
            'has_protected' => ($updates['files']['protected'] ?? 0) > 0,
            'summary' => [
                'commits' => $updates['commits']['new'] ?? 0,
                'files_total' => $updates['files']['total'] ?? 0,
                'files_safe' => $updates['files']['safe'] ?? 0,
                'files_verify' => $updates['files']['verify'] ?? 0,
                'files_protected' => $updates['files']['protected'] ?? 0,
            ],
            'warnings' => [],
            'info' => [],
        ];
        
        // Add API mode warning
        if (($updates['mode'] ?? '') === 'api') {
            $preview['warnings'][] = [
                'file' => '',
                'message' => 'Running in API mode. Automatic updates require git. Please deploy using git clone.',
            ];
        }
        
        // Add warnings for verify files
        foreach ($updates['files']['classified'][ClassifyService::VERIFY] ?? [] as $file) {
            $preview['warnings'][] = [
                'file' => $file['filename'],
                'message' => "Config file will be modified: {$file['filename']}",
            ];
        }
        
        // Add info for protected files
        foreach ($updates['files']['classified'][ClassifyService::PROTECTED] ?? [] as $file) {
            $preview['info'][] = [
                'file' => $file['filename'],
                'message' => "Protected file will be skipped: {$file['filename']}",
            ];
        }
        
        return array_merge($updates, ['preview' => $preview]);
    }
    
    /**
     * Get current status
     */
    public function getStatus(): array
    {
        if ($this->git->isGitRepo()) {
            return [
                'success' => true,
                'mode' => 'git',
                'branch' => $this->git->getCurrentBranch(),
                'commit' => $this->git->getCurrentCommitShort(),
                'has_uncommitted' => $this->git->hasUncommittedChanges(),
                'uncommitted_changes' => $this->git->getUncommittedChanges(),
                'remote_url' => $this->git->getRemoteUrl(),
            ];
        }
        
        // API mode - return stored info
        $settings = $this->getSettings();
        return [
            'success' => true,
            'mode' => 'api',
            'branch' => $settings['branch'] ?? 'main',
            'commit' => $settings['last_commit'] ? substr($settings['last_commit'], 0, 7) : 'unknown',
            'has_uncommitted' => false,
            'uncommitted_changes' => [],
            'remote_url' => !empty($settings['update_repo']) ? "https://github.com/{$settings['update_repo']}" : null,
            'note' => 'Running in API mode (no .git folder)',
        ];
    }
    
    /**
     * Get diagnostics
     */
    public function getDiagnostics(): array
    {
        $gitDiag = $this->git->getDiagnostics();
        $settings = $this->getSettings();
        
        return array_merge($gitDiag, [
            'mode' => $gitDiag['is_git_repo'] ? 'git' : 'api',
            'update_repo' => $settings['update_repo'] ?? null,
            'has_token' => !empty($settings['token']),
            'last_commit' => $settings['last_commit'] ?? null,
            'last_fetch' => $settings['last_fetch'] ?? null,
            'last_update' => $settings['last_update'] ?? null,
        ]);
    }
}
