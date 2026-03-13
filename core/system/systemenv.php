<?php

declare(strict_types=1);

namespace Core\System;

final class SystemEnv
{
    private const VALID_ENVS = ['dev', 'development', 'test', 'testing', 'prod', 'production', 'live'];
    
    private static ?self $instance = null;
    private string $environment = 'dev';

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(string $env): void
    {
        if (!in_array($env, self::VALID_ENVS, true)) {
            throw new \InvalidArgumentException(
                "Invalid environment: {$env}. Valid: " . implode(', ', self::VALID_ENVS)
            );
        }
        $this->environment = $env;
        
        // Configure error display based on environment
        if ($this->isProd()) {
            error_reporting(0);
            ini_set('display_errors', '0');
        } else {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        }
    }

    public function set(string $env): void
    {
        $this->init($env);
    }

    public function get(): string
    {
        return $this->environment;
    }

    public function isDev(): bool
    {
        return $this->environment === 'dev' || $this->environment === 'development';
    }

    public function isTest(): bool
    {
        return $this->environment === 'test' || $this->environment === 'testing';
    }

    public function isProd(): bool
    {
        return $this->environment === 'prod' || $this->environment === 'production' || $this->environment === 'live';
    }

    public function isLive(): bool
    {
        return $this->isProd();
    }
}

/* RULES
- Purpose: Environment detection and management (dev/staging/live)
- Config sources: Set during System::init()
- Paths: None
- Logs: None
- Prohibitions:
  - NO hardcoded environment values outside init
*/
