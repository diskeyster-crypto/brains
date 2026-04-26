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

                // ── PM Close Execution ────────────────────────────────────────
                $pmCloseActions = ['hybrid_close_confirmed', 'would_close_on_lock_touch'];
                $pmAction       = $profileResult['action'] ?? 'skip';
                $closeAttemptResult = null;

                if (in_array($pmAction, $pmCloseActions, true)) {
                    $posMode   = (string)($pos['mode'] ?? $pos['execution_mode'] ?? 'demo');
                    $posSymbol = (string)($pos['symbol'] ?? '');
                    $posSide   = (string)($pos['side']   ?? '');
                    $posSize   = (float)($pos['size']    ?? 0.0);
                    $closeReasonValue = ($pmAction === 'hybrid_close_confirmed')
                        ? 'hybrid_confirmed'
                        : 'lock_touch';

                    if ($posMode === 'demo') {
                        $closeAttemptResult = $this->closeDemoPosition($posSymbol, $posSide, $posSize);
                        $closeAttemptResult['close_reason'] = $closeReasonValue;
                        $profileResult['action'] = $closeAttemptResult['close_ok']
                            ? 'demo_close_submitted'
                            : 'demo_close_failed';

                        // Write PM close registry so bot journal can attribute the close
                        if ($closeAttemptResult['close_ok']) {
                            $this->writePmCloseRegistry(
                                $posSymbol,
                                $posSide,
                                $closeReasonValue,
                                $closeAttemptResult['close_order_id'] ?? null
                            );
                        }
                    } else {
                        // live mode — do NOT close; safety guard
                        $closeAttemptResult = [
                            'close_attempted'    => false,
                            'close_ok'           => false,
                            'close_ret_code'     => null,
                            'close_ret_msg'      => null,
                            'close_reason'       => $closeReasonValue,
                            'close_source'       => 'profit_manager',
                            'close_order_id'     => null,
                            'close_error_reason' => 'live_close_disabled_for_safety',
                        ];
                        $profileResult['action'] = 'live_close_pending';
                    }
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
                    'hybrid_state'               => $profileResult['hybrid_state']               ?? 'idle',
                    'hybrid_pattern_detected'    => $profileResult['hybrid_pattern_detected']    ?? false,
                    'hybrid_pattern_type'        => $profileResult['hybrid_pattern_type']        ?? null,
                    'hybrid_confirmation_ticks'  => $profileResult['hybrid_confirmation_ticks']  ?? 0,
                    'hybrid_confirmation_result' => $profileResult['hybrid_confirmation_result'] ?? null,
                    'hybrid_guard_stop'          => $profileResult['hybrid_guard_stop']          ?? null,
                    'hybrid_guard_active'        => $profileResult['hybrid_guard_active']        ?? false,
                    'hybrid_breathing_stop'      => $profileResult['hybrid_breathing_stop']      ?? null,
                    'hybrid_breathing_active'    => $profileResult['hybrid_breathing_active']    ?? false,
                    'hybrid_simulation_enabled'  => $profileResult['hybrid_simulation_enabled']  ?? false,
                    'hybrid_detection_score'     => $profileResult['hybrid_detection_score']     ?? null,
                    'hybrid_support_level'       => $profileResult['hybrid_support_level']       ?? null,
                    'hybrid_detection_evidence'  => $profileResult['hybrid_detection_evidence']  ?? null,
                    'hybrid_detection_reason'    => $profileResult['hybrid_detection_reason']    ?? null,
                    'hybrid_price_source'        => $profileResult['hybrid_price_source']        ?? 'none',
                    'hybrid_price_points'        => $profileResult['hybrid_price_points']        ?? 0,
                    'hybrid_min_close_roi'       => $profileResult['hybrid_min_close_roi']       ?? null,
                    // Close execution output (null when no close was attempted this tick)
                    'close_attempted'            => $closeAttemptResult['close_attempted']    ?? null,
                    'close_ok'                   => $closeAttemptResult['close_ok']           ?? null,
                    'close_ret_code'             => $closeAttemptResult['close_ret_code']     ?? null,
                    'close_ret_msg'              => $closeAttemptResult['close_ret_msg']      ?? null,
                    'close_reason'               => $closeAttemptResult['close_reason']       ?? null,
                    'close_source'               => $closeAttemptResult['close_source']       ?? null,
                    'close_order_id'             => $closeAttemptResult['close_order_id']     ?? null,
                    'close_error_reason'         => $closeAttemptResult['close_error_reason'] ?? null,
                ];
            }

            // ── Computed summary counts ───────────────────────────────────────
            $positionsTotal = count($rawPositions);
            $invalidCount   = count($validationErrors);
            $validCount     = $positionsTotal - $invalidCount;

            $executedCount = ($actionsSummary['would_set_profit_lock']  ?? 0)
                           + ($actionsSummary['would_move_profit_lock'] ?? 0)
                           + ($actionsSummary['demo_close_submitted']   ?? 0);
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

    // =========================================================================
    // PM Close Execution Helpers
    // =========================================================================

    /**
     * Load bot module config (base + active overrides).
     * Used to obtain Bybit Demo credentials for PM-initiated close orders.
     */
    private function loadBotConfig(): array
    {
        $botBase   = $this->repoRoot . '/modules/bot/config/base.php';
        $botActive = $this->repoRoot . '/modules/bot/config/active.php';
        $cfg = [];
        try {
            if (is_file($botBase)) {
                $base = @require $botBase;
                if (is_array($base)) {
                    $cfg = $base;
                }
            }
            if (is_file($botActive)) {
                $active = @require $botActive;
                if (is_array($active)) {
                    $cfg = array_merge($cfg, $active);
                }
            }
        } catch (\Throwable) {
            // return whatever we have
        }
        return $cfg;
    }

    /**
     * Create a Bybit Demo gateway client using credentials from the bot config.
     * Returns null when credentials are absent or client creation fails.
     */
    private function getDemoGateway(array $botConfig): ?\Core\Gateway\Bybit
    {
        $apiKey    = (string)($botConfig['demo_api_key']      ?? '');
        $apiSecret = (string)($botConfig['demo_api_secret']   ?? '');
        $baseUrl   = (string)($botConfig['demo_api_base_url'] ?? 'https://api-demo.bybit.com');

        if ($apiKey === '' || $apiSecret === '') {
            return null;
        }

        try {
            $gw = \Core\Gateway\Bybit::client('bybit_demo_pm');
            $gw->setCredentials($apiKey, $apiSecret);
            $gw->setBaseUrl($baseUrl);
            return $gw;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Check whether a specific symbol+side position still has non-zero size on Bybit Demo.
     * Returns false on API error (fail-safe: prevents spurious closes).
     */
    private function fetchDemoPositionExists(\Core\Gateway\Bybit $gw, string $symbol, string $side): bool
    {
        try {
            $resp = $gw->request('/v5/position/list', [
                'category' => 'linear',
                'symbol'   => $symbol,
            ], true);

            if (!($resp['success'] ?? false) || ($resp['ret_code'] ?? -1) !== 0) {
                return false;
            }

            $bybitSideCheck = ($side === 'long') ? 'Buy' : 'Sell';
            foreach ((array)($resp['result']['list'] ?? []) as $pos) {
                if (
                    (string)($pos['symbol'] ?? '') === $symbol &&
                    (string)($pos['side']   ?? '') === $bybitSideCheck &&
                    (float)($pos['size']    ?? 0)  > 0
                ) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Submit a demo-only reduce-only Market close order on Bybit Demo.
     *
     * Safety checks (performed before order submission):
     *   - symbol must be non-empty
     *   - side must be 'long' or 'short'
     *   - size must be > 0
     *   - position must still exist on Bybit Demo (fetched live)
     *
     * For long positions: Bybit side = Sell
     * For short positions: Bybit side = Buy
     *
     * @param string $symbol Position symbol (e.g. 'BTCUSDT')
     * @param string $side   Canonical side: 'long' or 'short'
     * @param float  $size   Position size in base currency (contracts)
     * @return array{
     *   close_attempted: bool,
     *   close_ok: bool,
     *   close_ret_code: int|null,
     *   close_ret_msg: string|null,
     *   close_source: string,
     *   close_order_id: string|null,
     *   close_error_reason: string|null
     * }
     */
    private function closeDemoPosition(string $symbol, string $side, float $size): array
    {
        $result = [
            'close_attempted'    => false,
            'close_ok'           => false,
            'close_ret_code'     => null,
            'close_ret_msg'      => null,
            'close_source'       => 'profit_manager',
            'close_order_id'     => null,
            'close_error_reason' => null,
        ];

        // Safety: symbol
        if ($symbol === '') {
            $result['close_error_reason'] = 'symbol_missing';
            return $result;
        }

        // Safety: side
        if (!in_array($side, ['long', 'short'], true)) {
            $result['close_error_reason'] = 'side_invalid';
            return $result;
        }

        // Safety: size
        if ($size <= 0.0) {
            $result['close_error_reason'] = 'size_zero_or_negative';
            return $result;
        }

        // Obtain gateway
        $botConfig = $this->loadBotConfig();
        $gw        = $this->getDemoGateway($botConfig);
        if ($gw === null) {
            $result['close_error_reason'] = 'demo_credentials_missing';
            return $result;
        }

        // Safety: verify position still exists before sending close order
        if (!$this->fetchDemoPositionExists($gw, $symbol, $side)) {
            $result['close_error_reason'] = 'position_already_gone';
            return $result;
        }

        // Build close order
        $bybitSide = ($side === 'long') ? 'Sell' : 'Buy';
        $qtyStr    = rtrim(rtrim(number_format($size, 8, '.', ''), '0'), '.');

        $result['close_attempted'] = true;

        try {
            $orderResp = $gw->request('/v5/order/create', [
                'category'    => 'linear',
                'symbol'      => $symbol,
                'side'        => $bybitSide,
                'orderType'   => 'Market',
                'qty'         => $qtyStr,
                'reduceOnly'  => true,
                'positionIdx' => 0,
            ], true);
        } catch (\Throwable $ex) {
            $result['close_error_reason'] = 'order_submit_exception';
            $result['close_ret_msg']      = $ex->getMessage();
            return $result;
        }

        $retCode = (int)($orderResp['ret_code'] ?? -1);
        $retMsg  = (string)($orderResp['ret_msg'] ?? '');
        $result['close_ret_code'] = $retCode;
        $result['close_ret_msg']  = $retMsg;

        if (($orderResp['success'] ?? false) && $retCode === 0) {
            $result['close_ok']       = true;
            $result['close_order_id'] = $orderResp['result']['orderId'] ?? null;
        } else {
            $result['close_error_reason'] = 'order_rejected';
        }

        return $result;
    }

    /**
     * Write an entry to the PM close registry so the bot journal can attribute
     * the close to Profit Manager when the position disappears from Bybit Demo.
     *
     * File: modules/bot/storage/runtime/pm_close_registry.json
     * Key:  {symbol}_{side}
     */
    private function writePmCloseRegistry(
        string  $symbol,
        string  $side,
        string  $closeReason,
        ?string $closeOrderId
    ): void {
        $registryPath = $this->repoRoot . '/modules/bot/storage/runtime/pm_close_registry.json';
        $dir          = dirname($registryPath);

        try {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $registry = [];
            if (is_file($registryPath)) {
                $raw = @file_get_contents($registryPath);
                if ($raw !== false && $raw !== '') {
                    $dec = @json_decode($raw, true);
                    if (is_array($dec)) {
                        $registry = $dec;
                    }
                }
            }

            $key = $symbol . '_' . $side;
            $registry[$key] = [
                'symbol'        => $symbol,
                'side'          => $side,
                'close_source'  => 'profit_manager',
                'close_reason'  => $closeReason,
                'close_order_id'=> $closeOrderId,
                'ts'            => time(),
            ];

            file_put_contents(
                $registryPath,
                json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                LOCK_EX
            );
        } catch (\Throwable) {
            // Never crash a tick over registry write failure
        }
    }
}
