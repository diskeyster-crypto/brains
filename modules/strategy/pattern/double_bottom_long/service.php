<?php

declare(strict_types=1);

/**
 * Double Bottom Long Strategy — Service
 *
 * Long-only module. Short path, double_top, and all short-side logic removed.
 *
 * Orchestrates the pipeline:
 *   market_regime → trend → corridor → bucket → wave → double_bottom → control_check → signal
 *
 * ── Synchronous path (manual_list) ───────────────────────────────────────────
 *   run()        Full scan in one call.
 *
 * ── Batched path (all-universe) ──────────────────────────────────────────────
 *   queueRun()   Write run_state.json with status=queued and return immediately.
 *   tickBatch()  Process one batch (batch_size symbols).  Called by cron.
 *
 * Derived from modules/strategy/pattern/service.php.
 * No bot execution is wired in this foundation version.
 */

namespace Modules\Strategy\DoubleBottomLong;

final class DoubleBottomLongService
{
    private static ?self $instance = null;
    private string $moduleDir;
    private string $repoRoot;

    private const H4_INTERVAL = '240';  // Bybit kline interval

    /** @var \OrderBookContextService|null */
    private ?\OrderBookContextService $obcService = null;

    // ── Per-tick OBC wall counters (reset at start of each tickBatch) ─────────
    private int $obWallCheckedTotal       = 0;
    private int $obWallFetchSuccessTotal  = 0;
    private int $obWallFetchFailedTotal   = 0;
    private int $obWallAskRiskTotal       = 0;
    private int $obWallBidSupportTotal    = 0;
    private int $obWallAskEatenTotal      = 0;
    private int $obWallSoftDemoteTotal    = 0;
    private int $obWallPendingTotal       = 0;
    private int $obWallHardRejectTotal    = 0;
    // OBC skip counters — gate enabled but fetch skipped for various reasons
    private int $obWallSkipQualityBelowThresholdTotal = 0;
    private int $obWallSkipServiceUnavailableTotal     = 0;
    private int $obWallSkipMissingEntryPriceTotal      = 0;
    /** @var list<array<string,mixed>> */
    private array $obWallSkipExamples = [];
    // OBC soft_demote handoff-block counters (accumulated across normalization passes per tick)
    private int $obWallSoftDemoteBlockedHandoffTotal = 0;
    private int $obWallSoftDemoteAllowedHandoffTotal = 0;
    /** @var list<array<string,mixed>> */
    private array $obWallSoftDemoteBlockExamples = [];

    // ── DBL garbage veto counters (reset at start of each tickBatch) ──────────
    private int $dblGarbageVetoCheckedTotal           = 0;
    private int $dblGarbageVetoBlockedTotal           = 0;
    private int $dblGarbageLowQualityWithoutObcTotal  = 0;
    private int $dblGarbageObcQualitySkipTotal        = 0;
    private int $dblGarbageLateExtensionTotal         = 0;
    private int $dblGarbageWhipsawWeakQualityTotal    = 0;
    private int $dblGarbageLateLocalEntryCheckedTotal = 0;
    private int $dblGarbageLateLocalEntryBlockedTotal = 0;
    private int $dblGarbageLateLocalLowQualityBlockedTotal = 0;
    private int $dblGarbageLateLocalMidQualityBlockedTotal = 0;
    private int $dblGarbageLateLocalHighQualityBlockedTotal = 0;
    private int $dblGarbageLateLocalPassedDueQualityTotal = 0;
    private int $dblGarbageEntryFarFromPoint3Total    = 0;
    private int $dblGarbagePostPoint3ImpulseSpentTotal = 0;
    private int $dblGarbageInsufficientRoomSwingHighTotal = 0;
    private int $dblGarbageNearRecentSwingHighTotal   = 0;
    private int $dblGarbagePassedTotal                = 0;
    private int $dblGarbageLateLocalTinyRoomWithoutReclaimTotal = 0;
    // ── DBL funnel counters (reset at start of each tickBatch) ───────────────
    private int $dblRawCandidatesTotal                = 0;
    // ── DBL near-miss counters (reset at start of each tickBatch) ────────────
    private int $dblNearMissTotal                     = 0;
    /** @var array<string,int> */
    private array $dblNearMissByReason                = [];
    /** @var list<array<string,mixed>> */
    private array $dblGarbageBlockExamples = [];
    /** @var list<array<string,mixed>> */
    private array $dblGarbagePassExamples  = [];
    /** @var list<array<string,mixed>> */
    private array $dblGarbageLateLocalEntryExamples = [];
    /** @var list<array<string,mixed>> */
    private array $dblGarbageLateLocalPassedExamples = [];
    /** @var list<array<string,mixed>> */
    private array $dblGarbageLateLocalTinyRoomWithoutReclaimExamples = [];
    /** @var list<array<string,mixed>> */
    private array $dblNearMissExamples               = [];
    // ── DBL trend-shift confirmation gate counters (reset at start of each tickBatch) ──
    private int $dblTrendShiftCheckedTotal                       = 0;
    private int $dblTrendShiftConfirmedTotal                     = 0;
    private int $dblTrendShiftPendingTotal                       = 0;
    private int $dblTrendShiftFailedTotal                        = 0;
    private int $dblTrendShiftReclaimHoldConfirmedTotal          = 0;
    private int $dblTrendShiftRetestHoldConfirmedTotal           = 0;
    private int $dblTrendShiftHigherLowConfirmedTotal            = 0;
    private int $dblTrendShiftShortStructureBreakConfirmedTotal  = 0;
    private int $dblTrendShiftReclaimLostTotal                   = 0;
    private int $dblTrendShiftPoint3BrokenTotal                  = 0;
    private int $dblTrendShiftFreshLowerLowTotal                 = 0;
    private int $dblTrendShiftPendingExpiredTotal                = 0;
    private int $dblTrendShiftHandoffBlockedUnconfirmedTotal     = 0;
    /** @var list<array<string,mixed>> */
    private array $dblTrendShiftConfirmedExamples           = [];
    /** @var list<array<string,mixed>> */
    private array $dblTrendShiftPendingExamples             = [];
    /** @var list<array<string,mixed>> */
    private array $dblTrendShiftFailedExamples              = [];
    /** @var list<array<string,mixed>> */
    private array $dblTrendShiftBlockedUnconfirmedExamples  = [];
    // ── DBL pattern-status state machine counters (reset at start of each tickBatch) ──
    private int $dblPatternStatusCheckedTotal               = 0;
    private int $dblPatternActiveTotal                      = 0;
    private int $dblPatternConfirmedTotal                   = 0;
    private int $dblPatternInvalidTotal                     = 0;
    private int $dblPatternPendingTotal                     = 0;
    private int $dblPatternPendingRecheckedTotal            = 0;
    private int $dblPatternPendingConfirmedTotal            = 0;
    private int $dblPatternPendingInvalidatedTotal          = 0;
    private int $dblPatternPendingExpiredTotal              = 0;
    private int $dblPatternConfirmedNecklineBreakTotal      = 0;
    private int $dblPatternConfirmedReclaimHoldTotal        = 0;
    private int $dblPatternConfirmedRetestHoldTotal         = 0;
    private int $dblPatternConfirmedHigherLowTotal          = 0;
    private int $dblPatternInvalidPoint3BrokenTotal         = 0;
    private int $dblPatternInvalidFreshLowerLowTotal        = 0;
    private int $dblPatternInvalidReclaimLostTotal          = 0;
    private int $dblTraceIncompleteTotal                    = 0;
    private int $dblTraceReconstructedBeforePatternStateTotal = 0;
    private int $dblTraceReconstructionFailedBeforePatternStateTotal = 0;
    private int $reclaimLostCheckedTotal                    = 0;
    private int $reclaimLostTerminalTotal                   = 0;
    private int $reclaimLostRecoveredTotal                  = 0;
    private int $reclaimLostAmbiguousTotal                  = 0;
    private int $patternStateBlockedBeforeGarbageTotal      = 0;
    private int $dblPatternInvalidTtlExpiredTotal           = 0;
    private int $dblPatternInvalidWeakBounceTotal           = 0;
    private int $dblPatternConfirmedSentToGarbageVetoTotal  = 0;
    private int $dblPatternActiveBlockedFromHandoffTotal    = 0;
    private int $dblPatternInvalidBlockedFromHandoffTotal   = 0;
    private int $dblPatternConfirmedHandoffReadyTotal       = 0;
    /** @var list<array<string,mixed>> */
    private array $dblPatternActiveExamples                 = [];
    /** @var list<array<string,mixed>> */
    private array $dblPatternConfirmedExamples              = [];
    /** @var list<array<string,mixed>> */
    private array $dblPatternInvalidExamples                = [];
    /** @var list<array<string,mixed>> */
    private array $dblPatternPendingExamples                = [];
    /** @var list<array<string,mixed>> */
    private array $dblPatternPendingConfirmedExamples       = [];
    /** @var list<array<string,mixed>> */
    private array $dblPatternPendingInvalidatedExamples     = [];
    /** @var list<array<string,mixed>> */
    private array $dblTraceIncompleteExamples               = [];
    /** @var list<array<string,mixed>> */
    private array $reclaimLostTerminalExamples              = [];
    /** @var list<array<string,mixed>> */
    private array $reclaimLostRecoveredExamples             = [];
    /** @var list<array<string,mixed>> */
    private array $patternStateBlockedBeforeGarbageExamples = [];
    private int $dblPatternPendingSweepTotal              = 0;
    private int $dblPatternPendingSweepConfirmedTotal     = 0;
    private int $dblPatternPendingSweepInvalidTotal       = 0;
    private int $dblPatternPendingSweepExpiredTotal       = 0;
    private int $dblPatternPendingSweepStillActiveTotal   = 0;
    private int $dblPatternPendingStorageBeforeTotal      = 0;
    private int $dblPatternPendingStorageAfterTotal       = 0;
    private int $dblPatternPendingStorageStaleRemovedTotal = 0;
    // ── Confirmed-pattern freshness counters (reset at start of each tickBatch) ─
    private int $confirmedPatternFreshnessCheckedTotal          = 0;
    private int $confirmedPatternValidForHandoffTotal           = 0;
    private int $confirmedPatternExpiredTotal                   = 0;
    private int $confirmedPatternInvalidatedTotal               = 0;
    private int $confirmedPatternBlockedByGenericFreshnessTotal = 0;
    // ── Lifecycle consistency counters (reset at start of each tickBatch) ──────
    private int $lifecycleConsistencyCheckedTotal               = 0;
    private int $lifecycleInconsistentFixedTotal                = 0;
    /** @var list<array<string,mixed>> */
    private array $lifecycleInconsistentExamples                = [];
    // ── DBL trace completeness counters (reset at start of each tickBatch) ────
    private int $dblTraceCheckedTotal                        = 0;
    private int $dblTraceCompleteTotal                       = 0;
    private int $dblTraceMissingTotal                        = 0;
    private int $dblTraceReconstructedTotal                  = 0;
    private int $dblTraceReconstructionFailedTotal           = 0;
    private int $dblGarbageMissingTraceBlockedTotal          = 0;
    private int $dblGarbageReclaimNotConfirmedTotal          = 0;
    private int $dblGarbageMissingTracePassedHighQualityTotal = 0;
    /** @var list<array<string,mixed>> */
    private array $dblTraceMissingExamples               = [];
    /** @var list<array<string,mixed>> */
    private array $dblTraceReconstructedExamples         = [];
    /** @var list<array<string,mixed>> */
    private array $dblGarbageMissingTraceBlockExamples   = [];
    /** @var list<array<string,mixed>> */
    private array $dblGarbageReclaimNotConfirmedExamples = [];

    // ── Per-tick entry-context fetch counters (reset at start of each tickBatch) ──
    private int $ctxFetchAttemptedThisTick       = 0;
    private int $ctxFetchSuccessThisTick         = 0;
    private int $ctxFetchFailedThisTick          = 0;
    private int $ctxFetchSkippedPrefilterThisTick = 0;
    private int $ctxFetchSkippedLimitThisTick     = 0;

    // ── Per-tick scan suppression counters (reset at start of each tickBatch) ──
    private int $scanSuppressionSkippedThisTick  = 0;
    private int $scanSuppressionAddedThisTick    = 0;
    private int $scanSuppressionRefreshedThisTick = 0;
    private int $scanSuppressionExpiredThisTick  = 0;
    private int $ctxFetchCapReachedThisTick      = 0;
    /** @var array<string,mixed> Loaded from storage at start of batch, persisted after */
    private array $scanSuppressionCache           = [];
    /** @var list<array<string,mixed>> */
    private array $scanSuppressionSkipExamples   = [];
    /** @var list<array<string,mixed>> */
    private array $scanSuppressionAddedExamples  = [];
    /** @var list<array<string,mixed>> */
    private array $scanSuppressionExpiredExamples = [];
    /** @var list<array<string,mixed>> */
    private array $ctxFetchCapExamples           = [];

    public function __construct(?string $moduleDir = null)
    {
        if ($moduleDir !== null) {
            $this->moduleDir = rtrim($moduleDir, '/');
        } else {
            $this->moduleDir = rtrim(
                \Core\System\SystemPaths::instance()->get('strategy.double_bottom_long'),
                '/'
            );
        }
        // Repo root is 4 levels above modules/strategy/pattern/double_bottom_long/
        $this->repoRoot = rtrim(dirname($this->moduleDir, 4), '/');
        // ── OrderBook Wall Context service (optional; fails gracefully) ─────
        $obcDir = $this->repoRoot . '/modules/system/orderbook_context';
        if (is_file($obcDir . '/service.php')) {
            try {
                require_once $obcDir . '/service.php';
                $this->obcService = new \OrderBookContextService($obcDir);
            } catch (\Throwable) {
                $this->obcService = null;
            }
        }
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
            return DoubleBottomLongBootstrap::instance($this->moduleDir)->load()['config'];
        } catch (\Throwable) {
            return [];
        }
    }

    public function getLastRun(): array
    {
        return $this->readJson('storage/last_run.json', []);
    }

    public function getStats(): array
    {
        return $this->readJson('storage/stats.json', []);
    }

    public function getCycleStats(): array
    {
        return $this->readJson('storage/cycle_stats.json', []);
    }

    public function getSignals(): array
    {
        return $this->readJson('storage/signals.json', []);
    }

    public function getMarketRegime(): array
    {
        return $this->readJson('storage/market_regime.json', []);
    }

    public function getRunState(): array
    {
        return $this->readJson('storage/run_state.json', ['status' => 'idle']);
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

    public function loadStorage(string $file): mixed
    {
        return $this->readJson('storage/' . $file, []);
    }

    // =========================================================================
    // Scan orchestration
    // =========================================================================

    /**
     * Queue a batched run (async-safe, for large universes).
     */
    public function queueRun(): array
    {
        $config = $this->getConfig();
        if (empty($config)) {
            return ['ok' => false, 'error' => 'Config load failed'];
        }

        $prevState   = $this->getRunState();
        $fullSymbols = $this->buildUniverse($config);   // full filtered registry

        // ── Registry cursor rotation ─────────────────────────────────────────
        // max_symbols_per_run is a per-cycle WINDOW SIZE, not a permanent cap.
        // The cursor persists in run_state.json['next_registry_cursor'] across cycles
        // so each new queueRun() advances to the next unseen window of symbols.
        $registryTotal     = count($fullSymbols);
        $maxSymbols        = (int)($config['max_symbols_per_run'] ?? 0);
        if ($maxSymbols <= 0) {
            $maxSymbols = $registryTotal;   // 0 = scan entire registry per cycle
        }

        $prevCursor        = (int)($prevState['next_registry_cursor'] ?? 0);
        $cursorResetReason = null;
        if ($registryTotal > 0 && ($prevCursor < 0 || $prevCursor >= $registryTotal)) {
            $prevCursor        = 0;
            $cursorResetReason = 'cursor_out_of_range';
        }
        $windowStart       = $prevCursor;
        $registryWrapped   = false;
        $prevRound         = (int)($prevState['full_registry_scan_round'] ?? 0);
        $registryScanRound = $prevRound;

        if ($registryTotal === 0) {
            // Empty registry — no windowing possible.
            $universe        = [];
            $windowEnd       = 0;
            $nextCursor      = 0;
            $selectedTotal   = 0;
        } elseif ($maxSymbols >= $registryTotal) {
            // Window covers the entire registry: always scan all, cursor resets to 0.
            $universe          = $fullSymbols;
            $windowEnd         = $registryTotal - 1;
            $nextCursor        = 0;
            $selectedTotal     = $registryTotal;
            $registryWrapped   = true;
            $registryScanRound = $prevRound + 1;
        } else {
            // Windowed scan: take up to $maxSymbols starting at $windowStart.
            // Do NOT wrap within one cycle; just take what is available up to the end.
            $available     = $registryTotal - $windowStart;
            $selectedCount = min($maxSymbols, $available);
            $universe      = array_slice($fullSymbols, $windowStart, $selectedCount);
            $windowEnd     = $windowStart + $selectedCount - 1;
            $nextCursor    = ($windowStart + $selectedCount) % $registryTotal;
            $selectedTotal = $selectedCount;
            if (($windowStart + $selectedCount) >= $registryTotal) {
                $registryWrapped   = true;
                $registryScanRound = $prevRound + 1;
            }
        }
        // ── end rotation ─────────────────────────────────────────────────────

        // Cycle-local stats are reset on each new queue run; cumulative stats.json is never wiped.
        $this->writeJson('storage/cycle_stats.json', $this->zeroStats());
        foreach ([
            'signals.json'  => [],
            'last_run.json' => ['status' => 'queued', 'started_at' => date('c')],
        ] as $f => $v) {
            if (!file_exists($this->moduleDir . '/storage/' . $f)) {
                $this->writeJson('storage/' . $f, $v);
            }
        }
        // Current-cycle candidate snapshots are always reset on a new queued cycle.
        $this->writeJson('storage/candidates_found.json', []);
        $this->writeJson('storage/candidates_emitted.json', []);
        // Bot handoff queue persists across cycles to maintain lifecycle states.
        // Only initialise the file if it does not yet exist.
        if (!file_exists($this->moduleDir . '/storage/bot_handoff_queue.json')) {
            $this->writeJson('storage/bot_handoff_queue.json', []);
        }
        $regimePath = $this->moduleDir . '/storage/market_regime.json';
        $regimeRaw  = file_exists($regimePath) ? (string)@file_get_contents($regimePath) : '';
        if (empty(json_decode($regimeRaw, true))) {
            $this->writeJson('storage/market_regime.json', ['regime' => 'unknown', 'ts' => date('c')]);
        }
        if (!file_exists($this->moduleDir . '/storage/market_regime_history.ndjson')) {
            file_put_contents($this->moduleDir . '/storage/market_regime_history.ndjson', '');
        }
        if (!file_exists($this->moduleDir . '/storage/cycle_history.ndjson')) {
            file_put_contents($this->moduleDir . '/storage/cycle_history.ndjson', '');
        }

        $cycleId    = (int)($prevState['cycle_id'] ?? 0);
        $nowIso     = date('c');
        $regDiag    = $this->readJson('storage/registry_diag.json', []);
        $regEmpty   = $registryTotal === 0;
        $queueEmpty = count($universe) === 0;
        $state = [
            'status'        => 'queued',
            'queued_at'     => $nowIso,
            'cycle_started_at' => $nowIso,
            'cycle_id'      => $cycleId,
            'current_cycle_id' => $cycleId,
            'cumulative_cycles_completed' => (int)($prevState['cumulative_cycles_completed'] ?? $cycleId),
            'symbols'       => $universe,
            'total'         => count($universe),
            'cursor'        => 0,
            'processed'     => 0,
            'found'         => 0,
            'processed_symbols' => 0,
            'total_symbols'     => count($universe),
            'remaining_symbols' => count($universe),
            'signals_json_semantics' => 'active_rolling_pool_across_cycles_ttl',
            'signals_active_final_total' => count($this->getSignals()),
            'final_signals_total'        => count($this->getSignals()),
            'signals_emitted_total'      => (int)($this->getStats()['signals_emitted_total'] ?? 0),
            'cron_enabled'            => (bool)($config['enabled'] ?? false),
            'last_tick_at'            => $prevState['last_tick_at'] ?? null,
            'last_tick_result'        => $prevState['last_tick_result'] ?? 'queued',
            'run_status'              => 'queued',
            'continuous_scan_enabled' => (bool)($config['continuous_scan_enabled'] ?? true),
            'errors'        => [],
            // Registry diagnostics — promoted to top level for easy consumption
            'registry_diag'             => $regDiag,
            'registry_source_path'      => $regDiag['registry_source_path']  ?? null,
            'registry_loaded'           => (bool)($regDiag['registry_loaded'] ?? false),
            'registry_symbol_count'     => $registryTotal,
            'registry_total'            => $registryTotal,
            'registry_empty'            => $regEmpty,
            'queue_empty_universe'      => $queueEmpty,
            // next_retry_allowed=true lets the next cron tick auto-requeue if the universe was empty
            'next_retry_allowed'        => $queueEmpty ? (bool)($config['continuous_scan_enabled'] ?? true) : false,
            'auto_requeued_from_status' => null,
            // Registry rotation cursor fields
            'registry_cursor'             => $windowStart,
            'previous_registry_cursor'    => $prevCursor,
            'next_registry_cursor'        => $nextCursor,
            'registry_window_start'       => $windowStart,
            'registry_window_end'         => $windowEnd,
            'registry_wrapped'            => $registryWrapped,
            'full_registry_scan_round'    => $registryScanRound,
            'selected_symbols_total'      => $selectedTotal,
            'max_symbols_per_run'         => (int)($config['max_symbols_per_run'] ?? 0),
            'batch_size'                  => (int)($config['batch_size'] ?? 50),
            'registry_cursor_reset_reason' => $cursorResetReason,
        ];
        $this->writeJson('storage/run_state.json', $state);
        return ['ok' => true, 'total' => count($universe)];
    }

    /**
     * CronManager entry-point: advance one batch.
     */
    public function tickBatch(): void
    {
        $state  = $this->getRunState();
        $status = $state['status'] ?? 'idle';

        // Auto-queue when:
        //  - status is 'idle' (first cron tick after module is enabled), OR
        //  - status is 'done' with continuous scan enabled (includes empty-registry recovery).
        //
        // The 'done' branch handles the case where a previous cycle completed with an empty
        // universe (registry was not yet populated) and set next_cycle_ready=true.  Without
        // this check the module stays frozen at status=done and never retries.
        $isDoneRetryable = ($status === 'done') && (
            (bool)($state['continuous_scan_enabled'] ?? false)
            || (bool)($state['next_cycle_ready']     ?? false)
            || (int)($state['total']                 ?? -1) === 0
        );

        if ($status === 'idle' || $isDoneRetryable) {
            $cfg = $this->getConfig();
            if ((bool)($cfg['enabled'] ?? false) && (bool)($cfg['continuous_scan_enabled'] ?? true)) {
                $autoRequeuedFrom = $status;
                $qResult = $this->queueRun();
                if (!($qResult['ok'] ?? false)) {
                    return;
                }
                $state  = $this->getRunState();
                // Stamp a diagnostic so operators can confirm the module recovered from 'done'.
                if ($autoRequeuedFrom === 'done') {
                    $state['auto_requeued_from_status'] = $autoRequeuedFrom;
                    $this->writeJson('storage/run_state.json', $state);
                }
                $status = $state['status'] ?? 'idle';
            }
            if (in_array($status, ['idle', 'done'], true)) {
                return;
            }
        }

        if ($status === 'queued') {
            $state['status']     = 'running';
            $state['started_at'] = date('c');
            $this->writeJson('storage/run_state.json', $state);
        }

        if (!in_array($state['status'] ?? '', ['queued', 'running'], true)) {
            return;
        }

        $config  = $this->getConfig();
        $batchSz = max(1, (int)($config['batch_size'] ?? 20));
        $maxSec  = max(10, (int)($config['max_runtime_seconds'] ?? 55));

        // Reset per-tick entry-context fetch counters.
        $this->ctxFetchAttemptedThisTick        = 0;
        $this->ctxFetchSuccessThisTick          = 0;
        $this->ctxFetchFailedThisTick           = 0;
        $this->ctxFetchSkippedPrefilterThisTick = 0;
        $this->ctxFetchSkippedLimitThisTick     = 0;
        // Reset per-tick OBC wall counters.
        $this->obWallCheckedTotal      = 0;
        $this->obWallFetchSuccessTotal = 0;
        $this->obWallFetchFailedTotal  = 0;
        $this->obWallAskRiskTotal      = 0;
        $this->obWallBidSupportTotal   = 0;
        $this->obWallAskEatenTotal     = 0;
        $this->obWallSoftDemoteTotal   = 0;
        $this->obWallPendingTotal      = 0;
        $this->obWallHardRejectTotal   = 0;
        $this->obWallSkipQualityBelowThresholdTotal = 0;
        $this->obWallSkipServiceUnavailableTotal     = 0;
        $this->obWallSkipMissingEntryPriceTotal      = 0;
        $this->obWallSkipExamples                    = [];
        $this->obWallSoftDemoteBlockedHandoffTotal   = 0;
        $this->obWallSoftDemoteAllowedHandoffTotal   = 0;
        $this->obWallSoftDemoteBlockExamples         = [];
        // Reset per-tick scan suppression counters.
        $this->scanSuppressionSkippedThisTick   = 0;
        $this->scanSuppressionAddedThisTick     = 0;
        $this->scanSuppressionRefreshedThisTick = 0;
        $this->scanSuppressionExpiredThisTick   = 0;
        $this->ctxFetchCapReachedThisTick       = 0;
        $this->scanSuppressionSkipExamples      = [];
        $this->scanSuppressionAddedExamples     = [];
        $this->scanSuppressionExpiredExamples   = [];
        $this->ctxFetchCapExamples              = [];
        // Reset per-tick garbage veto counters.
        $this->dblGarbageVetoCheckedTotal          = 0;
        $this->dblGarbageVetoBlockedTotal          = 0;
        $this->dblGarbageLowQualityWithoutObcTotal = 0;
        $this->dblGarbageObcQualitySkipTotal       = 0;
        $this->dblGarbageLateExtensionTotal        = 0;
        $this->dblGarbageWhipsawWeakQualityTotal   = 0;
        $this->dblGarbageLateLocalEntryCheckedTotal = 0;
        $this->dblGarbageLateLocalEntryBlockedTotal = 0;
        $this->dblGarbageLateLocalLowQualityBlockedTotal = 0;
        $this->dblGarbageLateLocalMidQualityBlockedTotal = 0;
        $this->dblGarbageLateLocalHighQualityBlockedTotal = 0;
        $this->dblGarbageLateLocalPassedDueQualityTotal = 0;
        $this->dblGarbageEntryFarFromPoint3Total = 0;
        $this->dblGarbagePostPoint3ImpulseSpentTotal = 0;
        $this->dblGarbageInsufficientRoomSwingHighTotal = 0;
        $this->dblGarbageNearRecentSwingHighTotal = 0;
        $this->dblGarbagePassedTotal               = 0;
        $this->dblGarbageLateLocalTinyRoomWithoutReclaimTotal = 0;
        $this->dblRawCandidatesTotal               = 0;
        $this->dblNearMissTotal                    = 0;
        $this->dblNearMissByReason                 = [];
        $this->dblGarbageBlockExamples             = [];
        $this->dblGarbagePassExamples              = [];
        $this->dblGarbageLateLocalEntryExamples    = [];
        $this->dblGarbageLateLocalPassedExamples   = [];
        $this->dblGarbageLateLocalTinyRoomWithoutReclaimExamples = [];
        $this->dblNearMissExamples                 = [];
        // Reset per-tick trace completeness counters.
        $this->dblTraceCheckedTotal                        = 0;
        $this->dblTraceCompleteTotal                       = 0;
        $this->dblTraceMissingTotal                        = 0;
        $this->dblTraceReconstructedTotal                  = 0;
        $this->dblTraceReconstructionFailedTotal           = 0;
        $this->dblGarbageMissingTraceBlockedTotal          = 0;
        $this->dblGarbageReclaimNotConfirmedTotal          = 0;
        $this->dblGarbageMissingTracePassedHighQualityTotal = 0;
        $this->dblTraceMissingExamples               = [];
        $this->dblTraceReconstructedExamples         = [];
        $this->dblGarbageMissingTraceBlockExamples   = [];
        $this->dblGarbageReclaimNotConfirmedExamples = [];
        // Reset per-tick trend-shift gate counters.
        $this->dblTrendShiftCheckedTotal                      = 0;
        $this->dblTrendShiftConfirmedTotal                    = 0;
        $this->dblTrendShiftPendingTotal                      = 0;
        $this->dblTrendShiftFailedTotal                       = 0;
        $this->dblTrendShiftReclaimHoldConfirmedTotal         = 0;
        $this->dblTrendShiftRetestHoldConfirmedTotal          = 0;
        $this->dblTrendShiftHigherLowConfirmedTotal           = 0;
        $this->dblTrendShiftShortStructureBreakConfirmedTotal = 0;
        $this->dblTrendShiftReclaimLostTotal                  = 0;
        $this->dblTrendShiftPoint3BrokenTotal                 = 0;
        $this->dblTrendShiftFreshLowerLowTotal                = 0;
        $this->dblTrendShiftPendingExpiredTotal               = 0;
        $this->dblTrendShiftHandoffBlockedUnconfirmedTotal    = 0;
        $this->dblTrendShiftConfirmedExamples          = [];
        $this->dblTrendShiftPendingExamples            = [];
        $this->dblTrendShiftFailedExamples             = [];
        $this->dblTrendShiftBlockedUnconfirmedExamples = [];
        // Reset per-tick pattern-status state machine counters.
        $this->dblPatternStatusCheckedTotal              = 0;
        $this->dblPatternActiveTotal                     = 0;
        $this->dblPatternConfirmedTotal                  = 0;
        $this->dblPatternInvalidTotal                    = 0;
        $this->dblPatternPendingTotal                    = 0;
        $this->dblPatternPendingRecheckedTotal           = 0;
        $this->dblPatternPendingConfirmedTotal           = 0;
        $this->dblPatternPendingInvalidatedTotal         = 0;
        $this->dblPatternPendingExpiredTotal             = 0;
        $this->dblPatternConfirmedNecklineBreakTotal     = 0;
        $this->dblPatternConfirmedReclaimHoldTotal       = 0;
        $this->dblPatternConfirmedRetestHoldTotal        = 0;
        $this->dblPatternConfirmedHigherLowTotal         = 0;
        $this->dblPatternInvalidPoint3BrokenTotal        = 0;
        $this->dblPatternInvalidFreshLowerLowTotal       = 0;
        $this->dblPatternInvalidReclaimLostTotal         = 0;
        $this->dblTraceIncompleteTotal                   = 0;
        $this->dblTraceReconstructedBeforePatternStateTotal = 0;
        $this->dblTraceReconstructionFailedBeforePatternStateTotal = 0;
        $this->reclaimLostCheckedTotal                   = 0;
        $this->reclaimLostTerminalTotal                  = 0;
        $this->reclaimLostRecoveredTotal                 = 0;
        $this->reclaimLostAmbiguousTotal                 = 0;
        $this->patternStateBlockedBeforeGarbageTotal     = 0;
        $this->dblPatternInvalidTtlExpiredTotal          = 0;
        $this->dblPatternInvalidWeakBounceTotal          = 0;
        $this->dblPatternConfirmedSentToGarbageVetoTotal = 0;
        $this->dblPatternActiveBlockedFromHandoffTotal   = 0;
        $this->dblPatternInvalidBlockedFromHandoffTotal  = 0;
        $this->dblPatternConfirmedHandoffReadyTotal      = 0;
        $this->dblPatternActiveExamples            = [];
        $this->dblPatternConfirmedExamples         = [];
        $this->dblPatternInvalidExamples           = [];
        $this->dblPatternPendingExamples           = [];
        $this->dblPatternPendingConfirmedExamples  = [];
        $this->dblPatternPendingInvalidatedExamples = [];
        $this->dblTraceIncompleteExamples          = [];
        $this->reclaimLostTerminalExamples         = [];
        $this->reclaimLostRecoveredExamples        = [];
        $this->patternStateBlockedBeforeGarbageExamples = [];
        $this->dblPatternPendingSweepTotal               = 0;
        $this->dblPatternPendingSweepConfirmedTotal      = 0;
        $this->dblPatternPendingSweepInvalidTotal        = 0;
        $this->dblPatternPendingSweepExpiredTotal        = 0;
        $this->dblPatternPendingSweepStillActiveTotal    = 0;
        $this->dblPatternPendingStorageBeforeTotal       = 0;
        $this->dblPatternPendingStorageAfterTotal        = 0;
        $this->dblPatternPendingStorageStaleRemovedTotal = 0;
        // Reset per-tick confirmed-pattern freshness counters.
        $this->confirmedPatternFreshnessCheckedTotal          = 0;
        $this->confirmedPatternValidForHandoffTotal           = 0;
        $this->confirmedPatternExpiredTotal                   = 0;
        $this->confirmedPatternInvalidatedTotal               = 0;
        $this->confirmedPatternBlockedByGenericFreshnessTotal = 0;
        // Reset per-tick lifecycle consistency counters.
        $this->lifecycleConsistencyCheckedTotal               = 0;
        $this->lifecycleInconsistentFixedTotal                = 0;
        $this->lifecycleInconsistentExamples                  = [];

        $symbols = (array)($state['symbols']  ?? []);
        $cursor  = (int)($state['cursor']      ?? 0);
        $total   = count($symbols);
        $tStart  = time();

        $stats      = array_merge($this->zeroStats(), $this->getStats());
        $cycleStats = array_merge($this->zeroStats(), $this->getCycleStats());
        $signals = $this->expireSignals($this->getSignals(), $config);

        $regDiag = (array)($state['registry_diag'] ?? []);
        $stats['registry_loaded']            = (bool)($regDiag['registry_loaded']      ?? false);
        $stats['registry_symbol_count']      = (int)($regDiag['registry_symbol_count'] ?? 0);
        $cycleStats['registry_loaded']       = (bool)($regDiag['registry_loaded']      ?? false);
        $cycleStats['registry_symbol_count'] = (int)($regDiag['registry_symbol_count'] ?? 0);

        // On cycle rollover: reset only cycle-local stats; cumulative stats.json is never wiped.
        if ($cursor === 0 && !empty($state['reset_stats_on_next_cycle'])) {
            $cycleStats                           = $this->zeroStats();
            $cycleStats['registry_loaded']        = (bool)($regDiag['registry_loaded']       ?? false);
            $cycleStats['registry_symbol_count']  = (int)($regDiag['registry_symbol_count']  ?? 0);
            unset($state['reset_stats_on_next_cycle']);
        }

        $regimeEnabled  = (bool)($config['market_regime_enabled'] ?? true);
        $prevRegimeData = $this->readJson('storage/market_regime.json', []);
        if (empty($prevRegimeData)) {
            $prevRegimeData = ['regime' => 'unknown', 'ts' => date('c')];
            $this->writeJson('storage/market_regime.json', $prevRegimeData);
        }
        $prevRegimeStr = (string)($prevRegimeData['regime'] ?? 'unknown');

        if ($regimeEnabled && $cursor === 0 && $total > 0) {
            $this->computeAndPersistRegime($symbols, $config, $prevRegimeStr);
            $prevRegimeData = $this->readJson('storage/market_regime.json', []);
        }

        $regimeStr = (string)($prevRegimeData['regime'] ?? 'unknown');

        $processed        = 0;
        $found            = 0;
        $batchPreviewRows = [];
        $newlyEmitted     = [];
        $tickAt           = date('c');
        $foundCandidates   = (array)$this->readJson('storage/candidates_found.json', []);
        $emittedCandidates = (array)$this->readJson('storage/candidates_emitted.json', []);
        $synQFailedExamples = [];  // accumulated per tick, max 5
        // bad_accept diagnostics: emitted signals with adverse price moves
        $badAcceptExamples = [];
        $activeHandoffBySymbol = [];
        if ((bool)($config['bad_accept_diagnostic_enabled'] ?? true)) {
            $hqRaw = (array)$this->readJson('storage/bot_handoff_queue.json', []);
            foreach ($hqRaw as $hq) {
                $s = strtolower((string)($hq['symbol'] ?? ''));
                if ($s !== '' && in_array($hq['handoff_status'] ?? '', ['new', 'refreshed'], true)) {
                    $activeHandoffBySymbol[$s] = $hq;
                }
            }
        }
        // Calibration example arrays — accumulated per tick for last_run.json (Task 6)
        $normalSignalExamples             = [];
        $rejectedSignalExamples           = [];
        $finalLowQualityRejectExamples    = [];
        $setupAllowedQualityFailedExamples = [];
        $setupAllowedPendingExamples       = [];
        $setupAllowedFinalGateWarnExamples = [];
        $lateGoodSetupExamples             = [];
        // Trace counters (Task 4)
        $emittedSignalsWithTraceTotal    = 0;
        $emittedSignalsMissingTraceTotal = 0;
        $handoffEntriesWithTraceTotal    = 0;
        $handoffEntriesMissingTraceTotal = 0;
        $missingTraceExamples            = [];

        // Pre-initialise pending confirmation counters for this tick
        $pendingConfirmationStats = [
            'loaded_total'                               => 0,
            'rechecked_total'                            => 0,
            'added_total'                                => 0,
            'confirmed_total'                            => 0,
            'invalidated_total'                          => 0,
            'expired_total'                              => 0,
            'active_total'                               => 0,
            'invalidated_fresh_dump_total'               => 0,
            'invalidated_reclaim_lost_total'             => 0,
            'invalidated_base_support_broken_total'      => 0,
            'invalidated_falling_knife_total'            => 0,
            'invalidated_entry_distance_worsened_total'  => 0,
            'invalidated_stale_setup_total'              => 0,
            'confirmed_dropped_total'                    => 0,
            'confirmed_examples'                         => [],
            'invalidated_examples'                       => [],
        ];

        // Recheck pending confirmations first (before normal symbol processing)
        if ((bool)($config['pending_confirmation_enabled'] ?? true)
            && (bool)($config['pending_confirmation_recheck_first'] ?? true)
        ) {
            $pendingRecheckResult = $this->recheckPendingConfirmations($config, $regimeStr);
            foreach ($pendingRecheckResult['newly_emitted'] as $sig) {
                $sig['_setup_signal_allowed'] = true;
                $newlyEmitted[] = $sig;
                // Trace check for pending-confirmation emissions
                // (these bypass the per-symbol emission trace check below).
                $traceFieldsAlwaysPending = ['quality_source'];
                $isPendingSynth = (string)($sig['quality_source'] ?? '') === 'synthetic_intraday_setup';
                $traceFieldsSynthPending  = $isPendingSynth ? [
                    'neckline_level', 'reclaim_level', 'entry_distance_from_neckline_pct',
                    'entry_distance_from_reclaim_pct', 'synthetic_quality_score',
                    'setup_class_score', 'intraday_double_bottom_score',
                    'synthetic_quality_pass',
                ] : [];
                $pendingTraceFields   = array_merge($traceFieldsAlwaysPending, $traceFieldsSynthPending);
                $pendingMissingFields = [];
                foreach ($pendingTraceFields as $ptf) {
                    if (($sig[$ptf] ?? null) === null) {
                        $pendingMissingFields[] = $ptf;
                    }
                }
                if (empty($pendingMissingFields)) {
                    $emittedSignalsWithTraceTotal++;
                } else {
                    $emittedSignalsMissingTraceTotal++;
                    if (count($missingTraceExamples) < 5) {
                        $missingTraceExamples[] = [
                            'symbol'          => $sig['symbol'] ?? null,
                            'signal_id'       => $sig['signal_id'] ?? null,
                            'missing_fields'  => $pendingMissingFields,
                            'setup_class'     => $sig['setup_class'] ?? null,
                            'quality_source'  => $sig['quality_source'] ?? null,
                            'is_current_run'  => true,
                            'reason'          => 'missing_at_pending_confirmation_emission',
                        ];
                    }
                }
            }
            $pendingConfirmationStats['loaded_total']      = $pendingRecheckResult['loaded_total'];
            $pendingConfirmationStats['rechecked_total']   = $pendingRecheckResult['rechecked_total'];
            $pendingConfirmationStats['confirmed_total']   = $pendingRecheckResult['confirmed_total'];
            $pendingConfirmationStats['invalidated_total'] = $pendingRecheckResult['invalidated_total'];
            $pendingConfirmationStats['expired_total']     = $pendingRecheckResult['expired_total'];
            $pendingConfirmationStats['active_total']      = $pendingRecheckResult['active_total'];
            $pendingConfirmationStats['invalidated_fresh_dump_total']              = $pendingRecheckResult['invalidated_fresh_dump_total']              ?? 0;
            $pendingConfirmationStats['invalidated_reclaim_lost_total']            = $pendingRecheckResult['invalidated_reclaim_lost_total']            ?? 0;
            $pendingConfirmationStats['invalidated_base_support_broken_total']     = $pendingRecheckResult['invalidated_base_support_broken_total']     ?? 0;
            $pendingConfirmationStats['invalidated_falling_knife_total']           = $pendingRecheckResult['invalidated_falling_knife_total']           ?? 0;
            $pendingConfirmationStats['invalidated_entry_distance_worsened_total'] = $pendingRecheckResult['invalidated_entry_distance_worsened_total'] ?? 0;
            $pendingConfirmationStats['invalidated_stale_setup_total']             = $pendingRecheckResult['invalidated_stale_setup_total']             ?? 0;
            $pendingConfirmationStats['confirmed_examples']                        = $pendingRecheckResult['confirmed_examples']                        ?? [];
            $pendingConfirmationStats['invalidated_examples']                      = $pendingRecheckResult['invalidated_examples']                      ?? [];
        }

        // Pre-initialise fields that are only assigned inside the $isDone block
        // so they are always defined when used in the last_run.json write below.
        $completedCycleId = null;
        $finishedAt       = null;

        // ── Load scan suppression cache ───────────────────────────────────────
        $suppressionEnabled = (bool)($config['scan_suppression_enabled'] ?? true);
        if ($suppressionEnabled) {
            $suppRelFile = (string)($config['scan_suppression_storage_file'] ?? 'storage/scan_suppression.json');
            $suppRaw  = (array)$this->readJson($suppRelFile, []);
            $this->scanSuppressionCache = [];
            foreach ($suppRaw as $entry) {
                $sym = strtolower((string)($entry['symbol'] ?? ''));
                if ($sym !== '') {
                    $this->scanSuppressionCache[$sym] = $entry;
                }
            }
            // Expire stale cache entries on load
            $nowTs = time();
            foreach ($this->scanSuppressionCache as $sym => $entry) {
                $untilTs = strtotime((string)($entry['suppress_until'] ?? '')) ?: 0;
                if ($untilTs > 0 && $nowTs >= $untilTs) {
                    $this->scanSuppressionExpiredThisTick++;
                    if (count($this->scanSuppressionExpiredExamples) < 5) {
                        $this->scanSuppressionExpiredExamples[] = [
                            'symbol'        => $sym,
                            'reason'        => $entry['reason']       ?? null,
                            'failed_stage'  => $entry['failed_stage'] ?? null,
                            'suppress_until'=> $entry['suppress_until'] ?? null,
                            'ttl_minutes'   => $entry['ttl_minutes']  ?? null,
                            'daily_change_pct' => $entry['last_daily_change_pct'] ?? null,
                            'price'         => $entry['last_price']   ?? null,
                            'action'        => 'expired_ttl',
                        ];
                    }
                    unset($this->scanSuppressionCache[$sym]);
                }
            }
        }

        // Build lookup sets for early-expire conditions.
        $pendingSymbolSet = [];
        $pendingConfAll   = (array)$this->readJson($this->moduleDir . '/storage/pending_confirmations.json', []);
        foreach ($pendingConfAll as $pe) {
            $ps = strtolower((string)($pe['symbol'] ?? ''));
            if ($ps !== '') {
                $pendingSymbolSet[$ps] = true;
            }
        }

        while ($cursor < $total && $processed < $batchSz && (time() - $tStart) < $maxSec) {
            $symbol = $symbols[$cursor];
            $cursor++;
            $processed++;

            // ── Scan suppression check ────────────────────────────────────────
            $symLower = strtolower($symbol);
            if ($suppressionEnabled && isset($this->scanSuppressionCache[$symLower])) {
                $suppEntry = $this->scanSuppressionCache[$symLower];
                $earlyExpire = false;
                $earlyExpireReason = null;

                // Early-expire: symbol is in pending confirmations
                if (!$earlyExpire && isset($pendingSymbolSet[$symLower])) {
                    $earlyExpire = true;
                    $earlyExpireReason = 'scan_suppression_expired_pending_exists';
                }

                if ($earlyExpire) {
                    $this->scanSuppressionExpiredThisTick++;
                    if (count($this->scanSuppressionExpiredExamples) < 5) {
                        $this->scanSuppressionExpiredExamples[] = [
                            'symbol'        => $symLower,
                            'reason'        => $earlyExpireReason,
                            'failed_stage'  => $suppEntry['failed_stage'] ?? null,
                            'suppress_until'=> $suppEntry['suppress_until'] ?? null,
                            'ttl_minutes'   => $suppEntry['ttl_minutes']  ?? null,
                            'daily_change_pct' => $suppEntry['last_daily_change_pct'] ?? null,
                            'price'         => $suppEntry['last_price']   ?? null,
                            'action'        => $earlyExpireReason,
                        ];
                    }
                    unset($this->scanSuppressionCache[$symLower]);
                    // Do NOT skip — let the symbol be processed normally since early-expire cleared it
                } else {
                    // Still suppressed — skip expensive processSymbol
                    $this->scanSuppressionSkippedThisTick++;
                    if (count($this->scanSuppressionSkipExamples) < 5) {
                        $this->scanSuppressionSkipExamples[] = [
                            'symbol'        => $symLower,
                            'reason'        => $suppEntry['reason']       ?? null,
                            'failed_stage'  => $suppEntry['failed_stage'] ?? null,
                            'suppress_until'=> $suppEntry['suppress_until'] ?? null,
                            'ttl_minutes'   => $suppEntry['ttl_minutes']  ?? null,
                            'daily_change_pct' => $suppEntry['last_daily_change_pct'] ?? null,
                            'price'         => $suppEntry['last_price']   ?? null,
                            'action'        => 'skipped_by_scan_suppression',
                        ];
                    }
                    // Update observations_count in cache
                    $this->scanSuppressionCache[$symLower]['observations_count'] =
                        ((int)($suppEntry['observations_count'] ?? 0)) + 1;
                    continue;
                }
            }

            try {
                $result = $this->processSymbol($symbol, $config, $regimeStr);
                if (($result['candidate_found'] ?? false)) {
                    $found++;
                    $foundCandidates = $this->mergeCandidateRecord(
                        $foundCandidates,
                        $this->buildFoundCandidateRecord($result, (int)($state['cycle_id'] ?? 0), $tickAt)
                    );
                }
                if ($result['final_signal_status'] === 'emitted') {
                    $sig = $result['signal'];
                    // Tag signal with setup_allowed so applySignalFilters() can track final_eligibility per setup.
                    $sig['_setup_signal_allowed'] = (bool)($result['setup_signal_allowed'] ?? false);
                    // Carry setup_class so applySignalFilters() can apply the adaptive stop-width gate.
                    $sig['setup_class'] = $result['setup_class'] ?? null;
                    // ── Preserve full diagnostic context for trace continuity (Task 1) ──────
                    $sig['strategy']               = 'double_bottom_long';
                    $sig['signal_source']          = 'double_bottom_long';
                    $sig['pattern_algorithm']      = 'double_bottom_long';
                    $sig['candidate_trigger']      = $sig['entry_price'] ?? null;
                    $sig['neckline_level']         = $result['neckline_level']                    ?? null;
                    $sig['reclaim_level']          = $result['reclaim_level']                     ?? null;
                    // A-class: reclaim_level = intraday neckline (price reclaimed above it).
                    // coinCtx reclaim_after_flat may be null when there is no flat_base reclaim.
                    if ($sig['reclaim_level'] === null
                        && ($sig['setup_class'] ?? null) === 'classic_intraday_double_bottom_reclaim'
                    ) {
                        $sig['reclaim_level'] = $result['intraday_db_neckline_level']
                            ?? $result['neckline_level']
                            ?? null;
                    }
                    $sig['entry_distance_from_neckline_pct'] = $result['entry_distance_from_neckline_pct'] ?? null;
                    // entry_distance_from_reclaim_pct may already be in signal; carry from diagBase if not
                    if (!isset($sig['entry_distance_from_reclaim_pct'])) {
                        $sig['entry_distance_from_reclaim_pct'] = $result['entry_distance_from_reclaim_pct'] ?? null;
                    }
                    $sig['late_good_setup']                   = $result['late_good_setup']                   ?? false;
                    $sig['missed_ideal_entry']                = $result['missed_ideal_entry']                ?? false;
                    $sig['waiting_for_better_entry_distance'] = $result['waiting_for_better_entry_distance'] ?? false;
                    // Scores — prefer from diagBase result; signal.php only carries candidate_quality_score
                    $sig['legacy_candidate_quality_score']    = $sig['candidate_quality_score'] ?? null;
                    $sig['synthetic_quality_score']           = $result['synthetic_quality_score']           ?? null;
                    $sig['setup_class_score']                 = $result['setup_class_score']                 ?? null;
                    $sig['intraday_double_bottom_score']      = $result['intraday_double_bottom_score']      ?? null;
                    // Quality context
                    $sig['synthetic_quality_pass']            = $result['synthetic_quality_pass']            ?? null;
                    if (!isset($sig['quality_source'])) {
                        $sig['quality_source'] = $result['quality_source'] ?? null;
                    }
                    $sig['reason_codes']                      = $result['reason_codes']                      ?? null;
                    $sig['warnings']                          = $result['synthetic_quality_warnings']        ?? null;
                    // Pending context (for confirmed-from-pending signals these are already set)
                    if (!isset($sig['pending_confirmation_status'])) {
                        $sig['pending_confirmation_status']   = $result['pending_confirmation_status']       ?? null;
                        $sig['pending_confirmation_reason']   = $result['pending_confirmation_reason']       ?? null;
                    }
                    $sig['pending_created_at']                = $result['pending_confirmation_created_at']   ?? null;
                    $sig['pending_confirmed_at']              = $sig['pending_confirmed_at']                 ?? null;
                    $sig['pending_invalidated_reason']        = null; // emitted = not invalidated
                    // OBC wall context fields — carry from result into signal for consumers
                    $sig['ob_wall_context']   = $result['ob_wall_context']    ?? null;
                    $sig['ob_ask_wall_risk']  = $result['ob_ask_wall_risk']   ?? false;
                    $sig['ob_bid_wall_support'] = $result['ob_bid_wall_support'] ?? false;
                    $sig['ob_ask_wall_eaten'] = $result['ob_ask_wall_eaten']  ?? false;
                    $sig['ob_soft_demoted']   = $result['ob_soft_demoted']    ?? false;
                    $sig['ob_wall_checked']   = $result['ob_wall_checked']    ?? false;
                    $sig['ob_gate_mode']      = $result['ob_gate_mode']             ?? null;
                    $sig['ob_fetch_ok']       = $result['ob_fetch_ok']              ?? null;
                    $sig['ob_ask_wall_distance_pct'] = $result['ob_ask_wall_distance_pct'] ?? null;
                    $sig['ob_bid_wall_distance_pct'] = $result['ob_bid_wall_distance_pct'] ?? null;
                    $sig['ob_ask_wall_status'] = $result['ob_ask_wall_status']      ?? null;
                    $sig['ob_bid_wall_status'] = $result['ob_bid_wall_status']      ?? null;
                    $sig['ob_skip_reason']    = $result['ob_skip_reason']           ?? null;
                    $sig['ob_skip_quality_score'] = $result['ob_skip_quality_score'] ?? null;
                    $sig['ob_skip_required_quality_score'] = $result['ob_skip_required_quality_score'] ?? null;
                    // Ensure strategy_signal_context in signals.json includes all OBC fields for consumers.
                    // PatternSignal::build() does not create this sub-object, so we build/merge it here.
                    $existingSsc = is_array($sig['strategy_signal_context'] ?? null) ? $sig['strategy_signal_context'] : [];
                    $sig['strategy_signal_context'] = array_merge($existingSsc, [
                        'ob_wall_checked'                => $sig['ob_wall_checked'],
                        'ob_wall_context'                => $sig['ob_wall_context'],
                        'ob_ask_wall_risk'               => $sig['ob_ask_wall_risk'],
                        'ob_bid_wall_support'            => $sig['ob_bid_wall_support'],
                        'ob_ask_wall_eaten'              => $sig['ob_ask_wall_eaten'],
                        'ob_soft_demoted'                => $sig['ob_soft_demoted'],
                        'ob_gate_mode'                   => $sig['ob_gate_mode'],
                        'ob_fetch_ok'                    => $sig['ob_fetch_ok'],
                        'ob_ask_wall_distance_pct'       => $sig['ob_ask_wall_distance_pct'],
                        'ob_bid_wall_distance_pct'       => $sig['ob_bid_wall_distance_pct'],
                        'ob_ask_wall_status'             => $sig['ob_ask_wall_status'],
                        'ob_bid_wall_status'             => $sig['ob_bid_wall_status'],
                        'ob_skip_reason'                 => $sig['ob_skip_reason'],
                        'ob_skip_quality_score'          => $sig['ob_skip_quality_score'],
                        'ob_skip_required_quality_score' => $sig['ob_skip_required_quality_score'],
                    ]);
                    // ── Garbage veto context — carry from diagBase result into signal ──
                    $sig['daily_change_pct']           = $result['daily_change_pct']            ?? null;
                    $sig['position_in_24h_range_pct']  = $result['position_in_24h_range_pct']   ?? null;
                    $sig['room_to_24h_high_roi']        = $result['room_to_24h_high_roi']        ?? null;
                    $sig['recent_10m_range_roi']        = $result['recent_10m_range_roi']        ?? null;
                    $sig['recent_60m_range_roi']        = $result['recent_60m_range_roi']        ?? null;
                    $sig['recent_10m_direction_flips']  = $result['recent_10m_direction_flips']  ?? null;
                    $sig['recent_60m_direction_flips']  = $result['recent_60m_direction_flips']  ?? null;
                    $sig['whipsaw_score']               = $result['whipsaw_score']               ?? null;
                    $sig['entry_hour_utc']              = $result['entry_hour_utc']              ?? null;
                    $sig['entry_hour_local_utc3']       = $result['entry_hour_local_utc3']       ?? null;
                    $sig['session_bucket']              = $result['session_bucket']              ?? null;
                    $sig['post_point3_bounce_roi']      = $result['post_point3_bounce_roi']      ?? null;
                    $sig['position_in_local_recovery_leg_pct'] = $result['position_in_local_recovery_leg_pct'] ?? null;
                    $sig['room_to_recent_swing_high_roi'] = $result['room_to_recent_swing_high_roi'] ?? null;
                    $sig['near_recent_swing_high']      = $result['near_recent_swing_high']      ?? null;
                    $sig['post_point3_impulse_spent_pct'] = $result['post_point3_impulse_spent_pct'] ?? null;
                    $sig['local_recovery_leg_low_price'] = $result['local_recovery_leg_low_price'] ?? null;
                    $sig['local_recovery_leg_high_price'] = $result['local_recovery_leg_high_price'] ?? null;
                    $sig['recent_swing_high_price']     = $result['recent_swing_high_price']     ?? null;
                    $sig['recent_swing_high_time']      = $result['recent_swing_high_time']      ?? null;
                    // DBL point trace fields — ensure they propagate to strategy_signal_context
                    // so Stop Manager and downstream consumers have full trace.
                    $existingSscTrace = is_array($sig['strategy_signal_context'] ?? null) ? $sig['strategy_signal_context'] : [];
                    $sig['strategy_signal_context'] = array_merge($existingSscTrace, [
                        // Garbage veto context
                        'daily_change_pct'           => $sig['daily_change_pct'],
                        'position_in_24h_range_pct'  => $sig['position_in_24h_range_pct'],
                        'room_to_24h_high_roi'       => $sig['room_to_24h_high_roi'],
                        'recent_10m_range_roi'       => $sig['recent_10m_range_roi'],
                        'recent_60m_range_roi'       => $sig['recent_60m_range_roi'],
                        'recent_60m_direction_flips' => $sig['recent_60m_direction_flips'],
                        'whipsaw_score'              => $sig['whipsaw_score'],
                        'entry_hour_utc'             => $sig['entry_hour_utc'],
                        'session_bucket'             => $sig['session_bucket'],
                        'post_point3_bounce_roi'     => $sig['post_point3_bounce_roi'],
                        'position_in_local_recovery_leg_pct' => $sig['position_in_local_recovery_leg_pct'],
                        'room_to_recent_swing_high_roi' => $sig['room_to_recent_swing_high_roi'],
                        'near_recent_swing_high'     => $sig['near_recent_swing_high'],
                        'post_point3_impulse_spent_pct' => $sig['post_point3_impulse_spent_pct'],
                        'local_recovery_leg_low_price' => $sig['local_recovery_leg_low_price'],
                        'local_recovery_leg_high_price' => $sig['local_recovery_leg_high_price'],
                        'recent_swing_high_price'    => $sig['recent_swing_high_price'],
                        'recent_swing_high_time'     => $sig['recent_swing_high_time'],
                        // DBL point trace for Stop Manager
                        'point_1_low_price'          => $result['bottom_1_price']                ?? null,
                        'point_1_low_time'           => null,
                        'point_2_neckline_price'     => $result['intraday_db_neckline_level']    ?? $result['neckline_level'] ?? null,
                        'point_2_neckline_time'      => null,
                        'point_3_second_low_price'   => $result['bottom_2_price']               ?? null,
                        'point_3_second_low_time'    => null,
                        'neckline_level'             => $result['neckline_level']                ?? $result['intraday_db_neckline_level'] ?? null,
                        'reclaim_level'              => $result['reclaim_level']                 ?? null,
                        'second_bottom_level'        => $result['bottom_2_price']               ?? null,
                        'entry_distance_from_point3_pct' => $result['entry_distance_from_point3_pct'] ?? $result['entry_distance_from_neckline_pct'] ?? null,
                        'entry_distance_from_reclaim_pct' => $result['entry_distance_from_reclaim_pct'] ?? null,
                        'fresh_lower_low_after_point3' => false,
                        'point3_confirmed'           => true,
                        'reclaim_confirmed'          => (bool)($result['reclaim_after_flat_detected'] ?? false),
                        // Garbage veto result (initialised here; updated in updateBotHandoff)
                        'garbage_veto_checked'       => false,
                        'garbage_veto_triggered'     => false,
                        'garbage_veto_reason'        => null,
                        'garbage_veto_secondary_reasons' => [],
                    ]);
                    // Detect and list any fields that are unavailable (null) — do not fill with fake defaults
                    // Fields always required for trace completeness
                    $traceFieldsAlways = [
                        'quality_source',
                    ];
                    // Fields only required when signal uses synthetic intraday quality path
                    $isSyntheticQuality = (string)($sig['quality_source'] ?? '') === 'synthetic_intraday_setup';
                    $traceFieldsSynth = $isSyntheticQuality ? [
                        'neckline_level', 'reclaim_level', 'entry_distance_from_neckline_pct',
                        'entry_distance_from_reclaim_pct', 'synthetic_quality_score',
                        'setup_class_score', 'intraday_double_bottom_score',
                        'synthetic_quality_pass',
                    ] : [];
                    $traceFields = array_merge($traceFieldsAlways, $traceFieldsSynth);
                    $missingTraceFields = [];
                    foreach ($traceFields as $tf) {
                        if (($sig[$tf] ?? null) === null) {
                            $missingTraceFields[] = $tf;
                        }
                    }
                    $sig['missing_diagnostic_fields'] = $missingTraceFields ?: null;
                    // Trace counter (Task 4): only counts signals emitted in the current run
                    // (this block executes only for final_signal_status=emitted, not stale signals)
                    if (empty($missingTraceFields)) {
                        $emittedSignalsWithTraceTotal++;
                    } else {
                        $emittedSignalsMissingTraceTotal++;
                        if (count($missingTraceExamples) < 5) {
                            $missingTraceExamples[] = [
                                'symbol'          => $sig['symbol'] ?? $symbol,
                                'signal_id'       => $sig['signal_id'] ?? null,
                                'missing_fields'  => $missingTraceFields,
                                'setup_class'     => $sig['setup_class'] ?? null,
                                'quality_source'  => $sig['quality_source'] ?? null,
                                'is_current_run'  => true,
                                'reason'          => 'missing_at_emission',
                            ];
                        }
                    }
                    // Collect normal signal example (Task 6)
                    if (count($normalSignalExamples) < 10) {
                        $normalSignalExamples[] = [
                            'symbol'                         => $sig['symbol']                         ?? $symbol,
                            'signal_id'                      => $sig['signal_id']                      ?? null,
                            'setup_class'                    => $sig['setup_class']                    ?? null,
                            'entry_distance_from_neckline_pct' => $sig['entry_distance_from_neckline_pct'] ?? null,
                            'entry_distance_from_reclaim_pct'  => $sig['entry_distance_from_reclaim_pct']  ?? null,
                            'synthetic_quality_score'        => $sig['synthetic_quality_score']        ?? null,
                            'setup_class_score'              => $sig['setup_class_score']              ?? null,
                            'intraday_double_bottom_score'   => $sig['intraday_double_bottom_score']   ?? null,
                            'candidate_quality_score'        => $sig['candidate_quality_score']        ?? null,
                            'legacy_candidate_quality_score' => $sig['legacy_candidate_quality_score'] ?? null,
                            'quality_source'                 => $sig['quality_source']                 ?? null,
                            'warnings'                       => $sig['warnings']                       ?? null,
                            'reason_codes'                   => $sig['reason_codes']                   ?? null,
                            'pending_confirmation_status'    => $sig['pending_confirmation_status']    ?? null,
                            'final_gate_warnings'            => array_filter([
                                ($sig['final_trend_mismatch_warning']    ?? false) ? 'final_trend_mismatch_warning'    : null,
                                ($sig['final_context_inconsistent_warning'] ?? false) ? 'final_context_inconsistent_warning' : null,
                                ($sig['final_stop_width_warning']        ?? false) ? 'final_stop_width_warning'        : null,
                            ]),
                        ];
                    }
                    $newlyEmitted[] = $sig;
                    $emittedCandidates = $this->mergeCandidateRecord(
                        $emittedCandidates,
                        $this->buildEmittedCandidateRecord($result, (int)($state['cycle_id'] ?? 0), $tickAt)
                    );
                }
                $stats      = $this->accumulateStats($stats,      $result);
                $cycleStats = $this->accumulateStats($cycleStats, $result);

                // Track pending confirmation additions
                if (($result['pending_confirmation_status'] ?? null) === 'added') {
                    $pendingConfirmationStats['added_total']++;
                }

                // Collect synthetic quality fail examples for diagnostics (max 5 per tick)
                if (count($synQFailedExamples) < 5
                    && (
                        (bool)($result['synthetic_quality_checked'] ?? false) && !(bool)($result['synthetic_quality_pass'] ?? false)
                        || (string)($result['reject_reason'] ?? '') === 'synthetic_candidate_quality_failed'
                    )
                ) {
                    $synQFailedExamples[] = [
                        'symbol'                          => $symbol,
                        'setup_class'                     => $result['setup_class']          ?? null,
                        'synthetic_candidate_source'      => $result['synthetic_candidate_source'] ?? null,
                        'synthetic_quality_score'         => $result['synthetic_quality_score']    ?? null,
                        'synthetic_quality_reason'        => $result['synthetic_quality_reason']   ?? null,
                        'synthetic_quality_block_reasons' => $result['synthetic_quality_block_reasons'] ?? [],
                        'entry_context_score'             => $result['entry_context_score']         ?? null,
                        'intraday_double_bottom_score'    => $result['intraday_double_bottom_score']?? null,
                        'setup_class_score'               => $result['setup_class_score']           ?? null,
                        'entry_distance_from_neckline_pct' => $result['entry_distance_from_neckline_pct'] ?? null,
                        'entry_distance_from_reclaim_pct'  => $result['entry_distance_from_reclaim_pct']  ?? null,
                    ];
                }

                $rejectR       = $result['reject_reason']     ?? null;
                $neckDistStatus = 'n/a';
                if ($rejectR === 'price_too_far_above_neckline') { $neckDistStatus = 'too_far_above'; }
                elseif ($rejectR === 'price_too_far_below_neckline') { $neckDistStatus = 'too_far_below'; }
                elseif ($result['candidate_found'] ?? false) { $neckDistStatus = 'ok'; }

                // ── Calibration example collection (Task 6) ──────────────────────────
                $fssForExamples    = $result['final_signal_status'] ?? '';
                $killStageEx       = (string)($result['setup_allowed_kill_stage']  ?? '');
                $killReasonEx      = (string)($result['setup_allowed_kill_reason'] ?? '');
                $setupAllowedEx    = (bool)($result['setup_allowed']               ?? false);
                $pendingStatusEx   = $result['pending_confirmation_status']         ?? null;

                // General rejected signal (candidate found but no signal emitted, not late_good_setup)
                if (($result['candidate_found'] ?? false)
                    && !in_array($fssForExamples, ['emitted', 'late_good_setup', 'pending_confirmation'], true)
                    && count($rejectedSignalExamples) < 10
                ) {
                    $rejectedSignalExamples[] = [
                        'symbol'                         => $symbol,
                        'setup_class'                    => $result['setup_class']                    ?? null,
                        'reject_reason'                  => $rejectR,
                        'failed_stage'                   => $result['failed_stage']                   ?? null,
                        'entry_distance_from_neckline_pct' => $result['entry_distance_from_neckline_pct'] ?? null,
                        'synthetic_quality_score'        => $result['synthetic_quality_score']        ?? null,
                        'setup_class_score'              => $result['setup_class_score']              ?? null,
                        'intraday_double_bottom_score'   => $result['intraday_double_bottom_score']   ?? null,
                        'candidate_quality_score'        => $result['candidate_quality_score']        ?? null,
                        'quality_source'                 => $result['quality_source']                 ?? null,
                        'warnings'                       => $result['synthetic_quality_warnings']     ?? null,
                        'reason_codes'                   => $result['reason_codes']                   ?? null,
                    ];
                }

                // final_low_quality rejected signal (quality_pass=false)
                if (($result['candidate_found'] ?? false)
                    && ($result['quality_pass'] ?? null) === false
                    && count($finalLowQualityRejectExamples) < 5
                ) {
                    $finalLowQualityRejectExamples[] = [
                        'symbol'                         => $symbol,
                        'signal_id'                      => $result['signal_id']                      ?? null,
                        'setup_class'                    => $result['setup_class']                    ?? null,
                        '_setup_signal_allowed'          => $result['setup_signal_allowed']           ?? null,
                        'candidate_quality_score'        => $result['candidate_quality_score']        ?? null,
                        'legacy_candidate_quality_score' => $result['candidate_quality_score']        ?? null,
                        'synthetic_quality_score'        => $result['synthetic_quality_score']        ?? null,
                        'setup_class_score'              => $result['setup_class_score']              ?? null,
                        'intraday_double_bottom_score'   => $result['intraday_double_bottom_score']   ?? null,
                        'neckline_score'                 => $result['neckline_score']                 ?? null,
                        'confirmation_score'             => $result['confirmation_score']             ?? null,
                        'structure_score'                => $result['structure_score']                ?? null,
                        'context_score'                  => $result['context_score']                  ?? null,
                        'entry_distance_from_neckline_pct' => $result['entry_distance_from_neckline_pct'] ?? null,
                        'final_decision'                 => 'rejected',
                        'final_reason'                   => $result['quality_reject_reason']         ?? $rejectR,
                    ];
                }

                // setup_allowed but quality failed
                if ($setupAllowedEx && $killStageEx === 'quality' && count($setupAllowedQualityFailedExamples) < 5) {
                    $setupAllowedQualityFailedExamples[] = [
                        'symbol'                         => $symbol,
                        'setup_class'                    => $result['setup_class']                    ?? null,
                        'kill_stage'                     => $killStageEx,
                        'kill_reason'                    => $killReasonEx,
                        'candidate_quality_score'        => $result['candidate_quality_score']        ?? null,
                        'synthetic_quality_score'        => $result['synthetic_quality_score']        ?? null,
                        'setup_class_score'              => $result['setup_class_score']              ?? null,
                        'intraday_double_bottom_score'   => $result['intraday_double_bottom_score']   ?? null,
                        'entry_distance_from_neckline_pct' => $result['entry_distance_from_neckline_pct'] ?? null,
                        'quality_source'                 => $result['quality_source']                 ?? null,
                        'quality_reject_reason'          => $result['quality_reject_reason']          ?? null,
                    ];
                }

                // setup_allowed but pending (control check waiting)
                if ($setupAllowedEx && in_array($pendingStatusEx, ['added', 'added_better_entry'], true) && count($setupAllowedPendingExamples) < 5) {
                    $setupAllowedPendingExamples[] = [
                        'symbol'                         => $symbol,
                        'setup_class'                    => $result['setup_class']                    ?? null,
                        'pending_confirmation_status'    => $pendingStatusEx,
                        'pending_confirmation_reason'    => $result['pending_confirmation_reason']    ?? null,
                        'pending_expires_at'             => $result['pending_confirmation_expires_at'] ?? null,
                        'entry_distance_from_neckline_pct' => $result['entry_distance_from_neckline_pct'] ?? null,
                        'synthetic_quality_score'        => $result['synthetic_quality_score']        ?? null,
                        'candidate_quality_score'        => $result['candidate_quality_score']        ?? null,
                    ];
                }

                // late_good_setup
                if ($fssForExamples === 'late_good_setup' && count($lateGoodSetupExamples) < 10) {
                    $lateGoodSetupExamples[] = [
                        'symbol'                         => $symbol,
                        'setup_class'                    => $result['setup_class']                    ?? null,
                        'entry_distance_from_neckline_pct' => $result['entry_distance_from_neckline_pct'] ?? null,
                        'entry_distance_from_reclaim_pct'  => $result['entry_distance_from_reclaim_pct']  ?? null,
                        'synthetic_quality_score'        => $result['synthetic_quality_score']        ?? null,
                        'setup_class_score'              => $result['setup_class_score']              ?? null,
                        'intraday_double_bottom_score'   => $result['intraday_double_bottom_score']   ?? null,
                        'candidate_quality_score'        => $result['candidate_quality_score']        ?? null,
                        'quality_source'                 => $result['quality_source']                 ?? null,
                        'pending_confirmation_status'    => $pendingStatusEx,
                        'late_good_setup'                => true,
                        'missed_ideal_entry'             => $result['missed_ideal_entry']             ?? false,
                        'waiting_for_better_entry_distance' => $result['waiting_for_better_entry_distance'] ?? false,
                    ];
                }

                $fss        = $result['final_signal_status'] ?? '';
                $finalStage = match (true) {
                    $fss === 'emitted'                              => 'signal',
                    $fss === 'confirm_pending'                      => 'confirm',
                    ($result['candidate_found'] ?? false)           => 'quality',
                    ($result['double_bottom_checked'] ?? false)     => 'pattern',
                    (($result['wave_state'] ?? 'unknown') !== 'unknown')
                        && str_starts_with($rejectR ?? '', 'wave_')     => 'wave',
                    ($result['current_bucket'] ?? 0) > 0
                        && str_starts_with($rejectR ?? '', 'bucket_')   => 'bucket',
                    str_starts_with($rejectR ?? '', 'trend_')            => 'trend',
                    default                                              => 'pre_trend',
                };

                $trendDir    = $result['trend_direction'] ?? 'unknown';
                $sideAllowed = in_array($trendDir, ['bullish', 'bearish', 'flat'], true);

                $batchPreviewRows[] = [
                    'symbol'                => $symbol,
                    'side'                  => 'long',
                    'primary_pattern'       => $result['primary_pattern']       ?? null,
                    'market_regime'         => $result['market_regime']         ?? null,
                    'trend_direction'       => $result['trend_direction']       ?? null,
                    'side_allowed'          => $sideAllowed,
                    'trend_pass'            => in_array($result['trend_direction'] ?? '', ['bullish', 'bearish', 'flat'], true),
                    'corridor_low'          => $result['corridor_low']          ?? null,
                    'corridor_high'         => $result['corridor_high']         ?? null,
                    'corridor_bucket'       => $result['current_bucket']        ?? null,
                    'bucket_allowed_long'   => $result['bucket_allowed_long']   ?? false,
                    'bucket_allowed'        => $result['bucket_allowed_long']   ?? false,
                    'wave_direction'        => $result['wave_direction']        ?? null,
                    'wave_state'            => $result['wave_state']            ?? null,
                    'double_bottom_checked' => $result['double_bottom_checked'] ?? false,
                    'double_bottom_found'   => ($result['candidate_found'] ?? false) && ($result['primary_pattern'] ?? '') === 'double_bottom',
                    'neckline_value'        => $result['neckline_value']        ?? null,
                    'low1_value'            => $result['low1_value']            ?? null,
                    'low2_value'            => $result['low2_value']            ?? null,
                    'pattern_window_size'   => $result['pattern_window_size']   ?? null,
                    'similarity_delta_pct'  => $result['similarity_delta_pct']  ?? null,
                    'pattern_reject_reason' => ($result['double_bottom_checked'] ?? false)
                                                ? ($rejectR ?? null) : null,
                    'candidate_found'         => $result['candidate_found']        ?? false,
                    'neckline_distance_status'=> $neckDistStatus,
                    'final_stage_reached'     => $finalStage,
                    'pattern_score'           => $result['pattern_score']           ?? null,
                    'structure_score'         => $result['structure_score']         ?? null,
                    'neckline_score'          => $result['neckline_score']          ?? null,
                    'confirmation_score'      => $result['confirmation_score']      ?? null,
                    'context_score'           => $result['context_score']           ?? null,
                    'candidate_quality_score' => $result['candidate_quality_score'] ?? null,
                    'quality_pass'            => $result['quality_pass']            ?? null,
                    'quality_reject_reason'   => $result['quality_reject_reason']   ?? null,
                    'control_check_checked'   => isset($result['confirm_status']) && $result['confirm_status'] !== null,
                    'control_check_status'    => $result['confirm_status']          ?? null,
                    'final_signal_status'     => $fss,
                    'reject_reason'           => $rejectR,
                    // Setup-allowed funnel diagnostics
                    'setup_class'              => $result['setup_class']              ?? null,
                    'setup_signal_allowed'     => $result['setup_signal_allowed']     ?? false,
                    'setup_allowed_kill_stage'  => $result['setup_allowed_kill_stage']  ?? null,
                    'setup_allowed_kill_reason' => $result['setup_allowed_kill_reason'] ?? null,
                    'intraday_double_bottom_detected' => $result['intraday_double_bottom_detected'] ?? false,
                    'neckline_reclaim_confirmed'      => $result['neckline_reclaim_confirmed']      ?? false,
                    'reclaim_after_flat_detected'     => $result['reclaim_after_flat_detected']     ?? false,
                    'entry_context_score'             => $result['entry_context_score']             ?? null,
                    'signal_id'               => $result['signal_id']              ?? null,
                    'winner_selected'         => null,
                    'winner_reject_reason'    => null,
                    'final_reject_reason'     => null,
                ];
            } catch (\Throwable $e) {
                $state['errors'][] = $symbol . ': ' . $e->getMessage();
            }

            // ── Write scan suppression after stable non-technical rejects ─────
            if ($suppressionEnabled && isset($result)) {
                $this->updateScanSuppression($symLower, $result, $config);
            }

            // ── bad_accept diagnostic: high-score signal with adverse price move ─
            if (isset($result)
                && count($badAcceptExamples) < 10
                && (bool)($config['bad_accept_diagnostic_enabled'] ?? true)
                && isset($activeHandoffBySymbol[$symLower])
            ) {
                $hqEntry    = $activeHandoffBySymbol[$symLower];
                $curPrice   = (float)($result['scan_suppression_last_price'] ?? 0.0);
                $entryPrice = (float)($hqEntry['entry_price'] ?? 0.0);
                $adversePct = (float)($config['bad_accept_adverse_pct_threshold'] ?? 3.0) / 100.0;
                $qualityMin = (float)($config['bad_accept_quality_score_threshold'] ?? 0.6);
                $qScore     = max(
                    (float)($hqEntry['candidate_quality_score'] ?? 0.0),
                    (float)($hqEntry['pattern_score'] ?? 0.0)
                );
                if ($curPrice > 0.0
                    && $entryPrice > 0.0
                    && $curPrice < $entryPrice * (1.0 - $adversePct)
                    && $qScore >= $qualityMin
                ) {
                    $movePct = round((($curPrice - $entryPrice) / $entryPrice) * 100.0, 2);
                    $badAcceptExamples[] = [
                        'symbol'                  => $symbol,
                        'signal_id'               => $hqEntry['signal_id'] ?? null,
                        'detected_at'             => $hqEntry['detected_at'] ?? null,
                        'entry_price'             => $entryPrice,
                        'current_price'           => $curPrice,
                        'adverse_move_pct'        => $movePct,
                        'candidate_quality_score' => $qScore,
                        'pattern_score'           => (float)($hqEntry['pattern_score'] ?? 0.0) ?: null,
                        'setup_class'             => $hqEntry['strategy_signal_context']['setup_class'] ?? null,
                        'daily_change_pct'        => $result['scan_suppression_daily_change_pct'] ?? null,
                        'reason'                  => 'bad_accept_adverse_move',
                    ];
                }
            }
        }

        [$signals, $filterStats, $signalOutcomeMap] =
            $this->applySignalFilters($signals, $newlyEmitted, $config);

        // Count how many signals emitted in this batch survived to become winners in this cycle.
        $cycleNewWinnerCount = 0;
        foreach ($newlyEmitted as $s) {
            $sid = (string)($s['signal_id'] ?? '');
            if ($sid !== '' && ($signalOutcomeMap[$sid]['winner'] ?? false)) {
                $cycleNewWinnerCount++;
            }
        }

        // Collect setup_allowed_final_gate_warning_examples from surviving signals (Task 6)
        foreach ($signals as $s) {
            if (count($setupAllowedFinalGateWarnExamples) >= 5) {
                break;
            }
            if ((bool)($s['final_trend_mismatch_warning'] ?? false)
                || (bool)($s['final_context_inconsistent_warning'] ?? false)
                || (bool)($s['final_stop_width_warning'] ?? false)
            ) {
                $setupAllowedFinalGateWarnExamples[] = [
                    'symbol'                             => $s['symbol']                         ?? null,
                    'signal_id'                          => $s['signal_id']                      ?? null,
                    'setup_class'                        => $s['setup_class']                    ?? null,
                    '_setup_signal_allowed'              => $s['_setup_signal_allowed']           ?? null,
                    'final_trend_mismatch_warning'       => $s['final_trend_mismatch_warning']   ?? false,
                    'final_context_inconsistent_warning' => $s['final_context_inconsistent_warning'] ?? false,
                    'final_stop_width_warning'           => $s['final_stop_width_warning']       ?? false,
                    'final_stop_width_warning_reason'    => $s['final_stop_width_warning_reason'] ?? null,
                    'synthetic_quality_score'            => $s['synthetic_quality_score']        ?? null,
                    'candidate_quality_score'            => $s['candidate_quality_score']        ?? null,
                    'entry_distance_from_neckline_pct'   => $s['entry_distance_from_neckline_pct'] ?? null,
                ];
            }
        }

        // Handoff trace counters are computed from the actual written handoff records
        // after updateBotHandoff() runs below — see post-handoff section.

        $stats      = $this->applyFilterStatsDelta($stats,      $filterStats);
        $cycleStats = $this->applyFilterStatsDelta($cycleStats, $filterStats);

        foreach ($batchPreviewRows as &$row) {
            $sigId = $row['signal_id'] ?? null;
            if ($sigId !== null && isset($signalOutcomeMap[$sigId])) {
                $row['winner_selected']      = $signalOutcomeMap[$sigId]['winner'];
                $row['winner_reject_reason'] = $signalOutcomeMap[$sigId]['reason'];
                $row['final_reject_reason']  = $signalOutcomeMap[$sigId]['winner']
                    ? null
                    : $signalOutcomeMap[$sigId]['reason'];
            }
        }
        unset($row);

        $foundCandidates   = $this->applySignalOutcomeToCandidates($foundCandidates, $signalOutcomeMap, $tickAt);
        $emittedCandidates = $this->applySignalOutcomeToCandidates($emittedCandidates, $signalOutcomeMap, $tickAt);

        $totalProcessed = (int)($state['processed'] ?? 0) + $processed;
        $totalFound     = (int)($state['found']     ?? 0) + $found;

        $state['cursor']    = $cursor;
        $state['processed'] = $totalProcessed;
        $state['found']     = $totalFound;

        $isDone = ($cursor >= $total);
        $continuousEnabled = (bool)($config['continuous_scan_enabled'] ?? true);

        if ($isDone) {
            $finishedAt = date('c');
            $completedCycleId = (int)($state['cycle_id'] ?? 0);  // id of the cycle that just finished

            $state['cycle_finished_at'] = $finishedAt;
            $state['cycle_id']          = $completedCycleId + 1;
            $state['cumulative_cycles_completed'] = (int)($state['cycle_id']);

            // Compact summary of the just-completed cycle (persisted for operator rollover verification).
            $lastCycleSummary = [
                'cycle_id'               => $completedCycleId,
                'started_at'             => $state['cycle_started_at'] ?? $state['started_at'] ?? null,
                'finished_at'            => $finishedAt,
                'symbols_total'          => $total,
                'symbols_scanned'        => $totalProcessed,
                'signals_emitted_total'  => (int)($cycleStats['signals_emitted_total'] ?? 0),
                'signals_active_final'   => count($signals),
                'candidates_found_total' => count($foundCandidates),
                'candidates_emitted_total' => count($emittedCandidates),
                'final_status'           => $continuousEnabled ? 'continuous' : 'done',
            ];
            $state['last_cycle_summary'] = $lastCycleSummary;
            // cycle_history.ndjson is written after updateBotHandoff() and lifecycle update
            // so that handoff/pattern-status/freshness/garbage counters are all available.

            if ($continuousEnabled && $total > 0) {
                // Cycle window complete with continuous scan enabled.
                // Set done + next_cycle_ready=true so the isDoneRetryable check on the
                // next cron tick calls queueRun(), which advances the registry cursor to
                // the next window of symbols (registry rotation).
                //
                // Back-reference: the isDoneRetryable condition (above in tickBatch startup)
                // triggers on (status==='done' && continuous_scan_enabled===true), which is
                // exactly what we set here.  The two blocks must stay in sync.
                $state['status']           = 'done';
                $state['completed_at']     = date('c');
                $state['next_cycle_ready'] = true;
                $foundCandidates           = [];
                $emittedCandidates         = [];
                // Mark that cycle_stats.json should be reset at the start of the next cycle.
                // Cumulative stats.json is never wiped.
                $state['reset_stats_on_next_cycle'] = true;
            } else {
                $state['status']           = 'done';
                $state['completed_at']     = date('c');
                $state['next_cycle_ready'] = $continuousEnabled;
            }
        }

        // Cron diagnostics — persisted so the operator can verify cron is driving the module
        $state['cron_enabled']            = (bool)($config['enabled'] ?? false);
        $state['tick_source']             = 'centralized_cron';
        $state['last_tick_at']            = $tickAt;
        $state['last_tick_result']        = sprintf(
            'ok: cycle=%d processed=%d/%d remaining=%d',
            (int)($state['cycle_id'] ?? 0),
            $totalProcessed,
            $total,
            max(0, $total - $cursor)
        );
        $state['run_status']              = $state['status'];
        $state['processed_symbols']       = $totalProcessed;
        $state['total_symbols']           = $total;
        // remaining_symbols = how many in the current window are still unprocessed.
        // Always 0 when processed >= total (regardless of continuous mode).
        $state['remaining_symbols']       = max(0, $total - $cursor);
        // registry_symbols_remaining_until_wrap = how many symbols remain in the full
        // registry before the rotation wraps back to position 0.
        $registryTotal  = (int)($state['registry_total'] ?? 0);
        $nextCursorVal  = (int)($state['next_registry_cursor'] ?? 0);
        $state['registry_symbols_remaining_until_wrap'] = $registryTotal > 0
            ? max(0, $registryTotal - $nextCursorVal)
            : 0;
        $state['continuous_scan_enabled'] = $continuousEnabled;
        $state['signals_json_semantics']  = 'active_rolling_pool_across_cycles_ttl';
        $state['signals_active_final_total'] = count($signals);
        $state['final_signals_total']        = count($signals);
        $state['signals_emitted_total']      = (int)($stats['signals_emitted_total'] ?? 0);

        // Runtime diagnostics: separate current-cycle from cumulative
        $state['current_cycle_id']                    = (int)($state['cycle_id']      ?? 0);
        $state['current_cycle_started_at']            = $state['cycle_started_at']   ?? null;
        $state['current_cycle_processed_symbols']     = $totalProcessed;
        $state['current_cycle_signals_emitted_total'] = (int)($cycleStats['signals_emitted_total'] ?? 0);
        $state['current_cycle_final_signals_total']   = $cycleNewWinnerCount;
        $state['current_cycle_signals_active_final']  = count($signals);
        // active_pool_signals_total = total signals in rolling pool (active_final + stale).
        // Use final_signals_active_final_total for the non-stale subset.
        $state['active_pool_signals_total']           = count($signals);
        $state['cumulative_signals_emitted_total']    = (int)($stats['signals_emitted_total']       ?? 0);
        $state['cumulative_signals_active_final']     = count($signals);
        $state['cumulative_cycles_completed']         = (int)($state['cumulative_cycles_completed'] ?? 0);
        // Bot handoff counters (populated after updateBotHandoff() runs at end of tick)
        $state['bot_handoff_ready_total']     = 0;
        $state['bot_handoff_new_total']       = 0;
        $state['bot_handoff_refreshed_total'] = 0;
        $state['bot_handoff_expired_total']   = 0;

        $state['preview_rows'] = array_slice(
            array_merge((array)($state['preview_rows'] ?? []), $batchPreviewRows),
            -200
        );

        $this->writeJson('storage/signals.json',   array_values($signals));
        $this->writeJson('storage/candidates_found.json',   array_values($foundCandidates));
        $this->writeJson('storage/candidates_emitted.json', array_values($emittedCandidates));

        // ── Persist scan suppression cache ────────────────────────────────────
        if ($suppressionEnabled) {
            $suppRelFile = (string)($config['scan_suppression_storage_file'] ?? 'storage/scan_suppression.json');
            $this->writeJson($suppRelFile, array_values($this->scanSuppressionCache));
        }

        // Refresh bot handoff queue with the current active-pool winner signals.
        $handoffStats = $this->updateBotHandoff($signals, $config, $tickAt);

        // Update state with real handoff counters before writing run_state.json.
        $state['bot_handoff_ready_total']     = $handoffStats['ready_total'];
        $state['bot_handoff_new_total']       = $handoffStats['new_total'];
        $state['bot_handoff_refreshed_total'] = $handoffStats['refreshed_total'];
        $state['bot_handoff_expired_total']   = $handoffStats['expired_total'];

        // ── Handoff trace counters from actual written queue records ─────────
        // Check trace completeness from the real handoff entries (new/refreshed),
        // accepting either top-level fields OR nested strategy_signal_context.
        // Required for "with trace": signal_id, symbol, side, strategy_id/owner_strategy,
        // setup_class (either location), quality_source (either location),
        // at least one quality score.
        // is_current_run=false marks these as handoff-check examples, not emission-loop examples.
        foreach ($handoffStats['active_records'] as $hr) {
            $ctx      = is_array($hr['strategy_signal_context'] ?? null) ? $hr['strategy_signal_context'] : [];
            $setupCls = $hr['setup_class'] ?? $ctx['setup_class'] ?? null;
            $qualSrc  = $hr['quality_source'] ?? $ctx['quality_source'] ?? null;
            $anyScore = ($hr['candidate_quality_score']  ?? $ctx['candidate_quality_score']  ?? null) !== null
                     || ($hr['synthetic_quality_score']  ?? $ctx['synthetic_quality_score']  ?? null) !== null
                     || ($hr['setup_class_score']        ?? $ctx['setup_class_score']        ?? null) !== null;
            $hasId    = (string)($hr['signal_id'] ?? '') !== '';
            $hasSym   = (string)($hr['symbol']    ?? '') !== '';
            $hasSide  = (string)($hr['side']      ?? '') !== '';
            $hasStrat = (string)($hr['strategy_id'] ?? $hr['owner_strategy'] ?? '') !== '';
            $missingF = [];
            if (!$hasId)    $missingF[] = 'signal_id';
            if (!$hasSym)   $missingF[] = 'symbol';
            if (!$hasSide)  $missingF[] = 'side';
            if (!$hasStrat) $missingF[] = 'strategy_id';
            if ($setupCls === null) $missingF[] = 'setup_class';
            if ($qualSrc  === null) $missingF[] = 'quality_source';
            if (!$anyScore)         $missingF[] = 'quality_score(any)';
            if (empty($missingF)) {
                $handoffEntriesWithTraceTotal++;
            } else {
                $handoffEntriesMissingTraceTotal++;
                if (count($missingTraceExamples) < 5) {
                    $missingTraceExamples[] = [
                        'symbol'         => $hr['symbol']    ?? null,
                        'signal_id'      => $hr['signal_id'] ?? null,
                        'missing_fields' => $missingF,
                        'setup_class'    => $setupCls,
                        'quality_source' => $qualSrc,
                        'is_current_run' => false,
                        'reason'         => 'missing_in_handoff_record',
                    ];
                }
            }
        }

        $this->writeJson('storage/run_state.json', $state);

        // ── Task 1: Align signals.json lifecycle with queue normalization ────────
        // After the queue normalization pass, any signal whose handoff entry is
        // non-executable must be marked stale in signals.json so it no longer
        // appears as active_final=true.  Diagnostic fields are preserved.
        $blockedSigIds          = $handoffStats['blocked_signal_ids'] ?? [];
        $signalSscPatchMap      = $handoffStats['signal_ssc_patch_map'] ?? [];
        $sigMarkedStale         = 0;
        $sigActiveNow           = 0;
        $sigStaleCurrent        = 0;
        $sigNonExec             = 0;
        $sigMarkedStaleExamples = [];
        $nowTsSignal            = time();

        foreach ($signals as &$sig) {
            $sid = (string)($sig['signal_id'] ?? '');
            if ($sid === '') {
                continue;
            }
            $prevActiveF = $sig['active_final'] ?? null;
            $prevStale   = (bool)($sig['stale'] ?? false);

            if (isset($blockedSigIds[$sid])) {
                $blockReason = $blockedSigIds[$sid];
                $isAgeIssue  = in_array($blockReason, [
                    'handoff_blocked_stale_signal',
                    'handoff_blocked_not_current_run',
                    'handoff_blocked_expired_signal',
                ], true);
                $wasAlreadyMarked = ($sig['active_final'] ?? null) === false
                    && ($sig['stale_reason'] ?? '') === $blockReason;
                if (!$wasAlreadyMarked) {
                    $sig['active_final']             = false;
                    if ($isAgeIssue) {
                        $sig['stale'] = true;
                    }
                    $sig['stale_reason']             = $blockReason;
                    $sig['block_reason']             = $blockReason;
                    $sig['handoff_ready']            = false;
                    $sig['executable']               = false;
                    $sig['last_lifecycle_update_at'] = date('c');
                    $sig['last_lifecycle_reason']    = $blockReason;
                    // Propagate block_reason into strategy_signal_context for OBC soft_demote
                    // and garbage veto so all downstream consumers identify the gate that blocked handoff.
                    $isGarbageVeto = str_starts_with($blockReason, 'garbage_');
                    if ($blockReason === 'ob_soft_demote_ask_wall_risk' || $isGarbageVeto) {
                        $existingSscBlk = is_array($sig['strategy_signal_context'] ?? null)
                            ? $sig['strategy_signal_context'] : [];
                        $sscUpdate = [
                            'block_reason'    => $blockReason,
                            'handoff_ready'   => false,
                            'executable'      => false,
                            'active_final'    => false,
                        ];
                        if ($isGarbageVeto) {
                            $sscUpdate['garbage_veto_triggered'] = true;
                            $sscUpdate['garbage_veto_reason']    = $blockReason;
                        }
                        $sig['strategy_signal_context'] = array_merge($existingSscBlk, $sscUpdate);
                    }
                    $sigMarkedStale++;
                    if (count($sigMarkedStaleExamples) < 5) {
                        $detTs = isset($sig['detected_at']) ? strtotime($sig['detected_at']) : 0;
                        $sigMarkedStaleExamples[] = [
                            'symbol'                => $sig['symbol']  ?? null,
                            'signal_id'             => $sid,
                            'previous_active_final' => $prevActiveF,
                            'new_active_final'      => false,
                            'stale_reason'          => $blockReason,
                            'detected_at'           => $sig['detected_at'] ?? null,
                            'age_minutes'           => ($detTs > 0)
                                ? round(($nowTsSignal - $detTs) / 60, 1)
                                : null,
                        ];
                    }
                }
                $sigNonExec++;
                $sigStaleCurrent++;
            } else {
                // Signal is NOT blocked — explicitly stamp active_final=true so signals.json
                // consumers do not have to fall back to status=active heuristics.
                if (!$prevStale && ($sig['active_final'] ?? null) !== false) {
                    if (($sig['active_final'] ?? null) !== true) {
                        $sig['active_final']             = true;
                        $sig['stale']                    = false;
                        $sig['handoff_ready']            = true;
                        $sig['executable']               = true;
                        if (!isset($sig['active_final_at'])) {
                            $sig['active_final_at']      = date('c');
                        }
                        $sig['last_lifecycle_update_at'] = date('c');
                        $sig['last_lifecycle_reason']    = 'active_confirmed';
                    }
                    $sigActiveNow++;
                } else {
                    $sigStaleCurrent++;
                }
            }
            // Propagate pattern-status and freshness diagnostics from handoff queue into signals.json SSC.
            // This ensures signals.json consumers see the same dbl_pattern_status, confirmation fields,
            // and freshness fields that are in bot_handoff_queue.strategy_signal_context.
            if (isset($signalSscPatchMap[$sid])) {
                $existingSscSignal = is_array($sig['strategy_signal_context'] ?? null) ? $sig['strategy_signal_context'] : [];
                $sig['strategy_signal_context'] = array_merge($existingSscSignal, $signalSscPatchMap[$sid]);
            }
        }
        unset($sig);

        // Compute final signal lifecycle totals (Task 2 counters)
        $sigActiveTotal    = $sigActiveNow;
        $sigStaleTotal     = $sigStaleCurrent;
        $sigNonExecTotal   = $sigNonExec;
        $sigHistoricalTotal = count(array_filter($signals, fn($s) => ($s['active_final'] ?? null) === false));

        // Re-write signals.json with updated lifecycle flags
        $this->writeJson('storage/signals.json', array_values($signals));
        $cycleStats = $this->finalizeStats($cycleStats, $total, $totalProcessed, $batchSz, count($signals));
        $this->writeJson('storage/stats.json',       $stats);
        $this->writeJson('storage/cycle_stats.json', $cycleStats);

        // ── Deferred cycle_history.ndjson write ──────────────────────────────────
        // Written here (after updateBotHandoff + signals lifecycle update + finalizeStats)
        // so that handoff, pattern-status, garbage-veto, and freshness counters are all
        // available for aggregation over time.
        if ($isDone && isset($lastCycleSummary)) {
            $cycleHistoryRecord = json_encode(array_merge($lastCycleSummary, [
                // Original distribution maps
                'reject_reason_distribution'       => $cycleStats['reject_reason_distribution'] ?? (object)[],
                'final_reject_reason_distribution' => $cycleStats['final_reject_reason_distribution'] ?? (object)[],
                // Signal counts (post-lifecycle-update)
                'current_cycle_signals_emitted_total' => (int)($cycleStats['signals_emitted_total'] ?? 0),
                'final_signals_total'                 => count($signals),
                'final_signals_active_final_total'    => $sigActiveTotal,
                // Handoff counters
                'bot_handoff_ready_total'             => $handoffStats['ready_total'],
                'handoff_queue_ready_written_total'   => $handoffStats['handoff_queue_ready_written_total'] ?? 0,
                // Pattern-status state machine counters
                'dbl_pattern_status_checked_total'          => $this->dblPatternStatusCheckedTotal,
                'dbl_pattern_active_total'                  => $this->dblPatternActiveTotal,
                'dbl_pattern_confirmed_total'               => $this->dblPatternConfirmedTotal,
                'dbl_pattern_invalid_total'                 => $this->dblPatternInvalidTotal,
                'dbl_pattern_pending_total'                 => $this->dblPatternPendingTotal,
                'dbl_pattern_pending_sweep_total'           => $this->dblPatternPendingSweepTotal,
                'dbl_pattern_pending_sweep_confirmed_total' => $this->dblPatternPendingSweepConfirmedTotal,
                'dbl_pattern_pending_sweep_invalid_total'   => $this->dblPatternPendingSweepInvalidTotal,
                'dbl_pattern_pending_sweep_expired_total'   => $this->dblPatternPendingSweepExpiredTotal,
                // Garbage veto counters
                'dbl_garbage_veto_checked_total'  => $this->dblGarbageVetoCheckedTotal,
                'dbl_garbage_veto_blocked_total'  => $this->dblGarbageVetoBlockedTotal,
                'dbl_garbage_passed_total'        => $this->dblGarbagePassedTotal,
                // Current-run freshness counters
                'current_run_freshness_checked_total' => $handoffStats['current_run_freshness_checked_total'] ?? 0,
                'current_run_freshness_passed_total'  => $handoffStats['current_run_freshness_passed_total'] ?? 0,
                'current_run_freshness_blocked_total' => $handoffStats['current_run_freshness_blocked_total'] ?? 0,
                // Confirmed-pattern freshness counters
                'confirmed_pattern_freshness_checked_total'            => $this->confirmedPatternFreshnessCheckedTotal,
                'confirmed_pattern_valid_for_handoff_total'            => $this->confirmedPatternValidForHandoffTotal,
                'confirmed_pattern_expired_total'                      => $this->confirmedPatternExpiredTotal,
                'confirmed_pattern_invalidated_total'                  => $this->confirmedPatternInvalidatedTotal,
                'confirmed_pattern_blocked_by_generic_freshness_total' => $this->confirmedPatternBlockedByGenericFreshnessTotal,
                // Lifecycle consistency counters
                'lifecycle_consistency_checked_total'  => $this->lifecycleConsistencyCheckedTotal,
                'lifecycle_inconsistent_fixed_total'   => $this->lifecycleInconsistentFixedTotal,
            ])) . "\n";
            @file_put_contents(
                $this->moduleDir . '/storage/cycle_history.ndjson',
                $cycleHistoryRecord,
                FILE_APPEND | LOCK_EX
            );
        }

        $regimeSummary = [
            'current_regime'   => $prevRegimeData['regime']           ?? 'unknown',
            'previous_regime'  => $prevRegimeData['previous_regime']  ?? 'unknown',
            'regime_changed'   => $prevRegimeData['regime_changed']   ?? false,
            'regime_reason'    => $prevRegimeData['regime_reason']    ?? 'unknown',
            'bull_count'       => $prevRegimeData['bull_count']       ?? 0,
            'bear_count'       => $prevRegimeData['bear_count']       ?? 0,
            'flat_count'       => $prevRegimeData['flat_count']       ?? 0,
            'unknown_count'    => $prevRegimeData['unknown_count']    ?? 0,
            'total_count_used' => $prevRegimeData['total_count_used'] ?? 0,
            'bull_ratio'       => $prevRegimeData['bull_ratio']       ?? 0.0,
            'bear_ratio'       => $prevRegimeData['bear_ratio']       ?? 0.0,
            'flat_ratio'       => $prevRegimeData['flat_ratio']       ?? 0.0,
        ];

        // ── Closed-trade calibration diagnostics ────────────────────────────────
        // Read bot closed_trades.json and compute score/warning bucket statistics
        // for double_bottom_long trades that have a strategy_signal_context trace.
        // Diagnostics only — no signal behavior is changed.
        // Wrapped in try-catch so a calibration failure cannot block hourly stats.
        try {
            $calibration = $this->computeCalibration($config);
        } catch (\Throwable) {
            $calibration = [
                'calibration_closed_trades_total'            => 0,
                'calibration_closed_trades_with_trace_total' => 0,
                'calibration_winning_trades_total'           => 0,
                'calibration_losing_trades_total'            => 0,
                'calibration_deep_loss_total'                => 0,
                'calibration_entry_distance_buckets'         => [],
                'calibration_synthetic_quality_buckets'      => [],
                'calibration_setup_class_score_buckets'      => [],
                'calibration_candidate_quality_buckets'      => [],
                'calibration_warning_combo_stats'            => [],
                'calibration_profitable_examples'            => [],
                'calibration_losing_examples'                => [],
                'calibration_deep_loss_examples'             => [],
                'calibration_bad_signature_examples'         => [],
                'calibration_candidate_rules'                => [],
            ];
        }

        // ── Hourly performance statistics ────────────────────────────────────
        // Group double_bottom_long closed trades by open hour (UTC) to surface
        // per-hour win-rate / avg-roi. Diagnostics only.
        // Wrapped in try-catch; errors are recorded as hourly_stats_error fields.
        try {
            $hourlyStats = $this->computeHourlyStats($config);
        } catch (\Throwable $e) {
            $hourlyStats = [
                'hourly_stats_enabled'              => false,
                'hourly_stats_generated_at'         => null,
                'hourly_stats_total_trades'         => 0,
                'hourly_stats_bad_hour_candidates'  => 0,
                'hourly_stats_good_hour_candidates' => 0,
                'hourly_stats_bad_block_candidates' => 0,
                'hourly_stats_good_block_candidates'=> 0,
                'hourly_stats_file'                 => null,
                'hourly_stats_error'                => true,
                'hourly_stats_error_reason'         => $e->getMessage(),
            ];
        }

        // ── Throughput health diagnostics ────────────────────────────────────
        // Estimate orders-per-hour and handoff-per-hour by reading cycle_history.
        // This is diagnostics only: no thresholds are auto-loosened.
        $dblRuntimeHoursEstimated        = null;
        $dblOrdersPerHourEstimated       = null;
        $dblHandoffReadyPerHourEstimated = null;
        $dblExpectedMinHandoffPer6h      = (int)($config['dbl_expected_min_handoff_per_6h'] ?? 3);
        $dblThroughputTooLow             = false;
        try {
            $cycleHistFile = $this->moduleDir . '/storage/cycle_history.ndjson';
            if (is_file($cycleHistFile)) {
                $lines = @file($cycleHistFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (is_array($lines) && count($lines) > 0) {
                    // Use last 6 hours of records
                    $nowTsForThroughput = time();
                    $windowSec = 6 * 3600;
                    $handoffReadySumWindow = 0;
                    $ordersCreatedSumWindow = 0;
                    $firstTsInWindow = null;
                    $lastTsInWindow  = null;
                    foreach ($lines as $line) {
                        $rec = @json_decode($line, true);
                        if (!is_array($rec)) continue;
                        $recTs = isset($rec['finished_at']) ? @strtotime((string)$rec['finished_at']) : false;
                        if ($recTs === false || $recTs <= 0) continue;
                        if (($nowTsForThroughput - $recTs) > $windowSec) continue;
                        $handoffReadySumWindow += (int)($rec['bot_handoff_ready_total'] ?? 0);
                        $ordersCreatedSumWindow += (int)($rec['orders_created'] ?? 0);
                        if ($firstTsInWindow === null || $recTs < $firstTsInWindow) $firstTsInWindow = $recTs;
                        if ($lastTsInWindow  === null || $recTs > $lastTsInWindow)  $lastTsInWindow  = $recTs;
                    }
                    if ($firstTsInWindow !== null && $lastTsInWindow !== null && $lastTsInWindow > $firstTsInWindow) {
                        $spanHours = ($lastTsInWindow - $firstTsInWindow) / 3600.0;
                        if ($spanHours >= 0.1) {
                            $dblRuntimeHoursEstimated        = round($spanHours, 2);
                            $dblHandoffReadyPerHourEstimated = round($handoffReadySumWindow / $spanHours, 2);
                            $dblOrdersPerHourEstimated       = round($ordersCreatedSumWindow / $spanHours, 2);
                            // Flag throughput too low if we have enough runtime data
                            if ($spanHours >= 1.0) {
                                $expectedPer6h = $dblExpectedMinHandoffPer6h;
                                $actualPer6h   = $dblHandoffReadyPerHourEstimated * 6.0;
                                $dblThroughputTooLow = $actualPer6h < $expectedPer6h;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Throughput diagnostics are best-effort; never block the tick.
        }

        $this->writeJson('storage/last_run.json', [
            // done_retryable = empty universe (registry not yet populated, will retry)
            // done           = normal cycle completion (continuous or not)
            // running        = mid-cycle batch tick
            'status'            => match(true) {
                $isDone && $total === 0 => 'done_retryable',
                $isDone                 => 'done',
                default                 => 'running',
            },
            'started_at'        => $state['started_at']      ?? null,
            'updated_at'        => date('c'),
            'finished_at'       => $isDone ? $finishedAt : null,
            // current_cycle_id is the cycle actively running right now (post-increment if just rolled over)
            'current_cycle_id'          => (int)($state['cycle_id'] ?? 0),
            // last_completed_cycle_id is the cycle that just finished in this tick (null while still in progress)
            'last_completed_cycle_id'   => $completedCycleId,
            'cycle_id'          => (int)($state['cycle_id'] ?? 0),
            'cycle_started_at'  => $state['cycle_started_at'] ?? null,
            'cycle_finished_at' => $finishedAt,
            'cumulative_cycles_completed' => (int)($state['cumulative_cycles_completed'] ?? 0),
            'continuous_scan'   => $continuousEnabled,
            'tick_source'       => 'centralized_cron',
            'total'             => $total,
            'processed'         => $totalProcessed,
            'found'             => $totalFound,
            'signals_semantics' => 'signals.json = active rolling pool across cycles with TTL expiry',
            'signals_emitted_total'      => (int)($stats['signals_emitted_total'] ?? 0),
            'signals_active_final_total' => $sigActiveTotal,
            // Explicit semantic separation: cycle-local vs active pool vs handoff
            'current_cycle_signals_emitted_total' => (int)($cycleStats['signals_emitted_total'] ?? 0),
            'current_cycle_final_signals_total'   => $cycleNewWinnerCount,
            'active_pool_signals_total'           => count($signals),
            'active_pool_note'                    => 'rolling pool may include stale/withdrawn signals; use final_signals_active_final_total for executable active signals',
            // Task 2: Signal lifecycle counters
            'signals_marked_stale_total'      => $sigMarkedStale,
            'signals_active_current_total'    => $sigActiveTotal,
            'signals_active_historical_total' => $sigHistoricalTotal,
            'signals_stale_total'             => $sigStaleTotal,
            'signals_non_executable_total'    => $sigNonExecTotal,
            'signals_marked_stale_examples'   => $sigMarkedStaleExamples,
            'bot_handoff_ready_total'     => $handoffStats['ready_total'],
            'bot_handoff_new_total'       => $handoffStats['new_total'],
            'bot_handoff_refreshed_total' => $handoffStats['refreshed_total'],
            'bot_handoff_expired_total'   => $handoffStats['expired_total'],
            'final_signals_total'                         => count($signals),
            'final_signals_produced_this_run_total'       => $cycleNewWinnerCount,
            'final_signals_active_final_total'            => $sigActiveTotal,
            'final_signals_stale_withdrawn_total'         => $sigStaleTotal,
            'final_signals_executable_handoff_ready_total' => $handoffStats['ready_total'],
            'last_cycle_summary' => $state['last_cycle_summary'] ?? null,
            'regime'       => $regimeSummary,
            'pipeline_summary' => [
                'symbols_total'            => $total,
                'symbols_scanned'          => $totalProcessed,
                'trend_pass_total'         => $cycleStats['trend_pass_total']          ?? 0,
                'corridor_pass_total'      => $cycleStats['corridor_pass_total']       ?? 0,
                'bucket_allowed_total'     => $cycleStats['bucket_allowed_total']      ?? 0,
                'wave_pass_total'          => $cycleStats['wave_pass_total']           ?? 0,
                'wave_rejected_total'      => $cycleStats['wave_rejected_total']       ?? 0,
                'double_bottom_checked_total' => $cycleStats['double_bottom_checked_total'] ?? 0,
                'double_bottom_found_total'   => $cycleStats['double_bottom_found_total']   ?? 0,
                'pattern_rejected_total'   => $cycleStats['pattern_rejected_total']    ?? 0,
                'control_check_pass_total' => $cycleStats['control_check_pass_total']  ?? 0,
                'control_check_expired'    => $cycleStats['control_check_expired_total'] ?? 0,
                'rejected_by_trend_side'         => $cycleStats['rejected_by_trend_side_total']        ?? 0,
                'rejected_by_bucket'             => $cycleStats['rejected_by_bucket_total']            ?? 0,
                'rejected_by_wave'               => $cycleStats['rejected_by_wave_total']              ?? 0,
                'rejected_by_pattern'            => $cycleStats['rejected_by_pattern_total']           ?? 0,
                'rejected_by_neckline_distance'  => $cycleStats['rejected_by_neckline_distance_total'] ?? 0,
                'candidate_waiting_confirm'  => $cycleStats['candidate_waiting_confirm_total']  ?? 0,
                'candidate_expired'          => $cycleStats['candidate_expired_total']          ?? 0,
                'candidate_confirm_failed'   => $cycleStats['candidate_confirm_failed_total']   ?? 0,
                'signals_emitted_total'      => $cycleStats['signals_emitted_total']         ?? 0,
                'signals_active_final_total' => $sigActiveTotal,
                'signals_before_winner_selection'   => $cycleStats['signals_before_winner_selection_total']   ?? 0,
                'signals_after_winner_selection'    => $cycleStats['signals_after_winner_selection_total']    ?? 0,
                'signals_rejected_missing_quality'  => $cycleStats['signals_rejected_missing_quality_total']  ?? 0,
                'signals_rejected_low_neckline'     => $cycleStats['signals_rejected_low_neckline_total']     ?? 0,
                'signals_rejected_loser_by_quality' => $cycleStats['signals_rejected_loser_by_quality_total'] ?? 0,
                'signals_before_final_eligibility'      => $cycleStats['signals_before_final_eligibility_total']    ?? 0,
                'signals_after_final_eligibility'       => $cycleStats['signals_after_final_eligibility_total']     ?? 0,
                'signals_rejected_final_trend'          => $cycleStats['signals_rejected_final_trend_total']        ?? 0,
                'signals_rejected_final_context'        => $cycleStats['signals_rejected_final_context_total']      ?? 0,
                'signals_rejected_final_quality'        => $cycleStats['signals_rejected_final_quality_total']      ?? 0,
                'signals_rejected_final_low_neckline'   => $cycleStats['signals_rejected_final_low_neckline_total'] ?? 0,
                'signals_rejected_final_low_quality'    => $cycleStats['signals_rejected_final_low_quality_total']  ?? 0,
                'signals_entered_final_eligibility'    => $cycleStats['signals_entered_final_eligibility_total']    ?? 0,
                'signals_rejected_during_finalization' => $cycleStats['signals_rejected_during_finalization_total'] ?? 0,
            ],
            'final_reject_reason_distribution' => $cycleStats['final_reject_reason_distribution'] ?? (object)[],
            'reject_reason_distribution' => $cycleStats['reject_reason_distribution'] ?? (object)[],
            'errors_count'               => count($state['errors'] ?? []),
            // Coin trend context pipeline counters
            'coin_trend_context_checked_total'   => (int)($cycleStats['coin_trend_context_checked_total']   ?? 0),
            'coin_trend_context_pass_total'      => (int)($cycleStats['coin_trend_context_pass_total']      ?? 0),
            'coin_trend_context_warning_total'   => (int)($cycleStats['coin_trend_context_warning_total']   ?? 0),
            'coin_trend_context_reject_total'    => (int)($cycleStats['coin_trend_context_reject_total']    ?? 0),
            'active_falling_knife_reject_total'  => (int)($cycleStats['active_falling_knife_reject_total']  ?? 0),
            'active_downtrend_reject_total'      => (int)($cycleStats['active_downtrend_reject_total']      ?? 0),
            'post_dump_detected_total'           => (int)($cycleStats['post_dump_detected_total']           ?? 0),
            'stabilization_detected_total'       => (int)($cycleStats['stabilization_detected_total']       ?? 0),
            'support_resistance_detected_total'  => (int)($cycleStats['support_resistance_detected_total']  ?? 0),
            'flat_base_detected_total'           => (int)($cycleStats['flat_base_detected_total']           ?? 0),
            'reclaim_after_flat_detected_total'  => (int)($cycleStats['reclaim_after_flat_detected_total']  ?? 0),
            'bearish_reversal_exception_used_total'    => (int)($cycleStats['bearish_reversal_exception_used_total']    ?? 0),
            'bearish_reversal_exception_blocked_total' => (int)($cycleStats['bearish_reversal_exception_blocked_total'] ?? 0),
            'double_bottom_context_pass_total'   => (int)($cycleStats['double_bottom_context_pass_total']   ?? 0),
            'double_bottom_context_reject_total' => (int)($cycleStats['double_bottom_context_reject_total'] ?? 0),
            // Registry diagnostics — required for dashboard to diagnose empty-universe retries
            'registry_source_path'      => $regDiag['registry_source_path']  ?? null,
            'registry_loaded'           => (bool)($regDiag['registry_loaded'] ?? false),
            'registry_symbol_count'     => (int)($state['registry_symbol_count'] ?? $regDiag['registry_symbol_count'] ?? 0),
            'registry_total'            => (int)($state['registry_total'] ?? 0),
            'registry_empty'            => (int)($state['registry_total'] ?? $regDiag['registry_symbol_count'] ?? 0) === 0,
            'queue_empty_universe'      => $total === 0,
            'next_retry_allowed'        => $total === 0 ? $continuousEnabled : false,
            'auto_requeued_from_status' => $state['auto_requeued_from_status'] ?? null,
            // Registry rotation cursor fields
            'registry_cursor'           => (int)($state['registry_cursor']          ?? 0),
            'previous_registry_cursor'  => (int)($state['previous_registry_cursor'] ?? 0),
            'next_registry_cursor'      => (int)($state['next_registry_cursor']      ?? 0),
            'registry_window_start'     => (int)($state['registry_window_start']     ?? 0),
            'registry_window_end'       => (int)($state['registry_window_end']       ?? 0),
            'registry_wrapped'          => (bool)($state['registry_wrapped']         ?? false),
            'full_registry_scan_round'  => (int)($state['full_registry_scan_round']  ?? 0),
            'selected_symbols_total'    => (int)($state['selected_symbols_total']    ?? 0),
            'max_symbols_per_run'       => (int)($config['max_symbols_per_run']      ?? 0),
            'batch_size'                => (int)($config['batch_size']               ?? 50),
            'registry_symbols_remaining_until_wrap' => (int)($state['registry_symbols_remaining_until_wrap'] ?? 0),
            // ── OrderBook wall entry gate counters (per tick) ────────────────────
            'orderbook_wall_checked_total'         => $this->obWallCheckedTotal,
            'orderbook_wall_fetch_success_total'   => $this->obWallFetchSuccessTotal,
            'orderbook_wall_fetch_failed_total'    => $this->obWallFetchFailedTotal,
            'orderbook_wall_ask_risk_total'        => $this->obWallAskRiskTotal,
            'orderbook_wall_bid_support_bonus_total' => $this->obWallBidSupportTotal,
            'orderbook_wall_ask_eaten_bonus_total' => $this->obWallAskEatenTotal,
            'orderbook_wall_soft_demote_total'     => $this->obWallSoftDemoteTotal,
            'orderbook_wall_soft_demote_blocks_handoff' => (bool)($config['orderbook_entry_wall_soft_demote_blocks_handoff'] ?? true),
            'orderbook_wall_soft_demote_blocked_handoff_total' => $this->obWallSoftDemoteBlockedHandoffTotal,
            'orderbook_wall_soft_demote_allowed_handoff_total' => $this->obWallSoftDemoteAllowedHandoffTotal,
            'orderbook_wall_soft_demote_block_examples'        => $this->obWallSoftDemoteBlockExamples,
            'orderbook_wall_pending_total'         => $this->obWallPendingTotal,
            'orderbook_wall_hard_reject_total'     => $this->obWallHardRejectTotal,
            'orderbook_wall_gate_enabled'          => (bool)($config['orderbook_entry_wall_gate_enabled'] ?? false),
            'orderbook_wall_gate_mode'             => (string)($config['orderbook_entry_wall_gate_mode']  ?? 'soft_demote'),
            // OBC skip diagnostic counters (gate enabled, fetch skipped for candidate-level reasons)
            'orderbook_wall_skip_quality_below_threshold_total' => $this->obWallSkipQualityBelowThresholdTotal,
            'orderbook_wall_skip_service_unavailable_total'     => $this->obWallSkipServiceUnavailableTotal,
            'orderbook_wall_skip_missing_entry_price_total'     => $this->obWallSkipMissingEntryPriceTotal,
            'orderbook_wall_skip_examples'                     => $this->obWallSkipExamples,
            // Entry-context fetch counters for this tick
            'entry_context_fetch_attempted_total'       => $this->ctxFetchAttemptedThisTick,
            'entry_context_fetch_success_total'         => $this->ctxFetchSuccessThisTick,
            'entry_context_fetch_failed_total'          => $this->ctxFetchFailedThisTick,
            'entry_context_fetch_skipped_prefilter_total' => $this->ctxFetchSkippedPrefilterThisTick,
            'entry_context_fetch_skipped_limit_total'   => $this->ctxFetchSkippedLimitThisTick,
            'entry_context_fetch_limit'                 => (int)($config['entry_context_max_symbols_per_tick'] ?? 50),
            // Entry-context prefilter cycle counters
            'entry_context_prefilter_checked_total' => (int)($cycleStats['entry_context_prefilter_checked_total'] ?? 0),
            'entry_context_prefilter_pass_total'    => (int)($cycleStats['entry_context_prefilter_pass_total']    ?? 0),
            'entry_context_prefilter_reject_total'  => (int)($cycleStats['entry_context_prefilter_reject_total']  ?? 0),
            // Specific reject reason counters
            'specific_reject_reason_used_total'              => (int)($cycleStats['specific_reject_reason_used_total']              ?? 0),
            'generic_bearish_reject_total'                   => (int)($cycleStats['generic_bearish_reject_total']                   ?? 0),
            'bearish_reject_with_specific_reason_total'      => (int)($cycleStats['bearish_reject_with_specific_reason_total']      ?? 0),
            'reject_active_falling_knife_total'              => (int)($cycleStats['reject_active_falling_knife_total']              ?? 0),
            'reject_entry_context_unavailable_total'         => (int)($cycleStats['reject_entry_context_unavailable_total']         ?? 0),
            'reject_skipped_entry_context_due_prefilter_total' => (int)($cycleStats['reject_skipped_entry_context_due_prefilter_total'] ?? 0),
            'reject_no_post_dump_detected_total'             => (int)($cycleStats['reject_no_post_dump_detected_total']             ?? 0),
            'reject_active_downtrend_no_stabilization_total' => (int)($cycleStats['reject_active_downtrend_no_stabilization_total'] ?? 0),
            'reject_recent_dump_still_unstable_total'        => (int)($cycleStats['reject_recent_dump_still_unstable_total']        ?? 0),
            'reject_no_flat_base_after_dump_total'           => (int)($cycleStats['reject_no_flat_base_after_dump_total']           ?? 0),
            'reject_flat_base_too_wide_total'                => (int)($cycleStats['reject_flat_base_too_wide_total']                ?? 0),
            'reject_base_support_broken_total'               => (int)($cycleStats['reject_base_support_broken_total']               ?? 0),
            'reject_reclaim_after_flat_not_confirmed_total'  => (int)($cycleStats['reject_reclaim_after_flat_not_confirmed_total']  ?? 0),
            'reject_entry_too_far_after_reclaim_total'       => (int)($cycleStats['reject_entry_too_far_after_reclaim_total']       ?? 0),
            'reject_no_post_dump_flat_reclaim_total'         => (int)($cycleStats['reject_no_post_dump_flat_reclaim_total']         ?? 0),
            'reject_no_intraday_double_bottom_total'         => (int)($cycleStats['reject_no_intraday_double_bottom_total']         ?? 0),
            'reject_neckline_reclaim_not_confirmed_total'       => (int)($cycleStats['reject_neckline_reclaim_not_confirmed_total']       ?? 0),
            'reject_entry_too_far_after_neckline_reclaim_total' => (int)($cycleStats['reject_entry_too_far_after_neckline_reclaim_total'] ?? 0),
            'reject_setup_class_not_signal_allowed_total'        => (int)($cycleStats['reject_setup_class_not_signal_allowed_total']       ?? 0),
            'reject_classic_double_bottom_not_confirmed_total' => (int)($cycleStats['reject_classic_double_bottom_not_confirmed_total'] ?? 0),
            // Intraday double-bottom detection counters
            'intraday_double_bottom_checked_total'           => (int)($cycleStats['intraday_double_bottom_checked_total']           ?? 0),
            'intraday_double_bottom_detected_total'          => (int)($cycleStats['intraday_double_bottom_detected_total']          ?? 0),
            'intraday_double_bottom_reclaim_confirmed_total' => (int)($cycleStats['intraday_double_bottom_reclaim_confirmed_total'] ?? 0),
            'intraday_double_bottom_reject_total'            => (int)($cycleStats['intraday_double_bottom_reject_total']            ?? 0),
            // Setup class counters
            'setup_class_checked_total'                      => (int)($cycleStats['setup_class_checked_total']                      ?? 0),
            'setup_class_classic_double_bottom_total'        => (int)($cycleStats['setup_class_classic_double_bottom_total']        ?? 0),
            'setup_class_post_dump_base_reclaim_total'       => (int)($cycleStats['setup_class_post_dump_base_reclaim_total']       ?? 0),
            'setup_class_diagnostic_recovery_total'          => (int)($cycleStats['setup_class_diagnostic_recovery_total']          ?? 0),
            'setup_class_signal_allowed_total'               => (int)($cycleStats['setup_class_signal_allowed_total']               ?? 0),
            'setup_class_signal_blocked_total'               => (int)($cycleStats['setup_class_signal_blocked_total']               ?? 0),
            // Setup-allowed funnel counters
            'setup_allowed_total'                               => (int)($cycleStats['setup_allowed_total']                               ?? 0),
            'setup_allowed_classic_pattern_checked_total'       => (int)($cycleStats['setup_allowed_classic_pattern_checked_total']       ?? 0),
            'setup_allowed_classic_pattern_pass_total'          => (int)($cycleStats['setup_allowed_classic_pattern_pass_total']          ?? 0),
            'setup_allowed_classic_pattern_failed_total'        => (int)($cycleStats['setup_allowed_classic_pattern_failed_total']        ?? 0),
            'setup_allowed_quality_checked_total'               => (int)($cycleStats['setup_allowed_quality_checked_total']               ?? 0),
            'setup_allowed_quality_pass_total'                  => (int)($cycleStats['setup_allowed_quality_pass_total']                  ?? 0),
            'setup_allowed_quality_failed_total'                => (int)($cycleStats['setup_allowed_quality_failed_total']                ?? 0),
            'setup_allowed_control_checked_total'               => (int)($cycleStats['setup_allowed_control_checked_total']               ?? 0),
            'setup_allowed_control_pass_total'                  => (int)($cycleStats['setup_allowed_control_pass_total']                  ?? 0),
            'setup_allowed_control_failed_total'                => (int)($cycleStats['setup_allowed_control_failed_total']                ?? 0),
            'setup_allowed_final_eligibility_checked_total'     => (int)($cycleStats['setup_allowed_final_eligibility_checked_total']     ?? 0),
            'setup_allowed_final_eligibility_pass_total'        => (int)($cycleStats['setup_allowed_final_eligibility_pass_total']        ?? 0),
            'setup_allowed_final_eligibility_failed_total'      => (int)($cycleStats['setup_allowed_final_eligibility_failed_total']      ?? 0),
            'setup_allowed_signal_emitted_total'                => (int)($cycleStats['setup_allowed_signal_emitted_total']                ?? 0),
            // Entry-setup-allowed / old-gate-bypass / synthetic candidate counters
            'entry_setup_allowed_total'                          => (int)($cycleStats['entry_setup_allowed_total']                          ?? 0),
            'entry_setup_blocked_by_safety_total'                => (int)($cycleStats['entry_setup_blocked_by_safety_total']                ?? 0),
            'entry_setup_old_h4_gates_bypassed_total'            => (int)($cycleStats['entry_setup_old_h4_gates_bypassed_total']            ?? 0),
            'entry_setup_old_h4_gates_warning_total'             => (int)($cycleStats['entry_setup_old_h4_gates_warning_total']             ?? 0),
            'synthetic_candidate_built_total'                    => (int)($cycleStats['synthetic_candidate_built_total']                    ?? 0),
            'synthetic_candidate_intraday_double_bottom_total'   => (int)($cycleStats['synthetic_candidate_intraday_double_bottom_total']   ?? 0),
            'synthetic_candidate_post_dump_base_reclaim_total'   => (int)($cycleStats['synthetic_candidate_post_dump_base_reclaim_total']   ?? 0),
            // Synthetic quality scorer counters
            'synthetic_quality_checked_total'                    => (int)($cycleStats['synthetic_quality_checked_total']                    ?? 0),
            'synthetic_quality_pass_total'                       => (int)($cycleStats['synthetic_quality_pass_total']                       ?? 0),
            'synthetic_quality_failed_total'                     => (int)($cycleStats['synthetic_quality_failed_total']                     ?? 0),
            'synthetic_quality_bypassed_old_h4_quality_total'    => (int)($cycleStats['synthetic_quality_bypassed_old_h4_quality_total']    ?? 0),
            'synthetic_quality_classic_intraday_db_pass_total'   => (int)($cycleStats['synthetic_quality_classic_intraday_db_pass_total']   ?? 0),
            'synthetic_quality_post_dump_base_reclaim_pass_total' => (int)($cycleStats['synthetic_quality_post_dump_base_reclaim_pass_total'] ?? 0),
            'reject_synthetic_candidate_quality_failed_total'    => (int)($cycleStats['reject_synthetic_candidate_quality_failed_total']    ?? 0),
            'reject_quality_weak_structure_total'                => (int)($cycleStats['reject_quality_weak_structure_total']                ?? 0),
            // A-class intraday DB specific counters
            'synthetic_quality_intraday_db_checked_total'        => (int)($cycleStats['synthetic_quality_intraday_db_checked_total']        ?? 0),
            'synthetic_quality_intraday_db_pass_total'           => (int)($cycleStats['synthetic_quality_intraday_db_pass_total']           ?? 0),
            'synthetic_quality_intraday_db_failed_total'         => (int)($cycleStats['synthetic_quality_intraday_db_failed_total']         ?? 0),
            'synthetic_quality_generic_entry_context_warning_total' => (int)($cycleStats['synthetic_quality_generic_entry_context_warning_total'] ?? 0),
            // Pending confirmation counters
            'pending_confirmation_loaded_total'      => $pendingConfirmationStats['loaded_total'],
            'pending_confirmation_rechecked_total'   => $pendingConfirmationStats['rechecked_total'],
            'pending_confirmation_added_total'       => $pendingConfirmationStats['added_total'],
            'pending_confirmation_confirmed_total'   => $pendingConfirmationStats['confirmed_total'],
            'pending_confirmation_invalidated_total' => $pendingConfirmationStats['invalidated_total'],
            'pending_confirmation_expired_total'     => $pendingConfirmationStats['expired_total'],
            'pending_confirmation_active_total'      => $pendingConfirmationStats['active_total'],
            'pending_confirmation_invalidated_fresh_dump_total'              => $pendingConfirmationStats['invalidated_fresh_dump_total'] ?? 0,
            'pending_confirmation_invalidated_reclaim_lost_total'            => $pendingConfirmationStats['invalidated_reclaim_lost_total'] ?? 0,
            'pending_confirmation_invalidated_falling_knife_total'           => $pendingConfirmationStats['invalidated_falling_knife_total'] ?? 0,
            'pending_confirmation_invalidated_entry_distance_worsened_total' => $pendingConfirmationStats['invalidated_entry_distance_worsened_total'] ?? 0,
            'pending_confirmation_invalidated_stale_setup_total'             => $pendingConfirmationStats['invalidated_stale_setup_total'] ?? 0,
            'pending_confirmation_confirmed_examples'   => $pendingConfirmationStats['confirmed_examples']   ?? [],
            'pending_confirmation_invalidated_examples' => $pendingConfirmationStats['invalidated_examples'] ?? [],
            // Adaptive stop-width gate counters
            'final_stop_width_warning_total'                    => (int)($cycleStats['final_stop_width_warning_total']                    ?? 0),
            'final_stop_width_hard_reject_total'                => (int)($cycleStats['final_stop_width_hard_reject_total']                ?? 0),
            'final_stop_width_bypassed_for_synthetic_total'     => (int)($cycleStats['final_stop_width_bypassed_for_synthetic_total']     ?? 0),
            'final_stop_missing_for_synthetic_total'            => (int)($cycleStats['final_stop_missing_for_synthetic_total']            ?? 0),
            // H4 final gate warning counters
            'setup_allowed_final_trend_warning_total'           => (int)($cycleStats['setup_allowed_final_trend_warning_total']           ?? 0),
            'setup_allowed_final_context_warning_total'         => (int)($cycleStats['setup_allowed_final_context_warning_total']         ?? 0),
            'setup_allowed_old_h4_final_gates_bypassed_total'   => (int)($cycleStats['setup_allowed_old_h4_final_gates_bypassed_total']   ?? 0),
            // Late good setup counters
            'late_good_setup_detected_total'                    => (int)($cycleStats['late_good_setup_detected_total']                    ?? 0),
            'late_good_setup_waiting_pullback_total'            => (int)($cycleStats['late_good_setup_waiting_pullback_total']            ?? 0),
            'pending_confirmation_added_better_entry_distance_total' => (int)($cycleStats['pending_confirmation_added_better_entry_distance_total'] ?? 0),
            // ── Signal trace continuity diagnostics (Task 4) ─────────────────────
            'emitted_signals_with_trace_total'    => $emittedSignalsWithTraceTotal,
            'emitted_signals_missing_trace_total' => $emittedSignalsMissingTraceTotal,
            'handoff_entries_with_trace_total'    => $handoffEntriesWithTraceTotal,
            'handoff_entries_missing_trace_total' => $handoffEntriesMissingTraceTotal,
            'missing_trace_examples'              => $missingTraceExamples,
            // ── Handoff lifecycle freshness diagnostics (Task 4 new) ─────────────
            'handoff_blocked_stale_signal_total'                        => $handoffStats['blocked_stale_total']              ?? 0,
            'handoff_blocked_not_current_run_total'                     => $handoffStats['blocked_not_current_run_total']    ?? 0,
            'handoff_blocked_needs_revalidation_after_symbol_block_total' => $handoffStats['blocked_needs_revalidation_total'] ?? 0,
            'handoff_revalidated_after_unblock_total'                   => $handoffStats['revalidated_after_unblock_total']  ?? 0,
            'handoff_removed_stale_queue_entries_total'                 => $handoffStats['removed_stale_queue_entries_total'] ?? 0,
            'stale_handoff_block_examples'                              => $handoffStats['stale_block_examples']             ?? [],
            'revalidation_required_examples'                            => $handoffStats['revalidation_required_examples']   ?? [],
            'current_run_freshness_checked_total'                       => $handoffStats['current_run_freshness_checked_total'] ?? 0,
            'current_run_freshness_passed_total'                        => $handoffStats['current_run_freshness_passed_total'] ?? 0,
            'current_run_freshness_blocked_total'                       => $handoffStats['current_run_freshness_blocked_total'] ?? 0,
            'current_run_freshness_detected_at_only_total'              => $handoffStats['current_run_freshness_detected_at_only_total'] ?? 0,
            'current_run_freshness_refreshed_at_used_total'             => $handoffStats['current_run_freshness_refreshed_at_used_total'] ?? 0,
            'current_run_freshness_pending_recheck_bypassed_total'      => $handoffStats['current_run_freshness_pending_recheck_bypassed_total'] ?? 0,
            'current_run_freshness_examples'                            => $handoffStats['current_run_freshness_examples'] ?? [],
            // ── Confirmed-pattern freshness diagnostics ───────────────────────────
            'confirmed_pattern_freshness_checked_total'                 => $this->confirmedPatternFreshnessCheckedTotal,
            'confirmed_pattern_valid_for_handoff_total'                 => $this->confirmedPatternValidForHandoffTotal,
            'confirmed_pattern_expired_total'                           => $this->confirmedPatternExpiredTotal,
            'confirmed_pattern_invalidated_total'                       => $this->confirmedPatternInvalidatedTotal,
            'confirmed_pattern_blocked_by_generic_freshness_total'      => $this->confirmedPatternBlockedByGenericFreshnessTotal,
            // Pending storage cleanup diagnostics
            'pending_cleanup_checked_total'                             => $handoffStats['pending_cleanup_checked_total'] ?? 0,
            'pending_cleanup_removed_confirmed_total'                   => $handoffStats['pending_cleanup_removed_confirmed_total'] ?? 0,
            'pending_cleanup_removed_garbage_blocked_total'             => $handoffStats['pending_cleanup_removed_garbage_blocked_total'] ?? 0,
            'pending_cleanup_removed_invalid_total'                     => $handoffStats['pending_cleanup_removed_invalid_total'] ?? 0,
            'pending_cleanup_removed_expired_total'                     => $handoffStats['pending_cleanup_removed_expired_total'] ?? 0,
            'pending_cleanup_history_written_total'                     => $handoffStats['pending_cleanup_history_written_total'] ?? 0,
            'pending_cleanup_examples'                                  => $handoffStats['pending_cleanup_examples'] ?? [],
            // ── Lifecycle consistency diagnostics ─────────────────────────────────
            'lifecycle_consistency_checked_total'                       => $this->lifecycleConsistencyCheckedTotal,
            'lifecycle_inconsistent_fixed_total'                        => $this->lifecycleInconsistentFixedTotal,
            'lifecycle_inconsistent_examples'                           => $this->lifecycleInconsistentExamples,
            // ── Queue entry normalization counters (explicit non-executable flags) ──
            'queue_entries_normalized_total'              => $handoffStats['queue_entries_normalized_total']              ?? 0,
            'queue_entries_marked_non_executable_total'   => $handoffStats['queue_entries_marked_non_executable_total']   ?? 0,
            'queue_entries_executable_total'              => $handoffStats['queue_entries_executable_total']              ?? 0,
            'queue_entries_blocked_not_current_run_total' => $handoffStats['queue_entries_blocked_not_current_run_total'] ?? 0,
            'queue_entries_blocked_blacklist_total'       => $handoffStats['queue_entries_blocked_blacklist_total']       ?? 0,
            'queue_entries_blocked_freeze_total'          => $handoffStats['queue_entries_blocked_freeze_total']          ?? 0,
            'queue_non_executable_examples'              => $handoffStats['queue_non_executable_examples']               ?? [],
            'handoff_queue_ready_written_total'           => $handoffStats['handoff_queue_ready_written_total'] ?? 0,
            'handoff_queue_blocked_diagnostic_total'      => $handoffStats['handoff_queue_blocked_diagnostic_total'] ?? 0,
            'handoff_queue_blocked_with_ready_status_total' => $handoffStats['handoff_queue_blocked_with_ready_status_total'] ?? 0,
            'handoff_queue_blocked_with_ready_status_examples' => $handoffStats['handoff_queue_blocked_with_ready_status_examples'] ?? [],
            // ── DBL garbage veto counters (per tick) ──────────────────────────────
            'dbl_garbage_veto_enabled'                   => (bool)($config['dbl_garbage_veto_enabled'] ?? true),
            'dbl_garbage_veto_checked_total'             => $this->dblGarbageVetoCheckedTotal,
            'dbl_garbage_veto_blocked_total'             => $this->dblGarbageVetoBlockedTotal,
            'dbl_garbage_low_quality_without_obc_total'  => $this->dblGarbageLowQualityWithoutObcTotal,
            'dbl_garbage_obc_quality_skip_total'         => $this->dblGarbageObcQualitySkipTotal,
            'dbl_garbage_late_daily_extension_total'     => $this->dblGarbageLateExtensionTotal,
            'dbl_garbage_whipsaw_weak_quality_total'     => $this->dblGarbageWhipsawWeakQualityTotal,
            'dbl_garbage_late_local_entry_checked_total' => $this->dblGarbageLateLocalEntryCheckedTotal,
            'dbl_garbage_late_local_entry_blocked_total' => $this->dblGarbageLateLocalEntryBlockedTotal,
            'dbl_garbage_late_local_checked_total'       => $this->dblGarbageLateLocalEntryCheckedTotal,
            'dbl_garbage_late_local_blocked_total'       => $this->dblGarbageLateLocalEntryBlockedTotal,
            'dbl_garbage_late_local_low_quality_blocked_total' => $this->dblGarbageLateLocalLowQualityBlockedTotal,
            'dbl_garbage_late_local_mid_quality_blocked_total' => $this->dblGarbageLateLocalMidQualityBlockedTotal,
            'dbl_garbage_late_local_high_quality_blocked_total' => $this->dblGarbageLateLocalHighQualityBlockedTotal,
            'dbl_garbage_late_local_passed_due_quality_total' => $this->dblGarbageLateLocalPassedDueQualityTotal,
            'dbl_garbage_entry_far_from_point3_total'    => $this->dblGarbageEntryFarFromPoint3Total,
            'dbl_garbage_post_point3_impulse_spent_total' => $this->dblGarbagePostPoint3ImpulseSpentTotal,
            'dbl_garbage_insufficient_room_to_recent_swing_high_total' => $this->dblGarbageInsufficientRoomSwingHighTotal,
            'dbl_garbage_near_recent_swing_high_total'   => $this->dblGarbageNearRecentSwingHighTotal,
            'dbl_garbage_passed_total'                   => $this->dblGarbagePassedTotal,
            'dbl_garbage_late_local_tiny_room_without_reclaim_total' => $this->dblGarbageLateLocalTinyRoomWithoutReclaimTotal,
            'dbl_garbage_block_examples'                 => $this->dblGarbageBlockExamples,
            'dbl_garbage_pass_examples'                  => $this->dblGarbagePassExamples,
            'dbl_garbage_late_local_entry_examples'      => $this->dblGarbageLateLocalEntryExamples,
            'dbl_garbage_late_local_passed_examples'     => $this->dblGarbageLateLocalPassedExamples,
            'dbl_garbage_late_local_tiny_room_without_reclaim_examples' => $this->dblGarbageLateLocalTinyRoomWithoutReclaimExamples,
            // ── DBL funnel counters (per tick) ────────────────────────────────────
            'dbl_raw_candidates_total'                   => $this->dblRawCandidatesTotal,
            'dbl_pattern_active_total_funnel'            => $this->dblPatternActiveTotal,
            'dbl_pattern_confirmed_total_funnel'         => $this->dblPatternConfirmedTotal,
            'dbl_pattern_invalid_total_funnel'           => $this->dblPatternInvalidTotal,
            'dbl_freshness_blocked_total'                => $handoffStats['current_run_freshness_blocked_total'] ?? 0,
            'dbl_pattern_state_blocked_total'            => $this->patternStateBlockedBeforeGarbageTotal,
            'dbl_garbage_veto_checked_total_funnel'      => $this->dblGarbageVetoCheckedTotal,
            'dbl_garbage_veto_blocked_total_funnel'      => $this->dblGarbageVetoBlockedTotal,
            'dbl_garbage_veto_passed_total'              => $this->dblGarbagePassedTotal,
            'dbl_handoff_ready_total'                    => $handoffStats['ready_total'] ?? 0,
            'dbl_bot_queue_ready_written_total'          => $handoffStats['handoff_queue_ready_written_total'] ?? 0,
            // ── DBL near-miss bucket ──────────────────────────────────────────────
            'dbl_near_miss_total'                        => $this->dblNearMissTotal,
            'dbl_near_miss_by_reason'                    => $this->dblNearMissByReason,
            'dbl_near_miss_examples'                     => $this->dblNearMissExamples,
            // ── DBL trace completeness counters (per tick) ────────────────────────
            'dbl_trace_checked_total'                        => $this->dblTraceCheckedTotal,
            'dbl_trace_complete_total'                       => $this->dblTraceCompleteTotal,
            'dbl_trace_missing_total'                        => $this->dblTraceMissingTotal,
            'dbl_trace_reconstructed_total'                  => $this->dblTraceReconstructedTotal,
            'dbl_trace_reconstruction_failed_total'          => $this->dblTraceReconstructionFailedTotal,
            'dbl_trace_incomplete_total'                     => $this->dblTraceIncompleteTotal,
            'dbl_trace_reconstructed_before_pattern_state_total' => $this->dblTraceReconstructedBeforePatternStateTotal,
            'dbl_trace_reconstruction_failed_before_pattern_state_total' => $this->dblTraceReconstructionFailedBeforePatternStateTotal,
            'dbl_garbage_missing_trace_blocked_total'        => $this->dblGarbageMissingTraceBlockedTotal,
            'dbl_garbage_reclaim_not_confirmed_total'        => $this->dblGarbageReclaimNotConfirmedTotal,
            'dbl_garbage_missing_trace_passed_high_quality_total' => $this->dblGarbageMissingTracePassedHighQualityTotal,
            'dbl_trace_missing_examples'                     => $this->dblTraceMissingExamples,
            'dbl_trace_incomplete_examples'                  => $this->dblTraceIncompleteExamples,
            'dbl_trace_reconstructed_examples'               => $this->dblTraceReconstructedExamples,
            'dbl_garbage_missing_trace_block_examples'       => $this->dblGarbageMissingTraceBlockExamples,
            'dbl_garbage_reclaim_not_confirmed_examples'     => $this->dblGarbageReclaimNotConfirmedExamples,
            // ── DBL trend-shift confirmation gate counters (per tick) ─────────────
            'dbl_trend_shift_gate_enabled'                           => (bool)($config['dbl_trend_shift_gate_enabled'] ?? true),
            'dbl_trend_shift_required_for_handoff'                   => (bool)($config['dbl_trend_shift_required_for_handoff'] ?? true),
            'dbl_trend_shift_checked_total'                          => $this->dblTrendShiftCheckedTotal,
            'dbl_trend_shift_confirmed_total'                        => $this->dblTrendShiftConfirmedTotal,
            'dbl_trend_shift_pending_total'                          => $this->dblTrendShiftPendingTotal,
            'dbl_trend_shift_failed_total'                           => $this->dblTrendShiftFailedTotal,
            'dbl_trend_shift_reclaim_hold_confirmed_total'           => $this->dblTrendShiftReclaimHoldConfirmedTotal,
            'dbl_trend_shift_retest_hold_confirmed_total'            => $this->dblTrendShiftRetestHoldConfirmedTotal,
            'dbl_trend_shift_higher_low_confirmed_total'             => $this->dblTrendShiftHigherLowConfirmedTotal,
            'dbl_trend_shift_short_structure_break_confirmed_total'  => $this->dblTrendShiftShortStructureBreakConfirmedTotal,
            'dbl_trend_shift_reclaim_lost_total'                     => $this->dblTrendShiftReclaimLostTotal,
            'dbl_trend_shift_point3_broken_total'                    => $this->dblTrendShiftPoint3BrokenTotal,
            'dbl_trend_shift_fresh_lower_low_total'                  => $this->dblTrendShiftFreshLowerLowTotal,
            'dbl_trend_shift_pending_expired_total'                  => $this->dblTrendShiftPendingExpiredTotal,
            'dbl_trend_shift_handoff_blocked_unconfirmed_total'      => $this->dblTrendShiftHandoffBlockedUnconfirmedTotal,
            'dbl_trend_shift_confirmed_examples'                     => $this->dblTrendShiftConfirmedExamples,
            'dbl_trend_shift_pending_examples'                       => $this->dblTrendShiftPendingExamples,
            'dbl_trend_shift_failed_examples'                        => $this->dblTrendShiftFailedExamples,
            'dbl_trend_shift_blocked_unconfirmed_examples'           => $this->dblTrendShiftBlockedUnconfirmedExamples,
            // ── DBL pattern-status state machine counters (per tick) ────────────────
            'dbl_pattern_status_enabled'                             => (bool)($config['dbl_pattern_status_enabled'] ?? true),
            'dbl_pattern_confirmation_required_for_handoff'          => (bool)($config['dbl_pattern_confirmation_required_for_handoff'] ?? true),
            'dbl_pattern_status_checked_total'                       => $this->dblPatternStatusCheckedTotal,
            'dbl_pattern_active_total'                               => $this->dblPatternActiveTotal,
            'dbl_pattern_confirmed_total'                            => $this->dblPatternConfirmedTotal,
            'dbl_pattern_invalid_total'                              => $this->dblPatternInvalidTotal,
            'dbl_pattern_pending_total'                              => $this->dblPatternPendingTotal,
            'dbl_pattern_pending_rechecked_total'                    => $this->dblPatternPendingRecheckedTotal,
            'dbl_pattern_pending_confirmed_total'                    => $this->dblPatternPendingConfirmedTotal,
            'dbl_pattern_pending_invalidated_total'                  => $this->dblPatternPendingInvalidatedTotal,
            'dbl_pattern_pending_expired_total'                      => $this->dblPatternPendingExpiredTotal,
            'dbl_pattern_confirmed_neckline_break_total'             => $this->dblPatternConfirmedNecklineBreakTotal,
            'dbl_pattern_confirmed_reclaim_hold_total'               => $this->dblPatternConfirmedReclaimHoldTotal,
            'dbl_pattern_confirmed_retest_hold_total'                => $this->dblPatternConfirmedRetestHoldTotal,
            'dbl_pattern_confirmed_higher_low_total'                 => $this->dblPatternConfirmedHigherLowTotal,
            'dbl_pattern_invalid_point3_broken_total'                => $this->dblPatternInvalidPoint3BrokenTotal,
            'dbl_pattern_invalid_fresh_lower_low_total'              => $this->dblPatternInvalidFreshLowerLowTotal,
            'dbl_pattern_invalid_reclaim_lost_total'                 => $this->dblPatternInvalidReclaimLostTotal,
            'dbl_pattern_invalid_ttl_expired_total'                  => $this->dblPatternInvalidTtlExpiredTotal,
            'dbl_pattern_invalid_weak_bounce_total'                  => $this->dblPatternInvalidWeakBounceTotal,
            'dbl_pattern_confirmed_sent_to_garbage_veto_total'       => $this->dblPatternConfirmedSentToGarbageVetoTotal,
            'dbl_pattern_active_blocked_from_handoff_total'          => $this->dblPatternActiveBlockedFromHandoffTotal,
            'dbl_pattern_invalid_blocked_from_handoff_total'         => $this->dblPatternInvalidBlockedFromHandoffTotal,
            'pattern_state_blocked_before_garbage_total'             => $this->patternStateBlockedBeforeGarbageTotal,
            'pattern_state_blocked_before_garbage_examples'          => $this->patternStateBlockedBeforeGarbageExamples,
            'reclaim_lost_checked_total'                             => $this->reclaimLostCheckedTotal,
            'reclaim_lost_terminal_total'                            => $this->reclaimLostTerminalTotal,
            'reclaim_lost_recovered_total'                           => $this->reclaimLostRecoveredTotal,
            'reclaim_lost_ambiguous_total'                           => $this->reclaimLostAmbiguousTotal,
            'reclaim_lost_terminal_examples'                         => $this->reclaimLostTerminalExamples,
            'reclaim_lost_recovered_examples'                        => $this->reclaimLostRecoveredExamples,
            'dbl_pattern_confirmed_handoff_ready_total'              => $this->dblPatternConfirmedHandoffReadyTotal,
            'dbl_pattern_active_examples'                             => $this->dblPatternActiveExamples,
            'dbl_pattern_confirmed_examples'                          => $this->dblPatternConfirmedExamples,
            'dbl_pattern_invalid_examples'                            => $this->dblPatternInvalidExamples,
            'dbl_pattern_pending_examples'                            => $this->dblPatternPendingExamples,
            'dbl_pattern_pending_confirmed_examples'                  => $this->dblPatternPendingConfirmedExamples,
            'dbl_pattern_pending_invalidated_examples'                => $this->dblPatternPendingInvalidatedExamples,
            'dbl_pattern_pending_sweep_total'                => $this->dblPatternPendingSweepTotal,
            'dbl_pattern_pending_sweep_confirmed_total'      => $this->dblPatternPendingSweepConfirmedTotal,
            'dbl_pattern_pending_sweep_invalid_total'        => $this->dblPatternPendingSweepInvalidTotal,
            'dbl_pattern_pending_sweep_expired_total'        => $this->dblPatternPendingSweepExpiredTotal,
            'dbl_pattern_pending_sweep_still_active_total'   => $this->dblPatternPendingSweepStillActiveTotal,
            'dbl_pattern_pending_storage_before_total'       => $this->dblPatternPendingStorageBeforeTotal,
            'dbl_pattern_pending_storage_after_total'        => $this->dblPatternPendingStorageAfterTotal,
            'dbl_pattern_pending_storage_stale_removed_total'=> $this->dblPatternPendingStorageStaleRemovedTotal,
            // ── Scan suppression cache diagnostics (Task 7) ──────────────────────
            'scan_suppression_enabled'                   => $suppressionEnabled,
            'scan_suppression_entries_total'             => $suppressionEnabled ? count($this->scanSuppressionCache) : 0,
            'scan_suppression_active_total'              => $suppressionEnabled ? count($this->scanSuppressionCache) : 0,
            'skipped_by_scan_suppression_total'          => $this->scanSuppressionSkippedThisTick,
            'scan_suppression_added_total'               => $this->scanSuppressionAddedThisTick,
            'scan_suppression_refreshed_total'           => $this->scanSuppressionRefreshedThisTick,
            'scan_suppression_expired_total'             => $this->scanSuppressionExpiredThisTick,
            'scan_suppression_expired_market_changed_total' => 0,
            'scan_suppression_expired_prefilter_passed_total' => 0,
            'entry_context_fetch_budget_total'           => (int)($config['max_entry_context_fetch_per_run'] ?? $config['entry_context_max_symbols_per_tick'] ?? 35),
            'entry_context_fetch_used_total'             => $this->ctxFetchAttemptedThisTick,
            'entry_context_fetch_cap_reached_total'      => $this->ctxFetchCapReachedThisTick,
            'scan_suppression_skip_examples'             => $this->scanSuppressionSkipExamples,
            'scan_suppression_added_examples'            => $this->scanSuppressionAddedExamples,
            'scan_suppression_expired_examples'          => $this->scanSuppressionExpiredExamples,
            'entry_context_fetch_cap_examples'           => $this->ctxFetchCapExamples,
            // ── Calibration example arrays (Task 6) ──────────────────────────────
            'normal_signal_examples'                   => $normalSignalExamples,
            'rejected_signal_examples'                 => $rejectedSignalExamples,
            'final_low_quality_reject_examples'        => $finalLowQualityRejectExamples,
            'setup_allowed_quality_failed_examples'    => $setupAllowedQualityFailedExamples,
            'setup_allowed_pending_examples'           => $setupAllowedPendingExamples,
            'setup_allowed_final_gate_warning_examples' => $setupAllowedFinalGateWarnExamples,
            'late_good_setup_examples'                 => $lateGoodSetupExamples,
            // Diagnostic examples: last 5 synthetic quality failures in this tick
            'synthetic_quality_failed_examples'                  => $synQFailedExamples,
            // bad_accept diagnostics: high-score signals with adverse price move since emission
            'bad_accept_examples'                                => $badAcceptExamples,
            // ── Closed-trade calibration diagnostics ─────────────────────────────
            'calibration_closed_trades_total'           => $calibration['calibration_closed_trades_total'],
            'calibration_closed_trades_with_trace_total' => $calibration['calibration_closed_trades_with_trace_total'],
            'calibration_winning_trades_total'           => $calibration['calibration_winning_trades_total'],
            'calibration_losing_trades_total'            => $calibration['calibration_losing_trades_total'],
            'calibration_deep_loss_total'                => $calibration['calibration_deep_loss_total'],
            'calibration_entry_distance_buckets'         => $calibration['calibration_entry_distance_buckets'],
            'calibration_synthetic_quality_buckets'      => $calibration['calibration_synthetic_quality_buckets'],
            'calibration_setup_class_score_buckets'      => $calibration['calibration_setup_class_score_buckets'],
            'calibration_candidate_quality_buckets'      => $calibration['calibration_candidate_quality_buckets'],
            'calibration_warning_combo_stats'            => $calibration['calibration_warning_combo_stats'],
            'calibration_profitable_examples'            => $calibration['calibration_profitable_examples'],
            'calibration_losing_examples'                => $calibration['calibration_losing_examples'],
            'calibration_deep_loss_examples'             => $calibration['calibration_deep_loss_examples'],
            'calibration_bad_signature_examples'         => $calibration['calibration_bad_signature_examples'],
            'calibration_candidate_rules'                => $calibration['calibration_candidate_rules'],
            // ── Hourly performance statistics ─────────────────────────────────
            'hourly_stats_enabled'               => $hourlyStats['hourly_stats_enabled'],
            'hourly_stats_generated_at'          => $hourlyStats['hourly_stats_generated_at'],
            'hourly_stats_total_trades'          => $hourlyStats['hourly_stats_total_trades'],
            'hourly_stats_bad_hour_candidates'   => $hourlyStats['hourly_stats_bad_hour_candidates'],
            'hourly_stats_good_hour_candidates'  => $hourlyStats['hourly_stats_good_hour_candidates'],
            'hourly_stats_bad_block_candidates'  => $hourlyStats['hourly_stats_bad_block_candidates'],
            'hourly_stats_good_block_candidates' => $hourlyStats['hourly_stats_good_block_candidates'],
            'hourly_stats_file'                  => $hourlyStats['hourly_stats_file'],
            'hourly_stats_error'                 => $hourlyStats['hourly_stats_error']        ?? false,
            'hourly_stats_error_reason'          => $hourlyStats['hourly_stats_error_reason'] ?? null,
            // ── Demo/live comparison timing and context fingerprints ──────────────
            'run_started_at'               => $state['started_at']      ?? null,
            'run_finished_at'              => $isDone ? ($finishedAt ?? date('c')) : null,
            'obc_wall_state_entries_total' => (static function (string $repoRoot): int {
                $path = $repoRoot . '/modules/system/orderbook_context/storage/wall_state.json';
                if (!is_file($path)) {
                    return 0;
                }
                $raw = @file_get_contents($path);
                if ($raw === false) {
                    return 0;
                }
                $d = @json_decode($raw, true);
                return is_array($d) ? count($d) : 0;
            })($this->repoRoot),
            'obc_wall_state_hash'          => (static function (string $repoRoot): ?string {
                $path = $repoRoot . '/modules/system/orderbook_context/storage/wall_state.json';
                if (!is_file($path)) {
                    return null;
                }
                $raw = @file_get_contents($path);
                return $raw !== false ? md5($raw) : null;
            })($this->repoRoot),
            // ── Throughput health diagnostics ─────────────────────────────────────
            'dbl_runtime_hours_estimated'         => $dblRuntimeHoursEstimated,
            'dbl_orders_per_hour_estimated'       => $dblOrdersPerHourEstimated,
            'dbl_handoff_ready_per_hour_estimated' => $dblHandoffReadyPerHourEstimated,
            'dbl_expected_min_handoff_per_6h'     => $dblExpectedMinHandoffPer6h,
            'dbl_throughput_too_low'              => $dblThroughputTooLow,
        ]);

        if ($isDone) {
            $this->writeRuntimeSnapshot($config, $state);
        }
    }

    /**
     * Write runtime_snapshot.php with the config values used in the completed cycle.
     */
    private function writeRuntimeSnapshot(array $config, array $state): void
    {
        $snap = [
            'snapshot_at'       => date('c'),
            'strategy_id'       => 'double_bottom_long',
            'side'              => 'long',
            'mode'              => $config['mode']      ?? 'passive',
            'enabled'           => $config['enabled']   ?? false,
            'timeframe'         => $config['timeframe'] ?? 'H4',
            'cycle_id'          => $state['cycle_id']   ?? 0,
            // Scan
            'universe_mode'           => $config['universe_mode']          ?? 'all',
            'batch_size'              => $config['batch_size']             ?? 50,
            'max_symbols_per_run'     => $config['max_symbols_per_run']    ?? 0,
            'continuous_scan_enabled' => $config['continuous_scan_enabled'] ?? true,
            // Registry diagnostics (from last completed queue run)
            'registry_symbol_count'   => (int)($state['registry_symbol_count']
                ?? $state['registry_diag']['registry_symbol_count']
                ?? 0),
            'registry_total'          => (int)($state['registry_total']       ?? 0),
            'registry_empty'          => (bool)($state['registry_empty']
                ?? ((int)($state['registry_diag']['registry_symbol_count'] ?? 0) === 0)),
            'last_queue_total'        => (int)($state['total'] ?? 0),
            // Registry rotation cursor fields
            'registry_cursor'          => (int)($state['registry_cursor']          ?? 0),
            'next_registry_cursor'     => (int)($state['next_registry_cursor']      ?? 0),
            'registry_window_start'    => (int)($state['registry_window_start']     ?? 0),
            'registry_window_end'      => (int)($state['registry_window_end']       ?? 0),
            'registry_wrapped'         => (bool)($state['registry_wrapped']         ?? false),
            'full_registry_scan_round' => (int)($state['full_registry_scan_round']  ?? 0),
            'selected_symbols_total'   => (int)($state['selected_symbols_total']    ?? 0),
            'registry_symbols_remaining_until_wrap' => (int)($state['registry_symbols_remaining_until_wrap'] ?? 0),
            // Entry-context lazy-fetch config
            'entry_context_lazy_fetch_enabled'             => (bool)($config['entry_context_lazy_fetch_enabled']             ?? true),
            'entry_context_fetch_after_prefilters'         => (bool)($config['entry_context_fetch_after_prefilters']         ?? true),
            'entry_context_fetch_for_rejected_diagnostics' => (bool)($config['entry_context_fetch_for_rejected_diagnostics'] ?? false),
            'entry_context_max_symbols_per_tick'           => (int)($config['entry_context_max_symbols_per_tick']            ?? 50),
            // Entry-context fetch counters from the last completed cycle tick
            'entry_context_fetch_attempted_total'           => $this->ctxFetchAttemptedThisTick,
            'entry_context_fetch_success_total'             => $this->ctxFetchSuccessThisTick,
            'entry_context_fetch_skipped_prefilter_total'   => $this->ctxFetchSkippedPrefilterThisTick,
            'entry_context_fetch_skipped_limit_total'       => $this->ctxFetchSkippedLimitThisTick,
            // Entry-context prefilter config
            'entry_context_prefilter_enabled'       => (bool)($config['entry_context_prefilter_enabled']       ?? true),
            'entry_context_prefilter_min_score'     => (float)($config['entry_context_prefilter_min_score']     ?? 2.0),
            // Stop — fixed_from_liq_zone model
            'stop_mode'                  => $config['stop_mode']                    ?? 'fixed_from_liq_zone',
            'stop_from_liq_buffer_value' => $config['stop_from_liq_buffer_value']   ?? 0.002,
            'stop_from_liq_buffer_type'  => $config['stop_from_liq_buffer_type']    ?? 'percent',
            'stop_buffer_pct_below_lows' => $config['stop_buffer_pct_below_lows']   ?? 0.005,
            'max_stop_loss_pct'          => $config['max_stop_loss_pct']            ?? 0.05,
            'bot_budget'                 => $config['bot_budget']                   ?? 0.0,
            'bot_leverage'               => $config['bot_leverage']                 ?? 1,
            'entry_mode'                 => $config['entry_mode']                   ?? 'limit',
            // Exit (strategy-owned; no trailing in this module)
            'reverse_pattern_close_enabled' => $config['reverse_pattern_close_enabled'] ?? false,
            'tp_enabled'                    => $config['tp_enabled']                    ?? true,
            'tp_mode'                       => $config['tp_mode']                      ?? 'fixed_r',
            'tp_value'                      => $config['tp_value']                     ?? 2.5,
            // Quality gate parameters
            'market_regime_gate_mode'         => $config['market_regime_gate_mode']         ?? 'hard',
            'min_candidate_quality_score'     => $config['min_candidate_quality_score']     ?? 0.68,
            'min_neckline_score'              => $config['min_neckline_score']              ?? 0.55,
            'double_bottom_similarity_tolerance_pct' => $config['double_bottom_similarity_tolerance_pct'] ?? 0.05,
            'allowed_long_buckets'            => $config['allowed_long_buckets']            ?? [1, 2],
            // Brain-compatible keys for discoverStrategyModules()
            'config_valid'     => true,
            'effective_config' => [
                'mode'    => $config['mode']    ?? 'passive',
                'enabled' => $config['enabled'] ?? false,
            ],
        ];

        $path  = $this->moduleDir . '/config/runtime_snapshot.php';
        $lines = [
            "<?php\n\ndeclare(strict_types=1);\n\n",
            "/**\n * Double Bottom Long — Runtime Snapshot\n",
            " * Auto-written after each completed scan cycle.\n",
            " * snapshot_at: " . $snap['snapshot_at'] . "\n */\n\n",
            "return " . var_export($snap, true) . ";\n",
        ];
        @file_put_contents($path, implode('', $lines));
    }

    /**
     * Write a pending confirmation entry to storage/pending_confirmations.json.
     * Lazy-creates the file; respects pending_confirmation_max_items cap.
     */
    private function writePendingConfirmation(array $entry, array $config): void
    {
        $path     = $this->moduleDir . '/storage/pending_confirmations.json';
        $maxItems = max(1, (int)($config['pending_confirmation_max_items'] ?? 20));
        $existing = [];
        if (file_exists($path)) {
            $raw = @file_get_contents($path);
            $decoded = $raw ? @json_decode($raw, true) : null;
            $existing = is_array($decoded) ? $decoded : [];
        }
        // Deduplicate: if same symbol+setup_class already pending, replace it.
        $existing = array_values(array_filter($existing, static function (array $e) use ($entry): bool {
            return !(($e['symbol'] ?? '') === $entry['symbol']
                && ($e['setup_class'] ?? '') === ($entry['setup_class'] ?? ''));
        }));
        $existing[] = $entry;
        // Cap to max items (keep newest entries)
        if (count($existing) > $maxItems) {
            $existing = array_slice($existing, -$maxItems);
        }
        @file_put_contents($path, json_encode(array_values($existing), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * At the start of each tick, recheck any pending confirmations.
     * - Prune expired entries
     * - Re-fetch entry-context candles for each pending symbol
     * - If confirm bars now satisfied and all safety checks pass: emit full signal candidate
     * - If safety check fails: invalidate with specific reason
     *
     * Returns stats array + newly_emitted signal candidates.
     */
    private function recheckPendingConfirmations(array $config, string $regimeStr): array
    {
        $path = $this->moduleDir . '/storage/pending_confirmations.json';
        $result = [
            'newly_emitted'                              => [],
            'loaded_total'                               => 0,
            'rechecked_total'                            => 0,
            'confirmed_total'                            => 0,
            'invalidated_total'                          => 0,
            'expired_total'                              => 0,
            'active_total'                               => 0,
            'invalidated_fresh_dump_total'               => 0,
            'invalidated_reclaim_lost_total'             => 0,
            'invalidated_base_support_broken_total'      => 0,
            'invalidated_falling_knife_total'            => 0,
            'invalidated_entry_distance_worsened_total'  => 0,
            'invalidated_stale_setup_total'              => 0,
            'confirmed_dropped_total'                    => 0,
            'confirmed_examples'                         => [],
            'invalidated_examples'                       => [],
        ];

        if (!file_exists($path)) {
            return $result;
        }

        $raw     = @file_get_contents($path);
        $pending = $raw ? @json_decode($raw, true) : null;
        if (!is_array($pending) || empty($pending)) {
            return $result;
        }

        $result['loaded_total'] = count($pending);
        $nowTs = time();
        $kept  = [];

        foreach ($pending as $entry) {
            if (!is_array($entry) || empty($entry['symbol'])) {
                continue;
            }
            $symbol       = (string)$entry['symbol'];
            $setupClass   = (string)($entry['setup_class'] ?? '');
            $expiresAt    = (string)($entry['expires_at'] ?? '');
            $expireTs     = $expiresAt ? strtotime($expiresAt) : 0;
            $createdAt    = (string)($entry['created_at'] ?? '');
            $createdTs    = $createdAt ? strtotime($createdAt) : 0;

            // Prune expired (stale setup)
            if ($expireTs > 0 && $nowTs > $expireTs) {
                $result['expired_total']++;
                $result['invalidated_total']++;
                $result['invalidated_stale_setup_total']++;
                if (count($result['invalidated_examples']) < 5) {
                    $result['invalidated_examples'][] = [
                        'symbol'                         => $symbol,
                        'setup_class'                    => $setupClass,
                        'reason'                         => 'pending_invalidated_stale_setup',
                        'pending_recheck_status'         => 'invalidated',
                        'pending_recheck_reason'         => 'pending_invalidated_stale_setup',
                        'pending_invalidated_reason'     => 'stale_expired',
                        'expires_at'                     => $expiresAt,
                        'pending_created_at'             => $createdAt ?: null,
                        'last_rechecked_at'              => date('c'),
                        'confirm_bars_seen'              => (int)($entry['confirm_bars_seen']    ?? 0),
                        'confirm_bars_required'          => (int)($entry['confirm_bars_required'] ?? 2),
                        'current_confirm_bars_seen'      => null,
                        'entry_distance_from_neckline_pct' => $entry['entry_distance_from_neckline_pct'] ?? null,
                        'current_entry_distance_from_neckline_pct' => null,
                        'neckline_price'                 => $entry['neckline_level'] ?? null,
                        'current_price'                  => null,
                        'base_support_broken'            => $entry['support_broken'] ?? null,
                        'reclaim_lost'                   => null,
                        'fresh_dump_detected'            => null,
                    ];
                }
                continue;
            }

            $result['rechecked_total']++;

            // Only recheck entries that match the current operating mode.
            $entryMode  = (string)($entry['mode'] ?? 'demo');
            $configMode = (string)($config['mode'] ?? 'demo');
            if ($entryMode !== $configMode) {
                $result['invalidated_total']++;
                $result['invalidated_stale_setup_total']++;
                continue;
            }

            // Re-fetch current entry context candles for this symbol
            try {
                $ctxCandles = $this->fetchEntryContextCandles($symbol, $config);
            } catch (\Throwable $e) {
                $kept[] = $entry;
                $result['active_total']++;
                continue;
            }

            if (count($ctxCandles) < 5) {
                $kept[] = $entry;
                $result['active_total']++;
                continue;
            }

            $lastCandle = $ctxCandles[count($ctxCandles) - 1];
            $lastClose  = (float)($lastCandle['close'] ?? 0.0);
            $neckline   = (float)($entry['neckline_level'] ?? 0.0);

            // Helper: build a rich pending diagnostic snapshot for invalidated_examples
            $pendingDiagSnapshot = static function (
                string $symbol, string $setupClass, string $createdAt,
                array $entry, float $neckline, float $lastClose,
                string $recheckReason, string $invalidatedReason
            ): array {
                return [
                    'symbol'                         => $symbol,
                    'setup_class'                    => $setupClass,
                    'pending_created_at'             => $createdAt ?: null,
                    'last_rechecked_at'              => date('c'),
                    'confirm_bars_seen'              => (int)($entry['confirm_bars_seen']    ?? 0),
                    'confirm_bars_required'          => (int)($entry['confirm_bars_required'] ?? 2),
                    'current_confirm_bars_seen'      => null,
                    'entry_distance_from_neckline_pct' => $entry['entry_distance_from_neckline_pct'] ?? null,
                    'current_entry_distance_from_neckline_pct' => $neckline > 0.0
                        ? round((($lastClose - $neckline) / $neckline) * 100, 4) : null,
                    'neckline_price'                 => $neckline > 0.0 ? $neckline : null,
                    'current_price'                  => $lastClose > 0.0 ? $lastClose : null,
                    'pending_recheck_status'         => 'invalidated',
                    'pending_recheck_reason'         => $recheckReason,
                    'pending_invalidated_reason'     => $invalidatedReason,
                    'base_support_broken'            => $entry['support_broken'] ?? null,
                    'reclaim_lost'                   => null,
                    'fresh_dump_detected'            => null,
                ];
            };

            // ── Strict invalidation checks ────────────────────────────────────

            // 1. Falling knife: last close has broken sharply below the stored neckline/reclaim
            if ((bool)($config['synthetic_quality_require_no_falling_knife'] ?? true)) {
                if ($neckline > 0.0 && $lastClose < $neckline * (1.0 - 0.04)) {
                    $result['invalidated_total']++;
                    $result['invalidated_falling_knife_total']++;
                    if (count($result['invalidated_examples']) < 5) {
                        $ex = $pendingDiagSnapshot($symbol, $setupClass, $createdAt, $entry, $neckline, $lastClose,
                            'pending_invalidated_falling_knife', 'active_falling_knife');
                        $ex['last_close']       = $lastClose;
                        $ex['neckline']         = $neckline;
                        $ex['reclaim_lost']     = true;
                        $result['invalidated_examples'][] = $ex;
                    }
                    continue;
                }
            }

            // 2. Reclaim lost: price has dropped back below neckline by a small margin
            if ($neckline > 0.0 && $lastClose < $neckline * (1.0 - 0.015)) {
                $result['invalidated_total']++;
                $result['invalidated_reclaim_lost_total']++;
                if (count($result['invalidated_examples']) < 5) {
                    $ex = $pendingDiagSnapshot($symbol, $setupClass, $createdAt, $entry, $neckline, $lastClose,
                        'pending_invalidated_reclaim_lost', 'reclaim_lost');
                    $ex['last_close']   = $lastClose;
                    $ex['neckline']     = $neckline;
                    $ex['reclaim_lost'] = true;
                    $result['invalidated_examples'][] = $ex;
                }
                continue;
            }

            // 3. Fresh dump after pending was created: check for a significant drop in recent candles
            if ($createdTs > 0) {
                $maxDumpPct   = (float)($config['max_recent_dump_1h_pct'] ?? 9.0);
                $checkWindow  = min(count($ctxCandles), 60);
                $recentBars   = array_slice($ctxCandles, -$checkWindow);
                // Find the highest close after pending creation and the current close
                $postCreateHigh = 0.0;
                foreach ($recentBars as $bar) {
                    $barTs = isset($bar['time']) ? (int)($bar['time'] / 1000) : 0;
                    if ($barTs >= $createdTs - 60 /* 1-minute clock-skew tolerance */) {
                        $barClose = (float)($bar['close'] ?? 0.0);
                        if ($barClose > $postCreateHigh) {
                            $postCreateHigh = $barClose;
                        }
                    }
                }
                if ($postCreateHigh > 0.0) {
                    $dumpPct = (($postCreateHigh - $lastClose) / $postCreateHigh) * 100.0;
                    if ($dumpPct >= $maxDumpPct) {
                        $result['invalidated_total']++;
                        // Count as reclaim_lost (price dumped post-creation)
                        $result['invalidated_reclaim_lost_total']++;
                        if (count($result['invalidated_examples']) < 5) {
                            $ex = $pendingDiagSnapshot($symbol, $setupClass, $createdAt, $entry, $neckline, $lastClose,
                                'pending_invalidated_fresh_dump', 'fresh_dump');
                            $ex['dump_pct']           = round($dumpPct, 2);
                            $ex['post_high']          = $postCreateHigh;
                            $ex['last_close']         = $lastClose;
                            $ex['fresh_dump_detected'] = true;
                            $ex['reclaim_lost']       = true;
                            $result['invalidated_examples'][] = $ex;
                        }
                        continue;
                    }
                }
            }

            // 4. Entry distance worsened beyond the hard cap
            $maxDist = (float)($config['synthetic_quality_intraday_db_max_entry_distance_from_neckline_pct']
                ?? $config['synthetic_quality_max_entry_distance_from_neckline_pct'] ?? 2.0);
            if ($neckline > 0.0 && $maxDist > 0.0) {
                $distPct = (($lastClose - $neckline) / $neckline) * 100.0;
                if ($distPct > $maxDist) {
                    $result['invalidated_total']++;
                    $result['invalidated_entry_distance_worsened_total']++;
                    if (count($result['invalidated_examples']) < 5) {
                        $ex = $pendingDiagSnapshot($symbol, $setupClass, $createdAt, $entry, $neckline, $lastClose,
                            'pending_invalidated_entry_distance_worsened', 'entry_distance_worsened');
                        $ex['dist_pct'] = round($distPct, 3);
                        $ex['max_dist'] = $maxDist;
                        $result['invalidated_examples'][] = $ex;
                    }
                    continue;
                }
            }

            // ── Confirm bar count check ───────────────────────────────────────
            $confirmBarsRequired = max(1, (int)($entry['confirm_bars_required'] ?? 2));
            $barsAbove = 0;
            $checkCandles = array_slice($ctxCandles, -$confirmBarsRequired - 5);
            foreach ($checkCandles as $bar) {
                if ((float)($bar['close'] ?? 0.0) >= $neckline * 0.998) {
                    $barsAbove++;
                }
            }

            if ($barsAbove >= $confirmBarsRequired) {
                $result['confirmed_total']++;

                // Build a full signal-compatible object from the stored context.
                // The signal must have signal_id and all quality fields to survive
                // applySignalFilters() ($isComplete check and quality gates).
                $trigger  = (float)($entry['candidate_trigger'] ?? $neckline);
                $sqScore  = (float)($entry['synthetic_quality_score'] ?? 0.7);
                $cqScore  = (float)($entry['candidate_quality_score'] ?? $sqScore);
                $trigInt  = (int)round($trigger * 1e4);
                $signalId = sprintf('dbl_%s_%d_long', strtolower($symbol), $trigInt);

                // Compute stop-loss from stored lows (same formula as PatternSignal::computeStopLoss).
                $bufferPct = (float)($config['stop_buffer_pct_below_lows'] ?? 0.005);
                $low1      = (float)($entry['low1_price'] ?? 0.0);
                $low2      = (float)($entry['low2_price'] ?? 0.0);
                $slPrice   = null;
                $slPct     = null;
                if ($low1 > 0.0 && $low2 > 0.0 && $trigger > 0.0) {
                    $stopRef = min($low1, $low2);
                    $slPrice = round($stopRef * (1.0 - $bufferPct), 8);
                    $slPct   = round(($trigger - $slPrice) / $trigger, 6);
                }

                $confirmedSig = [
                    // Identity
                    'strategy_id'             => 'double_bottom_long',
                    'strategy'                => 'double_bottom_long',
                    'signal_source'           => 'double_bottom_long',
                    'pattern_algorithm'       => 'double_bottom_long',
                    'signal_id'               => $signalId,
                    'symbol'                  => $symbol,
                    'side'                    => 'long',
                    'setup_class'             => $setupClass,
                    // Entry geometry
                    'entry_type'              => 'breakout',
                    'entry_price'             => $trigger,
                    'candidate_trigger'       => $trigger,
                    'primary_pattern'         => 'double_bottom',
                    'candidate_score'         => $sqScore,
                    'neckline_level'          => $neckline > 0.0 ? $neckline : ($entry['neckline_level'] ?? null),
                    // A-class: reclaim_level = intraday neckline when flat_base reclaim is absent.
                    'reclaim_level'           => $entry['reclaim_level'] ?? (
                        $setupClass === 'classic_intraday_double_bottom_reclaim'
                        ? ($entry['neckline_level'] ?? ($neckline > 0.0 ? $neckline : null))
                        : null
                    ),
                    // Stop-loss (reconstructed)
                    'stop_loss_price'         => $slPrice,
                    'stop_loss_pct'           => $slPct,
                    'stop_basis'              => 'pattern_lows',
                    // Take-profit
                    'tp_enabled'              => (bool)($config['tp_enabled'] ?? true),
                    'tp_mode'                 => (string)($config['tp_mode'] ?? 'fixed_r'),
                    'tp_value'                => (float)($config['tp_value'] ?? 2.5),
                    'tp_price'                => null,
                    // Quality scores (from stored context; do not fill with fake 0.7 if missing)
                    'pattern_score'           => isset($entry['pattern_score'])     ? (float)$entry['pattern_score']     : null,
                    'structure_score'         => isset($entry['structure_score'])   ? (float)$entry['structure_score']   : null,
                    'neckline_score'          => isset($entry['neckline_score'])    ? (float)$entry['neckline_score']    : null,
                    'confirmation_score'      => isset($entry['confirmation_score'])? (float)$entry['confirmation_score']: null,
                    'context_score'           => isset($entry['context_score'])     ? (float)$entry['context_score']     : null,
                    'candidate_quality_score' => $cqScore,
                    'legacy_candidate_quality_score' => $cqScore,
                    'synthetic_quality_score' => $sqScore,
                    'setup_class_score'       => $entry['setup_class_score']           ?? null,
                    'intraday_double_bottom_score' => $entry['intraday_double_bottom_score'] ?? null,
                    // Quality gate outcome
                    'synthetic_quality_pass'  => true,
                    'quality_pass'            => true,
                    'quality_reject_reason'   => null,
                    'quality_source'          => $entry['quality_source'] ?? 'synthetic_intraday_setup',
                    'reason_codes'            => $entry['reason_codes'] ?? null,
                    'warnings'                => $entry['synthetic_quality_warnings'] ?? null,
                    // Entry distance
                    'entry_distance_from_neckline_pct' => $neckline > 0.0
                        ? round((($lastClose - $neckline) / $neckline) * 100, 4) : 0.0,
                    'entry_distance_from_reclaim_pct'  => $entry['entry_distance_from_reclaim_pct'] ?? null,
                    // Late-good-setup flags
                    'late_good_setup'                   => (bool)($entry['late_good_setup'] ?? false),
                    'missed_ideal_entry'                => false,
                    'waiting_for_better_entry_distance' => false,
                    // H4 market context (from stored context; used by applySignalFilters)
                    'trend_direction'         => (string)($entry['trend_direction'] ?? 'unknown'),
                    'wave_state'              => (string)($entry['wave_state'] ?? 'unknown'),
                    'corridor_bucket'         => (int)($entry['corridor_bucket'] ?? 0),
                    'market_regime'           => $regimeStr,
                    // Lifecycle
                    'detected_at'             => $entry['created_at'] ?? date('c'),
                    'status'                  => 'active',
                    'final_signal_status'     => 'emitted',
                    // Pending confirmation metadata
                    'pending_confirmation_status'   => 'confirmed',
                    'pending_confirmation_reason'   => 'confirm_bars_satisfied',
                    'pending_confirmed_at'          => date('c'),
                    'pending_created_at'            => $entry['created_at'] ?? null,
                    'pending_invalidated_reason'    => null,
                    '_setup_signal_allowed'         => true,
                    // Corridor/wave (stored copies from H4 at creation time)
                    'corridor_low'            => 0.0,
                    'corridor_high'           => 0.0,
                    'wave_direction'          => 'unknown',
                ];

                // Compute TP price if possible
                if ($slPrice !== null && $slPrice > 0.0) {
                    $riskDist = abs($trigger - $slPrice);
                    $tpVal = (float)($config['tp_value'] ?? 2.5);
                    $confirmedSig['tp_price'] = round($trigger + $riskDist * $tpVal, 8);
                }

                if (count($result['confirmed_examples']) < 5) {
                    $result['confirmed_examples'][] = [
                        'symbol'               => $symbol,
                        'setup_class'          => $setupClass,
                        'signal_id'            => $signalId,
                        'candidate_trigger'    => $trigger,
                        'synthetic_quality_score' => $sqScore,
                        'stop_loss_pct'        => $slPct,
                        'confirmed_at'         => date('c'),
                    ];
                }

                $result['newly_emitted'][] = $confirmedSig;
                continue;
            }

            // Still waiting — keep it
            $kept[] = $entry;
            $result['active_total']++;
        }

        // Write back the surviving pending entries
        @file_put_contents($path, json_encode(array_values($kept), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        return $result;
    }

    /**
     * Synchronous full run (for small manual_list).
     */
    public function run(): array
    {
        $queueResult = $this->queueRun();
        if (!($queueResult['ok'] ?? false)) {
            return $queueResult;
        }

        $maxIterations = 500;
        $i = 0;
        do {
            $this->tickBatch();
            $state = $this->getRunState();
            $i++;
        } while (in_array($state['status'] ?? '', ['queued', 'running'], true) && $i < $maxIterations);

        return [
            'ok'        => true,
            'processed' => $state['processed']   ?? 0,
            'found'     => $state['found']        ?? 0,
            'status'    => $state['status']       ?? 'unknown',
        ];
    }

    // =========================================================================
    // Market regime
    // =========================================================================

    private function computeAndPersistRegime(array $symbols, array $config, string $previousRegime): void
    {
        $this->requireLogic('trend');
        $this->requireLogic('market_regime');

        $summaries  = [];
        $sampleSize = min(count($symbols), (int)($config['regime_sample_size'] ?? 30));
        $sample     = array_slice($symbols, 0, $sampleSize);

        // Use at least 30 candles so PatternTrend (SLOW_PERIOD = 21) can classify;
        // fewer candles always yield 'unknown', collapsing the regime to 'unknown' too.
        $regimeLookback = max(30, (int)($config['regime_sample_lookback_candles'] ?? 30));

        foreach ($sample as $sym) {
            try {
                $candles = $this->fetchCandles($sym, array_merge($config, ['lookback_candles' => $regimeLookback]));
                if (count($candles) >= 5) {
                    $trendResult = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternTrend())->analyse($candles);
                    $summaries[] = ['symbol' => $sym, 'trend' => $trendResult['trend_direction']];
                } else {
                    $summaries[] = ['symbol' => $sym, 'trend' => 'flat'];
                }
            } catch (\Throwable) {
                $summaries[] = ['symbol' => $sym, 'trend' => 'flat'];
            }
        }

        $regimeEngine = new \Modules\Strategy\DoubleBottomLong\Logic\PatternMarketRegime();
        $regimeResult = $regimeEngine->compute($summaries, $previousRegime, $config);

        $this->writeJson('storage/market_regime.json', $regimeResult);

        $histPath = $this->moduleDir . '/storage/market_regime_history.ndjson';
        $histLine = json_encode([
            'ts'               => $regimeResult['ts'],
            'previous_regime'  => $regimeResult['previous_regime'],
            'current_regime'   => $regimeResult['regime'],
            'regime_changed'   => $regimeResult['regime_changed'],
            'regime_reason'    => $regimeResult['regime_reason'],
            'bull_count'       => $regimeResult['bull_count'],
            'bear_count'       => $regimeResult['bear_count'],
            'flat_count'       => $regimeResult['flat_count'],
            'unknown_count'    => $regimeResult['unknown_count'],
            'total_count_used' => $regimeResult['total_count_used'],
            'bull_ratio'       => $regimeResult['bull_ratio'],
            'bear_ratio'       => $regimeResult['bear_ratio'],
            'flat_ratio'       => $regimeResult['flat_ratio'],
        ]) . "\n";
        file_put_contents($histPath, $histLine, FILE_APPEND | LOCK_EX);
    }

    // =========================================================================
    // Per-symbol pipeline — long-only
    // =========================================================================

    private function processSymbol(string $symbol, array $config, string $regimeStr): array
    {
        $candles = $this->fetchCandles($symbol, $config);

        // ── Cheap H4 filters — run BEFORE expensive ctx fetch ─────────────────
        $this->requireLogic('trend');
        $trend    = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternTrend())->analyse($candles);
        $trendDir = $trend['trend_direction'];

        $this->requireLogic('corridor');
        $lastClose = (float)(end($candles)['close'] ?? 0.0);
        // Compute 24h price change from H4 candles (6 H4 bars = 24h)
        $n = count($candles);
        $close24hAgo = ($n >= 7) ? (float)($candles[$n - 7]['close'] ?? 0.0) : 0.0;
        $dailyChangePct = ($close24hAgo > 0.0)
            ? round((($lastClose - $close24hAgo) / $close24hAgo) * 100.0, 2)
            : null;
        $corridor  = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternCorridor())->compute($candles, $lastClose, $config);

        $this->requireLogic('wave');
        $wave = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternWave())->analyse($candles);

        // ── Lazy entry-context fetch decision ────────────────────────────────
        // Fetch 1m/5m candles only when the symbol is a plausible candidate.
        // Skipping saves API quota for symbols already doomed by cheap H4 gates.
        $lazyEnabled     = (bool)($config['entry_context_lazy_fetch_enabled']             ?? true);
        $fetchAfterPre   = (bool)($config['entry_context_fetch_after_prefilters']         ?? true);
        $fetchForRejDiag = (bool)($config['entry_context_fetch_for_rejected_diagnostics'] ?? false);
        // max_entry_context_fetch_per_run takes precedence; fall back to legacy entry_context_max_symbols_per_tick
        $maxPerTick      = max(1, (int)($config['max_entry_context_fetch_per_run'] ?? $config['entry_context_max_symbols_per_tick'] ?? 35));

        $ctxCandles         = [];
        $entryCtxAvailable  = false;
        $entryCtxSkipReason = null;

        if ($lazyEnabled && $fetchAfterPre) {
            $prefEnabled = (bool)($config['entry_context_prefilter_enabled'] ?? true);

            if ($prefEnabled) {
                // Score-based prefilter: only fetch if concrete H4 signals are promising.
                // Bearish regime alone NEVER triggers a fetch.
                $pref        = $this->cheapEntryContextPrefilter($candles, $trend, $corridor, $wave, $config);
                $shouldFetch = $pref['should_fetch'] || $fetchForRejDiag;
            } else {
                // Legacy broad condition kept for backwards compatibility when prefilter=false.
                $trendOk = ($trendDir === 'bullish')
                    || !(bool)($config['trend_long_require_bullish'] ?? true);
                $corrOk  = (bool)($corridor['bucket_allowed_long'] ?? false);
                $waveOk  = ($wave['wave_direction'] === 'up') && ($wave['wave_state'] === 'corrective');
                $pref    = ['should_fetch' => true, 'prefilter_score' => 0.0, 'prefilter_reasons' => [], 'prefilter_reject_reason' => null];
                $shouldFetch = $trendOk || $corrOk || $waveOk || $fetchForRejDiag;
            }

            if ($shouldFetch) {
                if ($this->ctxFetchAttemptedThisTick >= $maxPerTick) {
                    $entryCtxSkipReason = 'entry_context_fetch_cap_reached';
                    $this->ctxFetchSkippedLimitThisTick++;
                    $this->ctxFetchCapReachedThisTick++;
                    if (count($this->ctxFetchCapExamples) < 5) {
                        $this->ctxFetchCapExamples[] = [
                            'symbol'         => $symbol,
                            'reason'         => 'entry_context_fetch_cap_reached',
                            'fetch_budget'   => $maxPerTick,
                            'fetch_used'     => $this->ctxFetchAttemptedThisTick,
                            'action'         => 'skipped_cap',
                        ];
                    }
                } else {
                    $this->ctxFetchAttemptedThisTick++;
                    $fetched = $this->fetchEntryContextCandles($symbol, $config);
                    if (count($fetched) >= 5) {
                        $ctxCandles        = $fetched;
                        $entryCtxAvailable = true;
                        $this->ctxFetchSuccessThisTick++;
                    } else {
                        $this->ctxFetchFailedThisTick++;
                    }
                }
            } else {
                $entryCtxSkipReason = 'skipped_entry_context_due_prefilter';
                $this->ctxFetchSkippedPrefilterThisTick++;
            }
        } else {
            // Lazy fetch disabled: always fetch ctx candles.
            $pref = ['should_fetch' => true, 'prefilter_score' => 0.0, 'prefilter_reasons' => [], 'prefilter_reject_reason' => null];
            $this->ctxFetchAttemptedThisTick++;
            $fetched = $this->fetchEntryContextCandles($symbol, $config);
            if (count($fetched) >= 5) {
                $ctxCandles        = $fetched;
                $entryCtxAvailable = true;
                $this->ctxFetchSuccessThisTick++;
            } else {
                $this->ctxFetchFailedThisTick++;
            }
        }

        // ── Coin trend context ────────────────────────────────────────────────
        // Runs on $ctxCandles (1m/5m when fetched) or falls back to H4 when empty.
        $coinCtx = $this->pipelineCoinTrendContext($candles, $ctxCandles, $config);

        // Normalise entry-context fetch outcome into a status / reason pair.
        if ($entryCtxAvailable) {
            $entryCtxStatus = 'pass';
            $entryCtxNormReason = null;
        } elseif ($entryCtxSkipReason === 'skipped_entry_context_due_prefilter') {
            $entryCtxStatus = 'skipped';
            $entryCtxNormReason = 'skipped_entry_context_due_prefilter';
        } elseif ($entryCtxSkipReason !== null) {
            // fetch_limit_reached or other skip
            $entryCtxStatus = 'skipped';
            $entryCtxNormReason = $entryCtxSkipReason;
        } else {
            // fetch was attempted but returned too few candles
            $entryCtxStatus = 'unavailable';
            $entryCtxNormReason = 'entry_context_unavailable';
        }

        $diagBase = [
            'market_regime'                  => $regimeStr,
            'trend_direction'                => $trendDir,
            'corridor_low'                   => $corridor['corridor_low'],
            'corridor_high'                  => $corridor['corridor_high'],
            'current_bucket'                 => $corridor['current_bucket'],
            'bucket_allowed_long'            => $corridor['bucket_allowed_long'],
            'wave_direction'                 => $wave['wave_direction'],
            'wave_state'                     => $wave['wave_state'],
            // Coin trend context fields (from pipelineCoinTrendContext)
            'coin_trend_context_status'      => $coinCtx['coin_trend_context_status'],
            'coin_trend_context_score'       => $coinCtx['coin_trend_context_score'],
            'coin_trend_context_reason'      => $coinCtx['coin_trend_context_reason'],
            'short_trend_slope_pct'          => $coinCtx['short_trend_slope_pct'],
            'mid_trend_slope_pct'            => $coinCtx['mid_trend_slope_pct'],
            'long_trend_slope_pct'           => $coinCtx['long_trend_slope_pct'],
            'recent_lower_low_count'         => $coinCtx['recent_lower_low_count'],
            'recent_lower_high_count'        => $coinCtx['recent_lower_high_count'],
            'recent_dump_15m_pct'            => $coinCtx['recent_dump_15m_pct'],
            'recent_dump_1h_pct'             => $coinCtx['recent_dump_1h_pct'],
            'distance_from_recent_high_pct'  => $coinCtx['distance_from_recent_high_pct'],
            'distance_from_recent_low_pct'   => $coinCtx['distance_from_recent_low_pct'],
            'active_downtrend_detected'      => $coinCtx['active_downtrend_detected'],
            'active_falling_knife_detected'  => $coinCtx['active_falling_knife_detected'],
            'post_dump_detected'             => $coinCtx['post_dump_detected'],
            'post_dump_drop_pct'             => $coinCtx['post_dump_drop_pct'],
            'stabilization_detected'         => $coinCtx['stabilization_detected'],
            'stabilization_bars'             => $coinCtx['stabilization_bars'],
            'stabilization_range_width_pct'  => $coinCtx['stabilization_range_width_pct'],
            'stabilization_slope_pct'        => $coinCtx['stabilization_slope_pct'],
            'stabilization_score'            => $coinCtx['stabilization_score'],
            'flat_base_detected'             => $coinCtx['flat_base_detected'],
            'flat_base_low'                  => $coinCtx['flat_base_low'],
            'flat_base_high'                 => $coinCtx['flat_base_high'],
            'flat_base_width_pct'            => $coinCtx['flat_base_width_pct'],
            'flat_base_touches'              => $coinCtx['flat_base_touches'],
            'flat_base_score'                => $coinCtx['flat_base_score'],
            'reclaim_after_flat_detected'    => $coinCtx['reclaim_after_flat_detected'],
            'reclaim_level'                  => $coinCtx['reclaim_level'],
            'reclaim_confirmed_bars'         => $coinCtx['reclaim_confirmed_bars'],
            'reclaim_strength_pct'           => $coinCtx['reclaim_strength_pct'],
            'reclaim_score'                  => $coinCtx['reclaim_score'],
            'entry_distance_from_reclaim_pct' => $coinCtx['entry_distance_from_reclaim_pct'],
            // Support / resistance
            'support_level'                  => $coinCtx['support_level'],
            'resistance_level'               => $coinCtx['resistance_level'],
            'neckline_level'                 => $coinCtx['neckline_level'],
            'base_low'                       => $coinCtx['base_low'],
            'base_high'                      => $coinCtx['base_high'],
            'base_width_pct'                 => $coinCtx['base_width_pct'],
            'support_touches'                => $coinCtx['support_touches'],
            'resistance_touches'             => $coinCtx['resistance_touches'],
            'support_resistance_score'       => $coinCtx['support_resistance_score'],
            'support_broken'                 => $coinCtx['support_broken'],
            'setup_context_type'             => $coinCtx['setup_context_type'],
            'bearish_reversal_exception_used'=> false,  // set by tryLong() if used
            'reversal_context_score'         => $coinCtx['reversal_context_score'],
            'entry_context_score'            => $coinCtx['entry_context_score'],
            // Entry context candle diagnostics
            'ctx_interval'                   => $coinCtx['ctx_interval'],
            'ctx_candles_count'              => $coinCtx['ctx_candles_count'],
            // Entry context availability (lazy-fetch decision result)
            'entry_context_available'        => $entryCtxAvailable,
            'entry_context_skip_reason'      => $entryCtxSkipReason,
            // Normalised entry-context status (pass / skipped / unavailable)
            'entry_context_status'           => $entryCtxStatus,
            'entry_context_reason'           => $entryCtxNormReason,
            // Intraday double-bottom detection
            'intraday_double_bottom_detected'       => $coinCtx['intraday_double_bottom_detected'],
            'bottom_1_price'                        => $coinCtx['bottom_1_price'],
            'bottom_1_index'                        => $coinCtx['bottom_1_index'],
            'bottom_2_price'                        => $coinCtx['bottom_2_price'],
            'bottom_2_index'                        => $coinCtx['bottom_2_index'],
            'bottom_low_diff_pct'                   => $coinCtx['bottom_low_diff_pct'],
            'second_low_break_pct'                  => $coinCtx['second_low_break_pct'],
            'intraday_db_neckline_level'            => $coinCtx['intraday_db_neckline_level'],
            'neckline_bounce_pct'                   => $coinCtx['neckline_bounce_pct'],
            'neckline_reclaim_confirmed'            => $coinCtx['neckline_reclaim_confirmed'],
            'neckline_reclaim_confirm_bars'         => $coinCtx['neckline_reclaim_confirm_bars'],
            'entry_distance_from_neckline_pct'      => $coinCtx['entry_distance_from_neckline_pct'],
            'intraday_double_bottom_score'          => $coinCtx['intraday_double_bottom_score'],
            'intraday_double_bottom_reason'         => $coinCtx['intraday_double_bottom_reason'],
            // Setup class
            'setup_class'          => $coinCtx['setup_class'],
            'setup_class_score'    => $coinCtx['setup_class_score'],
            'setup_class_reason'   => $coinCtx['setup_class_reason'],
            'setup_class_warnings' => $coinCtx['setup_class_warnings'],
            'setup_signal_allowed' => $coinCtx['setup_signal_allowed'],
            // Entry-context prefilter diagnostics
            'entry_context_prefilter_score'         => $pref['prefilter_score'],
            'entry_context_prefilter_reasons'       => $pref['prefilter_reasons'],
            'entry_context_prefilter_reject_reason' => $pref['prefilter_reject_reason'],
        ];

        // Carry current price and 24h change for scan suppression early-expire checks.
        $diagBase['scan_suppression_last_price']       = $lastClose > 0.0 ? $lastClose : null;
        $diagBase['scan_suppression_daily_change_pct'] = $dailyChangePct;

        // ── Garbage veto context — computed here for propagation into signal ──
        // daily_change_pct: from H4 (6 H4 bars = 24h)
        $diagBase['daily_change_pct'] = $dailyChangePct;
        // position_in_24h_range_pct: where is current price within the 24h corridor
        $corrLow  = (float)($corridor['corridor_low']  ?? 0.0);
        $corrHigh = (float)($corridor['corridor_high'] ?? 0.0);
        if ($corrHigh > $corrLow && $corrLow > 0.0) {
            $diagBase['position_in_24h_range_pct'] = round(
                (($lastClose - $corrLow) / ($corrHigh - $corrLow)) * 100.0, 2
            );
            $diagBase['room_to_24h_high_roi'] = round(
                (($corrHigh - $lastClose) / $lastClose) * 100.0, 2
            );
        } else {
            $diagBase['position_in_24h_range_pct'] = null;
            $diagBase['room_to_24h_high_roi']       = null;
        }
        // Whipsaw metrics from entry-context candles (1m/5m) when available.
        // Used by garbage veto 4 (weak quality + whipsaw).
        if ($entryCtxAvailable && count($ctxCandles) >= 10) {
            $whipsaw = $this->computeWhipsawMetrics($ctxCandles);
        } else {
            $whipsaw = [
                'recent_10m_range_roi'      => null,
                'recent_60m_range_roi'      => null,
                'recent_10m_direction_flips' => null,
                'recent_60m_direction_flips' => null,
                'whipsaw_score'              => null,
            ];
        }
        $diagBase['recent_10m_range_roi']      = $whipsaw['recent_10m_range_roi'];
        $diagBase['recent_60m_range_roi']      = $whipsaw['recent_60m_range_roi'];
        $diagBase['recent_10m_direction_flips'] = $whipsaw['recent_10m_direction_flips'];
        $diagBase['recent_60m_direction_flips'] = $whipsaw['recent_60m_direction_flips'];
        $diagBase['whipsaw_score']              = $whipsaw['whipsaw_score'];
        // Entry hour for time-of-day diagnostics
        $nowHourUtc   = (int)date('G');
        $diagBase['entry_hour_utc']        = $nowHourUtc;
        $diagBase['entry_hour_local_utc3'] = ($nowHourUtc + 3) % 24;
        if ($nowHourUtc >= 6 && $nowHourUtc < 14) {
            $diagBase['session_bucket'] = 'day';
        } elseif ($nowHourUtc >= 14 && $nowHourUtc < 22) {
            $diagBase['session_bucket'] = 'evening';
        } else {
            $diagBase['session_bucket'] = 'night';
        }

        // Local late-entry diagnostics for DBL recovery leg (diagnostic + veto input).
        $point3Price  = (float)($coinCtx['bottom_2_price'] ?? 0.0);
        $reclaimLevel = (float)($coinCtx['reclaim_level'] ?? $coinCtx['intraday_db_neckline_level'] ?? $coinCtx['neckline_level'] ?? 0.0);
        $diagBase['entry_distance_from_point3_pct'] = ($point3Price > 0.0 && $lastClose > 0.0)
            ? round((($lastClose - $point3Price) / $point3Price) * 100.0, 4)
            : null;
        if (($diagBase['entry_distance_from_reclaim_pct'] ?? null) === null) {
            $diagBase['entry_distance_from_reclaim_pct'] = ($reclaimLevel > 0.0 && $lastClose > 0.0)
                ? round((($lastClose - $reclaimLevel) / $reclaimLevel) * 100.0, 4)
                : null;
        }
        $diagBase['local_recovery_leg_low_price']       = null;
        $diagBase['local_recovery_leg_high_price']      = null;
        $diagBase['post_point3_bounce_roi']             = null;
        $diagBase['position_in_local_recovery_leg_pct'] = null;
        $diagBase['post_point3_impulse_spent_pct']      = null;
        $diagBase['recent_swing_high_price']            = null;
        $diagBase['recent_swing_high_time']             = null;
        $diagBase['room_to_recent_swing_high_roi']      = null;
        $diagBase['near_recent_swing_high']             = null;
        if ($entryCtxAvailable && !empty($ctxCandles) && $point3Price > 0.0) {
            $p3Idx = (int)($coinCtx['bottom_2_index'] ?? (count($ctxCandles) - 1));
            $p3Idx = max(0, min($p3Idx, count($ctxCandles) - 1));
            $fromP3 = array_slice($ctxCandles, $p3Idx);
            if (!empty($fromP3)) {
                $localLow  = (float)min(array_column($fromP3, 'low'));
                $localHigh = (float)max(array_column($fromP3, 'high'));
                $diagBase['local_recovery_leg_low_price']  = $localLow > 0.0 ? round($localLow, 8) : null;
                $diagBase['local_recovery_leg_high_price'] = $localHigh > 0.0 ? round($localHigh, 8) : null;
                if ($localHigh > $point3Price) {
                    $diagBase['post_point3_bounce_roi'] = round((($localHigh - $point3Price) / $point3Price) * 100.0, 4);
                    $diagBase['post_point3_impulse_spent_pct'] = round(
                        (($lastClose - $point3Price) / ($localHigh - $point3Price)) * 100.0,
                        3
                    );
                }
                if ($localHigh > $localLow && $lastClose > 0.0) {
                    $diagBase['position_in_local_recovery_leg_pct'] = round(
                        (($lastClose - $localLow) / ($localHigh - $localLow)) * 100.0,
                        3
                    );
                }
            }
            $recentWindow = array_slice($ctxCandles, -60);
            if (!empty($recentWindow)) {
                $swingHigh = (float)max(array_column($recentWindow, 'high'));
                $diagBase['recent_swing_high_price'] = $swingHigh > 0.0 ? round($swingHigh, 8) : null;
                foreach (array_reverse($recentWindow) as $bar) {
                    if ((float)($bar['high'] ?? 0.0) === $swingHigh) {
                        $ts = (int)($bar['ts'] ?? 0);
                        $diagBase['recent_swing_high_time'] = $ts > 0 ? date('c', (int)floor($ts / 1000)) : null;
                        break;
                    }
                }
                if ($swingHigh > 0.0 && $lastClose > 0.0) {
                    $roomRoi = (($swingHigh - $lastClose) / $lastClose) * 100.0;
                    $diagBase['room_to_recent_swing_high_roi'] = round($roomRoi, 4);
                    $nearPct = (float)($config['dbl_garbage_near_recent_swing_high_pct'] ?? 0.35);
                    $diagBase['near_recent_swing_high'] = $roomRoi <= $nearPct;
                }
            }
        }

        // ── Precompute entry_setup_allowed (A/B intraday allowance) ──────────
        // Evaluated after entry context / setup class classification but BEFORE
        // tryLong() so the result can be passed into tryLong() as a gate override.
        // When entry_setup_allowed=true the old regime/trend/corridor/wave hard
        // rejects are downgraded to warnings inside tryLong().
        $entrySetupRes = $this->computeEntrySetupAllowed($diagBase, $config);
        $diagBase['entry_setup_allowed']           = $entrySetupRes['entry_setup_allowed'];
        $diagBase['entry_setup_class']             = $entrySetupRes['entry_setup_class'];
        $diagBase['entry_setup_reason']            = $entrySetupRes['entry_setup_reason'];
        $diagBase['entry_setup_score']             = $entrySetupRes['entry_setup_score'];
        $diagBase['entry_setup_blocked_by_safety'] = $entrySetupRes['blocked_by_safety'];
        $diagBase['entry_setup_block_reason']      = $entrySetupRes['block_reason'];
        // Fields updated by tryLong(); initialised here so no_signal return can
        // safely pick them up from $longResult without needing a separate default path.
        $diagBase['old_market_regime_warning']          = null;
        $diagBase['old_trend_warning']                  = null;
        $diagBase['old_corridor_warning']               = null;
        $diagBase['old_wave_warning']                   = null;
        $diagBase['old_h4_gate_warning_reasons']        = [];
        $diagBase['entry_setup_old_h4_gates_bypassed']  = false;
        $diagBase['synthetic_candidate_built']          = false;
        $diagBase['synthetic_candidate_source']         = null;

        $longResult = $this->tryLong($symbol, $candles, $config, $regimeStr, $trendDir, $corridor, $wave, $diagBase);
        if ($longResult['final_signal_status'] === 'emitted') {
            return $longResult;
        }

        $longRej = $longResult['reject_reason'] ?? null;

        return array_merge($diagBase, [
            'symbol'                 => $symbol,
            'candidate_found'        => false,
            'candidate_side'         => 'long',
            'primary_pattern'        => null,
            'double_bottom_checked'  => $longResult['double_bottom_checked']  ?? false,
            'neckline_value'         => $longResult['neckline_value']         ?? null,
            'low1_value'             => $longResult['low1_value']             ?? null,
            'low2_value'             => $longResult['low2_value']             ?? null,
            'pattern_window_size'    => $longResult['pattern_window_size']    ?? null,
            'similarity_delta_pct'   => $longResult['similarity_delta_pct']  ?? null,
            'pattern_score'           => null,
            'structure_score'         => null,
            'neckline_score'          => null,
            'confirmation_score'      => null,
            'context_score'           => null,
            'candidate_quality_score' => null,
            'quality_pass'            => null,
            'quality_reject_reason'   => $longResult['quality_reject_reason'] ?? null,
            'quality_source'          => $longResult['quality_source']         ?? null,
            'confirm_status'         => null,
            'confirm_bars_waited'    => 0,
            'candidate_expired'      => false,
            // Forward setup funnel fields so accumulateStats() can track per-stage counts.
            'setup_allowed'                     => $longResult['setup_allowed']                     ?? false,
            'setup_allowed_kill_stage'          => $longResult['setup_allowed_kill_stage']          ?? null,
            'setup_allowed_kill_reason'         => $longResult['setup_allowed_kill_reason']         ?? null,
            // Forward tryLong-modified diagnostic fields (not in processSymbol's diagBase copy).
            'old_market_regime_warning'         => $longResult['old_market_regime_warning']         ?? null,
            'old_trend_warning'                 => $longResult['old_trend_warning']                 ?? null,
            'old_corridor_warning'              => $longResult['old_corridor_warning']              ?? null,
            'old_wave_warning'                  => $longResult['old_wave_warning']                  ?? null,
            'old_h4_gate_warning_reasons'       => $longResult['old_h4_gate_warning_reasons']       ?? [],
            'entry_setup_old_h4_gates_bypassed' => $longResult['entry_setup_old_h4_gates_bypassed'] ?? false,
            'synthetic_candidate_built'         => $longResult['synthetic_candidate_built']         ?? false,
            'synthetic_candidate_source'        => $longResult['synthetic_candidate_source']        ?? null,
            // Forward synthetic quality diagnostic fields so accumulateStats() can count them.
            'synthetic_quality_checked'         => $longResult['synthetic_quality_checked']         ?? false,
            'synthetic_quality_pass'            => $longResult['synthetic_quality_pass']            ?? null,
            'synthetic_quality_score'           => $longResult['synthetic_quality_score']           ?? null,
            'synthetic_quality_reason'          => $longResult['synthetic_quality_reason']          ?? null,
            'synthetic_quality_block_reasons'   => $longResult['synthetic_quality_block_reasons']   ?? [],
            'synthetic_quality_components'      => $longResult['synthetic_quality_components']      ?? [],
            'synthetic_quality_warnings'        => $longResult['synthetic_quality_warnings']        ?? [],
            'synthetic_quality_soft_warnings'   => $longResult['synthetic_quality_soft_warnings']   ?? [],
            'synthetic_quality_is_intraday_db'  => $longResult['synthetic_quality_is_intraday_db']  ?? false,
            'final_signal_status'    => 'no_signal',
            'reject_reason'          => $longRej ?? 'no_valid_candidate',
            'signal'                 => null,
        ]);
    }

    private function tryLong(string $symbol, array $candles, array $config,
        string $regimeStr, string $trendDir, array $corridor, array $wave, array $diagBase): array
    {
        $side = 'long';

        // Whether this symbol was pre-qualified as an A/B intraday setup.
        // When true, old regime/trend/corridor/wave failures become warnings so
        // the symbol can enter the setup_allowed funnel.
        $entrySetupAllowed = (bool)($diagBase['entry_setup_allowed'] ?? false);
        $oldGateWarnings   = [];

        // ── Active falling knife: hard reject before anything else ────────────
        // This block stays hard even when entry_setup_allowed=true.
        if ((bool)($config['coin_trend_context_enabled'] ?? true)
            && (bool)($config['active_downtrend_block_enabled'] ?? true)
            && (bool)($diagBase['active_falling_knife_detected'] ?? false)
        ) {
            return $this->reject($diagBase, $symbol, 'double_bottom', 'active_falling_knife');
        }

        // ── Market regime gate ────────────────────────────────────────────────
        // Bearish regime is hard-blocked by default.
        // Exception 1: bearish_reversal_exception (classic post-dump stabilization path).
        // Exception 2: entry_setup_allowed=true (A/B intraday setup confirmed), in which
        //   case the regime failure is recorded as a warning and bearishRevExUsed is set.
        $bearishRevExUsed = false;
        if ((bool)($config['market_regime_enabled'] ?? true)) {
            $regimeGateMode = (string)($config['market_regime_gate_mode'] ?? 'soft');
            if ($regimeGateMode === 'hard') {
                $this->requireLogic('market_regime');
                $rGate = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternMarketRegime())
                    ->gate($regimeStr, $side, $regimeGateMode);
                if (!$rGate['pass']) {
                    if ($entrySetupAllowed) {
                        // Downgrade to warning; set reversal exception flag so
                        // the downstream trend gate is also skipped correctly.
                        $oldGateWarnings[] = $rGate['reason'];
                        $diagBase['old_market_regime_warning'] = $rGate['reason'];
                        if ($regimeStr === 'bearish') {
                            $bearishRevExUsed = true;
                            $diagBase['bearish_reversal_exception_used'] = true;
                        }
                    } else {
                        // Original path: check bearish reversal exception
                        $exceptionAllowed = false;
                        if ((bool)($config['bearish_reversal_exception_enabled'] ?? true)
                            && !(bool)($diagBase['active_falling_knife_detected'] ?? false)
                        ) {
                            $requireStab  = (bool)($config['bearish_reversal_requires_post_dump_stabilization'] ?? true);
                            $requireFlat  = (bool)($config['bearish_reversal_requires_flat_base'] ?? true);
                            $requireReclaim = (bool)($config['bearish_reversal_requires_reclaim'] ?? true);
                            $maxLowerLows = (int)($config['max_recent_lower_low_count'] ?? 2);
                            $minRevScore  = (float)($config['min_reversal_context_score'] ?? 7.0);

                            $stabOk   = !$requireStab  || (bool)($diagBase['stabilization_detected'] ?? false);
                            $flatOk   = !$requireFlat  || (bool)($diagBase['flat_base_detected']     ?? false);
                            $reclOk   = !$requireReclaim || (bool)($diagBase['reclaim_after_flat_detected'] ?? false);
                            $llOk     = (int)($diagBase['recent_lower_low_count'] ?? 999) <= $maxLowerLows;
                            $revScore = (float)($diagBase['reversal_context_score'] ?? 0.0);
                            $scoreOk  = $revScore >= $minRevScore;

                            if ($stabOk && $flatOk && $reclOk && $llOk && $scoreOk) {
                                $exceptionAllowed = true;
                                $bearishRevExUsed = true;
                            }
                        }
                        if (!$exceptionAllowed) {
                            // Resolve the most specific failure reason (regime alone is a fallback).
                            $specificReason = $this->resolveDoubleBottomRejectReason($diagBase, $config);
                            $diagBase['primary_reject_reason']    = $specificReason;
                            $diagBase['secondary_reject_reasons'] = $specificReason !== $rGate['reason']
                                ? [$rGate['reason']]
                                : [];
                            $diagBase['reason_codes'] = array_values(array_unique(
                                [$specificReason, $rGate['reason']]
                            ));
                            $diagBase['failed_stage'] = $this->failedStageForReason($specificReason);
                            return $this->reject($diagBase, $symbol, 'double_bottom', $specificReason);
                        }
                    }
                }
            }
        }

        // Update diagBase with bearish_reversal_exception_used flag
        $diagBase['bearish_reversal_exception_used'] = $bearishRevExUsed;
        if ($bearishRevExUsed && !$entrySetupAllowed) {
            $diagBase['setup_context_type'] = 'post_dump_flat_reversal';
        }

        // ── Trend gate ────────────────────────────────────────────────────────
        if ((bool)($config['trend_required'] ?? true)) {
            $this->requireLogic('trend');
            // When trend_long_require_bullish is true (default), only emit long signals
            // when the short-term trend is already turning bullish.
            // Exception: skip bullish requirement when bearish_reversal_exception_used=true.
            $requireBullish = (bool)($config['trend_long_require_bullish'] ?? true);
            if ($requireBullish && !$bearishRevExUsed) {
                if ($trendDir !== 'bullish') {
                    if ($entrySetupAllowed) {
                        $oldGateWarnings[] = "trend_{$trendDir}_side_long_mismatch";
                        $diagBase['old_trend_warning'] = "trend_{$trendDir}_side_long_mismatch";
                    } else {
                        return $this->reject($diagBase, $symbol, 'double_bottom',
                            "trend_{$trendDir}_side_long_mismatch");
                    }
                }
            } elseif (!$bearishRevExUsed) {
                $tGate = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternTrend())->gate($trendDir, $side);
                if (!$tGate['pass']) {
                    if ($entrySetupAllowed) {
                        $oldGateWarnings[] = $tGate['reason'];
                        $diagBase['old_trend_warning'] = $tGate['reason'];
                    } else {
                        return $this->reject($diagBase, $symbol, 'double_bottom', $tGate['reason']);
                    }
                }
            }
            // When bearish_reversal_exception_used=true, trend gate is bypassed.
        }

        if ((bool)($config['corridor_required'] ?? true)) {
            $this->requireLogic('corridor');
            $cGate = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternCorridor())->gate($corridor, $side);
            if (!$cGate['pass']) {
                if ($entrySetupAllowed) {
                    $oldGateWarnings[] = $cGate['reason'];
                    $diagBase['old_corridor_warning'] = $cGate['reason'];
                } else {
                    return $this->reject($diagBase, $symbol, 'double_bottom', $cGate['reason']);
                }
            }
        }

        if ((bool)($config['wave_required'] ?? true)) {
            $this->requireLogic('wave');
            $wGate = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternWave())->gate($wave, $side);
            if (!$wGate['pass']) {
                if ($entrySetupAllowed) {
                    $oldGateWarnings[] = $wGate['reason'];
                    $diagBase['old_wave_warning'] = $wGate['reason'];
                } else {
                    return $this->reject($diagBase, $symbol, 'double_bottom', $wGate['reason']);
                }
            }
        }

        // Record old-gate warning diagnostics.
        $diagBase['old_h4_gate_warning_reasons']       = $oldGateWarnings;
        $diagBase['entry_setup_old_h4_gates_bypassed'] = $entrySetupAllowed && count($oldGateWarnings) > 0;

        // ── Entry context safety gate ─────────────────────────────────────────
        // Block signal emission when entry-context candles were not fetched.
        // The coin_trend_context pipeline ran on H4 fallback only in this case,
        // and its dump/stab/flat/reclaim results cannot be trusted for decisions.
        // This remains a hard reject even for entry_setup_allowed=true.
        if (!(bool)($diagBase['entry_context_available'] ?? true)) {
            return $this->reject(
                $diagBase, $symbol, 'double_bottom',
                (string)($diagBase['entry_context_skip_reason'] ?? 'entry_context_unavailable')
            );
        }

        // ── Setup class gate ──────────────────────────────────────────────────
        // Only A/B classes (classic_intraday_double_bottom_reclaim, post_dump_base_reclaim)
        // may emit signals. C (diagnostic_recovery_context) is diagnostics only.
        // When entry_setup_allowed=true the class check was already satisfied by
        // computeEntrySetupAllowed(); we skip the gate and stamp directly.
        if ((bool)($config['intraday_setup_classification_enabled'] ?? true)) {
            if (!$entrySetupAllowed) {
                $setupClass       = (string)($diagBase['setup_class']          ?? 'none');
                $setupSigAllowed  = (bool)($diagBase['setup_signal_allowed']   ?? false);
                $allowedClasses   = (array)($config['setup_class_handoff_allowed'] ?? ['classic_intraday_double_bottom_reclaim', 'post_dump_base_reclaim']);
                if (!$setupSigAllowed || !in_array($setupClass, $allowedClasses, true)) {
                    $specificReason = $this->resolveDoubleBottomRejectReason($diagBase, $config);
                    return $this->reject($diagBase, $symbol, 'double_bottom', $specificReason);
                }
            }
        }

        // Setup class gate passed (either directly or via entry_setup_allowed) — stamp for funnel diagnostics.
        $diagBase['setup_allowed']            = true;
        $diagBase['setup_allowed_kill_stage']  = null;
        $diagBase['setup_allowed_kill_reason'] = null;
        if ($entrySetupAllowed) {
            $diagBase['setup_context_type']   = 'entry_setup_allowed';
            $diagBase['setup_allowed_source'] = 'setup_class';
            $diagBase['setup_allowed_reason'] = $diagBase['entry_setup_reason'] ?? null;
        }

        $this->requireLogic('double_bottom');
        $candidate = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternDoubleBottom())->detect($candles, $config);
        if (!$candidate['candidate_found']) {
            // A-class bypass: intraday double-bottom + neckline reclaim already confirmed;
            // skip H4 PatternDoubleBottom unless require_h4_double_bottom_after_intraday_setup=true.
            $requireH4ForIntraday = (bool)($config['require_h4_double_bottom_after_intraday_setup'] ?? false);
            // B-class bypass: post-dump base reclaim may skip H4 pattern when explicitly allowed.
            $allowWithoutClassic = (bool)($config['allow_post_dump_base_reclaim_without_classic_double_bottom'] ?? true);
            $currentSetupClass   = (string)($diagBase['setup_class'] ?? 'none');

            $skipH4 = (!$requireH4ForIntraday && $currentSetupClass === 'classic_intraday_double_bottom_reclaim')
                   || ($allowWithoutClassic      && $currentSetupClass === 'post_dump_base_reclaim');

            if ($skipH4) {
                // Build a synthetic candidate from intraday fields so the quality/control
                // code has real values instead of nulls, which avoids spurious quality failures.
                $synth = $this->buildSyntheticCandidate($diagBase, $currentSetupClass);
                $candidate = $synth;
                $diagBase['synthetic_candidate_built'] = true;
                $diagBase['synthetic_candidate_source'] = $synth['candidate_source'] ?? $currentSetupClass;
            } else {
                // Classic-pattern kill: stamp kill stage before rejecting.
                $diagBase['setup_allowed_kill_stage']  = 'classic_pattern';
                $diagBase['setup_allowed_kill_reason'] = 'setup_allowed_but_classic_pattern_failed';
                return $this->reject($diagBase, $symbol, 'double_bottom', $candidate['reject_reason'] ?? 'no_double_bottom', true, [
                    'neckline_value'       => $candidate['neckline']              ?? 0.0,
                    'low1_value'           => $candidate['low1_price']            ?? 0.0,
                    'low2_value'           => $candidate['low2_price']            ?? 0.0,
                    'pattern_window_size'  => $candidate['window_size']           ?? 0,
                    'similarity_delta_pct' => $candidate['similarity_delta_pct'] ?? 0.0,
                ]);
            }
        }

        // ── Quality check ─────────────────────────────────────────────────────
        // For synthetic A/B candidates (H4 PatternDoubleBottom bypassed) use the
        // dedicated synthetic quality scorer instead of the old H4 quality scorer.
        // The old scorer rates structure from similarity_delta_pct / H4 neckline depth
        // which is meaningless for intraday-derived candidates and produces spurious
        // quality_weak_structure failures.
        $isSynthetic = (bool)($diagBase['synthetic_candidate_built'] ?? false);
        if ($isSynthetic && (bool)($config['synthetic_setup_quality_enabled'] ?? true)) {
            $synQuality = $this->scoreSyntheticSetupQuality($diagBase, $candidate, $config);
            if (!$synQuality['synthetic_quality_pass']) {
                $diagBase['setup_allowed_kill_stage']  = 'quality';
                $diagBase['setup_allowed_kill_reason'] = 'synthetic_candidate_quality_failed';
                $diagBase['synthetic_quality_checked'] = true;
                $diagBase['synthetic_quality_pass']    = false;
                $diagBase['synthetic_quality_score']   = $synQuality['synthetic_quality_score'];
                $diagBase['synthetic_quality_reason']  = $synQuality['synthetic_quality_reason'];
                $diagBase['synthetic_quality_block_reasons'] = $synQuality['synthetic_quality_block_reasons'];
                $diagBase['synthetic_quality_components']    = $synQuality['synthetic_quality_components'];
                $diagBase['synthetic_quality_warnings']      = $synQuality['synthetic_quality_warnings'] ?? [];
                $diagBase['synthetic_quality_soft_warnings'] = $synQuality['synthetic_quality_soft_warnings'] ?? [];
                $diagBase['synthetic_quality_is_intraday_db'] = $synQuality['synthetic_quality_is_intraday_db'] ?? false;
                $diagBase['quality_source']            = 'synthetic_intraday_setup';
                return array_merge($diagBase, [
                    'symbol'                  => $symbol,
                    'candidate_found'         => true,
                    'candidate_side'          => $side,
                    'primary_pattern'         => 'double_bottom',
                    'double_bottom_checked'   => true,
                    'neckline_value'          => $candidate['neckline']              ?? 0.0,
                    'low1_value'              => $candidate['low1_price']            ?? 0.0,
                    'low2_value'              => $candidate['low2_price']            ?? 0.0,
                    'pattern_window_size'     => $candidate['window_size']           ?? 0,
                    'similarity_delta_pct'    => $candidate['similarity_delta_pct'] ?? 0.0,
                    'pattern_score'           => $synQuality['synthetic_quality_score'],
                    'structure_score'         => 0.0,
                    'neckline_score'          => 0.0,
                    'confirmation_score'      => 0.0,
                    'context_score'           => 0.0,
                    'candidate_quality_score' => $synQuality['synthetic_quality_score'],
                    'quality_pass'            => false,
                    'quality_reject_reason'   => 'synthetic_candidate_quality_failed',
                    'quality_source'          => 'synthetic_intraday_setup',
                    'signal_quality_class'    => $bearishRevExUsed ? 'controlled_reversal' : 'clean_signal',
                    'confirm_status'          => null,
                    'confirm_bars_waited'     => 0,
                    'candidate_expired'       => false,
                    'final_signal_status'     => 'rejected',
                    'reject_reason'           => 'synthetic_candidate_quality_failed',
                    'signal'                  => null,
                ]);
            }
            // Synthetic quality passed — build a normalised $quality array that the
            // downstream signal builder and return blocks expect.
            $quality = [
                'pattern_score'           => $synQuality['synthetic_quality_score'],
                'structure_score'         => min(1.0, $synQuality['synthetic_quality_score']),
                'neckline_score'          => min(1.0, $synQuality['synthetic_quality_score']),
                'confirmation_score'      => min(1.0, $synQuality['synthetic_quality_score']),
                'context_score'           => min(1.0, $synQuality['synthetic_quality_score']),
                'candidate_quality_score' => $synQuality['synthetic_quality_score'],
                'quality_pass'            => true,
                'quality_reject_reason'   => null,
            ];
            $diagBase['quality_source']            = 'synthetic_intraday_setup';
            $diagBase['synthetic_quality_checked'] = true;
            $diagBase['synthetic_quality_pass']    = true;
            $diagBase['synthetic_quality_score']   = $synQuality['synthetic_quality_score'];
            $diagBase['synthetic_quality_reason']  = $synQuality['synthetic_quality_reason'];
            $diagBase['synthetic_quality_block_reasons'] = [];
            $diagBase['synthetic_quality_components']    = $synQuality['synthetic_quality_components'];
            $diagBase['synthetic_quality_warnings']      = $synQuality['synthetic_quality_warnings'] ?? [];
            $diagBase['synthetic_quality_soft_warnings'] = $synQuality['synthetic_quality_soft_warnings'] ?? [];
            $diagBase['synthetic_quality_is_intraday_db'] = $synQuality['synthetic_quality_is_intraday_db'] ?? false;

            // late_good_setup detection (Task 4):
            // Setup is high-quality but entry is in the soft-distance zone beyond the hard cap.
            // For GENIUS-like setups: do not emit immediate market signal; create pending for better entry.
            $isLateGoodSetup = (bool)($synQuality['late_good_setup'] ?? false);
            $diagBase['late_good_setup']                  = $isLateGoodSetup;
            $diagBase['missed_ideal_entry']               = (bool)($synQuality['missed_ideal_entry'] ?? false);
            $diagBase['waiting_for_better_entry_distance'] = (bool)($synQuality['waiting_for_better_entry_distance'] ?? false);
            if ($isLateGoodSetup) {
                // Store as pending-better-entry if enabled.
                $lateGoodPendingEnabled = (bool)($config['synthetic_quality_intraday_db_borderline_distance_pending_enabled'] ?? true);
                if ($lateGoodPendingEnabled && (bool)($config['pending_confirmation_enabled'] ?? true)) {
                    $ttlMin    = max(1, (int)($config['pending_confirmation_ttl_minutes'] ?? 15));
                    $expiresAt = date('c', time() + $ttlMin * 60);
                    $this->writePendingConfirmation([
                        'symbol'                              => $symbol,
                        'side'                                => $side,
                        'mode'                                => $config['mode'] ?? 'demo',
                        'setup_class'                         => $diagBase['setup_class'] ?? null,
                        'neckline_level'                      => $candidate['neckline'] ?? null,
                        'candidate_trigger'                   => $candidate['candidate_trigger'] ?? $candidate['neckline'] ?? null,
                        'low1_price'                          => $candidate['low1_price'] ?? null,
                        'low2_price'                          => $candidate['low2_price'] ?? null,
                        'reclaim_level'                       => $diagBase['reclaim_level'] ?? null,
                        'created_at'                          => date('c'),
                        'expires_at'                          => $expiresAt,
                        'confirm_bars_required'               => $config['reclaim_confirm_bars'] ?? $config['double_bottom_reclaim_confirm_bars'] ?? 2,
                        'confirm_bars_seen'                   => 0,
                        'entry_distance_from_neckline_pct'    => $diagBase['entry_distance_from_neckline_pct'] ?? null,
                        'reason'                              => 'late_good_setup_waiting_better_entry',
                        'status'                              => 'pending_better_entry',
                        'synthetic_quality_score'             => $synQuality['synthetic_quality_score'],
                        'synthetic_quality_warnings'          => $synQuality['synthetic_quality_warnings'] ?? [],
                        'candidate_quality_score'             => $quality['candidate_quality_score'],
                        'neckline_score'                      => $quality['neckline_score'],
                        'confirmation_score'                  => $quality['confirmation_score'],
                        'structure_score'                     => $quality['structure_score'],
                        'context_score'                       => $quality['context_score'],
                        'pattern_score'                       => $quality['pattern_score'],
                        'trend_direction'                     => $diagBase['trend_direction'] ?? 'unknown',
                        'wave_state'                          => $diagBase['wave_state'] ?? 'unknown',
                        'corridor_bucket'                     => $diagBase['corridor_bucket'] ?? 0,
                        'signal_quality_class'                => $bearishRevExUsed ? 'controlled_reversal' : 'clean_signal',
                        'active_falling_knife_detected'       => $diagBase['active_falling_knife_detected'] ?? false,
                        'support_broken'                      => $diagBase['support_broken'] ?? false,
                        'reclaim_after_flat_detected'         => $diagBase['reclaim_after_flat_detected'] ?? false,
                        'entry_context_score'                 => $diagBase['entry_context_score'] ?? null,
                        'late_good_setup'                     => true,
                    ], $config);
                    $diagBase['pending_confirmation_status'] = 'added_better_entry';
                    $diagBase['pending_confirmation_reason'] = 'late_good_setup_waiting_better_entry';
                    $diagBase['pending_confirmation_expires_at'] = $expiresAt;
                }
                $diagBase['setup_allowed_kill_stage']  = 'late_good_setup';
                $diagBase['setup_allowed_kill_reason'] = 'late_good_setup_waiting_better_entry';
                return array_merge($diagBase, [
                    'symbol'                  => $symbol,
                    'candidate_found'         => true,
                    'candidate_side'          => $side,
                    'primary_pattern'         => 'double_bottom',
                    'double_bottom_checked'   => true,
                    'neckline_value'          => $candidate['neckline']              ?? 0.0,
                    'low1_value'              => $candidate['low1_price']            ?? 0.0,
                    'low2_value'              => $candidate['low2_price']            ?? 0.0,
                    'pattern_window_size'     => $candidate['window_size']           ?? 0,
                    'similarity_delta_pct'    => $candidate['similarity_delta_pct'] ?? 0.0,
                    'pattern_score'           => $quality['pattern_score'],
                    'structure_score'         => $quality['structure_score'],
                    'neckline_score'          => $quality['neckline_score'],
                    'confirmation_score'      => $quality['confirmation_score'],
                    'context_score'           => $quality['context_score'],
                    'candidate_quality_score' => $quality['candidate_quality_score'],
                    'quality_pass'            => true,
                    'quality_reject_reason'   => null,
                    'quality_source'          => 'synthetic_intraday_setup',
                    'signal_quality_class'    => $bearishRevExUsed ? 'controlled_reversal' : 'clean_signal',
                    'confirm_status'          => null,
                    'confirm_bars_waited'     => 0,
                    'candidate_expired'       => false,
                    'final_signal_status'     => 'late_good_setup',
                    'reject_reason'           => 'late_good_setup_waiting_better_entry',
                    'signal'                  => null,
                ]);
            }
        } else {
            $this->requireLogic('candidate_quality');
            $quality = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternCandidateQuality())->score(
                $candidate, $wave, $config, $side
            );
            $diagBase['quality_source'] = 'h4_pattern_quality';
        }

        if (!$quality['quality_pass']) {
            // Quality kill: stamp before returning so accumulateStats() can track it.
            $diagBase['setup_allowed_kill_stage']  = 'quality';
            $diagBase['setup_allowed_kill_reason'] = 'setup_allowed_but_quality_failed';
            return array_merge($diagBase, [
                'symbol'                  => $symbol,
                'candidate_found'         => true,
                'candidate_side'          => $side,
                'primary_pattern'         => 'double_bottom',
                'double_bottom_checked'   => true,
                'neckline_value'          => $candidate['neckline']              ?? 0.0,
                'low1_value'              => $candidate['low1_price']            ?? 0.0,
                'low2_value'              => $candidate['low2_price']            ?? 0.0,
                'pattern_window_size'     => $candidate['window_size']           ?? 0,
                'similarity_delta_pct'    => $candidate['similarity_delta_pct'] ?? 0.0,
                'pattern_score'           => $quality['pattern_score'],
                'structure_score'         => $quality['structure_score'],
                'neckline_score'          => $quality['neckline_score'],
                'confirmation_score'      => $quality['confirmation_score'],
                'context_score'           => $quality['context_score'],
                'candidate_quality_score' => $quality['candidate_quality_score'],
                'quality_pass'            => false,
                'quality_reject_reason'   => $quality['quality_reject_reason'],
                'quality_source'          => $diagBase['quality_source'] ?? 'h4_pattern_quality',
                'signal_quality_class'    => $bearishRevExUsed ? 'controlled_reversal' : 'clean_signal',
                'confirm_status'          => null,
                'confirm_bars_waited'     => 0,
                'candidate_expired'       => false,
                'final_signal_status'     => 'rejected',
                'reject_reason'           => $quality['quality_reject_reason'] ?? 'quality_filter',
                'signal'                  => null,
            ]);
        }

        if ((bool)($config['confirm_required'] ?? true)) {
            $this->requireLogic('control_check');
            $candIdx = max(0, count($candles) - 3);
            $confirm = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternControlCheck())->check($candidate, $candles, $candIdx, $config);
            if (!$confirm['confirm_pass']) {
                // Control-check kill: stamp before returning.
                $diagBase['setup_allowed_kill_stage']  = 'control_check';
                $confirmStatus = $confirm['confirm_status'] ?? '';
                // waiting_for_confirm_bar: store as pending confirmation instead of final dead reject
                $isPendingWait = $confirmStatus === 'confirm_waiting'
                    && (bool)($config['pending_confirmation_enabled'] ?? true);
                if ($isPendingWait) {
                    $diagBase['setup_allowed_kill_reason']    = 'waiting_for_confirm_bar';
                    $diagBase['pending_confirmation_status']  = 'added';
                    $diagBase['pending_confirmation_reason']  = 'waiting_for_confirm_bar';
                    $ttlMin = max(1, (int)($config['pending_confirmation_ttl_minutes'] ?? 15));
                    $expiresAt = date('c', time() + $ttlMin * 60);
                    $diagBase['pending_confirmation_expires_at'] = $expiresAt;
                    $this->writePendingConfirmation([
                        'symbol'                              => $symbol,
                        'side'                                => $side,
                        'mode'                                => $config['mode'] ?? 'demo',
                        'setup_class'                         => $diagBase['setup_class'] ?? null,
                        'neckline_level'                      => $candidate['neckline'] ?? null,
                        'candidate_trigger'                   => $candidate['candidate_trigger'] ?? $candidate['neckline'] ?? null,
                        'low1_price'                          => $candidate['low1_price'] ?? null,
                        'low2_price'                          => $candidate['low2_price'] ?? null,
                        'reclaim_level'                       => $diagBase['reclaim_level'] ?? null,
                        'created_at'                          => date('c'),
                        'expires_at'                          => $expiresAt,
                        'confirm_bars_required'               => $config['reclaim_confirm_bars'] ?? $config['double_bottom_reclaim_confirm_bars'] ?? 2,
                        'confirm_bars_seen'                   => $confirm['confirm_bars_waited'] ?? 0,
                        'entry_distance_from_neckline_pct'    => $diagBase['entry_distance_from_neckline_pct'] ?? null,
                        'entry_distance_from_reclaim_pct'     => $diagBase['entry_distance_from_reclaim_pct'] ?? null,
                        'reason'                              => 'waiting_for_confirm_bar',
                        'status'                              => 'pending',
                        'synthetic_quality_score'             => $diagBase['synthetic_quality_score'] ?? null,
                        'synthetic_quality_warnings'          => $diagBase['synthetic_quality_warnings'] ?? [],
                        // Quality fields needed to reconstruct a full signal-compatible object on confirmation.
                        'candidate_quality_score'             => $quality['candidate_quality_score'] ?? null,
                        'neckline_score'                      => $quality['neckline_score'] ?? null,
                        'confirmation_score'                  => $quality['confirmation_score'] ?? null,
                        'structure_score'                     => $quality['structure_score'] ?? null,
                        'context_score'                       => $quality['context_score'] ?? null,
                        'pattern_score'                       => $quality['pattern_score'] ?? null,
                        // H4 market context — used by applySignalFilters() gates.
                        'trend_direction'                     => $diagBase['trend_direction'] ?? 'unknown',
                        'wave_state'                          => $diagBase['wave_state'] ?? 'unknown',
                        'corridor_bucket'                     => $diagBase['corridor_bucket'] ?? 0,
                        'signal_quality_class'                => $bearishRevExUsed ? 'controlled_reversal' : 'clean_signal',
                        'active_falling_knife_detected'       => $diagBase['active_falling_knife_detected'] ?? false,
                        'support_broken'                      => $diagBase['support_broken'] ?? false,
                        'reclaim_after_flat_detected'         => $diagBase['reclaim_after_flat_detected'] ?? false,
                        'entry_context_score'                 => $diagBase['entry_context_score'] ?? null,
                    ], $config);
                } else {
                    $diagBase['setup_allowed_kill_reason'] = 'setup_allowed_but_control_failed';
                }
                return array_merge($diagBase, [
                    'symbol'                  => $symbol,
                    'candidate_found'         => true,
                    'candidate_side'          => $side,
                    'primary_pattern'         => 'double_bottom',
                    'double_bottom_checked'   => true,
                    'neckline_value'          => $candidate['neckline']              ?? 0.0,
                    'low1_value'              => $candidate['low1_price']            ?? 0.0,
                    'low2_value'              => $candidate['low2_price']            ?? 0.0,
                    'pattern_window_size'     => $candidate['window_size']           ?? 0,
                    'similarity_delta_pct'    => $candidate['similarity_delta_pct'] ?? 0.0,
                    'pattern_score'           => $quality['pattern_score'],
                    'structure_score'         => $quality['structure_score'],
                    'neckline_score'          => $quality['neckline_score'],
                    'confirmation_score'      => $quality['confirmation_score'],
                    'context_score'           => $quality['context_score'],
                    'candidate_quality_score' => $quality['candidate_quality_score'],
                    'quality_pass'            => true,
                    'quality_reject_reason'   => null,
                    'quality_source'          => $diagBase['quality_source'] ?? 'h4_pattern_quality',
                    'signal_quality_class'    => $bearishRevExUsed ? 'controlled_reversal' : 'clean_signal',
                    'confirm_status'          => $confirm['confirm_status'],
                    'confirm_bars_waited'     => $confirm['confirm_bars_waited'],
                    'candidate_expired'       => $confirm['candidate_expired'],
                    'final_signal_status'     => $isPendingWait ? 'pending_confirmation' : 'confirm_pending',
                    'reject_reason'           => $isPendingWait ? 'waiting_for_confirm_bar' : $confirm['reject_reason'],
                    'pending_confirmation_status'   => $diagBase['pending_confirmation_status'] ?? null,
                    'pending_confirmation_reason'   => $diagBase['pending_confirmation_reason'] ?? null,
                    'pending_confirmation_expires_at' => $diagBase['pending_confirmation_expires_at'] ?? null,
                    'signal'                  => null,
                ]);
            }
        } else {
            $confirm = ['confirm_status' => 'confirm_pass', 'confirm_bar_close' => null, 'confirm_bars_waited' => 0];
        }

        // ── OrderBook Wall Context gate ───────────────────────────────────────
        // Fetched only for candidates that reach confirmation (serious long candidates).
        // Default mode: soft_demote (tag signal with risk, do not hard-reject).
        // Merge the computed quality score into the diag passed to the gate so
        // it reads the real score instead of defaulting to 0.0 from an unset key.
        $obcDiag = array_merge($diagBase, [
            'candidate_quality_score' => $quality['candidate_quality_score'] ?? null,
        ]);
        $obcWallCtx = $this->applyOrderBookWallGate($symbol, $candidate, $config, $obcDiag);
        if (!empty($obcWallCtx['ob_hard_reject'])) {
            return $this->reject($diagBase, $symbol, 'double_bottom', 'ob_ask_wall_hard_reject', true, [
                'ob_wall_context'       => $obcWallCtx,
                'ob_wall_ask_risk'      => $obcWallCtx['ob_ask_wall_risk'] ?? false,
                'ob_wall_fetch_ok'      => $obcWallCtx['ob_fetch_ok'] ?? null,
            ]);
        }

        $this->requireLogic('signal');
        $signal = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternSignal())->build(
            $symbol, $candidate, $confirm,
            array_merge($diagBase, $corridor, $wave, [
                'pattern_score'           => $quality['pattern_score'],
                'structure_score'         => $quality['structure_score'],
                'neckline_score'          => $quality['neckline_score'],
                'confirmation_score'      => $quality['confirmation_score'],
                'context_score'           => $quality['context_score'],
                'candidate_quality_score' => $quality['candidate_quality_score'],
            ]),
            date('c'),
            $config
        );

        return array_merge($diagBase, [
            'symbol'                  => $symbol,
            'candidate_found'         => true,
            'candidate_side'          => $side,
            'primary_pattern'         => 'double_bottom',
            'double_bottom_checked'   => true,
            'neckline_value'          => $candidate['neckline']              ?? 0.0,
            'low1_value'              => $candidate['low1_price']            ?? 0.0,
            'low2_value'              => $candidate['low2_price']            ?? 0.0,
            'pattern_window_size'     => $candidate['window_size']           ?? 0,
            'similarity_delta_pct'    => $candidate['similarity_delta_pct'] ?? 0.0,
            'pattern_score'           => $quality['pattern_score'],
            'structure_score'         => $quality['structure_score'],
            'neckline_score'          => $quality['neckline_score'],
            'confirmation_score'      => $quality['confirmation_score'],
            'context_score'           => $quality['context_score'],
            'candidate_quality_score' => $quality['candidate_quality_score'],
            'quality_pass'            => true,
            'quality_reject_reason'   => null,
            'quality_source'          => $diagBase['quality_source'] ?? 'h4_pattern_quality',
            'signal_quality_class'    => $bearishRevExUsed ? 'controlled_reversal' : 'clean_signal',
            'confirm_status'          => 'confirm_pass',
            'confirm_bars_waited'     => $confirm['confirm_bars_waited'] ?? 0,
            'candidate_expired'       => false,
            'final_signal_status'     => 'emitted',
            'reject_reason'           => null,
            'setup_allowed_kill_stage'  => null,
            'setup_allowed_kill_reason' => null,
            // OBC wall context (may be empty if gate disabled or OBC unavailable)
            'ob_wall_context'         => !empty($obcWallCtx) ? $obcWallCtx : null,
            'ob_ask_wall_risk'        => $obcWallCtx['ob_ask_wall_risk']   ?? false,
            'ob_bid_wall_support'     => $obcWallCtx['ob_bid_wall_support'] ?? false,
            'ob_ask_wall_eaten'       => $obcWallCtx['ob_ask_wall_eaten']  ?? false,
            'ob_soft_demoted'         => $obcWallCtx['ob_soft_demoted']    ?? false,
            'ob_wall_checked'         => $obcWallCtx['ob_wall_checked']    ?? false,
            'ob_gate_mode'            => $obcWallCtx['ob_gate_mode']             ?? null,
            'ob_fetch_ok'             => $obcWallCtx['ob_fetch_ok']              ?? null,
            'ob_ask_wall_distance_pct' => $obcWallCtx['ob_ask_wall_distance_pct'] ?? null,
            'ob_bid_wall_distance_pct' => $obcWallCtx['ob_bid_wall_distance_pct'] ?? null,
            'ob_ask_wall_status'      => $obcWallCtx['ob_ask_wall_status']       ?? null,
            'ob_bid_wall_status'      => $obcWallCtx['ob_bid_wall_status']       ?? null,
            'ob_skip_reason'          => $obcWallCtx['ob_skip_reason']                  ?? null,
            'ob_skip_quality_score'   => $obcWallCtx['ob_skip_quality_score']             ?? null,
            'ob_skip_required_quality_score' => $obcWallCtx['ob_skip_required_quality_score'] ?? null,
            'signal_id'               => $signal['signal_id'],
            'signal'                  => $signal,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Apply the OrderBook Wall Context gate for a confirmed long candidate.
     *
     * Only fetches for candidates that passed quality + confirmation gates.
     * Default mode is soft_demote: signals are tagged with wall risk but not hard-rejected.
     *
     * Returns a normalized wall context array with ob_ prefixed diagnostic fields.
     * Returns [] when OBC gate is disabled or the OBC service is unavailable.
     *
     * @param string $symbol
     * @param array  $candidate Confirmed double-bottom candidate
     * @param array  $config    Strategy config
     * @param array  $diagBase  Current diag context (for quality score access)
     * @return array<string,mixed>
     */
    private function applyOrderBookWallGate(
        string $symbol,
        array  $candidate,
        array  $config,
        array  $diagBase
    ): array {
        $gateEnabled = (bool)($config['orderbook_entry_wall_gate_enabled'] ?? false);
        if (!$gateEnabled) {
            return [];
        }
        if ($this->obcService === null) {
            $this->obWallSkipServiceUnavailableTotal++;
            $skipQualSvc = $diagBase['candidate_quality_score'] ?? null;
            if (count($this->obWallSkipExamples) < 5) {
                $this->obWallSkipExamples[] = [
                    'symbol'                  => $symbol,
                    'candidate_quality_score' => $skipQualSvc,
                    'required_quality_score'  => (float)($config['orderbook_entry_wall_fetch_after_quality_score'] ?? 0.0),
                    'reason'                  => 'obc_service_unavailable',
                ];
            }
            return [
                'ob_wall_checked'                => false,
                'ob_skip_reason'                 => 'obc_service_unavailable',
                'ob_skip_quality_score'          => $skipQualSvc,
                'ob_skip_required_quality_score' => (float)($config['orderbook_entry_wall_fetch_after_quality_score'] ?? 0.0),
            ];
        }

        // Quality-score threshold: only fetch for candidates above this score
        $fetchAfterScore = (float)($config['orderbook_entry_wall_fetch_after_quality_score'] ?? 0.0);
        $qualScore = (float)($diagBase['candidate_quality_score'] ?? 0.0);
        if ($fetchAfterScore > 0.0 && $qualScore < $fetchAfterScore) {
            $this->obWallSkipQualityBelowThresholdTotal++;
            if (count($this->obWallSkipExamples) < 5) {
                $this->obWallSkipExamples[] = [
                    'symbol'                  => $symbol,
                    'candidate_quality_score' => $qualScore,
                    'required_quality_score'  => $fetchAfterScore,
                    'reason'                  => 'quality_below_threshold',
                ];
            }
            return [
                'ob_wall_checked'                => false,
                'ob_skip_reason'                 => 'quality_below_threshold',
                'ob_skip_quality_score'          => $qualScore,
                'ob_skip_required_quality_score' => $fetchAfterScore,
            ];
        }

        // Use neckline level as the entry price reference
        $entryPrice = (float)($candidate['neckline'] ?? $candidate['entry_price'] ?? 0.0);
        if ($entryPrice <= 0.0) {
            $this->obWallSkipMissingEntryPriceTotal++;
            if (count($this->obWallSkipExamples) < 5) {
                $this->obWallSkipExamples[] = [
                    'symbol'                  => $symbol,
                    'candidate_quality_score' => $diagBase['candidate_quality_score'] ?? null,
                    'required_quality_score'  => $fetchAfterScore,
                    'reason'                  => 'missing_entry_price',
                ];
            }
            return [
                'ob_wall_checked'                => false,
                'ob_skip_reason'                 => 'missing_entry_price',
                'ob_skip_quality_score'          => $diagBase['candidate_quality_score'] ?? null,
                'ob_skip_required_quality_score' => $fetchAfterScore,
            ];
        }

        $nearPct          = (float)($config['orderbook_entry_wall_near_pct']              ?? 1.5);
        $persistRequired  = (bool)($config['orderbook_entry_wall_persistent_required']    ?? true);
        $supportBonus     = (bool)($config['orderbook_entry_wall_support_bonus_enabled']  ?? true);
        $eatenBonus       = (bool)($config['orderbook_entry_wall_ask_eaten_bonus_enabled'] ?? true);
        $pendingEnabled   = (bool)($config['orderbook_entry_wall_pending_enabled']        ?? false);
        $gateMode         = (string)($config['orderbook_entry_wall_gate_mode']            ?? 'soft_demote');

        $this->obWallCheckedTotal++;

        $wallCtx = null;
        $fetchOk = false;
        try {
            $wallCtx = $this->obcService->getWallContext($symbol, $entryPrice);
            $fetchOk = (bool)($wallCtx['fetch_ok'] ?? false);
        } catch (\Throwable) {
            $fetchOk = false;
        }

        if ($fetchOk) {
            $this->obWallFetchSuccessTotal++;
        } else {
            $this->obWallFetchFailedTotal++;
        }

        $nearestAskWall = $wallCtx['nearest_ask_wall'] ?? null;
        $nearestBidWall = $wallCtx['nearest_bid_wall'] ?? null;
        $askStatus      = (string)($wallCtx['ask_wall_status'] ?? 'none');
        $bidStatus      = (string)($wallCtx['bid_wall_status'] ?? 'none');

        $obAskWallRisk    = false;
        $obBidWallSupport = false;
        $obAskWallEaten   = false;
        $obSoftDemoted    = false;
        $obHardReject     = false;

        // ── Ask wall risk check (wall above entry = resistance) ───────────────
        if ($nearestAskWall !== null) {
            $askDist = (float)($nearestAskWall['distance_pct'] ?? 999.0);
            $isNear = $askDist <= $nearPct;
            $isPersistent = !$persistRequired || $askStatus === 'persistent';
            $isEaten = ($askStatus === 'eaten' || $askStatus === 'broken');

            if ($isEaten && $eatenBonus) {
                // Eaten/broken ask wall above entry = continuation signal (good for long)
                $obAskWallEaten = true;
                $this->obWallAskEatenTotal++;
            } elseif ($isNear && $isPersistent && !$isEaten) {
                // Persistent ask wall near entry = resistance risk
                $obAskWallRisk = true;
                $this->obWallAskRiskTotal++;

                if ($gateMode === 'hard_reject') {
                    $obHardReject = true;
                    $this->obWallHardRejectTotal++;
                } elseif ($pendingEnabled) {
                    $this->obWallPendingTotal++;
                    // pending mode: gate does not emit hard_reject but flags the signal
                } else {
                    // Default: soft_demote — tag the signal, do not block
                    $obSoftDemoted = true;
                    $this->obWallSoftDemoteTotal++;
                }
            }
        }

        // ── Bid wall support check (wall below entry = support) ───────────────
        if ($supportBonus && $nearestBidWall !== null && !$obAskWallRisk) {
            $bidDist  = (float)($nearestBidWall['distance_pct'] ?? 999.0);
            $bidNear  = $bidDist <= $nearPct;
            $bidPersist = !$persistRequired || $bidStatus === 'persistent';
            if ($bidNear && $bidPersist && $bidStatus !== 'eaten' && $bidStatus !== 'broken') {
                $obBidWallSupport = true;
                $this->obWallBidSupportTotal++;
            }
        }

        return [
            'ob_wall_checked'        => true,
            'ob_fetch_ok'            => $fetchOk,
            'ob_entry_price_ref'     => $entryPrice,
            'ob_ask_wall_risk'       => $obAskWallRisk,
            'ob_bid_wall_support'    => $obBidWallSupport,
            'ob_ask_wall_eaten'      => $obAskWallEaten,
            'ob_soft_demoted'        => $obSoftDemoted,
            'ob_hard_reject'         => $obHardReject,
            'ob_gate_mode'           => $gateMode,
            'ob_ask_wall_status'     => $askStatus,
            'ob_bid_wall_status'     => $bidStatus,
            'ob_ask_wall_distance_pct' => $nearestAskWall !== null ? (float)($nearestAskWall['distance_pct'] ?? null) : null,
            'ob_bid_wall_distance_pct' => $nearestBidWall !== null ? (float)($nearestBidWall['distance_pct'] ?? null) : null,
            'ob_ask_wall_notional'   => $nearestAskWall['notional']  ?? null,
            'ob_bid_wall_notional'   => $nearestBidWall['notional']  ?? null,
        ];
    }

    private function reject(array $diag, string $symbol, string $pattern, ?string $reason, bool $patternChecked = false, array $extra = []): array
    {
        // Respect any primary_reject_reason already stamped on diag (e.g., from bearish resolver);
        // otherwise default to the explicit $reason.
        $primaryRej   = $diag['primary_reject_reason']    ?? $reason;
        $secondaryRej = (array)($diag['secondary_reject_reasons'] ?? []);
        $reasonCodes  = (array)($diag['reason_codes']             ?? ($reason !== null ? [$reason] : []));
        $failedStage  = $diag['failed_stage'] ?? $this->failedStageForReason($reason ?? '');

        return array_merge($diag, $extra, [
            'symbol'                  => $symbol,
            'candidate_found'         => false,
            'candidate_side'          => 'long',
            'primary_pattern'         => $pattern,
            'double_bottom_checked'   => $patternChecked,
            'pattern_score'           => null,
            'structure_score'         => null,
            'neckline_score'          => null,
            'confirmation_score'      => null,
            'context_score'           => null,
            'candidate_quality_score' => null,
            'quality_pass'            => null,
            'quality_reject_reason'   => null,
            'confirm_status'          => null,
            'confirm_bars_waited'     => 0,
            'candidate_expired'       => false,
            'final_signal_status'     => 'rejected',
            'reject_reason'           => $reason,
            'primary_reject_reason'   => $primaryRej,
            'secondary_reject_reasons' => $secondaryRej,
            'reason_codes'            => $reasonCodes,
            'failed_stage'            => $failedStage,
            'signal'                  => null,
        ]);
    }

    private function mergeSignal(array $signals, array $signal): array
    {
        $key = $signal['signal_id'];
        $byId = [];
        foreach ($signals as $s) {
            $byId[$s['signal_id']] = $s;
        }
        $byId[$key] = $signal;
        return array_values($byId);
    }

    /**
     * Apply the full signal filtering pipeline (long-only).
     *
     * Stage 1 — Final eligibility:
     *   a. Quality completeness
     *   b. Neckline floor
     *   c. Trend consistency (long requires bullish context)
     *   d. Context consistency (wave + bucket)
     *   e. Quality composite floor
     * Stage 2 — Winner selection: one signal per symbol by quality ranking.
     *
     * Returns [filteredSignals, filterStats, signalOutcomeMap].
     */
    private function applySignalFilters(
        array $existingSignals,
        array $newlyEmitted,
        array $config
    ): array {
        $minNeckline      = (float)($config['min_neckline_score']          ?? 0.0);
        $minFinalQuality  = (float)($config['min_candidate_quality_score'] ?? 0.0);
        $trendRequired    = (bool)($config['trend_required']               ?? true);
        $corridorRequired = (bool)($config['corridor_required']            ?? true);
        $waveRequired     = (bool)($config['wave_required']                ?? true);
        $allowedLong      = (array)($config['allowed_long_buckets']        ?? [1, 2]);
        $maxStopLossPct   = (float)($config['max_stop_loss_pct']           ?? 0.0);

        // H4 final gate warning mode for setup_allowed A/B signals.
        // When 'warning': final_trend_mismatch / final_context_inconsistent become warning-only
        // for signals that are _setup_signal_allowed=true with an A/B synthetic setup class.
        $h4FinalGatesMode       = (string)($config['final_old_h4_gates_mode_for_setup_allowed'] ?? 'warning');
        $h4GateWarnSetupClasses = ['classic_intraday_double_bottom_reclaim', 'post_dump_base_reclaim'];

        // Counters for H4 final gate warnings
        $setupAllowedFinalTrendWarningTotal   = 0;
        $setupAllowedFinalContextWarningTotal = 0;
        $setupAllowedOldH4FinalGatesBypassedTotal = 0;

        // Adaptive stop-width gate config
        $stopGateMode        = (string)($config['final_stop_width_gate_mode']        ?? 'adaptive');
        $stopWarnPct         = (float)($config['final_stop_width_warning_pct']       ?? 0.05);
        $stopHardPct         = (float)($config['final_stop_width_hard_pct']          ?? 0.12);
        $stopWarnForSynth    = (bool)($config['final_stop_width_warning_for_synthetic_setup']    ?? true);
        $stopHardForSynth    = (bool)($config['final_stop_width_hard_block_for_synthetic_setup'] ?? true);
        $stopWarnClasses     = (array)($config['final_stop_width_warn_setup_classes'] ?? [
            'classic_intraday_double_bottom_reclaim', 'post_dump_base_reclaim',
        ]);
        $adaptiveStopMinQuality  = (float)($config['final_stop_width_adaptive_min_quality_score'] ?? 0.0);

        $finalRejectDist  = [];

        // New stop-width diagnostic counters
        $stopWidthWarningTotal           = 0;
        $stopWidthHardRejectTotal        = 0;
        $stopWidthBypassedForSynthTotal  = 0;
        $stopMissingForSynthTotal        = 0;

        $merged = [];
        foreach ($existingSignals as $s) {
            if (isset($s['signal_id'])) {
                $merged[$s['signal_id']] = $s;
            }
        }
        foreach ($newlyEmitted as $s) {
            if (isset($s['signal_id'])) {
                $merged[$s['signal_id']] = $s;
            }
        }

        $beforeFinalEligibility = count($merged);
        $eligible               = [];
        $signalOutcomeMap       = [];

        $rejectedFinalQuality    = 0;
        $rejectedFinalLowNeckline = 0;
        $rejectedFinalLowQuality  = 0;
        $rejectedFinalTrend       = 0;
        $rejectedFinalContext     = 0;

        // Setup-allowed funnel: track how many setup_allowed signals enter/survive final_eligibility.
        $setupAllowedBeforeElig = 0;
        $setupAllowedAfterElig  = 0;

        $isComplete = static function (array $s): bool {
            static $requiredKeys = [
                'quality_pass', 'candidate_quality_score', 'neckline_score',
                'confirmation_score', 'structure_score', 'context_score',
            ];
            if (($s['quality_pass'] ?? null) !== true) {
                return false;
            }
            foreach ($requiredKeys as $k) {
                if (!array_key_exists($k, $s) || $s[$k] === null) {
                    return false;
                }
            }
            return true;
        };

        foreach ($merged as $id => $s) {
            $isSetupAllowed = (bool)($s['_setup_signal_allowed'] ?? false);
            if ($isSetupAllowed) {
                $setupAllowedBeforeElig++;
            }

            // 1a. Quality completeness
            if (!$isComplete($s)) {
                $rejectedFinalQuality++;
                $signalOutcomeMap[$id] = ['winner' => false, 'reason' => 'final_low_quality'];
                $finalRejectDist['final_low_quality'] = ($finalRejectDist['final_low_quality'] ?? 0) + 1;
                continue;
            }

            // 1b. Neckline floor
            if ($minNeckline > 0.0 && (float)($s['neckline_score'] ?? 0.0) < $minNeckline) {
                $rejectedFinalLowNeckline++;
                $signalOutcomeMap[$id] = ['winner' => false, 'reason' => 'final_low_neckline'];
                $finalRejectDist['final_low_neckline'] = ($finalRejectDist['final_low_neckline'] ?? 0) + 1;
                continue;
            }

            // 1b2. Stop-loss width guard: adaptive behaviour for synthetic/intraday A/B setups.
            //      For classic/non-synthetic signals: keep the old hard-reject at max_stop_loss_pct.
            //      For A/B synthetic setups: warn-only between warning_pct and hard_pct; hard block above hard_pct.
            if ($maxStopLossPct > 0.0 || ($stopGateMode === 'adaptive' && $stopHardForSynth)) {
                $slPct      = isset($s['stop_loss_pct']) ? (float)$s['stop_loss_pct'] : null;
                $setupClass = (string)($s['setup_class'] ?? '');
                $isSynthAdaptive = $stopGateMode === 'adaptive'
                    && $stopWarnForSynth
                    && $setupClass !== ''
                    && in_array($setupClass, $stopWarnClasses, true);

                if ($isSynthAdaptive) {
                    // Adaptive path for A/B synthetic setups
                    if ($slPct === null) {
                        // Missing SL: lows were zero or entry price was invalid.
                        // For synthetic A/B setups, do not hard-reject here — the stop was never capped to null
                        // by computeStopLoss() any more (that early clip was removed). Null means the geometry
                        // data (low1_price/low2_price) was missing, not that it was "too wide".
                        // The Stop Manager module is responsible for managing live risk; strategy-level eligibility
                        // is determined by reclaim quality and entry distance (already checked upstream).
                        $stopMissingForSynthTotal++;
                        $stopWidthBypassedForSynthTotal++;
                        $s['final_stop_width_warning']        = true;
                        $s['final_stop_width_warning_reason'] = 'final_stop_missing_for_synthetic_setup';
                        $s['final_stop_width_gate_bypassed_for_synthetic_setup'] = true;
                        // keep eligible
                    } elseif ($stopHardForSynth && $slPct > $stopHardPct) {
                        // Exceeds emergency hard cap → reject
                        $stopWidthHardRejectTotal++;
                        $signalOutcomeMap[$id] = ['winner' => false, 'reason' => 'final_stop_too_wide'];
                        $finalRejectDist['final_stop_too_wide'] = ($finalRejectDist['final_stop_too_wide'] ?? 0) + 1;
                        continue;
                    } elseif ($slPct > $stopWarnPct) {
                        // Moderately wide: warning only for whitelisted A/B synthetic classes.
                        // If adaptive_min_quality_score is configured, require quality to be strong.
                        $qualScore = (float)($s['candidate_quality_score'] ?? 0.0);
                        $qualOk    = $adaptiveStopMinQuality <= 0.0 || $qualScore >= $adaptiveStopMinQuality;
                        if (!$qualOk && $adaptiveStopMinQuality > 0.0) {
                            // Quality too low for adaptive wide-stop bypass → hard reject
                            $stopWidthHardRejectTotal++;
                            $s['adaptive_stop_width_allowed']         = false;
                            $s['adaptive_stop_width_reason']          = 'adaptive_stop_quality_too_low';
                            $s['adaptive_stop_min_quality_score']     = $adaptiveStopMinQuality;
                            $s['adaptive_stop_quality_ok']            = false;
                            $s['configured_max_stop_loss_pct']        = $maxStopLossPct;
                            // effective limit when quality gate fails is the warning threshold
                            $s['effective_stop_loss_pct_limit']       = $stopWarnPct;
                            $s['effective_stop_loss_pct']             = $slPct;
                            $signalOutcomeMap[$id] = ['winner' => false, 'reason' => 'final_stop_too_wide'];
                            $finalRejectDist['final_stop_too_wide'] = ($finalRejectDist['final_stop_too_wide'] ?? 0) + 1;
                            continue;
                        }
                        $stopWidthWarningTotal++;
                        $stopWidthBypassedForSynthTotal++;
                        $s['final_stop_width_warning']        = true;
                        $s['final_stop_width_warning_reason'] = 'final_stop_width_above_warning_threshold';
                        $s['final_stop_width_gate_bypassed_for_synthetic_setup'] = true;
                        $s['adaptive_stop_width_allowed']         = true;
                        $s['adaptive_stop_width_reason']          = 'setup_class_whitelisted_quality_ok';
                        $s['adaptive_stop_min_quality_score']     = $adaptiveStopMinQuality;
                        $s['adaptive_stop_quality_ok']            = true;
                        $s['configured_max_stop_loss_pct']        = $maxStopLossPct;
                        // When adaptive wide stop is allowed, the actual enforced limit
                        // is stopHardPct (not stopWarnPct).  Setting limit=stopWarnPct
                        // was inconsistent (signal allowed above limit).
                        $s['effective_stop_loss_pct_limit']       = $stopHardPct;
                        $s['effective_stop_loss_pct']             = $slPct;
                        // keep eligible
                    } else {
                        // Within warning threshold: no issue
                        $s['final_stop_width_warning'] = false;
                        $s['final_stop_width_gate_bypassed_for_synthetic_setup'] = false;
                        $s['adaptive_stop_width_allowed']     = true;
                        $s['adaptive_stop_width_reason']      = 'within_warning_threshold';
                        $s['adaptive_stop_min_quality_score'] = $adaptiveStopMinQuality;
                        $s['adaptive_stop_quality_ok']        = true;
                        $s['configured_max_stop_loss_pct']    = $maxStopLossPct;
                        $s['effective_stop_loss_pct_limit']   = $stopWarnPct;
                        $s['effective_stop_loss_pct']         = $slPct ?? 0.0;
                    }
                    // Always stamp gate-mode diagnostics
                    $s['final_stop_width_gate_mode']    = $stopGateMode;
                    $s['final_stop_width_hard_pct']     = $stopHardPct;
                    $s['final_stop_width_warning_pct']  = $stopWarnPct;
                    $s['stop_loss_pct']                 = $slPct;
                } elseif ($maxStopLossPct > 0.0) {
                    // Classic hard-reject path — max_stop_loss_pct is the hard cap
                    if ($slPct === null || $slPct > $maxStopLossPct) {
                        $stopWidthHardRejectTotal++;
                        $s['adaptive_stop_width_allowed']   = false;
                        $s['adaptive_stop_width_reason']    = 'classic_hard_cap_exceeded';
                        $s['configured_max_stop_loss_pct']  = $maxStopLossPct;
                        $s['effective_stop_loss_pct_limit'] = $maxStopLossPct;
                        $s['effective_stop_loss_pct']       = $slPct;
                        $signalOutcomeMap[$id] = ['winner' => false, 'reason' => 'final_stop_too_wide'];
                        $finalRejectDist['final_stop_too_wide'] = ($finalRejectDist['final_stop_too_wide'] ?? 0) + 1;
                        continue;
                    }
                    // Within classic cap — stamp diagnostics
                    $s['adaptive_stop_width_allowed']   = true;
                    $s['adaptive_stop_width_reason']    = 'within_classic_hard_cap';
                    $s['configured_max_stop_loss_pct']  = $maxStopLossPct;
                    $s['effective_stop_loss_pct_limit'] = $maxStopLossPct;
                    $s['effective_stop_loss_pct']       = $slPct;
                }
            }

            // 1c. Trend consistency — long requires bullish context
            if ($trendRequired) {
                $trendDir = (string)($s['trend_direction'] ?? 'unknown');
                $trendMismatch = !in_array($trendDir, ['bullish', 'bearish', 'flat'], true) || $trendDir !== 'bullish';
                if ($trendMismatch) {
                    // For setup_allowed A/B synthetic signals, apply warning mode instead of hard reject.
                    $signalSetupClass = (string)($s['setup_class'] ?? '');
                    $isSetupAllowedAB = $isSetupAllowed && in_array($signalSetupClass, $h4GateWarnSetupClasses, true);
                    if ($h4FinalGatesMode === 'warning' && $isSetupAllowedAB) {
                        $setupAllowedFinalTrendWarningTotal++;
                        $setupAllowedOldH4FinalGatesBypassedTotal++;
                        $s['final_trend_mismatch_warning'] = true;
                        $s['final_trend_direction_seen']   = $trendDir;
                        // Fall through to context check (do not continue/reject)
                    } else {
                        $rejectedFinalTrend++;
                        $signalOutcomeMap[$id] = ['winner' => false, 'reason' => 'final_trend_mismatch'];
                        $finalRejectDist['final_trend_mismatch'] = ($finalRejectDist['final_trend_mismatch'] ?? 0) + 1;
                        continue;
                    }
                }
            }

            // 1d. Context consistency — wave state and bucket zone
            $contextRejectReason = null;
            if ($waveRequired) {
                $waveState = (string)($s['wave_state'] ?? '');
                if ($waveState !== '' && $waveState !== 'unknown' && $waveState !== 'corrective') {
                    $contextRejectReason = 'final_context_inconsistent';
                }
            }
            if ($contextRejectReason === null && $corridorRequired) {
                $bucket = (int)($s['corridor_bucket'] ?? 0);
                if ($bucket > 0 && !in_array($bucket, $allowedLong, true)) {
                    $contextRejectReason = 'final_context_inconsistent';
                }
            }
            if ($contextRejectReason !== null) {
                // For setup_allowed A/B synthetic signals, apply warning mode instead of hard reject.
                $signalSetupClass = (string)($s['setup_class'] ?? '');
                $isSetupAllowedAB = $isSetupAllowed && in_array($signalSetupClass, $h4GateWarnSetupClasses, true);
                if ($h4FinalGatesMode === 'warning' && $isSetupAllowedAB) {
                    $setupAllowedFinalContextWarningTotal++;
                    $setupAllowedOldH4FinalGatesBypassedTotal++;
                    $s['final_context_inconsistent_warning'] = true;
                    $s['final_context_reject_reason_seen']   = $contextRejectReason;
                    // Fall through to quality check (do not continue/reject)
                } else {
                    $rejectedFinalContext++;
                    $signalOutcomeMap[$id] = ['winner' => false, 'reason' => $contextRejectReason];
                    $finalRejectDist[$contextRejectReason] = ($finalRejectDist[$contextRejectReason] ?? 0) + 1;
                    continue;
                }
            }

            // 1e. Final quality composite floor
            if ($minFinalQuality > 0.0 && (float)($s['candidate_quality_score'] ?? 0.0) < $minFinalQuality) {
                $rejectedFinalLowQuality++;
                $signalOutcomeMap[$id] = ['winner' => false, 'reason' => 'final_low_quality'];
                $finalRejectDist['final_low_quality'] = ($finalRejectDist['final_low_quality'] ?? 0) + 1;
                continue;
            }

            if ($isSetupAllowed) {
                $setupAllowedAfterElig++;
            }
            $eligible[] = $s;
        }

        $afterFinalEligibility = count($eligible);

        // Stage 2 — Winner selection: one signal per symbol (long-only, no side split needed)
        $bySymbol           = [];
        $winnerSignals      = [];
        $rejectedLoserByQuality = 0;

        foreach ($eligible as $s) {
            $sym = (string)($s['symbol'] ?? '');
            $bySymbol[$sym][] = $s;
        }

        foreach ($bySymbol as $group) {
            if (count($group) === 1) {
                $signalOutcomeMap[$group[0]['signal_id']] = ['winner' => true, 'reason' => null];
                $winnerSignals[] = $group[0];
                continue;
            }
            usort($group, static function (array $a, array $b): int {
                $qa = (float)($a['candidate_quality_score'] ?? 0.0);
                $qb = (float)($b['candidate_quality_score'] ?? 0.0);
                if ($qa !== $qb) {
                    return $qb <=> $qa;
                }
                $ca = (float)($a['confirmation_score'] ?? 0.0);
                $cb = (float)($b['confirmation_score'] ?? 0.0);
                if ($ca !== $cb) {
                    return $cb <=> $ca;
                }
                return strcmp(
                    (string)($b['detected_at'] ?? ''),
                    (string)($a['detected_at'] ?? '')
                );
            });

            $winner = $group[0];
            $signalOutcomeMap[$winner['signal_id']] = ['winner' => true, 'reason' => null];
            $winnerSignals[] = $winner;

            for ($i = 1, $n = count($group); $i < $n; $i++) {
                $loser = $group[$i];
                $signalOutcomeMap[$loser['signal_id']] = ['winner' => false, 'reason' => 'final_duplicate_removed'];
                $rejectedLoserByQuality++;
                $finalRejectDist['final_duplicate_removed'] = ($finalRejectDist['final_duplicate_removed'] ?? 0) + 1;
            }
        }

        $filterStats = [
            'before_final_eligibility'      => $beforeFinalEligibility,
            'after_final_eligibility'       => $afterFinalEligibility,
            'rejected_final_quality'        => $rejectedFinalQuality,
            'rejected_final_low_neckline'   => $rejectedFinalLowNeckline,
            'rejected_final_low_quality'    => $rejectedFinalLowQuality,
            'rejected_final_trend'          => $rejectedFinalTrend,
            'rejected_final_short_path'     => 0,  // long-only module: always 0
            'rejected_final_context'        => $rejectedFinalContext,
            'final_reject_reason_distribution' => $finalRejectDist,
            'before_winner_selection'       => $afterFinalEligibility,
            'after_winner_selection'        => count($winnerSignals),
            'rejected_loser_by_quality'     => $rejectedLoserByQuality,
            'rejected_missing_quality'      => $rejectedFinalQuality,
            'rejected_low_neckline'         => $rejectedFinalLowNeckline,
            // Setup-allowed final_eligibility tracking
            'setup_allowed_before_final_eligibility' => $setupAllowedBeforeElig,
            'setup_allowed_after_final_eligibility'  => $setupAllowedAfterElig,
            // Stop-width gate adaptive counters
            'final_stop_width_warning_total'          => $stopWidthWarningTotal,
            'final_stop_width_hard_reject_total'      => $stopWidthHardRejectTotal,
            'final_stop_width_bypassed_for_synthetic_total' => $stopWidthBypassedForSynthTotal,
            'final_stop_missing_for_synthetic_total'  => $stopMissingForSynthTotal,
            // H4 final gate warning counters for setup_allowed A/B signals
            'setup_allowed_final_trend_warning_total'         => $setupAllowedFinalTrendWarningTotal,
            'setup_allowed_final_context_warning_total'       => $setupAllowedFinalContextWarningTotal,
            'setup_allowed_old_h4_final_gates_bypassed_total' => $setupAllowedOldH4FinalGatesBypassedTotal,
        ];

        return [array_values($winnerSignals), $filterStats, $signalOutcomeMap];
    }

    private function accumulateStats(array $stats, array $result): array
    {
        $regime        = $result['market_regime']       ?? 'unknown';
        $pattern       = $result['primary_pattern']     ?? '';
        $fss           = $result['final_signal_status'] ?? '';
        $rejectReason  = $result['reject_reason']       ?? null;
        $confirmStatus = $result['confirm_status']      ?? '';
        $dbChecked     = (bool)($result['double_bottom_checked'] ?? false);
        $candidateFound = (bool)($result['candidate_found']      ?? false);

        $inc = static function (array &$s, string $key): void { $s[$key] = ($s[$key] ?? 0) + 1; };

        if ($regime === 'bullish')        { $inc($stats, 'regime_bullish_total'); }
        elseif ($regime === 'bearish')    { $inc($stats, 'regime_bearish_total'); }
        elseif ($regime === 'mixed')      { $inc($stats, 'regime_mixed_total'); }
        elseif ($regime === 'transition') { $inc($stats, 'regime_transition_total'); }

        $tDir = $result['trend_direction'] ?? 'unknown';
        if ($tDir === 'bullish' || $tDir === 'bearish') {
            $inc($stats, 'trend_pass_total');
        } else {
            $inc($stats, 'trend_rejected_total');
        }

        $bucketAllowedLong = (bool)($result['bucket_allowed_long'] ?? false);
        if ($bucketAllowedLong) {
            $inc($stats, 'bucket_allowed_total');
            $inc($stats, 'corridor_pass_total');
        } else {
            $inc($stats, 'bucket_rejected_total');
        }

        $wDir   = $result['wave_direction'] ?? 'unknown';
        $wState = $result['wave_state']     ?? 'unknown';
        if ($wDir === 'up' && $wState === 'corrective') {
            $inc($stats, 'wave_pass_total');
        } else {
            $inc($stats, 'wave_rejected_total');
        }

        if ($dbChecked) {
            $inc($stats, 'double_bottom_checked_total');
        }
        if ($candidateFound && $pattern === 'double_bottom') {
            $inc($stats, 'double_bottom_found_total');
        }
        if ($candidateFound) {
            $inc($stats, 'setup_candidates_total');
            $inc($stats, 'candidates_before_quality_filter_total');
            $qualityPass         = (bool)($result['quality_pass']         ?? true);
            $qualityRejectReason = $result['quality_reject_reason'] ?? null;
            if ($qualityPass) {
                $inc($stats, 'candidates_after_quality_filter_total');
            } else {
                $inc($stats, 'candidates_rejected_by_quality_total');
                if ($qualityRejectReason !== null && $qualityRejectReason !== '') {
                    $qdist = (array)($stats['quality_reject_reason_distribution'] ?? []);
                    $qdist[$qualityRejectReason] = ($qdist[$qualityRejectReason] ?? 0) + 1;
                    $stats['quality_reject_reason_distribution'] = $qdist;
                }
            }
        }
        if ($dbChecked && !$candidateFound && in_array($fss, ['rejected', 'no_signal'], true)) {
            $inc($stats, 'pattern_rejected_total');
            if ($rejectReason !== null && $rejectReason !== '') {
                $pdist = (array)($stats['pattern_reject_reason_distribution'] ?? []);
                $pdist[$rejectReason] = ($pdist[$rejectReason] ?? 0) + 1;
                $stats['pattern_reject_reason_distribution'] = $pdist;
            }
        }

        if ($confirmStatus === 'confirm_pass')     { $inc($stats, 'control_check_pass_total'); }
        if ($result['candidate_expired'] ?? false) {
            $inc($stats, 'control_check_expired_total');
            $inc($stats, 'candidate_expired_total');
        }
        if ($confirmStatus === 'confirm_waiting') {
            $inc($stats, 'candidate_waiting_confirm_total');
            $inc($stats, 'control_check_failed_total');
        }
        if ($confirmStatus === 'confirm_failed') {
            $inc($stats, 'candidate_confirm_failed_total');
            $inc($stats, 'control_check_failed_total');
        }

        if ($fss === 'emitted') { $inc($stats, 'signals_emitted_total'); }

        if ($rejectReason !== null && $rejectReason !== '') {
            $dist = (array)($stats['reject_reason_distribution'] ?? []);
            $dist[$rejectReason] = ($dist[$rejectReason] ?? 0) + 1;
            $stats['reject_reason_distribution'] = $dist;
        }

        $necklineDistReasons = ['price_too_far_above_neckline', 'price_too_far_below_neckline'];
        if ($rejectReason !== null && $rejectReason !== '') {
            if (str_contains($rejectReason, '_side_') && str_contains($rejectReason, '_mismatch') && str_starts_with($rejectReason, 'trend_')) {
                $inc($stats, 'rejected_by_trend_side_total');
            } elseif (str_starts_with($rejectReason, 'bucket_rejected_')) {
                $inc($stats, 'rejected_by_bucket_total');
            } elseif (str_starts_with($rejectReason, 'wave_') && str_contains($rejectReason, 'rejected')) {
                $inc($stats, 'rejected_by_wave_total');
            } elseif (in_array($rejectReason, $necklineDistReasons, true)) {
                $inc($stats, 'rejected_by_neckline_distance_total');
            }
        }
        if ($dbChecked && !$candidateFound) {
            $inc($stats, 'rejected_by_pattern_total');
        }

        // Coin trend context counters
        $ctxStatus = $result['coin_trend_context_status'] ?? null;
        if ($ctxStatus !== null) {
            $inc($stats, 'coin_trend_context_checked_total');
            if ($ctxStatus === 'ok')      { $inc($stats, 'coin_trend_context_pass_total'); }
            if ($ctxStatus === 'warning') { $inc($stats, 'coin_trend_context_warning_total'); }
            if ($ctxStatus === 'reject')  { $inc($stats, 'coin_trend_context_reject_total'); }
        }
        if ((bool)($result['active_falling_knife_detected'] ?? false)) {
            $inc($stats, 'active_falling_knife_reject_total');
        }
        if ((bool)($result['active_downtrend_detected'] ?? false)) {
            $inc($stats, 'active_downtrend_reject_total');
        }
        if ((bool)($result['post_dump_detected'] ?? false)) {
            $inc($stats, 'post_dump_detected_total');
        }
        if ((bool)($result['stabilization_detected'] ?? false)) {
            $inc($stats, 'stabilization_detected_total');
        }
        // S/R detection counter
        $srScore = (float)($result['support_resistance_score'] ?? -1.0);
        if ($srScore >= 0.0) {
            $inc($stats, 'support_resistance_detected_total');
        }
        if ((bool)($result['flat_base_detected'] ?? false)) {
            $inc($stats, 'flat_base_detected_total');
        }
        if ((bool)($result['reclaim_after_flat_detected'] ?? false)) {
            $inc($stats, 'reclaim_after_flat_detected_total');
        }
        if ((bool)($result['bearish_reversal_exception_used'] ?? false)) {
            $inc($stats, 'bearish_reversal_exception_used_total');
        } elseif ($rejectReason === 'regime_bearish_long_hard_block') {
            $inc($stats, 'bearish_reversal_exception_blocked_total');
        }
        if ((bool)($result['double_bottom_checked'] ?? false)) {
            $ctxStatus2 = $result['coin_trend_context_status'] ?? 'ok';
            if (in_array($ctxStatus2, ['ok', 'warning'], true) && !(bool)($result['active_falling_knife_detected'] ?? false)) {
                $inc($stats, 'double_bottom_context_pass_total');
            } else {
                $inc($stats, 'double_bottom_context_reject_total');
            }
        }

        // Entry-context prefilter counters
        $prefScore = $result['entry_context_prefilter_score'] ?? null;
        if ($prefScore !== null) {
            $inc($stats, 'entry_context_prefilter_checked_total');
            if ((bool)($result['entry_context_available'] ?? false)) {
                $inc($stats, 'entry_context_prefilter_pass_total');
            } else {
                $skipReason = $result['entry_context_skip_reason'] ?? null;
                if ($skipReason === 'skipped_entry_context_due_prefilter') {
                    $inc($stats, 'entry_context_prefilter_reject_total');
                }
            }
        }

        // Specific reject reason counters — must match reject_reason_distribution.
        // Note: processSymbol() returns final_signal_status='no_signal' for all non-emitted
        // symbols; the reject_reason_distribution is driven by $rejectReason regardless of
        // that status, so we use the same condition here.
        $primaryRej = (string)($result['primary_reject_reason'] ?? $rejectReason ?? '');
        if ($primaryRej !== '' && $rejectReason !== null && $rejectReason !== '') {
            if ($primaryRej !== 'regime_bearish_long_hard_block') {
                $inc($stats, 'specific_reject_reason_used_total');
            } else {
                $inc($stats, 'generic_bearish_reject_total');
            }
            // Did bearish regime contribute but we still have a specific reason?
            $secReasons = (array)($result['secondary_reject_reasons'] ?? []);
            if (in_array('regime_bearish_long_hard_block', $secReasons, true)) {
                $inc($stats, 'bearish_reject_with_specific_reason_total');
            }
            // Per-reason counters
            $specificCounterMap = [
                'active_falling_knife'                        => 'reject_active_falling_knife_total',
                'entry_context_unavailable'                   => 'reject_entry_context_unavailable_total',
                'skipped_entry_context_due_prefilter'         => 'reject_skipped_entry_context_due_prefilter_total',
                'no_post_dump_detected'                       => 'reject_no_post_dump_detected_total',
                'active_downtrend_no_stabilization'           => 'reject_active_downtrend_no_stabilization_total',
                'recent_dump_still_unstable'                  => 'reject_recent_dump_still_unstable_total',
                'no_flat_base_after_dump'                     => 'reject_no_flat_base_after_dump_total',
                'flat_base_too_wide'                          => 'reject_flat_base_too_wide_total',
                'base_support_broken'                         => 'reject_base_support_broken_total',
                'reclaim_after_flat_not_confirmed'            => 'reject_reclaim_after_flat_not_confirmed_total',
                'neckline_reclaim_not_confirmed'              => 'reject_neckline_reclaim_not_confirmed_total',
                'entry_too_far_after_neckline_reclaim'        => 'reject_entry_too_far_after_neckline_reclaim_total',
                'entry_too_far_after_reclaim'                 => 'reject_entry_too_far_after_reclaim_total',
                'no_post_dump_flat_reclaim'                   => 'reject_no_post_dump_flat_reclaim_total',
                'no_intraday_double_bottom'                   => 'reject_no_intraday_double_bottom_total',
                'setup_class_not_signal_allowed'              => 'reject_setup_class_not_signal_allowed_total',
                'classic_double_bottom_not_confirmed'         => 'reject_classic_double_bottom_not_confirmed_total',
            ];
            if (isset($specificCounterMap[$primaryRej])) {
                $inc($stats, $specificCounterMap[$primaryRej]);
            }
        }

        // Intraday double-bottom classification counters
        if ((bool)($result['entry_context_available'] ?? false)) {
            $inc($stats, 'intraday_double_bottom_checked_total');
            if ((bool)($result['intraday_double_bottom_detected'] ?? false)) {
                $inc($stats, 'intraday_double_bottom_detected_total');
                if ((bool)($result['neckline_reclaim_confirmed'] ?? false)) {
                    $inc($stats, 'intraday_double_bottom_reclaim_confirmed_total');
                }
            } else {
                $inc($stats, 'intraday_double_bottom_reject_total');
            }
        }

        // Setup class counters
        $setupClass = (string)($result['setup_class'] ?? 'none');
        if ($setupClass !== 'none' && $setupClass !== '') {
            $inc($stats, 'setup_class_checked_total');
            if ($setupClass === 'classic_intraday_double_bottom_reclaim') {
                $inc($stats, 'setup_class_classic_double_bottom_total');
            } elseif ($setupClass === 'post_dump_base_reclaim') {
                $inc($stats, 'setup_class_post_dump_base_reclaim_total');
            } elseif ($setupClass === 'diagnostic_recovery_context') {
                $inc($stats, 'setup_class_diagnostic_recovery_total');
            }
            if ((bool)($result['setup_signal_allowed'] ?? false)) {
                $inc($stats, 'setup_class_signal_allowed_total');
            } else {
                $inc($stats, 'setup_class_signal_blocked_total');
            }
        }

        // Setup-allowed funnel counters — track where setup_allowed symbols die.
        if ((bool)($result['setup_allowed'] ?? false)) {
            $inc($stats, 'setup_allowed_total');
            $killStage = (string)($result['setup_allowed_kill_stage'] ?? '');

            $inc($stats, 'setup_allowed_classic_pattern_checked_total');
            if ($killStage === 'classic_pattern') {
                $inc($stats, 'setup_allowed_classic_pattern_failed_total');
            } else {
                $inc($stats, 'setup_allowed_classic_pattern_pass_total');
                $inc($stats, 'setup_allowed_quality_checked_total');
                if ($killStage === 'quality') {
                    $inc($stats, 'setup_allowed_quality_failed_total');
                } elseif ($killStage === 'late_good_setup') {
                    // late_good_setup: quality passed but entry distance in soft zone — counted as pass
                    $inc($stats, 'setup_allowed_quality_pass_total');
                    $inc($stats, 'late_good_setup_detected_total');
                    if ((bool)($result['pending_confirmation_status'] ?? false)) {
                        $inc($stats, 'late_good_setup_waiting_pullback_total');
                        $inc($stats, 'pending_confirmation_added_better_entry_distance_total');
                    }
                } else {
                    $inc($stats, 'setup_allowed_quality_pass_total');
                    $inc($stats, 'setup_allowed_control_checked_total');
                    if ($killStage === 'control_check') {
                        $inc($stats, 'setup_allowed_control_failed_total');
                    } else {
                        $inc($stats, 'setup_allowed_control_pass_total');
                        if ($fss === 'emitted') {
                            $inc($stats, 'setup_allowed_signal_emitted_total');
                        }
                    }
                }
            }
        }

        // Entry-setup-allowed / old-gate-bypass / synthetic candidate counters
        if ((bool)($result['entry_setup_allowed'] ?? false)) {
            $inc($stats, 'entry_setup_allowed_total');
        }
        if ((bool)($result['entry_setup_blocked_by_safety'] ?? false)) {
            $inc($stats, 'entry_setup_blocked_by_safety_total');
        }
        if ((bool)($result['entry_setup_old_h4_gates_bypassed'] ?? false)) {
            $inc($stats, 'entry_setup_old_h4_gates_bypassed_total');
            if (count((array)($result['old_h4_gate_warning_reasons'] ?? [])) > 0) {
                $inc($stats, 'entry_setup_old_h4_gates_warning_total');
            }
        }
        if ((bool)($result['synthetic_candidate_built'] ?? false)) {
            $inc($stats, 'synthetic_candidate_built_total');
            $src = (string)($result['synthetic_candidate_source'] ?? '');
            if ($src === 'intraday_double_bottom') {
                $inc($stats, 'synthetic_candidate_intraday_double_bottom_total');
            } elseif ($src === 'post_dump_base_reclaim') {
                $inc($stats, 'synthetic_candidate_post_dump_base_reclaim_total');
            }
        }

        // Synthetic quality scorer counters
        if ((bool)($result['synthetic_quality_checked'] ?? false)) {
            $inc($stats, 'synthetic_quality_checked_total');
            $inc($stats, 'synthetic_quality_bypassed_old_h4_quality_total');
            $isIntraday = (bool)($result['synthetic_quality_is_intraday_db'] ?? false);
            if ($isIntraday) {
                $inc($stats, 'synthetic_quality_intraday_db_checked_total');
            }
            if ((bool)($result['synthetic_quality_pass'] ?? false)) {
                $inc($stats, 'synthetic_quality_pass_total');
                if ($isIntraday) {
                    $inc($stats, 'synthetic_quality_intraday_db_pass_total');
                }
                $synthSrc = (string)($result['synthetic_candidate_source'] ?? '');
                if ($synthSrc === 'intraday_double_bottom') {
                    $inc($stats, 'synthetic_quality_classic_intraday_db_pass_total');
                } elseif ($synthSrc === 'post_dump_base_reclaim') {
                    $inc($stats, 'synthetic_quality_post_dump_base_reclaim_pass_total');
                }
            } else {
                $inc($stats, 'synthetic_quality_failed_total');
                $inc($stats, 'reject_synthetic_candidate_quality_failed_total');
                if ($isIntraday) {
                    $inc($stats, 'synthetic_quality_intraday_db_failed_total');
                }
            }
            // Count generic entry context score warnings
            $warnings = (array)($result['synthetic_quality_warnings'] ?? []);
            if (in_array('generic_entry_context_score_low', $warnings, true)) {
                $inc($stats, 'synthetic_quality_generic_entry_context_warning_total');
            }
        } elseif (
            // Fallback: if synthetic_quality_checked field was lost in result merging,
            // recover counters from reject_reason / setup_allowed_kill_reason.
            (string)($result['reject_reason'] ?? '') === 'synthetic_candidate_quality_failed'
            || (string)($result['setup_allowed_kill_reason'] ?? '') === 'synthetic_candidate_quality_failed'
        ) {
            $inc($stats, 'synthetic_quality_checked_total');
            $inc($stats, 'synthetic_quality_bypassed_old_h4_quality_total');
            $inc($stats, 'synthetic_quality_failed_total');
            $inc($stats, 'reject_synthetic_candidate_quality_failed_total');
        }
        // Track old H4 quality_weak_structure rejects on non-synthetic candidates
        if (!((bool)($result['synthetic_candidate_built'] ?? false))
            && (string)($result['quality_reject_reason'] ?? '') !== ''
            && str_starts_with((string)($result['quality_reject_reason'] ?? ''), 'quality_weak_structure')
        ) {
            $inc($stats, 'reject_quality_weak_structure_total');
        }

        return $stats;
    }

    /**
     * Apply the batch-level signal-filter deltas to a stats array.
     * Used for both cumulative (stats.json) and cycle-local (cycle_stats.json).
     */
    private function applyFilterStatsDelta(array $s, array $filterStats): array
    {
        $s['signals_rejected_missing_quality_total'] =
            ($s['signals_rejected_missing_quality_total'] ?? 0) + $filterStats['rejected_missing_quality'];
        $s['signals_rejected_low_neckline_total'] =
            ($s['signals_rejected_low_neckline_total'] ?? 0) + $filterStats['rejected_low_neckline'];
        $s['signals_rejected_loser_by_quality_total'] =
            ($s['signals_rejected_loser_by_quality_total'] ?? 0) + $filterStats['rejected_loser_by_quality'];
        // Snapshot fields — reflect current tick's filter counts
        $s['signals_before_winner_selection_total'] = $filterStats['before_winner_selection'];
        $s['signals_after_winner_selection_total']  = $filterStats['after_winner_selection'];
        $s['signals_before_final_eligibility_total']    = $filterStats['before_final_eligibility'];
        $s['signals_after_final_eligibility_total']     = $filterStats['after_final_eligibility'];
        $s['signals_rejected_final_trend_total']        = $filterStats['rejected_final_trend'];
        $s['signals_rejected_final_context_total']      = $filterStats['rejected_final_context'];
        $s['signals_rejected_final_quality_total']      = $filterStats['rejected_final_quality'];
        $s['signals_rejected_final_low_neckline_total'] = $filterStats['rejected_final_low_neckline'];
        $s['signals_rejected_final_low_quality_total']  = $filterStats['rejected_final_low_quality'];
        // Running totals (additive)
        $s['signals_entered_final_eligibility_total'] =
            ($s['signals_entered_final_eligibility_total'] ?? 0) + $filterStats['before_final_eligibility'];
        $s['signals_rejected_during_finalization_total'] =
            ($s['signals_rejected_during_finalization_total'] ?? 0)
            + max(0, $filterStats['before_final_eligibility'] - $filterStats['after_final_eligibility']);
        $finalRejDist = (array)($s['final_reject_reason_distribution'] ?? []);
        foreach ((array)($filterStats['final_reject_reason_distribution'] ?? []) as $fReason => $fCnt) {
            $finalRejDist[$fReason] = ($finalRejDist[$fReason] ?? 0) + (int)$fCnt;
        }
        $s['final_reject_reason_distribution'] = empty($finalRejDist) ? (object)[] : $finalRejDist;
        // Setup-allowed final_eligibility tracking (additive running totals)
        $saBeforeElig = (int)($filterStats['setup_allowed_before_final_eligibility'] ?? 0);
        $saAfterElig  = (int)($filterStats['setup_allowed_after_final_eligibility']  ?? 0);
        $s['setup_allowed_final_eligibility_checked_total'] =
            ($s['setup_allowed_final_eligibility_checked_total'] ?? 0) + $saBeforeElig;
        $s['setup_allowed_final_eligibility_pass_total'] =
            ($s['setup_allowed_final_eligibility_pass_total'] ?? 0) + $saAfterElig;
        $s['setup_allowed_final_eligibility_failed_total'] =
            ($s['setup_allowed_final_eligibility_failed_total'] ?? 0) + max(0, $saBeforeElig - $saAfterElig);
        // Stop-width gate adaptive counters (additive running totals)
        $s['final_stop_width_warning_total'] =
            ($s['final_stop_width_warning_total'] ?? 0) + (int)($filterStats['final_stop_width_warning_total'] ?? 0);
        $s['final_stop_width_hard_reject_total'] =
            ($s['final_stop_width_hard_reject_total'] ?? 0) + (int)($filterStats['final_stop_width_hard_reject_total'] ?? 0);
        $s['final_stop_width_bypassed_for_synthetic_total'] =
            ($s['final_stop_width_bypassed_for_synthetic_total'] ?? 0) + (int)($filterStats['final_stop_width_bypassed_for_synthetic_total'] ?? 0);
        $s['final_stop_missing_for_synthetic_total'] =
            ($s['final_stop_missing_for_synthetic_total'] ?? 0) + (int)($filterStats['final_stop_missing_for_synthetic_total'] ?? 0);
        // H4 final gate warning counters
        $s['setup_allowed_final_trend_warning_total'] =
            ($s['setup_allowed_final_trend_warning_total'] ?? 0) + (int)($filterStats['setup_allowed_final_trend_warning_total'] ?? 0);
        $s['setup_allowed_final_context_warning_total'] =
            ($s['setup_allowed_final_context_warning_total'] ?? 0) + (int)($filterStats['setup_allowed_final_context_warning_total'] ?? 0);
        $s['setup_allowed_old_h4_final_gates_bypassed_total'] =
            ($s['setup_allowed_old_h4_final_gates_bypassed_total'] ?? 0) + (int)($filterStats['setup_allowed_old_h4_final_gates_bypassed_total'] ?? 0);
        return $s;
    }

    /**
     * Stamp the per-tick finalization fields onto a stats array.
     * Used for both cumulative (stats.json) and cycle-local (cycle_stats.json).
     */
    private function finalizeStats(array $s, int $total, int $totalProcessed, int $batchSz, int $activeSignals): array
    {
        $s['symbols_total']              = $total;
        $s['symbols_scanned']            = $totalProcessed;
        $s['symbols_skipped']            = 0;
        $s['current_batch_size']         = $batchSz;
        $s['last_updated_at']            = date('c');
        $s['signals_json_semantics']     = 'active_rolling_pool_across_cycles_ttl';
        $s['signals_active_final_total'] = $activeSignals;
        $s['final_signals_total']        = $activeSignals;
        foreach (['reject_reason_distribution', 'quality_reject_reason_distribution',
                  'pattern_reject_reason_distribution', 'final_reject_reason_distribution'] as $k) {
            if (empty($s[$k])) {
                $s[$k] = (object)[];
            }
        }
        return $s;
    }

    private function zeroStats(): array
    {
        return [
            'symbols_total'                => 0,
            'symbols_scanned'              => 0,
            'symbols_skipped'              => 0,
            'registry_loaded'              => false,
            'registry_symbol_count'        => 0,
            'regime_bullish_total'         => 0,
            'regime_bearish_total'         => 0,
            'regime_mixed_total'           => 0,
            'regime_transition_total'      => 0,
            'trend_pass_total'             => 0,
            'trend_rejected_total'         => 0,
            'corridor_pass_total'          => 0,
            'corridor_rejected_total'      => 0,
            'bucket_allowed_total'         => 0,
            'bucket_rejected_total'        => 0,
            'wave_pass_total'              => 0,
            'wave_rejected_total'          => 0,
            'double_bottom_checked_total'  => 0,
            'double_bottom_found_total'    => 0,
            'pattern_rejected_total'       => 0,
            'setup_candidates_total'       => 0,
            'candidates_before_quality_filter_total' => 0,
            'candidates_after_quality_filter_total'  => 0,
            'candidates_rejected_by_quality_total'   => 0,
            'quality_reject_reason_distribution'     => (object)[],
            'control_check_pass_total'        => 0,
            'control_check_expired_total'     => 0,
            'control_check_failed_total'      => 0,
            'rejected_by_trend_side_total'        => 0,
            'rejected_by_bucket_total'            => 0,
            'rejected_by_wave_total'              => 0,
            'rejected_by_pattern_total'           => 0,
            'rejected_by_neckline_distance_total' => 0,
            'candidate_waiting_confirm_total'  => 0,
            'candidate_expired_total'          => 0,
            'candidate_confirm_failed_total'   => 0,
            'signals_emitted_total'        => 0,
            'signals_active_final_total'   => 0,
            'final_signals_total'          => 0,
            'signals_before_final_eligibility_total'    => 0,
            'signals_after_final_eligibility_total'     => 0,
            'signals_rejected_final_trend_total'        => 0,
            'signals_rejected_final_context_total'      => 0,
            'signals_rejected_final_quality_total'      => 0,
            'signals_rejected_final_low_neckline_total' => 0,
            'signals_rejected_final_low_quality_total'  => 0,
            'signals_before_winner_selection_total'   => 0,
            'signals_after_winner_selection_total'    => 0,
            'signals_rejected_missing_quality_total'  => 0,
            'signals_rejected_low_neckline_total'     => 0,
            'signals_rejected_loser_by_quality_total' => 0,
            'signals_entered_final_eligibility_total'    => 0,
            'signals_rejected_during_finalization_total' => 0,
            'final_reject_reason_distribution'           => (object)[],
            'current_batch_size'           => 0,
            'last_updated_at'              => null,
            'reject_reason_distribution'         => (object)[],
            'pattern_reject_reason_distribution' => (object)[],
            // Coin trend context counters
            'coin_trend_context_checked_total'   => 0,
            'coin_trend_context_pass_total'      => 0,
            'coin_trend_context_warning_total'   => 0,
            'coin_trend_context_reject_total'    => 0,
            'active_falling_knife_reject_total'  => 0,
            'active_downtrend_reject_total'      => 0,
            'post_dump_detected_total'           => 0,
            'stabilization_detected_total'       => 0,
            'support_resistance_detected_total'  => 0,
            'flat_base_detected_total'           => 0,
            'reclaim_after_flat_detected_total'  => 0,
            'bearish_reversal_exception_used_total'    => 0,
            'bearish_reversal_exception_blocked_total' => 0,
            'double_bottom_context_pass_total'   => 0,
            'double_bottom_context_reject_total' => 0,
            // Entry-context prefilter counters
            'entry_context_prefilter_checked_total' => 0,
            'entry_context_prefilter_pass_total'    => 0,
            'entry_context_prefilter_reject_total'  => 0,
            // Specific reject reason counters
            'specific_reject_reason_used_total'              => 0,
            'generic_bearish_reject_total'                   => 0,
            'bearish_reject_with_specific_reason_total'      => 0,
            'reject_active_falling_knife_total'              => 0,
            'reject_entry_context_unavailable_total'         => 0,
            'reject_skipped_entry_context_due_prefilter_total' => 0,
            'reject_no_post_dump_detected_total'             => 0,
            'reject_active_downtrend_no_stabilization_total' => 0,
            'reject_recent_dump_still_unstable_total'        => 0,
            'reject_no_flat_base_after_dump_total'           => 0,
            'reject_flat_base_too_wide_total'                => 0,
            'reject_base_support_broken_total'               => 0,
            'reject_reclaim_after_flat_not_confirmed_total'  => 0,
            'reject_entry_too_far_after_reclaim_total'       => 0,
            'reject_no_post_dump_flat_reclaim_total'         => 0,
            'reject_no_intraday_double_bottom_total'         => 0,
            'reject_classic_double_bottom_not_confirmed_total' => 0,
            // Intraday double-bottom classification counters
            'intraday_double_bottom_checked_total'           => 0,
            'intraday_double_bottom_detected_total'          => 0,
            'intraday_double_bottom_reclaim_confirmed_total' => 0,
            'intraday_double_bottom_reject_total'            => 0,
            // Setup class counters
            'setup_class_checked_total'                      => 0,
            'setup_class_classic_double_bottom_total'        => 0,
            'setup_class_post_dump_base_reclaim_total'       => 0,
            'setup_class_diagnostic_recovery_total'          => 0,
            'setup_class_signal_allowed_total'               => 0,
            'setup_class_signal_blocked_total'               => 0,
            // Additional specific reject reason counters
            'reject_neckline_reclaim_not_confirmed_total'       => 0,
            'reject_entry_too_far_after_neckline_reclaim_total' => 0,
            'reject_setup_class_not_signal_allowed_total'       => 0,
            // Setup-allowed funnel counters (per-stage after setup_class gate passes)
            'setup_allowed_total'                               => 0,
            'setup_allowed_classic_pattern_checked_total'       => 0,
            'setup_allowed_classic_pattern_pass_total'          => 0,
            'setup_allowed_classic_pattern_failed_total'        => 0,
            'setup_allowed_quality_checked_total'               => 0,
            'setup_allowed_quality_pass_total'                  => 0,
            'setup_allowed_quality_failed_total'                => 0,
            'setup_allowed_control_checked_total'               => 0,
            'setup_allowed_control_pass_total'                  => 0,
            'setup_allowed_control_failed_total'                => 0,
            'setup_allowed_final_eligibility_checked_total'     => 0,
            'setup_allowed_final_eligibility_pass_total'        => 0,
            'setup_allowed_final_eligibility_failed_total'      => 0,
            'setup_allowed_signal_emitted_total'                => 0,
            // Entry-setup-allowed / old-gate-bypass / synthetic candidate counters
            'entry_setup_allowed_total'                          => 0,
            'entry_setup_blocked_by_safety_total'                => 0,
            'entry_setup_old_h4_gates_bypassed_total'            => 0,
            'entry_setup_old_h4_gates_warning_total'             => 0,
            'synthetic_candidate_built_total'                    => 0,
            'synthetic_candidate_intraday_double_bottom_total'   => 0,
            'synthetic_candidate_post_dump_base_reclaim_total'   => 0,
            // Synthetic quality scorer counters
            'synthetic_quality_checked_total'                    => 0,
            'synthetic_quality_pass_total'                       => 0,
            'synthetic_quality_failed_total'                     => 0,
            'synthetic_quality_bypassed_old_h4_quality_total'    => 0,
            'synthetic_quality_classic_intraday_db_pass_total'   => 0,
            'synthetic_quality_post_dump_base_reclaim_pass_total' => 0,
            'reject_synthetic_candidate_quality_failed_total'    => 0,
            'reject_quality_weak_structure_total'                => 0,
            // Adaptive stop-width gate counters
            'final_stop_width_warning_total'                     => 0,
            'final_stop_width_hard_reject_total'                 => 0,
            'final_stop_width_bypassed_for_synthetic_total'      => 0,
            'final_stop_missing_for_synthetic_total'             => 0,
            // H4 final gate warning counters
            'setup_allowed_final_trend_warning_total'            => 0,
            'setup_allowed_final_context_warning_total'          => 0,
            'setup_allowed_old_h4_final_gates_bypassed_total'    => 0,
            // Late good setup counters
            'late_good_setup_detected_total'                     => 0,
            'late_good_setup_waiting_pullback_total'             => 0,
            'pending_confirmation_added_better_entry_distance_total' => 0,
        ];
    }

    /**
     * Compute signal TTL in seconds from config (signal_ttl_bars × H4 bar duration).
     * Single authoritative place for this calculation.
     */
    private function signalTtlSec(array $config): int
    {
        $bars = (int)($config['signal_ttl_bars'] ?? 2);
        // H4_INTERVAL is in minutes (240); convert to seconds per bar.
        return max(1, $bars) * (int)self::H4_INTERVAL * 60;
    }

    /**
     * Remove signals from signals.json that have exceeded signal_ttl_bars * H4 seconds.
     * Expired signals are dropped entirely so signals.json stays coherent across cycles.
     */
    private function expireSignals(array $signals, array $config): array
    {
        if ((int)($config['signal_ttl_bars'] ?? 2) <= 0) {
            return $signals;
        }
        $ttlSec = $this->signalTtlSec($config);
        $now    = time();
        return array_values(
            array_filter($signals, static function (array $s) use ($now, $ttlSec): bool {
                $detectedAt = $s['detected_at'] ?? '';
                if ($detectedAt === '') {
                    return false;
                }
                $ts = strtotime($detectedAt);
                return $ts !== false && ($ts + $ttlSec) > $now;
            })
        );
    }

    // =========================================================================
    // Coin trend context — post-dump stabilization gate
    // =========================================================================

    /**
     * Analyse coin-level trend context and detect post-dump stabilization conditions.
     *
     * Returns a full context array consumed by tryLong() to:
     *   1. Hard-reject active falling knives.
     *   2. Allow a bearish_reversal_exception when post-dump → flat/base → reclaim
     *      conditions are all met.
     *
     * @param array $candles    H4 candles — used for slope / trend / pattern logic.
     * @param array $ctxCandles Short-timeframe candles (1m/5m) — used for dump /
     *                          stabilization / flat-base / reclaim / S/R detection.
     *                          Falls back to $candles when empty.
     * @param array $config     Strategy config.
     */
    private function pipelineCoinTrendContext(array $candles, array $ctxCandles, array $config): array
    {
        $enabled = (bool)($config['coin_trend_context_enabled'] ?? true);

        // Derive the interval of the context candles for bar-count conversions.
        $ctxInterval    = (string)($config['entry_context_interval'] ?? '1');
        $ctxIntervalMin = max(1, (int)$ctxInterval);  // 1m, 5m, 15m etc.

        // Neutral context returned when the feature is disabled
        $neutral = static function () use ($ctxInterval): array {
            return [
                'coin_trend_context_status'      => 'ok',
                'coin_trend_context_score'       => 10.0,
                'coin_trend_context_reason'      => 'disabled',
                'short_trend_slope_pct'          => null,
                'mid_trend_slope_pct'            => null,
                'long_trend_slope_pct'           => null,
                'recent_lower_low_count'         => 0,
                'recent_lower_high_count'        => 0,
                'recent_dump_15m_pct'            => 0.0,
                'recent_dump_1h_pct'             => 0.0,
                'distance_from_recent_high_pct'  => 0.0,
                'distance_from_recent_low_pct'   => 0.0,
                'active_downtrend_detected'      => false,
                'active_falling_knife_detected'  => false,
                'post_dump_detected'             => false,
                'post_dump_drop_pct'             => 0.0,
                'stabilization_detected'         => false,
                'stabilization_bars'             => 0,
                'stabilization_range_width_pct'  => 0.0,
                'stabilization_slope_pct'        => 0.0,
                'stabilization_score'            => 0.0,
                'flat_base_detected'             => false,
                'flat_base_low'                  => null,
                'flat_base_high'                 => null,
                'flat_base_width_pct'            => 0.0,
                'flat_base_touches'              => 0,
                'flat_base_score'                => 0.0,
                'reclaim_after_flat_detected'    => false,
                'reclaim_level'                  => null,
                'reclaim_confirmed_bars'         => 0,
                'reclaim_strength_pct'           => 0.0,
                'reclaim_score'                  => 0.0,
                'entry_distance_from_reclaim_pct' => 0.0,
                // Support/resistance
                'support_level'                  => null,
                'resistance_level'               => null,
                'neckline_level'                 => null,
                'base_low'                       => null,
                'base_high'                      => null,
                'base_width_pct'                 => 0.0,
                'support_touches'                => 0,
                'resistance_touches'             => 0,
                'support_resistance_score'       => 0.0,
                'support_broken'                 => false,
                'setup_context_type'             => 'standard',
                'reversal_context_score'         => 0.0,
                'entry_context_score'            => 10.0,
                'ctx_interval'                   => $ctxInterval,
                'ctx_candles_count'              => 0,
                // Intraday double-bottom detection
                'intraday_double_bottom_detected'       => false,
                'bottom_1_price'                        => null,
                'bottom_1_index'                        => null,
                'bottom_2_price'                        => null,
                'bottom_2_index'                        => null,
                'bottom_low_diff_pct'                   => 0.0,
                'second_low_break_pct'                  => 0.0,
                'intraday_db_neckline_level'            => null,
                'neckline_bounce_pct'                   => 0.0,
                'neckline_reclaim_confirmed'            => false,
                'neckline_reclaim_confirm_bars'         => 0,
                'entry_distance_from_neckline_pct'      => 0.0,
                'intraday_double_bottom_score'          => 0.0,
                'intraday_double_bottom_reason'         => 'disabled',
                // Setup class
                'setup_class'          => 'none',
                'setup_class_score'    => 0.0,
                'setup_class_reason'   => 'disabled',
                'setup_class_warnings' => [],
                'setup_signal_allowed' => false,
            ];
        };

        if (!$enabled || count($candles) < 5) {
            return $neutral();
        }

        // If no short-TF candles were fetched successfully, fall back to H4.
        $ctxCandlesUsed = count($ctxCandles) >= 5 ? $ctxCandles : $candles;
        $ctxUsedInterval = count($ctxCandles) >= 5 ? $ctxInterval : self::H4_INTERVAL;

        $n = count($candles);

        // --- Slope calculations: always on H4 candles ----------------------
        $shortLb = min((int)($config['trend_lookback_short_candles'] ?? 60),  $n);
        $midLb   = min((int)($config['trend_lookback_mid_candles']   ?? 240), $n);
        $longLb  = min((int)($config['trend_lookback_long_candles']  ?? 720), $n);

        $shortSlice = array_slice($candles, $n - $shortLb);
        $midSlice   = array_slice($candles, $n - $midLb);
        $longSlice  = array_slice($candles, $n - $longLb);

        $shortSlopePct = $this->computeSlopePct($shortSlice);
        $midSlopePct   = $this->computeSlopePct($midSlice);
        $longSlopePct  = $this->computeSlopePct($longSlice);

        // --- Intraday checks: on context candles ----------------------------
        $ctxN = count($ctxCandlesUsed);
        $ctxShortLb = min((int)($config['trend_lookback_short_candles'] ?? 60), $ctxN);
        $ctxShortSlice = array_slice($ctxCandlesUsed, $ctxN - $ctxShortLb);

        $recentLowerLowCount  = $this->countRecentLowerLows($ctxShortSlice);
        $recentLowerHighCount = $this->countRecentLowerHighs($ctxShortSlice);

        // Compute how many bars approximate 15 min and 1 h on the context interval.
        $ctxIntervalMinUsed = count($ctxCandles) >= 5 ? $ctxIntervalMin : (int)self::H4_INTERVAL;
        $dump15mBars = max(1, (int)round(15 / $ctxIntervalMinUsed));
        $dump1hBars  = max(1, (int)round(60 / $ctxIntervalMinUsed));

        $recentDump15mPct = $this->estimateRecentDump($ctxCandlesUsed, $dump15mBars);
        $recentDump1hPct  = $this->estimateRecentDump($ctxCandlesUsed, $dump1hBars);

        [$distFromHighPct, $distFromLowPct] = $this->distanceFromRecentHighLow($ctxCandlesUsed, $ctxN);

        $maxLowerLows = (int)($config['max_recent_lower_low_count'] ?? 2);
        $maxDownSlope = (float)($config['max_recent_down_slope_pct'] ?? -1.5);
        $maxDump15m   = (float)($config['max_recent_dump_15m_pct']  ?? 5.0);
        $maxDump1h    = (float)($config['max_recent_dump_1h_pct']   ?? 9.0);

        // Active downtrend: slope steeply negative AND multiple fresh lower lows
        $activeDowntrend = (bool)($config['active_downtrend_block_enabled'] ?? true)
            && ($shortSlopePct < $maxDownSlope)
            && ($recentLowerLowCount > $maxLowerLows);

        // Falling knife: very steep recent dump still in progress (no stabilization)
        $activeFallingKnife = (
            $recentDump15mPct > $maxDump15m
            && $recentLowerLowCount > 0
            && $shortSlopePct < $maxDownSlope
        ) || (
            $recentDump1hPct > $maxDump1h
            && $shortSlopePct < ($maxDownSlope * 1.5)
        );

        // Sub-checks: post-dump stabilization, flat base, reclaim
        $pdCtx = [
            'short_slope_pct'        => $shortSlopePct,
            'dist_from_high_pct'     => $distFromHighPct,
            'active_falling_knife'   => $activeFallingKnife,
        ];

        $postDumpResult = (bool)($config['post_dump_stabilization_enabled'] ?? true)
            ? $this->detectPostDumpStabilization($ctxCandlesUsed, $config, $pdCtx)
            : ['post_dump_detected' => false, 'post_dump_drop_pct' => 0.0,
               'stabilization_detected' => false, 'stabilization_bars' => 0,
               'stabilization_range_width_pct' => 0.0, 'stabilization_slope_pct' => 0.0,
               'low_hold_score' => 0.0, 'stabilization_score' => 0.0,
               'stabilization_reason' => 'disabled', 'stabilization_warnings' => []];

        $flatBaseResult = (bool)($config['flat_base_enabled'] ?? true)
            ? $this->detectFlatBaseAfterDump($ctxCandlesUsed, $config, $postDumpResult)
            : ['flat_base_detected' => false, 'flat_base_low' => null, 'flat_base_high' => null,
               'flat_base_mid' => null, 'flat_base_width_pct' => 0.0, 'flat_base_touches' => 0,
               'flat_base_score' => 0.0, 'flat_base_reason' => 'disabled'];

        $reclaimResult = (bool)($config['reclaim_after_flat_required'] ?? true)
            ? $this->detectReclaimAfterFlatBase($ctxCandlesUsed, $config, $flatBaseResult)
            : ['reclaim_after_flat_detected' => false, 'reclaim_level' => null,
               'reclaim_confirmed_bars' => 0, 'reclaim_strength_pct' => 0.0,
               'reclaim_score' => 0.0, 'entry_distance_from_reclaim_pct' => 0.0,
               'reclaim_reason' => 'disabled'];

        $srResult = (bool)($config['support_resistance_enabled'] ?? true)
            ? $this->detectSupportResistanceLevels($ctxCandlesUsed, $config)
            : ['support_level' => null, 'resistance_level' => null, 'neckline_level' => null,
               'base_low' => null, 'base_high' => null, 'base_mid' => null, 'base_width_pct' => 0.0,
               'support_touches' => 0, 'resistance_touches' => 0,
               'support_resistance_score' => 0.0, 'support_resistance_reason' => 'disabled',
               'support_broken' => false];

        // Reversal context score (0–10): used by bearish_reversal_exception check
        $reversalScore = $this->computeReversalContextScore(
            $postDumpResult, $flatBaseResult, $reclaimResult,
            $activeFallingKnife, $activeDowntrend, $config
        );
        // Entry context score: higher is better; includes non-reversal signals
        $entryContextScore = $reversalScore;
        if (!$activeFallingKnife && !$activeDowntrend) {
            $entryContextScore = min(10.0, $reversalScore + 1.0);
        }

        // ── Intraday double-bottom detection on entry-context candles ─────────
        $intradayDbResult = $this->detectIntradayDoubleBottom($ctxCandlesUsed, $config);

        // ── Setup class classification ────────────────────────────────────────
        // Build a flat context for classifyReversalSetup (merge all sub-results).
        $classifyCtx = array_merge(
            $postDumpResult, $flatBaseResult, $reclaimResult, $srResult,
            [
                'active_falling_knife_detected'   => $activeFallingKnife,
                'entry_context_score'             => $entryContextScore,
            ],
            $intradayDbResult
        );
        $setupClass = $this->classifyReversalSetup($classifyCtx, $config);

        // Determine status
        $status = 'ok';
        $reason = 'ok';

        if ($activeFallingKnife) {
            $status = 'reject';
            $reason = 'active_falling_knife';
        } elseif ($activeDowntrend && !(bool)($postDumpResult['stabilization_detected'] ?? false)) {
            $status = 'reject';
            $reason = 'active_downtrend_no_stabilization';
        } elseif ($recentLowerLowCount > $maxLowerLows + 1) {
            $status = 'reject';
            $reason = 'fresh_lower_low_sequence';
        } elseif ($recentDump1hPct > $maxDump1h && !(bool)($postDumpResult['stabilization_detected'] ?? false)) {
            $status = 'reject';
            $reason = 'recent_dump_still_unstable';
        } elseif ($reversalScore < (float)($config['min_reversal_context_score'] ?? 7.0)
               && $activeDowntrend) {
            $status = 'reject';
            $reason = 'coin_trend_context_too_weak';
        } elseif ($shortSlopePct < 0 && $recentLowerLowCount > 0) {
            $status = 'warning';
            $reason = 'weak_trend_warning';
        }

        // Determine setup_context_type
        $setupContextType = 'standard';
        if ((bool)($postDumpResult['stabilization_detected'] ?? false)
            && (bool)($flatBaseResult['flat_base_detected']  ?? false)
            && (bool)($reclaimResult['reclaim_after_flat_detected'] ?? false)
        ) {
            $setupContextType = 'post_dump_flat_reversal';
        } elseif ((bool)($postDumpResult['post_dump_detected'] ?? false)) {
            $setupContextType = 'post_dump';
        }

        return [
            'coin_trend_context_status'      => $status,
            'coin_trend_context_score'       => round($reversalScore, 2),
            'coin_trend_context_reason'      => $reason,
            'short_trend_slope_pct'          => round($shortSlopePct, 4),
            'mid_trend_slope_pct'            => round($midSlopePct, 4),
            'long_trend_slope_pct'           => round($longSlopePct, 4),
            'recent_lower_low_count'         => $recentLowerLowCount,
            'recent_lower_high_count'        => $recentLowerHighCount,
            'recent_dump_15m_pct'            => round($recentDump15mPct, 4),
            'recent_dump_1h_pct'             => round($recentDump1hPct, 4),
            'distance_from_recent_high_pct'  => round($distFromHighPct, 4),
            'distance_from_recent_low_pct'   => round($distFromLowPct, 4),
            'active_downtrend_detected'      => $activeDowntrend,
            'active_falling_knife_detected'  => $activeFallingKnife,
            'post_dump_detected'             => (bool)($postDumpResult['post_dump_detected']       ?? false),
            'post_dump_drop_pct'             => (float)($postDumpResult['post_dump_drop_pct']       ?? 0.0),
            'stabilization_detected'         => (bool)($postDumpResult['stabilization_detected']   ?? false),
            'stabilization_bars'             => (int)($postDumpResult['stabilization_bars']         ?? 0),
            'stabilization_range_width_pct'  => (float)($postDumpResult['stabilization_range_width_pct'] ?? 0.0),
            'stabilization_slope_pct'        => (float)($postDumpResult['stabilization_slope_pct'] ?? 0.0),
            'stabilization_score'            => (float)($postDumpResult['stabilization_score']     ?? 0.0),
            'flat_base_detected'             => (bool)($flatBaseResult['flat_base_detected']       ?? false),
            'flat_base_low'                  => $flatBaseResult['flat_base_low']                   ?? null,
            'flat_base_high'                 => $flatBaseResult['flat_base_high']                  ?? null,
            'flat_base_width_pct'            => (float)($flatBaseResult['flat_base_width_pct']     ?? 0.0),
            'flat_base_touches'              => (int)($flatBaseResult['flat_base_touches']          ?? 0),
            'flat_base_score'                => (float)($flatBaseResult['flat_base_score']          ?? 0.0),
            'reclaim_after_flat_detected'    => (bool)($reclaimResult['reclaim_after_flat_detected'] ?? false),
            'reclaim_level'                  => $reclaimResult['reclaim_level']                    ?? null,
            'reclaim_confirmed_bars'         => (int)($reclaimResult['reclaim_confirmed_bars']      ?? 0),
            'reclaim_strength_pct'           => (float)($reclaimResult['reclaim_strength_pct']     ?? 0.0),
            'reclaim_score'                  => (float)($reclaimResult['reclaim_score']             ?? 0.0),
            'entry_distance_from_reclaim_pct' => (float)($reclaimResult['entry_distance_from_reclaim_pct'] ?? 0.0),
            // Support / resistance
            'support_level'                  => $srResult['support_level']              ?? null,
            'resistance_level'               => $srResult['resistance_level']           ?? null,
            'neckline_level'                 => $srResult['neckline_level']             ?? null,
            'base_low'                       => $srResult['base_low']                  ?? null,
            'base_high'                      => $srResult['base_high']                 ?? null,
            'base_width_pct'                 => (float)($srResult['base_width_pct']    ?? 0.0),
            'support_touches'                => (int)($srResult['support_touches']      ?? 0),
            'resistance_touches'             => (int)($srResult['resistance_touches']   ?? 0),
            'support_resistance_score'       => (float)($srResult['support_resistance_score'] ?? 0.0),
            'support_broken'                 => (bool)($srResult['support_broken']      ?? false),
            'setup_context_type'             => $setupContextType,
            'reversal_context_score'         => round($reversalScore, 2),
            'entry_context_score'            => round($entryContextScore, 2),
            'ctx_interval'                   => $ctxUsedInterval,
            'ctx_candles_count'              => count($ctxCandlesUsed),
            // Intraday double-bottom detection
            'intraday_double_bottom_detected'       => (bool)($intradayDbResult['intraday_double_bottom_detected'] ?? false),
            'bottom_1_price'                        => $intradayDbResult['bottom_1_price']                   ?? null,
            'bottom_1_index'                        => $intradayDbResult['bottom_1_index']                   ?? null,
            'bottom_2_price'                        => $intradayDbResult['bottom_2_price']                   ?? null,
            'bottom_2_index'                        => $intradayDbResult['bottom_2_index']                   ?? null,
            'bottom_low_diff_pct'                   => (float)($intradayDbResult['bottom_low_diff_pct']      ?? 0.0),
            'second_low_break_pct'                  => (float)($intradayDbResult['second_low_break_pct']     ?? 0.0),
            'intraday_db_neckline_level'            => $intradayDbResult['intraday_db_neckline_level']       ?? null,
            'neckline_bounce_pct'                   => (float)($intradayDbResult['neckline_bounce_pct']      ?? 0.0),
            'neckline_reclaim_confirmed'            => (bool)($intradayDbResult['neckline_reclaim_confirmed'] ?? false),
            'neckline_reclaim_confirm_bars'         => (int)($intradayDbResult['neckline_reclaim_confirm_bars'] ?? 0),
            'entry_distance_from_neckline_pct'      => (float)($intradayDbResult['entry_distance_from_neckline_pct'] ?? 0.0),
            'intraday_double_bottom_score'          => (float)($intradayDbResult['intraday_double_bottom_score'] ?? 0.0),
            'intraday_double_bottom_reason'         => (string)($intradayDbResult['intraday_double_bottom_reason'] ?? 'not_run'),
            // Setup class
            'setup_class'          => $setupClass['setup_class'],
            'setup_class_score'    => $setupClass['setup_class_score'],
            'setup_class_reason'   => $setupClass['setup_class_reason'],
            'setup_class_warnings' => $setupClass['setup_class_warnings'],
            'setup_signal_allowed' => $setupClass['setup_signal_allowed'],
        ];
    }

    /**
     * Classify the intraday reversal setup into an explicit setup class.
     *
     * A — classic_intraday_double_bottom_reclaim
     * B — post_dump_base_reclaim
     * C — diagnostic_recovery_context (no signal permitted)
     * none — no recovery context at all
     *
     * @param array $ctx Merged coin-trend context (from pipelineCoinTrendContext).
     * @param array $config Strategy configuration.
     */
    private function classifyReversalSetup(array $ctx, array $config): array
    {
        $disabled = [
            'setup_class'          => 'none',
            'setup_class_score'    => 0.0,
            'setup_class_reason'   => 'disabled',
            'setup_class_warnings' => [],
            'setup_signal_allowed' => false,
        ];

        if (!(bool)($config['intraday_setup_classification_enabled'] ?? true)) {
            return $disabled;
        }

        $knife         = (bool)($ctx['active_falling_knife_detected'] ?? false);
        $supportBroken = (bool)($ctx['support_broken']                ?? false);

        // ── A-class: classic_intraday_double_bottom_reclaim ───────────────────
        $idbDetected   = (bool)($ctx['intraday_double_bottom_detected'] ?? false);
        $idbScore      = (float)($ctx['intraday_double_bottom_score']   ?? 0.0);
        $idbMinScore   = (float)($config['intraday_double_bottom_min_score'] ?? 7.5);
        $neckReclaim   = (bool)($ctx['neckline_reclaim_confirmed']      ?? false);
        $entryDistNeck = (float)($ctx['entry_distance_from_neckline_pct'] ?? 0.0);
        $maxDistNeck   = (float)($config['double_bottom_max_entry_distance_from_neckline_pct'] ?? 3.0);

        if ($idbDetected && $neckReclaim && !$knife && !$supportBroken
            && ($maxDistNeck <= 0.0 || $entryDistNeck <= $maxDistNeck)
            && $idbScore >= $idbMinScore
        ) {
            return [
                'setup_class'          => 'classic_intraday_double_bottom_reclaim',
                'setup_class_score'    => round($idbScore, 2),
                'setup_class_reason'   => 'pass',
                'setup_class_warnings' => [],
                'setup_signal_allowed' => true,
            ];
        }

        // ── B-class: post_dump_base_reclaim ───────────────────────────────────
        $bEnabled       = (bool)($config['post_dump_base_reclaim_enabled']               ?? true);
        $bMinScore      = (float)($config['post_dump_base_reclaim_min_score']            ?? 7.5);
        $bReqSupport    = (bool)($config['post_dump_base_reclaim_requires_support_hold'] ?? true);
        $bReqReclaim    = (bool)($config['post_dump_base_reclaim_requires_reclaim']      ?? true);
        $bMaxEntryDst   = (float)($config['max_entry_distance_from_reclaim_pct']         ?? 2.5);

        $postDump   = (bool)($ctx['post_dump_detected']         ?? false);
        $stab       = (bool)($ctx['stabilization_detected']     ?? false);
        $flatBase   = (bool)($ctx['flat_base_detected']         ?? false);
        $reclaim    = (bool)($ctx['reclaim_after_flat_detected'] ?? false);
        $entryDist  = (float)($ctx['entry_distance_from_reclaim_pct'] ?? 0.0);
        $ctxScore   = (float)($ctx['entry_context_score']       ?? 0.0);

        $supportHoldOk = !$bReqSupport || !$supportBroken;
        $reclaimOk     = !$bReqReclaim || $reclaim;

        if ($bEnabled && $postDump && $stab && $flatBase && $supportHoldOk && $reclaimOk
            && !$knife
            && ($bMaxEntryDst <= 0.0 || $entryDist <= $bMaxEntryDst)
            && $ctxScore >= $bMinScore
        ) {
            return [
                'setup_class'          => 'post_dump_base_reclaim',
                'setup_class_score'    => round($ctxScore, 2),
                'setup_class_reason'   => 'pass',
                'setup_class_warnings' => [],
                'setup_signal_allowed' => true,
            ];
        }

        // ── C-class: diagnostic_recovery_context ─────────────────────────────
        if ($postDump || $stab || $idbDetected) {
            $cReason = $knife ? 'falling_knife'
                : (!$postDump ? 'no_post_dump'
                : (!$stab ? 'no_stabilization'
                : (!$flatBase ? 'no_flat_base'
                : (!$reclaimOk ? 'no_reclaim'
                : ($supportBroken ? 'support_broken'
                : ($ctxScore < $bMinScore ? 'score_too_low'
                : 'conditions_not_met'))))));
            return [
                'setup_class'          => 'diagnostic_recovery_context',
                'setup_class_score'    => round($ctxScore, 2),
                'setup_class_reason'   => $cReason,
                'setup_class_warnings' => [],
                'setup_signal_allowed' => false,
            ];
        }

        return [
            'setup_class'          => 'none',
            'setup_class_score'    => 0.0,
            'setup_class_reason'   => 'no_recovery_context',
            'setup_class_warnings' => [],
            'setup_signal_allowed' => false,
        ];
    }

    /**
     * Detect an intraday classic double-bottom structure on entry-context candles.
     *
     * Pattern: dump → bottom_1 → neckline bounce → bottom_2/low hold → neckline reclaim → confirmation
     *
     * Returns diagnostics for all 14 specified fields. `intraday_double_bottom_detected`
     * is true only when every pass condition is satisfied. When no full pass is found the
     * best near-miss candidate is retained and its first failing condition is reported as
     * `intraday_double_bottom_reason`.
     */
    private function detectIntradayDoubleBottom(array $candles, array $config): array
    {
        $empty = [
            'intraday_double_bottom_detected'       => false,
            'bottom_1_price'                        => null,
            'bottom_1_index'                        => null,
            'bottom_2_price'                        => null,
            'bottom_2_index'                        => null,
            'bottom_low_diff_pct'                   => 0.0,
            'second_low_break_pct'                  => 0.0,
            'intraday_db_neckline_level'            => null,
            'neckline_bounce_pct'                   => 0.0,
            'neckline_reclaim_confirmed'            => false,
            'neckline_reclaim_confirm_bars'         => 0,
            'entry_distance_from_neckline_pct'      => 0.0,
            'intraday_double_bottom_score'          => 0.0,
            'intraday_double_bottom_reason'         => 'disabled',
        ];

        if (!(bool)($config['intraday_double_bottom_enabled'] ?? true)) {
            return $empty;
        }

        $n = count($candles);
        if ($n < 10) {
            return array_merge($empty, ['intraday_double_bottom_reason' => 'not_enough_candles']);
        }

        $minSep      = max(2, (int)($config['double_bottom_min_separation_bars']                ?? 5));
        $maxSep      = max($minSep + 1, (int)($config['double_bottom_max_separation_bars']      ?? 80));
        $pivWin      = max(1, (int)($config['double_bottom_pivot_window']                        ?? 2));
        $lowTolPct   = (float)($config['double_bottom_low_tolerance_pct']                       ?? 3.0);
        $maxBreakPct = (float)($config['double_bottom_max_second_low_break_pct']                ?? 1.5);
        $minBnc      = (float)($config['double_bottom_neckline_min_bounce_pct']                 ?? 1.5);
        $minConfBars = (int)($config['double_bottom_reclaim_confirm_bars']                       ?? 2);
        $maxEntryDst = (float)($config['double_bottom_max_entry_distance_from_neckline_pct']    ?? 3.0);

        // ── Find pivot lows ───────────────────────────────────────────────────
        $pivotLows = [];
        for ($i = $pivWin; $i < $n - $pivWin; $i++) {
            $low    = $candles[$i]['low'];
            $isPivot = true;
            for ($j = $i - $pivWin; $j <= $i + $pivWin; $j++) {
                if ($j !== $i && ($candles[$j]['low'] ?? 0.0) <= $low) {
                    $isPivot = false;
                    break;
                }
            }
            if ($isPivot) {
                $pivotLows[] = ['idx' => $i, 'price' => $low];
            }
        }

        if (count($pivotLows) < 2) {
            return array_merge($empty, ['intraday_double_bottom_reason' => 'no_intraday_double_bottom']);
        }

        $lastClose = (float)($candles[$n - 1]['close'] ?? 0.0);

        // ── Scan pairs most-recent first to find the best valid double bottom ─
        $best      = null;
        $bestScore = -1.0;

        $pLen = count($pivotLows);
        for ($pi2 = $pLen - 1; $pi2 >= 1; $pi2--) {
            $b2 = $pivotLows[$pi2];
            for ($pi1 = $pi2 - 1; $pi1 >= 0; $pi1--) {
                $b1  = $pivotLows[$pi1];
                $sep = $b2['idx'] - $b1['idx'];

                if ($sep < $minSep) {
                    continue;
                }
                if ($sep > $maxSep) {
                    break; // b1 only moves further back for lower pi1
                }

                // ── Evaluate each pass condition in order; track failure reason ──
                $reason = 'pass';

                // (1) Low-difference tolerance
                $lowDiffPct = $b1['price'] > 0.0
                    ? abs($b2['price'] - $b1['price']) / $b1['price'] * 100.0
                    : 0.0;
                if ($lowDiffPct > $lowTolPct) {
                    $reason = 'double_bottom_lows_too_far_apart';
                }

                // (2) Second low must not break too deep below first
                $breakPct = $b1['price'] > 0.0
                    ? ($b1['price'] - $b2['price']) / $b1['price'] * 100.0
                    : 0.0;
                if ($reason === 'pass' && $breakPct > $maxBreakPct) {
                    $reason = 'second_low_broke_too_deep';
                }

                // (3) Neckline: highest high between b1 and b2
                $neckline = 0.0;
                for ($k = $b1['idx'] + 1; $k < $b2['idx']; $k++) {
                    $h = $candles[$k]['high'] ?? 0.0;
                    if ($h > $neckline) {
                        $neckline = $h;
                    }
                }

                if ($neckline <= 0.0) {
                    continue; // no candles between the lows — degenerate, skip
                }

                $avgLow     = ($b1['price'] + $b2['price']) / 2.0;
                $bouncePct  = $avgLow > 0.0
                    ? ($neckline - $avgLow) / $avgLow * 100.0
                    : 0.0;
                if ($reason === 'pass' && $bouncePct < $minBnc) {
                    $reason = 'neckline_bounce_too_weak';
                }

                // (4) Neckline reclaim: count bars closing above neckline after b2
                $reclaimBars = 0;
                for ($k = $b2['idx'] + 1; $k < $n; $k++) {
                    if (($candles[$k]['close'] ?? 0.0) > $neckline) {
                        $reclaimBars++;
                    }
                }
                $reclaimConfirmed = ($reclaimBars >= $minConfBars) && ($lastClose > $neckline);

                if ($reason === 'pass' && !$reclaimConfirmed) {
                    $reason = 'neckline_reclaim_not_confirmed';
                }

                // (5) Entry distance from neckline
                $entryDstPct = $neckline > 0.0
                    ? ($lastClose - $neckline) / $neckline * 100.0
                    : 0.0;
                if ($reason === 'pass' && $entryDstPct > $maxEntryDst) {
                    $reason = 'entry_too_far_after_neckline_reclaim';
                }

                // Score (used to prefer the best candidate among ties)
                $score = 0.0;
                $score += min(3.0, $bouncePct / max(0.01, $minBnc));          // bounce quality up to 3
                $score += ($lowDiffPct <= $lowTolPct * 0.5) ? 2.0 : 1.0;     // tight lows bonus
                $score += $reclaimConfirmed ? 3.0 : 0.0;                      // reclaim bonus
                $score += max(0.0, 2.0 - ($entryDstPct / max(0.01, $maxEntryDst)) * 2.0); // proximity bonus
                $score += ($reason === 'pass') ? 1.0 : 0.0;                   // full-pass bonus

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = [
                        'b1'               => $b1,
                        'b2'               => $b2,
                        'neckline'         => $neckline,
                        'lowDiffPct'       => $lowDiffPct,
                        'breakPct'         => $breakPct,
                        'bouncePct'        => $bouncePct,
                        'reclaimBars'      => $reclaimBars,
                        'reclaimConfirmed' => $reclaimConfirmed,
                        'entryDstPct'      => $entryDstPct,
                        'score'            => $score,
                        'reason'           => $reason,
                    ];
                }
            }
        }

        if ($best === null) {
            return array_merge($empty, ['intraday_double_bottom_reason' => 'no_intraday_double_bottom']);
        }

        return [
            'intraday_double_bottom_detected'       => ($best['reason'] === 'pass'),
            'bottom_1_price'                        => round($best['b1']['price'], 8),
            'bottom_1_index'                        => $best['b1']['idx'],
            'bottom_2_price'                        => round($best['b2']['price'], 8),
            'bottom_2_index'                        => $best['b2']['idx'],
            'bottom_low_diff_pct'                   => round($best['lowDiffPct'], 4),
            'second_low_break_pct'                  => round($best['breakPct'], 4),
            'intraday_db_neckline_level'            => round($best['neckline'], 8),
            'neckline_bounce_pct'                   => round($best['bouncePct'], 4),
            'neckline_reclaim_confirmed'            => $best['reclaimConfirmed'],
            'neckline_reclaim_confirm_bars'         => $best['reclaimBars'],
            'entry_distance_from_neckline_pct'      => round($best['entryDstPct'], 4),
            'intraday_double_bottom_score'          => round($best['score'], 2),
            'intraday_double_bottom_reason'         => $best['reason'],
        ];
    }

    /**
     * Detect post-dump stabilization: a significant prior drop from recent high
     * followed by price compressing into a narrow range (lows holding).
     */
    private function detectPostDumpStabilization(array $candles, array $config, array $ctx): array
    {
        $n = count($candles);
        if ($n < 5) {
            return [
                'post_dump_detected' => false, 'post_dump_drop_pct' => 0.0,
                'stabilization_detected' => false, 'stabilization_bars' => 0,
                'stabilization_range_width_pct' => 0.0, 'stabilization_slope_pct' => 0.0,
                'low_hold_score' => 0.0, 'stabilization_score' => 0.0,
                'stabilization_reason' => 'insufficient_candles', 'stabilization_warnings' => [],
            ];
        }

        $pdLookback = min((int)($config['post_dump_lookback_candles'] ?? 240), $n);
        $slice      = array_slice($candles, $n - $pdLookback);
        $sliceN     = count($slice);

        // Find the highest high in the lookback window
        $recentHigh = 0.0;
        foreach ($slice as $c) {
            $h = (float)($c['high'] ?? 0.0);
            if ($h > $recentHigh) {
                $recentHigh = $h;
            }
        }

        $lastClose    = (float)(end($candles)['close'] ?? 0.0);
        $postDumpDrop = $recentHigh > 0.0
            ? (($recentHigh - $lastClose) / $recentHigh) * 100.0
            : 0.0;

        $minDrop = (float)($config['post_dump_min_drop_from_recent_high_pct'] ?? 4.0);
        $maxDrop = (float)($config['post_dump_max_drop_from_recent_high_pct'] ?? 25.0);

        $postDumpDetected = ($postDumpDrop >= $minDrop && $postDumpDrop <= $maxDrop);

        // Stabilization window: last stabilization_min_bars candles
        $stabMinBars = (int)($config['stabilization_min_bars'] ?? 12);
        $stabWindow  = array_slice($candles, max(0, $n - $stabMinBars));
        $stabN       = count($stabWindow);

        if ($stabN < 3) {
            return [
                'post_dump_detected' => $postDumpDetected, 'post_dump_drop_pct' => round($postDumpDrop, 4),
                'stabilization_detected' => false, 'stabilization_bars' => $stabN,
                'stabilization_range_width_pct' => 0.0, 'stabilization_slope_pct' => 0.0,
                'low_hold_score' => 0.0, 'stabilization_score' => 0.0,
                'stabilization_reason' => 'stab_window_too_small', 'stabilization_warnings' => [],
            ];
        }

        // Compute stab window high/low/range
        $stabHigh = 0.0;
        $stabLow  = PHP_FLOAT_MAX;
        foreach ($stabWindow as $c) {
            $h = (float)($c['high']  ?? 0.0);
            $l = (float)($c['low']   ?? 0.0);
            if ($h > $stabHigh) { $stabHigh = $h; }
            if ($l < $stabLow  && $l > 0.0) { $stabLow = $l; }
        }
        $stabRangeWidthPct = ($stabLow > 0.0 && $stabHigh > $stabLow)
            ? (($stabHigh - $stabLow) / $stabLow) * 100.0
            : 0.0;

        $stabSlopePct = $this->computeSlopePct($stabWindow);

        $maxRangeWidth = (float)($config['stabilization_max_range_width_pct'] ?? 4.0);
        $maxStabSlope  = (float)($config['stabilization_max_down_slope_pct']  ?? 0.8);
        $minLowHold    = (int)($config['stabilization_min_low_hold_bars']      ?? 6);
        $minorBreakPct = (float)($config['stabilization_allow_minor_low_break_pct'] ?? 0.6);

        // Count bars where lows held (not making new lows outside minor break tolerance)
        $refLow       = (float)($stabWindow[0]['low'] ?? 0.0);
        $lowHoldCount = 0;
        foreach ($stabWindow as $c) {
            $l = (float)($c['low'] ?? 0.0);
            $allowedBreak = $refLow > 0.0 ? $refLow * (1.0 - $minorBreakPct / 100.0) : 0.0;
            if ($l >= $allowedBreak) {
                $lowHoldCount++;
            }
            // Update reference: only tighten, never widen
            if ($l > 0.0 && $l > $refLow) {
                $refLow = $l;
            }
        }
        $lowHoldScore = $stabN > 0 ? $lowHoldCount / $stabN : 0.0;

        $stabDetected = $postDumpDetected
            && $stabN >= $stabMinBars
            && $stabRangeWidthPct <= $maxRangeWidth
            && abs($stabSlopePct) <= $maxStabSlope
            && $lowHoldCount >= $minLowHold
            && !(bool)($ctx['active_falling_knife'] ?? false);

        $stabScore = 0.0;
        if ($postDumpDetected) { $stabScore += 2.0; }
        if ($stabN >= $stabMinBars) { $stabScore += 2.0; }
        if ($stabRangeWidthPct <= $maxRangeWidth) { $stabScore += 2.0; }
        if (abs($stabSlopePct) <= $maxStabSlope) { $stabScore += 2.0; }
        if ($lowHoldCount >= $minLowHold) { $stabScore += 2.0; }

        $stabReason = $stabDetected ? 'stabilization_ok' :
            ($postDumpDetected ? 'post_dump_but_no_stabilization' : 'no_post_dump');

        return [
            'post_dump_detected'            => $postDumpDetected,
            'post_dump_drop_pct'            => round($postDumpDrop, 4),
            'stabilization_detected'        => $stabDetected,
            'stabilization_bars'            => $stabN,
            'stabilization_range_width_pct' => round($stabRangeWidthPct, 4),
            'stabilization_slope_pct'       => round($stabSlopePct, 4),
            'low_hold_score'                => round($lowHoldScore, 4),
            'stabilization_score'           => $stabScore,
            'stabilization_reason'          => $stabReason,
            'stabilization_warnings'        => [],
        ];
    }

    /**
     * Detect a flat/base consolidation zone after the dump, within the last
     * flat_base_lookback_candles bars.
     */
    private function detectFlatBaseAfterDump(array $candles, array $config, array $postDumpResult): array
    {
        $empty = [
            'flat_base_detected' => false, 'flat_base_low' => null, 'flat_base_high' => null,
            'flat_base_mid' => null, 'flat_base_width_pct' => 0.0, 'flat_base_touches' => 0,
            'flat_base_score' => 0.0, 'flat_base_reason' => 'no_post_dump',
        ];

        if (!(bool)($postDumpResult['post_dump_detected'] ?? false)) {
            return $empty;
        }

        $n         = count($candles);
        $fbLookback = min((int)($config['flat_base_lookback_candles'] ?? 48), $n);
        $fbSlice    = array_slice($candles, $n - $fbLookback);
        $fbN        = count($fbSlice);

        if ($fbN < 3) {
            return array_merge($empty, ['flat_base_reason' => 'insufficient_candles']);
        }

        $fbHigh = 0.0;
        $fbLow  = PHP_FLOAT_MAX;
        foreach ($fbSlice as $c) {
            $h = (float)($c['high'] ?? 0.0);
            $l = (float)($c['low']  ?? 0.0);
            if ($h > $fbHigh) { $fbHigh = $h; }
            if ($l < $fbLow && $l > 0.0) { $fbLow = $l; }
        }

        if ($fbLow <= 0.0 || $fbHigh <= $fbLow) {
            return array_merge($empty, ['flat_base_reason' => 'invalid_range']);
        }

        $fbWidthPct   = (($fbHigh - $fbLow) / $fbLow) * 100.0;
        $fbMid        = ($fbHigh + $fbLow) / 2.0;

        $maxWidth    = (float)($config['flat_base_max_width_pct'] ?? 3.5);
        $minTouches  = (int)($config['flat_base_min_touches']     ?? 2);

        // Count candles that "touch" or stay near the base range (within base)
        $touches = 0;
        foreach ($fbSlice as $c) {
            $l = (float)($c['low']  ?? 0.0);
            $h = (float)($c['high'] ?? 0.0);
            // Touch = low is within 1% of fbLow or high is within 1% of fbHigh
            $nearLow  = $fbLow  > 0.0 && abs($l - $fbLow)  / $fbLow  < 0.01;
            $nearHigh = $fbHigh > 0.0 && abs($h - $fbHigh) / $fbHigh < 0.01;
            if ($nearLow || $nearHigh) {
                $touches++;
            }
        }

        $lastClose = (float)(end($candles)['close'] ?? 0.0);
        $baseBroken = $lastClose < $fbLow * (1.0 - 0.005);  // below base with 0.5% tolerance

        if ($baseBroken) {
            return array_merge($empty, [
                'flat_base_low'    => round($fbLow, 6),
                'flat_base_high'   => round($fbHigh, 6),
                'flat_base_width_pct' => round($fbWidthPct, 4),
                'flat_base_touches' => $touches,
                'flat_base_reason' => 'base_support_broken',
            ]);
        }

        if ($fbWidthPct > $maxWidth) {
            return array_merge($empty, [
                'flat_base_low'    => round($fbLow, 6),
                'flat_base_high'   => round($fbHigh, 6),
                'flat_base_width_pct' => round($fbWidthPct, 4),
                'flat_base_touches' => $touches,
                'flat_base_reason' => 'flat_base_too_wide',
            ]);
        }

        if ($touches < $minTouches) {
            return array_merge($empty, [
                'flat_base_low'    => round($fbLow, 6),
                'flat_base_high'   => round($fbHigh, 6),
                'flat_base_width_pct' => round($fbWidthPct, 4),
                'flat_base_touches' => $touches,
                'flat_base_reason' => 'flat_base_not_enough_touches',
            ]);
        }

        // Score: 0–10
        $fbScore = 0.0;
        if ($fbWidthPct <= $maxWidth * 0.6)       { $fbScore += 3.0; }
        elseif ($fbWidthPct <= $maxWidth)          { $fbScore += 1.5; }
        if ($touches >= $minTouches * 2)           { $fbScore += 3.0; }
        elseif ($touches >= $minTouches)           { $fbScore += 1.5; }
        if ((bool)($postDumpResult['stabilization_detected'] ?? false)) { $fbScore += 4.0; }

        return [
            'flat_base_detected'  => true,
            'flat_base_low'       => round($fbLow, 6),
            'flat_base_high'      => round($fbHigh, 6),
            'flat_base_mid'       => round($fbMid, 6),
            'flat_base_width_pct' => round($fbWidthPct, 4),
            'flat_base_touches'   => $touches,
            'flat_base_score'     => min(10.0, $fbScore),
            'flat_base_reason'    => 'flat_base_ok',
        ];
    }

    /**
     * Detect that the last closes have reclaimed above the flat/base high
     * for at least reclaim_confirm_bars bars.
     */
    private function detectReclaimAfterFlatBase(array $candles, array $config, array $flatBaseResult): array
    {
        $empty = [
            'reclaim_after_flat_detected' => false, 'reclaim_level' => null,
            'reclaim_confirmed_bars' => 0, 'reclaim_strength_pct' => 0.0,
            'reclaim_score' => 0.0, 'entry_distance_from_reclaim_pct' => 0.0,
            'reclaim_reason' => 'no_flat_base',
        ];

        if (!(bool)($flatBaseResult['flat_base_detected'] ?? false)) {
            return $empty;
        }

        $fbHigh = (float)($flatBaseResult['flat_base_high'] ?? 0.0);
        if ($fbHigh <= 0.0) {
            return array_merge($empty, ['reclaim_reason' => 'invalid_base_high']);
        }

        // Support both config key names for backwards compatibility
        $minAbove    = (float)($config['reclaim_min_close_above_level_pct']
            ?? $config['reclaim_min_close_above_base_pct'] ?? 0.4);
        $confirmBars = (int)($config['reclaim_confirm_bars'] ?? 2);
        $maxEntryDist = (float)($config['max_entry_distance_from_reclaim_pct'] ?? 2.5);
        $n           = count($candles);

        // Look at the last confirm_bars closes
        $reclaimWindow = array_slice($candles, max(0, $n - max($confirmBars, 5)));
        $confirmedCount = 0;
        $lastClose      = 0.0;
        $failedBackBelow = false;

        foreach ($reclaimWindow as $c) {
            $close = (float)($c['close'] ?? 0.0);
            if ($close > 0.0) {
                $lastClose = $close;
            }
            if ($close > $fbHigh * (1.0 + $minAbove / 100.0)) {
                $confirmedCount++;
            }
            // Detect if price reclaimed but then fell back below
            if ($confirmedCount > 0 && $close > 0.0 && $close < $fbHigh * 0.995) {
                $failedBackBelow = true;
            }
        }

        $reclaimStrength = ($fbHigh > 0.0 && $lastClose > 0.0)
            ? (($lastClose - $fbHigh) / $fbHigh) * 100.0
            : 0.0;

        // Entry distance from reclaim: how far above the reclaim level is the current close
        $entryDistPct = max(0.0, $reclaimStrength);

        if ($failedBackBelow) {
            return array_merge($empty, [
                'reclaim_level'                   => round($fbHigh, 6),
                'reclaim_confirmed_bars'          => $confirmedCount,
                'reclaim_strength_pct'            => round($reclaimStrength, 4),
                'entry_distance_from_reclaim_pct' => round($entryDistPct, 4),
                'reclaim_reason'                  => 'reclaim_failed_back_below_level',
            ]);
        }

        if ($confirmedCount < $confirmBars) {
            return array_merge($empty, [
                'reclaim_level'                   => round($fbHigh, 6),
                'reclaim_confirmed_bars'          => $confirmedCount,
                'reclaim_strength_pct'            => round($reclaimStrength, 4),
                'entry_distance_from_reclaim_pct' => round($entryDistPct, 4),
                'reclaim_reason'                  => 'reclaim_after_flat_not_confirmed',
            ]);
        }

        // Check not too far extended above reclaim
        if ($entryDistPct > $maxEntryDist) {
            return array_merge($empty, [
                'reclaim_level'                   => round($fbHigh, 6),
                'reclaim_confirmed_bars'          => $confirmedCount,
                'reclaim_strength_pct'            => round($reclaimStrength, 4),
                'entry_distance_from_reclaim_pct' => round($entryDistPct, 4),
                'reclaim_reason'                  => 'entry_too_far_after_reclaim',
            ]);
        }

        if ($reclaimStrength < 0.0) {
            return array_merge($empty, [
                'reclaim_level'                   => round($fbHigh, 6),
                'reclaim_confirmed_bars'          => $confirmedCount,
                'reclaim_strength_pct'            => round($reclaimStrength, 4),
                'entry_distance_from_reclaim_pct' => round($entryDistPct, 4),
                'reclaim_reason'                  => 'reclaim_too_weak_after_flat',
            ]);
        }

        $reclaimScore = 2.0
            + min(4.0, $confirmedCount * 1.0)
            + min(4.0, max(0.0, $reclaimStrength));

        return [
            'reclaim_after_flat_detected'     => true,
            'reclaim_level'                   => round($fbHigh, 6),
            'reclaim_confirmed_bars'          => $confirmedCount,
            'reclaim_strength_pct'            => round($reclaimStrength, 4),
            'reclaim_score'                   => min(10.0, $reclaimScore),
            'entry_distance_from_reclaim_pct' => round($entryDistPct, 4),
            'reclaim_reason'                  => 'reclaim_ok',
        ];
    }

    /**
     * Detect support and resistance levels from recent candle data.
     *
     * Support  = repeated local lows / base-low area within sr_lookback_candles.
     * Resistance / neckline = upper side of the post-dump base or last local rejection high.
     *
     * Returns a map of level data consumed by pipelineCoinTrendContext().
     */
    private function detectSupportResistanceLevels(array $candles, array $config): array
    {
        $empty = [
            'support_level'            => null,
            'resistance_level'         => null,
            'neckline_level'           => null,
            'base_low'                 => null,
            'base_high'                => null,
            'base_mid'                 => null,
            'base_width_pct'           => 0.0,
            'support_touches'          => 0,
            'resistance_touches'       => 0,
            'support_resistance_score' => 0.0,
            'support_resistance_reason'=> 'no_support_level',
            'support_broken'           => false,
        ];

        $n = count($candles);
        if ($n < 5) {
            return $empty;
        }

        $srLookback      = min((int)($config['sr_lookback_candles']             ?? 120), $n);
        $supportTolPct   = (float)($config['support_touch_tolerance_pct']       ?? 0.4);
        $resistTolPct    = (float)($config['resistance_touch_tolerance_pct']    ?? 0.4);
        $minSupportTouch = (int)($config['min_support_touches']                 ?? 2);
        $minResistTouch  = (int)($config['min_resistance_touches']              ?? 1);

        $slice     = array_slice($candles, $n - $srLookback);
        $sliceN    = count($slice);
        $lastClose = (float)(end($candles)['close'] ?? 0.0);

        if ($lastClose <= 0.0) {
            return $empty;
        }

        // Collect local lows and highs (pivot swing points)
        $localLows  = [];
        $localHighs = [];
        for ($i = 1; $i < $sliceN - 1; $i++) {
            $prevLow  = (float)($slice[$i - 1]['low']  ?? 0.0);
            $currLow  = (float)($slice[$i]['low']      ?? 0.0);
            $nextLow  = (float)($slice[$i + 1]['low']  ?? 0.0);
            $prevHigh = (float)($slice[$i - 1]['high'] ?? 0.0);
            $currHigh = (float)($slice[$i]['high']     ?? 0.0);
            $nextHigh = (float)($slice[$i + 1]['high'] ?? 0.0);

            if ($currLow > 0.0 && $currLow <= $prevLow && $currLow <= $nextLow) {
                $localLows[] = $currLow;
            }
            if ($currHigh > 0.0 && $currHigh >= $prevHigh && $currHigh >= $nextHigh) {
                $localHighs[] = $currHigh;
            }
        }

        if (empty($localLows)) {
            return $empty;
        }

        // --- Support level: cluster local lows ---
        sort($localLows);
        $supportLevel  = null;
        $supportTouches = 0;

        // Group nearby lows (within tolerance) and find the most-touched cluster
        $clusters = [];
        foreach ($localLows as $low) {
            $placed = false;
            foreach ($clusters as &$cluster) {
                $refLow = $cluster['ref'];
                if ($refLow > 0.0 && abs($low - $refLow) / $refLow * 100.0 <= $supportTolPct) {
                    $cluster['count']++;
                    $cluster['sum'] += $low;
                    $cluster['ref']  = $cluster['sum'] / $cluster['count'];  // update centroid
                    $placed = true;
                    break;
                }
            }
            unset($cluster);
            if (!$placed) {
                $clusters[] = ['ref' => $low, 'count' => 1, 'sum' => $low];
            }
        }

        // Pick cluster with most touches
        usort($clusters, fn($a, $b) => $b['count'] <=> $a['count']);
        if (!empty($clusters)) {
            $best          = $clusters[0];
            $supportLevel  = round($best['ref'], 6);
            $supportTouches = (int)$best['count'];
        }

        if ($supportLevel === null || $supportTouches < $minSupportTouch) {
            return array_merge($empty, [
                'support_level'   => $supportLevel,
                'support_touches' => $supportTouches,
                'support_resistance_reason' => $supportTouches > 0
                    ? 'support_touches_too_low' : 'no_support_level',
            ]);
        }

        // --- Resistance / neckline: use the highest local high above support ---
        $resistanceLows  = array_filter($localHighs, fn($h) => $supportLevel !== null && $h > $supportLevel);
        $resistanceLevel = null;
        $resistTouches   = 0;

        if (!empty($resistanceLows)) {
            sort($resistanceLows);
            $rClusters = [];
            foreach ($resistanceLows as $high) {
                $placed = false;
                foreach ($rClusters as &$rc) {
                    $refH = $rc['ref'];
                    if ($refH > 0.0 && abs($high - $refH) / $refH * 100.0 <= $resistTolPct) {
                        $rc['count']++;
                        $rc['sum'] += $high;
                        $rc['ref']  = $rc['sum'] / $rc['count'];
                        $placed = true;
                        break;
                    }
                }
                unset($rc);
                if (!$placed) {
                    $rClusters[] = ['ref' => $high, 'count' => 1, 'sum' => $high];
                }
            }
            usort($rClusters, fn($a, $b) => $b['count'] <=> $a['count']);
            if (!empty($rClusters)) {
                $rBest           = $rClusters[0];
                $resistanceLevel = round($rBest['ref'], 6);
                $resistTouches   = (int)$rBest['count'];
            }
        }

        // Base dimensions (support → resistance)
        $baseHigh     = $resistanceLevel ?? (float)(array_sum($localHighs) / max(1, count($localHighs)));
        $baseLow      = $supportLevel;
        $baseMid      = null;
        $baseWidthPct = 0.0;
        if ($baseLow > 0.0 && $baseHigh > $baseLow) {
            $baseWidthPct = (($baseHigh - $baseLow) / $baseLow) * 100.0;
            $baseMid      = round(($baseLow + $baseHigh) / 2.0, 6);
        }

        // Neckline = resistance level (or base high if no discrete resistance)
        $necklineLevel = $resistanceLevel ?? round($baseHigh, 6);

        // Support broken: current close is meaningfully below support
        $supportBroken = $supportLevel > 0.0 && $lastClose < $supportLevel * (1.0 - $supportTolPct / 100.0);

        if ($supportBroken) {
            return array_merge($empty, [
                'support_level'             => $supportLevel,
                'resistance_level'          => $resistanceLevel,
                'neckline_level'            => $necklineLevel,
                'base_low'                  => round($baseLow, 6),
                'base_high'                 => round($baseHigh, 6),
                'base_mid'                  => $baseMid,
                'base_width_pct'            => round($baseWidthPct, 4),
                'support_touches'           => $supportTouches,
                'resistance_touches'        => $resistTouches,
                'support_resistance_score'  => 0.0,
                'support_resistance_reason' => 'support_broken',
                'support_broken'            => true,
            ]);
        }

        if ($resistanceLevel === null || $resistTouches < $minResistTouch) {
            return array_merge($empty, [
                'support_level'             => $supportLevel,
                'resistance_level'          => $resistanceLevel,
                'neckline_level'            => $necklineLevel,
                'base_low'                  => round($baseLow, 6),
                'base_high'                 => round($baseHigh, 6),
                'base_mid'                  => $baseMid,
                'base_width_pct'            => round($baseWidthPct, 4),
                'support_touches'           => $supportTouches,
                'resistance_touches'        => $resistTouches,
                'support_resistance_score'  => 2.0,
                'support_resistance_reason' => 'no_resistance_level',
                'support_broken'            => false,
            ]);
        }

        // S/R score: 0–10
        $srScore = 0.0;
        $srScore += min(3.0, $supportTouches * 1.0);
        $srScore += min(2.0, $resistTouches * 1.0);
        if ($baseWidthPct > 0.0 && $baseWidthPct <= 5.0) { $srScore += 2.0; }
        elseif ($baseWidthPct <= 10.0)                    { $srScore += 1.0; }
        // Price inside or above base (not below support)
        if ($lastClose >= $baseLow && $lastClose <= $baseHigh) { $srScore += 2.0; }
        elseif ($lastClose > $baseHigh)                        { $srScore += 1.0; }

        return [
            'support_level'             => $supportLevel,
            'resistance_level'          => $resistanceLevel,
            'neckline_level'            => $necklineLevel,
            'base_low'                  => round($baseLow, 6),
            'base_high'                 => round($baseHigh, 6),
            'base_mid'                  => $baseMid,
            'base_width_pct'            => round($baseWidthPct, 4),
            'support_touches'           => $supportTouches,
            'resistance_touches'        => $resistTouches,
            'support_resistance_score'  => min(10.0, $srScore),
            'support_resistance_reason' => 'ok',
            'support_broken'            => false,
        ];
    }

    /**
     * Compute % slope of close prices over the candle window using
     * simple linear regression (rise-over-run as % of first price).
     */
    private function computeSlopePct(array $candles): float
    {
        $closes = array_map(fn($c) => (float)($c['close'] ?? 0.0), $candles);
        $closes = array_values(array_filter($closes, fn($v) => $v > 0.0));
        $n      = count($closes);
        if ($n < 2) {
            return 0.0;
        }

        // Simple: last – first / first × 100, normalized by bar count
        $first = $closes[0];
        $last  = $closes[$n - 1];
        if ($first <= 0.0) {
            return 0.0;
        }
        return (($last - $first) / $first) * 100.0;
    }

    /**
     * Count the number of candles in the window whose low is strictly
     * lower than the previous candle's low (recent lower lows).
     */
    private function countRecentLowerLows(array $candles): int
    {
        $n = count($candles);
        if ($n < 2) {
            return 0;
        }
        $count   = 0;
        $prevLow = (float)($candles[0]['low'] ?? 0.0);
        for ($i = 1; $i < $n; $i++) {
            $low = (float)($candles[$i]['low'] ?? 0.0);
            if ($low > 0.0 && $prevLow > 0.0 && $low < $prevLow) {
                $count++;
            }
            if ($low > 0.0) {
                $prevLow = $low;
            }
        }
        return $count;
    }

    /**
     * Count recent lower highs in the candle window.
     */
    private function countRecentLowerHighs(array $candles): int
    {
        $n = count($candles);
        if ($n < 2) {
            return 0;
        }
        $count    = 0;
        $prevHigh = (float)($candles[0]['high'] ?? 0.0);
        for ($i = 1; $i < $n; $i++) {
            $high = (float)($candles[$i]['high'] ?? 0.0);
            if ($high > 0.0 && $prevHigh > 0.0 && $high < $prevHigh) {
                $count++;
            }
            if ($high > 0.0) {
                $prevHigh = $high;
            }
        }
        return $count;
    }

    /**
     * Estimate a recent dump % over the last $bars H4 candles.
     * Returns: (highest high over window - latest close) / highest high * 100.
     */
    private function estimateRecentDump(array $candles, int $bars): float
    {
        $n     = count($candles);
        $slice = array_slice($candles, max(0, $n - $bars));
        if (empty($slice)) {
            return 0.0;
        }
        $high      = 0.0;
        $lastClose = 0.0;
        foreach ($slice as $c) {
            $h = (float)($c['high']  ?? 0.0);
            $cl = (float)($c['close'] ?? 0.0);
            if ($h > $high)   { $high      = $h; }
            if ($cl > 0.0)    { $lastClose = $cl; }
        }
        if ($high <= 0.0 || $lastClose <= 0.0) {
            return 0.0;
        }
        return max(0.0, (($high - $lastClose) / $high) * 100.0);
    }

    /**
     * Return [distFromHighPct, distFromLowPct] for the current close
     * relative to the recent high/low over $lookbackBars.
     */
    private function distanceFromRecentHighLow(array $candles, int $lookbackBars): array
    {
        $n     = count($candles);
        $slice = array_slice($candles, max(0, $n - $lookbackBars));
        if (empty($slice)) {
            return [0.0, 0.0];
        }
        $high      = 0.0;
        $low       = PHP_FLOAT_MAX;
        $lastClose = 0.0;
        foreach ($slice as $c) {
            $h  = (float)($c['high']  ?? 0.0);
            $l  = (float)($c['low']   ?? 0.0);
            $cl = (float)($c['close'] ?? 0.0);
            if ($h > $high)          { $high      = $h; }
            if ($l < $low && $l > 0) { $low       = $l; }
            if ($cl > 0.0)           { $lastClose = $cl; }
        }
        if ($lastClose <= 0.0) {
            return [0.0, 0.0];
        }
        $distFromHigh = $high > 0.0
            ? (($high - $lastClose) / $high) * 100.0 : 0.0;
        $distFromLow  = ($low < PHP_FLOAT_MAX && $low > 0.0)
            ? (($lastClose - $low) / $low) * 100.0 : 0.0;
        return [max(0.0, $distFromHigh), max(0.0, $distFromLow)];
    }

    /**
     * Compute a 0–10 reversal context score based on all sub-check results.
     * Used by bearish_reversal_exception eligibility.
     */
    private function computeReversalContextScore(
        array $postDumpResult,
        array $flatBaseResult,
        array $reclaimResult,
        bool  $activeFallingKnife,
        bool  $activeDowntrend,
        array $config
    ): float {
        if ($activeFallingKnife) {
            return 0.0;
        }

        $score = 0.0;

        // Post-dump presence (0–2)
        if ((bool)($postDumpResult['post_dump_detected'] ?? false)) {
            $score += 2.0;
        }

        // Stabilization quality (0–3)
        if ((bool)($postDumpResult['stabilization_detected'] ?? false)) {
            $stabScore = (float)($postDumpResult['stabilization_score'] ?? 0.0);
            $score += min(3.0, $stabScore * 0.3);
        }

        // Flat base quality (0–2.5)
        if ((bool)($flatBaseResult['flat_base_detected'] ?? false)) {
            $fbScore = (float)($flatBaseResult['flat_base_score'] ?? 0.0);
            $score += min(2.5, $fbScore * 0.25);
        }

        // Reclaim quality (0–2.5)
        if ((bool)($reclaimResult['reclaim_after_flat_detected'] ?? false)) {
            $reclScore = (float)($reclaimResult['reclaim_score'] ?? 0.0);
            $score += min(2.5, $reclScore * 0.25);
        }

        // Penalty for active downtrend without stabilization
        if ($activeDowntrend && !(bool)($postDumpResult['stabilization_detected'] ?? false)) {
            $score = max(0.0, $score - 2.0);
        }

        return min(10.0, $score);
    }

    /**
     * Load and return the FULL filtered registry symbol list.
     *
     * max_symbols_per_run windowing is intentionally NOT applied here; it is
     * handled by queueRun() so that the persistent registry cursor can rotate
     * through the complete registry across successive cycles.
     */
    private function buildUniverse(array $config): array
    {
        $mode     = (string)($config['universe_mode']   ?? 'all');
        $excluded = (array)($config['excluded_symbols'] ?? []);

        $registryDiag = [
            'registry_source_path'  => null,
            'registry_loaded'       => false,
            'registry_symbol_count' => 0,
            'registry_error'        => null,
        ];

        if ($mode === 'manual_list') {
            $symbols = (array)($config['allowed_symbols'] ?? []);
            $registryDiag['registry_source_path'] = 'manual_list';
            $registryDiag['registry_loaded']       = true;
            $registryDiag['registry_symbol_count'] = count($symbols);
        } else {
            try {
                $registryDir = \Core\System\SystemPaths::instance()
                    ->get('parser.parser1_market_registry');
            } catch (\Throwable) {
                $registryDir = dirname(__DIR__, 4)
                    . '/parser/parser1_market_registry';
            }

            $activePath = rtrim($registryDir, '/') . '/storage/active.json';
            $registryDiag['registry_source_path'] = $activePath;

            try {
                if (!file_exists($activePath)) {
                    throw new \RuntimeException('File not found: ' . $activePath);
                }
                $raw = file_get_contents($activePath);
                if ($raw === false || $raw === '') {
                    throw new \RuntimeException('File is empty: ' . $activePath);
                }
                $active = json_decode($raw, true);
                if (!is_array($active)) {
                    throw new \RuntimeException('JSON decode failed');
                }
                if (array_is_list($active)) {
                    $symbols = array_filter(array_column($active, 'symbol'));
                } else {
                    $symbols = array_keys($active);
                }
                $symbols = array_values($symbols);
                $registryDiag['registry_loaded']       = true;
                $registryDiag['registry_symbol_count'] = count($symbols);
            } catch (\Throwable $e) {
                $symbols = [];
                $registryDiag['registry_error'] = $e->getMessage();
            }
        }

        $this->writeJson('storage/registry_diag.json', $registryDiag);

        $symbols = array_filter($symbols, fn($s) => !in_array($s, $excluded, true));

        return array_values($symbols);
    }

    private function fetchCandles(string $symbol, array $config): array
    {
        $limit      = (int)($config['lookback_candles']  ?? 120);
        $baseUrl    = (string)($config['bybit_base_url'] ?? 'https://api.bybit.com');
        $timeoutSec = (int)($config['bybit_timeout_sec'] ?? 10);

        $url = sprintf(
            '%s/v5/market/kline?category=linear&symbol=%s&interval=%s&limit=%d',
            rtrim($baseUrl, '/'),
            urlencode($symbol),
            self::H4_INTERVAL,
            $limit
        );

        $ctx  = stream_context_create(['http' => ['timeout' => $timeoutSec]]);
        $raw  = @file_get_contents($url, false, $ctx);
        $json = $raw ? json_decode($raw, true) : null;

        if (!$json || ($json['retCode'] ?? -1) !== 0) {
            return [];
        }

        $list = $json['result']['list'] ?? [];
        $list = array_reverse($list);

        $candles = [];
        foreach ($list as $bar) {
            $candles[] = [
                'ts'     => (int)($bar[0] ?? 0),
                'open'   => (float)($bar[1] ?? 0),
                'high'   => (float)($bar[2] ?? 0),
                'low'    => (float)($bar[3] ?? 0),
                'close'  => (float)($bar[4] ?? 0),
                'volume' => (float)($bar[5] ?? 0),
            ];
        }

        return $candles;
    }

    /**
     * Fetch candles for any Bybit kline interval.
     *
     * Unlike fetchCandles() which is hard-coded to H4, this method accepts an
     * arbitrary $interval string (e.g. '1', '5', '15', '60', '240') and a
     * $limit so it can be used for entry-context short-timeframe candle fetching.
     */
    private function fetchCandlesForInterval(string $symbol, string $interval, int $limit, array $config): array
    {
        $baseUrl    = (string)($config['bybit_base_url']    ?? 'https://api.bybit.com');
        $timeoutSec = (int)($config['bybit_timeout_sec']    ?? 10);

        $url = sprintf(
            '%s/v5/market/kline?category=linear&symbol=%s&interval=%s&limit=%d',
            rtrim($baseUrl, '/'),
            urlencode($symbol),
            urlencode($interval),
            $limit
        );

        $ctx  = stream_context_create(['http' => ['timeout' => $timeoutSec]]);
        $raw  = @file_get_contents($url, false, $ctx);
        $json = $raw ? json_decode($raw, true) : null;

        if (!$json || ($json['retCode'] ?? -1) !== 0) {
            return [];
        }

        $list = $json['result']['list'] ?? [];
        $list = array_reverse($list);

        $candles = [];
        foreach ($list as $bar) {
            $candles[] = [
                'ts'     => (int)($bar[0] ?? 0),
                'open'   => (float)($bar[1] ?? 0),
                'high'   => (float)($bar[2] ?? 0),
                'low'    => (float)($bar[3] ?? 0),
                'close'  => (float)($bar[4] ?? 0),
                'volume' => (float)($bar[5] ?? 0),
            ];
        }

        return $candles;
    }

    /**
     * Fetch entry-context candles using the short-timeframe config.
     *
     * Tries entry_context_interval first; on failure (empty result or API error)
     * falls back to entry_context_fallback_interval.  Returns empty array if
     * both fail so callers can gracefully degrade to H4.
     */
    private function fetchEntryContextCandles(string $symbol, array $config): array
    {
        if (!(bool)($config['entry_context_enabled'] ?? true)) {
            return [];
        }

        $interval = (string)($config['entry_context_interval']          ?? '1');
        $limit    = (int)($config['entry_context_lookback_candles']     ?? 180);

        $candles = $this->fetchCandlesForInterval($symbol, $interval, $limit, $config);

        if (count($candles) >= 5) {
            return $candles;
        }

        // Primary fetch failed — try fallback interval
        $fbInterval = (string)($config['entry_context_fallback_interval']          ?? '5');
        $fbLimit    = (int)($config['entry_context_fallback_lookback_candles']     ?? 180);

        return $this->fetchCandlesForInterval($symbol, $fbInterval, $fbLimit, $config);
    }

    private function requireBootstrap(): void
    {
        require_once $this->moduleDir . '/bootstrap.php';
    }

    /**
     * Cheap H4-based prefilter to decide whether it's worth fetching 1m/5m candles.
     *
     * Scores each concrete H4 signal independently:
     *   +1  trend_direction is bullish (or non-strict mode)
     *   +1  bucket_allowed_long=true (symbol is in a bottom corridor bucket)
     *   +1  wave is corrective+up (pullback reversal shape)
     *   +1  H4 price has dropped >= min_h4_drop_from_recent_high_pct from recent high
     *   +1  last close is within max_distance_from_corridor_low_pct of corridor_low
     *
     * Bearish regime alone NEVER adds a point — regime is not used here at all.
     *
     * Returns:
     *   should_fetch         bool
     *   prefilter_score      float (0-5)
     *   prefilter_reasons    string[]  (signals that scored)
     *   prefilter_reject_reason string|null
     */
    private function cheapEntryContextPrefilter(
        array $candles,
        array $trend,
        array $corridor,
        array $wave,
        array $config
    ): array {
        $score   = 0.0;
        $reasons = [];

        // ── Signal 1: bullish trend ───────────────────────────────────────────
        if ((bool)($config['entry_context_prefilter_allow_bullish_trend'] ?? true)) {
            $trendDir = $trend['trend_direction'] ?? 'unknown';
            if ($trendDir === 'bullish') {
                $score++;
                $reasons[] = 'bullish_trend';
            }
        }

        // ── Signal 2: bottom corridor bucket ─────────────────────────────────
        if ((bool)($config['entry_context_prefilter_allow_corridor_bottom'] ?? true)) {
            if ((bool)($corridor['bucket_allowed_long'] ?? false)) {
                $score++;
                $reasons[] = 'corridor_bottom_bucket';
            }
        }

        // ── Signal 3: corrective wave (pullback) ──────────────────────────────
        if ((bool)($config['entry_context_prefilter_allow_corrective_wave'] ?? true)) {
            $wDir   = $wave['wave_direction'] ?? 'unknown';
            $wState = $wave['wave_state']     ?? 'unknown';
            if ($wDir === 'up' && $wState === 'corrective') {
                $score++;
                $reasons[] = 'corrective_wave_up';
            }
        }

        // ── Signal 4: H4 dump candidate — price dropped >= N% from recent high ─
        if ((bool)($config['entry_context_prefilter_allow_h4_dump_candidate'] ?? true)) {
            $lookback = max(1, (int)($config['entry_context_prefilter_h4_dump_lookback_candles'] ?? 24));
            $minDrop  = (float)($config['entry_context_prefilter_min_h4_drop_from_recent_high_pct'] ?? 3.0);
            $slice    = array_slice($candles, -$lookback);
            $highs    = array_filter(array_column($slice, 'high'), static fn($v) => is_numeric($v) && (float)$v > 0.0);
            if (!empty($highs)) {
                $recentHigh = (float)max($highs);
                $lastClose  = (float)(end($slice)['close'] ?? 0.0);
                if ($recentHigh > 0) {
                    $dropPct = (($recentHigh - $lastClose) / $recentHigh) * 100.0;
                    if ($dropPct >= $minDrop) {
                        $score++;
                        $reasons[] = 'h4_drop_from_recent_high_' . round($dropPct, 1) . 'pct';
                    }
                }
            }
        }

        // ── Signal 5: price near corridor low ────────────────────────────────
        $corrLow  = (float)($corridor['corridor_low']  ?? 0.0);
        $corrHigh = (float)($corridor['corridor_high'] ?? 0.0);
        $lastClose = count($candles) > 0 ? (float)(end($candles)['close'] ?? 0.0) : 0.0;
        if ($corrLow > 0 && $lastClose > 0 && $corrHigh > $corrLow) {
            $corrRange      = $corrHigh - $corrLow;
            $distFromLowPct = (($lastClose - $corrLow) / $corrRange) * 100.0;
            $maxDist = (float)($config['entry_context_prefilter_max_distance_from_corridor_low_pct'] ?? 8.0);
            if ($distFromLowPct <= $maxDist) {
                $score++;
                $reasons[] = 'near_corridor_low_' . round($distFromLowPct, 1) . 'pct';
            }
        }

        $minScore    = (float)($config['entry_context_prefilter_min_score'] ?? 2.0);
        $shouldFetch = $score >= $minScore;
        $rejectReason = $shouldFetch ? null : 'prefilter_score_too_low_' . $score . '_min_' . $minScore;

        return [
            'should_fetch'              => $shouldFetch,
            'prefilter_score'           => $score,
            'prefilter_reasons'         => $reasons,
            'prefilter_reject_reason'   => $rejectReason,
        ];
    }

    /**
     * Determine the most specific reject reason given the current symbol's diagnostic context.
     *
     * Priority order (highest specificity first):
     *  1. active_falling_knife
     *  2. entry_context_unavailable
     *  3. skipped_entry_context_due_prefilter
     *  4. no_post_dump_detected
     *  5. active_downtrend_no_stabilization
     *  6. recent_dump_still_unstable
     *  7. no_flat_base_after_dump
     *  8. flat_base_too_wide
     *  9. base_support_broken
     * 10. reclaim_after_flat_not_confirmed
     * 11. reclaim_failed_back_below_level
     * 12. entry_too_far_after_reclaim
     * 13. no_post_dump_flat_reclaim
     * 14. no_intraday_double_bottom
     * 15. classic_double_bottom_not_confirmed
     * 16. regime_bearish_long_hard_block  (fallback)
     *
     * Bearish regime alone is NEVER returned unless nothing more specific is found.
     */

    /**
     * Score quality for a synthetic A/B intraday setup candidate.
     *
     * Called when synthetic_candidate_built=true and synthetic_setup_quality_enabled=true.
     * Instead of old H4 structure metrics (similarity_delta / neckline depth), this
     * scorer uses the intraday detection scores already computed in diagBase.
     *
     * @param  array  $ctx       Merged diagBase (includes coinCtx and intraday fields).
     * @param  array  $candidate The synthetic candidate array (from buildSyntheticCandidate).
     * @param  array  $config    Effective strategy config.
     * @return array {
     *   synthetic_quality_checked, synthetic_quality_pass, synthetic_quality_score,
     *   synthetic_quality_reason, synthetic_quality_block_reasons[], synthetic_quality_components
     * }
     */
    private function scoreSyntheticSetupQuality(array $ctx, array $candidate, array $config): array
    {
        $setupClass  = (string)($ctx['setup_class']    ?? $candidate['candidate_source'] ?? 'unknown');
        $blockReasons = [];
        $warnings     = [];
        $softWarnings = [];
        $components   = [];

        // Hard safety blocks (shared A + B)
        if ((bool)($config['synthetic_quality_require_no_falling_knife'] ?? true)
            && (bool)($ctx['active_falling_knife_detected'] ?? false)
        ) {
            $blockReasons[] = 'active_falling_knife';
        }
        if ((bool)($config['synthetic_quality_require_support_not_broken'] ?? true)
            && (bool)($ctx['support_broken'] ?? false)
        ) {
            $blockReasons[] = 'base_support_broken';
        }

        $isIntradayDb = $setupClass === 'classic_intraday_double_bottom_reclaim'
            || $candidate['candidate_source'] === 'intraday_double_bottom';

        if ($isIntradayDb) {
            // A-class checks
            if (!(bool)($ctx['intraday_double_bottom_detected'] ?? false)) {
                $blockReasons[] = 'no_intraday_double_bottom';
            }
            if ((bool)($config['synthetic_quality_require_neckline_reclaim'] ?? true)
                && !(bool)($ctx['neckline_reclaim_confirmed'] ?? false)
            ) {
                $blockReasons[] = 'neckline_reclaim_not_confirmed';
            }
            $minDbScore  = (float)($config['synthetic_quality_intraday_db_min_score']
                ?? $config['synthetic_quality_min_intraday_db_score'] ?? 7.5);
            $dbScore     = (float)($ctx['intraday_double_bottom_score'] ?? 0.0);
            $components['intraday_db_score']   = $dbScore;
            if ($dbScore < $minDbScore) {
                $blockReasons[] = 'intraday_db_score_too_low';
            }
            $minSetupScore = (float)($config['synthetic_quality_intraday_db_min_setup_score']
                ?? $config['synthetic_quality_min_setup_class_score'] ?? 7.5);
            $setupScore  = (float)($ctx['setup_class_score'] ?? 0.0);
            $components['setup_class_score'] = $setupScore;
            if ($setupScore < $minSetupScore) {
                $blockReasons[] = 'setup_class_score_too_low';
            }
            $maxNeckDist = (float)($config['synthetic_quality_intraday_db_max_entry_distance_from_neckline_pct']
                ?? $config['synthetic_quality_max_entry_distance_from_neckline_pct'] ?? 2.0);
            $neckDist    = (float)($ctx['entry_distance_from_neckline_pct'] ?? 0.0);
            $components['entry_distance_from_neckline_pct'] = $neckDist;
            // Soft cap for late_good_setup classification (GENIUS-like: good scores, slightly too far)
            $softNeckDist = (float)($config['synthetic_quality_intraday_db_soft_entry_distance_from_neckline_pct'] ?? 2.5);
            if ($maxNeckDist > 0.0 && $neckDist > $maxNeckDist) {
                // Check if it qualifies as a late_good_setup (high-quality but entry distance in soft zone)
                $minBorderlineScore = (float)($config['synthetic_quality_intraday_db_borderline_min_score'] ?? 8.5);
                $borderlinePendingEnabled = (bool)($config['synthetic_quality_intraday_db_borderline_distance_pending_enabled'] ?? true);
                $isLateGoodSetup = $borderlinePendingEnabled
                    && $neckDist <= $softNeckDist
                    && $dbScore >= $minBorderlineScore
                    && $setupScore >= $minBorderlineScore
                    && count($blockReasons) === 0; // Only quality-passing setups (no other blocks yet)
                if ($isLateGoodSetup) {
                    // Mark as late_good_setup: do not add to blockReasons so $pass stays true.
                    // Set indicator flags for tryLong() to use.
                    $warnings[] = 'late_good_setup_entry_distance';
                    $softWarnings[] = 'late_good_setup_entry_distance';
                    $components['late_good_setup']                  = true;
                    $components['missed_ideal_entry']               = true;
                    $components['waiting_for_better_entry_distance'] = true;
                    $components['late_good_setup_neckline_dist']    = $neckDist;
                    $components['late_good_setup_soft_cap']         = $softNeckDist;
                } else {
                    $blockReasons[] = 'entry_too_far_after_neckline_reclaim';
                }
            }
        } elseif ($setupClass === 'post_dump_base_reclaim'
            || $candidate['candidate_source'] === 'post_dump_base_reclaim'
        ) {
            // B-class checks
            if (!(bool)($ctx['post_dump_detected'] ?? false)) {
                $blockReasons[] = 'no_post_dump_detected';
            }
            if (!(bool)($ctx['stabilization_detected'] ?? false)) {
                $blockReasons[] = 'no_stabilization_detected';
            }
            if (!(bool)($ctx['flat_base_detected'] ?? false)) {
                $blockReasons[] = 'no_flat_base_detected';
            }
            if ((bool)($config['synthetic_quality_require_reclaim_after_flat'] ?? true)
                && !(bool)($ctx['reclaim_after_flat_detected'] ?? false)
            ) {
                $blockReasons[] = 'reclaim_after_flat_not_confirmed';
            }
            $maxReclDist = (float)($config['synthetic_quality_max_entry_distance_from_reclaim_pct'] ?? 2.5);
            $reclDist    = (float)($ctx['entry_distance_from_reclaim_pct'] ?? 0.0);
            $components['entry_distance_from_reclaim_pct'] = $reclDist;
            if ($maxReclDist > 0.0 && $reclDist > $maxReclDist) {
                $blockReasons[] = 'entry_too_far_after_reclaim';
            }
        } else {
            $blockReasons[] = 'unknown_synthetic_setup_class';
        }

        // Shared score thresholds
        // setup_class_score is already handled per-class above for A-class;
        // only apply generic shared gate for B-class and unknown.
        if (!$isIntradayDb) {
            $minClassScore = (float)($config['synthetic_quality_min_setup_class_score'] ?? 7.5);
            $classScore    = (float)($ctx['setup_class_score'] ?? 0.0);
            $components['setup_class_score'] = $classScore;
            if ($classScore < $minClassScore) {
                $blockReasons[] = 'setup_class_score_too_low';
            }
        }

        // entry_context_score: hard gate for B-class; warning-only for A-class when config says so.
        $minCtxScore = (float)($config['synthetic_quality_min_entry_context_score'] ?? 7.5);
        $ctxScore    = (float)($ctx['entry_context_score'] ?? $ctx['reversal_context_score'] ?? 0.0);
        $components['entry_context_score'] = $ctxScore;
        $requireCtxForIntraday = (bool)($config['synthetic_quality_require_generic_entry_context_score_for_intraday_db'] ?? true);
        if ($isIntradayDb && !$requireCtxForIntraday) {
            // A-class: downgrade low generic ctx score to warning, not a hard block.
            if ($ctxScore < $minCtxScore) {
                $warnings[] = 'generic_entry_context_score_low';
                $softWarnings[] = 'generic_entry_context_score_low';
            }
        } else {
            if ($ctxScore < $minCtxScore) {
                $blockReasons[] = 'entry_context_score_too_low';
            }
        }

        $pass = count($blockReasons) === 0;

        // Composite score: normalise scoring fields (0–10) to 0–1
        $scoreFields = ['intraday_db_score', 'setup_class_score', 'entry_context_score'];
        $normSum     = 0.0;
        $normCount   = 0;
        foreach ($scoreFields as $f) {
            if (isset($components[$f])) {
                $normSum += min(1.0, max(0.0, (float)$components[$f] / 10.0));
                $normCount++;
            }
        }
        $compositeScore = $normCount > 0 ? round($normSum / $normCount, 4) : 0.0;

        return [
            'synthetic_quality_checked'       => true,
            'synthetic_quality_pass'          => $pass,
            'synthetic_quality_score'         => $compositeScore,
            'synthetic_quality_reason'        => $pass ? 'synthetic_quality_passed' : implode(',', $blockReasons),
            'synthetic_quality_block_reasons' => $blockReasons,
            'synthetic_quality_components'    => $components,
            'synthetic_quality_warnings'      => $warnings,
            'synthetic_quality_soft_warnings' => $softWarnings,
            'synthetic_quality_is_intraday_db'=> $isIntradayDb,
            'late_good_setup'                 => (bool)($components['late_good_setup'] ?? false),
            'missed_ideal_entry'              => (bool)($components['missed_ideal_entry'] ?? false),
            'waiting_for_better_entry_distance' => (bool)($components['waiting_for_better_entry_distance'] ?? false),
        ];
    }

    /**
     * emit a signal for, independent of the old H4 market-regime/trend/corridor/wave
     * gates.  When this returns entry_setup_allowed=true the old H4 gates are
     * downgraded to warnings inside tryLong() so the symbol reaches the
     * setup_allowed funnel.
     *
     * Hard safety blocks (active_falling_knife, entry_context unavailable, support
     * broken, reclaim not confirmed, entry too far) still prevent entry_setup_allowed
     * and are flagged via blocked_by_safety=true with a block_reason string.
     */
    private function computeEntrySetupAllowed(array $ctx, array $config): array
    {
        $default = [
            'entry_setup_allowed' => false,
            'entry_setup_class'   => null,
            'entry_setup_reason'  => null,
            'entry_setup_score'   => 0.0,
            'blocked_by_safety'   => false,
            'block_reason'        => null,
        ];

        // Hard block: intraday detection cannot be trusted without entry context.
        if (!(bool)($ctx['entry_context_available'] ?? false)) {
            return array_merge($default, [
                'blocked_by_safety' => true,
                'block_reason'      => 'entry_context_unavailable',
            ]);
        }

        // Hard block: active falling knife, never enter long.
        if ((bool)($ctx['active_falling_knife_detected'] ?? false)) {
            return array_merge($default, [
                'blocked_by_safety' => true,
                'block_reason'      => 'active_falling_knife',
            ]);
        }

        // Must be a signal-allowed setup class.
        if (!(bool)($ctx['setup_signal_allowed'] ?? false)) {
            return $default;
        }

        $setupClass     = (string)($ctx['setup_class'] ?? 'none');
        $allowedClasses = (array)($config['setup_class_handoff_allowed']
            ?? ['classic_intraday_double_bottom_reclaim', 'post_dump_base_reclaim']);
        if (!in_array($setupClass, $allowedClasses, true)) {
            return $default;
        }

        // Hard block: support broken.
        if ((bool)($ctx['support_broken'] ?? false)) {
            return array_merge($default, [
                'blocked_by_safety' => true,
                'block_reason'      => 'base_support_broken',
            ]);
        }

        // A-class checks: neckline reclaim must be confirmed and entry distance within limit.
        if ($setupClass === 'classic_intraday_double_bottom_reclaim') {
            if (!(bool)($ctx['neckline_reclaim_confirmed'] ?? false)) {
                return array_merge($default, [
                    'blocked_by_safety' => true,
                    'block_reason'      => 'neckline_reclaim_not_confirmed',
                ]);
            }
            $maxNeckDist = (float)($config['max_entry_distance_from_neckline_pct'] ?? 0.0);
            if ($maxNeckDist > 0.0 && (float)($ctx['entry_distance_from_neckline_pct'] ?? 0.0) > $maxNeckDist) {
                return array_merge($default, [
                    'blocked_by_safety' => true,
                    'block_reason'      => 'entry_too_far_after_neckline_reclaim',
                ]);
            }
        }

        // B-class checks: reclaim after flat must be confirmed and entry distance within limit.
        if ($setupClass === 'post_dump_base_reclaim') {
            if (!(bool)($ctx['reclaim_after_flat_detected'] ?? false)) {
                return array_merge($default, [
                    'blocked_by_safety' => true,
                    'block_reason'      => 'reclaim_after_flat_not_confirmed',
                ]);
            }
            $maxReclDist = (float)($config['max_entry_distance_from_reclaim_pct'] ?? 0.0);
            if ($maxReclDist > 0.0 && (float)($ctx['entry_distance_from_reclaim_pct'] ?? 0.0) > $maxReclDist) {
                return array_merge($default, [
                    'blocked_by_safety' => true,
                    'block_reason'      => 'entry_too_far_after_reclaim',
                ]);
            }
        }

        return [
            'entry_setup_allowed' => true,
            'entry_setup_class'   => $setupClass,
            'entry_setup_reason'  => (string)($ctx['setup_class_reason'] ?? 'intraday_setup_allowed'),
            'entry_setup_score'   => (float)($ctx['setup_class_score']   ?? 0.0),
            'blocked_by_safety'   => false,
            'block_reason'        => null,
        ];
    }

    /**
     * Build a synthetic candidate array for A/B intraday setup classes when the
     * H4 PatternDoubleBottom detector found nothing.  Provides real price values
     * from intraday detection so the downstream quality/control scorers do not
     * receive null fields and produce spurious failures.
     *
     * @param  array  $ctx        Merged diagBase (includes coinCtx and intraday fields).
     * @param  string $setupClass 'classic_intraday_double_bottom_reclaim' | 'post_dump_base_reclaim'
     */
    private function buildSyntheticCandidate(array $ctx, string $setupClass): array
    {
        if ($setupClass === 'classic_intraday_double_bottom_reclaim') {
            $neckline  = (float)($ctx['intraday_db_neckline_level'] ?? $ctx['neckline_level'] ?? 0.0);
            $low1Price = (float)($ctx['bottom_1_price'] ?? 0.0);
            $low2Price = (float)($ctx['bottom_2_price'] ?? 0.0);
            $simDelta  = (float)($ctx['bottom_low_diff_pct'] ?? 0.0);
            $b1        = (int)($ctx['bottom_1_index'] ?? 0);
            $b2        = (int)($ctx['bottom_2_index'] ?? 0);
            $windowSz  = max(4, $b2 - $b1);
            $source    = 'intraday_double_bottom';
            // Use intraday double-bottom quality score as candidate_score proxy.
            $candScore = min(1.0, max(0.0, (float)($ctx['intraday_double_bottom_score'] ?? 0.65)));
        } else {
            // post_dump_base_reclaim
            $neckline  = (float)($ctx['reclaim_level'] ?? $ctx['base_high'] ?? $ctx['neckline_level'] ?? 0.0);
            $low1Price = (float)($ctx['flat_base_low'] ?? $ctx['base_low'] ?? 0.0);
            $low2Price = $low1Price;
            $simDelta  = 0.0;
            $windowSz  = max(4, (int)($ctx['flat_base_touches'] ?? $ctx['stabilization_bars'] ?? 6));
            $source    = 'post_dump_base_reclaim';
            $candScore = min(1.0, max(0.0, (float)($ctx['reclaim_score'] ?? 0.60)));
        }

        return [
            'candidate_found'      => true,
            'candidate_side'       => 'long',
            // candidate_trigger is the price level at which the signal fires (neckline/reclaim).
            // PatternSignal::build() uses this as the entry_price / trigger.  A zero trigger
            // produces a signal_id with '0' and a zero stop_loss_pct — both are invalid.
            'candidate_trigger'    => $neckline,
            'neckline'             => $neckline,
            'low1_price'           => $low1Price,
            'low2_price'           => $low2Price,
            'similarity_delta_pct' => $simDelta,
            'window_size'          => $windowSz,
            'candidate_score'      => $candScore,
            'candidate_source'     => $source,
            'low1_index'           => $ctx['bottom_1_index'] ?? null,
            'low2_index'           => $ctx['bottom_2_index'] ?? null,
            'reject_reason'        => null,
        ];
    }

    private function resolveDoubleBottomRejectReason(array $ctx, array $config): string
    {
        // 1. Active falling knife
        if ((bool)($ctx['active_falling_knife_detected'] ?? false)) {
            return 'active_falling_knife';
        }

        // 2. Entry-context candles unavailable (fetch attempted but failed)
        $ctxStatus = $ctx['entry_context_status'] ?? null;
        if ($ctxStatus === 'unavailable') {
            return 'entry_context_unavailable';
        }

        // 3. Entry-context skipped by prefilter
        if ($ctx['entry_context_skip_reason'] === 'skipped_entry_context_due_prefilter'
            || $ctxStatus === 'skipped'
        ) {
            return 'skipped_entry_context_due_prefilter';
        }

        // 4. No post-dump (price never dumped so no double-bottom setup possible)
        if (!(bool)($ctx['post_dump_detected'] ?? false)) {
            return 'no_post_dump_detected';
        }

        // 5. Active downtrend with no stabilization
        if ((bool)($ctx['active_downtrend_detected'] ?? false)
            && !(bool)($ctx['stabilization_detected'] ?? false)
        ) {
            return 'active_downtrend_no_stabilization';
        }

        // 6. Post-dump exists but not yet stabilized
        if ((bool)($ctx['post_dump_detected'] ?? false)
            && !(bool)($ctx['stabilization_detected'] ?? false)
        ) {
            return 'recent_dump_still_unstable';
        }

        // 7. No flat base after dump
        if (!(bool)($ctx['flat_base_detected'] ?? false)) {
            return 'no_flat_base_after_dump';
        }

        // 8. Flat base is too wide
        $maxFlatWidth = (float)($config['max_flat_base_width_pct'] ?? 0.0);
        if ($maxFlatWidth > 0.0 && (float)($ctx['flat_base_width_pct'] ?? 0.0) > $maxFlatWidth) {
            return 'flat_base_too_wide';
        }

        // 9. Base support broken
        if ((bool)($ctx['support_broken'] ?? false)) {
            return 'base_support_broken';
        }

        // 10. Reclaim after flat not confirmed
        if (!(bool)($ctx['reclaim_after_flat_detected'] ?? false)) {
            return 'reclaim_after_flat_not_confirmed';
        }

        // 11. Reclaim failed back below level (negative reclaim strength)
        if ((float)($ctx['reclaim_strength_pct'] ?? 0.0) < 0.0) {
            return 'reclaim_failed_back_below_level';
        }

        // 12. Intraday neckline reclaim not confirmed
        if ((bool)($ctx['intraday_double_bottom_detected'] ?? false)
            && !(bool)($ctx['neckline_reclaim_confirmed'] ?? false)
        ) {
            return 'neckline_reclaim_not_confirmed';
        }

        // 13. Entry too far after intraday neckline reclaim
        $maxNeckDst = (float)($config['double_bottom_max_entry_distance_from_neckline_pct'] ?? 0.0);
        if ($maxNeckDst > 0.0
            && (bool)($ctx['neckline_reclaim_confirmed'] ?? false)
            && (float)($ctx['entry_distance_from_neckline_pct'] ?? 0.0) > $maxNeckDst
        ) {
            return 'entry_too_far_after_neckline_reclaim';
        }

        // 14. Entry too far after reclaim
        $maxEntryDist = (float)($config['max_entry_distance_from_reclaim_pct'] ?? 0.0);
        if ($maxEntryDist > 0.0
            && (float)($ctx['entry_distance_from_reclaim_pct'] ?? 0.0) > $maxEntryDist
        ) {
            return 'entry_too_far_after_reclaim';
        }

        // 15. Missing dump → flat → reclaim chain
        $hasDump   = (bool)($ctx['post_dump_detected']         ?? false);
        $hasFlat   = (bool)($ctx['flat_base_detected']         ?? false);
        $hasReclaim = (bool)($ctx['reclaim_after_flat_detected'] ?? false);
        if (!$hasDump || !$hasFlat || !$hasReclaim) {
            return 'no_post_dump_flat_reclaim';
        }

        // 16. No intraday double bottom
        if (!(bool)($ctx['intraday_double_bottom_detected'] ?? false)) {
            return 'no_intraday_double_bottom';
        }

        // 17. Setup class not signal-allowed
        if (!(bool)($ctx['setup_signal_allowed'] ?? true)) {
            return 'setup_class_not_signal_allowed';
        }

        // 18. Classic double bottom not confirmed
        if ((bool)($ctx['double_bottom_checked'] ?? false)
            && !(bool)($ctx['candidate_found'] ?? false)
        ) {
            return 'classic_double_bottom_not_confirmed';
        }

        // 19. Generic bearish block (fallback — nothing more specific found)
        return 'regime_bearish_long_hard_block';
    }

    /**
     * Map a reject reason string to a coarse pipeline stage name.
     * Used for the failed_stage diagnostic field.
     */
    private function failedStageForReason(string $reason): string
    {
        return match (true) {
            $reason === 'active_falling_knife'                       => 'falling_knife',
            $reason === 'entry_context_unavailable'                  => 'entry_context',
            $reason === 'skipped_entry_context_due_prefilter'        => 'prefilter',
            $reason === 'entry_too_far_after_reclaim'                => 'entry_distance',
            $reason === 'entry_too_far_after_neckline_reclaim'       => 'entry_distance',
            str_starts_with($reason, 'entry_context_')               => 'entry_context',
            $reason === 'no_post_dump_detected'                      => 'post_dump',
            $reason === 'active_downtrend_no_stabilization'          => 'stabilization',
            $reason === 'recent_dump_still_unstable'                 => 'stabilization',
            $reason === 'no_flat_base_after_dump'                    => 'flat_base',
            $reason === 'flat_base_too_wide'                         => 'flat_base',
            $reason === 'base_support_broken'                        => 'support',
            $reason === 'reclaim_after_flat_not_confirmed'           => 'reclaim',
            $reason === 'reclaim_failed_back_below_level'            => 'reclaim',
            $reason === 'neckline_reclaim_not_confirmed'             => 'intraday_reclaim',
            $reason === 'no_post_dump_flat_reclaim'                  => 'post_dump_flat_reclaim',
            $reason === 'no_intraday_double_bottom'                  => 'intraday_pattern',
            $reason === 'setup_class_not_signal_allowed'             => 'setup_class',
            $reason === 'classic_double_bottom_not_confirmed'        => 'classic_pattern',
            $reason === 'regime_bearish_long_hard_block'             => 'regime',
            str_starts_with($reason, 'trend_')                       => 'trend',
            str_starts_with($reason, 'bucket_')                      => 'corridor',
            str_starts_with($reason, 'wave_')                        => 'wave',
            default                                                   => 'other',
        };
    }

    // =========================================================================
    // Closed-trade calibration diagnostics
    // =========================================================================

    /**
     * Build closed-trade calibration diagnostics for double_bottom_long.
     *
     * Reads the bot's closed_trades.json, filters to double_bottom_long trades
     * that carry a strategy_signal_context trace, then computes score/warning
     * bucket statistics and candidate rule recommendations.
     *
     * Diagnostics only — no execution behavior is changed.
     */
    private function computeCalibration(array $config): array
    {
        $deepLossThreshold = (float)($config['calibration_deep_loss_threshold'] ?? -30.0);

        $botRelDir   = (string)($config['bot_module_dir'] ?? 'modules/bot');
        $botDir      = str_starts_with($botRelDir, '/')
            ? rtrim($botRelDir, '/')
            : $this->repoRoot . '/' . rtrim($botRelDir, '/');
        $closedPath  = $botDir . '/storage/trades/closed_trades.json';

        $zero = [
            'calibration_closed_trades_total'            => 0,
            'calibration_closed_trades_with_trace_total' => 0,
            'calibration_winning_trades_total'           => 0,
            'calibration_losing_trades_total'            => 0,
            'calibration_deep_loss_total'                => 0,
            'calibration_entry_distance_buckets'         => [],
            'calibration_synthetic_quality_buckets'      => [],
            'calibration_setup_class_score_buckets'      => [],
            'calibration_candidate_quality_buckets'      => [],
            'calibration_warning_combo_stats'            => [],
            'calibration_profitable_examples'            => [],
            'calibration_losing_examples'                => [],
            'calibration_deep_loss_examples'             => [],
            'calibration_bad_signature_examples'         => [],
            'calibration_candidate_rules'                => [],
        ];

        $raw = @file_get_contents($closedPath);
        if ($raw === false || $raw === '') {
            return $zero;
        }
        $allTrades = @json_decode($raw, true);
        if (!is_array($allTrades)) {
            return $zero;
        }

        // Filter to double_bottom_long trades that carry a strategy_signal_context.
        $trades = [];
        foreach ($allTrades as $ct) {
            if ((string)($ct['strategy_id'] ?? '') !== 'double_bottom_long') {
                continue;
            }
            $ctx = is_array($ct['strategy_signal_context'] ?? null) ? $ct['strategy_signal_context'] : [];
            if (empty($ctx)) {
                continue;
            }
            $ct['_ctx'] = $ctx;
            $trades[] = $ct;
        }

        // ── Bucket scaffolds ──────────────────────────────────────────────────
        $mkBuckets = static function(array $defs): array {
            $out = [];
            foreach ($defs as $key => $label) {
                $out[$key] = ['label' => $label, 'count' => 0, 'wins' => 0, 'losses' => 0, 'roi_sum' => 0.0, 'pnl_sum' => 0.0];
            }
            return $out;
        };

        $entryDistBuckets = $mkBuckets([
            '0_0.25'   => '0–0.25',
            '0.25_0.5' => '0.25–0.5',
            '0.5_1.0'  => '0.5–1.0',
            '1.0_2.0'  => '1.0–2.0',
            'gt_2.0'   => '>2.0',
        ]);
        $synQBuckets = $mkBuckets([
            'lt_0.65'   => '<0.65',
            '0.65_0.70' => '0.65–0.70',
            '0.70_0.80' => '0.70–0.80',
            'gt_0.80'   => '>0.80',
        ]);
        $setupScoreBuckets = $mkBuckets([
            'lt_8.5'  => '<8.5',
            '8.5_9.5' => '8.5–9.5',
            '9.5_10.5'=> '9.5–10.5',
            'gt_10.5' => '>10.5',
        ]);
        $candQBuckets = $mkBuckets([
            'lt_0.65'   => '<0.65',
            '0.65_0.75' => '0.65–0.75',
            '0.75_0.85' => '0.75–0.85',
            'gt_0.85'   => '>0.85',
        ]);

        // Warning/flag combo trackers
        $comboKeys = [
            'generic_entry_context_score_low',
            'final_context_inconsistent_warning',
            'final_trend_mismatch_warning',
            'late_good_setup',
            'missed_ideal_entry',
            'generic_entry_context_score_low+final_context_inconsistent_warning',
            'generic_entry_context_score_low+final_trend_mismatch_warning',
            'generic_entry_context_score_low+late_good_setup',
            'generic_entry_context_score_low+missed_ideal_entry',
        ];
        $comboRequired = [
            'generic_entry_context_score_low'                                     => ['generic_entry_context_score_low'],
            'final_context_inconsistent_warning'                                  => ['final_context_inconsistent_warning'],
            'final_trend_mismatch_warning'                                        => ['final_trend_mismatch_warning'],
            'late_good_setup'                                                     => ['late_good_setup'],
            'missed_ideal_entry'                                                  => ['missed_ideal_entry'],
            'generic_entry_context_score_low+final_context_inconsistent_warning' => ['generic_entry_context_score_low', 'final_context_inconsistent_warning'],
            'generic_entry_context_score_low+final_trend_mismatch_warning'       => ['generic_entry_context_score_low', 'final_trend_mismatch_warning'],
            'generic_entry_context_score_low+late_good_setup'                    => ['generic_entry_context_score_low', 'late_good_setup'],
            'generic_entry_context_score_low+missed_ideal_entry'                 => ['generic_entry_context_score_low', 'missed_ideal_entry'],
        ];
        $combos = [];
        foreach ($comboKeys as $ck) {
            $combos[$ck] = ['count' => 0, 'wins' => 0, 'losses' => 0, 'roi_sum' => 0.0, 'pnl_sum' => 0.0, 'deep_loss_count' => 0];
        }

        // ── Per-trade loop ────────────────────────────────────────────────────
        $totalCount  = count($trades);
        $withTrace   = 0;
        $wins        = 0;
        $losses      = 0;
        $deepLosses  = 0;

        $profitableExamples = [];
        $losingExamples     = [];
        $deepLossExamples   = [];
        $badSigExamples     = [];

        foreach ($trades as $ct) {
            $ctx     = $ct['_ctx'];
            $roiRaw  = $ct['roi'] ?? $ct['roi_pct'] ?? null;
            $roi     = $roiRaw !== null ? (float)$roiRaw : null;
            $pnl     = (float)($ct['pnl'] ?? $ct['realized_pnl'] ?? 0.0);

            if ((string)($ct['signal_id'] ?? '') !== '') {
                $withTrace++;
            }

            $isWin      = $roi !== null && $roi > 0;
            $isLoss     = $roi !== null && $roi <= 0;
            $isDeepLoss = $roi !== null && $roi <= $deepLossThreshold;

            if ($isWin)      $wins++;
            if ($isLoss)     $losses++;
            if ($isDeepLoss) $deepLosses++;

            // Quality scores (top-level preferred, fall back to ctx)
            $entryDist = $ct['entry_distance_from_neckline_pct'] ?? $ctx['entry_distance_from_neckline_pct'] ?? null;
            $synQ      = $ct['synthetic_quality_score']   ?? $ctx['synthetic_quality_score']   ?? null;
            $setupSc   = $ct['setup_class_score']         ?? $ctx['setup_class_score']         ?? null;
            $candQ     = $ct['candidate_quality_score']   ?? $ctx['candidate_quality_score']   ?? null;

            // Warnings / reason codes
            $warnings    = $ct['warnings']    ?? $ctx['warnings']    ?? [];
            $reasonCodes = $ct['reason_codes'] ?? $ctx['reason_codes'] ?? [];
            if (!is_array($warnings))    $warnings    = ($warnings !== null && $warnings !== '') ? [(string)$warnings] : [];
            if (!is_array($reasonCodes)) $reasonCodes = ($reasonCodes !== null && $reasonCodes !== '') ? [(string)$reasonCodes] : [];

            // Merge boolean flags into the warning set for combo matching
            $allW = array_unique(array_merge($warnings, $reasonCodes));
            if ((bool)($ct['late_good_setup']   ?? $ctx['late_good_setup']   ?? false)) {
                $allW[] = 'late_good_setup';
            }
            if ((bool)($ct['missed_ideal_entry'] ?? $ctx['missed_ideal_entry'] ?? false)) {
                $allW[] = 'missed_ideal_entry';
            }
            $allW    = array_unique($allW);
            $allWSet = array_flip($allW);

            // Build example record
            $openedTs   = isset($ct['opened_at']) ? strtotime($ct['opened_at']) : 0;
            $closedTs   = isset($ct['closed_at']) ? strtotime($ct['closed_at']) : 0;
            $durMin     = ($openedTs > 0 && $closedTs > 0) ? round(($closedTs - $openedTs) / 60, 1) : null;
            $exampleRec = [
                'symbol'                           => $ct['symbol']      ?? null,
                'signal_id'                        => $ct['signal_id']   ?? null,
                'roi'                              => $roi,
                'pnl'                              => $pnl,
                'duration_minutes'                 => $durMin,
                'close_reason'                     => $ct['close_reason'] ?? null,
                'close_guard'                      => $ct['close_guard']  ?? null,
                'setup_class'                      => $ct['setup_class']  ?? $ctx['setup_class']  ?? null,
                'synthetic_quality_score'          => $synQ,
                'setup_class_score'                => $setupSc,
                'intraday_double_bottom_score'     => $ct['intraday_double_bottom_score'] ?? $ctx['intraday_double_bottom_score'] ?? null,
                'candidate_quality_score'          => $candQ,
                'entry_distance_from_neckline_pct' => $entryDist,
                'quality_source'                   => $ct['quality_source'] ?? $ctx['quality_source'] ?? null,
                'warnings'                         => $allW ?: null,
                'reason_codes'                     => $reasonCodes ?: null,
            ];

            if ($isWin  && count($profitableExamples) < 5) $profitableExamples[] = $exampleRec;
            if ($isLoss && count($losingExamples)     < 5) $losingExamples[]     = $exampleRec;
            if ($isDeepLoss && count($deepLossExamples) < 5) $deepLossExamples[] = $exampleRec;
            // Bad signature: losing trade with generic_entry_context_score_low + ≥1 other warning
            if ($isLoss
                && isset($allWSet['generic_entry_context_score_low'])
                && count($allW) >= 2
                && count($badSigExamples) < 5
            ) {
                $badSigExamples[] = $exampleRec;
            }

            // ── entry_distance bucket ──────────────────────────────────────
            if ($entryDist !== null) {
                $d  = (float)$entryDist;
                $bk = match(true) {
                    $d <= 0.25 => '0_0.25',
                    $d <= 0.5  => '0.25_0.5',
                    $d <= 1.0  => '0.5_1.0',
                    $d <= 2.0  => '1.0_2.0',
                    default    => 'gt_2.0',
                };
                $entryDistBuckets[$bk]['count']++;
                if ($isWin)  $entryDistBuckets[$bk]['wins']++;
                if ($isLoss) $entryDistBuckets[$bk]['losses']++;
                if ($roi !== null) $entryDistBuckets[$bk]['roi_sum'] += $roi;
                $entryDistBuckets[$bk]['pnl_sum'] += $pnl;
            }

            // ── synthetic_quality_score bucket ────────────────────────────
            if ($synQ !== null) {
                $q  = (float)$synQ;
                $bk = match(true) {
                    $q < 0.65 => 'lt_0.65',
                    $q < 0.70 => '0.65_0.70',
                    $q < 0.80 => '0.70_0.80',
                    default   => 'gt_0.80',
                };
                $synQBuckets[$bk]['count']++;
                if ($isWin)  $synQBuckets[$bk]['wins']++;
                if ($isLoss) $synQBuckets[$bk]['losses']++;
                if ($roi !== null) $synQBuckets[$bk]['roi_sum'] += $roi;
                $synQBuckets[$bk]['pnl_sum'] += $pnl;
            }

            // ── setup_class_score bucket ──────────────────────────────────
            if ($setupSc !== null) {
                $s  = (float)$setupSc;
                $bk = match(true) {
                    $s < 8.5  => 'lt_8.5',
                    $s < 9.5  => '8.5_9.5',
                    $s < 10.5 => '9.5_10.5',
                    default   => 'gt_10.5',
                };
                $setupScoreBuckets[$bk]['count']++;
                if ($isWin)  $setupScoreBuckets[$bk]['wins']++;
                if ($isLoss) $setupScoreBuckets[$bk]['losses']++;
                if ($roi !== null) $setupScoreBuckets[$bk]['roi_sum'] += $roi;
                $setupScoreBuckets[$bk]['pnl_sum'] += $pnl;
            }

            // ── candidate_quality_score bucket ────────────────────────────
            if ($candQ !== null) {
                $c  = (float)$candQ;
                $bk = match(true) {
                    $c < 0.65 => 'lt_0.65',
                    $c < 0.75 => '0.65_0.75',
                    $c < 0.85 => '0.75_0.85',
                    default   => 'gt_0.85',
                };
                $candQBuckets[$bk]['count']++;
                if ($isWin)  $candQBuckets[$bk]['wins']++;
                if ($isLoss) $candQBuckets[$bk]['losses']++;
                if ($roi !== null) $candQBuckets[$bk]['roi_sum'] += $roi;
                $candQBuckets[$bk]['pnl_sum'] += $pnl;
            }

            // ── warning combo stats ───────────────────────────────────────
            foreach ($comboRequired as $comboKey => $requiredFlags) {
                $matched = true;
                foreach ($requiredFlags as $flag) {
                    if (!isset($allWSet[$flag])) {
                        $matched = false;
                        break;
                    }
                }
                if ($matched) {
                    $combos[$comboKey]['count']++;
                    if ($isWin)      $combos[$comboKey]['wins']++;
                    if ($isLoss)     $combos[$comboKey]['losses']++;
                    if ($roi !== null) $combos[$comboKey]['roi_sum'] += $roi;
                    $combos[$comboKey]['pnl_sum'] += $pnl;
                    if ($isDeepLoss) $combos[$comboKey]['deep_loss_count']++;
                }
            }
        }

        // ── Finalize bucket stats ─────────────────────────────────────────────
        $finalizeBuckets = static function(array $buckets): array {
            $out = [];
            foreach ($buckets as $key => $bk) {
                $cnt = $bk['count'];
                $out[$key] = [
                    'label'   => $bk['label'],
                    'count'   => $cnt,
                    'wins'    => $bk['wins'],
                    'losses'  => $bk['losses'],
                    'winrate' => $cnt > 0 ? round($bk['wins'] / $cnt, 3) : null,
                    'avg_roi' => $cnt > 0 ? round($bk['roi_sum'] / $cnt, 3) : null,
                    'pnl_sum' => round($bk['pnl_sum'], 4),
                ];
            }
            return $out;
        };

        $finSynQ   = $finalizeBuckets($synQBuckets);
        $finSetup  = $finalizeBuckets($setupScoreBuckets);
        $finCandQ  = $finalizeBuckets($candQBuckets);

        // ── Finalize warning combo stats ──────────────────────────────────────
        $finalCombos = [];
        foreach ($combos as $comboKey => $cs) {
            $cnt = $cs['count'];
            $finalCombos[$comboKey] = [
                'count'           => $cnt,
                'wins'            => $cs['wins'],
                'losses'          => $cs['losses'],
                'winrate'         => $cnt > 0 ? round($cs['wins'] / $cnt, 3) : null,
                'avg_roi'         => $cnt > 0 ? round($cs['roi_sum'] / $cnt, 3) : null,
                'pnl_sum'         => round($cs['pnl_sum'], 4),
                'deep_loss_count' => $cs['deep_loss_count'],
            ];
        }

        return [
            'calibration_closed_trades_total'            => $totalCount,
            'calibration_closed_trades_with_trace_total' => $withTrace,
            'calibration_winning_trades_total'           => $wins,
            'calibration_losing_trades_total'            => $losses,
            'calibration_deep_loss_total'                => $deepLosses,
            'calibration_entry_distance_buckets'         => $finalizeBuckets($entryDistBuckets),
            'calibration_synthetic_quality_buckets'      => $finSynQ,
            'calibration_setup_class_score_buckets'      => $finSetup,
            'calibration_candidate_quality_buckets'      => $finCandQ,
            'calibration_warning_combo_stats'            => $finalCombos,
            'calibration_profitable_examples'            => $profitableExamples,
            'calibration_losing_examples'                => $losingExamples,
            'calibration_deep_loss_examples'             => $deepLossExamples,
            'calibration_bad_signature_examples'         => $badSigExamples,
            'calibration_candidate_rules'                => $this->buildCalibrationRules($finSynQ, $finSetup, $finCandQ, $finalCombos),
        ];
    }

    /**
     * Build candidate rule recommendations from finalized bucket/combo stats.
     *
     * Diagnostics only — rules must not affect execution.
     */
    private function buildCalibrationRules(
        array $finSynQ,
        array $finSetup,
        array $finCandQ,
        array $finalCombos
    ): array {
        $rules = [];

        $recommend = static function(float $winrate): string {
            if ($winrate < 0.35) return 'consider_reject';
            if ($winrate < 0.50) return 'demote_to_pending';
            return 'observe';
        };

        // synthetic_quality_score < 0.65
        $bk = $finSynQ['lt_0.65'] ?? null;
        if ($bk !== null && $bk['count'] > 0 && $bk['winrate'] !== null) {
            $rules[] = [
                'rule_name'      => 'synthetic_quality_below_0.65',
                'matched_count'  => $bk['count'],
                'winrate'        => $bk['winrate'],
                'avg_roi'        => $bk['avg_roi'],
                'recommendation' => $recommend((float)$bk['winrate']),
                'reason'         => 'synthetic_quality_score < 0.65',
            ];
        }

        // setup_class_score < 8.5
        $bk = $finSetup['lt_8.5'] ?? null;
        if ($bk !== null && $bk['count'] > 0 && $bk['winrate'] !== null) {
            $rules[] = [
                'rule_name'      => 'setup_class_score_below_8.5',
                'matched_count'  => $bk['count'],
                'winrate'        => $bk['winrate'],
                'avg_roi'        => $bk['avg_roi'],
                'recommendation' => $recommend((float)$bk['winrate']),
                'reason'         => 'setup_class_score < 8.5',
            ];
        }

        // candidate_quality_score < 0.65
        $bk = $finCandQ['lt_0.65'] ?? null;
        if ($bk !== null && $bk['count'] > 0 && $bk['winrate'] !== null) {
            $rules[] = [
                'rule_name'      => 'candidate_quality_below_0.65',
                'matched_count'  => $bk['count'],
                'winrate'        => $bk['winrate'],
                'avg_roi'        => $bk['avg_roi'],
                'recommendation' => $recommend((float)$bk['winrate']),
                'reason'         => 'candidate_quality_score < 0.65',
            ];
        }

        // Warning-based rules
        $warnRules = [
            'generic_entry_context_score_low'    => 'warning present: generic_entry_context_score_low',
            'final_context_inconsistent_warning' => 'warning present: final_context_inconsistent_warning',
            'final_trend_mismatch_warning'       => 'warning present: final_trend_mismatch_warning',
            'generic_entry_context_score_low+final_context_inconsistent_warning'
                => 'combo: generic_entry_context_score_low + final_context_inconsistent_warning',
            'generic_entry_context_score_low+final_trend_mismatch_warning'
                => 'combo: generic_entry_context_score_low + final_trend_mismatch_warning',
        ];
        foreach ($warnRules as $comboKey => $reason) {
            $cs = $finalCombos[$comboKey] ?? null;
            if ($cs !== null && $cs['count'] > 0 && $cs['winrate'] !== null) {
                $rules[] = [
                    'rule_name'      => 'warning_' . str_replace('+', '_and_', $comboKey),
                    'matched_count'  => $cs['count'],
                    'winrate'        => $cs['winrate'],
                    'avg_roi'        => $cs['avg_roi'],
                    'recommendation' => $recommend((float)$cs['winrate']),
                    'reason'         => $reason,
                ];
            }
        }

        return $rules;
    }

    /**
     * Computes hourly and 4-hour-block performance statistics for
     * double_bottom_long closed trades and writes them to hourly_stats.json.
     *
     * Diagnostics only — no trading behavior is affected.
     *
     * Returns a summary array suitable for last_run.json.
     */
    private function computeHourlyStats(array $config): array
    {
        $zero = [
            'hourly_stats_enabled'              => false,
            'hourly_stats_generated_at'         => null,
            'hourly_stats_total_trades'         => 0,
            'hourly_stats_bad_hour_candidates'  => 0,
            'hourly_stats_good_hour_candidates' => 0,
            'hourly_stats_bad_block_candidates' => 0,
            'hourly_stats_good_block_candidates'=> 0,
            'hourly_stats_file'                 => null,
            'hourly_stats_error'                => false,
            'hourly_stats_error_reason'         => null,
        ];

        if (!(bool)($config['hourly_stats_enabled'] ?? true)) {
            return $zero;
        }

        $minSamples     = max(1, (int)($config['hourly_stats_min_samples_for_signal'] ?? 10));
        $badAvgRoi      = (float)($config['hourly_stats_bad_avg_roi_threshold']    ?? -5.0);
        $badWinrate     = (float)($config['hourly_stats_bad_winrate_threshold']    ?? 40.0);
        $goodAvgRoi     = (float)($config['hourly_stats_good_avg_roi_threshold']   ?? 5.0);
        $goodWinrate    = (float)($config['hourly_stats_good_winrate_threshold']   ?? 60.0);
        $statsRelFile   = (string)($config['hourly_stats_file'] ?? 'storage/hourly_stats.json');
        $deepLossThr    = (float)($config['calibration_deep_loss_threshold'] ?? -30.0);

        $botRelDir  = (string)($config['bot_module_dir'] ?? 'modules/bot');
        $botDir     = str_starts_with($botRelDir, '/')
            ? rtrim($botRelDir, '/')
            : $this->repoRoot . '/' . rtrim($botRelDir, '/');
        $closedPath = $botDir . '/storage/trades/closed_trades.json';

        $raw = @file_get_contents($closedPath);
        if ($raw === false || $raw === '') {
            return $zero;
        }
        $allTrades = @json_decode($raw, true);
        if (!is_array($allTrades)) {
            return $zero;
        }

        // Filter to double_bottom_long trades only (no ctx requirement here —
        // we want all closed trades for a reliable hour sample).
        $trades = [];
        foreach ($allTrades as $ct) {
            $sid = (string)($ct['strategy_id'] ?? $ct['owner_strategy'] ?? $ct['strategy'] ?? '');
            if ($sid !== 'double_bottom_long') {
                continue;
            }
            $trades[] = $ct;
        }

        $totalCount = count($trades);

        // ── Build per-hour buckets (keys "00".."23") ─────────────────────────
        $mkHourBucket = static function(): array {
            return [
                'trades' => 0, 'wins' => 0, 'losses' => 0, 'breakeven' => 0,
                'roi_values' => [], 'pnl_sum' => 0.0, 'duration_minutes' => [],
                'deep_loss_count' => 0, 'last_trade_ts' => 0,
                'examples_bad' => [], 'examples_good' => [],
            ];
        };

        $hourBuckets = [];
        for ($h = 0; $h < 24; $h++) {
            $hourBuckets[sprintf('%02d', $h)] = $mkHourBucket();
        }

        // 4-hour blocks
        $blockDefs = [
            '00_03' => ['00','01','02','03'],
            '04_07' => ['04','05','06','07'],
            '08_11' => ['08','09','10','11'],
            '12_15' => ['12','13','14','15'],
            '16_19' => ['16','17','18','19'],
            '20_23' => ['20','21','22','23'],
        ];

        foreach ($trades as $ct) {
            // Derive open timestamp
            $openStr = (string)($ct['opened_at'] ?? $ct['entry_time'] ?? $ct['created_at'] ?? '');
            $openTs  = $openStr !== '' ? strtotime($openStr) : 0;
            if ($openTs <= 0) {
                continue;
            }

            $hour    = (int)gmdate('G', $openTs);   // 0..23 UTC
            $hourKey = sprintf('%02d', $hour);

            $roiRaw  = $ct['roi'] ?? $ct['roi_pct'] ?? null;
            $roi     = $roiRaw !== null ? (float)$roiRaw : null;
            $pnl     = (float)($ct['pnl'] ?? $ct['realized_pnl'] ?? 0.0);

            $closedTs = isset($ct['closed_at']) ? (int)strtotime($ct['closed_at']) : 0;
            $durMin   = ($openTs > 0 && $closedTs > 0) ? round(($closedTs - $openTs) / 60.0, 1) : null;

            $bk = &$hourBuckets[$hourKey];
            $bk['trades']++;
            $bk['pnl_sum'] += $pnl;
            if ($roi !== null) {
                $bk['roi_values'][] = $roi;
                if ($roi > 0)           $bk['wins']++;
                elseif ($roi < 0)       $bk['losses']++;
                else                    $bk['breakeven']++;
                if ($roi <= $deepLossThr) $bk['deep_loss_count']++;
            } else {
                $bk['breakeven']++;  // unknown roi counts as breakeven
            }
            if ($durMin !== null) {
                $bk['duration_minutes'][] = $durMin;
            }
            if ($openTs > $bk['last_trade_ts']) {
                $bk['last_trade_ts'] = $openTs;
            }

            // Collect examples (up to 5 per type per hour, assessed after finalization)
            $exRec = [
                'symbol'                           => $ct['symbol']      ?? null,
                'signal_id'                        => $ct['signal_id']   ?? null,
                'opened_at'                        => $ct['opened_at']   ?? null,
                'closed_at'                        => $ct['closed_at']   ?? null,
                'roi'                              => $roi,
                'pnl'                              => $pnl,
                'duration_minutes'                 => $durMin,
                'close_reason'                     => $ct['close_reason'] ?? null,
                'close_guard'                      => $ct['close_guard']  ?? null,
                'setup_class'                      => $ct['setup_class']  ?? ($ct['strategy_signal_context']['setup_class'] ?? null),
                'synthetic_quality_score'          => $ct['synthetic_quality_score']   ?? ($ct['strategy_signal_context']['synthetic_quality_score'] ?? null),
                'setup_class_score'                => $ct['setup_class_score']         ?? ($ct['strategy_signal_context']['setup_class_score'] ?? null),
                'candidate_quality_score'          => $ct['candidate_quality_score']   ?? ($ct['strategy_signal_context']['candidate_quality_score'] ?? null),
                'entry_distance_from_neckline_pct' => $ct['entry_distance_from_neckline_pct'] ?? ($ct['strategy_signal_context']['entry_distance_from_neckline_pct'] ?? null),
                'warnings'                         => $ct['warnings']    ?? ($ct['strategy_signal_context']['warnings'] ?? null),
                'reason_codes'                     => $ct['reason_codes'] ?? ($ct['strategy_signal_context']['reason_codes'] ?? null),
            ];
            // Stash for later — labelled by roi for good/bad classification
            $bk['_examples_stash'][] = [$roi, $exRec];
        }
        unset($bk);

        // ── Finalize per-hour buckets ─────────────────────────────────────────
        $finalizeHour = static function(array $bk, string $hourKey, int $minSamples, float $badAvgRoi, float $badWinrate, float $goodAvgRoi, float $goodWinrate): array {
            $trades   = $bk['trades'];
            $wins     = $bk['wins'];
            $losses   = $bk['losses'];
            $rois     = $bk['roi_values'];
            $durs     = $bk['duration_minutes'];

            $winratePct = $trades > 0 ? round($wins / $trades * 100.0, 2) : null;
            $avgRoi     = count($rois) > 0 ? round(array_sum($rois) / count($rois), 4) : null;
            $sumRoi     = count($rois) > 0 ? round(array_sum($rois), 4) : null;

            // Median roi
            $medianRoi = null;
            if (count($rois) > 0) {
                $sorted = $rois;
                sort($sorted);
                $n = count($sorted);
                $medianRoi = round(($n % 2 === 0)
                    ? ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2.0
                    : $sorted[(int)($n / 2)], 4);
            }

            $avgDur = count($durs) > 0 ? round(array_sum($durs) / count($durs), 1) : null;
            $bestRoi  = count($rois) > 0 ? max($rois) : null;
            $worstRoi = count($rois) > 0 ? min($rois) : null;

            $deepLossRate = $trades > 0 ? round($bk['deep_loss_count'] / $trades * 100.0, 2) : null;
            $lastTradeAt  = $bk['last_trade_ts'] > 0 ? gmdate('c', $bk['last_trade_ts']) : null;

            // Status classification
            if ($trades < $minSamples) {
                $status = 'insufficient_data';
            } elseif ($avgRoi !== null && $winratePct !== null && $avgRoi <= $badAvgRoi && $winratePct <= $badWinrate) {
                $status = 'bad_hour_candidate';
            } elseif ($avgRoi !== null && $winratePct !== null && $avgRoi >= $goodAvgRoi && $winratePct >= $goodWinrate) {
                $status = 'good_hour_candidate';
            } else {
                $status = 'neutral';
            }

            // Examples: up to 5 for bad/good candidate hours
            $examplesBad  = [];
            $examplesGood = [];
            foreach ($bk['_examples_stash'] ?? [] as [$roi2, $exRec]) {
                if ($roi2 !== null && $roi2 <= 0 && count($examplesBad)  < 5) $examplesBad[]  = $exRec;
                if ($roi2 !== null && $roi2 > 0  && count($examplesGood) < 5) $examplesGood[] = $exRec;
            }

            $out = [
                'hour'                  => $hourKey,
                'trades'                => $trades,
                'wins'                  => $wins,
                'losses'                => $losses,
                'breakeven'             => $bk['breakeven'],
                'winrate_pct'           => $winratePct,
                'avg_roi'               => $avgRoi,
                'median_roi'            => $medianRoi,
                'sum_roi'               => $sumRoi,
                'pnl_sum'               => round($bk['pnl_sum'], 4),
                'avg_duration_minutes'  => $avgDur,
                'deep_loss_count'       => $bk['deep_loss_count'],
                'deep_loss_rate_pct'    => $deepLossRate,
                'best_roi'              => $bestRoi,
                'worst_roi'             => $worstRoi,
                'last_trade_at'         => $lastTradeAt,
                'status'                => $status,
            ];
            if ($status === 'bad_hour_candidate' || $status === 'good_hour_candidate') {
                $out['examples'] = ($status === 'bad_hour_candidate') ? $examplesBad : $examplesGood;
            }
            return $out;
        };

        $finalHours     = [];
        $badHourCount   = 0;
        $goodHourCount  = 0;

        foreach ($hourBuckets as $hk => $bk) {
            $fin = $finalizeHour($bk, (string)$hk, $minSamples, $badAvgRoi, $badWinrate, $goodAvgRoi, $goodWinrate);
            $finalHours[$hk] = $fin;
            if ($fin['status'] === 'bad_hour_candidate')  $badHourCount++;
            if ($fin['status'] === 'good_hour_candidate') $goodHourCount++;
        }

        // ── Build 4-hour block stats ──────────────────────────────────────────
        $finalBlocks    = [];
        $badBlockCount  = 0;
        $goodBlockCount = 0;

        foreach ($blockDefs as $blockKey => $hours) {
            $bt = 0; $bw = 0; $bl = 0; $bbe = 0;
            $bRois = []; $bPnl = 0.0; $bDeep = 0;
            foreach ($hours as $hk) {
                $raw2 = $hourBuckets[$hk];
                $bt  += $raw2['trades'];
                $bw  += $raw2['wins'];
                $bl  += $raw2['losses'];
                $bbe += $raw2['breakeven'];
                $bRois = array_merge($bRois, $raw2['roi_values']);
                $bPnl += $raw2['pnl_sum'];
                $bDeep += $raw2['deep_loss_count'];
            }

            $bWinratePct = $bt > 0 ? round($bw / $bt * 100.0, 2) : null;
            $bAvgRoi     = count($bRois) > 0 ? round(array_sum($bRois) / count($bRois), 4) : null;
            $bSumRoi     = count($bRois) > 0 ? round(array_sum($bRois), 4) : null;

            $bMedianRoi  = null;
            if (count($bRois) > 0) {
                $sorted = $bRois;
                sort($sorted);
                $n = count($sorted);
                $bMedianRoi = round(($n % 2 === 0)
                    ? ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2.0
                    : $sorted[(int)($n / 2)], 4);
            }

            if ($bt < $minSamples) {
                $bStatus = 'insufficient_data';
            } elseif ($bAvgRoi !== null && $bWinratePct !== null && $bAvgRoi <= $badAvgRoi && $bWinratePct <= $badWinrate) {
                $bStatus = 'bad_hour_candidate';
            } elseif ($bAvgRoi !== null && $bWinratePct !== null && $bAvgRoi >= $goodAvgRoi && $bWinratePct >= $goodWinrate) {
                $bStatus = 'good_hour_candidate';
            } else {
                $bStatus = 'neutral';
            }

            if ($bStatus === 'bad_hour_candidate')  $badBlockCount++;
            if ($bStatus === 'good_hour_candidate') $goodBlockCount++;

            $finalBlocks[$blockKey] = [
                'hours'          => $hours,
                'trades'         => $bt,
                'wins'           => $bw,
                'losses'         => $bl,
                'breakeven'      => $bbe,
                'winrate_pct'    => $bWinratePct,
                'avg_roi'        => $bAvgRoi,
                'median_roi'     => $bMedianRoi,
                'sum_roi'        => $bSumRoi,
                'pnl_sum'        => round($bPnl, 4),
                'deep_loss_count'=> $bDeep,
                'status'         => $bStatus,
            ];
        }

        // ── Collect examples for bad/good hours ───────────────────────────────
        $normExamples    = [];
        $missingPrExamples = [];
        $missingRoiExamples = [];
        foreach ($finalHours as $hk => $fh) {
            if (isset($fh['examples'])) {
                foreach ($fh['examples'] as $ex) {
                    if (count($normExamples) < 5) $normExamples[] = array_merge(['hour' => $hk], $ex);
                }
            }
        }

        // ── Write hourly_stats.json ───────────────────────────────────────────
        $generatedAt = date('c');
        $statsPayload = [
            'generated_at'  => $generatedAt,
            'source'        => 'closed_trades',
            'strategy'      => 'double_bottom_long',
            'total_trades'  => $totalCount,
            'timezone'      => 'UTC',
            'hours'         => $finalHours,
            'blocks'        => $finalBlocks,
        ];
        $this->writeJson($statsRelFile, $statsPayload);

        return [
            'hourly_stats_enabled'            => true,
            'hourly_stats_generated_at'       => $generatedAt,
            'hourly_stats_total_trades'       => $totalCount,
            'hourly_stats_bad_hour_candidates'  => $badHourCount,
            'hourly_stats_good_hour_candidates' => $goodHourCount,
            'hourly_stats_bad_block_candidates'  => $badBlockCount,
            'hourly_stats_good_block_candidates' => $goodBlockCount,
            'hourly_stats_file'               => $statsRelFile,
        ];
    }

    private function requireLogic(string $file): void
    {
        require_once $this->moduleDir . '/logic/' . $file . '.php';
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

    private function writeJson(string $relPath, mixed $data): void
    {
        $path = $this->moduleDir . '/' . $relPath;
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function buildFoundCandidateRecord(array $result, int $cycleId, string $tickAt): array
    {
        $candidateKey = $this->candidateStableKey($result);
        $finalRejectReason = $this->normalizeFinalRejectReason(
            $result['final_reject_reason'] ?? null,
            $result['reject_reason'] ?? null
        );

        return [
            'candidate_key'         => $candidateKey,
            'signal_id'             => $result['signal_id'] ?? null,
            'symbol'                => (string)($result['symbol'] ?? ''),
            'side'                  => 'long',
            'primary_pattern'       => $result['primary_pattern'] ?? 'double_bottom',
            'cycle_id'              => $cycleId,
            'final_signal_status'   => $result['final_signal_status'] ?? null,
            'reject_reason'         => $result['reject_reason'] ?? null,
            'final_reject_reason'   => $finalRejectReason,
            'neckline_value'        => $result['neckline_value'] ?? null,
            'low1_value'            => $result['low1_value'] ?? null,
            'low2_value'            => $result['low2_value'] ?? null,
            'pattern_window_size'   => $result['pattern_window_size'] ?? null,
            'similarity_delta_pct'  => $result['similarity_delta_pct'] ?? null,
            'pattern_score'         => $result['pattern_score'] ?? null,
            'structure_score'       => $result['structure_score'] ?? null,
            'neckline_score'        => $result['neckline_score'] ?? null,
            'confirmation_score'    => $result['confirmation_score'] ?? null,
            'context_score'         => $result['context_score'] ?? null,
            'candidate_quality_score' => $result['candidate_quality_score'] ?? null,
            'quality_pass'          => $result['quality_pass'] ?? null,
            'quality_reject_reason' => $result['quality_reject_reason'] ?? null,
            'detected_at'           => $result['signal']['detected_at'] ?? $tickAt,
            'last_seen_at'          => $tickAt,
            'seen_count'            => 1,
        ];
    }

    private function buildEmittedCandidateRecord(array $result, int $cycleId, string $tickAt): array
    {
        $signal = (array)($result['signal'] ?? []);
        $signal['candidate_key']       = $this->candidateStableKey($result);
        $signal['cycle_id']            = $cycleId;
        $signal['final_signal_status'] = $signal['final_signal_status'] ?? 'emitted';
        $signal['final_reject_reason'] = $signal['final_reject_reason'] ?? null;
        $signal['last_seen_at']        = $tickAt;
        $signal['seen_count']          = 1;
        return $signal;
    }

    private function mergeCandidateRecord(array $records, array $record): array
    {
        $id = (string)($record['candidate_key'] ?? '');
        if ($id === '') {
            return $records;
        }

        $map = [];
        foreach ($records as $r) {
            $k = (string)($r['candidate_key'] ?? '');
            if ($k !== '') {
                $map[$k] = $r;
            }
        }

        if (isset($map[$id])) {
            $prev = $map[$id];
            $record['detected_at'] = $prev['detected_at'] ?? ($record['detected_at'] ?? null);
            $record['seen_count']  = (int)($prev['seen_count'] ?? 0) + 1;
        }
        $map[$id] = array_merge($map[$id] ?? [], $record);

        return array_values($map);
    }

    private function applySignalOutcomeToCandidates(array $records, array $signalOutcomeMap, string $tickAt): array
    {
        foreach ($records as &$record) {
            $signalId = (string)($record['signal_id'] ?? '');
            if ($signalId !== '' && isset($signalOutcomeMap[$signalId])) {
                $winner = (bool)($signalOutcomeMap[$signalId]['winner'] ?? false);
                $reason = $signalOutcomeMap[$signalId]['reason'] ?? null;
                $record['final_signal_status'] = $winner ? 'active' : 'rejected_final';
                $record['final_reject_reason'] = $winner ? null : $this->normalizeFinalRejectReason($reason, $record['reject_reason'] ?? null);
            } elseif (($record['final_reject_reason'] ?? null) === null) {
                $record['final_reject_reason'] = $this->normalizeFinalRejectReason(null, $record['reject_reason'] ?? null);
            }
            $record['last_seen_at'] = $tickAt;
        }
        unset($record);

        return $records;
    }

    private function candidateStableKey(array $result): string
    {
        $signalId = (string)($result['signal_id'] ?? '');
        if ($signalId !== '') {
            return $signalId;
        }

        $symbol   = strtolower((string)($result['symbol'] ?? ''));
        $pattern  = (string)($result['primary_pattern'] ?? 'double_bottom');
        $neckline = number_format((float)($result['neckline_value'] ?? 0.0), 6, '.', '');
        $low1     = number_format((float)($result['low1_value'] ?? 0.0), 6, '.', '');
        $low2     = number_format((float)($result['low2_value'] ?? 0.0), 6, '.', '');
        return sprintf('cand_%s_%s_%s_%s_%s', $symbol, $pattern, $neckline, $low1, $low2);
    }

    private function normalizeFinalRejectReason(?string $reason, ?string $fallbackRejectReason): ?string
    {
        $r = (string)($reason ?? '');
        if ($r === '') {
            $r = (string)($fallbackRejectReason ?? '');
        }
        if ($r === '') {
            return null;
        }

        if (in_array($r, ['final_low_neckline', 'price_too_far_above_neckline', 'price_too_far_below_neckline'], true)) {
            return 'final_low_neckline';
        }
        if (in_array($r, ['final_low_quality', 'final_quality_fail'], true)
            || str_contains($r, 'quality')
            || str_contains($r, 'confirm')
            || str_contains($r, 'candidate_expired')) {
            return 'final_low_quality';
        }
        if (in_array($r, ['final_trend_mismatch', 'final_side_trend_conflict'], true)
            || str_starts_with($r, 'trend_')) {
            return 'final_trend_mismatch';
        }
        if (in_array($r, ['final_context_inconsistent', 'final_wave_mismatch', 'final_bucket_mismatch'], true)
            || str_starts_with($r, 'wave_')
            || str_starts_with($r, 'bucket_')
            || str_contains($r, 'context')) {
            return 'final_context_inconsistent';
        }
        if (in_array($r, ['final_duplicate_removed', 'final_winner_lost'], true)) {
            return 'final_duplicate_removed';
        }

        return $r;
    }

    // =========================================================================
    // Bot handoff contract
    // =========================================================================

    /**
     * Refresh the bot handoff queue file (storage/bot_handoff_queue.json).
     *
     * Merges the current active-pool winner signals into a stable lifecycle-tracked
     * file intended for future Bot module consumption.
     *
     * Lifecycle states:
     *   new        — signal seen for the first time this tick
     *   refreshed  — signal was already in the queue and is still active
     *   expired    — signal left the pool after its TTL elapsed
     *   withdrawn  — signal left the pool before TTL (lost winner selection, etc.)
     *
     * Records are deduplicated by signal_id.
     * Expired/withdrawn records are retained for audit but excluded from ready_total.
     *
     * @param array $activeSignals Current filtered winner signals (signals.json pool)
     * @param array $config        Module config (for stop/tp/budget/ttl params)
     * @return array {ready_total, new_total, refreshed_total, expired_total}
     * @param string $tickAt ISO-8601 timestamp for the current run (used for freshness gate)
     */
    // =========================================================================
    // Scan suppression helpers
    // =========================================================================

    /**
     * TTL map for stable non-technical reject reasons (in minutes).
     * Returns the scan suppression TTL (minutes) for a given stable reject reason.
     * Only reasons listed here will trigger suppression.
     * Technical failures and temporary data errors are never suppressed.
     * Returns 0 when the reason should not trigger suppression.
     */
    private function scanSuppressionTtlForReason(string $reason, array $config): int
    {
        $short  = max(1, (int)($config['scan_suppression_short_ttl_minutes']   ?? 15));
        $medium = max(1, (int)($config['scan_suppression_medium_ttl_minutes']  ?? 30));
        $long   = max(1, (int)($config['scan_suppression_long_ttl_minutes']    ?? 60));

        return match($reason) {
            'no_post_dump_detected'                  => $long,
            'active_downtrend_no_stabilization'      => $long,
            'no_flat_base_after_dump'                => $medium,
            'base_support_broken'                    => $long,
            'reclaim_after_flat_not_confirmed'       => $short,
            'pending_invalidated_fresh_dump'         => $long,
            'pending_invalidated_reclaim_lost'       => $medium,
            'pending_invalidated_context_deteriorated' => $medium,
            default                                  => 0,   // 0 = do not suppress
        };
    }

    /**
     * Non-suppressible reasons: technical failures and temporary data errors.
     * These must NEVER result in a suppression entry.
     */
    private function isSuppressibleRejectReason(string $reason): bool
    {
        return !in_array($reason, [
            'candles_unavailable',
            'http_fetch_failed',
            'entry_context_unavailable',
            'parser_registry_empty',
            'temporary_data_error',
            'entry_context_fetch_cap_reached',
            'entry_context_fetch_limit_reached',
            'skipped_entry_context_due_prefilter',
        ], true);
    }

    /**
     * After processSymbol() returns, evaluate whether to write/update/clear
     * a scan suppression entry for the symbol.
     *
     * Suppression is only written for stable, non-technical reject reasons.
     * Signals emitted, late_good_setup, or pending_confirmation are never suppressed.
     * Technical errors are never suppressed.
     */
    private function updateScanSuppression(string $symLower, array $result, array $config): void
    {
        $fss         = (string)($result['final_signal_status'] ?? '');
        $rejectReason = (string)($result['primary_reject_reason'] ?? $result['reject_reason'] ?? '');
        $pendingInvReason = (string)($result['pending_invalidated_reason'] ?? '');

        // Never suppress if a signal was emitted or the setup is still active
        if (in_array($fss, ['emitted', 'late_good_setup', 'pending_confirmation'], true)) {
            // If we had a suppression entry, remove it since this symbol is now viable
            if (isset($this->scanSuppressionCache[$symLower])) {
                $this->scanSuppressionExpiredThisTick++;
                if (count($this->scanSuppressionExpiredExamples) < 5) {
                    $prev = $this->scanSuppressionCache[$symLower];
                    $this->scanSuppressionExpiredExamples[] = [
                        'symbol'         => $symLower,
                        'reason'         => 'scan_suppression_expired_prefilter_passed',
                        'failed_stage'   => $prev['failed_stage'] ?? null,
                        'suppress_until' => $prev['suppress_until'] ?? null,
                        'ttl_minutes'    => $prev['ttl_minutes']  ?? null,
                        'daily_change_pct' => $prev['last_daily_change_pct'] ?? null,
                        'price'          => $prev['last_price']   ?? null,
                        'action'         => 'scan_suppression_expired_prefilter_passed',
                    ];
                }
                unset($this->scanSuppressionCache[$symLower]);
            }
            return;
        }

        // Resolve effective reject reason (pending invalidation reasons use their own key)
        $effectiveReason = $rejectReason;
        if ($pendingInvReason !== '') {
            // Map pending invalidation reasons to their suppressible equivalents
            $pendingMap = [
                'fresh_dump'            => 'pending_invalidated_fresh_dump',
                'reclaim_lost'          => 'pending_invalidated_reclaim_lost',
                'context_deteriorated'  => 'pending_invalidated_context_deteriorated',
            ];
            foreach ($pendingMap as $k => $v) {
                if (str_contains($pendingInvReason, $k)) {
                    $effectiveReason = $v;
                    break;
                }
            }
        }

        if ($effectiveReason === '' || !$this->isSuppressibleRejectReason($effectiveReason)) {
            return;
        }

        $ttlMin = $this->scanSuppressionTtlForReason($effectiveReason, $config);
        if ($ttlMin <= 0) {
            return;
        }

        $nowTs       = time();
        $untilTs     = $nowTs + ($ttlMin * 60);
        $suppressedAt = date('c', $nowTs);
        $suppressUntil = date('c', $untilTs);

        $lastPrice      = (float)($result['scan_suppression_last_price'] ?? $result['last_price'] ?? $result['entry_price'] ?? 0.0);
        $dailyChangePct = $result['scan_suppression_daily_change_pct'] ?? null;
        $lastRunId   = null;  // not available at this level; can be enriched later if needed

        $isNew = !isset($this->scanSuppressionCache[$symLower]);
        $obsCount = (int)(($this->scanSuppressionCache[$symLower]['observations_count'] ?? 0)) + 1;

        $entry = [
            'symbol'               => $symLower,
            'strategy'             => 'double_bottom_long',
            'reason'               => $effectiveReason,
            'failed_stage'         => $result['failed_stage'] ?? null,
            'suppressed_at'        => $suppressedAt,
            'suppress_until'       => $suppressUntil,
            'ttl_minutes'          => $ttlMin,
            'last_price'           => $lastPrice > 0.0 ? $lastPrice : null,
            'last_daily_change_pct'=> $dailyChangePct,
            'last_seen_run_id'     => $lastRunId,
            'observations_count'   => $obsCount,
        ];

        $this->scanSuppressionCache[$symLower] = $entry;

        if ($isNew) {
            $this->scanSuppressionAddedThisTick++;
            if (count($this->scanSuppressionAddedExamples) < 5) {
                $this->scanSuppressionAddedExamples[] = [
                    'symbol'         => $symLower,
                    'reason'         => $effectiveReason,
                    'failed_stage'   => $entry['failed_stage'],
                    'suppress_until' => $suppressUntil,
                    'ttl_minutes'    => $ttlMin,
                    'daily_change_pct' => $dailyChangePct,
                    'price'          => $entry['last_price'],
                    'action'         => 'added',
                ];
            }
        } else {
            $this->scanSuppressionRefreshedThisTick++;
        }
    }

    private function updateBotHandoff(array $activeSignals, array $config, string $tickAt): array
    {
        // ── Freshness gate config ────────────────────────────────────────────
        $maxAgeMinutes          = (int)($config['handoff_signal_max_age_minutes']          ?? 10);
        $requireCurrentRun      = (bool)($config['signal_requires_current_run_for_handoff'] ?? true);
        $requireRevalidAfterBlock = (bool)($config['require_revalidation_after_symbol_block'] ?? true);
        $maxAgeSec              = $maxAgeMinutes > 0 ? $maxAgeMinutes * 60 : 0;
        $nowTs                  = time();
        $tickTs                 = strtotime($tickAt);

        $existing = (array)$this->readJson('storage/bot_handoff_queue.json', []);

        // Sweep pending_patterns.json: recheck/expire/confirm active entries
        $this->sweepDblPendingPatternsStorage($config);

        $existingMap = [];
        foreach ($existing as $r) {
            $id = (string)($r['signal_id'] ?? '');
            if ($id !== '') {
                $existingMap[$id] = $r;
            }
        }

        $activeIds                                   = [];
        $newTotal                                    = 0;
        $refreshedTotal                              = 0;
        $expiredTotal                                = 0;
        $blockedStaleTotal                           = 0;
        $blockedNotCurrentRunTotal                   = 0;
        $removedStaleQueueEntriesTotal               = 0;
        $revalidatedAfterUnblockTotal                = 0;
        $blockedNeedsRevalidationTotal               = 0;
        $staleBlockExamples                          = [];
        $revalidationRequiredExamples                = [];
        $result                                      = [];
        $currentRunFreshnessWindowSec                = 300;
        $currentRunFreshnessCheckedTotal             = 0;
        $currentRunFreshnessPassedTotal              = 0;
        $currentRunFreshnessBlockedTotal             = 0;
        $currentRunFreshnessDetectedAtOnlyTotal      = 0;
        $currentRunFreshnessRefreshedAtUsedTotal     = 0;
        $currentRunFreshnessPendingRecheckBypassedTotal = 0;
        $currentRunFreshnessExamples                 = [];
        $confirmedPatternFreshnessCheckedTotal       = 0;
        $confirmedPatternValidForHandoffTotal        = 0;
        $confirmedPatternExpiredTotal                = 0;
        $confirmedPatternInvalidatedTotal            = 0;
        $confirmedPatternBlockedByGenericFreshnessTotal = 0;
        // Map: signal_id → confirmed-pattern validity diag fields to merge into queue SSC.
        $confirmedPatternDiagMap                     = [];
        // Pending storage cleanup diagnostics (active-only pending list hygiene).
        $pendingCleanupCheckedTotal                  = 0;
        $pendingCleanupRemovedConfirmedTotal         = 0;
        $pendingCleanupRemovedGarbageBlockedTotal    = 0;
        $pendingCleanupRemovedInvalidTotal           = 0;
        $pendingCleanupRemovedExpiredTotal           = 0;
        $pendingCleanupHistoryWrittenTotal           = 0;
        $pendingCleanupExamples                      = [];

        // Process currently-active signals: new or refreshed
        foreach ($activeSignals as $signal) {
            $id = (string)($signal['signal_id'] ?? '');
            if ($id === '') {
                continue;
            }

            // ── Age freshness gate ───────────────────────────────────────────
            $detectedAt = (string)($signal['detected_at'] ?? '');
            $detectedTs = $detectedAt !== '' ? strtotime($detectedAt) : 0;
            if ($maxAgeSec > 0 && $detectedTs > 0 && ($nowTs - $detectedTs) > $maxAgeSec) {
                $ageMin = round(($nowTs - $detectedTs) / 60, 1);
                $blockedStaleTotal++;
                if (count($staleBlockExamples) < 5) {
                    $staleBlockExamples[] = [
                        'symbol'      => $signal['symbol']    ?? null,
                        'side'        => $signal['side']      ?? 'long',
                        'strategy'    => 'double_bottom_long',
                        'signal_id'   => $id,
                        'detected_at' => $detectedAt,
                        'age_minutes' => $ageMin,
                        'reason'      => 'handoff_blocked_stale_signal',
                    ];
                }
                // Mark existing queue entry as stale-withdrawn if it exists
                if (isset($existingMap[$id])) {
                    $staleEntry = $existingMap[$id];
                    $staleEntry['handoff_status']    = 'withdrawn';
                    $staleEntry['withdrawn_at']      = date('c');
                    $staleEntry['last_change_reason'] = 'stale_signal_age_exceeded';
                    $staleEntry['handoff_ready']     = false;
                    $staleEntry['stale']             = true;
                    $staleEntry['stale_reason']      = 'handoff_blocked_stale_signal';
                    $staleEntry['executable']        = false;
                    $result[$id] = $staleEntry;
                    $removedStaleQueueEntriesTotal++;
                }
                $activeIds[$id] = true; // mark as processed so we don't also expire it below
                continue;
            }

            // ── Current-run freshness gate ───────────────────────────────────
            // Current-run freshness must use last-refresh activity, not only detected_at.
            $effectiveFreshAt = '';
            $freshnessSource  = 'missing';
            $freshCandidates = [
                'refreshed_at'            => (string)($signal['refreshed_at'] ?? ''),
                'last_refreshed_at'       => (string)($signal['last_refreshed_at'] ?? ''),
                'last_lifecycle_update_at'=> (string)($signal['last_lifecycle_update_at'] ?? ''),
                'updated_at'              => (string)($signal['updated_at'] ?? ''),
                'detected_at'             => $detectedAt,
            ];
            foreach ($freshCandidates as $src => $val) {
                if ($val !== '') {
                    $effectiveFreshAt = $val;
                    $freshnessSource = $src === 'detected_at' ? 'detected_at_only' : $src;
                    break;
                }
            }
            $effectiveFreshTs = $effectiveFreshAt !== '' ? (int)strtotime($effectiveFreshAt) : 0;

            $sigCurrentCycleId = (int)($signal['current_cycle_id'] ?? 0);
            $sigLastSeenCycleId = (int)($signal['last_seen_cycle_id'] ?? 0);
            $sigEmittedCycleId = (int)($signal['emitted_cycle_id'] ?? 0);
            $cycleMarksCurrent = $sigCurrentCycleId > 0
                && ($sigLastSeenCycleId === $sigCurrentCycleId || $sigEmittedCycleId === $sigCurrentCycleId);
            if ($cycleMarksCurrent && $tickTs !== false) {
                $effectiveFreshAt = $tickAt;
                $effectiveFreshTs = (int)$tickTs;
                $freshnessSource = 'current_cycle_id';
            }

            $detectedAgeMin = $detectedTs > 0 ? round(($nowTs - $detectedTs) / 60, 1) : null;
            $effectiveFreshAgeMin = $effectiveFreshTs > 0 ? round(($nowTs - $effectiveFreshTs) / 60, 1) : null;
            $effectiveFreshAgeSec = $effectiveFreshTs > 0 ? ($nowTs - $effectiveFreshTs) : null;
            $pendingReasonRaw = (string)($signal['pending_reason'] ?? ($signal['strategy_signal_context']['dbl_pattern_pending_reason'] ?? ''));
            $dblStatusRaw = (string)($signal['dbl_pattern_status'] ?? ($signal['strategy_signal_context']['dbl_pattern_status'] ?? ''));
            $existingPending = (string)($existingMap[$id]['handoff_status'] ?? '') === 'pending';
            $isPatternPendingRecheck = $existingPending
                || $dblStatusRaw === 'active'
                || $pendingReasonRaw === 'waiting_dbl_pattern_confirmation';
            $isConfirmedPattern = $dblStatusRaw === 'confirmed';
            $confirmedPatternDiag = null;
            $confirmedPatternValid = true;
            $confirmedPatternInvalidReason = null;
            $confirmedPatternAgeMin = null;
            $confirmedPatternTtlMinUsed = null;
            if ($isConfirmedPattern) {
                $confirmedPatternFreshnessCheckedTotal++;
                $this->confirmedPatternFreshnessCheckedTotal++;
                $confirmedPatternDiag = $this->computeConfirmedPatternValidityDiag(
                    $signal,
                    $config,
                    $nowTs,
                    $detectedAgeMin
                );
                $confirmedPatternDiagMap[$id] = $confirmedPatternDiag;
                $confirmedPatternValid = (bool)($confirmedPatternDiag['confirmed_pattern_valid_for_handoff'] ?? false);
                $confirmedPatternInvalidReason = (string)($confirmedPatternDiag['confirmed_pattern_invalid_reason'] ?? '');
                $confirmedPatternAgeMin = $confirmedPatternDiag['confirmed_pattern_age_minutes'] ?? null;
                $confirmedPatternTtlMinUsed = $confirmedPatternDiag['confirmed_pattern_ttl_minutes'] ?? null;
                if ($confirmedPatternValid) {
                    $confirmedPatternValidForHandoffTotal++;
                    $this->confirmedPatternValidForHandoffTotal++;
                } elseif ($confirmedPatternInvalidReason === 'confirmed_pattern_ttl_expired') {
                    $confirmedPatternExpiredTotal++;
                    $this->confirmedPatternExpiredTotal++;
                } else {
                    $confirmedPatternInvalidatedTotal++;
                    $this->confirmedPatternInvalidatedTotal++;
                }
            }

            if ($requireCurrentRun && $tickTs !== false) {
                $currentRunFreshnessCheckedTotal++;
                if ($freshnessSource === 'detected_at_only') {
                    $currentRunFreshnessDetectedAtOnlyTotal++;
                } elseif ($freshnessSource !== 'missing') {
                    $currentRunFreshnessRefreshedAtUsedTotal++;
                }

                $missingFreshness = $effectiveFreshTs <= 0;
                $staleFreshness = !$missingFreshness && $effectiveFreshAgeSec !== null && $effectiveFreshAgeSec > $currentRunFreshnessWindowSec;
                $freshnessBlocked = $missingFreshness || $staleFreshness;

                // ── Confirmed-pattern validity/freshness handling ─────────────────
                // For confirmed patterns, always evaluate confirmed validity first.
                if ($isConfirmedPattern && !$confirmedPatternValid) {
                    $cpReason = $confirmedPatternInvalidReason !== ''
                        ? $confirmedPatternInvalidReason
                        : 'confirmed_pattern_price_invalidated';
                    $currentRunFreshnessBlockedTotal++;
                    if (count($staleBlockExamples) < 5) {
                        $staleBlockExamples[] = [
                            'symbol'                       => $signal['symbol']  ?? null,
                            'side'                         => $signal['side']    ?? 'long',
                            'strategy'                     => 'double_bottom_long',
                            'signal_id'                    => $id,
                            'detected_at'                  => $detectedAt,
                            'effective_fresh_at'           => $effectiveFreshAt !== '' ? $effectiveFreshAt : null,
                            'detected_age_minutes'         => $detectedAgeMin,
                            'confirmed_pattern_age_minutes'=> $confirmedPatternAgeMin,
                            'confirmed_pattern_ttl_minutes'=> $confirmedPatternTtlMinUsed,
                            'reason'                       => $cpReason,
                        ];
                    }
                    if (isset($existingMap[$id])) {
                        $blocked = $existingMap[$id];
                        $blocked['handoff_status']    = 'withdrawn';
                        $blocked['withdrawn_at']      = date('c');
                        $blocked['last_change_reason'] = $cpReason;
                        $blocked['handoff_ready']     = false;
                        $blocked['active_final']      = false;
                        $blocked['stale']             = true;
                        $blocked['stale_reason']      = $cpReason;
                        $blocked['block_reason']      = $cpReason;
                        $blocked['executable']        = false;
                        $sscBlocked = is_array($blocked['strategy_signal_context'] ?? null) ? $blocked['strategy_signal_context'] : [];
                        $sscBlocked = array_merge($sscBlocked, $confirmedPatternDiag ?? [], [
                            'effective_fresh_at'               => $effectiveFreshAt !== '' ? $effectiveFreshAt : null,
                            'detected_age_minutes'             => $detectedAgeMin,
                            'effective_fresh_age_minutes'      => $effectiveFreshAgeMin,
                            'current_run_freshness_source'     => $freshnessSource,
                            'current_run_freshness_passed'     => false,
                            'current_run_freshness_block_reason' => $cpReason,
                        ]);
                        $blocked['strategy_signal_context'] = $sscBlocked;
                        $result[$id] = $blocked;
                        $removedStaleQueueEntriesTotal++;
                    }
                    $activeIds[$id] = true;
                    continue;
                } elseif ($freshnessBlocked && $isConfirmedPattern) {
                    // Confirmed+valid patterns bypass the generic 300 s freshness gate.
                    $currentRunFreshnessPassedTotal++;
                    if (count($currentRunFreshnessExamples) < 10) {
                        $currentRunFreshnessExamples[] = [
                            'symbol'                       => $signal['symbol'] ?? null,
                            'signal_id'                    => $id,
                            'detected_at'                  => $detectedAt !== '' ? $detectedAt : null,
                            'effective_fresh_at'           => $effectiveFreshAt !== '' ? $effectiveFreshAt : null,
                            'detected_age_minutes'         => $detectedAgeMin,
                            'effective_fresh_age_minutes'  => $effectiveFreshAgeMin,
                            'current_run_freshness_source' => $freshnessSource,
                            'current_run_freshness_passed' => true,
                            'current_run_freshness_block_reason' => null,
                            'confirmed_pattern_bypass'     => true,
                            'confirmed_pattern_age_minutes'=> $confirmedPatternAgeMin,
                            'confirmed_pattern_ttl_minutes'=> $confirmedPatternTtlMinUsed,
                        ];
                    }
                } elseif ($freshnessBlocked && !$isPatternPendingRecheck) {
                    // Generic freshness block (non-confirmed patterns).
                    // Safety guard: if a confirmed pattern somehow reaches here, count it.
                    if ($isConfirmedPattern) {
                        $confirmedPatternBlockedByGenericFreshnessTotal++;
                        $this->confirmedPatternBlockedByGenericFreshnessTotal++;
                    }
                    $blockedNotCurrentRunTotal++;
                    $currentRunFreshnessBlockedTotal++;
                    $freshnessReason = $missingFreshness
                        ? 'missing_effective_fresh_at'
                        : 'effective_freshness_window_exceeded';
                    if (count($staleBlockExamples) < 5) {
                        $staleBlockExamples[] = [
                            'symbol'      => $signal['symbol']    ?? null,
                            'side'        => $signal['side']      ?? 'long',
                            'strategy'    => 'double_bottom_long',
                            'signal_id'   => $id,
                            'detected_at' => $detectedAt,
                            'effective_fresh_at' => $effectiveFreshAt !== '' ? $effectiveFreshAt : null,
                            'detected_age_minutes' => $detectedAgeMin,
                            'effective_fresh_age_minutes' => $effectiveFreshAgeMin,
                            'current_run_freshness_source' => $freshnessSource,
                            'reason'      => 'handoff_blocked_not_current_run',
                        ];
                    }
                    if (count($currentRunFreshnessExamples) < 10) {
                        $currentRunFreshnessExamples[] = [
                            'symbol' => $signal['symbol'] ?? null,
                            'signal_id' => $id,
                            'detected_at' => $detectedAt !== '' ? $detectedAt : null,
                            'effective_fresh_at' => $effectiveFreshAt !== '' ? $effectiveFreshAt : null,
                            'detected_age_minutes' => $detectedAgeMin,
                            'effective_fresh_age_minutes' => $effectiveFreshAgeMin,
                            'current_run_freshness_source' => $freshnessSource,
                            'current_run_freshness_passed' => false,
                            'current_run_freshness_block_reason' => $freshnessReason,
                        ];
                    }
                    // Mark existing queue entry as blocked-not-current-run if it exists
                    if (isset($existingMap[$id])) {
                        $blocked = $existingMap[$id];
                        $blocked['handoff_status']    = 'withdrawn';
                        $blocked['withdrawn_at']      = date('c');
                        $blocked['last_change_reason'] = 'not_current_run';
                        $blocked['handoff_ready']     = false;
                        $blocked['active_final']      = false;
                        $blocked['stale']             = true;
                        $blocked['stale_reason']      = 'handoff_blocked_not_current_run';
                        $blocked['block_reason']      = 'handoff_blocked_not_current_run';
                        $blocked['executable']        = false;
                        $blocked['effective_fresh_at'] = $effectiveFreshAt !== '' ? $effectiveFreshAt : null;
                        $blocked['detected_age_minutes'] = $detectedAgeMin;
                        $blocked['effective_fresh_age_minutes'] = $effectiveFreshAgeMin;
                        $blocked['current_run_freshness_source'] = $freshnessSource;
                        $blocked['current_run_freshness_passed'] = false;
                        $blocked['current_run_freshness_block_reason'] = $freshnessReason;
                        $sscBlocked = is_array($blocked['strategy_signal_context'] ?? null) ? $blocked['strategy_signal_context'] : [];
                        $sscBlocked['detected_at'] = $detectedAt !== '' ? $detectedAt : ($sscBlocked['detected_at'] ?? null);
                        $sscBlocked['effective_fresh_at'] = $effectiveFreshAt !== '' ? $effectiveFreshAt : null;
                        $sscBlocked['detected_age_minutes'] = $detectedAgeMin;
                        $sscBlocked['effective_fresh_age_minutes'] = $effectiveFreshAgeMin;
                        $sscBlocked['current_run_freshness_source'] = $freshnessSource;
                        $sscBlocked['current_run_freshness_passed'] = false;
                        $sscBlocked['current_run_freshness_block_reason'] = $freshnessReason;
                        $blocked['strategy_signal_context'] = $sscBlocked;
                        $result[$id] = $blocked;
                        $removedStaleQueueEntriesTotal++;
                    }
                    $activeIds[$id] = true;
                    continue;
                }
                if ($freshnessBlocked && $isPatternPendingRecheck) {
                    $currentRunFreshnessPendingRecheckBypassedTotal++;
                }
                // Increment pass counter for all signals reaching here, EXCEPT confirmed
                // patterns that were freshness-blocked (those already incremented above in the bypass block).
                if (!($isConfirmedPattern && $freshnessBlocked)) {
                    $currentRunFreshnessPassedTotal++;
                }
                if (count($currentRunFreshnessExamples) < 10 && !($isConfirmedPattern && $freshnessBlocked)) {
                    $currentRunFreshnessExamples[] = [
                        'symbol' => $signal['symbol'] ?? null,
                        'signal_id' => $id,
                        'detected_at' => $detectedAt !== '' ? $detectedAt : null,
                        'effective_fresh_at' => $effectiveFreshAt !== '' ? $effectiveFreshAt : null,
                        'detected_age_minutes' => $detectedAgeMin,
                        'effective_fresh_age_minutes' => $effectiveFreshAgeMin,
                        'current_run_freshness_source' => $freshnessSource,
                        'current_run_freshness_passed' => true,
                        'current_run_freshness_block_reason' => null,
                        'pending_recheck_bypass' => $freshnessBlocked && $isPatternPendingRecheck,
                    ];
                }
            }

            $activeIds[$id] = true;
            $record = $this->buildBotHandoffRecord($signal, $config);

            if (isset($existingMap[$id])) {
                $prev = $existingMap[$id];
                $record['detected_at']   = $prev['detected_at']   ?? $record['detected_at'];
                $record['first_seen_at'] = $prev['first_seen_at'] ?? ($prev['detected_at'] ?? $record['detected_at']);
                $record['seen_count']    = (int)($prev['seen_count'] ?? 0) + 1;
                $record['handoff_status'] = 'refreshed';
                // If this entry was previously marked as needing revalidation, clear it
                // since the signal is being refreshed in the current run.
                if ($requireRevalidAfterBlock && ($prev['needs_revalidation_after_unblock'] ?? false)) {
                    $record['needs_revalidation_after_unblock'] = false;
                    $record['revalidated_at'] = date('c');
                    $revalidatedAfterUnblockTotal++;
                }
                $refreshedTotal++;
            } else {
                $record['first_seen_at'] = $record['detected_at'];
                $record['seen_count']    = 1;
                $record['handoff_status'] = 'new';
                $newTotal++;
            }

            $record['last_refreshed_at'] = date('c');
            $recordDetectedTs = isset($record['detected_at']) ? (int)strtotime((string)$record['detected_at']) : 0;
            $recordDetectedAgeMin = $recordDetectedTs > 0 ? round(($nowTs - $recordDetectedTs) / 60, 1) : null;
            $record['effective_fresh_at'] = $effectiveFreshAt !== '' ? $effectiveFreshAt : $record['last_refreshed_at'];
            $recordEffectiveFreshTs = isset($record['effective_fresh_at']) ? (int)strtotime((string)$record['effective_fresh_at']) : 0;
            $recordEffectiveFreshAgeMin = $recordEffectiveFreshTs > 0 ? round(($nowTs - $recordEffectiveFreshTs) / 60, 1) : null;
            $record['detected_age_minutes'] = $recordDetectedAgeMin;
            $record['effective_fresh_age_minutes'] = $recordEffectiveFreshAgeMin;
            $record['current_run_freshness_source'] = $freshnessSource;
            $record['current_run_freshness_passed'] = true;
            $record['current_run_freshness_block_reason'] = null;
            if (isset($confirmedPatternDiagMap[$id])) {
                foreach ($confirmedPatternDiagMap[$id] as $cpField => $cpVal) {
                    $record[$cpField] = $cpVal;
                }
            }
            $sscFresh = is_array($record['strategy_signal_context'] ?? null) ? $record['strategy_signal_context'] : [];
            $sscFresh['detected_at'] = $record['detected_at'] ?? ($detectedAt !== '' ? $detectedAt : ($sscFresh['detected_at'] ?? null));
            $sscFresh['effective_fresh_at'] = $record['effective_fresh_at'];
            $sscFresh['detected_age_minutes'] = $recordDetectedAgeMin;
            $sscFresh['effective_fresh_age_minutes'] = $recordEffectiveFreshAgeMin;
            $sscFresh['current_run_freshness_source'] = $freshnessSource;
            $sscFresh['current_run_freshness_passed'] = true;
            $sscFresh['current_run_freshness_block_reason'] = null;
            // Merge confirmed-pattern validity diag for every confirmed pattern.
            if (isset($confirmedPatternDiagMap[$id])) {
                $sscFresh = array_merge($sscFresh, $confirmedPatternDiagMap[$id]);
            }
            $record['strategy_signal_context'] = $sscFresh;
            $result[$id] = $record;
        }

        // Process records that have left the active pool: mark as expired or withdrawn
        $ttlSec = $this->signalTtlSec($config);

        foreach ($existingMap as $id => $prev) {
            if (isset($result[$id])) {
                continue;  // already updated above
            }
            $prevStatus = (string)($prev['handoff_status'] ?? 'active');
            if (in_array($prevStatus, ['expired', 'withdrawn'], true)) {
                // Keep already-finalised records for audit trail; non-executable flags
                // are set in the normalization pass below.
                $result[$id] = $prev;
                continue;
            }
            // Determine exit cause: TTL elapsed → expired, otherwise → withdrawn
            $detectedAt = $prev['detected_at'] ?? '';
            $ts = $detectedAt !== '' ? strtotime($detectedAt) : 0;
            $status = ($ts > 0 && ($nowTs - $ts) > $ttlSec) ? 'expired' : 'withdrawn';
            $prev['handoff_status'] = $status;
            $prev['withdrawn_at']   = date('c');
            $prev['handoff_ready']  = false;
            $prev['executable']     = false;
            $prev['block_reason']   = ($status === 'expired')
                ? 'handoff_blocked_expired_signal'
                : 'handoff_blocked_withdrawn';
            $result[$id] = $prev;
            $expiredTotal++;
        }

        // ── Normalization pass: ensure every entry has explicit lifecycle flags ──
        // This covers all statuses including entries kept for audit trail that
        // may carry stale executable=true from an earlier run.
        $queueNormalizedTotal           = 0;
        $queueMarkedNonExecutableTotal  = 0;
        $queueExecutableTotal           = 0;
        $queueBlockedNcrTotal           = 0;  // blocked_not_current_run
        $queueBlockedBlTotal            = 0;  // blocked_by_blacklist (strategy-side: always 0)
        $queueBlockedFrTotal            = 0;  // blocked_by_freeze (strategy-side: always 0)
        $queueNonExecutableExamples     = [];
        $handoffQueueReadyWrittenTotal  = 0;
        $handoffQueueBlockedDiagnosticTotal = 0;
        $handoffQueueBlockedWithReadyStatusTotal = 0;
        $handoffQueueBlockedWithReadyStatusExamples = [];
        // OBC soft_demote handoff gate config — true by default (safe demo mode)
        $softDemoteBlocksHandoff = (bool)($config['orderbook_entry_wall_soft_demote_blocks_handoff'] ?? true);
        $softDemoteBlockedTotal  = 0;
        $softDemoteAllowedTotal  = 0;
        $softDemoteBlockExamples = [];
        // Garbage veto config
        $garbageVetoEnabled = (bool)($config['dbl_garbage_veto_enabled'] ?? true);
        // Map of signal_id → block_reason for non-executable queue entries;
        // used by caller to align signals.json lifecycle flags.
        $blockedSignalIds               = [];

        foreach ($result as $id => $r) {
            $status       = (string)($r['handoff_status'] ?? '');
            $isActive     = in_array($status, ['new', 'refreshed'], true);
            $needsRevalid = (bool)($r['needs_revalidation_after_unblock'] ?? false);
            $prevReady    = $r['handoff_ready'] ?? null;
            $prevExec     = $r['executable']    ?? null;
            $prevQueueStatus = (string)($existingMap[$id]['handoff_status'] ?? '');
            $changed      = false;

            if ($isActive && !$needsRevalid) {
                $this->dblRawCandidatesTotal++;
                // Check whether OBC soft_demote should block this handoff entry.
                $isSoftDemoted = (bool)($r['strategy_signal_context']['ob_soft_demoted'] ?? false);
                if ($isSoftDemoted && $softDemoteBlocksHandoff) {
                    // Soft_demote blocks handoff: mark non-executable but keep record for diagnostics.
                    $blockReason = 'ob_soft_demote_ask_wall_risk';
                    if ($prevReady !== false) { $result[$id]['handoff_ready'] = false; $changed = true; }
                    if ($prevExec  !== false) { $result[$id]['executable']    = false; $changed = true; }
                    if (($r['block_reason'] ?? null) !== $blockReason) { $result[$id]['block_reason'] = $blockReason; $changed = true; }
                    $softDemoteBlockedTotal++;
                    if (count($softDemoteBlockExamples) < 5) {
                        $softDemoteBlockExamples[] = [
                            'symbol'       => $r['symbol']    ?? null,
                            'signal_id'    => $id,
                            'detected_at'  => $r['detected_at'] ?? null,
                            'ob_gate_mode' => $r['strategy_signal_context']['ob_gate_mode'] ?? null,
                        ];
                    }
                    // Add to blockedSignalIds so signals.json lifecycle flags are aligned.
                    $sigIdInQueue = (string)($r['signal_id'] ?? $id);
                    if ($sigIdInQueue !== '') {
                        $blockedSignalIds[$sigIdInQueue] = $blockReason;
                    }
                    $queueMarkedNonExecutableTotal++;
                } elseif ($garbageVetoEnabled) {
                    // ── DBL pattern-status state machine (primary gate) ────────────────
                    $sscForPs1 = is_array($r['strategy_signal_context'] ?? null) ? $r['strategy_signal_context'] : [];
                    $sscForPs1 = $this->enrichDblContextWithParser2(
                        (string)($r['symbol']      ?? ''),
                        (string)($r['detected_at'] ?? ''),
                        $sscForPs1,
                        $config
                    );
                    $prepPs1 = $this->prepareDblPatternStateContextWithTrace(
                        (string)($r['symbol'] ?? ''),
                        (string)($r['detected_at'] ?? ''),
                        $r,
                        $sscForPs1,
                        $config
                    );
                    $sscForPs1 = $prepPs1['context'];
                    $ps = $this->applyDblPatternStatusStateMachine($r, $sscForPs1, $config);
                    $this->dblPatternStatusCheckedTotal++;
                    $sscPatternMerge = is_array($result[$id]['strategy_signal_context'] ?? null)
                        ? $result[$id]['strategy_signal_context'] : [];
                    $result[$id]['strategy_signal_context'] = array_merge($sscPatternMerge, $prepPs1['diag'], $ps['diag']);
                    $psStatus = (string)($ps['status'] ?? 'raw_candidate');
                    $reclaimFinalState = (string)($ps['diag']['reclaim_loss_final_state'] ?? 'unknown');
                    if (($ps['diag']['reclaim_lost_after_confirm'] ?? false) === true) {
                        $this->reclaimLostCheckedTotal++;
                        if (($ps['diag']['reclaim_level_lost'] ?? false) === true) {
                            $this->reclaimLostTerminalTotal++;
                            if (count($this->reclaimLostTerminalExamples) < 10) {
                                $this->reclaimLostTerminalExamples[] = [
                                    'symbol' => $r['symbol'] ?? null,
                                    'signal_id' => $id,
                                    'reclaim_loss_final_state' => $reclaimFinalState,
                                    'latest_price' => $ps['diag']['latest_price'] ?? null,
                                    'latest_price_distance_from_reclaim_pct' => $ps['diag']['latest_price_distance_from_reclaim_pct'] ?? null,
                                ];
                            }
                        } elseif ($reclaimFinalState === 'recovered') {
                            $this->reclaimLostRecoveredTotal++;
                            if (count($this->reclaimLostRecoveredExamples) < 10) {
                                $this->reclaimLostRecoveredExamples[] = [
                                    'symbol' => $r['symbol'] ?? null,
                                    'signal_id' => $id,
                                    'reclaim_loss_final_state' => $reclaimFinalState,
                                    'reclaim_retest_held' => $ps['diag']['reclaim_retest_held'] ?? null,
                                ];
                            }
                        } else {
                            $this->reclaimLostAmbiguousTotal++;
                        }
                    }
                    if ($psStatus === 'active') {
                        $this->dblPatternActiveTotal++;
                        $this->dblPatternPendingTotal++;
                        $this->dblPatternActiveBlockedFromHandoffTotal++;
                        if ($prevQueueStatus === 'pending') {
                            $this->dblPatternPendingRecheckedTotal++;
                        }
                        $blockReason = 'waiting_dbl_pattern_confirmation';
                        if ($prevReady !== false) { $result[$id]['handoff_ready'] = false; $changed = true; }
                        if ($prevExec  !== false) { $result[$id]['executable']    = false; $changed = true; }
                        if (($result[$id]['active_final'] ?? null) !== false) { $result[$id]['active_final'] = false; $changed = true; }
                        if (($result[$id]['block_reason'] ?? null) !== $blockReason) { $result[$id]['block_reason'] = $blockReason; $changed = true; }
                        if (($result[$id]['handoff_status'] ?? '') !== 'pending') { $result[$id]['handoff_status'] = 'pending'; $changed = true; }
                        $sigIdInQueue = (string)($r['signal_id'] ?? $id);
                        if ($sigIdInQueue !== '') {
                            $blockedSignalIds[$sigIdInQueue] = $blockReason;
                        }
                        $queueMarkedNonExecutableTotal++;
                        $this->writePendingPatternWatchEntry($r, $id, $ps, $config);
                        if (count($this->dblPatternActiveExamples) < 5) {
                            $this->dblPatternActiveExamples[] = $this->buildDblPatternExample($r, $id, $ps);
                        }
                        if (count($this->dblPatternPendingExamples) < 5) {
                            $this->dblPatternPendingExamples[] = $this->buildDblPatternExample($r, $id, $ps);
                        }
                        continue;
                    }
                    if ($psStatus === 'invalid') {
                        $this->dblPatternInvalidTotal++;
                        $this->patternStateBlockedBeforeGarbageTotal++;
                        $this->dblPatternInvalidBlockedFromHandoffTotal++;
                        // Sync pending_patterns.json: remove this signal if it's there as active
                        $this->removeDblPendingPatternEntry((string)($r['signal_id'] ?? $id));
                        if ($prevQueueStatus === 'pending') {
                            $this->dblPatternPendingRecheckedTotal++;
                            $this->dblPatternPendingInvalidatedTotal++;
                        }
                        $invReason = (string)($ps['invalid_reason'] ?? 'pattern_structure_invalid');
                        $blockReason = $invReason !== '' ? $invReason : 'dbl_pattern_invalid';
                        if ($prevReady !== false) { $result[$id]['handoff_ready'] = false; $changed = true; }
                        if ($prevExec  !== false) { $result[$id]['executable']    = false; $changed = true; }
                        if (($result[$id]['active_final'] ?? null) !== false) { $result[$id]['active_final'] = false; $changed = true; }
                        if (($result[$id]['block_reason'] ?? null) !== $blockReason) { $result[$id]['block_reason'] = $blockReason; $changed = true; }
                        if (($result[$id]['handoff_status'] ?? '') !== 'blocked') { $result[$id]['handoff_status'] = 'blocked'; $changed = true; }
                        $sigIdInQueue = (string)($r['signal_id'] ?? $id);
                        if ($sigIdInQueue !== '') {
                            $blockedSignalIds[$sigIdInQueue] = $blockReason;
                        }
                        $queueMarkedNonExecutableTotal++;
                        switch ($invReason) {
                            case 'dbl_trace_incomplete':
                                $this->dblTraceIncompleteTotal++;
                                break;
                            case 'point3_broken':
                                $this->dblPatternInvalidPoint3BrokenTotal++;
                                break;
                            case 'fresh_lower_low_after_point3':
                                $this->dblPatternInvalidFreshLowerLowTotal++;
                                break;
                            case 'reclaim_level_lost':
                            case 'neckline_reclaim_failed':
                                $this->dblPatternInvalidReclaimLostTotal++;
                                break;
                            case 'confirmation_ttl_expired':
                                $this->dblPatternInvalidTtlExpiredTotal++;
                                $this->dblPatternPendingExpiredTotal++;
                                break;
                            case 'weak_bounce_after_point3':
                                $this->dblPatternInvalidWeakBounceTotal++;
                                break;
                        }
                        if (count($this->dblPatternInvalidExamples) < 5) {
                            $this->dblPatternInvalidExamples[] = $this->buildDblPatternExample($r, $id, $ps);
                        }
                        if (count($this->patternStateBlockedBeforeGarbageExamples) < 10) {
                            $this->patternStateBlockedBeforeGarbageExamples[] = [
                                'symbol' => $r['symbol'] ?? null,
                                'signal_id' => $id,
                                'dbl_pattern_status' => $psStatus,
                                'invalid_reason' => $invReason,
                                'dbl_trace_complete' => $ps['diag']['dbl_trace_complete'] ?? null,
                                'dbl_trace_source' => $ps['diag']['dbl_trace_source'] ?? null,
                            ];
                        }
                        if (count($this->dblPatternPendingInvalidatedExamples) < 5) {
                            $this->dblPatternPendingInvalidatedExamples[] = $this->buildDblPatternExample($r, $id, $ps);
                        }
                        if ($invReason === 'dbl_trace_incomplete' && count($this->dblTraceIncompleteExamples) < 10) {
                            $this->dblTraceIncompleteExamples[] = [
                                'symbol' => $r['symbol'] ?? null,
                                'signal_id' => $id,
                                'point_1_missing' => $ps['diag']['point_1_missing'] ?? null,
                                'point_3_missing' => $ps['diag']['point_3_missing'] ?? null,
                                'neckline_present' => $ps['diag']['neckline_present'] ?? null,
                                'reclaim_present' => $ps['diag']['reclaim_present'] ?? null,
                                'dbl_trace_source' => $ps['diag']['dbl_trace_source'] ?? null,
                            ];
                        }
                        continue;
                    }
                    // confirmed/raw_candidate fallback that proceeds to garbage veto
                    $this->dblPatternConfirmedTotal++;
                    $this->dblPatternConfirmedSentToGarbageVetoTotal++;
                    $psPath = (string)($ps['confirmation_path'] ?? '');
                    if ($psPath === 'neckline_break') {
                        $this->dblPatternConfirmedNecklineBreakTotal++;
                    } elseif ($psPath === 'reclaim_hold') {
                        $this->dblPatternConfirmedReclaimHoldTotal++;
                    } elseif ($psPath === 'retest_hold') {
                        $this->dblPatternConfirmedRetestHoldTotal++;
                    } elseif ($psPath === 'higher_low_after_point3') {
                        $this->dblPatternConfirmedHigherLowTotal++;
                    }
                    if ($prevQueueStatus === 'pending') {
                        $this->dblPatternPendingRecheckedTotal++;
                        $this->dblPatternPendingConfirmedTotal++;
                        if (count($this->dblPatternPendingConfirmedExamples) < 5) {
                            $this->dblPatternPendingConfirmedExamples[] = $this->buildDblPatternExample($r, $id, $ps);
                        }
                    }
                    if (count($this->dblPatternConfirmedExamples) < 5) {
                        $this->dblPatternConfirmedExamples[] = $this->buildDblPatternExample($r, $id, $ps);
                    }

                    // ── DBL garbage veto ─────────────────────────────────────────────
                    $gv = $this->applyDblGarbageVeto(
                        $r,
                        is_array($r['strategy_signal_context'] ?? null) ? $r['strategy_signal_context'] : [],
                        $config
                    );
                    $this->dblGarbageVetoCheckedTotal++;
                    // Merge veto diagnostics back into strategy_signal_context
                    $sscMerge = is_array($result[$id]['strategy_signal_context'] ?? null)
                        ? $result[$id]['strategy_signal_context'] : [];
                    $result[$id]['strategy_signal_context'] = array_merge($sscMerge, $gv['diag']);
                    if (($gv['diag']['dbl_garbage_late_local_entry_checked'] ?? false) === true) {
                        $this->dblGarbageLateLocalEntryCheckedTotal++;
                        if (($gv['diag']['garbage_entry_far_from_point3'] ?? false) === true) {
                            $this->dblGarbageEntryFarFromPoint3Total++;
                        }
                        if (($gv['diag']['garbage_post_point3_impulse_already_spent'] ?? false) === true) {
                            $this->dblGarbagePostPoint3ImpulseSpentTotal++;
                        }
                        if (($gv['diag']['garbage_insufficient_room_to_recent_swing_high'] ?? false) === true) {
                            $this->dblGarbageInsufficientRoomSwingHighTotal++;
                        }
                        if (($gv['diag']['garbage_near_recent_swing_high'] ?? false) === true) {
                            $this->dblGarbageNearRecentSwingHighTotal++;
                        }
                        if (($gv['diag']['local_late_passed_due_quality'] ?? false) === true) {
                            $this->dblGarbageLateLocalPassedDueQualityTotal++;
                            if (count($this->dblGarbageLateLocalPassedExamples) < 10) {
                                $this->dblGarbageLateLocalPassedExamples[] = [
                                    'symbol'                           => $r['symbol'] ?? null,
                                    'q'                                => $gv['diag']['candidate_quality_score'] ?? null,
                                    'local_late_quality_tier'          => $gv['diag']['local_late_quality_tier'] ?? null,
                                    'local_late_flags_total'           => $gv['diag']['local_late_flags_total'] ?? null,
                                    'local_late_flags_required'        => $gv['diag']['local_late_flags_required'] ?? null,
                                    'entry_distance_from_point3_pct'   => $gv['diag']['entry_distance_from_point3_pct'] ?? null,
                                    'post_point3_impulse_spent_pct'    => $gv['diag']['post_point3_impulse_spent_pct'] ?? null,
                                    'room_to_recent_swing_high_roi'    => $gv['diag']['room_to_recent_swing_high_roi'] ?? null,
                                    'near_recent_swing_high'           => $gv['diag']['near_recent_swing_high'] ?? null,
                                    'local_late_tiny_room'             => $gv['diag']['local_late_tiny_room'] ?? null,
                                    'final_local_late_decision'        => $gv['diag']['local_late_decision'] ?? null,
                                ];
                            }
                        }
                    }
                    // Trace completeness counters
                    if (($gv['diag']['dbl_trace_checked'] ?? false) === true) {
                        $this->dblTraceCheckedTotal++;
                        if ($gv['diag']['dbl_trace_complete'] ?? false) {
                            $this->dblTraceCompleteTotal++;
                        } else {
                            $this->dblTraceMissingTotal++;
                            if (count($this->dblTraceMissingExamples) < 10) {
                                $this->dblTraceMissingExamples[] = [
                                    'symbol'                      => $r['symbol'] ?? null,
                                    'signal_id'                   => $id,
                                    'candidate_quality_score'     => $gv['diag']['candidate_quality_score'] ?? null,
                                    'setup_class'                 => $gv['diag']['setup_class'] ?? null,
                                    'pending_confirmation_status' => $gv['diag']['pending_confirmation_status'] ?? null,
                                    'pending_confirmation_reason' => $r['strategy_signal_context']['pending_confirmation_reason'] ?? null,
                                    'ob_wall_checked'             => $gv['diag']['ob_wall_checked'] ?? false,
                                    'ob_ask_wall_risk'            => $gv['diag']['ob_ask_wall_risk'] ?? false,
                                    'point_1_low_price'           => $r['strategy_signal_context']['point_1_low_price']  ?? null,
                                    'point_2_neckline_price'      => $r['strategy_signal_context']['point_2_neckline_price'] ?? null,
                                    'point_3_second_low_price'    => $gv['diag']['point_3_second_low_price'] ?? null,
                                    'neckline_level'              => $r['strategy_signal_context']['neckline_level'] ?? null,
                                    'reclaim_level'               => $gv['diag']['reclaim_level'] ?? null,
                                    'reclaim_confirmed'           => $gv['diag']['reclaim_confirmed'] ?? false,
                                    'entry_distance_from_point3_pct' => $gv['diag']['entry_distance_from_point3_pct'] ?? null,
                                    'dbl_trace_source'            => $gv['diag']['dbl_trace_source'] ?? 'missing',
                                    'garbage_veto_reason'         => $gv['reason'] ?? null,
                                ];
                            }
                        }
                        if ($gv['diag']['dbl_trace_reconstructed_from_parser2'] ?? false) {
                            $this->dblTraceReconstructedTotal++;
                            if (count($this->dblTraceReconstructedExamples) < 5) {
                                $this->dblTraceReconstructedExamples[] = [
                                    'symbol'           => $r['symbol'] ?? null,
                                    'signal_id'        => $id,
                                    'dbl_trace_source' => $gv['diag']['dbl_trace_source'] ?? null,
                                    'dbl_trace_complete' => $gv['diag']['dbl_trace_complete'] ?? false,
                                    'point_3_second_low_price' => $gv['diag']['point_3_second_low_price'] ?? null,
                                ];
                            }
                        }
                        if ($gv['diag']['dbl_trace_reconstruction_failed'] ?? false) {
                            $this->dblTraceReconstructionFailedTotal++;
                        }
                    }
                    if ($gv['diag']['dbl_trace_missing_high_quality_bypass'] ?? false) {
                        $this->dblGarbageMissingTracePassedHighQualityTotal++;
                    }

                    if ($gv['veto_triggered']) {
                        $this->dblGarbageVetoBlockedTotal++;
                        switch ($gv['reason']) {
                            case 'garbage_low_quality_without_obc_confirmation':
                                $this->dblGarbageLowQualityWithoutObcTotal++;
                                break;
                            case 'garbage_obc_quality_skip':
                                $this->dblGarbageObcQualitySkipTotal++;
                                break;
                            case 'garbage_late_daily_extension_long':
                                $this->dblGarbageLateExtensionTotal++;
                                break;
                            case 'garbage_whipsaw_weak_quality':
                                $this->dblGarbageWhipsawWeakQualityTotal++;
                                break;
                            case 'garbage_local_late_entry_after_recovery':
                                $this->dblGarbageLateLocalEntryBlockedTotal++;
                                $lateTier = (string)($gv['diag']['local_late_quality_tier'] ?? '');
                                if ($lateTier === 'high') {
                                    $this->dblGarbageLateLocalHighQualityBlockedTotal++;
                                } elseif ($lateTier === 'mid') {
                                    $this->dblGarbageLateLocalMidQualityBlockedTotal++;
                                } else {
                                    $this->dblGarbageLateLocalLowQualityBlockedTotal++;
                                }
                                break;
                            case 'garbage_missing_critical_dbl_trace':
                                $this->dblGarbageMissingTraceBlockedTotal++;
                                break;
                            case 'garbage_reclaim_not_confirmed':
                                $this->dblGarbageReclaimNotConfirmedTotal++;
                                break;
                            case 'garbage_late_local_tiny_room_without_reclaim':
                                $this->dblGarbageLateLocalTinyRoomWithoutReclaimTotal++;
                                break;
                        }
                        $blockReason = (string)$gv['reason'];
                        if ($prevReady !== false) { $result[$id]['handoff_ready'] = false; $changed = true; }
                        if ($prevExec  !== false) { $result[$id]['executable']    = false; $changed = true; }
                        if (($r['block_reason'] ?? null) !== $blockReason) { $result[$id]['block_reason'] = $blockReason; $changed = true; }
                        if (($result[$id]['handoff_status'] ?? '') !== 'blocked') { $result[$id]['handoff_status'] = 'blocked'; $changed = true; }
                        $sigIdInQueue = (string)($r['signal_id'] ?? $id);
                        if ($sigIdInQueue !== '') {
                            $blockedSignalIds[$sigIdInQueue] = $blockReason;
                        }
                        $queueMarkedNonExecutableTotal++;
                        // Near-miss: veto triggered with at most one secondary reason
                        // means the signal was close to passing (one factor pushed it over).
                        $nmSecondary = array_filter(
                            $gv['secondary_reasons'] ?? [],
                            fn($s) => !str_starts_with($s, 'local_late_quality_tier=')
                                   && !str_starts_with($s, 'local_late_flags=')
                                   && !str_starts_with($s, 'setup_class=')
                                   && !str_starts_with($s, 'pending_confirmation_status=')
                        );
                        if (count($nmSecondary) <= 1) {
                            $this->dblNearMissTotal++;
                            $this->dblNearMissByReason[$blockReason] = ($this->dblNearMissByReason[$blockReason] ?? 0) + 1;
                            if (count($this->dblNearMissExamples) < 10) {
                                $this->dblNearMissExamples[] = [
                                    'symbol'                         => $r['symbol'] ?? null,
                                    'signal_id'                      => $id,
                                    'candidate_quality_score'        => $gv['diag']['candidate_quality_score'] ?? null,
                                    'setup_class'                    => $gv['diag']['setup_class'] ?? null,
                                    'dbl_pattern_status'             => $r['dbl_pattern_status'] ?? ($r['strategy_signal_context']['dbl_pattern_status'] ?? null),
                                    'block_reason'                   => $blockReason,
                                    'garbage_veto_reason'            => $blockReason,
                                    'secondary_reasons'              => $gv['secondary_reasons'] ?? [],
                                    'point3_confirmed'               => (bool)($r['strategy_signal_context']['point_3_confirmed'] ?? false),
                                    'reclaim_confirmed'              => $gv['diag']['reclaim_confirmed'] ?? false,
                                    'neckline_reclaim_confirmed'     => $gv['diag']['neckline_reclaim_confirmed'] ?? false,
                                    'reclaim_retest_held'            => (bool)($r['strategy_signal_context']['reclaim_retest_held'] ?? false),
                                    'entry_distance_from_point3_pct' => $gv['diag']['entry_distance_from_point3_pct'] ?? null,
                                    'room_to_recent_swing_high_roi'  => $gv['diag']['room_to_recent_swing_high_roi'] ?? null,
                                    'local_late_flags_total'         => $gv['diag']['local_late_flags_total'] ?? null,
                                    'local_late_flags_required'      => $gv['diag']['local_late_flags_required'] ?? null,
                                    'local_late_tiny_room_detected'  => $gv['diag']['local_late_tiny_room_detected'] ?? false,
                                ];
                            }
                        }
                        if (count($this->dblGarbageBlockExamples) < 10) {
                            $this->dblGarbageBlockExamples[] = [
                                'symbol'                     => $r['symbol']       ?? null,
                                'signal_id'                  => $id,
                                'detected_at'                => $r['detected_at']  ?? null,
                                'block_reason'               => $blockReason,
                                'secondary_reasons'          => $gv['secondary_reasons'],
                                'candidate_quality_score'    => $gv['diag']['candidate_quality_score'],
                                'warnings'                   => $gv['diag']['warnings'],
                                'ob_skip_reason'             => $gv['diag']['ob_skip_reason'],
                                'day_change_pct'             => $gv['diag']['day_change_pct'],
                                'position_in_24h_range_pct'  => $gv['diag']['position_in_24h_range_pct'],
                                'recent_10m_range_roi'       => $gv['diag']['recent_10m_range_roi'],
                                'recent_60m_direction_flips' => $gv['diag']['recent_60m_direction_flips'],
                                'entry_distance_from_point3_pct' => $gv['diag']['entry_distance_from_point3_pct'] ?? null,
                                'entry_distance_from_reclaim_pct' => $gv['diag']['entry_distance_from_reclaim_pct'] ?? null,
                                'post_point3_bounce_roi' => $gv['diag']['post_point3_bounce_roi'] ?? null,
                                'post_point3_impulse_spent_pct' => $gv['diag']['post_point3_impulse_spent_pct'] ?? null,
                                'room_to_recent_swing_high_roi' => $gv['diag']['room_to_recent_swing_high_roi'] ?? null,
                                'near_recent_swing_high' => $gv['diag']['near_recent_swing_high'] ?? null,
                                'recent_swing_high_price' => $gv['diag']['recent_swing_high_price'] ?? null,
                                'recent_swing_high_time' => $gv['diag']['recent_swing_high_time'] ?? null,
                                'dbl_trace_source'        => $gv['diag']['dbl_trace_source'] ?? null,
                                'dbl_trace_complete'      => $gv['diag']['dbl_trace_complete'] ?? null,
                            ];
                        }
                        if ($blockReason === 'garbage_local_late_entry_after_recovery'
                            && count($this->dblGarbageLateLocalEntryExamples) < 10
                        ) {
                            $this->dblGarbageLateLocalEntryExamples[] = [
                                'symbol'                         => $r['symbol'] ?? null,
                                'signal_id'                      => $id,
                                'candidate_quality_score'        => $gv['diag']['candidate_quality_score'] ?? null,
                                'entry_price'                    => $gv['diag']['entry_price'] ?? null,
                                'point_3_second_low_price'       => $gv['diag']['point_3_second_low_price'] ?? null,
                                'reclaim_level'                  => $gv['diag']['reclaim_level'] ?? null,
                                'entry_distance_from_point3_pct' => $gv['diag']['entry_distance_from_point3_pct'] ?? null,
                                'entry_distance_from_reclaim_pct'=> $gv['diag']['entry_distance_from_reclaim_pct'] ?? null,
                                'post_point3_bounce_roi'         => $gv['diag']['post_point3_bounce_roi'] ?? null,
                                'post_point3_impulse_spent_pct'  => $gv['diag']['post_point3_impulse_spent_pct'] ?? null,
                                'room_to_recent_swing_high_roi'  => $gv['diag']['room_to_recent_swing_high_roi'] ?? null,
                                'near_recent_swing_high'         => $gv['diag']['near_recent_swing_high'] ?? null,
                                'local_recovery_leg_low_price'   => $gv['diag']['local_recovery_leg_low_price'] ?? null,
                                'local_recovery_leg_high_price'  => $gv['diag']['local_recovery_leg_high_price'] ?? null,
                                'recent_swing_high_price'        => $gv['diag']['recent_swing_high_price'] ?? null,
                                'recent_swing_high_time'         => $gv['diag']['recent_swing_high_time'] ?? null,
                                'local_late_quality_tier'        => $gv['diag']['local_late_quality_tier'] ?? null,
                                'local_late_flags_total'         => $gv['diag']['local_late_flags_total'] ?? null,
                                'local_late_flags_required'      => $gv['diag']['local_late_flags_required'] ?? null,
                                'local_late_tiny_room'           => $gv['diag']['local_late_tiny_room'] ?? null,
                                'local_late_decision'            => $gv['diag']['local_late_decision'] ?? null,
                                'garbage_veto_reason'            => $blockReason,
                                'garbage_veto_secondary_reasons' => $gv['secondary_reasons'] ?? [],
                                'dbl_trace_source'               => $gv['diag']['dbl_trace_source'] ?? null,
                            ];
                        }
                        if ($blockReason === 'garbage_missing_critical_dbl_trace'
                            && count($this->dblGarbageMissingTraceBlockExamples) < 10
                        ) {
                            $this->dblGarbageMissingTraceBlockExamples[] = [
                                'symbol'                      => $r['symbol'] ?? null,
                                'signal_id'                   => $id,
                                'candidate_quality_score'     => $gv['diag']['candidate_quality_score'] ?? null,
                                'setup_class'                 => $gv['diag']['setup_class'] ?? null,
                                'pending_confirmation_status' => $gv['diag']['pending_confirmation_status'] ?? null,
                                'pending_confirmation_reason' => $r['strategy_signal_context']['pending_confirmation_reason'] ?? null,
                                'ob_wall_checked'             => $gv['diag']['ob_wall_checked'] ?? false,
                                'ob_ask_wall_risk'            => $gv['diag']['ob_ask_wall_risk'] ?? false,
                                'point_1_low_price'           => $r['strategy_signal_context']['point_1_low_price']  ?? null,
                                'point_2_neckline_price'      => $r['strategy_signal_context']['point_2_neckline_price'] ?? null,
                                'point_3_second_low_price'    => $gv['diag']['point_3_second_low_price'] ?? null,
                                'neckline_level'              => $r['strategy_signal_context']['neckline_level'] ?? null,
                                'reclaim_level'               => $gv['diag']['reclaim_level'] ?? null,
                                'reclaim_confirmed'           => $gv['diag']['reclaim_confirmed'] ?? false,
                                'entry_distance_from_point3_pct' => $gv['diag']['entry_distance_from_point3_pct'] ?? null,
                                'dbl_trace_source'            => $gv['diag']['dbl_trace_source'] ?? 'missing',
                                'garbage_veto_reason'         => $blockReason,
                                'secondary_reasons'           => $gv['secondary_reasons'] ?? [],
                            ];
                        }
                        if ($blockReason === 'garbage_reclaim_not_confirmed'
                            && count($this->dblGarbageReclaimNotConfirmedExamples) < 10
                        ) {
                            $this->dblGarbageReclaimNotConfirmedExamples[] = [
                                'symbol'                      => $r['symbol'] ?? null,
                                'signal_id'                   => $id,
                                'candidate_quality_score'     => $gv['diag']['candidate_quality_score'] ?? null,
                                'setup_class'                 => $gv['diag']['setup_class'] ?? null,
                                'pending_confirmation_status' => $gv['diag']['pending_confirmation_status'] ?? null,
                                'pending_confirmation_reason' => $r['strategy_signal_context']['pending_confirmation_reason'] ?? null,
                                'ob_wall_checked'             => $gv['diag']['ob_wall_checked'] ?? false,
                                'ob_ask_wall_risk'            => $gv['diag']['ob_ask_wall_risk'] ?? false,
                                'point_1_low_price'           => $r['strategy_signal_context']['point_1_low_price']  ?? null,
                                'point_2_neckline_price'      => $r['strategy_signal_context']['point_2_neckline_price'] ?? null,
                                'point_3_second_low_price'    => $gv['diag']['point_3_second_low_price'] ?? null,
                                'neckline_level'              => $r['strategy_signal_context']['neckline_level'] ?? null,
                                'reclaim_level'               => $gv['diag']['reclaim_level'] ?? null,
                                'reclaim_confirmed'           => $gv['diag']['reclaim_confirmed'] ?? false,
                                'entry_distance_from_point3_pct' => $gv['diag']['entry_distance_from_point3_pct'] ?? null,
                                'dbl_trace_source'            => $gv['diag']['dbl_trace_source'] ?? null,
                                'garbage_veto_reason'         => $blockReason,
                                'secondary_reasons'           => $gv['secondary_reasons'] ?? [],
                            ];
                        }
                        if ($blockReason === 'garbage_late_local_tiny_room_without_reclaim'
                            && count($this->dblGarbageLateLocalTinyRoomWithoutReclaimExamples) < 10
                        ) {
                            $this->dblGarbageLateLocalTinyRoomWithoutReclaimExamples[] = [
                                'symbol'                         => $r['symbol'] ?? null,
                                'signal_id'                      => $id,
                                'candidate_quality_score'        => $gv['diag']['candidate_quality_score'] ?? null,
                                'setup_class'                    => $gv['diag']['setup_class'] ?? null,
                                'entry_distance_from_point3_pct' => $gv['diag']['entry_distance_from_point3_pct'] ?? null,
                                'room_to_recent_swing_high_roi'  => $gv['diag']['room_to_recent_swing_high_roi'] ?? null,
                                'reclaim_confirmed'              => $gv['diag']['reclaim_confirmed'] ?? false,
                                'neckline_reclaim_confirmed'     => $gv['diag']['neckline_reclaim_confirmed'] ?? false,
                                'local_late_tiny_room_reason'    => $gv['diag']['local_late_tiny_room_reason'] ?? null,
                                'garbage_veto_reason'            => $blockReason,
                                'secondary_reasons'              => $gv['secondary_reasons'] ?? [],
                            ];
                        }
                    } else {
                        // Veto passed and pattern is confirmed — allow handoff
                        $this->dblGarbagePassedTotal++;
                        $this->dblPatternConfirmedHandoffReadyTotal++;
                        if ($prevReady !== true)  { $result[$id]['handoff_ready']  = true;  $changed = true; }
                        if ($prevExec  !== true)  { $result[$id]['executable']     = true;  $changed = true; }
                        if (($result[$id]['active_final'] ?? null) !== true) { $result[$id]['active_final'] = true; $changed = true; }
                        if (($r['stale']        ?? null) !== false) { $result[$id]['stale']       = false; $changed = true; }
                        if (($r['stale_reason'] ?? null) !== null)  { $result[$id]['stale_reason'] = null;  $changed = true; }
                        if (($r['block_reason'] ?? null) !== null)  { $result[$id]['block_reason'] = null;  $changed = true; }
                        $queueExecutableTotal++;
                        if ($isSoftDemoted) {
                            $softDemoteAllowedTotal++;
                        }
                        // If Veto 8 soft penalty fired (diagnostic_only mode), count as near-miss for awareness.
                        if (($gv['diag']['local_late_tiny_room_soft_penalty'] ?? false) === true) {
                            $nmReason = 'soft_late_local_tiny_room_no_reclaim';
                            $this->dblNearMissTotal++;
                            $this->dblNearMissByReason[$nmReason] = ($this->dblNearMissByReason[$nmReason] ?? 0) + 1;
                            if (count($this->dblNearMissExamples) < 10) {
                                $this->dblNearMissExamples[] = [
                                    'symbol'                         => $r['symbol'] ?? null,
                                    'signal_id'                      => $id,
                                    'candidate_quality_score'        => $gv['diag']['candidate_quality_score'] ?? null,
                                    'setup_class'                    => $gv['diag']['setup_class'] ?? null,
                                    'dbl_pattern_status'             => 'confirmed',
                                    'block_reason'                   => null,
                                    'garbage_veto_reason'            => null,
                                    'secondary_reasons'              => ['soft_penalty:local_late_tiny_room_no_reclaim'],
                                    'point3_confirmed'               => (bool)($r['strategy_signal_context']['point_3_confirmed'] ?? false),
                                    'reclaim_confirmed'              => $gv['diag']['reclaim_confirmed'] ?? false,
                                    'neckline_reclaim_confirmed'     => $gv['diag']['neckline_reclaim_confirmed'] ?? false,
                                    'reclaim_retest_held'            => (bool)($r['strategy_signal_context']['reclaim_retest_held'] ?? false),
                                    'entry_distance_from_point3_pct' => $gv['diag']['entry_distance_from_point3_pct'] ?? null,
                                    'room_to_recent_swing_high_roi'  => $gv['diag']['room_to_recent_swing_high_roi'] ?? null,
                                    'local_late_flags_total'         => $gv['diag']['local_late_flags_total'] ?? null,
                                    'local_late_flags_required'      => $gv['diag']['local_late_flags_required'] ?? null,
                                    'local_late_tiny_room_detected'  => true,
                                    'note'                           => 'diagnostic_only_veto8_would_have_blocked',
                                ];
                            }
                        }
                        if (count($this->dblGarbagePassExamples) < 5) {
                            $this->dblGarbagePassExamples[] = [
                                'symbol'                  => $r['symbol']      ?? null,
                                'signal_id'               => $id,
                                'detected_at'             => $r['detected_at'] ?? null,
                                'candidate_quality_score' => $gv['diag']['candidate_quality_score'],
                                'day_change_pct'          => $gv['diag']['day_change_pct'],
                                'position_in_24h_range_pct' => $gv['diag']['position_in_24h_range_pct'],
                            ];
                        }
                    }
                } else {
                    // Garbage veto disabled — still require confirmed DBL pattern status.
                    $sscForPs2 = is_array($r['strategy_signal_context'] ?? null) ? $r['strategy_signal_context'] : [];
                    $sscForPs2 = $this->enrichDblContextWithParser2(
                        (string)($r['symbol']      ?? ''),
                        (string)($r['detected_at'] ?? ''),
                        $sscForPs2,
                        $config
                    );
                    $prepPs2 = $this->prepareDblPatternStateContextWithTrace(
                        (string)($r['symbol'] ?? ''),
                        (string)($r['detected_at'] ?? ''),
                        $r,
                        $sscForPs2,
                        $config
                    );
                    $sscForPs2 = $prepPs2['context'];
                    $ps = $this->applyDblPatternStatusStateMachine($r, $sscForPs2, $config);
                    $this->dblPatternStatusCheckedTotal++;
                    $sscPatternMerge = is_array($result[$id]['strategy_signal_context'] ?? null)
                        ? $result[$id]['strategy_signal_context'] : [];
                    $result[$id]['strategy_signal_context'] = array_merge($sscPatternMerge, $prepPs2['diag'], $ps['diag']);
                    $reclaimFinalState = (string)($ps['diag']['reclaim_loss_final_state'] ?? 'unknown');
                    if (($ps['diag']['reclaim_lost_after_confirm'] ?? false) === true) {
                        $this->reclaimLostCheckedTotal++;
                        if (($ps['diag']['reclaim_level_lost'] ?? false) === true) {
                            $this->reclaimLostTerminalTotal++;
                        } elseif ($reclaimFinalState === 'recovered') {
                            $this->reclaimLostRecoveredTotal++;
                        } else {
                            $this->reclaimLostAmbiguousTotal++;
                        }
                    }
                    if (($ps['status'] ?? 'raw_candidate') === 'confirmed') {
                        $this->dblPatternConfirmedTotal++;
                        $this->dblPatternConfirmedHandoffReadyTotal++;
                        if ($prevReady !== true)  { $result[$id]['handoff_ready']  = true;  $changed = true; }
                        if ($prevExec  !== true)  { $result[$id]['executable']     = true;  $changed = true; }
                        if (($result[$id]['active_final'] ?? null) !== true) { $result[$id]['active_final'] = true; $changed = true; }
                        if (($r['stale']        ?? null) !== false) { $result[$id]['stale']       = false; $changed = true; }
                        if (($r['stale_reason'] ?? null) !== null)  { $result[$id]['stale_reason'] = null;  $changed = true; }
                        if (($r['block_reason'] ?? null) !== null)  { $result[$id]['block_reason'] = null;  $changed = true; }
                        $queueExecutableTotal++;
                        if ($isSoftDemoted) {
                            $softDemoteAllowedTotal++;
                        }
                    } else {
                        $this->patternStateBlockedBeforeGarbageTotal++;
                        $blockReason = ($ps['status'] ?? '') === 'active'
                            ? 'waiting_dbl_pattern_confirmation'
                            : ((string)($ps['invalid_reason'] ?? 'dbl_pattern_invalid'));
                        if ($prevReady !== false) { $result[$id]['handoff_ready'] = false; $changed = true; }
                        if ($prevExec  !== false) { $result[$id]['executable']    = false; $changed = true; }
                        if (($result[$id]['active_final'] ?? null) !== false) { $result[$id]['active_final'] = false; $changed = true; }
                        if (($result[$id]['block_reason'] ?? null) !== $blockReason) { $result[$id]['block_reason'] = $blockReason; $changed = true; }
                        if (($result[$id]['handoff_status'] ?? '') !== (($ps['status'] ?? '') === 'active' ? 'pending' : 'blocked')) {
                            $result[$id]['handoff_status'] = (($ps['status'] ?? '') === 'active' ? 'pending' : 'blocked');
                            $changed = true;
                        }
                        $queueMarkedNonExecutableTotal++;
                    }
                }
            } else {
                // Non-executable: determine most-specific block reason
                $blockReason = $r['block_reason'] ?? $r['stale_reason'] ?? null;
                if ($needsRevalid && $blockReason === null) {
                    $blockReason = 'handoff_blocked_needs_revalidation_after_symbol_block';
                } elseif ($status === 'expired' && $blockReason === null) {
                    $blockReason = 'handoff_blocked_expired_signal';
                } elseif ($status === 'withdrawn' && $blockReason === null) {
                    $blockReason = 'handoff_blocked_withdrawn';
                }
                if (is_string($blockReason) && str_starts_with($blockReason, 'garbage_')) {
                    if (($result[$id]['handoff_status'] ?? '') !== 'blocked') {
                        $result[$id]['handoff_status'] = 'blocked';
                        $changed = true;
                    }
                }

                if ($prevReady !== false) { $result[$id]['handoff_ready'] = false; $changed = true; }
                if ($prevExec  !== false) { $result[$id]['executable']    = false; $changed = true; }
                // Lifecycle consistency: withdrawn/expired/blocked/stale records must not have active_final=true.
                $this->lifecycleConsistencyCheckedTotal++;
                $prevActiveFinal = $result[$id]['active_final'] ?? null;
                if ($prevActiveFinal !== false) {
                    $result[$id]['active_final'] = false;
                    $changed = true;
                    $this->lifecycleInconsistentFixedTotal++;
                    if (count($this->lifecycleInconsistentExamples) < 5) {
                        $this->lifecycleInconsistentExamples[] = [
                            'symbol'                => $r['symbol']     ?? null,
                            'signal_id'             => $id,
                            'handoff_status'        => $status,
                            'previous_active_final' => $prevActiveFinal,
                            'block_reason'          => $blockReason,
                        ];
                    }
                }
                if ($needsRevalid && ($r['blocked_by_symbol_guard'] ?? false) !== true) {
                    $result[$id]['blocked_by_symbol_guard'] = true;
                    $changed = true;
                }
                if ($blockReason !== null && ($r['block_reason'] ?? null) !== $blockReason) {
                    $result[$id]['block_reason'] = $blockReason;
                    $changed = true;
                }

                if ($changed) {
                    $queueMarkedNonExecutableTotal++;
                }
                if ($blockReason === 'handoff_blocked_not_current_run') {
                    $queueBlockedNcrTotal++;
                }
                // Record in the signal_id → block_reason map for signals.json alignment
                $sigIdInQueue = (string)($r['signal_id'] ?? $id);
                if ($sigIdInQueue !== '') {
                    $blockedSignalIds[$sigIdInQueue] = $blockReason ?? 'handoff_blocked_non_executable';
                }
                // Count revalidation-needed entries separately for the existing counter
                if ($needsRevalid) {
                    $blockedNeedsRevalidationTotal++;
                    if (count($revalidationRequiredExamples) < 5) {
                        $revalidationRequiredExamples[] = [
                            'symbol'      => $r['symbol']    ?? null,
                            'side'        => $r['side']      ?? 'long',
                            'strategy'    => 'double_bottom_long',
                            'signal_id'   => $r['signal_id'] ?? null,
                            'detected_at' => $r['detected_at'] ?? null,
                            'blocked_source' => $r['symbol_guard_block_source'] ?? 'unknown',
                            'reason'      => 'handoff_blocked_needs_revalidation_after_symbol_block',
                            'needs_revalidation_after_unblock' => true,
                        ];
                    }
                }

                // Collect examples for entries that changed to non-executable
                if ($changed && count($queueNonExecutableExamples) < 5) {
                    $detTs = isset($r['detected_at']) ? strtotime($r['detected_at']) : 0;
                    $queueNonExecutableExamples[] = [
                        'symbol'                  => $r['symbol']     ?? null,
                        'signal_id'               => $id,
                        'previous_handoff_ready'  => $prevReady,
                        'previous_executable'     => $prevExec,
                        'new_handoff_ready'       => false,
                        'new_executable'          => false,
                        'reason'                  => $blockReason,
                        'detected_at'             => $r['detected_at'] ?? null,
                        'age_minutes'             => ($detTs > 0)
                            ? round(($nowTs - $detTs) / 60, 1)
                            : null,
                    ];
                }
            }

            if ($changed) {
                $queueNormalizedTotal++;
            }
        }

        // Compute readyTotal from normalized result
        $readyTotal = $queueExecutableTotal;

        // Handoff queue hygiene diagnostics.
        foreach ($result as $qRec) {
            $qStatus = (string)($qRec['handoff_status'] ?? '');
            $qReady  = (bool)($qRec['handoff_ready'] ?? false);
            $qExec   = (bool)($qRec['executable'] ?? false);
            $qReason = (string)($qRec['block_reason'] ?? '');
            $isGarbageBlocked = $qReason !== '' && str_starts_with($qReason, 'garbage_');
            if ($qExec && $qReady && in_array($qStatus, ['new', 'refreshed'], true)) {
                $handoffQueueReadyWrittenTotal++;
            }
            if ($isGarbageBlocked) {
                $handoffQueueBlockedDiagnosticTotal++;
                if (in_array($qStatus, ['new', 'refreshed'], true)) {
                    $handoffQueueBlockedWithReadyStatusTotal++;
                    if (count($handoffQueueBlockedWithReadyStatusExamples) < 5) {
                        $handoffQueueBlockedWithReadyStatusExamples[] = [
                            'symbol'        => $qRec['symbol'] ?? null,
                            'signal_id'     => $qRec['signal_id'] ?? null,
                            'handoff_status'=> $qStatus,
                            'handoff_ready' => $qReady,
                            'executable'    => $qExec,
                            'block_reason'  => $qReason,
                        ];
                    }
                }
            }
        }

        // Cleanup pending_patterns active storage after final handoff lifecycle is known.
        $pendingCleanup = $this->cleanupDblPendingPatternsAfterHandoff($result);
        $pendingCleanupCheckedTotal               = (int)($pendingCleanup['pending_cleanup_checked_total'] ?? 0);
        $pendingCleanupRemovedConfirmedTotal      = (int)($pendingCleanup['pending_cleanup_removed_confirmed_total'] ?? 0);
        $pendingCleanupRemovedGarbageBlockedTotal = (int)($pendingCleanup['pending_cleanup_removed_garbage_blocked_total'] ?? 0);
        $pendingCleanupRemovedInvalidTotal        = (int)($pendingCleanup['pending_cleanup_removed_invalid_total'] ?? 0);
        $pendingCleanupRemovedExpiredTotal        = (int)($pendingCleanup['pending_cleanup_removed_expired_total'] ?? 0);
        $pendingCleanupHistoryWrittenTotal        = (int)($pendingCleanup['pending_cleanup_history_written_total'] ?? 0);
        $pendingCleanupExamples                   = (array)($pendingCleanup['pending_cleanup_examples'] ?? []);

        $this->writeJson('storage/bot_handoff_queue.json', array_values($result));

        // Accumulate soft_demote handoff-block counters into class properties for last_run.
        $this->obWallSoftDemoteBlockedHandoffTotal += $softDemoteBlockedTotal;
        $this->obWallSoftDemoteAllowedHandoffTotal += $softDemoteAllowedTotal;
        foreach ($softDemoteBlockExamples as $ex) {
            if (count($this->obWallSoftDemoteBlockExamples) < 5) {
                $this->obWallSoftDemoteBlockExamples[] = $ex;
            }
        }

        return [
            'ready_total'     => $readyTotal,
            'new_total'       => $newTotal,
            'refreshed_total' => $refreshedTotal,
            'expired_total'   => $expiredTotal,
            // Freshness gate counters
            'blocked_stale_total'                        => $blockedStaleTotal,
            'blocked_not_current_run_total'              => $blockedNotCurrentRunTotal,
            'removed_stale_queue_entries_total'          => $removedStaleQueueEntriesTotal,
            'blocked_needs_revalidation_total'           => $blockedNeedsRevalidationTotal,
            'revalidated_after_unblock_total'            => $revalidatedAfterUnblockTotal,
            'stale_block_examples'                       => $staleBlockExamples,
            'revalidation_required_examples'             => $revalidationRequiredExamples,
            // Current-run freshness diagnostics
            'current_run_freshness_checked_total'        => $currentRunFreshnessCheckedTotal,
            'current_run_freshness_passed_total'         => $currentRunFreshnessPassedTotal,
            'current_run_freshness_blocked_total'        => $currentRunFreshnessBlockedTotal,
            'current_run_freshness_detected_at_only_total' => $currentRunFreshnessDetectedAtOnlyTotal,
            'current_run_freshness_refreshed_at_used_total' => $currentRunFreshnessRefreshedAtUsedTotal,
            'current_run_freshness_pending_recheck_bypassed_total' => $currentRunFreshnessPendingRecheckBypassedTotal,
            'current_run_freshness_examples'             => $currentRunFreshnessExamples,
            // Confirmed-pattern freshness diagnostics
            'confirmed_pattern_freshness_checked_total'          => $confirmedPatternFreshnessCheckedTotal,
            'confirmed_pattern_valid_for_handoff_total'          => $confirmedPatternValidForHandoffTotal,
            'confirmed_pattern_expired_total'                    => $confirmedPatternExpiredTotal,
            'confirmed_pattern_invalidated_total'                => $confirmedPatternInvalidatedTotal,
            'confirmed_pattern_blocked_by_generic_freshness_total' => $confirmedPatternBlockedByGenericFreshnessTotal,
            // Pending storage cleanup diagnostics
            'pending_cleanup_checked_total'                      => $pendingCleanupCheckedTotal,
            'pending_cleanup_removed_confirmed_total'            => $pendingCleanupRemovedConfirmedTotal,
            'pending_cleanup_removed_garbage_blocked_total'      => $pendingCleanupRemovedGarbageBlockedTotal,
            'pending_cleanup_removed_invalid_total'              => $pendingCleanupRemovedInvalidTotal,
            'pending_cleanup_removed_expired_total'              => $pendingCleanupRemovedExpiredTotal,
            'pending_cleanup_history_written_total'              => $pendingCleanupHistoryWrittenTotal,
            'pending_cleanup_examples'                           => $pendingCleanupExamples,
            // Queue normalization counters (Task: explicit non-executable flags)
            'queue_entries_normalized_total'              => $queueNormalizedTotal,
            'queue_entries_marked_non_executable_total'   => $queueMarkedNonExecutableTotal,
            'queue_entries_executable_total'              => $queueExecutableTotal,
            'queue_entries_blocked_not_current_run_total' => $queueBlockedNcrTotal,
            'queue_entries_blocked_blacklist_total'       => $queueBlockedBlTotal,
            'queue_entries_blocked_freeze_total'          => $queueBlockedFrTotal,
            'queue_non_executable_examples'              => $queueNonExecutableExamples,
            'handoff_queue_ready_written_total'          => $handoffQueueReadyWrittenTotal,
            'handoff_queue_blocked_diagnostic_total'     => $handoffQueueBlockedDiagnosticTotal,
            'handoff_queue_blocked_with_ready_status_total' => $handoffQueueBlockedWithReadyStatusTotal,
            'handoff_queue_blocked_with_ready_status_examples' => $handoffQueueBlockedWithReadyStatusExamples,
            // OBC soft_demote handoff-block counters
            'soft_demote_blocked_handoff_total' => $softDemoteBlockedTotal,
            'soft_demote_allowed_handoff_total' => $softDemoteAllowedTotal,
            'soft_demote_block_examples'        => $softDemoteBlockExamples,
            // Map of signal_id → block_reason for non-executable entries (for signals.json alignment)
            'blocked_signal_ids'                          => $blockedSignalIds,
            // Active records (new/refreshed/executable) for trace diagnostics
            'active_records'  => array_values(array_filter(
                $result,
                fn($r) => ($r['executable'] ?? false) === true
            )),
            // SSC patch map: signal_id → pattern-status + freshness fields to propagate back to signals.json
            'signal_ssc_patch_map' => $this->buildHandoffSscPatchMap($result),
        ];
    }

    /**
     * Extract pattern-status and freshness SSC fields from all queue entries and
     * return a map of signal_id → fields to merge back into signals.json SSC.
     *
     * @param array $queueResult Finalized handoff queue result (signal_id → record)
     * @return array signal_id → array of SSC fields
     */
    private function buildHandoffSscPatchMap(array $queueResult): array
    {
        static $sscPatternFields = [
            'dbl_pattern_status', 'dbl_pattern_status_reason', 'dbl_pattern_confirmation_path',
            'dbl_pattern_invalid_reason', 'dbl_pattern_pending_reason', 'dbl_pattern_confirmation_source',
            'dbl_pattern_confirmed_at', 'dbl_pattern_invalidated_at',
            'neckline_closes_above_count', 'reclaim_hold_bars', 'reclaim_hold_minutes',
            'reclaim_retest_held', 'higher_low_after_point3', 'fresh_lower_low_after_point3',
            'point3_broken', 'reclaim_level_lost',
            'point_1_missing', 'point_3_missing', 'neckline_present', 'reclaim_present',
            'dbl_trace_complete', 'dbl_trace_source',
            'reclaim_went_above', 'reclaim_lost_after_confirm', 'reclaim_recovered_after_loss',
            'current_price_above_reclaim', 'latest_price', 'latest_price_distance_from_reclaim_pct',
            'reclaim_loss_duration_minutes', 'reclaim_loss_final_state',
            'dbl_pattern_diag_recomputed_at', 'dbl_pattern_diag_source', 'stale_pattern_diag_cleared',
            'effective_fresh_at', 'detected_age_minutes', 'effective_fresh_age_minutes',
            'current_run_freshness_source', 'current_run_freshness_passed', 'current_run_freshness_block_reason',
            // Confirmed-pattern validity diagnostics
            'confirmed_pattern_age_minutes', 'confirmed_pattern_ttl_minutes',
            'confirmed_pattern_valid_for_handoff', 'confirmed_pattern_invalid_reason',
            'confirmed_pattern_price_still_valid', 'confirmed_pattern_price_check_source',
        ];

        $map = [];
        foreach ($queueResult as $id => $qEntry) {
            $sigId = (string)($qEntry['signal_id'] ?? $id);
            if ($sigId === '') {
                continue;
            }
            $qSsc  = is_array($qEntry['strategy_signal_context'] ?? null) ? $qEntry['strategy_signal_context'] : [];
            $patch = [];
            foreach ($sscPatternFields as $field) {
                if (array_key_exists($field, $qSsc)) {
                    $patch[$field] = $qSsc[$field];
                } elseif (array_key_exists($field, $qEntry)) {
                    $patch[$field] = $qEntry[$field];
                }
            }
            if (!empty($patch)) {
                $map[$sigId] = $patch;
            }
        }
        return $map;
    }

    /**
     * Build a stable bot handoff contract record from a winner signal and the
     * module config.
     *
     * This is the strategy-owned signal contract.
     * It does NOT contain exchange-order fields (those belong to the future Bot module).
     */
    private function buildBotHandoffRecord(array $signal, array $config): array
    {
        $ttlSec     = $this->signalTtlSec($config);
        $detectedAt = (string)($signal['detected_at'] ?? date('c'));
        $detectedTs = strtotime($detectedAt);
        $expiresAt  = $detectedTs !== false
            ? date('c', $detectedTs + $ttlSec)
            : null;

        return [
            // Strategy ownership
            'owner_strategy'  => 'double_bottom_long',
            'strategy_id'     => 'double_bottom_long',

            // Signal identity
            'signal_id'       => (string)($signal['signal_id'] ?? ''),
            'symbol'          => (string)($signal['symbol']    ?? ''),
            'side'            => 'long',
            'timeframe'       => (string)($config['timeframe'] ?? 'H4'),

            // Lifecycle (handoff_status is overwritten by the caller)
            'detected_at'    => $detectedAt,
            'expires_at'     => $expiresAt,
            'handoff_status' => 'active',
            'handoff_ready'  => true,
            'executable'     => true,
            'stale'          => false,
            'stale_reason'   => null,
            'effective_fresh_at' => $signal['effective_fresh_at'] ?? null,
            'detected_age_minutes' => $signal['detected_age_minutes'] ?? null,
            'effective_fresh_age_minutes' => $signal['effective_fresh_age_minutes'] ?? null,
            'current_run_freshness_source' => $signal['current_run_freshness_source'] ?? null,
            'current_run_freshness_passed' => $signal['current_run_freshness_passed'] ?? null,
            'current_run_freshness_block_reason' => $signal['current_run_freshness_block_reason'] ?? null,

            // Entry geometry
            'entry_mode'      => (string)($config['entry_mode']          ?? 'limit'),
            'entry_type'      => (string)($signal['entry_type']      ?? 'breakout'),
            'entry_price'     => (float)($signal['entry_price']      ?? 0.0),
            'primary_pattern' => (string)($signal['primary_pattern'] ?? 'double_bottom'),

            // Pipeline context
            'trend_direction' => (string)($signal['trend_direction'] ?? 'unknown'),
            'corridor_bucket' => (int)($signal['corridor_bucket']    ?? 0),
            'wave_state'      => (string)($signal['wave_state']      ?? 'unknown'),
            'confirm_status'  => (string)($signal['confirm_status']  ?? 'confirm_pass'),

            // Quality scores
            'pattern_score'           => (float)($signal['pattern_score']           ?? 0.0),
            'structure_score'         => (float)($signal['structure_score']         ?? 0.0),
            'neckline_score'          => (float)($signal['neckline_score']          ?? 0.0),
            'confirmation_score'      => (float)($signal['confirmation_score']      ?? 0.0),
            'context_score'           => (float)($signal['context_score']           ?? 0.0),
            'candidate_quality_score' => (float)($signal['candidate_quality_score'] ?? 0.0),
            'quality_pass'            => (bool)($signal['quality_pass']             ?? true),

            // Coin trend context handoff guard
            'active_falling_knife_detected'  => (bool)($signal['active_falling_knife_detected']  ?? false),
            'reclaim_after_flat_detected'     => (bool)($signal['reclaim_after_flat_detected']     ?? false),
            'support_broken'                  => (bool)($signal['support_broken']                  ?? false),
            'entry_context_score'             => (float)($signal['entry_context_score']             ?? 0.0),
            'entry_distance_from_reclaim_pct' => (float)($signal['entry_distance_from_reclaim_pct'] ?? 0.0),
            'bearish_reversal_exception_used' => (bool)($signal['bearish_reversal_exception_used'] ?? false),
            'setup_context_type'              => (string)($signal['setup_context_type']             ?? 'standard'),
            'signal_quality_class'            => (string)($signal['signal_quality_class']           ?? 'clean_signal'),

            // ── Signal diagnostic context for calibration traceability (Task 2) ───────
            'strategy_signal_context' => [
                'setup_class'                     => $signal['setup_class']                     ?? null,
                '_setup_signal_allowed'           => $signal['_setup_signal_allowed']           ?? null,
                'synthetic_quality_score'         => $signal['synthetic_quality_score']         ?? null,
                'setup_class_score'               => $signal['setup_class_score']               ?? null,
                'intraday_double_bottom_score'    => $signal['intraday_double_bottom_score']    ?? null,
                'candidate_quality_score'         => $signal['candidate_quality_score']         ?? null,
                'entry_distance_from_neckline_pct' => $signal['entry_distance_from_neckline_pct'] ?? null,
                'entry_distance_from_reclaim_pct'  => $signal['entry_distance_from_reclaim_pct']  ?? null,
                'late_good_setup'                  => $signal['late_good_setup']                 ?? false,
                'missed_ideal_entry'               => $signal['missed_ideal_entry']              ?? false,
                'waiting_for_better_entry_distance' => $signal['waiting_for_better_entry_distance'] ?? false,
                'quality_source'                   => $signal['quality_source']                  ?? null,
                'warnings'                         => $signal['warnings']                        ?? null,
                'reason_codes'                     => $signal['reason_codes']                    ?? null,
                'pending_confirmation_status'      => $signal['pending_confirmation_status']     ?? null,
                'pending_confirmation_reason'      => $signal['pending_confirmation_reason']     ?? null,
                // OBC wall context
                'ob_wall_checked'                  => $signal['ob_wall_checked']                 ?? false,
                'ob_ask_wall_risk'                 => $signal['ob_ask_wall_risk']                ?? false,
                'ob_bid_wall_support'              => $signal['ob_bid_wall_support']             ?? false,
                'ob_ask_wall_eaten'                => $signal['ob_ask_wall_eaten']               ?? false,
                'ob_soft_demoted'                  => $signal['ob_soft_demoted']                 ?? false,
                'ob_wall_context'                  => $signal['ob_wall_context']                 ?? null,
                'ob_gate_mode'                     => $signal['ob_gate_mode']                    ?? null,
                'ob_fetch_ok'                      => $signal['ob_fetch_ok']                     ?? null,
                'ob_ask_wall_distance_pct'         => $signal['ob_ask_wall_distance_pct']        ?? null,
                'ob_bid_wall_distance_pct'         => $signal['ob_bid_wall_distance_pct']        ?? null,
                'ob_ask_wall_status'               => $signal['ob_ask_wall_status']              ?? null,
                'ob_bid_wall_status'               => $signal['ob_bid_wall_status']              ?? null,
                'ob_skip_reason'                   => $signal['ob_skip_reason']                  ?? null,
                'ob_skip_quality_score'            => $signal['ob_skip_quality_score']           ?? null,
                'ob_skip_required_quality_score'   => $signal['ob_skip_required_quality_score']  ?? null,
                // Garbage veto context
                'daily_change_pct'           => $signal['daily_change_pct']           ?? null,
                'position_in_24h_range_pct'  => $signal['position_in_24h_range_pct']  ?? null,
                'room_to_24h_high_roi'       => $signal['room_to_24h_high_roi']        ?? null,
                'recent_10m_range_roi'       => $signal['recent_10m_range_roi']        ?? null,
                'recent_60m_range_roi'       => $signal['recent_60m_range_roi']        ?? null,
                'recent_60m_direction_flips' => $signal['recent_60m_direction_flips']  ?? null,
                'whipsaw_score'              => $signal['whipsaw_score']               ?? null,
                'entry_hour_utc'             => $signal['entry_hour_utc']              ?? null,
                'session_bucket'             => $signal['session_bucket']              ?? null,
                'post_point3_bounce_roi'     => $signal['post_point3_bounce_roi']      ?? null,
                'position_in_local_recovery_leg_pct' => $signal['position_in_local_recovery_leg_pct'] ?? null,
                'room_to_recent_swing_high_roi' => $signal['room_to_recent_swing_high_roi'] ?? null,
                'near_recent_swing_high'     => $signal['near_recent_swing_high']      ?? null,
                'post_point3_impulse_spent_pct' => $signal['post_point3_impulse_spent_pct'] ?? null,
                'local_recovery_leg_low_price' => $signal['local_recovery_leg_low_price'] ?? null,
                'local_recovery_leg_high_price' => $signal['local_recovery_leg_high_price'] ?? null,
                'recent_swing_high_price'    => $signal['recent_swing_high_price']     ?? null,
                'recent_swing_high_time'     => $signal['recent_swing_high_time']      ?? null,
                // DBL point trace for Stop Manager — carry from signal's strategy_signal_context if present
                'point_1_low_price'          => $signal['strategy_signal_context']['point_1_low_price']      ?? null,
                'point_2_neckline_price'     => $signal['strategy_signal_context']['point_2_neckline_price'] ?? null,
                'point_3_second_low_price'   => $signal['strategy_signal_context']['point_3_second_low_price'] ?? null,
                'neckline_level'             => $signal['strategy_signal_context']['neckline_level']         ?? $signal['neckline_level'] ?? null,
                'reclaim_level'              => $signal['strategy_signal_context']['reclaim_level']          ?? $signal['reclaim_level'] ?? null,
                'second_bottom_level'        => $signal['strategy_signal_context']['second_bottom_level']    ?? null,
                'entry_distance_from_point3_pct' => $signal['strategy_signal_context']['entry_distance_from_point3_pct'] ?? null,
                'fresh_lower_low_after_point3' => $signal['strategy_signal_context']['fresh_lower_low_after_point3'] ?? false,
                'point3_confirmed'           => $signal['strategy_signal_context']['point3_confirmed']       ?? true,
                'reclaim_confirmed'          => $signal['strategy_signal_context']['reclaim_confirmed']      ?? false,
                'neckline_reclaim_confirmed' => $signal['neckline_reclaim_confirmed']                        ?? $signal['strategy_signal_context']['neckline_reclaim_confirmed'] ?? false,
                // Garbage veto result (populated in updateBotHandoff normalization pass)
                'garbage_veto_checked'       => $signal['strategy_signal_context']['garbage_veto_checked']   ?? false,
                'garbage_veto_triggered'     => $signal['strategy_signal_context']['garbage_veto_triggered'] ?? false,
                'garbage_veto_reason'        => $signal['strategy_signal_context']['garbage_veto_reason']    ?? null,
                'garbage_veto_secondary_reasons' => $signal['strategy_signal_context']['garbage_veto_secondary_reasons'] ?? [],
                // Trace completeness diagnostics (populated in updateBotHandoff normalization pass)
                'dbl_trace_complete'                    => $signal['strategy_signal_context']['dbl_trace_complete']                    ?? null,
                'dbl_trace_source'                      => $signal['strategy_signal_context']['dbl_trace_source']                      ?? null,
                'dbl_trace_reconstructed_from_parser2'  => $signal['strategy_signal_context']['dbl_trace_reconstructed_from_parser2']  ?? false,
                'dbl_trace_missing_high_quality_bypass' => $signal['strategy_signal_context']['dbl_trace_missing_high_quality_bypass'] ?? false,
                // DBL pattern-status state machine diagnostics
                'dbl_pattern_status_enabled'        => $signal['strategy_signal_context']['dbl_pattern_status_enabled'] ?? (bool)($config['dbl_pattern_status_enabled'] ?? true),
                'dbl_pattern_status'                => $signal['strategy_signal_context']['dbl_pattern_status'] ?? null,
                'dbl_pattern_status_reason'         => $signal['strategy_signal_context']['dbl_pattern_status_reason'] ?? null,
                'dbl_pattern_confirmation_path'     => $signal['strategy_signal_context']['dbl_pattern_confirmation_path'] ?? 'none',
                'dbl_pattern_invalid_reason'        => $signal['strategy_signal_context']['dbl_pattern_invalid_reason'] ?? null,
                'dbl_pattern_pending_reason'        => $signal['strategy_signal_context']['dbl_pattern_pending_reason'] ?? null,
                'dbl_pattern_detected_at'           => $signal['strategy_signal_context']['dbl_pattern_detected_at'] ?? ($signal['detected_at'] ?? null),
                'dbl_pattern_confirmed_at'          => $signal['strategy_signal_context']['dbl_pattern_confirmed_at'] ?? null,
                'dbl_pattern_invalidated_at'        => $signal['strategy_signal_context']['dbl_pattern_invalidated_at'] ?? null,
                'point_1_low_time'                 => $signal['strategy_signal_context']['point_1_low_time'] ?? null,
                'point_2_neckline_time'            => $signal['strategy_signal_context']['point_2_neckline_time'] ?? null,
                'point_3_second_low_time'          => $signal['strategy_signal_context']['point_3_second_low_time'] ?? null,
                'neckline_break_confirmed'         => $signal['strategy_signal_context']['neckline_break_confirmed'] ?? false,
                'neckline_closes_above_count'      => $signal['strategy_signal_context']['neckline_closes_above_count'] ?? null,
                'reclaim_hold_bars'                => $signal['strategy_signal_context']['reclaim_hold_bars'] ?? null,
                'reclaim_hold_minutes'             => $signal['strategy_signal_context']['reclaim_hold_minutes'] ?? null,
                'reclaim_retest_held'              => $signal['strategy_signal_context']['reclaim_retest_held'] ?? false,
                'higher_low_after_point3'          => $signal['strategy_signal_context']['higher_low_after_point3'] ?? false,
                'higher_low_after_point3_price'    => $signal['strategy_signal_context']['higher_low_after_point3_price'] ?? null,
                'point3_broken'                    => $signal['strategy_signal_context']['point3_broken'] ?? false,
                'reclaim_level_lost'               => $signal['strategy_signal_context']['reclaim_level_lost'] ?? false,
                'confirmation_ttl_expired'         => $signal['strategy_signal_context']['confirmation_ttl_expired'] ?? false,
                // Current-run freshness diagnostics
                'detected_at'                      => $signal['strategy_signal_context']['detected_at'] ?? ($signal['detected_at'] ?? null),
                'effective_fresh_at'               => $signal['strategy_signal_context']['effective_fresh_at'] ?? ($signal['effective_fresh_at'] ?? null),
                'detected_age_minutes'             => $signal['strategy_signal_context']['detected_age_minutes'] ?? ($signal['detected_age_minutes'] ?? null),
                'effective_fresh_age_minutes'      => $signal['strategy_signal_context']['effective_fresh_age_minutes'] ?? ($signal['effective_fresh_age_minutes'] ?? null),
                'current_run_freshness_source'     => $signal['strategy_signal_context']['current_run_freshness_source'] ?? ($signal['current_run_freshness_source'] ?? null),
                'current_run_freshness_passed'     => $signal['strategy_signal_context']['current_run_freshness_passed'] ?? ($signal['current_run_freshness_passed'] ?? null),
                'current_run_freshness_block_reason' => $signal['strategy_signal_context']['current_run_freshness_block_reason'] ?? ($signal['current_run_freshness_block_reason'] ?? null),
                // Confirmed-pattern validity diagnostics (populated in updateBotHandoff freshness bypass)
                'confirmed_pattern_age_minutes'       => $signal['strategy_signal_context']['confirmed_pattern_age_minutes']       ?? null,
                'confirmed_pattern_ttl_minutes'       => $signal['strategy_signal_context']['confirmed_pattern_ttl_minutes']       ?? null,
                'confirmed_pattern_valid_for_handoff' => $signal['strategy_signal_context']['confirmed_pattern_valid_for_handoff'] ?? null,
                'confirmed_pattern_invalid_reason'    => $signal['strategy_signal_context']['confirmed_pattern_invalid_reason']    ?? null,
                'confirmed_pattern_price_still_valid' => $signal['strategy_signal_context']['confirmed_pattern_price_still_valid'] ?? null,
                'confirmed_pattern_price_check_source'=> $signal['strategy_signal_context']['confirmed_pattern_price_check_source']?? null,
            ],

            // Execution parameters (strategy-owned; no exchange-order fields yet)
            'stop_mode'                     => (string)($config['stop_mode']                     ?? 'fixed_from_liq_zone'),
            'stop_from_liq_buffer_value'    => (float)($config['stop_from_liq_buffer_value']    ?? 0.002),
            'stop_from_liq_buffer_type'     => (string)($config['stop_from_liq_buffer_type']    ?? 'percent'),
            'stop_buffer_pct_below_lows'    => (float)($config['stop_buffer_pct_below_lows']    ?? 0.005),
            'max_stop_loss_pct'             => (float)($config['max_stop_loss_pct']             ?? 0.05),
            // Pattern-derived stop and take-profit (computed by PatternSignal::build)
            'stop_loss_price'               => $signal['stop_loss_price']   ?? null,
            'stop_loss_pct'                 => $signal['stop_loss_pct']     ?? null,
            'stop_basis'                    => $signal['stop_basis']        ?? 'pattern_lows',
            'bot_budget'                    => (float)($config['bot_budget']                    ?? 0.0),
            'bot_leverage'                  => (int)($config['bot_leverage']                    ?? 1),
            'tp_enabled'                    => (bool)($config['tp_enabled']                     ?? true),
            'tp_mode'                       => (string)($config['tp_mode']                      ?? 'fixed_r'),
            'tp_value'                      => (float)($config['tp_value']                      ?? 2.5),
            'tp_price'                      => $signal['tp_price']          ?? null,
            'reverse_pattern_close_enabled' => (bool)($config['reverse_pattern_close_enabled']  ?? false),
        ];
    }

    // =========================================================================
    // DBL Garbage Veto
    // =========================================================================

    /**
     * Conservative pre-handoff trash filter.
     *
     * Checks a signal/queue record against hard-veto rules:
     *  1. Low quality + generic warning + OBC not confirmed
     *  2. OBC skipped due to quality_below_threshold
     *  3. Late daily extension long (already strongly extended on 24h)
     *  4. Whipsaw + weak quality
     *  5. Local late entry after recovery leg already spent
     *  6. Missing critical DBL trace (point3/neckline/entry-distance null)
     *  7. Reclaim not confirmed for medium-quality signals
     *
     * Before vetoes run, attempts to reconstruct missing point3/reclaim metrics
     * from parser2 ticker history (lightweight; fails gracefully).
     *
     * Returns an array with:
     *  - veto_triggered: bool
     *  - reason:         string|null  (primary block reason)
     *  - secondary_reasons: string[]
     *  - diag:           array        (all computed values for diagnostics)
     */
    private function applyDblGarbageVeto(array $record, array $context, array $config): array
    {
        $ssc = is_array($context) ? $context : [];

        // Extract quality and warning context
        $qualityScore = (float)($record['candidate_quality_score'] ?? $ssc['candidate_quality_score'] ?? 0.0);
        $warnings     = (array)($record['warnings'] ?? $ssc['warnings'] ?? []);
        $hasGenericWarning = in_array('generic_entry_context_score_low', $warnings, true);

        // OBC context
        $obWallChecked = (bool)($record['ob_wall_checked'] ?? $ssc['ob_wall_checked'] ?? false);
        $obSkipReason  = (string)($record['ob_skip_reason'] ?? $ssc['ob_skip_reason'] ?? '');
        $obSkipScore   = isset($record['ob_skip_quality_score'])
            ? (float)$record['ob_skip_quality_score']
            : (float)($ssc['ob_skip_quality_score'] ?? 0.0);
        $obSkipRequired = isset($record['ob_skip_required_quality_score'])
            ? (float)$record['ob_skip_required_quality_score']
            : (float)($ssc['ob_skip_required_quality_score'] ?? 0.0);
        $obcMissingOrSkipped = !$obWallChecked || $obSkipReason !== '';

        // Daily extension context
        $dayChangePct      = isset($ssc['daily_change_pct'])
            ? ($ssc['daily_change_pct'] !== null ? (float)$ssc['daily_change_pct'] : null)
            : null;
        $positionIn24h     = isset($ssc['position_in_24h_range_pct'])
            ? ($ssc['position_in_24h_range_pct'] !== null ? (float)$ssc['position_in_24h_range_pct'] : null)
            : null;
        $roomTo24hHigh     = isset($ssc['room_to_24h_high_roi'])
            ? ($ssc['room_to_24h_high_roi'] !== null ? (float)$ssc['room_to_24h_high_roi'] : null)
            : null;

        // Whipsaw context
        $recent10mRange    = isset($ssc['recent_10m_range_roi'])
            ? ($ssc['recent_10m_range_roi'] !== null ? (float)$ssc['recent_10m_range_roi'] : null)
            : null;
        $recent60mFlips    = isset($ssc['recent_60m_direction_flips'])
            ? ($ssc['recent_60m_direction_flips'] !== null ? (int)$ssc['recent_60m_direction_flips'] : null)
            : null;
        $whipsawScore      = isset($ssc['whipsaw_score'])
            ? ($ssc['whipsaw_score'] !== null ? (float)$ssc['whipsaw_score'] : null)
            : null;
        // Local late-entry context
        $entryPrice = (float)($record['entry_price'] ?? 0.0);
        $entryDistPoint3 = isset($ssc['entry_distance_from_point3_pct'])
            ? ($ssc['entry_distance_from_point3_pct'] !== null ? (float)$ssc['entry_distance_from_point3_pct'] : null)
            : null;
        $entryDistReclaim = isset($ssc['entry_distance_from_reclaim_pct'])
            ? ($ssc['entry_distance_from_reclaim_pct'] !== null ? (float)$ssc['entry_distance_from_reclaim_pct'] : null)
            : null;
        $postPoint3BounceRoi = isset($ssc['post_point3_bounce_roi'])
            ? ($ssc['post_point3_bounce_roi'] !== null ? (float)$ssc['post_point3_bounce_roi'] : null)
            : null;
        $postPoint3ImpulseSpentPct = isset($ssc['post_point3_impulse_spent_pct'])
            ? ($ssc['post_point3_impulse_spent_pct'] !== null ? (float)$ssc['post_point3_impulse_spent_pct'] : null)
            : null;
        $roomToRecentSwingHighRoi = isset($ssc['room_to_recent_swing_high_roi'])
            ? ($ssc['room_to_recent_swing_high_roi'] !== null ? (float)$ssc['room_to_recent_swing_high_roi'] : null)
            : null;
        $nearRecentSwingHigh = isset($ssc['near_recent_swing_high'])
            ? (bool)$ssc['near_recent_swing_high']
            : false;
        $point3SecondLowPrice = isset($ssc['point_3_second_low_price'])
            ? ($ssc['point_3_second_low_price'] !== null ? (float)$ssc['point_3_second_low_price'] : null)
            : null;
        $reclaimLevel = isset($ssc['reclaim_level'])
            ? ($ssc['reclaim_level'] !== null ? (float)$ssc['reclaim_level'] : null)
            : null;
        $recentSwingHighPrice = isset($ssc['recent_swing_high_price'])
            ? ($ssc['recent_swing_high_price'] !== null ? (float)$ssc['recent_swing_high_price'] : null)
            : null;
        $recentSwingHighTime = $ssc['recent_swing_high_time'] ?? null;
        $localRecoveryLegLowPrice = isset($ssc['local_recovery_leg_low_price'])
            ? ($ssc['local_recovery_leg_low_price'] !== null ? (float)$ssc['local_recovery_leg_low_price'] : null)
            : null;
        $localRecoveryLegHighPrice = isset($ssc['local_recovery_leg_high_price'])
            ? ($ssc['local_recovery_leg_high_price'] !== null ? (float)$ssc['local_recovery_leg_high_price'] : null)
            : null;

        // ── Trace completeness assessment + parser2 reconstruction ────────────
        // Assess whether critical point-1-2-3 / neckline / reclaim fields are
        // present.  If incomplete, attempt a lightweight reconstruction from
        // parser2 ticker history so that Veto 5 (local late-entry) can evaluate,
        // and Veto 6 (missing-trace) has accurate bypass conditions.
        $necklineLevelTrace = isset($ssc['neckline_level'])
            ? ($ssc['neckline_level'] !== null ? (float)$ssc['neckline_level'] : null)
            : null;
        $reclaimConfirmed         = (bool)($ssc['reclaim_confirmed']            ?? false);
        $necklineReclaimConfirmed = (bool)($ssc['neckline_reclaim_confirmed']   ?? false);
        $reclaimAfterFlat         = (bool)($record['reclaim_after_flat_detected'] ?? false);
        $hasAnyReclaimConfirmation = $reclaimConfirmed || $reclaimAfterFlat || $necklineReclaimConfirmed;
        $pendingConfirmStatus      = (string)($ssc['pending_confirmation_status'] ?? '');
        $setupClass                = (string)($ssc['setup_class']                ?? '');
        $obAskWallRisk             = (bool)($ssc['ob_ask_wall_risk']             ?? false);

        $traceHasPoint3            = $point3SecondLowPrice !== null && $point3SecondLowPrice > 0.0;
        $traceHasNeckline          = $necklineLevelTrace !== null && $necklineLevelTrace > 0.0;
        $traceHasReclaim           = $reclaimLevel !== null && $reclaimLevel > 0.0;
        $traceHasEntryDistFromPoint3 = $entryDistPoint3 !== null;
        $traceHasReclaimConfirmation = $hasAnyReclaimConfirmation;
        $traceComplete             = $traceHasPoint3 && $traceHasNeckline
            && $traceHasReclaim && $traceHasEntryDistFromPoint3;

        $traceChecked                  = true;
        $traceSource                   = $traceComplete ? 'native' : 'missing';
        $traceReconstructed            = false;
        $traceReconstructionFailed     = false;
        $traceReconstructionFailedReason = null;
        $missingTraceHighQualityBypass = false;

        // If trace is incomplete, attempt lightweight reconstruction from parser2.
        if (!$traceComplete) {
            $sym = (string)($record['symbol'] ?? '');
            if ($sym !== '') {
                $recon = $this->tryReconstructLocalTraceFromParser2($sym, $entryPrice, $config);
                if ($recon['ok']) {
                    $traceReconstructed = true;
                    $traceSource        = 'parser2_reconstructed';
                    if (!$traceHasPoint3 && $recon['point3_price'] !== null) {
                        $point3SecondLowPrice = (float)$recon['point3_price'];
                        $traceHasPoint3 = true;
                    }
                    if (!$traceHasEntryDistFromPoint3 && $recon['entry_distance_from_point3_pct'] !== null) {
                        $entryDistPoint3 = (float)$recon['entry_distance_from_point3_pct'];
                        $traceHasEntryDistFromPoint3 = true;
                    }
                    if ($localRecoveryLegLowPrice === null && $recon['local_low'] !== null) {
                        $localRecoveryLegLowPrice = round((float)$recon['local_low'], 8);
                    }
                    if ($localRecoveryLegHighPrice === null && $recon['local_high'] !== null) {
                        $localRecoveryLegHighPrice = round((float)$recon['local_high'], 8);
                    }
                    if ($postPoint3BounceRoi === null) {
                        $postPoint3BounceRoi = $recon['post_point3_bounce_roi'];
                    }
                    if ($postPoint3ImpulseSpentPct === null) {
                        $postPoint3ImpulseSpentPct = $recon['post_point3_impulse_spent_pct'];
                    }
                    if ($roomToRecentSwingHighRoi === null) {
                        $roomToRecentSwingHighRoi = $recon['room_to_recent_swing_high_roi'];
                    }
                    if ($recentSwingHighPrice === null && $recon['recent_swing_high_price'] !== null) {
                        $recentSwingHighPrice = (float)$recon['recent_swing_high_price'];
                    }
                    if (!$nearRecentSwingHigh && $recon['near_recent_swing_high'] !== null) {
                        $nearRecentSwingHigh = (bool)$recon['near_recent_swing_high'];
                    }
                    // Re-evaluate completeness with reconstructed values.
                    $traceComplete = $traceHasPoint3 && $traceHasNeckline
                        && $traceHasReclaim && $traceHasEntryDistFromPoint3;
                } else {
                    $traceReconstructionFailed       = true;
                    $traceReconstructionFailedReason = $recon['failed_reason'] ?? 'reconstruction_unavailable';
                    $traceSource = 'missing';
                }
            }
        }

        $secondaryReasons = [];
        $primaryReason    = null;

        // ── Veto 1: low quality + generic warning + OBC missing/skipped ────────
        $v1Enabled = (bool)($config['dbl_garbage_veto_enabled'] ?? true);
        if ($v1Enabled) {
            $lowQualMax       = (float)($config['dbl_garbage_low_quality_max_score']                        ?? 0.70);
            $reqGenericWarn   = (bool) ($config['dbl_garbage_low_quality_requires_generic_warning']         ?? true);
            $reqObcMissing    = (bool) ($config['dbl_garbage_low_quality_requires_obc_missing_or_skipped']  ?? true);
            $isLowQuality     = $qualityScore <= $lowQualMax && $qualityScore > 0.0;
            $passGenericWarn  = !$reqGenericWarn || $hasGenericWarning;
            $passObcMissing   = !$reqObcMissing  || $obcMissingOrSkipped;
            if ($isLowQuality && $passGenericWarn && $passObcMissing) {
                $primaryReason = 'garbage_low_quality_without_obc_confirmation';
            }
        }

        // ── Veto 2: OBC skipped due to quality_below_threshold ─────────────────
        if ($primaryReason === null && (bool)($config['dbl_garbage_block_obc_quality_skip'] ?? true)) {
            $obcSkipMaxScore = (float)($config['dbl_garbage_obc_quality_skip_max_score'] ?? 0.72);
            $obSkipBelowRequired = $obSkipScore > 0.0
                && $obSkipRequired > 0.0
                && $obSkipScore < $obSkipRequired;
            if ($obSkipReason === 'quality_below_threshold'
                && $obSkipBelowRequired
                && ($qualityScore <= $obcSkipMaxScore || $hasGenericWarning)
            ) {
                $primaryReason = 'garbage_obc_quality_skip';
                if ($qualityScore > $obcSkipMaxScore) {
                    $secondaryReasons[] = 'has_generic_warning_despite_score';
                }
            }
        }

        // ── Veto 3: late daily extension long ───────────────────────────────────
        if ($primaryReason === null && (bool)($config['dbl_garbage_daily_extension_enabled'] ?? true)) {
            $hotPct       = (float)($config['dbl_garbage_day_change_hot_pct']           ?? 35.0);
            $maxPosPct    = (float)($config['dbl_garbage_position_in_24h_range_max_pct'] ?? 80.0);
            $minRoomRoi   = (float)($config['dbl_garbage_min_room_to_24h_high_roi']      ?? 10.0);

            if ($dayChangePct !== null && $positionIn24h !== null) {
                if ($dayChangePct >= $hotPct && $positionIn24h >= $maxPosPct) {
                    $primaryReason = 'garbage_late_daily_extension_long';
                    $secondaryReasons[] = sprintf(
                        'day_change=%.1f%%_position=%.1f%%',
                        $dayChangePct,
                        $positionIn24h
                    );
                }
            }
            if ($primaryReason === null && $dayChangePct !== null && $roomTo24hHigh !== null) {
                $veryHotPct = $hotPct + 10.0; // 45% default
                if ($dayChangePct >= $veryHotPct && $roomTo24hHigh < $minRoomRoi) {
                    $primaryReason = 'garbage_late_daily_extension_long';
                    $secondaryReasons[] = sprintf(
                        'day_change=%.1f%%_room_to_24h_high=%.1f%%',
                        $dayChangePct,
                        $roomTo24hHigh
                    );
                }
            }
        }

        // ── Veto 4: whipsaw + weak quality ─────────────────────────────────────
        if ($primaryReason === null && (bool)($config['dbl_garbage_whipsaw_enabled'] ?? true)) {
            $maxRange10m    = (float)($config['dbl_garbage_whipsaw_max_10m_range_roi']          ?? 15.0);
            $maxFlips60m    = (int)  ($config['dbl_garbage_whipsaw_max_60m_direction_flips']    ?? 10);
            $reqWeakQual    = (bool) ($config['dbl_garbage_whipsaw_requires_weak_quality']       ?? true);
            $weakQualMax    = (float)($config['dbl_garbage_whipsaw_weak_quality_max_score']      ?? 0.72);
            $isWeakQuality  = $qualityScore <= $weakQualMax && $qualityScore > 0.0;
            $isWhipsaw      = false;
            if ($recent10mRange !== null && $recent10mRange > $maxRange10m) {
                $isWhipsaw = true;
                $secondaryReasons[] = sprintf('10m_range_roi=%.1f%%', $recent10mRange);
            }
            if ($recent60mFlips !== null && $recent60mFlips > $maxFlips60m) {
                $isWhipsaw = true;
                $secondaryReasons[] = sprintf('60m_direction_flips=%d', $recent60mFlips);
            }
            if ($isWhipsaw && (!$reqWeakQual || $isWeakQuality)) {
                $primaryReason = 'garbage_whipsaw_weak_quality';
            } else {
                // Not blocking — clear secondary reasons added for whipsaw only
                $secondaryReasons = array_filter($secondaryReasons, static function (string $r): bool {
                    return !str_starts_with($r, '10m_range_roi=') && !str_starts_with($r, '60m_direction_flips=');
                });
                $secondaryReasons = array_values($secondaryReasons);
            }
        }

        // ── Veto 5: local late entry after recovery leg ────────────────────────
        $lateLocalEnabled = (bool)($config['dbl_garbage_late_local_entry_enabled'] ?? true);
        $lateLocalChecked = false;
        $localLateFlagsTotal = 0;
        $localLateFlagsRequired = 2;
        $localLateQualityTier = 'low';
        $localLateTinyRoom = false;
        $localLateNearHighRequiredForHighQuality = false;
        $localLateBlockedByQualityRule = false;
        $localLatePassedDueQuality = false;
        $localLateDecision = 'not_evaluated';
        $lateEntryFlags = [
            'garbage_entry_far_from_point3' => false,
            'garbage_post_point3_impulse_already_spent' => false,
            'garbage_insufficient_room_to_recent_swing_high' => false,
            'garbage_near_recent_swing_high' => false,
        ];
        if ($lateLocalEnabled) {
            $lateLocalChecked = true;
            $maxEntryDistPoint3 = (float)($config['dbl_garbage_max_entry_distance_from_point3_pct'] ?? 1.2);
            $maxImpulseSpentPct = (float)($config['dbl_garbage_max_post_point3_impulse_spent_pct']  ?? 70.0);
            $minRoomSwingHigh   = (float)($config['dbl_garbage_min_room_to_recent_swing_high_roi']  ?? 5.0);
            $tinyRoomSwingHigh  = (float)($config['dbl_garbage_tiny_room_to_recent_swing_high_roi'] ?? 2.0);
            $qualityAwareEnabled = (bool)($config['dbl_garbage_late_local_quality_aware_enabled'] ?? true);

            if ($entryDistPoint3 !== null && $entryDistPoint3 > $maxEntryDistPoint3) {
                $lateEntryFlags['garbage_entry_far_from_point3'] = true;
            }
            if ($postPoint3ImpulseSpentPct !== null && $postPoint3ImpulseSpentPct > $maxImpulseSpentPct) {
                $lateEntryFlags['garbage_post_point3_impulse_already_spent'] = true;
            }
            if ($roomToRecentSwingHighRoi !== null && $roomToRecentSwingHighRoi < $minRoomSwingHigh) {
                $lateEntryFlags['garbage_insufficient_room_to_recent_swing_high'] = true;
            }
            if ($roomToRecentSwingHighRoi !== null && $roomToRecentSwingHighRoi < $tinyRoomSwingHigh) {
                $localLateTinyRoom = true;
            }
            if ($nearRecentSwingHigh) {
                $lateEntryFlags['garbage_near_recent_swing_high'] = true;
            }

            $localLateFlagsTotal = count(array_filter($lateEntryFlags));
            $lateConditionsMet = false;
            if ($qualityAwareEnabled) {
                // Quality tiers: low (q < 0.78), mid (0.78 <= q < 0.82), high (q >= 0.82).
                $lowQualMax = (float)($config['dbl_garbage_late_local_low_quality_max'] ?? 0.78);
                $highQualMin = (float)($config['dbl_garbage_late_local_high_quality_min'] ?? 0.82);
                $flagsReqLow = max(1, (int)($config['dbl_garbage_late_local_flags_required_low_quality'] ?? 2));
                $flagsReqMid = max(1, (int)($config['dbl_garbage_late_local_flags_required_mid_quality'] ?? 3));
                $flagsReqHigh = max(1, (int)($config['dbl_garbage_late_local_flags_required_high_quality'] ?? 3));
                $requireNearHighOrTinyRoomForHigh = (bool)($config['dbl_garbage_late_local_high_quality_requires_near_high_or_tiny_room'] ?? true);

                if ($qualityScore >= $highQualMin) {
                    $localLateQualityTier = 'high';
                    $localLateFlagsRequired = $flagsReqHigh;
                } elseif ($qualityScore >= $lowQualMax) {
                    $localLateQualityTier = 'mid';
                    $localLateFlagsRequired = $flagsReqMid;
                } else {
                    $localLateQualityTier = 'low';
                    $localLateFlagsRequired = $flagsReqLow;
                }

                $localLateNearHighRequiredForHighQuality = $localLateQualityTier === 'high'
                    && $requireNearHighOrTinyRoomForHigh;
                $highQualityNearHighGateOk = true;
                if ($localLateNearHighRequiredForHighQuality) {
                    $highQualityNearHighGateOk = $nearRecentSwingHigh || $localLateTinyRoom;
                }

                $lateConditionsMet = $localLateFlagsTotal >= $localLateFlagsRequired
                    && $highQualityNearHighGateOk;
                $localLatePassedDueQuality = $localLateFlagsTotal > 0
                    && !$lateConditionsMet
                    && $primaryReason === null;
            } else {
                $localLateFlagsRequired = 2;
                $lateConditionsMet = $localLateFlagsTotal >= $localLateFlagsRequired;
                $localLatePassedDueQuality = $localLateFlagsTotal > 0
                    && !$lateConditionsMet
                    && $primaryReason === null;
            }
            if ($lateConditionsMet && $primaryReason === null) {
                $primaryReason = 'garbage_local_late_entry_after_recovery';
                $localLateBlockedByQualityRule = true;
                $localLateDecision = 'blocked';
                foreach ($lateEntryFlags as $flag => $met) {
                    if ($met) {
                        $secondaryReasons[] = $flag;
                    }
                }
                $secondaryReasons[] = 'local_late_quality_tier=' . $localLateQualityTier;
                $secondaryReasons[] = 'local_late_flags=' . $localLateFlagsTotal . '/' . $localLateFlagsRequired;
                if ($localLateNearHighRequiredForHighQuality) {
                    $secondaryReasons[] = 'local_late_high_quality_near_high_gate=true';
                }
            } else {
                $localLateDecision = $lateLocalChecked ? 'passed' : 'not_evaluated';
            }
        }

        // ── Veto 6: missing critical DBL trace ─────────────────────────────────
        // Block handoff when point3 / entry-distance fields are null and we could
        // not reconstruct them from parser2.  Only bypass for very strong signals
        // with OBC confirmed and no generic quality warning.
        if ($primaryReason === null && (bool)($config['dbl_garbage_block_missing_critical_trace'] ?? true)) {
            if (!$traceComplete) {
                $minBypassQuality    = (float)($config['dbl_garbage_missing_trace_min_quality_to_bypass']            ?? 0.82);
                $requireObcForBypass = (bool) ($config['dbl_garbage_missing_trace_requires_obc_confirmed_to_bypass'] ?? true);
                $canBypass = $qualityScore >= $minBypassQuality
                    && (!$requireObcForBypass || ($obWallChecked && !$obAskWallRisk))
                    && !$hasGenericWarning;
                if ($canBypass) {
                    $missingTraceHighQualityBypass = true;
                } else {
                    $primaryReason = 'garbage_missing_critical_dbl_trace';
                    if (!$traceHasPoint3) {
                        $secondaryReasons[] = 'missing_point3_trace';
                    }
                    if (!$traceHasNeckline || !$traceHasReclaim) {
                        $secondaryReasons[] = 'missing_neckline_or_reclaim';
                    }
                    if (!$traceHasEntryDistFromPoint3) {
                        $secondaryReasons[] = 'missing_entry_distance_from_point3';
                    }
                    if (!$traceHasReclaimConfirmation) {
                        $secondaryReasons[] = 'missing_reclaim_confirmation';
                    }
                    if ($traceReconstructionFailed) {
                        $secondaryReasons[] = 'parser2_reconstruction_failed';
                    }
                }
            }
        }

        // ── Veto 7: reclaim not confirmed for medium-quality signals ───────────
        // For signals where quality is medium (≤ configured threshold) and no
        // reclaim confirmation is available, block the handoff.
        if ($primaryReason === null
            && (bool)($config['dbl_garbage_require_reclaim_confirmation_for_medium_quality'] ?? true)
        ) {
            $medQualMax      = (float)($config['dbl_garbage_reclaim_confirmation_medium_quality_max'] ?? 0.78);
            $isMediumQuality = $qualityScore > 0.0 && $qualityScore <= $medQualMax;
            if ($isMediumQuality && !$hasAnyReclaimConfirmation) {
                $primaryReason = 'garbage_reclaim_not_confirmed';
                $secondaryReasons[] = 'reclaim_confirmed_false';
                if ($setupClass !== '') {
                    $secondaryReasons[] = 'setup_class=' . $setupClass;
                }
                if ($pendingConfirmStatus !== '' && $pendingConfirmStatus !== 'confirmed') {
                    $secondaryReasons[] = 'pending_confirmation_status=' . $pendingConfirmStatus;
                }
            }
        }

        // ── Veto 8: late-local + tiny-room WITHOUT any reclaim confirmation ────
        // Targets FHE/FIGHT-style entries: far from point3, tiny room to swing high,
        // and zero reclaim evidence. Governed by diagnostic_only toggle so the
        // first deployment only writes diagnostics; toggling to false adds the hard block.
        $lateLocalTinyRoomDetected = false;
        $lateLocalTinyRoomReason   = null;
        $lateLocalTinyRoomSoftPenalty = false;
        $reclaimRetestHeldForV8    = (bool)($ssc['reclaim_retest_held'] ?? false);
        if ($primaryReason === null) {
            $v8FarPoint3Pct   = (float)($config['dbl_garbage_late_local_far_point3_pct']    ?? 1.8);
            $v8TinyRoomRoi    = (float)($config['dbl_garbage_late_local_tiny_room_roi']     ?? 2.0);
            $v8DiagOnly       = (bool) ($config['dbl_garbage_late_local_tiny_room_diagnostic_only'] ?? true);
            $v8FarFromPoint3  = $entryDistPoint3 !== null && $entryDistPoint3 >= $v8FarPoint3Pct;
            $v8TinyRoom       = $roomToRecentSwingHighRoi !== null && $roomToRecentSwingHighRoi <= $v8TinyRoomRoi;
            $v8NoReclaim      = !$hasAnyReclaimConfirmation && !$reclaimRetestHeldForV8;
            if ($v8FarFromPoint3 && $v8TinyRoom && $v8NoReclaim) {
                $lateLocalTinyRoomDetected = true;
                $lateLocalTinyRoomReason   = 'late_entry_far_from_point3_tiny_room_no_reclaim';
                if ($v8DiagOnly) {
                    $lateLocalTinyRoomSoftPenalty = true;
                    $secondaryReasons[] = 'soft_late_local_tiny_room_no_reclaim';
                } else {
                    $primaryReason = 'garbage_late_local_tiny_room_without_reclaim';
                    $secondaryReasons[] = 'entry_dist_from_point3=' . round($entryDistPoint3, 2);
                    $secondaryReasons[] = 'room_to_swing_high=' . round($roomToRecentSwingHighRoi, 2);
                    $secondaryReasons[] = 'reclaim_confirmed_false';
                }
            }
        }

        $vetoTriggered = $primaryReason !== null;

        return [
            'veto_triggered'    => $vetoTriggered,
            'reason'            => $primaryReason,
            'secondary_reasons' => $secondaryReasons,
            'diag'              => [
                'garbage_veto_checked'               => true,
                'garbage_veto_triggered'             => $vetoTriggered,
                'garbage_veto_reason'                => $primaryReason,
                'garbage_veto_secondary_reasons'     => $secondaryReasons,
                'candidate_quality_score'            => $qualityScore,
                'warnings'                           => $warnings,
                'ob_wall_checked'                    => $obWallChecked,
                'ob_skip_reason'                     => $obSkipReason !== '' ? $obSkipReason : null,
                'ob_skip_quality_score'              => $obSkipScore > 0.0 ? $obSkipScore : null,
                'ob_skip_required_quality_score'     => $obSkipRequired > 0.0 ? $obSkipRequired : null,
                'day_change_pct'                     => $dayChangePct,
                'position_in_24h_range_pct'          => $positionIn24h,
                'room_to_24h_high_roi'               => $roomTo24hHigh,
                'recent_10m_range_roi'               => $recent10mRange,
                'recent_60m_direction_flips'         => $recent60mFlips,
                'whipsaw_score'                      => $whipsawScore,
                'entry_price'                        => $entryPrice > 0.0 ? $entryPrice : null,
                'point_3_second_low_price'           => $point3SecondLowPrice,
                'reclaim_level'                      => $reclaimLevel,
                'entry_distance_from_point3_pct'     => $entryDistPoint3,
                'entry_distance_from_reclaim_pct'    => $entryDistReclaim,
                'post_point3_bounce_roi'             => $postPoint3BounceRoi,
                'post_point3_impulse_spent_pct'      => $postPoint3ImpulseSpentPct,
                'room_to_recent_swing_high_roi'      => $roomToRecentSwingHighRoi,
                'near_recent_swing_high'             => $nearRecentSwingHigh,
                'local_recovery_leg_low_price'       => $localRecoveryLegLowPrice,
                'local_recovery_leg_high_price'      => $localRecoveryLegHighPrice,
                'recent_swing_high_price'            => $recentSwingHighPrice,
                'recent_swing_high_time'             => $recentSwingHighTime,
                'dbl_garbage_late_local_entry_checked' => $lateLocalChecked,
                'garbage_entry_far_from_point3'      => $lateEntryFlags['garbage_entry_far_from_point3'],
                'garbage_post_point3_impulse_already_spent' => $lateEntryFlags['garbage_post_point3_impulse_already_spent'],
                'garbage_insufficient_room_to_recent_swing_high' => $lateEntryFlags['garbage_insufficient_room_to_recent_swing_high'],
                'garbage_near_recent_swing_high'     => $lateEntryFlags['garbage_near_recent_swing_high'],
                'local_late_flags_total'             => $localLateFlagsTotal,
                'local_late_flags_required'          => $localLateFlagsRequired,
                'local_late_quality_tier'            => $localLateQualityTier,
                'local_late_tiny_room'               => $localLateTinyRoom,
                'local_late_near_high_required_for_high_quality' => $localLateNearHighRequiredForHighQuality,
                'local_late_blocked_by_quality_rule' => $localLateBlockedByQualityRule,
                'local_late_passed_due_quality'      => $localLatePassedDueQuality,
                'local_late_decision'                => $localLateDecision,
                // Trace completeness diagnostics
                'dbl_trace_checked'                      => $traceChecked,
                'dbl_trace_complete'                     => $traceComplete,
                'dbl_trace_source'                       => $traceSource,
                'dbl_trace_has_point3'                   => $traceHasPoint3,
                'dbl_trace_has_neckline'                 => $traceHasNeckline,
                'dbl_trace_has_reclaim'                  => $traceHasReclaim,
                'dbl_trace_has_entry_dist_from_point3'   => $traceHasEntryDistFromPoint3,
                'dbl_trace_has_reclaim_confirmation'     => $traceHasReclaimConfirmation,
                'dbl_trace_reconstructed_from_parser2'   => $traceReconstructed,
                'dbl_trace_reconstruction_failed'        => $traceReconstructionFailed,
                'dbl_trace_reconstruction_failed_reason' => $traceReconstructionFailedReason,
                'dbl_trace_missing_high_quality_bypass'  => $missingTraceHighQualityBypass,
                'ob_ask_wall_risk'                       => $obAskWallRisk,
                'reclaim_confirmed'                      => $reclaimConfirmed,
                'neckline_reclaim_confirmed'             => $necklineReclaimConfirmed,
                'setup_class'                            => $setupClass !== '' ? $setupClass : null,
                'pending_confirmation_status'            => $pendingConfirmStatus !== '' ? $pendingConfirmStatus : null,
                // Veto 8 diagnostics
                'local_late_tiny_room_detected'          => $lateLocalTinyRoomDetected,
                'local_late_tiny_room_soft_penalty'      => $lateLocalTinyRoomSoftPenalty,
                'local_late_tiny_room_reason'            => $lateLocalTinyRoomReason,
            ],
        ];
    }

    /**
     * Prepare DBL context before pattern-state decision:
     * - optionally reconstruct missing trace fields from parser2 history
     * - clear stale contradictory pattern diag flags
     *
     * @return array{context:array<string,mixed>,diag:array<string,mixed>}
     */
    private function prepareDblPatternStateContextWithTrace(
        string $symbol,
        string $detectedAt,
        array $record,
        array $context,
        array $config
    ): array {
        $ctx = is_array($context) ? $context : [];
        $recomputedAt = date('c');
        $staleCleared = false;

        $point1 = (float)($ctx['point_1_low_price'] ?? 0.0);
        $point2 = (float)($ctx['point_2_neckline_price'] ?? ($ctx['neckline_level'] ?? 0.0));
        $point3 = (float)($ctx['point_3_second_low_price'] ?? 0.0);
        $traceMissing = $point1 <= 0.0 || $point3 <= 0.0 || $point2 <= 0.0;
        $reconDiag = [
            'dbl_trace_reconstructed_before_pattern_state' => false,
            'dbl_trace_reconstruction_failed_before_pattern_state' => false,
            'dbl_trace_reconstruction_failed_reason_before_pattern_state' => null,
            'dbl_trace_source' => (string)($ctx['dbl_trace_source'] ?? ($ctx['dbl_pattern_confirmation_source'] ?? 'signal_context')),
        ];

        if ($traceMissing && $symbol !== '') {
            $entryPrice = (float)($record['entry_price'] ?? $record['last_price'] ?? 0.0);
            $recon = $this->tryReconstructDblPatternTraceFromParser2($symbol, $detectedAt, $entryPrice, $config);
            if (($recon['ok'] ?? false) === true) {
                if ($point1 <= 0.0 && (float)($recon['point_1_low_price'] ?? 0.0) > 0.0) {
                    $ctx['point_1_low_price'] = (float)$recon['point_1_low_price'];
                    $ctx['point_1_low_time'] = $recon['point_1_low_time'] ?? ($ctx['point_1_low_time'] ?? null);
                }
                if ($point2 <= 0.0 && (float)($recon['point_2_neckline_price'] ?? 0.0) > 0.0) {
                    $ctx['point_2_neckline_price'] = (float)$recon['point_2_neckline_price'];
                    $ctx['point_2_neckline_time'] = $recon['point_2_neckline_time'] ?? ($ctx['point_2_neckline_time'] ?? null);
                }
                if ($point3 <= 0.0 && (float)($recon['point_3_second_low_price'] ?? 0.0) > 0.0) {
                    $ctx['point_3_second_low_price'] = (float)$recon['point_3_second_low_price'];
                    $ctx['point_3_second_low_time'] = $recon['point_3_second_low_time'] ?? ($ctx['point_3_second_low_time'] ?? null);
                }
                if (!isset($ctx['dbl_trace_source']) || (string)$ctx['dbl_trace_source'] === '') {
                    $ctx['dbl_trace_source'] = 'parser2_reconstructed_pattern_state';
                }
                $this->dblTraceReconstructedBeforePatternStateTotal++;
                $reconDiag['dbl_trace_reconstructed_before_pattern_state'] = true;
                $reconDiag['dbl_trace_source'] = 'parser2_reconstructed_pattern_state';
            } else {
                $this->dblTraceReconstructionFailedBeforePatternStateTotal++;
                $reconDiag['dbl_trace_reconstruction_failed_before_pattern_state'] = true;
                $reconDiag['dbl_trace_reconstruction_failed_reason_before_pattern_state'] = $recon['failed_reason'] ?? 'unknown';
            }
        }

        // Clear stale contradictory diag fields before recalculation.
        $staleFields = [
            'confirmation_ttl_expired',
            'dbl_pattern_confirmed_at',
            'dbl_pattern_invalidated_at',
            'dbl_pattern_invalid_reason',
            'dbl_pattern_pending_reason',
            'dbl_pattern_status_reason',
        ];
        foreach ($staleFields as $field) {
            if (array_key_exists($field, $ctx)) {
                $ctx[$field] = null;
                $staleCleared = true;
            }
        }
        $ctx['dbl_pattern_diag_recomputed_at'] = $recomputedAt;
        $ctx['dbl_pattern_diag_source'] = $reconDiag['dbl_trace_reconstructed_before_pattern_state']
            ? 'parser2_recomputed'
            : ((string)($ctx['dbl_pattern_confirmation_source'] ?? '') !== '' ? 'mixed' : 'signal_context');
        $ctx['stale_pattern_diag_cleared'] = $staleCleared;

        return [
            'context' => $ctx,
            'diag' => array_merge($reconDiag, [
                'dbl_pattern_diag_recomputed_at' => $recomputedAt,
                'dbl_pattern_diag_source' => $ctx['dbl_pattern_diag_source'],
                'stale_pattern_diag_cleared' => $staleCleared,
            ]),
        ];
    }

    /**
     * Reconstruct DBL pattern trace points (1-2-3 + times) from parser2 tick history.
     *
     * @return array<string,mixed>
     */
    private function tryReconstructDblPatternTraceFromParser2(
        string $symbol,
        string $detectedAt,
        float $entryPrice,
        array $config
    ): array {
        $normalizedSymbol = strtoupper(trim($symbol));
        if ($normalizedSymbol === '' || !preg_match('/^[A-Z0-9]{2,30}$/', $normalizedSymbol)) {
            return ['ok' => false, 'failed_reason' => 'invalid_symbol'];
        }
        $detectedTs = $detectedAt !== '' ? (int)strtotime($detectedAt) : 0;
        if ($detectedTs <= 0) {
            $detectedTs = time() - 3600;
        }
        $storageRoot = $this->repoRoot . '/modules/parser/parser2_history_accumulator/storage/' . $normalizedSymbol;
        $files = [
            $storageRoot . '/' . gmdate('Y-m-d') . '.ndjson',
            $storageRoot . '/' . gmdate('Y-m-d', time() - 86400) . '.ndjson',
        ];
        $ticks = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $fh = @fopen($file, 'r');
            if (!is_resource($fh)) {
                continue;
            }
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                $ts = 0;
                if (isset($row['ts_unix'])) {
                    $ts = (int)$row['ts_unix'];
                } elseif (isset($row['ts'])) {
                    $parsed = strtotime((string)$row['ts']);
                    $ts = $parsed !== false ? (int)$parsed : 0;
                }
                $price = (float)($row['last_price'] ?? ($row['data']['lastPrice'] ?? 0.0));
                if ($ts <= 0 || $price <= 0.0 || $ts < ($detectedTs - 60)) {
                    continue;
                }
                $ticks[] = ['ts' => $ts, 'price' => $price];
            }
            fclose($fh);
        }
        if (count($ticks) < 5) {
            return ['ok' => false, 'failed_reason' => 'insufficient_ticks'];
        }
        usort($ticks, static fn($a, $b) => $a['ts'] <=> $b['ts']);

        $maxIdx = 0;
        $maxPrice = 0.0;
        foreach ($ticks as $i => $t) {
            if ((float)$t['price'] > $maxPrice) {
                $maxPrice = (float)$t['price'];
                $maxIdx = $i;
            }
        }
        $before = array_slice($ticks, 0, max(1, $maxIdx));
        $after = array_slice($ticks, min(count($ticks) - 1, $maxIdx + 1));
        if (empty($before) || empty($after)) {
            return ['ok' => false, 'failed_reason' => 'insufficient_split_ticks'];
        }
        $point1 = array_reduce($before, static function ($c, $t) {
            if ($c === null || (float)$t['price'] < (float)$c['price']) {
                return $t;
            }
            return $c;
        });
        $point3 = array_reduce($after, static function ($c, $t) {
            if ($c === null || (float)$t['price'] < (float)$c['price']) {
                return $t;
            }
            return $c;
        });
        if (!is_array($point1) || !is_array($point3)) {
            return ['ok' => false, 'failed_reason' => 'failed_points'];
        }

        return [
            'ok' => true,
            'point_1_low_price' => (float)$point1['price'],
            'point_1_low_time' => date('c', (int)$point1['ts']),
            'point_2_neckline_price' => $maxPrice > 0.0 ? $maxPrice : null,
            'point_2_neckline_time' => isset($ticks[$maxIdx]['ts']) ? date('c', (int)$ticks[$maxIdx]['ts']) : null,
            'point_3_second_low_price' => (float)$point3['price'],
            'point_3_second_low_time' => date('c', (int)$point3['ts']),
            'entry_price_used' => $entryPrice > 0.0 ? $entryPrice : null,
            'ticks_loaded' => count($ticks),
            'failed_reason' => null,
        ];
    }

    /**
     * DBL pattern-status state machine gate (primary pre-handoff gate).
     *
     * States:
     *  - raw_candidate
     *  - active
     *  - confirmed
     *  - invalid
     *
     * Only confirmed patterns are allowed to continue to garbage-veto/handoff.
     *
     * @return array{status:string,reason:string|null,confirmation_path:string,invalid_reason:?string,pending_reason:?string,diag:array<string,mixed>}
     */
    private function applyDblPatternStatusStateMachine(array $record, array $context, array $config): array
    {
        $ssc = is_array($context) ? $context : [];
        $enabled = (bool)($config['dbl_pattern_status_enabled'] ?? true);
        $confirmRequired = (bool)($config['dbl_pattern_confirmation_required_for_handoff'] ?? true);
        $allowPending = (bool)($config['dbl_pattern_allow_active_to_pending'] ?? true);
        $allowConfirmedToHandoff = (bool)($config['dbl_pattern_allow_confirmed_to_handoff'] ?? true);

        $detectedAt = (string)($record['detected_at'] ?? date('c'));
        $nowIso = date('c');
        $qualityScore = (float)($record['candidate_quality_score'] ?? $ssc['candidate_quality_score'] ?? 0.0);

        $point1 = isset($ssc['point_1_low_price']) ? (float)$ssc['point_1_low_price'] : 0.0;
        $point2 = isset($ssc['point_2_neckline_price']) ? (float)$ssc['point_2_neckline_price'] : 0.0;
        $point3 = isset($ssc['point_3_second_low_price']) ? (float)$ssc['point_3_second_low_price'] : 0.0;
        $necklineLevel = isset($ssc['neckline_level']) ? (float)$ssc['neckline_level'] : 0.0;
        $reclaimLevel = isset($ssc['reclaim_level']) ? (float)$ssc['reclaim_level'] : 0.0;
        $secondBottomLevel = isset($ssc['second_bottom_level']) ? (float)$ssc['second_bottom_level'] : 0.0;

        $hasPoint1 = $point1 > 0.0;
        $hasPoint2 = $point2 > 0.0 || $necklineLevel > 0.0;
        $hasPoint3 = $point3 > 0.0;
        $hasStructure = $hasPoint1 && $hasPoint2 && $hasPoint3;
        $traceComplete = $hasPoint1 && $hasPoint3;
        $point1Missing = !$hasPoint1;
        $point3Missing = !$hasPoint3;
        $necklinePresent = $necklineLevel > 0.0 || $point2 > 0.0;
        $reclaimPresent = $reclaimLevel > 0.0;

        $point3TolerancePct = (float)($config['dbl_pattern_point3_break_tolerance_pct'] ?? 0.20);
        $point3ToleranceMult = 1.0 - max(0.0, $point3TolerancePct) / 100.0;
        $point3BrokenByTolerance = $hasPoint1 && $hasPoint3 && $point3 < ($point1 * $point3ToleranceMult);

        $freshLowerLow = (bool)($ssc['fresh_lower_low_after_point3'] ?? false);
        $point3Broken = (bool)($ssc['point3_broken'] ?? false) || $point3BrokenByTolerance;
        $reclaimLost = (bool)($ssc['reclaim_lost_after_confirm'] ?? false) || (bool)($ssc['reclaim_level_lost'] ?? false);
        $weakBounce = (bool)($ssc['weak_bounce_after_point3'] ?? false);

        $closesAbove = isset($ssc['neckline_closes_above_count'])
            ? (int)$ssc['neckline_closes_above_count']
            : (isset($ssc['reclaim_closes_above_count']) ? (int)$ssc['reclaim_closes_above_count'] : 0);
        $minClosesAbove = max(1, (int)($config['dbl_pattern_min_closes_above_neckline'] ?? 2));
        $reclaimHoldBars = isset($ssc['reclaim_hold_bars']) ? (int)$ssc['reclaim_hold_bars'] : 0;
        $requiredHoldBars = max(1, (int)($config['dbl_pattern_reclaim_hold_bars'] ?? 2));
        $reclaimHoldMinutes = isset($ssc['reclaim_hold_minutes']) ? (float)$ssc['reclaim_hold_minutes'] : 0.0;
        $requiredHoldMinutes = max(1, (int)($config['dbl_pattern_reclaim_hold_minutes'] ?? 2));
        $higherLowAfterPoint3 = (bool)($ssc['higher_low_after_point3'] ?? false);
        $higherLowPrice = isset($ssc['higher_low_after_point3_price']) ? (float)$ssc['higher_low_after_point3_price'] : 0.0;
        $higherLowTolPct = (float)($config['dbl_pattern_higher_low_tolerance_pct'] ?? 0.15);
        $higherLowMin = $hasPoint3 ? ($point3 * (1.0 + max(0.0, $higherLowTolPct) / 100.0)) : 0.0;
        $higherLowPathValid = $higherLowAfterPoint3 && $higherLowPrice > 0.0 && $higherLowPrice >= $higherLowMin && !$freshLowerLow;

        $reclaimConfirmed = (bool)($ssc['reclaim_confirmed'] ?? false);
        $necklineReclaimConfirmed = (bool)($ssc['neckline_reclaim_confirmed'] ?? false);
        $reclaimRetestHeld = (bool)($ssc['reclaim_retest_held'] ?? false);
        $reclaimWentAbove = (bool)($ssc['reclaim_went_above'] ?? ($reclaimConfirmed || $necklineReclaimConfirmed));
        $reclaimLostAfterConfirm = (bool)($ssc['reclaim_lost_after_confirm'] ?? false) || $reclaimLost;
        $reclaimRecoveredAfterLoss = (bool)($ssc['reclaim_recovered_after_loss'] ?? false);
        $latestPrice = isset($ssc['latest_price']) ? (float)$ssc['latest_price'] : (float)($record['entry_price'] ?? $record['last_price'] ?? 0.0);
        $reclaimTolerancePct = (float)($config['dbl_pattern_reclaim_loss_tolerance_pct'] ?? 0.10);
        $reclaimToleranceMult = 1.0 - max(0.0, $reclaimTolerancePct) / 100.0;
        $currentPriceAboveReclaim = $reclaimPresent && $latestPrice > 0.0
            ? $latestPrice >= ($reclaimLevel * $reclaimToleranceMult)
            : false;
        $latestPriceDistanceFromReclaimPct = ($reclaimPresent && $latestPrice > 0.0)
            ? round((($latestPrice - $reclaimLevel) / $reclaimLevel) * 100.0, 4)
            : null;
        $reclaimLossDurationMinutes = isset($ssc['reclaim_loss_duration_minutes']) && is_numeric($ssc['reclaim_loss_duration_minutes'])
            ? (float)$ssc['reclaim_loss_duration_minutes']
            : null;
        if ($reclaimLostAfterConfirm && $reclaimRecoveredAfterLoss === false && $reclaimLossDurationMinutes === null && isset($ssc['reclaim_lost_since_ts'])) {
            $lostSinceTs = (int)$ssc['reclaim_lost_since_ts'];
            if ($lostSinceTs > 0) {
                $reclaimLossDurationMinutes = round((time() - $lostSinceTs) / 60, 2);
            }
        }
        if ($reclaimLostAfterConfirm && $currentPriceAboveReclaim) {
            $reclaimRecoveredAfterLoss = true;
        }
        $reclaimLossFinalState = 'unknown';
        if ($reclaimLostAfterConfirm) {
            $reclaimLossFinalState = $reclaimRecoveredAfterLoss ? 'recovered' : ($currentPriceAboveReclaim ? 'recovered' : 'still_lost');
        }
        $reclaimLossTerminal = $reclaimLostAfterConfirm && (
            (!$reclaimRecoveredAfterLoss && !$currentPriceAboveReclaim)
            || $point3Broken
            || $freshLowerLow
        );

        $necklineBreakConfirmed = $closesAbove >= $minClosesAbove && !$reclaimLost;
        $reclaimHoldConfirmed = ($reclaimConfirmed || $necklineReclaimConfirmed)
            && !$reclaimLost
            && ($reclaimHoldBars >= $requiredHoldBars || $reclaimHoldMinutes >= $requiredHoldMinutes);
        $retestHoldConfirmed = $reclaimRetestHeld && !$freshLowerLow && !$reclaimLost;

        $confirmedPaths = [];
        if ($necklineBreakConfirmed) { $confirmedPaths[] = 'neckline_break'; }
        if ($reclaimHoldConfirmed)   { $confirmedPaths[] = 'reclaim_hold'; }
        if ($retestHoldConfirmed)    { $confirmedPaths[] = 'retest_hold'; }
        if ($higherLowPathValid)     { $confirmedPaths[] = 'higher_low_after_point3'; }

        $confirmationPath = 'none';
        if (count($confirmedPaths) === 1) {
            $confirmationPath = $confirmedPaths[0];
        } elseif (count($confirmedPaths) > 1) {
            $confirmationPath = 'mixed';
        }
        $isConfirmed = !empty($confirmedPaths);

        $ttlMinutes = (int)($config['dbl_pattern_pending_ttl_minutes'] ?? 10);
        $ttlExpired = false;
        if ($ttlMinutes > 0) {
            $detTs = strtotime($detectedAt);
            if ($detTs !== false && $detTs > 0) {
                $ttlExpired = (time() - $detTs) > ($ttlMinutes * 60);
            }
        }
        if ($ttlExpired && $reclaimLostAfterConfirm && !$reclaimRecoveredAfterLoss) {
            $reclaimLossTerminal = true;
        }

        $status = 'raw_candidate';
        $statusReason = 'pattern_status_not_evaluated';
        $invalidReason = null;
        $pendingReason = null;
        $confirmedAt = null;
        $invalidatedAt = null;

        if (!$enabled) {
            $status = 'confirmed';
            $statusReason = 'pattern_status_gate_disabled';
            $confirmationPath = 'mixed';
            $confirmedAt = $nowIso;
        } else {
            if (!$traceComplete) {
                $status = 'invalid';
                $statusReason = 'dbl_trace_incomplete';
                $invalidReason = 'dbl_trace_incomplete';
            } elseif ($point3Broken) {
                $status = 'invalid';
                $statusReason = 'point3_broken';
                $invalidReason = 'point3_broken';
            } elseif ($freshLowerLow) {
                $status = 'invalid';
                $statusReason = 'fresh_lower_low_after_point3';
                $invalidReason = 'fresh_lower_low_after_point3';
            } elseif ($reclaimLossTerminal) {
                $status = 'invalid';
                $statusReason = 'reclaim_level_lost';
                $invalidReason = 'reclaim_level_lost';
            } elseif ($ttlExpired) {
                $status = 'invalid';
                $statusReason = 'confirmation_ttl_expired';
                $invalidReason = 'confirmation_ttl_expired';
            } elseif ($weakBounce) {
                $status = 'invalid';
                $statusReason = 'weak_bounce_after_point3';
                $invalidReason = 'weak_bounce_after_point3';
            } elseif ($isConfirmed && (!$confirmRequired || $allowConfirmedToHandoff)) {
                $status = 'confirmed';
                $statusReason = 'pattern_confirmation_path_valid';
                $confirmedAt = $nowIso;
            } elseif ($hasStructure && $allowPending) {
                $status = 'active';
                $statusReason = 'waiting_dbl_pattern_confirmation';
                $pendingReason = $reclaimConfirmed || $necklineReclaimConfirmed
                    ? 'waiting_retest_hold_confirmation'
                    : 'waiting_neckline_reclaim_confirmation';
            } elseif ($hasStructure) {
                $status = 'invalid';
                $statusReason = 'pattern_confirmation_required';
                $invalidReason = 'pattern_structure_invalid';
            } else {
                $status = 'raw_candidate';
                $statusReason = 'pattern_structure_incomplete';
            }
            if ($status === 'invalid') {
                $invalidatedAt = $nowIso;
            }
        }

        $diag = [
            'dbl_pattern_status_enabled'      => $enabled,
            'dbl_pattern_status'              => $status,
            'dbl_pattern_status_reason'       => $statusReason,
            'dbl_pattern_confirmation_path'   => $confirmationPath,
            'dbl_pattern_invalid_reason'      => $invalidReason,
            'dbl_pattern_pending_reason'      => $pendingReason,
            'dbl_pattern_detected_at'         => $detectedAt,
            'dbl_pattern_confirmed_at'        => $confirmedAt,
            'dbl_pattern_invalidated_at'      => $invalidatedAt,
            'point_1_low_price'               => $hasPoint1 ? $point1 : null,
            'point_1_low_time'                => $ssc['point_1_low_time'] ?? null,
            'point_2_neckline_price'          => $hasPoint2 ? ($point2 > 0.0 ? $point2 : $necklineLevel) : null,
            'point_2_neckline_time'           => $ssc['point_2_neckline_time'] ?? null,
            'point_3_second_low_price'        => $hasPoint3 ? $point3 : null,
            'point_3_second_low_time'         => $ssc['point_3_second_low_time'] ?? null,
            'neckline_level'                  => $necklineLevel > 0.0 ? $necklineLevel : null,
            'reclaim_level'                   => $reclaimLevel > 0.0 ? $reclaimLevel : null,
            'second_bottom_level'             => $secondBottomLevel > 0.0 ? $secondBottomLevel : null,
            'entry_distance_from_point3_pct'  => $ssc['entry_distance_from_point3_pct'] ?? null,
            'entry_distance_from_reclaim_pct' => $ssc['entry_distance_from_reclaim_pct'] ?? null,
            'neckline_break_confirmed'        => $necklineBreakConfirmed,
            'neckline_closes_above_count'     => $closesAbove > 0 ? $closesAbove : null,
            'reclaim_confirmed'               => $reclaimConfirmed,
            'neckline_reclaim_confirmed'      => $necklineReclaimConfirmed,
            'reclaim_hold_bars'               => $reclaimHoldBars > 0 ? $reclaimHoldBars : null,
            'reclaim_hold_minutes'            => $reclaimHoldMinutes > 0.0 ? $reclaimHoldMinutes : null,
            'reclaim_retest_held'             => $reclaimRetestHeld,
            'reclaim_went_above'              => $reclaimWentAbove,
            'reclaim_lost_after_confirm'      => $reclaimLostAfterConfirm,
            'reclaim_recovered_after_loss'    => $reclaimRecoveredAfterLoss,
            'current_price_above_reclaim'     => $currentPriceAboveReclaim,
            'latest_price'                    => $latestPrice > 0.0 ? $latestPrice : null,
            'latest_price_distance_from_reclaim_pct' => $latestPriceDistanceFromReclaimPct,
            'reclaim_loss_duration_minutes'   => $reclaimLossDurationMinutes,
            'reclaim_loss_final_state'        => $reclaimLossFinalState,
            'higher_low_after_point3'         => $higherLowAfterPoint3,
            'higher_low_after_point3_price'   => $higherLowPrice > 0.0 ? $higherLowPrice : null,
            'fresh_lower_low_after_point3'    => $freshLowerLow,
            'point3_broken'                   => $point3Broken,
            'reclaim_level_lost'              => $reclaimLossTerminal,
            'confirmation_ttl_expired'        => $ttlExpired,
            'point_1_missing'                 => $point1Missing,
            'point_3_missing'                 => $point3Missing,
            'neckline_present'                => $necklinePresent,
            'reclaim_present'                 => $reclaimPresent,
            'dbl_trace_complete'              => $traceComplete,
            'dbl_trace_source'                => $ssc['dbl_trace_source'] ?? ($ssc['dbl_pattern_confirmation_source'] ?? 'signal_context'),
            'dbl_pattern_diag_recomputed_at'  => $ssc['dbl_pattern_diag_recomputed_at'] ?? date('c'),
            'dbl_pattern_diag_source'         => $ssc['dbl_pattern_diag_source'] ?? 'mixed',
            'stale_pattern_diag_cleared'      => (bool)($ssc['stale_pattern_diag_cleared'] ?? false),
            // keep trend-shift fields for backward compatibility
            'trend_shift_gate_enabled'        => (bool)($config['dbl_trend_shift_gate_enabled'] ?? true),
            'trend_shift_checked'             => true,
            'trend_shift_confirmed'           => $status === 'confirmed',
            'trend_shift_confirmation_path'   => $confirmationPath,
            'trend_shift_state'               => $status === 'active' ? 'pending' : ($status === 'invalid' ? 'failed' : ($status === 'confirmed' ? 'confirmed' : 'not_checked')),
            'trend_shift_pending_reason'      => $pendingReason,
            'trend_shift_failed_reason'       => $invalidReason,
            'trend_direction_before_entry'    => $ssc['trend_direction'] ?? null,
            'recent_60m_direction_flips'      => $ssc['recent_60m_direction_flips'] ?? null,
            'room_to_recent_swing_high_roi'   => $ssc['room_to_recent_swing_high_roi'] ?? null,
            'post_point3_impulse_spent_pct'   => $ssc['post_point3_impulse_spent_pct'] ?? null,
            'dbl_pattern_confirmation_source'               => $ssc['dbl_pattern_confirmation_source'] ?? 'candidate_context',
            'dbl_pattern_confirmation_candles_loaded'       => $ssc['dbl_pattern_confirmation_candles_loaded'] ?? null,
            'dbl_pattern_confirmation_window_minutes'       => $ssc['dbl_pattern_confirmation_window_minutes'] ?? null,
            'dbl_pattern_confirmation_error'                => $ssc['dbl_pattern_confirmation_error'] ?? null,
        ];

        return [
            'status'            => $status,
            'reason'            => $statusReason,
            'confirmation_path' => $confirmationPath,
            'invalid_reason'    => $invalidReason,
            'pending_reason'    => $pendingReason,
            'diag'              => $diag,
        ];
    }

    /**
     * Persist/update a DBL active pattern watch entry.
     */
    private function writePendingPatternWatchEntry(array $record, string $signalId, array $ps, array $config): void
    {
        if (!(bool)($config['dbl_pattern_pending_enabled'] ?? true)) {
            return;
        }
        $path = $this->moduleDir . '/storage/pending_patterns.json';
        $maxItems = max(1, (int)($config['dbl_pattern_pending_max_items'] ?? 100));
        $existing = (array)$this->readJson('storage/pending_patterns.json', []);
        $ssc = is_array($record['strategy_signal_context'] ?? null) ? $record['strategy_signal_context'] : [];
        $ttlMin = (int)($config['dbl_pattern_pending_ttl_minutes'] ?? 10);
        $expiresAt = $ttlMin > 0 ? date('c', time() + ($ttlMin * 60)) : null;
        $entry = [
            'symbol'                 => (string)($record['symbol'] ?? ''),
            'signal_id'              => $signalId,
            'setup_class'            => $ssc['setup_class'] ?? null,
            'candidate_quality_score'=> $record['candidate_quality_score'] ?? ($ssc['candidate_quality_score'] ?? null),
            'dbl_pattern_status'     => 'active',
            'pending_reason'         => $ps['pending_reason'] ?? 'waiting_dbl_pattern_confirmation',
            'point_1_low_price'      => $ssc['point_1_low_price'] ?? null,
            'point_1_low_time'       => $ssc['point_1_low_time'] ?? null,
            'point_2_neckline_price' => $ssc['point_2_neckline_price'] ?? null,
            'point_2_neckline_time'  => $ssc['point_2_neckline_time'] ?? null,
            'point_3_second_low_price' => $ssc['point_3_second_low_price'] ?? null,
            'point_3_second_low_time'=> $ssc['point_3_second_low_time'] ?? null,
            'neckline_level'         => $ssc['neckline_level'] ?? null,
            'reclaim_level'          => $ssc['reclaim_level'] ?? null,
            'detected_at'            => $record['detected_at'] ?? date('c'),
            'last_checked_at'        => date('c'),
            'expires_at'             => $expiresAt,
            'required_confirmation_paths' => ['neckline_break', 'reclaim_hold', 'retest_hold', 'higher_low_after_point3'],
            'last_price'             => $record['entry_price'] ?? null,
        ];

        $kept = [];
        foreach ($existing as $e) {
            if (!is_array($e)) { continue; }
            $sameId = (string)($e['signal_id'] ?? '') === $signalId;
            if (!$sameId) { $kept[] = $e; }
        }
        $kept[] = $entry;
        if (count($kept) > $maxItems) {
            $kept = array_slice($kept, -$maxItems);
        }
        @file_put_contents($path, json_encode(array_values($kept), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * Build a compact DBL pattern-status example payload for last_run.
     */
    private function buildDblPatternExample(array $r, string $id, array $ps): array
    {
        $ssc = is_array($r['strategy_signal_context'] ?? null) ? $r['strategy_signal_context'] : [];
        $d = is_array($ps['diag'] ?? null) ? $ps['diag'] : [];
        return [
            'symbol'                        => $r['symbol'] ?? null,
            'signal_id'                     => $id,
            'setup_class'                   => $ssc['setup_class'] ?? null,
            'candidate_quality_score'       => $r['candidate_quality_score'] ?? ($ssc['candidate_quality_score'] ?? null),
            'dbl_pattern_status'            => $ps['status'] ?? null,
            'dbl_pattern_confirmation_path' => $ps['confirmation_path'] ?? 'none',
            'dbl_pattern_invalid_reason'    => $ps['invalid_reason'] ?? null,
            'point_1_low_price'             => $d['point_1_low_price'] ?? ($ssc['point_1_low_price'] ?? null),
            'point_2_neckline_price'        => $d['point_2_neckline_price'] ?? ($ssc['point_2_neckline_price'] ?? null),
            'point_3_second_low_price'      => $d['point_3_second_low_price'] ?? ($ssc['point_3_second_low_price'] ?? null),
            'neckline_level'                => $d['neckline_level'] ?? ($ssc['neckline_level'] ?? null),
            'reclaim_level'                 => $d['reclaim_level'] ?? ($ssc['reclaim_level'] ?? null),
            'neckline_closes_above_count'   => $d['neckline_closes_above_count'] ?? null,
            'reclaim_confirmed'             => $d['reclaim_confirmed'] ?? false,
            'neckline_reclaim_confirmed'    => $d['neckline_reclaim_confirmed'] ?? false,
            'reclaim_retest_held'           => $d['reclaim_retest_held'] ?? false,
            'higher_low_after_point3'       => $d['higher_low_after_point3'] ?? false,
            'fresh_lower_low_after_point3'  => $d['fresh_lower_low_after_point3'] ?? false,
            'point3_broken'                 => $d['point3_broken'] ?? false,
        ];
    }

    /**
     * Final trend-shift confirmation gate before Bot handoff.
     *
     * Runs after the garbage veto passes. Evaluates whether the DBL reversal
     * is confirmed by at least one of four strong confirmation paths:
     *
     *   A) reclaim_hold       — reclaim_confirmed / neckline_reclaim_confirmed with sufficient
     *                           closes above reclaim and no subsequent reclaim loss.
     *   B) retest_hold        — reclaim_retest_held = true (price retested and held reclaim).
     *   C) higher_low         — higher_low_after_point3 = true with no fresh lower low.
     *   D) short_structure_break — short_structure_break_confirmed or local_falling_resistance_broken.
     *
     * If not confirmed:
     *   - quality >= 0.78 → pending (block handoff, recheck on next tick, TTL applies)
     *   - quality <  0.78 OR hard failure → failed (hard block)
     *
     * @param  array $record  The bot_handoff_queue record (or active signal).
     * @param  array $context The strategy_signal_context sub-array.
     * @param  array $config  Module config.
     * @return array{gate_enabled:bool,gate_checked:bool,state:string,confirmation_path:string|null,
     *               block_reason:string|null,failed_reason:string|null,diag:array<string,mixed>}
     */
    private function applyDblTrendShiftConfirmationGate(array $record, array $context, array $config): array
    {
        $gateEnabled        = (bool)($config['dbl_trend_shift_gate_enabled']        ?? true);
        $requiredForHandoff = (bool)($config['dbl_trend_shift_required_for_handoff'] ?? true);

        $emptyDiag = [
            'trend_shift_gate_enabled'          => $gateEnabled,
            'trend_shift_checked'               => false,
            'trend_shift_confirmed'             => false,
            'trend_shift_confirmation_path'     => null,
            'trend_shift_state'                 => 'not_checked',
            'trend_shift_pending_reason'        => null,
            'trend_shift_failed_reason'         => null,
            'reclaim_confirmed'                 => false,
            'neckline_reclaim_confirmed'        => false,
            'reclaim_closes_above_count'        => null,
            'reclaim_hold_minutes'              => null,
            'reclaim_lost_after_confirm'        => false,
            'reclaim_retest_held'               => false,
            'higher_low_after_point3'           => false,
            'higher_low_after_point3_price'     => null,
            'fresh_lower_low_after_point3'      => false,
            'point3_broken'                     => false,
            'short_structure_break_confirmed'   => false,
            'local_falling_resistance_broken'   => false,
            'trend_direction_before_entry'      => null,
            'recent_60m_direction_flips'        => null,
            'room_to_recent_swing_high_roi'     => null,
            'post_point3_impulse_spent_pct'     => null,
        ];

        if (!$gateEnabled) {
            return [
                'gate_enabled'      => false,
                'gate_checked'      => false,
                'state'             => 'not_checked',
                'confirmation_path' => null,
                'block_reason'      => null,
                'failed_reason'     => null,
                'diag'              => $emptyDiag,
            ];
        }

        $ssc = is_array($context) ? $context : [];

        // ── Extract confirmation context from signal record and SSC ─────────────
        $qualityScore             = (float)($record['candidate_quality_score'] ?? $ssc['candidate_quality_score'] ?? 0.0);
        $reclaimConfirmed         = (bool)($ssc['reclaim_confirmed']                   ?? false);
        $necklineReclaimConfirmed = (bool)($ssc['neckline_reclaim_confirmed']          ?? false);
        $reclaimAfterFlat         = (bool)($record['reclaim_after_flat_detected']
            ?? $ssc['reclaim_after_flat_detected'] ?? false);
        $reclaimClosesAbove       = isset($ssc['reclaim_closes_above_count'])
            ? ($ssc['reclaim_closes_above_count'] !== null ? (int)$ssc['reclaim_closes_above_count'] : null)
            : null;
        $reclaimHoldMinutes       = isset($ssc['reclaim_hold_minutes'])
            ? ($ssc['reclaim_hold_minutes'] !== null ? (float)$ssc['reclaim_hold_minutes'] : null)
            : null;
        $reclaimLostAfterConfirm  = (bool)($ssc['reclaim_lost_after_confirm']          ?? false);
        $reclaimRetestHeld        = (bool)($ssc['reclaim_retest_held']                 ?? false);
        $higherLowAfterPoint3     = (bool)($ssc['higher_low_after_point3']             ?? false);
        $higherLowAfterPoint3Price = isset($ssc['higher_low_after_point3_price'])
            ? ($ssc['higher_low_after_point3_price'] !== null ? (float)$ssc['higher_low_after_point3_price'] : null)
            : null;
        $freshLowerLow            = (bool)($ssc['fresh_lower_low_after_point3']        ?? false);
        $point3Broken             = (bool)($ssc['point3_broken']                       ?? false);
        $shortStructureBreak      = (bool)($ssc['short_structure_break_confirmed']     ?? false);
        $localFallingResBroken    = (bool)($ssc['local_falling_resistance_broken']     ?? false);
        $trendDirection           = isset($ssc['trend_direction'])
            ? (string)$ssc['trend_direction']
            : null;
        $recent60mFlips           = isset($ssc['recent_60m_direction_flips'])
            ? ($ssc['recent_60m_direction_flips'] !== null ? (int)$ssc['recent_60m_direction_flips'] : null)
            : null;
        $roomToSwingHigh          = isset($ssc['room_to_recent_swing_high_roi'])
            ? ($ssc['room_to_recent_swing_high_roi'] !== null ? (float)$ssc['room_to_recent_swing_high_roi'] : null)
            : null;
        $postPoint3Impulse        = isset($ssc['post_point3_impulse_spent_pct'])
            ? ($ssc['post_point3_impulse_spent_pct'] !== null ? (float)$ssc['post_point3_impulse_spent_pct'] : null)
            : null;

        // ── Config thresholds ────────────────────────────────────────────────────
        $minClosesAbove       = max(1, (int)($config['dbl_trend_shift_min_closes_above_reclaim']   ?? 2));
        $pendingTtlMin        = (int)  ($config['dbl_trend_shift_pending_ttl_minutes']             ?? 10);
        $allowPending         = (bool) ($config['dbl_trend_shift_allow_high_quality_pending']      ?? true);
        $requireNoFreshLower  = (bool) ($config['dbl_trend_shift_require_no_fresh_lower_low']      ?? true);
        $highQualThreshold    = (float)($config['dbl_trend_shift_high_quality_threshold']          ?? 0.82);
        $pendingMinQuality    = 0.78; // minimum quality to allow pending (same as garbage veto tier boundary)

        // ── Pending TTL check ────────────────────────────────────────────────────
        // TTL is disabled when pending_ttl_minutes <= 0.
        $nowTs      = time();
        $detectedAt = (string)($record['detected_at'] ?? '');
        $detectedTs = $detectedAt !== '' ? strtotime($detectedAt) : 0;
        $ageSeconds = $detectedTs > 0 ? ($nowTs - $detectedTs) : 0;
        $ttlExpired = $pendingTtlMin > 0 && $ageSeconds > ($pendingTtlMin * 60);

        // ── Path A: Reclaim hold ─────────────────────────────────────────────────
        // reclaim_confirmed OR neckline_reclaim_confirmed OR reclaim_after_flat,
        // not lost after confirm, and enough closes above reclaim (if tracked).
        $pathAMet = ($reclaimConfirmed || $necklineReclaimConfirmed || $reclaimAfterFlat)
            && !$reclaimLostAfterConfirm;
        // If reclaim_closes_above_count is explicitly tracked and below threshold, invalidate.
        if ($pathAMet && $reclaimClosesAbove !== null && $reclaimClosesAbove < $minClosesAbove) {
            $pathAMet = false;
        }

        // ── Path B: Retest hold ──────────────────────────────────────────────────
        $pathBMet = $reclaimRetestHeld;

        // ── Path C: Higher-low after point3 ─────────────────────────────────────
        $pathCMet = $higherLowAfterPoint3 && !$freshLowerLow;

        // ── Path D: Short structure break ────────────────────────────────────────
        $pathDMet = $shortStructureBreak || $localFallingResBroken;

        $confirmationPath    = null;
        $trendShiftConfirmed = false;

        if ($pathAMet) {
            $trendShiftConfirmed = true;
            $confirmationPath    = 'reclaim_hold';
        } elseif ($pathBMet) {
            $trendShiftConfirmed = true;
            $confirmationPath    = 'retest_hold';
        } elseif ($pathCMet) {
            $trendShiftConfirmed = true;
            $confirmationPath    = 'higher_low_after_point3';
        } elseif ($pathDMet) {
            $trendShiftConfirmed = true;
            $confirmationPath    = 'short_structure_break';
        }

        // If gate is not required for handoff, treat every signal as confirmed.
        if (!$requiredForHandoff) {
            $trendShiftConfirmed = true;
            if ($confirmationPath === null) {
                $confirmationPath = 'gate_not_required';
            }
        }

        // ── Determine final state ────────────────────────────────────────────────
        $state        = 'not_checked';
        $blockReason  = null;
        $failedReason = null;
        $pendingReason = null;

        if ($trendShiftConfirmed) {
            $state = 'confirmed';
        } else {
            // Check for hard failure indicators
            $hardFailed     = false;
            $hardFailReason = null;

            if ($point3Broken) {
                $hardFailed     = true;
                $hardFailReason = 'point3_broken';
            } elseif ($reclaimLostAfterConfirm) {
                $hardFailed     = true;
                $hardFailReason = 'reclaim_level_lost';
            } elseif ($freshLowerLow && $requireNoFreshLower && $qualityScore < $highQualThreshold) {
                // Fresh lower low on low/mid-quality (q < high_quality_threshold) = failed outright.
                // High-quality signals may still recover; they fall through to pending.
                $hardFailed     = true;
                $hardFailReason = 'fresh_lower_low_after_point3';
            } elseif ($ttlExpired) {
                $hardFailed     = true;
                $hardFailReason = 'pending_ttl_expired';
            }

            if ($hardFailed) {
                $state        = 'failed';
                $failedReason = $hardFailReason;
                $blockReason  = 'trend_shift_confirmation_failed';
            } elseif ($allowPending && $qualityScore >= $pendingMinQuality) {
                // Promising but unconfirmed — keep in pending/watch, recheck next tick
                $state         = 'pending';
                $pendingReason = 'waiting_trend_shift_confirmation';
                $blockReason   = 'waiting_trend_shift_confirmation';
            } else {
                // Low quality or pending not allowed — hard fail
                $state        = 'failed';
                $failedReason = 'trend_shift_not_confirmed_low_quality';
                $blockReason  = 'trend_shift_confirmation_failed';
            }
        }

        $diag = [
            'trend_shift_gate_enabled'          => $gateEnabled,
            'trend_shift_checked'               => true,
            'trend_shift_confirmed'             => $trendShiftConfirmed,
            'trend_shift_confirmation_path'     => $confirmationPath,
            'trend_shift_state'                 => $state,
            'trend_shift_pending_reason'        => $pendingReason,
            'trend_shift_failed_reason'         => $failedReason,
            'reclaim_confirmed'                 => $reclaimConfirmed,
            'neckline_reclaim_confirmed'        => $necklineReclaimConfirmed,
            'reclaim_closes_above_count'        => $reclaimClosesAbove,
            'reclaim_hold_minutes'              => $reclaimHoldMinutes,
            'reclaim_lost_after_confirm'        => $reclaimLostAfterConfirm,
            'reclaim_retest_held'               => $reclaimRetestHeld,
            'higher_low_after_point3'           => $higherLowAfterPoint3,
            'higher_low_after_point3_price'     => $higherLowAfterPoint3Price,
            'fresh_lower_low_after_point3'      => $freshLowerLow,
            'point3_broken'                     => $point3Broken,
            'short_structure_break_confirmed'   => $shortStructureBreak,
            'local_falling_resistance_broken'   => $localFallingResBroken,
            'trend_direction_before_entry'      => $trendDirection,
            'recent_60m_direction_flips'        => $recent60mFlips,
            'room_to_recent_swing_high_roi'     => $roomToSwingHigh,
            'post_point3_impulse_spent_pct'     => $postPoint3Impulse,
        ];

        return [
            'gate_enabled'      => $gateEnabled,
            'gate_checked'      => true,
            'state'             => $state,
            'confirmation_path' => $confirmationPath,
            'block_reason'      => $blockReason,
            'failed_reason'     => $failedReason,
            'diag'              => $diag,
        ];
    }

    /**
     * Build a compact trend-shift gate example record for last_run diagnostics.
     *
     * @param  array  $r      The bot_handoff_queue record.
     * @param  string $id     The signal_id.
     * @param  array  $tsGate Return value of applyDblTrendShiftConfirmationGate().
     * @return array<string,mixed>
     */
    private function buildTrendShiftExample(array $r, string $id, array $tsGate): array
    {
        $ssc = is_array($r['strategy_signal_context'] ?? null) ? $r['strategy_signal_context'] : [];
        $d   = $tsGate['diag'];
        return [
            'symbol'                        => $r['symbol']                          ?? null,
            'signal_id'                     => $id,
            'candidate_quality_score'       => $r['candidate_quality_score']
                ?? $ssc['candidate_quality_score']                                   ?? null,
            'setup_class'                   => $ssc['setup_class']                   ?? null,
            'trend_shift_state'             => $tsGate['state']                      ?? null,
            'trend_shift_confirmation_path' => $tsGate['confirmation_path']          ?? null,
            'trend_shift_failed_reason'     => $tsGate['failed_reason']              ?? null,
            'reclaim_level'                 => $ssc['reclaim_level']                 ?? null,
            'neckline_level'                => $ssc['neckline_level']                ?? null,
            'point3_second_low_price'       => $ssc['point_3_second_low_price']      ?? null,
            'reclaim_confirmed'             => $d['reclaim_confirmed']               ?? false,
            'neckline_reclaim_confirmed'    => $d['neckline_reclaim_confirmed']      ?? false,
            'reclaim_closes_above_count'    => $d['reclaim_closes_above_count']      ?? null,
            'higher_low_after_point3'       => $d['higher_low_after_point3']         ?? false,
            'fresh_lower_low_after_point3'  => $d['fresh_lower_low_after_point3']    ?? false,
            'recent_60m_direction_flips'    => $d['recent_60m_direction_flips']      ?? null,
            'room_to_recent_swing_high_roi' => $d['room_to_recent_swing_high_roi']   ?? null,
            'post_point3_impulse_spent_pct' => $d['post_point3_impulse_spent_pct']   ?? null,
        ];
    }

    /**
     * Compute whipsaw metrics from 1m/5m entry-context candles.
     *
     * Returns:
     *   recent_10m_range_roi      - (high - low) / low * 100 for last 10 candles
     *   recent_60m_range_roi      - (high - low) / low * 100 for last 60 candles
     *   recent_10m_direction_flips - number of close direction reversals in last 10 candles
     *   recent_60m_direction_flips - number of close direction reversals in last 60 candles
     *   whipsaw_score              - simple combined score (0..1); higher = more chaotic
     */
    private function computeWhipsawMetrics(array $candles): array
    {
        $n = count($candles);
        if ($n < 2) {
            return [
                'recent_10m_range_roi'       => null,
                'recent_60m_range_roi'       => null,
                'recent_10m_direction_flips' => null,
                'recent_60m_direction_flips' => null,
                'whipsaw_score'              => null,
            ];
        }

        $window10  = array_slice($candles, -10);
        $window60  = array_slice($candles, -60);

        $rangeRoi = static function (array $win): ?float {
            if (count($win) < 2) {
                return null;
            }
            $high = max(array_column($win, 'high'));
            $low  = min(array_column($win, 'low'));
            if ($low <= 0.0) {
                return null;
            }
            return round(($high - $low) / $low * 100.0, 3);
        };

        $dirFlips = static function (array $win): int {
            $flips = 0;
            $cnt   = count($win);
            if ($cnt < 3) {
                return 0;
            }
            $prevDir = null;
            for ($i = 1; $i < $cnt; $i++) {
                $diff = (float)($win[$i]['close'] ?? 0.0) - (float)($win[$i - 1]['close'] ?? 0.0);
                $dir  = $diff > 0 ? 1 : ($diff < 0 ? -1 : 0);
                if ($dir !== 0) {
                    if ($prevDir !== null && $dir !== $prevDir) {
                        $flips++;
                    }
                    $prevDir = $dir;
                }
            }
            return $flips;
        };

        $range10m  = $rangeRoi($window10);
        $range60m  = $rangeRoi($window60);
        $flips10m  = $dirFlips($window10);
        $flips60m  = $dirFlips($window60);

        // Simple 0–1 combined whipsaw score.
        // Normalization bases: 20% range → 1.0 range component; 15 flips → 1.0 flip component.
        // These are not veto thresholds (those are in config); they are internal score scaling
        // anchors that represent "clearly extreme" values for the composite score.
        $score = null;
        if ($range60m !== null && $flips60m !== null) {
            $rangeNorm  = min(1.0, $range60m / 20.0);
            $flipsNorm  = min(1.0, $flips60m / 15.0);
            $score      = round(($rangeNorm * 0.6 + $flipsNorm * 0.4), 3);
        }

        return [
            'recent_10m_range_roi'       => $range10m,
            'recent_60m_range_roi'       => $range60m,
            'recent_10m_direction_flips' => $flips10m,
            'recent_60m_direction_flips' => $flips60m,
            'whipsaw_score'              => $score,
        ];
    }

    /**
     * Lightweight reconstruction of local DBL 1-2-3 trace from parser2 ticker history.
     *
     * Reads today's and yesterday's ndjson files from the parser2 history accumulator
     * storage for the given symbol.  Extracts last-price ticks within a 4-hour window
     * and computes a proxy for:
     *   - point3_price               (local price minimum = second-bottom proxy)
     *   - local_low / local_high     (range over the lookback window)
     *   - entry_distance_from_point3_pct
     *   - post_point3_bounce_roi
     *   - post_point3_impulse_spent_pct
     *   - room_to_recent_swing_high_roi / near_recent_swing_high / recent_swing_high_price
     *
     * This is ONLY used to supply trace values for the garbage-veto and diagnostics
     * when the native intraday-DB fields are null.  It does NOT alter detection logic.
     *
     * @param array<string,mixed> $config
     * @return array{ok:bool,failed_reason:string|null,point3_price:float|null,local_low:float|null,local_high:float|null,entry_distance_from_point3_pct:float|null,post_point3_bounce_roi:float|null,post_point3_impulse_spent_pct:float|null,room_to_recent_swing_high_roi:float|null,near_recent_swing_high:bool|null,recent_swing_high_price:float|null,ticks_loaded:int,lookback_minutes:int}
     */
    private function tryReconstructLocalTraceFromParser2(string $symbol, float $entryPrice, array $config): array
    {
        /** @return array{ok:false,failed_reason:string,point3_price:null,local_low:null,local_high:null,entry_distance_from_point3_pct:null,post_point3_bounce_roi:null,post_point3_impulse_spent_pct:null,room_to_recent_swing_high_roi:null,near_recent_swing_high:null,recent_swing_high_price:null,ticks_loaded:0,lookback_minutes:0} */
        $fail = static function (string $reason): array {
            return [
                'ok'                             => false,
                'failed_reason'                  => $reason,
                'point3_price'                   => null,
                'local_low'                      => null,
                'local_high'                     => null,
                'entry_distance_from_point3_pct' => null,
                'post_point3_bounce_roi'         => null,
                'post_point3_impulse_spent_pct'  => null,
                'room_to_recent_swing_high_roi'  => null,
                'near_recent_swing_high'         => null,
                'recent_swing_high_price'        => null,
                'ticks_loaded'                   => 0,
                'lookback_minutes'               => 0,
            ];
        };

        $normalizedSymbol = strtoupper(trim($symbol));
        if (!preg_match('/^[A-Z0-9]{2,30}$/', $normalizedSymbol)) {
            return $fail('invalid_symbol');
        }

        $lookbackMinutes = 240;
        $cutoffTs        = time() - ($lookbackMinutes * 60);
        $storageRoot     = $this->repoRoot
            . '/modules/parser/parser2_history_accumulator/storage/'
            . $normalizedSymbol;
        $files = [
            $storageRoot . '/' . gmdate('Y-m-d') . '.ndjson',
            $storageRoot . '/' . gmdate('Y-m-d', time() - 86400) . '.ndjson',
        ];

        $prices = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $fh = @fopen($file, 'r');
            if (!is_resource($fh)) {
                continue;
            }
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                $ts = 0;
                if (isset($row['ts_unix'])) {
                    $ts = (int)$row['ts_unix'];
                } elseif (isset($row['ts'])) {
                    $parsed = strtotime((string)$row['ts']);
                    $ts = $parsed !== false ? (int)$parsed : 0;
                }
                $price = (float)($row['last_price'] ?? ($row['data']['lastPrice'] ?? 0.0));
                if ($ts <= 0 || $price <= 0.0 || $ts < $cutoffTs) {
                    continue;
                }
                $prices[] = $price;
            }
            fclose($fh);
        }

        if (count($prices) < 5) {
            return $fail('parser2_insufficient_data');
        }

        $localLow  = (float)min($prices);
        $localHigh = (float)max($prices);

        if ($localLow <= 0.0 || $localHigh <= $localLow) {
            return $fail('parser2_invalid_price_range');
        }

        $entryDistFromPoint3 = $entryPrice > 0.0
            ? round((($entryPrice - $localLow) / $localLow) * 100.0, 4)
            : null;

        $postPoint3BounceRoi = round((($localHigh - $localLow) / $localLow) * 100.0, 4);

        $postPoint3ImpulseSpentPct = ($entryPrice > 0.0 && ($localHigh - $localLow) > 0.0)
            ? round((($entryPrice - $localLow) / ($localHigh - $localLow)) * 100.0, 3)
            : null;

        $roomToSwingHighRoi = $entryPrice > 0.0
            ? round((($localHigh - $entryPrice) / $entryPrice) * 100.0, 4)
            : null;

        $nearPct       = (float)($config['dbl_garbage_near_recent_swing_high_pct'] ?? 0.35);
        $nearSwingHigh = $roomToSwingHighRoi !== null ? ($roomToSwingHighRoi <= $nearPct) : null;

        return [
            'ok'                             => true,
            'failed_reason'                  => null,
            'point3_price'                   => $localLow,
            'local_low'                      => $localLow,
            'local_high'                     => $localHigh,
            'entry_distance_from_point3_pct' => $entryDistFromPoint3,
            'post_point3_bounce_roi'         => $postPoint3BounceRoi,
            'post_point3_impulse_spent_pct'  => $postPoint3ImpulseSpentPct,
            'room_to_recent_swing_high_roi'  => $roomToSwingHighRoi,
            'near_recent_swing_high'         => $nearSwingHigh,
            'recent_swing_high_price'        => $localHigh,
            'ticks_loaded'                   => count($prices),
            'lookback_minutes'               => $lookbackMinutes,
        ];
    }

    /**
     * Compute DBL confirmation/invalidation fields from parser2 last_price ticks.
     * Reads ticks from detected_at onwards and evaluates all 4 confirmation paths
     * plus invalidation conditions.
     */
    private function computeDblConfirmationFromParser2(
        string $symbol,
        float  $necklineLevel,
        float  $reclaimLevel,
        float  $point3Price,
        int    $detectedAtTs,
        array  $config
    ): array {
        $empty = [
            'ok'                            => false,
            'source'                        => 'missing',
            'candles_loaded'                => 0,
            'window_minutes'                => 0,
            'error'                         => null,
            'neckline_closes_above_count'   => 0,
            'reclaim_closes_above_count'    => 0,
            'reclaim_confirmed'             => false,
            'neckline_reclaim_confirmed'    => false,
            'reclaim_hold_bars'             => 0,
            'reclaim_hold_minutes'          => 0.0,
            'reclaim_retest_held'           => false,
            'higher_low_after_point3'       => false,
            'higher_low_after_point3_price' => null,
            'fresh_lower_low_after_point3'  => false,
            'point3_broken'                 => false,
            'reclaim_level_lost'            => false,
            'weak_bounce_after_point3'      => false,
            'reclaim_went_above'            => false,
            'reclaim_lost_after_confirm'    => false,
            'reclaim_recovered_after_loss'  => false,
            'current_price_above_reclaim'   => false,
            'latest_price'                  => null,
            'latest_price_distance_from_reclaim_pct' => null,
            'reclaim_loss_duration_minutes' => null,
            'reclaim_loss_final_state'      => 'unknown',
        ];

        $normalizedSymbol = strtoupper(trim($symbol));
        if (!preg_match('/^[A-Z0-9]{2,30}$/', $normalizedSymbol)) {
            $empty['error'] = 'invalid_symbol';
            return $empty;
        }

        // Use neckline as reference level; fall back to reclaim
        $refNeckline = $necklineLevel > 0.0 ? $necklineLevel : $reclaimLevel;
        $refReclaim  = $reclaimLevel  > 0.0 ? $reclaimLevel  : $necklineLevel;
        if ($refNeckline <= 0.0 && $refReclaim <= 0.0) {
            $empty['error'] = 'no_levels';
            return $empty;
        }

        // Load parser2 ticks
        $storageRoot = $this->repoRoot
            . '/modules/parser/parser2_history_accumulator/storage/'
            . $normalizedSymbol;
        $files = [
            $storageRoot . '/' . gmdate('Y-m-d') . '.ndjson',
            $storageRoot . '/' . gmdate('Y-m-d', time() - 86400) . '.ndjson',
        ];

        $ticks = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $fh = @fopen($file, 'r');
            if (!is_resource($fh)) {
                continue;
            }
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                $ts = 0;
                if (isset($row['ts_unix'])) {
                    $ts = (int)$row['ts_unix'];
                } elseif (isset($row['ts'])) {
                    $parsed = strtotime((string)$row['ts']);
                    $ts = $parsed !== false ? (int)$parsed : 0;
                }
                $price = (float)($row['last_price'] ?? ($row['data']['lastPrice'] ?? 0.0));
                if ($ts <= 0 || $price <= 0.0) {
                    continue;
                }
                // Only include ticks from detectedAt onwards (60s tolerance)
                if ($detectedAtTs > 0 && $ts < ($detectedAtTs - 60)) {
                    continue;
                }
                $ticks[] = ['ts' => $ts, 'price' => $price];
            }
            fclose($fh);
        }

        if (count($ticks) < 2) {
            $empty['error'] = 'insufficient_ticks';
            return $empty;
        }

        // Sort by timestamp ascending
        usort($ticks, static fn($a, $b) => $a['ts'] <=> $b['ts']);

        $firstTs = $ticks[0]['ts'];
        $lastTs  = $ticks[count($ticks) - 1]['ts'];
        $windowMinutes = (int)(($lastTs - $firstTs) / 60);

        // Tolerance config
        $point3TolerancePct  = (float)($config['dbl_pattern_point3_break_tolerance_pct'] ?? 0.20);
        $point3BreakThreshold = $point3Price > 0.0
            ? $point3Price * (1.0 - max(0.0, $point3TolerancePct) / 100.0)
            : 0.0;
        $higherLowTolPct     = (float)($config['dbl_pattern_higher_low_tolerance_pct'] ?? 0.15);
        $higherLowMin        = $point3Price > 0.0
            ? $point3Price * (1.0 + max(0.0, $higherLowTolPct) / 100.0)
            : 0.0;
        $minClosesAbove      = max(1, (int)($config['dbl_pattern_min_closes_above_neckline'] ?? 2));

        // State tracking
        $maxConsecAboveNeckline = 0;
        $maxConsecAboveReclaim  = 0;
        $consecAboveNeckline    = 0;
        $consecAboveReclaim     = 0;
        $reclaimHoldBars        = 0;
        $reclaimHoldSecondsTotal = 0;
        $reclaimWentAbove       = false;
        $reclaimLostAfter       = false;
        $reclaimRecoveredAfterLoss = false;
        $reclaimLostSinceTs     = null;
        $point3Broken           = false;
        $freshLowerLow          = false;
        $prevAboveReclaim       = false;
        $prevTs                 = null;
        $pricesSinceDetected    = [];

        foreach ($ticks as $tick) {
            $price = $tick['price'];
            $ts    = $tick['ts'];

            $pricesSinceDetected[] = $price;

            // Point3 break / fresh lower low
            if ($point3Price > 0.0 && $point3BreakThreshold > 0.0 && $price < $point3BreakThreshold) {
                $point3Broken  = true;
                $freshLowerLow = true;
            }

            // Above neckline
            $aboveNeckline = $refNeckline > 0.0 && $price > $refNeckline;
            if ($aboveNeckline) {
                $consecAboveNeckline++;
                if ($consecAboveNeckline > $maxConsecAboveNeckline) {
                    $maxConsecAboveNeckline = $consecAboveNeckline;
                }
            } else {
                $consecAboveNeckline = 0;
            }

            // Above reclaim
            $aboveReclaim = $refReclaim > 0.0 && $price > $refReclaim;
            if ($aboveReclaim) {
                $consecAboveReclaim++;
                if ($consecAboveReclaim > $maxConsecAboveReclaim) {
                    $maxConsecAboveReclaim = $consecAboveReclaim;
                }
                if (!$reclaimWentAbove) {
                    $reclaimWentAbove = true;
                }
                if ($prevAboveReclaim && $prevTs !== null) {
                    $reclaimHoldSecondsTotal += ($ts - $prevTs);
                    $reclaimHoldBars++;
                }
            } else {
                if ($reclaimWentAbove && !$aboveReclaim) {
                    // Dropped below reclaim after having been above it
                    $reclaimLostAfter = true;
                    if ($reclaimLostSinceTs === null) {
                        $reclaimLostSinceTs = $ts;
                    }
                }
                $consecAboveReclaim = 0;
            }

            if ($reclaimLostAfter && $aboveReclaim) {
                $reclaimRecoveredAfterLoss = true;
                $reclaimLostSinceTs = null;
            }

            $prevAboveReclaim = $aboveReclaim;
            $prevTs = $ts;
        }

        $reclaimHoldMinutes = round($reclaimHoldSecondsTotal / 60.0, 2);
        $reclaimConfirmed   = $maxConsecAboveReclaim  >= $minClosesAbove;
        $necklineConfirmed  = $maxConsecAboveNeckline >= $minClosesAbove;

        // Higher low after point3: find minimum price that is above point3+tolerance
        $higherLowFound = false;
        $higherLowPrice = null;
        if (count($pricesSinceDetected) >= 3 && $point3Price > 0.0 && !$freshLowerLow) {
            $minSince = min($pricesSinceDetected);
            $lastPrice = end($pricesSinceDetected);
            // A higher low: min price is above point3 by tolerance, and last price is above that min
            if ($minSince >= $higherLowMin && $lastPrice >= $minSince) {
                $higherLowFound = true;
                $higherLowPrice = $minSince;
            }
        }

        // Reclaim retest held: reclaim confirmed + current price near/above reclaim + no lower low
        $reclaimRetestHeld = false;
        if ($reclaimWentAbove && !$freshLowerLow && count($pricesSinceDetected) > 0) {
            $lastPrice = end($pricesSinceDetected);
            $reclaimRetestZone = $refReclaim * 0.997; // within 0.3% of reclaim = retest zone
            if ($lastPrice >= $reclaimRetestZone) {
                $reclaimRetestHeld = true;
            }
        }
        $latestPrice = count($pricesSinceDetected) > 0 ? (float)end($pricesSinceDetected) : null;
        $currentPriceAboveReclaim = $latestPrice !== null && $refReclaim > 0.0 ? ($latestPrice >= ($refReclaim * 0.999)) : false;
        if ($reclaimLostAfter && $currentPriceAboveReclaim) {
            $reclaimRecoveredAfterLoss = true;
        }
        $latestPriceDistanceFromReclaimPct = ($latestPrice !== null && $refReclaim > 0.0)
            ? round((($latestPrice - $refReclaim) / $refReclaim) * 100.0, 4)
            : null;
        $reclaimLossDurationMinutes = null;
        if ($reclaimLostAfter && $reclaimLostSinceTs !== null) {
            $reclaimLossDurationMinutes = round((max($lastTs, time()) - $reclaimLostSinceTs) / 60.0, 2);
        }
        $reclaimLossFinalState = 'unknown';
        if ($reclaimLostAfter) {
            $reclaimLossFinalState = $reclaimRecoveredAfterLoss ? 'recovered' : 'still_lost';
        }

        // Weak bounce: bounced < 50% of distance from point3 to neckline, never reached neckline
        $weakBounce = false;
        if ($refNeckline > 0.0 && $point3Price > 0.0 && !$reclaimWentAbove && count($pricesSinceDetected) > 0) {
            $maxSince = max($pricesSinceDetected);
            $distToNeckline = $refNeckline - $point3Price;
            if ($distToNeckline > 0.0) {
                $bounceSize = $maxSince - $point3Price;
                if ($bounceSize >= 0.0 && $bounceSize < ($distToNeckline * 0.5)) {
                    $weakBounce = true;
                }
            }
        }

        return [
            'ok'                            => true,
            'source'                        => 'parser2',
            'candles_loaded'                => count($ticks),
            'window_minutes'                => $windowMinutes,
            'error'                         => null,
            'neckline_closes_above_count'   => $maxConsecAboveNeckline,
            'reclaim_closes_above_count'    => $maxConsecAboveReclaim,
            'reclaim_confirmed'             => $reclaimConfirmed,
            'neckline_reclaim_confirmed'    => $necklineConfirmed,
            'reclaim_hold_bars'             => $reclaimHoldBars,
            'reclaim_hold_minutes'          => $reclaimHoldMinutes,
            'reclaim_retest_held'           => $reclaimRetestHeld,
            'higher_low_after_point3'       => $higherLowFound,
            'higher_low_after_point3_price' => $higherLowPrice,
            'fresh_lower_low_after_point3'  => $freshLowerLow,
            'point3_broken'                 => $point3Broken,
            'reclaim_level_lost'            => $reclaimLostAfter,
            'weak_bounce_after_point3'      => $weakBounce,
            'reclaim_went_above'            => $reclaimWentAbove,
            'reclaim_lost_after_confirm'    => $reclaimLostAfter,
            'reclaim_recovered_after_loss'  => $reclaimRecoveredAfterLoss,
            'current_price_above_reclaim'   => $currentPriceAboveReclaim,
            'latest_price'                  => $latestPrice,
            'latest_price_distance_from_reclaim_pct' => $latestPriceDistanceFromReclaimPct,
            'reclaim_loss_duration_minutes' => $reclaimLossDurationMinutes,
            'reclaim_loss_final_state'      => $reclaimLossFinalState,
        ];
    }

    /**
     * Enrich a strategy_signal_context array with parser2-computed confirmation fields
     * when the key confirmation fields are absent or unset.
     *
     * Only enriches if the existing context lacks positive confirmation data
     * (i.e., all paths are false/0/null).  Existing true/positive values are
     * preserved and not overwritten.
     */
    private function enrichDblContextWithParser2(
        string $symbol,
        string $detectedAt,
        array  $ctx,
        array  $config
    ): array {
        // Check whether any confirmation path is already positively set
        $alreadyConfirming = (
            ($ctx['reclaim_confirmed']          ?? false) === true ||
            ($ctx['neckline_reclaim_confirmed'] ?? false) === true ||
            ($ctx['higher_low_after_point3']    ?? false) === true ||
            ($ctx['reclaim_retest_held']        ?? false) === true ||
            (int)($ctx['neckline_closes_above_count'] ?? 0) >= max(1, (int)($config['dbl_pattern_min_closes_above_neckline'] ?? 2))
        );
        if ($alreadyConfirming) {
            return $ctx;
        }

        $necklineLevel = (float)($ctx['neckline_level'] ?? ($ctx['point_2_neckline_price'] ?? 0.0));
        $reclaimLevel  = (float)($ctx['reclaim_level']  ?? 0.0);
        $point3Price   = (float)($ctx['point_3_second_low_price'] ?? 0.0);
        if ($necklineLevel <= 0.0 && $reclaimLevel <= 0.0) {
            return $ctx;
        }
        $detectedTs = $detectedAt !== '' ? (int)strtotime($detectedAt) : 0;
        if ($detectedTs <= 0) {
            $detectedTs = time() - 600; // default: 10 minutes ago
        }

        $computed = $this->computeDblConfirmationFromParser2(
            $symbol,
            $necklineLevel,
            $reclaimLevel,
            $point3Price,
            $detectedTs,
            $config
        );

        if (!($computed['ok'] ?? false)) {
            $ctx['dbl_pattern_confirmation_source']          = 'fallback';
            $ctx['dbl_pattern_confirmation_candles_loaded']  = 0;
            $ctx['dbl_pattern_confirmation_window_minutes']  = 0;
            $ctx['dbl_pattern_confirmation_error']           = $computed['error'] ?? 'unknown';
            return $ctx;
        }

        // Merge: only override fields that are currently absent/falsy/zero
        $mergeFields = [
            'neckline_closes_above_count',
            'reclaim_closes_above_count',
            'reclaim_confirmed',
            'neckline_reclaim_confirmed',
            'reclaim_hold_bars',
            'reclaim_hold_minutes',
            'reclaim_retest_held',
            'reclaim_went_above',
            'reclaim_lost_after_confirm',
            'reclaim_recovered_after_loss',
            'current_price_above_reclaim',
            'latest_price',
            'latest_price_distance_from_reclaim_pct',
            'reclaim_loss_duration_minutes',
            'reclaim_loss_final_state',
            'higher_low_after_point3',
            'higher_low_after_point3_price',
            'fresh_lower_low_after_point3',
            'point3_broken',
            'reclaim_level_lost',
            'weak_bounce_after_point3',
        ];
        foreach ($mergeFields as $field) {
            if (!isset($ctx[$field]) || $ctx[$field] === false || $ctx[$field] === null || $ctx[$field] === 0 || $ctx[$field] === 0.0) {
                if (isset($computed[$field]) && $computed[$field] !== false && $computed[$field] !== null && $computed[$field] !== 0 && $computed[$field] !== 0.0) {
                    $ctx[$field] = $computed[$field];
                }
            }
        }

        $ctx['dbl_pattern_confirmation_source']         = 'parser2';
        $ctx['dbl_trace_source'] = 'parser2';
        $ctx['dbl_pattern_confirmation_candles_loaded'] = $computed['candles_loaded'];
        $ctx['dbl_pattern_confirmation_window_minutes'] = $computed['window_minutes'];
        $ctx['dbl_pattern_confirmation_error']          = null;

        return $ctx;
    }

    /**
     * Compute confirmed-pattern validity diagnostics for a signal.
     *
     * @return array{
     *   confirmed_pattern_age_minutes: float|int|null,
     *   confirmed_pattern_ttl_minutes: int,
     *   confirmed_pattern_valid_for_handoff: bool,
     *   confirmed_pattern_invalid_reason: ?string,
     *   confirmed_pattern_price_still_valid: bool,
     *   confirmed_pattern_price_check_source: string
     * }
     */
    private function computeConfirmedPatternValidityDiag(array $signal, array $config, int $nowTs, mixed $detectedAgeMin = null): array
    {
        $confirmedPatternTtlMin = (int)($config['dbl_confirmed_pattern_ttl_minutes'] ?? 10);
        $confirmedPatternRequireValid = (bool)($config['dbl_confirmed_pattern_require_price_still_valid'] ?? true);
        $confirmedPatternMaxAgeMin = (int)($config['dbl_confirmed_pattern_max_age_before_handoff_minutes'] ?? 10);

        $confirmedAtRaw = (string)($signal['strategy_signal_context']['dbl_pattern_confirmed_at'] ?? ($signal['dbl_pattern_confirmed_at'] ?? ''));
        $confirmedTs = $confirmedAtRaw !== '' ? (int)strtotime($confirmedAtRaw) : 0;
        $confirmedAgeMin = $confirmedTs > 0 ? round(($nowTs - $confirmedTs) / 60, 1) : null;

        // Fallback: if no confirmed_at, use detected_at vs max_age_before_handoff.
        $ageForTtlCheck = $confirmedAgeMin ?? (is_numeric($detectedAgeMin) ? (float)$detectedAgeMin : null);
        $ttlMin = $confirmedTs > 0 ? $confirmedPatternTtlMin : $confirmedPatternMaxAgeMin;

        $point3Broken = (bool)($signal['strategy_signal_context']['point3_broken'] ?? false);
        $reclaimLevelLost = (bool)($signal['strategy_signal_context']['reclaim_level_lost'] ?? false);
        $freshLowerLow = (bool)($signal['strategy_signal_context']['fresh_lower_low_after_point3'] ?? false);

        $priceStillValid = !$point3Broken && !$reclaimLevelLost && !$freshLowerLow;
        $invalidReason = null;
        $valid = true;

        if ($ageForTtlCheck !== null && $ageForTtlCheck > $ttlMin) {
            $valid = false;
            $invalidReason = 'confirmed_pattern_ttl_expired';
        } elseif ($confirmedPatternRequireValid && !$priceStillValid) {
            $valid = false;
            if ($point3Broken) {
                $invalidReason = 'confirmed_pattern_point3_broken';
            } elseif ($reclaimLevelLost) {
                $invalidReason = 'confirmed_pattern_reclaim_lost';
            } else {
                $invalidReason = 'confirmed_pattern_price_invalidated';
            }
        }

        return [
            'confirmed_pattern_age_minutes'       => $confirmedAgeMin,
            'confirmed_pattern_ttl_minutes'       => $ttlMin,
            'confirmed_pattern_valid_for_handoff' => $valid,
            'confirmed_pattern_invalid_reason'    => $invalidReason,
            'confirmed_pattern_price_still_valid' => $priceStillValid,
            'confirmed_pattern_price_check_source'=> 'strategy_signal_context',
        ];
    }

    /**
     * Remove processed (non-active-pending) entries from pending_patterns.json and
     * append compact lifecycle rows to pending_patterns_history.ndjson.
     *
     * @param array<string,array<string,mixed>> $queueResult signal_id => queue entry
     * @return array<string,mixed>
     */
    private function cleanupDblPendingPatternsAfterHandoff(array $queueResult): array
    {
        $existing = (array)$this->readJson('storage/pending_patterns.json', []);
        if (empty($existing)) {
            return [
                'pending_cleanup_checked_total' => 0,
                'pending_cleanup_removed_confirmed_total' => 0,
                'pending_cleanup_removed_garbage_blocked_total' => 0,
                'pending_cleanup_removed_invalid_total' => 0,
                'pending_cleanup_removed_expired_total' => 0,
                'pending_cleanup_history_written_total' => 0,
                'pending_cleanup_examples' => [],
            ];
        }

        $queueBySignalId = [];
        $queueBySymbol = [];
        foreach ($queueResult as $qid => $qEntry) {
            if (!is_array($qEntry)) {
                continue;
            }
            $sigId = (string)($qEntry['signal_id'] ?? $qid);
            if ($sigId !== '') {
                $queueBySignalId[$sigId] = $qEntry;
            }
            $sym = strtolower((string)($qEntry['symbol'] ?? ''));
            if ($sym !== '') {
                $queueBySymbol[$sym] = $qEntry;
            }
        }

        $checked = 0;
        $removedConfirmed = 0;
        $removedGarbage = 0;
        $removedInvalid = 0;
        $removedExpired = 0;
        $historyWritten = 0;
        $examples = [];
        $kept = [];
        $historyRows = [];

        foreach ($existing as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $checked++;
            $sigId = (string)($entry['signal_id'] ?? '');
            $sym = strtolower((string)($entry['symbol'] ?? ''));
            $entryStatus = (string)($entry['dbl_pattern_status'] ?? 'active');
            $entryPendingReason = (string)($entry['pending_reason'] ?? '');

            $q = null;
            if ($sigId !== '' && isset($queueBySignalId[$sigId])) {
                $q = $queueBySignalId[$sigId];
            } elseif ($sym !== '' && isset($queueBySymbol[$sym])) {
                $q = $queueBySymbol[$sym];
            }

            $qStatus = (string)($q['handoff_status'] ?? '');
            $qBlockReason = (string)($q['block_reason'] ?? '');
            $qExecutable = (bool)($q['executable'] ?? false);
            $qReady = (bool)($q['handoff_ready'] ?? false);
            $qPatternStatus = (string)($q['strategy_signal_context']['dbl_pattern_status'] ?? ($q['dbl_pattern_status'] ?? ''));
            $isGarbageBlocked = $qBlockReason !== '' && str_starts_with($qBlockReason, 'garbage_');

            // Active pending list must never contain confirmed entries with pending_reason.
            $isConfirmedPendingEntry = $entryStatus === 'confirmed'
                || ($entryPendingReason !== '' && str_starts_with($entryPendingReason, 'waiting_') && $qPatternStatus === 'confirmed');

            $shouldRemove = $isConfirmedPendingEntry
                || in_array($qStatus, ['blocked', 'withdrawn', 'expired'], true)
                || $isGarbageBlocked
                || $qExecutable
                || $qReady
                || $qPatternStatus === 'confirmed'
                || $qPatternStatus === 'invalid';

            if (!$shouldRemove) {
                $kept[] = $entry;
                continue;
            }

            if ($isConfirmedPendingEntry || $qPatternStatus === 'confirmed') {
                $removedConfirmed++;
            }
            if ($isGarbageBlocked) {
                $removedGarbage++;
            }
            if ($qPatternStatus === 'invalid') {
                $removedInvalid++;
            }
            if ($qStatus === 'expired') {
                $removedExpired++;
            }

            $finalStatus = 'withdrawn';
            if ($qExecutable || $qReady) {
                $finalStatus = 'confirmed_handoff_ready';
            } elseif ($isGarbageBlocked) {
                $finalStatus = 'confirmed_blocked_by_garbage';
            } elseif ($qPatternStatus === 'invalid') {
                $finalStatus = 'invalid';
            } elseif ($qStatus === 'expired') {
                $finalStatus = 'expired';
            } elseif ($qStatus === 'withdrawn') {
                $finalStatus = 'withdrawn';
            }

            $historyRows[] = [
                'signal_id'      => $sigId !== '' ? $sigId : ($entry['signal_id'] ?? null),
                'symbol'         => $entry['symbol'] ?? ($q['symbol'] ?? null),
                'processed_at'   => date('c'),
                'final_status'   => $finalStatus,
                'handoff_status' => $qStatus !== '' ? $qStatus : ($q['handoff_status'] ?? null),
                'block_reason'   => $qBlockReason !== '' ? $qBlockReason : ($q['block_reason'] ?? null),
                'dbl_pattern_status' => $qPatternStatus !== '' ? $qPatternStatus : $entryStatus,
                'pending_reason' => $entryPendingReason !== '' ? $entryPendingReason : null,
            ];
            $historyWritten++;

            if (count($examples) < 10) {
                $examples[] = [
                    'signal_id'      => $sigId !== '' ? $sigId : null,
                    'symbol'         => $entry['symbol'] ?? null,
                    'entry_status'   => $entryStatus,
                    'queue_status'   => $qStatus !== '' ? $qStatus : null,
                    'queue_pattern_status' => $qPatternStatus !== '' ? $qPatternStatus : null,
                    'block_reason'   => $qBlockReason !== '' ? $qBlockReason : null,
                    'final_status'   => $finalStatus,
                    'removed'        => true,
                ];
            }
        }

        $this->writeJson('storage/pending_patterns.json', array_values($kept));
        if (!empty($historyRows)) {
            $historyPath = $this->moduleDir . '/storage/pending_patterns_history.ndjson';
            $blob = '';
            foreach ($historyRows as $row) {
                $blob .= json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
            }
            @file_put_contents($historyPath, $blob, FILE_APPEND | LOCK_EX);
        }

        return [
            'pending_cleanup_checked_total' => $checked,
            'pending_cleanup_removed_confirmed_total' => $removedConfirmed,
            'pending_cleanup_removed_garbage_blocked_total' => $removedGarbage,
            'pending_cleanup_removed_invalid_total' => $removedInvalid,
            'pending_cleanup_removed_expired_total' => $removedExpired,
            'pending_cleanup_history_written_total' => $historyWritten,
            'pending_cleanup_examples' => $examples,
        ];
    }

    /**
     * Sweep storage/pending_patterns.json on every tick:
     * – remove expired entries (TTL exceeded)
     * – re-evaluate active entries using parser2 + state machine
     * – mark confirmed entries (they will advance on next tick via normal flow)
     * – remove invalid entries (they are cleaned up from storage)
     * – update $this sweep counters
     */
    private function sweepDblPendingPatternsStorage(array $config): void
    {
        if (!(bool)($config['dbl_pattern_pending_enabled'] ?? true) ||
            !(bool)($config['dbl_pattern_pending_recheck_enabled'] ?? true)
        ) {
            return;
        }

        $existing   = (array)$this->readJson('storage/pending_patterns.json', []);
        $nowTs      = time();
        $ttlMin     = (int)($config['dbl_pattern_pending_ttl_minutes'] ?? 10);
        $maxItems   = max(1, (int)($config['dbl_pattern_pending_max_items'] ?? 100));

        $storageBefore     = count($existing);
        $staleRemoved      = 0;
        $sweepTotal        = 0;
        $sweepConfirmed    = 0;
        $sweepInvalid      = 0;
        $sweepExpired      = 0;
        $sweepStillActive  = 0;
        $kept              = [];

        foreach ($existing as $entry) {
            if (!is_array($entry)) {
                $staleRemoved++;
                continue;
            }
            $entryStatus = (string)($entry['dbl_pattern_status'] ?? 'active');

            // Remove already-finalized (non-active) entries from previous runs
            if ($entryStatus !== 'active') {
                $staleRemoved++;
                continue;
            }

            $sweepTotal++;

            $symbol     = (string)($entry['symbol']      ?? '');
            $detectedAt = (string)($entry['detected_at'] ?? '');
            $expiresAt  = (string)($entry['expires_at']  ?? '');
            $expireTs   = $expiresAt !== '' ? (int)strtotime($expiresAt) : 0;
            $detectedTs = $detectedAt !== '' ? (int)strtotime($detectedAt) : 0;

            // TTL check
            if (($ttlMin > 0 && $detectedTs > 0 && ($nowTs - $detectedTs) > ($ttlMin * 60)) ||
                ($expireTs > 0 && $nowTs > $expireTs)
            ) {
                $sweepExpired++;
                $sweepInvalid++;
                continue; // drop expired
            }

            // Build context from stored fields
            $necklineLevel = (float)($entry['neckline_level'] ?? 0.0);
            $reclaimLevel  = (float)($entry['reclaim_level']  ?? 0.0);
            $point3Price   = (float)($entry['point_3_second_low_price'] ?? 0.0);

            $ctx = [
                'point_1_low_price'           => $entry['point_1_low_price']   ?? null,
                'point_1_low_time'            => $entry['point_1_low_time']    ?? null,
                'point_2_neckline_price'      => $entry['point_2_neckline_price'] ?? null,
                'point_2_neckline_time'       => $entry['point_2_neckline_time']  ?? null,
                'point_3_second_low_price'    => $point3Price > 0.0 ? $point3Price : null,
                'point_3_second_low_time'     => $entry['point_3_second_low_time'] ?? null,
                'neckline_level'              => $necklineLevel > 0.0 ? $necklineLevel : null,
                'reclaim_level'               => $reclaimLevel > 0.0 ? $reclaimLevel : null,
            ];

            // Enrich context with parser2 confirmation data
            if ($symbol !== '' && ($necklineLevel > 0.0 || $reclaimLevel > 0.0)) {
                $computed = $this->computeDblConfirmationFromParser2(
                    $symbol,
                    $necklineLevel,
                    $reclaimLevel,
                    $point3Price,
                    $detectedTs > 0 ? $detectedTs : ($nowTs - 600),
                    $config
                );
                if ($computed['ok'] ?? false) {
                    $ctx['neckline_closes_above_count']   = $computed['neckline_closes_above_count'];
                    $ctx['reclaim_closes_above_count']    = $computed['reclaim_closes_above_count'];
                    $ctx['reclaim_confirmed']             = $computed['reclaim_confirmed'];
                    $ctx['neckline_reclaim_confirmed']    = $computed['neckline_reclaim_confirmed'];
                    $ctx['reclaim_hold_bars']             = $computed['reclaim_hold_bars'];
                    $ctx['reclaim_hold_minutes']          = $computed['reclaim_hold_minutes'];
                    $ctx['reclaim_retest_held']           = $computed['reclaim_retest_held'];
                    $ctx['higher_low_after_point3']       = $computed['higher_low_after_point3'];
                    $ctx['higher_low_after_point3_price'] = $computed['higher_low_after_point3_price'];
                    $ctx['fresh_lower_low_after_point3']  = $computed['fresh_lower_low_after_point3'];
                    $ctx['point3_broken']                 = $computed['point3_broken'];
                    $ctx['reclaim_level_lost']            = $computed['reclaim_level_lost'];
                    $ctx['weak_bounce_after_point3']      = $computed['weak_bounce_after_point3'];
                    $ctx['dbl_pattern_confirmation_source']         = 'parser2';
                    $ctx['dbl_pattern_confirmation_candles_loaded'] = $computed['candles_loaded'];
                    $ctx['dbl_pattern_confirmation_window_minutes'] = $computed['window_minutes'];
                }
            }

            // Fake record for state machine (signal-like shape)
            $fakeRecord = [
                'detected_at'             => $detectedAt,
                'candidate_quality_score' => $entry['candidate_quality_score'] ?? 0.0,
                'entry_price'             => $entry['last_price'] ?? null,
            ];

            $prepSweep = $this->prepareDblPatternStateContextWithTrace(
                $symbol,
                $detectedAt,
                $fakeRecord,
                $ctx,
                $config
            );
            $ctx = $prepSweep['context'];
            $ps  = $this->applyDblPatternStatusStateMachine($fakeRecord, $ctx, $config);
            $newStatus = (string)($ps['status'] ?? 'raw_candidate');

            $entry['last_checked_at']                      = date('c');
            $entry['dbl_pattern_confirmation_source']      = $ctx['dbl_pattern_confirmation_source'] ?? 'fallback';
            $entry['dbl_pattern_confirmation_candles_loaded'] = $ctx['dbl_pattern_confirmation_candles_loaded'] ?? 0;

            if ($newStatus === 'confirmed') {
                $sweepConfirmed++;
                $entry['dbl_pattern_status']       = 'confirmed';
                $entry['dbl_pattern_confirmed_at'] = date('c');
                $kept[] = $entry; // kept so next-tick handoff can see it
            } elseif ($newStatus === 'invalid') {
                $sweepInvalid++;
                $entry['dbl_pattern_status']         = 'invalid';
                $entry['dbl_pattern_invalidated_at'] = date('c');
                $entry['dbl_pattern_invalid_reason'] = $ps['invalid_reason'] ?? 'unknown';
                // Drop invalid entries from active pending storage
            } else {
                $sweepStillActive++;
                $kept[] = $entry;
            }
        }

        // Cap storage
        if (count($kept) > $maxItems) {
            $kept = array_slice($kept, -$maxItems);
        }

        $storageAfter = count($kept);

        // Persist updated storage
        $path = $this->moduleDir . '/storage/pending_patterns.json';
        @file_put_contents(
            $path,
            json_encode(array_values($kept), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        // Update instance counters
        $this->dblPatternPendingSweepTotal               += $sweepTotal;
        $this->dblPatternPendingSweepConfirmedTotal      += $sweepConfirmed;
        $this->dblPatternPendingSweepInvalidTotal        += $sweepInvalid;
        $this->dblPatternPendingSweepExpiredTotal        += $sweepExpired;
        $this->dblPatternPendingSweepStillActiveTotal    += $sweepStillActive;
        $this->dblPatternPendingStorageBeforeTotal       += $storageBefore;
        $this->dblPatternPendingStorageAfterTotal        += $storageAfter;
        $this->dblPatternPendingStorageStaleRemovedTotal += $staleRemoved;
    }

    /**
     * Remove or mark an entry in pending_patterns.json as invalid so that
     * an entry blocked in the handoff queue doesn't stay active in pending storage.
     */
    private function removeDblPendingPatternEntry(string $signalId): void
    {
        if ($signalId === '') {
            return;
        }
        $existing = (array)$this->readJson('storage/pending_patterns.json', []);
        $kept     = [];
        $changed  = false;
        foreach ($existing as $e) {
            if (!is_array($e)) {
                continue;
            }
            if ((string)($e['signal_id'] ?? '') === $signalId) {
                $changed = true;
                // Drop from active pending storage
                continue;
            }
            $kept[] = $e;
        }
        if ($changed) {
            $path = $this->moduleDir . '/storage/pending_patterns.json';
            @file_put_contents(
                $path,
                json_encode(array_values($kept), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
        }
    }
}
