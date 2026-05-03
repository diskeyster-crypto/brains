<?php

declare(strict_types=1);

/**
 * Dynamic Strategies — Active Config Overrides
 *
 * Written by the admin UI or manually.
 * Merged on top of base.php at runtime.
 *
 * Default state: enabled, demo mode, shadow_only=true, no handoff, no live.
 */

return [
    'enabled'         => true,
    'mode'            => 'demo',
    'shadow_only'     => true,
    'handoff_enabled' => false,
    'live_enabled'    => false,
    'emit_bot_handoff' => false,
];
