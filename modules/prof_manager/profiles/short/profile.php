<?php

declare(strict_types=1);

namespace Modules\ProfManager\Profiles\Short;

/**
 * ShortProfile — Stub
 *
 * Short positions are not yet supported.
 * Always returns unsupported_side_short regardless of position data.
 */
class ShortProfile
{
    /**
     * Process a short position (stub — always returns unsupported).
     *
     * @param array $position Normalized position (side = 'short')
     * @param int   $nowTs    Current unix timestamp
     * @return array {action, skip_reason, side_supported, profile_used, ...}
     */
    public function process(array $position, int $nowTs): array
    {
        return [
            'action'        => 'skip',
            'skip_reason'   => 'unsupported_side_short',
            'roi'           => null,
            'peak_roi'      => null,
            'lock_price'    => null,
            'lock_active'   => false,
            'profile_used'  => 'short_stub',
            'side_supported'=> false,
            'notes'         => [],
        ];
    }
}
