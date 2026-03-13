<?php

declare(strict_types=1);

namespace Modules\System\Brain\Lib;

/**
 * BrainPassportsTrait - Passports builder methods
 * 
 * This trait is part of the BrainService refactor into traits.
 * No behavior changes are allowed (refactor-only).
 */
trait BrainPassportsTrait
{
    /**
     * Recalculate all passports from strategies
     */
    public function recalculatePassports(): array
    {
        $result = [
            'success' => true,
            'passports_updated' => 0,
            'errors' => [],
        ];
        
        $passportsDir = $this->storageDir . '/passports';
        if (!is_dir($passportsDir)) {
            mkdir($passportsDir, 0755, true);
        }
        
        $strategies = $this->getStrategies();
        
        foreach ($strategies as $id => $strategy) {
            try {
                // Calculate fitness score for strategy
                $fitness = $this->calculateFitnessScore($id);
                
                // Build passport
                $passport = [
                    'strategy_id' => $id,
                    'strategy_name' => $strategy['name'] ?? $id,
                    'updated_at' => date('Y-m-d H:i:s'),
                    'fitness_score' => $fitness['score'] ?? 0,
                    'metrics' => $fitness['metrics'] ?? [],
                    'enabled' => $strategy['enabled'] ?? false,
                    'simulator_enabled' => $strategy['simulator_enabled'] ?? false,
                    'executor_enabled' => $strategy['executor_enabled'] ?? false,
                ];
                
                // Save passport
                $passportFile = $passportsDir . '/' . $id . '.json';
                file_put_contents(
                    $passportFile,
                    json_encode($passport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
                
                $result['passports_updated']++;
                
            } catch (\Throwable $e) {
                $result['errors'][] = "Strategy {$id}: " . $e->getMessage();
            }
        }
        
        if (!empty($result['errors'])) {
            $result['success'] = count($result['errors']) < count($strategies);
        }
        
        return $result;
    }
    
    /**
     * Build passports from simulator training dataset (memory-safe streaming)
     */
    public function buildPassportsFromSimulator(): array
    {
        $result = [
            'success' => false,
            'symbols_processed' => 0,
            'passports_created' => 0,
            'total_trades' => 0,
            'errors' => [],
        ];
        
        // Get simulator storage path
        $simulatorPath = $this->modulePaths['simulator'] ?? null;
        if (!$simulatorPath) {
            $result['errors'][] = 'Simulator path not configured';
            return $result;
        }
        
        $datasetFile = $simulatorPath . '/training_dataset.ndjson';
        if (!is_file($datasetFile)) {
            // Not an error - just no data yet
            return $result;
        }
        
        // Stream and aggregate NDJSON file by symbol (memory-safe)
        $handle = fopen($datasetFile, 'r');
        if (!$handle) {
            $result['errors'][] = 'Cannot open training_dataset.ndjson';
            return $result;
        }
        
        // Aggregation structures per symbol (memory-limited)
        $symbolAggregates = [];
        $maxSamples = self::PASSPORT_MAX_SAMPLES_PER_SYMBOL;
        
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            $trade = json_decode($line, true);
            if (!is_array($trade) || empty($trade['symbol'])) {
                continue;
            }
            
            $symbol = $trade['symbol'];
            $result['total_trades']++;
            
            // Initialize symbol aggregate if not exists
            if (!isset($symbolAggregates[$symbol])) {
                $symbolAggregates[$symbol] = [
                    'trades_total' => 0,
                    'wins' => 0,
                    'roi_sum' => 0.0,
                    'rois' => [],      // limited to maxSamples
                    'maes' => [],      // limited to maxSamples
                    'mfes' => [],      // limited to maxSamples
                    'durations' => [], // limited to maxSamples
                ];
            }
            
            $agg = &$symbolAggregates[$symbol];
            $agg['trades_total']++;
            
            // Extract values
            $roi = (float)($trade['roi_margin'] ?? $trade['roi'] ?? 0);
            $mae = (float)($trade['mae'] ?? 0);
            $mfe = (float)($trade['mfe'] ?? 0);
            $duration = (float)($trade['duration_min'] ?? 0);
            
            // Count wins and accumulate ROI sum (no memory limit needed)
            if ($roi > 0) {
                $agg['wins']++;
            }
            $agg['roi_sum'] += $roi;
            
            // Store samples for medians (with memory limit using reservoir sampling)
            $this->addSampleWithLimit($agg['rois'], $roi, $maxSamples, $agg['trades_total']);
            $this->addSampleWithLimit($agg['maes'], $mae, $maxSamples, $agg['trades_total']);
            $this->addSampleWithLimit($agg['mfes'], $mfe, $maxSamples, $agg['trades_total']);
            $this->addSampleWithLimit($agg['durations'], $duration, $maxSamples, $agg['trades_total']);
        }
        fclose($handle);
        
        if (empty($symbolAggregates)) {
            // Not an error - just no valid trades
            return $result;
        }
        
        $result['symbols_processed'] = count($symbolAggregates);
        
        // Ensure passports directory exists
        if (!is_dir($this->passportsDir)) {
            mkdir($this->passportsDir, 0755, true);
        }
        
        // Build and save passport for each symbol
        foreach ($symbolAggregates as $symbol => $agg) {
            $passport = $this->buildPassportFromAggregate($symbol, $agg);
            
            // Atomic write: tmp file → rename
            $passportFile = $this->passportsDir . '/' . $symbol . '.json';
            $tmpFile = $this->passportsDir . '/.' . $symbol . '.tmp';
            
            $jsonData = json_encode($passport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $written = file_put_contents($tmpFile, $jsonData, LOCK_EX);
            
            if ($written !== false && rename($tmpFile, $passportFile)) {
                $result['passports_created']++;
            } else {
                // Clean up tmp file on failure
                if (is_file($tmpFile) && !unlink($tmpFile)) {
                    $this->log("Failed to clean up temp file: {$tmpFile}", 'warning');
                }
                $result['errors'][] = "Failed to write passport for {$symbol}";
            }
        }
        
        $result['success'] = ($result['passports_created'] > 0);
        $this->log("Passports built: {$result['passports_created']} from {$result['total_trades']} trades");
        
        return $result;
    }
    
    /**
     * Add sample to array with reservoir sampling for memory limit
     * 
     * Uses Algorithm R (reservoir sampling) to maintain a representative sample
     * when the number of items exceeds the limit. Each item has probability
     * maxSamples/totalCount of being in the final sample.
     * 
     * @param array $samples Reference to samples array
     * @param float $value Value to add
     * @param int $maxSamples Maximum samples to keep
     * @param int $totalCount Total items seen so far (for reservoir sampling)
     */
    private function addSampleWithLimit(array &$samples, float $value, int $maxSamples, int $totalCount): void
    {
        if (count($samples) < $maxSamples) {
            // Under limit: just add
            $samples[] = $value;
        } else {
            // Algorithm R reservoir sampling:
            // Generate random index in [0, totalCount-1]
            // If index < maxSamples, replace that element
            // This gives each item probability maxSamples/totalCount of being selected
            $j = random_int(0, $totalCount - 1);
            if ($j < $maxSamples) {
                $samples[$j] = $value;
            }
        }
    }
    
    /**
     * Build passport data from aggregated symbol data
     * 
     * @param string $symbol Symbol name
     * @param array $agg Aggregated data for this symbol
     * @return array Passport data
     */
    private function buildPassportFromAggregate(string $symbol, array $agg): array
    {
        $tradesTotal = $agg['trades_total'];
        $wins = $agg['wins'];
        
        $winRate = $tradesTotal > 0 ? round(($wins / $tradesTotal) * 100, 2) : 0;
        $avgRoiMargin = $tradesTotal > 0 ? round($agg['roi_sum'] / $tradesTotal, 4) : 0;
        
        return [
            'symbol' => $symbol,
            'version' => 'v1',
            'updated_at' => date('c'),
            'updated_ts' => time(),
            'trades_total' => $tradesTotal,
            'win_rate' => $winRate,
            'avg_roi_margin' => $avgRoiMargin,
            'median_mae' => $this->calculateMedian($agg['maes']),
            'median_mfe' => $this->calculateMedian($agg['mfes']),
            'median_duration_min' => $this->calculateMedian($agg['durations']),
            // Additional stats for analysis
            'stats' => [
                'wins' => $wins,
                'losses' => $tradesTotal - $wins,
                'avg_mae' => count($agg['maes']) > 0 ? round(array_sum($agg['maes']) / count($agg['maes']), 4) : 0,
                'avg_mfe' => count($agg['mfes']) > 0 ? round(array_sum($agg['mfes']) / count($agg['mfes']), 4) : 0,
                'avg_duration_min' => count($agg['durations']) > 0 ? round(array_sum($agg['durations']) / count($agg['durations']), 2) : 0,
                'min_roi' => count($agg['rois']) > 0 ? round(min($agg['rois']), 4) : 0,
                'max_roi' => count($agg['rois']) > 0 ? round(max($agg['rois']), 4) : 0,
                'samples_used' => count($agg['rois']),
            ],
        ];
    }
    
    /**
     * Build passport data for a single symbol (legacy method for direct trade arrays)
     * 
     * @param string $symbol Symbol name
     * @param array $trades Array of trade records for this symbol
     * @return array Passport data
     * @deprecated Use buildPassportsFromSimulator() streaming instead
     */
    private function buildSymbolPassport(string $symbol, array $trades): array
    {
        $tradesTotal = count($trades);
        
        // Calculate win rate (trades with positive ROI)
        $wins = 0;
        $rois = [];
        $maes = [];
        $mfes = [];
        $durations = [];
        
        foreach ($trades as $trade) {
            // ROI can be in different formats
            $roi = (float)($trade['roi_margin'] ?? $trade['roi'] ?? 0);
            $rois[] = $roi;
            
            if ($roi > 0) {
                $wins++;
            }
            
            // MAE/MFE
            $mae = (float)($trade['mae'] ?? 0);
            $mfe = (float)($trade['mfe'] ?? 0);
            $maes[] = $mae;
            $mfes[] = $mfe;
            
            // Duration
            $duration = (float)($trade['duration_min'] ?? 0);
            $durations[] = $duration;
        }
        
        $winRate = $tradesTotal > 0 ? round(($wins / $tradesTotal) * 100, 2) : 0;
        $avgRoiMargin = count($rois) > 0 ? round(array_sum($rois) / count($rois), 4) : 0;
        
        return [
            'symbol' => $symbol,
            'version' => 'v1',
            'updated_at' => date('c'),
            'updated_ts' => time(),
            'trades_total' => $tradesTotal,
            'win_rate' => $winRate,
            'avg_roi_margin' => $avgRoiMargin,
            'median_mae' => $this->calculateMedian($maes),
            'median_mfe' => $this->calculateMedian($mfes),
            'median_duration_min' => $this->calculateMedian($durations),
            // Additional stats for analysis
            'stats' => [
                'wins' => $wins,
                'losses' => $tradesTotal - $wins,
                'avg_mae' => count($maes) > 0 ? round(array_sum($maes) / count($maes), 4) : 0,
                'avg_mfe' => count($mfes) > 0 ? round(array_sum($mfes) / count($mfes), 4) : 0,
                'avg_duration_min' => count($durations) > 0 ? round(array_sum($durations) / count($durations), 2) : 0,
                'min_roi' => count($rois) > 0 ? round(min($rois), 4) : 0,
                'max_roi' => count($rois) > 0 ? round(max($rois), 4) : 0,
            ],
        ];
    }
    
    /**
     * Get all passports
     * 
     * @return array Array of passport data keyed by symbol
     */
    public function getPassports(): array
    {
        $passports = [];
        $files = glob($this->passportsDir . '/*.json') ?: [];
        
        foreach ($files as $file) {
            $symbol = basename($file, '.json');
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $data = json_decode($content, true);
            if (is_array($data)) {
                $passports[$symbol] = $data;
            }
        }
        
        return $passports;
    }
    
    /**
     * Get passport for specific symbol
     * 
     * @param string $symbol Symbol name
     * @return array|null Passport data or null if not found
     */
    public function getPassport(string $symbol): ?array
    {
        $file = $this->passportsDir . '/' . $symbol . '.json';
        if (!is_file($file)) {
            return null;
        }
        
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }
}

/* RULES
 * NO HARDCODE. CONFIG FIRST. SystemPaths ONLY.
 * This file is part of Brain refactor into traits.
 * No behavior changes allowed (refactor-only).
 */
