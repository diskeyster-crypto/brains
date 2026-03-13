<?php

declare(strict_types=1);

/**
 * Parser6DatasetTrait - Dataset writing and training data generation
 * 
 * Methods for writing dataset rows and generating training data.
 */
trait Parser6DatasetTrait
{
    /**
     * Write dataset row for active trade
     */
    private function writeDatasetRow(array $trade, int $ts, float $price): void
    {
        $datasetConfig = $this->config['dataset'] ?? [];
        if (!($datasetConfig['enabled'] ?? true)) {
            return;
        }

        $tradeId = $trade['trade_id'] ?? '';
        $datasetDir = $this->storageDir . '/dataset';
        $this->ensureDir($datasetDir);

        $row = [
            'trade_id' => $tradeId,
            'symbol' => $trade['symbol'] ?? '',
            'side' => $trade['side'] ?? 'long',
            'ts' => $ts,
            'iso' => date('c', $ts),
            'price' => $price,

            'entry_price' => $trade['entry']['opened_price'] ?? 0,
            'tp' => $trade['targets']['take_profit'] ?? null,
            'sl' => $trade['targets']['stop_loss_initial'] ?? null,
            'sl_current' => $trade['targets']['stop_loss_current'] ?? null,

            'trailing_active' => $trade['trailing']['active'] ?? false,
            'trailing_stop' => $trade['trailing']['stop_price'] ?? null,

            'pnl_unrealized_usdt' => $trade['pnl']['pnl_unrealized_usdt'] ?? 0,
            'roi_unrealized' => $trade['pnl']['roi_unrealized'] ?? 0,

            'features' => $this->calculateFeatures($trade, $price),

            'label' => null,
        ];

        $path = $datasetDir . '/' . $tradeId . '.jsonl';
        file_put_contents($path, json_encode($row) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Write final dataset row for closed trade
     */
    private function writeDatasetFinalRow(array $trade, array $closeResult): void
    {
        $datasetConfig = $this->config['dataset'] ?? [];
        if (!($datasetConfig['enabled'] ?? true)) {
            return;
        }

        $tradeId = $trade['trade_id'] ?? '';
        $datasetDir = $this->storageDir . '/dataset';
        $path = $datasetDir . '/' . $tradeId . '.jsonl';

        $finalRow = [
            'trade_id' => $tradeId,
            'symbol' => $trade['symbol'] ?? '',
            'side' => $trade['side'] ?? 'long',
            'ts' => $closeResult['close_ts'] ?? time(),
            'iso' => $closeResult['closed_at'] ?? date('c'),
            'price' => $closeResult['close_price'] ?? 0,

            'entry_price' => $trade['entry']['opened_price'] ?? 0,
            'tp' => $trade['targets']['take_profit'] ?? null,
            'sl' => $trade['targets']['stop_loss_initial'] ?? null,
            'sl_current' => $trade['targets']['stop_loss_current'] ?? null,

            'trailing_active' => $trade['trailing']['active'] ?? false,
            'trailing_stop' => $trade['trailing']['stop_price'] ?? null,

            'pnl_unrealized_usdt' => 0,
            'roi_unrealized' => 0,

            'features' => [],

            'label' => [
                'closed' => true,
                'close_reason' => $closeResult['close_reason'] ?? 'unknown',
                'roi_realized' => $closeResult['roi'] ?? 0,
                'duration_minutes' => 0,
                'win' => $closeResult['win'] ?? false,
            ],
        ];

        file_put_contents($path, json_encode($finalRow) . "\n", FILE_APPEND | LOCK_EX);
        
        // Also write to centralized training_dataset.ndjson per TZ spec
        $this->appendTrainingDataset($trade, $closeResult);
    }
    
    /**
     * Append to centralized training_dataset.ndjson (per TZ spec section 11)
     * This is the main training dataset for ML models.
     */
    private function appendTrainingDataset(array $trade, array $closeResult): void
    {
        $datasetConfig = $this->config['dataset'] ?? [];
        if (!($datasetConfig['enabled'] ?? true)) {
            return;
        }
        
        // Timing fields for self-sufficient dataset
        $tradeId = $trade['trade_id'] ?? '';
        $createdTs = (int)($trade['created_ts'] ?? 0);
        $openedTs = (int)($trade['entry']['opened_ts'] ?? 0);
        $closeTs = (int)($closeResult['close_ts'] ?? time());
        $durationSec = $openedTs > 0 ? ($closeTs - $openedTs) : 0;
        $durationMin = round($durationSec / 60, 2);
        
        // ISO timestamps
        $createdAt = $createdTs > 0 ? date('c', $createdTs) : ($trade['created_at'] ?? '');
        $openedAt = $openedTs > 0 ? date('c', $openedTs) : ($trade['entry']['opened_at'] ?? '');
        $closedAt = $closeTs > 0 ? date('c', $closeTs) : ($closeResult['closed_at'] ?? '');
        
        // Extended fields (Part 3 of TZ)
        $budget = (float)($trade['entry']['budget_usdt'] ?? 0);
        $leverage = (int)($trade['entry']['leverage'] ?? 1);
        $qty = (float)($trade['entry']['qty'] ?? 0);
        $entryPrice = (float)($trade['entry']['opened_price'] ?? 0);
        $notional = $entryPrice > 0 ? $qty * $entryPrice : 0;
        
        // MAE/MFE (Maximum Adverse/Favorable Excursion) from trade PnL tracking
        $mae = (float)($trade['pnl']['max_drawdown_roi'] ?? 0);
        $mfe = (float)($trade['pnl']['max_favorable_roi'] ?? 0);
        
        // ROI calculations
        $roi = (float)($closeResult['roi'] ?? 0);
        $roiMargin = $budget > 0 ? ($closeResult['pnl_realized_usdt'] ?? 0) / $budget : 0;
        $roiNotional = $notional > 0 ? ($closeResult['pnl_realized_usdt'] ?? 0) / $notional : 0;
        
        // Training dataset row format per TZ specification (extended + self-sufficient)
        $trainingRow = [
            // Self-sufficient fields (new TZ spec)
            'trade_id' => $tradeId,
            'created_ts' => $createdTs,
            'opened_ts' => $openedTs,
            'closed_ts' => $closeTs,
            'created_at' => $createdAt,
            'opened_at' => $openedAt,
            'closed_at' => $closedAt,
            // Core fields
            'symbol' => $trade['symbol'] ?? '',
            'side' => $trade['side'] ?? 'long',
            'entry_price' => $entryPrice,
            'exit_price' => (float)($closeResult['close_price'] ?? 0),
            'roi' => round($roi * 100, 4), // Convert to percentage
            'pnl' => round($closeResult['pnl_realized_usdt'] ?? 0, 4),
            'duration_sec' => $durationSec,
            'duration_min' => $durationMin,
            'score' => (float)($trade['signal_score'] ?? 0),
            'confirmations' => (int)($trade['signal_confirmations'] ?? 0),
            'close_reason' => $closeResult['close_reason'] ?? 'unknown',
            // Extended fields (Part 3 of TZ)
            'budget' => round($budget, 2),
            'leverage' => $leverage,
            'qty' => round($qty, 8),
            'notional' => round($notional, 2),
            'mae' => round($mae * 100, 4),       // Maximum Adverse Excursion %
            'mfe' => round($mfe * 100, 4),       // Maximum Favorable Excursion %
            'roi_margin' => round($roiMargin * 100, 4),     // ROI on margin %
            'roi_notional' => round($roiNotional * 100, 6), // ROI on notional %
        ];
        
        $path = $this->storageDir . '/training_dataset.ndjson';
        file_put_contents($path, json_encode($trainingRow) . "\n", FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Append to mode-specific training_dataset.ndjson
     * Per TZ: Write dataset to mode folders with "mode" field
     */
    private function appendTrainingDatasetForMode(
        array $trade, 
        array $closeResult, 
        string $modeStorageBase,
        string $mode
    ): void {
        $datasetConfig = $this->config['dataset'] ?? [];
        if (!($datasetConfig['enabled'] ?? true)) {
            return;
        }
        
        // Timing fields
        $tradeId = $trade['trade_id'] ?? '';
        $createdTs = (int)($trade['created_ts'] ?? 0);
        $openedTs = (int)($trade['entry']['opened_ts'] ?? 0);
        $closeTs = (int)($closeResult['close_ts'] ?? time());
        $durationSec = $openedTs > 0 ? ($closeTs - $openedTs) : 0;
        $durationMin = round($durationSec / 60, 2);
        
        // ISO timestamps
        $createdAt = $createdTs > 0 ? date('c', $createdTs) : ($trade['created_at'] ?? '');
        $openedAt = $openedTs > 0 ? date('c', $openedTs) : ($trade['entry']['opened_at'] ?? '');
        $closedAt = $closeTs > 0 ? date('c', $closeTs) : ($closeResult['closed_at'] ?? '');
        
        // Extended fields
        $budget = (float)($trade['entry']['budget_usdt'] ?? $trade['entry']['margin_usdt'] ?? 0);
        $leverage = (int)($trade['entry']['leverage'] ?? $trade['risk']['leverage'] ?? 1);
        $qty = (float)($trade['entry']['qty'] ?? 0);
        $entryPrice = (float)($trade['entry']['opened_price'] ?? 0);
        $notional = $entryPrice > 0 ? $qty * $entryPrice : 0;
        
        // MAE/MFE
        $mae = (float)($trade['pnl']['max_drawdown_roi'] ?? 0);
        $mfe = (float)($trade['pnl']['max_favorable_roi'] ?? 0);
        
        // ROI calculations
        $roi = (float)($closeResult['roi'] ?? 0);
        $pnlUsdt = (float)($closeResult['pnl_realized_usdt'] ?? 0);
        $roiMargin = $budget > 0 ? $pnlUsdt / $budget : 0;
        $roiNotional = $notional > 0 ? $pnlUsdt / $notional : 0;
        
        // Fee fields - use centralized helper
        $feesBps = $this->getEffectiveFeesBps($closeResult, $trade);
        $feesTotalUsdt = (float)($closeResult['fees_total_usdt'] ?? 0);
        $pnlGrossUsdt = (float)($closeResult['pnl_gross_usdt'] ?? $pnlUsdt);
        $pnlNetUsdt = (float)($closeResult['pnl_net_usdt'] ?? $pnlUsdt);
        $roiGrossPct = (float)($closeResult['roi_gross_pct'] ?? ($roi * 100));
        $roiNetPct = (float)($closeResult['roi_net_pct'] ?? ($roi * 100));
        
        // Training dataset row with mode field
        $trainingRow = [
            'schema_version' => 'training_dataset_v1',
            'mode' => $mode,
            // Self-sufficient fields
            'trade_id' => $tradeId,
            'created_ts' => $createdTs,
            'opened_ts' => $openedTs,
            'closed_ts' => $closeTs,
            'created_at' => $createdAt,
            'opened_at' => $openedAt,
            'closed_at' => $closedAt,
            // Core fields
            'symbol' => $trade['symbol'] ?? '',
            'side' => $trade['side'] ?? 'long',
            'entry_price' => $entryPrice,
            'exit_price' => (float)($closeResult['close_price'] ?? 0),
            'roi' => round($roi * 100, 4),
            'pnl' => round($pnlUsdt, 4),
            'duration_sec' => $durationSec,
            'duration_min' => $durationMin,
            'score' => (float)($trade['signal_score'] ?? 0),
            'confirmations' => (int)($trade['signal_confirmations'] ?? 0),
            'close_reason' => $closeResult['close_reason'] ?? 'unknown',
            // Extended fields
            'budget' => round($budget, 2),
            'leverage' => $leverage,
            'qty' => round($qty, 8),
            'notional' => round($notional, 2),
            'mae' => round($mae * 100, 4),
            'mfe' => round($mfe * 100, 4),
            'roi_margin' => round($roiMargin * 100, 4),
            'roi_notional' => round($roiNotional * 100, 6),
            // Fee fields
            'fees_bps' => $feesBps,
            'fees_total_usdt' => round($feesTotalUsdt, 4),
            'pnl_gross_usdt' => round($pnlGrossUsdt, 4),
            'pnl_net_usdt' => round($pnlNetUsdt, 4),
            'roi_gross_pct' => round($roiGrossPct, 4),
            'roi_net_pct' => round($roiNetPct, 4),
            // Profile fields
            'profile_id' => $trade['profile_id'] ?? 'default',
        ];
        
        // Write to mode-specific path
        $modePath = $modeStorageBase . '/training_dataset.ndjson';
        $this->ensureDir(dirname($modePath));
        file_put_contents($modePath, json_encode($trainingRow) . "\n", FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Append trailing episode to training_trailing_sim.ndjson
     * Writes one NDJSON line per closed trade for trailing analysis
     */
    private function appendTrailingEpisodeForMode(
        array $trade,
        array $closeResult,
        string $modeStorageBase,
        string $mode
    ): void {
        // Timestamps
        $closedTs = (int)($closeResult['close_ts'] ?? time());
        $closedAt = date('c', $closedTs);
        $openedTs = (int)($trade['entry']['opened_ts'] ?? 0);
        
        // Get trailing config
        $trailing = $trade['trailing'] ?? [];
        $trailingEnabled = (bool)($trailing['enabled'] ?? false);
        $trailingActivated = (bool)($trailing['active'] ?? false);
        $activatedTs = (int)($trailing['activated_ts'] ?? 0);
        
        // Risk block
        $risk = $trade['risk'] ?? [];
        $budget = (float)($trade['entry']['budget_usdt'] ?? $trade['entry']['margin_usdt'] ?? $risk['budget_usdt_per_trade'] ?? 50);
        $leverage = (int)($trade['entry']['leverage'] ?? $risk['leverage'] ?? 10);
        $slippageBps = (int)($risk['slippage_bps'] ?? 20);
        $feesBps = (int)($risk['fees_bps'] ?? 6);
        
        // Entry data
        $entryPrice = (float)($trade['entry']['opened_price'] ?? 0);
        
        // Close data
        $closePrice = (float)($closeResult['close_price'] ?? 0);
        $closeReason = $closeResult['close_reason'] ?? 'unknown';
        $pnlNetUsdt = (float)($closeResult['pnl_net_usdt'] ?? 0);
        $roiNetPct = (float)($closeResult['roi_net_pct'] ?? 0);
        $feesTotalUsdt = (float)($closeResult['fees_total_usdt'] ?? 0);
        
        // Trailing-specific metrics
        $activationRoiPct = (float)($trailing['activation_roi_pct'] ?? 6);
        $trailingMode = (string)($trailing['mode'] ?? 'normal');
        $drawdownFactor = (float)($trailing['drawdown_factor'] ?? 0.5);
        $roiAtActivation = (float)($trailing['roi_at_activation'] ?? 0);
        $peakRoiPct = (float)($trailing['peak_roi_pct'] ?? 0);
        $minRoiAfterActivation = $trailing['min_roi_after_activation_pct'] ?? null;
        $slUpdatesCount = (int)($trailing['sl_updates_count'] ?? 0);
        
        // Calculate derived fields
        $roiAtExit = $roiNetPct; // net ROI at exit
        
        // giveback_from_peak_pct = peak_roi - roi_at_exit (only if trailing was activated and peak exists)
        $givebackFromPeakPct = 0;
        if ($trailingActivated && $peakRoiPct > 0) {
            $givebackFromPeakPct = $peakRoiPct - $roiAtExit;
        }
        
        // worst_drawdown_from_peak_pct = peak_roi - min_roi_after_activation (if activation was; else 0)
        $worstDrawdownFromPeakPct = 0;
        if ($trailingActivated && $minRoiAfterActivation !== null) {
            $worstDrawdownFromPeakPct = $peakRoiPct - $minRoiAfterActivation;
        }
        
        // duration_after_activation_min
        $durationAfterActivationMin = 0;
        if ($trailingActivated && $activatedTs > 0) {
            $durationAfterActivationMin = round(($closedTs - $activatedTs) / 60, 2);
        }
        
        // Build the episode record per schema v1.0
        $episode = [
            'schema_version' => '1.0',
            'source' => 'sim',
            'mode' => $mode,
            
            'ts_closed_iso' => $closedAt,
            'ts_closed_unix' => $closedTs,
            
            'trade_id' => $trade['trade_id'] ?? '',
            'symbol' => $trade['symbol'] ?? '',
            'side' => strtolower($trade['side'] ?? 'long'),
            
            'profile_id' => $trade['profile_id'] ?? 'default',
            
            'risk' => [
                'budget_usdt_per_trade' => $budget,
                'leverage' => $leverage,
                'slippage_bps' => $slippageBps,
                'fees_bps' => $feesBps,
            ],
            
            'entry' => [
                'price_fill' => $entryPrice,
                'ts_open_unix' => $openedTs,
            ],
            
            'close' => [
                'price_fill' => $closePrice,
                'reason' => $closeReason,
                'pnl_net_usdt' => round($pnlNetUsdt, 4),
                'roi_net_pct' => round($roiNetPct, 4),
                'fees_total_usdt' => round($feesTotalUsdt, 4),
            ],
            
            'trailing' => [
                'enabled' => $trailingEnabled,
                'activated' => $trailingActivated,
                'activation_roi_pct' => $activationRoiPct,
                'mode' => $trailingMode,
                'drawdown_factor' => $drawdownFactor,
                
                'roi_at_activation' => $trailingActivated ? round($roiAtActivation, 4) : null,
                'peak_roi_pct' => round($peakRoiPct, 4),
                
                'roi_at_exit' => round($roiAtExit, 4),
                'giveback_from_peak_pct' => round($givebackFromPeakPct, 4),
                
                'min_roi_after_activation_pct' => $minRoiAfterActivation !== null ? round($minRoiAfterActivation, 4) : null,
                'worst_drawdown_from_peak_pct' => round($worstDrawdownFromPeakPct, 4),
                
                'duration_after_activation_min' => $durationAfterActivationMin,
                'sl_updates_count' => $slUpdatesCount,
            ],
        ];
        
        // Write to mode-specific path
        $modePath = $modeStorageBase . '/training_trailing_sim.ndjson';
        $this->ensureDir(dirname($modePath));
        file_put_contents($modePath, json_encode($episode) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Calculate features for dataset row
     */
    private function calculateFeatures(array $trade, float $currentPrice): array
    {
        // Simplified feature calculation
        $entryPrice = (float)($trade['entry']['opened_price'] ?? $currentPrice);
        $tp = $trade['targets']['take_profit'] ?? null;
        $sl = $trade['targets']['stop_loss_current'] ?? null;

        return [
            'dist_to_tp' => $tp !== null && $entryPrice > 0 ? round(($tp - $currentPrice) / $entryPrice, 6) : null,
            'dist_to_sl' => $sl !== null && $entryPrice > 0 ? round(($currentPrice - $sl) / $entryPrice, 6) : null,
        ];
    }
}

/* RULES
 * NO HARDCODE. CONFIG FIRST. SystemPaths ONLY.
 * Dataset methods handle training data generation for ML models.
 * All dataset writing respects the config['dataset']['enabled'] flag.
 * Methods must be self-contained and not depend on external state except $this->config.
 */
