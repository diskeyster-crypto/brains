<?php
declare(strict_types=1);

/**
 * PriceFeed — Smart Brain Phase 8.2
 *
 * Fetches real current market prices from the Bybit public API.
 * No API key required — uses only public endpoints.
 *
 * Preferred field: markPrice
 * Fallback field:  lastPrice
 *
 * If the request fails, returns an empty array and logs a warning.
 * Missing symbols are silently skipped.
 */
final class PriceFeed
{
    private const ENDPOINT = 'https://api.bybit.com/v5/market/tickers?category=linear';

    private SmartBrainLogger $logger;

    public function __construct(SmartBrainLogger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Fetch current prices for requested symbols from Bybit.
     *
     * @param array<int,string> $symbols  List of symbols, e.g. ["BTCUSDT","ETHUSDT"]
     * @return array<string,float>        Symbol→price map (only for symbols that were found)
     */
    public function getPrices(array $symbols): array
    {
        if ($symbols === []) {
            return [];
        }

        $wanted = [];
        foreach ($symbols as $sym) {
            $wanted[(string)$sym] = true;
        }

        $json = $this->fetchJson(self::ENDPOINT);
        if ($json === null) {
            return [];
        }

        $list = $json['result']['list'] ?? [];
        if (!is_array($list)) {
            $this->logger->log('warning', 'PriceFeed: unexpected response structure');
            return [];
        }

        $prices = [];
        foreach ($list as $ticker) {
            if (!is_array($ticker)) {
                continue;
            }
            $sym = (string)($ticker['symbol'] ?? '');
            if ($sym === '' || !isset($wanted[$sym])) {
                continue;
            }

            $price = $this->extractPrice($ticker);
            if ($price > 0.0) {
                $prices[$sym] = $price;
            }
        }

        return $prices;
    }

    /**
     * Extract price preferring markPrice, falling back to lastPrice.
     */
    private function extractPrice(array $ticker): float
    {
        // Prefer markPrice
        if (isset($ticker['markPrice']) && is_numeric($ticker['markPrice'])) {
            $p = (float)$ticker['markPrice'];
            if ($p > 0.0) {
                return $p;
            }
        }

        // Fallback to lastPrice
        if (isset($ticker['lastPrice']) && is_numeric($ticker['lastPrice'])) {
            $p = (float)$ticker['lastPrice'];
            if ($p > 0.0) {
                return $p;
            }
        }

        return 0.0;
    }

    /**
     * Fetch JSON from URL. Returns null on failure.
     *
     * @return array<string,mixed>|null
     */
    private function fetchJson(string $url): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 10,
                'header'  => "Accept: application/json\r\n",
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            $this->logger->log('warning', 'PriceFeed: HTTP request failed for ' . $url);
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            $this->logger->log('warning', 'PriceFeed: invalid JSON response');
            return null;
        }

        $retCode = (int)($data['retCode'] ?? -1);
        if ($retCode !== 0) {
            $msg = (string)($data['retMsg'] ?? 'unknown');
            $this->logger->log('warning', 'PriceFeed: Bybit API error retCode=' . $retCode . ' msg=' . $msg);
            return null;
        }

        return $data;
    }
}
