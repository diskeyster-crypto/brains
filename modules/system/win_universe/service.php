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
 *   - win_universe_stats.json      — per-symbol raw stats
 *   - win_universe.json            — qualified + near-qualified + rejected lists with details
 *   - win_universe_status.json     — last run status / counters
 *   - win_universe_pool.json       — current win pool state (promoted symbols)
 *   - win_universe_promotions.json — history of promotion events
 *   - win_universe_demotions.json  — history of demotion events
 */
final class WinUniverseService
{
    private WinUniverseEngine $engine;

    /** Absolute path to this module's storage/runtime directory */
    private string $runtimeDir;

    /** Merged module config (defaults + user config overlay) */
    private array $config = [];

    /** Max promotion/demotion history entries to keep */
    private const MAX_HISTORY = 200;

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
            // Load previous win pool state for lifecycle continuity
            $prevPoolData = $this->loadJson('win_universe_pool.json');
            $prevPool     = $prevPoolData['win_pool'] ?? [];

            $result = $this->engine->compute($wuConfig, $prevPool);

            $this->ensureRuntimeDir();
            $this->persistOutputs($result, $ts);

            $elapsed = round(microtime(true) - $startTime, 3);

            $status = [
                'ok'                            => true,
                'skipped'                       => false,
                'run_at'                        => $ts,
                'elapsed_sec'                   => $elapsed,
                'symbols_seen'                  => count($result['symbols']),
                'qualified_count'               => count($result['qualified']),
                'near_qualified_count'          => count($result['near_qualified']),
                'rejected_count'                => count($result['rejected']),
                'win_pool_count'                => count($result['win_pool']),
                'promotions_this_run'           => count($result['promotions']),
                'demotions_this_run'            => count($result['demotions']),
                'trade_count_total'             => $result['trade_count_total'],
                'sources_used'                  => $result['sources_used'],
                'config_used'                   => $result['config_used'],
                'threshold_sensitivity'         => $result['threshold_sensitivity'],
                'threshold_diagnostics'         => [
                    'failed_by_min_trades_count'    => $result['threshold_sensitivity']['failed_by_min_trades_count']    ?? 0,
                    'failed_by_roi_threshold_count' => $result['threshold_sensitivity']['failed_by_roi_threshold_count'] ?? 0,
                    'failed_by_avg_roi_count'       => $result['threshold_sensitivity']['failed_by_avg_roi_count']       ?? 0,
                    'failed_by_winrate_count'       => $result['threshold_sensitivity']['failed_by_winrate_count']       ?? 0,
                ],
                'candidate_sensitivity_preview' => $result['candidate_sensitivity_preview'] ?? [],
                'mode'                          => $result['config_used']['win_universe_mode'] ?? 'shadow',
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
     * Return current win pool state (win_universe_pool.json) or null.
     *
     * @return array<string,mixed>|null
     */
    public function getPool(): ?array
    {
        return $this->loadJson('win_universe_pool.json');
    }

    /**
     * Return recent promotion history (win_universe_promotions.json) or null.
     *
     * @return array<string,mixed>|null
     */
    public function getPromotions(): ?array
    {
        return $this->loadJson('win_universe_promotions.json');
    }

    /**
     * Return recent demotion history (win_universe_demotions.json) or null.
     *
     * @return array<string,mixed>|null
     */
    public function getDemotions(): ?array
    {
        return $this->loadJson('win_universe_demotions.json');
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

    /**
     * Save a user config overlay to win_universe_user_config.json.
     * Only whitelisted keys are accepted. Returns success/error.
     *
     * @param array<string,mixed> $values
     * @return array{ok:bool,saved:array<string,mixed>,errors:list<string>}
     */
    public function saveUserConfig(array $values): array
    {
        $allowed = [
            'win_universe_enabled'      => 'bool',
            'win_universe_mode'         => 'string',
            'min_roi_threshold'         => 'float',
            'lookback_days'             => 'int',
            'min_closed_trades'         => 'int',
            'min_winrate'               => 'float',
            'min_avg_roi'               => 'float',
            'qualification_expiry_days' => 'int',
            'demotion_loss_streak'      => 'int',
            'priority_bonus_enabled'    => 'bool',
            'priority_bonus_strength'   => 'float',
        ];

        $modeAllowed = ['shadow', 'priority', 'win_only'];
        $saved  = [];
        $errors = [];

        foreach ($allowed as $key => $type) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $raw = $values[$key];
            switch ($type) {
                case 'bool':
                    $saved[$key] = (bool)$raw;
                    break;
                case 'float':
                    if (!is_numeric($raw)) {
                        $errors[] = "Invalid float value for {$key}";
                        continue 2;
                    }
                    $saved[$key] = (float)$raw;
                    break;
                case 'int':
                    if (!is_numeric($raw)) {
                        $errors[] = "Invalid int value for {$key}";
                        continue 2;
                    }
                    $saved[$key] = (int)$raw;
                    break;
                case 'string':
                    if ($key === 'win_universe_mode' && !in_array((string)$raw, $modeAllowed, true)) {
                        $errors[] = "Invalid mode value: {$raw}";
                        continue 2;
                    }
                    $saved[$key] = (string)$raw;
                    break;
            }
        }

        if (!empty($errors)) {
            return ['ok' => false, 'saved' => [], 'errors' => $errors];
        }

        try {
            $this->ensureRuntimeDir();
            $existing = $this->loadJson('win_universe_user_config.json') ?? ['win_universe' => []];
            $existing['win_universe'] = array_merge($existing['win_universe'] ?? [], $saved);
            $existing['updated_at'] = date('c');
            $this->saveJson('win_universe_user_config.json', $existing);
        } catch (\Throwable $e) {
            return ['ok' => false, 'saved' => [], 'errors' => [$e->getMessage()]];
        }

        return ['ok' => true, 'saved' => $saved, 'errors' => []];
    }

    // =========================================================================
    // Output persistence
    // =========================================================================

    /**
     * Persist all runtime artifacts from a compute result.
     *
     * @param array<string,mixed> $result  Return value from WinUniverseEngine::compute()
     * @param string              $ts      ISO timestamp
     */
    private function persistOutputs(array $result, string $ts): void
    {
        // win_universe.json — qualified/near-qualified/rejected lists + per-symbol details
        $universe = [
            'mode'                          => $result['config_used']['win_universe_mode'] ?? 'shadow',
            'computed_at'                   => $ts,
            'qualified'                     => $result['qualified'],
            'near_qualified'                => $result['near_qualified'],
            'rejected'                      => $result['rejected'],
            'symbols'                       => $result['symbols'],
            'win_pool'                      => $result['win_pool'],
            'config_used'                   => $result['config_used'],
            'sources_used'                  => $result['sources_used'],
            'trade_count_total'             => $result['trade_count_total'],
            'threshold_sensitivity'         => $result['threshold_sensitivity'],
            'candidate_sensitivity_preview' => $result['candidate_sensitivity_preview'] ?? [],
        ];
        $this->saveJson('win_universe.json', $universe);

        // win_universe_pool.json — current win pool state
        $pool = [
            'win_pool'     => $result['win_pool'],
            'last_updated' => $ts,
            'pool_count'   => count($result['win_pool']),
        ];
        $this->saveJson('win_universe_pool.json', $pool);

        // win_universe_promotions.json — bounded promotion history
        $this->appendHistory('win_universe_promotions.json', $result['promotions'], $ts);

        // win_universe_demotions.json — bounded demotion history
        $this->appendHistory('win_universe_demotions.json', $result['demotions'], $ts);

        // win_universe_stats.json — raw per-symbol statistics with full diagnostics
        $stats = [
            'computed_at'                   => $ts,
            'symbols'                       => array_map(static function (array $rec): array {
                return [
                    'symbol'                        => $rec['symbol'],
                    'qualification_status'          => $rec['qualification_status'],
                    'qualified'                     => $rec['qualified'],
                    'near_qualified'                => $rec['near_qualified'],
                    'in_win_pool'                   => $rec['in_win_pool'] ?? false,
                    'promotion_eligible'            => $rec['promotion_eligible'] ?? false,
                    'demotion_eligible'             => $rec['demotion_eligible'] ?? false,
                    'qualification_reason'          => $rec['qualification_reason'],
                    'promotion_reason'              => $rec['promotion_reason'] ?? null,
                    'demotion_reason'               => $rec['demotion_reason'] ?? null,
                    'last_promotion_time'           => $rec['last_promotion_time'] ?? null,
                    'last_demotion_time'            => $rec['last_demotion_time'] ?? null,
                    'missing_requirements'          => $rec['missing_requirements'],
                    'recent_trade_count'            => $rec['recent_trade_count'],
                    'closed_trades_count'           => $rec['closed_trades_count'],
                    'closed_trades_window'          => $rec['closed_trades_window'],
                    'wins_above_threshold'          => $rec['wins_above_threshold'],
                    'recent_avg_roi'                => $rec['recent_avg_roi'],
                    'best_roi'                      => $rec['best_roi'],
                    'recent_winrate'                => $rec['recent_winrate'],
                    'last_trade_time'               => $rec['last_trade_time'],
                    'lookback_window_used'          => $rec['lookback_window_used'],
                    'thresholds_used'               => $rec['thresholds_used'],
                    // Distance-to-qualify metrics
                    'distance_to_min_roi_threshold' => $rec['distance_to_min_roi_threshold'] ?? null,
                    'distance_to_min_avg_roi'       => $rec['distance_to_min_avg_roi'] ?? null,
                    'distance_to_min_winrate'       => $rec['distance_to_min_winrate'] ?? null,
                    'missing_trade_count'           => $rec['missing_trade_count'] ?? 0,
                    'wins_needed_above_threshold'   => $rec['wins_needed_above_threshold'] ?? 0,
                ];
            }, $result['symbols']),
            'sources_used'                  => $result['sources_used'],
            'trade_count_total'             => $result['trade_count_total'],
            'threshold_sensitivity'         => $result['threshold_sensitivity'],
            'candidate_sensitivity_preview' => $result['candidate_sensitivity_preview'] ?? [],
        ];
        $this->saveJson('win_universe_stats.json', $stats);
    }

    /**
     * Append new events to a bounded history file.
     *
     * @param list<array<string,mixed>> $newEvents
     */
    private function appendHistory(string $filename, array $newEvents, string $ts): void
    {
        if (empty($newEvents)) {
            // Still write/update the file to ensure it exists
            $existing = $this->loadJson($filename);
            if ($existing === null) {
                $this->saveJson($filename, ['events' => [], 'last_updated' => $ts]);
            }
            return;
        }

        $existing = $this->loadJson($filename) ?? ['events' => []];
        $events   = $existing['events'] ?? [];

        foreach ($newEvents as $ev) {
            array_unshift($events, $ev);
        }

        // Bound to MAX_HISTORY
        if (count($events) > self::MAX_HISTORY) {
            $events = array_slice($events, 0, self::MAX_HISTORY);
        }

        $this->saveJson($filename, [
            'events'       => $events,
            'total_events' => count($events),
            'last_updated' => $ts,
        ]);
    }

    // =========================================================================
    // Config loading
    // =========================================================================

    /**
     * Load and merge module config.
     * Priority (highest first):
     *   1. win_universe_user_config.json (runtime user overlay)
     *   2. config/config.php (module defaults)
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

        // Load user config overlay
        $userConfigPath = $moduleBase . '/storage/runtime/win_universe_user_config.json';
        if (is_file($userConfigPath)) {
            try {
                $content = @file_get_contents($userConfigPath);
                if ($content !== false && $content !== '') {
                    $userCfg = json_decode($content, true);
                    if (is_array($userCfg) && !empty($userCfg['win_universe'])) {
                        // Merge user overlay into win_universe block
                        $defaults['win_universe'] = array_merge(
                            $defaults['win_universe'] ?? [],
                            $userCfg['win_universe']
                        );
                    }
                }
            } catch (\Throwable $e) {
                // keep defaults
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
