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
     */
    public function queueRun(): array
    {
        $config = $this->getConfig();
        if (empty($config)) {
            return ['ok' => false, 'error' => 'Config load failed'];
        }

        $universe = $this->buildUniverse($config);

        $state = [
            'status'      => 'queued',
            'queued_at'   => date('c'),
            'symbols'     => $universe,
            'total'       => count($universe),
            'cursor'      => 0,
            'processed'   => 0,
            'found'       => 0,
            'errors'      => [],
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

        if ($state['status'] !== 'running') {
            return;
        }

        $config  = $this->getConfig();
        $batchSz = max(1, (int)($config['batch_size'] ?? 20));
        $maxSec  = max(10, (int)($config['max_runtime_seconds'] ?? 55));

        $symbols  = (array)($state['symbols']   ?? []);
        $cursor   = (int)($state['cursor']       ?? 0);
        $total    = count($symbols);
        $tStart   = time();

        $stats    = $this->getStats();
        $signals  = $this->getSignals();

        $regime   = $this->readJson('storage/market_regime.json', []);
        $regimeStr = (string)($regime['regime'] ?? 'unknown');

        $processed = 0;
        $found     = 0;

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
            } catch (\Throwable $e) {
                $state['errors'][] = $symbol . ': ' . $e->getMessage();
            }
        }

        $state['cursor']    = $cursor;
        $state['processed'] = (int)($state['processed'] ?? 0) + $processed;
        $state['found']     = (int)($state['found']     ?? 0) + $found;

        if ($cursor >= $total) {
            $state['status']       = 'done';
            $state['completed_at'] = date('c');
        }

        $this->writeJson('storage/run_state.json', $state);
        $this->writeJson('storage/signals.json',   array_values($signals));
        $this->writeJson('storage/stats.json',      $stats);

        $lastRun = [
            'run_at'      => date('c'),
            'status'      => $state['status'],
            'processed'   => $state['processed'],
            'found'       => $state['found'],
            'errors_count' => count($state['errors'] ?? []),
        ];
        $this->writeJson('storage/last_run.json', $lastRun);
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

        $state = $this->getRunState();
        $state['status'] = 'running';
        $this->writeJson('storage/run_state.json', $state);

        // Override batch size to run everything in one pass
        $config            = $this->getConfig();
        $config['batch_size'] = count($state['symbols'] ?? []) + 1;
        $this->writeJson('storage/run_state.json', $state);

        $this->tickBatch();
        return $this->getRunState();
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
        $regime  = $result['market_regime']  ?? 'unknown';
        $pattern = $result['primary_pattern'] ?? '';
        $fss     = $result['final_signal_status'] ?? '';

        $inc = function (array &$s, string $key) { $s[$key] = ($s[$key] ?? 0) + 1; };

        if ($regime === 'bullish')    { $inc($stats, 'regime_bullish_total'); }
        elseif ($regime === 'bearish') { $inc($stats, 'regime_bearish_total'); }
        elseif ($regime === 'mixed')   { $inc($stats, 'regime_mixed_total'); }
        elseif ($regime === 'transition') { $inc($stats, 'regime_transition_total'); }

        $tDir = $result['trend_direction'] ?? 'unknown';
        if ($tDir !== 'unknown') { $inc($stats, 'trend_pass_total'); }

        if (!empty($result['current_bucket'])) { $inc($stats, 'corridor_pass_total'); }
        if ($result['bucket_allowed_long']  ?? false) { $inc($stats, 'bucket_allowed_total'); }
        if ($result['bucket_allowed_short'] ?? false) { $inc($stats, 'bucket_allowed_total'); }

        if (($result['wave_state'] ?? '') === 'corrective') { $inc($stats, 'wave_pass_total'); }
        else { $inc($stats, 'wave_rejected_total'); }

        if ($pattern === 'double_bottom') { $inc($stats, 'double_bottom_found_total'); }
        if ($pattern === 'double_top')    { $inc($stats, 'double_top_found_total'); }

        $confirmStatus = $result['confirm_status'] ?? '';
        if ($confirmStatus === 'confirm_pass')    { $inc($stats, 'control_check_pass_total'); }
        if ($result['candidate_expired'] ?? false) { $inc($stats, 'control_check_expired_total'); }

        if ($fss === 'emitted') { $inc($stats, 'final_signals_total'); }

        return $stats;
    }

    private function buildUniverse(array $config): array
    {
        $mode     = (string)($config['universe_mode']    ?? 'all');
        $excluded = (array)($config['excluded_symbols']  ?? []);
        $maxCount = (int)($config['max_symbols_per_run'] ?? 0);

        if ($mode === 'manual_list') {
            $symbols = (array)($config['allowed_symbols'] ?? []);
        } else {
            // Try to load from market registry; fallback to empty list for now
            try {
                $registry = \Core\System\SystemPaths::instance()->get('parser.parser1_market_registry');
                $active   = json_decode(
                    file_get_contents($registry . '/storage/active_symbols.json') ?: '[]',
                    true
                ) ?: [];
                $symbols = array_column($active, 'symbol') ?: array_keys($active);
            } catch (\Throwable) {
                $symbols = [];
            }
        }

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
