<?php

declare(strict_types=1);

namespace Core\KeyCenter;

use Core\System\System;
use Core\Logger\Logger;

/**
 * Centralized Key Center for credential management
 * 
 * All API keys, secrets, tokens should go through this class.
 * Supports encrypted storage and multiple accounts.
 */
final class KeyCenter
{
    private static ?self $instance = null;
    private array $keys = [];
    private string $storagePath;
    private string $encryptionKey;

    private function __construct()
    {
        $this->storagePath = System::path('storage') . '/keys.json';
        $this->encryptionKey = $this->getEncryptionKey();
        $this->load();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get encryption key (from env or generate)
     */
    private function getEncryptionKey(): string
    {
        $keyFile = System::path('storage') . '/.encryption_key';
        
        if (file_exists($keyFile)) {
            return trim(file_get_contents($keyFile));
        }
        
        // Generate new key
        $key = bin2hex(random_bytes(32));
        
        // Ensure storage directory exists
        $dir = dirname($keyFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($keyFile, $key);
        chmod($keyFile, 0600);
        
        return $key;
    }

    /**
     * Load keys from storage
     */
    private function load(): void
    {
        if (!file_exists($this->storagePath)) {
            $this->keys = [];
            return;
        }

        $content = file_get_contents($this->storagePath);
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::warning('KeyCenter: Failed to parse keys.json');
            $this->keys = [];
            return;
        }

        $this->keys = $data ?? [];
    }

    /**
     * Save keys to storage
     */
    private function save(): void
    {
        $dir = dirname($this->storagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Atomic write
        $tmpFile = $this->storagePath . '.tmp.' . uniqid();
        $content = json_encode($this->keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        if (file_put_contents($tmpFile, $content) === false) {
            throw new \RuntimeException('Failed to write keys file');
        }
        
        if (!rename($tmpFile, $this->storagePath)) {
            @unlink($tmpFile);
            throw new \RuntimeException('Failed to save keys file');
        }
        
        chmod($this->storagePath, 0600);
    }

    /**
     * Encrypt a value
     */
    private function encrypt(string $value): string
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a value
     */
    private function decrypt(string $encrypted): string
    {
        $data = base64_decode($encrypted);
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        return openssl_decrypt($ciphertext, 'AES-256-CBC', $this->encryptionKey, OPENSSL_RAW_DATA, $iv) ?: '';
    }

    /**
     * Set credentials for a service
     * 
     * @param string $service Service name (e.g., 'bybit', 'telegram')
     * @param string $account Account name (e.g., 'default', 'main', 'sub1')
     * @param array $credentials Key-value pairs of credentials
     */
    public function setCredentials(string $service, string $account, array $credentials): void
    {
        if (!isset($this->keys[$service])) {
            $this->keys[$service] = [];
        }
        
        // Encrypt sensitive values
        $encrypted = [];
        foreach ($credentials as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $encrypted[$key] = [
                    'encrypted' => true,
                    'value' => $this->encrypt((string)$value),
                ];
            } else {
                $encrypted[$key] = [
                    'encrypted' => false,
                    'value' => $value,
                ];
            }
        }
        
        $this->keys[$service][$account] = [
            'credentials' => $encrypted,
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];
        
        $this->save();
        
        Logger::info('KeyCenter: Credentials set', [
            'service' => $service,
            'account' => $account,
            'keys' => array_keys($credentials),
        ], 'security');
    }

    /**
     * Get credentials for a service
     * 
     * @return array Decrypted credentials
     */
    public function getCredentials(string $service, string $account = 'default'): array
    {
        if (!isset($this->keys[$service][$account])) {
            return [];
        }

        $data = $this->keys[$service][$account]['credentials'] ?? [];
        $decrypted = [];
        
        foreach ($data as $key => $item) {
            if ($item['encrypted'] ?? false) {
                $decrypted[$key] = $this->decrypt($item['value']);
            } else {
                $decrypted[$key] = $item['value'];
            }
        }
        
        return $decrypted;
    }

    /**
     * Check if credentials exist
     */
    public function hasCredentials(string $service, string $account = 'default'): bool
    {
        return isset($this->keys[$service][$account]);
    }

    /**
     * Delete credentials
     */
    public function deleteCredentials(string $service, string $account = 'default'): void
    {
        if (isset($this->keys[$service][$account])) {
            unset($this->keys[$service][$account]);
            $this->save();
            
            Logger::info('KeyCenter: Credentials deleted', [
                'service' => $service,
                'account' => $account,
            ], 'security');
        }
    }

    /**
     * List all accounts for a service
     */
    public function listAccounts(string $service): array
    {
        if (!isset($this->keys[$service])) {
            return [];
        }
        return array_keys($this->keys[$service]);
    }

    /**
     * List all services
     */
    public function listServices(): array
    {
        return array_keys($this->keys);
    }

    /**
     * Get Bybit credentials (shorthand)
     */
    public function getBybitCredentials(string $account = 'default'): array
    {
        return $this->getCredentials('bybit', $account);
    }

    /**
     * Set Bybit credentials (shorthand)
     * Mainnet only - testnet removed for production stability.
     */
    public function setBybitCredentials(string $apiKey, string $apiSecret, string $account = 'default'): void
    {
        $this->setCredentials('bybit', $account, [
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
        ]);
    }

    /**
     * Check if a key name should be encrypted
     */
    private function isSensitiveKey(string $key): bool
    {
        $sensitivePatterns = ['key', 'secret', 'token', 'password', 'credential'];
        $keyLower = strtolower($key);
        
        foreach ($sensitivePatterns as $pattern) {
            if (str_contains($keyLower, $pattern)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Export credentials info (without secrets)
     */
    public function export(): array
    {
        $export = [];
        
        foreach ($this->keys as $service => $accounts) {
            $export[$service] = [];
            foreach ($accounts as $account => $data) {
                $export[$service][$account] = [
                    'keys' => array_keys($data['credentials'] ?? []),
                    'created_at' => $data['created_at'] ?? null,
                    'updated_at' => $data['updated_at'] ?? null,
                ];
            }
        }
        
        return $export;
    }

    /**
     * Get diagnostic info
     */
    public function diagnostic(): array
    {
        return [
            'storage_path' => $this->storagePath,
            'storage_exists' => file_exists($this->storagePath),
            'encryption_key_exists' => file_exists(System::path('storage') . '/.encryption_key'),
            'services' => $this->listServices(),
            'accounts' => $this->export(),
        ];
    }
}

/* RULES
- Purpose: Centralized API key management with AES-256 encryption
- Config sources: None (stores in storage/credentials/)
- Paths: Uses System::path('storage') . '/credentials/'
- Logs: Security events to Logger::CHANNEL_SECURITY
- Prohibitions:
  - NO plain-text key storage
  - NO config fallback for credentials (use KeyCenter only)
  - NO exposing decrypted keys in logs
*/
