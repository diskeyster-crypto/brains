<?php

declare(strict_types=1);

namespace Modules\ProfManager\Lib;

/**
 * PositionReader
 *
 * Reads active/open positions from the bot module's paper runtime source.
 * Tries multiple candidate paths in order; uses the first non-empty valid source.
 * Returns an empty list with diagnostics when no compatible source is found.
 *
 * Normalizes all common field variants into PM canonical format.
 * Enriches missing fields: leverage default, budget default, calculated size,
 * current_price from Bybit public REST API, and PnL calculation.
 *
 * Does NOT invent fake positions and does NOT call any authenticated or
 * trading API endpoints.
 */
class PositionReader
{
    private string $repoRoot;

    /** Per-instance price cache; symbol → float (0.0 means unavailable). */
    private array $priceCache = [];

    public function __construct(string $repoRoot)
    {
        $this->repoRoot = rtrim($repoRoot, '/');
    }

    /**
     * Read and normalize active positions.
     *
     * @return array{
     *   positions: list<array>,
     *   source: string,
     *   enrichment_summary: array,
     *   diagnostics: array
     * }
     */
    public function read(): array
    {
        $fixedCandidates = [
            'modules/bot/storage/active_positions.json',
        ];

        $strategyCandidates = $this->resolveStrategyCandidates();

        $executorCandidates = [
            'modules/trading/executor_bot/storage/last_run.json',
        ];

        $allCandidates = array_merge($fixedCandidates, $strategyCandidates, $executorCandidates);
        $tried = [];

        foreach ($allCandidates as $relPath) {
            $absPath = $this->repoRoot . '/' . $relPath;
            $tried[] = $relPath;

            if (!is_file($absPath) || !is_readable($absPath)) {
                continue;
            }

            $raw = file_get_contents($absPath);
            if ($raw === false || $raw === '') {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }

            // executor_bot last_run may have a 'positions' key
            if (str_contains($relPath, 'last_run.json') && isset($decoded['positions']) && is_array($decoded['positions'])) {
                $decoded = $decoded['positions'];
            }

            $rawPositions = array_values($decoded);
            if (empty($rawPositions)) {
                continue;
            }

            $enrichmentStats = [
                'prices_from_bybit'    => 0,
                'prices_from_positions' => 0,
                'prices_missing'       => 0,
                'sizes_calculated'     => 0,
                'leverage_defaulted'   => 0,
                'budget_defaulted'     => 0,
            ];

            $positions = $this->normalizePositions($rawPositions, $enrichmentStats);

            return [
                'positions'          => $positions,
                'source'             => $relPath,
                'enrichment_summary' => $enrichmentStats,
                'diagnostics'        => [
                    'path'  => $absPath,
                    'count' => count($positions),
                    'tried' => $tried,
                ],
            ];
        }

        return [
            'positions'          => [],
            'source'             => 'none',
            'enrichment_summary' => [],
            'diagnostics'        => [
                'reason'     => 'no_positions_source_found',
                'candidates' => $allCandidates,
                'tried'      => $tried,
            ],
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve dynamic strategy sub-module candidates.
     *
     * @return list<string>
     */
    private function resolveStrategyCandidates(): array
    {
        $candidates   = [];
        $strategyRoot = $this->repoRoot . '/modules/strategy';

        if (!is_dir($strategyRoot)) {
            return $candidates;
        }

        $dirs = glob($strategyRoot . '/*/storage', GLOB_ONLYDIR);
        if (!is_array($dirs)) {
            return $candidates;
        }

        foreach ($dirs as $storageDir) {
            $base         = str_replace($this->repoRoot . '/', '', $storageDir);
            $candidates[] = $base . '/bot_active_positions.json';
            $candidates[] = $base . '/active_positions.json';
        }

        return $candidates;
    }

    /**
     * Normalize and enrich position fields to canonical PM format.
     *
     * Field aliases supported:
     *   size        : qty, quantity, position_size, positionQty, contracts, amount
     *                 (bot_budget is NOT a size alias — it is capital in USDT)
     *   leverage    : leverage, lev, bot_leverage, position_leverage
     *   entry_price : entry_price, avg_price, avgPrice, average_price, entryPrice
     *   current_price: current_price, mark_price, markPrice, last_price, price,
     *                  currentPrice, runtime.current_price, market.mark_price
     *   pnl         : unrealised_pnl, unrealized_pnl, unrealisedPnl, unrealizedPnl, pnl
     *
     * Nested unwrapping: position.*, data.*
     * Original data preserved under _raw.
     *
     * Enrichment (applied when fields are missing):
     *   leverage  → default 10; _leverage_source = explicit|default
     *   budget    → from bot_budget/budget, else default 6; _budget_source = explicit|default
     *   size      → calculated as (budget × leverage) / entry_price; _size_calculated = true
     *   current_price → fetched from Bybit public REST API; _price_source = active_positions|bybit_public|unavailable
     *   unrealised_pnl → calculated from size and prices when available
     *
     * @param list<mixed> $raw
     * @param array       $enrichmentStats passed by reference; counters updated in place
     * @return list<array>
     */
    private function normalizePositions(array $raw, array &$enrichmentStats): array
    {
        $result = [];

        foreach ($raw as $pos) {
            if (!is_array($pos)) {
                continue;
            }

            // ── Unwrap common nesting ─────────────────────────────────────────
            if (!isset($pos['symbol']) && isset($pos['position']) && is_array($pos['position'])) {
                $nested = $pos['position'];
                unset($pos['position']);
                $pos = array_merge($nested, $pos);
            }
            if (!isset($pos['symbol']) && isset($pos['data']) && is_array($pos['data'])) {
                $nested = $pos['data'];
                unset($pos['data']);
                $pos = array_merge($nested, $pos);
            }

            // ── Preserve original data for diagnostics ────────────────────────
            if (!isset($pos['_raw'])) {
                $pos['_raw'] = $pos;
            }

            // ── entry_price ───────────────────────────────────────────────────
            if (!isset($pos['entry_price']) || (float) $pos['entry_price'] <= 0.0) {
                foreach (['avg_price', 'avgPrice', 'average_price', 'entryPrice'] as $alias) {
                    if (isset($pos[$alias]) && (float) $pos[$alias] > 0.0) {
                        $pos['entry_price'] = $pos[$alias];
                        break;
                    }
                }
            }
            $entryPrice = (float) ($pos['entry_price'] ?? 0.0);

            // ── leverage ──────────────────────────────────────────────────────
            $leverageFound = (isset($pos['leverage']) && (float) $pos['leverage'] > 0.0);
            if ($leverageFound) {
                $pos['_leverage_source'] = 'explicit';
            } else {
                foreach (['lev', 'bot_leverage', 'position_leverage'] as $alias) {
                    if (isset($pos[$alias]) && (float) $pos[$alias] > 0.0) {
                        $pos['leverage']         = $pos[$alias];
                        $pos['_leverage_source'] = 'explicit';
                        $leverageFound           = true;
                        break;
                    }
                }
            }
            if (!$leverageFound) {
                $pos['leverage']         = 10.0;
                $pos['_leverage_source'] = 'default';
                $enrichmentStats['leverage_defaulted']++;
            }
            $leverage = (float) $pos['leverage'];

            // ── budget (capital in USDT — NOT size) ───────────────────────────
            // bot_budget and budget are capital fields, never a position-size alias
            $budgetFound = false;
            foreach (['budget', 'bot_budget'] as $alias) {
                if (isset($pos[$alias]) && (float) $pos[$alias] > 0.0) {
                    $pos['_budget']        = (float) $pos[$alias];
                    $pos['_budget_source'] = 'explicit';
                    $budgetFound           = true;
                    break;
                }
            }
            if (!$budgetFound) {
                $pos['_budget']        = 6.0;
                $pos['_budget_source'] = 'default';
                $enrichmentStats['budget_defaulted']++;
            }
            $budget = (float) $pos['_budget'];

            // ── size (contracts / coin qty) ───────────────────────────────────
            // Real qty aliases only — bot_budget is USDT capital, not contracts
            $sizeFound = (isset($pos['size']) && (float) $pos['size'] > 0.0);
            if (!$sizeFound) {
                foreach (['qty', 'quantity', 'position_size', 'positionQty', 'contracts', 'amount'] as $alias) {
                    if (isset($pos[$alias]) && (float) $pos[$alias] > 0.0) {
                        $pos['size'] = $pos[$alias];
                        $sizeFound   = true;
                        break;
                    }
                }
            }
            // Calculate from budget × leverage ÷ entry_price when no real qty found
            if (!$sizeFound && $entryPrice > 0.0 && $leverage > 0.0 && $budget > 0.0) {
                $calculatedSize = ($budget * $leverage) / $entryPrice;
                if ($calculatedSize > 0.0) {
                    $pos['size']             = $calculatedSize;
                    $pos['_size_calculated'] = true;
                    $enrichmentStats['sizes_calculated']++;
                    $sizeFound = true;
                }
            }

            // ── current_price ─────────────────────────────────────────────────
            $priceFound = (isset($pos['current_price']) && (float) $pos['current_price'] > 0.0);
            if (!$priceFound) {
                foreach (['mark_price', 'markPrice', 'last_price', 'price', 'currentPrice'] as $alias) {
                    if (isset($pos[$alias]) && (float) $pos[$alias] > 0.0) {
                        $pos['current_price'] = $pos[$alias];
                        $priceFound           = true;
                        break;
                    }
                }
            }
            // Nested: runtime.current_price
            if (!$priceFound
                && isset($pos['runtime']['current_price'])
                && (float) $pos['runtime']['current_price'] > 0.0) {
                $pos['current_price'] = $pos['runtime']['current_price'];
                $priceFound           = true;
            }
            // Nested: market.mark_price
            if (!$priceFound
                && isset($pos['market']['mark_price'])
                && (float) $pos['market']['mark_price'] > 0.0) {
                $pos['current_price'] = $pos['market']['mark_price'];
                $priceFound           = true;
            }
            if ($priceFound) {
                $pos['_price_source'] = 'active_positions';
                $enrichmentStats['prices_from_positions']++;
            }

            // Bybit public REST API — only when price is still missing
            $symbol = (string) ($pos['symbol'] ?? '');
            if (!$priceFound && $symbol !== '') {
                $fetchedPrice = $this->fetchBybitPrice($symbol);
                if ($fetchedPrice !== null) {
                    $pos['current_price'] = $fetchedPrice;
                    $pos['_price_source'] = 'bybit_public';
                    $priceFound           = true;
                    $enrichmentStats['prices_from_bybit']++;
                }
            }

            if (!$priceFound) {
                $pos['_no_price_data'] = true;
                $pos['_price_source']  = 'unavailable';
                $enrichmentStats['prices_missing']++;
            } else {
                $pos['_no_price_data'] = false;
            }

            // ── unrealised_pnl ────────────────────────────────────────────────
            if (!isset($pos['unrealised_pnl'])) {
                foreach (['unrealized_pnl', 'unrealisedPnl', 'unrealizedPnl', 'pnl'] as $alias) {
                    if (isset($pos[$alias])) {
                        $pos['unrealised_pnl'] = $pos[$alias];
                        break;
                    }
                }
            }
            // Calculate PnL when size and prices are available
            if (!isset($pos['unrealised_pnl']) || $pos['unrealised_pnl'] === null) {
                $size         = (float) ($pos['size']          ?? 0.0);
                $currentPrice = (float) ($pos['current_price'] ?? 0.0);
                if ($size > 0.0 && $entryPrice > 0.0 && $currentPrice > 0.0) {
                    $side                  = strtolower((string) ($pos['side'] ?? 'long'));
                    $pos['unrealised_pnl'] = ($side === 'long' || $side === 'buy')
                        ? ($currentPrice - $entryPrice) * $size
                        : ($entryPrice - $currentPrice) * $size;
                }
            }

            $result[] = $pos;
        }

        return $result;
    }

    /**
     * Fetch current mark price from Bybit public REST API (no auth required).
     *
     * Uses a per-instance cache so repeated calls for the same symbol within one
     * tick do not make multiple network requests.
     *
     * Returns null when the price cannot be retrieved (network error, unknown symbol, etc.).
     *
     * @param string $symbol e.g. "BTCUSDT"
     * @return float|null
     */
    private function fetchBybitPrice(string $symbol): ?float
    {
        if (array_key_exists($symbol, $this->priceCache)) {
            $v = $this->priceCache[$symbol];
            return $v > 0.0 ? $v : null;
        }

        $url   = 'https://api.bybit.com/v5/market/tickers?category=linear&symbol=' . urlencode($symbol);
        $price = null;

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 3,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT      => 'BrainsPM/1.0',
                    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                ]);
                $resp = curl_exec($ch);
                curl_close($ch);
                if (is_string($resp) && $resp !== '') {
                    $price = $this->extractBybitPrice(@json_decode($resp, true));
                }
            } elseif ((bool) ini_get('allow_url_fopen')) {
                $ctx  = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
                $resp = @file_get_contents($url, false, $ctx);
                if (is_string($resp) && $resp !== '') {
                    $price = $this->extractBybitPrice(@json_decode($resp, true));
                }
            }
        } catch (\Throwable) {
            // Network failure — price stays null; position tagged _no_price_data
        }

        $this->priceCache[$symbol] = $price ?? 0.0;
        return $price;
    }

    /**
     * Extract markPrice (preferred) or lastPrice from a Bybit v5 tickers response.
     *
     * @param mixed $data decoded JSON response
     * @return float|null
     */
    private function extractBybitPrice(mixed $data): ?float
    {
        if (!is_array($data)) {
            return null;
        }
        $list = $data['result']['list'] ?? [];
        if (!is_array($list) || empty($list)) {
            return null;
        }
        $ticker = $list[0];
        $price  = (float) ($ticker['markPrice'] ?? $ticker['lastPrice'] ?? 0.0);
        return $price > 0.0 ? $price : null;
    }
}
