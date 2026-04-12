<?php
declare(strict_types=1);

/**
 * Unified Config Module — Default Configuration
 *
 * CONFIG FIRST / ZERO-HARDCODE
 * This module is a shadow system — read-only extraction and preview.
 * It does NOT govern any runtime module's behaviour.
 *
 * @return array<string,mixed>
 */
return [

    /* ======================================================
       MODULE CORE
       ====================================================== */
    'module' => [
        'enabled'     => true,
        'description' => 'Unified Config shadow module — extraction and preview only',
    ],

    /* ======================================================
       EXTRACTION SOURCES
       Paths are resolved via SystemPaths at runtime.
       Source keys match SystemPaths module keys.
       ====================================================== */
    'sources' => [
        'smart_brain'    => 'system.smart_brain',
        'trading_bot'    => 'system.trading_bot',
        'profit_manager' => 'system.profit_manager',
        'coin_passport'  => 'system.coin_passport',
    ],

    /* ======================================================
       AUDIT ENGINE
       ====================================================== */
    'audit' => [
        // Re-extract on every cron pass (or only when manually triggered)
        'auto_extract_on_cron' => true,

        // If true, log audit results to runtime/audit.log
        'audit_logging_enabled' => true,
    ],

    /* ======================================================
       STORAGE (relative to module base)
       ====================================================== */
    'storage' => [
        'ownership_map_file'      => 'storage/runtime/config_ownership_map.json',
        'conflict_report_file'    => 'storage/runtime/config_conflict_report.json',
        'operational_draft_file'  => 'storage/runtime/config_operational_draft.json',
        'immutable_draft_file'    => 'storage/runtime/config_immutable_draft.json',
        'effective_preview_file'  => 'storage/runtime/config_effective_preview.json',
        'last_extract_file'       => 'storage/runtime/last_extract.json',
    ],

    /* ======================================================
       UI
       ====================================================== */
    'ui' => [
        'read_only' => true,
        'show_source_ownership' => true,
        'show_conflicts'        => true,
        'show_field_type'       => true,   // operational vs immutable
    ],

];
