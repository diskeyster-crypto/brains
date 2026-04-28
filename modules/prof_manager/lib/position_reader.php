<?php

declare(strict_types=1);

namespace Modules\ProfManager\Lib;

/**
 * PositionReader
 *
 * Reads active/open positions from the bot module's positions cache.
 * Primary (and only) source: modules/bot/storage/active_positions.json
 * This file is written by the bot after syncing with Bybit (demo or live).
 *
 * Normalizes all common field variants into PM canonical format.
 * Enriches missing fields: leverage default, budget default, calculated size,
 * current_price via PriceProvider (gateway or public REST fallback), and PnL calculation.
 *
 * Preserves each position's mode/execution_mode field as-is; does NOT force demo.
 * ProfManagerService uses moduleMode() as the fallback when position has no mode.
 *
 * Does NOT invent fake positions and does NOT call any authenticated or
 * trading API endpoints.
 */
class PositionReader
{
    private string $repoRoot;

    /** Kept for constructor-signature compatibility; no longer used for TTL filtering. */
    private int $positionTtlHours;

    /** Shared PriceProvider instance (created lazily). */
    private ?PriceProvider $priceProvider = null;

    public function __construct(string $repoRoot, int $positionTtlHours = 6)
    {
        $this->repoRoot         = rtrim($repoRoot, '/');
        $this->positionTtlHours = max(0, $positionTtlHours);
    }

    /**
     * Read and normalize active positions.
     *
     * Source of truth: modules/bot/storage/active_positions.json
     * (written by the bot after syncing with Bybit — demo or live).
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
        $relPath = 'modules/bot/storage/active_positions.json';
        $absPath = $this->repoRoot . '/' . $relPath;

        if (!is_file($absPath) || !is_readable($absPath)) {
            return $this->emptyResult($absPath, $relPath, 'no_positions');
        }

        $raw = file_get_contents($absPath);
        if ($raw === false || $raw === '') {
            return $this->emptyResult($absPath, $relPath, 'no_positions');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->emptyResult($absPath, $relPath, 'no_positions');
        }

        $rawPositions = array_values($decoded);
        if (empty($rawPositions)) {
            return $this->emptyResult($absPath, $relPath, 'no_positions');
        }

        $enrichmentStats = [
            'prices_from_position'  => 0,
            'prices_from_gateway'   => 0,
            'prices_missing'        => 0,
            'sizes_calculated'      => 0,
            'leverage_defaulted'    => 0,
            'budget_defaulted'      => 0,
        ];

        $positions = $this->normalizePositions($rawPositions, $enrichmentStats);

        // Count positions by mode
        $positionsDemo = 0;
        $positionsLive = 0;
        foreach ($positions as $pos) {
            $m = (string)($pos['mode'] ?? $pos['execution_mode'] ?? '');
            if ($m === 'live') {
                $positionsLive++;
            } else {
                $positionsDemo++;
            }
        }

        $provider = $this->priceProvider();

        return [
            'positions'            => $positions,
            'source'               => $relPath,
            'enrichment_summary'   => $enrichmentStats,
            'price_provider_error' => $provider->getProviderError(),
            'price_provider_source'=> $provider->getProviderSource(),
            'diagnostics'          => [
                'path'             => $absPath,
                'count'            => count($positions),
                'source_authority' => 'bot_active_positions_cache',
                'positions_demo'   => $positionsDemo,
                'positions_live'   => $positionsLive,
            ],
        ];
    }

    /**
     * Return a standardised empty result.
     */
    private function emptyResult(string $absPath, string $relPath, string $reason): array
    {
        return [
            'positions'          => [],
            'source'             => $relPath,
            'enrichment_summary' => [],
            'diagnostics'        => [
                'path'             => $absPath,
                'reason'           => $reason,
                'source_authority' => 'bot_active_positions_cache',
            ],
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Extract a UNIX timestamp from a position's timestamp field(s).
     * Returns null if no recognisable timestamp field is present.
     *
     * Checked fields in order: opened_at, entered_at, last_updated_at, created_at, timestamp
     *
     * @param mixed $pos
     * @return int|null
     */
    private function extractPositionTimestamp(mixed $pos): ?int
    {
        if (!is_array($pos)) {
            return null;
        }

        foreach (['opened_at', 'entered_at', 'last_updated_at', 'created_at', 'timestamp'] as $field) {
            if (!isset($pos[$field]) || $pos[$field] === '' || $pos[$field] === null) {
                continue;
            }
            $val = $pos[$field];
            if (is_int($val) && $val > 0) {
                return $val;
            }
            if (is_string($val) && $val !== '') {
                $ts = strtotime($val);
                if ($ts !== false && $ts > 0) {
                    return $ts;
                }
            }
        }

        return null;
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
     *   current_price → fetched via PriceProvider (centralized gateway, read-only);
     *                   _price_source = active_positions|bybit_gateway_readonly|unavailable
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
                $enrichmentStats['prices_from_position']++;
            }

            // Bybit price via PriceProvider — only when price is still missing
            $symbol = (string) ($pos['symbol'] ?? '');
            if (!$priceFound && $symbol !== '') {
                $fetchedPrice = $this->priceProvider()->getPrice($symbol);
                if ($fetchedPrice !== null) {
                    $pos['current_price'] = $fetchedPrice;
                    $diag                 = $this->priceProvider()->getLastDiagnostics();
                    $providerSource       = (string) ($diag['source'] ?? 'bybit_gateway_readonly');
                    $pos['_price_source'] = $providerSource;
                    if (isset($diag['price_field_used'])) {
                        $pos['_price_field_used'] = $diag['price_field_used'];
                    }
                    $priceFound = true;
                    $enrichmentStats['prices_from_gateway']++;
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
     * Lazy-initialise the shared PriceProvider instance.
     */
    private function priceProvider(): PriceProvider
    {
        if ($this->priceProvider === null) {
            $this->priceProvider = new PriceProvider();
        }
        return $this->priceProvider;
    }
}
