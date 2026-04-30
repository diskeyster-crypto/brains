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

    private const H4_INTERVAL = '240';  // Bybit kline interval

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

        // Pre-initialise fields that are only assigned inside the $isDone block
        // so they are always defined when used in the last_run.json write below.
        $completedCycleId = null;
        $finishedAt       = null;

        while ($cursor < $total && $processed < $batchSz && (time() - $tStart) < $maxSec) {
            $symbol = $symbols[$cursor];
            $cursor++;
            $processed++;

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
                    $newlyEmitted[] = $result['signal'];
                    $emittedCandidates = $this->mergeCandidateRecord(
                        $emittedCandidates,
                        $this->buildEmittedCandidateRecord($result, (int)($state['cycle_id'] ?? 0), $tickAt)
                    );
                }
                $stats      = $this->accumulateStats($stats,      $result);
                $cycleStats = $this->accumulateStats($cycleStats, $result);

                $rejectR       = $result['reject_reason']     ?? null;
                $neckDistStatus = 'n/a';
                if ($rejectR === 'price_too_far_above_neckline') { $neckDistStatus = 'too_far_above'; }
                elseif ($rejectR === 'price_too_far_below_neckline') { $neckDistStatus = 'too_far_below'; }
                elseif ($result['candidate_found'] ?? false) { $neckDistStatus = 'ok'; }

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
                    'signal_id'               => $result['signal_id']              ?? null,
                    'winner_selected'         => null,
                    'winner_reject_reason'    => null,
                    'final_reject_reason'     => null,
                ];
            } catch (\Throwable $e) {
                $state['errors'][] = $symbol . ': ' . $e->getMessage();
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

            // Append a compact record to cycle_history.ndjson for operator audit trail.
            $cycleHistoryRecord = json_encode(array_merge($lastCycleSummary, [
                'reject_reason_distribution'       => $cycleStats['reject_reason_distribution'] ?? (object)[],
                'final_reject_reason_distribution' => $cycleStats['final_reject_reason_distribution'] ?? (object)[],
            ])) . "\n";
            @file_put_contents(
                $this->moduleDir . '/storage/cycle_history.ndjson',
                $cycleHistoryRecord,
                FILE_APPEND | LOCK_EX
            );

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
            ($isDone && $continuousEnabled) ? $total : max(0, $total - $cursor)
        );
        $state['run_status']              = $state['status'];
        $state['processed_symbols']       = $totalProcessed;
        $state['total_symbols']           = $total;
        $state['remaining_symbols']       = ($isDone && $continuousEnabled) ? $total : max(0, $total - $cursor);
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
        // active_pool_signals_total = size of the current rolling winner pool (signals.json)
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

        // Refresh bot handoff queue with the current active-pool winner signals.
        $handoffStats = $this->updateBotHandoff($signals, $config);

        // Update state with real handoff counters before writing run_state.json.
        $state['bot_handoff_ready_total']     = $handoffStats['ready_total'];
        $state['bot_handoff_new_total']       = $handoffStats['new_total'];
        $state['bot_handoff_refreshed_total'] = $handoffStats['refreshed_total'];
        $state['bot_handoff_expired_total']   = $handoffStats['expired_total'];

        $this->writeJson('storage/run_state.json', $state);

        $stats      = $this->finalizeStats($stats,      $total, $totalProcessed, $batchSz, count($signals));
        $cycleStats = $this->finalizeStats($cycleStats, $total, $totalProcessed, $batchSz, count($signals));
        $this->writeJson('storage/stats.json',       $stats);
        $this->writeJson('storage/cycle_stats.json', $cycleStats);

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
            'finished_at'       => ($isDone && !$continuousEnabled) ? $finishedAt : null,
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
            'signals_active_final_total' => count($signals),
            // Explicit semantic separation: cycle-local vs active pool vs handoff
            'current_cycle_signals_emitted_total' => (int)($cycleStats['signals_emitted_total'] ?? 0),
            'current_cycle_final_signals_total'   => $cycleNewWinnerCount,
            'active_pool_signals_total'           => count($signals),
            'bot_handoff_ready_total'     => $handoffStats['ready_total'],
            'bot_handoff_new_total'       => $handoffStats['new_total'],
            'bot_handoff_refreshed_total' => $handoffStats['refreshed_total'],
            'bot_handoff_expired_total'   => $handoffStats['expired_total'],
            'final_signals_total'        => count($signals),
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
                'signals_active_final_total' => count($signals),
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

        $this->requireLogic('trend');
        $trend    = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternTrend())->analyse($candles);
        $trendDir = $trend['trend_direction'];

        $this->requireLogic('corridor');
        $lastClose = (float)(end($candles)['close'] ?? 0.0);
        $corridor  = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternCorridor())->compute($candles, $lastClose, $config);

        $this->requireLogic('wave');
        $wave = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternWave())->analyse($candles);

        // ── Coin trend context ────────────────────────────────────────────────
        $coinCtx = $this->pipelineCoinTrendContext($candles, $config);

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
            'setup_context_type'             => $coinCtx['setup_context_type'],
            'bearish_reversal_exception_used'=> false,  // set by tryLong() if used
            'reversal_context_score'         => $coinCtx['reversal_context_score'],
            'entry_context_score'            => $coinCtx['entry_context_score'],
        ];

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
            'quality_reject_reason'   => null,
            'confirm_status'         => null,
            'confirm_bars_waited'    => 0,
            'candidate_expired'      => false,
            'final_signal_status'    => 'no_signal',
            'reject_reason'          => $longRej ?? 'no_valid_candidate',
            'signal'                 => null,
        ]);
    }

    private function tryLong(string $symbol, array $candles, array $config,
        string $regimeStr, string $trendDir, array $corridor, array $wave, array $diagBase): array
    {
        $side = 'long';

        // ── Active falling knife: hard reject before anything else ────────────
        if ((bool)($config['coin_trend_context_enabled'] ?? true)
            && (bool)($config['active_downtrend_block_enabled'] ?? true)
            && (bool)($diagBase['active_falling_knife_detected'] ?? false)
        ) {
            return $this->reject($diagBase, $symbol, 'double_bottom', 'active_falling_knife');
        }

        // ── Market regime gate ────────────────────────────────────────────────
        // Bearish regime is hard-blocked by default.
        // Exception: bearish_reversal_exception allows entry when all post-dump
        // stabilization conditions are met (post-dump → flat/base → reclaim).
        $bearishRevExUsed = false;
        if ((bool)($config['market_regime_enabled'] ?? true)) {
            $regimeGateMode = (string)($config['market_regime_gate_mode'] ?? 'soft');
            if ($regimeGateMode === 'hard') {
                $this->requireLogic('market_regime');
                $rGate = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternMarketRegime())
                    ->gate($regimeStr, $side, $regimeGateMode);
                if (!$rGate['pass']) {
                    // Check bearish reversal exception
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
                        return $this->reject($diagBase, $symbol, 'double_bottom', $rGate['reason']);
                    }
                }
            }
        }

        // Update diagBase with bearish_reversal_exception_used flag
        $diagBase['bearish_reversal_exception_used'] = $bearishRevExUsed;
        if ($bearishRevExUsed) {
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
                    return $this->reject($diagBase, $symbol, 'double_bottom',
                        "trend_{$trendDir}_side_long_mismatch");
                }
            } elseif (!$bearishRevExUsed) {
                $tGate = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternTrend())->gate($trendDir, $side);
                if (!$tGate['pass']) {
                    return $this->reject($diagBase, $symbol, 'double_bottom', $tGate['reason']);
                }
            }
            // When bearish_reversal_exception_used=true, trend gate is bypassed.
        }

        if ((bool)($config['corridor_required'] ?? true)) {
            $this->requireLogic('corridor');
            $cGate = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternCorridor())->gate($corridor, $side);
            if (!$cGate['pass']) {
                return $this->reject($diagBase, $symbol, 'double_bottom', $cGate['reason']);
            }
        }

        if ((bool)($config['wave_required'] ?? true)) {
            $this->requireLogic('wave');
            $wGate = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternWave())->gate($wave, $side);
            if (!$wGate['pass']) {
                return $this->reject($diagBase, $symbol, 'double_bottom', $wGate['reason']);
            }
        }

        $this->requireLogic('double_bottom');
        $candidate = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternDoubleBottom())->detect($candles, $config);
        if (!$candidate['candidate_found']) {
            return $this->reject($diagBase, $symbol, 'double_bottom', $candidate['reject_reason'] ?? 'no_double_bottom', true, [
                'neckline_value'       => $candidate['neckline']              ?? 0.0,
                'low1_value'           => $candidate['low1_price']            ?? 0.0,
                'low2_value'           => $candidate['low2_price']            ?? 0.0,
                'pattern_window_size'  => $candidate['window_size']           ?? 0,
                'similarity_delta_pct' => $candidate['similarity_delta_pct'] ?? 0.0,
            ]);
        }

        $this->requireLogic('candidate_quality');
        $quality = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternCandidateQuality())->score(
            $candidate, $wave, $config, $side
        );
        if (!$quality['quality_pass']) {
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
                    'signal_quality_class'    => $bearishRevExUsed ? 'controlled_reversal' : 'clean_signal',
                    'confirm_status'          => $confirm['confirm_status'],
                    'confirm_bars_waited'     => $confirm['confirm_bars_waited'],
                    'candidate_expired'       => $confirm['candidate_expired'],
                    'final_signal_status'     => 'confirm_pending',
                    'reject_reason'           => $confirm['reject_reason'],
                    'signal'                  => null,
                ]);
            }
        } else {
            $confirm = ['confirm_status' => 'confirm_pass', 'confirm_bar_close' => null, 'confirm_bars_waited' => 0];
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
            'signal_quality_class'    => $bearishRevExUsed ? 'controlled_reversal' : 'clean_signal',
            'confirm_status'          => 'confirm_pass',
            'confirm_bars_waited'     => $confirm['confirm_bars_waited'] ?? 0,
            'candidate_expired'       => false,
            'final_signal_status'     => 'emitted',
            'reject_reason'           => null,
            'signal_id'               => $signal['signal_id'],
            'signal'                  => $signal,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function reject(array $diag, string $symbol, string $pattern, ?string $reason, bool $patternChecked = false, array $extra = []): array
    {
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
        $finalRejectDist  = [];

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

            // 1b2. Stop-loss width guard: reject signals where the pattern-derived SL
            //      is null (lows were missing) or exceeds max_stop_loss_pct of entry.
            //      A null stop_loss means the SL was already computed to be > max inside
            //      PatternSignal::computeStopLoss() — both cases are a risk rejection.
            if ($maxStopLossPct > 0.0) {
                $slPct = isset($s['stop_loss_pct']) ? (float)$s['stop_loss_pct'] : null;
                if ($slPct === null || $slPct > $maxStopLossPct) {
                    $rejectedFinalLowNeckline++;
                    $signalOutcomeMap[$id] = ['winner' => false, 'reason' => 'final_stop_too_wide'];
                    $finalRejectDist['final_stop_too_wide'] = ($finalRejectDist['final_stop_too_wide'] ?? 0) + 1;
                    continue;
                }
            }

            // 1c. Trend consistency — long requires bullish context
            if ($trendRequired) {
                $trendDir = (string)($s['trend_direction'] ?? 'unknown');
                if (!in_array($trendDir, ['bullish', 'bearish', 'flat'], true)) {
                    $rejectedFinalTrend++;
                    $signalOutcomeMap[$id] = ['winner' => false, 'reason' => 'final_trend_mismatch'];
                    $finalRejectDist['final_trend_mismatch'] = ($finalRejectDist['final_trend_mismatch'] ?? 0) + 1;
                    continue;
                }
                if ($trendDir !== 'bullish') {
                    $rejectedFinalTrend++;
                    $signalOutcomeMap[$id] = ['winner' => false, 'reason' => 'final_trend_mismatch'];
                    $finalRejectDist['final_trend_mismatch'] = ($finalRejectDist['final_trend_mismatch'] ?? 0) + 1;
                    continue;
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
                $rejectedFinalContext++;
                $signalOutcomeMap[$id] = ['winner' => false, 'reason' => $contextRejectReason];
                $finalRejectDist[$contextRejectReason] = ($finalRejectDist[$contextRejectReason] ?? 0) + 1;
                continue;
            }

            // 1e. Final quality composite floor
            if ($minFinalQuality > 0.0 && (float)($s['candidate_quality_score'] ?? 0.0) < $minFinalQuality) {
                $rejectedFinalLowQuality++;
                $signalOutcomeMap[$id] = ['winner' => false, 'reason' => 'final_low_quality'];
                $finalRejectDist['final_low_quality'] = ($finalRejectDist['final_low_quality'] ?? 0) + 1;
                continue;
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
            'flat_base_detected_total'           => 0,
            'reclaim_after_flat_detected_total'  => 0,
            'bearish_reversal_exception_used_total'    => 0,
            'bearish_reversal_exception_blocked_total' => 0,
            'double_bottom_context_pass_total'   => 0,
            'double_bottom_context_reject_total' => 0,
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
     * NOTE: Only H4 candles are available (lookback_candles); mid/long lookbacks
     * are capped at the available candle count.
     */
    private function pipelineCoinTrendContext(array $candles, array $config): array
    {
        $enabled = (bool)($config['coin_trend_context_enabled'] ?? true);

        // Neutral context returned when the feature is disabled
        $neutral = static function (): array {
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
                'setup_context_type'             => 'standard',
                'reversal_context_score'         => 0.0,
                'entry_context_score'            => 10.0,
            ];
        };

        if (!$enabled || count($candles) < 5) {
            return $neutral();
        }

        $n = count($candles);

        $shortLb = min((int)($config['trend_lookback_short_candles'] ?? 60),  $n);
        $midLb   = min((int)($config['trend_lookback_mid_candles']   ?? 240), $n);
        $longLb  = min((int)($config['trend_lookback_long_candles']  ?? 720), $n);

        $shortSlice = array_slice($candles, $n - $shortLb);
        $midSlice   = array_slice($candles, $n - $midLb);
        $longSlice  = array_slice($candles, $n - $longLb);

        $shortSlopePct = $this->computeSlopePct($shortSlice);
        $midSlopePct   = $this->computeSlopePct($midSlice);
        $longSlopePct  = $this->computeSlopePct($longSlice);

        $recentLowerLowCount  = $this->countRecentLowerLows($shortSlice);
        $recentLowerHighCount = $this->countRecentLowerHighs($shortSlice);

        $recentDump15mPct = $this->estimateRecentDump($candles, 2);
        $recentDump1hPct  = $this->estimateRecentDump($candles, 4);

        [$distFromHighPct, $distFromLowPct] = $this->distanceFromRecentHighLow($candles, $shortLb);

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
            ? $this->detectPostDumpStabilization($candles, $config, $pdCtx)
            : ['post_dump_detected' => false, 'post_dump_drop_pct' => 0.0,
               'stabilization_detected' => false, 'stabilization_bars' => 0,
               'stabilization_range_width_pct' => 0.0, 'stabilization_slope_pct' => 0.0,
               'low_hold_score' => 0.0, 'stabilization_score' => 0.0,
               'stabilization_reason' => 'disabled', 'stabilization_warnings' => []];

        $flatBaseResult = (bool)($config['flat_base_enabled'] ?? true)
            ? $this->detectFlatBaseAfterDump($candles, $config, $postDumpResult)
            : ['flat_base_detected' => false, 'flat_base_low' => null, 'flat_base_high' => null,
               'flat_base_mid' => null, 'flat_base_width_pct' => 0.0, 'flat_base_touches' => 0,
               'flat_base_score' => 0.0, 'flat_base_reason' => 'disabled'];

        $reclaimResult = (bool)($config['reclaim_after_flat_required'] ?? true)
            ? $this->detectReclaimAfterFlatBase($candles, $config, $flatBaseResult)
            : ['reclaim_after_flat_detected' => false, 'reclaim_level' => null,
               'reclaim_confirmed_bars' => 0, 'reclaim_strength_pct' => 0.0,
               'reclaim_score' => 0.0, 'reclaim_reason' => 'disabled'];

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
            'setup_context_type'             => $setupContextType,
            'reversal_context_score'         => round($reversalScore, 2),
            'entry_context_score'            => round($entryContextScore, 2),
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
            'reclaim_score' => 0.0, 'reclaim_reason' => 'no_flat_base',
        ];

        if (!(bool)($flatBaseResult['flat_base_detected'] ?? false)) {
            return $empty;
        }

        $fbHigh = (float)($flatBaseResult['flat_base_high'] ?? 0.0);
        if ($fbHigh <= 0.0) {
            return array_merge($empty, ['reclaim_reason' => 'invalid_base_high']);
        }

        $minAbove    = (float)($config['reclaim_min_close_above_base_pct'] ?? 0.4);
        $confirmBars = (int)($config['reclaim_confirm_bars']                ?? 2);
        $n           = count($candles);

        // Look at the last confirm_bars closes
        $reclaimWindow = array_slice($candles, max(0, $n - max($confirmBars, 5)));
        $confirmedCount = 0;
        $lastClose      = 0.0;

        foreach ($reclaimWindow as $c) {
            $close = (float)($c['close'] ?? 0.0);
            if ($close > 0.0) {
                $lastClose = $close;
            }
            if ($close > $fbHigh * (1.0 + $minAbove / 100.0)) {
                $confirmedCount++;
            }
        }

        $reclaimStrength = ($fbHigh > 0.0 && $lastClose > 0.0)
            ? (($lastClose - $fbHigh) / $fbHigh) * 100.0
            : 0.0;

        if ($confirmedCount < $confirmBars) {
            return array_merge($empty, [
                'reclaim_level'          => round($fbHigh, 6),
                'reclaim_confirmed_bars' => $confirmedCount,
                'reclaim_strength_pct'   => round($reclaimStrength, 4),
                'reclaim_reason'         => 'reclaim_after_flat_not_confirmed',
            ]);
        }

        // Check not too far extended above reclaim
        $maxExtension = 5.0;  // allow up to 5% above reclaim level
        if ($reclaimStrength > $maxExtension) {
            return array_merge($empty, [
                'reclaim_level'          => round($fbHigh, 6),
                'reclaim_confirmed_bars' => $confirmedCount,
                'reclaim_strength_pct'   => round($reclaimStrength, 4),
                'reclaim_reason'         => 'entry_too_far_after_reclaim',
            ]);
        }

        if ($reclaimStrength < 0.0) {
            return array_merge($empty, [
                'reclaim_level'          => round($fbHigh, 6),
                'reclaim_confirmed_bars' => $confirmedCount,
                'reclaim_strength_pct'   => round($reclaimStrength, 4),
                'reclaim_reason'         => 'reclaim_too_weak_after_flat',
            ]);
        }

        $reclaimScore = 2.0
            + min(4.0, $confirmedCount * 1.0)
            + min(4.0, max(0.0, $reclaimStrength));

        return [
            'reclaim_after_flat_detected' => true,
            'reclaim_level'               => round($fbHigh, 6),
            'reclaim_confirmed_bars'      => $confirmedCount,
            'reclaim_strength_pct'        => round($reclaimStrength, 4),
            'reclaim_score'               => min(10.0, $reclaimScore),
            'reclaim_reason'              => 'reclaim_ok',
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

    private function requireBootstrap(): void
    {
        require_once $this->moduleDir . '/bootstrap.php';
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
     */
    private function updateBotHandoff(array $activeSignals, array $config): array
    {
        $existing = (array)$this->readJson('storage/bot_handoff_queue.json', []);

        $existingMap = [];
        foreach ($existing as $r) {
            $id = (string)($r['signal_id'] ?? '');
            if ($id !== '') {
                $existingMap[$id] = $r;
            }
        }

        $activeIds      = [];
        $newTotal       = 0;
        $refreshedTotal = 0;
        $expiredTotal   = 0;
        $result         = [];

        // Process currently-active signals: new or refreshed
        foreach ($activeSignals as $signal) {
            $id = (string)($signal['signal_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $activeIds[$id] = true;
            $record = $this->buildBotHandoffRecord($signal, $config);

            if (isset($existingMap[$id])) {
                $prev = $existingMap[$id];
                $record['detected_at']   = $prev['detected_at']   ?? $record['detected_at'];
                $record['first_seen_at'] = $prev['first_seen_at'] ?? ($prev['detected_at'] ?? $record['detected_at']);
                $record['seen_count']    = (int)($prev['seen_count'] ?? 0) + 1;
                $record['handoff_status'] = 'refreshed';
                $refreshedTotal++;
            } else {
                $record['first_seen_at'] = $record['detected_at'];
                $record['seen_count']    = 1;
                $record['handoff_status'] = 'new';
                $newTotal++;
            }

            $record['last_refreshed_at'] = date('c');
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
                // Keep already-finalised records for audit trail
                $result[$id] = $prev;
                continue;
            }
            // Determine exit cause: TTL elapsed → expired, otherwise → withdrawn
            $detectedAt = $prev['detected_at'] ?? '';
            $ts = $detectedAt !== '' ? strtotime($detectedAt) : 0;
            $status = ($ts > 0 && (time() - $ts) > $ttlSec) ? 'expired' : 'withdrawn';
            $prev['handoff_status'] = $status;
            $prev['withdrawn_at']   = date('c');
            $result[$id] = $prev;
            $expiredTotal++;
        }

        $this->writeJson('storage/bot_handoff_queue.json', array_values($result));

        $readyTotal = 0;
        foreach ($result as $r) {
            if (in_array($r['handoff_status'] ?? '', ['new', 'refreshed'], true)) {
                $readyTotal++;
            }
        }

        return [
            'ready_total'     => $readyTotal,
            'new_total'       => $newTotal,
            'refreshed_total' => $refreshedTotal,
            'expired_total'   => $expiredTotal,
        ];
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
            'detected_at'  => $detectedAt,
            'expires_at'   => $expiresAt,
            'handoff_status' => 'active',

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
            'entry_context_score'             => (float)($signal['entry_context_score']             ?? 0.0),
            'bearish_reversal_exception_used' => (bool)($signal['bearish_reversal_exception_used'] ?? false),
            'setup_context_type'              => (string)($signal['setup_context_type']             ?? 'standard'),
            'signal_quality_class'            => (string)($signal['signal_quality_class']           ?? 'clean_signal'),

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
}
