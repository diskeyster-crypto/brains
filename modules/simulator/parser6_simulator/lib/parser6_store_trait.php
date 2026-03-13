<?php

declare(strict_types=1);

/**
 * Parser6StoreTrait - Trade loading/saving for root storage
 * 
 * Methods for loading and saving trades, executed index, and rejected trades.
 */
trait Parser6StoreTrait
{
    /**
     * Load active trades from root storage
     */
    private function loadActiveTrades(): array
    {
        $dir = $this->storageDir . '/trades/active';
        if (!is_dir($dir)) {
            return [];
        }

        $trades = [];
        foreach (glob($dir . '/*.json') as $file) {
            $content = file_get_contents($file);
            $trade = json_decode($content, true);
            if (is_array($trade) && isset($trade['trade_id'])) {
                $trades[$trade['trade_id']] = $trade;
            }
        }

        return $trades;
    }

    /**
     * Load closed trades from root storage
     */
    private function loadClosedTrades(): array
    {
        $dir = $this->storageDir . '/trades/closed';
        if (!is_dir($dir)) {
            return [];
        }

        $trades = [];
        foreach (glob($dir . '/*.json') as $file) {
            $content = file_get_contents($file);
            $trade = json_decode($content, true);
            if (is_array($trade) && isset($trade['trade_id'])) {
                $trades[$trade['trade_id']] = $trade;
            }
        }

        return $trades;
    }

    /**
     * Load rejected trades from root storage
     */
    private function loadRejectedTrades(): array
    {
        $dir = $this->storageDir . '/trades/rejected';
        if (!is_dir($dir)) {
            return [];
        }

        $trades = [];
        foreach (glob($dir . '/*.json') as $file) {
            $content = file_get_contents($file);
            $trade = json_decode($content, true);
            if (is_array($trade) && isset($trade['trade_id'])) {
                $trades[$trade['trade_id']] = $trade;
            }
        }

        return $trades;
    }

    /**
     * Load trades from any directory
     */
    private function loadTradesFromDir(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $trades = [];
        foreach (glob($dir . '/*.json') as $file) {
            $content = file_get_contents($file);
            $trade = json_decode($content, true);
            if (is_array($trade) && isset($trade['trade_id'])) {
                $trades[$trade['trade_id']] = $trade;
            }
        }

        return $trades;
    }

    /**
     * Get IDs of active trades
     */
    private function getActiveTradeIds(): array
    {
        $trades = $this->loadActiveTrades();
        return array_flip(array_keys($trades));
    }

    /**
     * Get symbols of active trades
     */
    private function getActiveSymbols(): array
    {
        $trades = $this->loadActiveTrades();
        $symbols = [];
        foreach ($trades as $trade) {
            $symbol = $trade['symbol'] ?? '';
            if ($symbol !== '') {
                $symbols[$symbol] = true;
            }
        }
        return $symbols;
    }

    /**
     * Get list of busy symbols
     */
    public function getBusySymbols(): array
    {
        return array_keys($this->getActiveSymbols());
    }

    /**
     * Save active trade
     */
    private function saveActiveTrade(string $id, array $trade): void
    {
        $dir = $this->storageDir . '/trades/active';
        $this->ensureDir($dir);
        $this->writeJsonAtomic($dir . '/' . $id . '.json', $trade);
    }

    /**
     * Save closed trade
     */
    private function saveClosedTrade(string $id, array $trade): void
    {
        $dir = $this->storageDir . '/trades/closed';
        $this->ensureDir($dir);
        $this->writeJsonAtomic($dir . '/' . $id . '.json', $trade);
    }

    /**
     * Delete active trade
     */
    private function deleteActiveTrade(string $id): void
    {
        $path = $this->storageDir . '/trades/active/' . $id . '.json';
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Load executed index
     */
    private function loadExecutedIndex(): array
    {
        $path = $this->storageDir . '/executed_index.json';
        if (!is_file($path)) {
            return ['updated_at' => date('c'), 'completed' => []];
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : ['updated_at' => date('c'), 'completed' => []];
    }

    /**
     * Save executed index
     */
    private function saveExecutedIndex(array $index): void
    {
        $path = $this->storageDir . '/executed_index.json';
        $this->writeJsonAtomic($path, $index);
    }

    /**
     * Append rejected trade to centralized trades_rejected.json
     */
    private function appendToRejectedList(array $rejectedTrade): void
    {
        $path = $this->storageDir . '/trades_rejected.json';
        $tradeId = $rejectedTrade['trade_id'] ?? '';
        
        if ($tradeId === '') {
            return;
        }
        
        $data = [];
        if (is_file($path)) {
            $content = file_get_contents($path);
            $data = json_decode($content, true) ?: [];
        }
        
        $map = [];
        foreach ($data as $trade) {
            $id = $trade['trade_id'] ?? '';
            if ($id !== '') {
                $map[$id] = $trade;
            }
        }
        
        $map[$tradeId] = $rejectedTrade;
        $result = array_values($map);
        
        if (count($result) > 1000) {
            $result = array_slice($result, -1000);
        }
        
        $this->writeJsonAtomic($path, $result);
    }

    /**
     * Rebuild trades_rejected.json from directory
     */
    private function rebuildRejectedIndexFromDir(): void
    {
        $dir = $this->storageDir . '/trades/rejected';
        $path = $this->storageDir . '/trades_rejected.json';
        
        if (!is_dir($dir)) {
            return;
        }
        
        $files = glob($dir . '/*.json');
        if ($files === false || empty($files)) {
            $this->writeJsonAtomic($path, []);
            return;
        }
        
        $map = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $trade = json_decode($content, true);
            if ($trade && isset($trade['trade_id'])) {
                $map[$trade['trade_id']] = $trade;
            }
        }
        
        $result = array_values($map);
        usort($result, function($a, $b) {
            return ($b['rejected_ts'] ?? 0) <=> ($a['rejected_ts'] ?? 0);
        });
        
        if (count($result) > 1000) {
            $result = array_slice($result, 0, 1000);
        }
        
        $this->writeJsonAtomic($path, $result);
    }
    
    /**
     * Save rejected trade to root storage
     */
    private function saveRejectedTrade(array $trade, array $rejectResult): void
    {
        $tradeId = $trade['trade_id'] ?? '';
        if ($tradeId === '') {
            return;
        }
        
        $trade['status'] = 'rejected';
        $trade['reject_result'] = $rejectResult;
        $trade['reject_reason'] = $rejectResult['close_reason'] ?? 'unknown';
        $trade['rejected_at'] = date('c');
        $trade['rejected_ts'] = time();
        
        $dir = $this->storageDir . '/trades/rejected';
        $this->ensureDir($dir);
        $this->writeJsonAtomic($dir . '/' . $tradeId . '.json', $trade);
        
        // Also append to rejected list
        $this->appendToRejectedList($trade);
    }
}

/* RULES
 * NO HARDCODE. CONFIG FIRST. SystemPaths ONLY.
 * Root storage uses $this->storageDir.
 * For mode-specific storage, use integration trait methods.
 */
