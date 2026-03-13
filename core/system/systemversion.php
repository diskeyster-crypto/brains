<?php
/**
 * Tredercopis Core - System Version Management
 * 
 * Provides version information and compatibility checking.
 */

declare(strict_types=1);

namespace Core\System;

final class SystemVersion
{
    private static ?self $instance = null;
    private string $version = '1.1.0';
    private array $meta = [];

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

    /**
     * Initialize version from config
     *
     * @param string $version Version string (e.g., '1.1.0')
     * @param array $meta Optional metadata
     */
    public function init(string $version, array $meta = []): void
    {
        $this->version = $version;
        $this->meta = $meta;
    }

    /**
     * Get current version string
     *
     * @return string
     */
    public function get(): string
    {
        return $this->version;
    }

    /**
     * Get full version info
     *
     * @return array
     */
    public function info(): array
    {
        $parts = $this->parse($this->version);
        
        return [
            'version' => $this->version,
            'major' => $parts['major'],
            'minor' => $parts['minor'],
            'patch' => $parts['patch'],
            'prerelease' => $parts['prerelease'],
            'meta' => $this->meta,
            'php_version' => PHP_VERSION,
            'core_name' => 'Tredercopis Core',
        ];
    }

    /**
     * Check if current version is compatible with requirement
     *
     * Supports:
     * - Exact: '1.0.0'
     * - Range: '>=1.0.0', '>1.0.0', '<=1.0.0', '<1.0.0'
     * - Caret: '^1.0' (>=1.0.0 <2.0.0)
     * - Tilde: '~1.0' (>=1.0.0 <1.1.0)
     *
     * @param string $requirement Version requirement
     * @return bool
     */
    public function isCompatible(string $requirement): bool
    {
        $requirement = trim($requirement);
        
        // Caret range: ^1.0 means >=1.0.0 <2.0.0
        if (str_starts_with($requirement, '^')) {
            $base = ltrim($requirement, '^');
            $parts = $this->parse($base);
            $min = "{$parts['major']}.{$parts['minor']}.{$parts['patch']}";
            $max = ($parts['major'] + 1) . ".0.0";
            return $this->compare($this->version, '>=', $min) 
                && $this->compare($this->version, '<', $max);
        }
        
        // Tilde range: ~1.0 means >=1.0.0 <1.1.0
        if (str_starts_with($requirement, '~')) {
            $base = ltrim($requirement, '~');
            $parts = $this->parse($base);
            $min = "{$parts['major']}.{$parts['minor']}.{$parts['patch']}";
            $max = "{$parts['major']}." . ($parts['minor'] + 1) . ".0";
            return $this->compare($this->version, '>=', $min) 
                && $this->compare($this->version, '<', $max);
        }
        
        // Operators: >=, >, <=, <, =
        foreach (['>=', '<=', '>', '<', '='] as $op) {
            if (str_starts_with($requirement, $op)) {
                $version = ltrim($requirement, $op . ' ');
                return $this->compare($this->version, $op, $version);
            }
        }
        
        // Exact match
        return $this->compare($this->version, '=', $requirement);
    }

    /**
     * Compare two versions
     *
     * @param string $v1 First version
     * @param string $op Operator (>=, >, <=, <, =)
     * @param string $v2 Second version
     * @return bool
     */
    private function compare(string $v1, string $op, string $v2): bool
    {
        $p1 = $this->parse($v1);
        $p2 = $this->parse($v2);
        
        $n1 = $p1['major'] * 10000 + $p1['minor'] * 100 + $p1['patch'];
        $n2 = $p2['major'] * 10000 + $p2['minor'] * 100 + $p2['patch'];
        
        return match ($op) {
            '>=' => $n1 >= $n2,
            '>' => $n1 > $n2,
            '<=' => $n1 <= $n2,
            '<' => $n1 < $n2,
            '=' => $n1 === $n2,
            default => false,
        };
    }

    /**
     * Parse version string into components
     *
     * @param string $version Version string
     * @return array{major: int, minor: int, patch: int, prerelease: string}
     */
    private function parse(string $version): array
    {
        $version = ltrim($version, 'v');
        $prerelease = '';
        
        if (str_contains($version, '-')) {
            [$version, $prerelease] = explode('-', $version, 2);
        }
        
        $parts = explode('.', $version);
        
        return [
            'major' => (int)($parts[0] ?? 0),
            'minor' => (int)($parts[1] ?? 0),
            'patch' => (int)($parts[2] ?? 0),
            'prerelease' => $prerelease,
        ];
    }
}

/* RULES
- Purpose: Version management and compatibility checking
- Config sources: config/system.php -> core_version
- Paths: None
- Logs: None
- Prohibitions:
  - NO hardcoded version outside config
*/
