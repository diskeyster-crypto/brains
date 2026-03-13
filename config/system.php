<?php

declare(strict_types=1);

return [
    'name' => 'Tredercopis Core',
    'core_version' => '1.3.0',
    'env' => 'dev',
    'debug' => true,
    'timezone' => 'UTC',
    'locale' => 'en',
    
    // Docroot mode: 'public' | 'root' | 'auto'
    // - 'public': Document root is /public directory (recommended)
    // - 'root': Document root is project root (legacy)
    // - 'auto': Auto-detect based on SCRIPT_NAME (fallback)
    'docroot_mode' => 'auto',
];

/* RULES
- Purpose: Core system configuration
- Config sources: This is the source
- Paths: None
- Logs: None
- Prohibitions:
  - NO secrets or credentials here
*/
