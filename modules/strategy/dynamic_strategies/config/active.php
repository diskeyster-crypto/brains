<?php

declare(strict_types=1);

/**
 * Dynamic Strategies — Active Config Overrides
 *
 * Written by the admin UI or manually.
 * Merged on top of base.php at runtime.
 *
 * Default state: enabled, demo mode, side_mode=short,
 * handoff_enabled=true, no live execution.
 */

return [
    'enabled'              => true,
    'mode'                 => 'demo',
    'side_mode'            => 'short',
    'handoff_enabled'      => true,
    'emit_bot_handoff'     => true,
    'live_enabled'         => false,
    'live_handoff_enabled' => false,
    // Backward-compat; deprecated; not used as execution gate
    'shadow_only'          => false,
];
