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
 * Mode:
 *   demo — close positions on Bybit Demo via closeDemoPosition()
 *   live — close positions on Bybit Live via closeLivePosition() (reduceOnly market close)
 *
 * The effective close mode is determined by moduleMode() which reads config['mode']
 * (only 'demo' and 'live' are valid; anything else falls back to 'demo').
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

    /** @var \OrderBookContextService|null */
    private ?\OrderBookContextService $obcService = null;

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

        $shortConfigOverrides = $this->config['profiles']['short'] ?? [];
        $this->shortProfile   = new Profiles\Short\ShortProfile(
            $this->moduleDir . '/profiles/short',
            $shortConfigOverrides
        );

        // ── OrderBook Wall Context service (optional; fails gracefully) ────────
        $obcDir = $this->repoRoot . '/modules/system/orderbook_context';
        if (is_file($obcDir . '/service.php')) {
            try {
                require_once $obcDir . '/service.php';
                $wallConfig = array_filter([
                    'wall_exit_enabled'                    => $this->config['wall_exit_enabled'] ?? null,
                    'wall_exit_min_roi'                    => $this->config['wall_exit_min_roi'] ?? null,
                    'wall_exit_distance_pct'               => $this->config['wall_exit_distance_pct'] ?? null,
                    'wall_exit_fail_checks'                => $this->config['wall_exit_fail_checks'] ?? null,
                    'wall_exit_action'                     => $this->config['wall_exit_action'] ?? null,
                    'wall_exit_tighten_lock_buffer_roi'    => $this->config['wall_exit_tighten_lock_buffer_roi'] ?? null,
                ], fn($v) => $v !== null);
                $this->obcService = new \OrderBookContextService($obcDir, $wallConfig);
            } catch (\Throwable) {
                $this->obcService = null;
            }
        }
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
    public function tick(array $tickCtx = []): array
    {
        $fastTick          = (bool)($tickCtx['fast_tick'] ?? false);
        $fastTickAllowClose    = (bool)($this->config['fast_tick_allow_close']     ?? true);
        $fastTickAllowLockMove = (bool)($this->config['fast_tick_allow_lock_move'] ?? true);
        $nowTs      = time();
        $ts         = date('c', $nowTs);

        // ── Effective mode: inherit global_runtime_mode from bot config if present ──
        // The global_runtime_mode is written by the top DEMO/LIVE dashboard button.
        // Fallback to this module's own mode config for backward compatibility.
        $pmBotConfig         = $this->loadBotConfig();
        $pmGlobalRuntimeMode = (string)($pmBotConfig['global_runtime_mode'] ?? '');
        $pmLocalMode         = (string)($this->config['mode'] ?? 'demo');
        if ($pmGlobalRuntimeMode !== '' && in_array($pmGlobalRuntimeMode, ['demo', 'live'], true)) {
            $this->config['mode']    = $pmGlobalRuntimeMode;
            $pmEffectiveModeSource   = 'global_runtime_mode';
            $pmDeprecatedLocalMode   = $pmLocalMode !== '' && $pmLocalMode !== $pmGlobalRuntimeMode;
        } else {
            $pmEffectiveModeSource   = 'legacy_mode_fallback';
            $pmDeprecatedLocalMode   = false;
        }

        $moduleMode = $this->moduleMode();
        $account    = ($moduleMode === 'live') ? 'bybit_live' : 'bybit_demo';
        $sourceLabel= ($moduleMode === 'live') ? 'bybit_live_positions_cache' : 'bybit_demo_positions_cache';

        try {
            // ── Module enabled? ───────────────────────────────────────────────
            if (!$this->runtimeEnabled) {
                $result = array_merge([
                    'ok'             => true,
                    'ts'             => $ts,
                    'enabled'        => false,
                    'mode'           => $moduleMode,
                    'pm_effective_mode_source'     => $pmEffectiveModeSource,
                    'global_runtime_mode'          => $pmGlobalRuntimeMode !== '' ? $pmGlobalRuntimeMode : null,
                    'deprecated_local_mode_ignored'=> $pmDeprecatedLocalMode,
                    'account'        => $account,
                    'active_profile' => 'auto',
                    'skipped'        => 'module_disabled',
                    'skip_reason'    => 'module_disabled',
                    'positions_total'=> 0,
                    'positions_long' => 0,
                    'positions_short'=> 0,
                    'positions'      => 0,
                    'locks_active'      => $this->longProfile->getLockCount(),
                    'locks_active_long' => $this->longProfile->getLockCount(),
                    'locks_active_short'=> $this->shortProfile->getLockCount(),
                ], $this->buildConfigSnapshot());
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
                $result = array_merge([
                    'ok'             => true,
                    'ts'             => $ts,
                    'enabled'        => true,
                    'mode'           => $moduleMode,
                    'pm_effective_mode_source'     => $pmEffectiveModeSource,
                    'global_runtime_mode'          => $pmGlobalRuntimeMode !== '' ? $pmGlobalRuntimeMode : null,
                    'deprecated_local_mode_ignored'=> $pmDeprecatedLocalMode,
                    'account'        => $account,
                    'active_profile' => 'auto',
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
                    'source'            => $sourceLabel,
                    'source_authority'  => 'bot_active_positions_cache',
                    'executed_count'    => 0,
                    'skipped_count'     => 0,
                    'locks_active'      => $this->longProfile->getLockCount(),
                    'locks_active_long' => $this->longProfile->getLockCount(),
                    'locks_active_short'=> $this->shortProfile->getLockCount(),
                ], $this->buildConfigSnapshot());
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

            // Short-profile diagnostic counters
            $shortCheckedTotal             = 0;
            $shortLocksSetTotal            = 0;
            $shortLocksMovedTotal          = 0;
            $shortLockTouchCloseTotal      = 0;
            $shortCloseSubmittedTotal      = 0;
            $shortCloseFailedTotal         = 0;
            $shortSkippedBelowInitTotal    = 0;
            $shortSkippedBelowActivTotal   = 0;
            $shortSkippedCannotRoiTotal    = 0;
            $shortSkippedLockNotImprovTotal = 0;
            $shortSkippedLockTooCloseTotal = 0;
            $shortPositionExamples         = [];
            $shortLockExamples             = [];
            $shortCloseExamples            = [];
            $shortSkipExamples             = [];

            // PM state identity counters
            $pmStateIdentityMismatchTotal = 0;
            $pmStateLegacyKeyIgnoredTotal = 0;
            $pmStateIdentityMismatchExamples = [];

            // Fast-tick enforcement counters
            $fastTickCloseBlockedTotal        = 0;
            $fastTickComputedCloseNotExecTotal = 0;
            $fastTickLockMoveBlockedTotal      = 0;

            // Long impulse-hold diagnostic counters
            $impulseHoldCheckedTotal             = 0;
            $impulseHoldStrongTotal              = 0;
            $impulseHoldVeryStrongTotal          = 0;
            $impulseHoldLockTouchOverrideTotal   = 0;
            $impulseHoldLockTouchCloseAllowedTotal = 0;
            $impulseHoldMomentumBrokenTotal      = 0;
            $impulseHoldMissingMetricsTotal      = 0;
            $impulseHoldExamples                 = [];
            $impulseHoldOverrideExamples         = [];
            $impulseHoldCloseExamples            = [];

            // Long lock-touch grace diagnostic counters
            $graceCheckedTotal           = 0;
            $graceOverrideTotal          = 0;
            $graceCloseAllowedTotal      = 0;
            $graceExpiredTotal           = 0;
            $graceMomentumBrokenTotal    = 0;
            $graceSafetyFloorFailedTotal = 0;
            $graceExamples               = [];
            $graceOverrideExamples       = [];
            $graceCloseExamples          = [];

            // Long ROI staircase diagnostic counters
            $staircaseCheckedTotal    = 0;
            $staircaseActiveTotal     = 0;
            $staircaseFloorLostTotal  = 0;
            $staircaseCloseTotal      = 0;
            $staircaseExamples        = [];
            $staircaseCloseExamples   = [];

            // Long chop exit diagnostic counters
            $chopCheckedTotal                  = 0;
            $chopDetectedTotal                 = 0;
            $chopClosedTotal                   = 0;
            $chopSkippedStrongImpulseTotal     = 0;
            $chopSkippedLowRoiTotal            = 0;
            $chopSkippedNotEnoughSwingsTotal   = 0;
            $chopExamples                      = [];
            $chopCloseExamples                 = [];
            $chopSkipExamples                  = [];

            // Long lock-too-close diagnostic counters
            $lockTooCloseAdjustedTotal        = 0;
            $lockTooCloseClosedTotal          = 0;
            $lockTooCloseUnprotectedSkipTotal = 0;
            $lockTooCloseImpulseOverrideTotal = 0;
            $lockTooCloseAdjustedExamples     = [];
            $lockTooCloseCloseExamples        = [];
            $lockTooCloseUnprotectedExamples  = [];

            // Wall exit diagnostic counters
            $wallExitCheckedTotal           = 0;
            $wallExitTriggeredTotal         = 0;
            $wallExitTightenedTotal         = 0;  // reserved for tighten action
            $wallExitClosedTotal            = 0;
            $wallExitSkippedWallEatenTotal   = 0;
            $wallExitSkippedNoWallTotal      = 0;
            $wallExitExamples               = [];

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

                // ── Fetch wall context for this position (if OBC enabled) ─────
                $posWallContext = [];
                if ($this->obcService !== null) {
                    $posCurrentPrice = (float)($pos['current_price'] ?? $pos['mark_price'] ?? 0.0);
                    $posSymbol       = (string)($pos['symbol'] ?? '');
                    if ($posSymbol !== '' && $posCurrentPrice > 0.0) {
                        try {
                            $posWallContext = $this->obcService->getWallContext($posSymbol, $posCurrentPrice);
                        } catch (\Throwable) {
                            $posWallContext = [];
                        }
                    }
                }

                if ($side === 'long') {
                    $positionsLong++;
                    $profileResult = $this->longProfile->process($pos, $nowTs, $posWallContext, [
                        'allow_state_write' => !($fastTick && !$fastTickAllowLockMove),
                    ]);
                } elseif ($side === 'short') {
                    $positionsShort++;
                    $profileResult = $this->shortProfile->process($pos, $nowTs, $posWallContext, [
                        'allow_state_write' => !($fastTick && !$fastTickAllowLockMove),
                    ]);
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

                // ── PM state identity mismatch tracking ───────────────────────
                if (!empty($profileResult['pm_state_identity_mismatch'])) {
                    $pmStateIdentityMismatchTotal++;
                    if (count($pmStateIdentityMismatchExamples) < 5) {
                        $pmStateIdentityMismatchExamples[] = [
                            'symbol'      => $pos['symbol']                         ?? '',
                            'side'        => $pos['side']                            ?? '',
                            'signal_id'   => $pos['signal_id']                      ?? null,
                            'opened_at'   => $pos['opened_at'] ?? $pos['bot_submitted_at'] ?? null,
                            'entry_price' => (float)($pos['entry_price'] ?? $pos['avg_price'] ?? 0.0),
                            'action'      => $profileResult['action']                ?? 'skip',
                        ];
                    }
                }
                if (!empty($profileResult['pm_state_legacy_key_ignored'])) {
                    $pmStateLegacyKeyIgnoredTotal++;
                }

                // ── PM Close Execution ────────────────────────────────────────
                $pmCloseActions = ['hybrid_close_confirmed', 'would_close_on_lock_touch', 'roi_chop_indecision_exit', 'would_close_on_lock_too_close', 'wall_exit_close'];
                $pmAction       = $profileResult['action'] ?? 'skip';
                $closeAttemptResult = null;

                // Extract position mode info for cross-gateway diagnostics (always computed)
                $posRawMode = '';
                foreach (['execution_mode', 'mode'] as $_mf) {
                    $_mv = (string)($pos[$_mf] ?? '');
                    if ($_mv === 'live' || $_mv === 'demo') {
                        $posRawMode = $_mv;
                        break;
                    }
                }

                if (in_array($pmAction, $pmCloseActions, true)) {
                    // Fast-tick close enforcement
                    if ($fastTick && !$fastTickAllowClose) {
                        $fastTickCloseBlockedTotal++;
                        $fastTickComputedCloseNotExecTotal++;
                        $profileResult['action']           = 'skip';
                        $profileResult['skip_reason']      = 'fast_tick_close_blocked_by_config';
                        $closeAttemptResult = [
                            'close_attempted'    => false,
                            'close_ok'           => false,
                            'close_ret_code'     => null,
                            'close_ret_msg'      => null,
                            'close_reason'       => 'fast_tick_close_blocked_by_config',
                            'close_source'       => 'profit_manager_fast_tick',
                            'close_order_id'     => null,
                            'close_error_reason' => 'fast_tick_close_blocked_by_config',
                        ];
                    } else {
                    $posExecMode  = $this->resolveCloseMode($pos, $moduleMode);
                    $posSymbol    = (string)($pos['symbol'] ?? '');
                    $posSide      = (string)($pos['side']   ?? '');
                    $posSize      = (float)($pos['size']    ?? 0.0);
                    // Use close_reason_hint from long/short profile when available
                    $closeReasonHint  = $profileResult['close_reason_hint'] ?? null;
                    if ($pmAction === 'hybrid_close_confirmed') {
                        $closeReasonValue = 'hybrid_confirmed';
                    } elseif ($pmAction === 'roi_chop_indecision_exit') {
                        $closeReasonValue = 'roi_chop_indecision_exit';
                    } elseif ($pmAction === 'would_close_on_lock_too_close') {
                        $closeReasonValue = 'lock_price_too_close_profit_protect';
                    } elseif ($pmAction === 'wall_exit_close') {
                        $closeReasonValue = $closeReasonHint
                            ?? (($side === 'long') ? 'ask_wall_rejection_profit_exit' : 'bid_wall_rejection_profit_exit');
                    } else {
                        $closeReasonValue = $closeReasonHint ?? 'lock_touch';
                    }

                    if ($posExecMode === 'mode_mismatch') {
                        // Cross-gateway safety: position mode differs from PM module mode.
                        // Do not attempt the close; record mismatch details only.
                        $closeAttemptResult = [
                            'close_attempted'    => false,
                            'close_ok'           => false,
                            'close_ret_code'     => null,
                            'close_ret_msg'      => null,
                            'close_reason'       => $closeReasonValue,
                            'close_source'       => 'profit_manager',
                            'close_order_id'     => null,
                            'close_error_reason' => 'position_mode_mismatch',
                        ];
                        $profileResult['action'] = 'close_skipped_mode_mismatch';
                    } elseif ($posExecMode === 'demo') {
                        $closeAttemptResult = $this->closeDemoPosition($posSymbol, $posSide, $posSize, $pos);
                        $closeAttemptResult['close_reason'] = $closeReasonValue;
                        $profileResult['action'] = $closeAttemptResult['close_ok']
                            ? 'demo_close_submitted'
                            : 'demo_close_failed';

                        if ($closeAttemptResult['close_ok']) {
                            $this->writePmCloseRegistry(
                                $posSymbol,
                                $posSide,
                                $closeReasonValue,
                                $closeAttemptResult['close_order_id'] ?? null,
                                'demo',
                                array_merge($pos, ['profile_used' => $profileResult['profile_used'] ?? ''])
                            );
                        }
                    } elseif ($posExecMode === 'live') {
                        $closeAttemptResult = $this->closeLivePosition($posSymbol, $posSide, $posSize, $pos);
                        $closeAttemptResult['close_reason'] = $closeReasonValue;
                        $profileResult['action'] = $closeAttemptResult['close_ok']
                            ? 'live_close_submitted'
                            : 'live_close_failed';

                        if ($closeAttemptResult['close_ok']) {
                            $this->writePmCloseRegistry(
                                $posSymbol,
                                $posSide,
                                $closeReasonValue,
                                $closeAttemptResult['close_order_id'] ?? null,
                                'live',
                                array_merge($pos, ['profile_used' => $profileResult['profile_used'] ?? ''])
                            );
                        }
                    } else {
                        $closeAttemptResult = [
                            'close_attempted'    => false,
                            'close_ok'           => false,
                            'close_ret_code'     => null,
                            'close_ret_msg'      => null,
                            'close_reason'       => $closeReasonValue,
                            'close_source'       => 'profit_manager',
                            'close_order_id'     => null,
                            'close_error_reason' => 'close_failed invalid_execution_mode',
                        ];
                        $profileResult['action'] = 'close_failed';
                    }
                    } // end fast_tick else
                }

                // Track actions summary
                $action = $profileResult['action'] ?? 'skip';
                $actionsSummary[$action] = ($actionsSummary[$action] ?? 0) + 1;

                // Track skip summary
                if ($action === 'skip' && !empty($profileResult['skip_reason'])) {
                    $r = (string) $profileResult['skip_reason'];
                    $skipSummary[$r] = ($skipSummary[$r] ?? 0) + 1;
                }

                // ── Short-profile diagnostic tracking ─────────────────────────
                if ($side === 'short') {
                    $shortCheckedTotal++;
                    $shortSkipReason = $profileResult['skip_reason'] ?? null;

                    if ($action === 'would_set_profit_lock')  { $shortLocksSetTotal++; }
                    if ($action === 'would_move_profit_lock') { $shortLocksMovedTotal++; }
                    if ($action === 'would_close_on_lock_touch') { $shortLockTouchCloseTotal++; }
                    if (in_array($action, ['demo_close_submitted', 'live_close_submitted'], true)) {
                        $shortCloseSubmittedTotal++;
                    }
                    if (in_array($action, ['demo_close_failed', 'live_close_failed', 'close_failed'], true)) {
                        $shortCloseFailedTotal++;
                    }
                    if ($action === 'skip') {
                        if ($shortSkipReason === 'below_init_roi')        { $shortSkippedBelowInitTotal++; }
                        elseif ($shortSkipReason === 'below_activation_roi') { $shortSkippedBelowActivTotal++; }
                        elseif ($shortSkipReason === 'cannot_calculate_roi') { $shortSkippedCannotRoiTotal++; }
                        elseif (in_array($shortSkipReason, ['lock_not_improving', 'roi_step_too_small'], true)) {
                            $shortSkippedLockNotImprovTotal++;
                        }
                        elseif ($shortSkipReason === 'lock_price_too_close_to_current') {
                            $shortSkippedLockTooCloseTotal++;
                        }
                    }

                    // Build short example record (shared shape for all example buckets)
                    $_sc = $pos['strategy_signal_context'] ?? null;
                    $shortEx = [
                        'symbol'                  => $pos['symbol'] ?? '',
                        'side'                    => 'short',
                        'owner_strategy'          => $pos['owner_strategy'] ?? $pos['strategy_id'] ?? '',
                        'signal_id'               => $pos['signal_id'] ?? '',
                        'entry_price'             => (float) ($pos['entry_price'] ?? $pos['avg_price'] ?? 0.0),
                        'current_price'           => (float) ($pos['current_price'] ?? 0.0),
                        'leverage'                => (float) ($pos['leverage'] ?? 0.0),
                        'roi'                     => $profileResult['roi'] ?? null,
                        'peak_roi'                => $profileResult['peak_roi'] ?? null,
                        'lock_price'              => $profileResult['lock_price'] ?? null,
                        'action'                  => $action,
                        'skip_reason'             => $shortSkipReason,
                        'profile_used'            => $profileResult['profile_used'] ?? 'baseline_short_lock',
                        'dynamic_rule'            => is_array($_sc) ? ($_sc['dynamic_rule'] ?? null) : null,
                        'strategy_signal_context' => $_sc,
                    ];

                    if (in_array($action, ['would_set_profit_lock', 'would_move_profit_lock'], true)) {
                        if (count($shortLockExamples) < 5)     { $shortLockExamples[] = $shortEx; }
                    } elseif (in_array($action, ['demo_close_submitted', 'live_close_submitted'], true)) {
                        if (count($shortCloseExamples) < 5)    { $shortCloseExamples[] = $shortEx; }
                    } elseif ($action === 'skip') {
                        if (count($shortSkipExamples) < 10)    { $shortSkipExamples[] = $shortEx; }
                    }

                    if (count($shortPositionExamples) < 5)     { $shortPositionExamples[] = $shortEx; }

                    if ($fastTick && !$fastTickAllowLockMove
                        && in_array($action, ['would_set_profit_lock', 'would_move_profit_lock'], true)
                    ) {
                        $fastTickLockMoveBlockedTotal++;
                    }
                }

                // ── Long-profile impulse / grace diagnostic tracking ───────────
                if ($side === 'long') {
                    $impulseClass   = $profileResult['impulse_class']   ?? null;
                    $impulseBroken  = !empty($profileResult['momentum_broken']);
                    $overrideAction = $profileResult['lock_touch_override_action'] ?? null;
                    $overrideReason = $profileResult['lock_touch_override_reason'] ?? null;
                    $graceActive    = !empty($profileResult['grace_checked']);

                    if (!empty($profileResult['impulse_checked'])) {
                        $impulseHoldCheckedTotal++;
                        if ($impulseClass === 'strong')      { $impulseHoldStrongTotal++;     }
                        if ($impulseClass === 'very_strong') { $impulseHoldVeryStrongTotal++; }
                        if (!empty($profileResult['missing_metrics'])) { $impulseHoldMissingMetricsTotal++; }
                        if ($impulseBroken) { $impulseHoldMomentumBrokenTotal++; }

                        // Was an impulse-hold lock-touch override applied?
                        if ($overrideAction === 'hold_override_lock_touch'
                            && $overrideReason === 'impulse_hold_lock_touch_override'
                        ) {
                            $impulseHoldLockTouchOverrideTotal++;
                        }
                        // Was the lock-touch close allowed through (no impulse override)?
                        if ($overrideAction === null
                            && in_array($action, ['would_close_on_lock_touch', 'demo_close_submitted', 'live_close_submitted'], true)
                            && $overrideReason !== null
                            && str_starts_with((string) $overrideReason, 'lock_touch_impulse')
                        ) {
                            $impulseHoldLockTouchCloseAllowedTotal++;
                        }

                        // Build impulse example record
                        $impulseCtxData = $profileResult['impulse_context'] ?? [];
                        $impulseEx = [
                            'symbol'                        => $pos['symbol'] ?? '',
                            'side'                          => 'long',
                            'entry_price'                   => (float) ($pos['entry_price'] ?? $pos['avg_price'] ?? 0.0),
                            'current_price'                 => (float) ($pos['current_price'] ?? 0.0),
                            'roi'                           => $profileResult['roi'] ?? null,
                            'peak_roi'                      => $profileResult['peak_roi'] ?? null,
                            'lock_price'                    => $profileResult['lock_price'] ?? null,
                            'impulse_score'                 => $profileResult['impulse_score'] ?? null,
                            'impulse_class'                 => $impulseClass,
                            'price_change_1m_pct'           => $impulseCtxData['price_change_1m_pct']  ?? null,
                            'price_change_3m_pct'           => $impulseCtxData['price_change_3m_pct']  ?? null,
                            'price_change_5m_pct'           => $impulseCtxData['price_change_5m_pct']  ?? null,
                            'price_change_15m_pct'          => $impulseCtxData['price_change_15m_pct'] ?? null,
                            'price_change_30m_pct'          => $impulseCtxData['price_change_30m_pct'] ?? null,
                            'turnover_growth_pct'           => $impulseCtxData['turnover_growth_pct']  ?? null,
                            'open_interest_value_growth_pct'=> $impulseCtxData['open_interest_value_growth_pct'] ?? null,
                            'override_count'                => $profileResult['grace_override_count']  ?? 0,
                            'action'                        => $action,
                            'reason'                        => $overrideReason,
                            'break_reasons'                 => $impulseCtxData['evidence'] ?? [],
                            'missing_metrics'               => $impulseCtxData['missing_metrics'] ?? [],
                        ];

                        if (count($impulseHoldExamples) < 10)       { $impulseHoldExamples[] = $impulseEx; }
                        if ($overrideAction === 'hold_override_lock_touch'
                            && $overrideReason === 'impulse_hold_lock_touch_override'
                        ) {
                            if (count($impulseHoldOverrideExamples) < 5) { $impulseHoldOverrideExamples[] = $impulseEx; }
                        }
                        if (in_array($action, ['would_close_on_lock_touch', 'demo_close_submitted', 'live_close_submitted'], true)
                            && $overrideReason !== null && str_starts_with((string) $overrideReason, 'lock_touch_impulse')
                        ) {
                            if (count($impulseHoldCloseExamples) < 5) { $impulseHoldCloseExamples[] = $impulseEx; }
                        }
                    }

                    if ($fastTick && !$fastTickAllowLockMove
                        && in_array($action, ['would_set_profit_lock', 'would_move_profit_lock'], true)
                    ) {
                        $fastTickLockMoveBlockedTotal++;
                    }

                    if ($graceActive) {
                        $graceCheckedTotal++;
                        $graceOverrideReason = $overrideReason ?? '';
                        if ($overrideAction === 'hold_override_lock_touch'
                            && $overrideReason === 'lock_touch_grace_override'
                        ) {
                            $graceOverrideTotal++;
                        } elseif (str_contains($graceOverrideReason, 'grace_window_expired')) {
                            $graceExpiredTotal++;
                            $graceCloseAllowedTotal++;
                        } elseif (str_contains($graceOverrideReason, 'momentum_broken')) {
                            $graceMomentumBrokenTotal++;
                            $graceCloseAllowedTotal++;
                        } elseif (str_contains($graceOverrideReason, 'hard_profit_floor')
                                  || str_contains($graceOverrideReason, 'giveback_exceeded')
                        ) {
                            $graceSafetyFloorFailedTotal++;
                            $graceCloseAllowedTotal++;
                        } elseif (in_array($action, ['would_close_on_lock_touch', 'demo_close_submitted', 'live_close_submitted'], true)) {
                            $graceCloseAllowedTotal++;
                        }

                        // Build grace example record
                        $graceEx = [
                            'symbol'       => $pos['symbol'] ?? '',
                            'side'         => 'long',
                            'entry_price'  => (float) ($pos['entry_price'] ?? $pos['avg_price'] ?? 0.0),
                            'current_price'=> (float) ($pos['current_price'] ?? 0.0),
                            'roi'          => $profileResult['roi'] ?? null,
                            'peak_roi'     => $profileResult['peak_roi'] ?? null,
                            'lock_price'   => $profileResult['lock_price'] ?? null,
                            'override_count'=> $profileResult['grace_override_count'] ?? 0,
                            'action'       => $action,
                            'reason'       => $overrideReason,
                        ];

                        if (count($graceExamples) < 10) { $graceExamples[] = $graceEx; }
                        if ($overrideAction === 'hold_override_lock_touch' && $overrideReason === 'lock_touch_grace_override') {
                            if (count($graceOverrideExamples) < 5) { $graceOverrideExamples[] = $graceEx; }
                        }
                        if (in_array($action, ['would_close_on_lock_touch', 'demo_close_submitted', 'live_close_submitted'], true)) {
                            if (count($graceCloseExamples) < 5) { $graceCloseExamples[] = $graceEx; }
                        }
                    }

                    // ── Long ROI staircase diagnostic tracking ────────────────
                    if (!empty($profileResult['staircase_checked'])) {
                        $staircaseCheckedTotal++;
                        if (!empty($profileResult['staircase_active'])) {
                            $staircaseActiveTotal++;
                        }
                        $closeReason = $closeAttemptResult['close_reason'] ?? null;
                        if ($closeReason === 'roi_staircase_floor_lost') {
                            $staircaseFloorLostTotal++;
                        }
                        if (($closeReason === 'roi_staircase_floor_lost')
                            && in_array($action, ['demo_close_submitted', 'live_close_submitted'], true)
                        ) {
                            $staircaseCloseTotal++;
                        }

                        $staircaseEx = [
                            'symbol'             => $pos['symbol'] ?? '',
                            'side'               => 'long',
                            'entry_price'        => (float) ($pos['entry_price'] ?? $pos['avg_price'] ?? 0.0),
                            'current_price'      => (float) ($pos['current_price'] ?? 0.0),
                            'roi'                => $profileResult['roi'] ?? null,
                            'peak_roi'           => $profileResult['peak_roi'] ?? null,
                            'staircase_floor_roi'=> $profileResult['staircase_floor_roi'] ?? null,
                            'lock_price'         => $profileResult['lock_price'] ?? null,
                            'impulse_class'      => $profileResult['impulse_class'] ?? null,
                            'impulse_score'      => $profileResult['impulse_score'] ?? null,
                            'action'             => $action,
                            'reason'             => $closeReason,
                        ];

                        if (count($staircaseExamples) < 10) { $staircaseExamples[] = $staircaseEx; }
                        if ($closeReason === 'roi_staircase_floor_lost'
                            && in_array($action, ['demo_close_submitted', 'live_close_submitted'], true)
                        ) {
                            if (count($staircaseCloseExamples) < 5) { $staircaseCloseExamples[] = $staircaseEx; }
                        }
                    }

                    // ── Long chop exit diagnostic tracking ───────────────────
                    if (!empty($profileResult['chop_checked'])) {
                        $chopCheckedTotal++;
                        $chopCtxData = $profileResult['chop_context'] ?? [];
                        $skipReason  = $profileResult['chop_skip_reason'] ?? null;

                        if (!empty($profileResult['chop_detected'])) {
                            $chopDetectedTotal++;
                        }
                        if (in_array($action, ['demo_close_submitted', 'live_close_submitted'], true)
                            && ($closeAttemptResult['close_reason'] ?? null) === 'roi_chop_indecision_exit'
                        ) {
                            $chopClosedTotal++;
                        }
                        if ($skipReason === 'strong_impulse') {
                            $chopSkippedStrongImpulseTotal++;
                        } elseif ($skipReason === 'below_min_close_roi') {
                            $chopSkippedLowRoiTotal++;
                        } elseif ($skipReason === 'not_enough_swings') {
                            $chopSkippedNotEnoughSwingsTotal++;
                        }

                        $chopEx = [
                            'symbol'             => $pos['symbol'] ?? '',
                            'side'               => 'long',
                            'entry_price'        => (float) ($pos['entry_price'] ?? $pos['avg_price'] ?? 0.0),
                            'current_price'      => (float) ($pos['current_price'] ?? 0.0),
                            'roi'                => $profileResult['roi'] ?? null,
                            'peak_roi'           => $profileResult['peak_roi'] ?? null,
                            'staircase_floor_roi'=> $profileResult['staircase_floor_roi'] ?? null,
                            'lock_price'         => $profileResult['lock_price'] ?? null,
                            'impulse_class'      => $profileResult['impulse_class'] ?? null,
                            'impulse_score'      => $profileResult['impulse_score'] ?? null,
                            'roi_swings'         => $chopCtxData['roi_swings']         ?? [],
                            'swing_count'        => $chopCtxData['swing_count']         ?? 0,
                            'seconds_since_peak' => $chopCtxData['seconds_since_peak']  ?? null,
                            'action'             => $action,
                            'reason'             => $skipReason ?? ($chopCtxData['reason'] ?? null),
                        ];

                        if (count($chopExamples) < 10) { $chopExamples[] = $chopEx; }
                        if (!empty($profileResult['chop_detected'])) {
                            if (in_array($action, ['demo_close_submitted', 'live_close_submitted'], true)) {
                                if (count($chopCloseExamples) < 5) { $chopCloseExamples[] = $chopEx; }
                            } elseif ($skipReason !== null) {
                                if (count($chopSkipExamples) < 5) { $chopSkipExamples[] = $chopEx; }
                            }
                        }
                    }
                }

                // ── Long lock-too-close diagnostic tracking ───────────────────
                if ($side === 'long') {
                    $ltcAdjusted  = !empty($profileResult['lock_too_close_adjusted']);
                    $ltcUnprot    = !empty($profileResult['lock_too_close_unprotected']);
                    $ltcImpulse   = !empty($profileResult['lock_too_close_impulse_override']);
                    $ltcClose     = !empty($profileResult['lock_too_close_close_action']);

                    if ($ltcAdjusted) {
                        $lockTooCloseAdjustedTotal++;
                    }
                    if ($ltcImpulse) {
                        $lockTooCloseImpulseOverrideTotal++;
                    }
                    if ($ltcUnprot && !$ltcImpulse && !$ltcClose) {
                        $lockTooCloseUnprotectedSkipTotal++;
                    }
                    if ($ltcClose && in_array($action, ['demo_close_submitted', 'live_close_submitted'], true)) {
                        $lockTooCloseClosedTotal++;
                    }

                    $_ltcEx = null;
                    if ($ltcAdjusted || $ltcUnprot || $ltcImpulse || $ltcClose) {
                        $_ltcEx = [
                            'symbol'                   => $pos['symbol'] ?? '',
                            'side'                     => 'long',
                            'entry_price'              => (float) ($pos['entry_price'] ?? $pos['avg_price'] ?? 0.0),
                            'current_price'            => (float) ($pos['current_price'] ?? 0.0),
                            'roi'                      => $profileResult['roi'] ?? null,
                            'peak_roi'                 => $profileResult['peak_roi'] ?? null,
                            'original_target_lock_roi' => null,
                            'safe_lock_price'          => $profileResult['safe_lock_price'] ?? null,
                            'safe_lock_roi'            => $profileResult['safe_lock_roi']   ?? null,
                            'required_floor_roi'       => $profileResult['required_floor_roi'] ?? null,
                            'min_price_distance_pct'   => $profileResult['min_required_distance_pct'] ?? null,
                            'action'                   => $action,
                            'reason'                   => $profileResult['notes'][0] ?? null,
                        ];
                    }

                    if ($ltcAdjusted && $_ltcEx !== null) {
                        if (count($lockTooCloseAdjustedExamples) < 5) { $lockTooCloseAdjustedExamples[] = $_ltcEx; }
                    }
                    if ($ltcImpulse && $_ltcEx !== null) {
                        if (count($lockTooCloseUnprotectedExamples) < 5) { $lockTooCloseUnprotectedExamples[] = $_ltcEx; }
                    }
                    if ($ltcClose && $_ltcEx !== null) {
                        if (count($lockTooCloseCloseExamples) < 5) { $lockTooCloseCloseExamples[] = $_ltcEx; }
                    }
                }

                // ── Wall exit diagnostic tracking (long + short) ─────────────
                if (!empty($profileResult['wall_exit_checked'])) {
                    $wallExitCheckedTotal++;
                    $wallCtxData = $profileResult['wall_exit_context'] ?? null;

                    if (!empty($profileResult['wall_exit_triggered'])) {
                        $wallExitTriggeredTotal++;
                        if (in_array($action, ['demo_close_submitted', 'live_close_submitted'], true)) {
                            $wallExitClosedTotal++;
                        }
                    }
                    if (!empty($profileResult['wall_exit_skipped'])) {
                        $skipReason = (string)($profileResult['wall_exit_skip_reason'] ?? '');
                        if ($skipReason === 'wall_exit_skipped_wall_eaten') {
                            $wallExitSkippedWallEatenTotal++;
                        }
                    }

                    if (count($wallExitExamples) < 5 && ($wallCtxData !== null)) {
                        $wallExitExamples[] = [
                            'symbol'           => $pos['symbol'] ?? '',
                            'side'             => $pos['side']   ?? '',
                            'current_price'    => (float)($pos['current_price'] ?? 0.0),
                            'roi'              => $profileResult['roi'] ?? null,
                            'wall_side'        => $side === 'long' ? 'ask' : 'bid',
                            'wall_price'       => $wallCtxData['price']        ?? null,
                            'wall_distance_pct'=> $wallCtxData['distance_pct'] ?? null,
                            'wall_notional'    => $wallCtxData['notional']     ?? null,
                            'wall_score'       => $wallCtxData['wall_score']   ?? null,
                            'wall_status'      => ($side === 'long')
                                ? ($posWallContext['ask_wall_status'] ?? 'none')
                                : ($posWallContext['bid_wall_status'] ?? 'none'),
                            'action'           => $action,
                            'reason'           => $closeAttemptResult['close_reason']
                                ?? $profileResult['close_reason_hint']
                                ?? $profileResult['wall_exit_skip_reason']
                                ?? null,
                        ];
                    }
                } elseif (empty($profileResult['wall_exit_checked']) && !empty($posWallContext) && ($posWallContext['fetch_ok'] ?? false)) {
                    // Wall context was available but no wall was close enough
                    $wallExitSkippedNoWallTotal++;
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
                    // Impulse hold / grace diagnostics (long only; null for short)
                    'impulse_checked'            => $profileResult['impulse_checked']            ?? false,
                    'impulse_score'              => $profileResult['impulse_score']              ?? null,
                    'impulse_class'              => $profileResult['impulse_class']              ?? null,
                    'impulse_hold_active'        => $profileResult['impulse_hold_active']        ?? false,
                    'lock_touch_override_action' => $profileResult['lock_touch_override_action'] ?? null,
                    'lock_touch_override_reason' => $profileResult['lock_touch_override_reason'] ?? null,
                    'grace_active'               => $profileResult['grace_active']               ?? false,
                    'grace_override_count'       => $profileResult['grace_override_count']       ?? null,
                    'momentum_broken'            => $profileResult['momentum_broken']            ?? false,
                    // Staircase diagnostics (long only)
                    'staircase_checked'          => $profileResult['staircase_checked']          ?? false,
                    'staircase_active'           => $profileResult['staircase_active']           ?? false,
                    'staircase_floor_roi'        => $profileResult['staircase_floor_roi']        ?? null,
                    // Chop exit diagnostics (long only)
                    'chop_checked'               => $profileResult['chop_checked']               ?? false,
                    'chop_detected'              => $profileResult['chop_detected']              ?? false,
                    'chop_skip_reason'           => $profileResult['chop_skip_reason']           ?? null,
                    // Wall exit diagnostics
                    'wall_exit_checked'          => $profileResult['wall_exit_checked']          ?? false,
                    'wall_exit_triggered'        => $profileResult['wall_exit_triggered']        ?? false,
                    'wall_exit_skipped'          => $profileResult['wall_exit_skipped']          ?? false,
                    'wall_exit_skip_reason'      => $profileResult['wall_exit_skip_reason']      ?? null,
                    'nearest_wall'               => ($side === 'long')
                        ? ($posWallContext['nearest_ask_wall'] ?? null)
                        : ($posWallContext['nearest_bid_wall'] ?? null),
                    'wall_status'                => ($side === 'long')
                        ? ($posWallContext['ask_wall_status']  ?? 'none')
                        : ($posWallContext['bid_wall_status']  ?? 'none'),
                    // Close execution output (null when no close was attempted this tick)
                    'close_attempted'            => $closeAttemptResult['close_attempted']    ?? null,
                    'close_ok'                   => $closeAttemptResult['close_ok']           ?? null,
                    'close_ret_code'             => $closeAttemptResult['close_ret_code']     ?? null,
                    'close_ret_msg'              => $closeAttemptResult['close_ret_msg']      ?? null,
                    'close_reason'               => $closeAttemptResult['close_reason']       ?? null,
                    'close_source'               => $closeAttemptResult['close_source']       ?? null,
                    'close_order_id'             => $closeAttemptResult['close_order_id']     ?? null,
                    'close_error_reason'         => $closeAttemptResult['close_error_reason'] ?? null,
                    // Close mode resolution diagnostics
                    'module_mode'                => $moduleMode,
                    'position_mode'              => $posRawMode !== '' ? $posRawMode : null,
                    'effective_close_mode'       => ($closeAttemptResult !== null && isset($posExecMode) && $posExecMode !== 'mode_mismatch') ? $posExecMode : null,
                    'close_mode_resolution'      => $closeAttemptResult !== null
                        ? ($posRawMode === '' ? 'module_fallback' : (($closeAttemptResult['close_error_reason'] ?? '') === 'position_mode_mismatch' ? 'mode_mismatch' : 'explicit_match'))
                        : null,
                ];
            }

            // ── Computed summary counts ───────────────────────────────────────
            $positionsTotal = count($rawPositions);
            $invalidCount   = count($validationErrors);
            $validCount     = $positionsTotal - $invalidCount;

            $executedCount = ($actionsSummary['would_set_profit_lock']  ?? 0)
                           + ($actionsSummary['would_move_profit_lock'] ?? 0)
                           + ($actionsSummary['demo_close_submitted']   ?? 0)
                           + ($actionsSummary['live_close_submitted']   ?? 0);
            $skippedCount  = array_sum($skipSummary);

            // ── Clean stale long profile state/locks ──────────────────────────
            // Build the set of active long position keys seen this tick.
            // Include both the identity key (mode:symbol:side:signal_id:opened_at)
            // and the legacy symbol_side key so no active state is accidentally removed.
            $activeLongKeys = [];
            foreach ($rawPositions as $rp) {
                if (!is_array($rp)) { continue; }
                $rpSide = strtolower(trim((string)($rp['side'] ?? '')));
                if ($rpSide === 'buy') { $rpSide = 'long'; }
                if ($rpSide !== 'long') { continue; }
                $identityKey = \Modules\ProfManager\Profiles\Long\LongProfile::buildPositionIdentityKey($rp, 'long');
                $activeLongKeys[] = $identityKey;
                // Legacy fallback key
                $legacySym = strtolower((string)($rp['symbol'] ?? ''));
                if ($legacySym !== '') {
                    $activeLongKeys[] = $legacySym . '_long';
                }
            }
            $cleanResult = $this->longProfile->cleanStale(array_unique($activeLongKeys));

            // ── Clean stale short profile state/locks ─────────────────────────
            $activeShortKeys = [];
            foreach ($rawPositions as $rp) {
                if (!is_array($rp)) { continue; }
                $rpSide = strtolower(trim((string)($rp['side'] ?? '')));
                if ($rpSide === 'sell') { $rpSide = 'short'; }
                if ($rpSide !== 'short') { continue; }
                $identityKey = \Modules\ProfManager\Profiles\Short\ShortProfile::buildPositionIdentityKey($rp, 'short');
                $activeShortKeys[] = $identityKey;
                $legacySym = strtolower((string)($rp['symbol'] ?? ''));
                if ($legacySym !== '') {
                    $activeShortKeys[] = $legacySym . '_short';
                }
            }
            $shortCleanResult = $this->shortProfile->cleanStale(array_unique($activeShortKeys));

            $configSnapshot = $this->buildConfigSnapshot();

            // ── PM exchange profit-floor sync ─────────────────────────────────
            // Sets a stopLoss safety floor on the exchange from PM virtual lock.
            // Disabled by default; only runs when enabled in config.
            // PM virtual lock remains the primary exit logic.
            $floorSyncResult = $this->syncExchangeProfitFloor($moduleMode, $rawPositions, $positionsRuntime);

            $result = array_merge([
                'ok'                   => true,
                'ts'                   => $ts,
                'enabled'              => true,
                'mode'                 => $moduleMode,
                'pm_effective_mode_source'    => $pmEffectiveModeSource,
                'global_runtime_mode'         => $pmGlobalRuntimeMode !== '' ? $pmGlobalRuntimeMode : null,
                'deprecated_local_mode_ignored'=> $pmDeprecatedLocalMode,
                'account'              => $account,
                'active_profile'       => 'auto',
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
                'source'               => $sourceLabel,
                'source_authority'     => 'bot_active_positions_cache',
                'executed_count'       => $executedCount,
                'skipped_count'        => $skippedCount,
                'locks_active'         => $this->longProfile->getLockCount(),
                'locks_active_long'    => $this->longProfile->getLockCount(),
                'long_state_cleaned'   => $cleanResult['long_state_cleaned'],
                'long_locks_cleaned'   => $cleanResult['long_locks_cleaned'],
                // Short profile diagnostics
                'locks_active_short'                  => $this->shortProfile->getLockCount(),
                'short_state_cleaned'                 => $shortCleanResult['short_state_cleaned'],
                'short_locks_cleaned'                 => $shortCleanResult['short_locks_cleaned'],
                // PM state identity diagnostics
                'pm_state_identity_mismatch_total'    => $pmStateIdentityMismatchTotal,
                'pm_state_legacy_key_ignored_total'   => $pmStateLegacyKeyIgnoredTotal,
                // Fast-tick enforcement diagnostics
                'fast_tick'                                     => $fastTick,
                'fast_tick_allow_close'                         => $fastTickAllowClose,
                'fast_tick_allow_lock_move'                     => $fastTickAllowLockMove,
                'fast_tick_close_blocked_by_config_total'       => $fastTickCloseBlockedTotal,
                'fast_tick_computed_close_but_not_executed_total' => $fastTickComputedCloseNotExecTotal,
                'fast_tick_lock_move_blocked_by_config_total'   => $fastTickLockMoveBlockedTotal,
                'pm_state_stale_removed_total'        => $cleanResult['long_state_cleaned']
                                                       + $cleanResult['long_locks_cleaned']
                                                       + $shortCleanResult['short_state_cleaned']
                                                       + $shortCleanResult['short_locks_cleaned'],
                'pm_state_identity_mismatch_examples' => $pmStateIdentityMismatchExamples,
                'short_positions_checked_total'       => $shortCheckedTotal,
                'short_locks_set_total'               => $shortLocksSetTotal,
                'short_locks_moved_total'             => $shortLocksMovedTotal,
                'short_lock_touch_close_total'        => $shortLockTouchCloseTotal,
                'short_close_submitted_total'         => $shortCloseSubmittedTotal,
                'short_close_failed_total'            => $shortCloseFailedTotal,
                'short_skipped_below_init_total'      => $shortSkippedBelowInitTotal,
                'short_skipped_below_activation_total'=> $shortSkippedBelowActivTotal,
                'short_skipped_cannot_calculate_roi_total' => $shortSkippedCannotRoiTotal,
                'short_skipped_lock_not_improving_total'   => $shortSkippedLockNotImprovTotal,
                'short_skipped_lock_too_close_total'       => $shortSkippedLockTooCloseTotal,
                'short_position_examples'             => $shortPositionExamples,
                'short_lock_examples'                 => $shortLockExamples,
                'short_close_examples'                => $shortCloseExamples,
                'short_skip_examples'                 => $shortSkipExamples,
                // Long impulse hold diagnostics
                'impulse_hold_checked_total'              => $impulseHoldCheckedTotal,
                'impulse_hold_strong_total'               => $impulseHoldStrongTotal,
                'impulse_hold_very_strong_total'          => $impulseHoldVeryStrongTotal,
                'impulse_hold_lock_touch_override_total'  => $impulseHoldLockTouchOverrideTotal,
                'impulse_hold_lock_touch_close_allowed_total' => $impulseHoldLockTouchCloseAllowedTotal,
                'impulse_hold_momentum_broken_total'      => $impulseHoldMomentumBrokenTotal,
                'impulse_hold_missing_metrics_total'      => $impulseHoldMissingMetricsTotal,
                'impulse_hold_examples'                   => $impulseHoldExamples,
                'impulse_hold_override_examples'          => $impulseHoldOverrideExamples,
                'impulse_hold_close_examples'             => $impulseHoldCloseExamples,
                // Long lock-touch grace diagnostics
                'lock_touch_grace_checked_total'          => $graceCheckedTotal,
                'lock_touch_grace_override_total'         => $graceOverrideTotal,
                'lock_touch_grace_close_allowed_total'    => $graceCloseAllowedTotal,
                'lock_touch_grace_expired_total'          => $graceExpiredTotal,
                'lock_touch_grace_momentum_broken_total'  => $graceMomentumBrokenTotal,
                'lock_touch_grace_safety_floor_failed_total' => $graceSafetyFloorFailedTotal,
                'lock_touch_grace_examples'               => $graceExamples,
                'lock_touch_grace_override_examples'      => $graceOverrideExamples,
                'lock_touch_grace_close_examples'         => $graceCloseExamples,
                // Long ROI staircase diagnostics
                'roi_staircase_checked_total'             => $staircaseCheckedTotal,
                'roi_staircase_active_total'              => $staircaseActiveTotal,
                'roi_staircase_floor_lost_total'          => $staircaseFloorLostTotal,
                'roi_staircase_close_total'               => $staircaseCloseTotal,
                'roi_staircase_examples'                  => $staircaseExamples,
                'roi_staircase_close_examples'            => $staircaseCloseExamples,
                // Long chop exit diagnostics
                'chop_exit_checked_total'                       => $chopCheckedTotal,
                'chop_exit_detected_total'                      => $chopDetectedTotal,
                'chop_exit_closed_total'                        => $chopClosedTotal,
                'chop_exit_skipped_strong_impulse_total'        => $chopSkippedStrongImpulseTotal,
                'chop_exit_skipped_low_roi_total'               => $chopSkippedLowRoiTotal,
                'chop_exit_skipped_not_enough_swings_total'     => $chopSkippedNotEnoughSwingsTotal,
                'chop_exit_examples'                            => $chopExamples,
                'chop_exit_close_examples'                      => $chopCloseExamples,
                'chop_exit_skip_examples'                       => $chopSkipExamples,
                // Long lock-too-close diagnostics
                'lock_too_close_adjusted_total'          => $lockTooCloseAdjustedTotal,
                'lock_too_close_closed_total'            => $lockTooCloseClosedTotal,
                'lock_too_close_unprotected_skip_total'  => $lockTooCloseUnprotectedSkipTotal,
                'lock_too_close_impulse_override_total'  => $lockTooCloseImpulseOverrideTotal,
                'lock_too_close_adjusted_examples'       => $lockTooCloseAdjustedExamples,
                'lock_too_close_close_examples'          => $lockTooCloseCloseExamples,
                'lock_too_close_unprotected_examples'    => $lockTooCloseUnprotectedExamples,
                // Wall exit diagnostics
                'wall_exit_checked_total'                => $wallExitCheckedTotal,
                'wall_exit_triggered_total'              => $wallExitTriggeredTotal,
                'wall_exit_tightened_total'              => $wallExitTightenedTotal,
                'wall_exit_closed_total'                 => $wallExitClosedTotal,
                'wall_exit_skipped_wall_eaten_total'     => $wallExitSkippedWallEatenTotal,
                'wall_exit_skipped_no_wall_total'        => $wallExitSkippedNoWallTotal,
                'wall_exit_examples'                     => $wallExitExamples,
                // OrderBook Context service stats
                'obc_enabled'                            => ($this->obcService !== null),
                'obc_stats'                              => ($this->obcService !== null) ? $this->obcService->getStats() : null,
                // PM exchange profit-floor sync diagnostics
                'pm_floor_sync_enabled'                  => $floorSyncResult['pm_floor_sync_enabled'],
                'pm_floor_sync_skip_reason'              => $floorSyncResult['pm_floor_sync_skip_reason'] ?? null,
                'pm_floor_sync_attempted_total'          => $floorSyncResult['pm_floor_sync_attempted_total'],
                'pm_floor_sync_set_total'                => $floorSyncResult['pm_floor_sync_set_total'],
                'pm_floor_sync_skipped_total'            => $floorSyncResult['pm_floor_sync_skipped_total'],
                'pm_floor_sync_failed_total'             => $floorSyncResult['pm_floor_sync_failed_total'],
                'pm_floor_sync_examples'                 => $floorSyncResult['pm_floor_sync_examples'],
                'pm_floor_sync_skipped_examples'         => $floorSyncResult['pm_floor_sync_skipped_examples'],
                'pm_floor_sync_failed_examples'          => $floorSyncResult['pm_floor_sync_failed_examples'],
                'live_profit_floor_sync_skipped_disabled'=> $floorSyncResult['live_profit_floor_sync_skipped_disabled'] ?? false,
            ], $configSnapshot);

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
     * Lightweight fast-tick: runs one standard PM tick and writes the result
     * to storage/runtime/last_fast_run.json.
     *
     * Intended to be called by tickFastLoop(). May also be called directly
     * (e.g. from CLI or a standalone cron) when $calledFromLoop = false.
     *
     * Fast-tick enforcement:
     *   - When fast_tick_allow_close=false, any close action computed by the
     *     profile is blocked (action overridden to 'skip' with
     *     skip_reason='fast_tick_close_blocked_by_config').  Counter:
     *     fast_tick_close_blocked_by_config_total.
     *   - When fast_tick_allow_lock_move=false, profiles receive
     *     allow_state_write=false so writeState/writeLocks are skipped.
     *     Counter: fast_tick_lock_move_blocked_by_config_total.
     *
     * @param bool $calledFromLoop Set true when called from tickFastLoop() to
     *                             avoid acquiring a redundant per-tick lock.
     * @return array Tick result with fast_tick=true tag
     */
    public function tickFast(bool $calledFromLoop = false): array
    {
        $fastEnabled = (bool)($this->config['fast_tick_enabled'] ?? true);

        if (!$fastEnabled) {
            return [
                'ok'               => true,
                'fast_tick'        => true,
                'called_from_loop' => $calledFromLoop,
                'skipped'          => true,
                'skip_reason'      => 'fast_tick_disabled',
                'ts'               => date('c'),
            ];
        }

        // Run standard tick with fast_tick context flag
        $result = $this->tick(['fast_tick' => true]);

        // Tag result as fast-tick for diagnostics
        $result['fast_tick']        = true;
        $result['called_from_loop'] = $calledFromLoop;

        // Write to last_fast_run.json (separate from the regular last_run.json)
        $this->writeFastLastRun($result);

        return $result;
    }

    /**
     * Fast-loop runner: called by ISP cron once per minute.
     * Internally runs tickFast() every fast_loop_interval_seconds until
     * fast_loop_max_runtime_seconds is reached or fast_loop_max_ticks_per_run
     * ticks complete.
     *
     * Uses a file lock at storage/runtime/fast_loop.lock to prevent
     * overlapping loop invocations.
     *
     * Writes storage/runtime/last_fast_loop.json after each run.
     *
     * @return array Loop summary result
     */
    public function tickFastLoop(): array
    {
        $ts = date('c');

        $fastLoopEnabled  = (bool)($this->config['fast_loop_enabled']             ?? true);
        $intervalSec      = max(5,  (int)($this->config['fast_loop_interval_seconds']    ?? 15));
        $maxRuntimeSec    = max(10, (int)($this->config['fast_loop_max_runtime_seconds']  ?? 55));
        $overlapGuardSec  = max(10, (int)($this->config['fast_loop_overlap_guard_seconds'] ?? 70));
        $maxTicks         = max(1,  (int)($this->config['fast_loop_max_ticks_per_run']   ?? 4));

        $baseResult = [
            'fast_loop'              => true,
            'ts'                     => $ts,
            'interval_seconds'       => $intervalSec,
            'max_runtime_seconds'    => $maxRuntimeSec,
            'last_fast_loop_path'    => 'public/cron/prof_manager_fast_loop.php',
        ];

        if (!$fastLoopEnabled) {
            $result = array_merge($baseResult, [
                'ok'                     => true,
                'skipped'                => true,
                'skip_reason'            => 'fast_loop_disabled',
                'ticks_attempted'        => 0,
                'ticks_completed'        => 0,
                'ticks_skipped'          => 0,
                'closes_submitted_total' => 0,
                'locks_moved_total'      => 0,
                'duration_ms'            => 0,
            ]);
            $this->writeFastLoopLastRun($result);
            return $result;
        }

        // ── Overlap guard ─────────────────────────────────────────────────
        $lockPath = $this->getFastLoopLockPath();
        if (is_file($lockPath)) {
            $lockTs  = (int)@file_get_contents($lockPath);
            $lockAge = time() - $lockTs;
            if ($lockTs > 0 && $lockAge < $overlapGuardSec) {
                $result = array_merge($baseResult, [
                    'ok'                     => true,
                    'skipped'                => true,
                    'skip_reason'            => 'fast_loop_overlap_guard',
                    'lock_age_sec'           => $lockAge,
                    'ticks_attempted'        => 0,
                    'ticks_completed'        => 0,
                    'ticks_skipped'          => 0,
                    'closes_submitted_total' => 0,
                    'locks_moved_total'      => 0,
                    'duration_ms'            => 0,
                ]);
                $this->writeFastLoopLastRun($result);
                return $result;
            }
        }

        // ── Write lock ────────────────────────────────────────────────────
        $lockDir = dirname($lockPath);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }
        @file_put_contents($lockPath, (string)time(), LOCK_EX);

        // ── Loop ──────────────────────────────────────────────────────────
        $loopStart       = microtime(true);
        $ticksAttempted  = 0;
        $ticksCompleted  = 0;
        $ticksSkipped    = 0;
        $closesTotal     = 0;
        $locksMovedTotal = 0;
        $tickResults     = [];

        try {
            for ($i = 0; $i < $maxTicks; $i++) {
                $elapsed = microtime(true) - $loopStart;
                if ($elapsed >= $maxRuntimeSec) {
                    break;
                }

                $ticksAttempted++;

                try {
                    $tickResult = $this->tickFast(true);
                    $ticksCompleted++;

                    $closesSub   = (int)($tickResult['actions_summary']['demo_close_submitted'] ?? 0)
                                 + (int)($tickResult['actions_summary']['live_close_submitted'] ?? 0);
                    $locksSet    = (int)($tickResult['actions_summary']['would_set_profit_lock']  ?? 0)
                                 + (int)($tickResult['actions_summary']['would_move_profit_lock'] ?? 0);

                    $closesTotal     += $closesSub;
                    $locksMovedTotal += $locksSet;

                    $tickResults[] = [
                        'tick'             => $i + 1,
                        'ts'               => $tickResult['ts'] ?? date('c'),
                        'ok'               => $tickResult['ok'] ?? false,
                        'positions_total'  => $tickResult['positions_total'] ?? 0,
                        'executed_count'   => $tickResult['executed_count']  ?? 0,
                        'skipped_count'    => $tickResult['skipped_count']   ?? 0,
                        'closes_submitted' => $closesSub,
                        'locks_moved'      => $locksSet,
                    ];

                    if (!empty($tickResult['skipped'])) {
                        $ticksSkipped++;
                    }
                } catch (\Throwable $e) {
                    $ticksSkipped++;
                    $tickResults[] = [
                        'tick'  => $i + 1,
                        'ok'    => false,
                        'error' => $e->getMessage(),
                    ];
                }

                // Sleep between ticks if time allows another iteration
                if ($i < $maxTicks - 1) {
                    $elapsed   = microtime(true) - $loopStart;
                    $remaining = $maxRuntimeSec - $elapsed;
                    if ($remaining > $intervalSec) {
                        sleep($intervalSec);
                    } else {
                        break; // not enough time for another full tick
                    }
                }
            }
        } finally {
            // Always release the loop lock
            @unlink($lockPath);
        }

        $durationMs = (int)round((microtime(true) - $loopStart) * 1000);

        $result = array_merge($baseResult, [
            'ok'                     => true,
            'started_at'             => $ts,
            'finished_at'            => date('c'),
            'duration_ms'            => $durationMs,
            'ticks_attempted'        => $ticksAttempted,
            'ticks_completed'        => $ticksCompleted,
            'ticks_skipped'          => $ticksSkipped,
            'closes_submitted_total' => $closesTotal,
            'locks_moved_total'      => $locksMovedTotal,
            'errors_total'           => $ticksSkipped,
            'tick_results'           => $tickResults,
        ]);

        $this->writeFastLoopLastRun($result);
        return $result;
    }

    /**
     * Return current module status (without running a tick).
     *
     * @return array
     */
    public function getStatus(): array
    {
        $lastRun    = $this->store->readLastRun();
        $moduleMode = $this->moduleMode();
        $account    = ($moduleMode === 'live') ? 'bybit_live' : 'bybit_demo';

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

        // Check live gateway availability when in live mode
        $liveCloseExecutionEnabled = false;
        $liveGatewayAvailable      = false;
        if ($moduleMode === 'live') {
            $botConfig = $this->loadBotConfig();
            $gw        = $this->getLiveGateway($botConfig);
            $liveGatewayAvailable = ($gw !== null);
            $liveCloseExecutionEnabled = $liveGatewayAvailable;
        }

        $cronToken = (string) ($this->config['cron_token'] ?? '');

        return [
            'enabled'                       => $this->runtimeEnabled,
            'mode'                          => $moduleMode,
            'account'                       => $account,
            'active_profile'                => 'auto',
            'long_profile'                  => 'legacy_safe_long',
            'short_profile'                 => 'baseline_short_lock',
            'last_tick'                     => $lastRun['ts'] ?? null,
            'positions_tracked'             => (int) ($lastRun['valid_positions'] ?? $lastRun['positions'] ?? 0),
            'locks_active'                  => (int) ($lastRun['locks_active'] ?? $this->longProfile->getLockCount()),
            'planned_updates'               => (int) ($lastRun['executed_count'] ?? 0),
            'skipped'                       => (int) ($lastRun['skipped_count']  ?? 0),
            'last_error'                    => $lastError,
            'cron_interval_sec'             => 60,
            'cron_configured'               => ($cronToken !== ''),
            // Close execution capability
            'close_execution_mode'          => $moduleMode,
            'live_close_supported'          => true,
            'live_close_execution_enabled'  => $liveCloseExecutionEnabled,
            'demo_close_execution_enabled'  => ($moduleMode === 'demo'),
            'live_gateway_available'        => $liveGatewayAvailable,
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

    /**
     * Build a config snapshot array for last_run diagnostics.
     * Includes effective profile settings for both long and short profiles.
     */
    private function buildConfigSnapshot(): array
    {
        $lc = $this->longProfile->getConfig();
        $sc = $this->shortProfile->getConfig();
        return [
            'long_profile'           => 'legacy_safe_long',
            'long_profile_enabled'   => true,
            'long_init_roi'          => (float) ($lc['init_roi']       ?? 2.0),
            'long_activation_roi'    => (float) ($lc['activation_roi'] ?? 10.0),
            'long_step_roi'          => (float) ($lc['step_roi']       ?? 3.0),
            'long_lock_floor_roi'    => (float) ($lc['lock_floor_roi'] ?? 5.0),
            'short_profile'          => 'baseline_short_lock',
            'short_profile_enabled'  => true,
            'short_init_roi'         => (float) ($sc['init_roi']       ?? 2.0),
            'short_activation_roi'   => (float) ($sc['activation_roi'] ?? 8.0),
            'short_step_roi'         => (float) ($sc['step_roi']       ?? 3.0),
            'short_lock_floor_roi'   => (float) ($sc['lock_floor_roi'] ?? 4.0),
        ];
    }

    /**
     * Resolve the canonical module mode from config.
     * Only 'demo' and 'live' are valid. All other values fall back to 'demo'.
     */
    private function moduleMode(): string
    {
        $raw = (string)($this->config['mode'] ?? 'demo');
        return match ($raw) {
            'live'  => 'live',
            'demo'  => 'demo',
            default => 'demo',
        };
    }

    /**
     * Determine the effective close mode for a specific position.
     *
     * Prefer explicit position mode/execution_mode when present and valid.
     * Otherwise fall back to moduleMode (when position has no explicit mode).
     *
     * Cross-gateway safety rules:
     *   - If position has an explicit mode that differs from moduleMode → return 'mode_mismatch'.
     *     The caller must NOT close, must record close_attempted=false.
     *   - Never close a live position through demo gateway.
     *   - Never close a demo position through live gateway.
     *   - If position has no explicit mode → fallback to moduleMode is allowed.
     */
    private function resolveCloseMode(array $pos, string $moduleMode): string
    {
        $posMode = '';
        foreach (['execution_mode', 'mode'] as $field) {
            $v = (string)($pos[$field] ?? '');
            if ($v === 'live' || $v === 'demo') {
                $posMode = $v;
                break;
            }
        }

        if ($posMode === '') {
            // No valid explicit mode in position — use module config mode (safe fallback)
            return $moduleMode;
        }

        // Cross-gateway safety: position mode must match module mode.
        // Return a sentinel so the caller can record the mismatch and skip the close.
        if ($posMode !== $moduleMode) {
            return 'mode_mismatch';
        }

        return $posMode;
    }

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
     * Used to obtain Bybit credentials for PM-initiated close orders.
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
    private function fetchDemoPositionExists(
        \Core\Gateway\Bybit $gw,
        string $symbol,
        string $side,
        ?float &$exchangeSize = null,
        ?int &$positionIdx = null
    ): bool {
        return $this->fetchPositionExists($gw, $symbol, $side, $exchangeSize, $positionIdx);
    }

    /**
     * Get a Bybit Live gateway client via KeyCenter.
     *
     * Uses account_id from the bot config (single source of truth for live credentials).
     * Never uses demo credentials, never falls back.
     * Returns null when account_id is not configured or client creation fails.
     */
    private function getLiveGateway(array $botConfig): ?\Core\Gateway\Bybit
    {
        $accountId = trim((string)($botConfig['account_id'] ?? ''));
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
     * Shared position existence check for both demo and live gateways.
     *
     * Verifies the position still exists on the exchange with non-zero size.
     * Passes back exchange size and positionIdx if available.
     *
     * @param  \Core\Gateway\Bybit $gw
     * @param  string              $symbol
     * @param  string              $side           canonical: 'long' or 'short'
     * @param  float|null          $exchangeSize   output: exchange-reported position size
     * @param  int|null            $positionIdx    output: positionIdx from exchange record
     * @return bool
     */
    private function fetchPositionExists(
        \Core\Gateway\Bybit $gw,
        string $symbol,
        string $side,
        ?float &$exchangeSize = null,
        ?int &$positionIdx = null
    ): bool {
        $exchangeSize = null;
        $positionIdx  = null;

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
                    $exchangeSize = (float)($pos['size'] ?? 0);
                    $positionIdx  = isset($pos['positionIdx']) ? (int)$pos['positionIdx'] : null;
                    return true;
                }
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check whether a specific symbol+side live position still exists.
     * Wraps fetchPositionExists() for live gateway.
     */
    private function fetchLivePositionExists(
        \Core\Gateway\Bybit $gw,
        string $symbol,
        string $side,
        ?float &$exchangeSize = null,
        ?int &$positionIdx = null
    ): bool {
        return $this->fetchPositionExists($gw, $symbol, $side, $exchangeSize, $positionIdx);
    }

    /**
     * Read the duplicate close guard registry.
     * Returns the registry array (keyed by symbol_side_mode).
     */
    private function readCloseAttempts(): array
    {
        $path = $this->moduleDir . '/storage/runtime/close_attempts.json';
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $dec = @json_decode($raw, true);
        return is_array($dec) ? $dec : [];
    }

    /**
     * Write the duplicate close guard registry.
     */
    private function writeCloseAttempts(array $registry): void
    {
        $path = $this->moduleDir . '/storage/runtime/close_attempts.json';
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $path,
            json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );
    }

    /**
     * Check for a recent duplicate close attempt.
     *
     * @param  string $symbol
     * @param  string $side
     * @param  string $mode    'demo' or 'live'
     * @param  int    $ttl     TTL in seconds (default 60)
     * @return bool  true = duplicate detected (skip), false = proceed
     */
    private function duplicateCloseGuardCheck(string $symbol, string $side, string $mode, int $ttl = 60): bool
    {
        $registry = $this->readCloseAttempts();
        $key      = strtolower($symbol) . '_' . $side . '_' . $mode;
        if (!isset($registry[$key])) {
            return false;
        }
        $lastTs = (int)($registry[$key]['ts'] ?? 0);
        return (time() - $lastTs) < $ttl;
    }

    /**
     * Record a successful close attempt to prevent duplicates.
     */
    private function duplicateCloseGuardSet(string $symbol, string $side, string $mode): void
    {
        $registry = $this->readCloseAttempts();
        $key      = strtolower($symbol) . '_' . $side . '_' . $mode;

        // Prune expired entries (older than 5 minutes) before writing
        $now = time();
        foreach (array_keys($registry) as $k) {
            if (($now - (int)($registry[$k]['ts'] ?? 0)) > 300) {
                unset($registry[$k]);
            }
        }

        $registry[$key] = [
            'symbol' => $symbol,
            'side'   => $side,
            'mode'   => $mode,
            'ts'     => $now,
        ];

        $this->writeCloseAttempts($registry);
    }


    /**
     * Submit a demo reduce-only Market close order on Bybit Demo.
     *
     * Safety checks (performed before order submission):
     *   - symbol must be non-empty
     *   - side must be 'long' or 'short'
     *   - size must be > 0
     *   - no recent duplicate close for same symbol+side+mode
     *   - position must still exist on Bybit Demo (fetched live)
     *
     * For long positions: Bybit side = Sell
     * For short positions: Bybit side = Buy
     *
     * @param string $symbol    Position symbol (e.g. 'BTCUSDT')
     * @param string $side      Canonical side: 'long' or 'short'
     * @param float  $size      Position size in base currency (contracts)
     * @param array  $position  Original position record (for positionIdx, etc.)
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
    private function closeDemoPosition(string $symbol, string $side, float $size, array $position = []): array
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

        // Duplicate close guard
        if ($this->duplicateCloseGuardCheck($symbol, $side, 'demo')) {
            $result['close_error_reason'] = 'duplicate_close_guard';
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
        $exchangeSize = null;
        $exchangeIdx  = null;
        if (!$this->fetchDemoPositionExists($gw, $symbol, $side, $exchangeSize, $exchangeIdx)) {
            $result['close_error_reason'] = 'position_already_gone';
            return $result;
        }

        // Prefer exchange size if available (safer for reduceOnly)
        $closeSize = ($exchangeSize !== null && $exchangeSize > 0.0) ? $exchangeSize : $size;
        if ($exchangeSize !== null && $exchangeSize > 0.0 && $exchangeSize !== $size) {
            $result['close_size_source'] = 'exchange_position_size';
        }

        // positionIdx: prefer position record, then exchange, then default 0
        $posIdx = isset($position['positionIdx']) ? (int)$position['positionIdx']
                : ($exchangeIdx ?? 0);

        // Build close order
        $bybitSide = ($side === 'long') ? 'Sell' : 'Buy';
        $qtyStr    = rtrim(rtrim(number_format($closeSize, 8, '.', ''), '0'), '.');

        $result['close_attempted'] = true;

        try {
            $orderResp = $gw->request('/v5/order/create', [
                'category'    => 'linear',
                'symbol'      => $symbol,
                'side'        => $bybitSide,
                'orderType'   => 'Market',
                'qty'         => $qtyStr,
                'reduceOnly'  => true,
                'positionIdx' => $posIdx,
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
            $this->duplicateCloseGuardSet($symbol, $side, 'demo');
        } else {
            $result['close_error_reason'] = 'order_rejected';
        }

        return $result;
    }

    /**
     * Submit a live reduce-only Market close order on Bybit Live.
     *
     * Mirrors closeDemoPosition() but uses live credentials via KeyCenter.
     *
     * Safety requirements:
     *   - symbol must be non-empty
     *   - side must be 'long' or 'short'
     *   - size must be > 0
     *   - no recent duplicate close for same symbol+side+mode
     *   - position must still exist on Bybit Live (fetched live)
     *   - position side must match expected side
     *   - uses Market order, reduceOnly=true, category=linear
     *   - does NOT create a reverse position
     *
     * For long positions: Bybit side = Sell
     * For short positions: Bybit side = Buy
     *
     * @param string $symbol    Position symbol (e.g. 'BTCUSDT')
     * @param string $side      Canonical side: 'long' or 'short'
     * @param float  $size      Position size in base currency (contracts)
     * @param array  $position  Original position record (for positionIdx, etc.)
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
    private function closeLivePosition(string $symbol, string $side, float $size, array $position = []): array
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

        // Duplicate close guard
        if ($this->duplicateCloseGuardCheck($symbol, $side, 'live')) {
            $result['close_error_reason'] = 'duplicate_close_guard';
            return $result;
        }

        // Obtain live gateway via KeyCenter
        $botConfig = $this->loadBotConfig();
        $gw        = $this->getLiveGateway($botConfig);
        if ($gw === null) {
            $result['close_error_reason'] = 'live_credentials_missing';
            return $result;
        }

        // Safety: verify live position still exists before sending close order
        $exchangeSize = null;
        $exchangeIdx  = null;
        if (!$this->fetchLivePositionExists($gw, $symbol, $side, $exchangeSize, $exchangeIdx)) {
            $result['close_error_reason'] = 'position_already_gone';
            return $result;
        }

        // Exchange size check: do not submit if exchange size is zero
        if ($exchangeSize !== null && $exchangeSize <= 0.0) {
            $result['close_error_reason'] = 'exchange_size_zero';
            return $result;
        }

        // Prefer exchange size if available (safer for reduceOnly)
        $closeSize = ($exchangeSize !== null && $exchangeSize > 0.0) ? $exchangeSize : $size;
        if ($exchangeSize !== null && $exchangeSize > 0.0 && $exchangeSize !== $size) {
            $result['close_size_source'] = 'exchange_position_size';
        }

        // positionIdx: prefer position record, then exchange, then default 0
        $posIdx = isset($position['positionIdx']) ? (int)$position['positionIdx']
                : ($exchangeIdx ?? 0);

        // Build close order — reduceOnly prevents reverse position
        $bybitSide = ($side === 'long') ? 'Sell' : 'Buy';
        $qtyStr    = rtrim(rtrim(number_format($closeSize, 8, '.', ''), '0'), '.');

        $result['close_attempted'] = true;

        try {
            $orderResp = $gw->request('/v5/order/create', [
                'category'    => 'linear',
                'symbol'      => $symbol,
                'side'        => $bybitSide,
                'orderType'   => 'Market',
                'qty'         => $qtyStr,
                'reduceOnly'  => true,
                'positionIdx' => $posIdx,
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
            $this->duplicateCloseGuardSet($symbol, $side, 'live');
        } else {
            $result['close_error_reason'] = 'order_rejected';
        }

        return $result;
    }

    /**
     * Write an entry to the PM close registry so the bot journal can attribute
     * the close to Profit Manager when the position disappears from Bybit,
     * and so the bot can suppress re-entry for the same signal within the TTL.
     *
     * File: modules/bot/storage/runtime/pm_close_registry.json
     * Key:  {symbol}_{side}
     *
     * @param string      $symbol
     * @param string      $side
     * @param string      $closeReason
     * @param string|null $closeOrderId
     * @param string      $mode          'demo' or 'live'
     * @param array       $posContext    Original position record (for signal_id, strategy_id, etc.)
     */
    private function writePmCloseRegistry(
        string  $symbol,
        string  $side,
        string  $closeReason,
        ?string $closeOrderId,
        string  $mode = 'demo',
        array   $posContext = []
    ): void {
        $registryPath = $this->repoRoot . '/modules/bot/storage/runtime/pm_close_registry.json';
        $dir          = dirname($registryPath);

        // Configurable suppression TTL (default 3600 seconds = 1 hour)
        $suppressionTtl = (int)($this->config['pm_close_reentry_suppression_sec'] ?? 3600);
        $suppressionTtl = max(60, $suppressionTtl); // at least 60 seconds

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

            $now = time();
            $key = $symbol . '_' . $side;
            $registry[$key] = [
                'mode'                   => $mode,
                'symbol'                 => $symbol,
                'side'                   => $side,
                'signal_id'              => (string)($posContext['signal_id']       ?? ''),
                'strategy_id'            => (string)($posContext['strategy_id']     ?? ''),
                'owner_strategy'         => (string)($posContext['owner_strategy']  ?? $posContext['strategy_id'] ?? ''),
                'close_source'           => 'profit_manager',
                'close_reason'           => $closeReason,
                'close_order_id'         => $closeOrderId,
                'profile_used'           => (string)($posContext['profile_used']    ?? ''),
                'position_opened_at'     => $posContext['opened_at']      ?? $posContext['created_at']    ?? null,
                'bot_submitted_at'       => $posContext['bot_submitted_at']  ?? $posContext['submitted_at'] ?? null,
                'ts'                     => $now,
                'suppress_reentry_until' => $now + $suppressionTtl,
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

    // =========================================================================
    // Fast loop helpers
    // =========================================================================

    /**
     * Write the fast-tick result to storage/runtime/last_fast_run.json.
     * Never throws — write failures are silently swallowed to avoid crashing
     * the calling tick.
     */
    private function writeFastLastRun(array $data): void
    {
        $path = $this->moduleDir . '/storage/runtime/last_fast_run.json';
        $dir  = dirname($path);
        try {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents(
                $path,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
                LOCK_EX
            );
        } catch (\Throwable) {
            // swallow — never crash a tick over a diagnostic write
        }
    }

    /**
     * Write the fast-loop summary to storage/runtime/last_fast_loop.json.
     * Never throws.
     */
    private function writeFastLoopLastRun(array $data): void
    {
        $path = $this->moduleDir . '/storage/runtime/last_fast_loop.json';
        $dir  = dirname($path);
        try {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents(
                $path,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
                LOCK_EX
            );
        } catch (\Throwable) {
            // swallow
        }
    }

    /**
     * Absolute path to the fast-loop overlap-guard lock file.
     */
    private function getFastLoopLockPath(): string
    {
        return $this->moduleDir . '/storage/runtime/fast_loop.lock';
    }

    // =========================================================================
    // PM exchange profit-floor sync
    // =========================================================================

    /**
     * Sync PM virtual lock price to exchange as a safety stopLoss floor.
     *
     * The exchange stopLoss is a backup ONLY — PM virtual lock remains the
     * primary smart exit logic.  This method sets a stopLoss that sits a
     * configurable buffer below the virtual lock, so if PM fast tick misses,
     * the exchange will close near the intended floor.
     *
     * Safety gates:
     *   - pm_exchange_profit_floor_sync_enabled must be true
     *   - current mode must be in pm_exchange_profit_floor_modes
     *   - for live: pm_exchange_profit_floor_live_enabled must also be true
     *   - virtual_lock_roi must be >= pm_exchange_profit_floor_min_roi
     *   - update throttled by pm_exchange_profit_floor_min_update_interval_seconds
     *   - update only if floor improved by pm_exchange_profit_floor_min_improvement_roi
     *
     * @param string $moduleMode     Current PM mode ('demo' or 'live')
     * @param array  $rawPositions   Raw position records from the position reader
     * @param array  $posRuntime     Per-position runtime records (from this tick)
     * @return array Diagnostic result with counters and examples
     */
    public function syncExchangeProfitFloor(
        string $moduleMode,
        array  $rawPositions,
        array  $posRuntime
    ): array {
        $cfg            = $this->config;
        $enabled        = (bool)($cfg['pm_exchange_profit_floor_sync_enabled']              ?? false);
        $allowedModes   = (array)($cfg['pm_exchange_profit_floor_modes']                    ?? ['demo']);
        $liveEnabled    = (bool)($cfg['pm_exchange_profit_floor_live_enabled']              ?? false);
        $minRoi         = (float)($cfg['pm_exchange_profit_floor_min_roi']                  ?? 8.0);
        $bufferRoi      = (float)($cfg['pm_exchange_profit_floor_buffer_roi']               ?? 3.0);
        $minUpdateSec   = (int)($cfg['pm_exchange_profit_floor_min_update_interval_seconds'] ?? 30);
        $minImprovRoi   = (float)($cfg['pm_exchange_profit_floor_min_improvement_roi']      ?? 2.0);
        $useMarkPrice   = (bool)($cfg['pm_exchange_profit_floor_use_mark_price']            ?? true);
        $triggerBy      = $useMarkPrice ? 'MarkPrice' : 'LastPrice';

        $result = [
            'pm_floor_sync_enabled'           => $enabled,
            'pm_floor_sync_mode'              => $moduleMode,
            'pm_floor_sync_attempted_total'   => 0,
            'pm_floor_sync_set_total'         => 0,
            'pm_floor_sync_skipped_total'     => 0,
            'pm_floor_sync_failed_total'      => 0,
            'pm_floor_sync_examples'          => [],
            'pm_floor_sync_skipped_examples'  => [],
            'pm_floor_sync_failed_examples'   => [],
        ];

        if (!$enabled) {
            $result['pm_floor_sync_skip_reason'] = 'disabled';
            return $result;
        }

        if (!in_array($moduleMode, $allowedModes, true)) {
            $result['pm_floor_sync_skip_reason'] = 'mode_not_in_allowed_modes';
            return $result;
        }

        if ($moduleMode === 'live' && !$liveEnabled) {
            $result['pm_floor_sync_skip_reason']          = 'live_profit_floor_sync_skipped_disabled';
            $result['live_profit_floor_sync_skipped_disabled'] = true;
            return $result;
        }

        // Load sync state (throttle cache)
        $stateFile = $this->moduleDir . '/storage/runtime/pm_profit_floor_sync_state.json';
        $syncState = [];
        if (is_file($stateFile)) {
            try {
                $raw = @file_get_contents($stateFile);
                if ($raw !== false) {
                    $dec = json_decode($raw, true);
                    if (is_array($dec)) {
                        $syncState = $dec;
                    }
                }
            } catch (\Throwable) {}
        }

        $botConfig = $this->loadBotConfig();
        $gw = ($moduleMode === 'live') ? $this->getLiveGateway($botConfig) : $this->getDemoGateway($botConfig);

        if ($gw === null) {
            $result['pm_floor_sync_skip_reason'] = 'no_gateway';
            return $result;
        }

        // Build position lookup by symbol+side from raw positions
        $posIdx = [];
        foreach ($rawPositions as $rp) {
            $sym  = strtoupper((string)($rp['symbol'] ?? ''));
            $side = strtolower((string)($rp['side']   ?? ''));
            if ($sym !== '' && in_array($side, ['long', 'short'], true)) {
                $posIdx["{$sym}_{$side}"] = $rp;
            }
        }

        $nowTs           = time();
        $syncStateChanged = false;

        foreach ($posRuntime as $pr) {
            $symbol = strtoupper((string)($pr['symbol'] ?? ''));
            $side   = strtolower((string)($pr['side']   ?? ''));

            if ($symbol === '' || !in_array($side, ['long', 'short'], true)) {
                continue;
            }

            $lockActive = (bool)($pr['lock_active'] ?? false);
            $lockPrice  = isset($pr['lock_price']) && $pr['lock_price'] > 0.0 ? (float)$pr['lock_price'] : null;

            if (!$lockActive || $lockPrice === null) {
                continue;
            }

            $rawPos    = $posIdx["{$symbol}_{$side}"] ?? null;
            $entryPrice = (float)($rawPos['entry_price'] ?? $rawPos['avg_price'] ?? $pr['entry_price'] ?? 0.0);
            $leverage   = (float)($rawPos['leverage']   ?? $rawPos['bot_leverage'] ?? 0.0);

            if ($entryPrice <= 0.0 || $leverage <= 0.0) {
                $result['pm_floor_sync_skipped_total']++;
                $result['pm_floor_sync_skipped_examples'][] = [
                    'symbol' => $symbol, 'side' => $side,
                    'skip_reason' => 'missing_entry_or_leverage',
                ];
                continue;
            }

            // Compute virtual lock ROI
            if ($side === 'long') {
                $virtualLockRoi = ($lockPrice - $entryPrice) / $entryPrice * 100.0 * $leverage;
            } else {
                $virtualLockRoi = ($entryPrice - $lockPrice) / $entryPrice * 100.0 * $leverage;
            }

            if ($virtualLockRoi < $minRoi) {
                $result['pm_floor_sync_skipped_total']++;
                $result['pm_floor_sync_skipped_examples'][] = [
                    'symbol'          => $symbol,
                    'side'            => $side,
                    'virtual_lock_roi'=> round($virtualLockRoi, 4),
                    'min_roi'         => $minRoi,
                    'skip_reason'     => 'below_min_roi',
                ];
                continue;
            }

            // Compute exchange floor ROI and stop price
            $exchangeFloorRoi = max(0.0, $virtualLockRoi - $bufferRoi);
            if ($side === 'long') {
                $floorStopPrice = $entryPrice * (1.0 + $exchangeFloorRoi / $leverage / 100.0);
            } else {
                $floorStopPrice = $entryPrice * (1.0 - $exchangeFloorRoi / $leverage / 100.0);
            }

            if ($floorStopPrice <= 0.0) {
                $result['pm_floor_sync_skipped_total']++;
                continue;
            }

            // Throttle check — use PM identity key so a new position on the same
            // symbol/side does not inherit the previous position's throttle state.
            $rawPosForKey = $rawPos ?? [];
            $stateKey    = \Modules\ProfManager\Profiles\Long\LongProfile::buildPositionIdentityKey(
                array_merge($rawPosForKey, ['symbol' => $symbol, 'side' => $side]),
                $side
            );
            // Legacy fallback: check old symbol_side slot if identity key slot is empty
            if (!isset($syncState[$stateKey])) {
                $legacyKey = "{$symbol}_{$side}";
                if (isset($syncState[$legacyKey])) {
                    // Legacy entry present — use it only if it belongs to the same
                    // position (no identity fields in legacy → discard to prevent
                    // a new position inheriting old throttle state).
                    // Since legacy entries have no identity fields, we always discard.
                    unset($syncState[$legacyKey]);
                }
            }
            $posState    = $syncState[$stateKey] ?? null;
            $lastSetTs   = (int)($posState['last_set_ts']    ?? 0);
            $lastFloorRoi= (float)($posState['last_floor_roi'] ?? 0.0);

            if ($lastSetTs > 0 && ($nowTs - $lastSetTs) < $minUpdateSec) {
                $result['pm_floor_sync_skipped_total']++;
                $result['pm_floor_sync_skipped_examples'][] = [
                    'symbol'      => $symbol,
                    'side'        => $side,
                    'skip_reason' => 'throttled',
                    'seconds_since_last' => $nowTs - $lastSetTs,
                    'min_update_interval'=> $minUpdateSec,
                ];
                continue;
            }

            // Min improvement check
            if ($lastFloorRoi > 0.0 && ($exchangeFloorRoi - $lastFloorRoi) < $minImprovRoi) {
                $result['pm_floor_sync_skipped_total']++;
                $result['pm_floor_sync_skipped_examples'][] = [
                    'symbol'             => $symbol,
                    'side'               => $side,
                    'skip_reason'        => 'insufficient_improvement',
                    'exchange_floor_roi' => round($exchangeFloorRoi, 4),
                    'last_floor_roi'     => round($lastFloorRoi, 4),
                    'min_improvement'    => $minImprovRoi,
                ];
                continue;
            }

            $result['pm_floor_sync_attempted_total']++;

            // Call exchange
            $stopStr = rtrim(rtrim(number_format($floorStopPrice, 8, '.', ''), '0'), '.');
            $positionIdx = 0; // default one-way mode
            try {
                $resp = $gw->request('/v5/position/trading-stop', [
                    'category'    => 'linear',
                    'symbol'      => $symbol,
                    'stopLoss'    => $stopStr,
                    'slTriggerBy' => $triggerBy,
                    'tpslMode'    => 'Full',
                    'positionIdx' => $positionIdx,
                ], true);
                $retCode = (int)($resp['ret_code'] ?? -1);
                $retMsg  = (string)($resp['ret_msg'] ?? '');
            } catch (\Throwable $ex) {
                $retCode = -1;
                $retMsg  = $ex->getMessage();
            }

            $ok = $retCode === 0
                || $retCode === 110043
                || stripos($retMsg, 'not modified') !== false
                || stripos($retMsg, 'not been modified') !== false;

            $ex = [
                'symbol'              => $symbol,
                'side'                => $side,
                'mode'                => $moduleMode,
                'entry_price'         => $entryPrice,
                'lock_price'          => $lockPrice,
                'virtual_lock_roi'    => round($virtualLockRoi, 4),
                'exchange_floor_roi'  => round($exchangeFloorRoi, 4),
                'floor_stop_price'    => $floorStopPrice,
                'positionIdx'         => $positionIdx,
                'slTriggerBy'         => $triggerBy,
                'ret_code'            => $retCode,
                'ret_msg'             => $retMsg,
                'ok'                  => $ok,
            ];

            if ($ok) {
                $result['pm_floor_sync_set_total']++;
                if (count($result['pm_floor_sync_examples']) < 5) {
                    $result['pm_floor_sync_examples'][] = $ex;
                }
                $syncState[$stateKey] = [
                    'last_floor_roi'   => $exchangeFloorRoi,
                    'last_stop_price'  => $floorStopPrice,
                    'last_set_ts'      => $nowTs,
                    'last_lock_price'  => $lockPrice,
                    'last_set_at'      => date('c', $nowTs),
                ];
                $syncStateChanged = true;
            } else {
                $result['pm_floor_sync_failed_total']++;
                if (count($result['pm_floor_sync_failed_examples']) < 5) {
                    $result['pm_floor_sync_failed_examples'][] = $ex;
                }
            }
        }

        // Prune stale entries from sync state
        $activeKeys = [];
        foreach ($posRuntime as $pr) {
            $sym  = strtoupper((string)($pr['symbol'] ?? ''));
            $side = strtolower((string)($pr['side']   ?? ''));
            if ($sym !== '' && in_array($side, ['long', 'short'], true)) {
                $activeKeys[] = "{$sym}_{$side}";
            }
        }
        foreach (array_keys($syncState) as $k) {
            if (!in_array($k, $activeKeys, true)) {
                unset($syncState[$k]);
                $syncStateChanged = true;
            }
        }

        if ($syncStateChanged) {
            try {
                $dir = dirname($stateFile);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents(
                    $stateFile,
                    json_encode($syncState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
                    LOCK_EX
                );
            } catch (\Throwable) {}
        }

        return $result;
    }

}
