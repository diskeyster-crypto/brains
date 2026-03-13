<?php
declare(strict_types=1);

/**
 * Parser4 Analyzer — Smart Brain Market Structure Analyzer
 *
 * Phase 3: Real implementation.
 *
 * Reads Parser3 symbols + Parser2 price history.
 * Outputs corridor/volatility/strength/trend candidates.
 *
 * Does NOT generate trading signals.
 */
final class Parser4Analyzer
{
    /** @var array<string,mixed> */
    private array $cfg;
    private StateManager $state;

    /**
     * @param array<string,mixed> $cfg  Effective settings from config/parser4.php
     * @param StateManager $state
     */
    public function __construct(array $cfg, StateManager $state)
    {
        $this->cfg = $cfg;
        $this->state = $state;
    }

    /**
     * Run the analyzer pipeline.
     *
     * 1. Load symbols from Parser3 profiles directory
     * 2. For each symbol load latest Parser2 NDJSON history
     * 3. Calculate market structure (corridor, volatility, strength, trend)
     * 4. Filter and sort candidates
     * 5. Write storage/candidates.json
     *
     * @return array<int,array<string,mixed>>
     */
    public function run(): array
    {
        if (($this->cfg['enabled'] ?? true) !== true) {
            return [];
        }

        $minHistoryPoints = (int)($this->cfg['min_history_points'] ?? 40);
        $strengthThreshold = (float)($this->cfg['strength_threshold'] ?? 0.50);
        $maxCandidates = (int)($this->cfg['max_candidates'] ?? 200);

        $symbols = $this->loadSymbols();

        if ($symbols === []) {
            $this->state->writeJson('storage/candidates.json', []);
            return [];
        }

        $candidates = [];

        foreach ($symbols as $symbol) {
            $history = $this->loadHistory($symbol);

            if (count($history) < $minHistoryPoints) {
                continue;
            }

            $corridor = $this->calculateCorridor($history);
            $volatility = $this->calculateVolatility($history);

            if ($volatility <= 0.0) {
                continue;
            }

            $strength = $this->calculateStrength($corridor['width'], $volatility);

            if ($strength < $strengthThreshold) {
                continue;
            }

            $trendBias = $this->calculateTrend($history);
            $lastPrice = $history[count($history) - 1]['price'];

            $candidates[] = [
                'symbol' => $symbol,
                'corridor_low' => $corridor['low'],
                'corridor_high' => $corridor['high'],
                'corridor_width' => $corridor['width'],
                'volatility' => $volatility,
                'strength' => $strength,
                'trend_bias' => $trendBias,
                'history_points' => count($history),
                'last_price' => $lastPrice,
            ];
        }

        // Sort by strength descending
        usort($candidates, function (array $a, array $b): int {
            return $b['strength'] <=> $a['strength'];
        });

        // Apply max_candidates limit
        if (count($candidates) > $maxCandidates) {
            $candidates = array_slice($candidates, 0, $maxCandidates);
        }

        $this->state->writeJson('storage/candidates.json', $candidates);

        return $candidates;
    }

    // ------------------------------------------------------------------
    // Symbol loading
    // ------------------------------------------------------------------

    /**
     * Load symbol list from Parser3 profiles directory.
     * Symbols are derived from filenames: {profiles_dir}/profiles/*.json
     *
     * @return array<int,string>
     */
    private function loadSymbols(): array
    {
        $profilesKey = (string)($this->cfg['profiles_key'] ?? '');

        if ($profilesKey === '') {
            return [];
        }

        try {
            $storageDir = \Core\System\SystemPaths::instance()->get($profilesKey);
        } catch (\Throwable $e) {
            return [];
        }

        $profilesDir = $storageDir . '/profiles';

        if (!is_dir($profilesDir)) {
            return [];
        }

        $files = scandir($profilesDir);
        if ($files === false) {
            return [];
        }

        $symbols = [];

        foreach ($files as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            if (substr($f, -5) === '.json') {
                $symbol = substr($f, 0, -5);
                if ($symbol !== '') {
                    $symbols[] = $symbol;
                }
            }
        }

        return $symbols;
    }

    // ------------------------------------------------------------------
    // History loading
    // ------------------------------------------------------------------

    /**
     * Load price history for a single symbol from Parser2 NDJSON files.
     * Uses the latest .ndjson file in {history_dir}/{SYMBOL}/
     *
     * Supports two NDJSON line formats:
     *   Format A (flat):  {"ts":"...","ts_unix":123,"price":"0.12122"}
     *   Format B (Bybit): {"ts":"...","ts_unix":123,"data":{"lastPrice":"0.12122",...}}
     *
     * @return array<int,array{ts_unix:int,price:float}>
     */
    private function loadHistory(string $symbol): array
    {
        $historyKey = (string)($this->cfg['history_key'] ?? '');

        if ($historyKey === '') {
            return [];
        }

        try {
            $storageDir = \Core\System\SystemPaths::instance()->get($historyKey);
        } catch (\Throwable $e) {
            return [];
        }

        $symbolDir = $storageDir . '/' . $symbol;

        if (!is_dir($symbolDir)) {
            return [];
        }

        $files = scandir($symbolDir);
        if ($files === false) {
            return [];
        }

        $ndjsonFiles = [];
        foreach ($files as $f) {
            if (substr($f, -7) === '.ndjson') {
                $ndjsonFiles[] = $f;
            }
        }

        if ($ndjsonFiles === []) {
            return [];
        }

        sort($ndjsonFiles);

        // Use latest .ndjson file
        $latestFile = $symbolDir . '/' . $ndjsonFiles[count($ndjsonFiles) - 1];

        return $this->parseNdjson($latestFile);
    }

    /**
     * Parse an NDJSON file line by line (safe streaming read).
     *
     * @return array<int,array{ts_unix:int,price:float}>
     */
    private function parseNdjson(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return [];
        }

        $history = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $data = json_decode($line, true);
                if (!is_array($data)) {
                    continue;
                }

                $price = $this->extractPrice($data);
                if ($price <= 0.0) {
                    continue;
                }

                $history[] = [
                    'ts_unix' => (int)($data['ts_unix'] ?? 0),
                    'price' => $price,
                ];
            }
        } finally {
            fclose($handle);
        }

        return $history;
    }

    /**
     * Extract numeric price from a ticker row.
     *
     * Format A (flat):  {"price":"0.12122"}
     * Format B (Bybit): {"data":{"lastPrice":"0.12122","markPrice":"..."}}
     */
    private function extractPrice(array $row): float
    {
        // Format A — flat price field
        if (isset($row['price'])) {
            $p = (float)$row['price'];
            if ($p > 0.0) {
                return $p;
            }
        }

        // Format B — nested Bybit data.lastPrice
        if (isset($row['data']) && is_array($row['data'])) {
            if (isset($row['data']['lastPrice'])) {
                $p = (float)$row['data']['lastPrice'];
                if ($p > 0.0) {
                    return $p;
                }
            }
        }

        return 0.0;
    }

    // ------------------------------------------------------------------
    // Market structure calculations
    // ------------------------------------------------------------------

    /**
     * @param array<int,array{ts_unix:int,price:float}> $history
     * @return array{low:float,high:float,width:float}
     */
    private function calculateCorridor(array $history): array
    {
        $low = PHP_FLOAT_MAX;
        $high = 0.0;

        foreach ($history as $point) {
            $price = $point['price'];
            if ($price < $low) {
                $low = $price;
            }
            if ($price > $high) {
                $high = $price;
            }
        }

        $width = 0.0;
        if ($low > 0.0) {
            $width = ($high / $low) - 1.0;
        }

        return [
            'low' => round($low, 8),
            'high' => round($high, 8),
            'width' => round($width, 6),
        ];
    }

    /**
     * Calculate volatility as standard deviation of returns.
     *
     * @param array<int,array{ts_unix:int,price:float}> $history
     */
    private function calculateVolatility(array $history): float
    {
        $returns = [];

        for ($i = 1, $n = count($history); $i < $n; $i++) {
            $prev = $history[$i - 1]['price'];
            $curr = $history[$i]['price'];
            if ($prev > 0.0) {
                $returns[] = ($curr / $prev) - 1.0;
            }
        }

        return round($this->stddev($returns), 6);
    }

    /**
     * @param array<int,float> $values
     */
    private function stddev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $n;
        $variance = 0.0;

        foreach ($values as $v) {
            $variance += ($v - $mean) * ($v - $mean);
        }

        return sqrt($variance / ($n - 1));
    }

    /**
     * strength = corridor_width / volatility
     */
    private function calculateStrength(float $corridorWidth, float $volatility): float
    {
        if ($volatility <= 0.0) {
            return 0.0;
        }

        return round($corridorWidth / $volatility, 6);
    }

    /**
     * Determine trend bias from first and last price.
     *
     * @param array<int,array{ts_unix:int,price:float}> $history
     */
    private function calculateTrend(array $history): string
    {
        $first = $history[0]['price'];
        $last = $history[count($history) - 1]['price'];

        if ($last > $first) {
            return 'up';
        }
        if ($last < $first) {
            return 'down';
        }

        return 'flat';
    }
}
