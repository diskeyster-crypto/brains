<?php
declare(strict_types=1);

namespace Modules\System\ProfitManager\Lib;

/**
 * Stop Applier
 * 
 * Wrapper for setTradingStop with anti-spam protection.
 * Handles rate limiting and cooldown per symbol.
 */
class StopApplier
{
    private array $config;
    private Store $store;
    private RiskMath $riskMath;
    private $gateway;
    
    /** @var int Updates made this run */
    private int $updatesThisRun = 0;
    
    public function __construct(array $config, Store $store, RiskMath $riskMath, $gateway)
    {
        $this->config = $config;
        $this->store = $store;
        $this->riskMath = $riskMath;
        $this->gateway = $gateway;
    }
    
    /**
     * Apply stop loss update
     * 
     * @param string $symbol Symbol
     * @param string $side long|short
     * @param float $newSL New stop loss price
     * @param float $oldSL Current stop loss price (0 if none)
     * @param float $tickSize Tick size for validation
     * @param array $context Additional context for logging
     * @return array Result with ok, action, reason
     */
    public function applyStopLoss(
        string $symbol,
        string $side,
        float $newSL,
        float $oldSL,
        float $tickSize,
        array $context = []
    ): array {
        $mode = $this->config['module']['mode'] ?? 'dry';
        
        // Check global update limit
        $maxUpdates = $this->config['limits']['max_updates_per_run'] ?? 10;
        if ($this->updatesThisRun >= $maxUpdates) {
            return [
                'ok' => false,
                'action' => 'skip',
                'reason' => 'max_updates_per_run_reached',
                'details' => [
                    'updates_this_run' => $this->updatesThisRun,
                    'max_updates' => $maxUpdates,
                ],
            ];
        }
        
        // Check symbol cooldown
        if ($this->store->isSymbolLocked($symbol)) {
            $remaining = $this->store->getSymbolCooldownRemaining($symbol);
            return [
                'ok' => false,
                'action' => 'skip',
                'reason' => 'symbol_cooldown',
                'details' => [
                    'cooldown_remaining_sec' => $remaining,
                ],
            ];
        }
        
        // Check minimum SL change
        $minChangeTicks = $this->config['limits']['min_sl_change_ticks'] ?? 2;
        if ($oldSL > 0 && $tickSize > 0) {
            $changeTicks = $this->riskMath->priceDifferenceInTicks($newSL, $oldSL, $tickSize);
            if ($changeTicks < $minChangeTicks) {
                return [
                    'ok' => false,
                    'action' => 'skip',
                    'reason' => 'sl_change_too_small',
                    'details' => [
                        'change_ticks' => $changeTicks,
                        'min_change_ticks' => $minChangeTicks,
                    ],
                ];
            }
        }
        
        // Dry mode: log only
        if ($mode !== 'live') {
            $this->logApplied($symbol, 'step_sl_update', [
                'old_sl' => $oldSL,
                'new_sl' => $newSL,
                'mode' => 'dry',
                'context' => $context,
            ]);
            
            return [
                'ok' => true,
                'action' => 'step_sl_update',
                'reason' => 'dry_mode',
                'details' => [
                    'old_sl' => $oldSL,
                    'new_sl' => $newSL,
                ],
            ];
        }
        
        // Live mode: call exchange
        try {
            $result = $this->gateway->setTradingStop($symbol, $side, [
                'stopLoss' => (string) $newSL,
                'slTriggerBy' => $this->config['exchange']['sl_trigger_by'] ?? 'IndexPrice',
                'positionIdx' => $this->config['exchange']['position_idx'] ?? 0,
            ]);
            
            if (!($result['ok'] ?? false)) {
                $this->store->logError('setTradingStop failed', [
                    'symbol' => $symbol,
                    'error' => $result['error'] ?? 'unknown',
                ]);
                
                return [
                    'ok' => false,
                    'action' => 'failed',
                    'reason' => 'exchange_error',
                    'details' => [
                        'error' => $result['error'] ?? 'unknown',
                        'response' => $result['response'] ?? null,
                    ],
                ];
            }
            
            // Success: update counters and locks
            $this->updatesThisRun++;
            $this->store->lockSymbol($symbol);
            
            $this->logApplied($symbol, 'step_sl_update', [
                'old_sl' => $oldSL,
                'new_sl' => $newSL,
                'mode' => 'live',
                'context' => $context,
                'response' => $result['response'] ?? null,
            ]);
            
            return [
                'ok' => true,
                'action' => 'step_sl_update',
                'reason' => 'applied',
                'details' => [
                    'old_sl' => $oldSL,
                    'new_sl' => $newSL,
                ],
            ];
        } catch (\Throwable $e) {
            $this->store->logError('setTradingStop exception', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'ok' => false,
                'action' => 'failed',
                'reason' => 'exception',
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }
    
    /**
     * Apply dumb trailing (Bybit trailing stop)
     * 
     * @param string $symbol Symbol
     * @param string $side long|short
     * @param float $trailingStop Trailing stop distance
     * @param float $activePrice Active price
     * @param array $context Additional context for logging
     * @return array Result with ok, action, reason
     */
    public function applyDumbTrailing(
        string $symbol,
        string $side,
        float $trailingStop,
        float $activePrice,
        array $context = []
    ): array {
        $mode = $this->config['module']['mode'] ?? 'dry';
        
        // Check global update limit
        $maxUpdates = $this->config['limits']['max_updates_per_run'] ?? 10;
        if ($this->updatesThisRun >= $maxUpdates) {
            return [
                'ok' => false,
                'action' => 'skip',
                'reason' => 'max_updates_per_run_reached',
                'details' => [
                    'updates_this_run' => $this->updatesThisRun,
                    'max_updates' => $maxUpdates,
                ],
            ];
        }
        
        // Check symbol cooldown
        if ($this->store->isSymbolLocked($symbol)) {
            $remaining = $this->store->getSymbolCooldownRemaining($symbol);
            return [
                'ok' => false,
                'action' => 'skip',
                'reason' => 'symbol_cooldown',
                'details' => [
                    'cooldown_remaining_sec' => $remaining,
                ],
            ];
        }
        
        // Dry mode: log only
        if ($mode !== 'live') {
            $this->logApplied($symbol, 'dumb_trailing_set', [
                'trailing_stop' => $trailingStop,
                'active_price' => $activePrice,
                'mode' => 'dry',
                'context' => $context,
            ]);
            
            return [
                'ok' => true,
                'action' => 'dumb_trailing_set',
                'reason' => 'dry_mode',
                'details' => [
                    'trailing_stop' => $trailingStop,
                    'active_price' => $activePrice,
                ],
            ];
        }
        
        // Live mode: call exchange
        try {
            $result = $this->gateway->setTradingStop($symbol, $side, [
                'trailingStop' => (string) $trailingStop,
                'activePrice' => (string) $activePrice,
                'positionIdx' => $this->config['exchange']['position_idx'] ?? 0,
            ]);
            
            if (!($result['ok'] ?? false)) {
                $this->store->logError('setTradingStop (trailing) failed', [
                    'symbol' => $symbol,
                    'error' => $result['error'] ?? 'unknown',
                ]);
                
                return [
                    'ok' => false,
                    'action' => 'failed',
                    'reason' => 'exchange_error',
                    'details' => [
                        'error' => $result['error'] ?? 'unknown',
                        'response' => $result['response'] ?? null,
                    ],
                ];
            }
            
            // Success: update counters and locks
            $this->updatesThisRun++;
            $this->store->lockSymbol($symbol);
            
            $this->logApplied($symbol, 'dumb_trailing_set', [
                'trailing_stop' => $trailingStop,
                'active_price' => $activePrice,
                'mode' => 'live',
                'context' => $context,
                'response' => $result['response'] ?? null,
            ]);
            
            return [
                'ok' => true,
                'action' => 'dumb_trailing_set',
                'reason' => 'applied',
                'details' => [
                    'trailing_stop' => $trailingStop,
                    'active_price' => $activePrice,
                ],
            ];
        } catch (\Throwable $e) {
            $this->store->logError('setTradingStop (trailing) exception', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'ok' => false,
                'action' => 'failed',
                'reason' => 'exception',
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }
    
    /**
     * Get updates made this run
     */
    public function getUpdatesThisRun(): int
    {
        return $this->updatesThisRun;
    }
    
    /**
     * Reset updates counter
     */
    public function resetUpdatesCounter(): void
    {
        $this->updatesThisRun = 0;
    }
    
    /**
     * Log applied event
     */
    private function logApplied(string $symbol, string $action, array $details): void
    {
        $this->store->addAppliedEvent([
            'symbol' => $symbol,
            'action' => $action,
            'details' => $details,
        ]);
    }
}

/* RULES
- Anti-spam: per-symbol cooldown + global limit per run
- Minimum SL change threshold to avoid micro-updates
- Dry mode: log only, no exchange calls
- Live mode: call gateway->setTradingStop
- All events logged to applied_index
*/
