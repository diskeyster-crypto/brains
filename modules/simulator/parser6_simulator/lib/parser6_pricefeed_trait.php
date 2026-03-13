<?php

declare(strict_types=1);

use Core\System\SystemPaths;
use Core\Gateway\Bybit;

/**
 * Parser6PricefeedTrait - Tick reading and live price fetching
 * 
 * Methods for reading ticks from history and fetching live prices from Bybit.
 */
trait Parser6PricefeedTrait
{
    /**
     * Logged symbols for skipped ticks (prevent log spam)
     */
    private array $_loggedSkippedSymbols = [];
    
    /**
     * Live price cache
     */
    private array $livePriceCache = [];
    
    /**
     * Cache expiry in seconds
     */
    private int $priceCacheExpirySec = 5;

    /**
     * Read new ticks from history files
     */
    private function readNewTicks(array $trade): array
    {
        $symbol = $trade['symbol'] ?? '';
        $cursor = $trade['cursor'] ?? [];
        $lastProcessedTs = (int)($cursor['last_processed_ts'] ?? 0);
        $cursorDate = $cursor['history_date'] ?? date('Y-m-d');

        $sources = $this->config['sources'] ?? [];
        $historyKey = (string)($sources['history_key'] ?? 'parser.parser2_history_accumulator.storage');

        try {
            $historyBase = SystemPaths::instance()->get($historyKey);
        } catch (\Throwable $e) {
            $this->errors[] = 'history_key_unknown: ' . $historyKey;
            return [];
        }

        $historyBase = rtrim((string)$historyBase, '/');
        $todayDate = date('Y-m-d');
        $ticks = [];
        $currentDate = $cursorDate;
        $skippedInvalid = 0;
        
        $tickConfig = $this->config['tick_format'] ?? [];
        $tsKeys = $tickConfig['ts_keys'] ?? ['ts', 't', 'time', 'timestamp', 'ts_ms'];
        $priceKeys = $tickConfig['price_keys'] ?? ['price', 'last', 'close', 'p', 'lastPrice', 'markPrice'];

        while (strtotime($currentDate) <= strtotime($todayDate)) {
            $ndjsonPath = $historyBase . '/' . $symbol . '/' . $currentDate . '.ndjson';
            
            if (is_file($ndjsonPath)) {
                $fileTicks = $this->readNdjsonFile($ndjsonPath, $lastProcessedTs, $tsKeys, $priceKeys, $skippedInvalid);
                
                foreach ($fileTicks as $tick) {
                    $ticks[] = $tick;
                    if ($tick['ts'] > $lastProcessedTs) {
                        $lastProcessedTs = $tick['ts'];
                    }
                }
            }
            
            $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
        }
        
        if ($skippedInvalid > 0 && !isset($this->_loggedSkippedSymbols[$symbol])) {
            $this->errors[] = "skipped_invalid_ticks:{$symbol}:{$skippedInvalid}";
            $this->_loggedSkippedSymbols[$symbol] = true;
        }

        return $ticks;
    }

    /**
     * Read ticks from NDJSON file
     */
    private function readNdjsonFile(string $path, int $afterTs, array $tsKeys, array $priceKeys, int &$skippedInvalid): array
    {
        $ticks = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $tick = json_decode($line, true);
            if (!is_array($tick)) {
                $skippedInvalid++;
                continue;
            }

            $tickTs = $this->extractTickTsWithKeys($tick, $tsKeys);
            $tickPrice = $this->extractTickPriceWithKeys($tick, $priceKeys);

            if ($tickTs === null || $tickPrice === null) {
                $skippedInvalid++;
                continue;
            }

            if ($tickTs <= $afterTs) {
                continue;
            }

            $ticks[] = [
                'ts' => $tickTs,
                'price' => $tickPrice,
                'raw' => $tick,
            ];
        }

        fclose($handle);
        return $ticks;
    }

    /**
     * Extract timestamp with configurable key mapping
     */
    private function extractTickTsWithKeys(array $tick, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($tick[$key])) {
                $ts = $tick[$key];
                
                if ($key === 'ts_ms' || (is_numeric($ts) && $ts > 1e12)) {
                    return (int)($ts / 1000);
                }
                
                if (is_int($ts)) {
                    return $ts;
                }
                if (is_float($ts)) {
                    return (int)$ts;
                }
                if (is_string($ts)) {
                    $parsed = strtotime($ts);
                    return $parsed !== false ? $parsed : null;
                }
            }
        }
        return null;
    }

    /**
     * Extract price with configurable key mapping
     */
    private function extractTickPriceWithKeys(array $tick, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($tick[$key]) && is_numeric($tick[$key])) {
                return (float)$tick[$key];
            }
        }
        return null;
    }

    /**
     * Extract timestamp (legacy version)
     */
    private function extractTickTs(array $tick): ?int
    {
        if (isset($tick['ts'])) {
            $ts = $tick['ts'];
            if (is_int($ts)) {
                return $ts;
            }
            if (is_string($ts)) {
                $parsed = strtotime($ts);
                return $parsed !== false ? $parsed : null;
            }
        }
        if (isset($tick['t'])) {
            return (int)$tick['t'];
        }
        if (isset($tick['ts_unix'])) {
            return (int)$tick['ts_unix'];
        }
        if (isset($tick['timestamp'])) {
            $ts = $tick['timestamp'];
            if (is_int($ts)) {
                return $ts > 1e12 ? (int)($ts / 1000) : $ts;
            }
            return strtotime((string)$ts) ?: null;
        }
        return null;
    }

    /**
     * Extract price (legacy version)
     */
    private function extractTickPrice(array $tick): ?float
    {
        if (isset($tick['price'])) {
            return (float)$tick['price'];
        }
        if (isset($tick['lastPrice'])) {
            return (float)$tick['lastPrice'];
        }
        if (isset($tick['close'])) {
            return (float)$tick['close'];
        }
        if (isset($tick['c'])) {
            return (float)$tick['c'];
        }
        return null;
    }

    /**
     * Fetch LIVE price from Bybit API
     */
    private function fetchLivePriceFromBybit(string $symbol): ?float
    {
        $nowTs = time();
        
        if (isset($this->livePriceCache[$symbol])) {
            $cached = $this->livePriceCache[$symbol];
            if ($nowTs - $cached['ts'] < $this->priceCacheExpirySec) {
                return $cached['price'];
            }
        }
        
        try {
            $bybit = Bybit::instance();
            
            $response = $bybit->request('market.tickers', [
                'category' => 'linear',
                'symbol' => $symbol,
            ], false);
            
            if (!isset($response['result']['list'][0])) {
                $this->errors[] = "bybit_price_missing:{$symbol}";
                return null;
            }
            
            $tickerData = $response['result']['list'][0];
            $price = null;
            
            if (isset($tickerData['lastPrice']) && is_numeric($tickerData['lastPrice'])) {
                $price = (float)$tickerData['lastPrice'];
            } elseif (isset($tickerData['markPrice']) && is_numeric($tickerData['markPrice'])) {
                $price = (float)$tickerData['markPrice'];
            } elseif (isset($tickerData['indexPrice']) && is_numeric($tickerData['indexPrice'])) {
                $price = (float)$tickerData['indexPrice'];
            }
            
            if ($price === null || $price <= 0) {
                $this->errors[] = "bybit_price_invalid:{$symbol}";
                return null;
            }
            
            $this->livePriceCache[$symbol] = [
                'price' => $price,
                'ts' => $nowTs,
            ];
            
            return $price;
            
        } catch (\Throwable $e) {
            $this->errors[] = "bybit_api_error:{$symbol}:" . $e->getMessage();
            
            if (isset($this->livePriceCache[$symbol])) {
                return $this->livePriceCache[$symbol]['price'];
            }
            
            $this->emitEvent('price_unavailable', [
                'symbol' => $symbol,
                'reason' => $e->getMessage(),
                'ts' => $nowTs,
            ]);
            
            return null;
        }
    }

    /**
     * Fetch prices for multiple symbols
     */
    private function fetchLivePricesForSymbols(array $symbols): array
    {
        $prices = [];
        $symbolsToFetch = [];
        $nowTs = time();
        
        foreach ($symbols as $symbol) {
            if (isset($this->livePriceCache[$symbol])) {
                $cached = $this->livePriceCache[$symbol];
                if ($nowTs - $cached['ts'] < $this->priceCacheExpirySec) {
                    $prices[$symbol] = $cached['price'];
                    continue;
                }
            }
            $symbolsToFetch[] = $symbol;
        }
        
        if (empty($symbolsToFetch)) {
            return $prices;
        }
        
        foreach ($symbolsToFetch as $symbol) {
            $price = $this->fetchLivePriceFromBybit($symbol);
            if ($price !== null) {
                $prices[$symbol] = $price;
            }
        }
        
        return $prices;
    }

    /**
     * Update ticks_last_seen.json for all active symbols
     */
    private function updateTicksLastSeen(): void
    {
        $path = $this->storageDir . '/ticks_last_seen.json';
        $existing = [];
        if (is_file($path)) {
            $content = file_get_contents($path);
            $existing = json_decode($content ?: '', true) ?: [];
        }
        
        $nowTs = time();
        foreach ($this->livePriceCache as $symbol => $data) {
            $existing[$symbol] = [
                'last_ts' => $data['ts'],
                'last_price' => $data['price'],
                'updated_at' => date('c', $nowTs),
            ];
        }
        
        $this->writeJsonAtomic($path, $existing);
    }
}

/* RULES
 * NO HARDCODE. CONFIG FIRST. SystemPaths ONLY.
 * Tick format is configurable via config (ts_keys, price_keys).
 * Live prices fetched from Bybit API with caching.
 * If price unavailable, use cached price if available.
 */
