<?php
declare(strict_types=1);

/**
 * Coin Passport Module Bootstrap
 *
 * Loads service and controller for the coin_passport module.
 * This module is the single source of truth for per-symbol coin passports.
 * Smart Brain acts as UI/control-plane only; all passport storage and
 * computation lives here.
 */

$__coinPassportBase = __DIR__;

require_once $__coinPassportBase . '/service.php';
require_once $__coinPassportBase . '/controller.php';

// ---------------------------------------------------------------------------
// One-time data migration: copy any legacy Brain passport files that do not
// yet exist in the standalone coin_passport storage so no history is lost.
// ---------------------------------------------------------------------------
(static function (string $base): void {
    $legacyDir    = $base . '/../smart_brain/storage/passports';
    $standaloneDir = $base . '/storage/passports';

    if (!is_dir($legacyDir)) {
        return;
    }

    if (!is_dir($standaloneDir)) {
        @mkdir($standaloneDir, 0755, true);
    }

    foreach (glob($legacyDir . '/*.json') ?: [] as $src) {
        $dst = $standaloneDir . '/' . basename($src);
        if (!file_exists($dst)) {
            @copy($src, $dst);
        }
    }
})($__coinPassportBase);
