<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/passport_engine.php';

/**
 * CoinPassportService
 *
 * Service layer for the Coin Passport module.
 * Delegates computation to CoinPassportEngine.
 *
 * Persistent storage:  modules/system/coin_passport/storage/passports/
 * Trade data source:   modules/system/trading_bot/storage/
 */
final class CoinPassportService
{
    private CoinPassportEngine $engine;

    public function __construct()
    {
        $passportsDir       = __DIR__ . '/storage/passports';
        $tradingBotStorage  = __DIR__ . '/../trading_bot/storage';

        $this->engine = new CoinPassportEngine($passportsDir, $tradingBotStorage);
    }

    // =========================================================================
    // Read
    // =========================================================================

    /**
     * Return all passports as sorted array.
     *
     * @return array{passports:array<string,array<string,mixed>>,count:int}
     */
    public function getAllPassports(): array
    {
        $passports = $this->engine->loadAll();
        return [
            'passports' => $passports,
            'count'     => count($passports),
        ];
    }

    /**
     * Return a single passport by symbol (null if not found).
     *
     * @return array<string,mixed>|null
     */
    public function getPassport(string $symbol): ?array
    {
        return $this->engine->load(strtoupper($symbol));
    }

    // =========================================================================
    // Write / rebuild
    // =========================================================================

    /**
     * Rebuild passports for all symbols from available trade data.
     *
     * @return array{updated:int,symbols:list<string>,errors:list<string>}
     */
    public function rebuildAll(): array
    {
        return $this->engine->rebuildAll();
    }

    /**
     * Rebuild passport for a single symbol.
     *
     * @return array<string,mixed>
     */
    public function rebuildSymbol(string $symbol): array
    {
        return $this->engine->rebuildSymbol(strtoupper($symbol));
    }

    // =========================================================================
    // API read path for future Brain/Bot integration
    // =========================================================================

    /**
     * Return a minimal guidance block for Brain/Bot to consume.
     * Intended for future integration — read-only, no authority yet.
     *
     * @return array<string,mixed>
     */
    public function getGuidanceForSymbol(string $symbol): array
    {
        $passport = $this->engine->load(strtoupper($symbol));
        if ($passport === null) {
            return [
                'symbol'           => strtoupper($symbol),
                'available'        => false,
                'data_confidence'  => 'none',
            ];
        }

        return [
            'symbol'                                => $passport['symbol'],
            'available'                             => true,
            'data_confidence'                       => $passport['data_confidence'],
            'sample_size'                           => $passport['sample_size'],
            'corridor_low_roi'                      => $passport['corridor_low_roi'],
            'corridor_mid_roi'                      => $passport['corridor_mid_roi'],
            'corridor_high_roi'                     => $passport['corridor_high_roi'],
            'recommended_guaranteed_lock_start_roi' => $passport['recommended_guaranteed_lock_start_roi'],
            'recommended_guaranteed_lock_value_roi' => $passport['recommended_guaranteed_lock_value_roi'],
            'recommended_stage1_threshold_roi'      => $passport['recommended_stage1_threshold_roi'],
            'recommended_stage2_threshold_roi'      => $passport['recommended_stage2_threshold_roi'],
            'recommended_ladder_mode'               => $passport['recommended_ladder_mode'],
            'recommended_harvest_aggressiveness'    => $passport['recommended_harvest_aggressiveness'],
            'runner_probability'                    => $passport['runner_probability'],
            'updated_at'                            => $passport['updated_at'],
        ];
    }
}
