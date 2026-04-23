<?php

declare(strict_types=1);

/**
 * Stop Manager Module — Service
 *
 * Architecture:
 *   strategy modules → produce signals and position parameters
 *   bot module       → owns position lifecycle (active_positions.json)
 *   stop_manager     → sole owner of stop-loss computation and state
 *   profit manager   → separate, not implemented yet
 *
 * This module must NOT place or move stops on any exchange.
 * All computation is local/paper only.
 *
 * Stop modes:
 *   entry_liq_percent — stop is placed above liq (long) or below liq (short)
 *                       by a fraction of the entry↔liq distance.
 *
 *   For long:  stop = liq_price + buffer_pct × (entry_price − liq_price)
 *   For short: stop = liq_price − buffer_pct × (liq_price − entry_price)
 *
 *   If liq_price is not available in the position record, an estimate is
 *   derived from entry_price and bot_leverage (isolated-margin approximation):
 *     long:  liq_estimate = entry_price × (1 − 1 / leverage)
 *     short: liq_estimate = entry_price × (1 + 1 / leverage)
 *   Positions with leverage ≤ 0 where no liq_price exists are skipped.
 *
 * Breakeven / profit-lock rule (optional):
 *   When ROI% ≥ breakeven_trigger_roi, the stop is shifted to lock
 *   breakeven_profit_lock_roi.  Requires current_price on the position.
 *   ROI = (current_price − entry_price) / entry_price × 100 × leverage (long)
 *       = (entry_price − current_price) / entry_price × 100 × leverage (short)
 *
 * Stop state values:
 *   active    — computed and current
 *   no_liq    — cannot compute; liq_price unavailable and leverage unusable
 *   stale     — position closed; stop kept as closed reference
 *
 * Execution modes:
 *   disabled — initialize storage only; no stop computation
 *   paper    — full local computation; no exchange interaction
 */

namespace Modules\StopManager;

final class StopManagerService
{
    private static ?self $instance = null;
    private string $moduleDir;
    private string $repoRoot;

    public function __construct(?string $moduleDir = null)
    {
        if ($moduleDir !== null) {
            $this->moduleDir = rtrim($moduleDir, '/');
        } else {
            $paths = \Core\System\SystemPaths::instance();
            $this->moduleDir = rtrim(
                $paths->has('stop_manager.stop_manager')
                    ? $paths->get('stop_manager.stop_manager')
                    : $paths->get('stop_manager'),
                '/'
            );
        }
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
            return StopManagerBootstrap::instance($this->moduleDir)->load()['config'];
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

    public function getStops(): array
    {
        return $this->readJson('storage/stops.json', []);
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
     * CronManager / manual entry-point: process one tick.
     *
     * 1. Ensure storage files exist.
     * 2. Load config.
     * 3. If disabled: write truthful last_run and return.
     * 4. Load active positions from bot storage.
     * 5. Load existing stops.
     * 6. Process each position: initialize or recalculate stop, apply breakeven.
     * 7. Mark stops for positions that are no longer active.
     * 8. Persist stops, stats, last_run, runtime snapshot.
     */
    public function tick(): void
    {
        $this->initializeStorage();

        $config = $this->getConfig();
        if (empty($config)) {
            return;
        }

        if (!(bool)($config['enabled'] ?? false)) {
            $this->writeJson('storage/last_run.json', array_merge(
                $this->readJson('storage/last_run.json', []),
                [
                    'status'         => 'disabled',
                    'tick_at'        => date('c'),
                    'module_enabled' => false,
                    'module_mode'    => $config['mode'] ?? 'disabled',
                ]
            ));
            return;
        }

        $tickAt = date('c');
        $tStart = microtime(true);
        $mode   = (string)($config['mode'] ?? 'disabled');

        // ── 1. Load state ──────────────────────────────────────────────────────
        $positions = $this->loadBotPositions($config);
        $stops     = $this->readJson('storage/stops.json', []);
        $stats     = array_merge($this->zeroStats(), $this->getStats());

        // ── 2. Process ─────────────────────────────────────────────────────────
        $result = $this->processStops($positions, $stops, $config, $mode, $tickAt);
        $stops  = $result['stops'];

        // ── 3. Update stats ────────────────────────────────────────────────────
        $stats['ticks_total']                   += 1;
        $stats['positions_seen_total']           += $result['positions_seen'];
        $stats['stops_initialized_total']        += $result['stops_initialized'];
        $stats['stops_recalculated_total']       += $result['stops_recalculated'];
        $stats['breakeven_applied_total']        += $result['breakeven_applied'];
        $stats['stops_closed_reference_total']   += $result['stops_closed_reference'];
        $stats['positions_without_liq_total']    += $result['positions_without_liq'];
        $stats['stops_active_total']              = count(array_filter(
            $stops,
            static fn(array $s) => ($s['stop_state'] ?? '') === 'active'
        ));

        // ── 4. Persist ─────────────────────────────────────────────────────────
        $this->writeJson('storage/stops.json', array_values($stops));
        $this->writeJson('storage/stats.json', $stats);

        $elapsed = round(microtime(true) - $tStart, 4);

        $lastRun = [
            'status'                  => 'ok',
            'tick_at'                 => $tickAt,
            'elapsed_sec'             => $elapsed,
            'module_enabled'          => true,
            'module_mode'             => $mode,
            'positions_seen'          => $result['positions_seen'],
            'stops_initialized'       => $result['stops_initialized'],
            'stops_recalculated'      => $result['stops_recalculated'],
            'breakeven_applied'       => $result['breakeven_applied'],
            'stops_closed_reference'  => $result['stops_closed_reference'],
            'positions_without_liq'   => $result['positions_without_liq'],
            'stops_active_count'      => (int)($stats['stops_active_total'] ?? 0),
            'ticks_total'             => (int)($stats['ticks_total'] ?? 0),
        ];

        $this->writeJson('storage/last_run.json', $lastRun);
        $this->writeRuntimeSnapshot($config, $lastRun, (int)($stats['stops_active_total'] ?? 0));
    }

    // =========================================================================
    // Core stop processing
    // =========================================================================

    /**
     * Process all active positions against existing stops.
     *
     * @return array{
     *   stops: array,
     *   positions_seen: int,
     *   stops_initialized: int,
     *   stops_recalculated: int,
     *   breakeven_applied: int,
     *   stops_closed_reference: int,
     *   positions_without_liq: int,
     * }
     */
    private function processStops(
        array $positions,
        array $stops,
        array $config,
        string $mode,
        string $tickAt
    ): array {
        $positionsSeen         = 0;
        $stopsInitialized      = 0;
        $stopsRecalculated     = 0;
        $breakevenApplied      = 0;
        $stopsClosedReference  = 0;
        $positionsWithoutLiq   = 0;

        $isPaperMode = in_array($mode, ['paper'], true);

        // Index positions by execution key
        $posMap = [];
        foreach ($positions as $pos) {
            $k = $this->positionKey($pos);
            if ($k !== '' && ($pos['position_status'] ?? '') === 'open') {
                $posMap[$k] = $pos;
            }
        }

        // Index stops by execution key
        $stopMap = [];
        foreach ($stops as $stop) {
            $k = $this->positionKey($stop);
            if ($k !== '') {
                $stopMap[$k] = $stop;
            }
        }

        if ($isPaperMode) {
            // ── Process active positions ─────────────────────────────────────
            foreach ($posMap as $key => $pos) {
                $positionsSeen++;
                $bufferPct = (float)($config['stop_from_liq_buffer_pct'] ?? 0.05);

                // Resolve liq_price
                $liqPrice   = isset($pos['liq_price']) && (float)$pos['liq_price'] > 0.0
                    ? (float)$pos['liq_price']
                    : $this->estimateLiqPrice($pos);

                if ($liqPrice === null) {
                    $positionsWithoutLiq++;
                    // Record no_liq stop state so the position is tracked
                    $isNew = !isset($stopMap[$key]);
                    $stopMap[$key] = $this->buildNoLiqStop($pos, $tickAt);
                    if ($isNew) {
                        $stopsInitialized++;
                    }
                    continue;
                }

                $entryPrice = (float)($pos['entry_price'] ?? 0.0);
                $side       = (string)($pos['side'] ?? 'long');

                $stopPrice = $this->calcStopPrice($side, $entryPrice, $liqPrice, $bufferPct);

                if (!isset($stopMap[$key])) {
                    // New stop
                    $stopMap[$key] = $this->buildStop($pos, $stopPrice, $liqPrice, $tickAt, 'stop_initialized');
                    $stopsInitialized++;
                    $this->appendActionLog([
                        'timestamp'      => $tickAt,
                        'event_type'     => 'stop_initialized',
                        'strategy_id'    => $pos['strategy_id']    ?? '',
                        'owner_strategy' => $pos['owner_strategy'] ?? '',
                        'signal_id'      => $pos['signal_id']      ?? '',
                        'symbol'         => $pos['symbol']         ?? '',
                        'side'           => $side,
                        'entry_price'    => $entryPrice,
                        'liq_price'      => $liqPrice,
                        'stop_price'     => $stopPrice,
                        'execution_mode' => $pos['execution_mode'] ?? 'paper',
                        'reason'         => 'stop_initialized',
                    ]);
                } else {
                    // Recalculate: check if stop price has changed meaningfully (>0.001%)
                    $prevStop = $stopMap[$key];
                    $prevStopPrice = (float)($prevStop['stop_price'] ?? 0.0);
                    $recalcReason  = null;

                    if (abs($stopPrice - $prevStopPrice) > ($entryPrice * 0.00001)) {
                        $recalcReason = 'stop_recalculated';
                    }

                    // Apply breakeven if not yet applied
                    $breakevenWasApplied = (bool)($prevStop['breakeven_applied'] ?? false);
                    $breakEnabled        = (bool)($config['breakeven_enabled'] ?? false);
                    $currentPrice        = isset($pos['current_price'])
                        ? (float)$pos['current_price']
                        : null;

                    if ($breakEnabled && !$breakevenWasApplied && $currentPrice !== null && $entryPrice > 0.0) {
                        $leverage = max(1, (int)($pos['bot_leverage'] ?? 1));
                        $roi      = $this->calcRoi($side, $entryPrice, $currentPrice, $leverage);
                        $trigger  = (float)($config['breakeven_trigger_roi'] ?? 10.0);
                        $lockRoi  = (float)($config['breakeven_profit_lock_roi'] ?? 3.0);

                        if ($roi >= $trigger) {
                            $beStopPrice   = $this->calcBreakevenStop($side, $entryPrice, $leverage, $lockRoi);
                            $stopPrice     = $beStopPrice;
                            $recalcReason  = 'breakeven_applied';
                            $breakevenApplied++;
                            $stopMap[$key]['breakeven_applied'] = true;

                            $this->appendActionLog([
                                'timestamp'      => $tickAt,
                                'event_type'     => 'breakeven_applied',
                                'strategy_id'    => $pos['strategy_id']    ?? '',
                                'owner_strategy' => $pos['owner_strategy'] ?? '',
                                'signal_id'      => $pos['signal_id']      ?? '',
                                'symbol'         => $pos['symbol']         ?? '',
                                'side'           => $side,
                                'entry_price'    => $entryPrice,
                                'current_price'  => $currentPrice,
                                'roi'            => $roi,
                                'stop_price'     => $stopPrice,
                                'execution_mode' => $pos['execution_mode'] ?? 'paper',
                                'reason'         => 'breakeven_applied',
                            ]);
                        }
                    }

                    if ($recalcReason !== null && $recalcReason !== 'breakeven_applied') {
                        $stopsRecalculated++;
                        $this->appendActionLog([
                            'timestamp'      => $tickAt,
                            'event_type'     => 'stop_recalculated',
                            'strategy_id'    => $pos['strategy_id']    ?? '',
                            'owner_strategy' => $pos['owner_strategy'] ?? '',
                            'signal_id'      => $pos['signal_id']      ?? '',
                            'symbol'         => $pos['symbol']         ?? '',
                            'side'           => $side,
                            'entry_price'    => $entryPrice,
                            'stop_price'     => $stopPrice,
                            'execution_mode' => $pos['execution_mode'] ?? 'paper',
                            'reason'         => 'stop_recalculated',
                        ]);
                    }

                    $stopMap[$key]['stop_price']      = $stopPrice;
                    $stopMap[$key]['liq_price']       = $liqPrice;
                    $stopMap[$key]['stop_state']      = 'active';
                    $stopMap[$key]['last_updated_at'] = $tickAt;
                    if ($recalcReason !== null) {
                        $stopMap[$key]['transition_reason'] = $recalcReason;
                    }
                }
            }

            // ── Mark stops for closed/missing positions ───────────────────────
            foreach ($stopMap as $key => $stop) {
                if (isset($posMap[$key])) {
                    continue;
                }
                if (($stop['stop_state'] ?? '') === 'stale') {
                    continue;
                }
                $stopMap[$key]['stop_state']      = 'stale';
                $stopMap[$key]['last_updated_at'] = $tickAt;
                $stopMap[$key]['transition_reason'] = 'stop_position_closed_reference';
                $stopsClosedReference++;

                $this->appendActionLog([
                    'timestamp'      => $tickAt,
                    'event_type'     => 'stop_position_closed_reference',
                    'strategy_id'    => $stop['strategy_id']    ?? '',
                    'owner_strategy' => $stop['owner_strategy'] ?? '',
                    'signal_id'      => $stop['signal_id']      ?? '',
                    'symbol'         => $stop['symbol']         ?? '',
                    'side'           => $stop['side']           ?? '',
                    'entry_price'    => $stop['entry_price']    ?? 0.0,
                    'stop_price'     => $stop['stop_price']     ?? 0.0,
                    'execution_mode' => $stop['execution_mode'] ?? 'paper',
                    'reason'         => 'position_no_longer_active',
                ]);
            }
        }

        return [
            'stops'                  => $stopMap,
            'positions_seen'         => $positionsSeen,
            'stops_initialized'      => $stopsInitialized,
            'stops_recalculated'     => $stopsRecalculated,
            'breakeven_applied'      => $breakevenApplied,
            'stops_closed_reference' => $stopsClosedReference,
            'positions_without_liq'  => $positionsWithoutLiq,
        ];
    }

    // =========================================================================
    // Stop math
    // =========================================================================

    /**
     * Calculate stop price for entry_liq_percent mode.
     *
     * Long:  stop = liq_price + buffer_pct × (entry_price − liq_price)
     * Short: stop = liq_price − buffer_pct × (liq_price − entry_price)
     */
    private function calcStopPrice(string $side, float $entryPrice, float $liqPrice, float $bufferPct): float
    {
        if ($side === 'long') {
            $distance = $entryPrice - $liqPrice;
            return $liqPrice + $bufferPct * $distance;
        }
        // short
        $distance = $liqPrice - $entryPrice;
        return $liqPrice - $bufferPct * $distance;
    }

    /**
     * Calculate ROI for breakeven check.
     *
     * Long:  roi = (current − entry) / entry × 100 × leverage
     * Short: roi = (entry − current) / entry × 100 × leverage
     */
    private function calcRoi(string $side, float $entryPrice, float $currentPrice, int $leverage): float
    {
        if ($entryPrice <= 0.0) {
            return 0.0;
        }
        if ($side === 'long') {
            return ($currentPrice - $entryPrice) / $entryPrice * 100.0 * $leverage;
        }
        return ($entryPrice - $currentPrice) / $entryPrice * 100.0 * $leverage;
    }

    /**
     * Calculate breakeven stop price that locks the given ROI%.
     *
     * Long:  stop = entry × (1 + lock_roi / (100 × leverage))
     * Short: stop = entry × (1 − lock_roi / (100 × leverage))
     */
    private function calcBreakevenStop(string $side, float $entryPrice, int $leverage, float $lockRoi): float
    {
        $leverageSafe = max(1, $leverage);
        $fraction     = $lockRoi / (100.0 * $leverageSafe);
        if ($side === 'long') {
            return $entryPrice * (1.0 + $fraction);
        }
        return $entryPrice * (1.0 - $fraction);
    }

    /**
     * Estimate liquidation price when it is not provided in the position record.
     * Uses simplified isolated-margin approximation.
     *
     * Long:  liq_estimate = entry × (1 − 1 / leverage)
     * Short: liq_estimate = entry × (1 + 1 / leverage)
     *
     * Returns null if leverage is 0 or entry_price is 0.
     */
    private function estimateLiqPrice(array $pos): ?float
    {
        $entryPrice = (float)($pos['entry_price'] ?? 0.0);
        $leverage   = (int)($pos['bot_leverage']  ?? 0);
        $side       = (string)($pos['side']        ?? 'long');

        if ($entryPrice <= 0.0 || $leverage <= 0) {
            return null;
        }

        if ($side === 'long') {
            return $entryPrice * (1.0 - 1.0 / $leverage);
        }
        return $entryPrice * (1.0 + 1.0 / $leverage);
    }

    // =========================================================================
    // Stop record builders
    // =========================================================================

    private function buildStop(
        array $pos,
        float $stopPrice,
        float $liqPrice,
        string $tickAt,
        string $reason
    ): array {
        return [
            'owner_strategy'    => (string)($pos['owner_strategy'] ?? ''),
            'strategy_id'       => (string)($pos['strategy_id']    ?? ''),
            'signal_id'         => (string)($pos['signal_id']      ?? ''),
            'symbol'            => (string)($pos['symbol']         ?? ''),
            'side'              => (string)($pos['side']           ?? 'long'),
            'entry_price'       => (float)($pos['entry_price']     ?? 0.0),
            'liq_price'         => $liqPrice,
            'execution_mode'    => (string)($pos['execution_mode'] ?? 'paper'),
            'stop_mode'         => 'entry_liq_percent',
            'stop_price'        => $stopPrice,
            'stop_state'        => 'active',
            'breakeven_applied' => false,
            'last_updated_at'   => $tickAt,
            'created_at'        => $tickAt,
            'transition_reason' => $reason,
        ];
    }

    private function buildNoLiqStop(array $pos, string $tickAt): array
    {
        return [
            'owner_strategy'    => (string)($pos['owner_strategy'] ?? ''),
            'strategy_id'       => (string)($pos['strategy_id']    ?? ''),
            'signal_id'         => (string)($pos['signal_id']      ?? ''),
            'symbol'            => (string)($pos['symbol']         ?? ''),
            'side'              => (string)($pos['side']           ?? 'long'),
            'entry_price'       => (float)($pos['entry_price']     ?? 0.0),
            'liq_price'         => null,
            'execution_mode'    => (string)($pos['execution_mode'] ?? 'paper'),
            'stop_mode'         => 'entry_liq_percent',
            'stop_price'        => null,
            'stop_state'        => 'no_liq',
            'breakeven_applied' => false,
            'last_updated_at'   => $tickAt,
            'created_at'        => $tickAt,
            'transition_reason' => 'no_liq_price_available',
        ];
    }

    // =========================================================================
    // Bot positions reader
    // =========================================================================

    private function loadBotPositions(array $config): array
    {
        $relPath = (string)($config['bot_positions_path'] ?? 'modules/bot/storage/active_positions.json');
        $absPath = str_starts_with($relPath, '/')
            ? $relPath
            : $this->repoRoot . '/' . $relPath;

        if (!file_exists($absPath)) {
            return [];
        }
        $raw = file_get_contents($absPath);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    // =========================================================================
    // Storage initialization
    // =========================================================================

    private function initializeStorage(): void
    {
        $storageDir = $this->moduleDir . '/storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $defaults = [
            'storage/stops.json'    => [],
            'storage/last_run.json' => [
                'status'                  => 'never_run',
                'tick_at'                 => null,
                'elapsed_sec'             => 0,
                'module_enabled'          => false,
                'module_mode'             => 'disabled',
                'positions_seen'          => 0,
                'stops_initialized'       => 0,
                'stops_recalculated'      => 0,
                'breakeven_applied'       => 0,
                'stops_closed_reference'  => 0,
                'positions_without_liq'   => 0,
                'stops_active_count'      => 0,
                'ticks_total'             => 0,
            ],
            'storage/stats.json' => $this->zeroStats(),
        ];

        foreach ($defaults as $relPath => $default) {
            $absPath = $this->moduleDir . '/' . $relPath;
            if (!file_exists($absPath)) {
                $this->writeJson($relPath, $default);
            }
        }

        // Ensure actions_log.ndjson exists (append-only; not overwritten)
        $logPath = $this->moduleDir . '/storage/actions_log.ndjson';
        if (!file_exists($logPath)) {
            @file_put_contents($logPath, '');
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function positionKey(array $item): string
    {
        $signalId = (string)($item['signal_id'] ?? '');
        if ($signalId === '') {
            return '';
        }
        $stratId = (string)($item['strategy_id'] ?? $item['owner_strategy'] ?? 'unknown');
        return $stratId . ':' . $signalId;
    }

    private function zeroStats(): array
    {
        return [
            'ticks_total'                  => 0,
            'positions_seen_total'         => 0,
            'stops_initialized_total'      => 0,
            'stops_recalculated_total'     => 0,
            'breakeven_applied_total'      => 0,
            'stops_closed_reference_total' => 0,
            'positions_without_liq_total'  => 0,
            'stops_active_total'           => 0,
        ];
    }

    private function appendActionLog(array $event): void
    {
        $path = $this->moduleDir . '/storage/actions_log.ndjson';
        $line = json_encode($event, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    private function writeRuntimeSnapshot(array $config, array $lastRun, int $stopsActiveTotal): void
    {
        $snap = [
            'snapshot_at'          => date('c'),
            'module_id'            => 'stop_manager',
            'mode'                 => $config['mode']    ?? 'disabled',
            'enabled'              => $config['enabled'] ?? false,
            'tick_at'              => $lastRun['tick_at']        ?? null,
            'last_tick_result'     => $lastRun['status']         ?? 'ok',
            'positions_seen_total' => $lastRun['positions_seen'] ?? 0,
            'stops_active_total'   => $stopsActiveTotal,
            'config_valid'         => true,
            'effective_config'     => [
                'mode'    => $config['mode']    ?? 'disabled',
                'enabled' => $config['enabled'] ?? false,
            ],
        ];

        $path  = $this->moduleDir . '/config/runtime_snapshot.php';
        $lines = [
            "<?php\n\ndeclare(strict_types=1);\n\n",
            "/**\n * Stop Manager Module — Runtime Snapshot\n",
            " * Auto-written after each tick. Do not edit manually.\n",
            " * snapshot_at: " . $snap['snapshot_at'] . "\n */\n\n",
            "return " . var_export($snap, true) . ";\n",
        ];
        @file_put_contents($path, implode('', $lines));
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
