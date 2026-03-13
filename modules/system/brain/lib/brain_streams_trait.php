<?php
declare(strict_types=1);

namespace Modules\System\Brain\Lib;

/**
 * BrainStreamsTrait - Streaming I/O related methods
 * 
 * Extracted from BrainService for modularity.
 * Contains methods for reading/writing signal and feedback streams,
 * simulation results, run history, and module status.
 */
trait BrainStreamsTrait
{
    /**
     * Read signals from NDJSON file as a stream (Блок 1 - Streaming I/O)
     * 
     * Generator that yields one signal at a time, avoiding memory overflow.
     * 
     * @param string $file Path to NDJSON file
     * @return \Generator<array> Signal generator
     */
    public function readSignalsStream(string $file): \Generator
    {
        if (!is_file($file)) {
            return;
        }
        
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return;
        }
        
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }
                
                $signal = json_decode($line, true);
                if (is_array($signal)) {
                    yield $signal;
                }
            }
        } finally {
            fclose($handle);
        }
    }
    
    /**
     * Read feedback from NDJSON file as a stream (Блок 1 - Streaming I/O)
     * 
     * Generator that yields one feedback record at a time.
     * 
     * @param string $file Path to NDJSON file
     * @return \Generator<array> Feedback generator
     */
    public function readFeedbackStream(string $file): \Generator
    {
        if (!is_file($file)) {
            return;
        }
        
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return;
        }
        
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }
                
                $feedback = json_decode($line, true);
                if (is_array($feedback)) {
                    yield $feedback;
                }
            }
        } finally {
            fclose($handle);
        }
    }
    
    /**
     * Write signal to NDJSON file (Блок 1 - Streaming I/O)
     * 
     * @param string $file Path to NDJSON file
     * @param array $signal Signal data
     * @return bool Success
     */
    public function writeSignalNdjson(string $file, array $signal): bool
    {
        $line = json_encode($signal, JSON_UNESCAPED_UNICODE) . "\n";
        return file_put_contents($file, $line, FILE_APPEND) !== false;
    }
    
    /**
     * Count signals in NDJSON file without loading all (Блок 1)
     * 
     * @param string $file Path to NDJSON file
     * @return int Signal count
     */
    public function countSignalsStream(string $file): int
    {
        $count = 0;
        foreach ($this->readSignalsStream($file) as $signal) {
            $count++;
        }
        return $count;
    }
    
    /**
     * Calculate aggregates from feedback stream (Блок 7 - Memory Safe)
     * 
     * Processes feedback one record at a time, computing running aggregates.
     * No arrays stored in memory.
     * 
     * @param string $file Path to NDJSON feedback file
     * @return array Aggregated stats
     */
    public function calculateFeedbackAggregatesStream(string $file): array
    {
        $aggregates = [
            'count' => 0,
            'total_roi' => 0.0,
            'total_drawdown' => 0.0,
            'wins' => 0,
            'losses' => 0,
            'total_duration_hours' => 0.0,
            'max_roi' => 0.0,
            'max_drawdown' => 0.0,
        ];
        
        foreach ($this->readFeedbackStream($file) as $feedback) {
            $aggregates['count']++;
            
            $roi = (float)($feedback['roi'] ?? 0);
            $drawdown = (float)($feedback['drawdown'] ?? 0);
            $durationHours = (float)($feedback['duration_hours'] ?? 1);
            
            $aggregates['total_roi'] += $roi;
            $aggregates['total_drawdown'] += $drawdown;
            $aggregates['total_duration_hours'] += $durationHours;
            
            if ($roi > 0) {
                $aggregates['wins']++;
            } else {
                $aggregates['losses']++;
            }
            
            $aggregates['max_roi'] = max($aggregates['max_roi'], $roi);
            $aggregates['max_drawdown'] = max($aggregates['max_drawdown'], $drawdown);
        }
        
        // Calculate averages
        if ($aggregates['count'] > 0) {
            $aggregates['avg_roi'] = $aggregates['total_roi'] / $aggregates['count'];
            $aggregates['avg_drawdown'] = $aggregates['total_drawdown'] / $aggregates['count'];
            $aggregates['avg_duration_hours'] = $aggregates['total_duration_hours'] / $aggregates['count'];
            $aggregates['win_rate'] = ($aggregates['wins'] / $aggregates['count']) * 100;
        } else {
            $aggregates['avg_roi'] = 0;
            $aggregates['avg_drawdown'] = 0;
            $aggregates['avg_duration_hours'] = 0;
            $aggregates['win_rate'] = 0;
        }
        
        return $aggregates;
    }
    
    /**
     * Write feedback to NDJSON stream file
     * 
     * @param array $feedback Feedback data
     * @param string|null $file Path to NDJSON file (optional)
     * @return bool Success
     */
    public function writeFeedbackStream(array $feedback, ?string $file = null): bool
    {
        if ($file === null) {
            $file = $this->feedbackDir . '/feedback_stream.ndjson';
        }
        
        $record = array_merge([
            'ts' => self::isoTimestamp(),
        ], $feedback);
        
        $line = json_encode($record, JSON_UNESCAPED_UNICODE) . "\n";
        return file_put_contents($file, $line, FILE_APPEND) !== false;
    }
    
    /**
     * Read candidates from Parser4 storage (READ ONLY)
     * 
     * @return array|null Candidates array or null if not found
     */
    private function readCandidates(): ?array
    {
        $path = $this->modulePaths['parser4'] ?? null;
        if (!$path) {
            return null;
        }
        
        $file = $path . '/candidates.json';
        if (!is_file($file)) {
            return null;
        }
        
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? ($data['candidates'] ?? $data) : null;
    }
    
    /**
     * Read signals from Parser5 storage (READ ONLY, no generation)
     * 
     * @return array|null Signals array or null if not found
     */
    private function readSignals(): ?array
    {
        $path = $this->modulePaths['parser5'] ?? null;
        if (!$path) {
            return null;
        }
        
        $file = $path . '/signals.json';
        if (!is_file($file)) {
            return null;
        }
        
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? ($data['signals'] ?? $data) : null;
    }
    
    /**
     * Process signals through Brain gateway and save for simulator
     * 
     * Per ТЗ: Brain as signal gateway - loads Parser5 signals,
     * filters/processes them, and saves to brain/storage/signals.json
     * for the simulator to read.
     * 
     * @param array|null $signals Raw signals from Parser5
     * @param array $strategies Active strategies (enabled_for_simulator = true)
     * @return array Result with success, counts, and file path
     */
    private function processSignalGateway(?array $signals, array $strategies): array
    {
        $result = [
            'success' => false,
            'input_count' => 0,
            'output_count' => 0,
            'rejected_count' => 0,
            'passport_applied_count' => 0,  // FIX #2: Count of signals with ELIGIBLE passports
            'passport_adjusted_count' => 0, // FIX #2: Count of signals with actual MAE adjustment
            'entry_mode_impulse_count' => 0,  // Этап 3: Signals with impulse entry mode
            'entry_mode_retrace_count' => 0,  // Этап 3: Signals with retrace entry mode
            // STEP 2: Trailing mode counters
            'trailing_mode_tight_count' => 0,
            'trailing_mode_normal_count' => 0,
            'trailing_mode_loose_count' => 0,
            'trailing_mode_not_mature_count' => 0,
            'trailing_mode_no_passport_count' => 0,
            // STEP 4: Learning counters
            'trailing_mode_source_learning_count' => 0,
            'trailing_mode_source_passport_count' => 0,
            'trailing_mode_source_default_count' => 0,
            'learning_recommendations_loaded_count' => 0,
            'learning_recommendations_applied_count' => 0,
            'file' => null,
        ];
        
        // If no signals from Parser5, nothing to process
        if ($signals === null || empty($signals)) {
            $result['success'] = true; // Not an error, just no signals
            return $result;
        }
        
        $result['input_count'] = count($signals);
        $now = time();
        
        // Passport configuration (ТЗ Task 2)
        $passportConfig = $this->config['passport'] ?? [];
        $passportMinTrades = (int)($passportConfig['min_trades'] ?? 3);
        $passportTimeoutFactor = (float)($passportConfig['timeout_factor'] ?? 0.4);
        $passportMaeMinPct = (float)($passportConfig['mae_min_abs_pct'] ?? 0.1);
        $entryTimeoutMin = (int)($passportConfig['entry_timeout_min'] ?? 2);
        $entryTimeoutMax = (int)($passportConfig['entry_timeout_max'] ?? 120);
        $passportMaxEntryAdjustAbsPct = (float)($passportConfig['max_entry_adjust_abs_pct'] ?? 1.2);
        
        // Этап 3: Entry mode auto config
        $entryModeConfig = $passportConfig['entry_mode_auto'] ?? [];
        $entryModeAutoEnabled = (bool)($entryModeConfig['enabled'] ?? true);
        $impulseMinWinRate = (float)($entryModeConfig['impulse_min_win_rate'] ?? 0.55);
        $impulseMaxMaeAbsPct = (float)($entryModeConfig['impulse_max_mae_abs_pct'] ?? 0.2);
        $impulseMinMfeAbsPct = (float)($entryModeConfig['impulse_min_mfe_abs_pct'] ?? 0.4);
        $impulseMaxDuration = (int)($entryModeConfig['impulse_max_median_duration_min'] ?? 30);
        $retraceDefaultIfNoMfe = (bool)($entryModeConfig['retrace_default_if_no_mfe'] ?? true);
        $impulseLateThresholdMinPct = (float)($entryModeConfig['impulse_late_threshold_min_pct'] ?? 0.35);
        $retraceEnterNowIfNoMaeAdj = (bool)($entryModeConfig['retrace_enter_now_if_no_mae_adjustment'] ?? true);
        
        // STEP 2: Trailing mode auto config
        $trailingModeConfig = $passportConfig['trailing_mode_auto'] ?? [];
        
        // Normalize all signals to the full contract format for Parser6 (ТЗ #1)
        $finalSignals = [];
        $gatewayRejections = [];
        
        // P6.3: Initialize gateway decisions log path
        $gatewayDecisionsFile = null;
        // Use Brain storage resolved via SystemPaths (discoverModulePaths), fallback to storageDir
        $brainStoragePath = $this->modulePaths['brain'] ?? $this->storageDir ?? null;
        if (!empty($brainStoragePath)) {
            $runtimeDir = rtrim((string)$brainStoragePath, '/') . '/runtime';
            if (!is_dir($runtimeDir)) {
                @mkdir($runtimeDir, 0755, true);
            }
            $gatewayDecisionsFile = $runtimeDir . '/gateway_decisions.ndjson';
        }
        
        // Live per-symbol stats (Trading Bot) — used for Symbol Policy gates
        $liveStats = $this->loadLiveSymbolStats();

        foreach ($signals as $signal) {
            // P6.1.2: Reject signals with missing id
            $signalId = $signal['id'] ?? null;
            if (empty($signalId) || !is_string($signalId)) {
                $symbol = $signal['symbol'] ?? 'unknown';
                $result['rejected_count']++;
                $gatewayRejections[] = [
                    'symbol' => $symbol,
                    'reason' => 'reject_missing_signal_id',
                    'ts' => date('c'),
                ];
                // P6.3: Log gateway decision for rejection
                if ($gatewayDecisionsFile !== null) {
                    $decisionEntry = [
                        'ts' => date('c'),
                        'signal_id' => null,
                        'symbol' => $symbol,
                        'strategy_id' => $signal['strategy_id'] ?? 'unknown',
                        'decision' => 'reject',
                        'reason' => 'reject_missing_signal_id',
                        'entry_action' => null,
                        'late_threshold' => 0.0,
                        'entry_timeout_minutes' => 0,
                        'risk_profile_id' => null,
                        'risk_hash' => null,
                        'notes' => [],
                    ];
                    @file_put_contents($gatewayDecisionsFile, json_encode($decisionEntry) . "\n", FILE_APPEND | LOCK_EX);
                }
                continue;
            }
            
            // Extract required fields with defaults
            $symbol = $signal['symbol'] ?? null;
            if (empty($symbol)) {
                continue; // Skip signals without symbol
            }
            
            $side = strtolower((string)($signal['side'] ?? 'long'));
            // Validate side - fallback to 'long' for invalid values (ТЗ Task D)
            if (!in_array($side, ['long', 'short'], true)) {
                $side = 'long';
            }
            $originalEntryPrice = (float)($signal['entry_price'] ?? $signal['entry']['price'] ?? 0);
            $originalTpPrice = (float)($signal['take_profit'] ?? $signal['tp']['price'] ?? 0);
            $originalSlPrice = (float)($signal['stop_loss'] ?? $signal['sl']['price'] ?? 0);
            $createdTs = (int)($signal['created_ts'] ?? $now);
            $expiresAt = (int)($signal['expires_at'] ?? ($now + 1800)); // default 30 min
            
            // Initialize adjusted prices (may be modified by passport)
            $entryPrice = $originalEntryPrice;
            $tpPrice = $originalTpPrice;
            $slPrice = $originalSlPrice;
            
            // Default entry_action/entry_mode and passport application status
            $entryAction = null;
            $entryMode = null;  // Этап 3: impulse | retrace
            $passportApplied = false;
            $passportReason = '';
            $entryTimeoutMinutes = null;
            $lateThresholdPct = null; // ТЗ Task C: for reject_late check in Parser6
            
            // Load passport for this symbol (ТЗ Task 2)
            $passport = $this->getPassport($symbol);
            
            if ($passport !== null && isset($passport['trades_total'])) {
                $tradesTotalPassport = (int)$passport['trades_total'];
                
                // Only apply passport if enough trades (FIX #2: eligible check)
                if ($tradesTotalPassport >= $passportMinTrades) {
                    $passportApplied = true;
                    // FIX #2: Increment passport_applied_count when ELIGIBLE
                    // This happens REGARDLESS of whether MAE adjustment is made
                    $result['passport_applied_count']++;
                    
                    // Get median MAE (maximum adverse excursion) for retrace calculation
                    // MAE is typically negative (e.g., -1.2% means price moved against position)
                    $medianMae = (float)($passport['median_mae'] ?? 0);
                    $absMedianMae = abs($medianMae);
                    
                    // Этап 3: Extract passport metrics for entry_mode determination
                    $winRate = (float)($passport['win_rate'] ?? 0);
                    // Normalize win_rate: if stored as percentage (>1), convert to 0..1
                    if ($winRate > 1) {
                        $winRate = $winRate / 100;
                    }
                    $medianMfe = (float)($passport['median_mfe'] ?? 0);
                    $absMfe = abs($medianMfe);
                    $medianDurationMin = (int)($passport['median_duration_min'] ?? 0);
                    
                    // Этап 3: Determine entry_mode (impulse vs retrace) if auto-mode enabled
                    if ($entryModeAutoEnabled) {
                        // Check impulse conditions: ALL must be true
                        $isImpulseCandidate = (
                            $winRate >= $impulseMinWinRate &&
                            $absMedianMae <= $impulseMaxMaeAbsPct &&
                            $absMfe >= $impulseMinMfeAbsPct &&
                            $medianDurationMin > 0 &&
                            $medianDurationMin <= $impulseMaxDuration
                        );
                        
                        // Check if we have MFE data
                        $hasMfeData = ($absMfe > 0);
                        
                        if ($isImpulseCandidate) {
                            // IMPULSE MODE: fast trades with high win rate, low MAE, good MFE
                            $entryMode = 'impulse';
                            $entryAction = 'enter_now';
                            $passportReason = 'mode_impulse';
                            $result['entry_mode_impulse_count']++;
                            // Set late_threshold_pct for reject_late (ТЗ Task C)
                            $lateThresholdPct = max($impulseLateThresholdMinPct, max($absMedianMae, $passportMaeMinPct));
} elseif (!$hasMfeData && $retraceDefaultIfNoMfe) {
                            // NO MFE DATA: default to retrace mode if configured
                            $entryMode = 'retrace';
                            $entryAction = 'wait_retrace';
                            $passportReason = 'mode_retrace_no_mfe';
                            $result['entry_mode_retrace_count']++;
                            $wasMaeAdjusted = false;
                            if ($medianMae < 0 && $absMedianMae >= $passportMaeMinPct && $originalEntryPrice > 0) {
                                // Cap MAE adjustment to avoid unrealistic retrace targets
                                $absMaeForAdjust = min($absMedianMae, $passportMaxEntryAdjustAbsPct);
                                $retracePercent = $absMaeForAdjust / 100;
                                if ($side === 'long') {
                                    $entryPrice = $originalEntryPrice * (1 - $retracePercent);
                                } else {
                                    $entryPrice = $originalEntryPrice * (1 + $retracePercent);
                                }
                                // Adjust TP/SL
                                if ($originalEntryPrice > 0 && $originalSlPrice > 0 && $originalTpPrice > 0) {
                                    $slPctOriginal = ($originalSlPrice - $originalEntryPrice) / $originalEntryPrice;
                                    $tpPctOriginal = ($originalTpPrice - $originalEntryPrice) / $originalEntryPrice;
                                    $slPrice = $entryPrice * (1 + $slPctOriginal);
                                    $tpPrice = $entryPrice * (1 + $tpPctOriginal);
                                }
                                $result['passport_adjusted_count']++;
                                $wasMaeAdjusted = true;
                            }
                            $lateThresholdPct = max($absMedianMae, $passportMaeMinPct);

                            // If retrace mode chosen but no MAE adjustment was applied, optionally switch to enter_now
                            if (!$wasMaeAdjusted && $retraceEnterNowIfNoMaeAdj) {
                                $entryAction = 'enter_now';
                                $entryTimeoutMinutes = 0;
                                $passportReason .= '|retrace_no_mae_adjust_enter_now';
                                $lateThresholdPct = max($impulseLateThresholdMinPct, $lateThresholdPct);
                            }
                        } else {
                            // RETRACE MODE: standard mode with MAE adjustment
                            $entryMode = 'retrace';
                            $entryAction = 'wait_retrace';
                            $passportReason = 'mode_retrace';
                            $result['entry_mode_retrace_count']++;
                            
                            $wasMaeAdjusted = false;
                            if ($medianMae < 0 && $absMedianMae >= $passportMaeMinPct && $originalEntryPrice > 0) {
                                // Cap MAE adjustment to avoid unrealistic retrace targets
                                $absMaeForAdjust = min($absMedianMae, $passportMaxEntryAdjustAbsPct);
                                $retracePercent = $absMaeForAdjust / 100;
                                if ($side === 'long') {
                                    $entryPrice = $originalEntryPrice * (1 - $retracePercent);
                                } else {
                                    $entryPrice = $originalEntryPrice * (1 + $retracePercent);
                                }
                                // Adjust TP/SL proportionally
                                if ($originalEntryPrice > 0 && $originalSlPrice > 0 && $originalTpPrice > 0) {
                                    $slPctOriginal = ($originalSlPrice - $originalEntryPrice) / $originalEntryPrice;
                                    $tpPctOriginal = ($originalTpPrice - $originalEntryPrice) / $originalEntryPrice;
                                    $slPrice = $entryPrice * (1 + $slPctOriginal);
                                    $tpPrice = $entryPrice * (1 + $tpPctOriginal);
                                }
                                $result['passport_adjusted_count']++;
                                $wasMaeAdjusted = true;
                            }
                            $lateThresholdPct = max($absMedianMae, $passportMaeMinPct);

                            // If retrace mode chosen but no MAE adjustment was applied, optionally switch to enter_now
                            if (!$wasMaeAdjusted && $retraceEnterNowIfNoMaeAdj) {
                                $entryAction = 'enter_now';
                                $entryTimeoutMinutes = 0;
                                $passportReason .= '|retrace_no_mae_adjust_enter_now';
                                $lateThresholdPct = max($impulseLateThresholdMinPct, $lateThresholdPct);
                            }
                        }
                        
                        // Validate: new entry should not cross SL (for retrace mode with adjustment)
                        if ($entryMode === 'retrace' && $entryPrice !== $originalEntryPrice) {
                            $isInvalidEntry = false;
                            if ($side === 'long' && $slPrice > 0 && $entryPrice <= $slPrice) {
                                $isInvalidEntry = true;
                            } elseif ($side === 'short' && $slPrice > 0 && $entryPrice >= $slPrice) {
                                $isInvalidEntry = true;
                            }
                            
                            if ($isInvalidEntry) {
                                $gatewayRejections[] = [
                                    'signal_id' => $signal['id'] ?? ($symbol . '_' . $createdTs),
                                    'symbol' => $symbol,
                                    'reason' => 'passport_entry_crosses_sl',
                                    'details' => "Adjusted entry {$entryPrice} crosses SL {$slPrice} for {$side}",
                                ];
                                $result['rejected_count']++;
                                continue; // Skip this signal
                            }
                        }
                        
                        // Calculate entry timeout for retrace mode
                        if ($entryMode === 'retrace' && $medianDurationMin > 0) {
                            $calculatedTimeout = (int)($medianDurationMin * $passportTimeoutFactor);
                            $entryTimeoutMinutes = max($entryTimeoutMin, min($entryTimeoutMax, $calculatedTimeout));
                        } elseif ($entryMode === 'retrace') {
                            $entryTimeoutMinutes = $entryTimeoutMin;
                        }
                    } else {
                        // Entry mode auto disabled - fall back to old MAE-based logic
                        if ($medianMae < 0 && $absMedianMae >= $passportMaeMinPct && $originalEntryPrice > 0) {
                            $retracePercent = $absMedianMae / 100;
                            if ($side === 'long') {
                                $entryPrice = $originalEntryPrice * (1 - $retracePercent);
                                $entryAction = 'wait_retrace';
                                $passportReason = "adjusted_mae: long entry lowered by {$absMedianMae}%";
                            } else {
                                $entryPrice = $originalEntryPrice * (1 + $retracePercent);
                                $entryAction = 'wait_retrace';
                                $passportReason = "adjusted_mae: short entry raised by {$absMedianMae}%";
                            }
                            
                            if ($originalEntryPrice > 0 && $originalSlPrice > 0 && $originalTpPrice > 0) {
                                $slPctOriginal = ($originalSlPrice - $originalEntryPrice) / $originalEntryPrice;
                                $tpPctOriginal = ($originalTpPrice - $originalEntryPrice) / $originalEntryPrice;
                                $slPrice = $entryPrice * (1 + $slPctOriginal);
                                $tpPrice = $entryPrice * (1 + $tpPctOriginal);
                            }
                            
                            $isInvalidEntry = false;
                            if ($side === 'long' && $slPrice > 0 && $entryPrice <= $slPrice) {
                                $isInvalidEntry = true;
                            } elseif ($side === 'short' && $slPrice > 0 && $entryPrice >= $slPrice) {
                                $isInvalidEntry = true;
                            }
                            
                            if ($isInvalidEntry) {
                                $gatewayRejections[] = [
                                    'signal_id' => $signal['id'] ?? ($symbol . '_' . $createdTs),
                                    'symbol' => $symbol,
                                    'reason' => 'passport_entry_crosses_sl',
                                    'details' => "Adjusted entry {$entryPrice} crosses SL {$slPrice} for {$side}",
                                ];
                                $result['rejected_count']++;
                                continue;
                            }
                            
                            $medianDurationMinLegacy = (int)($passport['median_duration_min'] ?? 0);
                            if ($medianDurationMinLegacy > 0) {
                                $calculatedTimeout = (int)($medianDurationMinLegacy * $passportTimeoutFactor);
                                $entryTimeoutMinutes = max($entryTimeoutMin, min($entryTimeoutMax, $calculatedTimeout));
                            } else {
                                $entryTimeoutMinutes = $entryTimeoutMin;
                            }
                            
                            $result['passport_adjusted_count']++;
                            $lateThresholdPct = max($absMedianMae, $passportMaeMinPct);
                        } else {
                            $entryAction = 'enter_now';
                            $passportReason = 'eligible_no_adjustment';
                            $lateThresholdPct = $passportMaeMinPct;
                        }
                    }
                } else {
                    // FIX #2: Passport exists but not enough trades → not eligible
                    $entryAction = 'enter_now';
                    $passportReason = "not_enough_trades: {$tradesTotalPassport} < {$passportMinTrades}";
                }
            } else {
                // FIX #2: No passport for symbol → raw signal, enter now
                $entryAction = 'enter_now';
                $passportReason = 'no_passport';
            }
            
            // Calculate validity_minutes (minimum 1)
            $validityMinutes = max(1, (int)(($expiresAt - $createdTs) / 60));
            
            // Build normalized signal with full contract format
            // INCLUDES BOTH: structured fields (entry, tp, sl, brain) AND flat fields for backward compat
            // STEP 7: Add schema_version and trade_id for clean_signal_v1 contract
            // P6.1.2: Preserve original signal.id without fallback (already validated above)
            $normalizedSignal = [
                'schema_version' => $signal['schema_version'] ?? 'clean_signal_v1',  // P6.1: Preserve or default
                'trade_id' => $signalId,  // P6.1.2: Use validated signal.id (strict)
                'id' => $signalId,  // P6.1.2: Preserve original signal.id (strict)
                'symbol' => $symbol,
                'side' => $side,
                'created_ts' => $createdTs,
                'expires_at' => $expiresAt,
                
                // Structured fields (new format for Brain/Parser6 contract)
                'entry' => [
                    'type' => $signal['entry']['type'] ?? ($entryPrice > 0 ? 'limit' : 'market'),
                    'price' => $entryPrice,
                ],
                'tp' => [
                    'price' => $tpPrice,
                ],
                'sl' => [
                    'price' => $slPrice,
                ],
                
                // Flat fields (backward compat for Parser6)
                'status' => $signal['status'] ?? 'active',
                'entry_price' => $entryPrice,
                'take_profit' => $tpPrice,
                'stop_loss' => $slPrice,
                'validity_minutes' => $validityMinutes,
                'created_at' => date('c', $createdTs), // ISO format
                
                // Brain gateway fields (ТЗ Task 2 + Task 3 + Этап 3)
                'entry_mode' => $entryMode,  // Этап 3: impulse | retrace
                'entry_action' => $entryAction,
                'entry_timeout_minutes' => $entryTimeoutMinutes,
                'late_threshold_pct' => $lateThresholdPct, // ТЗ Task C: for reject_late in Parser6
                
                // Original fields
                'score' => (float)($signal['score'] ?? 0),
                'confirmations' => (int)($signal['confirmations'] ?? 0),
                'source' => $signal['source'] ?? 'parser5',
                'brain' => [
                    'approved' => true,
                    'profile_id' => 'default',
                    'reason' => $passportReason,
                    'passport_applied' => $passportApplied,
                    'original_entry_price' => $originalEntryPrice,
                    'original_take_profit' => $originalTpPrice,
                    'original_stop_loss' => $originalSlPrice,
                ],
            ];
            
            // Per ТЗ: Risk Profile v1 - Add profile_id and risk block to each signal
            $riskBlock = $this->getRiskBlockForSignal();
            $normalizedSignal['profile_id'] = $riskBlock['profile_id'];
            
            // STEP 2: Auto-select trailing mode from passport
            $trailingModeResult = $this->chooseTrailingModeFromPassport($passport, $trailingModeConfig);
            
            // STEP 4: Apply learning recommendation if available (overrides passport selection)
            $trailingModeResult = $this->applyLearningToTrailingMode($symbol, $trailingModeResult);
            
            // STEP 4: Track source counters
            $trailingSource = $trailingModeResult['trailing_mode_source'] ?? 'default';
            switch ($trailingSource) {
                case 'learning':
                    $result['trailing_mode_source_learning_count']++;
                    $result['learning_recommendations_applied_count']++;
                    break;
                case 'passport':
                    $result['trailing_mode_source_passport_count']++;
                    break;
                default:
                    $result['trailing_mode_source_default_count']++;
            }
            
            // Override trailing mode/factor in risk block with final selection
            $riskBlock['trailing']['mode'] = $trailingModeResult['mode'];
            $riskBlock['trailing']['drawdown_factor'] = $trailingModeResult['drawdown_factor'];
            
            $normalizedSignal['risk'] = $riskBlock;
            
            // STEP 2: Add trailing mode info to brain block
            $normalizedSignal['brain']['trailing_mode'] = $trailingModeResult['mode'];
            $normalizedSignal['brain']['trailing_mode_reason'] = $trailingModeResult['reason'];
            $normalizedSignal['brain']['trailing_drawdown_factor'] = $trailingModeResult['drawdown_factor'];
            // STEP 4: Add trailing mode source
            $normalizedSignal['brain']['trailing_mode_source'] = $trailingSource;
            if (isset($trailingModeResult['learning_reason'])) {
                $normalizedSignal['brain']['learning_reason'] = $trailingModeResult['learning_reason'];
            }
            if (isset($trailingModeResult['learning_delta_roi'])) {
                $normalizedSignal['brain']['learning_delta_roi'] = $trailingModeResult['learning_delta_roi'];
            }
            
            // STEP 2: Increment trailing mode counters
            switch ($trailingModeResult['reason']) {
                case 'mode_tight':
                    $result['trailing_mode_tight_count']++;
                    break;
                case 'mode_normal':
                    $result['trailing_mode_normal_count']++;
                    break;
                case 'mode_loose':
                    $result['trailing_mode_loose_count']++;
                    break;
                case 'not_mature':
                    $result['trailing_mode_not_mature_count']++;
                    break;
                case 'no_passport':
                    $result['trailing_mode_no_passport_count']++;
                    break;
            }
            // Also count learning-prefixed reasons
            if (strpos($trailingModeResult['reason'], 'learning_') === 0) {
                $learningReason = str_replace('learning_', '', $trailingModeResult['reason']);
                if ($learningReason === 'best_score' || $learningReason === 'not_enough_improvement' || $learningReason === 'not_enough_data') {
                    // The actual mode is in $trailingModeResult['mode']
                    switch ($trailingModeResult['mode']) {
                        case 'tight':
                            $result['trailing_mode_tight_count']++;
                            break;
                        case 'loose':
                            $result['trailing_mode_loose_count']++;
                            break;
                        default:
                            $result['trailing_mode_normal_count']++;
                    }
                }
            }
            
            // Symbol Policy (per-symbol gates based on live closed-trade stats)
            $policySignal = $this->applySymbolPolicyToSignal($normalizedSignal, $liveStats, $gatewayRejections);
            if ($policySignal === null) {
                $result['rejected_count']++;
                // P6.3: Log gateway decision for policy rejection
                if ($gatewayDecisionsFile !== null) {
                    $decisionEntry = [
                        'ts' => date('c'),
                        'signal_id' => $signalId,
                        'symbol' => $symbol,
                        'strategy_id' => $signal['strategy_id'] ?? 'parser5',
                        'decision' => 'reject',
                        'reason' => 'symbol_policy',
                        'entry_action' => $entryAction ?? 'enter_now',
                        'late_threshold' => $lateThresholdPct ?? 0.0,
                        'entry_timeout_minutes' => $entryTimeoutMinutes ?? 0,
                        'risk_profile_id' => $normalizedSignal['profile_id'] ?? null,
                        'risk_hash' => isset($normalizedSignal['risk']) ? md5(json_encode($normalizedSignal['risk'])) : null,
                        'notes' => ['symbol_policy_reject'],
                    ];
                    @file_put_contents($gatewayDecisionsFile, json_encode($decisionEntry) . "\n", FILE_APPEND | LOCK_EX);
                }
                continue;
            }
            $normalizedSignal = $policySignal;

            // P6.3: Log gateway decision for accepted signal
            if ($gatewayDecisionsFile !== null) {
                $riskHash = isset($normalizedSignal['risk']) ? md5(json_encode($normalizedSignal['risk'])) : null;
                // Decision is 'accept' for accepted signals, entry_action describes how to enter
                $decisionEntry = [
                    'ts' => date('c'),
                    'signal_id' => $signalId,
                    'symbol' => $symbol,
                    'strategy_id' => $signal['strategy_id'] ?? 'parser5',
                    'decision' => 'accept',
                    'reason' => $passportReason ?: 'no_passport',
                    'entry_action' => $entryAction ?? 'enter_now',
                    'late_threshold' => $lateThresholdPct ?? 0.0,
                    'entry_timeout_minutes' => $entryTimeoutMinutes ?? 0,
                    'risk_profile_id' => $normalizedSignal['profile_id'] ?? null,
                    'risk_hash' => $riskHash,
                    'notes' => [
                        'entry_mode' => $entryMode,
                        'passport_applied' => $passportApplied,
                        'trailing_mode' => $trailingModeResult['mode'] ?? null,
                    ],
                ];
                @file_put_contents($gatewayDecisionsFile, json_encode($decisionEntry) . "\n", FILE_APPEND | LOCK_EX);
            }
            
            $finalSignals[] = $normalizedSignal;
        }
        
        $result['output_count'] = count($finalSignals);
        
        // Save to Brain storage for simulator to read (use modulePaths, NOT __DIR__)
        $storageDir = $this->modulePaths['brain'] ?? null;
        if (!$storageDir) {
            // HARD LOGGING: This is the main "hidden stopper" for "Signals 0"
            $this->log("CRITICAL: brain.storage path not configured - signals will NOT be saved!", 'error');
            error_log("[Brain] CRITICAL: brain.storage missing - processSignalGateway cannot save signals!");
            $result['error'] = 'brain.storage not configured';
            return $result;
        }
        
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        
        $outputFile = $storageDir . '/signals.json';
        
        // Full contract format output for Parser6 (ТЗ #1)
        // STEP 7: Add schema_version and signals_written/rejected counters
        $outputData = [
            'schema_version' => 'clean_signal_v1',  // STEP 7: Contract version for signals.json
            'generated_at' => date('c'), // ISO format
            'generated_ts' => $now,
            'count' => $result['output_count'],
            'signals' => $finalSignals,
            'generated_by' => 'brain_gateway',
            'input_count' => $result['input_count'],
            'rejected_count' => $result['rejected_count'],
            // STEP 7: Pipeline summary fields
            'signals_written' => $result['output_count'],
            'signals_rejected_schema_incomplete' => 0, // All signals at this point are valid
            'passport_applied_count' => $result['passport_applied_count'],     // FIX #2: Eligible passport count
            'passport_adjusted_count' => $result['passport_adjusted_count'],   // FIX #2: Actual MAE adjustment count
            'entry_mode_impulse_count' => $result['entry_mode_impulse_count'], // Этап 3: Impulse mode count
            'entry_mode_retrace_count' => $result['entry_mode_retrace_count'], // Этап 3: Retrace mode count
            // STEP 2: Trailing mode counters
            'trailing_mode_tight_count' => $result['trailing_mode_tight_count'],
            'trailing_mode_normal_count' => $result['trailing_mode_normal_count'],
            'trailing_mode_loose_count' => $result['trailing_mode_loose_count'],
            'trailing_mode_not_mature_count' => $result['trailing_mode_not_mature_count'],
            'trailing_mode_no_passport_count' => $result['trailing_mode_no_passport_count'],
            // STEP 4: Learning counters
            'trailing_mode_source_learning_count' => $result['trailing_mode_source_learning_count'],
            'trailing_mode_source_passport_count' => $result['trailing_mode_source_passport_count'],
            'trailing_mode_source_default_count' => $result['trailing_mode_source_default_count'],
            'learning_recommendations_applied_count' => $result['learning_recommendations_applied_count'],
            'gateway_rejections' => $gatewayRejections,
            'strategies_count' => count($strategies),
        ];
        
        $written = @file_put_contents($outputFile, json_encode($outputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        if ($written !== false) {
            $result['success'] = true;
            $result['file'] = $outputFile;
            $this->log("Signal gateway: saved {$result['output_count']} signals to {$outputFile} (rejected: {$result['rejected_count']}, passport_eligible: {$result['passport_applied_count']}, impulse: {$result['entry_mode_impulse_count']}, retrace: {$result['entry_mode_retrace_count']}, trailing_tight: {$result['trailing_mode_tight_count']}, trailing_normal: {$result['trailing_mode_normal_count']}, trailing_loose: {$result['trailing_mode_loose_count']}, learning_applied: {$result['learning_recommendations_applied_count']})");
        } else {
            $this->log("Signal gateway: failed to save signals to {$outputFile}", 'error');
        }
        
        return $result;
    }
    
    /**
     * Validate signal against clean_signal_v1 schema
     * 
     * @param array $signal Signal to validate
     * @return array Validation result with 'valid' and 'missing_fields'
     */
    public function validateSignalSchema(array $signal): array
    {
        $requiredFields = [
            'trade_id',
            'symbol',
            'side',
            'entry_action',
            'entry_price',
            'entry_timeout_minutes',
        ];
        
        $requiredRiskFields = [
            'budget_usdt_per_trade',
            'leverage',
            'stop_from_liq_range_pct',
            'slippage_bps',
            'fees_bps',
        ];
        
        $requiredTrailingFields = [
            'enabled',
            'activation_roi_pct',
            'mode',
            'drawdown_factor',
        ];
        
        $requiredBrainFields = [
            'trailing_mode_source',
            'trailing_mode_reason',
        ];
        
        $missingFields = [];
        
        // Check top-level fields
        foreach ($requiredFields as $field) {
            // trade_id might be 'id' (backward compatibility)
            if ($field === 'trade_id') {
                if (!isset($signal['trade_id']) && !isset($signal['id'])) {
                    $missingFields[] = $field;
                }
            } elseif (!isset($signal[$field])) {
                $missingFields[] = $field;
            }
        }
        
        // Check risk block
        $risk = $signal['risk'] ?? [];
        foreach ($requiredRiskFields as $field) {
            if (!isset($risk[$field])) {
                $missingFields[] = 'risk.' . $field;
            }
        }
        
        // Check risk.trailing block
        $trailing = $risk['trailing'] ?? [];
        foreach ($requiredTrailingFields as $field) {
            if (!isset($trailing[$field])) {
                $missingFields[] = 'risk.trailing.' . $field;
            }
        }
        
        // Check risk.take_profit block (enabled is required)
        $takeProfit = $risk['take_profit'] ?? [];
        if (!isset($takeProfit['enabled'])) {
            $missingFields[] = 'risk.take_profit.enabled';
        }
        
        // Check brain block
        $brain = $signal['brain'] ?? [];
        foreach ($requiredBrainFields as $field) {
            if (!isset($brain[$field])) {
                $missingFields[] = 'brain.' . $field;
            }
        }
        
        return [
            'valid' => empty($missingFields),
            'missing_fields' => $missingFields,
        ];
    }
    
    /**
     * STEP 7: Add schema_version to signal and validate
     * If validation fails, returns null and adds to rejections
     * 
     * @param array $signal Signal to process
     * @param array &$rejections Array to append rejections
     * @return array|null Enriched signal or null if invalid
     */
    public function enrichSignalWithSchemaVersion(array $signal, array &$rejections): ?array
    {
        // Add schema version
        $signal['schema_version'] = 'clean_signal_v1';
        
        // Validate schema
        $validation = $this->validateSignalSchema($signal);
        
        if (!$validation['valid']) {
            $rejections[] = [
                'trade_id' => $signal['trade_id'] ?? $signal['id'] ?? 'unknown',
                'symbol' => $signal['symbol'] ?? 'unknown',
                'reason' => 'signal_schema_incomplete',
                'missing_fields' => $validation['missing_fields'],
                'rejected_at' => date('c'),
            ];
            return null;
        }
        
        return $signal;
    }
    
    /**
     * Read simulation results from Simulator (READ ONLY)
     * 
     * @return array|null Simulation results or null if not found
     */
    private function readSimulationResults(): ?array
    {
        $path = $this->modulePaths['simulator'] ?? null;
        if (!$path) {
            return null;
        }
        
        $file = $path . '/simulation.json';
        if (!is_file($file)) {
            return null;
        }
        
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }
    
    /**
     * Read simulator's last_run.json for detailed metrics (ТЗ #2)
     * 
     * @return array|null Last run data or null if not found
     */
    private function readSimulatorLastRun(): ?array
    {
        $path = $this->modulePaths['simulator'] ?? null;
        if (!$path) {
            return null;
        }
        
        $file = $path . '/last_run.json';
        if (!is_file($file)) {
            return null;
        }
        
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }
    
    /**
     * Extract simulator metrics from last_run.json for Brain's run record (ТЗ #2)
     * 
     * @param array|null $lastRun Simulator's last_run.json data
     * @return array Metrics for Brain run record
     */
    private function extractSimulatorMetrics(?array $lastRun): array
    {
        if ($lastRun === null) {
            return [
                'sim_total_signals' => 0,
                'sim_closed' => 0,
                'sim_rejected' => 0,
                'sim_win_rate' => 0.0,
                'sim_avg_roi' => 0.0,
                'sim_profit_factor' => 0.0,
                'sim_duration_ms' => 0,
            ];
        }
        
        // Extract from last_run.json format
        $stats = $lastRun['stats'] ?? $lastRun;
        
        return [
            'sim_total_signals' => (int)($stats['signals_loaded'] ?? $stats['total_signals'] ?? 0),
            'sim_closed' => (int)($stats['closed_trades'] ?? $stats['closed_now'] ?? 0),
            'sim_rejected' => (int)($stats['rejections_count'] ?? $stats['rejected'] ?? 0),
            'sim_win_rate' => (float)($stats['winrate'] ?? $stats['win_rate'] ?? 0.0),
            'sim_avg_roi' => (float)($stats['roi_avg'] ?? $stats['avg_roi'] ?? 0.0),
            'sim_profit_factor' => (float)($stats['profit_factor'] ?? 0.0),
            'sim_duration_ms' => (int)($stats['duration_ms'] ?? $lastRun['duration_ms'] ?? 0),
        ];
    }
    
    /**
     * Save pipeline run result
     * 
     * @param array $run Run data
     * @return void
     */
    private function saveRun(array $run): void
    {
        $file = $this->runsDir . '/' . $run['id'] . '.json';
        file_put_contents($file, json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        $this->cleanOldRuns();
    }
    
    /**
     * Clean old run files
     * 
     * @return void
     */
    private function cleanOldRuns(): void
    {
        $maxHistory = $this->getConfig('run.max_history', 100);
        $files = glob($this->runsDir . '/*.json') ?: [];
        
        if (count($files) <= $maxHistory) {
            return;
        }
        
        usort($files, fn($a, $b) => filemtime($a) - filemtime($b));
        
        $toDelete = count($files) - $maxHistory;
        for ($i = 0; $i < $toDelete; $i++) {
            @unlink($files[$i]);
        }
    }
    
    /**
     * Update last_run.json
     * 
     * C1.1: Write last_run in compatible format with both ok/success and finished_at/timestamp
     * 
     * @param array $run Run data
     * @return void
     */
    private function updateLastRun(array $run): void
    {
        $file = $this->storageDir . '/last_run.json';
        
        // C1.1: Compute success/ok with fallback (both fields for backward compatibility)
        $success = (bool)($run['success'] ?? $run['ok'] ?? false);
        $ok = (bool)($run['ok'] ?? $run['success'] ?? false);
        
        // C1.1: finished_at/timestamp with fallback
        $finishedAt = $run['finished_at'] ?? $run['timestamp'] ?? date('c');
        
        // C1.1: Determine status string (ok|error|config_error|idle)
        $status = 'ok';
        if (!$success) {
            $status = 'error';
            // Check for config errors (fix operator precedence with explicit parentheses)
            if (!empty($run['config_error']) || (!empty($run['errors']) && in_array('config_error', $run['errors'], true))) {
                $status = 'config_error';
            }
        }
        
        $data = [
            'id' => $run['id'] ?? 'run_unknown',
            'ts' => time(),
            // C1.1: Both timestamp formats for backward compatibility
            'timestamp' => $finishedAt,
            'finished_at' => $finishedAt,
            // A5: Unix timestamp for UI/sorting/debugging
            'finished_at_ts_unix' => is_numeric($finishedAt) ? (int)$finishedAt : strtotime($finishedAt),
            // C1.1: Both ok/success for backward compatibility
            'ok' => $ok,
            'success' => $success,
            // C1.1: Status string
            'status' => $status,
            'duration_ms' => $run['duration_ms'] ?? 0,
            'steps' => count($run['steps'] ?? []),
            // C1.1: Required summary fields
            'strategies_applied' => $run['strategies_applied'] ?? count($run['strategies'] ?? []),
            'candidates_loaded' => $run['candidates_loaded'] ?? 0,
            'signals_generated' => $run['signals_generated'] ?? 0,
            // STEP 7: Pipeline summary fields
            'signals_written' => $run['signals_written'] ?? 0,
            'signals_rejected_schema_incomplete' => $run['signals_rejected_schema_incomplete'] ?? 0,
            'management_commands_written' => $run['management_commands_written'] ?? 0,
            'learning_recommendations_applied_count' => $run['learning_recommendations_applied_count'] ?? 0,
            'trailing_mode_source_learning_count' => $run['trailing_mode_source_learning_count'] ?? 0,
            // P6.2: Contract versioning block
            'contract' => [
                'signals_schema' => 'clean_signal_v1',
                'risk_schema' => 'risk_active_v1',
                'trade_schema' => 'sim_trade_v1',
            ],
        ];
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Get run history
     * 
     * @param int $limit Maximum number of runs to return
     * @return array Array of run records
     */
    public function getRuns(int $limit = 20): array
    {
        $runs = [];
        $files = glob($this->runsDir . '/*.json') ?: [];
        
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $files = array_slice($files, 0, $limit);
        
        foreach ($files as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data)) {
                $runs[] = $data;
            }
        }
        
        return $runs;
    }
    
    /**
     * Get feedback history
     * 
     * @param int $limit Maximum number of feedback records to return
     * @return array Array of feedback records
     */
    public function getFeedback(int $limit = 50): array
    {
        $feedback = [];
        $files = glob($this->feedbackDir . '/*.json') ?: [];
        
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $files = array_slice($files, 0, $limit);
        
        foreach ($files as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data)) {
                $feedback[] = $data;
            }
        }
        
        return $feedback;
    }
    
    /**
     * Get last run info
     * 
     * @return array|null Last run data or null if not found
     */
    public function getLastRun(): ?array
    {
        $file = $this->storageDir . '/last_run.json';
        if (!is_file($file)) {
            return null;
        }
        
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }
    
    /**
     * Get system status summary
     * 
     * @return array Status summary
     */
    public function getStats(): array
    {
        $strategies = $this->getStrategies();
        $lastRun = $this->getLastRun();
        $runs = $this->getRuns(10);
        
        $successfulRuns = 0;
        foreach ($runs as $run) {
            if ($run['success'] ?? false) {
                $successfulRuns++;
            }
        }
        
        return [
            'total_strategies' => count($strategies),
            'simulator_enabled' => count(array_filter($strategies, fn($s) => $s['enabled_for_simulator'] ?? false)),
            'executor_enabled' => count(array_filter($strategies, fn($s) => $s['enabled_for_executor'] ?? false)),
            'last_run' => $lastRun,
            'total_runs' => count($runs),
            'successful_runs' => $successfulRuns,
            'module_paths' => $this->modulePaths,
        ];
    }
    
    /**
     * Get module status (what modules are available)
     * 
     * @return array Module status info
     */
    public function getModuleStatus(): array
    {
        return [
            'parser4' => [
                'available' => isset($this->modulePaths['parser4']),
                'path' => $this->modulePaths['parser4'] ?? null,
                'has_candidates' => isset($this->modulePaths['parser4']) && is_file($this->modulePaths['parser4'] . '/candidates.json'),
            ],
            'parser5' => [
                'available' => isset($this->modulePaths['parser5']),
                'path' => $this->modulePaths['parser5'] ?? null,
                'has_signals' => isset($this->modulePaths['parser5']) && is_file($this->modulePaths['parser5'] . '/signals.json'),
            ],
            'simulator' => [
                'available' => isset($this->modulePaths['simulator']),
                'path' => $this->modulePaths['simulator'] ?? null,
                'has_results' => isset($this->modulePaths['simulator']) && is_file($this->modulePaths['simulator'] . '/simulation.json'),
            ],
            'executor' => [
                'available' => isset($this->modulePaths['executor']),
                'path' => $this->modulePaths['executor'] ?? null,
            ],
        ];
    }
}

// ============================================================================
// RULES
// ============================================================================
// 1. This trait provides streaming I/O methods for BrainService
// 2. All methods access properties via $this-> from the consuming class
// 3. Properties used: $feedbackDir, $modulePaths, $config, $runsDir, $storageDir
// 4. Methods called from main class: getPassport(), getRiskBlockForSignal(),
//    chooseTrailingModeFromPassport(), applyLearningToTrailingMode(),
//    log(), getConfig(), getStrategies(), isoTimestamp()
// 5. Generator methods (readSignalsStream, readFeedbackStream) yield records
//    one at a time for memory efficiency
// 6. All file operations use proper error handling with is_file() checks
// 7. This trait should be used with: use BrainStreamsTrait;
// ============================================================================

/* RULES
- BrainStreamsTrait applies Symbol Policy gates per normalized signal.
- Live stats are loaded once per gateway run (cached by TTL).
- LF only
*/
