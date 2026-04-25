<?php

declare(strict_types=1);

namespace Modules\ProfManager;

/**
 * ProfManagerService — Router / Orchestrator
 *
 * Routes each active position to the appropriate profile based on side:
 *   long  → LongProfile  (legacy_safe_long baseline logic)
 *   short → ShortProfile (stub — unsupported)
 *
 * This class contains ZERO profit-lock business logic.
 * All logic lives in the respective profile classes.
 *
 * Public API:
 *   tick()            — run one processing cycle
 *   getStatus()       — return current module status
 *   setEnabled(bool)  — toggle enabled flag and persist to active.php
 *   saveConfig(array) — persist arbitrary config keys to active.php
 *
 * No exchange calls are ever made from this module.
 * Mode: demo only (this stage).
 */
final class ProfManagerService
{
    private static ?self $instance = null;

    private string $moduleDir;
    private string $repoRoot;
    private bool   $runtimeEnabled;

    /** @var array<string,mixed> */
    private array $config = [];

    /** @var Lib\Store */
    private Lib\Store $store;

    /** @var Lib\PositionReader */
    private Lib\PositionReader $positionReader;

    /** @var Lib\Validator */
    private Lib\Validator $validator;

    /** @var Profiles\Long\LongProfile */
    private Profiles\Long\LongProfile $longProfile;

    /** @var Profiles\Short\ShortProfile */
    private Profiles\Short\ShortProfile $shortProfile;

    public function __construct(?string $moduleDir = null)
    {
        $this->moduleDir = rtrim(
            $moduleDir ?? __DIR__,
            '/'
        );
        $this->repoRoot = rtrim(dirname($this->moduleDir, 2), '/');

        $this->config         = $this->loadConfig();
        $this->runtimeEnabled = (bool) ($this->config['enabled'] ?? false);

        $storageDir           = $this->moduleDir . '/storage';

        $this->store          = new Lib\Store($storageDir);
        $this->store->ensureStorageInit();

        $ttlHours             = (int) ($this->config['paper_position_ttl_hours'] ?? 6);
        $this->positionReader = new Lib\PositionReader($this->repoRoot, $ttlHours);
        $this->validator      = new Lib\Validator();

        $longConfigOverrides = $this->config['profiles']['long'] ?? [];
        $this->longProfile   = new Profiles\Long\LongProfile(
            $this->moduleDir . '/profiles/long',
            $longConfigOverrides
        );

        $this->shortProfile  = new Profiles\Short\ShortProfile();
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
     * Run one processing tick.
     *
     * Reads positions, routes each to the correct profile, and writes last_run.json.
     *
     * @return array Structured result
     */
    public function tick(): array
    {
        $nowTs = time();
        $ts    = date('c', $nowTs);

        try {
            // ── Module enabled? ───────────────────────────────────────────────
            if (!$this->runtimeEnabled) {
                $result = [
                    'ok'             => true,
                    'ts'             => $ts,
                    'enabled'        => false,
                    'mode'           => 'demo',
                    'active_profile' => 'auto',
                    'long_profile'   => 'legacy_safe_long',
                    'short_profile'  => 'unavailable',
                    'skipped'        => 'module_disabled',
                    'skip_reason'    => 'module_disabled',
                    'positions_total'=> 0,
                    'positions_long' => 0,
                    'positions_short'=> 0,
                    'positions'      => 0,
                ];
                $this->store->writeLastRun($result);
                return $result;
            }

            // ── Read positions ────────────────────────────────────────────────
            $readResult   = $this->positionReader->read();
            $rawPositions = $readResult['positions'];

            if (empty($rawPositions)) {
                $skipReason = ($readResult['source'] === 'none')
                    ? 'no_positions_source_found'
                    : 'no_positions';
                $result = [
                    'ok'             => true,
                    'ts'             => $ts,
                    'enabled'        => true,
                    'mode'           => 'demo',
                    'active_profile' => 'auto',
                    'long_profile'   => 'legacy_safe_long',
                    'short_profile'  => 'unavailable',
                    'positions_total'=> 0,
                    'positions_long' => 0,
                    'positions_short'=> 0,
                    'positions'      => 0,
                    'valid_positions'=> 0,
                    'positions_runtime' => [],
                    'actions_summary'   => [],
                    'skip_summary'      => [],
                    'skip_reasons_summary' => [],
                    'skip_reason'       => $skipReason,
                    'validation_errors' => [],
                    'source'            => 'bybit_demo_positions_cache',
                    'executed_count'    => 0,
                    'skipped_count'     => 0,
                    'locks_active'      => $this->longProfile->getLockCount(),
                ];
                $this->store->writeLastRun($result);
                return $result;
            }

            // ── Route each position to its profile ───────────────────────────
            $positionsRuntime = [];
            $validationErrors = [];
            $positionsLong    = 0;
            $positionsShort   = 0;
            $actionsSummary   = [];
            $skipSummary      = [];

            foreach ($rawPositions as $pos) {
                if (!is_array($pos)) {
                    continue;
                }

                // Normalize side
                $pos['side'] = $this->validator->normalizeSide($pos['side'] ?? '');

                // Validate
                $validation = $this->validator->validatePosition($pos);
                if (!$validation['ok']) {
                    $validationErrors[] = [
                        'symbol' => $pos['symbol'] ?? '?',
                        'errors' => $validation['errors'],
                    ];
                    continue;
                }

                // ── Detect side and route ─────────────────────────────────────
                $side = $pos['side'];

                if ($side === 'long') {
                    $positionsLong++;
                    $profileResult = $this->longProfile->process($pos, $nowTs);
                } elseif ($side === 'short') {
                    $positionsShort++;
                    $profileResult = $this->shortProfile->process($pos, $nowTs);
                } else {
                    $profileResult = [
                        'action'       => 'skip',
                        'skip_reason'  => 'unsupported_side',
                        'roi'          => null,
                        'peak_roi'     => null,
                        'lock_price'   => null,
                        'lock_active'  => false,
                        'profile_used' => 'none',
                        'notes'        => [],
                    ];
                }

                // Track actions summary
                $action = $profileResult['action'] ?? 'skip';
                $actionsSummary[$action] = ($actionsSummary[$action] ?? 0) + 1;

                // Track skip summary
                if ($action === 'skip' && !empty($profileResult['skip_reason'])) {
                    $r = (string) $profileResult['skip_reason'];
                    $skipSummary[$r] = ($skipSummary[$r] ?? 0) + 1;
                }

                // ── Build per-position runtime record ─────────────────────────
                $currentRoi       = $profileResult['roi'] ?? null;
                $activationRoi    = $profileResult['activation_roi'] ?? null;
                $positionsRuntime[] = [
                    'symbol'                    => $pos['symbol']     ?? '',
                    'side'                      => $pos['side']       ?? '',
                    'entry_price'               => (float) ($pos['entry_price']   ?? $pos['avg_price'] ?? 0.0),
                    'current_price'             => (float) ($pos['current_price'] ?? 0.0),
                    'profile_used'              => $profileResult['profile_used']  ?? 'none',
                    'action'                    => $profileResult['action']        ?? 'skip',
                    'skip_reason'               => $profileResult['skip_reason']   ?? null,
                    'roi'                       => $currentRoi,
                    'peak_roi'                  => $profileResult['peak_roi']      ?? null,
                    'lock_price'                => $profileResult['lock_price']    ?? null,
                    'lock_active'               => $profileResult['lock_active']   ?? false,
                    'init_roi'                  => $profileResult['init_roi']      ?? null,
                    'activation_roi'            => $activationRoi,
                    'roi_gap_to_activation'     => ($currentRoi !== null && $activationRoi !== null)
                        ? round((float) $activationRoi - (float) $currentRoi, 4)
                        : null,
                    'distance_pct'              => $profileResult['distance_pct']              ?? null,
                    'min_required_distance_pct' => $profileResult['min_required_distance_pct'] ?? null,
                    'price_source'              => $pos['_price_source'] ?? 'unknown',
                    'hybrid_state'              => $profileResult['hybrid_state']              ?? 'idle',
                    'hybrid_pattern_detected'   => $profileResult['hybrid_pattern_detected']   ?? false,
                    'hybrid_confirmation_ticks' => $profileResult['hybrid_confirmation_ticks'] ?? 0,
                    'hybrid_guard_stop'         => $profileResult['hybrid_guard_stop']         ?? null,
                    'hybrid_guard_active'       => $profileResult['hybrid_guard_active']       ?? false,
                    'hybrid_breathing_stop'     => $profileResult['hybrid_breathing_stop']     ?? null,
                    'hybrid_breathing_active'   => $profileResult['hybrid_breathing_active']   ?? false,
                ];
            }

            // ── Computed summary counts ───────────────────────────────────────
            $positionsTotal = count($rawPositions);
            $invalidCount   = count($validationErrors);
            $validCount     = $positionsTotal - $invalidCount;

            $executedCount = ($actionsSummary['would_set_profit_lock']  ?? 0)
                           + ($actionsSummary['would_move_profit_lock'] ?? 0);
            $skippedCount  = array_sum($skipSummary);

            // ── Clean stale long profile state/locks ──────────────────────────
            // Build the set of active long position keys seen this tick.
            $activeLongKeys = [];
            foreach ($positionsRuntime as $pr) {
                if (($pr['side'] ?? '') === 'long') {
                    $activeLongKeys[] = strtolower($pr['symbol']) . '_long';
                }
            }
            $cleanResult = $this->longProfile->cleanStale($activeLongKeys);

            $result = [
                'ok'                   => true,
                'ts'                   => $ts,
                'enabled'              => true,
                'mode'                 => 'demo',
                'active_profile'       => 'auto',
                'long_profile'         => 'legacy_safe_long',
                'short_profile'        => 'unavailable',
                'positions_total'      => $positionsTotal,
                'positions_long'       => $positionsLong,
                'positions_short'      => $positionsShort,
                'positions'            => $positionsTotal,
                'valid_positions'      => $validCount,
                'invalid_positions'    => $invalidCount,
                'actions_summary'      => $actionsSummary,
                'skip_summary'         => $skipSummary,
                'skip_reasons_summary' => $skipSummary,
                'positions_runtime'    => $positionsRuntime,
                'validation_errors'    => $validationErrors,
                'source'               => 'bybit_demo_positions_cache',
                'executed_count'       => $executedCount,
                'skipped_count'        => $skippedCount,
                'locks_active'         => $this->longProfile->getLockCount(),
                'long_state_cleaned'   => $cleanResult['long_state_cleaned'],
                'long_locks_cleaned'   => $cleanResult['long_locks_cleaned'],
            ];

            $this->store->writeLastRun($result);
            return $result;

        } catch (\Throwable $e) {
            $this->store->appendError('tick', $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $errorResult = [
                'ok'    => false,
                'ts'    => $ts,
                'error' => $e->getMessage(),
            ];
            try {
                $this->store->writeLastRun($errorResult);
            } catch (\Throwable) {
                // swallow secondary write failure
            }
            return $errorResult;
        }
    }

    /**
     * Return current module status (without running a tick).
     *
     * @return array
     */
    public function getStatus(): array
    {
        $lastRun = $this->store->readLastRun();

        $lastError = null;
        if (($lastRun['ok'] ?? null) !== true) {
            if (!empty($lastRun['error'])) {
                $lastError = (string) $lastRun['error'];
            } else {
                $lastErrRaw = $this->store->readLastError();
                if ($lastErrRaw !== '') {
                    $decoded = json_decode($lastErrRaw, true);
                    if (is_array($decoded) && isset($decoded['message'])) {
                        $lastError = $decoded['message'];
                    } else {
                        $lastError = $lastErrRaw;
                    }
                }
            }
        }

        $cronToken = (string) ($this->config['cron_token'] ?? '');

        return [
            'enabled'           => $this->runtimeEnabled,
            'mode'              => 'demo',
            'account'           => 'bybit_demo',
            'active_profile'    => 'auto',
            'long_profile'      => 'legacy_safe_long',
            'short_profile'     => 'unavailable',
            'last_tick'         => $lastRun['ts'] ?? null,
            'positions_tracked' => (int) ($lastRun['valid_positions'] ?? $lastRun['positions'] ?? 0),
            'locks_active'      => (int) ($lastRun['locks_active'] ?? $this->longProfile->getLockCount()),
            'planned_updates'   => (int) ($lastRun['executed_count'] ?? 0),
            'skipped'           => (int) ($lastRun['skipped_count']  ?? 0),
            'last_error'        => $lastError,
            'cron_interval_sec' => 60,
            'cron_configured'   => ($cronToken !== ''),
        ];
    }

    /**
     * Enable or disable the module and persist the flag to active.php.
     *
     * @param bool $enabled
     * @return array{ok: bool, enabled: bool}
     */
    public function setEnabled(bool $enabled): array
    {
        $this->runtimeEnabled    = $enabled;
        $this->config['enabled'] = $enabled;
        $this->saveConfig($this->config);
        return ['ok' => true, 'enabled' => $enabled];
    }

    /**
     * Save an arbitrary config array to active.php (merges on top of base config).
     *
     * @param array<string,mixed> $data
     */
    public function saveConfig(array $data): void
    {
        $activeFile = $this->moduleDir . '/config/active.php';

        $existing = [];
        if (is_file($activeFile)) {
            try {
                $loaded = @include $activeFile;
                if (is_array($loaded)) {
                    $existing = $loaded;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        $merged = array_merge($existing, $data);

        $php  = "<?php\n\ndeclare(strict_types=1);\n\n";
        $php .= "/**\n * Profit Manager Module — Active Config Overrides\n";
        $php .= " * Written by the admin UI. Do not edit manually.\n */\n\n";
        $php .= "return ";
        $php .= var_export($merged, true);
        $php .= ";\n";

        $dir = dirname($activeFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($activeFile, $php);

        // Reload config to keep instance in sync
        $this->config = $this->loadConfig();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function loadConfig(): array
    {
        $configPath = $this->moduleDir . '/config/config.php';
        $activePath = $this->moduleDir . '/config/active.php';
        $cfg = [];
        try {
            if (is_file($configPath)) {
                $base = require $configPath;
                if (is_array($base)) {
                    $cfg = $base;
                }
            }
            if (is_file($activePath)) {
                $active = require $activePath;
                if (is_array($active)) {
                    $cfg = array_merge($cfg, $active);
                }
            }
        } catch (\Throwable $e) {
            // return whatever we have
        }
        return $cfg;
    }
}
