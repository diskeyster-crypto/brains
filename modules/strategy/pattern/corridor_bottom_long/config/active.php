<?php

declare(strict_types=1);

/**
 * Corridor Bottom Long — Active Config Overrides
 *
 * Demo validation strategy.
 * It is enabled for storage/demo observation, but bot handoff is disabled by default.
 */

return [
    'enabled'         => true,
    'mode'            => 'demo',
    'handoff_enabled' => false,
];
