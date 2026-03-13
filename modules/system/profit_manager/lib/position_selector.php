<?php
declare(strict_types=1);

namespace Modules\System\ProfitManager\Lib;

use Core\System\SystemPaths;

/**
 * Position Selector
 * 
 * Determines which positions to manage based on mode:
 * - Mode A (exchange_only): All exchange positions
 * - Mode B (trading_bot_trades): Only positions from Trading Bot
 */
class PositionSelector
{
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    /**
     * Get managed symbols based on selector mode
     * 
     * @param array $exchangePositions Positions from exchange
     * @return array List of symbols to manage with their context
     */
    public function getManagedSymbols(array $exchangePositions): array
    {
        $mode = $this->config['selector']['mode'] ?? 'exchange_only';
        
        if ($mode === 'trading_bot_trades') {
            return $this->selectByTradingBotTrades($exchangePositions);
        }
        
        // Default: exchange_only
        return $this->selectByExchangePositions($exchangePositions);
    }
    
    /**
     * Get selector mode
     */
    public function getMode(): string
    {
        return $this->config['selector']['mode'] ?? 'exchange_only';
    }
    
    // =========================================================================
    // Mode A: Exchange Only
    // =========================================================================
    
    /**
     * Select positions from exchange data
     * 
     * @param array $exchangePositions Positions from exchange
     * @return array Managed symbols with context
     */
    private function selectByExchangePositions(array $exchangePositions): array
    {
        $managed = [];
        
        $includeSymbols = $this->config['selector']['include_symbols'] ?? [];
        $excludeSymbols = $this->config['selector']['exclude_symbols'] ?? [];
        $requireBotPrefix = $this->config['selector']['require_bot_prefix'] ?? false;
        
        foreach ($exchangePositions as $pos) {
            $symbol = $pos['symbol'] ?? '';
            $size = (float) ($pos['size'] ?? 0);
            
            // Skip zero-size positions
            if ($size <= 0) {
                continue;
            }
            
            // Check include list (if set)
            if (!empty($includeSymbols) && !in_array($symbol, $includeSymbols, true)) {
                continue;
            }
            
            // Check exclude list
            if (!empty($excludeSymbols) && in_array($symbol, $excludeSymbols, true)) {
                continue;
            }
            
            // Check bot prefix (if required)
            if ($requireBotPrefix) {
                // Try to determine if this is a bot position
                // Note: Bybit doesn't return orderLinkId in position data directly
                // This would need to be matched via order history or trade state
                // For now, skip this check if we can't determine
                // TODO: Implement orderLinkId matching
            }
            
            $managed[$symbol] = [
                'symbol' => $symbol,
                'source' => 'exchange',
                'position' => $pos,
                // Mode A: Use defaults from config
                'leverage' => $this->resolveLeverageFromPosition($pos),
                'activation_roi_pct' => (float) ($this->config['step_trailing']['activation_roi_pct_default'] ?? 0),
                'trailing_enabled' => true, // Default to enabled in Mode A
            ];
        }
        
        return $managed;
    }
    
    // =========================================================================
    // Mode B: Trading Bot Trades
    // =========================================================================
    
    /**
     * Select positions based on Trading Bot active trades
     * 
     * @param array $exchangePositions Positions from exchange
     * @return array Managed symbols with context
     */
    private function selectByTradingBotTrades(array $exchangePositions): array
    {
        $managed = [];
        
        // Load active trades from Trading Bot storage
        $activeTrades = $this->loadTradingBotActiveTrades();
        
        if (empty($activeTrades)) {
            return [];
        }
        
        // Build map of exchange positions by symbol
        $positionsBySymbol = [];
        foreach ($exchangePositions as $pos) {
            $symbol = $pos['symbol'] ?? '';
            if (!empty($symbol)) {
                $positionsBySymbol[$symbol] = $pos;
            }
        }
        
        // Match active trades to exchange positions
        foreach ($activeTrades as $trade) {
            $symbol = $trade['symbol'] ?? '';
            
            // Skip if no matching exchange position
            if (!isset($positionsBySymbol[$symbol])) {
                continue;
            }
            
            $pos = $positionsBySymbol[$symbol];
            $size = (float) ($pos['size'] ?? 0);
            
            // Skip zero-size positions
            if ($size <= 0) {
                continue;
            }
            
            // Extract trailing config from trade risk
            $risk = $trade['risk'] ?? [];
            $trailing = $risk['trailing'] ?? [];
            
            $managed[$symbol] = [
                'symbol' => $symbol,
                'source' => 'trading_bot',
                'position' => $pos,
                'trade' => $trade,
                // Mode B: Use risk from trade
                'leverage' => (float) ($risk['leverage'] ?? $pos['leverage'] ?? 0),
                'activation_roi_pct' => (float) ($trailing['activation_roi_pct'] ?? $this->config['step_trailing']['activation_roi_pct_default'] ?? 0),
                'trailing_enabled' => (bool) ($trailing['enabled'] ?? true),
            ];
        }
        
        return $managed;
    }
    
    /**
     * Load active trades from Trading Bot storage
     * 
     * @return array Active trades
     */
    private function loadTradingBotActiveTrades(): array
    {
        $storageKey = $this->config['sources']['trading_bot_storage_key'] ?? 'system.trading_bot.storage';
        $tradesPath = $this->config['sources']['trading_bot_trades_path'] ?? 'trades/active';
        
        try {
            $paths = SystemPaths::instance();
            
            if (!$paths->has($storageKey)) {
                return [];
            }
            
            $storageDir = $paths->get($storageKey);
            if (!is_string($storageDir) || !is_dir($storageDir)) {
                return [];
            }
            
            $tradesDir = $storageDir . '/' . $tradesPath;
            if (!is_dir($tradesDir)) {
                return [];
            }
            
            // Load all trade files
            $trades = [];
            $files = glob($tradesDir . '/*.json');
            
            if ($files === false) {
                return [];
            }
            
            foreach ($files as $file) {
                $content = @file_get_contents($file);
                if ($content === false) {
                    continue;
                }
                
                $trade = json_decode($content, true);
                if (is_array($trade)) {
                    $trades[] = $trade;
                }
            }
            
            return $trades;
        } catch (\Throwable $e) {
            // Log error silently
            return [];
        }
    }
    
    /**
     * Get trade by symbol from Trading Bot storage
     * 
     * @param string $symbol Symbol to find
     * @return array|null Trade data or null
     */
    public function getTradingBotTrade(string $symbol): ?array
    {
        $trades = $this->loadTradingBotActiveTrades();
        
        foreach ($trades as $trade) {
            if (($trade['symbol'] ?? '') === $symbol) {
                return $trade;
            }
        }
        
        return null;
    }


    /**
     * Resolve leverage for exchange position.
     *
     * Prefer exchange field "leverage".
     * Fallback: leverage = notional / positionIM (notional = |size| * markPrice).
     *
     * @param array $pos Exchange position
     * @return float Leverage (0 if cannot be resolved)
     */
    private function resolveLeverageFromPosition(array $pos): float
    {
        $lev = (float) ($pos['leverage'] ?? 0);
        if ($lev > 0.0) {
            return $lev;
        }

        $positionIM = (float) ($pos['positionIM'] ?? 0);
        $markPrice = (float) ($pos['markPrice'] ?? 0);
        $size = (float) ($pos['size'] ?? 0);

        $notional = abs($size) * $markPrice;
        if ($positionIM > 0.0 && $notional > 0.0) {
            $computed = $notional / $positionIM;
            if ($computed > 0.0) {
                return $computed;
            }
        }

        return 0.0;
    }
}

/* RULES
- Mode A: Uses exchange positions directly
- Mode B: Matches Trading Bot active trades to exchange positions
- Respects include/exclude symbol lists
- Risk parameters come from trade in Mode B, config defaults in Mode A
*/
