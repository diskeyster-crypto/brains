<?php

declare(strict_types=1);

/**
 * Pattern Strategy — Service
 *
 * Orchestrates the pattern pipeline:
 *   market_regime → trend → corridor → bucket → wave → pattern → control_check → signal
 *
 * ── Synchronous path (manual_list) ───────────────────────────────────────────
 *   run()        Full scan in one call.
 *
 * ── Batched path (all-universe) ──────────────────────────────────────────────
 *   queueRun()   Write run_state.json with status=queued and return immediately.
 *   tickBatch()  Process one batch (batch_size symbols).  Called by cron.
 *
 * No bot execution is wired in this foundation version.
 */

namespace Modules\Strategy\Pattern;

final class PatternService
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
                \Core\System\SystemPaths::instance()->get('strategy.pattern'),
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
            return PatternBootstrap::instance($this->moduleDir)->load()['config'];
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
     * Also seeds empty storage files and computes a fast market regime preview
     * so the dashboard shows something useful immediately.
     */
    public function queueRun(): array
    {
        $config = $this->getConfig();
        if (empty($config)) {
            return ['ok' => false, 'error' => 'Config load failed'];
        }

        $universe = $this->buildUniverse($config);

        // Always reset stats for clean per-run accounting so that final-eligibility
        // counters (before/after/rejected_*) are semantically truthful within each run
        // and never carry stale values from a previous scan cycle.
        $zeroStats = $this->zeroStats();
        $this->writeJson('storage/stats.json', $zeroStats);
        // Seed other storage files only when they don't exist yet
        foreach ([
            'signals.json'  => [],
            'last_run.json' => ['status' => 'queued', 'started_at' => date('c')],
        ] as $f => $v) {
            if (!file_exists($this->moduleDir . '/storage/' . $f)) {
                $this->writeJson('storage/' . $f, $v);
            }
        }
        // Seed market_regime.json if missing OR if the file exists but is empty / invalid JSON
        $regimePath = $this->moduleDir . '/storage/market_regime.json';
        $regimeRaw  = file_exists($regimePath) ? (string)@file_get_contents($regimePath) : '';
        if (empty(json_decode($regimeRaw, true))) {
            $this->writeJson('storage/market_regime.json', ['regime' => 'unknown', 'ts' => date('c')]);
        }
        if (!file_exists($this->moduleDir . '/storage/market_regime_history.ndjson')) {
            file_put_contents($this->moduleDir . '/storage/market_regime_history.ndjson', '');
        }

        $state = [
            'status'              => 'queued',
            'queued_at'           => date('c'),
            'symbols'             => $universe,
            'total'               => count($universe),
            'cursor'              => 0,
            'processed'           => 0,
            'found'               => 0,
            'errors'              => [],
            'registry_diag'       => $this->readJson('storage/registry_diag.json', []),
        ];
        $this->writeJson('storage/run_state.json', $state);
        return ['ok' => true, 'total' => count($universe)];
    }

    /**
     * CronManager entry-point: advance one batch.
     */
    public function tickBatch(): void
    {
        $state = $this->getRunState();
        $status = $state['status'] ?? 'idle';

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

        $symbols  = (array)($state['symbols']   ?? []);
        $cursor   = (int)($state['cursor']       ?? 0);
        $total    = count($symbols);
        $tStart   = time();

        // Merge previous stats on top of a zero skeleton so all keys are present
        $stats    = array_merge($this->zeroStats(), $this->getStats());
        $signals  = $this->getSignals();

        // Registry diagnostics from the queueRun() pass
        $regDiag = (array)($state['registry_diag'] ?? []);
        $stats['registry_loaded']       = (bool)($regDiag['registry_loaded']       ?? false);
        $stats['registry_symbol_count'] = (int)($regDiag['registry_symbol_count']  ?? 0);

        // ── Market Regime ────────────────────────────────────────────────────
        // Compute regime on first batch tick (cursor === 0) or if not yet set.
        $regimeEnabled = (bool)($config['market_regime_enabled'] ?? true);
        $prevRegimeData = $this->readJson('storage/market_regime.json', []);
        // Seed market_regime.json if empty — can happen if the file existed but was blank
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

        $processed = 0;
        $found     = 0;
        $batchPreviewRows = [];
        $newlyEmitted     = [];  // signals emitted this batch tick, before filtering

        while ($cursor < $total && $processed < $batchSz && (time() - $tStart) < $maxSec) {
            $symbol = $symbols[$cursor];
            $cursor++;
            $processed++;

            try {
                $result = $this->processSymbol($symbol, $config, $regimeStr);
                if ($result['final_signal_status'] === 'emitted') {
                    $newlyEmitted[] = $result['signal'];
                    $found++;
                }
                $stats = $this->accumulateStats($stats, $result);

                // Derive neckline_distance_status from reject reason for the preview row
                $rejectR = $result['reject_reason'] ?? null;
                $longRej = $result['long_reject_reason'] ?? null;
                $shortRej = $result['short_reject_reason'] ?? null;
                $neckDistStatus = 'n/a';
                foreach ([$rejectR, $longRej, $shortRej] as $r) {
                    if ($r === 'price_too_far_above_neckline') { $neckDistStatus = 'too_far_above'; break; }
                    if ($r === 'price_too_far_below_neckline') { $neckDistStatus = 'too_far_below'; break; }
                }
                if ($neckDistStatus === 'n/a' && ($result['candidate_found'] ?? false)) {
                    $neckDistStatus = 'ok';
                }

                // Derive final_stage_reached from what happened in the pipeline
                $fss = $result['final_signal_status'] ?? '';
                $finalStage = match (true) {
                    $fss === 'emitted'                         => 'signal',
                    $fss === 'confirm_pending'                 => 'confirm',
                    ($result['candidate_found'] ?? false)      => 'quality',
                    ($result['double_bottom_checked'] ?? false)
                        || ($result['double_top_checked'] ?? false) => 'pattern',
                    (($result['wave_state'] ?? 'unknown') !== 'unknown')
                        && str_starts_with($rejectR ?? '', 'wave_')    => 'wave',
                    ($result['current_bucket'] ?? 0) > 0
                        && str_starts_with($rejectR ?? '', 'bucket_')  => 'bucket',
                    str_starts_with($rejectR ?? '', 'trend_')          => 'trend',
                    default                                            => 'pre_trend',
                };

                // side_allowed: was the side gate passed? (trend gate passes any known trend now)
                $trendDir = $result['trend_direction'] ?? 'unknown';
                $sideAllowed = in_array($trendDir, ['bullish', 'bearish', 'flat'], true);

                // bucket_allowed: true if price is in an allowed zone for the attempted side
                $bucketAllowedLong  = (bool)($result['bucket_allowed_long']  ?? false);
                $bucketAllowedShort = (bool)($result['bucket_allowed_short'] ?? false);
                $candSide = $result['candidate_side'] ?? null;
                $bucketAllowed = $candSide === 'long'  ? $bucketAllowedLong
                               : ($candSide === 'short' ? $bucketAllowedShort
                               : ($bucketAllowedLong || $bucketAllowedShort));

                // Collect pipeline preview row for admin UI
                $batchPreviewRows[] = [
                    'symbol'                  => $symbol,
                    'side'                    => $result['candidate_side']          ?? null,
                    'primary_pattern'         => $result['primary_pattern']         ?? null,
                    'market_regime'           => $result['market_regime']          ?? null,
                    'trend_direction'         => $result['trend_direction']        ?? null,
                    'side_allowed'            => $sideAllowed,
                    'trend_pass'              => in_array($result['trend_direction'] ?? '', ['bullish', 'bearish', 'flat'], true),
                    'corridor_low'            => $result['corridor_low']           ?? null,
                    'corridor_high'           => $result['corridor_high']          ?? null,
                    'corridor_bucket'         => $result['current_bucket']         ?? null,
                    'bucket_allowed_long'     => $bucketAllowedLong,
                    'bucket_allowed_short'    => $bucketAllowedShort,
                    'bucket_allowed'          => $bucketAllowed,
                    'wave_direction'          => $result['wave_direction']         ?? null,
                    'wave_state'              => $result['wave_state']             ?? null,
                    'double_bottom_checked'   => $result['double_bottom_checked']  ?? false,
                    'double_bottom_found'     => ($result['candidate_found'] ?? false) && ($result['primary_pattern'] ?? '') === 'double_bottom',
                    'double_top_checked'      => $result['double_top_checked']     ?? false,
                    'double_top_found'        => ($result['candidate_found'] ?? false) && ($result['primary_pattern'] ?? '') === 'double_top',
                    'neckline_value'          => $result['neckline_value']         ?? null,
                    'low1_value'              => $result['low1_value']             ?? null,
                    'low2_value'              => $result['low2_value']             ?? null,
                    'high1_value'             => $result['high1_value']            ?? null,
                    'high2_value'             => $result['high2_value']            ?? null,
                    'pattern_window_size'     => $result['pattern_window_size']    ?? null,
                    'similarity_delta_pct'    => $result['similarity_delta_pct']   ?? null,
                    'pattern_reject_reason'   => ($result['double_bottom_checked'] ?? false) || ($result['double_top_checked'] ?? false)
                                                    ? ($result['reject_reason'] ?? null)
                                                    : null,
                    'candidate_found'         => $result['candidate_found']        ?? false,
                    'neckline_distance_status'=> $neckDistStatus,
                    'final_stage_reached'     => $finalStage,
                    // Quality scoring fields
                    'pattern_score'           => $result['pattern_score']           ?? null,
                    'structure_score'         => $result['structure_score']         ?? null,
                    'neckline_score'          => $result['neckline_score']          ?? null,
                    'confirmation_score'      => $result['confirmation_score']      ?? null,
                    'context_score'           => $result['context_score']           ?? null,
                    'candidate_quality_score' => $result['candidate_quality_score'] ?? null,
                    'quality_pass'            => $result['quality_pass']            ?? null,
                    'quality_reject_reason'   => $result['quality_reject_reason']   ?? null,
                    // Control check and final status
                    'control_check_checked'   => isset($result['confirm_status']) && $result['confirm_status'] !== null,
                    'control_check_status'    => $result['confirm_status']         ?? null,
                    'final_signal_status'     => $fss,
                    'long_reject_reason'      => $longRej,
                    'short_reject_reason'     => $shortRej,
                    'reject_reason'           => $rejectR,
                    // Winner selection fields — populated after applySignalFilters() below
                    'signal_id'               => $result['signal_id']              ?? null,
                    'winner_selected'         => null,
                    'winner_reject_reason'    => null,
                ];
            } catch (\Throwable $e) {
                $state['errors'][] = $symbol . ': ' . $e->getMessage();
            }
        }

        // ── Signal filtering + winner selection ──────────────────────────────
        // Apply quality-completeness check, neckline floor, and per-symbol-side
        // winner selection to both existing and newly emitted signals.
        [$signals, $filterStats, $signalOutcomeMap] =
            $this->applySignalFilters($signals, $newlyEmitted, $config);

        $stats['signals_rejected_missing_quality_total'] =
            ($stats['signals_rejected_missing_quality_total'] ?? 0) + $filterStats['rejected_missing_quality'];
        $stats['signals_rejected_low_neckline_total'] =
            ($stats['signals_rejected_low_neckline_total'] ?? 0) + $filterStats['rejected_low_neckline'];
        $stats['signals_rejected_loser_by_quality_total'] =
            ($stats['signals_rejected_loser_by_quality_total'] ?? 0) + $filterStats['rejected_loser_by_quality'];
        // Snapshot counters — overwritten each tick to show current state
        $stats['signals_before_winner_selection_total'] = $filterStats['before_winner_selection'];
        $stats['signals_after_winner_selection_total']  = $filterStats['after_winner_selection'];
        // Final-eligibility counters
        $stats['signals_before_final_eligibility_total']    = $filterStats['before_final_eligibility'];
        $stats['signals_after_final_eligibility_total']     = $filterStats['after_final_eligibility'];
        $stats['signals_rejected_final_trend_total']        =
            ($stats['signals_rejected_final_trend_total']        ?? 0) + $filterStats['rejected_final_trend'];
        $stats['signals_rejected_final_context_total']      =
            ($stats['signals_rejected_final_context_total']      ?? 0) + $filterStats['rejected_final_context'];
        $stats['signals_rejected_final_quality_total']      =
            ($stats['signals_rejected_final_quality_total']      ?? 0) + $filterStats['rejected_final_quality'];
        $stats['signals_rejected_final_low_neckline_total'] =
            ($stats['signals_rejected_final_low_neckline_total'] ?? 0) + $filterStats['rejected_final_low_neckline'];
        $stats['signals_rejected_final_low_quality_total'] =
            ($stats['signals_rejected_final_low_quality_total'] ?? 0) + $filterStats['rejected_final_low_quality'];
        $stats['signals_rejected_final_short_path_total'] =
            ($stats['signals_rejected_final_short_path_total'] ?? 0) + $filterStats['rejected_final_short_path'];

        // Tag preview rows with winner outcome
        foreach ($batchPreviewRows as &$row) {
            $sigId = $row['signal_id'] ?? null;
            if ($sigId !== null && isset($signalOutcomeMap[$sigId])) {
                $row['winner_selected']      = $signalOutcomeMap[$sigId]['winner'];
                $row['winner_reject_reason'] = $signalOutcomeMap[$sigId]['reason'];
            }
        }
        unset($row);

        $totalProcessed = (int)($state['processed'] ?? 0) + $processed;
        $totalFound     = (int)($state['found']     ?? 0) + $found;

        $state['cursor']    = $cursor;
        $state['processed'] = $totalProcessed;
        $state['found']     = $totalFound;

        $isDone = ($cursor >= $total);
        if ($isDone) {
            $state['status']       = 'done';
            $state['completed_at'] = date('c');
        }

        // Append preview rows to run_state
        $state['preview_rows'] = array_slice(
            array_merge((array)($state['preview_rows'] ?? []), $batchPreviewRows),
            -200   // keep last 200 rows max to avoid unbounded growth
        );

        $this->writeJson('storage/run_state.json', $state);
        $this->writeJson('storage/signals.json',   array_values($signals));

        // Persist enriched stats with zero-filled skeleton
        $stats['symbols_total']              = $total;
        $stats['symbols_scanned']            = $totalProcessed;
        $stats['symbols_skipped']            = 0;
        $stats['current_batch_size']         = $batchSz;
        $stats['last_updated_at']            = date('c');
        // signals_active_final_total = actual count in signals.json right now
        $stats['signals_active_final_total'] = count($signals);
        // final_signals_total kept as backward-compat alias (= active count)
        $stats['final_signals_total']        = count($signals);
        // Ensure reject_reason_distribution is always a JSON object, not array
        if (empty($stats['reject_reason_distribution'])) {
            $stats['reject_reason_distribution'] = (object)[];
        }
        if (empty($stats['quality_reject_reason_distribution'])) {
            $stats['quality_reject_reason_distribution'] = (object)[];
        }
        if (empty($stats['pattern_reject_reason_distribution'])) {
            $stats['pattern_reject_reason_distribution'] = (object)[];
        }
        $this->writeJson('storage/stats.json', $stats);

        // Market regime summary for last_run
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
            'status'                     => $state['status'],
            'started_at'                 => $state['started_at'] ?? null,
            'updated_at'                 => date('c'),
            'finished_at'                => $isDone ? ($state['completed_at'] ?? date('c')) : null,
            'symbols_total'              => $total,
            'symbols_scanned'            => $totalProcessed,
            'symbols_remaining'          => max(0, $total - $totalProcessed),
            'current_batch_size'         => $batchSz,
            // signals_active_final_total = active count actually present in signals.json
            'signals_active_final_total' => count($signals),
            // signals_emitted_total = cumulative emits this run (may differ from active due to dedup/TTL)
            'signals_emitted_total'      => $stats['signals_emitted_total'] ?? 0,
            // backward-compat alias (= signals_active_final_total)
            'final_signals_total'        => count($signals),
            'registry_loaded'            => $stats['registry_loaded'],
            'registry_symbol_count'      => $stats['registry_symbol_count'],
            'market_regime'              => $regimeSummary,
            'current_stage_summary'      => [
                'trend_pass'                 => $stats['trend_pass_total']              ?? 0,
                'trend_rejected'             => $stats['trend_rejected_total']          ?? 0,
                'corridor_pass'              => $stats['corridor_pass_total']           ?? 0,
                'corridor_rejected'          => $stats['corridor_rejected_total']       ?? 0,
                'bucket_allowed'             => $stats['bucket_allowed_total']          ?? 0,
                'bucket_rejected'            => $stats['bucket_rejected_total']         ?? 0,
                'wave_pass'                  => $stats['wave_pass_total']               ?? 0,
                'wave_rejected'              => $stats['wave_rejected_total']           ?? 0,
                'double_bottom_checked'      => $stats['double_bottom_checked_total']   ?? 0,
                'double_bottom_found'        => $stats['double_bottom_found_total']     ?? 0,
                'double_top_checked'         => $stats['double_top_checked_total']      ?? 0,
                'double_top_found'           => $stats['double_top_found_total']        ?? 0,
                'setup_candidates'           => $stats['setup_candidates_total']        ?? 0,
                'pattern_rejected'           => $stats['pattern_rejected_total']        ?? 0,
                'candidates_before_quality_filter' => $stats['candidates_before_quality_filter_total'] ?? 0,
                'candidates_after_quality_filter'  => $stats['candidates_after_quality_filter_total']  ?? 0,
                'candidates_rejected_by_quality'   => $stats['candidates_rejected_by_quality_total']   ?? 0,
                'quality_reject_reason_distribution' => $stats['quality_reject_reason_distribution'] ?? (object)[],
                'pattern_reject_reason_distribution' => $stats['pattern_reject_reason_distribution'] ?? (object)[],
                'control_check_pass'         => $stats['control_check_pass_total']      ?? 0,
                'control_check_failed'       => $stats['control_check_failed_total']    ?? 0,
                'control_check_expired'      => $stats['control_check_expired_total']   ?? 0,
                // Stage-drop breakdown
                'rejected_by_trend_side'         => $stats['rejected_by_trend_side_total']        ?? 0,
                'rejected_by_bucket'             => $stats['rejected_by_bucket_total']            ?? 0,
                'rejected_by_wave'               => $stats['rejected_by_wave_total']              ?? 0,
                'rejected_by_pattern'            => $stats['rejected_by_pattern_total']           ?? 0,
                'rejected_by_neckline_distance'  => $stats['rejected_by_neckline_distance_total'] ?? 0,
                // Confirm-state breakdown
                'candidate_waiting_confirm'  => $stats['candidate_waiting_confirm_total']  ?? 0,
                'candidate_expired'          => $stats['candidate_expired_total']          ?? 0,
                'candidate_confirm_failed'   => $stats['candidate_confirm_failed_total']   ?? 0,
                'signals_emitted_total'      => $stats['signals_emitted_total']         ?? 0,
                'signals_active_final_total' => count($signals),
                // Winner-selection filter summary
                'signals_before_winner_selection'   => $stats['signals_before_winner_selection_total']   ?? 0,
                'signals_after_winner_selection'    => $stats['signals_after_winner_selection_total']    ?? 0,
                'signals_rejected_missing_quality'  => $stats['signals_rejected_missing_quality_total']  ?? 0,
                'signals_rejected_low_neckline'     => $stats['signals_rejected_low_neckline_total']     ?? 0,
                'signals_rejected_loser_by_quality' => $stats['signals_rejected_loser_by_quality_total'] ?? 0,
                // Final-eligibility breakdown
                'signals_before_final_eligibility'      => $stats['signals_before_final_eligibility_total']    ?? 0,
                'signals_after_final_eligibility'       => $stats['signals_after_final_eligibility_total']     ?? 0,
                'signals_rejected_final_trend'          => $stats['signals_rejected_final_trend_total']        ?? 0,
                'signals_rejected_final_context'        => $stats['signals_rejected_final_context_total']      ?? 0,
                'signals_rejected_final_quality'        => $stats['signals_rejected_final_quality_total']      ?? 0,
                'signals_rejected_final_low_neckline'   => $stats['signals_rejected_final_low_neckline_total'] ?? 0,
                'signals_rejected_final_low_quality'    => $stats['signals_rejected_final_low_quality_total']  ?? 0,
                'signals_rejected_final_short_path'     => $stats['signals_rejected_final_short_path_total']   ?? 0,
            ],
            'reject_reason_distribution' => $stats['reject_reason_distribution'] ?? (object)[],
            'errors_count'               => count($state['errors'] ?? []),
        ]);
    }

    /**
     * Synchronous full run (for small manual_list).
     * Queues the run then drives tickBatch() in a loop until all symbols
     * are processed, regardless of batch_size config.
     */
    public function run(): array
    {
        $queueResult = $this->queueRun();
        if (!($queueResult['ok'] ?? false)) {
            return $queueResult;
        }

        // Drive the batch loop to completion — no config re-read trick needed
        // because tickBatch() reads symbols from run_state.json and loops until done.
        $maxIterations = 500; // safety cap
        $i = 0;
        do {
            $this->tickBatch();
            $state = $this->getRunState();
            $i++;
        } while (($state['status'] ?? 'done') === 'running' && $i < $maxIterations);

        return $this->getRunState();
    }

    // =========================================================================
    // Market Regime helpers
    // =========================================================================

    /**
     * Compute market regime from a sample of symbols and persist results.
     * Uses quick per-symbol trend (close vs open ratio) without fetching candles
     * from the API — just classifies based on available local trend data if any,
     * or defers to 'unknown' summaries if no candles are available yet.
     *
     * For the foundation pass: call after universe build, before batch processing.
     * Uses already-processed trend results from stats if available; otherwise
     * does a lightweight trend estimate from registry snapshot data if present.
     */
    private function computeAndPersistRegime(array $symbols, array $config, string $previousRegime): void
    {
        $this->requireLogic('market_regime');
        $this->requireLogic('trend');

        // Build lightweight symbol summaries from any cached candle snapshots
        // or mark unknown to get a working (if neutral) regime result.
        // For v1 foundation: if we have no cached candles, just emit 'mixed'
        // based on symbol count — regime will refine as batch processes.
        $summaries = [];
        $sampleSize = min(count($symbols), (int)($config['regime_sample_size'] ?? 30));
        $sample = array_slice($symbols, 0, $sampleSize);

        foreach ($sample as $sym) {
            // Try a quick candle fetch for regime estimation
            try {
                $candles = $this->fetchCandles($sym, array_merge($config, ['lookback_candles' => 20]));
                if (count($candles) >= 5) {
                    $trendResult = (new \Modules\Strategy\Pattern\Logic\PatternTrend())->analyse($candles);
                    $summaries[] = ['symbol' => $sym, 'trend' => $trendResult['trend_direction']];
                } else {
                    $summaries[] = ['symbol' => $sym, 'trend' => 'flat'];
                }
            } catch (\Throwable) {
                $summaries[] = ['symbol' => $sym, 'trend' => 'flat'];
            }
        }

        $regimeEngine = new \Modules\Strategy\Pattern\Logic\PatternMarketRegime();
        $regimeResult = $regimeEngine->compute($summaries, $previousRegime, $config);

        $this->writeJson('storage/market_regime.json', $regimeResult);

        // Append history record
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
    // Per-symbol pipeline
    // =========================================================================

    private function processSymbol(string $symbol, array $config, string $regimeStr): array
    {
        $candles = $this->fetchCandles($symbol, $config);

        // ── Trend ────────────────────────────────────────────────────────────
        $this->requireLogic('trend');
        $trend    = (new \Modules\Strategy\Pattern\Logic\PatternTrend())->analyse($candles);
        $trendDir = $trend['trend_direction'];

        // ── Corridor ─────────────────────────────────────────────────────────
        $this->requireLogic('corridor');
        $lastClose = (float)(end($candles)['close'] ?? 0.0);
        $corridor  = (new \Modules\Strategy\Pattern\Logic\PatternCorridor())->compute($candles, $lastClose, $config);

        // ── Wave ─────────────────────────────────────────────────────────────
        $this->requireLogic('wave');
        $wave = (new \Modules\Strategy\Pattern\Logic\PatternWave())->analyse($candles);

        $diagBase = [
            'market_regime'        => $regimeStr,
            'trend_direction'      => $trendDir,
            'corridor_low'         => $corridor['corridor_low'],
            'corridor_high'        => $corridor['corridor_high'],
            'current_bucket'       => $corridor['current_bucket'],
            'bucket_allowed_long'  => $corridor['bucket_allowed_long'],
            'bucket_allowed_short' => $corridor['bucket_allowed_short'],
            'wave_direction'       => $wave['wave_direction'],
            'wave_state'           => $wave['wave_state'],
        ];

        $sideMode       = (string)($config['side_mode'] ?? 'both');
        $enabledPatterns = (array)($config['enabled_patterns'] ?? ['double_bottom', 'double_top']);

        // Attempt long path; preserve result for diagnostics even on non-emit
        $longResult = null;
        if (in_array($sideMode, ['long_only', 'both'], true) && in_array('double_bottom', $enabledPatterns, true)) {
            $longResult = $this->tryLong($symbol, $candles, $config, $regimeStr, $trendDir, $corridor, $wave, $diagBase);
            if ($longResult['final_signal_status'] === 'emitted') {
                return $longResult;
            }
        }

        // Attempt short path; preserve result for diagnostics even on non-emit
        $shortResult = null;
        if (in_array($sideMode, ['short_only', 'both'], true) && in_array('double_top', $enabledPatterns, true)) {
            $shortResult = $this->tryShort($symbol, $candles, $config, $regimeStr, $trendDir, $corridor, $wave, $diagBase);
            if ($shortResult['final_signal_status'] === 'emitted') {
                return $shortResult;
            }
        }

        // No signal — return composite diagnostic so accumulateStats has full picture
        $longRej  = $longResult['reject_reason']        ?? null;
        $shortRej = $shortResult['reject_reason']       ?? null;
        $dbChecked = $longResult['double_bottom_checked']  ?? false;
        $dtChecked = $shortResult['double_top_checked']    ?? false;

        return array_merge($diagBase, [
            'symbol'                 => $symbol,
            'candidate_found'        => false,
            'candidate_side'         => null,
            'primary_pattern'        => null,
            'double_bottom_checked'  => $dbChecked,
            'double_top_checked'     => $dtChecked,
            'neckline_value'         => $longResult['neckline_value']         ?? ($shortResult['neckline_value']         ?? null),
            'low1_value'             => $longResult['low1_value']             ?? null,
            'low2_value'             => $longResult['low2_value']             ?? null,
            'high1_value'            => $shortResult['high1_value']           ?? null,
            'high2_value'            => $shortResult['high2_value']           ?? null,
            'pattern_window_size'    => $longResult['pattern_window_size']    ?? ($shortResult['pattern_window_size']    ?? null),
            'similarity_delta_pct'   => $longResult['similarity_delta_pct']  ?? ($shortResult['similarity_delta_pct']  ?? null),
            'pattern_score'           => null,
            'structure_score'         => null,
            'neckline_score'          => null,
            'confirmation_score'      => null,
            'context_score'           => null,
            'candidate_quality_score' => null,
            'quality_pass'            => null,
            'quality_reject_reason'   => null,
            'long_reject_reason'     => $longRej,
            'short_reject_reason'    => $shortRej,
            'confirm_status'         => null,
            'confirm_bars_waited'    => 0,
            'candidate_expired'      => false,
            'final_signal_status'    => 'no_signal',
            'reject_reason'          => $longRej ?? $shortRej ?? 'no_valid_candidate',
            'signal'                 => null,
        ]);
    }

    private function tryLong(string $symbol, array $candles, array $config,
        string $regimeStr, string $trendDir, array $corridor, array $wave, array $diagBase): array
    {
        $side = 'long';

        // Gate: trend
        if ((bool)($config['trend_required'] ?? true)) {
            $this->requireLogic('trend');
            $tGate = (new \Modules\Strategy\Pattern\Logic\PatternTrend())->gate($trendDir, $side);
            if (!$tGate['pass']) {
                return $this->reject($diagBase, $symbol, $side, 'double_bottom', $tGate['reason']);
            }
        }

        // Gate: corridor
        if ((bool)($config['corridor_required'] ?? true)) {
            $this->requireLogic('corridor');
            $cGate = (new \Modules\Strategy\Pattern\Logic\PatternCorridor())->gate($corridor, $side);
            if (!$cGate['pass']) {
                return $this->reject($diagBase, $symbol, $side, 'double_bottom', $cGate['reason']);
            }
        }

        // Gate: wave
        if ((bool)($config['wave_required'] ?? true)) {
            $this->requireLogic('wave');
            $wGate = (new \Modules\Strategy\Pattern\Logic\PatternWave())->gate($wave, $side);
            if (!$wGate['pass']) {
                return $this->reject($diagBase, $symbol, $side, 'double_bottom', $wGate['reason']);
            }
        }

        // Pattern — reached only when all gates pass
        $this->requireLogic('double_bottom');
        $candidate = (new \Modules\Strategy\Pattern\Logic\PatternDoubleBottom())->detect($candles, $config);
        if (!$candidate['candidate_found']) {
            return $this->reject($diagBase, $symbol, $side, 'double_bottom', $candidate['reject_reason'] ?? 'no_double_bottom', true, [
                'neckline_value'       => $candidate['neckline']              ?? 0.0,
                'low1_value'           => $candidate['low1_price']            ?? 0.0,
                'low2_value'           => $candidate['low2_price']            ?? 0.0,
                'pattern_window_size'  => $candidate['window_size']           ?? 0,
                'similarity_delta_pct' => $candidate['similarity_delta_pct'] ?? 0.0,
            ]);
        }

        // ── Candidate quality scoring ────────────────────────────────────────
        $this->requireLogic('candidate_quality');
        $quality = (new \Modules\Strategy\Pattern\Logic\PatternCandidateQuality())->score(
            $candidate, $wave, $config, $side
        );
        if (!$quality['quality_pass']) {
            return array_merge($diagBase, [
                'symbol'                  => $symbol,
                'candidate_found'         => true,
                'candidate_side'          => $side,
                'primary_pattern'         => 'double_bottom',
                'double_bottom_checked'   => true,
                'double_top_checked'      => false,
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

        // Control confirmation
        if ((bool)($config['confirm_required'] ?? true)) {
            $this->requireLogic('control_check');
            $candIdx = max(0, count($candles) - 3);
            $confirm = (new \Modules\Strategy\Pattern\Logic\PatternControlCheck())->check($candidate, $candles, $candIdx, $config);
            if (!$confirm['confirm_pass']) {
                return array_merge($diagBase, [
                    'symbol'                  => $symbol,
                    'candidate_found'         => true,
                    'candidate_side'          => $side,
                    'primary_pattern'         => 'double_bottom',
                    'double_bottom_checked'   => true,
                    'double_top_checked'      => false,
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

        // Emit signal — include quality scores in pipeline so PatternSignal persists them
        $this->requireLogic('signal');
        $signal = (new \Modules\Strategy\Pattern\Logic\PatternSignal())->build(
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
            'double_top_checked'      => false,
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

    private function tryShort(string $symbol, array $candles, array $config,
        string $regimeStr, string $trendDir, array $corridor, array $wave, array $diagBase): array
    {
        $side = 'short';

        if ((bool)($config['trend_required'] ?? true)) {
            $this->requireLogic('trend');
            $tGate = (new \Modules\Strategy\Pattern\Logic\PatternTrend())->gate($trendDir, $side);
            if (!$tGate['pass']) {
                return $this->reject($diagBase, $symbol, $side, 'double_top', $tGate['reason']);
            }
        }

        if ((bool)($config['corridor_required'] ?? true)) {
            $this->requireLogic('corridor');
            $cGate = (new \Modules\Strategy\Pattern\Logic\PatternCorridor())->gate($corridor, $side);
            if (!$cGate['pass']) {
                return $this->reject($diagBase, $symbol, $side, 'double_top', $cGate['reason']);
            }
        }

        if ((bool)($config['wave_required'] ?? true)) {
            $this->requireLogic('wave');
            $wGate = (new \Modules\Strategy\Pattern\Logic\PatternWave())->gate($wave, $side);
            if (!$wGate['pass']) {
                return $this->reject($diagBase, $symbol, $side, 'double_top', $wGate['reason']);
            }
        }

        $this->requireLogic('double_top');
        $candidate = (new \Modules\Strategy\Pattern\Logic\PatternDoubleTop())->detect($candles, $config);
        if (!$candidate['candidate_found']) {
            return $this->reject($diagBase, $symbol, $side, 'double_top', $candidate['reject_reason'] ?? 'no_double_top', true, [
                'neckline_value'       => $candidate['neckline']              ?? 0.0,
                'high1_value'          => $candidate['high1_price']           ?? 0.0,
                'high2_value'          => $candidate['high2_price']           ?? 0.0,
                'pattern_window_size'  => $candidate['window_size']           ?? 0,
                'similarity_delta_pct' => $candidate['similarity_delta_pct'] ?? 0.0,
            ]);
        }

        // ── Candidate quality scoring ────────────────────────────────────────
        $this->requireLogic('candidate_quality');
        $quality = (new \Modules\Strategy\Pattern\Logic\PatternCandidateQuality())->score(
            $candidate, $wave, $config, $side
        );
        if (!$quality['quality_pass']) {
            return array_merge($diagBase, [
                'symbol'                  => $symbol,
                'candidate_found'         => true,
                'candidate_side'          => $side,
                'primary_pattern'         => 'double_top',
                'double_bottom_checked'   => false,
                'double_top_checked'      => true,
                'neckline_value'          => $candidate['neckline']              ?? 0.0,
                'high1_value'             => $candidate['high1_price']           ?? 0.0,
                'high2_value'             => $candidate['high2_price']           ?? 0.0,
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
            $confirm = (new \Modules\Strategy\Pattern\Logic\PatternControlCheck())->check($candidate, $candles, $candIdx, $config);
            if (!$confirm['confirm_pass']) {
                return array_merge($diagBase, [
                    'symbol'                  => $symbol,
                    'candidate_found'         => true,
                    'candidate_side'          => $side,
                    'primary_pattern'         => 'double_top',
                    'double_bottom_checked'   => false,
                    'double_top_checked'      => true,
                    'neckline_value'          => $candidate['neckline']              ?? 0.0,
                    'high1_value'             => $candidate['high1_price']           ?? 0.0,
                    'high2_value'             => $candidate['high2_price']           ?? 0.0,
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
        $signal = (new \Modules\Strategy\Pattern\Logic\PatternSignal())->build(
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
            'primary_pattern'         => 'double_top',
            'double_bottom_checked'   => false,
            'double_top_checked'      => true,
            'neckline_value'          => $candidate['neckline']              ?? 0.0,
            'high1_value'             => $candidate['high1_price']           ?? 0.0,
            'high2_value'             => $candidate['high2_price']           ?? 0.0,
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

    /**
     * @param  bool $patternChecked  True only when pattern detection stage was actually invoked.
     */
    private function reject(array $diag, string $symbol, string $side, string $pattern, ?string $reason, bool $patternChecked = false, array $extra = []): array
    {
        return array_merge($diag, $extra, [
            'symbol'                  => $symbol,
            'candidate_found'         => false,
            'candidate_side'          => $side,
            'primary_pattern'         => $pattern,
            'double_bottom_checked'   => ($side === 'long'  && $patternChecked),
            'double_top_checked'      => ($side === 'short' && $patternChecked),
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
     * Apply the full signal filtering pipeline:
     *   Stage 1 — Final eligibility:
     *     a. Quality completeness (all score keys present, quality_pass = true)
     *     b. Neckline floor (neckline_score >= min_neckline_score when configured)
     *     c. Trend consistency (when trend_required: signal's trend_direction must be
     *        'bullish' or 'bearish'; flat/unknown are removed from the final active set)
     *     d. Context consistency (wave_state must be corrective; bucket must be in
     *        allowed zone for signal side — catches stale/inconsistent snapshots)
     *   Stage 2 — Winner selection: one signal per symbol+side by quality ranking.
     *
     * Returns [filteredSignals, filterStats, signalOutcomeMap].
     * signalOutcomeMap: signal_id → ['winner' => bool, 'reason' => string|null]
     *
     * @param  array $existingSignals  Currently persisted signals from storage.
     * @param  array $newlyEmitted     Signals emitted in the current batch tick.
     * @param  array $config           Effective strategy config.
     * @return array{0: array, 1: array, 2: array}
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
        $allowedShort     = (array)($config['allowed_short_buckets']       ?? [8, 9, 10]);

        $requiredScoreKeys = [
            'pattern_score', 'structure_score', 'neckline_score',
            'confirmation_score', 'context_score', 'candidate_quality_score',
        ];

        $isComplete = static function (array $sig) use ($requiredScoreKeys): bool {
            // quality_pass must be explicitly true
            if (($sig['quality_pass'] ?? null) !== true) {
                return false;
            }
            // All score keys must be present and numeric
            foreach ($requiredScoreKeys as $k) {
                if (!array_key_exists($k, $sig) || !is_numeric($sig[$k])) {
                    return false;
                }
            }
            // quality_reject_reason key must exist (null is correct for passing signals)
            return array_key_exists('quality_reject_reason', $sig);
        };

        $signalOutcomeMap = [];

        // Merge existing + new into a keyed map (new signals overwrite by signal_id)
        $merged = [];
        foreach ($existingSignals as $s) {
            $merged[$s['signal_id']] = $s;
        }
        foreach ($newlyEmitted as $s) {
            $merged[$s['signal_id']] = $s;
        }

        $beforeFinalEligibility   = count($merged);
        $rejectedFinalQuality     = 0;
        $rejectedFinalLowNeckline = 0;
        $rejectedFinalLowQuality  = 0;
        $rejectedFinalTrend       = 0;
        $rejectedFinalShortPath   = 0;
        $rejectedFinalContext     = 0;

        // ── Stage 1: Final eligibility ────────────────────────────────────────
        $eligible = [];
        foreach ($merged as $id => $s) {
            $side = (string)($s['side'] ?? '');

            // 1a. Quality completeness
            if (!$isComplete($s)) {
                $rejectedFinalQuality++;
                $signalOutcomeMap[$id] = ['winner' => false, 'reason' => 'final_quality_fail'];
                continue;
            }

            // 1b. Neckline floor
            if ($minNeckline > 0.0 && (float)($s['neckline_score'] ?? 0.0) < $minNeckline) {
                $rejectedFinalLowNeckline++;
                $signalOutcomeMap[$id] = ['winner' => false, 'reason' => 'final_low_neckline_score'];
                continue;
            }

            // 1c. Trend consistency:
            //     - flat/unknown means no directional context → remove when trend_required.
            //     - side-vs-trend: long requires bullish context; short requires bearish context.
            //       A short signal in a bullish market or a long signal in a bearish market
            //       indicates a context mismatch and must not survive into the final active set.
            if ($trendRequired) {
                $trendDir = (string)($s['trend_direction'] ?? 'unknown');
                if (!in_array($trendDir, ['bullish', 'bearish'], true)) {
                    $rejectedFinalTrend++;
                    if ($side === 'short') {
                        $rejectedFinalShortPath++;
                    }
                    $signalOutcomeMap[$id] = ['winner' => false, 'reason' => 'final_trend_mismatch'];
                    continue;
                }
                // Side-vs-trend: enforce long = bullish, short = bearish
                if (($side === 'long' && $trendDir !== 'bullish')
                    || ($side === 'short' && $trendDir !== 'bearish')
                ) {
                    $rejectedFinalTrend++;
                    if ($side === 'short') {
                        $rejectedFinalShortPath++;
                    }
                    $signalOutcomeMap[$id] = ['winner' => false, 'reason' => 'final_side_trend_conflict'];
                    continue;
                }
            }

            // 1d. Context consistency — wave state and bucket zone
            $contextRejectReason = null;

            // Wave: signal must have been in a corrective wave state when detected
            if ($waveRequired) {
                $waveState = (string)($s['wave_state'] ?? '');
                // Only reject when wave_state is explicitly non-corrective (not when absent)
                if ($waveState !== '' && $waveState !== 'unknown' && $waveState !== 'corrective') {
                    $contextRejectReason = 'final_wave_mismatch';
                }
            }

            // Bucket: signal's stored corridor_bucket must be in allowed zone
            if ($contextRejectReason === null && $corridorRequired) {
                $bucket = (int)($s['corridor_bucket'] ?? 0);
                if ($bucket > 0) {
                    if ($side === 'long' && !in_array($bucket, $allowedLong, true)) {
                        $contextRejectReason = 'final_bucket_mismatch';
                    } elseif ($side === 'short' && !in_array($bucket, $allowedShort, true)) {
                        $contextRejectReason = 'final_bucket_mismatch';
                    }
                }
            }

            if ($contextRejectReason !== null) {
                $rejectedFinalContext++;
                $signalOutcomeMap[$id] = ['winner' => false, 'reason' => $contextRejectReason];
                continue;
            }

            // 1e. Final quality composite floor — catches stale signals emitted under looser
            //     thresholds and ensures every active signal meets the current quality bar.
            if ($minFinalQuality > 0.0 && (float)($s['candidate_quality_score'] ?? 0.0) < $minFinalQuality) {
                $rejectedFinalLowQuality++;
                $signalOutcomeMap[$id] = ['winner' => false, 'reason' => 'final_low_quality'];
                continue;
            }

            $eligible[$id] = $s;
        }

        $afterFinalEligibility = count($eligible);

        // ── Stage 2: Winner selection — one signal per symbol+side ────────────
        $bySymbolSide = [];
        foreach ($eligible as $s) {
            $key = ($s['symbol'] ?? '') . '|' . ($s['side'] ?? '');
            $bySymbolSide[$key][] = $s;
        }

        $rejectedLoserByQuality = 0;
        $winnerSignals          = [];

        foreach ($bySymbolSide as $group) {
            if (count($group) === 1) {
                $w = $group[0];
                $signalOutcomeMap[$w['signal_id']] = ['winner' => true, 'reason' => null];
                $winnerSignals[] = $w;
                continue;
            }
            // Sort descending: candidate_quality_score → confirmation_score → newest detected_at
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
                $signalOutcomeMap[$loser['signal_id']] = ['winner' => false, 'reason' => 'loser_by_quality'];
                $rejectedLoserByQuality++;
            }
        }

        $filterStats = [
            // Final eligibility stage
            'before_final_eligibility'      => $beforeFinalEligibility,
            'after_final_eligibility'       => $afterFinalEligibility,
            'rejected_final_quality'        => $rejectedFinalQuality,
            'rejected_final_low_neckline'   => $rejectedFinalLowNeckline,
            'rejected_final_low_quality'    => $rejectedFinalLowQuality,
            'rejected_final_trend'          => $rejectedFinalTrend,
            'rejected_final_short_path'     => $rejectedFinalShortPath,
            'rejected_final_context'        => $rejectedFinalContext,
            // Winner selection stage
            'before_winner_selection'       => $afterFinalEligibility,
            'after_winner_selection'        => count($winnerSignals),
            'rejected_loser_by_quality'     => $rejectedLoserByQuality,
            // Backward-compat aliases
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
        $longRej       = $result['long_reject_reason']  ?? null;
        $shortRej      = $result['short_reject_reason'] ?? null;
        $confirmStatus = $result['confirm_status']      ?? '';
        $dbChecked     = (bool)($result['double_bottom_checked'] ?? false);
        $dtChecked     = (bool)($result['double_top_checked']    ?? false);
        $candidateFound = (bool)($result['candidate_found']      ?? false);

        $inc = static function (array &$s, string $key): void { $s[$key] = ($s[$key] ?? 0) + 1; };

        // ── Market regime vote for this symbol ──
        if ($regime === 'bullish')         { $inc($stats, 'regime_bullish_total'); }
        elseif ($regime === 'bearish')     { $inc($stats, 'regime_bearish_total'); }
        elseif ($regime === 'mixed')       { $inc($stats, 'regime_mixed_total'); }
        elseif ($regime === 'transition')  { $inc($stats, 'regime_transition_total'); }

        // ── Trend ──────────────────────────────
        $tDir = $result['trend_direction'] ?? 'unknown';
        if ($tDir === 'bullish' || $tDir === 'bearish') {
            $inc($stats, 'trend_pass_total');
        } else {
            $inc($stats, 'trend_rejected_total');
        }

        // ── Corridor: passed if current_bucket is non-zero (corridor was computed) ──
        $bucket = (int)($result['current_bucket'] ?? 0);
        if ($bucket > 0) {
            $inc($stats, 'corridor_pass_total');
        } else {
            $inc($stats, 'corridor_rejected_total');
        }

        // ── Bucket (allowed/rejected per side) ─
        // Track whether price was in an allowed zone for either side
        $bucketAllowedLong  = (bool)($result['bucket_allowed_long']  ?? false);
        $bucketAllowedShort = (bool)($result['bucket_allowed_short'] ?? false);
        if ($bucketAllowedLong || $bucketAllowedShort) {
            $inc($stats, 'bucket_allowed_total');
        } else {
            $inc($stats, 'bucket_rejected_total');
        }

        // ── Wave: passes if wave is corrective for either applicable direction ──
        $wDir   = $result['wave_direction'] ?? 'unknown';
        $wState = $result['wave_state']     ?? 'unknown';
        $waveLongOk  = ($wDir === 'up'   && $wState === 'corrective');
        $waveShortOk = ($wDir === 'down' && $wState === 'corrective');
        if ($waveLongOk || $waveShortOk) {
            $inc($stats, 'wave_pass_total');
        } else {
            $inc($stats, 'wave_rejected_total');
        }

        // ── Pattern stage ──────────────────────
        // double_bottom_checked_total: how many times double_bottom detection was actually called
        if ($dbChecked) {
            $inc($stats, 'double_bottom_checked_total');
        }
        if ($dtChecked) {
            $inc($stats, 'double_top_checked_total');
        }
        // Found only when candidate_found AND pattern matches
        if ($candidateFound && $pattern === 'double_bottom') {
            $inc($stats, 'double_bottom_found_total');
        }
        if ($candidateFound && $pattern === 'double_top') {
            $inc($stats, 'double_top_found_total');
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
        // pattern_rejected_total: only when pattern stage was reached but candidate not found
        if (($dbChecked || $dtChecked) && !$candidateFound && in_array($fss, ['rejected', 'no_signal'], true)) {
            $inc($stats, 'pattern_rejected_total');
            // Track the specific pattern-stage reject reason separately
            if ($rejectReason !== null && $rejectReason !== '') {
                $pdist = (array)($stats['pattern_reject_reason_distribution'] ?? []);
                $pdist[$rejectReason] = ($pdist[$rejectReason] ?? 0) + 1;
                $stats['pattern_reject_reason_distribution'] = $pdist;
            }
        }

        // ── Control check ──────────────────────
        if ($confirmStatus === 'confirm_pass')      { $inc($stats, 'control_check_pass_total'); }
        if ($result['candidate_expired'] ?? false)  {
            $inc($stats, 'control_check_expired_total');
            $inc($stats, 'candidate_expired_total');
        }
        if ($confirmStatus === 'confirm_waiting') {
            $inc($stats, 'candidate_waiting_confirm_total');
            $inc($stats, 'control_check_failed_total'); // backward-compat
        }
        if ($confirmStatus === 'confirm_failed') {
            $inc($stats, 'candidate_confirm_failed_total');
            $inc($stats, 'control_check_failed_total'); // backward-compat
        }

        // ── Signal ─────────────────────────────
        if ($fss === 'emitted')    { $inc($stats, 'signals_emitted_total'); }

        // ── Reject reason distribution ─────────
        // Count the primary reject reason (covers the "furthest" path attempted)
        if ($rejectReason !== null && $rejectReason !== '') {
            $dist = (array)($stats['reject_reason_distribution'] ?? []);
            $dist[$rejectReason] = ($dist[$rejectReason] ?? 0) + 1;
            $stats['reject_reason_distribution'] = $dist;
        }
        // For no_signal composites: also count the short-path reason separately if different
        if ($fss === 'no_signal' && $shortRej !== null && $shortRej !== $rejectReason) {
            $dist = (array)($stats['reject_reason_distribution'] ?? []);
            $dist[$shortRej] = ($dist[$shortRej] ?? 0) + 1;
            $stats['reject_reason_distribution'] = $dist;
        }

        // ── Explicit stage-drop counters ────────
        // Inspect both long and short reject reasons to surface the per-stage bottleneck
        // independently of the mixed reject_reason_distribution bucket.
        $necklineDistReasons = ['price_too_far_above_neckline', 'price_too_far_below_neckline'];
        foreach ([$longRej, $shortRej] as $sr) {
            if ($sr === null || $sr === '') {
                continue;
            }
            if (str_contains($sr, '_side_') && str_contains($sr, '_mismatch') && str_starts_with($sr, 'trend_')) {
                $inc($stats, 'rejected_by_trend_side_total');
            } elseif (str_starts_with($sr, 'bucket_rejected_')) {
                $inc($stats, 'rejected_by_bucket_total');
            } elseif (str_starts_with($sr, 'wave_') && str_contains($sr, 'rejected')) {
                $inc($stats, 'rejected_by_wave_total');
            } elseif (in_array($sr, $necklineDistReasons, true)) {
                $inc($stats, 'rejected_by_neckline_distance_total');
            }
        }
        // Pattern-stage rejects: when pattern detection ran but found no candidate
        if (($dbChecked || $dtChecked) && !$candidateFound) {
            $inc($stats, 'rejected_by_pattern_total');
        }
        // Confirm-wait stage drop: valid candidate stalled at confirmation window
        if ($confirmStatus === 'confirm_waiting') {
            // already counted above via candidate_waiting_confirm_total; no extra stage counter needed
        }

        return $stats;
    }

    /**
     * Return a zero-initialised stats skeleton so all counters always appear
     * even when no symbols have been processed yet.
     */
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
            'double_top_checked_total'     => 0,
            'double_top_found_total'       => 0,
            'pattern_rejected_total'       => 0,
            'setup_candidates_total'       => 0,
            'candidates_before_quality_filter_total' => 0,
            'candidates_after_quality_filter_total'  => 0,
            'candidates_rejected_by_quality_total'   => 0,
            'quality_reject_reason_distribution'     => (object)[],
            'control_check_pass_total'        => 0,
            'control_check_expired_total'     => 0,
            'control_check_failed_total'      => 0,
            // Explicit stage-drop counters — show where symbols die before candidates form
            'rejected_by_trend_side_total'        => 0,
            'rejected_by_bucket_total'            => 0,
            'rejected_by_wave_total'              => 0,
            'rejected_by_pattern_total'           => 0,
            'rejected_by_neckline_distance_total' => 0,
            // Confirm-state breakdown (replaces mixed control_check_failed_total bucket)
            'candidate_waiting_confirm_total'  => 0,
            'candidate_expired_total'          => 0,
            'candidate_confirm_failed_total'   => 0,
            'signals_emitted_total'        => 0,
            'signals_active_final_total'   => 0,
            'final_signals_total'          => 0,   // backward-compat alias = signals_active_final_total
            // Final-eligibility filter counters (Stage 1 of applySignalFilters)
            'signals_before_final_eligibility_total'    => 0,
            'signals_after_final_eligibility_total'     => 0,
            'signals_rejected_final_trend_total'        => 0,
            'signals_rejected_final_context_total'      => 0,
            'signals_rejected_final_quality_total'      => 0,
            'signals_rejected_final_low_neckline_total' => 0,
            'signals_rejected_final_low_quality_total'  => 0,
            'signals_rejected_final_short_path_total'   => 0,
            // Winner-selection filter counters (Stage 2 of applySignalFilters)
            'signals_before_winner_selection_total'   => 0,
            'signals_after_winner_selection_total'    => 0,
            'signals_rejected_missing_quality_total'  => 0,  // backward-compat = rejected_final_quality
            'signals_rejected_low_neckline_total'     => 0,  // backward-compat = rejected_final_low_neckline
            'signals_rejected_loser_by_quality_total' => 0,
            'current_batch_size'           => 0,
            'last_updated_at'              => null,
            'reject_reason_distribution'         => (object)[],
            'pattern_reject_reason_distribution' => (object)[],
        ];
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
            // Resolve registry path via SystemPaths; fall back to relative path
            try {
                $registryDir = \Core\System\SystemPaths::instance()
                    ->get('parser.parser1_market_registry');
            } catch (\Throwable) {
                $registryDir = dirname(__DIR__, 3)
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
                // active.json is keyed by symbol name: { "BTCUSDT": {...}, ... }
                // Support both dict-keyed and array-of-objects formats
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

        // Persist registry diagnostics so admin UI can show them
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
        // Bybit returns newest-first; reverse to oldest-first
        $list = array_reverse($list);

        $candles = [];
        foreach ($list as $bar) {
            // [start_ts, open, high, low, close, volume, turnover]
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

    // =========================================================================
    // Lazy-require logic files (avoid autoloader requirement)
    // =========================================================================

    private function requireBootstrap(): void
    {
        require_once $this->moduleDir . '/bootstrap.php';
    }

    private function requireLogic(string $file): void
    {
        require_once $this->moduleDir . '/logic/' . $file . '.php';
    }

    // =========================================================================
    // JSON storage helpers
    // =========================================================================

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
}
