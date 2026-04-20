<?php

declare(strict_types=1);

/**
 * Fish Strategy — Universe Builder
 *
 * Builds the list of symbols to scan for a given run cycle.
 *
 * Supported modes (v1):
 *   all         — load every active symbol from the parser1 market registry
 *   manual_list — use only `allowed_symbols` from config
 *
 * `excluded_symbols` is applied in both modes after the initial list is built.
 * Symbol comparison is case-insensitive during exclusion.
 */

namespace Modules\Strategy\Fish\Logic;

final class FishUniverse
{
    /**
     * Path to the Parser1 market registry active.json file.
     * Resolved relative to this module's directory at construction time.
     */
    private string $registryPath;

    public function __construct(string $moduleDir)
    {
        // Resolve registry relative to the project root (two levels up from modules/strategy/fish)
        $projectRoot        = dirname($moduleDir, 3);
        $this->registryPath = $projectRoot . '/modules/parser/parser1_market_registry/storage/active.json';
    }

    /**
     * Build the symbol list according to the config.
     *
     * @param  array  $config Effective merged config
     * @return array{symbols: list<string>, source: string, registry_total: int}
     */
    public function build(array $config): array
    {
        $mode            = (string)($config['universe_mode'] ?? 'all');
        $allowedSymbols  = (array)($config['allowed_symbols']  ?? []);
        $excludedSymbols = (array)($config['excluded_symbols'] ?? []);

        // Build exclusion set (uppercase)
        $excludeSet = [];
        foreach ($excludedSymbols as $s) {
            $excludeSet[strtoupper((string)$s)] = true;
        }

        if ($mode === 'manual_list') {
            $symbols = $this->applyExclusions($allowedSymbols, $excludeSet);
            return [
                'symbols'        => $symbols,
                'source'         => 'manual_list',
                'registry_total' => 0,
            ];
        }

        // mode === 'all': load from market registry
        $registry = $this->loadRegistry();
        $registryTotal = count($registry);

        // Extract symbol keys (the registry is keyed by symbol name)
        $all = array_keys($registry);
        $symbols = $this->applyExclusions($all, $excludeSet);

        return [
            'symbols'        => $symbols,
            'source'         => 'registry',
            'registry_total' => $registryTotal,
        ];
    }

    /**
     * Load active.json from the Parser1 market registry.
     * Returns an empty array if the file is missing or unreadable.
     *
     * @return array<string, mixed>  Symbol-keyed registry map
     */
    private function loadRegistry(): array
    {
        if (!file_exists($this->registryPath)) {
            return [];
        }

        $raw = file_get_contents($this->registryPath);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Remove excluded symbols from a list.
     *
     * @param  list<string>         $symbols    Input symbol list
     * @param  array<string, true>  $excludeSet Uppercase exclusion set
     * @return list<string>
     */
    private function applyExclusions(array $symbols, array $excludeSet): array
    {
        if (empty($excludeSet)) {
            return array_values($symbols);
        }

        $result = [];
        foreach ($symbols as $sym) {
            if (!isset($excludeSet[strtoupper((string)$sym)])) {
                $result[] = (string)$sym;
            }
        }
        return $result;
    }
}
