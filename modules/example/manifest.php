<?php
/**
 * Example Module Manifest
 * 
 * Every module MUST have a manifest.php file that defines:
 * - name: Module identifier (lowercase, no spaces)
 * - version: Semantic version (x.y.z)
 * - description: Human-readable description
 * - entry: Main entry point file
 * - requires: Array of required modules/services
 * 
 * @package Modules\Example
 */

return [
    'name' => 'example',
    'category' => 'other',
    'version' => '1.0.0',
    'description' => 'An example module demonstrating the module system',
    'entry' => 'runner.php',
    'requires' => [
        'core' => '>=1.0.0',
    ],
    
    // Optional fields
    'author' => 'Tredercopis Core',
    'cron' => [
        'execute' => [
            'handler' => 'runner.php',
            'schedule' => '60',
        ],
    ],
    'routes' => [],
];

/* RULES
- Purpose: Define module metadata and requirements
- Config sources: This file IS the config
- Paths: Entry and handler paths relative to module directory
- Logs: None (metadata only)
- Prohibitions:
  - No PHP code execution (return array only)
  - Name must be lowercase
  - Entry file must exist
  - Required modules must be available
*/
