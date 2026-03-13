<?php
/**
 * ConfigGuard - Centralized Config Protection Service
 * 
 * This service provides safe config saving with schema validation,
 * type checking, and atomic writes to prevent config corruption.
 * 
 * ARCHITECTURAL RULE:
 * In Tredercopis, direct config.php saves are FORBIDDEN.
 * All modules MUST use ConfigGuard with schema files.
 */

class ConfigGuard
{
    private static $logDir = null;
    
    /**
     * Initialize log directory
     */
    private static function initLogDir(): string
    {
        if (self::$logDir === null) {
            self::$logDir = __DIR__ . '/logs';
            if (!is_dir(self::$logDir)) {
                @mkdir(self::$logDir, 0755, true);
            }
        }
        return self::$logDir;
    }
    
    /**
     * Log a message
     */
    private static function log(string $level, string $message, array $context = []): void
    {
        $logDir = self::initLogDir();
        $logFile = $logDir . '/configguard.log';
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $line = "[{$timestamp}] [{$level}] {$message}{$contextStr}\n";
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Load config with optional schema merging
     * 
     * @param string $path Path to config.php
     * @return array Config array or empty array on failure
     */
    public static function load(string $path): array
    {
        if (!file_exists($path)) {
            self::log('warning', 'Config file not found', ['path' => $path]);
            return [];
        }
        
        $config = include $path;
        if (!is_array($config)) {
            self::log('error', 'Config is not an array', ['path' => $path]);
            return [];
        }
        
        return $config;
    }
    
    /**
     * Get schema path for a config file
     * 
     * @param string $configPath Path to config.php
     * @return string|null Path to schema.php or null if not found
     */
    public static function getSchema(string $configPath): ?string
    {
        $dir = dirname($configPath);
        
        // Check for schema.php in same directory
        $schemaPath = $dir . '/schema.php';
        if (file_exists($schemaPath)) {
            return $schemaPath;
        }
        
        // Check for default.php as fallback
        $defaultPath = $dir . '/default.php';
        if (file_exists($defaultPath)) {
            return $defaultPath;
        }
        
        return null;
    }
    
    /**
     * Load schema for a config
     * 
     * @param string $configPath Path to config.php
     * @return array Schema array or empty array if not found
     */
    public static function loadSchema(string $configPath): array
    {
        $schemaPath = self::getSchema($configPath);
        if ($schemaPath === null) {
            return [];
        }
        
        $schema = include $schemaPath;
        if (!is_array($schema)) {
            self::log('error', 'Schema is not an array', ['path' => $schemaPath]);
            return [];
        }
        
        return $schema;
    }
    
    /**
     * Save config with schema protection
     * 
     * @param string $path Path to config.php
     * @param array $input New values from UI/API
     * @return bool Success
     */
    public static function save(string $path, array $input): bool
    {
        // Load schema (source of truth for structure)
        $schema = self::loadSchema($path);
        
        // Load current config
        $current = self::load($path);
        
        // If no schema, use current config as base structure
        $base = !empty($schema) ? $schema : $current;
        
        // Deep merge: base <- current <- input
        $merged = self::merge($base, $current, $input);
        
        // Validate types if schema exists
        if (!empty($schema)) {
            $errors = self::validate($schema, $merged);
            if (!empty($errors)) {
                self::log('warning', 'Validation errors during save', [
                    'path' => $path,
                    'errors' => $errors
                ]);
                // Still save, but with corrected types
            }
        }
        
        // Atomic write
        return self::atomicWrite($path, $merged);
    }
    
    /**
     * Deep merge three arrays: default <- current <- input
     * 
     * Rules:
     * 1. Keys from default define the structure
     * 2. Current values override default
     * 3. Input values override current (only for keys that exist)
     * 4. Missing keys in input are preserved from current
     * 5. Protected sections are never overwritten from input
     * 
     * @param array $default Schema/default values
     * @param array $current Current config values
     * @param array $input New values from UI
     * @return array Merged config
     */
    public static function merge(array $default, array $current, array $input): array
    {
        $result = [];
        
        // Protected sections that should never be overwritten by UI input
        // CRITICAL: These keys are system-level and must NOT be modified via UI
        $protectedSections = [
            'sources',      // Data sources configuration
            'output',       // Output configuration
            'ui',           // UI field definitions
            'systempaths',  // System paths configuration
            'paths',        // Path configurations
            'internal',     // Internal system settings
            'profiles',     // Complex sensitivity profile configurations
            '_schema',      // Schema metadata
            '_meta',        // Internal metadata
        ];
        
        // Start with all keys from default
        $allKeys = array_unique(array_merge(
            array_keys($default),
            array_keys($current)
        ));
        
        foreach ($allKeys as $key) {
            $defaultValue = $default[$key] ?? null;
            $currentValue = $current[$key] ?? $defaultValue;
            $inputValue = $input[$key] ?? null;
            
            // Protected sections: always use current value
            if (in_array($key, $protectedSections, true)) {
                $result[$key] = $currentValue;
                continue;
            }
            
            // Check for corruption markers
            if (is_string($inputValue) && strpos($inputValue, '[object Object]') !== false) {
                self::log('warning', 'Detected corruption marker in input', ['key' => $key]);
                $result[$key] = $currentValue;
                continue;
            }
            
            // Both are associative arrays: recurse
            if (self::isAssociativeArray($defaultValue) && self::isAssociativeArray($currentValue)) {
                $inputForRecurse = self::isAssociativeArray($inputValue) ? $inputValue : [];
                $result[$key] = self::merge(
                    $defaultValue ?? [],
                    $currentValue ?? [],
                    $inputForRecurse
                );
                continue;
            }
            
            // Input has a value for this key
            if (array_key_exists($key, $input) && $inputValue !== null) {
                // Convert types based on default/current type
                $result[$key] = self::convertType($inputValue, $defaultValue ?? $currentValue);
            } else {
                // No input, use current value (or default if no current)
                $result[$key] = $currentValue;
            }
        }
        
        return $result;
    }
    
    /**
     * Convert a value to match the type of the reference value
     */
    private static function convertType($value, $reference)
    {
        if ($reference === null) {
            return $value;
        }
        
        $type = gettype($reference);
        
        switch ($type) {
            case 'boolean':
                if (is_string($value)) {
                    // Strict boolean conversion - only accept known boolean strings
                    $lower = strtolower(trim($value));
                    if (in_array($lower, ['true', '1', 'yes', 'on'], true)) {
                        return true;
                    }
                    if (in_array($lower, ['false', '0', 'no', 'off', ''], true)) {
                        return false;
                    }
                    // Unknown string - keep original reference value
                    return $reference;
                }
                return (bool)$value;
                
            case 'integer':
                if (is_numeric($value)) {
                    return (int)$value;
                }
                return $reference; // Keep original if invalid
                
            case 'double':
                if (is_numeric($value)) {
                    return (float)$value;
                }
                return $reference; // Keep original if invalid
                
            case 'array':
                if (is_array($value)) {
                    // If reference is a sequential array of numbers, convert values
                    if (self::isNumericArray($reference)) {
                        return array_map([self::class, 'parseNumericValue'], $value);
                    }
                    return $value;
                }
                // Try to parse as comma-separated string
                if (is_string($value) && strpos($value, ',') !== false) {
                    $parts = array_map('trim', explode(',', $value));
                    if (self::isNumericArray($reference)) {
                        return array_map([self::class, 'parseNumericValue'], $parts);
                    }
                    return $parts;
                }
                return $reference; // Keep original if can't convert
                
            case 'string':
            default:
                return (string)$value;
        }
    }
    
    /**
     * Parse a value as a numeric type (int or float)
     * @param mixed $value Value to parse
     * @return mixed Parsed numeric value or original value if not numeric
     */
    private static function parseNumericValue($value)
    {
        if (!is_numeric($value)) {
            return $value;
        }
        // Check if value contains decimal point or is a float
        $numericValue = $value + 0;
        return is_float($numericValue) ? (float)$value : (int)$value;
    }
    
    /**
     * Check if array is associative (has string keys)
     */
    private static function isAssociativeArray($value): bool
    {
        if (!is_array($value) || empty($value)) {
            return false;
        }
        return array_keys($value) !== range(0, count($value) - 1);
    }
    
    /**
     * Check if array is a sequential array of numbers
     */
    private static function isNumericArray($value): bool
    {
        if (!is_array($value) || empty($value)) {
            return false;
        }
        foreach ($value as $v) {
            if (!is_numeric($v)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Validate config against schema types
     * 
     * @param array $schema Schema with type definitions
     * @param array $data Data to validate
     * @return array List of validation errors
     */
    public static function validate(array $schema, array &$data): array
    {
        $errors = [];
        
        foreach ($schema as $key => $schemaValue) {
            if (!array_key_exists($key, $data)) {
                // Key missing, use schema default
                $data[$key] = $schemaValue;
                continue;
            }
            
            $dataValue = $data[$key];
            $expectedType = gettype($schemaValue);
            $actualType = gettype($dataValue);
            
            // Skip null schema values
            if ($schemaValue === null) {
                continue;
            }
            
            // Recurse for associative arrays
            if (self::isAssociativeArray($schemaValue) && is_array($dataValue)) {
                $subErrors = self::validate($schemaValue, $data[$key]);
                foreach ($subErrors as $subKey => $subError) {
                    $errors["{$key}.{$subKey}"] = $subError;
                }
                continue;
            }
            
            // Type mismatch: try to convert
            if ($expectedType !== $actualType) {
                $converted = self::convertType($dataValue, $schemaValue);
                if (gettype($converted) === $expectedType) {
                    $data[$key] = $converted;
                } else {
                    $errors[$key] = "Expected {$expectedType}, got {$actualType}";
                    $data[$key] = $schemaValue; // Use schema default
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * Atomically write config to file
     * 
     * @param string $path Target file path
     * @param array $config Config to write
     * @return bool Success
     */
    private static function atomicWrite(string $path, array $config): bool
    {
        $tmpPath = $path . '.tmp.' . getmypid();
        
        // Format config as PHP
        $content = "<?php\n\nreturn " . self::exportArray($config, 0) . ";\n";
        
        // Write to temp file
        if (file_put_contents($tmpPath, $content, LOCK_EX) === false) {
            self::log('error', 'Failed to write temp file', ['path' => $tmpPath]);
            return false;
        }
        
        // Atomic rename
        if (!rename($tmpPath, $path)) {
            self::log('error', 'Failed to rename temp file', [
                'tmp' => $tmpPath,
                'target' => $path
            ]);
            @unlink($tmpPath);
            return false;
        }
        
        // Clear opcode cache for the file
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
        
        self::log('info', 'Config saved successfully', ['path' => $path]);
        return true;
    }
    
    /**
     * Export array to PHP code with proper formatting
     */
    private static function exportArray(array $array, int $indent): string
    {
        $isAssoc = self::isAssociativeArray($array);
        $items = [];
        $indentStr = str_repeat('    ', $indent);
        $innerIndent = str_repeat('    ', $indent + 1);
        
        foreach ($array as $key => $value) {
            $keyStr = $isAssoc ? var_export($key, true) . ' => ' : '';
            
            if (is_array($value)) {
                if (empty($value)) {
                    $valueStr = '[]';
                } elseif (!self::isAssociativeArray($value) && self::isSimpleArray($value)) {
                    // Simple sequential array on one line
                    $valueStr = '[' . implode(', ', array_map(function($v) {
                        return var_export($v, true);
                    }, $value)) . ']';
                } else {
                    $valueStr = self::exportArray($value, $indent + 1);
                }
            } else {
                $valueStr = var_export($value, true);
            }
            
            $items[] = $innerIndent . $keyStr . $valueStr;
        }
        
        if (empty($items)) {
            return '[]';
        }
        
        return "[\n" . implode(",\n", $items) . ",\n" . $indentStr . ']';
    }
    
    /**
     * Check if array is simple (contains only scalars)
     */
    private static function isSimpleArray(array $array): bool
    {
        foreach ($array as $value) {
            if (is_array($value) || is_object($value)) {
                return false;
            }
        }
        return count($array) <= 10; // Keep short arrays on one line
    }
}
