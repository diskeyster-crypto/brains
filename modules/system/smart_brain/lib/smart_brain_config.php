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
}
