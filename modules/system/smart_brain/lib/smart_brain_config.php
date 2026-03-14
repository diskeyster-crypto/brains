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
     * Get user limits from risk_engine config.
     *
     * @return array<string,mixed>
     */
    public function getUserLimits(): array
    {
        $riskEngine = $this->config['risk_engine'] ?? [];
        return (array)($riskEngine['user_limits'] ?? []);
    }

    /**
     * Compute brain auto (derived) values from current config state.
     *
     * @return array<string,mixed>
     */
    public function getBrainAutoValues(): array
    {
        $corridorCfg = $this->getEffective('corridor');
        $riskCfg = $this->getEffective('risk_engine');
        $profilesCfg = $this->getEffective('profiles');
        $userLimits = $this->getUserLimits();

        $profileKey = (string)($profilesCfg['default_profile'] ?? '111');
        $profile = (array)($profilesCfg['profiles'][$profileKey] ?? []);

        $parser4Cfg = $this->getEffective('parser4');

        return [
            'effective_strength_threshold' => (float)($parser4Cfg['strength_threshold'] ?? 0.50),
            'effective_entry_zone_percent' => (float)($corridorCfg['entry_zone_percent'] ?? 0.20),
            'effective_budget_scaler' => (float)($profile['budget'] ?? 15.0),
            'effective_leverage_scaler' => (int)($profile['max_leverage'] ?? 5),
            'effective_reliability_gate' => (float)($userLimits['min_reliability_after_warmup'] ?? 0.15),
        ];
    }

    /**
     * Build full effective config snapshot for runtime output.
     *
     * @return array<string,mixed>
     */
    public function buildEffectiveSnapshot(): array
    {
        return [
            'user_limits' => $this->getUserLimits(),
            'brain_auto' => $this->getBrainAutoValues(),
            'parser4' => $this->getEffective('parser4'),
            'corridor' => $this->getEffective('corridor'),
            'risk_engine' => $this->getEffective('risk_engine'),
            'profiles' => $this->getEffective('profiles'),
            'simulator' => $this->getEffective('simulator'),
            'ui' => $this->getEffective('ui'),
            'generated_at' => date('c'),
        ];
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
