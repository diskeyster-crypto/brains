<?php
declare(strict_types=1);

/**
 * PatternEngine config loader.
 *
 * Reads pattern_engine.json and provides a typed config array.
 * All callers use PatternEngineConfig::load() — singleton, cached.
 */
final class PatternEngineConfig
{
    private const CONFIG_FILE = __DIR__ . '/pattern_engine.json';

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /**
     * Load and return the full config array.
     *
     * @return array<string,mixed>
     */
    public static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        if (!file_exists(self::CONFIG_FILE)) {
            self::$cache = self::defaults();
            return self::$cache;
        }

        $raw = file_get_contents(self::CONFIG_FILE);
        if ($raw === false) {
            self::$cache = self::defaults();
            return self::$cache;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::$cache = self::defaults();
            return self::$cache;
        }

        self::$cache = $decoded;
        return self::$cache;
    }

    /**
     * Save an updated config back to disk (used by settings UI).
     *
     * @param array<string,mixed> $config
     */
    public static function save(array $config): bool
    {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $ok          = (bool)file_put_contents(self::CONFIG_FILE, $json);
        self::$cache = null; // invalidate cache
        return $ok;
    }

    /** @return array<string,mixed> */
    private static function defaults(): array
    {
        return [
            'enabled'                    => true,
            'default_time_window_minutes' => 15,
            'detector_config'            => [],
            'scenario_profiles'          => [],
            'storage'                    => [
                'max_candidates_per_run' => 200,
                'max_signals_stored'     => 500,
                'max_scenarios_stored'   => 500,
                'candidate_ttl_hours'    => 24,
                'signal_ttl_hours'       => 48,
                'scenario_ttl_hours'     => 48,
            ],
        ];
    }
}
