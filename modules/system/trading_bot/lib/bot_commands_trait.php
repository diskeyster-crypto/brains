<?php
declare(strict_types=1);

namespace Modules\System\TradingBot\Lib;

/**
 * Bot Commands Trait
 * 
 * Handles processing and applying Brain Commands v1.
 * Commands are read from trading_commands.json and executed on the exchange.
 * 
 * P7.6: Real implementation of command application.
 */
trait BotCommandsTrait
{
    /**
     * Apply Brain Commands
     * 
     * Main entry point for processing trading commands from Brain.
     * Commands are applied only in LIVE mode with commands_enabled=true.
     * 
     * @param string $mode Execution mode (live|dry|test)
     * @return array Result summary
     */
    protected function applyBrainCommands(string $mode): array
    {
        $result = [
            'ok' => true,
            'status' => 'ok',
            'loaded' => 0,
            'processed' => 0,
            'applied_ok' => 0,
            'applied_failed' => 0,
            'skipped_already_applied' => 0,
            'invalid' => 0,
            'errors' => [],
        ];
        
        // Only apply commands in real exchange modes (live / demo)
        if (!$this->isRealExchangeMode()) {
            $result['status'] = 'skipped_not_live';
            return $result;
        }
        
        // P7.6.1: Check if commands are enabled
        $commandsEnabled = (bool)($this->config['execution']['commands_enabled'] ?? false);
        if (!$commandsEnabled) {
            $result['status'] = 'skipped_disabled';
            return $result;
        }
        
        // P7.6.1: Load commands from Brain
        $cmd = $this->loadCommandsFromBrain();
        if ($cmd['ok'] !== true) {
            $result['ok'] = false;
            $result['status'] = 'commands_load_failed';
            $result['errors'] = $cmd['errors'] ?? [];
            return $result;
        }
        
        $result['loaded'] = $cmd['count'];
        
        if ($cmd['count'] === 0) {
            $result['status'] = 'no_commands';
            return $result;
        }
        
        // P7.6.1: Load applied index
        $applied = $this->store->loadCommandsAppliedIndex();
        if (!is_array($applied)) {
            $applied = [];
        }
        
        // P7.6.1: Get max commands per run
        $max = (int)($this->config['execution']['commands_max_per_run'] ?? 50);
        $allowClose = (bool)($this->config['execution']['commands_allow_close'] ?? true);
        
        $processed = 0;
        
        // P7.6.1: Process commands in order
        foreach ($cmd['commands'] as $command) {
            if ($processed >= $max) {
                break;
            }
            
            $id = (string)($command['id'] ?? '');
            if (empty($id)) {
                $result['invalid']++;
                continue;
            }
            
            // P7.6.1: Skip if already applied
            if (isset($applied[$id])) {
                $result['skipped_already_applied']++;
                continue;
            }
            
            $processed++;
            $result['processed']++;
            
            // P7.6.1: Validate command type and target
            $type = $command['type'] ?? '';
            $target = $command['target'] ?? [];
            $params = $command['params'] ?? [];
            
            // Validate type
            if (!in_array($type, ['set_trading_stop', 'close_position'], true)) {
                $result['invalid']++;
                $this->markCommandResult($id, $command, false, 'invalid_type', "Unknown command type: {$type}");
                continue;
            }
            
            // Validate target
            if (!$this->validateCommandTarget($target)) {
                $result['invalid']++;
                $this->markCommandResult($id, $command, false, 'invalid_target', 'Target must have trade_id, signal_id, or (symbol + side)');
                continue;
            }
            
            // P7.6.2: Resolve target to trade
            $trade = $this->resolveCommandTargetTrade($command);
            if ($trade === null) {
                $result['applied_failed']++;
                $this->markCommandResult($id, $command, false, 'trade_not_found', 'Could not resolve target to active trade');
                continue;
            }
            
            $symbol = $trade['symbol'] ?? '';
            $side = $trade['side'] ?? '';
            
            // P7.6.1: Execute command
            $execResult = null;
            $execError = null;
            $execStatus = 'unknown';
            
            try {
                if ($type === 'set_trading_stop') {
                    // Build options from params
                    $options = [];
                    if (isset($params['stop_loss'])) {
                        $options['stop_loss'] = (float)$params['stop_loss'];
                    }
                    if (isset($params['trailing_stop'])) {
                        $options['trailing_stop'] = (float)$params['trailing_stop'];
                    }
                    if (isset($params['active_price'])) {
                        $options['active_price'] = (float)$params['active_price'];
                    }
                    if (isset($params['position_idx'])) {
                        $options['position_idx'] = (int)$params['position_idx'];
                    }
                    if (isset($params['tpsl_mode'])) {
                        $options['tpsl_mode'] = $params['tpsl_mode'];
                    }
                    if (isset($params['sl_trigger_by'])) {
                        $options['sl_trigger_by'] = $params['sl_trigger_by'];
                    }
                    
                    // P7.6.1: Call gateway
                    if ($this->gateway === null) {
                        $execResult = ['success' => false, 'error' => 'gateway_not_initialized'];
                    } else {
                        $execResult = $this->gateway->setTradingStop($symbol, $side, $options);
                    }
                    
                    if ($execResult['success'] ?? false) {
                        $execStatus = 'applied';
                        $result['applied_ok']++;
                    } else {
                        $execStatus = 'set_trading_stop_failed';
                        $execError = $execResult['error'] ?? 'unknown';
                        $result['applied_failed']++;
                    }
                    
                } elseif ($type === 'close_position') {
                    // P7.6.1: Check if close is allowed
                    if (!$allowClose) {
                        $execStatus = 'close_not_allowed';
                        $execError = 'commands_allow_close is disabled';
                        $result['applied_failed']++;
                    } else {
                        // Get position qty from trade
                        $qty = (float)($trade['qty'] ?? $trade['size'] ?? 0);
                        
                        if ($qty <= 0) {
                            $execStatus = 'close_invalid_qty';
                            $execError = 'Position quantity not available';
                            $result['applied_failed']++;
                        } else {
                            if ($this->gateway === null) {
                                $execResult = ['success' => false, 'error' => 'gateway_not_initialized'];
                            } else {
                                $execResult = $this->gateway->closePosition($symbol, $side, $qty);
                            }
                            
                            if ($execResult['success'] ?? false) {
                                $execStatus = 'closed';
                                $result['applied_ok']++;
                            } else {
                                $execStatus = 'close_failed';
                                $execError = $execResult['error'] ?? 'unknown';
                                $result['applied_failed']++;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $execStatus = 'exception';
                $execError = $e->getMessage();
                $result['applied_failed']++;
            }
            
            // P7.6.1: Build log row
            $row = [
                'ts' => date('c'),
                'mode' => $mode,
                'id' => $id,
                'type' => $type,
                'target' => $target,
                'params' => $params,
                'result_ok' => ($execStatus === 'applied' || $execStatus === 'closed'),
                'status' => $execStatus,
                'error' => $execError,
                'exchange_response' => $execResult ?? null,
            ];
            
            // P7.6.1: Mark command as applied
            $this->store->markCommandApplied($id, $row);
        }
        
        return $result;
    }
    
    /**
     * Validate command target has required fields
     * 
     * @param array $target Target array
     * @return bool True if valid
     */
    private function validateCommandTarget(array $target): bool
    {
        // Must have at least one of: trade_id, signal_id, (symbol + side)
        if (!empty($target['trade_id'])) {
            return true;
        }
        if (!empty($target['signal_id'])) {
            return true;
        }
        if (!empty($target['symbol']) && !empty($target['side'])) {
            return true;
        }
        return false;
    }
    
    /**
     * P7.6.2: Resolve command target to active trade
     * 
     * Resolution priority:
     * 1. target.trade_id → find by trade_id
     * 2. target.signal_id → find by signal_id
     * 3. target.symbol + target.side → find by symbol+side
     * 
     * @param array $command Command with target
     * @return array|null Trade data or null if not found
     */
    private function resolveCommandTargetTrade(array $command): ?array
    {
        $target = $command['target'] ?? [];
        
        // Load active trades
        $activeTrades = $this->store->loadActiveTrades();
        
        // Priority 1: trade_id
        if (!empty($target['trade_id'])) {
            $tradeId = (string)$target['trade_id'];
            
            // Check by key (file name)
            if (isset($activeTrades[$tradeId])) {
                return $activeTrades[$tradeId];
            }
            
            // Check by trade_id field inside trade
            foreach ($activeTrades as $trade) {
                if (($trade['trade_id'] ?? '') === $tradeId) {
                    return $trade;
                }
            }
        }
        
        // Priority 2: signal_id
        if (!empty($target['signal_id'])) {
            $signalId = (string)$target['signal_id'];
            
            foreach ($activeTrades as $trade) {
                if (($trade['signal_id'] ?? '') === $signalId) {
                    return $trade;
                }
            }
        }
        
        // Priority 3: symbol + side
        if (!empty($target['symbol']) && !empty($target['side'])) {
            $symbol = strtoupper((string)$target['symbol']);
            $side = strtolower((string)$target['side']);
            
            foreach ($activeTrades as $trade) {
                $tradeSymbol = strtoupper($trade['symbol'] ?? '');
                $tradeSide = strtolower($trade['side'] ?? '');
                
                if ($tradeSymbol === $symbol && $tradeSide === $side) {
                    return $trade;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Mark command result (helper for failed commands before execution)
     * 
     * @param string $id Command ID
     * @param array $command Original command
     * @param bool $ok Success status
     * @param string $status Status string
     * @param string|null $error Error message
     */
    private function markCommandResult(string $id, array $command, bool $ok, string $status, ?string $error = null): void
    {
        $row = [
            'ts' => date('c'),
            'mode' => $this->config['module']['mode'] ?? 'dry',
            'id' => $id,
            'type' => $command['type'] ?? '',
            'target' => $command['target'] ?? [],
            'params' => $command['params'] ?? [],
            'result_ok' => $ok,
            'status' => $status,
            'error' => $error,
            'exchange_response' => null,
        ];
        
        $this->store->markCommandApplied($id, $row);
    }
}

/* RULES
- Commands are only applied in LIVE mode with commands_enabled=true
- Each command is processed once (dedup via applied index)
- set_trading_stop calls gateway->setTradingStop()
- close_position calls gateway->closePosition() if commands_allow_close=true
- All results are logged to commands_applied.ndjson
- Target resolution: trade_id > signal_id > symbol+side
*/
