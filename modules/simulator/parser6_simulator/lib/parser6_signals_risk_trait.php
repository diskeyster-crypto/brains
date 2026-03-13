<?php

declare(strict_types=1);

use Core\System\SystemPaths;


/**
 * Parser6SignalsRiskTrait - Signals loading and risk validation
 * 
 * Methods for loading signals and validating risk blocks from Brain.
 */
trait Parser6SignalsRiskTrait
{
    /**
     * Load signals by mode (RAW: Parser5 signals, CLEAN: Brain gateway signals)
     */
    private function loadSignalsByMode(string $mode): array
    {
        // Comparison mode config defines signal sources for RAW/CLEAN
        $comparison = $this->config['comparison'] ?? [];
        if (!empty($comparison['enabled'])) {
            $signalsCfg = $comparison['signals'] ?? [];

            // RAW: direct signals from Parser5 (before Brain gateway)
            if ($mode === 'raw') {
                $signalsKey = (string)($signalsCfg['raw_signals_key'] ?? '');
                $signalsFile = (string)($signalsCfg['raw_signals_file'] ?? 'signals.json');
                if ($signalsKey !== '') {
                    return $this->loadSignalsFromSystemPaths($signalsKey, $signalsFile, true);
                }
            }

            // CLEAN: signals after Brain gateway processing
            $signalsKey = (string)($signalsCfg['clean_signals_key'] ?? '');
            $signalsFile = (string)($signalsCfg['clean_signals_file'] ?? 'signals.json');
            if ($signalsKey !== '') {
                return $this->loadSignalsFromSystemPaths($signalsKey, $signalsFile, true);
            }
        }

        // Legacy fallback (single source configured in sources.signals_key)
        return $this->loadSignals();
    }

    /**
     * Load signals from default configured source (legacy).
     *
     * @return array<int,array<string,mixed>>
     */
    private function loadSignals(): array
    {
        $sources = $this->config['sources'] ?? [];
        $signalsKey = (string)($sources['signals_key'] ?? 'parser.parser5_signal_monitor.storage');
        $signalsFile = (string)($sources['signals_file'] ?? 'signals.json');

        return $this->loadSignalsFromSystemPaths($signalsKey, $signalsFile, true);
    }

    /**
     * Load signals JSON from SystemPaths key + filename.
     *
     * @param string $signalsKey SystemPaths key to resolve directory
     * @param string $signalsFile Filename (relative to resolved dir)
     * @param bool $silentMissing If true, missing file returns [] without error
     * @return array<int,array<string,mixed>>
     */
    private function loadSignalsFromSystemPaths(string $signalsKey, string $signalsFile, bool $silentMissing): array
    {
        try {
            $signalsDir = SystemPaths::instance()->get($signalsKey);
        } catch (\Throwable $e) {
            $this->errors[] = 'signals_key_unknown: ' . $signalsKey;
            return [];
        }

        $path = rtrim((string)$signalsDir, '/') . '/' . ltrim($signalsFile, '/');

        if (!is_file($path)) {
            if (!$silentMissing) {
                $this->errors[] = 'signals_file_missing: ' . $path;
            }
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            $this->errors[] = 'signals_read_error: ' . $path;
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            $this->errors[] = 'signals_json_error: ' . $path;
            return [];
        }

        // Support formats: {signals: [...]} or [...]
        if (isset($data['signals']) && is_array($data['signals'])) {
            return $data['signals'];
        }
        if (isset($data[0]) && is_array($data[0])) {
            return $data;
        }

        return [];
    }

    /**
     * Filter eligible signals (root storage, deprecated - use filterEligibleSignalsForMode)
     */
    private function filterEligibleSignals(array $signals, array $executedIndex, int $nowTs): array
    {
        $completed = $executedIndex['completed'] ?? [];
        $activeTrades = $this->getActiveTradeIds();
        $activeSymbols = $this->getActiveSymbols();
        
        $eligible = [];

        foreach ($signals as $signal) {
            $id = $signal['id'] ?? $this->generateSignalId($signal);
            $symbol = $signal['symbol'] ?? '';
            $status = $signal['status'] ?? '';
            $expiresAt = (int)($signal['expires_at'] ?? 0);

            if ($status !== 'active') {
                continue;
            }

            if ($expiresAt > 0 && $expiresAt <= $nowTs) {
                continue;
            }

            if (isset($completed[$id])) {
                continue;
            }

            if (isset($activeTrades[$id])) {
                continue;
            }
            
            if (isset($activeSymbols[$symbol])) {
                $this->rejectSignal($signal, $nowTs, 'symbol_busy');
                continue;
            }

            $eligible[] = $signal;
        }

        return $eligible;
    }

    /**
     * Filter eligible signals for specific mode storage
     */
    private function filterEligibleSignalsForMode(array $signals, array $executedIndex, int $nowTs, string $modeStorageBase): array
    {
        $completed = $executedIndex['completed'] ?? [];
        $activeSymbols = $this->getActiveSymbolsForMode($modeStorageBase);
        
        $eligible = [];
        $activeDir = $modeStorageBase . '/trades/active';
        $activeTradeIds = [];
        if (is_dir($activeDir)) {
            foreach (glob($activeDir . '/*.json') as $file) {
                $activeTradeIds[basename($file, '.json')] = true;
            }
        }

        foreach ($signals as $signal) {
            $id = $signal['id'] ?? $this->generateSignalId($signal);
            $symbol = $signal['symbol'] ?? '';
            $status = $signal['status'] ?? '';
            $expiresAt = (int)($signal['expires_at'] ?? 0);

            if ($status !== 'active') {
                continue;
            }

            if ($expiresAt > 0 && $expiresAt <= $nowTs) {
                continue;
            }

            if (isset($completed[$id])) {
                continue;
            }

            if (isset($activeTradeIds[$id])) {
                continue;
            }
            
            if (isset($activeSymbols[$symbol])) {
                continue;
            }

            $eligible[] = $signal;
        }

        return $eligible;
    }

    /**
     * Reject a signal with a specific reason
     */
    private function rejectSignal(array $signal, int $nowTs, string $reason): void
    {
        $id = $signal['id'] ?? $this->generateSignalId($signal);
        
        $trade = [
            'trade_id' => $id,
            'symbol' => $signal['symbol'] ?? '',
            'side' => strtolower($signal['side'] ?? 'long'),
            'source' => 'parser5',
            'created_at' => date('c', $signal['created_ts'] ?? $nowTs),
            'created_ts' => $signal['created_ts'] ?? $nowTs,
            'expires_at' => $signal['expires_at'] ?? null,
            'signal_score' => (float)($signal['score'] ?? 0),
            'signal_confirmations' => (int)($signal['confirmations'] ?? 0),
            'entry' => [
                'entry_price_signal' => (float)($signal['entry_price'] ?? 0),
            ],
            'targets' => [
                'take_profit' => (float)($signal['take_profit'] ?? 0),
                'stop_loss_initial' => (float)($signal['stop_loss'] ?? 0),
            ],
        ];
        
        $rejectResult = [
            'close_reason' => $reason,
            'closed_at' => date('c', $nowTs),
        ];
        
        $this->saveRejectedTrade($trade, $rejectResult);
        
        if ($reason === 'symbol_busy') {
            $this->rejections[] = "signal_rejected: {$id} reason={$reason}";
        } else {
            $this->errors[] = "signal_rejected: {$id} reason={$reason}";
        }
    }

    /**
     * Generate unique signal ID
     */
    private function generateSignalId(array $signal): string
    {
        $symbol = $signal['symbol'] ?? '';
        $side = $signal['side'] ?? '';
        $createdTs = $signal['created_ts'] ?? $signal['ts'] ?? time();
        $entry = $signal['entry_price'] ?? 0;
        
        return md5("{$symbol}_{$side}_{$createdTs}_{$entry}");
    }

    /**
     * Load Brain active risk profile (RAW STRICT - only risk_active.json)
     */
    private function loadBrainActiveRiskProfile(): array
    {
        $emptyFallback = [
            'profile_id' => null,
            'is_fallback' => true,
            'risk' => [],
        ];
        
        try {
            $brainKey = $this->config['management']['commands_storage_key'] ?? 'system.brain.storage';
            $paths = SystemPaths::instance();
            $brainStorage = $paths->has($brainKey) ? $paths->get($brainKey) : null;
            if (!$brainStorage) {
                return $emptyFallback;
            }
            $riskActiveFile = $brainStorage . '/runtime/risk_active.json';
            
            if (is_file($riskActiveFile)) {
                $content = file_get_contents($riskActiveFile);
                $riskData = json_decode($content, true);
                
                if (is_array($riskData) 
                    && ($riskData['schema_version'] ?? '') === 'risk_active_v1'
                    && !empty($riskData['risk'])
                ) {
                    return [
                        'profile_id' => $riskData['profile_id'] ?? 'unknown',
                        'is_fallback' => false,
                        'risk_source' => 'brain_risk_active',
                        'budget_usdt_per_trade' => (float)($riskData['risk']['budget_usdt_per_trade'] ?? 0),
                        'leverage' => (int)($riskData['risk']['leverage'] ?? 0),
                        'stop_from_liq_range_pct' => (float)($riskData['risk']['stop_from_liq_range_pct'] ?? 0),
                        'slippage_bps' => (int)($riskData['risk']['slippage_bps'] ?? 0),
                        'fees_bps' => (int)($riskData['risk']['fees_bps'] ?? 0),
                        'order_type' => $riskData['risk']['order_type'] ?? null,
                        'trailing' => $riskData['risk']['trailing'] ?? [],
                        'take_profit' => $riskData['risk']['take_profit'] ?? [],
                        'limits' => $riskData['risk']['limits'] ?? [],
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Brain storage not accessible
        }
        
        return $emptyFallback;
    }

    /**
     * Validate risk block - check all required fields
     * Returns array of error messages (empty if valid)
     */
    private function validateRiskBlock(array $risk): array
    {
        $errors = [];
        
        $requiredPositiveFields = [
            'budget_usdt_per_trade',
            'leverage',
            'stop_from_liq_range_pct',
        ];
        
        foreach ($requiredPositiveFields as $field) {
            if (!isset($risk[$field])) {
                $errors[] = "missing_field:{$field}";
            } elseif (!is_numeric($risk[$field])) {
                $errors[] = "invalid_type:{$field}";
            } elseif ($risk[$field] <= 0) {
                $errors[] = "invalid_value:{$field}_must_be_positive";
            }
        }
        
        $requiredNonNegativeFields = [
            'slippage_bps',
            'fees_bps',
        ];
        
        foreach ($requiredNonNegativeFields as $field) {
            if (!isset($risk[$field])) {
                $errors[] = "missing_field:{$field}";
            } elseif (!is_numeric($risk[$field])) {
                $errors[] = "invalid_type:{$field}";
            } elseif ($risk[$field] < 0) {
                $errors[] = "invalid_value:{$field}_must_be_non_negative";
            }
        }
        
        $trailing = $risk['trailing'] ?? [];
        if (empty($trailing)) {
            $errors[] = 'missing_block:trailing';
        } else {
            if (!isset($trailing['enabled'])) {
                $errors[] = 'missing_field:trailing.enabled';
            }
            if (!isset($trailing['activation_roi_pct'])) {
                $errors[] = 'missing_field:trailing.activation_roi_pct';
            } elseif (!is_numeric($trailing['activation_roi_pct'])) {
                $errors[] = 'invalid_type:trailing.activation_roi_pct';
            }
            if (!isset($trailing['mode'])) {
                $errors[] = 'missing_field:trailing.mode';
            }
            if (!isset($trailing['drawdown_factor'])) {
                $errors[] = 'missing_field:trailing.drawdown_factor';
            } elseif (!is_numeric($trailing['drawdown_factor'])) {
                $errors[] = 'invalid_type:trailing.drawdown_factor';
            }
        }
        
        $limits = $risk['limits'] ?? [];
        if (empty($limits)) {
            $errors[] = 'missing_block:limits';
        } else {
            if (!array_key_exists('max_open_trades', $limits)) {
                $errors[] = 'missing_field:limits.max_open_trades';
            }
        }
        
        $orderType = $risk['order_type'] ?? null;
        if ($orderType === null) {
            $errors[] = 'missing_field:order_type';
        } elseif ($orderType !== 'market') {
            $errors[] = "invalid_order_type:{$orderType}_only_market_allowed";
        }
        
        return $errors;
    }
}

/* RULES
 * NO HARDCODE. CONFIG FIRST. SystemPaths ONLY.
 * RAW mode: signals come directly from Parser5 storage (no Brain gateway).
 * CLEAN mode: signals come from Brain storage (after gateway processing).
 * Risk validation must check all required fields per C1.2 spec.
 */
