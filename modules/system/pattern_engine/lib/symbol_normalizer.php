<?php
declare(strict_types=1);

namespace PatternEngine;

if (defined('PATTERN_ENGINE_SYMBOL_NORMALIZER_LOADED')) {
    return;
}
define('PATTERN_ENGINE_SYMBOL_NORMALIZER_LOADED', true);

/**
 * SymbolNormalizer
 *
 * Normalizes trading symbols for cross-module compatibility between:
 *   - Pattern Engine
 *   - Coin Passport
 *   - Trading Bot / Demo Execution
 *   - AI Shadow
 *
 * Bybit linear perpetuals use BASEUSDT (e.g. BTCUSDT, ETHUSDT).
 * This normalizer maps common symbol variants to that canonical form.
 *
 * Outputs per signal:
 *   symbol_raw                  string  Original symbol as received
 *   symbol_normalized           string  After stripping expiry, underscores, etc.
 *   symbol_canonical            string  Best canonical form (validated vs passport universe)
 *   symbol_normalization_status string  unchanged|normalized|normalized_no_passport|normalized_fallback_to_original|failed
 *   symbol_normalization_reason string  Human-readable reason
 */
final class SymbolNormalizer
{
    /** @var list<string> */
    private array $passportSymbols = [];

    /**
     * @param string $passportDir  Path to coin_passport/storage/passports/
     */
    public function __construct(string $passportDir)
    {
        $this->passportSymbols = $this->loadPassportSymbols($passportDir);
    }

    /**
     * Normalize a raw symbol string.
     *
     * @return array{
     *   symbol_raw: string,
     *   symbol_normalized: string,
     *   symbol_canonical: string,
     *   symbol_normalization_status: string,
     *   symbol_normalization_reason: string,
     * }
     */
    public function normalize(string $symbol): array
    {
        if ($symbol === '') {
            return $this->result('', '', '', 'failed', 'empty_symbol');
        }

        $raw   = $symbol;
        $upper = strtoupper(trim($symbol));

        // Step 1: Strip futures expiry suffixes e.g. -25MAR26, -27JUN25, -03APR26
        $stripped = (string)preg_replace('/-\d{2}[A-Z]{3}\d{2,4}$/', '', $upper);

        // Step 2: Remove underscores and dashes within the base symbol (BTC_USDT → BTCUSDT)
        $stripped = str_replace(['_', '-'], '', $stripped);

        // Step 3: PERP suffix → replace with USDT (BTCPERP → BTCUSDT)
        if (str_ends_with($stripped, 'PERP')) {
            $stripped = substr($stripped, 0, -4) . 'USDT';
        }

        $normalized = $stripped;

        // Determine baseline status/reason before passport check
        if ($normalized === $upper) {
            $status = 'unchanged';
            $reason = 'already_uppercase_canonical';
        } else {
            $status = 'normalized';
            $reason = 'stripped_expiry_or_separator';
        }

        $canonical = $normalized;

        // Step 4: Validate against passport universe
        if (!empty($this->passportSymbols)) {
            $inPassport = in_array($canonical, $this->passportSymbols, true);

            if ($inPassport) {
                // Canonical form found in passport
                $status = ($canonical === $upper) ? 'unchanged' : 'normalized';
                $reason = ($canonical === $upper) ? 'already_canonical_in_passport' : 'normalized_found_in_passport';
            } else {
                // Try original uppercase — maybe the raw form is already the correct key
                if (in_array($upper, $this->passportSymbols, true)) {
                    $canonical = $upper;
                    $status    = 'normalized_fallback_to_original';
                    $reason    = 'canonical_not_in_passport_falling_back_to_original';
                } else {
                    // Neither form found in passport universe
                    $status = 'normalized_no_passport';
                    $reason = 'normalized_but_no_passport_found';
                }
            }
        }

        return $this->result($raw, $normalized, $canonical, $status, $reason);
    }

    /**
     * Return the loaded passport symbol universe (for diagnostics/testing).
     *
     * @return list<string>
     */
    public function getPassportSymbols(): array
    {
        return $this->passportSymbols;
    }

    // -------------------------------------------------------------------------

    /** @return list<string> */
    private function loadPassportSymbols(string $passportDir): array
    {
        if (!is_dir($passportDir)) {
            return [];
        }
        $files = glob($passportDir . '/*.json') ?: [];
        return array_values(array_map(
            static fn(string $f): string => strtoupper(basename($f, '.json')),
            $files
        ));
    }

    /**
     * @return array{
     *   symbol_raw: string,
     *   symbol_normalized: string,
     *   symbol_canonical: string,
     *   symbol_normalization_status: string,
     *   symbol_normalization_reason: string,
     * }
     */
    private function result(
        string $raw,
        string $normalized,
        string $canonical,
        string $status,
        string $reason
    ): array {
        return [
            'symbol_raw'                  => $raw,
            'symbol_normalized'           => $normalized,
            'symbol_canonical'            => $canonical,
            'symbol_normalization_status' => $status,
            'symbol_normalization_reason' => $reason,
        ];
    }
}
