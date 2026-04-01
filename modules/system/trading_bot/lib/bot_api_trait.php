<?php
declare(strict_types=1);

namespace Modules\System\TradingBot\Lib;

/**
 * Bot API Trait
 * 
 * API endpoint methods for Trading Bot.
 * Used by controller for API responses.
 */
trait BotApiTrait
{
    /**
     * Get intents list for API
     */
    public function getIntentsList(int $limit = 50): array
    {
        return [
            'ok' => true,
            'intents' => $this->store->loadRejectedIntents($limit),
            'count' => count($this->store->loadRejectedIntents($limit)),
        ];
    }
    
    /**
     * Get trades list for API
     */
    public function getTradesList(string $status = 'active', int $limit = 50): array
    {
        if ($status === 'active') {
            $trades = $this->store->loadActiveTrades();
        } else {
            $trades = $this->store->loadClosedTrades($limit);
        }
        
        return [
            'ok' => true,
            'trades' => array_values($trades),
            'count' => count($trades),
            'status' => $status,
        ];
    }
    
    /**
     * Get orders list for API
     */
    public function getOrdersList(string $status = 'active', int $limit = 50): array
    {
        if ($status === 'active') {
            $orders = $this->store->loadActiveOrders();
        } else {
            $orders = $this->store->loadClosedOrders($limit);
        }
        
        return [
            'ok' => true,
            'orders' => array_values($orders),
            'count' => count($orders),
            'status' => $status,
        ];
    }
    
    /**
     * Get stats for API
     */
    public function getStats(): array
    {
        // P9: Dashboard stats must reflect exchange truth.
        // Use Bybit /v5/position/closed-pnl (lookback window), and only fallback
        // to local storage for dry/test modes.

        $lookbackHours = (int)($this->config['ui']['stats_lookback_hours'] ?? 48);
        $lookbackHours = max(1, min(168, $lookbackHours));
        $closedPnlLimit = (int)($this->config['ui']['closed_pnl_limit'] ?? 200);
        $closedPnlLimit = max(1, min(200, $closedPnlLimit));

        // Open positions: prefer exchange positions count
        $openPositionsCount = 0;
        if ($this->gateway !== null) {
            $positions = $this->gateway->getPositions();
            if (is_array($positions)) {
                foreach ($positions as $p) {
                    $size = (float)($p['size'] ?? 0);
                    if ($size > 0) {
                        $openPositionsCount++;
                    }
                }
            }
        } else {
            $activeTrades = $this->store->loadActiveTrades();
            $openPositionsCount = count($activeTrades);
        }

        $statsSource = 'local_storage';
        $closedTradesCount = 0;
        $totalPnL = 0.0;
        $wins = 0;
        $losses = 0;
        $closedPnlError = null;

        if ($this->gateway !== null && $this->isRealExchangeMode()) {
            $endMs = (int)round(microtime(true) * 1000);
            $startMs = $endMs - ($lookbackHours * 3600 * 1000);

            $closed = $this->gateway->getClosedPnl($startMs, $endMs, $closedPnlLimit);
            if (($closed['ok'] ?? false) === true) {
                $statsSource = 'bybit_closed_pnl';
                $items = $closed['items'] ?? [];
                if (is_array($items)) {
                    foreach ($items as $row) {
                        if (!is_array($row)) {
                            continue;
                        }

                        $pnl = $row['closedPnl'] ?? $row['closed_pnl'] ?? null;
                        if ($pnl === null || $pnl === '') {
                            $pnl = 0;
                        }

                        $pnlF = (float)$pnl;
                        $totalPnL += $pnlF;

                        if ($pnlF > 0) {
                            $wins++;
                        } elseif ($pnlF < 0) {
                            $losses++;
                        }

                        $closedTradesCount++;
                    }
                }
            } else {
                $closedPnlError = $closed['error'] ?? 'closed_pnl_failed';
            }
        }

        // Fallback in dry/test or when closed-pnl fails
        if ($statsSource === 'local_storage') {
            $closedTrades = $this->store->loadClosedTrades(100);
            foreach ($closedTrades as $trade) {
                $pnl = (float)($trade['pnl'] ?? 0);
                $totalPnL += $pnl;

                if ($pnl > 0) {
                    $wins++;
                } elseif ($pnl < 0) {
                    $losses++;
                }
            }

            $closedTradesCount = count($closedTrades);
        }

        $totalClosed = $wins + $losses;
        $winRate = $totalClosed > 0 ? ($wins / $totalClosed) * 100 : 0;

        return [
            'ok' => true,
            'stats' => [
                'open_positions' => $openPositionsCount,
                'closed_trades' => $closedTradesCount,
                'total_pnl' => round($totalPnL, 4),
                'wins' => $wins,
                'losses' => $losses,
                'win_rate' => round($winRate, 2),
                'lookback_hours' => $lookbackHours,
                'source' => $statsSource,
                'closed_pnl_error' => $closedPnlError,
            ],
        ];
    }
    
    /**
     * Force reconcile with exchange
     */
    public function forceReconcile(): array
    {
        if ($this->configError !== null) {
            return [
                'ok' => false,
                'error' => $this->configError,
            ];
        }
        
        return $this->reconcileWithExchange();
    }
    
    /**
     * Update settings
     */
    public function updateSettings(array $settings): array
    {
        $allowed = ['enabled', 'mode', 'account_id', 'max_positions', 'safety_stop_errors'];
        $filtered = [];
        
        foreach ($allowed as $key) {
            if (isset($settings[$key])) {
                $filtered[$key] = $settings[$key];
            }
        }
        
        if (empty($filtered)) {
            return [
                'ok' => false,
                'error' => 'No valid settings provided',
            ];
        }
        
        // P0.5.3: Validate account_id against KeyCenter
        if (isset($filtered['account_id'])) {
            $accountId = trim((string)$filtered['account_id']);
            
            if ($accountId === '') {
                return [
                    'ok' => false,
                    'error' => 'account_id_empty',
                ];
            }
            
            // Get available accounts from KeyCenter
            $availableAccounts = [];
            try {
                $keyCenter = \Core\KeyCenter\KeyCenter::instance();
                if (method_exists($keyCenter, 'listAccounts')) {
                    $availableAccounts = $keyCenter->listAccounts('bybit') ?? [];
                }
            } catch (\Throwable $e) {
                // KeyCenter error - fall through to validation
            }
            
            if (!empty($availableAccounts) && !in_array($accountId, $availableAccounts, true)) {
                return [
                    'ok' => false,
                    'error' => 'account_id_not_configured',
                    'available_accounts' => $availableAccounts,
                ];
            }
            
            // For LIVE mode, ensure account is valid (stricter check)
            $currentMode = $this->config['module']['mode'] ?? 'dry';
            $newMode = $filtered['mode'] ?? $currentMode;
            
            if ($newMode === 'live') {
                $hasCredentials = false;
                try {
                    $keyCenter = \Core\KeyCenter\KeyCenter::instance();
                    if (method_exists($keyCenter, 'hasCredentials')) {
                        $hasCredentials = $keyCenter->hasCredentials('bybit', $accountId);
                    }
                } catch (\Throwable $e) {
                    // KeyCenter error
                }
                
                if (!$hasCredentials) {
                    return [
                        'ok' => false,
                        'error' => 'account_id_not_configured_for_live',
                        'message' => "Cannot save: account '$accountId' has no Bybit credentials in KeyCenter for LIVE mode",
                        'available_accounts' => $availableAccounts,
                    ];
                }
            }
            
            $filtered['account_id'] = $accountId;
        }
        
        $success = $this->saveRuntimeConfig($filtered);
        
        // Reload config
        if ($success) {
            $this->config = $this->loadConfig();
        }
        
        return [
            'ok' => $success,
            'saved' => $filtered,
        ];
    }


    /* =====================================================================
       UI — Active Positions (Exchange) + Actions
       ===================================================================== */

    /**
     * UI: Get active exchange positions in UI-friendly format
     * ROI "как на Bybit" = unrealisedPnl / positionIM * 100
     * ROI shown with 2 decimals in UI.
     *
     * @param int $limit Max items (0 = no limit)
     * @return array{ok:bool,positions:array,count:int,ts:string}
     */
    public function uiGetActivePositionsUi(int $limit = 0): array
    {
        $ts = date('c');

        if (!$this->gateway) {
            return ['ok' => false, 'positions' => [], 'count' => 0, 'ts' => $ts, 'error' => 'gateway_not_initialized'];
        }

        $list = $this->gateway->getPositions();
        if (!is_array($list)) {
            $list = [];
        }

        $positions = [];
        foreach ($list as $p) {
            $size = (float)($p['size'] ?? 0);
            if ($size <= 0) {
                continue;
            }

            $symbol = (string)($p['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            $sideRaw = (string)($p['side'] ?? '');
            $side = (strtolower($sideRaw) === 'buy') ? 'long' : ((strtolower($sideRaw) === 'sell') ? 'short' : strtolower($sideRaw));

            $unrealisedPnl = (float)($p['unrealisedPnl'] ?? 0);
            $positionIM = (float)($p['positionIM'] ?? 0);
            $roiPct = ($positionIM > 0) ? (($unrealisedPnl / $positionIM) * 100.0) : 0.0;

            $positions[] = [
                'symbol' => $symbol,
                'side' => $side,
                'side_raw' => $sideRaw,
                'size' => $size,
                'avg_price' => (float)($p['avgPrice'] ?? 0),
                'mark_price' => (float)($p['markPrice'] ?? 0),
                'liq_price' => (float)($p['liqPrice'] ?? 0),
                'unrealised_pnl' => $unrealisedPnl,
                'position_im' => $positionIM,
                'roi_pct' => $roiPct,
                'stop_loss' => (float)($p['stopLoss'] ?? 0),
                'take_profit' => (float)($p['takeProfit'] ?? 0),
                'trailing_stop' => (float)($p['trailingStop'] ?? 0),
                'active_price' => (float)($p['activePrice'] ?? 0),
                'position_idx' => (int)($p['positionIdx'] ?? 0),
                'raw' => $p,
            ];
        }

        // Stable ordering: highest abs PnL first (useful)
        usort($positions, function ($a, $b) {
            return abs((float)$b['unrealised_pnl']) <=> abs((float)$a['unrealised_pnl']);
        });

        if ($limit > 0 && count($positions) > $limit) {
            $positions = array_slice($positions, 0, $limit);
        }

        return [
            'ok' => true,
            'positions' => $positions,
            'count' => count($positions),
            'ts' => $ts,
        ];
    }

    /**
     * UI: Close position immediately (reduce-only market)
     * NO confirm in UI by design (user requested).
     */
    public function uiClosePosition(array $input): array
    {
        if (!$this->isRealExchangeMode()) {
            return ['ok' => false, 'error' => 'not_real_exchange_mode'];
        }

        $symbol = trim((string)($input['symbol'] ?? ''));
        $side = trim((string)($input['side'] ?? ''));
        $positionIdx = (int)($input['position_idx'] ?? 0);

        if ($symbol === '' || ($side !== 'long' && $side !== 'short')) {
            return ['ok' => false, 'error' => 'invalid_params'];
        }

        // Close reduce-only via gateway
        $close = $this->gateway->closePosition($symbol, $side, $positionIdx);

        return [
            'ok' => (bool)($close['success'] ?? false),
            'status' => ($close['success'] ?? false) ? 'closed' : 'close_failed',
            'symbol' => $symbol,
            'side' => $side,
            'position_idx' => $positionIdx,
            'close' => $close,
        ];
    }

    /**
     * UI: Update position stops (SL and/or trailing stop params)
     * "пока только менять" - if a field is not provided or <=0, it will not be changed.
     */
    public function uiUpdatePositionStops(array $input): array
    {
        if (!$this->isRealExchangeMode()) {
            return ['ok' => false, 'error' => 'not_real_exchange_mode'];
        }

        $symbol = trim((string)($input['symbol'] ?? ''));
        $side = trim((string)($input['side'] ?? ''));
        $positionIdx = (int)($input['position_idx'] ?? 0);

        if ($symbol === '' || ($side !== 'long' && $side !== 'short')) {
            return ['ok' => false, 'error' => 'invalid_params'];
        }

        $options = [
            'position_idx' => $positionIdx,
            'tpsl_mode' => $this->config['exchange']['tpsl_mode'] ?? 'Full',
            'sl_trigger_by' => $this->config['exchange']['sl_trigger_by'] ?? 'IndexPrice',
        ];

        // Only change if provided (>0)
        if (isset($input['stop_loss'])) {
            $sl = (float)$input['stop_loss'];
            if ($sl > 0) {
                $options['stop_loss'] = $sl;
            }
        }

        if (isset($input['trailing_stop'])) {
            $ts = (float)$input['trailing_stop'];
            if ($ts > 0) {
                $options['trailing_stop'] = $ts;
            }
        }

        if (isset($input['active_price'])) {
            $ap = (float)$input['active_price'];
            if ($ap > 0) {
                $options['active_price'] = $ap;
            }
        }

        if (!isset($options['stop_loss']) && !isset($options['trailing_stop']) && !isset($options['active_price'])) {
            return ['ok' => false, 'error' => 'nothing_to_update'];
        }

        $resp = $this->gateway->setTradingStop($symbol, $side, $options);

        return [
            'ok' => (bool)($resp['success'] ?? false),
            'status' => ($resp['success'] ?? false) ? 'updated' : 'update_failed',
            'symbol' => $symbol,
            'side' => $side,
            'position_idx' => $positionIdx,
            'options' => $options,
            'exchange' => $resp,
        ];
    }

}

/* RULES
- API trait provides REST endpoints for UI
- Returns normalized JSON responses
- NO direct file writes - use BotStore
- Respects module enabled/disabled state
*/
