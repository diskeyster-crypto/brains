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
    private string $storageDir;

    /** Path to rebuild status file */
    private const STATUS_FILE = 'status.json';

    public function __construct()
    {
        $this->storageDir   = __DIR__ . '/storage';
        $passportsDir       = $this->storageDir . '/passports';
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

    /**
     * Return last rebuild status (for UI display).
     *
     * @return array<string,mixed>
     */
    public function getStatus(): array
    {
        $path = $this->storageDir . '/' . self::STATUS_FILE;
        if (!file_exists($path)) {
            return [
                'last_rebuild_at'     => null,
                'last_rebuild_mode'   => null,
                'last_rebuild_status' => 'never',
                'last_rebuild_error'  => null,
                'last_updated_count'  => 0,
                'storage_path'        => $this->storageDir . '/passports',
            ];
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    // =========================================================================
    // Write / rebuild
    // =========================================================================

    /**
     * Rebuild passports for all symbols from available trade data.
     * Called by CronManager (coin_passport:rebuildAll) and from UI.
     *
     * @return array{updated:int,symbols:list<string>,errors:list<string>}
     */
    public function rebuildAll(): array
    {
        $result = $this->engine->rebuildAll();
        $this->saveStatus('rebuild_all', $result);
        return $result;
    }

    /**
     * Rebuild passports only for symbols that had recent trade activity
     * (closed in the last 7 days).
     * Called by CronManager (coin_passport:rebuildRecentSymbols).
     *
     * @return array{updated:int,symbols:list<string>,errors:list<string>}
     */
    public function rebuildRecentSymbols(): array
    {
        $cutoff     = time() - 7 * 86400;
        $closedDir  = __DIR__ . '/../trading_bot/storage/trades/closed';
        $recent     = [];

        if (is_dir($closedDir)) {
            foreach (glob($closedDir . '/*.json') ?: [] as $file) {
                $trade = json_decode((string)file_get_contents($file), true);
                if (!is_array($trade)) {
                    continue;
                }
                $symbol   = (string)($trade['symbol'] ?? '');
                $closedTs = (int)($trade['closed_ts'] ?? strtotime((string)($trade['closed_at'] ?? '')) ?: 0);
                if ($symbol !== '' && $closedTs >= $cutoff) {
                    $recent[$symbol] = true;
                }
            }
        }

        $result = ['updated' => 0, 'symbols' => [], 'errors' => []];

        foreach (array_keys($recent) as $symbol) {
            try {
                $this->engine->rebuildSymbol($symbol);
                $result['updated']++;
                $result['symbols'][] = $symbol;
            } catch (\Throwable $e) {
                $result['errors'][] = $symbol . ': ' . $e->getMessage();
            }
        }

        $this->saveStatus('rebuild_recent', $result);
        return $result;
    }

    /**
     * Rebuild passport for a single symbol.
     * Also called from trade-close hook for immediate update.
     *
     * @return array<string,mixed>
     */
    public function rebuildSymbol(string $symbol): array
    {
        $passport = $this->engine->rebuildSymbol(strtoupper($symbol));
        $this->saveStatus('rebuild_symbol:' . strtoupper($symbol), [
            'updated' => 1,
            'symbols' => [strtoupper($symbol)],
            'errors'  => [],
        ]);
        return $passport;
    }

    // =========================================================================
    // Status persistence
    // =========================================================================

    /**
     * Persist rebuild status to storage/status.json.
     *
     * @param array{updated:int,symbols:list<string>,errors:list<string>} $result
     */
    private function saveStatus(string $mode, array $result): void
    {
        $path = $this->storageDir . '/' . self::STATUS_FILE;
        $status = [
            'last_rebuild_at'     => date('Y-m-d H:i:s'),
            'last_rebuild_mode'   => $mode,
            'last_rebuild_status' => empty($result['errors']) ? 'ok' : 'partial_error',
            'last_rebuild_error'  => empty($result['errors']) ? null : implode('; ', $result['errors']),
            'last_updated_count'  => (int)($result['updated'] ?? 0),
            'storage_path'        => $this->storageDir . '/passports',
        ];
        @file_put_contents($path, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
