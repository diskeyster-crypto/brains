<?php

declare(strict_types=1);

namespace Modules\ProfManager\Lib;

/**
 * PriceProvider
 *
 * Fetches current market price for a symbol using the centralized Bybit gateway.
 *
 * ── SAFETY CONTRACT ──────────────────────────────────────────────────────────
 * Profit Manager may use the centralized exchange client ONLY for read-only
 * market data (market.tickers, signed=false).
 * Paper mode forbids trading actions, not read-only price access.
 *
 * Profit Manager must NEVER call trading mutation endpoints in paper mode.
 *
 * Hard-forbidden methods inside modules/prof_manager:
 *   - submitOrder / createOrder / cancelOrder
 *   - closePosition / updatePositionStops / setTradingStop
 *   - any private endpoint that mutates positions or orders
 *
 * Profit Manager does NOT create its own separate Bybit HTTP client.
 * It uses only the existing centralized Core\Gateway\Bybit connection model.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class PriceProvider
{
    /** Per-instance price cache: symbol → float (0.0 = unavailable) */
    private array $priceCache = [];

    /** Diagnostics from the last getPrice() call */
    private array $lastDiagnostics = [];

    /** Accumulated provider error (first non-null error encountered in this instance) */
    private ?string $providerError = null;

    /** Last resolved source name */
    private string $providerSource = 'none';

    /**
     * Get the current market price for a symbol.
     *
     * Uses the centralized Core\Gateway\Bybit client (read-only, market.tickers,
     * signed=false — no API key required).
     * Profit Manager does not create its own Bybit HTTP client.
     *
     * @param string $symbol e.g. "BTCUSDT"
     * @return float|null  null when price cannot be obtained
     */
    public function getPrice(string $symbol): ?float
    {
        if (array_key_exists($symbol, $this->priceCache)) {
            $v = $this->priceCache[$symbol];
            return $v > 0.0 ? $v : null;
        }

        $price      = null;
        $source     = 'none';
        $error      = null;
        $priceField = null;

        // ── Try centralized gateway (read-only public call) ───────────────
        try {
            [$price, $priceField] = $this->fetchViaGateway($symbol);
            if ($price !== null) {
                $source = 'bybit_gateway_readonly';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if ($error !== null && $this->providerError === null) {
            $this->providerError = $error;
        }
        if ($price !== null) {
            $this->providerSource = $source;
        }

        $this->lastDiagnostics = [
            'source'           => $source,
            'error'            => $error,
            'price_field_used' => $priceField,
            'symbol'           => $symbol,
        ];

        $this->priceCache[$symbol] = $price ?? 0.0;
        return $price;
    }

    /**
     * Diagnostics from the last getPrice() call.
     *
     * @return array{source: string, error: string|null, price_field_used: string|null, symbol: string}
     */
    public function getLastDiagnostics(): array
    {
        return $this->lastDiagnostics;
    }

    /**
     * Accumulated provider error (first encountered in this instance lifetime).
     */
    public function getProviderError(): ?string
    {
        return $this->providerError;
    }

    /**
     * The price source used for the most recent successful fetch.
     */
    public function getProviderSource(): string
    {
        return $this->providerSource;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Fetch via centralized Core\Gateway\Bybit (read-only public endpoint).
     *
     * Uses market.tickers with signed=false — no API key or signature required.
     * The gateway is accessed via Bybit::client('default') which initialises the
     * connection model shared with the bot, but only the unsigned path is used.
     *
     * @return array{0: float|null, 1: string|null}  [price, priceField]
     * @throws \RuntimeException when the gateway class is absent or returns an error
     */
    private function fetchViaGateway(string $symbol): array
    {
        if (!class_exists('\\Core\\Gateway\\Bybit')) {
            throw new \RuntimeException('Core\\Gateway\\Bybit not available');
        }

        // Client may throw if config/bybit.php is missing required keys
        $client = \Core\Gateway\Bybit::client('default');

        // signed=false → unsigned headers only, no API key required
        $resp = $client->request('market.tickers', [
            'category' => 'linear',
            'symbol'   => $symbol,
        ], false);

        if (!($resp['success'] ?? false)) {
            throw new \RuntimeException(
                'Gateway ticker error: ' . ($resp['ret_msg'] ?? 'unknown') .
                ' [' . ($resp['error_type'] ?? '') . ']'
            );
        }

        $list = $resp['result']['list'] ?? [];
        if (!is_array($list) || empty($list)) {
            return [null, null];
        }

        return $this->extractPrice($list[0]);
    }

    /**
     * Extract lastPrice → markPrice → indexPrice from a Bybit v5 ticker entry.
     *
     * @param mixed $ticker
     * @return array{0: float|null, 1: string|null}  [price, fieldName]
     */
    private function extractPrice(mixed $ticker): array
    {
        if (!is_array($ticker)) {
            return [null, null];
        }

        foreach (['lastPrice', 'markPrice', 'indexPrice'] as $field) {
            $v = (float) ($ticker[$field] ?? 0.0);
            if ($v > 0.0) {
                return [$v, $field];
            }
        }

        return [null, null];
    }
}
