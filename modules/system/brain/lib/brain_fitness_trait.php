<?php
declare(strict_types=1);

namespace Modules\System\Brain\Lib;

/**
 * Brain Fitness Trait
 * 
 * Contains fitness calculation and feedback methods extracted from BrainService.
 * Provides strategy fitness scoring, auto-disable for low performers, and 
 * meta-engine strategy generation/search capabilities.
 */
trait BrainFitnessTrait
{
    /**
     * Apply feedback to a single strategy (Блок 3)
     * 
     * Rules:
     * - Low win rate (< 40%) → increase selectivity, decrease risk
     * - High drawdown (> 80% of max) → reduce max_drawdown_pct
     * - Low ROI (< 50% of target) → adjust target_roi_pct
     * - Very high win rate (> 70%) with low ROI → can be more aggressive
     * 
     * @param string $strategyId Strategy ID
     * @return array Feedback result
     */
    public function applyFeedbackToStrategy(string $strategyId): array
    {
        $strategy = $this->getStrategy($strategyId);
        if ($strategy === null) {
            return ['success' => false, 'error' => 'Strategy not found'];
        }
        
        $performance = $strategy['performance'] ?? [];
        $constraints = $strategy['constraints'] ?? [];
        
        $result = [
            'strategy_id' => $strategyId,
            'applied' => false,
            'adjustments' => [],
            'previous_constraints' => $constraints,
            'new_constraints' => $constraints,
            'reason' => null,
            'confidence_check' => [], // Block B: Track confidence requirements
        ];
        
        $winRate = (float)($performance['win_rate'] ?? 0);
        $avgRoi = (float)($performance['avg_roi'] ?? 0);
        $avgDrawdown = (float)($performance['avg_drawdown'] ?? 0);
        
        // ====================================================================
        // Block B: Feedback Confidence Requirements (v2.1)
        // ====================================================================
        
        $totalSignals = (int)($performance['total_signals'] ?? 0);
        $confidence = (float)($performance['confidence'] ?? 0);
        $sampleDays = (int)($performance['sample_days'] ?? 0);
        
        // Calculate confidence from sample size if not provided
        // Simple linear relationship: 100+ signals = 1.0 confidence
        if ($confidence === 0.0 && $totalSignals > 0) {
            // Sample size-based confidence (more signals = higher confidence)
            $confidence = min(1.0, $totalSignals / 100.0);
        }
        
        // Track confidence requirements
        $result['confidence_check'] = [
            'signals_count' => $totalSignals,
            'signals_required' => self::MIN_SIGNALS_FOR_FEEDBACK,
            'signals_ok' => $totalSignals >= self::MIN_SIGNALS_FOR_FEEDBACK,
            'confidence' => $confidence,
            'confidence_required' => self::MIN_CONFIDENCE_FOR_FEEDBACK,
            'confidence_ok' => $confidence >= self::MIN_CONFIDENCE_FOR_FEEDBACK,
            'sample_days' => $sampleDays,
            'sample_days_required' => self::MIN_SAMPLE_DAYS_FOR_FEEDBACK,
            'sample_days_ok' => $sampleDays >= self::MIN_SAMPLE_DAYS_FOR_FEEDBACK,
        ];
        
        // Block B: Check signals count requirement
        if ($totalSignals < self::MIN_SIGNALS_FOR_FEEDBACK) {
            $result['reason'] = "Not enough signals for adjustment (need " . self::MIN_SIGNALS_FOR_FEEDBACK . ", have {$totalSignals})";
            return $result;
        }
        
        // Block B: Check confidence requirement
        if ($confidence < self::MIN_CONFIDENCE_FOR_FEEDBACK) {
            $result['reason'] = "Confidence too low for adjustment (need " . self::MIN_CONFIDENCE_FOR_FEEDBACK . ", have {$confidence})";
            return $result;
        }
        
        // Block B: Check sample days requirement
        if ($sampleDays < self::MIN_SAMPLE_DAYS_FOR_FEEDBACK) {
            $result['reason'] = "Not enough sample days for adjustment (need " . self::MIN_SAMPLE_DAYS_FOR_FEEDBACK . ", have {$sampleDays})";
            return $result;
        }
        
        $adjustments = [];
        $newConstraints = $constraints;
        
        // Rule 1: Low win rate (< 40%) → increase min_price_move, decrease risk
        if ($winRate < 40) {
            $adjustments[] = [
                'rule' => 'low_win_rate',
                'value' => $winRate,
                'action' => 'increase selectivity, decrease risk',
            ];
            
            // Reduce max drawdown to be more conservative
            $newConstraints['max_drawdown_pct'] = max(
                self::MIN_DRAWDOWN_PCT, 
                $constraints['max_drawdown_pct'] * self::LOW_WIN_RATE_DRAWDOWN_ADJUSTMENT
            );
            
            // Increase target ROI (more selective signals)
            $newConstraints['target_roi_pct'] = min(
                self::MAX_TARGET_ROI_PCT, 
                $constraints['target_roi_pct'] * self::LOW_WIN_RATE_ROI_ADJUSTMENT
            );
        }
        
        // Rule 2: High drawdown (> 80% of max allowed) → reduce risk
        $maxAllowedDrawdown = $constraints['max_drawdown_pct'] ?? 30;
        if ($avgDrawdown > $maxAllowedDrawdown * 0.8) {
            $adjustments[] = [
                'rule' => 'high_drawdown',
                'value' => $avgDrawdown,
                'threshold' => $maxAllowedDrawdown * 0.8,
                'action' => 'reduce max_drawdown_pct',
            ];
            
            $newConstraints['max_drawdown_pct'] = max(
                self::MIN_DRAWDOWN_PCT, 
                $constraints['max_drawdown_pct'] * self::HIGH_DRAWDOWN_ADJUSTMENT
            );
        }
        
        // Rule 3: Low ROI (< 50% of target) → adjust target
        $targetRoi = $constraints['target_roi_pct'] ?? 5;
        if ($avgRoi < $targetRoi * 0.5 && $avgRoi > 0) {
            $adjustments[] = [
                'rule' => 'low_roi',
                'value' => $avgRoi,
                'threshold' => $targetRoi * 0.5,
                'action' => 'adjust target_roi_pct closer to achievable',
            ];
            
            // Move target closer to actual achievable ROI
            $newConstraints['target_roi_pct'] = max(
                self::MIN_TARGET_ROI_PCT, 
                ($targetRoi + $avgRoi * 2) / 3
            );
        }
        
        // Rule 4: Very high win rate (> 70%) with low ROI → can be more aggressive
        if ($winRate > 70 && $avgRoi < $targetRoi) {
            $adjustments[] = [
                'rule' => 'high_win_low_roi',
                'win_rate' => $winRate,
                'avg_roi' => $avgRoi,
                'action' => 'increase target_roi_pct (can be more aggressive)',
            ];
            
            $newConstraints['target_roi_pct'] = min(
                self::MAX_TARGET_ROI_PCT, 
                $constraints['target_roi_pct'] * self::HIGH_WIN_RATE_AGGRESSION_ADJUSTMENT
            );
            $newConstraints['max_drawdown_pct'] = min(
                self::MAX_DRAWDOWN_PCT, 
                $constraints['max_drawdown_pct'] * self::HIGH_WIN_RATE_AGGRESSION_ADJUSTMENT
            );
        }
        
        // Apply adjustments if any
        if (!empty($adjustments)) {
            $result['applied'] = true;
            $result['adjustments'] = $adjustments;
            $result['new_constraints'] = $newConstraints;
            
            // Update strategy
            $strategy['constraints'] = $newConstraints;
            $strategy['parser5_profile'] = $this->generateParser5Profile($newConstraints);
            
            $saveResult = $this->saveStrategy($strategy);
            $result['save_result'] = $saveResult['success'];
            
            $this->log("Applied feedback adjustments to strategy: {$strategyId}, adjustments: " . count($adjustments));
        } else {
            $result['reason'] = 'No adjustments needed based on current performance';
        }
        
        return $result;
    }
    
    /**
     * Apply feedback to all eligible strategies (Блок 3)
     * 
     * @return array Results for all strategies
     */
    public function applyFeedbackToAllStrategies(): array
    {
        $strategies = $this->getStrategiesForSimulator();
        $results = [];
        
        foreach ($strategies as $id => $strategy) {
            $results[$id] = $this->applyFeedbackToStrategy($id);
        }
        
        return $results;
    }
    
    /**
     * Calculate fitness score for a strategy (Блок 5)
     * 
     * Formula: fitness_score = win_rate * FITNESS_WIN_RATE_WEIGHT + 
     *                          avg_roi * FITNESS_ROI_WEIGHT - 
     *                          avg_drawdown * FITNESS_DRAWDOWN_WEIGHT
     * 
     * @param string $strategyId Strategy ID
     * @return array Fitness calculation result
     */
    public function calculateFitnessScore(string $strategyId): array
    {
        $strategy = $this->getStrategy($strategyId);
        if ($strategy === null) {
            return ['success' => false, 'error' => 'Strategy not found'];
        }
        
        $performance = $strategy['performance'] ?? [];
        
        // Normalize values to 0-1 range for fair comparison
        // Win rate: convert percentage (0-100) to 0-1
        $winRate = (float)($performance['win_rate'] ?? 0) / 100;
        
        // ROI: normalize from typical range (-10% to +10%) to (-1 to 1)
        $avgRoi = min(1, max(-1, (float)($performance['avg_roi'] ?? 0) / self::ROI_NORMALIZATION_DIVISOR));
        
        // Drawdown: normalize from typical range (0-50%) to (0-1)
        $avgDrawdown = min(1, max(0, (float)($performance['avg_drawdown'] ?? 0) / self::DRAWDOWN_NORMALIZATION_DIVISOR));
        
        // ====================================================================
        // Block C: Time-aware Fitness (v2.1)
        // time_efficiency = avg_roi / avg_trade_duration
        // ====================================================================
        
        $avgTradeDuration = (float)($performance['avg_trade_duration_hours'] ?? 1); // Default 1 hour to avoid division by zero
        if ($avgTradeDuration < 0.1) {
            $avgTradeDuration = 0.1; // Floor at 6 minutes (0.1 hours) to prevent extreme values
        }
        
        // Time efficiency: ROI per hour (higher is better)
        $timeEfficiency = ((float)($performance['avg_roi'] ?? 0)) / $avgTradeDuration;
        
        // Normalize time efficiency (0-1% per hour normalizes to 0-1)
        $timeEfficiencyNorm = min(1, max(0, $timeEfficiency / self::TIME_EFFICIENCY_NORMALIZATION_DIVISOR));
        
        // Calculate fitness score using weighted formula (Block C: includes time_efficiency)
        // Formula: fitness = win_rate * 0.4 + avg_roi * 0.35 - avg_drawdown * 0.15 + time_efficiency * 0.10
        $fitnessScore = ($winRate * self::FITNESS_WIN_RATE_WEIGHT) + 
                        ($avgRoi * self::FITNESS_ROI_WEIGHT) - 
                        ($avgDrawdown * self::FITNESS_DRAWDOWN_WEIGHT) +
                        ($timeEfficiencyNorm * self::FITNESS_TIME_EFFICIENCY_WEIGHT);
        
        // Clamp to 0-1 range
        $fitnessScore = max(0, min(1, $fitnessScore));
        
        // Round to 2 decimal places
        $fitnessScore = round($fitnessScore, 2);
        
        // Update strategy with fitness score
        $strategy['fitness_score'] = $fitnessScore;
        $strategy['fitness_calculated_at'] = date('Y-m-d H:i:s');
        $strategy['time_efficiency'] = round($timeEfficiency, 4); // Block C
        
        // Save strategy
        $file = $this->strategiesDir . '/' . $this->sanitizeId($strategyId) . '.json';
        file_put_contents($file, json_encode($strategy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return [
            'success' => true,
            'strategy_id' => $strategyId,
            'fitness_score' => $fitnessScore,
            'time_efficiency' => $timeEfficiency, // Block C
            'components' => [
                'win_rate' => $performance['win_rate'] ?? 0,
                'avg_roi' => $performance['avg_roi'] ?? 0,
                'avg_drawdown' => $performance['avg_drawdown'] ?? 0,
                'avg_trade_duration_hours' => $avgTradeDuration, // Block C
            ],
            'normalized' => [
                'win_rate_norm' => $winRate,
                'avg_roi_norm' => $avgRoi,
                'avg_drawdown_norm' => $avgDrawdown,
                'time_efficiency_norm' => $timeEfficiencyNorm, // Block C
            ],
            'weights' => [
                'win_rate' => self::FITNESS_WIN_RATE_WEIGHT,
                'roi' => self::FITNESS_ROI_WEIGHT,
                'drawdown' => self::FITNESS_DRAWDOWN_WEIGHT,
                'time_efficiency' => self::FITNESS_TIME_EFFICIENCY_WEIGHT, // Block C
            ],
        ];
    }
    
    /**
     * Calculate fitness for all strategies (Блок 5)
     * 
     * @return array Results for all strategies
     */
    public function calculateAllFitnessScores(): array
    {
        $strategies = $this->getStrategies();
        $results = [];
        
        foreach ($strategies as $id => $strategy) {
            $results[$id] = $this->calculateFitnessScore($id);
        }
        
        return $results;
    }
    
    /**
     * Get strategies sorted by fitness score (Блок 5)
     * 
     * @param bool $ascending Sort ascending (worst first) or descending (best first)
     * @return array Sorted strategies with fitness scores
     */
    public function getStrategiesByFitness(bool $ascending = false): array
    {
        $strategies = $this->getStrategies();
        
        // Calculate fitness for strategies that don't have it
        foreach ($strategies as $id => &$strategy) {
            if (!isset($strategy['fitness_score'])) {
                $fitnessResult = $this->calculateFitnessScore($id);
                $strategy['fitness_score'] = $fitnessResult['fitness_score'] ?? 0;
            }
        }
        
        // Sort by fitness
        uasort($strategies, function ($a, $b) use ($ascending) {
            $fitnessA = $a['fitness_score'] ?? 0;
            $fitnessB = $b['fitness_score'] ?? 0;
            
            return $ascending 
                ? $fitnessA <=> $fitnessB 
                : $fitnessB <=> $fitnessA;
        });
        
        return $strategies;
    }
    
    /**
     * Auto-disable low fitness strategies (Блок 5)
     * 
     * Disables strategies with fitness_score < threshold
     * 
     * @param float $threshold Minimum fitness threshold (default 0.3)
     * @return array Disabled strategies
     */
    public function autoDisableLowFitnessStrategies(float $threshold = 0.3): array
    {
        $strategies = $this->getStrategies();
        $disabled = [];
        
        foreach ($strategies as $id => $strategy) {
            $fitness = $strategy['fitness_score'] ?? null;
            
            // Skip if no fitness calculated yet
            if ($fitness === null) {
                continue;
            }
            
            // Skip if already disabled
            if (!($strategy['enabled_for_simulator'] ?? false)) {
                continue;
            }
            
            // Disable if below threshold
            if ($fitness < $threshold) {
                $this->toggleSimulator($id, false);
                $this->toggleExecutor($id, false);
                
                $disabled[$id] = [
                    'name' => $strategy['name'],
                    'fitness_score' => $fitness,
                    'threshold' => $threshold,
                ];
                
                $this->log("Auto-disabled low fitness strategy: {$id} (fitness={$fitness}, threshold={$threshold})", 'warning');
            }
        }
        
        return $disabled;
    }
    
    /**
     * Generate strategies from parameter ranges (Блок 6 - Meta-Engine)
     * 
     * @param array $ranges Parameter ranges
     * @param int $count Number of strategies to generate
     * @return array Generated strategies
     */
    public function generateStrategies(array $ranges, int $count = 100): array
    {
        $defaults = [
            'roi_min' => 2,
            'roi_max' => 10,
            'drawdown_min' => 10,
            'drawdown_max' => 40,
            'duration_min' => 30,
            'duration_max' => 240,
            'sessions' => ['any', 'london', 'new_york', 'tokyo'],
            'directions' => ['both', 'long', 'short'],
        ];
        
        $ranges = array_merge($defaults, $ranges);
        $generated = [];
        
        // Generate batch ID to avoid timestamp collisions within the same second
        $batchId = date('Ymd_His') . '_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 6);
        
        for ($i = 0; $i < $count; $i++) {
            // Use batch ID + index + random suffix to ensure uniqueness
            $id = 'auto_' . $batchId . '_' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT);
            
            // Random parameters within ranges
            $targetRoi = $this->randomFloat($ranges['roi_min'], $ranges['roi_max']);
            $maxDrawdown = $this->randomFloat($ranges['drawdown_min'], $ranges['drawdown_max']);
            $duration = rand($ranges['duration_min'], $ranges['duration_max']);
            $session = $ranges['sessions'][array_rand($ranges['sessions'])];
            $direction = $ranges['directions'][array_rand($ranges['directions'])];
            
            $strategyData = [
                'id' => $id,
                'name' => "Auto Strategy #{$id}",
                'description' => "Auto-generated strategy with ROI={$targetRoi}%, DD={$maxDrawdown}%",
                'enabled_for_simulator' => true,
                'enabled_for_executor' => false,
                'target_roi_pct' => round($targetRoi, 1),
                'max_drawdown_pct' => round($maxDrawdown, 1),
                'duration_max_min' => $duration,
                'session' => $session,
                'direction' => $direction,
                'auto_generated' => true,
                'generation_params' => [
                    'ranges' => $ranges,
                    'index' => $i + 1,
                    'batch_id' => $batchId,
                    'generated_at' => date('Y-m-d H:i:s'),
                ],
            ];
            
            $result = $this->saveStrategy($strategyData);
            if ($result['success']) {
                $generated[] = $result['strategy'];
            }
        }
        
        $this->log("Generated {$count} auto strategies");
        
        return $generated;
    }
    
    /**
     * Run AUTO STRATEGY SEARCH mode (Блок 6 - Meta-Engine)
     * 
     * Full meta-engine workflow:
     * 1. Generate N strategies from ranges
     * 2. Run simulator on all
     * 3. Calculate fitness
     * 4. Keep top performers
     * 5. Delete the rest
     * 
     * @param array $ranges Parameter ranges
     * @param int $generateCount Number of strategies to generate
     * @param int $keepTop Number of top strategies to keep
     * @return array Search result
     */
    public function runAutoStrategySearch(array $ranges = [], int $generateCount = 100, int $keepTop = 5): array
    {
        $startTime = microtime(true);
        $runId = 'meta_' . date('Ymd_His');
        
        // Block D: Anti-Overfitting - calculate split ratio
        $splitRatio = self::TRAIN_VALIDATION_SPLIT_RATIO;
        
        $result = [
            'id' => $runId,
            'mode' => 'AUTO_STRATEGY_SEARCH',
            'started_at' => date('Y-m-d H:i:s'),
            'params' => [
                'ranges' => $ranges,
                'generate_count' => $generateCount,
                'keep_top' => $keepTop,
                'train_validation_split' => $splitRatio, // Block D
            ],
            'phases' => [],
            'top_strategies' => [],
            'deleted_strategies' => [],
            'success' => false,
            'anti_overfitting' => true, // Block D flag
        ];
        
        try {
            // Phase 1: Generate strategies
            $this->log("META-ENGINE: Phase 1 - Generating {$generateCount} strategies");
            $generated = $this->generateStrategies($ranges, $generateCount);
            $result['phases'][] = [
                'phase' => 'generate',
                'status' => 'ok',
                'count' => count($generated),
            ];
            
            // ================================================================
            // Block D: Anti-Overfitting - Two-phase simulation
            // Phase 2a: Train on first 70% of data
            // Phase 2b: Validate on remaining 30% of data
            // ================================================================
            
            $this->log("META-ENGINE: Phase 2a - Running simulator (TRAIN set, {$splitRatio}%)");
            $trainResults = [];
            foreach ($generated as $strategy) {
                // Run simulator with train_mode flag
                $simResult = $this->runSimulator($strategy['id'], ['mode' => self::SIMULATOR_MODE_TRAIN, 'split_ratio' => $splitRatio]);
                $trainResults[$strategy['id']] = $simResult['success'];
            }
            $result['phases'][] = [
                'phase' => 'simulate_train',
                'status' => 'ok',
                'simulated' => count($trainResults),
                'successful' => count(array_filter($trainResults)),
                'data_ratio' => $splitRatio,
            ];
            
            // Phase 2b: Validate on validation set
            $this->log("META-ENGINE: Phase 2b - Running simulator (VALIDATION set, " . round((1 - $splitRatio) * 100) . "%)");
            $validationResults = [];
            foreach ($generated as $strategy) {
                // Run simulator with validation_mode flag
                $simResult = $this->runSimulator($strategy['id'], ['mode' => self::SIMULATOR_MODE_VALIDATION, 'split_ratio' => $splitRatio]);
                $validationResults[$strategy['id']] = $simResult['success'];
            }
            $result['phases'][] = [
                'phase' => 'simulate_validation',
                'status' => 'ok',
                'simulated' => count($validationResults),
                'successful' => count(array_filter($validationResults)),
                'data_ratio' => round(1 - $splitRatio, 2),
            ];
            
            // Phase 3: Calculate fitness ONLY on validation data (Block D)
            $this->log("META-ENGINE: Phase 3 - Calculating fitness scores (VALIDATION data only - anti-overfitting)");
            $fitnessResults = [];
            foreach ($generated as $strategy) {
                // Note: Fitness is calculated from validation performance only
                $fitnessResult = $this->calculateFitnessScore($strategy['id']);
                $fitnessResults[$strategy['id']] = $fitnessResult['fitness_score'] ?? 0;
            }
            $result['phases'][] = [
                'phase' => 'fitness_validation',
                'status' => 'ok',
                'calculated' => count($fitnessResults),
                'based_on' => 'validation_data_only', // Block D
            ];
            
            // Phase 4: Sort by fitness and keep top N
            $this->log("META-ENGINE: Phase 4 - Selecting top {$keepTop} strategies (by validation fitness)");
            arsort($fitnessResults);
            $topIds = array_slice(array_keys($fitnessResults), 0, $keepTop);
            
            $result['top_strategies'] = [];
            foreach ($topIds as $id) {
                $strategy = $this->getStrategy($id);
                if ($strategy) {
                    // Enable top strategies for executor review
                    $this->toggleSimulator($id, true);
                    
                    $result['top_strategies'][] = [
                        'id' => $id,
                        'name' => $strategy['name'],
                        'fitness_score' => $fitnessResults[$id],
                        'fitness_type' => 'validation', // Block D
                        'constraints' => $strategy['constraints'] ?? [],
                    ];
                }
            }
            
            // Phase 5: Delete non-top strategies
            $this->log("META-ENGINE: Phase 5 - Deleting non-top strategies");
            $deleteCount = 0;
            foreach ($generated as $strategy) {
                if (!in_array($strategy['id'], $topIds)) {
                    $this->deleteStrategy($strategy['id']);
                    $result['deleted_strategies'][] = $strategy['id'];
                    $deleteCount++;
                }
            }
            $result['phases'][] = [
                'phase' => 'cleanup',
                'status' => 'ok',
                'kept' => $keepTop,
                'deleted' => $deleteCount,
            ];
            
            $result['success'] = true;
            
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
            $this->log("META-ENGINE: Error - " . $e->getMessage(), 'error');
        }
        
        $result['duration_ms'] = round((microtime(true) - $startTime) * 1000);
        $result['finished_at'] = date('Y-m-d H:i:s');
        
        // Save run result
        $this->saveRun($result);
        
        $this->log("META-ENGINE: Completed in {$result['duration_ms']}ms, kept {$keepTop} top strategies (anti-overfitting enabled)");
        
        return $result;
    }
    
    /**
     * Generate strategies with safety limits (Блок 6)
     * 
     * Batched generation to prevent resource exhaustion.
     * 
     * @param array $ranges Parameter ranges
     * @param int $count Number of strategies to generate
     * @return array Generated strategies
     */
    public function generateStrategiesSafe(array $ranges, int $count = 100): array
    {
        // Enforce max batch size
        if ($count > self::MAX_STRATEGIES_PER_BATCH) {
            $this->log("Safe Auto Search: Splitting {$count} into batches of " . self::MAX_STRATEGIES_PER_BATCH, 'info');
            
            $allGenerated = [];
            $remaining = $count;
            $batchNum = 0;
            
            while ($remaining > 0) {
                $batchNum++;
                $batchSize = min(self::MAX_STRATEGIES_PER_BATCH, $remaining);
                
                $this->log("Safe Auto Search: Processing batch {$batchNum}, size={$batchSize}", 'info');
                
                // Generate batch
                $batch = $this->generateStrategies($ranges, $batchSize);
                $allGenerated = array_merge($allGenerated, $batch);
                
                $remaining -= $batchSize;
                
                // Small delay between batches to prevent resource exhaustion
                if ($remaining > 0) {
                    usleep(100000); // 100ms
                }
            }
            
            return $allGenerated;
        }
        
        // Direct generation for small counts
        return $this->generateStrategies($ranges, $count);
    }
    
    /**
     * Run AUTO STRATEGY SEARCH with safety limits (Блок 6)
     * 
     * Replaces direct runAutoStrategySearch for production use.
     * - Max 50 strategies per batch
     * - Queue-based processing
     * - Memory-safe feedback processing
     * 
     * @param array $ranges Parameter ranges
     * @param int $generateCount Total strategies (will be batched)
     * @param int $keepTop Number of top strategies to keep
     * @return array Search result
     */
    public function runAutoStrategySearchSafe(array $ranges = [], int $generateCount = 100, int $keepTop = 5): array
    {
        $startTime = microtime(true);
        $runId = 'meta_safe_' . date('Ymd_His');
        
        // Check state machine - prevent double execution
        $startResult = $this->startPipelineRun($runId);
        if (!$startResult['success']) {
            return [
                'success' => false,
                'error' => $startResult['error'],
                'mode' => 'AUTO_STRATEGY_SEARCH_SAFE',
            ];
        }
        
        // Log event
        $this->logEvent(self::EVENT_AUTO_SEARCH, [
            'run_id' => $runId,
            'generate_count' => $generateCount,
            'keep_top' => $keepTop,
            'batched' => $generateCount > self::MAX_STRATEGIES_PER_BATCH,
        ]);
        
        $result = [
            'id' => $runId,
            'mode' => 'AUTO_STRATEGY_SEARCH_SAFE',
            'started_at' => date('Y-m-d H:i:s'),
            'params' => [
                'ranges' => $ranges,
                'generate_count' => $generateCount,
                'keep_top' => $keepTop,
                'max_batch_size' => self::MAX_STRATEGIES_PER_BATCH,
                'batches_required' => ceil($generateCount / self::MAX_STRATEGIES_PER_BATCH),
            ],
            'phases' => [],
            'top_strategies' => [],
            'deleted_strategies' => [],
            'success' => false,
        ];
        
        try {
            // Phase 1: Generate with batching
            $this->log("META-ENGINE SAFE: Phase 1 - Generating {$generateCount} strategies (batched)");
            $generated = $this->generateStrategiesSafe($ranges, $generateCount);
            $result['phases'][] = [
                'phase' => 'generate_batched',
                'status' => 'ok',
                'count' => count($generated),
                'batches' => ceil($generateCount / self::MAX_STRATEGIES_PER_BATCH),
            ];
            
            // Update queue in state
            $this->updateState([
                'strategies_queue' => array_column($generated, 'id'),
            ]);
            
            // Phase 2: Run simulations with telemetry
            $this->log("META-ENGINE SAFE: Phase 2 - Running simulations with telemetry");
            $simResults = [];
            foreach ($generated as $strategy) {
                $simStart = microtime(true);
                $simResult = $this->runSimulator($strategy['id']);
                $simDuration = round((microtime(true) - $simStart) * 1000);
                
                // Write process telemetry
                $this->writeProcessTelemetry([
                    'module' => 'simulator',
                    'start' => date('Y-m-d H:i:s', (int)$simStart),
                    'end' => date('Y-m-d H:i:s'),
                    'duration_ms' => $simDuration,
                    'success' => $simResult['success'],
                ]);
                
                $simResults[$strategy['id']] = $simResult['success'];
            }
            $result['phases'][] = [
                'phase' => 'simulate_with_telemetry',
                'status' => 'ok',
                'simulated' => count($simResults),
            ];
            
            // Phase 3: Calculate fitness using stream aggregates (memory-safe)
            $this->log("META-ENGINE SAFE: Phase 3 - Calculating fitness (memory-safe)");
            $fitnessResults = [];
            foreach ($generated as $strategy) {
                $fitnessResult = $this->calculateFitnessScore($strategy['id']);
                $fitnessResults[$strategy['id']] = $fitnessResult['fitness_score'] ?? 0;
            }
            $result['phases'][] = [
                'phase' => 'fitness_memory_safe',
                'status' => 'ok',
                'calculated' => count($fitnessResults),
            ];
            
            // Phase 4: Select top performers
            arsort($fitnessResults);
            $topIds = array_slice(array_keys($fitnessResults), 0, $keepTop);
            
            foreach ($topIds as $id) {
                $strategy = $this->getStrategy($id);
                if ($strategy) {
                    $result['top_strategies'][] = [
                        'id' => $id,
                        'name' => $strategy['name'],
                        'fitness_score' => $fitnessResults[$id],
                    ];
                }
            }
            
            // Phase 5: Cleanup non-top
            foreach ($generated as $strategy) {
                if (!in_array($strategy['id'], $topIds)) {
                    $this->deleteStrategy($strategy['id']);
                    $result['deleted_strategies'][] = $strategy['id'];
                }
            }
            
            // Clear queue
            $this->updateState(['strategies_queue' => []]);
            
            $result['success'] = true;
            
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
            $this->log("META-ENGINE SAFE: Error - " . $e->getMessage(), 'error');
        }
        
        $result['duration_ms'] = round((microtime(true) - $startTime) * 1000);
        $result['finished_at'] = date('Y-m-d H:i:s');
        
        // End pipeline run
        $this->endPipelineRun($runId, $result['success'], $result['error'] ?? null);
        
        // Save run result
        $this->saveRun($result);
        
        return $result;
    }
    
    /**
     * Apply feedback using stream processing (Блок 7 - Memory Safe)
     * 
     * Processes feedback without loading entire dataset into memory.
     * 
     * @param string $strategyId Strategy ID
     * @param string|null $feedbackFile NDJSON feedback file (optional)
     * @return array Result
     */
    public function applyFeedbackMemorySafe(string $strategyId, ?string $feedbackFile = null): array
    {
        $strategy = $this->getStrategy($strategyId);
        if ($strategy === null) {
            return ['success' => false, 'error' => 'Strategy not found'];
        }
        
        // Use default feedback file if not specified
        if ($feedbackFile === null) {
            $feedbackFile = $this->feedbackDir . '/feedback_stream.ndjson';
        }
        
        // Calculate aggregates from stream (no arrays in memory)
        $aggregates = $this->calculateFeedbackAggregatesStream($feedbackFile);
        
        if ($aggregates['count'] < self::MIN_SIGNALS_FOR_FEEDBACK) {
            return [
                'success' => false,
                'error' => "Not enough signals (have {$aggregates['count']}, need " . self::MIN_SIGNALS_FOR_FEEDBACK . ")",
                'aggregates' => $aggregates,
                'memory_safe' => true,
            ];
        }
        
        // Update strategy performance from stream aggregates
        $strategy['performance'] = [
            'total_signals' => $aggregates['count'],
            'profitable' => $aggregates['wins'],
            'win_rate' => $aggregates['win_rate'],
            'avg_roi' => $aggregates['avg_roi'],
            'avg_drawdown' => $aggregates['avg_drawdown'],
            'max_drawdown' => $aggregates['max_drawdown'],
            'avg_trade_duration_hours' => $aggregates['avg_duration_hours'],
            'last_evaluated' => date('Y-m-d H:i:s'),
            'evaluated_from_stream' => true,
        ];
        
        // Save updated strategy
        $file = $this->strategiesDir . '/' . $this->sanitizeId($strategyId) . '.json';
        file_put_contents($file, json_encode($strategy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        // Log event
        $this->logEvent(self::EVENT_STRATEGY_UPDATED, [
            'feedback_source' => 'stream',
            'signals_processed' => $aggregates['count'],
        ], $strategyId);
        
        return [
            'success' => true,
            'strategy_id' => $strategyId,
            'aggregates' => $aggregates,
            'memory_safe' => true,
        ];
    }
    
    /**
     * Update performance metrics from simulation results
     * 
     * @param array $simulation Simulation results
     * @param string|null $specificStrategyId Optional specific strategy ID
     * @return void
     */
    private function updatePerformanceFromSimulation(array $simulation, ?string $specificStrategyId = null): void
    {
        $strategyResults = $simulation['by_strategy'] ?? [];
        
        // If specific strategy ID provided, create mock results if not in simulation
        if ($specificStrategyId && !isset($strategyResults[$specificStrategyId])) {
            $strategyResults[$specificStrategyId] = $simulation;
        }
        
        foreach ($strategyResults as $strategyId => $stats) {
            $strategy = $this->getStrategy($strategyId);
            if ($strategy === null) {
                continue;
            }
            
            // Update performance from simulator feedback (NOT calculated by Brain)
            $strategy['performance'] = [
                'total_signals' => (int)($stats['total_signals'] ?? 0),
                'profitable' => (int)($stats['profitable'] ?? 0),
                'win_rate' => (float)($stats['win_rate'] ?? 0.0),
                'avg_roi' => (float)($stats['avg_roi'] ?? 0.0),
                'avg_drawdown' => (float)($stats['avg_drawdown'] ?? $stats['max_drawdown'] ?? 0.0),
                'max_drawdown' => (float)($stats['max_drawdown'] ?? 0.0),
                'last_evaluated' => date('Y-m-d H:i:s'),
            ];
            $strategy['updated_at'] = date('Y-m-d H:i:s');
            
            // Save updated strategy
            $file = $this->strategiesDir . '/' . $this->sanitizeId($strategyId) . '.json';
            file_put_contents($file, json_encode($strategy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        
        // Save feedback snapshot
        $feedbackFile = $this->feedbackDir . '/feedback_' . date('Ymd_His') . '.json';
        file_put_contents($feedbackFile, json_encode([
            'timestamp' => date('Y-m-d H:i:s'),
            'simulation' => $simulation,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

// ============================================================================
// RULES
// ============================================================================
// 
// 1. This trait MUST be used by BrainService class only
// 2. All properties accessed via $this-> are defined in BrainService
// 3. All constants (self::*) are defined in BrainService
// 4. Methods from BrainService used by this trait:
//    - getStrategy(string $strategyId): ?array
//    - getStrategies(): array
//    - getStrategiesForSimulator(): array
//    - saveStrategy(array $data): array
//    - deleteStrategy(string $strategyId): array
//    - toggleSimulator(string $id, bool $enable): array
//    - toggleExecutor(string $id, bool $enable): array
//    - generateParser5Profile(array $constraints): array
//    - runSimulator(string $strategyId, array $options = []): array
//    - saveRun(array $run): void
//    - log(string $message, string $level = 'info'): void
//    - logEvent(string $event, array $data = [], ?string $strategyId = null): void
//    - startPipelineRun(string $runId): array
//    - endPipelineRun(string $runId, bool $success, ?string $error = null): void
//    - updateState(array $data): void
//    - writeProcessTelemetry(array $data): void
//    - sanitizeId(string $id): string
//    - randomFloat(float $min, float $max): float
//    - calculateFeedbackAggregatesStream(string $file): array
// 5. Properties from BrainService used by this trait:
//    - $this->strategiesDir (string)
//    - $this->feedbackDir (string)
// 6. DO NOT add property declarations to this trait
// 7. DO NOT change method signatures
// ============================================================================
