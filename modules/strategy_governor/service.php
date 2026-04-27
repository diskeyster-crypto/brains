<?php

declare(strict_types=1);

/**
 * Strategy Governor Module — Service
 *
 * Public entry point / mod_class implementation.
 *
 * V1 is shadow-only.  It must NOT block orders, must NOT change bot or
 * strategy behaviour.  It only observes signals, queues, positions, closed
 * trades, and writes recommended decisions.
 *
 * Architecture:
 *   StrategyGovernorService  — thin shell: config loading, error handling
 *   StrategyGovernor (src/)  — core engine: observations, decisions, stats
 */

namespace Modules\StrategyGovernor;

require_once __DIR__ . '/src/strategy_governor.php';

final class StrategyGovernorService
{
    private static ?self $instance = null;
    private string $moduleDir;

    // ── Constructor ───────────────────────────────────────────────────────────

    public function __construct(?string $moduleDir = null)
    {
        $this->moduleDir = $moduleDir !== null
            ? rtrim($moduleDir, '/')
            : rtrim(__DIR__, '/');
    }

    public static function instance(?string $moduleDir = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($moduleDir);
        }
        return self::$instance;
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Execute one Governor tick.
     *
     * Called by the cron task.  Returns the last_run summary array.
     * Failures are caught and recorded; this method never throws.
     */
    public function tick(): array
    {
        try {
            $config = $this->loadConfig();

            if (!($config['enabled'] ?? true)) {
                $summary = [
                    'started_at'  => date('Y-m-d H:i:s'),
                    'finished_at' => date('Y-m-d H:i:s'),
                    'status'      => 'disabled',
                    'mode'        => $config['mode'] ?? 'shadow',
                    'errors'      => [],
                ];
                $this->writeLastRun($summary);
                return $summary;
            }

            $governor = new StrategyGovernor($this->moduleDir, $config);
            return $governor->run();

        } catch (\Throwable $e) {
            $summary = [
                'started_at'  => date('Y-m-d H:i:s'),
                'finished_at' => date('Y-m-d H:i:s'),
                'status'      => 'error',
                'mode'        => 'shadow',
                'errors'      => [$e->getMessage()],
            ];
            $this->writeLastRun($summary);
            return $summary;
        }
    }

    /**
     * Return the most recent last_run.json summary, or an empty array.
     */
    public function getLastRun(): array
    {
        $path = $this->moduleDir . '/storage/last_run.json';
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        return @json_decode($raw, true) ?? [];
    }

    /**
     * Return the strategy_state.json recommendations, or an empty array.
     */
    public function getStrategyState(): array
    {
        $path = $this->moduleDir . '/storage/strategy_state.json';
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        return @json_decode($raw, true) ?? [];
    }

    /**
     * Return the merged effective config (base + active overrides).
     */
    public function getConfig(): array
    {
        try {
            return $this->loadConfig();
        } catch (\Throwable) {
            return [];
        }
    }

    // =========================================================================
    // Config loader
    // =========================================================================

    private function loadConfig(): array
    {
        $base   = [];
        $active = [];

        $basePath   = $this->moduleDir . '/config/base.php';
        $activePath = $this->moduleDir . '/config/active.php';

        if (is_file($basePath)) {
            $tmp = @include $basePath;
            if (is_array($tmp)) {
                $base = $tmp;
            }
        }
        if (is_file($activePath)) {
            $tmp = @include $activePath;
            if (is_array($tmp)) {
                $active = $tmp;
            }
        }

        return array_merge($base, $active);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function writeLastRun(array $data): void
    {
        $storageDir = $this->moduleDir . '/storage';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        @file_put_contents(
            $storageDir . '/last_run.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );
    }
}
