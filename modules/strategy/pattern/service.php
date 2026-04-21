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

        // Seed empty storage files if they don't exist yet
        $zeroStats = $this->zeroStats();
        foreach ([
            'signals.json'  => [],
            'stats.json'    => $zeroStats,
            'last_run.json' => ['status' => 'queued', 'started_at' => date('c')],
        ] as $f => $v) {
            if (!file_exists($this->moduleDir . '/storage/' . $f)) {
                $this->writeJson('storage/' . $f, $v);
            }
        }
        if (!file_exists($this->moduleDir . '/storage/market_regime.json')) {
            $this->writeJson('storage/market_regime.json', ['regime' => 'unknown']);
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
        $prevRegimeStr  = (string)($prevRegimeData['regime'] ?? 'unknown');

        if ($regimeEnabled && $cursor === 0 && $total > 0) {
            $this->computeAndPersistRegime($symbols, $config, $prevRegimeStr);
            $prevRegimeData = $this->readJson('storage/market_regime.json', []);
        }

        $regimeStr = (string)($prevRegimeData['regime'] ?? 'unknown');

        $processed = 0;
        $found     = 0;
        $batchPreviewRows = [];

        while ($cursor < $total && $processed < $batchSz && (time() - $tStart) < $maxSec) {
            $symbol = $symbols[$cursor];
            $cursor++;
            $processed++;

            try {
                $result = $this->processSymbol($symbol, $config, $regimeStr);
                if ($result['final_signal_status'] === 'emitted') {
                    $signals = $this->mergeSignal($signals, $result['signal']);
                    $found++;
                }
                $stats = $this->accumulateStats($stats, $result);

                // Collect pipeline preview row for admin UI
                $batchPreviewRows[] = [
                    'symbol'              => $symbol,
                    'market_regime'       => $result['market_regime']       ?? null,
                    'trend_direction'     => $result['trend_direction']     ?? null,
                    'current_bucket'      => $result['current_bucket']      ?? null,
                    'wave_state'          => $result['wave_state']          ?? null,
                    'pattern_candidate'   => $result['primary_pattern']     ?? null,
                    'control_check_status'=> $result['confirm_status']      ?? null,
                    'final_signal_status' => $result['final_signal_status'] ?? null,
                    'reject_reason'       => $result['reject_reason']       ?? null,
                ];
            } catch (\Throwable $e) {
                $state['errors'][] = $symbol . ': ' . $e->getMessage();
            }
        }

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
        $stats['symbols_total']      = $total;
        $stats['symbols_scanned']    = $totalProcessed;
        $stats['symbols_skipped']    = 0;
        $stats['current_batch_size'] = $batchSz;
        $stats['last_updated_at']    = date('c');
        // Ensure reject_reason_distribution is always a JSON object, not array
        if (empty($stats['reject_reason_distribution'])) {
            $stats['reject_reason_distribution'] = (object)[];
        }
        $this->writeJson('storage/stats.json', $stats);

        // Market regime summary for last_run
        $regimeSummary = [
            'current_regime'  => $prevRegimeData['regime']         ?? 'unknown',
            'previous_regime' => $prevRegimeData['previous_regime'] ?? 'unknown',
            'regime_changed'  => $prevRegimeData['regime_changed']  ?? false,
            'bull_count'      => $prevRegimeData['bull_count']      ?? 0,
            'bear_count'      => $prevRegimeData['bear_count']      ?? 0,
            'flat_count'      => $prevRegimeData['flat_count']      ?? 0,
        ];

        $this->writeJson('storage/last_run.json', [
            'status'                   => $state['status'],
            'started_at'               => $state['started_at'] ?? null,
            'updated_at'               => date('c'),
            'finished_at'              => $isDone ? ($state['completed_at'] ?? date('c')) : null,
            'symbols_total'            => $total,
            'symbols_scanned'          => $totalProcessed,
            'symbols_remaining'        => max(0, $total - $totalProcessed),
            'current_batch_size'       => $batchSz,
            'final_signals_total'      => $stats['final_signals_total'] ?? 0,
            'registry_loaded'          => $stats['registry_loaded'],
            'registry_symbol_count'    => $stats['registry_symbol_count'],
            'market_regime'            => $regimeSummary,
            'current_stage_summary'    => [
                'trend_pass'         => $stats['trend_pass_total']            ?? 0,
                'trend_rejected'     => $stats['trend_rejected_total']        ?? 0,
                'corridor_pass'      => $stats['corridor_pass_total']         ?? 0,
                'corridor_rejected'  => $stats['corridor_rejected_total']     ?? 0,
                'wave_pass'          => $stats['wave_pass_total']             ?? 0,
                'wave_rejected'      => $stats['wave_rejected_total']         ?? 0,
                'setup_candidates'   => $stats['setup_candidates_total']      ?? 0,
                'pattern_rejected'   => $stats['pattern_rejected_total']      ?? 0,
                'control_check_pass' => $stats['control_check_pass_total']    ?? 0,
                'control_check_fail' => $stats['control_check_failed_total']  ?? 0,
                'signals_emitted'    => $stats['final_signals_total']         ?? 0,
            ],
            'reject_reason_distribution' => $stats['reject_reason_distribution'] ?? (object)[],
            'errors_count'             => count($state['errors'] ?? []),
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
        $regimeResult = $regimeEngine->compute($summaries, $previousRegime);

        $this->writeJson('storage/market_regime.json', $regimeResult);

        // Append history record
        $histPath = $this->moduleDir . '/storage/market_regime_history.ndjson';
        $histLine = json_encode([
            'ts'              => $regimeResult['ts'],
            'previous_regime' => $regimeResult['previous_regime'],
            'current_regime'  => $regimeResult['regime'],
            'bull_count'      => $regimeResult['bull_count'],
            'bear_count'      => $regimeResult['bear_count'],
            'flat_count'      => $regimeResult['flat_count'],
            'regime_changed'  => $regimeResult['regime_changed'],
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
            'market_regime'   => $regimeStr,
            'trend_direction' => $trendDir,
            'corridor_low'    => $corridor['corridor_low'],
            'corridor_high'   => $corridor['corridor_high'],
            'current_bucket'  => $corridor['current_bucket'],
            'wave_direction'  => $wave['wave_direction'],
            'wave_state'      => $wave['wave_state'],
        ];

        $sideMode       = (string)($config['side_mode'] ?? 'both');
        $enabledPatterns = (array)($config['enabled_patterns'] ?? ['double_bottom', 'double_top']);

        // Attempt long path
        if (in_array($sideMode, ['long_only', 'both'], true) && in_array('double_bottom', $enabledPatterns, true)) {
            $result = $this->tryLong($symbol, $candles, $config, $regimeStr, $trendDir, $corridor, $wave, $diagBase);
            if ($result['final_signal_status'] === 'emitted') {
                return $result;
            }
        }

        // Attempt short path
        if (in_array($sideMode, ['short_only', 'both'], true) && in_array('double_top', $enabledPatterns, true)) {
            $result = $this->tryShort($symbol, $candles, $config, $regimeStr, $trendDir, $corridor, $wave, $diagBase);
            if ($result['final_signal_status'] === 'emitted') {
                return $result;
            }
        }

        // No signal
        return array_merge($diagBase, [
            'symbol'              => $symbol,
            'candidate_found'     => false,
            'candidate_side'      => null,
            'primary_pattern'     => null,
            'confirm_status'      => null,
            'confirm_bars_waited' => 0,
            'candidate_expired'   => false,
            'final_signal_status' => 'no_signal',
            'reject_reason'       => 'no_valid_candidate',
            'signal'              => null,
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

        // Pattern
        $this->requireLogic('double_bottom');
        $candidate = (new \Modules\Strategy\Pattern\Logic\PatternDoubleBottom())->detect($candles);
        if (!$candidate['candidate_found']) {
            return $this->reject($diagBase, $symbol, $side, 'double_bottom', $candidate['reject_reason'] ?? 'no_double_bottom');
        }

        // Control confirmation
        if ((bool)($config['confirm_required'] ?? true)) {
            $this->requireLogic('control_check');
            $candIdx = max(0, count($candles) - 3);
            $confirm = (new \Modules\Strategy\Pattern\Logic\PatternControlCheck())->check($candidate, $candles, $candIdx, $config);
            if (!$confirm['confirm_pass']) {
                return array_merge($diagBase, [
                    'symbol'              => $symbol,
                    'candidate_found'     => true,
                    'candidate_side'      => $side,
                    'primary_pattern'     => 'double_bottom',
                    'confirm_status'      => $confirm['confirm_status'],
                    'confirm_bars_waited' => $confirm['confirm_bars_waited'],
                    'candidate_expired'   => $confirm['candidate_expired'],
                    'final_signal_status' => 'confirm_pending',
                    'reject_reason'       => $confirm['reject_reason'],
                    'signal'              => null,
                ]);
            }
        } else {
            $confirm = ['confirm_status' => 'confirm_pass', 'confirm_bar_close' => null, 'confirm_bars_waited' => 0];
        }

        // Emit signal
        $this->requireLogic('signal');
        $signal = (new \Modules\Strategy\Pattern\Logic\PatternSignal())->build(
            $symbol, $candidate, $confirm,
            array_merge($diagBase, $corridor, $wave),
            date('c')
        );

        return array_merge($diagBase, [
            'symbol'              => $symbol,
            'candidate_found'     => true,
            'candidate_side'      => $side,
            'primary_pattern'     => 'double_bottom',
            'confirm_status'      => 'confirm_pass',
            'confirm_bars_waited' => $confirm['confirm_bars_waited'] ?? 0,
            'candidate_expired'   => false,
            'final_signal_status' => 'emitted',
            'reject_reason'       => null,
            'signal'              => $signal,
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
        $candidate = (new \Modules\Strategy\Pattern\Logic\PatternDoubleTop())->detect($candles);
        if (!$candidate['candidate_found']) {
            return $this->reject($diagBase, $symbol, $side, 'double_top', $candidate['reject_reason'] ?? 'no_double_top');
        }

        if ((bool)($config['confirm_required'] ?? true)) {
            $this->requireLogic('control_check');
            $candIdx = max(0, count($candles) - 3);
            $confirm = (new \Modules\Strategy\Pattern\Logic\PatternControlCheck())->check($candidate, $candles, $candIdx, $config);
            if (!$confirm['confirm_pass']) {
                return array_merge($diagBase, [
                    'symbol'              => $symbol,
                    'candidate_found'     => true,
                    'candidate_side'      => $side,
                    'primary_pattern'     => 'double_top',
                    'confirm_status'      => $confirm['confirm_status'],
                    'confirm_bars_waited' => $confirm['confirm_bars_waited'],
                    'candidate_expired'   => $confirm['candidate_expired'],
                    'final_signal_status' => 'confirm_pending',
                    'reject_reason'       => $confirm['reject_reason'],
                    'signal'              => null,
                ]);
            }
        } else {
            $confirm = ['confirm_status' => 'confirm_pass', 'confirm_bar_close' => null, 'confirm_bars_waited' => 0];
        }

        $this->requireLogic('signal');
        $signal = (new \Modules\Strategy\Pattern\Logic\PatternSignal())->build(
            $symbol, $candidate, $confirm,
            array_merge($diagBase, $corridor, $wave),
            date('c')
        );

        return array_merge($diagBase, [
            'symbol'              => $symbol,
            'candidate_found'     => true,
            'candidate_side'      => $side,
            'primary_pattern'     => 'double_top',
            'confirm_status'      => 'confirm_pass',
            'confirm_bars_waited' => $confirm['confirm_bars_waited'] ?? 0,
            'candidate_expired'   => false,
            'final_signal_status' => 'emitted',
            'reject_reason'       => null,
            'signal'              => $signal,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function reject(array $diag, string $symbol, string $side, string $pattern, ?string $reason): array
    {
        return array_merge($diag, [
            'symbol'              => $symbol,
            'candidate_found'     => false,
            'candidate_side'      => $side,
            'primary_pattern'     => $pattern,
            'confirm_status'      => null,
            'confirm_bars_waited' => 0,
            'candidate_expired'   => false,
            'final_signal_status' => 'rejected',
            'reject_reason'       => $reason,
            'signal'              => null,
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

    private function accumulateStats(array $stats, array $result): array
    {
        $regime       = $result['market_regime']       ?? 'unknown';
        $pattern      = $result['primary_pattern']      ?? '';
        $fss          = $result['final_signal_status']  ?? '';
        $rejectReason = $result['reject_reason']        ?? null;
        $confirmStatus = $result['confirm_status']      ?? '';

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

        // ── Corridor ───────────────────────────
        if (!empty($result['current_bucket'])) {
            $inc($stats, 'corridor_pass_total');
        } else {
            $inc($stats, 'corridor_rejected_total');
        }

        // ── Bucket (allowed/rejected per side) ─
        if ($result['bucket_allowed_long']  ?? false) { $inc($stats, 'bucket_allowed_total'); }
        if ($result['bucket_allowed_short'] ?? false) { $inc($stats, 'bucket_allowed_total'); }
        if ($rejectReason !== null && str_contains((string)$rejectReason, 'bucket')) {
            $inc($stats, 'bucket_rejected_total');
        }

        // ── Wave ───────────────────────────────
        if (($result['wave_state'] ?? '') === 'corrective') {
            $inc($stats, 'wave_pass_total');
        } else {
            $inc($stats, 'wave_rejected_total');
        }

        // ── Pattern ────────────────────────────
        if ($pattern === 'double_bottom')  { $inc($stats, 'double_bottom_found_total'); }
        if ($pattern === 'double_top')     { $inc($stats, 'double_top_found_total'); }
        if ($result['candidate_found'] ?? false) {
            $inc($stats, 'setup_candidates_total');
        } elseif ($pattern !== '' && $fss === 'rejected') {
            $inc($stats, 'pattern_rejected_total');
        }

        // ── Control check ──────────────────────
        if ($confirmStatus === 'confirm_pass')      { $inc($stats, 'control_check_pass_total'); }
        if ($result['candidate_expired'] ?? false)  { $inc($stats, 'control_check_expired_total'); }
        if ($confirmStatus !== '' && $confirmStatus !== 'confirm_pass') {
            $inc($stats, 'control_check_failed_total');
        }

        // ── Signal ─────────────────────────────
        if ($fss === 'emitted')    { $inc($stats, 'final_signals_total'); }

        // ── Reject reason distribution ─────────
        if ($rejectReason !== null && $rejectReason !== '') {
            $dist = (array)($stats['reject_reason_distribution'] ?? []);
            $dist[$rejectReason] = ($dist[$rejectReason] ?? 0) + 1;
            $stats['reject_reason_distribution'] = $dist;
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
            'double_bottom_found_total'    => 0,
            'double_top_found_total'       => 0,
            'pattern_rejected_total'       => 0,
            'setup_candidates_total'       => 0,
            'control_check_pass_total'     => 0,
            'control_check_expired_total'  => 0,
            'control_check_failed_total'   => 0,
            'final_signals_total'          => 0,
            'current_batch_size'           => 0,
            'last_updated_at'              => null,
            'reject_reason_distribution'   => (object)[],
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
