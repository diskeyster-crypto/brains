<?php
declare(strict_types=1);

namespace Modules\System\TradingBot\Lib;

use Core\System\SystemPaths;

/**
 * Bot Core Trait
 * 
 * Core functionality for Trading Bot.
 * Module resolution, path handling, initialization.
 */
trait BotCoreTrait
{
    /**
     * Resolve module base path via SystemPaths
     * NO HARDCODE - uses PackMap keys only
     */
    private function resolveModuleBase(): ?string
    {
        $paths = SystemPaths::instance();
        
        // Try system.trading_bot key first
        $candidates = [
            'system.trading_bot',
            'trading.trading_bot',
        ];
        
        foreach ($candidates as $key) {
            try {
                if ($paths->has($key)) {
                    $path = $paths->get($key);
                    if (is_string($path) && $path !== '' && is_dir($path)) {
                        return rtrim($path, '/');
                    }
                }
            } catch (\Throwable $e) {
                // Continue to next candidate
            }
        }
        
        // NO FALLBACK - return null if not found via SystemPaths
        return null;
    }
    
    /**
     * Get module base directory
     */
    protected function getModuleBase(): ?string
    {
        return $this->moduleBase;
    }
    
    /**
     * Get storage directory
     */
    protected function getStorageDir(): ?string
    {
        return $this->storageDir;
    }
    
    /**
     * Get logs directory
     */
    protected function getLogsDir(): ?string
    {
        return $this->logsDir;
    }
    
    /**
     * Check if bot is in live mode
     */
    protected function isLiveMode(): bool
    {
        return ($this->config['module']['mode'] ?? 'paper') === 'live';
    }

    /**
     * Check if bot is in demo mode (Bybit Demo account, real API calls)
     */
    protected function isDemoMode(): bool
    {
        return ($this->config['module']['mode'] ?? 'paper') === 'demo';
    }

    /**
     * Check if bot is in dry/paper mode (no real exchange calls)
     */
    protected function isDryMode(): bool
    {
        $mode = $this->config['module']['mode'] ?? 'paper';
        return $mode === 'dry' || $mode === 'paper';
    }

    /**
     * Check if bot is in test mode
     */
    protected function isTestMode(): bool
    {
        return ($this->config['module']['mode'] ?? 'paper') === 'test';
    }

    /**
     * Check if bot is in paper mode (legacy alias for dry)
     */
    protected function isPaperMode(): bool
    {
        $mode = $this->config['module']['mode'] ?? 'paper';
        return $mode === 'paper' || $mode === 'dry';
    }

    /**
     * Check if bot uses a real exchange (live or demo — actual API calls are made).
     * Returns false when $demoExecutionContext is true, which allows per-intent demo
     * execution (no real orders) even when the bot mode is 'live'.
     */
    protected function isRealExchangeMode(): bool
    {
        // Per-intent demo context: no real exchange calls regardless of bot mode
        if (!empty($this->demoExecutionContext)) {
            return false;
        }
        $mode = $this->config['module']['mode'] ?? 'paper';
        return $mode === 'live' || $mode === 'demo';
    }

    /**
     * Get bot mode
     */
    protected function getMode(): string
    {
        return $this->config['module']['mode'] ?? 'paper';
    }
    
    /**
     * Ensure directory exists
     */
    protected function ensureDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return @mkdir($dir, 0755, true);
        }
        return true;
    }
}

/* RULES
- Core trait provides shared utility methods
- NO business logic - only infrastructure
- Respects LIVE/dry/test modes
- Uses BotStore for all file I/O
*/
