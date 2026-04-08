<?php
declare(strict_types=1);

namespace Modules\System\ProfitManager\Lib;

/**
 * Profit Manager
 * 
 * Main cycle logic for trailing stop management.
 * Step 1: Load config + locks
 * Step 2: Fetch exchange positions
 * Step 3: Select managed positions
 * Step 4: Apply rules (step trailing, dumb trailing)
 */
class ProfitManager
{
    private array $config;
    private Store $store;
    private RiskMath $riskMath;
    private PositionSelector $selector;
    private StopApplier $applier;
    private Validator $validator;
    private $gateway;
    
    /** @var array Instrument meta cache */
    private array $instrumentCache = [];
    
    public function __construct(
        array $config,
        Store $store,
        RiskMath $riskMath,
        PositionSelector $selector,
        StopApplier $applier,
        Validator $validator,
        $gateway
    ) {
        $this->config = $config;
        $this->store = $store;
        $this->riskMath = $riskMath;
        $this->selector = $selector;
        $this->applier = $applier;
        $this->validator = $validator;
        $this->gateway = $gateway;
    }
    
    /**
     * Main execution cycle
     * 
     * @return array Execution result
     */
    public function run(): array
    {
        $items = [];
        $errors = [];
        $warnings = [];
        
        $stats = [
            'step_trailing' => ['applied' => 0, 'skipped' => 0, 'failed' => 0],
            'dumb_trailing' => ['applied' => 0, 'skipped' => 0, 'failed' => 0],
        ];
        
        // Step 2: Fetch exchange positions
        $positionsResult = $this->gateway->getPositions();
        if (!($positionsResult['ok'] ?? false)) {
            $errors[] = 'fetch_positions_failed: ' . ($positionsResult['error'] ?? 'unknown');
            return [
                'positions_total' => 0,
                'positions_managed' => 0,
                'items' => $items,
                'stats' => $stats,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }
        
        $positions = $positionsResult['positions'] ?? [];
        $positionsTotal = count($positions);
        
        // Step 3: Select managed positions
        $managed = $this->selector->getManagedSymbols($positions);
        $positionsManaged = count($managed);
        
        // Step 4: Apply rules for each managed position
        foreach ($managed as $symbol => $ctx) {
            $itemResult = $this->processPosition($symbol, $ctx);
            $items[] = $itemResult;
            
            // Update stats
            if (isset($itemResult['step_trailing'])) {
                $action = $itemResult['step_trailing']['action'] ?? 'skip';
                if ($action === 'step_sl_update') {
                    $stats['step_trailing']['applied']++;
                } elseif ($action === 'failed') {
                    $stats['step_trailing']['failed']++;
                } else {
                    $stats['step_trailing']['skipped']++;
                }
            }
            
            if (isset($itemResult['dumb_trailing'])) {
                $action = $itemResult['dumb_trailing']['action'] ?? 'skip';
                if ($action === 'dumb_trailing_set') {
                    $stats['dumb_trailing']['applied']++;
                } elseif ($action === 'failed') {
                    $stats['dumb_trailing']['failed']++;
                } else {
                    $stats['dumb_trailing']['skipped']++;
                }
            }
            
            // Collect errors
            if (!empty($itemResult['errors'])) {
                $errors = array_merge($errors, $itemResult['errors']);
            }
            
            // Update symbol status
            $this->updateSymbolStatus($symbol, $ctx, $itemResult);
        }
        
        return [
            'positions_total' => $positionsTotal,
            'positions_managed' => $positionsManaged,
            'items' => $items,
            'stats' => $stats,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
    
    /**
     * Process a single position
     * 
     * @param string $symbol Symbol
     * @param array $ctx Managed context
     * @return array Position result
     */
    private function processPosition(string $symbol, array $ctx): array
    {
        $position = $ctx['position'] ?? [];
        $result = [
            'symbol' => $symbol,
            'side' => $this->validator->normalizeSide($position['side'] ?? ''),
            'roi_pct' => null,
            'step_trailing' => null,
            'dumb_trailing' => null,
            'errors' => [],
        ];
        
        // Validate context
        $validation = $this->validator->validateManagedContext($ctx);
        if (!$validation['ok']) {
            $result['errors'] = $validation['errors'];
            return $result;
        }
        
        // Calculate ROI
        $positionIM = (float) ($position['positionIM'] ?? 0);
        $unrealisedPnl = (float) ($position['unrealisedPnl'] ?? 0);
        $roi = $this->riskMath->calculateRoiPct($unrealisedPnl, $positionIM);
        $result['roi_pct'] = $roi;
        
        // Get instrument meta for tick size
        $tickSize = $this->getTickSize($symbol);
        
        // Apply Step Trailing (priority 1)
        $stepResult = $this->processStepTrailing($symbol, $position, $ctx, $tickSize);
        $result['step_trailing'] = $stepResult;
        
        // Apply Dumb Trailing (priority 2)
        $dumbResult = $this->processDumbTrailing($symbol, $position, $ctx, $tickSize);
        $result['dumb_trailing'] = $dumbResult;
        
        return $result;
    }
    
    /**
     * Process step trailing for position
     */
    private function processStepTrailing(string $symbol, array $position, array $ctx, float $tickSize): array
    {
        // Check if should skip
        $skipReason = $this->validator->shouldSkipStepTrailing($position, $ctx, $this->riskMath);
        if ($skipReason !== null) {
            return [
                'action' => 'skip',
                'reason' => $skipReason,
            ];
        }
        
        // Calculate ROI and target lock
        $positionIM = (float) ($position['positionIM'] ?? 0);
        $unrealisedPnl = (float) ($position['unrealisedPnl'] ?? 0);
        $roi = $this->riskMath->calculateRoiPct($unrealisedPnl, $positionIM);
        
        $activationRoi = (float) ($ctx['activation_roi_pct'] ?? $this->config['step_trailing']['activation_roi_pct_default'] ?? 0);
        $stepRoi = (float) ($this->config['step_trailing']['step_roi_pct'] ?? 0);
        $lockBuffer = (float) ($this->config['step_trailing']['lock_buffer_roi_pct'] ?? 0);
        $lockFloor = (float) ($this->config['step_trailing']['lock_floor_roi_pct'] ?? 0);
        
        $targetLockRoi = $this->riskMath->calculateStepTrailingLockRoi(
            $roi,
            $activationRoi,
            $stepRoi,
            $lockBuffer,
            $lockFloor
        );
        
        if ($targetLockRoi === null) {
            return [
                'action' => 'skip',
                'reason' => 'no_valid_lock_roi',
            ];
        }
        
        // Calculate new SL price
        $side = $this->validator->normalizeSide($position['side'] ?? '');
        $entryPrice = (float) ($position['avgPrice'] ?? 0);
        $leverage = (float) ($ctx['leverage'] ?? 0);
        
        $candidateSL = $this->riskMath->calculateStepTrailingSL($side, $entryPrice, $targetLockRoi, $leverage);
        if ($candidateSL === null) {
            return [
                'action' => 'skip',
                'reason' => 'cannot_calculate_sl',
            ];
        }
        
        // Validate SL is on profitable side
        if (!$this->riskMath->isSLOnProfitableSide($side, $candidateSL, $entryPrice)) {
            return [
                'action' => 'skip',
                'reason' => 'sl_not_on_profitable_side',
            ];
        }
        
        // Validate SL is safe distance from current price
        $markPrice = (float) ($position['markPrice'] ?? 0);
        $lastPrice = (float) ($position['lastPrice'] ?? $markPrice);
        $refPrice = $this->riskMath->getReferencePrice($side, $markPrice, $lastPrice);
        $minDistancePctBase = (float) ($this->config['step_trailing']['min_distance_to_price_pct'] ?? 0.05);
        $minDistancePctEffective = $minDistancePctBase;
        if ($leverage > 0.0) {
            // Step trailing uses ROI (margin %) which already includes leverage. To avoid a hard block on high leverage,
            // scale min-distance (price %) by leverage.
            $minDistancePctEffective = $minDistancePctBase / max(1.0, $leverage);
        }

        if (!$this->riskMath->isSLSafeDistance($side, $candidateSL, $refPrice, $minDistancePctEffective)) {
            return [
                'action' => 'skip',
                'reason' => 'too_close_to_price',
                'debug' => [
                    'candidate_sl' => $candidateSL,
                    'ref_price' => $refPrice,
                    'min_distance_pct_base' => $minDistancePctBase,
                    'min_distance_pct_effective' => $minDistancePctEffective,
                    'leverage' => $leverage,
                ],
            ];
        }
        
        // Normalize by tick size
        $newSL = $this->riskMath->normalizeSLPrice($side, $candidateSL, $tickSize);
        
        // Validate ratchet (new SL must improve over old)
        $oldSL = (float) ($position['stopLoss'] ?? 0);
        if (!$this->riskMath->isSLImproving($side, $newSL, $oldSL)) {
            return [
                'action' => 'skip',
                'reason' => 'not_improving',
                'debug' => [
                    'old_sl' => $oldSL,
                    'new_sl' => $newSL,
                ],
            ];
        }
        
        // Apply the SL update
        $applyResult = $this->applier->applyStopLoss($symbol, $side, $newSL, $oldSL, $tickSize, [
            'roi' => $roi,
            'target_lock_roi' => $targetLockRoi,
            'leverage' => $leverage,
            'entry' => $entryPrice,
            'ref_price' => $refPrice,
        ]);
        
        return array_merge($applyResult, [
            'old_sl' => $oldSL,
            'new_sl' => $newSL,
            'roi' => $roi,
            'target_lock_roi' => $targetLockRoi,
        ]);
    }
    
    /**
     * Process dumb trailing for position
     */
    private function processDumbTrailing(string $symbol, array $position, array $ctx, float $tickSize): array
    {
        // Check if should skip
        $skipReason = $this->validator->shouldSkipDumbTrailing($position, $ctx, $this->riskMath);
        if ($skipReason !== null) {
            return [
                'action' => 'skip',
                'reason' => $skipReason,
            ];
        }
        
        // Check if already has trailing stop
        $existingTrailing = (float) ($position['trailingStop'] ?? 0);
        $existingActive = (float) ($position['activePrice'] ?? 0);
        
        if ($existingTrailing > 0) {
            // Check if needs re-arm
            $side = $this->validator->normalizeSide($position['side'] ?? '');
            $markPrice = (float) ($position['markPrice'] ?? 0);
            $lastPrice = (float) ($position['lastPrice'] ?? $markPrice);
            $refPrice = $this->riskMath->getReferencePrice($side, $markPrice, $lastPrice);
            
            $needsRearm = $this->needsTrailingRearm($side, $existingActive, $refPrice);
            if (!$needsRearm) {
                return [
                    'action' => 'skip',
                    'reason' => 'trailing_already_armed',
                ];
            }
        }
        
        // Calculate trailing parameters
        $side = $this->validator->normalizeSide($position['side'] ?? '');
        $leverage = (float) ($ctx['leverage'] ?? 0);
        $activationRoi = (float) ($ctx['activation_roi_pct'] ?? $this->config['dumb_trailing']['activation_roi_pct_default'] ?? $this->config['step_trailing']['activation_roi_pct_default'] ?? 0);
        $markPrice = (float) ($position['markPrice'] ?? 0);
        $lastPrice = (float) ($position['lastPrice'] ?? $markPrice);
        $refPrice = $this->riskMath->getReferencePrice($side, $markPrice, $lastPrice);
        
        $minDistancePct = (float) ($this->config['dumb_trailing']['min_distance_pct'] ?? 0);
        $drawdownFactor = (float) ($this->config['dumb_trailing']['drawdown_factor_default'] ?? 0);
        $epsilonPct = (float) ($this->config['dumb_trailing']['epsilon_pct'] ?? 0);
        
        $trailingStop = $this->riskMath->calculateDumbTrailingDistance(
            $refPrice,
            $activationRoi,
            $leverage,
            $minDistancePct,
            $drawdownFactor
        );
        
        $activePrice = $this->riskMath->calculateDumbTrailingActivePrice(
            $side,
            $refPrice,
            $epsilonPct,
            $tickSize
        );
        
        // Apply trailing
        $applyResult = $this->applier->applyDumbTrailing($symbol, $side, $trailingStop, $activePrice, [
            'leverage' => $leverage,
            'activation_roi' => $activationRoi,
            'ref_price' => $refPrice,
        ]);
        
        return array_merge($applyResult, [
            'trailing_stop' => $trailingStop,
            'active_price' => $activePrice,
        ]);
    }
    
    /**
     * Check if trailing needs re-arm
     */
    private function needsTrailingRearm(string $side, float $activePrice, float $refPrice): bool
    {
        if ($activePrice <= 0) {
            return true;
        }
        
        // LONG: activePrice should be <= refPrice to be armed
        // SHORT: activePrice should be >= refPrice to be armed
        if ($side === 'long') {
            return $activePrice > $refPrice;
        } else {
            return $activePrice < $refPrice;
        }
    }
    
    /**
     * Get tick size for symbol
     */
    private function getTickSize(string $symbol): float
    {
        // Check cache
        if (isset($this->instrumentCache[$symbol]['tickSize'])) {
            return $this->instrumentCache[$symbol]['tickSize'];
        }
        
        // Fetch from gateway
        $meta = $this->gateway->getInstrumentMeta($symbol);
        if ($meta !== null && isset($meta['tickSize'])) {
            $this->instrumentCache[$symbol] = $meta;
            return (float) $meta['tickSize'];
        }
        
        // Fallback from config
        return (float) ($this->config['exchange']['tick_size_fallback'] ?? 0.0001);
    }
    
    /**
     * Update symbol status in store
     */
    private function updateSymbolStatus(string $symbol, array $ctx, array $result): void
    {
        $position = $ctx['position'] ?? [];

        $status = [
            'last_seen_ts' => time(),
            'roi_pct' => $result['roi_pct'],
            'stop_loss' => (float) ($position['stopLoss'] ?? 0),
            'trailing_stop' => (float) ($position['trailingStop'] ?? 0),
            'active_price' => (float) ($position['activePrice'] ?? 0),

            // Summary (for UI compatibility)
            'last_action' => null,
            'last_action_ts' => null,
            'last_reason' => null,

            // Detailed (for debugging)
            'step_action' => null,
            'step_action_ts' => null,
            'step_reason' => null,
            'dumb_action' => null,
            'dumb_action_ts' => null,
            'dumb_reason' => null,
        ];

        // Record step trailing action
        if (isset($result['step_trailing'])) {
            $st = $result['step_trailing'];
            $status['step_action'] = $st['action'] ?? null;

            if (($st['action'] ?? '') === 'step_sl_update') {
                $status['step_action_ts'] = time();

                // Step trailing has priority in summary
                $status['last_action'] = 'step_sl_update';
                $status['last_action_ts'] = $status['step_action_ts'];

                $status['stop_loss'] = $st['new_sl'] ?? $status['stop_loss'];
            } else {
                $status['step_reason'] = $st['reason'] ?? null;

                // Only set summary reason if empty (do not override later)
                if ($status['last_reason'] === null) {
                    $status['last_reason'] = $status['step_reason'];
                }
            }
        }

        // Record dumb trailing action
        if (isset($result['dumb_trailing'])) {
            $dt = $result['dumb_trailing'];
            $status['dumb_action'] = $dt['action'] ?? null;

            if (($dt['action'] ?? '') === 'dumb_trailing_set') {
                $status['dumb_action_ts'] = time();
                $status['trailing_stop'] = $dt['trailing_stop'] ?? $status['trailing_stop'];
                $status['active_price'] = $dt['active_price'] ?? $status['active_price'];

                // Set summary only if step trailing did not act
                if ($status['last_action'] === null) {
                    $status['last_action'] = 'dumb_trailing_set';
                    $status['last_action_ts'] = $status['dumb_action_ts'];
                }
            } else {
                $status['dumb_reason'] = $dt['reason'] ?? null;

                // Do not overwrite step reason
                if ($status['last_action'] === null && $status['last_reason'] === null) {
                    $status['last_reason'] = $status['dumb_reason'];
                }
            }
        }

        $this->store->updateSymbolStatus($symbol, $status);
    }

    // =========================================================================
    // Shadow Trailing Mode (trailing_owner = profit_manager_shadow)
    // =========================================================================

    /**
     * Run shadow trailing pass for all open positions.
     * Does NOT call any exchange API — computes diagnostics only.
     *
     * @param array $positions     Raw exchange positions
     * @param array $botConfig     Bot config (for trailing parameters)
     * @return array Shadow result with per-position diagnostics
     */
    public function runShadow(array $positions, array $botConfig): array
    {
        $items = [];
        $ts = date('c');

        foreach ($positions as $position) {
            $symbol = (string)($position['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            $side = strtolower((string)($position['side'] ?? ''));
            if (!in_array($side, ['buy', 'sell', 'long', 'short'], true)) {
                continue;
            }
            // Normalize side
            if ($side === 'buy') $side = 'long';
            if ($side === 'sell') $side = 'short';

            // Position metrics
            $positionIM   = (float)($position['positionIM'] ?? 0);
            $unrealisedPnl = (float)($position['unrealisedPnl'] ?? 0);
            $markPrice    = (float)($position['markPrice'] ?? 0);
            $avgPrice     = (float)($position['avgPrice'] ?? 0);
            $leverage     = (float)($position['leverage'] ?? 1);

            $roiBybit = ($positionIM > 0) ? (($unrealisedPnl / $positionIM) * 100.0) : 0.0;
            $roiBybit = round($roiBybit, 4);

            // Determine trade_id / position key
            $tradeKey = $symbol . '_' . $side;
            if (!empty($position['orderId'])) {
                $tradeKey = (string)$position['orderId'];
            } elseif (!empty($position['trade_id'])) {
                $tradeKey = (string)$position['trade_id'];
            }

            // Load persisted shadow state for continuity between ticks
            $prevState = $this->store->loadShadowState($tradeKey);

            // Trailing config from bot config
            $execCfg          = is_array($botConfig['execution'] ?? null) ? $botConfig['execution'] : [];
            $activationRoiPct = (float)($execCfg['trailing_activation_roi'] ?? 3.5);
            $drawdownFactor   = (float)($execCfg['trailing_drawdown_factor'] ?? 0.5);
            $stepRoi          = (float)($execCfg['step_trailing_step_roi_pct'] ?? 2.0);
            $bufferRoi        = (float)($execCfg['step_trailing_lock_buffer_roi_pct'] ?? 0.5);

            // Peak ROI — monotonic: only increases
            $prevPeakRoi   = (float)($prevState['peak_roi'] ?? 0.0);
            $peakRoi       = max($prevPeakRoi, $roiBybit);
            $trailingArmed = (bool)($prevState['trailing_armed'] ?? false);
            $lastLockRoi   = (float)($prevState['last_lock_roi'] ?? 0.0);

            // Arm when activation threshold crossed
            if (!$trailingArmed && $activationRoiPct > 0 && $peakRoi >= $activationRoiPct) {
                $trailingArmed = true;
            }

            // Compute proposed stop (step trailing logic in shadow)
            $proposedStopPrice = (float)($prevState['proposed_stop_price'] ?? 0.0);
            $proposedLockRoi   = $lastLockRoi;
            $proposedAction    = 'hold';

            if ($trailingArmed && $stepRoi > 0 && $avgPrice > 0) {
                $steps = (int)floor(($peakRoi - $activationRoiPct) / $stepRoi);
                if ($steps > 0) {
                    $targetLockRoi = $activationRoiPct + ($steps * $stepRoi) - $bufferRoi;
                    $targetLockRoi = max(0.0, $targetLockRoi);

                    if ($targetLockRoi > $lastLockRoi) {
                        // Compute new SL price from locked ROI
                        $roiPerUnit = ($leverage > 0) ? ($targetLockRoi / 100.0 / $leverage) : 0.0;
                        if ($side === 'long') {
                            $newStop = $avgPrice * (1.0 + $roiPerUnit);
                        } else {
                            $newStop = $avgPrice * (1.0 - $roiPerUnit);
                        }
                        $proposedStopPrice = round($newStop, 8);
                        $proposedLockRoi   = $targetLockRoi;
                        $proposedAction    = 'move_stop';
                    }
                }
            } elseif ($trailingArmed) {
                $proposedAction = 'armed_watching';
            }

            $nowTs = time();

            // Build shadow state to persist
            $shadowState = [
                'trade_id'           => $tradeKey,
                'symbol'             => $symbol,
                'side'               => $side,
                'owner_mode'         => 'profit_manager_shadow',
                'peak_roi'           => round($peakRoi, 4),
                'trailing_armed'     => $trailingArmed,
                'last_lock_roi'      => $proposedAction === 'move_stop' ? $proposedLockRoi : $lastLockRoi,
                'last_move_ts'       => $proposedAction === 'move_stop' ? $nowTs : (int)($prevState['last_move_ts'] ?? 0),
                'proposed_stop_price'=> $proposedStopPrice,
                'proposed_lock_roi'  => $proposedLockRoi,
                'proposed_action'    => $proposedAction,
                'current_roi'        => $roiBybit,
                'updated_at'         => $ts,
            ];

            $this->store->saveShadowState($tradeKey, $shadowState);

            $items[] = $shadowState;
        }

        $journal = [
            'ts'              => $ts,
            'trailing_owner'  => 'profit_manager_shadow',
            'pm_shadow_active'=> true,
            'positions_seen'  => count($positions),
            'positions_processed' => count($items),
            'items'           => $items,
        ];

        $this->store->saveShadowJournal($journal);

        return $journal;
    }
}

/* RULES
- Step trailing has priority over dumb trailing
- All calculations use RiskMath
- Anti-spam via StopApplier
- Status updated for each symbol after processing
- Idempotent: repeated runs safe (ratchet only, no rollback)
*/
