<?php

declare(strict_types=1);

/**
 * Bot Module — Service
 *
 * Architecture split:
 *   strategy modules → find signals, each writes storage/bot_handoff_queue.json
 *   bot module       → autodiscovers strategies, reads operator overrides, owns
 *                      order_queue.json / active_orders.json
 *   profit manager   → separate, monitors profit locks
 *
 * Control layer (this step):
 *   - strategy autodiscovery via manifest.json scan
 *   - strategy_registry.json — persisted registry of all discovered strategies
 *   - operator_overrides.json — compact per-strategy operator controls
 *   - bot consumes only operator-enabled strategies via the registry
 *
 * Bot queue lifecycle states:
 *   queued    — signal ingested, awaiting validation
 *   ready     — validated, bot-owned, ready for execution
 *   submitted — promoted to active_orders (terminal for the queue)
 *   expired   — signal TTL elapsed before execution
 *   withdrawn — signal disappeared from handoff before TTL
 *
 * Bot execution modes:
 *   demo     — PRIMARY: real execution on Bybit Demo account (api-demo.bybit.com)
 *   disabled — ingest/runtime only; no local entries created
 *   passive  — ingest/queue/runtime only; no execution promotion
 *   paper    — full local execution simulation in storage; no exchange interaction (legacy)
 *   smoke    — legacy alias for paper
 *   active   — legacy alias for paper
 *
 * Order lifecycle states (demo mode):
 *   created          — order record built from ready queue item
 *   submitted_demo   — market order submitted to Bybit Demo
 *   confirmed_demo   — position verified open on Bybit Demo
 *   cancelled        — order cancelled before fill
 *   expired          — order TTL elapsed before fill
 *
 * Order lifecycle states (paper mode):
 *   created          — order record built from ready queue item
 *   submitted_paper  — paper-submitted (deterministic, no real exchange)
 *   filled_paper     — paper-filled; originating position is created
 *   cancelled        — order cancelled before fill
 *   expired          — order TTL elapsed before fill
 *
 * Position lifecycle states:
 *   open    — active position (demo or paper)
 *   closing — close in progress (reserved)
 *   closed  — position closed
 *
 * Storage files:
 *   active_orders.json      — open orders (demo or paper)
 *   active_positions.json   — open positions cache (demo: synced from Bybit Demo; paper: local)
 *   closed_positions.json   — historical closed positions
 *   execution_log.ndjson    — append-only execution event log
 */

namespace Modules\Bot;

final class BotService
{
    private static ?self $instance = null;
    private string $moduleDir;
    private string $repoRoot;

    // Per-tick closed trade journal dedup diagnostic counters (reset at construction; bot is
    // a singleton per PHP process so each cron run starts from zero).
    private int   $closedTradeDuplicateIdTotal       = 0;
    private int   $closedTradeDuplicateConflictTotal  = 0;
    private int   $closedTradeIndAggrMismatchTotal    = 0;
    private array $closedTradeConflictExamples        = [];

    public function __construct(?string $moduleDir = null)
    {
        if ($moduleDir !== null) {
            $this->moduleDir = rtrim($moduleDir, '/');
        } else {
            $paths = \Core\System\SystemPaths::instance();
            $this->moduleDir = rtrim(
                $paths->has('bot.bot') ? $paths->get('bot.bot') : $paths->get('bot'),
                '/'
            );
        }
        // Derive repo root: two levels above modules/bot (i.e. the repo root)
        $this->repoRoot = rtrim(dirname($this->moduleDir, 2), '/');
    }

    public static function instance(string $moduleDir): self
    {
        if (self::$instance === null) {
            self::$instance = new self($moduleDir);
        }
        return self::$instance;
    }

    // =========================================================================
    // Public API
    // =========================================================================

    public function getConfig(): array
    {
        try {
            $this->requireBootstrap();
            return BotBootstrap::instance($this->moduleDir)->load()['config'];
        } catch (\Throwable) {
            return [];
        }
    }

    public function getLastRun(): array
    {
        return $this->readJson('storage/last_run.json', []);
    }

    /**
     * Check connectivity to Bybit Demo account using configured credentials.
     *
     * Makes a lightweight authenticated call (/v5/account/wallet-balance) and
     * returns a structured result.  Never logs credentials.
     *
     * @return array{
     *   connected: bool,
     *   mode: string,
     *   account: string,
     *   error: string|null
     * }
     */
    public function checkDemoConnection(): array
    {
        $config    = $this->getConfig();
        $apiKey    = (string)($config['demo_api_key']      ?? '');
        $apiSecret = (string)($config['demo_api_secret']   ?? '');
        $baseUrl   = (string)($config['demo_api_base_url'] ?? 'https://api-demo.bybit.com');

        if ($apiKey === '' || $apiSecret === '') {
            return [
                'connected' => false,
                'mode'      => 'demo',
                'account'   => 'bybit_demo',
                'error'     => 'missing_credentials',
            ];
        }

        try {
            $gw = \Core\Gateway\Bybit::client('bybit_demo_check');
            $gw->setCredentials($apiKey, $apiSecret);
            $gw->setBaseUrl($baseUrl);

            $resp = $gw->request('/v5/account/wallet-balance', [
                'accountType' => 'UNIFIED',
            ], true);

            $retCode = $resp['ret_code'] ?? -1;
            $success = ($resp['success'] ?? false) && $retCode === 0;

            if ($success) {
                return [
                    'connected' => true,
                    'mode'      => 'demo',
                    'account'   => 'bybit_demo',
                    'error'     => null,
                ];
            }

            $errMsg = (string)($resp['ret_msg'] ?? ($resp['error_type'] ?? 'api_error'));
            return [
                'connected' => false,
                'mode'      => 'demo',
                'account'   => 'bybit_demo',
                'error'     => $errMsg,
            ];
        } catch (\Throwable $ex) {
            return [
                'connected' => false,
                'mode'      => 'demo',
                'account'   => 'bybit_demo',
                'error'     => $ex->getMessage(),
            ];
        }
    }

    /**
     * Test live connection via KeyCenter.
     *
     * Uses account_id from config (single source of truth).
     * Makes a lightweight signed API call to verify credentials.
     * Never uses demo credentials.
     */
    public function checkLiveConnection(): array
    {
        $config    = $this->getConfig();
        $accountId = trim((string)($config['account_id'] ?? ''));

        if ($accountId === '') {
            return [
                'connected'   => false,
                'mode'        => 'live',
                'account'     => '',
                'error'       => 'account_id_missing',
                'skip_reason' => 'live_not_ready',
            ];
        }

        try {
            $gw   = \Core\Gateway\Bybit::client($accountId);
            $resp = $gw->request('/v5/account/wallet-balance', [
                'accountType' => 'UNIFIED',
            ], true);

            $retCode = $resp['ret_code'] ?? -1;
            $success = ($resp['success'] ?? false) && $retCode === 0;

            if ($success) {
                return [
                    'connected'   => true,
                    'mode'        => 'live',
                    'account'     => $accountId,
                    'error'       => null,
                    'skip_reason' => null,
                ];
            }

            $errMsg     = (string)($resp['ret_msg'] ?? ($resp['error_type'] ?? 'api_error'));
            $skipReason = in_array($retCode, [10003, 10004, -1], true)
                ? 'live_keycenter_credentials_missing'
                : 'live_not_ready';

            return [
                'connected'   => false,
                'mode'        => 'live',
                'account'     => $accountId,
                'error'       => $errMsg,
                'skip_reason' => $skipReason,
            ];
        } catch (\Throwable $ex) {
            return [
                'connected'   => false,
                'mode'        => 'live',
                'account'     => $accountId,
                'error'       => $ex->getMessage(),
                'skip_reason' => 'live_not_ready',
            ];
        }
    }

    public function getStats(): array
    {
        return $this->readJson('storage/stats.json', []);
    }

    public function getOrderQueue(): array
    {
        return $this->readJson('storage/order_queue.json', []);
    }

    public function getActiveOrders(): array
    {
        return $this->readJson('storage/active_orders.json', []);
    }

    public function getActivePositions(): array
    {
        return $this->readJson('storage/active_positions.json', []);
    }

    public function getStrategyRegistry(): array
    {
        return $this->readJson('storage/strategy_registry.json', []);
    }

    public function getOperatorOverrides(): array
    {
        return $this->readJson('storage/operator_overrides.json', []);
    }

    public function saveOperatorOverrides(array $overrides): void
    {
        $this->writeJson('storage/operator_overrides.json', $overrides);
    }

    public function getRuntimeSnapshot(): array
    {
        try {
            $snap = require $this->moduleDir . '/config/runtime_snapshot.php';
            return is_array($snap) ? $snap : [];
        } catch (\Throwable) {
            return [];
        }
    }

    // =========================================================================
    // Cron entry-point
    // =========================================================================

    /**
     * CronManager entry-point: process one tick.
     *
     * 1. Refresh strategy registry via manifest scan.
     * 2. Load operator overrides to determine enabled/disabled strategies.
     * 3. Read handoff queues from all enabled strategies.
     * 4. Merge signals into bot-owned order queue (deduped by strategy_id:signal_id).
     * 5. Persist all state.
     */
    public function tick(): void
    {
        // Always ensure storage files exist with truthful empty-state values,
        // even when the bot is disabled. This guarantees the admin UI always
        // has real files to read rather than missing-file errors.
        $this->initializeStorage();

        $config = $this->getConfig();
        if (empty($config)) {
            return;
        }

        $tickAt = date('c');
        $tStart = microtime(true);

        // ── 1. Strategy discovery ─────────────────────────────────────────────
        // Registry must stay fresh even when the bot is disabled so dashboard
        // and runtime views do not show a stale strategy count after modules are
        // added, removed, or cleaned.
        $registry  = $this->refreshRegistry($config);
        $overrides = $this->readJson('storage/operator_overrides.json', []);

        $enabledStrategies  = [];
        $disabledStrategies = [];
        foreach ($registry as $rec) {
            $stratId   = (string)($rec['strategy_id'] ?? '');
            $opEnabled = (bool)($overrides[$stratId]['enabled'] ?? true);
            if ($opEnabled) {
                $enabledStrategies[] = $rec;
            } else {
                $disabledStrategies[] = $rec;
            }
        }

        // ── Effective mode: global_runtime_mode takes precedence over legacy mode ──
        // global_runtime_mode is written by the top DEMO/LIVE button and is the
        // single source of truth. Fallback to mode for backward compatibility.
        $globalRuntimeMode   = (string)($config['global_runtime_mode'] ?? '');
        $botMode             = $globalRuntimeMode !== '' ? $globalRuntimeMode : (string)($config['mode'] ?? 'demo');
        $effectiveModeSource = $globalRuntimeMode !== '' ? 'global_runtime_mode' : 'legacy_mode_fallback';

        // ── Demo credentials diagnostics ─────────────────────────────────────
        $demoApiKey         = (string)($config['demo_api_key']    ?? '');
        $demoApiSecret      = (string)($config['demo_api_secret'] ?? '');
        $demoCredsConfigured = ($demoApiKey !== '' && $demoApiSecret !== '');
        $demoConnected      = false;
        $demoConnError      = null;

        if ($botMode === 'demo') {
            $connResult        = $this->checkDemoConnection();
            $demoConnected     = (bool)($connResult['connected'] ?? false);
            $demoConnError     = $connResult['error'] ?? null;
        } elseif (!$demoCredsConfigured) {
            $demoConnError = 'missing_credentials';
        }

        // ── Live credentials diagnostics ─────────────────────────────────────
        $liveAccountId       = trim((string)($config['account_id'] ?? ''));
        $liveEnabled         = (bool)($config['live_enabled'] ?? false);
        $liveConnected       = false;
        $liveConnError       = null;
        $liveCredsMissing    = false;

        if ($botMode === 'live') {
            $liveConnResult  = $this->checkLiveConnection();
            $liveConnected   = (bool)($liveConnResult['connected'] ?? false);
            $liveConnError   = $liveConnResult['error'] ?? null;
            $liveCredsMissing = ($liveConnResult['skip_reason'] ?? '') === 'live_keycenter_credentials_missing';
        }

        $account = match ($botMode) {
            'demo'  => 'bybit_demo',
            'live'  => $liveAccountId !== '' ? $liveAccountId : 'live',
            default => 'local',
        };

        if (!(bool)($config['enabled'] ?? false)) {
            // Bot disabled: still write truthful last_run including fresh strategy
            // discovery totals so the dashboard can render the current registry.
            $stats = array_merge($this->zeroStats(), $this->getStats(), [
                'strategies_discovered_total' => count($registry),
                'strategies_enabled_total'    => count($enabledStrategies),
                'strategies_disabled_total'   => count($disabledStrategies),
            ]);
            $this->writeJson('storage/stats.json', $stats);
            $this->writeJson('storage/last_run.json', array_merge(
                $this->readJson('storage/last_run.json', []),
                [
                    'status'                       => 'disabled',
                    'tick_at'                      => $tickAt,
                    'elapsed_sec'                  => round(microtime(true) - $tStart, 6),
                    'bot_enabled'                  => false,
                    'bot_mode'                     => $botMode,
                    'mode'                         => $botMode,
                    'global_runtime_mode'          => $globalRuntimeMode !== '' ? $globalRuntimeMode : null,
                    'effective_mode_source'        => $effectiveModeSource,
                    'account'                      => $account,
                    'demo_credentials_configured'  => $demoCredsConfigured,
                    'demo_connected'               => $demoConnected,
                    'demo_connection_error'        => $demoConnError,
                    'live_account_id'              => $liveAccountId,
                    'live_enabled'                 => $liveEnabled,
                    'live_connected'               => $liveConnected,
                    'live_connection_error'        => $liveConnError,
                    'live_credentials_missing'     => $liveCredsMissing,
                    'strategies_discovered_total'  => count($registry),
                    'strategies_enabled_total'     => count($enabledStrategies),
                    'strategies_disabled_total'    => count($disabledStrategies),
                    'handoff_sources_active_total' => 0,
                    'handoff_signals_processed'    => 0,
                    'order_queue_total'            => $this->countByStatus($this->readJson('storage/order_queue.json', []), ['queued', 'ready']),
                    'active_orders_count'          => count($this->readJson('storage/active_orders.json', [])),
                    'active_positions_count'       => count($this->readJson('storage/active_positions.json', [])),
                    'ticks_total'                  => (int)($stats['ticks_total'] ?? 0),
                    'handoff_signals_seen_total'   => (int)($stats['handoff_signals_seen_total'] ?? 0),
                ]
            ));
            $this->writeRuntimeSnapshot($config, $this->readJson('storage/last_run.json', []), count($registry), count($enabledStrategies));
            return;
        }

        $handoffSourcesActive = 0;
        foreach ($enabledStrategies as $rec) {
            if (($rec['status'] ?? '') === 'bot_ready') {
                $handoffSourcesActive++;
            }
        }

        // ── 2. Collect signals ────────────────────────────────────────────────
        $allSignals          = [];
        $ignoredSignalsCount = 0;

        $handoffSourcesAllowed          = 0;
        $handoffSourcesBlockedByHandoff = 0;
        $handoffSourcesLegacyAllowed    = 0;

        // Signal source selector (set in config/base.php, default: direct_strategy_handoff).
        // Allowed values: direct_strategy_handoff | governor_approved_demo | shadow_compare
        // Unknown/missing values fall back to direct_strategy_handoff.
        $signalSourceModeRaw = (string)($config['signal_source_mode'] ?? 'direct_strategy_handoff');
        $validSourceModes    = ['direct_strategy_handoff', 'governor_approved_demo', 'shadow_compare'];
        $signalSourceMode    = in_array($signalSourceModeRaw, $validSourceModes, true)
                                 ? $signalSourceModeRaw
                                 : 'direct_strategy_handoff';
        $signalSourceModeInvalidFallback = ($signalSourceMode !== $signalSourceModeRaw);

        // shadow_compare_enabled: when true in direct mode, also compute comparison counters
        $shadowCompareEnabled = (bool)($config['shadow_compare_enabled'] ?? false);

        // Effective execution path (what actually creates orders)
        $signalSourceEffectiveExecution = match ($signalSourceMode) {
            'governor_approved_demo' => 'governor_approved_demo',
            'shadow_compare'         => 'direct_strategy_handoff',
            default                  => 'direct_strategy_handoff',
        };

        // Governor queue counters
        $govQueueSeen           = 0;
        $govQueueValid          = 0;
        $govQueueUsed           = 0;
        $govQueueDupSkipped     = 0;
        $govQueueSkippedNonDemo = 0;
        $govQueueSkippedInvalid = 0;
        $govQueueSkippedExpired = 0;

        // Direct handoff counters
        $directHandoffSeen = 0;
        $directHandoffUsed = 0;

        // Shadow compare counters (shadow_compare mode or shadow_compare_enabled=true in direct mode)
        $shadowOverlap       = 0;
        $shadowDirectOnly    = 0;
        $shadowGovOnly       = 0;
        $shadowWouldFilter   = 0;
        $shadowWouldAdd      = 0;
        $shadowDirectOnlyEx  = [];
        $shadowGovOnlyEx     = [];
        $shadowOverlapEx     = [];

        // ── Helper: load Governor approved_demo_queue ─────────────────────
        $loadGovQueue = function () use (&$govQueueSeen, &$govQueueValid, &$govQueueDupSkipped,
                                         &$govQueueSkippedNonDemo, &$govQueueSkippedInvalid,
                                         &$govQueueSkippedExpired): array {
            $govQueuePath = $this->repoRoot . '/modules/strategy_governor/storage/approved_demo_queue.json';
            $govRaw = [];
            if (file_exists($govQueuePath)) {
                $rawContent = @file_get_contents($govQueuePath);
                if ($rawContent !== false && $rawContent !== '') {
                    $govDecoded = json_decode($rawContent, true);
                    if (is_array($govDecoded)) {
                        $govRaw = $govDecoded;
                    }
                }
            }
            $govQueueSeen = count($govRaw);

            $govFiltered = [];
            foreach ($govRaw as $govItem) {
                // Demo-only safety — skip any non-demo item
                $govItemMode = (string)($govItem['mode'] ?? '');
                if ($govItemMode !== 'demo') {
                    $govQueueSkippedNonDemo++;
                    continue;
                }
                // Required fields
                if ((string)($govItem['signal_id'] ?? '') === ''
                    || (string)($govItem['symbol']    ?? '') === ''
                ) {
                    $govQueueSkippedInvalid++;
                    continue;
                }
                // entry_price must exist, be numeric and > 0
                $govEntryPrice = $govItem['entry_price'] ?? null;
                if ($govEntryPrice === null || !is_numeric($govEntryPrice) || (float)$govEntryPrice <= 0.0) {
                    $govQueueSkippedInvalid++;
                    continue;
                }
                // TTL check
                $govTtl = (string)($govItem['ttl_expires_at'] ?? '');
                if ($govTtl !== '') {
                    $govTtlTs = strtotime($govTtl);
                    if ($govTtlTs !== false && time() > $govTtlTs) {
                        $govQueueSkippedExpired++;
                        continue;
                    }
                }
                // Set handoff_status so processHandoff recognises the item
                $govItem['handoff_status'] = $govItem['handoff_status'] ?? 'new';
                // Ensure entry_mode has a sensible default
                if (empty($govItem['entry_mode'])) {
                    $govItem['entry_mode'] = 'limit';
                }
                $govFiltered[] = $govItem;
            }
            $govQueueValid = count($govFiltered);
            return $govFiltered;
        };

        if ($signalSourceMode === 'governor_approved_demo') {
            // ── Governor approved demo queue ──────────────────────────────
            $allSignals = $loadGovQueue();

        } elseif ($signalSourceMode === 'shadow_compare') {
            // ── Shadow compare: direct handoff executed + Governor read for diagnostics only ──
            // Step 1: collect direct strategy handoff signals (unchanged behaviour)
            foreach ($enabledStrategies as $rec) {
                $handoffResult = $this->resolveHandoffEnabled((string)($rec['strategy_id'] ?? ''), $overrides, (string)($rec['module_path'] ?? ''));
                if (!$handoffResult['allowed']) {
                    $handoffSourcesBlockedByHandoff++;
                    continue;
                }
                $handoffSourcesAllowed++;
                if ($handoffResult['legacy']) {
                    $handoffSourcesLegacyAllowed++;
                }
                $signals    = $this->readHandoffQueueForStrategy($rec);
                $allSignals = array_merge($allSignals, $signals);
            }
            foreach ($disabledStrategies as $rec) {
                $ignoredSignalsCount += count($this->readHandoffQueueForStrategy($rec));
            }
            $directHandoffSeen = count($allSignals);
            $directHandoffUsed = $directHandoffSeen;

            // Step 2: load Governor queue for diagnostics only — do NOT pass into processHandoff
            $govFiltered = $loadGovQueue();

            // Step 3: build stable key sets for comparison
            $buildKey = static function (array $item): string {
                $govKey = (string)($item['governor_signal_key'] ?? '');
                if ($govKey !== '') {
                    return $govKey;
                }
                $sid  = (string)($item['strategy_id'] ?? '');
                $sgid = (string)($item['signal_id']   ?? '');
                if ($sid !== '' && $sgid !== '') {
                    return $sid . ':' . $sgid;
                }
                $sym = (string)($item['symbol']      ?? '');
                $det = (string)($item['detected_at'] ?? '');
                if ($sid !== '' && $sym !== '' && $det !== '') {
                    return $sid . ':' . $sym . ':' . $det;
                }
                return '';
            };

            $directKeys  = [];
            $directByKey = [];
            foreach ($allSignals as $sig) {
                $k = $buildKey($sig);
                if ($k !== '') {
                    $directKeys[$k]  = true;
                    $directByKey[$k] = $sig;
                }
            }

            $govKeys  = [];
            $govByKey = [];
            foreach ($govFiltered as $gSig) {
                $k = $buildKey($gSig);
                if ($k !== '') {
                    $govKeys[$k]  = true;
                    $govByKey[$k] = $gSig;
                }
            }

            // Step 4: compute compare counters
            $buildExample = static function (array $item): array {
                return [
                    'strategy_id' => (string)($item['strategy_id'] ?? ''),
                    'symbol'      => (string)($item['symbol']      ?? ''),
                    'signal_id'   => (string)($item['signal_id']   ?? ''),
                    'detected_at' => (string)($item['detected_at'] ?? ''),
                    'reason'      => (string)($item['reason']      ?? $item['governor_reason'] ?? ''),
                ];
            };

            foreach ($directKeys as $k => $_) {
                if (isset($govKeys[$k])) {
                    $shadowOverlap++;
                    if (count($shadowOverlapEx) < 20) {
                        $shadowOverlapEx[] = $buildExample($directByKey[$k]);
                    }
                } else {
                    $shadowDirectOnly++;
                    $shadowWouldFilter++;
                    if (count($shadowDirectOnlyEx) < 20) {
                        $shadowDirectOnlyEx[] = $buildExample($directByKey[$k]);
                    }
                }
            }
            foreach ($govKeys as $k => $_) {
                if (!isset($directKeys[$k])) {
                    $shadowGovOnly++;
                    $shadowWouldAdd++;
                    if (count($shadowGovOnlyEx) < 20) {
                        $shadowGovOnlyEx[] = $buildExample($govByKey[$k]);
                    }
                }
            }

        } else {
            // ── Direct strategy handoff (existing behaviour, unchanged) ───
            foreach ($enabledStrategies as $rec) {
                $handoffResult = $this->resolveHandoffEnabled((string)($rec['strategy_id'] ?? ''), $overrides, (string)($rec['module_path'] ?? ''));
                if (!$handoffResult['allowed']) {
                    $handoffSourcesBlockedByHandoff++;
                    continue;
                }
                $handoffSourcesAllowed++;
                if ($handoffResult['legacy']) {
                    $handoffSourcesLegacyAllowed++;
                }
                $signals    = $this->readHandoffQueueForStrategy($rec);
                $allSignals = array_merge($allSignals, $signals);
            }
            // Count signals from disabled strategies (tracked but not processed)
            foreach ($disabledStrategies as $rec) {
                $ignoredSignalsCount += count($this->readHandoffQueueForStrategy($rec));
            }
            $directHandoffSeen = count($allSignals);
            $directHandoffUsed = $directHandoffSeen;

            // ── Optional shadow compare in direct mode ─────────────────────
            // When shadow_compare_enabled=true, also read the Governor queue for
            // diagnostics.  Execution is unchanged — no Governor items reach processHandoff.
            if ($shadowCompareEnabled) {
                $govFiltered = $loadGovQueue();

                $buildKey = static function (array $item): string {
                    $govKey = (string)($item['governor_signal_key'] ?? '');
                    if ($govKey !== '') {
                        return $govKey;
                    }
                    $sid  = (string)($item['strategy_id'] ?? '');
                    $sgid = (string)($item['signal_id']   ?? '');
                    if ($sid !== '' && $sgid !== '') {
                        return $sid . ':' . $sgid;
                    }
                    $sym = (string)($item['symbol']      ?? '');
                    $det = (string)($item['detected_at'] ?? '');
                    if ($sid !== '' && $sym !== '' && $det !== '') {
                        return $sid . ':' . $sym . ':' . $det;
                    }
                    return '';
                };

                $buildExample = static function (array $item): array {
                    return [
                        'strategy_id' => (string)($item['strategy_id'] ?? ''),
                        'symbol'      => (string)($item['symbol']      ?? ''),
                        'signal_id'   => (string)($item['signal_id']   ?? ''),
                        'detected_at' => (string)($item['detected_at'] ?? ''),
                        'reason'      => (string)($item['reason']      ?? $item['governor_reason'] ?? ''),
                    ];
                };

                $directKeys  = [];
                $directByKey = [];
                foreach ($allSignals as $sig) {
                    $k = $buildKey($sig);
                    if ($k !== '') {
                        $directKeys[$k]  = true;
                        $directByKey[$k] = $sig;
                    }
                }

                $govKeys  = [];
                $govByKey = [];
                foreach ($govFiltered as $gSig) {
                    $k = $buildKey($gSig);
                    if ($k !== '') {
                        $govKeys[$k]  = true;
                        $govByKey[$k] = $gSig;
                    }
                }

                foreach ($directKeys as $k => $_) {
                    if (isset($govKeys[$k])) {
                        $shadowOverlap++;
                        if (count($shadowOverlapEx) < 20) {
                            $shadowOverlapEx[] = $buildExample($directByKey[$k]);
                        }
                    } else {
                        $shadowDirectOnly++;
                        $shadowWouldFilter++;
                        if (count($shadowDirectOnlyEx) < 20) {
                            $shadowDirectOnlyEx[] = $buildExample($directByKey[$k]);
                        }
                    }
                }
                foreach ($govKeys as $k => $_) {
                    if (!isset($directKeys[$k])) {
                        $shadowGovOnly++;
                        $shadowWouldAdd++;
                        if (count($shadowGovOnlyEx) < 20) {
                            $shadowGovOnlyEx[] = $buildExample($govByKey[$k]);
                        }
                    }
                }
            }
        }

        // ── 3. Load bot state ─────────────────────────────────────────────────
        $orderQueue       = $this->readJson('storage/order_queue.json', []);
        $activeOrders     = $this->readJson('storage/active_orders.json', []);
        $activePositions  = $this->readJson('storage/active_positions.json', []);
        $closedPositions  = $this->readJson('storage/closed_positions.json', []);
        $stats            = array_merge($this->zeroStats(), $this->getStats());

        // ── 4. Process handoff → order queue ──────────────────────────────────
        $result     = $this->processHandoff($allSignals, $orderQueue, $config, $overrides, $tickAt, $botMode);
        $orderQueue = $result['order_queue'];

        // Compute governor queue "used" counter (new + refreshed entries from governor source)
        if ($signalSourceMode === 'governor_approved_demo') {
            $govQueueUsed = $result['new_total'] + $result['refreshed_total'];
        }
        // shadow_compare: governor queue used for orders must remain 0

        // ── 4a. Apply freeze/blacklist cleanup to existing queued/ready items ──
        $fbQueueResult            = $this->applyFreezeBlacklistToQueue($orderQueue, $config, $botMode, $tickAt);
        $orderQueue               = $fbQueueResult['order_queue'];
        $ordersBlockedByFreeze    = ((int)($result['blocked_by_freeze_total']          ?? 0)) + $fbQueueResult['blocked_by_freeze'];
        $ordersBlockedByManualBl  = ((int)($result['blocked_by_manual_blacklist_total'] ?? 0)) + $fbQueueResult['blocked_by_manual_blacklist'];
        $ordersBlockedByAutoBl    = ((int)($result['blocked_by_auto_blacklist_total']   ?? 0)) + $fbQueueResult['blocked_by_auto_blacklist'];
        $freezeBlockExamples      = (array)($result['freeze_block_examples']    ?? []);
        $blacklistBlockExamples   = (array)($result['blacklist_block_examples'] ?? []);
        // Handoff-specific freeze/blacklist diagnostic counters (Task 4)
        $handoffBlockedFreezeTotal       = (int)($result['handoff_blocked_symbol_freeze_total']      ?? 0);
        $handoffBlockedBlacklistTotal    = (int)($result['handoff_blocked_symbol_blacklist_total']   ?? 0);
        $handoffFreezeBlockExamples      = (array)($result['handoff_blocked_symbol_freeze_examples']    ?? []);
        $handoffBlacklistBlockExamples   = (array)($result['handoff_blocked_symbol_blacklist_examples'] ?? []);

        // ── 4b. Reconcile stale submitted queue items ─────────────────────────
        $reconResult = $this->reconcileSubmittedQueue($orderQueue, $activeOrders, $activePositions, $tickAt, $config);
        $orderQueue  = $reconResult['order_queue'];

        // ── 5. Execution state machine ────────────────────────────────────────
        $execResult = $this->processExecution($orderQueue, $activeOrders, $activePositions, $closedPositions, $botMode, $config, $tickAt);
        $orderQueue      = $execResult['order_queue'];
        $activeOrders    = $execResult['active_orders'];
        $activePositions = $execResult['active_positions'];
        $closedPositions = $execResult['closed_positions'];

        // ── 5b. Post-execution reconciliation ────────────────────────────────
        // If positions were closed during execution (e.g. by Profit Manager),
        // recordClosedTrade() has already written the updated closed_trades.json
        // to disk.  Run a second reconcile pass so matching submitted queue items
        // are immediately moved to closed_reconciled in the same tick instead of
        // waiting until the next tick.
        if ($execResult['positions_closed'] > 0) {
            $reconResult2 = $this->reconcileSubmittedQueue(
                $orderQueue, $activeOrders, $activePositions, $tickAt, $config
            );
            $orderQueue = $reconResult2['order_queue'];

            // Aggregate counts: accumulate matched/reconciled totals from both
            // passes; use the final pass values for the "still blocking" counters
            // so last_run reflects the true state after all closes this tick.
            $reconResult['order_queue']              = $reconResult2['order_queue'];
            $reconResult['submitted_total']         += $reconResult2['submitted_total'];
            $reconResult['active_position_matched'] += $reconResult2['active_position_matched'];
            $reconResult['active_order_matched']    += $reconResult2['active_order_matched'];
            $reconResult['closed_trade_matched']    += $reconResult2['closed_trade_matched'];
            $reconResult['reconciled_closed']       += $reconResult2['reconciled_closed'];
            $reconResult['reconciled_expired']      += $reconResult2['reconciled_expired'];
            $reconResult['pm_close_reconciled']      = ($reconResult['pm_close_reconciled'] ?? 0) + ($reconResult2['pm_close_reconciled'] ?? 0);
            // Final-state snapshot: must reflect state after all closes this tick
            $reconResult['waiting_match']            = $reconResult2['waiting_match'];
            $reconResult['stale_unmatched']          = $reconResult2['stale_unmatched'];
            $reconResult['still_blocking']           = $reconResult2['still_blocking'];
        }

        // ── 5c. PM close registry diagnostics ────────────────────────────────
        $pmRegDiag = $this->computePmRegistryDiagnostics();

        // ── 6. Update stats ───────────────────────────────────────────────────
        $stats['ticks_total']               += 1;
        // Discovery counters are current-state snapshots, not cumulative
        $stats['strategies_discovered_total'] = count($registry);
        $stats['strategies_enabled_total']    = count($enabledStrategies);
        $stats['strategies_disabled_total']   = count($disabledStrategies);
        $stats['handoff_sources_active_total']= $handoffSourcesActive;
        // Signal counters are cumulative
        $stats['handoff_signals_seen_total']  += $result['signals_seen'];
        $stats['handoff_signals_ignored_disabled_strategy_total'] += $ignoredSignalsCount;
        $stats['order_queue_new_total']       += $result['new_total'];
        $stats['order_queue_refreshed_total'] += $result['refreshed_total'];
        $stats['order_queue_expired_total']   += $result['expired_total'];
        $stats['order_queue_withdrawn_total'] += $result['withdrawn_total'];
        $stats['handoff_signals_ignored_recent_pm_close_total'] = ($stats['handoff_signals_ignored_recent_pm_close_total'] ?? 0)
            + (int)($result['ignored_recent_pm_close_total'] ?? 0);
        $stats['pm_close_reentry_suppressed_total'] = ($stats['pm_close_reentry_suppressed_total'] ?? 0)
            + (int)($result['pm_close_reentry_suppressed_total'] ?? 0);
        // Execution counters are cumulative
        $stats['orders_created_total']                    += $execResult['orders_created'];
        $stats['orders_submitted_paper_total']            += $execResult['orders_submitted_paper'];
        $stats['orders_filled_paper_total']               += $execResult['orders_filled_paper'];
        $stats['orders_submitted_demo_total']             += $execResult['orders_submitted_demo'];
        $stats['orders_confirmed_demo_total']             += $execResult['orders_confirmed_demo'];
        $stats['positions_opened_total']                  += $execResult['positions_opened'];
        $stats['positions_closed_total']                  += $execResult['positions_closed'];
        $stats['positions_closed_expired_total']          += $execResult['positions_closed_expired'];
        $stats['positions_closed_withdrawn_total']        += $execResult['positions_closed_withdrawn'];
        $stats['positions_closed_reverse_pattern_total']  += $execResult['positions_closed_reverse_pattern'];
        $stats['queue_items_skipped_mode_disabled_total'] += $execResult['queue_items_skipped_mode_disabled'];
        $stats['queue_items_skipped_mode_passive_total']  += $execResult['queue_items_skipped_mode_passive'];
        $stats['execution_log_events_total']              += $execResult['execution_log_events'];
        // Derived counts
        $stats['order_queue_total']      = $this->countByStatus($orderQueue, ['queued', 'ready']);
        $stats['active_orders_total']    = count($activeOrders);
        $stats['active_positions_total'] = count($activePositions);

        // ── 7. Persist ────────────────────────────────────────────────────────
        $this->writeJson('storage/order_queue.json',       $orderQueue);
        $this->writeJson('storage/active_orders.json',     $activeOrders);
        $this->writeJson('storage/active_positions.json',  $activePositions);
        $this->writeJson('storage/closed_positions.json',  $closedPositions);
        $this->writeJson('storage/stats.json',             $stats);

        // ── 7b. Update position runtime age tracker ───────────────────────────
        $this->updatePositionRuntimeAge($activePositions, $tickAt, $config);

        // ── 7c. Compute test reality metrics ─────────────────────────────────
        $testReality = $this->computeTestRealityMetrics($activePositions);

        // ── 7d. Compute slot utilization ──────────────────────────────────────
        $slotLimit       = $this->resolveMaxActivePositions($config);
        $slotUsed        = count($activePositions);
        $slotFree        = max(0, $slotLimit - $slotUsed);
        $slotUtilizationPct = $slotLimit > 0 ? round($slotUsed / $slotLimit * 100.0, 1) : null;

        $elapsed = round(microtime(true) - $tStart, 4);

        // ── 7e. Freeze/blacklist diagnostics ──────────────────────────────────
        $fbDiag = $this->computeFreezeBlacklistDiagnostics($config);

        // ── 7f. Signal trace diagnostics for open positions (Task 5) ──────────
        $positionsOpenedWithTrace    = 0;
        $positionsOpenedMissingTrace = 0;
        $signalTraceMissingExamples  = [];
        foreach ($activePositions as $pos) {
            $hasTrace = (string)($pos['signal_id'] ?? '') !== ''
                && (string)($pos['strategy_id']    ?? '') !== '';
            if ($hasTrace) {
                $positionsOpenedWithTrace++;
            } else {
                $positionsOpenedMissingTrace++;
                if (count($signalTraceMissingExamples) < 5) {
                    $signalTraceMissingExamples[] = [
                        'symbol'         => $pos['symbol']      ?? null,
                        'signal_id'      => $pos['signal_id']   ?? null,
                        'strategy'       => $pos['strategy_id'] ?? null,
                        'missing_fields' => array_filter([
                            ((string)($pos['signal_id']   ?? '') === '') ? 'signal_id'   : null,
                            ((string)($pos['strategy_id'] ?? '') === '') ? 'strategy_id' : null,
                        ]),
                        'reason'         => 'no_signal_id_or_strategy_id_in_position',
                    ];
                }
            }
        }

        // ── 7g. Signal trace diagnostics for closed trades ──────────────────
        $closedTradesWithTrace    = 0;
        $closedTradesMissingTrace = 0;
        $closedTraceMissingExamples = [];
        $closedTradesForDiag = $this->readJson('storage/trades/closed_trades.json', []);
        foreach ($closedTradesForDiag as $ct) {
            $hasTrace = (string)($ct['signal_id'] ?? '') !== ''
                && (string)($ct['strategy_id']    ?? '') !== '';
            if ($hasTrace) {
                $closedTradesWithTrace++;
            } else {
                $closedTradesMissingTrace++;
                if (count($closedTraceMissingExamples) < 5) {
                    $missingF = [];
                    if ((string)($ct['signal_id']   ?? '') === '') $missingF[] = 'signal_id';
                    if ((string)($ct['strategy_id'] ?? '') === '') $missingF[] = 'strategy_id';
                    $closedTraceMissingExamples[] = [
                        'symbol'       => $ct['symbol']       ?? null,
                        'side'         => $ct['side']         ?? null,
                        'strategy_id'  => $ct['strategy_id']  ?? null,
                        'signal_id'    => $ct['signal_id']    ?? null,
                        'closed_at'    => $ct['closed_at']    ?? null,
                        'close_reason' => $ct['close_reason'] ?? null,
                        'missing_fields' => $missingF,
                        'reason'       => 'no_signal_trace_in_closed_trade',
                    ];
                }
            }
        }

        // ── 7g2. Non-destructive consistency check: aggregate vs individual files ──
        // Compares closed_trades.json entries against their corresponding closed/{id}.json
        // files. Reports mismatches only — does not delete or modify any file.
        $closedTradeIndAggrMismatch = 0;
        try {
            $closedDir = $this->moduleDir . '/storage/trades/closed';
            foreach ($closedTradesForDiag as $ct) {
                $ctId = (string)($ct['id'] ?? '');
                if ($ctId === '') {
                    continue;
                }
                $indivFilePath = $closedDir . '/' . $ctId . '.json';
                if (!is_file($indivFilePath)) {
                    // Aggregate entry exists but individual file is missing — count as mismatch.
                    $closedTradeIndAggrMismatch++;
                    $this->closedTradeIndAggrMismatchTotal++;
                    continue;
                }
                $indivRaw = @file_get_contents($indivFilePath);
                if ($indivRaw === false || $indivRaw === '') {
                    $closedTradeIndAggrMismatch++;
                    $this->closedTradeIndAggrMismatchTotal++;
                    continue;
                }
                $indivData = @json_decode($indivRaw, true);
                if (!is_array($indivData)) {
                    $closedTradeIndAggrMismatch++;
                    $this->closedTradeIndAggrMismatchTotal++;
                    continue;
                }
                // Compare key identity fields (signal-level, not price precision).
                if (($indivData['id']           ?? '') !== $ctId
                    || ($indivData['symbol']    ?? '') !== ($ct['symbol']    ?? '')
                    || ($indivData['side']      ?? '') !== ($ct['side']      ?? '')
                    || ($indivData['signal_id'] ?? '') !== ($ct['signal_id'] ?? '')
                    || ($indivData['closed_at'] ?? null) !== ($ct['closed_at'] ?? null)
                    || ($indivData['close_reason'] ?? null) !== ($ct['close_reason'] ?? null)
                    || ($indivData['close_source'] ?? null) !== ($ct['close_source'] ?? null)
                    || (string)round((float)($indivData['roi'] ?? 0), 4) !== (string)round((float)($ct['roi'] ?? 0), 4)
                ) {
                    $closedTradeIndAggrMismatch++;
                    $this->closedTradeIndAggrMismatchTotal++;
                }
            }
        } catch (\Throwable) {
            // Never crash the tick over diagnostic reads
        }

        // ── 7h. Order queue diagnostics for double_bottom_long (Task 3) ─────────
        // Build a lookup of all handoff queue entries keyed by signal_id for source checks.
        $dbHandoffMap = [];
        foreach ($enabledStrategies as $stratRec) {
            if ((string)($stratRec['strategy_id'] ?? '') !== 'double_bottom_long') {
                continue;
            }
            $hqPath = $stratRec['handoff_queue_path'] ?? null;
            if ($hqPath === null) {
                break;
            }
            $hqAbsPath = str_starts_with($hqPath, '/') ? $hqPath : $this->repoRoot . '/' . $hqPath;
            if (!file_exists($hqAbsPath)) {
                break;
            }
            $hqRaw = @file_get_contents($hqAbsPath);
            if ($hqRaw !== false && $hqRaw !== '') {
                $hqDec = @json_decode($hqRaw, true);
                if (is_array($hqDec)) {
                    foreach ($hqDec as $hqEntry) {
                        $hSid = (string)($hqEntry['signal_id'] ?? '');
                        if ($hSid !== '') {
                            $dbHandoffMap[$hSid] = $hqEntry;
                        }
                    }
                }
            }
            break;
        }

        $dbqTotal             = 0;
        $dbqStaleSourceTotal  = 0;
        $dbqMissingSourceTotal= 0;
        $dbqExecSourceTotal   = 0;
        $dbqStaleSourceExamples  = [];
        $dbqMissingSourceExamples= [];
        $nowTsBot = time();

        foreach ($orderQueue as $qItem) {
            $qStratId = (string)($qItem['strategy_id'] ?? '');
            if ($qStratId !== 'double_bottom_long') {
                continue;
            }
            $dbqTotal++;
            $qSigId   = (string)($qItem['signal_id'] ?? '');
            $qSym     = (string)($qItem['symbol']    ?? '');
            $qStatus  = (string)($qItem['queue_status'] ?? '');
            $qCreated = $qItem['first_queued_at'] ?? $qItem['created_at'] ?? null;
            $qUpdated = $qItem['last_refreshed_at'] ?? $qItem['updated_at'] ?? null;

            if ($qSigId === '' || !isset($dbHandoffMap[$qSigId])) {
                $dbqMissingSourceTotal++;
                if (count($dbqMissingSourceExamples) < 5) {
                    $dbqMissingSourceExamples[] = [
                        'symbol'          => $qSym,
                        'side'            => $qItem['side']  ?? null,
                        'signal_id'       => $qSigId,
                        'queue_status'    => $qStatus,
                        'queue_created_at' => $qCreated,
                        'queue_updated_at' => $qUpdated,
                        'source_found'    => false,
                        'source_executable' => null,
                        'source_stale'    => null,
                        'source_age_minutes' => null,
                        'reason'          => 'source_handoff_not_found',
                    ];
                }
                continue;
            }

            $src     = $dbHandoffMap[$qSigId];
            $srcExec = ($src['executable'] ?? null) === true;
            $srcStale= ($src['stale'] ?? false) === true;
            $srcDetectedAt = $src['detected_at'] ?? null;
            $srcDetTs = $srcDetectedAt !== null ? strtotime($srcDetectedAt) : 0;
            $srcAge   = ($srcDetTs > 0) ? round(($nowTsBot - $srcDetTs) / 60, 1) : null;

            if ($srcExec) {
                $dbqExecSourceTotal++;
            } else {
                $dbqStaleSourceTotal++;
                if (count($dbqStaleSourceExamples) < 5) {
                    $dbqStaleSourceExamples[] = [
                        'symbol'             => $qSym,
                        'side'               => $qItem['side'] ?? null,
                        'signal_id'          => $qSigId,
                        'queue_status'       => $qStatus,
                        'queue_created_at'   => $qCreated,
                        'queue_updated_at'   => $qUpdated,
                        'source_found'       => true,
                        'source_executable'  => false,
                        'source_stale'       => $srcStale,
                        'source_age_minutes' => $srcAge,
                        'reason'             => $src['block_reason'] ?? 'source_non_executable',
                    ];
                }
            }
        }

        // ── 7i. Deep loss diagnostics for double_bottom_long (Task 5) ─────────
        $deepLossDiagEnabled  = (bool)($config['deep_loss_diagnostic_enabled'] ?? true);
        $deepLossThreshold    = (float)($config['deep_loss_roi_threshold'] ?? -30.0);
        $deepLossFilter       = (string)($config['deep_loss_strategy_filter'] ?? 'double_bottom_long');
        $deepLossTotal        = 0;
        $deepLossWithTrace    = 0;
        $deepLossMissingTrace = 0;
        $deepLossExamples     = [];

        if ($deepLossDiagEnabled) {
            foreach ($closedTradesForDiag as $ct) {
                if ((string)($ct['strategy_id'] ?? '') !== $deepLossFilter) {
                    continue;
                }
                $roiRaw = $ct['roi'] ?? $ct['roi_pct'] ?? null;
                $roi    = $roiRaw !== null ? (float)$roiRaw : null;
                if ($roi === null || $roi > $deepLossThreshold) {
                    continue;
                }
                $deepLossTotal++;
                $hasTrace = (string)($ct['signal_id'] ?? '') !== '';
                if ($hasTrace) {
                    $deepLossWithTrace++;
                } else {
                    $deepLossMissingTrace++;
                }
                if (count($deepLossExamples) < 10) {
                    // Prefer nested strategy_signal_context for quality fields
                    $ctx = is_array($ct['strategy_signal_context'] ?? null) ? $ct['strategy_signal_context'] : [];
                    $detTs = isset($ct['opened_at']) ? strtotime($ct['opened_at']) : 0;
                    $closedTs = isset($ct['closed_at']) ? strtotime($ct['closed_at']) : 0;
                    $durationMin = ($detTs > 0 && $closedTs > 0) ? round(($closedTs - $detTs) / 60, 1) : null;
                    $deepLossExamples[] = [
                        'symbol'                           => $ct['symbol']       ?? null,
                        'signal_id'                        => $ct['signal_id']    ?? null,
                        'roi'                              => $roi,
                        'pnl'                              => $ct['pnl']          ?? $ct['realized_pnl'] ?? null,
                        'entry_price'                      => $ct['entry_price']  ?? null,
                        'exit_price'                       => $ct['exit_price']   ?? $ct['close_price'] ?? null,
                        'leverage'                         => $ct['leverage']     ?? null,
                        'close_reason'                     => $ct['close_reason'] ?? null,
                        'duration_minutes'                 => $durationMin,
                        'setup_class'                      => $ct['setup_class']        ?? $ctx['setup_class']        ?? null,
                        'synthetic_quality_score'          => $ct['synthetic_quality_score']   ?? $ctx['synthetic_quality_score']   ?? null,
                        'setup_class_score'                => $ct['setup_class_score']         ?? $ctx['setup_class_score']         ?? null,
                        'intraday_double_bottom_score'     => $ct['intraday_double_bottom_score'] ?? $ctx['intraday_double_bottom_score'] ?? null,
                        'candidate_quality_score'          => $ct['candidate_quality_score']   ?? $ctx['candidate_quality_score']   ?? null,
                        'entry_distance_from_neckline_pct' => $ct['entry_distance_from_neckline_pct'] ?? $ctx['entry_distance_from_neckline_pct'] ?? null,
                        'quality_source'                   => $ct['quality_source'] ?? $ctx['quality_source'] ?? null,
                        'reason_codes'                     => $ct['reason_codes']  ?? $ctx['reason_codes']  ?? null,
                        'warnings'                         => $ct['warnings']      ?? $ctx['warnings']      ?? null,
                    ];
                }
            }
        }

        // ── 7j. Missed early-fail diagnostics for double_bottom_long ──────────
        // Scan closed_trades.json for double_bottom_long trades that look like
        // they should have been caught by the early-fail guard but were not.
        // Criteria: ROI <= threshold (default -15), duration_minutes <= threshold (default 45),
        // close_guard != double_bottom_early_fail.
        // This is diagnostics only — no entry thresholds are changed.
        $efMissedTotal           = 0;
        $efMissedWithTrace       = 0;
        $efMissedMissingTrace    = 0;
        $efMissedExamples        = [];
        $efMissedRoiThreshold    = (float)($config['double_bottom_early_fail_missed_roi_threshold']      ?? -15.0);
        $efMissedDurationMinutes = (int)($config['double_bottom_early_fail_missed_duration_minutes']     ?? 45);
        // Count closed trades where Stop Manager ef guard attribution was successfully preserved.
        $closedTradesStopGuardAttributedTotal = 0;

        foreach ($closedTradesForDiag as $ct) {
            // Count trades correctly attributed to the Stop Manager early-fail guard.
            if ((string)($ct['close_source'] ?? '') === 'stop_manager'
                && (string)($ct['close_guard'] ?? '') === 'double_bottom_early_fail'
            ) {
                $closedTradesStopGuardAttributedTotal++;
            }

            if ((string)($ct['strategy_id'] ?? '') !== 'double_bottom_long') {
                continue;
            }
            // Skip trades already attributed to the early-fail guard
            if ((string)($ct['close_guard'] ?? '') === 'double_bottom_early_fail') {
                continue;
            }
            $roiRaw = $ct['roi'] ?? $ct['roi_pct'] ?? null;
            $roi    = $roiRaw !== null ? (float)$roiRaw : null;
            if ($roi === null || $roi > $efMissedRoiThreshold) {
                continue;
            }
            // Duration filter
            $openedTsEf  = isset($ct['opened_at']) ? strtotime($ct['opened_at']) : 0;
            $closedTsEf  = isset($ct['closed_at']) ? strtotime($ct['closed_at']) : 0;
            $durationMinEf = ($openedTsEf > 0 && $closedTsEf > 0)
                ? round(($closedTsEf - $openedTsEf) / 60, 1)
                : null;
            if ($durationMinEf !== null && $durationMinEf > $efMissedDurationMinutes) {
                continue;
            }
            $efMissedTotal++;
            $hasTraceEf = (string)($ct['signal_id'] ?? '') !== '';
            if ($hasTraceEf) {
                $efMissedWithTrace++;
            } else {
                $efMissedMissingTrace++;
            }
            if (count($efMissedExamples) < 10) {
                $ctxEf = is_array($ct['strategy_signal_context'] ?? null) ? $ct['strategy_signal_context'] : [];
                $efMissedExamples[] = [
                    'symbol'                           => $ct['symbol']       ?? null,
                    'signal_id'                        => $ct['signal_id']    ?? null,
                    'roi'                              => $roi,
                    'duration_minutes'                 => $durationMinEf,
                    'close_reason'                     => $ct['close_reason'] ?? null,
                    'close_source'                     => $ct['close_source'] ?? null,
                    'leverage'                         => $ct['leverage']     ?? null,
                    'setup_class'                      => $ct['setup_class']        ?? $ctxEf['setup_class']        ?? null,
                    'synthetic_quality_score'          => $ct['synthetic_quality_score']   ?? $ctxEf['synthetic_quality_score']   ?? null,
                    'setup_class_score'                => $ct['setup_class_score']         ?? $ctxEf['setup_class_score']         ?? null,
                    'intraday_double_bottom_score'     => $ct['intraday_double_bottom_score'] ?? $ctxEf['intraday_double_bottom_score'] ?? null,
                    'candidate_quality_score'          => $ct['candidate_quality_score']   ?? $ctxEf['candidate_quality_score']   ?? null,
                    'entry_distance_from_neckline_pct' => $ct['entry_distance_from_neckline_pct'] ?? $ctxEf['entry_distance_from_neckline_pct'] ?? null,
                    'warnings'                         => $ct['warnings']      ?? $ctxEf['warnings']      ?? null,
                    'reason_codes'                     => $ct['reason_codes']  ?? $ctxEf['reason_codes']  ?? null,
                ];
            }
        }

        // ── 7k. Live skipped signal journal counters ─────────────────────────────
        $liveSkippedTotal        = 0;
        $liveSkippedNewThisRun   = 0;
        $liveSkippedByReason     = [];
        $liveSkippedExamples     = [];
        if ($botMode === 'live') {
            $liveSkippedJournal = $this->readJson('storage/runtime/live_skipped_signals.json', []);
            if (is_array($liveSkippedJournal)) {
                $liveSkippedTotal = count($liveSkippedJournal);
                foreach ($liveSkippedJournal as $skRec) {
                    if (!is_array($skRec)) {
                        continue;
                    }
                    $skStage = (string)($skRec['skip_stage'] ?? 'unknown');
                    $liveSkippedByReason[$skStage] = ($liveSkippedByReason[$skStage] ?? 0) + 1;
                    // Count entries observed during this tick
                    if (($skRec['observed_at'] ?? '') === $tickAt) {
                        $liveSkippedNewThisRun++;
                    }
                    if (count($liveSkippedExamples) < 5) {
                        $liveSkippedExamples[] = [
                            'symbol'            => $skRec['symbol']      ?? null,
                            'strategy_id'       => $skRec['strategy_id'] ?? null,
                            'signal_id'         => $skRec['signal_id']   ?? null,
                            'skip_stage'        => $skStage,
                            'skip_reason'       => $skRec['skip_reason'] ?? null,
                            'observed_at'       => $skRec['observed_at'] ?? null,
                            'bybit_error_code'  => $skRec['bybit_error_code']    ?? null,
                            'bybit_error_message' => $skRec['bybit_error_message'] ?? null,
                            'insufficient_balance_detected' => $skRec['insufficient_balance_detected'] ?? false,
                        ];
                    }
                }
            }
        }

        $lastRun = [
            'status'               => 'ok',
            'tick_at'              => $tickAt,
            'elapsed_sec'          => $elapsed,
            'bot_enabled'          => true,
            'bot_mode'             => $botMode,
            'mode'                 => $botMode,
            'global_runtime_mode'  => $globalRuntimeMode !== '' ? $globalRuntimeMode : null,
            'effective_mode_source'=> $effectiveModeSource,
            'account'              => $account,
            'demo_credentials_configured' => $demoCredsConfigured,
            'demo_connected'              => $demoConnected,
            'demo_connection_error'       => $demoConnError,
            'live_account_id'             => $liveAccountId,
            'live_enabled'                => $liveEnabled,
            'live_connected'              => $liveConnected,
            'live_connection_error'       => $liveConnError,
            'live_credentials_missing'    => $liveCredsMissing,

            // Discovery
            'strategies_discovered_total' => count($registry),
            'strategies_enabled_total'    => count($enabledStrategies),
            'strategies_disabled_total'   => count($disabledStrategies),
            'handoff_sources_active_total'=> $handoffSourcesActive,

            // Handoff gate breakdown (per tick, current state)
            'handoff_sources_discovered_total'        => count($registry),
            'handoff_sources_enabled_total'           => count($enabledStrategies),
            'handoff_sources_allowed_total'           => $handoffSourcesAllowed,
            'handoff_sources_blocked_by_handoff_total'=> $handoffSourcesBlockedByHandoff,
            'handoff_sources_legacy_allowed_total'    => $handoffSourcesLegacyAllowed,

            // Signal source selector
            'signal_source_mode'                      => $signalSourceMode,
            'signal_source_effective_execution'       => $signalSourceEffectiveExecution,
            'signal_source_mode_invalid_fallback'     => $signalSourceModeInvalidFallback,
            'shadow_compare_enabled'                  => $shadowCompareEnabled,
            'governor_queue_seen_total'               => $govQueueSeen,
            'governor_queue_valid_total'              => $govQueueValid,
            'governor_queue_used_for_orders_total'    => $govQueueUsed,
            'governor_queue_duplicate_skipped_total'  => $govQueueDupSkipped,
            'governor_queue_skipped_non_demo_total'   => $govQueueSkippedNonDemo,
            'governor_queue_skipped_invalid_total'    => $govQueueSkippedInvalid,
            'governor_queue_skipped_expired_total'    => $govQueueSkippedExpired,
            'direct_handoff_seen_total'               => $directHandoffSeen,
            'direct_handoff_used_total'               => $directHandoffUsed,
            'shadow_compare_overlap_total'            => $shadowOverlap,
            'shadow_compare_direct_only_total'        => $shadowDirectOnly,
            'shadow_compare_governor_only_total'      => $shadowGovOnly,
            'shadow_compare_governor_would_filter_total' => $shadowWouldFilter,
            'shadow_compare_governor_would_add_total'    => $shadowWouldAdd,
            'shadow_compare_direct_only_examples'     => $shadowDirectOnlyEx,
            'shadow_compare_governor_only_examples'   => $shadowGovOnlyEx,
            'shadow_compare_overlap_examples'         => $shadowOverlapEx,

            // Signals this tick
            'handoff_signals_processed'                   => $result['signals_seen'],
            'handoff_signals_ignored_disabled_strategy'   => $ignoredSignalsCount,
            'handoff_signals_ignored_invalid_payload'     => $result['ignored_invalid_signal_payload'],
            'handoff_signals_ignored_invalid_entry_mode'  => $result['ignored_invalid_entry_mode'],
            'handoff_signals_ignored_recent_pm_close_total' => (int)($result['ignored_recent_pm_close_total'] ?? 0),
            'pm_close_reentry_suppressed_total'           => $stats['pm_close_reentry_suppressed_total'] ?? 0,
            'pm_close_reentry_suppressed_examples'        => $result['pm_close_reentry_suppressed_examples'] ?? [],

            // PM close registry state diagnostics (current snapshot)
            'pm_close_registry_entries_total'             => $pmRegDiag['entries_total'],
            'pm_close_registry_active_suppression_total'  => $pmRegDiag['active_suppression_total'],
            'pm_close_registry_consumed_retained_total'   => $pmRegDiag['consumed_retained_total'],
            'pm_close_registry_expired_removed_total'     => $pmRegDiag['expired_removed_total'],

            // Queue changes this tick
            'order_queue_new_total'       => $result['new_total'],
            'order_queue_refreshed_total' => $result['refreshed_total'],
            'order_queue_expired_total'   => $result['expired_total'],
            'order_queue_withdrawn_total' => $result['withdrawn_total'],

            // Execution this tick
            'execution_mode'                         => $botMode,
            'orders_created'                         => $execResult['orders_created'],
            'orders_submitted_paper'                 => $execResult['orders_submitted_paper'],
            'orders_filled_paper'                    => $execResult['orders_filled_paper'],
            'orders_submitted_demo'                  => $execResult['orders_submitted_demo'],
            'orders_confirmed_demo'                  => $execResult['orders_confirmed_demo'],
            'positions_opened'                       => $execResult['positions_opened'],
            'positions_closed'                       => $execResult['positions_closed'],
            'positions_closed_expired'               => $execResult['positions_closed_expired'],
            'positions_closed_withdrawn'             => $execResult['positions_closed_withdrawn'],
            'positions_closed_reverse_pattern'       => $execResult['positions_closed_reverse_pattern'],
            'queue_items_skipped_mode_disabled'      => $execResult['queue_items_skipped_mode_disabled'],
            'queue_items_skipped_mode_passive'       => $execResult['queue_items_skipped_mode_passive'],
            'execution_log_events'                   => $execResult['execution_log_events'],

            // Demo execution diagnostics (this tick)
            'demo_orders_prepared'           => $execResult['demo_orders_prepared']           ?? 0,
            'demo_orders_rejected'           => $execResult['demo_orders_rejected']           ?? 0,
            'demo_orders_submitted'          => $execResult['orders_submitted_demo']           ?? 0,
            'demo_orders_confirmed'          => $execResult['orders_confirmed_demo']           ?? 0,
            'demo_last_error_code'           => $execResult['demo_last_error_code']           ?? null,
            'demo_last_error_msg'            => $execResult['demo_last_error_msg']            ?? null,
            'demo_last_rejected_symbol'      => $execResult['demo_last_rejected_symbol']      ?? null,
            'demo_qty_invalid_count'         => $execResult['demo_qty_invalid_count']         ?? 0,
            'demo_leverage_clamped_count'    => $execResult['demo_leverage_clamped_count']    ?? 0,
            'demo_set_leverage_failed_count' => $execResult['demo_set_leverage_failed_count'] ?? 0,
            // Per-tick leverage diagnostics
            'demo_last_req_leverage'         => $execResult['demo_last_req_leverage']         ?? null,
            'demo_last_eff_leverage'         => $execResult['demo_last_eff_leverage']         ?? null,
            'demo_last_leverage_source'      => $execResult['demo_last_leverage_source']      ?? null,
            'demo_last_budget_source'        => $execResult['demo_last_budget_source']        ?? null,
            'demo_last_set_lev_note'         => $execResult['demo_last_set_lev_note']         ?? null,
            'demo_last_set_lev_code'         => $execResult['demo_last_set_lev_code']         ?? null,
            'demo_last_set_lev_msg'          => $execResult['demo_last_set_lev_msg']          ?? null,
            'demo_leverage_mismatch_count'   => $execResult['demo_leverage_mismatch_count']   ?? 0,

            // Current queue/execution state
            'order_queue_total'      => $stats['order_queue_total'],
            'active_orders_count'    => count($activeOrders),
            'active_positions_count' => count($activePositions),

            // Submitted queue reconciliation (this tick)
            'submitted_reconcile_enabled'              => $reconResult['reconcile_enabled'],
            'submitted_queue_total'                    => $reconResult['submitted_total'],
            'submitted_active_position_matched_total'  => $reconResult['active_position_matched'],
            'submitted_active_order_matched_total'     => $reconResult['active_order_matched'],
            'submitted_closed_trade_matched_total'     => $reconResult['closed_trade_matched'],
            'submitted_reconciled_closed_total'        => $reconResult['reconciled_closed'],
            'submitted_reconciled_expired_total'       => $reconResult['reconciled_expired'],
            'submitted_pm_close_reconciled_total'      => $reconResult['pm_close_reconciled'] ?? 0,
            'submitted_waiting_match_total'            => $reconResult['waiting_match'],
            'submitted_stale_unmatched_total'          => $reconResult['stale_unmatched'],
            'submitted_still_blocking_total'           => $reconResult['still_blocking'],

            // Funnel: signal → order (per-tick view)
            'funnel_handoff_seen_total'               => $result['signals_seen'],
            'funnel_handoff_processed_total'          => $result['signals_seen'] - (int)($result['ignored_invalid_signal_payload'] ?? 0) - (int)($result['ignored_invalid_entry_mode'] ?? 0),
            'funnel_order_queue_new_total'            => $result['new_total'],
            'funnel_order_queue_refreshed_total'      => $result['refreshed_total'],
            'funnel_orders_created_total'             => $execResult['orders_created'],
            'funnel_orders_submitted_demo_total'      => $execResult['orders_submitted_demo'],
            'funnel_orders_confirmed_demo_total'      => $execResult['orders_confirmed_demo'],
            'funnel_blocked_by_active_position_total' => $execResult['funnel_blocked_by_active_position'] ?? 0,
            'funnel_blocked_by_submitted_queue_total' => $reconResult['still_blocking'],
            'funnel_blocked_by_max_slots_total'       => $execResult['funnel_blocked_by_max_slots'] ?? 0,
            'funnel_duplicate_signal_total'           => $govQueueDupSkipped,
            'funnel_stale_signal_total'               => $result['expired_total'],

            // Slot utilization
            'slot_limit_total'       => $slotLimit,
            'slot_used_total'        => $slotUsed,
            'slot_free_total'        => $slotFree,
            'slot_utilization_pct'   => $slotUtilizationPct,

            // Test reality metrics
            'test_reality_closed_trades_total'            => $testReality['test_reality_closed_trades_total'],
            'test_reality_open_positions_total'           => $testReality['test_reality_open_positions_total'],
            'test_reality_realized_pnl_total'             => $testReality['test_reality_realized_pnl_total'],
            'test_reality_unrealized_pnl_total'           => $testReality['test_reality_unrealized_pnl_total'],
            'test_reality_net_pnl_if_closed_now'          => $testReality['test_reality_net_pnl_if_closed_now'],
            'test_reality_closed_winrate'                 => $testReality['test_reality_closed_winrate'],
            'test_reality_avg_closed_roi'                 => $testReality['test_reality_avg_closed_roi'],
            'test_reality_avg_open_roi'                   => $testReality['test_reality_avg_open_roi'],
            'test_reality_worst_open_roi'                 => $testReality['test_reality_worst_open_roi'],
            'test_reality_best_open_roi'                  => $testReality['test_reality_best_open_roi'],
            'test_reality_open_positions_in_loss_total'   => $testReality['test_reality_open_positions_in_loss_total'],
            'test_reality_open_positions_in_profit_total' => $testReality['test_reality_open_positions_in_profit_total'],
            'test_reality_open_unrealized_unknown_total'  => $testReality['test_reality_open_unrealized_unknown_total'],
            'test_reality_by_strategy'                    => $testReality['test_reality_by_strategy'],

            // Cumulative
            'ticks_total'                => (int)($stats['ticks_total'] ?? 0),
            'handoff_signals_seen_total' => (int)($stats['handoff_signals_seen_total'] ?? 0),

            // Freeze/blacklist diagnostics
            'symbol_freeze_enabled'               => $fbDiag['symbol_freeze_enabled'],
            'symbol_freeze_after_close_minutes'   => $fbDiag['symbol_freeze_after_close_minutes'],
            'symbol_freeze_registry_entries_total'=> $fbDiag['symbol_freeze_registry_entries_total'],
            'symbol_freeze_active_total'          => $fbDiag['symbol_freeze_active_total'],
            'symbol_freeze_expired_removed_total' => $fbDiag['symbol_freeze_expired_removed_total'],
            'symbol_blacklist_enabled'            => $fbDiag['symbol_blacklist_enabled'],
            'manual_blacklist_total'              => $fbDiag['manual_blacklist_total'],
            'auto_blacklist_total'                => $fbDiag['auto_blacklist_total'],
            'auto_blacklist_active_total'         => $fbDiag['auto_blacklist_active_total'],
            'auto_blacklist_expired_removed_total'=> $fbDiag['auto_blacklist_expired_removed_total'],
            'orders_blocked_by_freeze_total'      => $ordersBlockedByFreeze,
            'orders_blocked_by_manual_blacklist_total' => $ordersBlockedByManualBl,
            'orders_blocked_by_auto_blacklist_total'   => $ordersBlockedByAutoBl,
            'freeze_block_examples'               => $freezeBlockExamples,
            'blacklist_block_examples'            => $blacklistBlockExamples,
            // ── Adaptive symbol freeze diagnostics ────────────────────────────
            'symbol_freeze_adaptive_enabled'                         => $fbDiag['symbol_freeze_adaptive_enabled'],
            'symbol_freeze_double_bottom_applied_total'              => $fbDiag['symbol_freeze_double_bottom_applied_total'],
            'symbol_freeze_double_bottom_profit_small_total'         => $fbDiag['symbol_freeze_double_bottom_profit_small_total'],
            'symbol_freeze_double_bottom_profit_normal_total'        => $fbDiag['symbol_freeze_double_bottom_profit_normal_total'],
            'symbol_freeze_double_bottom_profit_strong_total'        => $fbDiag['symbol_freeze_double_bottom_profit_strong_total'],
            'symbol_freeze_double_bottom_profit_extreme_total'       => $fbDiag['symbol_freeze_double_bottom_profit_extreme_total'],
            'symbol_freeze_double_bottom_loss_total'                 => $fbDiag['symbol_freeze_double_bottom_loss_total'],
            'symbol_freeze_double_bottom_deep_loss_total'            => $fbDiag['symbol_freeze_double_bottom_deep_loss_total'],
            'symbol_freeze_double_bottom_early_fail_total'           => $fbDiag['symbol_freeze_double_bottom_early_fail_total'],
            'symbol_freeze_double_bottom_emergency_stop_total'       => $fbDiag['symbol_freeze_double_bottom_emergency_stop_total'],
            'symbol_freeze_double_bottom_missing_roi_total'          => $fbDiag['symbol_freeze_double_bottom_missing_roi_total'],
            'symbol_freeze_double_bottom_examples'                   => $fbDiag['symbol_freeze_double_bottom_examples'],
            'symbol_freeze_double_bottom_not_applied_total'          => $fbDiag['symbol_freeze_double_bottom_not_applied_total'],
            'symbol_freeze_double_bottom_not_applied_examples'       => $fbDiag['symbol_freeze_double_bottom_not_applied_examples'],
            // ── Handoff-specific freeze/blacklist diagnostic counters (Task 4) ────
            'handoff_blocked_symbol_freeze_total'       => $handoffBlockedFreezeTotal,
            'handoff_blocked_symbol_blacklist_total'    => $handoffBlockedBlacklistTotal,
            'handoff_blocked_symbol_freeze_examples'    => $handoffFreezeBlockExamples,
            'handoff_blocked_symbol_blacklist_examples' => $handoffBlacklistBlockExamples,
            // ── Signal trace diagnostics for open positions (Task 5) ─────────────
            'positions_opened_with_signal_trace_total'    => $positionsOpenedWithTrace,
            'positions_opened_missing_signal_trace_total' => $positionsOpenedMissingTrace,
            'signal_trace_missing_examples'               => $signalTraceMissingExamples,
            // ── Signal trace diagnostics for closed trades (Task 4 fix) ──────────
            'closed_trades_with_signal_trace_total'       => $closedTradesWithTrace,
            'closed_trades_missing_signal_trace_total'    => $closedTradesMissingTrace,
            'closed_trades_signal_trace_missing_examples' => $closedTraceMissingExamples,
            // ── Closed trade journal dedup/integrity diagnostics ──────────────────
            'closed_trade_duplicate_id_total'                    => $this->closedTradeDuplicateIdTotal,
            'closed_trade_duplicate_conflict_total'              => $this->closedTradeDuplicateConflictTotal,
            'closed_trade_individual_aggregate_mismatch_total'   => $this->closedTradeIndAggrMismatchTotal,
            'closed_trade_conflict_examples'                     => $this->closedTradeConflictExamples,
            // ── Handoff freshness/lifecycle diagnostics (new) ────────────────────
            'handoff_blocked_stale_signal_total'                          => $result['handoff_blocked_stale_signal_total']                        ?? 0,
            'stale_handoff_ignored_total'                                 => $result['stale_handoff_ignored_total']                               ?? 0,
            'handoff_blocked_needs_revalidation_after_symbol_block_total' => $result['handoff_blocked_needs_revalidation_after_symbol_block_total'] ?? 0,
            'stale_handoff_block_examples'                                => $result['stale_handoff_block_examples']                              ?? [],
            'revalidation_required_examples'                              => $result['revalidation_required_examples']                            ?? [],
            // ── Execution mode neutral diagnostics (strategy signals are environment-neutral) ──
            'handoff_strategy_signals_seen_total'          => $result['handoff_strategy_signals_seen_total']          ?? 0,
            'handoff_strategy_signals_used_total'          => $result['handoff_strategy_signals_used_total']          ?? 0,
            'handoff_execution_mode_from_bot_total'        => $result['handoff_execution_mode_from_bot_total']        ?? 0,
            'handoff_execution_mode_from_global_total'     => $effectiveModeSource === 'global_runtime_mode' ? ($result['handoff_execution_mode_from_bot_total'] ?? 0) : 0,
            'handoff_missing_execution_mode_ignored_total' => $result['handoff_missing_execution_mode_ignored_total'] ?? 0,
            'handoff_blocked_global_live_disabled_total'   => $result['handoff_blocked_global_live_disabled_total']   ?? 0,
            'handoff_blocked_gateway_unavailable_total'    => $result['handoff_blocked_gateway_unavailable_total']    ?? 0,
            'deprecated_strategy_mode_keys_seen_total'     => $result['deprecated_strategy_mode_keys_seen_total']    ?? 0,
            'deprecated_strategy_mode_keys_ignored_total'  => $result['deprecated_strategy_mode_keys_ignored_total'] ?? 0,
            // ── Task 3: Order queue diagnostics for double_bottom_long ───────────
            'order_queue_double_bottom_items_total'          => $dbqTotal,
            'order_queue_double_bottom_stale_source_total'   => $dbqStaleSourceTotal,
            'order_queue_double_bottom_missing_source_total' => $dbqMissingSourceTotal,
            'order_queue_double_bottom_executable_source_total' => $dbqExecSourceTotal,
            'order_queue_stale_source_examples'              => $dbqStaleSourceExamples,
            'order_queue_missing_source_examples'            => $dbqMissingSourceExamples,
            // ── Task 5: Deep loss diagnostics for double_bottom_long ─────────────
            'double_bottom_deep_loss_total'                  => $deepLossTotal,
            'double_bottom_deep_loss_with_trace_total'       => $deepLossWithTrace,
            'double_bottom_deep_loss_missing_trace_total'    => $deepLossMissingTrace,
            'double_bottom_deep_loss_examples'               => $deepLossExamples,
            // ── Missed early-fail diagnostics for double_bottom_long ─────────────
            'double_bottom_early_fail_missed_total'              => $efMissedTotal,
            'double_bottom_early_fail_missed_with_trace_total'   => $efMissedWithTrace,
            'double_bottom_early_fail_missed_missing_trace_total'=> $efMissedMissingTrace,
            'double_bottom_early_fail_missed_examples'           => $efMissedExamples,
            // ── Stop Manager ef guard attribution diagnostics ─────────────────────
            'closed_trades_stop_guard_attributed_total'          => $closedTradesStopGuardAttributedTotal,
            // ── Live skipped signal journal diagnostics ───────────────────────────
            'live_skipped_signals_total'      => $liveSkippedTotal,
            'live_skipped_signals_new_this_run' => $liveSkippedNewThisRun,
            'live_skipped_by_reason'          => $liveSkippedByReason,
            'live_skipped_examples'           => $liveSkippedExamples,
        ];

        $this->writeJson('storage/last_run.json', $lastRun);
        $this->computeSymbolFreezeDurationStats($config);
        $this->writeRuntimeSnapshot($config, $lastRun, count($registry), count($enabledStrategies));
    }

    // =========================================================================
    // Strategy autodiscovery
    // =========================================================================

    /**
     * Scan modules/strategy/* (up to configured depth) for strategy modules.
     * Writes strategy_registry.json and returns the discovered records.
     * Always overwrites the file with a fresh scan — never merges stale contents.
     *
     * A directory is a strategy module when it has a manifest.json with
     * category == 'strategy'. Storage presence is NOT required for discovery.
     *
     * status: 'bot_ready'              — storage/bot_handoff_queue.json exists
     *         'discovered'             — storage dir present but no handoff queue yet
     *         'storage_not_initialized'— manifest found, storage dir absent
     */
    private function refreshRegistry(array $config): array
    {
        $discovered = $this->discoverStrategies($config);
        $this->writeJson('storage/strategy_registry.json', $discovered);
        return $discovered;
    }

    private function discoverStrategies(array $config): array
    {
        $scanRoots = (array)($config['strategy_scan_roots'] ?? ['modules/strategy']);
        $maxDepth  = max(1, min(5, (int)($config['strategy_scan_depth'] ?? 3)));

        $found = [];
        foreach ($scanRoots as $root) {
            $absRoot = str_starts_with($root, '/') ? $root : $this->repoRoot . '/' . $root;
            if (is_dir($absRoot)) {
                $this->scanDirForStrategies($absRoot, 0, $maxDepth, $found);
            }
        }
        return array_values($found);
    }

    private function scanDirForStrategies(string $dir, int $depth, int $maxDepth, array &$found): void
    {
        $manifestPath = $dir . '/manifest.json';
        if (file_exists($manifestPath)) {
            $raw      = @file_get_contents($manifestPath);
            $manifest = ($raw !== false) ? json_decode($raw, true) : null;
            if (is_array($manifest) && ($manifest['category'] ?? '') === 'strategy') {
                $record  = $this->buildStrategyRegistryRecord($dir, $manifest);
                if ($record !== null) {
                    $found[$record['strategy_id']] = $record;
                }
            }
        }
        if ($depth < $maxDepth) {
            $subdirs = glob($dir . '/*', GLOB_ONLYDIR) ?: [];
            foreach ($subdirs as $subdir) {
                $this->scanDirForStrategies($subdir, $depth + 1, $maxDepth, $found);
            }
        }
    }

    /**
     * Build a registry record for a discovered strategy module.
     */
    private function buildStrategyRegistryRecord(string $absDir, array $manifest): ?array
    {
        $stratId = (string)($manifest['name'] ?? '');
        if ($stratId === '') {
            return null;
        }

        $modulePath = ltrim(str_replace($this->repoRoot . '/', '', $absDir), '/');

        // Storage directory and handoff queue presence determine status
        $hasStorage     = is_dir($absDir . '/storage');
        $handoffRelPath = $modulePath . '/storage/bot_handoff_queue.json';
        $handoffAbsPath = $absDir . '/storage/bot_handoff_queue.json';
        $hasHandoff     = file_exists($handoffAbsPath);

        // Infer long/short support from strategy_id and description
        $supportsLong  = !str_ends_with($stratId, '_short');
        $supportsShort = !str_ends_with($stratId, '_long');
        $desc = strtolower((string)($manifest['description'] ?? ''));
        if (str_contains($desc, 'long') || str_contains($desc, 'лонг')) {
            $supportsLong  = true;
            $supportsShort = str_contains($desc, 'short') || str_contains($desc, 'шорт');
        }

        return [
            'strategy_id'        => $stratId,
            'module_path'        => $modulePath,
            'manifest_path'      => $modulePath . '/manifest.json',
            'title'              => (string)($manifest['title'] ?? $stratId),
            'category'           => (string)($manifest['category'] ?? 'strategy'),
            'enabled_by_default' => (bool)($manifest['enabled_by_default'] ?? true),
            'handoff_queue_path' => $hasHandoff ? $handoffRelPath : null,
            'supports_long'      => $supportsLong,
            'supports_short'     => $supportsShort,
            'status'             => $hasHandoff ? 'bot_ready' : ($hasStorage ? 'discovered' : 'storage_not_initialized'),
            'discovered_at'      => date('c'),
        ];
    }

    // =========================================================================
    // Handoff ingestion
    // =========================================================================

    /**
     * Resolve whether a strategy's handoff queue may be ingested.
     *
     * Priority:
     *   1. operator_overrides[strategy_id].handoff_enabled — explicit operator decision
     *   2. strategy config (active.php merged over base.php)
     *   3. default false — never ingest unless explicitly enabled
     */
    /**
     * Resolve whether a strategy's handoff queue may be ingested.
     *
     * Returns an array:
     *   'allowed' => bool   — whether ingestion is permitted
     *   'legacy'  => bool   — true when allowed only because the key is absent everywhere
     *                         (backward-compat default); false for an explicit decision
     *
     * Resolution order:
     *   1. Operator override (explicit true/false) — highest precedence
     *   2. Strategy config/active.php → config/base.php (explicit true/false)
     *   3. Key absent everywhere → allow (legacy backward-compat, NOT a block)
     */
    private function resolveHandoffEnabled(string $stratId, array $overrides, string $modulePath): array
    {
        // 1. Operator override takes precedence when explicitly set
        if (isset($overrides[$stratId]['handoff_enabled'])) {
            return ['allowed' => (bool)$overrides[$stratId]['handoff_enabled'], 'legacy' => false];
        }

        // 2. Strategy config files
        if ($modulePath !== '') {
            $absModulePath = str_starts_with($modulePath, '/') ? $modulePath : $this->repoRoot . '/' . $modulePath;
            $base   = $absModulePath . '/config/base.php';
            $active = $absModulePath . '/config/active.php';
            $cfg    = [];
            if (file_exists($base)) {
                $data = @include $base;
                if (is_array($data)) {
                    $cfg = $data;
                }
            }
            if (file_exists($active)) {
                $data = @include $active;
                if (is_array($data)) {
                    $cfg = array_merge($cfg, $data);
                }
            }
            if (isset($cfg['handoff_enabled'])) {
                return ['allowed' => (bool)$cfg['handoff_enabled'], 'legacy' => false];
            }
        }

        // 3. Key absent everywhere — allow for backward compatibility.
        //    Strategies built before handoff_enabled existed must continue working
        //    without requiring a config migration.
        return ['allowed' => true, 'legacy' => true];
    }

    /**
     * Read handoff queue for a single registry record.
     * Returns only signals with handoff_status in [new, refreshed].
     */
    private function readHandoffQueueForStrategy(array $record): array
    {
        $relPath = $record['handoff_queue_path'] ?? null;
        if ($relPath === null) {
            return [];
        }

        $absPath = str_starts_with($relPath, '/') ? $relPath : $this->repoRoot . '/' . $relPath;
        if (!file_exists($absPath)) {
            return [];
        }
        $raw = file_get_contents($absPath);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $ready = [];
        foreach ($decoded as $rec) {
            $status = (string)($rec['handoff_status'] ?? '');
            if (in_array($status, ['new', 'refreshed'], true)) {
                $ready[] = $rec;
            }
        }
        return $ready;
    }

    /**
     * Reconcile stale submitted order-queue items.
     *
     * A submitted queue item can permanently block new signals with the same
     * signal_id once the underlying position or order is no longer active.
     * This method inspects each submitted item and transitions it to a safe
     * terminal status when appropriate.
     *
     * Resolution order for each submitted item:
     *   1. Active order exists (strategy_id + signal_id)          → keep submitted
     *   2. Active position exists (strategy_id + symbol)          → keep submitted
     *   3. Closed trade matched (signal_id or strategy+symbol+ts) → closed_reconciled
     *   4. Age > submitted_without_position_ttl_minutes config    → submitted_expired
     *   5. Age within TTL but no evidence yet                     → waiting_match (submitted)
     *   6. No timestamps at all / suspicious                      → stale_unmatched (submitted)
     *
     * When submitted_reconcile_enabled = false the method is a no-op
     * (all counters zero, queue returned unchanged).
     *
     * Never creates orders, never modifies active_positions or active_orders.
     *
     * @return array{
     *   order_queue: array,
     *   reconcile_enabled: bool,
     *   submitted_total: int,
     *   active_position_matched: int,
     *   active_order_matched: int,
     *   closed_trade_matched: int,
     *   reconciled_closed: int,
     *   reconciled_expired: int,
     *   waiting_match: int,
     *   stale_unmatched: int,
     *   still_blocking: int,
     * }
     */
    private function reconcileSubmittedQueue(
        array $orderQueue,
        array $activeOrders,
        array $activePositions,
        string $tickAt,
        array $config = []
    ): array {
        $reconcileEnabled = (bool)($config['submitted_reconcile_enabled'] ?? true);

        $submittedTotal          = 0;
        $activePositionMatched   = 0;
        $activeOrderMatched      = 0;
        $closedTradeMatched      = 0;
        $reconciledClosed        = 0;
        $reconciledExpired       = 0;
        $pmCloseReconciledTotal  = 0;
        $waitingMatch            = 0;
        $staleUnmatched          = 0;
        $stillBlocking           = 0;

        if (!$reconcileEnabled) {
            // Count how many submitted items exist for diagnostics, but don't mutate.
            foreach ($orderQueue as $qItem) {
                if ((string)($qItem['queue_status'] ?? '') === 'submitted') {
                    $submittedTotal++;
                    $stillBlocking++;
                }
            }
            return [
                'order_queue'              => $orderQueue,
                'reconcile_enabled'        => false,
                'submitted_total'          => $submittedTotal,
                'active_position_matched'  => 0,
                'active_order_matched'     => 0,
                'closed_trade_matched'     => 0,
                'reconciled_closed'        => 0,
                'reconciled_expired'       => 0,
                'pm_close_reconciled'      => 0,
                'waiting_match'            => 0,
                'stale_unmatched'          => 0,
                'still_blocking'           => $stillBlocking,
            ];
        }

        // Config TTL
        $ttlWithoutPosMins = (int)($config['submitted_without_position_ttl_minutes'] ?? 30);
        $ttlWithoutPosSecs = max(1, $ttlWithoutPosMins) * 60;

        // ── Load PM close registry for submitted-item reconciliation ──────────
        $pmCloseRegistry = [];
        try {
            $pmRegPath = $this->moduleDir . '/storage/runtime/pm_close_registry.json';
            if (is_file($pmRegPath)) {
                $pmRegRaw = @file_get_contents($pmRegPath);
                if ($pmRegRaw !== false && $pmRegRaw !== '') {
                    $pmRegDec = @json_decode($pmRegRaw, true);
                    if (is_array($pmRegDec)) {
                        $pmCloseRegistry = $pmRegDec;
                    }
                }
            }
        } catch (\Throwable) {
            // Never fail over registry load
        }

        // ── Build fast-lookup sets ────────────────────────────────────────────
        // Active orders: keyed by "strategy_id:signal_id"
        $activeOrderKeys = [];
        foreach ($activeOrders as $ao) {
            $stratId  = (string)($ao['strategy_id'] ?? $ao['owner_strategy'] ?? '');
            $signalId = (string)($ao['signal_id'] ?? '');
            if ($stratId !== '' && $signalId !== '') {
                $activeOrderKeys[$stratId . ':' . $signalId] = true;
            }
        }

        // Active positions: keyed by "strategy_id:symbol" (strongest available)
        // Also indexed by symbol alone as a fallback
        $activePosByStratSym = [];
        $activePosBySym      = [];
        foreach ($activePositions as $ap) {
            $stratId = (string)($ap['strategy_id'] ?? $ap['owner_strategy'] ?? '');
            $symbol  = (string)($ap['symbol'] ?? '');
            if ($symbol !== '') {
                $activePosBySym[$symbol] = true;
                if ($stratId !== '') {
                    $activePosByStratSym[$stratId . ':' . $symbol] = true;
                }
            }
        }

        // ── Load closed trades ────────────────────────────────────────────────
        $closedTrades     = $this->readJson('storage/trades/closed_trades.json', []);
        // Index by strategy_id:symbol → array of closed_at timestamps
        $closedByStratSym  = [];
        $closedBySignalId  = [];
        foreach ($closedTrades as $ct) {
            $ctStratId  = (string)($ct['strategy_id']  ?? '');
            $ctSymbol   = (string)($ct['symbol']        ?? '');
            $ctSignalId = (string)($ct['signal_id']     ?? '');
            $ctClosedAt = (string)($ct['closed_at']     ?? '');
            $ctClosedTs = ($ctClosedAt !== '') ? @strtotime($ctClosedAt) : false;

            if ($ctStratId !== '' && $ctSymbol !== '') {
                $k = $ctStratId . ':' . $ctSymbol;
                $closedByStratSym[$k][] = ($ctClosedTs !== false) ? $ctClosedTs : 0;
            }
            if ($ctSignalId !== '') {
                $closedBySignalId[$ctSignalId] = ($ctClosedTs !== false) ? $ctClosedTs : 0;
            }
        }

        $now = time();

        foreach ($orderQueue as &$qItem) {
            if ((string)($qItem['queue_status'] ?? '') !== 'submitted') {
                continue;
            }
            $submittedTotal++;

            $stratId  = (string)($qItem['strategy_id'] ?? $qItem['owner_strategy'] ?? '');
            $symbol   = (string)($qItem['symbol']       ?? '');
            $signalId = (string)($qItem['signal_id']    ?? '');

            // Best timestamp for age calculation: submitted_at → updated_at → created_at → detected_at
            $ageRefAt = (string)($qItem['submitted_at']  ?? '')
                     ?: (string)($qItem['updated_at']    ?? '')
                     ?: (string)($qItem['created_at']    ?? '')
                     ?: (string)($qItem['detected_at']   ?? '');
            $ageRefTs = ($ageRefAt !== '') ? @strtotime($ageRefAt) : false;

            $expiresAt = (string)($qItem['expires_at'] ?? '');
            $expiresTs = ($expiresAt !== '') ? @strtotime($expiresAt) : false;

            // ── Check active order ──────────────────────────────────────────
            $hasActiveOrder = false;
            if ($stratId !== '' && $signalId !== '') {
                $hasActiveOrder = isset($activeOrderKeys[$stratId . ':' . $signalId]);
            }
            if ($hasActiveOrder) {
                $activeOrderMatched++;
                $stillBlocking++;
                continue;
            }

            // ── Check active position ───────────────────────────────────────
            $hasActivePos = false;
            if ($stratId !== '' && $symbol !== '') {
                $hasActivePos = isset($activePosByStratSym[$stratId . ':' . $symbol]);
            }
            if (!$hasActivePos && $symbol !== '') {
                $hasActivePos = isset($activePosBySym[$symbol]);
            }
            if ($hasActivePos) {
                $activePositionMatched++;
                $stillBlocking++;
                continue;
            }

            // ── No active order/position — check PM close registry ─────────
            $pmRegKey  = $symbol . '_' . (string)($qItem['side'] ?? '');
            $queueMode = (string)($qItem['execution_mode'] ?? '');

            if ($symbol !== '' && isset($pmCloseRegistry[$pmRegKey])) {
                $pmEntry         = $pmCloseRegistry[$pmRegKey];
                $suppressUntil   = (int)($pmEntry['suppress_reentry_until'] ?? 0);
                $pmEntryTs       = (int)($pmEntry['ts'] ?? 0);
                // Legacy compatibility: derive suppress_until from ts + default TTL when field absent.
                if ($suppressUntil === 0 && $pmEntryTs > 0) {
                    $suppressUntil = $pmEntryTs + 3600;
                }
                $pmEntryMode     = (string)($pmEntry['mode'] ?? '');
                $pmEntrySignalId = (string)($pmEntry['signal_id'] ?? '');
                $modeMatches     = $pmEntryMode === '' || $queueMode === '' || $pmEntryMode === $queueMode;
                $sigIdMatches    = $pmEntrySignalId === '' || $signalId === '' || $pmEntrySignalId === $signalId;

                $pmStillActive   = $suppressUntil > $now;

                if ($pmStillActive && $modeMatches && $sigIdMatches) {
                    $qItem['queue_status']          = 'pm_close_reconciled';
                    $qItem['previous_queue_status'] = 'submitted';
                    $qItem['reconciled_at']         = $tickAt;
                    $qItem['last_change_reason']    = 'pm_close_registry_matched';
                    $qItem['close_order_id']        = (string)($pmEntry['close_order_id'] ?? '');
                    $qItem['close_reason']          = (string)($pmEntry['close_reason']   ?? '');
                    $qItem['close_source']          = 'profit_manager';
                    $qItem['lifecycle_note']        = 'Matched PM close registry; terminal — does not block new signals.';
                    $pmCloseReconciledTotal++;
                    continue;
                }
            }

            // ── No active order/position — check if closed ──────────────────
            $matchedClosedTrade = false;
            $submittedTs        = $ageRefTs; // reuse for closed_at >= submitted_at check

            // Strongest: signal_id exact match in closed trades
            if ($signalId !== '' && isset($closedBySignalId[$signalId])) {
                $matchedClosedTrade = true;
            }

            // Fallback: strategy_id + symbol with closed_at >= submitted_at
            if (!$matchedClosedTrade && $stratId !== '' && $symbol !== '') {
                $k = $stratId . ':' . $symbol;
                if (isset($closedByStratSym[$k])) {
                    foreach ($closedByStratSym[$k] as $ctTs) {
                        // Accept if closed_at is after submitted_at, or if no timestamps available
                        if ($submittedTs === false || $ctTs === 0 || $ctTs >= $submittedTs) {
                            $matchedClosedTrade = true;
                            break;
                        }
                    }
                }
            }

            if ($matchedClosedTrade) {
                $closedTradeMatched++;
                $qItem['queue_status']           = 'closed_reconciled';
                $qItem['previous_queue_status']  = 'submitted';
                $qItem['reconciled_at']          = $tickAt;
                $qItem['last_change_reason']     = 'closed_reconciled_matched_closed_trade';
                $qItem['lifecycle_note']         = 'Matched closed trade; terminal — does not block new signals.';
                $reconciledClosed++;
                continue;
            }

            // ── No closed trade match — check signal expires_at or config TTL ──
            $isSignalExpired = $expiresTs !== false && $now > $expiresTs;
            $isTtlExpired    = $ageRefTs !== false && ($now - $ageRefTs) > $ttlWithoutPosSecs;

            if ($isSignalExpired || $isTtlExpired) {
                $reason = $isSignalExpired
                    ? 'submitted_ttl_expired_signal_expires_at'
                    : 'submitted_ttl_expired_no_active_position';
                $qItem['queue_status']           = 'submitted_expired';
                $qItem['previous_queue_status']  = 'submitted';
                $qItem['reconciled_at']          = $tickAt;
                $qItem['last_change_reason']     = $reason;
                $qItem['lifecycle_note']         = 'Expired by TTL with no active order/position; terminal — does not block new signals.';
                $reconciledExpired++;
                continue;
            }

            // ── Within TTL, no evidence yet — conservative hold ─────────────
            if ($ageRefTs !== false) {
                // Timestamp present but within TTL — keep submitted, will be re-checked next tick
                $waitingMatch++;
                $stillBlocking++;
            } else {
                // No usable timestamp — suspicious, count separately
                $staleUnmatched++;
                $stillBlocking++;
            }
        }
        unset($qItem);

        return [
            'order_queue'             => $orderQueue,
            'reconcile_enabled'       => true,
            'submitted_total'         => $submittedTotal,
            'active_position_matched' => $activePositionMatched,
            'active_order_matched'    => $activeOrderMatched,
            'closed_trade_matched'    => $closedTradeMatched,
            'reconciled_closed'       => $reconciledClosed,
            'reconciled_expired'      => $reconciledExpired,
            'pm_close_reconciled'     => $pmCloseReconciledTotal,
            'waiting_match'           => $waitingMatch,
            'stale_unmatched'         => $staleUnmatched,
            'still_blocking'          => $stillBlocking,
        ];
    }

    /**
     * Merge handoff signals from all enabled strategies into the bot order queue.
     *
     * Deduplication key: {strategy_id}:{signal_id}  (composite, cross-strategy safe)
     *
     * Rules:
     *   - new signal              → status = queued
     *   - same signal seen again  → status = ready, seen_count++
     *   - signal gone from handoff, TTL expired  → status = expired
     *   - signal gone from handoff, still valid  → status = withdrawn
     *   - terminal items (expired/withdrawn/…)   → kept unchanged
     *
     * Operator overrides applied per strategy:
     *   - entry_mode (if set and valid)
     *   - bot_budget (if > 0)
     *   - bot_leverage (if > 0)
     *
     * @return array{
     *   order_queue: array,
     *   signals_seen: int,
     *   new_total: int,
     *   refreshed_total: int,
     *   expired_total: int,
     *   withdrawn_total: int
     * }
     */
    private function processHandoff(
        array $handoffSignals,
        array $orderQueue,
        array $config,
        array $overrides,
        string $tickAt,
        string $botMode = 'demo'
    ): array {
        $allowedModes = (array)($config['allowed_entry_modes'] ?? ['limit', 'market']);
        $maxAgeSec    = (int)($config['max_signal_age_sec'] ?? 0);

        // Build queue lookup by composite key
        $queueMap = [];
        foreach ($orderQueue as $item) {
            $key = $this->queueKey($item);
            if ($key !== '') {
                $queueMap[$key] = $item;
            }
        }

        // ── Load PM close registry for re-entry suppression ───────────────────
        $pmCloseRegistry = [];
        try {
            $pmRegPath = $this->moduleDir . '/storage/runtime/pm_close_registry.json';
            if (is_file($pmRegPath)) {
                $pmRegRaw = @file_get_contents($pmRegPath);
                if ($pmRegRaw !== false && $pmRegRaw !== '') {
                    $pmRegDec = @json_decode($pmRegRaw, true);
                    if (is_array($pmRegDec)) {
                        $pmCloseRegistry = $pmRegDec;
                    }
                }
            }
        } catch (\Throwable) {
            // Never fail over registry load
        }

        $newTotal                           = 0;
        $refreshedTotal                     = 0;
        $expiredTotal                       = 0;
        $withdrawnTotal                     = 0;
        $ignoredInvalidPayload              = 0;
        $ignoredInvalidMode                 = 0;
        $ignoredRecentPmCloseTotal          = 0;
        $pmCloseReentrySuppressedTotal      = 0;
        $pmCloseReentrySuppressedExamples   = [];
        $blockedByFreezeTotal               = 0;
        $blockedByManualBlTotal             = 0;
        $blockedByAutoBlTotal               = 0;
        $freezeBlockExamples                = [];
        $blacklistBlockExamples             = [];
        $handoffFreezeBlockExamples         = [];
        $handoffBlacklistBlockExamples      = [];
        $staleHandoffIgnoredTotal                   = 0;
        $handoffBlockedStaleTotal                   = 0;
        $handoffNeedsRevalidationTotal              = 0;
        $staleHandoffBlockExamples                  = [];
        $revalidationRequiredExamples               = [];
        $handoffStrategySignalsSeenTotal            = 0;
        $handoffStrategySignalsUsedTotal            = 0;
        $handoffExecutionModeFromBotTotal           = 0;
        $handoffMissingExecutionModeIgnoredTotal    = 0;
        $handoffBlockedGlobalLiveDisabledTotal      = 0;
        $handoffBlockedGatewayUnavailableTotal      = 0;
        $handoffDeprecatedModeKeysSeenTotal         = 0;
        $handoffDeprecatedModeKeysIgnoredTotal      = 0;
        $result                                     = [];
        $activeKeys                                 = [];

        foreach ($handoffSignals as $signal) {
            $signalId = (string)($signal['signal_id'] ?? '');
            if ($signalId === '') {
                $ignoredInvalidPayload++;
                continue;
            }
            $stratId = (string)($signal['strategy_id'] ?? $signal['owner_strategy'] ?? '');
            $key     = ($stratId !== '' ? $stratId : 'unknown') . ':' . $signalId;

            // Resolve entry_mode: operator override > signal value
            $entryMode   = (string)($signal['entry_mode'] ?? 'limit');
            $opOverrides = (array)($overrides[$stratId] ?? []);
            $opMode      = $opOverrides['entry_mode'] ?? null;
            if ($opMode !== null && in_array($opMode, ['limit', 'market'], true)) {
                $entryMode = $opMode;
            }

            // Entry mode gate
            if (!in_array($entryMode, $allowedModes, true)) {
                $ignoredInvalidMode++;
                continue;
            }

            // Age gate
            if ($maxAgeSec > 0) {
                $detectedTs = strtotime((string)($signal['detected_at'] ?? ''));
                if ($detectedTs !== false && (time() - $detectedTs) > $maxAgeSec) {
                    $staleHandoffIgnoredTotal++;
                    $handoffBlockedStaleTotal++;
                    if (count($staleHandoffBlockExamples) < 5) {
                        $staleHandoffBlockExamples[] = [
                            'symbol'      => (string)($signal['symbol']    ?? ''),
                            'side'        => (string)($signal['side']      ?? ''),
                            'strategy'    => $stratId,
                            'signal_id'   => $signalId,
                            'detected_at' => (string)($signal['detected_at'] ?? ''),
                            'age_minutes' => round((time() - $detectedTs) / 60, 1),
                            'blocked_source' => 'age_gate',
                            'reason'      => 'handoff_blocked_stale_signal',
                            'needs_revalidation_after_unblock' => false,
                        ];
                    }
                    continue;
                }
            }

            // ── Stale-source block: do not refresh items with non-executable source ──
            // When the strategy's handoff queue has already normalized this signal
            // as non-executable (executable=false / handoff_ready=false), the bot
            // must not re-queue or refresh an existing order_queue item from it.
            // Applies regardless of bot-side age gate to ensure queue hygiene.
            $sigSymbol = (string)($signal['symbol'] ?? '');
            $sigSide   = (string)($signal['side']   ?? '');
            // Execution mode is always determined by Bot environment, not strategy signal.
            // Strategy signals are environment-neutral; per-strategy mode fields are deprecated.
            $sigMode   = $botMode;
            $handoffStrategySignalsSeenTotal++;
            $handoffExecutionModeFromBotTotal++;
            // Track deprecated strategy-mode keys for diagnostics (do not block on them)
            if (isset($signal['execution_mode']) || isset($signal['mode'])
                || isset($signal['live_enabled']) || isset($signal['live_forbidden'])
            ) {
                $handoffDeprecatedModeKeysSeenTotal++;
                $handoffDeprecatedModeKeysIgnoredTotal++;
            }
            if (($signal['executable'] ?? null) === false
                || ($signal['handoff_ready'] ?? null) === false
            ) {
                // If an existing queued/ready item exists, preserve it with a diagnostic reason
                if (isset($queueMap[$key])) {
                    $prevBlocked  = $queueMap[$key];
                    $prevStatusBl = (string)($prevBlocked['queue_status'] ?? 'queued');
                    if (in_array($prevStatusBl, ['queued', 'ready'], true)) {
                        $prevBlocked['queue_status']      = 'skipped';
                        $prevBlocked['exit_at']           = $tickAt;
                        $prevBlocked['last_change_reason']= 'source_handoff_stale_or_non_executable';
                        $prevBlocked['handoff_ready']     = false;
                        $prevBlocked['block_reason']      = $signal['block_reason'] ?? $signal['stale_reason'] ?? 'source_handoff_stale_or_non_executable';
                        $result[$key]                     = $prevBlocked;
                        $staleHandoffIgnoredTotal++;
                        $handoffBlockedStaleTotal++;
                        if (count($staleHandoffBlockExamples) < 5) {
                            $detectedTs = strtotime((string)($signal['detected_at'] ?? ''));
                            $staleHandoffBlockExamples[] = [
                                'symbol'      => $sigSymbol,
                                'side'        => $sigSide,
                                'strategy'    => $stratId,
                                'signal_id'   => $signalId,
                                'detected_at' => (string)($signal['detected_at'] ?? ''),
                                'age_minutes' => $detectedTs !== false ? round((time() - $detectedTs) / 60, 1) : null,
                                'blocked_source' => 'source_handoff_non_executable',
                                'reason'      => $signal['block_reason'] ?? $signal['stale_reason'] ?? 'source_handoff_stale_or_non_executable',
                                'needs_revalidation_after_unblock' => (bool)($signal['needs_revalidation_after_unblock'] ?? false),
                            ];
                        }
                    }
                }
                continue;
            }

            // ── PM close re-entry suppression ─────────────────────────────────
            // If a recent PM close registry entry matches this handoff signal by
            // symbol + side + mode + signal_id (when available), suppress it until TTL expires.
            $pmRegKey  = $sigSymbol . '_' . $sigSide;

            if ($sigSymbol !== '' && $sigSide !== '' && isset($pmCloseRegistry[$pmRegKey])) {
                $pmEntry          = $pmCloseRegistry[$pmRegKey];
                $suppressUntil    = (int)($pmEntry['suppress_reentry_until'] ?? 0);
                $pmEntryTs        = (int)($pmEntry['ts'] ?? 0);
                // Legacy compatibility: if suppress_reentry_until absent, derive from ts + default TTL.
                if ($suppressUntil === 0 && $pmEntryTs > 0) {
                    $suppressUntil = $pmEntryTs + 3600;
                }
                $pmEntryMode      = (string)($pmEntry['mode'] ?? '');
                $pmEntrySignalId  = (string)($pmEntry['signal_id'] ?? '');
                $modeMatches      = $pmEntryMode === '' || $pmEntryMode === $sigMode;
                $signalIdMatches  = $pmEntrySignalId === '' || $signalId === '' || $pmEntrySignalId === $signalId;

                if ($suppressUntil > time() && $modeMatches && $signalIdMatches) {
                    $ignoredRecentPmCloseTotal++;
                    $pmCloseReentrySuppressedTotal++;
                    if (count($pmCloseReentrySuppressedExamples) < 5) {
                        $pmCloseReentrySuppressedExamples[] = [
                            'symbol'                 => $sigSymbol,
                            'side'                   => $sigSide,
                            'signal_id'              => $signalId,
                            'close_order_id'         => (string)($pmEntry['close_order_id'] ?? ''),
                            'suppress_reentry_until' => date('c', $suppressUntil),
                        ];
                    }
                    // Do not add to activeKeys so existing queued/ready items will be withdrawn
                    continue;
                }
            }

            // ── Freeze / blacklist gate ───────────────────────────────────────────
            $fbGate = $this->checkFreezeBlacklistGate($sigSymbol, $sigMode, $sigSide, $tickAt, $config);
            if ($fbGate['blocked']) {
                $fbReason = $fbGate['reason'];
                if ($fbReason === 'symbol_frozen_after_close') {
                    $blockedByFreezeTotal++;
                    if (count($freezeBlockExamples) < 5) {
                        $freezeBlockExamples[] = [
                            'symbol'       => $sigSymbol,
                            'side'         => $sigSide,
                            'mode'         => $sigMode,
                            'signal_id'    => $signalId,
                            'frozen_until' => $fbGate['frozen_until'],
                        ];
                    }
                    if (count($handoffFreezeBlockExamples) < 5) {
                        $handoffFreezeBlockExamples[] = [
                            'symbol'        => $sigSymbol,
                            'side'          => $sigSide,
                            'strategy'      => $stratId,
                            'signal_id'     => $signalId,
                            'blocked_until' => $fbGate['frozen_until'],
                            'block_source'  => 'symbol_freeze',
                            'reason'        => $fbReason,
                        ];
                    }
                } else {
                    if ($fbReason === 'symbol_blacklisted_manual') {
                        $blockedByManualBlTotal++;
                    } else {
                        $blockedByAutoBlTotal++;
                    }
                    if (count($blacklistBlockExamples) < 5) {
                        $blacklistBlockExamples[] = [
                            'symbol'        => $sigSymbol,
                            'side'          => $sigSide,
                            'mode'          => $sigMode,
                            'signal_id'     => $signalId,
                            'reason'        => $fbReason,
                            'blocked_until' => $fbGate['blocked_until'],
                        ];
                    }
                    if (count($handoffBlacklistBlockExamples) < 5) {
                        $handoffBlacklistBlockExamples[] = [
                            'symbol'        => $sigSymbol,
                            'side'          => $sigSide,
                            'strategy'      => $stratId,
                            'signal_id'     => $signalId,
                            'blocked_until' => $fbGate['blocked_until'],
                            'block_source'  => 'symbol_blacklist',
                            'reason'        => $fbReason,
                        ];
                    }
                }
                // If existing queued/ready item — mark it skipped + needs revalidation after unblock
                if (isset($queueMap[$key])) {
                    $prevBlocked    = $queueMap[$key];
                    $prevStatusBl   = (string)($prevBlocked['queue_status'] ?? 'queued');
                    if (in_array($prevStatusBl, ['queued', 'ready'], true)) {
                        $prevBlocked['queue_status']                  = 'skipped';
                        $prevBlocked['exit_at']                       = $tickAt;
                        $prevBlocked['last_change_reason']            = $fbReason;
                        $prevBlocked['blocked_by_symbol_guard']       = true;
                        $prevBlocked['symbol_guard_block_source']     = ($fbReason === 'symbol_frozen_after_close') ? 'freeze' : 'blacklist';
                        $prevBlocked['blocked_at']                    = $tickAt;
                        $prevBlocked['needs_revalidation_after_unblock'] = true;
                        $prevBlocked['handoff_ready']                 = false;
                        if ($fbGate['frozen_until'] !== null) {
                            $prevBlocked['frozen_until'] = $fbGate['frozen_until'];
                        }
                        if ($fbGate['blocked_until'] !== null) {
                            $prevBlocked['blocked_until'] = $fbGate['blocked_until'];
                        }
                        $result[$key] = $prevBlocked;
                        $handoffNeedsRevalidationTotal++;
                        if (count($revalidationRequiredExamples) < 5) {
                            $revalidationRequiredExamples[] = [
                                'symbol'      => $sigSymbol,
                                'side'        => $sigSide,
                                'strategy'    => $stratId,
                                'signal_id'   => $signalId,
                                'detected_at' => (string)($signal['detected_at'] ?? ''),
                                'blocked_source' => $prevBlocked['symbol_guard_block_source'],
                                'reason'      => 'handoff_blocked_needs_revalidation_after_symbol_block',
                                'needs_revalidation_after_unblock' => true,
                            ];
                        }
                    }
                }
                continue;
            }

            $handoffStrategySignalsUsedTotal++;
            $activeKeys[$key] = true;

            if (isset($queueMap[$key])) {
                $prev       = $queueMap[$key];
                $prevStatus = (string)($prev['queue_status'] ?? 'queued');
                // backward-compat: items created before execution_mode field assume current mode
                $prevMode   = (string)($prev['execution_mode'] ?? $botMode);

                // If an existing submitted item belongs to a different mode, do not treat it
                // as a blocking duplicate — create a fresh queue item for the current mode.
                $isModeSwitchedTerminal = ($prevStatus === 'submitted' && $prevMode !== $sigMode);

                // Terminal/reconciled statuses must not block new valid future signals.
                // closed_reconciled and submitted_expired are lifecycle-terminal; if the signal
                // is still present in the handoff queue on the next tick, treat it as new.
                $isLifecycleTerminal = in_array($prevStatus, [
                    'closed_reconciled', 'pm_close_reconciled', 'submitted_expired',
                    'expired', 'withdrawn', 'rejected', 'failed', 'skipped', 'cancelled', 'closed',
                ], true);

                if (in_array($prevStatus, ['queued', 'ready'], true) || $isModeSwitchedTerminal || $isLifecycleTerminal) {
                    $item = $this->buildQueueItem($signal, $opOverrides, $config, $tickAt, $sigMode);
                    $item['entry_mode'] = $entryMode;

                    if ($isModeSwitchedTerminal || $isLifecycleTerminal) {
                        // Fresh item; start back at queued (lifecycle terminal cleared)
                        $reason = $isModeSwitchedTerminal
                            ? 'new_from_handoff_after_mode_switch'
                            : 'new_from_handoff_after_terminal_lifecycle';
                        $item['queue_status']          = 'queued';
                        $item['first_queued_at']       = $tickAt;
                        $item['seen_count']            = 1;
                        $item['last_refreshed_at']     = $tickAt;
                        $item['last_change_reason']    = $reason;
                        $item['source_handoff_status'] = (string)($signal['handoff_status'] ?? 'new');
                        $result[$key]                  = $item;
                        $newTotal++;
                    } else {
                        $item['queue_status']          = 'ready';
                        $item['first_queued_at']       = $prev['first_queued_at'] ?? $tickAt;
                        $item['seen_count']            = (int)($prev['seen_count'] ?? 1) + 1;
                        $item['last_refreshed_at']     = $tickAt;
                        $item['last_change_reason']    = 'refreshed_from_handoff';
                        $item['source_handoff_status'] = (string)($signal['handoff_status'] ?? 'refreshed');
                        $result[$key]                  = $item;
                        $refreshedTotal++;
                    }
                } else {
                    $result[$key] = $prev;
                }
            } else {
                $item = $this->buildQueueItem($signal, $opOverrides, $config, $tickAt, $sigMode);
                $item['entry_mode']            = $entryMode;
                $item['queue_status']          = 'queued';
                $item['first_queued_at']       = $tickAt;
                $item['seen_count']            = 1;
                $item['last_refreshed_at']     = $tickAt;
                $item['last_change_reason']    = 'new_from_handoff';
                $item['source_handoff_status'] = (string)($signal['handoff_status'] ?? 'new');
                $result[$key]                  = $item;
                $newTotal++;
            }
        }

        // Handle queue items no longer present in any enabled handoff
        foreach ($queueMap as $key => $prev) {
            if (isset($result[$key])) {
                continue;
            }
            $prevStatus = (string)($prev['queue_status'] ?? 'queued');
            if (in_array($prevStatus, [
                'expired', 'withdrawn', 'submitted', 'closed_reconciled', 'pm_close_reconciled',
                'submitted_expired', 'active_order', 'active_position', 'rejected',
            ], true)) {
                $result[$key] = $prev;
                continue;
            }
            $expiresAt = $prev['expires_at'] ?? '';
            $isExpired = $expiresAt !== ''
                && strtotime($expiresAt) !== false
                && time() > strtotime($expiresAt);
            $prev['queue_status']       = $isExpired ? 'expired' : 'withdrawn';
            $prev['exit_at']            = $tickAt;
            $prev['last_change_reason'] = $isExpired ? 'expired_by_ttl' : 'withdrawn_missing_from_handoff';
            $result[$key]               = $prev;
            if ($isExpired) {
                $expiredTotal++;
            } else {
                $withdrawnTotal++;
            }
        }

        return [
            'order_queue'                          => array_values($result),
            'signals_seen'                         => count($handoffSignals),
            'new_total'                            => $newTotal,
            'refreshed_total'                      => $refreshedTotal,
            'expired_total'                        => $expiredTotal,
            'withdrawn_total'                      => $withdrawnTotal,
            'ignored_invalid_signal_payload'       => $ignoredInvalidPayload,
            'ignored_invalid_entry_mode'           => $ignoredInvalidMode,
            'ignored_recent_pm_close_total'        => $ignoredRecentPmCloseTotal,
            'pm_close_reentry_suppressed_total'    => $pmCloseReentrySuppressedTotal,
            'pm_close_reentry_suppressed_examples' => $pmCloseReentrySuppressedExamples,
            'blocked_by_freeze_total'              => $blockedByFreezeTotal,
            'blocked_by_manual_blacklist_total'    => $blockedByManualBlTotal,
            'blocked_by_auto_blacklist_total'      => $blockedByAutoBlTotal,
            'freeze_block_examples'                => $freezeBlockExamples,
            'blacklist_block_examples'             => $blacklistBlockExamples,
            // Handoff-specific freeze/blacklist diagnostic counters (Task 4)
            'handoff_blocked_symbol_freeze_total'      => $blockedByFreezeTotal,
            'handoff_blocked_symbol_blacklist_total'   => $blockedByManualBlTotal + $blockedByAutoBlTotal,
            'handoff_blocked_symbol_freeze_examples'   => $handoffFreezeBlockExamples,
            'handoff_blocked_symbol_blacklist_examples'=> $handoffBlacklistBlockExamples,
            // Handoff freshness/lifecycle diagnostics (Task 4 new)
            'handoff_blocked_stale_signal_total'                        => $handoffBlockedStaleTotal,
            'stale_handoff_ignored_total'                               => $staleHandoffIgnoredTotal,
            'handoff_blocked_needs_revalidation_after_symbol_block_total' => $handoffNeedsRevalidationTotal,
            'stale_handoff_block_examples'                              => $staleHandoffBlockExamples,
            'revalidation_required_examples'                            => $revalidationRequiredExamples,
            // ── Execution mode neutral diagnostics ───────────────────────────────
            'handoff_strategy_signals_seen_total'            => $handoffStrategySignalsSeenTotal,
            'handoff_strategy_signals_used_total'            => $handoffStrategySignalsUsedTotal,
            'handoff_execution_mode_from_bot_total'          => $handoffExecutionModeFromBotTotal,
            'handoff_missing_execution_mode_ignored_total'   => $handoffMissingExecutionModeIgnoredTotal,
            'handoff_blocked_global_live_disabled_total'     => $handoffBlockedGlobalLiveDisabledTotal,
            'handoff_blocked_gateway_unavailable_total'      => $handoffBlockedGatewayUnavailableTotal,
            'deprecated_strategy_mode_keys_seen_total'       => $handoffDeprecatedModeKeysSeenTotal,
            'deprecated_strategy_mode_keys_ignored_total'    => $handoffDeprecatedModeKeysIgnoredTotal,
        ];
    }

    // =========================================================================
    // Execution state machine
    // =========================================================================

    /**
     * Process execution for the current tick.
     *
     * Modes:
     *   demo     → real execution on Bybit Demo account; submit market orders, sync positions from Bybit Demo.
     *   disabled → skip execution; count skipped ready items as mode_disabled.
     *   passive  → skip execution; count skipped ready items as mode_passive.
     *   paper    → deterministic local execution in storage/state; no real exchange.
     *   smoke    → legacy alias for paper.
     *   active   → legacy alias for paper.
     *
     * Demo tick flow:
     *   queue ready  → check symbol on Bybit Demo (dedup)
     *                → submit market order to Bybit Demo
     *                → sync all Bybit Demo positions into active_positions.json
     *
     * Paper tick flow (all in one pass):
     *   queue ready  → create order (created)
     *                → submit order (submitted_paper)
     *                → fill order   (filled_paper)
     *                → open position (open)
     *
     * Position close conditions checked each tick (paper mode):
     *   expired_by_signal_ttl — position expires_at has passed
     *
     * @return array{
     *   order_queue: array,
     *   active_orders: array,
     *   active_positions: array,
     *   closed_positions: array,
     *   orders_created: int,
     *   orders_submitted_paper: int,
     *   orders_filled_paper: int,
     *   orders_submitted_demo: int,
     *   orders_confirmed_demo: int,
     *   positions_opened: int,
     *   positions_closed: int,
     *   positions_closed_expired: int,
     *   positions_closed_withdrawn: int,
     *   positions_closed_reverse_pattern: int,
     *   queue_items_skipped_mode_disabled: int,
     *   queue_items_skipped_mode_passive: int,
     *   execution_log_events: int,
     * }
     */
    private function processExecution(
        array $orderQueue,
        array $activeOrders,
        array $activePositions,
        array $closedPositions,
        string $mode,
        array $config,
        string $tickAt
    ): array {
        $ordersCreated                  = 0;
        $ordersSubmittedPaper           = 0;
        $ordersFilledPaper              = 0;
        $ordersSubmittedDemo            = 0;
        $ordersConfirmedDemo            = 0;
        $positionsOpened                = 0;
        $positionsClosed                = 0;
        $positionsClosedExpired         = 0;
        $positionsClosedWithdrawn       = 0;
        $positionsClosedReversePattern  = 0;
        $queueSkippedModeDisabled       = 0;
        $logEvents                      = 0;

        // demo mode: real execution on Bybit Demo account
        $isDemoMode = ($mode === 'demo');
        // live mode: real execution on Bybit Live account via KeyCenter
        $isLiveMode = ($mode === 'live');

        // ── Demo mode execution path ──────────────────────────────────────────
        if ($isDemoMode) {
            $demoResult = $this->processDemoExecution(
                $orderQueue, $activeOrders, $activePositions, $closedPositions, $config, $tickAt
            );
            return array_merge($demoResult, [
                'orders_submitted_paper'           => 0,
                'orders_filled_paper'              => 0,
                'positions_closed_expired'         => $demoResult['positions_closed_expired'] ?? 0,
                'positions_closed_withdrawn'       => $demoResult['positions_closed_withdrawn'] ?? 0,
                'positions_closed_reverse_pattern' => 0,
                'queue_items_skipped_mode_disabled'=> 0,
                'queue_items_skipped_mode_passive' => 0,
            ]);
        }

        // ── Live mode execution path ──────────────────────────────────────────
        if ($isLiveMode) {
            $liveResult = $this->processLiveExecution(
                $orderQueue, $activeOrders, $activePositions, $closedPositions, $config, $tickAt
            );
            return array_merge($liveResult, [
                'orders_submitted_paper'           => 0,
                'orders_filled_paper'              => 0,
                'positions_closed_expired'         => $liveResult['positions_closed_expired'] ?? 0,
                'positions_closed_withdrawn'       => $liveResult['positions_closed_withdrawn'] ?? 0,
                'positions_closed_reverse_pattern' => 0,
                'queue_items_skipped_mode_disabled'=> 0,
                'queue_items_skipped_mode_passive' => 0,
            ]);
        }

        // Build order lookup by composite key {strategy_id}:{signal_id}
        $orderMap = [];
        foreach ($activeOrders as $order) {
            $k = $this->executionKey($order);
            if ($k !== '') {
                $orderMap[$k] = $order;
            }
        }

        // Build position lookup by composite key
        $positionMap = [];
        foreach ($activePositions as $pos) {
            $k = $this->executionKey($pos);
            if ($k !== '') {
                $positionMap[$k] = $pos;
            }
        }

        // ── Step 1: promote ready queue items ────────────────────────────────
        foreach ($orderQueue as &$qItem) {
            $qStatus = (string)($qItem['queue_status'] ?? '');
            if ($qStatus !== 'ready') {
                continue;
            }

            $key = $this->executionKey($qItem);
            if ($key === '') {
                continue;
            }

            // Unknown mode (not demo or live): skip item
            $queueSkippedModeDisabled++;
            $qItem['skip_reason'] = 'ignored_unknown_mode';
            continue;
        }
        unset($qItem);

        return [
            'order_queue'                      => array_values($orderQueue),
            'active_orders'                    => array_values($orderMap),
            'active_positions'                 => array_values($positionMap),
            'closed_positions'                 => $closedPositions,
            'orders_created'                   => $ordersCreated,
            'orders_submitted_paper'           => 0,
            'orders_filled_paper'              => 0,
            'positions_opened'                 => $positionsOpened,
            'positions_closed'                 => $positionsClosed,
            'positions_closed_expired'         => $positionsClosedExpired,
            'positions_closed_withdrawn'       => $positionsClosedWithdrawn,
            'positions_closed_reverse_pattern' => $positionsClosedReversePattern,
            'queue_items_skipped_mode_disabled' => $queueSkippedModeDisabled,
            'queue_items_skipped_mode_passive'  => 0,
            'execution_log_events'             => $logEvents,
            'orders_submitted_demo'            => 0,
            'orders_confirmed_demo'            => 0,
        ];
    }

    /**
     * Build a bot-owned active order record from a ready queue item.
     */
    private function buildOrderFromQueueItem(array $qItem, string $tickAt): array
    {
        return [
            // Ownership — carried forward unchanged from queue item
            'owner_strategy' => (string)($qItem['owner_strategy'] ?? ''),
            'strategy_id'    => (string)($qItem['strategy_id']    ?? ''),
            'signal_id'      => (string)($qItem['signal_id']      ?? ''),

            // Signal geometry
            'symbol'      => (string)($qItem['symbol']      ?? ''),
            'side'        => (string)($qItem['side']        ?? 'long'),
            'timeframe'   => (string)($qItem['timeframe']   ?? 'H4'),
            'entry_mode'  => (string)($qItem['entry_mode']  ?? 'limit'),
            'entry_type'  => (string)($qItem['entry_type']  ?? 'breakout'),
            'entry_price' => (float)($qItem['entry_price']  ?? 0.0),

            // Execution parameters
            'bot_budget'                    => (float)($qItem['bot_budget']                    ?? 0.0),
            'bot_leverage'                  => (int)($qItem['bot_leverage']                    ?? 1),
            'budget_source'                 => (string)($qItem['budget_source']                ?? 'unknown'),
            'leverage_source'               => (string)($qItem['leverage_source']              ?? 'unknown'),
            'stop_mode'                     => (string)($qItem['stop_mode']                    ?? 'fixed_from_liq_zone'),
            'stop_from_liq_buffer_value'    => (float)($qItem['stop_from_liq_buffer_value']   ?? 0.002),
            'stop_from_liq_buffer_type'     => (string)($qItem['stop_from_liq_buffer_type']   ?? 'percent'),
            'tp_enabled'                    => (bool)($qItem['tp_enabled']                    ?? false),
            'tp_mode'                       => (string)($qItem['tp_mode']                     ?? 'fixed_r'),
            'tp_value'                      => (float)($qItem['tp_value']                     ?? 2.0),
            'reverse_pattern_close_enabled' => (bool)($qItem['reverse_pattern_close_enabled'] ?? false),

            // Order lifecycle
            'order_status'      => 'created',
            'execution_mode'    => 'demo',
            'transition_reason' => 'created_from_ready_queue',
            'expires_at'        => (string)($qItem['expires_at'] ?? ''),
            'created_at'        => $tickAt,
            'last_updated_at'   => $tickAt,
        ];
    }

    /**
     * Build a bot-owned active position record from a filled Bybit Demo position response.
     *
     * Called in demo mode after syncing positions from Bybit Demo.
     * Merges queue item context (strategy_id, signal_id, budget, leverage) with
     * live position data (entry_price, mark_price, unrealised_pnl, liq_price).
     *
     * @param array $bybitPos  Single position from Bybit Demo /v5/position/list
     * @param array $qItem     Matching queue item (for strategy context); may be empty
     * @param string $tickAt   Current tick timestamp
     */
    private function buildPositionFromDemoData(array $bybitPos, array $qItem, string $tickAt): array
    {
        $symbol     = (string)($bybitPos['symbol']        ?? $qItem['symbol'] ?? '');
        $bybitSide  = (string)($bybitPos['side']          ?? 'Buy');
        $side       = (strtolower($bybitSide) === 'sell') ? 'short' : 'long';
        $entryPrice = (float)($bybitPos['avgPrice']       ?? $bybitPos['entryPrice'] ?? $qItem['entry_price'] ?? 0.0);
        $markPrice  = (float)($bybitPos['markPrice']      ?? 0.0);
        $leverage   = (int)($bybitPos['leverage']         ?? $qItem['bot_leverage'] ?? 5);
        if ($leverage <= 0) {
            $leverage = 5;
        }
        $budget     = (float)($qItem['bot_budget']        ?? 0.0);
        if ($budget <= 0.0) {
            $budget = 6.0;
        }
        $size       = (float)($bybitPos['size']           ?? 0.0);
        $unrealisedPnl = (float)($bybitPos['unrealisedPnl'] ?? 0.0);
        $liqPrice   = (float)($bybitPos['liqPrice']       ?? 0.0);

        // Opened-at with diagnostics
        $rawCreatedTime     = $bybitPos['createdTime'] ?? null;
        $rawCreatedTimeUnit = 'unknown';
        $openedAtSource     = 'local_cache';
        $openedAt           = $tickAt;

        if ($rawCreatedTime !== null && is_numeric($rawCreatedTime) && (int)$rawCreatedTime > 0) {
            $ct = (int)$rawCreatedTime;
            if ($ct > 1_000_000_000_000) {
                // milliseconds
                $rawCreatedTimeUnit = 'ms';
                $openedAt           = date('c', (int)($ct / 1000));
                $openedAtSource     = 'bybit_createdTime_ms';
            } elseif ($ct > 1_000_000_000) {
                // seconds
                $rawCreatedTimeUnit = 'sec';
                $openedAt           = date('c', $ct);
                $openedAtSource     = 'bybit_createdTime_sec';
            }
            // else: value too small to be a valid Unix timestamp, fall back to local
        }

        // Exchange-sourced opened_at (may be very old on Bybit demo)
        $exchangeOpenedAt       = $openedAt;
        $exchangeOpenedAtSource = $openedAtSource;

        // Bot timestamps from queue item — more reliable for duration display
        $botConfirmedAt      = (string)($qItem['confirmed_at'] ?? '');
        $botSubmittedAt      = (string)($qItem['submitted_at'] ?? '');
        $botSignalDetectedAt = (string)($qItem['detected_at']  ?? '');

        // Prefer bot_confirmed_at > bot_submitted_at > exchange_opened_at for display
        $openedAtDisplaySource = $exchangeOpenedAtSource;
        if ($botConfirmedAt !== '') {
            $openedAt              = $botConfirmedAt;
            $openedAtDisplaySource = 'bot_confirmed_at';
        } elseif ($botSubmittedAt !== '') {
            $openedAt              = $botSubmittedAt;
            $openedAtDisplaySource = 'bot_submitted_at';
        }

        $synced_at   = $tickAt;
        $durationSec = (int)(time() - @strtotime($openedAt));
        if ($durationSec < 0) {
            $durationSec = 0;
        }

        return [
            // Ownership — from queue item when available
            'owner_strategy'  => (string)($qItem['owner_strategy'] ?? ''),
            'strategy_id'     => (string)($qItem['strategy_id']    ?? ''),
            'signal_id'       => (string)($qItem['signal_id']      ?? ''),

            // Signal geometry
            'symbol'      => $symbol,
            'side'        => $side,
            'timeframe'   => (string)($qItem['timeframe']  ?? 'H4'),
            'entry_mode'  => (string)($qItem['entry_mode'] ?? 'market'),
            'entry_type'  => (string)($qItem['entry_type'] ?? 'breakout'),
            'entry_price' => $entryPrice,

            // Execution parameters
            'bot_budget'     => $budget,
            'bot_leverage'   => $leverage,
            'budget'         => $budget,
            'leverage'       => (float)$leverage,
            'size'           => $size,
            'budget_source'  => (string)($qItem['budget_source']   ?? 'config'),
            'leverage_source'=> (string)($qItem['leverage_source'] ?? 'config'),

            // Live price / PnL from Bybit Demo
            'mark_price'     => $markPrice > 0.0 ? $markPrice : null,
            'current_price'  => $markPrice > 0.0 ? $markPrice : null,
            'unrealised_pnl' => $unrealisedPnl,
            'liq_price'      => $liqPrice > 0.0 ? $liqPrice : null,

            // Position lifecycle
            'position_status'   => 'open',
            'execution_mode'    => 'demo',
            'mode'              => 'demo',
            'account'           => 'bybit_demo',
            'transition_reason' => 'synced_from_bybit_demo',
            'opened_at'                 => $openedAt,
            'opened_at_source'          => $openedAtSource,
            'opened_at_display_source'  => $openedAtDisplaySource,
            'exchange_opened_at'        => $exchangeOpenedAt !== '' ? $exchangeOpenedAt : null,
            'exchange_opened_at_source' => $exchangeOpenedAtSource,
            'bot_submitted_at'          => $botSubmittedAt      !== '' ? $botSubmittedAt      : null,
            'bot_confirmed_at'          => $botConfirmedAt      !== '' ? $botConfirmedAt      : null,
            'bot_signal_detected_at'    => $botSignalDetectedAt !== '' ? $botSignalDetectedAt : null,
            'raw_created_time'          => $rawCreatedTime,
            'raw_created_time_unit'     => $rawCreatedTimeUnit,
            'synced_at'                 => $synced_at,
            'duration_sec'              => $durationSec,
            'entered_at'                => $openedAt,
            'last_updated_at'           => $tickAt,

            // ── Signal trace attribution (Task 3) ────────────────────────────────
            'pattern_algorithm'       => (string)($qItem['pattern_algorithm']        ?? ''),
            'setup_class'             => $qItem['setup_class']                       ?? null,
            'strategy_signal_context' => $qItem['strategy_signal_context']           ?? null,
        ];
    }

    /**
     * Fetch symbol instrument info from Bybit Demo (market endpoint, no auth).
     *
     * Returns leverage and lot-size constraints for the given linear symbol.
     * Returns an empty array on error or when symbol is not found.
     *
     * @return array{
     *   bybit_max_leverage: int|null,
     *   min_order_qty: float|null,
     *   qty_step: float|null,
     *   max_order_qty: float|null,
     *   min_notional_value: float|null
     * }
     */
    private function fetchSymbolInstrumentInfo(\Core\Gateway\Bybit $gw, string $symbol): array
    {
        try {
            $resp = $gw->request('/v5/market/instruments-info', [
                'category' => 'linear',
                'symbol'   => $symbol,
            ], false);

            if (!($resp['success'] ?? false) || ($resp['ret_code'] ?? -1) !== 0) {
                return [];
            }

            $list = $resp['result']['list'] ?? [];
            if (!is_array($list) || empty($list)) {
                return [];
            }

            $info           = $list[0];
            $leverageFilter = (array)($info['leverageFilter'] ?? []);
            $lotSizeFilter  = (array)($info['lotSizeFilter']  ?? []);

            $maxLeverage = (float)($leverageFilter['maxLeverage'] ?? 0.0);
            $minOrderQty = (float)($lotSizeFilter['minOrderQty']  ?? 0.0);
            $qtyStep     = (float)($lotSizeFilter['qtyStep']      ?? 0.0);
            $maxOrderQty = (float)($lotSizeFilter['maxOrderQty']  ?? 0.0);
            $minNotional = (float)($info['minNotionalValue']      ?? 0.0);

            return [
                'bybit_max_leverage'  => $maxLeverage > 0.0 ? (int)floor($maxLeverage) : null,
                'min_order_qty'       => $minOrderQty > 0.0 ? $minOrderQty : null,
                'qty_step'            => $qtyStep     > 0.0 ? $qtyStep     : null,
                'max_order_qty'       => $maxOrderQty > 0.0 ? $maxOrderQty : null,
                'min_notional_value'  => $minNotional > 0.0 ? $minNotional : null,
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Set isolated leverage for a symbol on Bybit Demo (old-bot algorithm).
     *
     * Algorithm:
     *  1. Pre-clamp requested leverage to $metaMax if known.
     *  2. Call /v5/position/set-leverage.
     *  3. Treat retCode 0 and 110043 (or "not modified" in retMsg) as success.
     *  4. If Bybit rejects with a max-leverage error, parse the max from retMsg
     *     and retry once with the parsed value.
     *
     * Never logs API credentials.
     *
     * @param int|null $metaMax  Bybit maxLeverage from instruments-info (may be null)
     * @return array{
     *   ok: bool,
     *   requested: int,
     *   effective: int,
     *   meta_max: int|null,
     *   parsed_max: int|null,
     *   note: string,
     *   ret_code: int,
     *   ret_msg: string
     * }
     */
    private function setDemoLeverage(
        \Core\Gateway\Bybit $gw,
        string $symbol,
        int $requested,
        ?int $metaMax = null
    ): array {
        // Pre-clamp to known Bybit max
        $effective = $requested;
        if ($metaMax !== null && $metaMax > 0 && $requested > $metaMax) {
            $effective = $metaMax;
        }

        $attempt = function(int $lev) use ($gw, $symbol): array {
            try {
                return $gw->request('/v5/position/set-leverage', [
                    'category'     => 'linear',
                    'symbol'       => $symbol,
                    'buyLeverage'  => (string)$lev,
                    'sellLeverage' => (string)$lev,
                ], true);
            } catch (\Throwable $ex) {
                return ['ret_code' => -1, 'ret_msg' => $ex->getMessage(), 'success' => false];
            }
        };

        $isOk = static function(int $retCode, string $retMsg): bool {
            return $retCode === 0
                || $retCode === 110043
                || stripos($retMsg, 'not modified') !== false
                || stripos($retMsg, 'not been modified') !== false;
        };

        $resp    = $attempt($effective);
        $retCode = (int)($resp['ret_code'] ?? -1);
        $retMsg  = (string)($resp['ret_msg'] ?? '');

        if ($isOk($retCode, $retMsg)) {
            $note = ($retCode === 110043
                     || stripos($retMsg, 'not modified') !== false
                     || stripos($retMsg, 'not been modified') !== false)
                ? 'already_set'
                : 'set_ok';
            return [
                'ok'         => true,
                'requested'  => $requested,
                'effective'  => $effective,
                'meta_max'   => $metaMax,
                'parsed_max' => null,
                'note'       => $note,
                'ret_code'   => $retCode,
                'ret_msg'    => $retMsg,
            ];
        }

        // Try to parse a smaller max-leverage value from the error message and retry
        $parsedMax = $this->parseMaxLeverageFromMsg($retMsg);
        if ($parsedMax !== null && $parsedMax > 0 && $parsedMax < $effective) {
            $resp2    = $attempt($parsedMax);
            $retCode2 = (int)($resp2['ret_code'] ?? -1);
            $retMsg2  = (string)($resp2['ret_msg'] ?? '');
            $ok2      = $isOk($retCode2, $retMsg2);
            return [
                'ok'         => $ok2,
                'requested'  => $requested,
                'effective'  => $ok2 ? $parsedMax : $effective,
                'meta_max'   => $metaMax,
                'parsed_max' => $parsedMax,
                'note'       => $ok2 ? 'set_ok_after_parse_retry' : 'retry_failed',
                'ret_code'   => $retCode2,
                'ret_msg'    => $retMsg2,
            ];
        }

        return [
            'ok'         => false,
            'requested'  => $requested,
            'effective'  => $effective,
            'meta_max'   => $metaMax,
            'parsed_max' => null,
            'note'       => 'rejected',
            'ret_code'   => $retCode,
            'ret_msg'    => $retMsg,
        ];
    }

    /**
     * Attempt to parse the maximum leverage from a Bybit error message.
     *
     * Handles messages like:
     *  "The maximum leverage is 75"
     *  "Leverage should not exceed 75"
     *  "Cross Margin/Isolated Margin leverage ... maximum is 75"
     */
    private function parseMaxLeverageFromMsg(string $msg): ?int
    {
        if (preg_match(
            '/(?:maximum\s+leverage\s+(?:is\s+)?|leverage\s+(?:should\s+not\s+exceed|cannot\s+exceed|exceeds?)\s+)(\d+)/i',
            $msg,
            $m
        )) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * Resolve execution budget from config → signal priority chain.
     *
     * Priority:
     *  1. config max_bot_budget if > 0
     *  2. config budget_per_trade if > 0
     *  3. qItem bot_budget if > 0
     *  4. fallback 6.0
     *
     * @return array{budget: float, source: string}
     */
    private function resolveExecutionBudget(array $qItem, array $config): array
    {
        $maxBotBudget   = (float)($config['max_bot_budget']   ?? 0.0);
        $budgetPerTrade = (float)($config['budget_per_trade'] ?? 0.0);
        $qItemBudget    = (float)($qItem['bot_budget']        ?? 0.0);

        if ($maxBotBudget > 0.0) {
            return ['budget' => $maxBotBudget, 'source' => 'config.max_bot_budget'];
        }
        if ($budgetPerTrade > 0.0) {
            return ['budget' => $budgetPerTrade, 'source' => 'config.budget_per_trade'];
        }
        if ($qItemBudget > 0.0) {
            return ['budget' => $qItemBudget, 'source' => 'signal.bot_budget'];
        }
        return ['budget' => 6.0, 'source' => 'fallback'];
    }

    /**
     * Resolve execution leverage from config → signal priority chain.
     *
     * Priority:
     *  1. config max_bot_leverage if > 0
     *  2. config leverage if > 0
     *  3. qItem bot_leverage ONLY if > 1 (value=1 treated as "not set")
     *  4. fallback 5
     *
     * @return array{leverage: int, source: string}
     */
    private function resolveExecutionLeverage(array $qItem, array $config): array
    {
        $maxBotLeverage = (int)($config['max_bot_leverage'] ?? 0);
        $configLeverage = (int)($config['leverage']         ?? 0);
        $qItemLeverage  = (int)($qItem['bot_leverage']      ?? 0);

        if ($maxBotLeverage > 0) {
            return ['leverage' => $maxBotLeverage, 'source' => 'config.max_bot_leverage'];
        }
        if ($configLeverage > 0) {
            return ['leverage' => $configLeverage, 'source' => 'config.leverage'];
        }
        // bot_leverage=1 is treated as unset/default placeholder, not as intentional 1x
        if ($qItemLeverage > 1) {
            return ['leverage' => $qItemLeverage, 'source' => 'signal.bot_leverage'];
        }
        return ['leverage' => 5, 'source' => 'fallback'];
    }

    /**
     * Resolve max active positions from config.
     *
     * Priority:
     *  1. config default_max_active_positions if > 0
     *  2. config max_active_positions if > 0
     *  3. fallback 10
     */
    private function resolveMaxActivePositions(array $config): int
    {
        $defaultMax = (int)($config['default_max_active_positions'] ?? 0);
        $maxActive  = (int)($config['max_active_positions']         ?? 0);

        if ($defaultMax > 0) {
            return $defaultMax;
        }
        if ($maxActive > 0) {
            return $maxActive;
        }
        return 10;
    }

    /**
     * Compute "test reality" diagnostics from closed_trades.json and active_positions.
     *
     * Reads closed trades (realized) and current active positions (unrealized).
     * Never modifies any storage. Returns all test reality metrics as an array.
     *
     * @param array $activePositions Current in-memory active positions
     * @return array
     */
    private function computeTestRealityMetrics(array $activePositions): array
    {
        $closedTrades = $this->readJson('storage/trades/closed_trades.json', []);

        // ── Closed trade stats ────────────────────────────────────────────────
        $closedTotal       = 0;
        $closedWins        = 0;
        $closedLosses      = 0;
        $realizedPnlTotal  = 0.0;
        $closedRoiSum      = 0.0;
        $closedRoiCount    = 0;
        $byStrategy        = [];

        foreach ($closedTrades as $trade) {
            $closedTotal++;
            $roi  = isset($trade['roi'])  ? (float)$trade['roi']  : null;
            $pnl  = isset($trade['pnl'])  ? (float)$trade['pnl']  : null;
            $sid  = (string)($trade['strategy_id'] ?? $trade['owner_strategy'] ?? 'unknown');

            if ($pnl !== null) {
                $realizedPnlTotal += $pnl;
            }
            if ($roi !== null) {
                $closedRoiSum += $roi;
                $closedRoiCount++;
                if ($roi >= 0.0) {
                    $closedWins++;
                } else {
                    $closedLosses++;
                }
            }

            if (!isset($byStrategy[$sid])) {
                $byStrategy[$sid] = [
                    'closed_trades_total'  => 0,
                    'open_positions_total' => 0,
                    'realized_pnl_total'   => 0.0,
                    'unrealized_pnl_total' => 0.0,
                    'closed_roi_sum'       => 0.0,
                    'closed_roi_count'     => 0,
                    'closed_wins'          => 0,
                    'open_roi_sum'         => 0.0,
                    'open_roi_count'       => 0,
                ];
            }
            $byStrategy[$sid]['closed_trades_total']++;
            if ($pnl !== null) {
                $byStrategy[$sid]['realized_pnl_total'] += $pnl;
            }
            if ($roi !== null) {
                $byStrategy[$sid]['closed_roi_sum']   += $roi;
                $byStrategy[$sid]['closed_roi_count']++;
                if ($roi >= 0.0) {
                    $byStrategy[$sid]['closed_wins']++;
                }
            }
        }

        $closedWinrate  = ($closedTotal > 0 && $closedRoiCount > 0) ? round($closedWins / $closedRoiCount, 4) : null;
        $avgClosedRoi   = ($closedRoiCount > 0) ? round($closedRoiSum / $closedRoiCount, 4) : null;

        // ── Active position (open/unrealized) stats ───────────────────────────
        $openTotal            = count($activePositions);
        $unrealizedPnlTotal   = 0.0;
        $openInLoss           = 0;
        $openInProfit         = 0;
        $unknownUnrealized    = 0;
        $openRoiSum           = 0.0;
        $openRoiCount         = 0;
        $worstOpenRoi         = null;
        $bestOpenRoi          = null;

        foreach ($activePositions as $pos) {
            $sid         = (string)($pos['strategy_id'] ?? $pos['owner_strategy'] ?? 'unknown');
            $upnl        = isset($pos['unrealised_pnl']) ? (float)$pos['unrealised_pnl'] : null;
            $entryPrice  = (float)($pos['entry_price']  ?? 0.0);
            $markPrice   = (float)($pos['mark_price']   ?? $pos['current_price'] ?? 0.0);
            $leverage    = max(1, (int)($pos['bot_leverage'] ?? $pos['leverage'] ?? 1));
            $side        = (string)($pos['side'] ?? 'long');

            // Compute open ROI if we have entry/mark prices
            $openRoi = null;
            if ($entryPrice > 0.0 && $markPrice > 0.0) {
                if ($side === 'short') {
                    $openRoi = ($entryPrice - $markPrice) / $entryPrice * $leverage * 100.0;
                } else {
                    $openRoi = ($markPrice - $entryPrice) / $entryPrice * $leverage * 100.0;
                }
                $openRoi = round($openRoi, 4);
            }

            if ($upnl !== null) {
                $unrealizedPnlTotal += $upnl;
                if ($upnl < 0.0) {
                    $openInLoss++;
                } else {
                    $openInProfit++;
                }
            } elseif ($openRoi !== null) {
                // Can infer direction from ROI
                if ($openRoi < 0.0) {
                    $openInLoss++;
                } else {
                    $openInProfit++;
                }
            } else {
                $unknownUnrealized++;
            }

            if ($openRoi !== null) {
                $openRoiSum += $openRoi;
                $openRoiCount++;
                if ($worstOpenRoi === null || $openRoi < $worstOpenRoi) {
                    $worstOpenRoi = $openRoi;
                }
                if ($bestOpenRoi === null || $openRoi > $bestOpenRoi) {
                    $bestOpenRoi = $openRoi;
                }
            }

            if (!isset($byStrategy[$sid])) {
                $byStrategy[$sid] = [
                    'closed_trades_total'  => 0,
                    'open_positions_total' => 0,
                    'realized_pnl_total'   => 0.0,
                    'unrealized_pnl_total' => 0.0,
                    'closed_roi_sum'       => 0.0,
                    'closed_roi_count'     => 0,
                    'closed_wins'          => 0,
                    'open_roi_sum'         => 0.0,
                    'open_roi_count'       => 0,
                ];
            }
            $byStrategy[$sid]['open_positions_total']++;
            if ($upnl !== null) {
                $byStrategy[$sid]['unrealized_pnl_total'] += $upnl;
            }
            if ($openRoi !== null) {
                $byStrategy[$sid]['open_roi_sum']   += $openRoi;
                $byStrategy[$sid]['open_roi_count']++;
            }
        }

        $avgOpenRoi       = ($openRoiCount > 0)  ? round($openRoiSum / $openRoiCount, 4)    : null;
        $netPnlIfClosed   = round($realizedPnlTotal + $unrealizedPnlTotal, 6);

        // ── Build per-strategy summary ────────────────────────────────────────
        $testRealityByStrategy = [];
        foreach ($byStrategy as $sid => $s) {
            $sClosed   = (int)$s['closed_trades_total'];
            $sWr       = ($s['closed_roi_count'] > 0) ? round($s['closed_wins'] / $s['closed_roi_count'], 4) : null;
            $sAvgClosed= ($s['closed_roi_count'] > 0) ? round($s['closed_roi_sum'] / $s['closed_roi_count'], 4) : null;
            $sAvgOpen  = ($s['open_roi_count']   > 0) ? round($s['open_roi_sum']   / $s['open_roi_count'],   4) : null;
            $testRealityByStrategy[$sid] = [
                'closed_trades_total'  => $sClosed,
                'open_positions_total' => (int)$s['open_positions_total'],
                'realized_pnl_total'   => round($s['realized_pnl_total'],   6),
                'unrealized_pnl_total' => round($s['unrealized_pnl_total'],  6),
                'net_pnl_if_closed_now'=> round($s['realized_pnl_total'] + $s['unrealized_pnl_total'], 6),
                'closed_winrate'       => $sWr,
                'avg_closed_roi'       => $sAvgClosed,
                'avg_open_roi'         => $sAvgOpen,
            ];
        }

        return [
            'test_reality_closed_trades_total'          => $closedTotal,
            'test_reality_open_positions_total'         => $openTotal,
            'test_reality_realized_pnl_total'           => round($realizedPnlTotal,  6),
            'test_reality_unrealized_pnl_total'         => round($unrealizedPnlTotal, 6),
            'test_reality_net_pnl_if_closed_now'        => $netPnlIfClosed,
            'test_reality_closed_winrate'               => $closedWinrate,
            'test_reality_avg_closed_roi'               => $avgClosedRoi,
            'test_reality_avg_open_roi'                 => $avgOpenRoi,
            'test_reality_worst_open_roi'               => $worstOpenRoi,
            'test_reality_best_open_roi'                => $bestOpenRoi,
            'test_reality_open_positions_in_loss_total' => $openInLoss,
            'test_reality_open_positions_in_profit_total' => $openInProfit,
            'test_reality_open_unrealized_unknown_total'=> $unknownUnrealized,
            'test_reality_by_strategy'                  => $testRealityByStrategy,
        ];
    }

    /**
     * Get a Bybit gateway client configured for Bybit Demo account.
     *
     * Uses demo_api_key / demo_api_secret / demo_api_base_url from bot config.
     * Returns null when credentials are not set.
     */
    private function getDemoGateway(array $config): ?\Core\Gateway\Bybit
    {
        $apiKey    = (string)($config['demo_api_key']     ?? '');
        $apiSecret = (string)($config['demo_api_secret']  ?? '');
        $baseUrl   = (string)($config['demo_api_base_url']?? 'https://api-demo.bybit.com');

        if ($apiKey === '' || $apiSecret === '') {
            return null;
        }

        try {
            $gw = \Core\Gateway\Bybit::client('bybit_demo');
            $gw->setCredentials($apiKey, $apiSecret);
            $gw->setBaseUrl($baseUrl);
            return $gw;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get a Bybit gateway client for live trading via KeyCenter.
     *
     * Uses account_id from bot config. KeyCenter handles credential injection.
     * Never uses demo credentials, never falls back to demo.
     * Returns null when account_id is not configured or client creation fails.
     */
    private function getLiveGateway(array $config): ?\Core\Gateway\Bybit
    {
        $accountId = trim((string)($config['account_id'] ?? ''));
        if ($accountId === '') {
            return null;
        }

        try {
            return \Core\Gateway\Bybit::client($accountId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fetch all open linear USDT-settled positions from Bybit Demo.
     *
     * Returns a list of raw Bybit position records (from result.list).
     * Returns an empty array on error.
     */
    private function fetchDemoPositions(\Core\Gateway\Bybit $gw): array
    {
        try {
            $resp = $gw->request('/v5/position/list', [
                'category'   => 'linear',
                'settleCoin' => 'USDT',
                'limit'      => 200,
            ], true);

            if (!($resp['success'] ?? false) || ($resp['ret_code'] ?? -1) !== 0) {
                return [];
            }

            $list = $resp['result']['list'] ?? [];
            if (!is_array($list)) {
                return [];
            }

            // Only include positions with non-zero size
            $open = [];
            foreach ($list as $pos) {
                if (is_array($pos) && (float)($pos['size'] ?? 0) > 0) {
                    $open[] = $pos;
                }
            }
            return $open;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Demo mode execution:
     *   1. Fetch current open positions from Bybit Demo.
     *   2. For each ready queue item, check dedup by symbol, submit market order.
     *   3. Re-sync positions from Bybit Demo after submitting.
     *   4. Build active_positions cache from Bybit Demo data.
     */
    private function processDemoExecution(
        array $orderQueue,
        array $activeOrders,
        array $activePositions,
        array $closedPositions,
        array $config,
        string $tickAt
    ): array {
        $ordersCreated       = 0;
        $ordersSubmittedDemo = 0;
        $ordersConfirmedDemo = 0;
        $positionsOpened     = 0;
        $positionsClosed     = 0;
        $positionsClosedExp  = 0;
        $positionsClosedWith = 0;
        $logEvents           = 0;

        // Demo execution diagnostics
        $demoOrdersPrepared       = 0;
        $demoOrdersRejected       = 0;
        // Funnel blocking counters
        $blockedByActivePosition  = 0;
        $blockedByMaxSlots        = 0;
        $demoLeverageClampedCount = 0;
        $demoSetLevFailedCount    = 0;
        $demoQtyInvalidCount      = 0;
        $demoLastErrorCode        = null;
        $demoLastErrorMsg         = null;
        $demoLastRejectedSymbol   = null;
        // Last-tick leverage diagnostics (updated per submitted order)
        $demoLastReqLeverage      = null;
        $demoLastEffLeverage      = null;
        $demoLastLeverageSrc      = null;
        $demoLastBudgetSrc        = null;
        $demoLastSetLevNote       = null;
        $demoLastSetLevCode       = null;
        $demoLastSetLevMsg        = null;
        $demoLeverageMismatchCount= 0;

        $gw = $this->getDemoGateway($config);

        // Tracks effective leverage per symbol for post-submit verify (Part 5)
        $submittedEffective = [];

        // No credentials → skip all ready items, keep positions cache unchanged
        if ($gw === null) {
            foreach ($orderQueue as &$qItem) {
                if (($qItem['queue_status'] ?? '') === 'ready') {
                    $qItem['skip_reason'] = 'demo_credentials_missing';
                }
            }
            unset($qItem);

            return [
                'order_queue'        => array_values($orderQueue),
                'active_orders'      => $activeOrders,
                'active_positions'   => $activePositions,
                'closed_positions'   => $closedPositions,
                'orders_created'     => 0,
                'orders_submitted_demo' => 0,
                'orders_confirmed_demo' => 0,
                'positions_opened'   => 0,
                'positions_closed'   => 0,
                'positions_closed_expired'   => 0,
                'positions_closed_withdrawn' => 0,
                'execution_log_events' => 0,
                'funnel_blocked_by_active_position' => 0,
                'funnel_blocked_by_max_slots'       => 0,
            ];
        }

        // Fetch current positions from Bybit Demo
        $demoPositions = $this->fetchDemoPositions($gw);

        // Build symbol → Bybit position map for dedup
        $symbolMap = [];
        foreach ($demoPositions as $pos) {
            $sym = (string)($pos['symbol'] ?? '');
            if ($sym !== '') {
                $symbolMap[$sym] = $pos;
            }
        }

        // Build queue key → queue item map for signal context lookup
        $queueMap = [];
        foreach ($orderQueue as $q) {
            $k = $this->queueKey($q);
            if ($k !== '') {
                $queueMap[$k] = $q;
            }
        }

        $maxPos = $this->resolveMaxActivePositions($config);

        // Process ready queue items
        foreach ($orderQueue as &$qItem) {
            if (($qItem['queue_status'] ?? '') !== 'ready') {
                continue;
            }

            // Only process items stamped for demo mode (or legacy items without execution_mode)
            $itemMode = $qItem['execution_mode'] ?? null;
            if ($itemMode !== null && $itemMode !== 'demo') {
                $qItem['skip_reason'] = 'wrong_execution_mode';
                continue;
            }

            $key    = $this->queueKey($qItem);
            $symbol = (string)($qItem['symbol'] ?? '');
            $side   = (string)($qItem['side']   ?? 'long');

            if ($symbol === '') {
                continue;
            }

            // Skip if symbol already open on Bybit Demo
            if (isset($symbolMap[$symbol])) {
                $qItem['skip_reason'] = 'symbol_already_active_on_demo';
                $blockedByActivePosition++;
                continue;
            }

            // Skip if max positions reached
            if ($maxPos > 0 && count($symbolMap) >= $maxPos) {
                $qItem['skip_reason'] = 'max_active_positions_reached';
                $blockedByMaxSlots++;
                continue;
            }

            $entryPrice = (float)($qItem['entry_price'] ?? 0.0);

            if ($entryPrice <= 0.0) {
                $qItem['skip_reason'] = 'invalid_entry_price_or_budget';
                $demoOrdersRejected++;
                continue;
            }

            // ── Resolve execution budget & leverage from config (Part 1 / Part 2) ──
            $budgetResolved   = $this->resolveExecutionBudget($qItem, $config);
            $leverageResolved = $this->resolveExecutionLeverage($qItem, $config);

            $budget            = $budgetResolved['budget'];
            $budgetSource      = $budgetResolved['source'];
            $requestedLeverage = $leverageResolved['leverage'];
            $leverageSource    = $leverageResolved['source'];

            // Overwrite queue item so logs reflect actual execution values
            $qItem['bot_budget']     = $budget;
            $qItem['bot_leverage']   = $requestedLeverage;
            $qItem['budget_source']  = $budgetSource;
            $qItem['leverage_source']= $leverageSource;

            $demoOrdersPrepared++;

            // ── Step 1: Fetch symbol instrument info ──────────────────────────
            $symbolInfo  = $this->fetchSymbolInstrumentInfo($gw, $symbol);
            $bybitMaxLev = $symbolInfo['bybit_max_leverage'] ?? null;
            $minOrderQty = $symbolInfo['min_order_qty']      ?? null;
            $qtyStep     = $symbolInfo['qty_step']           ?? null;
            $maxOrderQty = $symbolInfo['max_order_qty']      ?? null;
            $minNotional = $symbolInfo['min_notional_value'] ?? null;

            // Store instrument meta in queue item
            $qItem['requested_leverage']  = $requestedLeverage;
            $qItem['bybit_max_leverage']  = $bybitMaxLev;
            $qItem['qty_step']            = $qtyStep;
            $qItem['min_order_qty']       = $minOrderQty;
            $qItem['min_notional_value']  = $minNotional;

            // ── Step 2: Set leverage on Bybit Demo (old-bot algorithm) ─────────
            // setDemoLeverage clamps, calls API, retries if Bybit rejects with max
            $levResult = $this->setDemoLeverage($gw, $symbol, $requestedLeverage, $bybitMaxLev);

            $effectiveLeverage   = $levResult['effective'];
            $leverageWasClamped  = ($levResult['effective'] !== $levResult['requested']);

            if ($leverageWasClamped) {
                $demoLeverageClampedCount++;
            }

            $qItem['set_leverage_attempted'] = true;
            $qItem['set_leverage_ok']        = $levResult['ok'];
            $qItem['set_leverage_ret_code']  = $levResult['ret_code'];
            $qItem['set_leverage_ret_msg']   = $levResult['ret_msg'];
            $qItem['set_leverage_note']      = $levResult['note'];
            $qItem['effective_leverage']     = $effectiveLeverage;
            $qItem['leverage_was_clamped']   = $leverageWasClamped;

            // Track for dashboard last-tick display
            $demoLastReqLeverage  = $requestedLeverage;
            $demoLastEffLeverage  = $effectiveLeverage;
            $demoLastLeverageSrc  = $leverageSource;
            $demoLastBudgetSrc    = $budgetSource;
            $demoLastSetLevNote   = $levResult['note'];
            $demoLastSetLevCode   = $levResult['ret_code'];
            $demoLastSetLevMsg    = $levResult['ret_msg'];

            if (!$levResult['ok']) {
                $qItem['skip_reason']     = 'set_leverage_failed';
                $qItem['demo_error_code'] = $levResult['ret_code'];
                $qItem['demo_error_msg']  = $levResult['ret_msg'];
                $demoSetLevFailedCount++;
                $demoOrdersRejected++;
                $demoLastErrorCode      = $levResult['ret_code'];
                $demoLastErrorMsg       = $levResult['ret_msg'];
                $demoLastRejectedSymbol = $symbol;
                continue;
            }

            // ── Step 3: Calculate qty using effective leverage (Part 4) ────────
            $rawQty = ($budget * $effectiveLeverage) / $entryPrice;
            $qItem['raw_qty'] = $rawQty;

            // ── Step 4: Normalize qty ─────────────────────────────────────────
            $normalizedQty = $rawQty;
            if ($qtyStep !== null && $qtyStep > 0.0) {
                // Determine decimal places from qtyStep (e.g. 0.001 → 3 places)
                $decPlaces     = max(0, (int)ceil(-log10($qtyStep)));
                $normalizedQty = floor($rawQty / $qtyStep) * $qtyStep;
                $normalizedQty = round($normalizedQty, $decPlaces);
            }
            $qItem['normalized_qty'] = $normalizedQty;

            // Validate normalized qty
            if ($normalizedQty <= 0.0) {
                $qItem['skip_reason'] = 'qty_invalid_after_normalization';
                $demoQtyInvalidCount++;
                $demoOrdersRejected++;
                $demoLastRejectedSymbol = $symbol;
                continue;
            }
            if ($minOrderQty !== null && $normalizedQty < $minOrderQty) {
                $qItem['skip_reason'] = 'qty_below_min_order_qty';
                $demoQtyInvalidCount++;
                $demoOrdersRejected++;
                $demoLastRejectedSymbol = $symbol;
                continue;
            }
            if ($maxOrderQty !== null && $maxOrderQty > 0.0 && $normalizedQty > $maxOrderQty) {
                $qItem['skip_reason'] = 'qty_above_max_order_qty';
                $demoQtyInvalidCount++;
                $demoOrdersRejected++;
                $demoLastRejectedSymbol = $symbol;
                continue;
            }
            if ($minNotional !== null && $minNotional > 0.0
                && ($normalizedQty * $entryPrice) < $minNotional
            ) {
                $qItem['skip_reason'] = 'qty_below_min_notional';
                $demoQtyInvalidCount++;
                $demoOrdersRejected++;
                $demoLastRejectedSymbol = $symbol;
                continue;
            }

            // Format qty string without trailing zeros
            $qtyStr    = rtrim(rtrim(number_format($normalizedQty, 8, '.', ''), '0'), '.');
            $bybitSide = ($side === 'short') ? 'Sell' : 'Buy';

            try {
                $orderResp = $gw->request('/v5/order/create', [
                    'category'    => 'linear',
                    'symbol'      => $symbol,
                    'side'        => $bybitSide,
                    'orderType'   => 'Market',
                    'qty'         => $qtyStr,
                    'timeInForce' => 'IOC',
                    'positionIdx' => 0,
                ], true);
            } catch (\Throwable) {
                $qItem['skip_reason']     = 'demo_submit_exception';
                $demoOrdersRejected++;
                $demoLastRejectedSymbol   = $symbol;
                continue;
            }

            $ordersCreated++;

            if (($orderResp['success'] ?? false) && ($orderResp['ret_code'] ?? -1) === 0) {
                $ordersSubmittedDemo++;
                $qItem['queue_status']       = 'submitted';
                $qItem['submitted_at']       = $tickAt;
                $qItem['last_change_reason'] = 'submitted_to_demo';
                $qItem['demo_order_id']      = $orderResp['result']['orderId'] ?? null;

                // Optimistically add to symbolMap so subsequent items see correct count
                $symbolMap[$symbol] = ['symbol' => $symbol, 'size' => $normalizedQty, '_pending' => true];
                $positionsOpened++;

                // Track effective leverage for post-submit verify (Part 5)
                $submittedEffective[$symbol] = $effectiveLeverage;

                $this->appendExecutionLog([
                    'timestamp'             => $tickAt,
                    'event_type'            => 'demo_order_submitted',
                    'strategy_id'           => $qItem['strategy_id'] ?? '',
                    'owner_strategy'        => $qItem['owner_strategy'] ?? '',
                    'signal_id'             => $qItem['signal_id'] ?? '',
                    'symbol'                => $symbol,
                    'side'                  => $side,
                    'entry_price'           => $entryPrice,
                    'budget'                => $budget,
                    'budget_source'         => $budgetSource,
                    'requested_leverage'    => $requestedLeverage,
                    'leverage_source'       => $leverageSource,
                    'meta_max_leverage'     => $bybitMaxLev,
                    'effective_leverage'    => $effectiveLeverage,
                    'set_leverage_note'     => $levResult['note'],
                    'set_leverage_ret_code' => $levResult['ret_code'],
                    'set_leverage_ret_msg'  => $levResult['ret_msg'],
                    'leverage_was_clamped'  => $leverageWasClamped,
                    'raw_qty'               => $rawQty,
                    'normalized_qty'        => $normalizedQty,
                    'qty_step'              => $qtyStep,
                    'min_order_qty'         => $minOrderQty,
                    'min_notional_value'    => $minNotional,
                    'order_type'            => 'Market',
                    'execution_mode'        => 'demo',
                    'bybit_side'            => $bybitSide,
                    'demo_order_id'         => $qItem['demo_order_id'],
                    'reason'                => 'submitted_market_order_to_bybit_demo',
                ]);
                $logEvents++;
            } else {
                $retCode = $orderResp['ret_code'] ?? null;
                $retMsg  = $orderResp['ret_msg']  ?? null;
                $qItem['skip_reason']     = 'demo_order_rejected';
                $qItem['demo_error_code'] = $retCode;
                $qItem['demo_error_msg']  = $retMsg;
                $demoOrdersRejected++;
                $demoLastErrorCode      = $retCode;
                $demoLastErrorMsg       = $retMsg;
                $demoLastRejectedSymbol = $symbol;
            }
        }
        unset($qItem);

        // Re-sync positions from Bybit Demo after submitting orders
        $freshDemoPositions = $this->fetchDemoPositions($gw);

        // Build a queue-item context map: symbol → first matching queue item
        $symbolQueueContext = [];
        foreach ($orderQueue as $q) {
            $sym = (string)($q['symbol'] ?? '');
            if ($sym !== '' && !isset($symbolQueueContext[$sym])) {
                $symbolQueueContext[$sym] = $q;
            }
        }

        // Build new active_positions from Bybit Demo data
        $newActivePositions = [];
        foreach ($freshDemoPositions as $pos) {
            $sym     = (string)($pos['symbol'] ?? '');
            $qCtx    = $symbolQueueContext[$sym] ?? [];
            $record  = $this->buildPositionFromDemoData($pos, $qCtx, $tickAt);

            // Part 5: verify Bybit-reported leverage matches what we set
            $bybitPosLev  = (int)($pos['leverage'] ?? 0);
            $expectedLev  = $submittedEffective[$sym] ?? null;
            if ($expectedLev !== null && $bybitPosLev > 0 && $bybitPosLev !== $expectedLev) {
                $record['leverage_warning']                      = 'leverage_not_applied_on_exchange';
                $record['requested_leverage']                    = $expectedLev;
                $record['bybit_position_leverage_after_submit']  = $bybitPosLev;
                $demoLeverageMismatchCount++;
                $this->appendExecutionLog([
                    'timestamp'                             => $tickAt,
                    'event_type'                            => 'demo_leverage_mismatch',
                    'symbol'                                => $sym,
                    'expected_leverage'                     => $expectedLev,
                    'bybit_position_leverage_after_submit'  => $bybitPosLev,
                    'reason'                                => 'leverage_not_applied_on_exchange',
                ]);
                $logEvents++;
            }

            $newActivePositions[] = $record;
            $ordersConfirmedDemo++;
        }

        // Detect positions that were in cache but are no longer open on Bybit Demo
        $freshSymbols = array_flip(array_map(
            fn($p) => (string)($p['symbol'] ?? ''),
            $freshDemoPositions
        ));
        foreach ($activePositions as $pos) {
            $sym = (string)($pos['symbol'] ?? '');
            if ($sym !== '' && !isset($freshSymbols[$sym]) && ($pos['execution_mode'] ?? '') === 'demo') {
                // Position gone from Bybit Demo — move to closed
                $pos['position_status']           = 'closed';
                $pos['closed_at']                 = $tickAt;
                $pos['close_reason']              = 'position_gone_from_bybit_demo';
                $pos['close_source']              = 'exchange_disappeared';
                $pos['close_source_confidence']   = 'inferred';
                $pos['closed_at_source']          = 'detected_missing_from_exchange';
                $pos['closed_at_is_estimated']    = true;
                $pos['last_updated_at']           = $tickAt;
                $pos['mode']                      = 'demo';
                $closedPositions[]                = $pos;
                $positionsClosed++;
                $this->recordClosedTrade($pos, $tickAt);
            }
        }

        return [
            'order_queue'                      => array_values($orderQueue),
            'active_orders'                    => $activeOrders,
            'active_positions'                 => array_values($newActivePositions),
            'closed_positions'                 => $closedPositions,
            'orders_created'                   => $ordersCreated,
            'orders_submitted_demo'            => $ordersSubmittedDemo,
            'orders_confirmed_demo'            => $ordersConfirmedDemo,
            'positions_opened'                 => $positionsOpened,
            'positions_closed'                 => $positionsClosed,
            'positions_closed_expired'         => $positionsClosedExp,
            'positions_closed_withdrawn'       => $positionsClosedWith,
            'execution_log_events'             => $logEvents,
            // Demo execution diagnostics
            'demo_orders_prepared'             => $demoOrdersPrepared,
            'demo_orders_rejected'             => $demoOrdersRejected,
            'demo_leverage_clamped_count'      => $demoLeverageClampedCount,
            'demo_set_leverage_failed_count'   => $demoSetLevFailedCount,
            'demo_qty_invalid_count'           => $demoQtyInvalidCount,
            'demo_last_error_code'             => $demoLastErrorCode,
            'demo_last_error_msg'              => $demoLastErrorMsg,
            'demo_last_rejected_symbol'        => $demoLastRejectedSymbol,
            // Per-tick leverage diagnostics (last order attempt)
            'demo_last_req_leverage'           => $demoLastReqLeverage,
            'demo_last_eff_leverage'           => $demoLastEffLeverage,
            'demo_last_leverage_source'        => $demoLastLeverageSrc,
            'demo_last_budget_source'          => $demoLastBudgetSrc,
            'demo_last_set_lev_note'           => $demoLastSetLevNote,
            'demo_last_set_lev_code'           => $demoLastSetLevCode,
            'demo_last_set_lev_msg'            => $demoLastSetLevMsg,
            'demo_leverage_mismatch_count'     => $demoLeverageMismatchCount,
            // Funnel blocking counters
            'funnel_blocked_by_active_position'=> $blockedByActivePosition,
            'funnel_blocked_by_max_slots'      => $blockedByMaxSlots,
        ];
    }

    /**
     * Live mode execution: real orders on Bybit Live via KeyCenter.
     *
     * Safety guards (executed before ANY order submission):
     *   1. live_enabled flag must be true in config
     *   2. account_id must exist in config
     *   3. KeyCenter must return a valid live gateway
     *   4. Lightweight API test call must succeed
     *
     * No fallback to demo. No fallback from live to demo.
     * Mirrors processDemoExecution() in structure; no logic change to stop/math.
     */
    private function processLiveExecution(
        array $orderQueue,
        array $activeOrders,
        array $activePositions,
        array $closedPositions,
        array $config,
        string $tickAt
    ): array {
        $ordersCreated       = 0;
        $ordersSubmittedLive = 0;
        $ordersConfirmedLive = 0;
        $positionsOpened     = 0;
        $positionsClosed     = 0;
        $positionsClosedExp  = 0;
        $positionsClosedWith = 0;
        $logEvents           = 0;

        // Live execution diagnostics
        $liveOrdersPrepared       = 0;
        $liveOrdersRejected       = 0;
        $liveLeverageClampedCount = 0;
        $liveSetLevFailedCount    = 0;
        $liveQtyInvalidCount      = 0;
        $liveLastErrorCode        = null;
        $liveLastErrorMsg         = null;
        $liveLastRejectedSymbol   = null;
        $liveLastReqLeverage      = null;
        $liveLastEffLeverage      = null;
        $liveLastLeverageSrc      = null;
        $liveLastBudgetSrc        = null;
        $liveLastSetLevNote       = null;
        $liveLastSetLevCode       = null;
        $liveLastSetLevMsg        = null;
        $liveLeverageMismatchCount= 0;

        $skipAllReason = null;

        // ── Safety guard 1: live_enabled flag ─────────────────────────────────
        if (!(bool)($config['live_enabled'] ?? false)) {
            $skipAllReason = 'live_not_enabled';
        }

        // ── Safety guard 2: account_id ────────────────────────────────────────
        $accountId = trim((string)($config['account_id'] ?? ''));
        if ($skipAllReason === null && $accountId === '') {
            $skipAllReason = 'live_not_ready';
        }

        // ── Safety guard 3 + 4: KeyCenter gateway + API test ──────────────────
        $gw = null;
        if ($skipAllReason === null) {
            $gw = $this->getLiveGateway($config);
            if ($gw === null) {
                $skipAllReason = 'live_not_ready';
            } else {
                // Lightweight API test
                try {
                    $testResp = $gw->request('/v5/account/wallet-balance', [
                        'accountType' => 'UNIFIED',
                    ], true);
                    $testOk = ($testResp['success'] ?? false) && ($testResp['ret_code'] ?? -1) === 0;
                    if (!$testOk) {
                        $testCode    = $testResp['ret_code'] ?? -1;
                        $skipAllReason = in_array($testCode, [10003, 10004], true)
                            ? 'live_keycenter_credentials_missing'
                            : 'live_not_ready';
                        $gw = null;
                    }
                } catch (\Throwable) {
                    $skipAllReason = 'live_not_ready';
                    $gw = null;
                }
            }
        }

        // ── If any guard failed: skip all ready queue items ────────────────────
        if ($gw === null) {
            // Map internal skip reason to the normalised stage name for the journal.
            $guardSkipStage = match ($skipAllReason ?? '') {
                'live_not_enabled', 'live_keycenter_credentials_missing' => 'live_account_not_ready',
                default => 'live_gateway_unavailable',
            };
            foreach ($orderQueue as &$qItem) {
                if (($qItem['queue_status'] ?? '') === 'ready') {
                    $qItem['skip_reason'] = $skipAllReason ?? 'live_not_ready';
                    $this->recordLiveSkippedSignal($qItem, $guardSkipStage, $config, $tickAt);
                }
            }
            unset($qItem);

            return [
                'order_queue'            => array_values($orderQueue),
                'active_orders'          => $activeOrders,
                'active_positions'       => $activePositions,
                'closed_positions'       => $closedPositions,
                'orders_created'         => 0,
                'orders_submitted_demo'  => 0,
                'orders_confirmed_demo'  => 0,
                'positions_opened'       => 0,
                'positions_closed'       => 0,
                'positions_closed_expired'   => 0,
                'positions_closed_withdrawn' => 0,
                'execution_log_events'   => 0,
                'live_orders_prepared'   => 0,
                'live_orders_rejected'   => 0,
                'live_skip_reason'       => $skipAllReason,
            ];
        }

        // Fetch current positions from Bybit Live
        $livePositions = $this->fetchDemoPositions($gw); // same API endpoint, different gateway

        // Build symbol → Bybit position map for dedup
        $symbolMap = [];
        foreach ($livePositions as $pos) {
            $sym = (string)($pos['symbol'] ?? '');
            if ($sym !== '') {
                $symbolMap[$sym] = $pos;
            }
        }

        // Build queue key → queue item map
        $queueMap = [];
        foreach ($orderQueue as $q) {
            $k = $this->queueKey($q);
            if ($k !== '') {
                $queueMap[$k] = $q;
            }
        }

        $maxPos = $this->resolveMaxActivePositions($config);
        $submittedEffective = [];

        // Process ready queue items
        foreach ($orderQueue as &$qItem) {
            if (($qItem['queue_status'] ?? '') !== 'ready') {
                continue;
            }

            // Only process items stamped for live mode (or legacy items without execution_mode)
            $itemMode = $qItem['execution_mode'] ?? null;
            if ($itemMode !== null && $itemMode !== 'live') {
                $qItem['skip_reason'] = 'wrong_execution_mode';
                continue;
            }

            $key    = $this->queueKey($qItem);
            $symbol = (string)($qItem['symbol'] ?? '');
            $side   = (string)($qItem['side']   ?? 'long');

            if ($symbol === '') {
                continue;
            }

            if (isset($symbolMap[$symbol])) {
                $qItem['skip_reason'] = 'symbol_already_active_on_live';
                $this->recordLiveSkippedSignal($qItem, 'active_position_exists', $config, $tickAt, $symbolMap);
                continue;
            }

            if ($maxPos > 0 && count($symbolMap) >= $maxPos) {
                $qItem['skip_reason'] = 'max_active_positions_reached';
                $this->recordLiveSkippedSignal($qItem, 'max_active_positions_reached', $config, $tickAt, $symbolMap);
                continue;
            }

            $entryPrice = (float)($qItem['entry_price'] ?? 0.0);
            if ($entryPrice <= 0.0) {
                $qItem['skip_reason'] = 'invalid_entry_price_or_budget';
                $liveOrdersRejected++;
                $this->recordLiveSkippedSignal($qItem, 'local_qty_validation_failed', $config, $tickAt, $symbolMap);
                continue;
            }

            $budgetResolved   = $this->resolveExecutionBudget($qItem, $config);
            $leverageResolved = $this->resolveExecutionLeverage($qItem, $config);

            $budget            = $budgetResolved['budget'];
            $budgetSource      = $budgetResolved['source'];
            $requestedLeverage = $leverageResolved['leverage'];
            $leverageSource    = $leverageResolved['source'];

            $qItem['bot_budget']      = $budget;
            $qItem['bot_leverage']    = $requestedLeverage;
            $qItem['budget_source']   = $budgetSource;
            $qItem['leverage_source'] = $leverageSource;

            $liveOrdersPrepared++;

            $symbolInfo  = $this->fetchSymbolInstrumentInfo($gw, $symbol);
            $bybitMaxLev = $symbolInfo['bybit_max_leverage'] ?? null;
            $minOrderQty = $symbolInfo['min_order_qty']      ?? null;
            $qtyStep     = $symbolInfo['qty_step']           ?? null;
            $maxOrderQty = $symbolInfo['max_order_qty']      ?? null;
            $minNotional = $symbolInfo['min_notional_value'] ?? null;

            $qItem['requested_leverage'] = $requestedLeverage;
            $qItem['bybit_max_leverage'] = $bybitMaxLev;
            $qItem['qty_step']           = $qtyStep;
            $qItem['min_order_qty']      = $minOrderQty;
            $qItem['min_notional_value'] = $minNotional;

            $levResult = $this->setDemoLeverage($gw, $symbol, $requestedLeverage, $bybitMaxLev);

            $effectiveLeverage  = $levResult['effective'];
            $leverageWasClamped = ($levResult['effective'] !== $levResult['requested']);
            if ($leverageWasClamped) {
                $liveLeverageClampedCount++;
            }

            $qItem['set_leverage_attempted'] = true;
            $qItem['set_leverage_ok']        = $levResult['ok'];
            $qItem['set_leverage_ret_code']  = $levResult['ret_code'];
            $qItem['set_leverage_ret_msg']   = $levResult['ret_msg'];
            $qItem['set_leverage_note']      = $levResult['note'];
            $qItem['effective_leverage']     = $effectiveLeverage;
            $qItem['leverage_was_clamped']   = $leverageWasClamped;

            $liveLastReqLeverage = $requestedLeverage;
            $liveLastEffLeverage = $effectiveLeverage;
            $liveLastLeverageSrc = $leverageSource;
            $liveLastBudgetSrc   = $budgetSource;
            $liveLastSetLevNote  = $levResult['note'];
            $liveLastSetLevCode  = $levResult['ret_code'];
            $liveLastSetLevMsg   = $levResult['ret_msg'];

            if (!$levResult['ok']) {
                $qItem['skip_reason']     = 'set_leverage_failed';
                $qItem['live_error_code'] = $levResult['ret_code'];
                $qItem['live_error_msg']  = $levResult['ret_msg'];
                $liveSetLevFailedCount++;
                $liveOrdersRejected++;
                $liveLastErrorCode      = $levResult['ret_code'];
                $liveLastErrorMsg       = $levResult['ret_msg'];
                $liveLastRejectedSymbol = $symbol;
                $this->recordLiveSkippedSignal($qItem, 'bybit_leverage_error', $config, $tickAt, $symbolMap);
                continue;
            }

            $rawQty        = ($budget * $effectiveLeverage) / $entryPrice;
            $qItem['raw_qty'] = $rawQty;

            $normalizedQty = $rawQty;
            if ($qtyStep !== null && $qtyStep > 0.0) {
                $decPlaces     = max(0, (int)ceil(-log10($qtyStep)));
                $normalizedQty = floor($rawQty / $qtyStep) * $qtyStep;
                $normalizedQty = round($normalizedQty, $decPlaces);
            }
            $qItem['normalized_qty'] = $normalizedQty;

            if ($normalizedQty <= 0.0) {
                $qItem['skip_reason'] = 'qty_invalid_after_normalization';
                $liveQtyInvalidCount++;
                $liveOrdersRejected++;
                $liveLastRejectedSymbol = $symbol;
                $this->recordLiveSkippedSignal($qItem, 'bybit_qty_invalid', $config, $tickAt, $symbolMap);
                continue;
            }
            if ($minOrderQty !== null && $normalizedQty < $minOrderQty) {
                $qItem['skip_reason'] = 'qty_below_min_order_qty';
                $liveQtyInvalidCount++;
                $liveOrdersRejected++;
                $liveLastRejectedSymbol = $symbol;
                $this->recordLiveSkippedSignal($qItem, 'bybit_qty_invalid', $config, $tickAt, $symbolMap);
                continue;
            }
            if ($maxOrderQty !== null && $maxOrderQty > 0.0 && $normalizedQty > $maxOrderQty) {
                $qItem['skip_reason'] = 'qty_above_max_order_qty';
                $liveQtyInvalidCount++;
                $liveOrdersRejected++;
                $liveLastRejectedSymbol = $symbol;
                $this->recordLiveSkippedSignal($qItem, 'bybit_qty_invalid', $config, $tickAt, $symbolMap);
                continue;
            }
            if ($minNotional !== null && $minNotional > 0.0
                && ($normalizedQty * $entryPrice) < $minNotional
            ) {
                $qItem['skip_reason'] = 'qty_below_min_notional';
                $liveQtyInvalidCount++;
                $liveOrdersRejected++;
                $liveLastRejectedSymbol = $symbol;
                $this->recordLiveSkippedSignal($qItem, 'bybit_min_notional', $config, $tickAt, $symbolMap);
                continue;
            }

            $qtyStr    = rtrim(rtrim(number_format($normalizedQty, 8, '.', ''), '0'), '.');
            $bybitSide = ($side === 'short') ? 'Sell' : 'Buy';

            try {
                $orderResp = $gw->request('/v5/order/create', [
                    'category'    => 'linear',
                    'symbol'      => $symbol,
                    'side'        => $bybitSide,
                    'orderType'   => 'Market',
                    'qty'         => $qtyStr,
                    'timeInForce' => 'IOC',
                    'positionIdx' => 0,
                ], true);
            } catch (\Throwable) {
                $qItem['skip_reason']   = 'live_submit_exception';
                $liveOrdersRejected++;
                $liveLastRejectedSymbol = $symbol;
                $this->recordLiveSkippedSignal($qItem, 'order_create_exception', $config, $tickAt, $symbolMap);
                continue;
            }

            $ordersCreated++;

            if (($orderResp['success'] ?? false) && ($orderResp['ret_code'] ?? -1) === 0) {
                $ordersSubmittedLive++;
                $qItem['queue_status']       = 'submitted';
                $qItem['submitted_at']       = $tickAt;
                $qItem['last_change_reason'] = 'submitted_to_live';
                $qItem['live_order_id']      = $orderResp['result']['orderId'] ?? null;

                $symbolMap[$symbol] = ['symbol' => $symbol, 'size' => $normalizedQty, '_pending' => true];
                $positionsOpened++;

                $submittedEffective[$symbol] = $effectiveLeverage;

                $this->appendExecutionLog([
                    'timestamp'             => $tickAt,
                    'event_type'            => 'live_order_submitted',
                    'strategy_id'           => $qItem['strategy_id'] ?? '',
                    'owner_strategy'        => $qItem['owner_strategy'] ?? '',
                    'signal_id'             => $qItem['signal_id'] ?? '',
                    'symbol'                => $symbol,
                    'side'                  => $side,
                    'entry_price'           => $entryPrice,
                    'budget'                => $budget,
                    'budget_source'         => $budgetSource,
                    'requested_leverage'    => $requestedLeverage,
                    'leverage_source'       => $leverageSource,
                    'meta_max_leverage'     => $bybitMaxLev,
                    'effective_leverage'    => $effectiveLeverage,
                    'set_leverage_note'     => $levResult['note'],
                    'set_leverage_ret_code' => $levResult['ret_code'],
                    'set_leverage_ret_msg'  => $levResult['ret_msg'],
                    'leverage_was_clamped'  => $leverageWasClamped,
                    'raw_qty'               => $rawQty,
                    'normalized_qty'        => $normalizedQty,
                    'qty_step'              => $qtyStep,
                    'min_order_qty'         => $minOrderQty,
                    'min_notional_value'    => $minNotional,
                    'order_type'            => 'Market',
                    'execution_mode'        => 'live',
                    'bybit_side'            => $bybitSide,
                    'live_order_id'         => $qItem['live_order_id'],
                    'reason'                => 'submitted_market_order_to_bybit_live',
                ]);
                $logEvents++;
            } else {
                $retCode = $orderResp['ret_code'] ?? null;
                $retMsg  = $orderResp['ret_msg']  ?? null;
                $qItem['skip_reason']     = 'live_order_rejected';
                $qItem['live_error_code'] = $retCode;
                $qItem['live_error_msg']  = $retMsg;
                $liveOrdersRejected++;
                $liveLastErrorCode      = $retCode;
                $liveLastErrorMsg       = $retMsg;
                $liveLastRejectedSymbol = $symbol;
                $this->recordLiveSkippedSignal($qItem, 'bybit_order_error', $config, $tickAt, $symbolMap, $orderResp);
            }
        }
        unset($qItem);

        // Re-sync positions from Bybit Live after submitting orders
        $freshLivePositions = $this->fetchDemoPositions($gw);

        $symbolQueueContext = [];
        foreach ($orderQueue as $q) {
            $sym = (string)($q['symbol'] ?? '');
            if ($sym !== '' && !isset($symbolQueueContext[$sym])) {
                $symbolQueueContext[$sym] = $q;
            }
        }

        $newActivePositions = [];
        foreach ($freshLivePositions as $pos) {
            $sym    = (string)($pos['symbol'] ?? '');
            $qCtx   = $symbolQueueContext[$sym] ?? [];
            $record = $this->buildPositionFromDemoData($pos, $qCtx, $tickAt);

            // Override demo fields with live fields
            $record['execution_mode']    = 'live';
            $record['mode']              = 'live';
            $record['account']           = $accountId;
            $record['transition_reason'] = 'synced_from_bybit_live';

            // Verify leverage
            $bybitPosLev = (int)($pos['leverage'] ?? 0);
            $expectedLev = $submittedEffective[$sym] ?? null;
            if ($expectedLev !== null && $bybitPosLev > 0 && $bybitPosLev !== $expectedLev) {
                $record['leverage_warning']                     = 'leverage_not_applied_on_exchange';
                $record['requested_leverage']                   = $expectedLev;
                $record['bybit_position_leverage_after_submit'] = $bybitPosLev;
                $liveLeverageMismatchCount++;
                $this->appendExecutionLog([
                    'timestamp'                            => $tickAt,
                    'event_type'                           => 'live_leverage_mismatch',
                    'symbol'                               => $sym,
                    'expected_leverage'                    => $expectedLev,
                    'bybit_position_leverage_after_submit' => $bybitPosLev,
                    'reason'                               => 'leverage_not_applied_on_exchange',
                ]);
                $logEvents++;
            }

            $newActivePositions[] = $record;
            $ordersConfirmedLive++;
        }

        // Detect positions gone from live exchange
        $freshSymbols = array_flip(array_map(
            fn($p) => (string)($p['symbol'] ?? ''),
            $freshLivePositions
        ));
        foreach ($activePositions as $pos) {
            $sym = (string)($pos['symbol'] ?? '');
            if ($sym !== '' && !isset($freshSymbols[$sym]) && ($pos['execution_mode'] ?? '') === 'live') {
                $pos['position_status']          = 'closed';
                $pos['closed_at']                = $tickAt;
                $pos['close_reason']             = 'position_gone_from_bybit_live';
                $pos['close_source']             = 'exchange_disappeared';
                $pos['close_source_confidence']  = 'inferred';
                $pos['closed_at_source']         = 'detected_missing_from_exchange';
                $pos['closed_at_is_estimated']   = true;
                $pos['last_updated_at']          = $tickAt;
                $pos['mode']                     = 'live';
                $closedPositions[]               = $pos;
                $positionsClosed++;
                $this->recordClosedTrade($pos, $tickAt);
            }
        }

        return [
            'order_queue'              => array_values($orderQueue),
            'active_orders'            => $activeOrders,
            'active_positions'         => array_values($newActivePositions),
            'closed_positions'         => $closedPositions,
            'orders_created'           => $ordersCreated,
            'orders_submitted_demo'    => 0,
            'orders_confirmed_demo'    => 0,
            'positions_opened'         => $positionsOpened,
            'positions_closed'         => $positionsClosed,
            'positions_closed_expired'   => $positionsClosedExp,
            'positions_closed_withdrawn' => $positionsClosedWith,
            'execution_log_events'     => $logEvents,
            // Live execution diagnostics (reuse demo_* keys for last_run.json compatibility)
            'demo_orders_prepared'           => $liveOrdersPrepared,
            'demo_orders_rejected'           => $liveOrdersRejected,
            'demo_leverage_clamped_count'    => $liveLeverageClampedCount,
            'demo_set_leverage_failed_count' => $liveSetLevFailedCount,
            'demo_qty_invalid_count'         => $liveQtyInvalidCount,
            'demo_last_error_code'           => $liveLastErrorCode,
            'demo_last_error_msg'            => $liveLastErrorMsg,
            'demo_last_rejected_symbol'      => $liveLastRejectedSymbol,
            'demo_last_req_leverage'         => $liveLastReqLeverage,
            'demo_last_eff_leverage'         => $liveLastEffLeverage,
            'demo_last_leverage_source'      => $liveLastLeverageSrc,
            'demo_last_budget_source'        => $liveLastBudgetSrc,
            'demo_last_set_lev_note'         => $liveLastSetLevNote,
            'demo_last_set_lev_code'         => $liveLastSetLevCode,
            'demo_last_set_lev_msg'          => $liveLastSetLevMsg,
            'demo_leverage_mismatch_count'   => $liveLeverageMismatchCount,
            'live_orders_prepared'           => $liveOrdersPrepared,
            'live_orders_rejected'           => $liveOrdersRejected,
            'live_skip_reason'               => null,
        ];
    }

    /**
     * Persist a skipped-live-signal record to the live skipped-signal journal.
     *
     * Called ONLY from processLiveExecution(); never from demo/paper paths.
     *
     * Files written:
     *   storage/runtime/live_skipped_signals.json
     *     Keyed by stable key "live:{strategy_id}:{symbol}:{signal_id}".
     *     On repeated attempts the record is replaced (most-recent skip wins).
     *
     *   storage/runtime/live_skipped_signals.ndjson
     *     One JSON line appended per call — full append-only audit trail.
     *
     * API keys and secrets are never stored here.
     *
     * @param array       $qItem          Queue item at the point of the skip (may be partial).
     * @param string      $skipStage      Normalised skip stage (see required stages in spec).
     * @param array       $config         Bot effective config (for global_runtime_mode, account_id).
     * @param string      $tickAt         ISO-8601 tick timestamp.
     * @param array       $activeSymbolMap Symbol → position map for active_position_exists check.
     * @param array|null  $orderResponse  Raw Bybit order response, if one was attempted.
     */
    private function recordLiveSkippedSignal(
        array   $qItem,
        string  $skipStage,
        array   $config,
        string  $tickAt,
        array   $activeSymbolMap = [],
        ?array  $orderResponse   = null
    ): void {
        $signalId = (string)($qItem['signal_id']   ?? '');
        $symbol   = (string)($qItem['symbol']      ?? '');
        $stratId  = (string)($qItem['strategy_id'] ?? $qItem['owner_strategy'] ?? '');

        // Stable dedup key — same signal cannot generate two distinct trade opportunities.
        $stableKey = 'live:' . $stratId . ':' . $symbol . ':' . $signalId;

        $globalRtm = (string)($config['global_runtime_mode'] ?? '');
        $accountId = (string)($config['account_id']          ?? '');

        $activePosExists = isset($activeSymbolMap[$symbol]) && $activeSymbolMap[$symbol] !== false;

        // Build sanitised order-request preview (quantities must be known to be meaningful).
        $orderReqPreview = null;
        $normalizedQty   = isset($qItem['normalized_qty']) ? (float)$qItem['normalized_qty'] : null;
        if ($normalizedQty !== null && $normalizedQty > 0.0) {
            $bybitSide = ($qItem['side'] ?? 'long') === 'short' ? 'Sell' : 'Buy';
            $qtyStr    = rtrim(rtrim(number_format($normalizedQty, 8, '.', ''), '0'), '.');
            $orderReqPreview = [
                'category'    => 'linear',
                'symbol'      => $symbol,
                'side'        => $bybitSide,
                'orderType'   => 'Market',
                'qty'         => $qtyStr,
                'timeInForce' => 'IOC',
                'positionIdx' => 0,
            ];
        }

        // Sanitise raw exchange response — never persist credential-bearing fields.
        $exchangeRaw = null;
        if ($orderResponse !== null) {
            $bannedKeys  = ['api_key', 'apiKey', 'api_secret', 'apiSecret', 'secret', 'sign', 'signature'];
            $exchangeRaw = array_diff_key($orderResponse, array_flip($bannedKeys));
        }

        // Detect Bybit insufficient balance error: retCode 110007 or known retMsg patterns.
        $bybitErrCode = $qItem['live_error_code'] ?? null;
        $bybitErrMsg  = (string)($qItem['live_error_msg'] ?? '');
        if ($orderResponse !== null) {
            $bybitErrCode = $bybitErrCode ?? ($orderResponse['ret_code'] ?? null);
            $bybitErrMsg  = $bybitErrMsg !== '' ? $bybitErrMsg : (string)($orderResponse['ret_msg'] ?? '');
        }
        $insufficientBalancePatterns = ['not enough', 'insufficient balance', 'ab not enough'];
        $bybitErrMsgLower = strtolower($bybitErrMsg);
        $insuffBalanceByError = ($bybitErrCode === 110007)
            || ($bybitErrCode === '110007')
            || array_reduce(
                $insufficientBalancePatterns,
                static fn(bool $carry, string $pat) => $carry || str_contains($bybitErrMsgLower, $pat),
                false
            );

        // Override skip_stage to insufficient_balance when the Bybit error indicates balance problem.
        if ($insuffBalanceByError && $skipStage === 'bybit_order_error') {
            $skipStage = 'insufficient_balance';
        }

        $record = [
            'observed_at'                     => $tickAt,
            'mode'                            => 'live',
            'global_runtime_mode'             => $globalRtm !== '' ? $globalRtm : null,
            'account_id'                      => $accountId !== '' ? $accountId : null,
            'strategy_id'                     => $stratId,
            'signal_id'                       => $signalId,
            'governor_signal_key'             => $qItem['governor_signal_key']   ?? null,
            'dynamic_idea_key'                => $qItem['dynamic_idea_key']      ?? null,
            'symbol'                          => $symbol,
            'side'                            => (string)($qItem['side']          ?? ''),
            'entry_price'                     => $qItem['entry_price']            ?? null,
            'current_price'                   => null,
            'budget'                          => $qItem['bot_budget']             ?? null,
            'leverage_requested'              => $qItem['requested_leverage']
                                                    ?? $qItem['bot_leverage']     ?? null,
            'leverage_effective'              => $qItem['effective_leverage']     ?? null,
            'raw_qty'                         => $qItem['raw_qty']                ?? null,
            'normalized_qty'                  => $normalizedQty,
            'min_qty'                         => $qItem['min_order_qty']          ?? null,
            'max_qty'                         => $qItem['max_order_qty']          ?? null,
            'qty_step'                        => $qItem['qty_step']               ?? null,
            'min_notional'                    => $qItem['min_notional_value']     ?? null,
            'strategy_signal_context'         => $qItem['strategy_signal_context'] ?? null,
            'handoff_ready'                   => $qItem['handoff_ready']          ?? null,
            'executable'                      => $qItem['executable']             ?? null,
            'bot_queue_key'                   => $this->queueKey($qItem),
            'skip_stage'                      => $skipStage,
            'skip_reason'                     => $insuffBalanceByError && ($qItem['skip_reason'] ?? '') === 'live_order_rejected'
                                                    ? 'insufficient_balance'
                                                    : ($qItem['skip_reason'] ?? $skipStage),
            'bybit_error_code'                => $bybitErrCode,
            'bybit_error_message'             => $bybitErrMsg !== '' ? $bybitErrMsg : null,
            'exchange_response_raw'           => $exchangeRaw,
            'account_balance_snapshot'        => null,
            'active_position_exists'          => $activePosExists,
            'max_active_positions_blocked'    => ($skipStage === 'max_active_positions_reached'),
            'insufficient_balance_detected'   => $insuffBalanceByError || in_array(
                $skipStage,
                ['insufficient_balance', 'bybit_min_notional', 'local_min_notional_failed'],
                true
            ),
            'order_would_have_been_submitted' => in_array(
                $skipStage,
                ['bybit_order_error', 'insufficient_balance', 'order_create_exception', 'order_submit_returned_no_order_id'],
                true
            ),
            'order_request_preview'           => $orderReqPreview,
        ];

        // Update keyed JSON journal (most-recent skip wins per stable key).
        $journalRelPath = 'storage/runtime/live_skipped_signals.json';
        $journal        = $this->readJson($journalRelPath, []);
        if (!is_array($journal)) {
            $journal = [];
        }
        $journal[$stableKey] = $record;
        $this->writeJson($journalRelPath, $journal);

        // Append one NDJSON line for full append-only audit trail.
        $ndJsonPath = $this->moduleDir . '/storage/runtime/live_skipped_signals.ndjson';
        $ndJsonDir  = dirname($ndJsonPath);
        if (!is_dir($ndJsonDir)) {
            mkdir($ndJsonDir, 0755, true);
        }
        $line = json_encode(
            array_merge(['journal_key' => $stableKey], $record),
            JSON_UNESCAPED_UNICODE
        );
        file_put_contents($ndJsonPath, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Build a bot-owned active position record from a filled paper order.
     *
     * Liquidation context for stop_manager:
     *   liq_price           — null in paper mode (no real exchange liq available)
     *   estimated_liq_price — isolated-margin local estimate; positive float or null
     *                         long:  entry × (1 − 1 / leverage)
     *                         short: entry × (1 + 1 / leverage)
     *                         null when entry_price ≤ 0, leverage ≤ 1, or result ≤ 0
     *
     * stop_manager reads estimated_liq_price as liq_source = estimated.
     * Only an exchange-provided liq_price > 0 may be treated as real.
     */
    private function buildPositionFromOrder(array $order, string $tickAt): array
    {
        $entryPrice = (float)($order['entry_price'] ?? 0.0);
        $leverage   = max(1, (int)($order['bot_leverage'] ?? 5));
        $budget     = (float)($order['bot_budget'] ?? 0.0);
        $side       = (string)($order['side'] ?? 'long');

        // size = (budget × leverage) / entry_price
        $size = 0.0;
        if ($entryPrice > 0.0 && $budget > 0.0 && $leverage > 0) {
            $size = round(($budget * $leverage) / $entryPrice, 6);
        }

        $estimatedLiqPrice = $this->computeEstimatedLiqPrice($entryPrice, $leverage, $side);

        return [
            // Ownership — carried forward unchanged
            'owner_strategy' => (string)($order['owner_strategy'] ?? ''),
            'strategy_id'    => (string)($order['strategy_id']    ?? ''),
            'signal_id'      => (string)($order['signal_id']      ?? ''),

            // Signal geometry
            'symbol'      => (string)($order['symbol']      ?? ''),
            'side'        => $side,
            'timeframe'   => (string)($order['timeframe']   ?? 'H4'),
            'entry_mode'  => (string)($order['entry_mode']  ?? 'limit'),
            'entry_type'  => (string)($order['entry_type']  ?? 'breakout'),
            'entry_price' => $entryPrice,

            // Execution parameters
            'bot_budget'                    => $budget,
            'bot_leverage'                  => $leverage,
            // Canonical PM fields (mirrors bot_budget / bot_leverage for live-like paper format)
            'budget'                        => $budget,
            'leverage'                      => (float)$leverage,
            'size'                          => $size,
            'budget_source'                 => (string)($order['budget_source']   ?? 'unknown'),
            'leverage_source'               => (string)($order['leverage_source'] ?? 'unknown'),
            'stop_mode'                     => (string)($order['stop_mode']                    ?? 'fixed_from_liq_zone'),
            'stop_from_liq_buffer_value'    => (float)($order['stop_from_liq_buffer_value']   ?? 0.002),
            'stop_from_liq_buffer_type'     => (string)($order['stop_from_liq_buffer_type']   ?? 'percent'),
            'tp_enabled'                    => (bool)($order['tp_enabled']                    ?? false),
            'tp_mode'                       => (string)($order['tp_mode']                     ?? 'fixed_r'),
            'tp_value'                      => (float)($order['tp_value']                     ?? 2.0),
            'reverse_pattern_close_enabled' => (bool)($order['reverse_pattern_close_enabled'] ?? false),

            // Liquidation context (local estimate; no real exchange liq without exchange call)
            'liq_price'           => null,
            'estimated_liq_price' => $estimatedLiqPrice,

            // Position lifecycle
            'position_status'   => 'open',
            'execution_mode'    => 'demo',
            'transition_reason' => 'filled_locally',
            'expires_at'        => (string)($order['expires_at'] ?? ''),
            'opened_at'         => $tickAt,
            'entered_at'        => $tickAt,
            'last_updated_at'   => $tickAt,
        ];
    }

    /**
     * Compute isolated-margin estimated liquidation price for paper positions.
     *
     * Long:  liq_estimate = entry × (1 − 1 / leverage)
     * Short: liq_estimate = entry × (1 + 1 / leverage)
     *
     * Returns null when result would be ≤ 0 (e.g. leverage = 1 long) or inputs are unusable.
     */
    private function computeEstimatedLiqPrice(float $entryPrice, int $leverage, string $side): ?float
    {
        if ($entryPrice <= 0.0 || $leverage <= 0) {
            return null;
        }

        $estimate = $side === 'long'
            ? $entryPrice * (1.0 - 1.0 / $leverage)
            : $entryPrice * (1.0 + 1.0 / $leverage);

        return $estimate > 0.0 ? $estimate : null;
    }

    /**
     * Build a composite execution key: strategy_id:signal_id
     * Used for deduplication across active_orders and active_positions.
     */
    private function executionKey(array $item): string
    {
        $signalId = (string)($item['signal_id'] ?? '');
        if ($signalId === '') {
            return '';
        }
        $stratId = (string)($item['strategy_id'] ?? $item['owner_strategy'] ?? 'unknown');
        return $stratId . ':' . $signalId;
    }

    /**
     *
     * Operator overrides (per-strategy) are applied here:
     *   - bot_budget   (> 0 overrides signal value)
     *   - bot_leverage (> 0 overrides signal value)
     *   - entry_mode   (applied by processHandoff before this call)
     *
     * Deep strategy internals (pattern thresholds, TTL, corridor config, etc.)
     * are NOT exposed here — they stay inside each strategy module.
     */
    private function buildQueueItem(array $signal, array $opOverrides, array $config, string $tickAt, string $executionMode = 'demo'): array
    {
        // Resolve budget: signal → operator override → config → hard fallback
        $botBudget      = (float)($signal['bot_budget']  ?? 0.0);
        $botLeverage    = (int)($signal['bot_leverage']  ?? 0);
        $budgetSource   = ($botBudget  > 0.0) ? 'signal' : '';
        $leverageSource = ($botLeverage > 0)  ? 'signal' : '';

        $opBudget   = (float)($opOverrides['bot_budget']   ?? 0.0);
        $opLeverage = (int)($opOverrides['bot_leverage']   ?? 0);
        if ($opBudget > 0.0) {
            $botBudget    = $opBudget;
            $budgetSource = 'operator';
        }
        if ($opLeverage > 0) {
            $botLeverage    = $opLeverage;
            $leverageSource = 'operator';
        }

        if ($botBudget <= 0.0) {
            $cfgBudget = (float)($config['budget_per_trade'] ?? 0.0);
            if ($cfgBudget <= 0.0) {
                $cfgBudget = (float)($config['max_bot_budget'] ?? 0.0);
            }
            if ($cfgBudget > 0.0) {
                $botBudget    = $cfgBudget;
                $budgetSource = 'config';
            } else {
                $botBudget    = 6.0;
                $budgetSource = 'default';
            }
        }
        if ($botLeverage <= 0) {
            $cfgLeverage = (int)($config['leverage'] ?? 0);
            if ($cfgLeverage <= 0) {
                $cfgLeverage = (int)($config['max_bot_leverage'] ?? 0);
            }
            if ($cfgLeverage > 0) {
                $botLeverage    = $cfgLeverage;
                $leverageSource = 'config';
            } else {
                $botLeverage    = 5;
                $leverageSource = 'default';
            }
        }

        return [
            // Strategy ownership — read from handoff contract, never overridden by bot
            'owner_strategy' => (string)($signal['owner_strategy'] ?? ''),
            'strategy_id'    => (string)($signal['strategy_id']    ?? ''),
            'signal_id'      => (string)($signal['signal_id']      ?? ''),

            // Signal identity
            'symbol'    => (string)($signal['symbol']    ?? ''),
            'side'      => (string)($signal['side']      ?? 'long'),
            'timeframe' => (string)($signal['timeframe'] ?? 'H4'),

            // Entry geometry (entry_mode may be overridden by processHandoff)
            'entry_mode'  => (string)($signal['entry_mode']  ?? 'limit'),
            'entry_type'  => (string)($signal['entry_type']  ?? 'breakout'),
            'entry_price' => (float)($signal['entry_price']  ?? 0.0),

            // Freshness
            'detected_at' => (string)($signal['detected_at'] ?? $tickAt),
            'expires_at'  => (string)($signal['expires_at']  ?? ''),

            // Execution parameters — strategy defaults, possibly overridden by operator
            'stop_mode'                     => (string)($signal['stop_mode']                    ?? 'fixed_from_liq_zone'),
            'stop_from_liq_buffer_value'    => (float)($signal['stop_from_liq_buffer_value']   ?? 0.002),
            'stop_from_liq_buffer_type'     => (string)($signal['stop_from_liq_buffer_type']   ?? 'percent'),
            'bot_budget'                    => $botBudget,
            'bot_leverage'                  => $botLeverage,
            'budget_source'                 => $budgetSource,
            'leverage_source'               => $leverageSource,
            'tp_enabled'                    => (bool)($signal['tp_enabled']                    ?? false),
            'tp_mode'                       => (string)($signal['tp_mode']                     ?? 'fixed_r'),
            'tp_value'                      => (float)($signal['tp_value']                     ?? 2.0),
            'reverse_pattern_close_enabled' => (bool)($signal['reverse_pattern_close_enabled'] ?? false),

            // ── Signal diagnostic context propagation (Task 3) ───────────────────────
            'pattern_algorithm'      => (string)($signal['primary_pattern'] ?? ''),
            'setup_class'            => $signal['setup_class']            ?? null,
            'strategy_signal_context' => $signal['strategy_signal_context'] ?? null,

            // Bot lifecycle state (overwritten by caller)
            'queue_status'   => 'queued',
            // Mode this item was created for — prevents cross-mode execution
            'execution_mode' => $executionMode,
        ];
    }

    // =========================================================================
    // Storage initialization
    // =========================================================================

    /**
     * Ensure all 7 bot storage files exist with truthful empty-state values.
     * Called at the start of every tick regardless of enabled/disabled state.
     * Never overwrites a file that already contains real data.
     */
    private function initializeStorage(): void
    {
        $storageDir = $this->moduleDir . '/storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        // Each entry: [relative path, default value (written only if file missing)]
        $defaults = [
            'storage/strategy_registry.json'  => [],
            'storage/operator_overrides.json' => (object)[],
            'storage/order_queue.json'        => [],
            'storage/active_orders.json'      => [],
            'storage/active_positions.json'   => [],
            'storage/closed_positions.json'   => [],
            'storage/last_run.json'           => [
                'status'                                    => 'never_run',
                'tick_at'                                   => null,
                'elapsed_sec'                               => 0,
                'bot_enabled'                               => false,
                'bot_mode'                                  => 'demo',
                'strategies_discovered_total'               => 0,
                'strategies_enabled_total'                  => 0,
                'strategies_disabled_total'                 => 0,
                'handoff_sources_active_total'              => 0,
                'handoff_signals_processed'                 => 0,
                'handoff_signals_ignored_disabled_strategy' => 0,
                'order_queue_new_total'                     => 0,
                'order_queue_refreshed_total'               => 0,
                'order_queue_expired_total'                 => 0,
                'order_queue_withdrawn_total'               => 0,
                'execution_mode'                            => 'demo',
                'orders_created'                            => 0,
                'orders_submitted_paper'                    => 0,
                'orders_filled_paper'                       => 0,
                'orders_submitted_demo'                     => 0,
                'orders_confirmed_demo'                     => 0,
                'positions_opened'                          => 0,
                'positions_closed'                          => 0,
                'positions_closed_expired'                  => 0,
                'positions_closed_withdrawn'                => 0,
                'positions_closed_reverse_pattern'          => 0,
                'queue_items_skipped_mode_disabled'         => 0,
                'queue_items_skipped_mode_passive'          => 0,
                'execution_log_events'                      => 0,
                'order_queue_total'                         => 0,
                'active_orders_count'                       => 0,
                'active_positions_count'                    => 0,
                'ticks_total'                               => 0,
                'handoff_signals_seen_total'                => 0,
                'signal_source_mode'                        => 'direct_strategy_handoff',
                'signal_source_effective_execution'         => 'direct_strategy_handoff',
                'signal_source_mode_invalid_fallback'       => false,
                'shadow_compare_enabled'                    => false,
                'governor_queue_seen_total'                 => 0,
                'governor_queue_valid_total'                => 0,
                'governor_queue_used_for_orders_total'      => 0,
                'governor_queue_duplicate_skipped_total'    => 0,
                'governor_queue_skipped_non_demo_total'     => 0,
                'governor_queue_skipped_invalid_total'      => 0,
                'governor_queue_skipped_expired_total'      => 0,
                'direct_handoff_seen_total'                 => 0,
                'direct_handoff_used_total'                 => 0,
                'shadow_compare_overlap_total'              => 0,
                'shadow_compare_direct_only_total'          => 0,
                'shadow_compare_governor_only_total'        => 0,
                'shadow_compare_governor_would_filter_total'=> 0,
                'shadow_compare_governor_would_add_total'   => 0,
                'shadow_compare_direct_only_examples'       => [],
                'shadow_compare_governor_only_examples'     => [],
                'shadow_compare_overlap_examples'           => [],
                // Submitted queue reconciliation
                'submitted_reconcile_enabled'              => true,
                'submitted_queue_total'                    => 0,
                'submitted_active_position_matched_total'  => 0,
                'submitted_active_order_matched_total'     => 0,
                'submitted_closed_trade_matched_total'     => 0,
                'submitted_reconciled_closed_total'        => 0,
                'submitted_reconciled_expired_total'       => 0,
                'submitted_waiting_match_total'            => 0,
                'submitted_stale_unmatched_total'          => 0,
                'submitted_still_blocking_total'           => 0,
            ],
            'storage/stats.json' => $this->zeroStats(),
        ];

        foreach ($defaults as $relPath => $default) {
            $absPath = $this->moduleDir . '/' . $relPath;
            if (!file_exists($absPath)) {
                $this->writeJson($relPath, $default);
            }
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Composite deduplication key: strategy_id:signal_id
     * Safe across multiple strategy sources.
     */
    private function queueKey(array $item): string
    {
        $signalId = (string)($item['signal_id'] ?? '');
        if ($signalId === '') {
            return '';
        }
        $stratId = (string)($item['strategy_id'] ?? $item['owner_strategy'] ?? 'unknown');
        return $stratId . ':' . $signalId;
    }

    private function countByStatus(array $queue, array $statuses): int
    {
        $count = 0;
        foreach ($queue as $item) {
            if (in_array($item['queue_status'] ?? '', $statuses, true)) {
                $count++;
            }
        }
        return $count;
    }

    private function zeroStats(): array
    {
        return [
            'ticks_total'                 => 0,
            // Discovery (current-state)
            'strategies_discovered_total' => 0,
            'strategies_enabled_total'    => 0,
            'strategies_disabled_total'   => 0,
            'handoff_sources_active_total'=> 0,
            // Signals (cumulative)
            'handoff_signals_seen_total'  => 0,
            'handoff_signals_ignored_disabled_strategy_total' => 0,
            // Queue (cumulative)
            'order_queue_total'           => 0,
            'order_queue_new_total'       => 0,
            'order_queue_refreshed_total' => 0,
            'order_queue_expired_total'   => 0,
            'order_queue_withdrawn_total' => 0,
            // Execution (cumulative)
            'orders_created_total'                    => 0,
            'orders_submitted_paper_total'            => 0,
            'orders_filled_paper_total'               => 0,
            'orders_submitted_demo_total'             => 0,
            'orders_confirmed_demo_total'             => 0,
            'positions_opened_total'                  => 0,
            'positions_closed_total'                  => 0,
            'positions_closed_expired_total'          => 0,
            'positions_closed_withdrawn_total'        => 0,
            'positions_closed_reverse_pattern_total'  => 0,
            'queue_items_skipped_mode_disabled_total' => 0,
            'queue_items_skipped_mode_passive_total'  => 0,
            'execution_log_events_total'              => 0,
            // Live counts
            'active_orders_total'         => 0,
            'active_positions_total'      => 0,
        ];
    }

    private function writeRuntimeSnapshot(
        array $config,
        array $lastRun,
        int $discoveredTotal,
        int $enabledTotal
    ): void {
        $snap = [
            'snapshot_at'      => date('c'),
            'bot_id'           => 'bot',
            'mode'             => $config['mode']    ?? 'demo',
            'enabled'          => $config['enabled'] ?? false,
            'tick_at'          => $lastRun['tick_at']    ?? null,
            'last_tick_result' => $lastRun['status']     ?? 'ok',

            // Discovery
            'strategies_discovered_total'  => $discoveredTotal,
            'strategies_enabled_total'     => $enabledTotal,
            'strategies_disabled_total'    => $discoveredTotal - $enabledTotal,
            'handoff_sources_active_total' => $lastRun['handoff_sources_active_total'] ?? 0,

            // Signals this tick
            'handoff_signals_processed' => $lastRun['handoff_signals_processed'] ?? 0,

            // Entry
            'allowed_entry_modes' => $config['allowed_entry_modes'] ?? ['limit', 'market'],

            // Execution caps
            'max_bot_budget'   => $config['max_bot_budget']   ?? 0.0,
            'max_bot_leverage' => $config['max_bot_leverage'] ?? 0,

            // Runtime truth
            'order_queue_total'      => $lastRun['order_queue_total']      ?? 0,
            'active_orders_count'    => $lastRun['active_orders_count']    ?? 0,
            'active_positions_count' => $lastRun['active_positions_count'] ?? 0,

            // Brain-compatible keys
            'config_valid'     => true,
            'effective_config' => [
                'mode'    => $config['mode']    ?? 'demo',
                'enabled' => $config['enabled'] ?? false,
            ],
        ];

        $path  = $this->moduleDir . '/config/runtime_snapshot.php';
        $lines = [
            "<?php\n\ndeclare(strict_types=1);\n\n",
            "/**\n * Bot Module — Runtime Snapshot\n",
            " * Auto-written after each tick. Do not edit manually.\n",
            " * snapshot_at: " . $snap['snapshot_at'] . "\n */\n\n",
            "return " . var_export($snap, true) . ";\n",
        ];
        @file_put_contents($path, implode('', $lines));
    }

    private function appendExecutionLog(array $event): void
    {
        $path = $this->moduleDir . '/storage/execution_log.ndjson';
        $line = json_encode($event, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    private function requireBootstrap(): void
    {
        require_once $this->moduleDir . '/bootstrap.php';
    }

    private function readJson(string $relPath, mixed $default = null): mixed
    {
        $path = $this->moduleDir . '/' . $relPath;
        if (!file_exists($path)) {
            return $default;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return ($decoded !== null) ? $decoded : $default;
    }

    /**
     * Maintain a local position runtime age counter.
     *
     * Key: symbol + '_' + side + '_' + account_or_execution_mode
     *   - First time a position appears: runtime_age_minutes = 1, first_seen_at = now
     *   - On every subsequent tick:      runtime_age_minutes += tick_interval_minutes
     *   - If position disappears:        entry removed from map
     *
     * Stored in: storage/position_runtime_age.json
     */
    private function updatePositionRuntimeAge(array $activePositions, string $tickAt, array $config): void
    {
        $relPath         = 'storage/position_runtime_age.json';
        $ageMap          = $this->readJson($relPath, []);
        $tickIntervalSec = max(30, (int)($config['tick_interval_sec'] ?? 60));
        $tickMinutes     = max(1, (int)round($tickIntervalSec / 60));

        $currentKeys = [];
        foreach ($activePositions as $pos) {
            $sym  = (string)($pos['symbol'] ?? '');
            $side = (string)($pos['side']   ?? '');
            if ($sym === '' || $side === '') {
                continue;
            }
            $account = (string)($pos['account'] ?? $pos['execution_mode'] ?? 'local');
            $key     = $sym . '_' . $side . '_' . $account;
            $currentKeys[$key] = true;

            if (isset($ageMap[$key])) {
                $ageMap[$key]['runtime_age_minutes'] += $tickMinutes;
                $ageMap[$key]['last_seen_at']         = $tickAt;
            } else {
                $ageMap[$key] = [
                    'symbol'              => $sym,
                    'side'                => $side,
                    'account'             => $account,
                    'execution_mode'      => (string)($pos['execution_mode'] ?? 'demo'),
                    'runtime_age_minutes' => 1,
                    'first_seen_at'       => $tickAt,
                    'last_seen_at'        => $tickAt,
                ];
            }
        }

        // Remove positions no longer active
        foreach (array_keys($ageMap) as $k) {
            if (!isset($currentKeys[$k])) {
                unset($ageMap[$k]);
            }
        }

        $this->writeJson($relPath, $ageMap);
    }

    private function writeJson(string $relPath, mixed $data): void
    {
        $path = $this->moduleDir . '/' . $relPath;
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Compute current-state diagnostics for the PM close registry.
     *
     * Also performs lazy cleanup: removes entries whose effective suppress_until
     * has expired so the file does not grow unbounded.
     *
     * @return array{
     *   entries_total: int,
     *   active_suppression_total: int,
     *   consumed_retained_total: int,
     *   expired_removed_total: int
     * }
     */
    private function computePmRegistryDiagnostics(): array
    {
        $result = [
            'entries_total'            => 0,
            'active_suppression_total' => 0,
            'consumed_retained_total'  => 0,
            'expired_removed_total'    => 0,
        ];

        $pmRegistryPath = $this->moduleDir . '/storage/runtime/pm_close_registry.json';
        try {
            if (!is_file($pmRegistryPath)) {
                return $result;
            }
            $raw = @file_get_contents($pmRegistryPath);
            if ($raw === false || $raw === '') {
                return $result;
            }
            $registry = @json_decode($raw, true);
            if (!is_array($registry)) {
                return $result;
            }

            $now     = time();
            $changed = false;
            foreach ($registry as $key => $entry) {
                $entryTs       = (int)($entry['ts'] ?? 0);
                $suppressUntil = (int)($entry['suppress_reentry_until'] ?? 0);
                if ($suppressUntil === 0 && $entryTs > 0) {
                    $suppressUntil = $entryTs + 3600;
                }

                if ($suppressUntil > $now) {
                    $result['entries_total']++;
                    $result['active_suppression_total']++;
                    if (!empty($entry['close_attribution_consumed'])) {
                        $result['consumed_retained_total']++;
                    }
                } else {
                    // Expired — remove
                    unset($registry[$key]);
                    $result['expired_removed_total']++;
                    $changed = true;
                }
            }

            if ($changed) {
                @file_put_contents(
                    $pmRegistryPath,
                    json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                    LOCK_EX
                );
            }
        } catch (\Throwable) {
            // Never crash over diagnostics
        }

        return $result;
    }

    /**
     * Record a real closed position into the unified closed trades journal.
     *
     * Writes to:
     *   storage/trades/closed_trades.json    — aggregated list (max 1 000 entries)
     *   storage/trades/closed/{id}.json      — individual per-trade file
     *
     * Never modifies active_positions, never places orders, observation-only.
     *
     * @param array  $pos     Closed position record (already has closed_at, close_reason, mode)
     * @param string $tickAt  Tick timestamp
     */
    private function recordClosedTrade(array $pos, string $tickAt): void
    {
        try {
            $symbol    = (string)($pos['symbol']         ?? '');
            $side      = (string)($pos['side']           ?? '');
            $openedAt  = (string)($pos['opened_at']      ?? $pos['entered_at'] ?? '');
            $closedAt  = (string)($pos['closed_at']      ?? $tickAt);
            $mode      = (string)($pos['mode']           ?? $pos['execution_mode'] ?? 'demo');
            $account   = (string)($pos['account']        ?? ($mode === 'live' ? 'bybit_live' : 'bybit_demo'));
            $stratId   = (string)($pos['strategy_id']    ?? $pos['owner_strategy'] ?? '');
            $closeReason = (string)($pos['close_reason'] ?? 'unknown');

            if ($symbol === '' || $side === '') {
                return;
            }

            // ── Exit price: last known mark/current price from position ───────
            $entryPrice     = (float)($pos['entry_price']  ?? 0.0);
            $rawMarkPrice   = (float)($pos['mark_price']   ?? 0.0);
            $rawCurrentPrice= (float)($pos['current_price']?? 0.0);

            if ($rawMarkPrice > 0.0) {
                $exitPrice       = $rawMarkPrice;
                $exitPriceSource = 'last_cached_mark_price';
            } elseif ($rawCurrentPrice > 0.0) {
                $exitPrice       = $rawCurrentPrice;
                $exitPriceSource = 'last_cached_current_price';
            } else {
                // Fallback: use entry as exit (ROI = 0, estimate)
                $exitPrice       = $entryPrice;
                $exitPriceSource = 'fallback_entry_price';
            }

            // ── Leverage & budget ─────────────────────────────────────────────
            $leverage = max(1, (int)($pos['bot_leverage'] ?? $pos['leverage'] ?? 1));
            $budget   = (float)($pos['bot_budget']        ?? $pos['budget']   ?? 0.0);
            $size     = (float)($pos['size']              ?? 0.0);

            // ── ROI & PnL ─────────────────────────────────────────────────────
            $roi = null;
            $pnl = null;
            if ($entryPrice > 0.0 && $exitPrice > 0.0 && $leverage > 0) {
                if ($side === 'short') {
                    $roi = ($entryPrice - $exitPrice) / $entryPrice * $leverage * 100.0;
                } else {
                    $roi = ($exitPrice - $entryPrice) / $entryPrice * $leverage * 100.0;
                }
                $roi = round($roi, 4);
            }
            if ($roi !== null && $budget > 0.0) {
                $pnl = round($budget * $roi / 100.0, 6);
            }

            // ── Duration ─────────────────────────────────────────────────────
            $durationSec = null;
            if ($openedAt !== '' && $closedAt !== '') {
                $oTs = is_numeric($openedAt) ? (int)$openedAt : (int)@strtotime($openedAt);
                $cTs = is_numeric($closedAt) ? (int)$closedAt : (int)@strtotime($closedAt);
                if ($oTs > 0 && $cTs >= $oTs) {
                    $durationSec = $cTs - $oTs;
                }
            }

            // ── Close source detection ────────────────────────────────────────
            // Only set stop_manager if the position record carries an explicit
            // stop-trigger field.  Never infer it from stops.json presence alone.
            $closeSource = 'unknown';
            $closeReason = 'position_gone_from_exchange';

            $explicitCloseSource = (string)($pos['close_source'] ?? '');
            $explicitCloseReason = (string)($pos['close_reason'] ?? '');

            // Read diagnostic fields propagated from position-gone detection
            $closeSourceConfidence = (string)($pos['close_source_confidence'] ?? '');
            $closedAtSource        = (string)($pos['closed_at_source']        ?? '');
            $closedAtIsEstimated   = (bool)($pos['closed_at_is_estimated']    ?? false);
            $openedAtDisplaySource = (string)($pos['opened_at_display_source']?? '');
            $exchangeOpenedAt      = isset($pos['exchange_opened_at']) ? (string)$pos['exchange_opened_at'] : null;
            $botSubmittedAt        = isset($pos['bot_submitted_at'])   ? (string)$pos['bot_submitted_at']   : null;
            $botConfirmedAt        = isset($pos['bot_confirmed_at'])   ? (string)$pos['bot_confirmed_at']   : null;

            if ($explicitCloseSource !== '') {
                $closeSource = $explicitCloseSource;
            }
            if ($explicitCloseReason !== '' && $explicitCloseReason !== 'position_gone_from_bybit_demo' && $explicitCloseReason !== 'position_gone_from_bybit_live') {
                $closeReason = $explicitCloseReason;
            }

            // ── PM close registry lookup ──────────────────────────────────────
            // If Profit Manager closed this position it will have left an entry
            // in the registry file. Consume it for attribution, but ONLY remove it
            // once suppress_reentry_until has expired so re-entry suppression stays
            // intact for the full TTL window.
            $closeOrderId   = null;
            $executionType  = 'inferred_close';
            $closeGuard     = null;
            $pmRegistryPath = $this->moduleDir . '/storage/runtime/pm_close_registry.json';
            $pmRegistryKey  = $symbol . '_' . $side;
            // Default suppression TTL mirrors PM config default (1 hour).
            $pmRegistryDefaultTtl = 3600;

            try {
                if (is_file($pmRegistryPath)) {
                    $regRaw = @file_get_contents($pmRegistryPath);
                    if ($regRaw !== false && $regRaw !== '') {
                        $registry = @json_decode($regRaw, true);
                        if (is_array($registry) && isset($registry[$pmRegistryKey])) {
                            $entry   = $registry[$pmRegistryKey];
                            $entryTs = (int)($entry['ts'] ?? 0);
                            $nowTs   = time();

                            // Derive effective suppress_until: explicit field or ts + default TTL.
                            $suppressUntil = (int)($entry['suppress_reentry_until'] ?? 0);
                            if ($suppressUntil === 0 && $entryTs > 0) {
                                $suppressUntil = $entryTs + $pmRegistryDefaultTtl;
                            }

                            // Attribution window: entry must be recent enough to be credible.
                            // Use suppress_until as the outer bound (the entry is "alive").
                            $alreadyConsumed = (bool)($entry['close_attribution_consumed'] ?? false);
                            if (!$alreadyConsumed && $entryTs > 0 && $suppressUntil >= $nowTs) {
                                // Registry hit — override close attribution
                                $closeSource           = (string)($entry['close_source']   ?? 'profit_manager');
                                $closeReason           = (string)($entry['close_reason']   ?? $closeReason);
                                $closeOrderId          = ($entry['close_order_id'] ?? null) !== null
                                    ? (string)$entry['close_order_id']
                                    : null;
                                $executionType         = 'pm_market_close';
                                $closeSourceConfidence = 'confirmed';
                                $closedAtIsEstimated   = false;
                                $closedAtSource        = 'pm_registry';
                            }

                            if ($suppressUntil > $nowTs) {
                                // Suppression still active — keep the entry but mark it consumed
                                // so a second position-gone event does not re-attribute it.
                                if (!$alreadyConsumed) {
                                    $registry[$pmRegistryKey]['close_attribution_consumed']    = true;
                                    $registry[$pmRegistryKey]['close_attribution_consumed_at'] = $tickAt;
                                }
                            } else {
                                // Suppression expired — safe to remove.
                                unset($registry[$pmRegistryKey]);
                            }

                            @file_put_contents(
                                $pmRegistryPath,
                                json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                                LOCK_EX
                            );
                        }
                    }
                }
            } catch (\Throwable) {
                // Never crash over registry read/write failures
            }

            // ── EF close registry lookup ──────────────────────────────────────
            // If Stop Manager's early-fail guard closed this position it will have
            // written an entry to ef_close_registry.json.  Prefer this attribution
            // over exchange_disappeared for double_bottom_long positions.
            // TTL mirrors the registry write TTL (2 hours / 7200 s).
            //
            // The registry key is {mode}_{symbol}_{side} where mode is normalized
            // to 'demo' or 'live' (same logic as writeEfCloseRegistry in stop_manager).
            $efRegistryPath   = $this->moduleDir . '/storage/runtime/ef_close_registry.json';
            $efModeNorm       = in_array($mode, ['live', 'demo'], true) ? $mode : 'demo';
            $efRegistryKey    = $efModeNorm . '_' . $symbol . '_' . $side;
            $efRegistryTtl    = 7200;
            // Augment fields sourced from ef registry when the position record is missing them.
            $efSetupBreakReason  = null;
            $efSignalIdAugment   = null;
            $efStratCtxAugment   = null;

            try {
                if (is_file($efRegistryPath)) {
                    $efRegRaw = @file_get_contents($efRegistryPath);
                    if ($efRegRaw !== false && $efRegRaw !== '') {
                        $efRegistry = @json_decode($efRegRaw, true);
                        if (is_array($efRegistry) && isset($efRegistry[$efRegistryKey])) {
                            $efEntry   = $efRegistry[$efRegistryKey];
                            $efEntryTs = (int)($efEntry['ts'] ?? 0);
                            $efNowTs   = time();
                            $efExpiry  = $efEntryTs > 0 ? $efEntryTs + $efRegistryTtl : 0;
                            $efAlreadyConsumed = (bool)($efEntry['close_attribution_consumed'] ?? false);

                            if (!$efAlreadyConsumed && $efEntryTs > 0 && $efExpiry >= $efNowTs) {
                                // Verify signal_id matches when both sides carry one (extra safety).
                                $efSignalId  = (string)($efEntry['signal_id'] ?? '');
                                $posSignalId = (string)($pos['signal_id']     ?? '');
                                $signalIdOk  = ($efSignalId === '' || $posSignalId === '' || $efSignalId === $posSignalId);

                                if ($signalIdOk) {
                                    // Registry hit — override close attribution with guard values
                                    $closeSource           = (string)($efEntry['close_source'] ?? 'stop_manager');
                                    $closeReason           = (string)($efEntry['close_reason'] ?? $closeReason);
                                    $closeOrderId          = ($efEntry['close_order_id'] ?? null) !== null
                                        ? (string)$efEntry['close_order_id']
                                        : null;
                                    $closeGuard            = (string)($efEntry['close_guard'] ?? 'double_bottom_early_fail');
                                    $executionType         = 'ef_guard_market_close';
                                    $closeSourceConfidence = 'stop_manager_close_guard_registry';
                                    $closedAtIsEstimated   = false;
                                    $closedAtSource        = 'ef_close_registry';

                                    // Carry over setup_break_reason into the closed trade record.
                                    $efSetupBreakReason = ($efEntry['setup_break_reason'] ?? null) !== null
                                        ? (string)$efEntry['setup_break_reason']
                                        : null;

                                    // Use ef registry as fallback source for signal_id and
                                    // strategy_signal_context when the bot's position copy is missing them.
                                    if ($posSignalId === '' && $efSignalId !== '') {
                                        $efSignalIdAugment = $efSignalId;
                                    }
                                    if (!isset($pos['strategy_signal_context']) && isset($efEntry['strategy_signal_context'])) {
                                        $efStratCtxAugment = $efEntry['strategy_signal_context'];
                                    }

                                    // Mark consumed so a second position-gone event does not re-attribute.
                                    $efRegistry[$efRegistryKey]['close_attribution_consumed']    = true;
                                    $efRegistry[$efRegistryKey]['close_attribution_consumed_at'] = $tickAt;

                                    @file_put_contents(
                                        $efRegistryPath,
                                        json_encode($efRegistry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                                        LOCK_EX
                                    );
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // Never crash over registry read/write failures
            }

            // Normalise legacy 'unknown' source — when no explicit source was found,
            // the position disappeared from the exchange without a clear PM/Stop hit.
            if ($closeSource === 'unknown') {
                $closeSource = 'exchange_disappeared';
                if ($closeSourceConfidence === '') {
                    $closeSourceConfidence = 'inferred';
                }
                if ($closedAtSource === '') {
                    $closedAtSource = 'detected_missing_from_exchange';
                }
                $closedAtIsEstimated = true;
            }

            // Infer close type from ROI when the source is exchange_disappeared.
            // Do NOT label as stop_loss — negative ROI alone is not sufficient.
            $inferredCloseType = null;
            if ($closeSource === 'exchange_disappeared' && $roi !== null) {
                $inferredCloseType = ($roi < 0.0) ? 'loss_exit_candidate' : 'profit_exit_candidate';
            }

            // ── Build trade record ────────────────────────────────────────────
            // NOTE: id is filled in below after the safer trade ID is computed.
            $resolvedSignalId = (string)($pos['signal_id'] ?? '') !== ''
                ? (string)$pos['signal_id']
                : ($efSignalIdAugment ?: null);

            $trade = [
                'id'               => '',   // placeholder; replaced after ID computation
                'symbol'           => $symbol,
                'side'             => $side,
                'strategy_id'      => $stratId,
                'entry_price'      => $entryPrice > 0.0 ? $entryPrice : null,
                'exit_price'       => $exitPrice  > 0.0 ? $exitPrice  : null,
                'exit_price_source'=> $exitPriceSource,
                'roi'              => $roi,
                'pnl'              => $pnl,
                'leverage'         => $leverage,
                'budget'           => $budget > 0.0 ? $budget : null,
                'size'             => $size  > 0.0 ? $size   : null,
                'opened_at'        => $openedAt  !== '' ? $openedAt  : null,
                'closed_at'        => $closedAt  !== '' ? $closedAt  : null,
                'duration_sec'     => $durationSec,
                'mode'             => $mode,
                'account'          => $account,
                'close_source'               => $closeSource,
                'close_reason'               => $closeReason,
                'close_order_id'             => $closeOrderId,
                'close_guard'                => $closeGuard,
                'setup_break_reason'         => $efSetupBreakReason,
                'execution_type'             => $executionType,
                'close_source_confidence'    => $closeSourceConfidence !== '' ? $closeSourceConfidence : null,
                'closed_at_source'           => $closedAtSource         !== '' ? $closedAtSource         : null,
                'closed_at_is_estimated'     => $closedAtIsEstimated,
                'inferred_close_type'        => $inferredCloseType,
                'opened_at_display_source'   => $openedAtDisplaySource  !== '' ? $openedAtDisplaySource  : null,
                'exchange_opened_at'         => $exchangeOpenedAt,
                'bot_submitted_at'           => $botSubmittedAt,
                'bot_confirmed_at'           => $botConfirmedAt,
                // ── Signal trace attribution (traceability, not outcome data) ──
                // When the ef registry has a richer signal_id or strategy_signal_context
                // (e.g. the position record was trimmed after entry), fall back to registry values.
                'signal_id'              => $resolvedSignalId,
                'owner_strategy'         => (string)($pos['owner_strategy'] ?? '') !== '' ? (string)$pos['owner_strategy'] : null,
                'pattern_algorithm'      => (string)($pos['pattern_algorithm'] ?? '') !== '' ? (string)$pos['pattern_algorithm'] : null,
                'setup_class'            => $pos['setup_class']            ?? null,
                'strategy_signal_context'=> $pos['strategy_signal_context'] ?? $efStratCtxAugment,
            ];

            // ── Trade ID: compute from fully-resolved trade record ────────────
            // Use signal_id + closed_at + close_source when available for a
            // collision-resistant ID; fall back to legacy symbol+side+opened_at.
            $tSigId      = (string)($trade['signal_id']   ?? '');
            $tClosedAt   = (string)($trade['closed_at']   ?? '');
            $tCloseSource = (string)($trade['close_source'] ?? '');
            if ($tSigId !== '') {
                $idComponents = $symbol . '|' . $side . '|' . $tSigId . '|' . $openedAt . '|' . $tClosedAt . '|' . $tCloseSource;
                $tradeId = 'ct_' . substr(md5($idComponents), 0, 16);
            } else {
                // Legacy fallback: symbol + side + opened_at (12-char hash)
                $idLegacy = $symbol . '_' . $side . '_' . ($openedAt !== '' ? $openedAt : $closedAt);
                $tradeId  = 'ct_' . substr(md5($idLegacy), 0, 12);
            }
            $trade['id'] = $tradeId;

            // ── Load aggregate FIRST, dedup/conflict check ────────────────────
            // Individual file must NOT be written before dedup is complete.
            $aggRelPath = 'storage/trades/closed_trades.json';
            $aggAbsPath = $this->moduleDir . '/' . $aggRelPath;

            $existing = [];
            if (is_file($aggAbsPath)) {
                $raw = @file_get_contents($aggAbsPath);
                if ($raw !== false && $raw !== '') {
                    $dec = @json_decode($raw, true);
                    if (is_array($dec)) {
                        $existing = $dec;
                    }
                }
            }

            $isDuplicate = false;
            foreach ($existing as $entry) {
                if (($entry['id'] ?? '') !== $tradeId) {
                    continue;
                }
                // Same ID — check whether it is the identical event or a conflict.
                $sameEvent = ($entry['symbol']       ?? '') === ($trade['symbol']       ?? '')
                    && ($entry['side']               ?? '') === ($trade['side']         ?? '')
                    && ($entry['signal_id']          ?? null) === ($trade['signal_id']  ?? null)
                    && ($entry['roi']                ?? null) === ($trade['roi']        ?? null)
                    && ($entry['close_source']       ?? '') === ($trade['close_source'] ?? '')
                    && ($entry['closed_at']          ?? '') === ($trade['closed_at']    ?? '');

                if ($sameEvent) {
                    // Identical event re-submitted — harmless duplicate.
                    $this->closedTradeDuplicateIdTotal++;
                    $isDuplicate = true;
                } else {
                    // Same hash but different event data — conflict: build a new unique ID.
                    $this->closedTradeDuplicateConflictTotal++;
                    if (count($this->closedTradeConflictExamples) < 5) {
                        $this->closedTradeConflictExamples[] = [
                            'trade_id'              => $tradeId,
                            'existing_symbol'       => $entry['symbol']       ?? null,
                            'existing_closed_at'    => $entry['closed_at']    ?? null,
                            'existing_close_source' => $entry['close_source'] ?? null,
                            'existing_roi'          => $entry['roi']          ?? null,
                            'new_closed_at'         => $trade['closed_at']    ?? null,
                            'new_close_source'      => $trade['close_source'] ?? null,
                            'new_roi'               => $trade['roi']          ?? null,
                        ];
                    }
                    // Append short hash suffix to make the new ID unique.
                    $conflictSuffix = substr(md5($tClosedAt . '|' . $tCloseSource . '|' . microtime()), 0, 6);
                    $tradeId        = $tradeId . '_c' . $conflictSuffix;
                    $trade['id']    = $tradeId;
                    $trade['conflict_id_suffix_reason'] = 'id_collision_different_event';
                }
                break;
            }

            if ($isDuplicate) {
                // Do NOT overwrite the individual file or re-append to aggregate.
                return;
            }

            // ── Write individual per-trade file (only after dedup is confirmed safe) ──
            $indivPath = 'storage/trades/closed/' . $tradeId . '.json';
            $this->writeJson($indivPath, $trade);

            // ── Append to aggregated closed_trades.json ───────────────────────
            $existing[] = $trade;

            // Trim to last 1 000 entries (oldest first, keep newest)
            if (count($existing) > 1000) {
                $existing = array_slice($existing, -1000);
            }

            $this->writeJson($aggRelPath, $existing);

            // ── Symbol freeze registration & auto blacklist ───────────────────
            $tradeConfig = $this->getConfig();
            $this->registerSymbolFreeze($trade, $tradeConfig, $tickAt);
            $this->checkAndUpdateAutoBlacklist($trade, $tradeConfig, $tickAt);

        } catch (\Throwable) {
            // Never crash the bot tick over journal write failures
        }
    }

    // =========================================================================
    // Symbol freeze & blacklist helpers
    // =========================================================================

    private function loadFreezeRegistry(): array
    {
        $path = $this->moduleDir . '/storage/runtime/symbol_freeze_registry.json';
        try {
            if (!is_file($path)) {
                return [];
            }
            $raw = @file_get_contents($path);
            if ($raw === false || $raw === '') {
                return [];
            }
            $dec = @json_decode($raw, true);
            return is_array($dec) ? $dec : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function saveFreezeRegistry(array $registry): void
    {
        $path = $this->moduleDir . '/storage/runtime/symbol_freeze_registry.json';
        try {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            @file_put_contents(
                $path,
                json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                LOCK_EX
            );
        } catch (\Throwable) {
        }
    }

    /**
     * Check if a symbol is currently frozen.
     *
     * @return array{frozen: bool, frozen_until: ?string}
     */
    private function isSymbolFrozen(string $symbol, string $mode, string $side, string $tickAt): array
    {
        if ($symbol === '' || $mode === '' || $side === '') {
            return ['frozen' => false, 'frozen_until' => null];
        }
        try {
            $registry = $this->loadFreezeRegistry();
            $key      = $symbol . '_' . $mode . '_' . $side;
            if (!isset($registry[$key])) {
                return ['frozen' => false, 'frozen_until' => null];
            }
            $entry       = $registry[$key];
            $frozenUntil = (string)($entry['frozen_until'] ?? '');
            if ($frozenUntil === '') {
                return ['frozen' => false, 'frozen_until' => null];
            }
            $frozenTs = @strtotime($frozenUntil);
            $nowTs    = time();
            if ($frozenTs !== false && $frozenTs > $nowTs) {
                return ['frozen' => true, 'frozen_until' => $frozenUntil];
            }
        } catch (\Throwable) {
        }
        return ['frozen' => false, 'frozen_until' => null];
    }

    /**
     * Register a symbol freeze after a position close.
     *
     * For double_bottom_long trades (when adaptive freeze is enabled) the
     * freeze duration is determined by resolveAdaptiveFreezeProfile() based on
     * close result type and ROI.  All other strategies continue to use the
     * flat global symbol_freeze_after_close_minutes value.
     */
    private function registerSymbolFreeze(array $trade, array $config, string $tickAt): void
    {
        if (!(bool)($config['symbol_freeze_after_close_enabled'] ?? false)) {
            return;
        }
        try {
            $symbol      = (string)($trade['symbol']        ?? '');
            $side        = (string)($trade['side']          ?? '');
            $mode        = (string)($trade['mode']          ?? 'demo');
            $closeSource = (string)($trade['close_source']  ?? 'unknown');
            $closedAt    = (string)($trade['closed_at']     ?? $tickAt);

            if ($symbol === '' || $side === '') {
                return;
            }

            // Mode gate
            $freezeModes = (array)($config['symbol_freeze_modes'] ?? ['demo', 'live']);
            if (!in_array($mode, $freezeModes, true)) {
                return;
            }

            // Source gate — apply based on config
            $applyToProfit = (bool)($config['symbol_freeze_apply_to_profit_close'] ?? true);
            $applyToStop   = (bool)($config['symbol_freeze_apply_to_stop_close']   ?? true);
            $applyToLoss   = (bool)($config['symbol_freeze_apply_to_loss_close']   ?? true);
            $applyToManual = (bool)($config['symbol_freeze_apply_to_manual_close'] ?? true);

            $shouldFreeze = false;
            if (in_array($closeSource, ['profit_manager', 'pm_market_close'], true) && $applyToProfit) {
                $shouldFreeze = true;
            } elseif (in_array($closeSource, ['stop_manager', 'stop_loss', 'stop_market_close'], true) && $applyToStop) {
                $shouldFreeze = true;
            } elseif (in_array($closeSource, ['manual', 'manual_close', 'operator'], true) && $applyToManual) {
                $shouldFreeze = true;
            } else {
                // Check ROI for loss close
                $roi    = ($trade['roi'] ?? null);
                $pnl    = ($trade['pnl'] ?? null);
                $isLoss = ($roi !== null && (float)$roi < 0.0)
                    || ($pnl !== null && (float)$pnl < 0.0);
                if ($isLoss && $applyToLoss) {
                    $shouldFreeze = true;
                } elseif ($applyToProfit || $applyToStop || $applyToManual) {
                    // Unknown source — freeze by default when any freeze is enabled
                    $shouldFreeze = true;
                }
            }

            if (!$shouldFreeze) {
                return;
            }

            // ── Determine freeze duration (adaptive vs flat) ──────────────────
            // Strategy detection with full fallback chain to ensure double_bottom_long
            // trades are always caught even when stored under different field names.
            $stratId       = (string)($trade['strategy_id']    ?? '');
            $ownerStrategy = (string)($trade['owner_strategy'] ?? '');
            $strategyField = (string)($trade['strategy']       ?? '');
            $ctx           = is_array($trade['strategy_signal_context'] ?? null) ? $trade['strategy_signal_context'] : [];
            $ctxOwner      = (string)($ctx['owner_strategy']  ?? '');
            $ctxStratId    = (string)($ctx['strategy_id']     ?? '');
            $ctxSrcStrat   = (string)($ctx['source_strategy'] ?? '');

            // Derive the detected strategy name using the priority fallback chain.
            $detectedStrategy = '';
            foreach ([$ownerStrategy, $stratId, $strategyField, $ctxOwner, $ctxStratId, $ctxSrcStrat] as $strategyCandidate) {
                if ($strategyCandidate !== '') {
                    $detectedStrategy = $strategyCandidate;
                    break;
                }
            }

            $isDoubleBottom = ($detectedStrategy === 'double_bottom_long');

            $adaptiveEnabled = (bool)($config['symbol_freeze_adaptive_enabled']          ?? false);
            $dbEnabled       = (bool)($config['symbol_freeze_double_bottom_enabled']     ?? false);

            $freezeProfile   = null;
            $freezeReason    = 'position_closed';
            $missingRoiWarn  = false;

            if ($adaptiveEnabled && $dbEnabled && $isDoubleBottom) {
                $profileResult = $this->resolveAdaptiveFreezeProfile($trade, $config);
                $minutes       = $profileResult['minutes'];
                $freezeProfile = $profileResult['profile'];
                $freezeReason  = $profileResult['reason'];
                $missingRoiWarn = $profileResult['missing_roi'] ?? false;

                // Record cumulative adaptive stats
                $this->recordAdaptiveFreezeStats($trade, $profileResult, $tickAt, $config);
            } else {
                $minutes = max(1, (int)($config['symbol_freeze_after_close_minutes'] ?? 10));

                // Record not-applied diagnostic when the trade is double_bottom_long but
                // adaptive profile was not used (disabled in config or flags not set).
                if ($isDoubleBottom) {
                    $notAppliedReason = !$adaptiveEnabled
                        ? 'adaptive_disabled'
                        : 'double_bottom_profile_disabled';
                    $this->recordNotAppliedFreezeStats($trade, $detectedStrategy, $notAppliedReason, $tickAt);
                }
            }

            $closedTs      = @strtotime($closedAt);
            $baseTs        = ($closedTs !== false && $closedTs > 0) ? $closedTs : time();
            $frozenUntilTs = $baseTs + ($minutes * 60);
            $frozenUntil   = date('c', $frozenUntilTs);

            $registry  = $this->loadFreezeRegistry();
            $key       = $symbol . '_' . $mode . '_' . $side;

            $entry = [
                'symbol'          => $symbol,
                'side'            => $side,
                'mode'            => $mode,
                'strategy'        => $detectedStrategy ?: null,
                'strategy_id'     => $stratId   ?: null,
                'owner_strategy'  => $ownerStrategy ?: null,
                'source'          => 'post_close',
                'reason'          => $freezeReason,
                'close_source'    => $closeSource,
                'close_reason'    => (string)($trade['close_reason'] ?? '') ?: null,
                'close_guard'     => (string)($trade['close_guard']  ?? '') ?: null,
                'roi'             => isset($trade['roi']) ? (float)$trade['roi'] : null,
                'signal_id'       => (string)($trade['signal_id']    ?? '') ?: null,
                'closed_at'       => $closedAt,
                'frozen_until'    => $frozenUntil,
                'freeze_minutes'  => $minutes,
                'created_at'      => $tickAt,
            ];
            if ($freezeProfile !== null) {
                $entry['freeze_profile'] = $freezeProfile;
            }
            if ($missingRoiWarn) {
                $entry['diagnostic_warning'] = 'roi_missing_used_default';
            }

            $registry[$key] = $entry;
            $this->saveFreezeRegistry($registry);
        } catch (\Throwable) {
        }
    }

    /**
     * Resolve the adaptive freeze profile (duration + label) for a
     * double_bottom_long trade based on close metadata and ROI.
     *
     * Priority order:
     *   1. emergency_stop guard  → emergency_stop profile
     *   2. early_fail guard      → early_fail profile
     *   3. ROI > 0               → profit tier (extreme / strong / normal / small)
     *   4. ROI <= deep_loss_roi  → deep_loss profile
     *   5. ROI < 0               → generic loss profile
     *   6. ROI missing           → default profile + warning flag
     *
     * @return array{profile: string, minutes: int, reason: string, missing_roi: bool}
     */
    private function resolveAdaptiveFreezeProfile(array $trade, array $config): array
    {
        $closeGuard = (string)($trade['close_guard'] ?? '');
        $roi        = isset($trade['roi']) ? (float)$trade['roi'] : null;

        // Guard: emergency stop (highest priority)
        if ($closeGuard === 'double_bottom_emergency_stop') {
            return [
                'profile'     => 'double_bottom_emergency_stop',
                'minutes'     => max(1, (int)($config['symbol_freeze_double_bottom_emergency_stop_minutes'] ?? 2880)),
                'reason'      => 'double_bottom_emergency_stop_freeze',
                'missing_roi' => false,
            ];
        }

        // Guard: early fail (second priority)
        if ($closeGuard === 'double_bottom_early_fail') {
            return [
                'profile'     => 'double_bottom_early_fail',
                'minutes'     => max(1, (int)($config['symbol_freeze_double_bottom_early_fail_minutes'] ?? 1440)),
                'reason'      => 'double_bottom_early_fail_freeze',
                'missing_roi' => false,
            ];
        }

        // ROI missing → default
        if ($roi === null) {
            return [
                'profile'     => 'double_bottom_default_missing_roi',
                'minutes'     => max(1, (int)($config['symbol_freeze_double_bottom_default_minutes'] ?? 720)),
                'reason'      => 'double_bottom_default_missing_roi_freeze',
                'missing_roi' => true,
            ];
        }

        // Profit tiers (roi > 0 required)
        if ($roi >= 0.0) {
            $extremeMin = (float)($config['symbol_freeze_double_bottom_profit_extreme_min_roi'] ?? 40.0);
            $strongMin  = (float)($config['symbol_freeze_double_bottom_profit_strong_min_roi']  ?? 20.0);
            $normalMin  = (float)($config['symbol_freeze_double_bottom_profit_normal_min_roi']  ?? 5.0);
            $smallMin   = (float)($config['symbol_freeze_double_bottom_profit_small_min_roi']   ?? 0.0);

            if ($roi >= $extremeMin) {
                return [
                    'profile'     => 'double_bottom_profit_extreme',
                    'minutes'     => max(1, (int)($config['symbol_freeze_double_bottom_profit_extreme_minutes'] ?? 1440)),
                    'reason'      => 'double_bottom_profit_extreme_freeze',
                    'missing_roi' => false,
                ];
            }
            if ($roi >= $strongMin) {
                return [
                    'profile'     => 'double_bottom_profit_strong',
                    'minutes'     => max(1, (int)($config['symbol_freeze_double_bottom_profit_strong_minutes'] ?? 1080)),
                    'reason'      => 'double_bottom_profit_strong_freeze',
                    'missing_roi' => false,
                ];
            }
            if ($roi >= $normalMin) {
                return [
                    'profile'     => 'double_bottom_profit_normal',
                    'minutes'     => max(1, (int)($config['symbol_freeze_double_bottom_profit_normal_minutes'] ?? 720)),
                    'reason'      => 'double_bottom_profit_normal_freeze',
                    'missing_roi' => false,
                ];
            }
            if ($roi >= $smallMin) {
                return [
                    'profile'     => 'double_bottom_profit_small',
                    'minutes'     => max(1, (int)($config['symbol_freeze_double_bottom_profit_small_minutes'] ?? 360)),
                    'reason'      => 'double_bottom_profit_small_freeze',
                    'missing_roi' => false,
                ];
            }
        }

        // Deep loss
        $deepLossRoi = (float)($config['symbol_freeze_double_bottom_deep_loss_roi'] ?? -30.0);
        if ($roi <= $deepLossRoi) {
            return [
                'profile'     => 'double_bottom_deep_loss',
                'minutes'     => max(1, (int)($config['symbol_freeze_double_bottom_deep_loss_minutes'] ?? 2880)),
                'reason'      => 'double_bottom_deep_loss_freeze',
                'missing_roi' => false,
            ];
        }

        // Generic loss
        return [
            'profile'     => 'double_bottom_loss',
            'minutes'     => max(1, (int)($config['symbol_freeze_double_bottom_loss_minutes'] ?? 1440)),
            'reason'      => 'double_bottom_loss_freeze',
            'missing_roi' => false,
        ];
    }

    /**
     * Increment cumulative adaptive freeze counters and append to examples.
     * Written to storage/symbol_freeze_adaptive_stats.json on every apply.
     */
    private function recordAdaptiveFreezeStats(
        array  $trade,
        array  $profileResult,
        string $tickAt,
        array  $config
    ): void {
        try {
            $stats   = $this->loadAdaptiveFreezeStats();
            $profile = (string)($profileResult['profile'] ?? 'double_bottom_default_missing_roi');

            $stats['applied_total'] = (int)($stats['applied_total'] ?? 0) + 1;

            $counterKey = 'profile_' . $profile . '_total';
            $stats[$counterKey] = (int)($stats[$counterKey] ?? 0) + 1;

            if ($profileResult['missing_roi'] ?? false) {
                $stats['missing_roi_total'] = (int)($stats['missing_roi_total'] ?? 0) + 1;
            }

            // Append example (keep last 10)
            $example = [
                'symbol'         => (string)($trade['symbol']      ?? ''),
                'signal_id'      => (string)($trade['signal_id']   ?? '') ?: null,
                'roi'            => isset($trade['roi']) ? (float)$trade['roi'] : null,
                'close_guard'    => (string)($trade['close_guard']  ?? '') ?: null,
                'close_reason'   => (string)($trade['close_reason'] ?? '') ?: null,
                'close_source'   => (string)($trade['close_source'] ?? '') ?: null,
                'freeze_profile' => $profile,
                'freeze_minutes' => (int)($profileResult['minutes'] ?? 0),
                'strategy'       => 'double_bottom_long',
                'created_at'     => $tickAt,
            ];
            $examples   = (array)($stats['examples'] ?? []);
            $examples[] = $example;
            if (count($examples) > 10) {
                $examples = array_slice($examples, -10);
            }
            $stats['examples']     = $examples;
            $stats['last_updated'] = $tickAt;

            $this->saveAdaptiveFreezeStats($stats);
        } catch (\Throwable) {
        }
    }

    /**
     * Record a diagnostic entry when a double_bottom_long trade was identified but
     * the adaptive freeze profile was not applied (e.g., adaptive flag disabled).
     *
     * Writes to the same symbol_freeze_adaptive_stats.json under
     * `not_applied_total` and `not_applied_examples`.
     */
    private function recordNotAppliedFreezeStats(
        array  $trade,
        string $detectedStrategy,
        string $reasonNotApplied,
        string $tickAt
    ): void {
        try {
            $stats = $this->loadAdaptiveFreezeStats();

            $stats['not_applied_total'] = (int)($stats['not_applied_total'] ?? 0) + 1;

            $example = [
                'symbol'            => (string)($trade['symbol']         ?? ''),
                'signal_id'         => (string)($trade['signal_id']      ?? '') ?: null,
                'roi'               => isset($trade['roi']) ? (float)$trade['roi'] : null,
                'strategy_id'       => (string)($trade['strategy_id']    ?? '') ?: null,
                'owner_strategy'    => (string)($trade['owner_strategy'] ?? '') ?: null,
                'detected_strategy' => $detectedStrategy,
                'close_reason'      => (string)($trade['close_reason']   ?? '') ?: null,
                'reason_not_applied'=> $reasonNotApplied,
                'created_at'        => $tickAt,
            ];
            $examples   = (array)($stats['not_applied_examples'] ?? []);
            $examples[] = $example;
            if (count($examples) > 10) {
                $examples = array_slice($examples, -10);
            }
            $stats['not_applied_examples'] = $examples;
            $stats['last_updated']         = $tickAt;

            $this->saveAdaptiveFreezeStats($stats);
        } catch (\Throwable) {
        }
    }

    private function loadAdaptiveFreezeStats(): array
    {
        $path = $this->moduleDir . '/storage/symbol_freeze_adaptive_stats.json';
        try {
            if (!is_file($path)) {
                return [];
            }
            $raw = @file_get_contents($path);
            if ($raw === false || $raw === '') {
                return [];
            }
            $dec = @json_decode($raw, true);
            return is_array($dec) ? $dec : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function saveAdaptiveFreezeStats(array $stats): void
    {
        $path = $this->moduleDir . '/storage/symbol_freeze_adaptive_stats.json';
        try {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            @file_put_contents(
                $path,
                json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                LOCK_EX
            );
        } catch (\Throwable) {
        }
    }

    private function loadSymbolBlacklist(): array
    {
        $path = $this->moduleDir . '/storage/runtime/symbol_blacklist.json';
        try {
            if (!is_file($path)) {
                return [];
            }
            $raw = @file_get_contents($path);
            if ($raw === false || $raw === '') {
                return [];
            }
            $dec = @json_decode($raw, true);
            return is_array($dec) ? $dec : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function saveSymbolBlacklist(array $blacklist): void
    {
        $path = $this->moduleDir . '/storage/runtime/symbol_blacklist.json';
        try {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            @file_put_contents(
                $path,
                json_encode($blacklist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                LOCK_EX
            );
        } catch (\Throwable) {
        }
    }

    /**
     * Check if a symbol is currently blacklisted (manual or auto).
     *
     * @return array{blacklisted: bool, source: ?string, reason: ?string, blocked_until: ?string}
     */
    private function isSymbolBlacklisted(string $symbol, string $mode): array
    {
        $notBlacklisted = ['blacklisted' => false, 'source' => null, 'reason' => null, 'blocked_until' => null];
        if ($symbol === '') {
            return $notBlacklisted;
        }
        try {
            $config  = $this->getConfig();
            if (!(bool)($config['symbol_blacklist_enabled'] ?? false)) {
                return $notBlacklisted;
            }

            // Check config manual_symbol_blacklist (always enforced when enabled)
            $configList = (array)($config['manual_symbol_blacklist'] ?? []);
            $normalised = array_map('strtoupper', $configList);
            if (in_array(strtoupper($symbol), $normalised, true)) {
                return [
                    'blacklisted'  => true,
                    'source'       => 'manual',
                    'reason'       => 'manual_operator_block',
                    'blocked_until'=> null,
                ];
            }

            // Check runtime symbol_blacklist.json
            $blacklist = $this->loadSymbolBlacklist();
            $sym       = strtoupper($symbol);
            $entry     = $blacklist[$sym] ?? ($blacklist[$symbol] ?? null);
            if ($entry === null) {
                return $notBlacklisted;
            }

            if (!(bool)($entry['enabled'] ?? true)) {
                return $notBlacklisted;
            }

            $source      = (string)($entry['source']       ?? 'manual');
            $reason      = (string)($entry['reason']       ?? 'manual_operator_block');
            $blockedUntil= $entry['blocked_until'] ?? null;

            // For auto entries, check blocked_until
            if ($source === 'auto' && $blockedUntil !== null) {
                $blockedTs = @strtotime((string)$blockedUntil);
                if ($blockedTs !== false && $blockedTs <= time()) {
                    // Expired
                    return $notBlacklisted;
                }
            }

            // For manual entries, blocked_until=null means permanent (no expiry check needed)
            return [
                'blacklisted'  => true,
                'source'       => $source,
                'reason'       => $reason,
                'blocked_until'=> $blockedUntil !== null ? (string)$blockedUntil : null,
            ];
        } catch (\Throwable) {
            return $notBlacklisted;
        }
    }

    /**
     * After a closed trade, check if auto-blacklist threshold is reached.
     * If yes, write/update symbol_blacklist.json.
     */
    private function checkAndUpdateAutoBlacklist(array $trade, array $config, string $tickAt): void
    {
        if (!(bool)($config['auto_blacklist_enabled'] ?? false)) {
            return;
        }
        try {
            $symbol    = (string)($trade['symbol']  ?? '');
            $mode      = (string)($trade['mode']    ?? 'demo');
            $roi       = $trade['roi'] ?? null;
            $pnl       = $trade['pnl'] ?? null;
            $closedAt  = (string)($trade['closed_at'] ?? $tickAt);

            if ($symbol === '') {
                return;
            }

            // Mode gate
            $autoModes = (array)($config['auto_blacklist_modes'] ?? ['demo', 'live']);
            if (!in_array($mode, $autoModes, true)) {
                return;
            }

            // Is this a loss?
            $isLoss = ($roi !== null && (float)$roi < 0.0) || ($pnl !== null && (float)$pnl < 0.0);

            $resetOnWin     = (bool)($config['auto_blacklist_reset_on_win'] ?? false);
            $windowHours    = max(1, (int)($config['auto_blacklist_window_hours'] ?? 24));
            $durationHours  = max(1, (int)($config['auto_blacklist_duration_hours'] ?? 24));
            $threshold      = max(1, (int)($config['auto_blacklist_loss_threshold'] ?? 3));

            // Load closed trades to count losses
            $closedTrades = $this->readJson('storage/trades/closed_trades.json', []);
            $windowSec    = $windowHours * 3600;
            $nowTs        = time();
            $sym          = strtoupper($symbol);

            $lossCount = 0;
            foreach ($closedTrades as $ct) {
                $ctSym  = strtoupper((string)($ct['symbol'] ?? ''));
                if ($ctSym !== $sym) {
                    continue;
                }
                $ctMode = (string)($ct['mode'] ?? 'demo');
                if (!in_array($ctMode, $autoModes, true)) {
                    continue;
                }
                $ctClosedAt = (string)($ct['closed_at'] ?? '');
                $ctTs       = ($ctClosedAt !== '') ? @strtotime($ctClosedAt) : false;
                if ($ctTs === false || ($nowTs - $ctTs) > $windowSec) {
                    continue;
                }
                $ctRoi = $ct['roi'] ?? null;
                $ctPnl = $ct['pnl'] ?? null;
                $ctLoss = ($ctRoi !== null && (float)$ctRoi < 0.0) || ($ctPnl !== null && (float)$ctPnl < 0.0);
                if (!$ctLoss) {
                    continue;
                }
                $lossCount++;
            }

            // Handle reset on win
            if (!$isLoss && $resetOnWin) {
                // Win: clear auto-blacklist entry if present
                $blacklist = $this->loadSymbolBlacklist();
                $key = strtoupper($symbol);
                if (isset($blacklist[$key]) && ($blacklist[$key]['source'] ?? '') === 'auto') {
                    unset($blacklist[$key]);
                    $this->saveSymbolBlacklist($blacklist);
                }
                return;
            }

            if (!$isLoss) {
                return;
            }

            if ($lossCount < $threshold) {
                return;
            }

            // Threshold reached — add/update auto blacklist
            $blockedUntilTs  = $nowTs + ($durationHours * 3600);
            $blockedUntil    = date('c', $blockedUntilTs);

            $blacklist = $this->loadSymbolBlacklist();
            $key       = strtoupper($symbol);
            $blacklist[$key] = [
                'symbol'       => strtoupper($symbol),
                'source'       => 'auto',
                'reason'       => 'loss_streak_threshold',
                'loss_count'   => $lossCount,
                'window_hours' => $windowHours,
                'created_at'   => $tickAt,
                'blocked_until'=> $blockedUntil,
                'enabled'      => true,
            ];
            $this->saveSymbolBlacklist($blacklist);
        } catch (\Throwable) {
        }
    }

    /**
     * Check freeze and blacklist gates for a symbol before creating a queue item.
     *
     * @return array{blocked: bool, reason: string, frozen_until: ?string, blocked_until: ?string}
     */
    private function checkFreezeBlacklistGate(
        string $symbol,
        string $mode,
        string $side,
        string $tickAt,
        array  $config
    ): array {
        $notBlocked = ['blocked' => false, 'reason' => '', 'frozen_until' => null, 'blocked_until' => null];

        if ($symbol === '') {
            return $notBlocked;
        }

        // ── Freeze check ──────────────────────────────────────────────────────
        if ((bool)($config['symbol_freeze_after_close_enabled'] ?? false)) {
            $freezeModes = (array)($config['symbol_freeze_modes'] ?? ['demo', 'live']);
            if (in_array($mode, $freezeModes, true)) {
                $freezeResult = $this->isSymbolFrozen($symbol, $mode, $side, $tickAt);
                if ($freezeResult['frozen']) {
                    return [
                        'blocked'      => true,
                        'reason'       => 'symbol_frozen_after_close',
                        'frozen_until' => $freezeResult['frozen_until'],
                        'blocked_until'=> null,
                    ];
                }
            }
        }

        // ── Blacklist check ───────────────────────────────────────────────────
        if ((bool)($config['symbol_blacklist_enabled'] ?? false)) {
            $blResult = $this->isSymbolBlacklisted($symbol, $mode);
            if ($blResult['blacklisted']) {
                $reason = match ($blResult['source'] ?? '') {
                    'manual' => 'symbol_blacklisted_manual',
                    'auto'   => 'symbol_blacklisted_auto',
                    default  => 'symbol_blacklisted_manual',
                };
                return [
                    'blocked'      => true,
                    'reason'       => $reason,
                    'frozen_until' => null,
                    'blocked_until'=> $blResult['blocked_until'],
                ];
            }
        }

        return $notBlocked;
    }

    /**
     * Scan existing queued/ready order_queue items and mark them skipped if
     * the symbol is now frozen or blacklisted.
     *
     * Only affects items in 'queued' or 'ready' status.
     * Terminal/submitted items are never touched.
     *
     * @return array{order_queue: array, blocked_by_freeze: int, blocked_by_manual_blacklist: int, blocked_by_auto_blacklist: int}
     */
    private function applyFreezeBlacklistToQueue(
        array  $orderQueue,
        array  $config,
        string $botMode,
        string $tickAt
    ): array {
        $blockedByFreeze   = 0;
        $blockedByManual   = 0;
        $blockedByAuto     = 0;

        $freezeEnabled     = (bool)($config['symbol_freeze_after_close_enabled'] ?? false);
        $blacklistEnabled  = (bool)($config['symbol_blacklist_enabled'] ?? false);

        if (!$freezeEnabled && !$blacklistEnabled) {
            return [
                'order_queue'                => $orderQueue,
                'blocked_by_freeze'          => 0,
                'blocked_by_manual_blacklist'=> 0,
                'blocked_by_auto_blacklist'  => 0,
            ];
        }

        foreach ($orderQueue as &$item) {
            $status = (string)($item['queue_status'] ?? '');
            if (!in_array($status, ['queued', 'ready'], true)) {
                continue;
            }
            $symbol = (string)($item['symbol']         ?? '');
            $side   = (string)($item['side']           ?? '');
            $mode   = (string)($item['execution_mode'] ?? $botMode);

            $gate = $this->checkFreezeBlacklistGate($symbol, $mode, $side, $tickAt, $config);
            if (!$gate['blocked']) {
                continue;
            }

            $item['queue_status']       = 'skipped';
            $item['exit_at']            = $tickAt;
            $item['last_change_reason'] = $gate['reason'];

            if ($gate['reason'] === 'symbol_frozen_after_close') {
                $item['frozen_until'] = $gate['frozen_until'];
                $blockedByFreeze++;
            } elseif ($gate['reason'] === 'symbol_blacklisted_manual') {
                $item['blocked_until'] = $gate['blocked_until'];
                $blockedByManual++;
            } elseif ($gate['reason'] === 'symbol_blacklisted_auto') {
                $item['blocked_until'] = $gate['blocked_until'];
                $blockedByAuto++;
            }
        }
        unset($item);

        return [
            'order_queue'                => $orderQueue,
            'blocked_by_freeze'          => $blockedByFreeze,
            'blocked_by_manual_blacklist'=> $blockedByManual,
            'blocked_by_auto_blacklist'  => $blockedByAuto,
        ];
    }

    /**
     * Compute freeze/blacklist diagnostics for last_run.json.
     *
     * Performs lazy expiry cleanup of both registries.
     * Also surfaces adaptive freeze counters from the persistent stats file.
     *
     * @return array{
     *   symbol_freeze_enabled: bool,
     *   symbol_freeze_after_close_minutes: int,
     *   symbol_freeze_registry_entries_total: int,
     *   symbol_freeze_active_total: int,
     *   symbol_freeze_expired_removed_total: int,
     *   symbol_blacklist_enabled: bool,
     *   manual_blacklist_total: int,
     *   auto_blacklist_total: int,
     *   auto_blacklist_active_total: int,
     *   auto_blacklist_expired_removed_total: int,
     *   symbol_freeze_adaptive_enabled: bool,
     *   symbol_freeze_double_bottom_applied_total: int,
     *   symbol_freeze_double_bottom_profit_small_total: int,
     *   symbol_freeze_double_bottom_profit_normal_total: int,
     *   symbol_freeze_double_bottom_profit_strong_total: int,
     *   symbol_freeze_double_bottom_profit_extreme_total: int,
     *   symbol_freeze_double_bottom_loss_total: int,
     *   symbol_freeze_double_bottom_deep_loss_total: int,
     *   symbol_freeze_double_bottom_early_fail_total: int,
     *   symbol_freeze_double_bottom_emergency_stop_total: int,
     *   symbol_freeze_double_bottom_missing_roi_total: int,
     *   symbol_freeze_double_bottom_examples: array,
     * }
     */
    private function computeFreezeBlacklistDiagnostics(array $config): array
    {
        $result = [
            'symbol_freeze_enabled'               => (bool)($config['symbol_freeze_after_close_enabled'] ?? false),
            'symbol_freeze_after_close_minutes'   => (int)($config['symbol_freeze_after_close_minutes'] ?? 10),
            'symbol_freeze_registry_entries_total'=> 0,
            'symbol_freeze_active_total'          => 0,
            'symbol_freeze_expired_removed_total' => 0,
            'symbol_blacklist_enabled'            => (bool)($config['symbol_blacklist_enabled'] ?? false),
            'manual_blacklist_total'              => 0,
            'auto_blacklist_total'                => 0,
            'auto_blacklist_active_total'         => 0,
            'auto_blacklist_expired_removed_total'=> 0,
            // Adaptive freeze counters (cumulative, sourced from adaptive_stats file)
            'symbol_freeze_adaptive_enabled'                         => (bool)($config['symbol_freeze_adaptive_enabled'] ?? false),
            'symbol_freeze_double_bottom_applied_total'              => 0,
            'symbol_freeze_double_bottom_profit_small_total'         => 0,
            'symbol_freeze_double_bottom_profit_normal_total'        => 0,
            'symbol_freeze_double_bottom_profit_strong_total'        => 0,
            'symbol_freeze_double_bottom_profit_extreme_total'       => 0,
            'symbol_freeze_double_bottom_loss_total'                 => 0,
            'symbol_freeze_double_bottom_deep_loss_total'            => 0,
            'symbol_freeze_double_bottom_early_fail_total'           => 0,
            'symbol_freeze_double_bottom_emergency_stop_total'       => 0,
            'symbol_freeze_double_bottom_missing_roi_total'          => 0,
            'symbol_freeze_double_bottom_examples'                   => [],
            // Not-applied diagnostics (double_bottom_long detected but adaptive skipped)
            'symbol_freeze_double_bottom_not_applied_total'          => 0,
            'symbol_freeze_double_bottom_not_applied_examples'       => [],
        ];

        $now = time();

        // ── Freeze registry ───────────────────────────────────────────────────
        try {
            $freezeRegistry = $this->loadFreezeRegistry();
            $changed        = false;
            foreach ($freezeRegistry as $key => $entry) {
                $frozenUntil = (string)($entry['frozen_until'] ?? '');
                $frozenTs    = ($frozenUntil !== '') ? @strtotime($frozenUntil) : false;
                if ($frozenTs !== false && $frozenTs > $now) {
                    $result['symbol_freeze_registry_entries_total']++;
                    $result['symbol_freeze_active_total']++;
                } else {
                    unset($freezeRegistry[$key]);
                    $result['symbol_freeze_expired_removed_total']++;
                    $changed = true;
                }
            }
            if ($changed) {
                $this->saveFreezeRegistry($freezeRegistry);
            }
        } catch (\Throwable) {
        }

        // ── Blacklist ─────────────────────────────────────────────────────────
        try {
            // Config manual list
            $configList = (array)($config['manual_symbol_blacklist'] ?? []);
            $result['manual_blacklist_total'] = count($configList);

            $blacklist   = $this->loadSymbolBlacklist();
            $changed     = false;
            foreach ($blacklist as $sym => $entry) {
                $source       = (string)($entry['source'] ?? 'manual');
                $blockedUntil = $entry['blocked_until'] ?? null;
                $enabled      = (bool)($entry['enabled'] ?? true);

                if ($source === 'auto') {
                    $result['auto_blacklist_total']++;
                    if ($enabled && $blockedUntil !== null) {
                        $blockedTs = @strtotime((string)$blockedUntil);
                        if ($blockedTs !== false && $blockedTs > $now) {
                            $result['auto_blacklist_active_total']++;
                        } else {
                            unset($blacklist[$sym]);
                            $result['auto_blacklist_expired_removed_total']++;
                            $changed = true;
                        }
                    } elseif ($enabled && $blockedUntil === null) {
                        $result['auto_blacklist_active_total']++;
                    }
                } else {
                    // Manual runtime entries
                    $result['manual_blacklist_total']++;
                }
            }
            if ($changed) {
                $this->saveSymbolBlacklist($blacklist);
            }
        } catch (\Throwable) {
        }

        // ── Adaptive freeze stats ─────────────────────────────────────────────
        try {
            $adaptStats = $this->loadAdaptiveFreezeStats();
            if (!empty($adaptStats)) {
                $result['symbol_freeze_double_bottom_applied_total']        = (int)($adaptStats['applied_total']                                              ?? 0);
                $result['symbol_freeze_double_bottom_profit_small_total']   = (int)($adaptStats['profile_double_bottom_profit_small_total']                   ?? 0);
                $result['symbol_freeze_double_bottom_profit_normal_total']  = (int)($adaptStats['profile_double_bottom_profit_normal_total']                  ?? 0);
                $result['symbol_freeze_double_bottom_profit_strong_total']  = (int)($adaptStats['profile_double_bottom_profit_strong_total']                  ?? 0);
                $result['symbol_freeze_double_bottom_profit_extreme_total'] = (int)($adaptStats['profile_double_bottom_profit_extreme_total']                 ?? 0);
                $result['symbol_freeze_double_bottom_loss_total']           = (int)($adaptStats['profile_double_bottom_loss_total']                           ?? 0);
                $result['symbol_freeze_double_bottom_deep_loss_total']      = (int)($adaptStats['profile_double_bottom_deep_loss_total']                      ?? 0);
                $result['symbol_freeze_double_bottom_early_fail_total']     = (int)($adaptStats['profile_double_bottom_early_fail_total']                     ?? 0);
                $result['symbol_freeze_double_bottom_emergency_stop_total'] = (int)($adaptStats['profile_double_bottom_emergency_stop_total']                 ?? 0);
                $result['symbol_freeze_double_bottom_missing_roi_total']    = (int)($adaptStats['missing_roi_total']                                          ?? 0);
                $result['symbol_freeze_double_bottom_examples']             = array_slice((array)($adaptStats['examples']           ?? []), -5);
                $result['symbol_freeze_double_bottom_not_applied_total']    = (int)($adaptStats['not_applied_total']                                          ?? 0);
                $result['symbol_freeze_double_bottom_not_applied_examples'] = array_slice((array)($adaptStats['not_applied_examples'] ?? []), -5);
            }
        } catch (\Throwable) {
        }

        return $result;
    }

    /**
     * Compute simulation statistics for candidate double_bottom_long freeze durations.
     *
     * Reads closed_trades.json and simulates how repeat same-symbol entries
     * would have been blocked at each candidate freeze duration.
     * Writes storage/symbol_freeze_duration_stats.json.
     *
     * This is diagnostics only — does not affect any trading behavior.
     */
    private function computeSymbolFreezeDurationStats(array $config): void
    {
        if (!(bool)($config['symbol_freeze_after_close_enabled'] ?? false)) {
            return;
        }
        try {
            $allTrades = $this->readJson('storage/trades/closed_trades.json', []);

            // Filter to double_bottom_long, sort by opened_at ascending
            $dbTrades = [];
            foreach ($allTrades as $ct) {
                $stratId = (string)($ct['strategy_id']    ?? '');
                $ownerS  = (string)($ct['owner_strategy'] ?? '');
                if ($stratId === 'double_bottom_long' || $ownerS === 'double_bottom_long') {
                    $dbTrades[] = $ct;
                }
            }

            if (empty($dbTrades)) {
                return;
            }

            // Sort by opened_at ascending
            usort($dbTrades, static function (array $a, array $b): int {
                $ta = @strtotime((string)($a['opened_at'] ?? $a['closed_at'] ?? '')) ?: 0;
                $tb = @strtotime((string)($b['opened_at'] ?? $b['closed_at'] ?? '')) ?: 0;
                return $ta <=> $tb;
            });

            $candidateDurations = [190, 360, 720, 1080, 1440, 2880];
            $durationStats      = [];

            foreach ($candidateDurations as $durationMin) {
                $durationSec = $durationMin * 60;
                // Map: symbol -> ts of last close that would trigger freeze
                $frozenUntil = [];

                $repeatEntriesTotal     = 0;
                $blockedWinningTotal    = 0;
                $blockedLosingTotal     = 0;
                $blockedRoiSum          = 0.0;
                $blockedPnlSum          = 0.0;

                foreach ($dbTrades as $ct) {
                    $symbol   = (string)($ct['symbol']    ?? '');
                    $closedAt = (string)($ct['closed_at'] ?? '');
                    $openedAt = (string)($ct['opened_at'] ?? $ct['entry_time'] ?? $ct['created_at'] ?? '');
                    $roi      = isset($ct['roi']) ? (float)$ct['roi'] : null;
                    $pnl      = isset($ct['pnl']) ? (float)$ct['pnl'] : null;

                    if ($symbol === '' || $openedAt === '') {
                        continue;
                    }

                    $openTs  = @strtotime($openedAt) ?: 0;
                    $closeTs = $closedAt !== '' ? (@strtotime($closedAt) ?: $openTs) : $openTs;

                    // Check if this entry would be blocked by a prior close freeze
                    if (isset($frozenUntil[$symbol]) && $openTs > 0 && $frozenUntil[$symbol] >= $openTs) {
                        // This would have been blocked
                        $repeatEntriesTotal++;
                        if ($roi !== null && $roi > 0.0) {
                            $blockedWinningTotal++;
                        } elseif ($roi !== null && $roi < 0.0) {
                            $blockedLosingTotal++;
                        }
                        $blockedRoiSum += ($roi ?? 0.0);
                        $blockedPnlSum += ($pnl ?? 0.0);
                    }

                    // Register/refresh freeze from this close
                    if ($closeTs > 0) {
                        $proposedUntil = $closeTs + $durationSec;
                        if (!isset($frozenUntil[$symbol]) || $proposedUntil > $frozenUntil[$symbol]) {
                            $frozenUntil[$symbol] = $proposedUntil;
                        }
                    }
                }

                $durationStats[(string)$durationMin] = [
                    'duration_minutes'             => $durationMin,
                    'repeat_entries_within_window_total' => $repeatEntriesTotal,
                    'blocked_winning_repeats_total'=> $blockedWinningTotal,
                    'blocked_losing_repeats_total' => $blockedLosingTotal,
                    'blocked_repeat_roi_sum'       => round($blockedRoiSum, 4),
                    'blocked_repeat_pnl_sum'       => round($blockedPnlSum, 4),
                    'estimated_saved_pnl'          => round(max(0.0, -$blockedPnlSum), 4),
                    'estimated_lost_pnl'           => round(max(0.0,  $blockedPnlSum), 4),
                    'net_estimated_effect'         => round(-$blockedPnlSum, 4),
                ];
            }

            $output = [
                'generated_at'    => date('c'),
                'strategy'        => 'double_bottom_long',
                'source'          => 'closed_trades',
                'trades_analyzed' => count($dbTrades),
                'note'            => 'Simulation only — diagnostics, does not affect behavior',
                'durations'       => $durationStats,
            ];

            $this->writeJson('storage/symbol_freeze_duration_stats.json', $output);
        } catch (\Throwable) {
        }
    }
}
