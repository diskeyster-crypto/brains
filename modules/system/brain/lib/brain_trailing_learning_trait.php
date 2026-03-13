<?php

declare(strict_types=1);

namespace Modules\System\Brain\Lib;

/**
 * BrainTrailingLearningTrait - Smart Trailing learning methods
 * 
 * This trait is part of the BrainService refactor into traits.
 * No behavior changes are allowed (refactor-only).
 */
trait BrainTrailingLearningTrait
{
    /**
     * STEP 2: Choose trailing mode from passport metrics
     * Auto-selects tight/normal/loose based on coin performance
     * 
     * @param array|null $passport Passport data for the symbol
     * @param array $config Trailing mode auto config from config.php
     * @return array [mode, drawdown_factor, reason, is_mature]
     */
    public function chooseTrailingModeFromPassport(?array $passport, array $config): array
    {
        // Default result
        $defaultMode = 'normal';
        $modes = $config['modes'] ?? [];
        $defaultFactor = (float)($modes['normal']['drawdown_factor'] ?? 0.50);
        
        // Check if auto-select is enabled
        if (!($config['enabled'] ?? true)) {
            return [
                'mode' => $defaultMode,
                'drawdown_factor' => $defaultFactor,
                'reason' => 'disabled',
                'is_mature' => false,
            ];
        }
        
        // No passport
        if ($passport === null) {
            return [
                'mode' => $defaultMode,
                'drawdown_factor' => $defaultFactor,
                'reason' => 'no_passport',
                'is_mature' => false,
            ];
        }
        
        // Normalize passport metrics
        $tradesTotal = (int)($passport['trades_total'] ?? 0);
        
        // Normalize win_rate: if > 1, assume it's a percentage and divide by 100
        $winRate = (float)($passport['win_rate'] ?? 0);
        if ($winRate > 1) {
            $winRate = $winRate / 100;
        }
        $winRate = max(0, min(1, $winRate)); // Clamp 0..1
        
        // Absolute MAE (MAE is typically negative, e.g., -1.2%)
        $maeAbsPct = abs((float)($passport['median_mae'] ?? 0));
        
        // Absolute MFE
        $mfeAbsPct = abs((float)($passport['median_mfe'] ?? 0));
        
        // Median duration in minutes
        $medianDurationMin = (int)($passport['median_duration_min'] ?? 999);
        
        // Check maturity threshold
        $minTrades = (int)($config['min_trades_for_auto'] ?? 20);
        if ($tradesTotal < $minTrades) {
            return [
                'mode' => $defaultMode,
                'drawdown_factor' => $defaultFactor,
                'reason' => 'not_mature',
                'is_mature' => false,
            ];
        }
        
        // Get thresholds
        $looseIf = $config['loose_if'] ?? [];
        $tightIf = $config['tight_if'] ?? [];
        
        // Check LOOSE conditions (ALL must be true)
        $isLoose = (
            $winRate >= (float)($looseIf['min_win_rate'] ?? 0.60) &&
            $mfeAbsPct >= (float)($looseIf['min_mfe_abs_pct'] ?? 0.60) &&
            $medianDurationMin <= (int)($looseIf['max_median_duration_min'] ?? 45)
        );
        
        if ($isLoose) {
            $looseFactor = (float)($modes['loose']['drawdown_factor'] ?? 0.75);
            return [
                'mode' => 'loose',
                'drawdown_factor' => $looseFactor,
                'reason' => 'mode_loose',
                'is_mature' => true,
            ];
        }
        
        // Check TIGHT conditions (ANY must be true)
        $isTight = (
            $winRate <= (float)($tightIf['max_win_rate'] ?? 0.52) ||
            $maeAbsPct >= (float)($tightIf['min_mae_abs_pct'] ?? 0.35)
        );
        
        if ($isTight) {
            $tightFactor = (float)($modes['tight']['drawdown_factor'] ?? 0.25);
            return [
                'mode' => 'tight',
                'drawdown_factor' => $tightFactor,
                'reason' => 'mode_tight',
                'is_mature' => true,
            ];
        }
        
        // Default to NORMAL
        return [
            'mode' => 'normal',
            'drawdown_factor' => $defaultFactor,
            'reason' => 'mode_normal',
            'is_mature' => true,
        ];
    }
    
    /**
     * STEP 4 Part A: Load trailing episodes from NDJSON file safely
     * Reads file line by line, uses ring buffer for last maxLines entries
     * 
     * @param string $file Path to NDJSON file
     * @param int $maxLines Maximum lines to keep (ring buffer)
     * @param string|null $sourceExpected Expected source to filter (e.g., 'sim', 'live'). Null = no filter.
     * @param string|null $modeExpected Expected mode to filter (e.g., 'clean'). Null = no filter.
     * @return array Array of episode records
     */
    public function loadTrailingEpisodesFromNdjson(string $file, int $maxLines, ?string $sourceExpected = 'sim', ?string $modeExpected = 'clean'): array
    {
        if (!is_file($file)) {
            return [];
        }
        
        $handle = @fopen($file, 'r');
        if (!$handle) {
            return [];
        }
        
        $episodes = [];
        $count = 0;
        
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            $record = @json_decode($line, true);
            if (!is_array($record)) {
                continue; // Skip broken JSON lines
            }
            
            // Filter by source if specified
            if ($sourceExpected !== null) {
                $source = $record['source'] ?? '';
                if ($source !== $sourceExpected) {
                    continue;
                }
            }
            
            // Filter by mode if specified
            $mode = $record['mode'] ?? '';
            if ($modeExpected !== null) {
                if ($mode !== $modeExpected) {
                    continue;
                }
            }
            
            // Skip if no symbol (fail fast before building episode)
            $symbol = $record['symbol'] ?? '';
            if (empty($symbol)) {
                continue;
            }
            
            // Extract required fields
            $episode = [
                'symbol' => $symbol,
                'trade_id' => $record['trade_id'] ?? '',
                'mode' => $mode,
                'ts_closed_unix' => (int)($record['ts_closed_unix'] ?? 0),
                'trailing_mode' => $record['trailing']['mode'] ?? 'normal',
                'trailing_enabled' => (bool)($record['trailing']['enabled'] ?? false),
                'trailing_activated' => (bool)($record['trailing']['activated'] ?? false),
                'close_reason' => $record['close']['reason'] ?? '',
                'roi_net_pct' => (float)($record['close']['roi_net_pct'] ?? 0),
                'giveback_from_peak_pct' => (float)($record['trailing']['giveback_from_peak_pct'] ?? 0),
                'worst_drawdown_from_peak_pct' => (float)($record['trailing']['worst_drawdown_from_peak_pct'] ?? 0),
            ];
            
            // Ring buffer: keep only last maxLines
            $episodes[] = $episode;
            $count++;
            
            if ($count > $maxLines) {
                array_shift($episodes); // Remove oldest
            }
        }
        
        fclose($handle);
        
        return $episodes;
    }
    
    /**
     * STEP 5: Get trailing episodes with auto-select between LIVE and SIM sources
     * 
     * For each symbol, selects the preferred source based on data availability.
     * LIVE is preferred when it has enough data (min_total_episodes_live).
     * SIM is used as fallback.
     * 
     * @param array $cfg Learning trailing config
     * @return array [
     *     'episodes' => array of selected episodes (ready for computeStats),
     *     'live_lines_loaded' => int,
     *     'sim_lines_loaded' => int,
     *     'symbols_using_live' => int,
     *     'symbols_using_sim' => int,
     *     'source_per_symbol' => ['BTCUSDT' => 'live', 'ETHUSDT' => 'sim', ...]
     * ]
     */
    public function getTrailingEpisodesForLearning(array $cfg): array
    {
        $result = [
            'episodes' => [],
            'live_lines_loaded' => 0,
            'sim_lines_loaded' => 0,
            'symbols_using_live' => 0,
            'symbols_using_sim' => 0,
            'source_per_symbol' => [],
        ];
        
        $sources = $cfg['dataset_sources'] ?? [];
        $maxLines = (int)($cfg['max_lines'] ?? 20000);
        $preferLive = (bool)($cfg['prefer_live_if_available'] ?? true);
        $minTotalLive = (int)($cfg['min_total_episodes_live'] ?? 60);
        $minPerModeLive = (int)($cfg['min_episodes_per_mode_live'] ?? 20);
        
        // Find simulator storage path
        $simulatorStorage = $this->modulePaths['simulator'] ?? null;
        if (!$simulatorStorage) {
            foreach ($this->modulePaths as $key => $path) {
                if (strpos($key, 'simulator') !== false || strpos($key, 'parser6') !== false) {
                    $simulatorStorage = $path;
                    break;
                }
            }
        }
        
        if (!$simulatorStorage) {
            return $result;
        }
        
        // Load LIVE episodes if enabled
        $liveEpisodes = [];
        $liveBySymbol = [];
        if (($sources['live']['enabled'] ?? false) && !empty($sources['live']['filename'])) {
            $liveFile = $simulatorStorage . '/' . $sources['live']['filename'];
            $sourceExpected = $sources['live']['source_expected'] ?? 'live';
            $modeExpected = $sources['live']['mode_expected'] ?? 'clean';
            
            $liveEpisodes = $this->loadTrailingEpisodesFromNdjson($liveFile, $maxLines, $sourceExpected, $modeExpected);
            $result['live_lines_loaded'] = count($liveEpisodes);
            
            // Group LIVE by symbol for counting
            foreach ($liveEpisodes as $ep) {
                $symbol = $ep['symbol'] ?? '';
                if (!empty($symbol)) {
                    $liveBySymbol[$symbol][] = $ep;
                }
            }
        }
        
        // Load SIM episodes if enabled
        $simEpisodes = [];
        $simBySymbol = [];
        if (($sources['sim']['enabled'] ?? false) && !empty($sources['sim']['filename'])) {
            $simFile = $simulatorStorage . '/' . $sources['sim']['filename'];
            $sourceExpected = $sources['sim']['source_expected'] ?? 'sim';
            $modeExpected = $sources['sim']['mode_expected'] ?? 'clean';
            
            $simEpisodes = $this->loadTrailingEpisodesFromNdjson($simFile, $maxLines, $sourceExpected, $modeExpected);
            $result['sim_lines_loaded'] = count($simEpisodes);
            
            // Group SIM by symbol for counting
            foreach ($simEpisodes as $ep) {
                $symbol = $ep['symbol'] ?? '';
                if (!empty($symbol)) {
                    $simBySymbol[$symbol][] = $ep;
                }
            }
        }
        
        // Get all unique symbols
        $allSymbols = array_unique(array_merge(array_keys($liveBySymbol), array_keys($simBySymbol)));
        
        // Select source per symbol
        $selectedEpisodes = [];
        foreach ($allSymbols as $symbol) {
            $liveCount = count($liveBySymbol[$symbol] ?? []);
            $simCount = count($simBySymbol[$symbol] ?? []);
            
            // Check if LIVE has enough data for this symbol
            $useLive = false;
            if ($preferLive && $liveCount >= $minTotalLive) {
                // Additionally check if LIVE has enough per mode
                $livePerMode = $this->countEpisodesPerMode($liveBySymbol[$symbol] ?? []);
                $hasEnoughPerMode = true;
                foreach (['tight', 'normal', 'loose'] as $mode) {
                    if (($livePerMode[$mode] ?? 0) < $minPerModeLive) {
                        $hasEnoughPerMode = false;
                        break;
                    }
                }
                $useLive = $hasEnoughPerMode || $liveCount >= $minTotalLive * 2; // Allow 2x total to compensate
            }
            
            if ($useLive && $liveCount > 0) {
                // Use LIVE for this symbol
                $selectedEpisodes = array_merge($selectedEpisodes, $liveBySymbol[$symbol]);
                $result['source_per_symbol'][$symbol] = 'live';
                $result['symbols_using_live']++;
            } elseif ($simCount > 0) {
                // Use SIM for this symbol
                $selectedEpisodes = array_merge($selectedEpisodes, $simBySymbol[$symbol]);
                $result['source_per_symbol'][$symbol] = 'sim';
                $result['symbols_using_sim']++;
            }
        }
        
        $result['episodes'] = $selectedEpisodes;
        return $result;
    }
    
    /**
     * Helper: Count episodes per trailing mode
     */
    private function countEpisodesPerMode(array $episodes): array
    {
        $counts = ['tight' => 0, 'normal' => 0, 'loose' => 0];
        foreach ($episodes as $ep) {
            $mode = $ep['trailing_mode'] ?? 'normal';
            if (isset($counts[$mode])) {
                $counts[$mode]++;
            }
        }
        return $counts;
    }
    
    /**
     * STEP 4 Part B: Compute trailing mode statistics by symbol
     * Groups episodes by symbol and trailing_mode, calculates medians
     * 
     * @param array $episodes Array of episode records
     * @param int $windowPerSymbol Number of recent episodes per symbol to use
     * @return array Stats grouped by symbol then mode
     */
    public function computeTrailingModeStats(array $episodes, int $windowPerSymbol): array
    {
        // Group by symbol first
        $bySymbol = [];
        foreach ($episodes as $ep) {
            $symbol = $ep['symbol'] ?? '';
            if (empty($symbol)) {
                continue;
            }
            $bySymbol[$symbol][] = $ep;
        }
        
        $result = [];
        
        foreach ($bySymbol as $symbol => $symbolEpisodes) {
            // Sort by ts_closed_unix descending (newest first)
            usort($symbolEpisodes, function($a, $b) {
                return ($b['ts_closed_unix'] ?? 0) - ($a['ts_closed_unix'] ?? 0);
            });
            
            // Take only last windowPerSymbol episodes
            $recent = array_slice($symbolEpisodes, 0, $windowPerSymbol);
            
            // Group by trailing_mode
            $byMode = [];
            foreach ($recent as $ep) {
                $mode = $ep['trailing_mode'] ?? 'normal';
                $byMode[$mode][] = $ep;
            }
            
            // Calculate stats for each mode
            $symbolStats = [];
            foreach ($byMode as $mode => $modeEpisodes) {
                $count = count($modeEpisodes);
                
                // Extract arrays for median calculation
                $roiValues = array_map(fn($e) => $e['roi_net_pct'], $modeEpisodes);
                $givebackValues = array_map(fn($e) => $e['giveback_from_peak_pct'], $modeEpisodes);
                $drawdownValues = array_map(fn($e) => $e['worst_drawdown_from_peak_pct'], $modeEpisodes);
                
                $symbolStats[$mode] = [
                    'count' => $count,
                    'median_roi_net_pct' => $this->calculateMedian($roiValues),
                    'median_giveback_from_peak_pct' => $this->calculateMedian($givebackValues),
                    'median_worst_drawdown_from_peak_pct' => $this->calculateMedian($drawdownValues),
                ];
            }
            
            $result[$symbol] = $symbolStats;
        }
        
        return $result;
    }
    
    /**
     * STEP 4 Part C: Choose best trailing mode for a symbol
     * Compares modes by score, checks improvement threshold
     * 
     * @param array $statsForSymbol Stats by mode for one symbol
     * @param array $cfg Learning config
     * @return array [selected_mode, reason, selected_score, delta_vs_normal_roi]
     */
    public function chooseBestTrailingModeForSymbol(array $statsForSymbol, array $cfg): array
    {
        $minEpisodesPerMode = (int)($cfg['min_episodes_per_mode'] ?? 20);
        $minImprovementRoi = (float)($cfg['min_improvement_roi_pct'] ?? 0.20);
        $weights = $cfg['score_weights'] ?? [];
        $wRoi = (float)($weights['roi_net_pct'] ?? 1.0);
        $wGiveback = (float)($weights['giveback_from_peak_pct'] ?? 0.30);
        $wDrawdown = (float)($weights['worst_drawdown_from_peak_pct'] ?? 0.20);
        
        // Get modes config for drawdown factors
        $modesConfig = $this->config['passport']['trailing_mode_auto']['modes'] ?? [];
        
        // Check if normal mode exists with enough data
        $normalStats = $statsForSymbol['normal'] ?? null;
        if ($normalStats === null || $normalStats['count'] < $minEpisodesPerMode) {
            return [
                'selected_mode' => 'normal',
                'selected_drawdown_factor' => (float)($modesConfig['normal']['drawdown_factor'] ?? 0.50),
                'reason' => 'not_enough_data',
                'selected_score' => 0,
                'delta_vs_normal_roi_pct' => 0,
            ];
        }
        
        // Calculate score for normal (baseline)
        $normalScore = $this->calculateModeScore(
            $normalStats['median_roi_net_pct'],
            $normalStats['median_giveback_from_peak_pct'],
            $normalStats['median_worst_drawdown_from_peak_pct'],
            $wRoi, $wGiveback, $wDrawdown
        );
        
        // Find best mode among candidates with enough data
        $bestMode = 'normal';
        $bestScore = $normalScore;
        $bestStats = $normalStats;
        
        foreach (['tight', 'normal', 'loose'] as $mode) {
            $stats = $statsForSymbol[$mode] ?? null;
            if ($stats === null || $stats['count'] < $minEpisodesPerMode) {
                continue;
            }
            
            $score = $this->calculateModeScore(
                $stats['median_roi_net_pct'],
                $stats['median_giveback_from_peak_pct'],
                $stats['median_worst_drawdown_from_peak_pct'],
                $wRoi, $wGiveback, $wDrawdown
            );
            
            if ($score > $bestScore) {
                $bestMode = $mode;
                $bestScore = $score;
                $bestStats = $stats;
            }
        }
        
        // Check improvement threshold
        $deltaRoi = $bestStats['median_roi_net_pct'] - $normalStats['median_roi_net_pct'];
        
        if ($bestMode !== 'normal' && $deltaRoi < $minImprovementRoi) {
            // Not enough improvement, stick with normal
            return [
                'selected_mode' => 'normal',
                'selected_drawdown_factor' => (float)($modesConfig['normal']['drawdown_factor'] ?? 0.50),
                'reason' => 'not_enough_improvement',
                'selected_score' => $normalScore,
                'delta_vs_normal_roi_pct' => 0,
            ];
        }
        
        return [
            'selected_mode' => $bestMode,
            'selected_drawdown_factor' => (float)($modesConfig[$bestMode]['drawdown_factor'] ?? 0.50),
            'reason' => 'learning_best_score',
            'selected_score' => $bestScore,
            'delta_vs_normal_roi_pct' => round($deltaRoi, 4),
        ];
    }
    
    /**
     * Calculate score for a mode
     * score = w_roi * roi - w_giveback * giveback - w_drawdown * drawdown
     */
    private function calculateModeScore(float $roi, float $giveback, float $drawdown, float $wRoi, float $wGiveback, float $wDrawdown): float
    {
        return ($wRoi * $roi) - ($wGiveback * $giveback) - ($wDrawdown * $drawdown);
    }
    
    /**
     * STEP 4 Part D1: Get learning recommendation file path for symbol
     */
    private function getLearningRecommendationPath(string $symbol): string
    {
        $storageDir = $this->modulePaths['brain'] ?? '';
        return $storageDir . '/learning/trailing/' . $symbol . '.json';
    }
    
    /**
     * STEP 4 Part D2: Load learning recommendation for symbol
     * 
     * @param string $symbol Trading symbol
     * @return array|null Recommendation or null if not exists
     */
    public function loadLearningRecommendation(string $symbol): ?array
    {
        $path = $this->getLearningRecommendationPath($symbol);
        if (!is_file($path)) {
            return null;
        }
        
        $data = @json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }
        
        return $data;
    }
    
    /**
     * STEP 4 Part D3: Save learning recommendation for symbol
     * Checks update interval before saving
     * 
     * @param string $symbol Trading symbol
     * @param array $recommendation Recommendation data
     * @param int $minUpdateInterval Minimum seconds between updates
     * @return bool True if saved, false if throttled
     */
    public function saveLearningRecommendation(string $symbol, array $recommendation, int $minUpdateInterval): bool
    {
        $path = $this->getLearningRecommendationPath($symbol);
        
        // Check existing file for update throttling
        $existing = $this->loadLearningRecommendation($symbol);
        if ($existing !== null) {
            $lastUpdate = (int)($existing['updated_ts_unix'] ?? 0);
            $now = time();
            if (($now - $lastUpdate) < $minUpdateInterval) {
                return false; // Throttled
            }
        }
        
        // Add timestamp
        $recommendation['updated_ts_unix'] = time();
        $recommendation['updated_at'] = date('c');
        
        // Ensure directory exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        // Write atomically
        $tmp = $path . '.tmp';
        $written = @file_put_contents($tmp, json_encode($recommendation, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($written !== false) {
            @rename($tmp, $path);
            return true;
        }
        
        return false;
    }
    
    /**
     * STEP 4 Part D4: Check rollback condition
     * If recent performance of selected mode is worse than normal, rollback to normal
     * 
     * @param array $episodes All episodes
     * @param string $symbol Symbol to check
     * @param string $selectedMode Currently selected mode
     * @param array $rollbackCfg Rollback configuration
     * @return bool True if should rollback to normal
     */
    public function shouldRollbackToNormal(array $episodes, string $symbol, string $selectedMode, array $rollbackCfg): bool
    {
        if (!($rollbackCfg['enabled'] ?? true)) {
            return false;
        }
        
        if ($selectedMode === 'normal') {
            return false; // Already normal
        }
        
        $window = (int)($rollbackCfg['window'] ?? 80);
        $maxDegradation = (float)($rollbackCfg['max_degradation_roi_pct'] ?? 0.20);
        
        // Filter episodes for this symbol
        $symbolEpisodes = array_filter($episodes, fn($e) => ($e['symbol'] ?? '') === $symbol);
        
        // Sort by ts descending (newest first)
        usort($symbolEpisodes, function($a, $b) {
            return ($b['ts_closed_unix'] ?? 0) - ($a['ts_closed_unix'] ?? 0);
        });
        
        // Take recent window
        $recent = array_slice($symbolEpisodes, 0, $window);
        
        // Separate by mode
        $selectedModeEpisodes = array_filter($recent, fn($e) => ($e['trailing_mode'] ?? '') === $selectedMode);
        $normalEpisodes = array_filter($recent, fn($e) => ($e['trailing_mode'] ?? '') === 'normal');
        
        if (count($selectedModeEpisodes) < 5 || count($normalEpisodes) < 5) {
            return false; // Not enough data for comparison
        }
        
        // Calculate median ROI for both
        $selectedRoiValues = array_map(fn($e) => $e['roi_net_pct'], $selectedModeEpisodes);
        $normalRoiValues = array_map(fn($e) => $e['roi_net_pct'], $normalEpisodes);
        
        $selectedMedian = $this->calculateMedian($selectedRoiValues);
        $normalMedian = $this->calculateMedian($normalRoiValues);
        
        // Check if selected mode is worse than normal by more than threshold
        $degradation = $normalMedian - $selectedMedian;
        
        return $degradation > $maxDegradation;
    }
    
    /**
     * STEP 4+5: Update learning recommendations for all symbols
     * Called periodically to analyze datasets and update recommendations
     * Uses multi-source loading (LIVE + SIM) with auto-select per symbol
     * 
     * @return array Update results with diagnostic counters
     */
    public function updateLearningRecommendations(): array
    {
        $result = [
            'success' => false,
            'symbols_analyzed' => 0,
            'recommendations_updated' => 0,
            'recommendations_throttled' => 0,
            'rollbacks' => 0,
            'errors' => [],
            // STEP 5: Diagnostic counters
            'learning_live_lines_loaded' => 0,
            'learning_sim_lines_loaded' => 0,
            'learning_symbols_using_live_count' => 0,
            'learning_symbols_using_sim_count' => 0,
        ];
        
        $learningCfg = $this->config['learning']['trailing'] ?? [];
        if (!($learningCfg['enabled'] ?? true)) {
            $result['success'] = true;
            $result['errors'][] = 'Learning trailing disabled in config';
            return $result;
        }
        
        // STEP 5: Load episodes using multi-source auto-select
        $loadResult = $this->getTrailingEpisodesForLearning($learningCfg);
        $episodes = $loadResult['episodes'];
        $sourcePerSymbol = $loadResult['source_per_symbol'];
        
        // Set diagnostic counters
        $result['learning_live_lines_loaded'] = $loadResult['live_lines_loaded'];
        $result['learning_sim_lines_loaded'] = $loadResult['sim_lines_loaded'];
        $result['learning_symbols_using_live_count'] = $loadResult['symbols_using_live'];
        $result['learning_symbols_using_sim_count'] = $loadResult['symbols_using_sim'];
        
        if (empty($episodes)) {
            $result['errors'][] = 'No valid episodes in any dataset';
            return $result;
        }
        
        // Compute stats
        $windowPerSymbol = (int)($learningCfg['window_per_symbol'] ?? 200);
        $stats = $this->computeTrailingModeStats($episodes, $windowPerSymbol);
        
        // Get additional config
        $minTotalEpisodes = (int)($learningCfg['min_total_episodes'] ?? 60);
        $minUpdateInterval = (int)($learningCfg['min_update_interval_sec'] ?? 86400);
        $rollbackCfg = $learningCfg['rollback'] ?? [];
        
        // Update index file
        $indexSymbols = [];
        
        // Process each symbol
        foreach ($stats as $symbol => $symbolStats) {
            $result['symbols_analyzed']++;
            
            // Check total episodes
            $totalEpisodes = array_sum(array_column($symbolStats, 'count'));
            if ($totalEpisodes < $minTotalEpisodes) {
                continue; // Not enough data
            }
            
            // Choose best mode
            $choice = $this->chooseBestTrailingModeForSymbol($symbolStats, $learningCfg);
            
            // Check rollback
            $shouldRollback = $this->shouldRollbackToNormal($episodes, $symbol, $choice['selected_mode'], $rollbackCfg);
            if ($shouldRollback) {
                $modesConfig = $this->config['passport']['trailing_mode_auto']['modes'] ?? [];
                $choice['selected_mode'] = 'normal';
                $choice['selected_drawdown_factor'] = (float)($modesConfig['normal']['drawdown_factor'] ?? 0.50);
                $choice['reason'] = 'rollback_to_normal';
                $result['rollbacks']++;
            }
            
            // STEP 5: Determine dataset source for this symbol
            $datasetSource = $sourcePerSymbol[$symbol] ?? 'sim';
            
            // Build recommendation
            $recommendation = [
                'schema_version' => '1.1',  // Updated for STEP 5
                'symbol' => $symbol,
                'dataset_source' => $datasetSource,  // STEP 5: Track source
                'window_per_symbol' => $windowPerSymbol,
                'selected_mode' => $choice['selected_mode'],
                'selected_drawdown_factor' => $choice['selected_drawdown_factor'],
                'reason' => $choice['reason'],
                'delta_vs_normal_roi_pct' => $choice['delta_vs_normal_roi_pct'],
                'stats' => $symbolStats,
            ];
            
            // Save recommendation (with throttle check)
            $saved = $this->saveLearningRecommendation($symbol, $recommendation, $minUpdateInterval);
            if ($saved) {
                $result['recommendations_updated']++;
                $indexSymbols[] = $symbol;
            } else {
                $result['recommendations_throttled']++;
            }
        }
        
        // Update index file
        $this->updateLearningIndex($indexSymbols);
        
        $result['success'] = true;
        return $result;
    }
    
    /**
     * Update learning index file
     */
    private function updateLearningIndex(array $symbols): void
    {
        $storageDir = $this->modulePaths['brain'] ?? '';
        $indexPath = $storageDir . '/learning/trailing/index.json';
        
        // Load existing index
        $existingSymbols = [];
        if (is_file($indexPath)) {
            $data = @json_decode((string)file_get_contents($indexPath), true);
            $existingSymbols = $data['symbols'] ?? [];
        }
        
        // Merge symbols
        $allSymbols = array_unique(array_merge($existingSymbols, $symbols));
        sort($allSymbols);
        
        $index = [
            'schema_version' => '1.0',
            'updated_ts_unix' => time(),
            'updated_at' => date('c'),
            'symbols_count' => count($allSymbols),
            'symbols' => $allSymbols,
        ];
        
        // Ensure directory
        $dir = dirname($indexPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        @file_put_contents($indexPath, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * STEP 4: Apply learning recommendation to trailing mode selection
     * Called from processSignalGateway after passport-based selection
     * 
     * @param string $symbol Trading symbol
     * @param array $passportResult Result from chooseTrailingModeFromPassport
     * @return array Updated result with learning override if applicable
     */
    public function applyLearningToTrailingMode(string $symbol, array $passportResult): array
    {
        $learningCfg = $this->config['learning']['trailing'] ?? [];
        
        // Check if learning is enabled
        if (!($learningCfg['enabled'] ?? true)) {
            $passportResult['trailing_mode_source'] = 'passport';
            return $passportResult;
        }
        
        // Load recommendation for symbol
        $recommendation = $this->loadLearningRecommendation($symbol);
        
        if ($recommendation === null) {
            // No recommendation, keep passport result
            $passportResult['trailing_mode_source'] = 'passport';
            return $passportResult;
        }
        
        // Validate recommendation
        $selectedMode = $recommendation['selected_mode'] ?? null;
        $selectedFactor = $recommendation['selected_drawdown_factor'] ?? null;
        $reason = $recommendation['reason'] ?? 'unknown';
        
        if (!in_array($selectedMode, ['tight', 'normal', 'loose'], true) || $selectedFactor === null) {
            // Invalid recommendation
            $passportResult['trailing_mode_source'] = 'passport';
            return $passportResult;
        }
        
        // Build reason with learning_ prefix (avoid double-prefixing)
        $fullReason = (strpos($reason, 'learning_') === 0) ? $reason : 'learning_' . $reason;
        
        // Apply learning recommendation (override passport)
        return [
            'mode' => $selectedMode,
            'drawdown_factor' => (float)$selectedFactor,
            'reason' => $fullReason,
            'is_mature' => $passportResult['is_mature'] ?? false,
            'trailing_mode_source' => 'learning',
            'learning_reason' => $reason,
            'learning_delta_roi' => $recommendation['delta_vs_normal_roi_pct'] ?? 0,
        ];
    }
}

/* RULES
 * NO HARDCODE. CONFIG FIRST. SystemPaths ONLY.
 * This file is part of Brain refactor into traits.
 * No behavior changes allowed (refactor-only).
 */
