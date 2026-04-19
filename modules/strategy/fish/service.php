<?php

declare(strict_types=1);

/**
 * Fish Strategy — Service
 *
 * Entry point for a single strategy run cycle.
 * Responsibilities:
 *   1. Load and validate config via FishBootstrap
 *   2. Write/update runtime_snapshot.php
 *   3. Write/update storage/last_run.json
 *   4. Report clean module status
 *
 * Market scanning and execution are NOT implemented yet.
 * This is a safe placeholder run that validates the module is wired correctly.
 */

namespace Modules\Strategy\Fish;

final class FishService
{
    private static ?self $instance = null;

    private string $moduleDir;

    private function __construct(string $moduleDir)
    {
        $this->moduleDir = rtrim($moduleDir, '/');
    }

    public static function instance(string $moduleDir): self
    {
        if (self::$instance === null) {
            self::$instance = new self($moduleDir);
        }
        return self::$instance;
    }

    /**
     * Run one service cycle.
     *
     * @return array Run result (status, config_valid, messages, …)
     */
    public function run(): array
    {
        $startMs = (int)round(microtime(true) * 1000);
        $runAt   = date('c');

        // Bootstrap: load + validate config
        require_once $this->moduleDir . '/bootstrap.php';
        $bootstrap = FishBootstrap::instance($this->moduleDir);

        try {
            $boot = $bootstrap->load();
        } catch (\Throwable $e) {
            $result = $this->buildResult(
                status: 'error',
                configValid: false,
                configErrors: [$e->getMessage()],
                message: 'Bootstrap failed: ' . $e->getMessage(),
                startMs: $startMs,
                runAt: $runAt,
            );
            $this->persist($result, null);
            return $result;
        }

        if (!$boot['valid']) {
            $result = $this->buildResult(
                status: 'error',
                configValid: false,
                configErrors: $boot['errors'],
                message: 'Config validation failed: ' . implode('; ', $boot['errors']),
                startMs: $startMs,
                runAt: $runAt,
            );
            $this->persist($result, $boot['config']);
            return $result;
        }

        $config = $boot['config'];

        // Module disabled or in passive/disabled mode — skip run, report clean status
        if (!($config['enabled'] ?? false) || ($config['mode'] ?? 'disabled') === 'disabled') {
            $result = $this->buildResult(
                status: 'ok',
                configValid: true,
                configErrors: [],
                message: 'Strategy loaded. Config valid. Module is disabled — no run performed.',
                startMs: $startMs,
                runAt: $runAt,
                extras: ['mode' => $config['mode'] ?? 'disabled'],
            );
            $this->persist($result, $config);
            return $result;
        }

        // --- Placeholder: market scanning not implemented yet ---
        // When scan_enabled becomes true and execution_enabled is wired,
        // insert the scanning / signal-generation loop here.
        $result = $this->buildResult(
            status: 'ok',
            configValid: true,
            configErrors: [],
            message: 'Strategy loaded. Config valid. Runtime updated. (Execution not wired yet.)',
            startMs: $startMs,
            runAt: $runAt,
            extras: ['mode' => $config['mode'] ?? 'passive'],
        );
        $this->persist($result, $config);

        return $result;
    }

    /**
     * Build a standard run result array.
     */
    private function buildResult(
        string $status,
        bool   $configValid,
        array  $configErrors,
        string $message,
        int    $startMs,
        string $runAt,
        array  $extras = []
    ): array {
        $durationMs = (int)round(microtime(true) * 1000) - $startMs;

        return array_merge([
            'strategy_id'   => 'fish',
            'status'        => $status,
            'config_valid'  => $configValid,
            'config_errors' => $configErrors,
            'message'       => $message,
            'run_at'        => $runAt,
            'duration_ms'   => $durationMs,
            'signals_found' => 0,
            'orders_placed' => 0,
            'errors_count'  => $configValid ? 0 : count($configErrors),
        ], $extras);
    }

    /**
     * Persist runtime snapshot and last_run.json.
     *
     * @param array      $result   Run result
     * @param array|null $config   Effective config (null on bootstrap failure)
     */
    private function persist(array $result, ?array $config): void
    {
        $this->writeRuntimeSnapshot($result, $config);
        $this->writeLastRun($result);
    }

    /**
     * Overwrite config/runtime_snapshot.php with current state.
     */
    private function writeRuntimeSnapshot(array $result, ?array $config): void
    {
        $snapshot = [
            'strategy_id'      => 'fish',
            'snapshot_version' => '0.1.0',
            'generated_at'     => $result['run_at'],
            'effective_config' => $config ?? [],
            'config_valid'     => $result['config_valid'],
            'config_errors'    => $result['config_errors'],
            'status'           => $result['status'],
        ];

        $export = '<?php' . "\n\n"
            . "declare(strict_types=1);\n\n"
            . "/**\n"
            . " * Fish Strategy — Runtime Snapshot\n"
            . " *\n"
            . " * Auto-generated by service.php. Do NOT edit manually.\n"
            . " * Generated: " . $result['run_at'] . "\n"
            . " */\n\n"
            . 'return ' . var_export($snapshot, true) . ";\n";

        $path = $this->moduleDir . '/config/runtime_snapshot.php';
        @file_put_contents($path, $export);
    }

    /**
     * Write storage/last_run.json with the run result.
     */
    private function writeLastRun(array $result): void
    {
        $path = $this->moduleDir . '/storage/last_run.json';
        @file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    }

    // ----------------------------------------------------------------
    // Read-only accessors for admin pages
    // ----------------------------------------------------------------

    /**
     * Load effective config (base + active merged).
     * Returns empty array on failure.
     */
    public function getConfig(): array
    {
        try {
            require_once $this->moduleDir . '/bootstrap.php';
            $boot = FishBootstrap::instance($this->moduleDir)->load();
            return $boot['config'];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Load runtime snapshot.
     */
    public function getRuntimeSnapshot(): array
    {
        $path = $this->moduleDir . '/config/runtime_snapshot.php';
        if (!file_exists($path)) {
            return [];
        }
        try {
            $data = require $path;
            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Load last_run.json.
     */
    public function getLastRun(): array
    {
        $path = $this->moduleDir . '/storage/last_run.json';
        if (!file_exists($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Load a storage JSON file. Returns [] on missing/invalid.
     */
    public function loadStorage(string $filename): array
    {
        $path = $this->moduleDir . '/storage/' . $filename;
        if (!file_exists($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Load stats.json.
     */
    public function getStats(): array
    {
        return $this->loadStorage('stats.json');
    }
}
