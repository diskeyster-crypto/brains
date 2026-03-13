<?php
declare(strict_types=1);

final class SmartBrainConfig
{
    private string $moduleBase;
    /** @var array<string,mixed> */
    private array $config;

    public function __construct(string $moduleBase)
    {
        $this->moduleBase = rtrim($moduleBase, '/');
        $this->config = require $this->moduleBase . '/config/config.php';
        $this->applyOverrides();
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * @return mixed
     */
    public function get(string $section, $default = null)
    {
        return $this->config[$section] ?? $default;
    }

    /**
     * Get effective settings for a section (merges mode-based structure).
     * If section has mode/settings/auto_rules, returns settings.
     * Otherwise returns section as-is.
     *
     * @return array<string,mixed>
     */
    public function getEffective(string $section): array
    {
        $data = $this->config[$section] ?? [];
        if (!is_array($data)) {
            return [];
        }
        if (isset($data['settings']) && is_array($data['settings'])) {
            return $data['settings'];
        }
        return $data;
    }

    /**
     * Apply runtime overrides from config_overrides/*.json
     */
    private function applyOverrides(): void
    {
        $overrideDir = $this->moduleBase . '/runtime/config_overrides';
        if (!is_dir($overrideDir)) {
            return;
        }

        $files = glob($overrideDir . '/*.json');
        if (!$files) {
            return;
        }

        foreach ($files as $file) {
            $section = pathinfo($file, PATHINFO_FILENAME);
            $overrideData = json_decode((string)file_get_contents($file), true);
            if (!is_array($overrideData) || !isset($this->config[$section])) {
                continue;
            }

            if (isset($this->config[$section]['settings']) && is_array($this->config[$section]['settings'])) {
                $this->config[$section]['settings'] = array_merge(
                    $this->config[$section]['settings'],
                    $overrideData
                );
            } else {
                $this->config[$section] = array_merge(
                    $this->config[$section],
                    $overrideData
                );
            }
        }
    }
}
