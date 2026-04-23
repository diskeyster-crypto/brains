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

        $prevState = $this->getRunState();
        $universe = $this->buildUniverse($config);

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

        $cycleId = (int)($prevState['cycle_id'] ?? 0);
        $nowIso  = date('c');
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
            'registry_diag' => $this->readJson('storage/registry_diag.json', []),
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

        // Auto-queue on first cron tick when the module is enabled with continuous scan.
        if ($status === 'idle') {
            $cfg = $this->getConfig();
            if ((bool)($cfg['enabled'] ?? false) && (bool)($cfg['continuous_scan_enabled'] ?? true)) {
                $qResult = $this->queueRun();
                if (!($qResult['ok'] ?? false)) {
                    return;
                }
                $state  = $this->getRunState();
                $status = $state['status'] ?? 'idle';
            }
            if ($status === 'idle') {
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
                // Auto-restart: reset cursor, keep symbols list, stay running
                $state['status']           = 'running';
                $state['cursor']           = 0;
                $state['processed']        = 0;
                $state['found']            = 0;
                $state['cycle_started_at'] = date('c');
                $state['next_cycle_ready'] = false;
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
            'status'            => $isDone && !$continuousEnabled ? 'done' : 'running',
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
            // Stop — fixed_from_liq_zone model
            'stop_mode'                  => $config['stop_mode']                    ?? 'fixed_from_liq_zone',
            'stop_from_liq_buffer_value' => $config['stop_from_liq_buffer_value']   ?? 0.002,
            'stop_from_liq_buffer_type'  => $config['stop_from_liq_buffer_type']    ?? 'percent',
            'bot_budget'                 => $config['bot_budget']                   ?? 0.0,
            'bot_leverage'               => $config['bot_leverage']                 ?? 1,
            // Exit (strategy-owned; no trailing in this module)
            'reverse_pattern_close_enabled' => $config['reverse_pattern_close_enabled'] ?? false,
            'tp_enabled'                    => $config['tp_enabled']                    ?? false,
            'tp_mode'                       => $config['tp_mode']                      ?? 'fixed_r',
            'tp_value'                      => $config['tp_value']                     ?? 2.0,
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

        foreach ($sample as $sym) {
            try {
                $candles = $this->fetchCandles($sym, array_merge($config, ['lookback_candles' => 20]));
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

        $diagBase = [
            'market_regime'       => $regimeStr,
            'trend_direction'     => $trendDir,
            'corridor_low'        => $corridor['corridor_low'],
            'corridor_high'       => $corridor['corridor_high'],
            'current_bucket'      => $corridor['current_bucket'],
            'bucket_allowed_long' => $corridor['bucket_allowed_long'],
            'wave_direction'      => $wave['wave_direction'],
            'wave_state'          => $wave['wave_state'],
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

        if ((bool)($config['trend_required'] ?? true)) {
            $this->requireLogic('trend');
            $tGate = (new \Modules\Strategy\DoubleBottomLong\Logic\PatternTrend())->gate($trendDir, $side);
            if (!$tGate['pass']) {
                return $this->reject($diagBase, $symbol, 'double_bottom', $tGate['reason']);
            }
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
            date('c')
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
        $allowedLong      = (array)($config['allowed_long_buckets']        ?? [1, 2, 3]);
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

    private function buildUniverse(array $config): array
    {
        $mode     = (string)($config['universe_mode']    ?? 'all');
        $excluded = (array)($config['excluded_symbols']  ?? []);
        $maxCount = (int)($config['max_symbols_per_run'] ?? 0);

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
        $symbols = array_values($symbols);

        if ($maxCount > 0 && count($symbols) > $maxCount) {
            $symbols = array_slice($symbols, 0, $maxCount);
        }

        return $symbols;
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

            // Execution parameters (strategy-owned; no exchange-order fields yet)
            'stop_mode'                     => (string)($config['stop_mode']                     ?? 'fixed_from_liq_zone'),
            'stop_from_liq_buffer_value'    => (float)($config['stop_from_liq_buffer_value']    ?? 0.002),
            'stop_from_liq_buffer_type'     => (string)($config['stop_from_liq_buffer_type']    ?? 'percent'),
            'bot_budget'                    => (float)($config['bot_budget']                    ?? 0.0),
            'bot_leverage'                  => (int)($config['bot_leverage']                    ?? 1),
            'tp_enabled'                    => (bool)($config['tp_enabled']                     ?? false),
            'tp_mode'                       => (string)($config['tp_mode']                      ?? 'fixed_r'),
            'tp_value'                      => (float)($config['tp_value']                      ?? 2.0),
            'reverse_pattern_close_enabled' => (bool)($config['reverse_pattern_close_enabled']  ?? false),
        ];
    }
}
