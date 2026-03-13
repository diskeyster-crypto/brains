<?php
declare(strict_types=1);

namespace Modules\System\GitHub;

use Core\Gateway\GatewayInterface;
use Core\System\System;

class GitHubGateway implements GatewayInterface
{
    private const API_BASE = 'https://api.github.com';
    private const RAW_BASE = 'https://raw.githubusercontent.com';

    private string $token = '';
    private string $username = '';
    private int $timeout = 30;

    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function configure(string $username, string $token): self
    {
        $this->username = $username;
        $this->token = $token;
        return $this;
    }

    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * MAIN request method (GatewayInterface contract)
     * GitHub uses GET by default.
     */
    public function request(string $endpoint, array $params = [], bool $signed = true): array
    {
        $startTime = microtime(true);

        $url = self::API_BASE . '/' . ltrim($endpoint, '/');

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();

        $headers = [
            'Accept: application/vnd.github.v3+json',
            'User-Agent: Tredercopis-Core/1.3',
        ];

        if ($signed && !empty($this->token)) {
            $headers[] = 'Authorization: token ' . $this->token;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER => true,
        ]);

        $response = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        curl_close($ch);

        $requestTime = microtime(true) - $startTime;

        $responseHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $data = json_decode($body, true);

        System::log('gateway', 'GitHub API request', [
            'endpoint' => $endpoint,
            'http_code' => $httpCode,
            'time' => round($requestTime, 3) . 's',
        ]);

        return [
            'success' => $curlErrno === 0 && $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'ret_code' => $data['status'] ?? null,
            'ret_msg' => $data['message'] ?? null,
            'endpoint' => $endpoint,
            'result' => $data,
            'raw_text' => $body,
            'curl_errno' => $curlErrno,
            'curl_error' => $curlError,
            'response_headers' => $this->parseHeaders($responseHeaders),
            'request_time' => $requestTime,
        ];
    }

    public function getProbableCause(array $response): string
    {
        if ($response['curl_errno'] !== 0) {
            return match($response['curl_errno']) {
                6 => 'DNS resolution failed.',
                7 => 'Connection failed.',
                28 => 'Timeout.',
                35 => 'SSL error.',
                default => 'Network error: ' . $response['curl_error'],
            };
        }

        return match($response['http_code']) {
            401 => 'Authentication failed.',
            403 => 'Forbidden or rate limited.',
            404 => 'Not found.',
            422 => 'Validation failed.',
            500, 502, 503 => 'GitHub server error.',
            default => $response['ret_msg'] ?? 'Unknown error',
        };
    }

    /* ================= HIGH LEVEL API ================= */

    /**
     * Test GitHub API connection
     * 
     * @return array ['success' => bool, 'user' => string, 'name' => string, 'rate_limit' => int, 'error' => string]
     */
    public function testConnection(): array
    {
        // Test by getting authenticated user info
        $result = $this->request('user');
        
        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $this->getProbableCause($result),
            ];
        }
        
        $userData = $result['result'] ?? [];
        $headers = $result['response_headers'] ?? [];
        
        return [
            'success' => true,
            'user' => $userData['login'] ?? 'Unknown',
            'name' => $userData['name'] ?? '',
            'email' => $userData['email'] ?? '',
            'rate_limit' => $headers['x-ratelimit-remaining'] ?? 'Unknown',
            'rate_limit_reset' => $headers['x-ratelimit-reset'] ?? null,
        ];
    }

    public function getRepo(string $owner, string $repo): array
    {
        return $this->request("repos/{$owner}/{$repo}");
    }

    public function getUserRepos(string $username = '', int $perPage = 100): array
    {
        $user = $username ?: $this->username;
        return $this->request("users/{$user}/repos", [
            'per_page' => $perPage,
            'sort' => 'updated',
        ]);
    }

    public function getAuthenticatedUserRepos(int $perPage = 100): array
    {
        return $this->request('user/repos', [
            'per_page' => $perPage,
        ]);
    }

    public function getCommits(string $owner, string $repo, string $branch = 'main', int $perPage = 10): array
    {
        return $this->request("repos/{$owner}/{$repo}/commits", [
            'sha' => $branch,
            'per_page' => $perPage,
        ]);
    }

    public function getBranches(string $owner, string $repo): array
    {
        return $this->request("repos/{$owner}/{$repo}/branches");
    }

    public function getTags(string $owner, string $repo): array
    {
        return $this->request("repos/{$owner}/{$repo}/tags");
    }

    public function getLatestRelease(string $owner, string $repo): array
    {
        return $this->request("repos/{$owner}/{$repo}/releases/latest");
    }

    public function getFileContents(string $owner, string $repo, string $path, string $branch = 'main'): array
    {
        return $this->request("repos/{$owner}/{$repo}/contents/{$path}", [
            'ref' => $branch,
        ]);
    }
    
    /**
     * Compare two commits and get list of changed files
     * 
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $base Base commit SHA
     * @param string $head Head commit SHA or branch
     * @return array Response with files array containing changed files
     */
    public function compareCommits(string $owner, string $repo, string $base, string $head): array
    {
        return $this->request("repos/{$owner}/{$repo}/compare/{$base}...{$head}");
    }
    
    /**
     * Get single commit details including files
     * 
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $sha Commit SHA
     * @return array Response with commit details including 'files' array
     */
    public function getCommit(string $owner, string $repo, string $sha): array
    {
        return $this->request("repos/{$owner}/{$repo}/commits/{$sha}");
    }

    /* ============ PUT / UPLOAD ============ */

    public function putRequest(string $endpoint, array $params = []): array
    {
        $url = self::API_BASE . '/' . ltrim($endpoint, '/');

        $ch = curl_init();

        $headers = [
            'Accept: application/vnd.github.v3+json',
            'User-Agent: Tredercopis-Core/1.3',
            'Content-Type: application/json',
        ];

        if (!empty($this->token)) {
            $headers[] = 'Authorization: token ' . $this->token;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($params),
        ]);

        $content = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        return [
            'success' => $errno === 0 && $code >= 200 && $code < 300,
            'http_code' => $code,
            'content' => $content,
            'curl_errno' => $errno,
            'curl_error' => $error,
        ];
    }

    public function uploadFile(string $owner, string $repo, string $path, string $content, string $message, string $branch = ''): array
    {
        $params = [
            'message' => $message,
            'content' => base64_encode($content),
        ];
        
        // Add branch if specified (for non-default branches)
        if (!empty($branch)) {
            $params['branch'] = $branch;
        }
        
        // First, check if file already exists (to get sha for update)
        $existingFile = $this->getFileContents($owner, $repo, $path, $branch ?: 'main');
        if ($existingFile['success'] && isset($existingFile['result']['sha'])) {
            $params['sha'] = $existingFile['result']['sha'];
        }
        
        return $this->putRequest("repos/{$owner}/{$repo}/contents/{$path}", $params);
    }

    /**
     * Download repository archive (ZIP)
     * 
     * @param string $owner Repository owner
     * @param string $repo Repository name
     * @param string $branch Branch name (default: main)
     * @return array ['success' => bool, 'content' => string, 'http_code' => int]
     */
    public function downloadArchive(string $owner, string $repo, string $branch = 'main'): array
    {
        $startTime = microtime(true);
        
        $url = self::API_BASE . "/repos/{$owner}/{$repo}/zipball/{$branch}";
        
        $ch = curl_init();
        
        $headers = [
            'Accept: application/vnd.github.v3+json',
            'User-Agent: Tredercopis-Core/1.3',
        ];
        
        if (!empty($this->token)) {
            $headers[] = 'Authorization: token ' . $this->token;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120, // ZIP downloads may take time
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true, // GitHub redirects to S3
            CURLOPT_MAXREDIRS => 5,
        ]);
        
        $content = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        
        curl_close($ch);
        
        $requestTime = microtime(true) - $startTime;
        
        System::log('gateway', 'GitHub archive download', [
            'repo' => "{$owner}/{$repo}",
            'branch' => $branch,
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'size' => strlen($content),
            'time' => round($requestTime, 3) . 's',
        ]);
        
        $success = $curlErrno === 0 && $httpCode >= 200 && $httpCode < 300;
        
        return [
            'success' => $success,
            'http_code' => $httpCode,
            'content' => $content,
            'content_type' => $contentType,
            'curl_errno' => $curlErrno,
            'curl_error' => $curlError,
            'request_time' => $requestTime,
        ];
    }

    /* ============ HELPERS ============ */

    private function parseHeaders(string $headerString): array
    {
        $headers = [];
        foreach (explode("\r\n", $headerString) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        return $headers;
    }
}
