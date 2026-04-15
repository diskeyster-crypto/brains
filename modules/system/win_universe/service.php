<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/win_universe_engine.php';

/**
 * WinUniverseService
 *
 * Service layer for the Win Universe module.
 * Delegates computation to WinUniverseEngine and persists runtime outputs.
 *
 * SHADOW MODE ONLY — does not affect Smart Brain, Trading Bot, or Profit Manager.
 *
 * Runtime outputs (under storage/runtime/):
 *   - win_universe_stats.json   — per-symbol raw stats
 *   - win_universe.json         — qualified + near-qualified + rejected lists with details
 *   - win_universe_status.json  — last run status / counters
 */
final class WinUniverseService
{
    private WinUniverseEngine $engine;

    /** Absolute path to this module's storage/runtime directory */
    private string $runtimeDir;

    /** Merged module config */
    private array $config = [];

    public function __construct()
    {
        $moduleBase = __DIR__;

        $this->runtimeDir = $moduleBase . '/storage/runtime';
        $this->config     = $this->loadConfig($moduleBase);
        $this->engine     = new WinUniverseEngine($moduleBase);
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Run the win universe computation and persist outputs.
     *
     * @return array<string,mixed> Result summary
     */
    public function run(): array
    {
        $startTime = microtime(true);
        $ts        = date('c');

        if (!($this->config['module']['enabled'] ?? true)) {
            return [
                'ok'       => true,
                'skipped'  => true,
                'reason'   => 'module_disabled',
                'run_at'   => $ts,
            ];
        }

        $wuConfig = $this->config['win_universe'] ?? [];

        if (!($wuConfig['win_universe_enabled'] ?? true)) {
            return [
                'ok'       => true,
                'skipped'  => true,
                'reason'   => 'win_universe_disabled',
                'run_at'   => $ts,
            ];
        }

        try {
            $result = $this->engine->compute($wuConfig);

            $this->ensureRuntimeDir();
            $this->persistOutputs($result, $ts);

            $elapsed = round(microtime(true) - $startTime, 3);

            $status = [
                'ok'                  => true,
                'skipped'             => false,
                'run_at'              => $ts,
                'elapsed_sec'         => $elapsed,
                'symbols_seen'        => count($result['symbols']),
                'qualified_count'     => count($result['qualified']),
                'near_qualified_count'=> count($result['near_qualified']),
                'rejected_count'      => count($result['rejected']),
                'trade_count_total'   => $result['trade_count_total'],
                'sources_used'        => $result['sources_used'],
                'config_used'         => $result['config_used'],
                'mode'                => 'shadow',
            ];

            $this->saveJson('win_universe_status.json', $status);

            return $status;

        } catch (\Throwable $e) {
            $errorStatus = [
                'ok'       => false,
                'skipped'  => false,
                'run_at'   => $ts,
                'error'    => $e->getMessage(),
                'mode'     => 'shadow',
            ];
            try {
                $this->ensureRuntimeDir();
                $this->saveJson('win_universe_status.json', $errorStatus);
            } catch (\Throwable $ignored) {
                // non-fatal
            }
            return $errorStatus;
        }
    }

    /**
     * Return the last saved win universe result (win_universe.json) or null.
     *
     * @return array<string,mixed>|null
     */
    public function getUniverse(): ?array
    {
        return $this->loadJson('win_universe.json');
    }

    /**
     * Return the last saved status (win_universe_status.json) or null.
     *
     * @return array<string,mixed>|null
     */
    public function getStatus(): ?array
    {
        return $this->loadJson('win_universe_status.json');
    }

    /**
     * Return per-symbol stats (win_universe_stats.json) or null.
     *
     * @return array<string,mixed>|null
     */
    public function getStats(): ?array
    {
        return $this->loadJson('win_universe_stats.json');
    }

    /**
     * Return the current merged config (for display/API).
     *
     * @return array<string,mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    // =========================================================================
    // Output persistence
    // =========================================================================

    /**
     * Persist win_universe.json, win_universe_stats.json.
     *
     * @param array<string,mixed> $result  Return value from WinUniverseEngine::compute()
     * @param string              $ts      ISO timestamp
     */
    private function persistOutputs(array $result, string $ts): void
    {
        // win_universe.json — qualified/near-qualified/rejected lists + per-symbol details
        $universe = [
            'mode'              => 'shadow',
            'computed_at'       => $ts,
            'qualified'         => $result['qualified'],
            'near_qualified'    => $result['near_qualified'],
            'rejected'          => $result['rejected'],
            'symbols'           => $result['symbols'],
            'config_used'       => $result['config_used'],
            'sources_used'      => $result['sources_used'],
            'trade_count_total' => $result['trade_count_total'],
        ];
        $this->saveJson('win_universe.json', $universe);

        // win_universe_stats.json — raw per-symbol statistics (flat map)
        $stats = [
            'computed_at'       => $ts,
            'symbols'           => array_map(static function (array $rec): array {
                return [
                    'symbol'                     => $rec['symbol'],
                    'closed_trades_count'        => $rec['closed_trades_count'],
                    'closed_trades_window'       => $rec['closed_trades_window'],
                    'wins_count'                 => $rec['wins_count'] ?? 0,
                    'losses_count'               => $rec['losses_count'] ?? 0,
                    'wins_above_threshold'       => $rec['wins_above_threshold'],
                    'recent_avg_roi'             => $rec['recent_avg_roi'],
                    'best_roi'                   => $rec['best_roi'],
                    'recent_winrate'             => $rec['recent_winrate'],
                    'last_trade_time'            => $rec['last_trade_time'],
                    'qualified'                  => $rec['qualified'],
                    'near_qualified'             => $rec['near_qualified'],
                    'qualification_reason'       => $rec['qualification_reason'],
                ];
            }, $result['symbols']),
            'sources_used'      => $result['sources_used'],
            'trade_count_total' => $result['trade_count_total'],
        ];
        $this->saveJson('win_universe_stats.json', $stats);
    }

    // =========================================================================
    // Config loading
    // =========================================================================

    /**
     * Load and merge module config.
     * Local config/config.php provides defaults.
     *
     * @return array<string,mixed>
     */
    private function loadConfig(string $moduleBase): array
    {
        $defaults = [];
        $configPath = $moduleBase . '/config/config.php';
        if (is_file($configPath)) {
            try {
                $loaded = require $configPath;
                if (is_array($loaded)) {
                    $defaults = $loaded;
                }
            } catch (\Throwable $e) {
                // keep empty defaults
            }
        }
        return $defaults;
    }

    // =========================================================================
    // Storage helpers
    // =========================================================================

    private function ensureRuntimeDir(): void
    {
        if (!is_dir($this->runtimeDir)) {
            @mkdir($this->runtimeDir, 0755, true);
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function saveJson(string $filename, array $data): void
    {
        $path    = $this->runtimeDir . '/' . $filename;
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($content === false) {
            return;
        }
        @file_put_contents($path, $content, LOCK_EX);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadJson(string $filename): ?array
    {
        $path = $this->runtimeDir . '/' . $filename;
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return null;
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }
}
