<?php
declare(strict_types=1);

namespace Modules\System\Config\Lib;

/**
 * ConfigAuditEngine — reads existing module configs and builds unified draft artefacts.
 *
 * SHADOW ONLY:
 *  - NEVER writes to other modules' config files.
 *  - NEVER mutates runtime state of Smart Brain / Trading Bot / PM / Coin Passport.
 *  - Produces five draft artefacts stored in modules/system/config/storage/runtime/.
 *
 * Audit coverage:
 *  - Smart Brain   : config/risk_engine.php + runtime/user_config.json + runtime/effective_config.json
 *  - Trading Bot   : config/config.php + config/bot.json
 *  - Profit Manager: config/config.php (proxy — reads Trading Bot config)
 *  - Coin Passport : manifest.php (no dedicated config file)
 *
 * Output artefacts:
 *  1. config_ownership_map.json    — which parameter belongs to which file/module
 *  2. config_conflict_report.json  — parameters that appear in multiple places with different values
 *  3. config_operational_draft.json — user-facing, frequently-changed parameters
 *  4. config_immutable_draft.json   — internal/technical parameters (not for routine changes)
 *  5. config_effective_preview.json — effective config per module as the system currently sees it
 */
final class ConfigAuditEngine
{
    private string $moduleBase;
    private array  $config;
    private array  $errors = [];

    // Source module base directories (resolved at extract time)
    private string $brainBase       = '';
    private string $botBase         = '';
    private string $pmBase          = '';
    private string $passportBase    = '';

    public function __construct(string $moduleBase, array $config)
    {
        $this->moduleBase = rtrim($moduleBase, '/');
        $this->config     = $config;
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Run the full extraction pass.
     *
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     ownership_map: array<string,mixed>,
     *     conflict_report: array<string,mixed>,
     *     operational_draft: array<string,mixed>,
     *     immutable_draft: array<string,mixed>,
     *     effective_preview: array<string,mixed>,
     * }
     */
    public function extract(): array
    {
        $this->errors = [];
        $this->resolveSourcePaths();

        $rawSources = $this->loadAllSources();

        $ownershipMap     = $this->buildOwnershipMap($rawSources);
        $conflictReport   = $this->buildConflictReport($ownershipMap);
        $operationalDraft = $this->buildOperationalDraft($rawSources);
        $immutableDraft   = $this->buildImmutableDraft($rawSources);
        $effectivePreview = $this->buildEffectivePreview($rawSources);

        return [
            'ok'               => empty($this->errors),
            'errors'           => $this->errors,
            'ownership_map'    => $ownershipMap,
            'conflict_report'  => $conflictReport,
            'operational_draft'=> $operationalDraft,
            'immutable_draft'  => $immutableDraft,
            'effective_preview'=> $effectivePreview,
        ];
    }

    // =========================================================================
    // Source resolution
    // =========================================================================

    private function resolveSourcePaths(): void
    {
        // Resolve sibling module directories relative to this module's parent.
        // Falls back to direct __DIR__-relative paths so the module works even
        // before SystemPaths finishes bootstrapping (e.g., CLI/cron cold-start).
        $systemDir = dirname($this->moduleBase);

        $this->brainBase    = $systemDir . '/smart_brain';
        $this->botBase      = $systemDir . '/trading_bot';
        $this->pmBase       = $systemDir . '/profit_manager';
        $this->passportBase = $systemDir . '/coin_passport';

        // Try SystemPaths for more reliable resolution when available
        try {
            $paths = \Core\System\SystemPaths::instance();
            $keys  = $this->config['sources'] ?? [];

            $candidates = [
                'brainBase'    => ['smart_brain',    $keys['smart_brain']    ?? ''],
                'botBase'      => ['trading_bot',    $keys['trading_bot']    ?? ''],
                'pmBase'       => ['profit_manager', $keys['profit_manager'] ?? ''],
                'passportBase' => ['coin_passport',  $keys['coin_passport']  ?? ''],
            ];

            foreach ($candidates as $prop => [$fallbackSuffix, $key]) {
                if ($key === '') {
                    continue;
                }
                try {
                    $p = $paths->get($key);
                    if (is_string($p) && $p !== '' && is_dir($p)) {
                        $this->$prop = $p;
                    }
                } catch (\Throwable $e) {
                    // keep sibling-relative fallback
                }
            }
        } catch (\Throwable $e) {
            // SystemPaths not available — sibling paths already set above
        }
    }

    // =========================================================================
    // Raw source loading
    // =========================================================================

    /**
     * Load all raw config files from each module.
     *
     * @return array<string,array<string,mixed>> keyed by source name
     */
    private function loadAllSources(): array
    {
        return [
            'brain_risk_engine'    => $this->loadPhpConfig($this->brainBase . '/config/risk_engine.php'),
            'brain_user_config'    => $this->loadJsonFile($this->brainBase . '/runtime/user_config.json'),
            'brain_effective'      => $this->loadJsonFile($this->brainBase . '/runtime/effective_config.json'),
            'bot_config'           => $this->loadPhpConfig($this->botBase  . '/config/config.php'),
            'bot_runtime'          => $this->loadJsonFile($this->botBase   . '/config/bot.json'),
            'pm_config'            => $this->loadPhpConfig($this->pmBase   . '/config/config.php'),
            'passport_manifest'    => $this->loadPhpConfig($this->passportBase . '/manifest.php'),
        ];
    }

    /**
     * Load and return a PHP config file (returns array, or empty on failure).
     *
     * @return array<string,mixed>
     */
    private function loadPhpConfig(string $path): array
    {
        if (!is_file($path)) {
            $this->errors[] = "Config file not found: {$path}";
            return [];
        }
        try {
            $data = require $path;
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            $this->errors[] = "Failed to load {$path}: " . $e->getMessage();
            return [];
        }
    }

    /**
     * Load and JSON-decode a JSON file (returns array, or empty on failure).
     *
     * @return array<string,mixed>
     */
    private function loadJsonFile(string $path): array
    {
        if (!is_file($path)) {
            $this->errors[] = "JSON file not found: {$path}";
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            $this->errors[] = "Could not read: {$path}";
            return [];
        }
        $data = @json_decode($raw, true);
        if (!is_array($data)) {
            $this->errors[] = "Invalid JSON in: {$path}";
            return [];
        }
        return $data;
    }

    // =========================================================================
    // Ownership map
    // =========================================================================

    /**
     * Build the ownership map: for each parameter key, list all sources that
     * define it and which value they provide.
     *
     * @param array<string,array<string,mixed>> $sources
     * @return array<string,mixed>
     */
    private function buildOwnershipMap(array $sources): array
    {
        $ts   = date('c');
        $map  = [];

        // Flatten parameters from each source into the map
        foreach ($this->parameterInventory() as $entry) {
            $key    = $entry['key'];
            $source = $entry['source'];
            $path   = $entry['path'];        // dot-notation path into the source array
            $type   = $entry['type'];        // 'operational' | 'immutable'
            $notes  = $entry['notes'] ?? '';

            $value   = $this->dotGet($sources[$source] ?? [], $path);
            $defined = ($value !== null);

            if (!isset($map[$key])) {
                $map[$key] = [
                    'key'        => $key,
                    'type'       => $type,
                    'notes'      => $notes,
                    'sources'    => [],
                    'primary'    => null,
                    'conflict'   => false,
                    'unused'     => true,
                ];
            }

            if ($defined) {
                $map[$key]['sources'][] = [
                    'source' => $source,
                    'path'   => $path,
                    'value'  => $value,
                    'file'   => $this->sourceFile($source),
                ];
                $map[$key]['unused'] = false;

                // First defined source wins as primary (highest precedence first)
                if ($map[$key]['primary'] === null) {
                    $map[$key]['primary'] = $source;
                }
            }
        }

        // Flag conflicts (same key, multiple sources, values differ)
        foreach ($map as $key => &$entry) {
            if (count($entry['sources']) > 1) {
                $firstVal = $entry['sources'][0]['value'];
                foreach ($entry['sources'] as $src) {
                    if ($src['value'] !== $firstVal) {
                        $entry['conflict'] = true;
                        break;
                    }
                }
            }
        }
        unset($entry);

        // Attach migration_readiness status to each parameter
        $legacySources = ['bot_config', 'brain_risk_engine', 'brain_effective', 'pm_config', 'passport_manifest'];
        foreach ($map as $key => &$entry) {
            if ($entry['unused']) {
                $entry['migration_readiness'] = 'blocked_by_missing_owner';
            } elseif ($entry['conflict']) {
                $entry['migration_readiness'] = 'blocked_by_conflict';
            } else {
                // Check if all sources are legacy (no runtime override)
                $sourceNames = array_column($entry['sources'], 'source');
                $hasRuntimeOwner = !empty(array_diff($sourceNames, $legacySources));
                $entry['migration_readiness'] = $hasRuntimeOwner
                    ? 'ready_for_soft_switch'
                    : 'blocked_by_legacy_dependency';
            }
        }
        unset($entry);

        return [
            'generated_at' => $ts,
            'parameters'   => array_values($map),
        ];
    }

    // =========================================================================
    // Conflict report
    // =========================================================================

    /**
     * Build a concise conflict report from the ownership map.
     *
     * @param array<string,mixed> $ownershipMap
     * @return array<string,mixed>
     */
    private function buildConflictReport(array $ownershipMap): array
    {
        $winReasonMap = [
            'brain_user_config' => 'User override — highest runtime precedence',
            'brain_effective'   => 'Effective merged runtime snapshot',
            'bot_runtime'       => 'Bot runtime config (bot.json override)',
            'bot_config'        => 'Bot static config.php (lowest operational precedence)',
            'brain_risk_engine' => 'Risk engine coefficients (immutable)',
            'pm_config'         => 'Profit Manager config.php',
            'passport_manifest' => 'Coin Passport manifest',
        ];

        $conflicts = [];
        foreach ($ownershipMap['parameters'] ?? [] as $entry) {
            if (!($entry['conflict'] ?? false)) {
                continue;
            }
            $primarySrc = $entry['primary'] ?? 'unknown';
            $conflicts[] = [
                'key'              => $entry['key'],
                'type'             => $entry['type'],
                'primary'          => $primarySrc,
                'win_reason'       => $winReasonMap[$primarySrc] ?? 'First defined source wins',
                'migration_target' => ($entry['type'] === 'immutable') ? 'immutable_internal' : 'operational_master',
                'sources'          => array_map(static fn($s) => [
                    'source' => $s['source'],
                    'file'   => $s['file'],
                    'value'  => $s['value'],
                ], $entry['sources']),
            ];
        }

        // Summarise duplicates (same key, same value — not a conflict but worth noting)
        $duplicates = [];
        foreach ($ownershipMap['parameters'] ?? [] as $entry) {
            if (($entry['conflict'] ?? false) || count($entry['sources'] ?? []) < 2) {
                continue;
            }
            $duplicates[] = [
                'key'              => $entry['key'],
                'type'             => $entry['type'],
                'migration_target' => ($entry['type'] === 'immutable') ? 'immutable_internal' : 'operational_master',
                'sources'          => array_column($entry['sources'], 'source'),
            ];
        }

        return [
            'generated_at'        => date('c'),
            'conflict_count'      => count($conflicts),
            'duplicate_count'     => count($duplicates),
            'conflicts'           => $conflicts,
            'duplicates'          => $duplicates,
        ];
    }

    // =========================================================================
    // Operational draft
    // =========================================================================

    /**
     * Build the operational master config draft from the highest-precedence source
     * for each operational parameter.
     *
     * @param array<string,array<string,mixed>> $sources
     * @return array<string,mixed>
     */
    private function buildOperationalDraft(array $sources): array
    {
        $params = [];

        // Precedence order for operational params:
        //   1. brain_user_config (runtime overrides — highest)
        //   2. brain_effective   (merged effective)
        //   3. bot_runtime       (bot.json)
        //   4. bot_config        (static config.php)
        //   5. brain_risk_engine (risk_engine.php)
        $precedence = ['brain_user_config', 'brain_effective', 'bot_runtime', 'bot_config', 'brain_risk_engine'];

        foreach ($this->operationalSchema() as $key => $def) {
            $resolved      = null;
            $resolvedFrom  = null;
            $resolvedPath  = null;
            $allValues     = [];

            foreach ($precedence as $srcName) {
                $srcPath = $def['sources'][$srcName] ?? null;
                if ($srcPath === null) {
                    continue;
                }
                $val = $this->dotGet($sources[$srcName] ?? [], $srcPath);
                if ($val !== null) {
                    $allValues[$srcName] = $val;
                    if ($resolved === null) {
                        $resolved     = $val;
                        $resolvedFrom = $srcName;
                        $resolvedPath = $srcPath;
                    }
                }
            }

            $params[$key] = [
                'key'           => $key,
                'label'         => $def['label'],
                'type'          => 'operational',
                'value'         => $resolved,
                'source'        => $resolvedFrom,
                'source_path'   => $resolvedPath,
                'source_file'   => $resolvedFrom !== null ? $this->sourceFile($resolvedFrom) : null,
                'all_values'    => $allValues,
                'notes'         => $def['notes'] ?? '',
            ];
        }

        return [
            'generated_at' => date('c'),
            'params'       => $params,
        ];
    }

    // =========================================================================
    // Immutable draft
    // =========================================================================

    /**
     * Build the immutable/internal config draft.
     *
     * @param array<string,array<string,mixed>> $sources
     * @return array<string,mixed>
     */
    private function buildImmutableDraft(array $sources): array
    {
        $params = [];

        foreach ($this->immutableSchema() as $key => $def) {
            $resolved     = null;
            $resolvedFrom = null;

            // Immutable params have a single primary source
            foreach ($def['sources'] as $srcName => $srcPath) {
                $val = $this->dotGet($sources[$srcName] ?? [], $srcPath);
                if ($val !== null) {
                    $resolved     = $val;
                    $resolvedFrom = $srcName;
                    break;
                }
            }

            $params[$key] = [
                'key'         => $key,
                'label'       => $def['label'],
                'type'        => 'immutable',
                'value'       => $resolved,
                'source'      => $resolvedFrom,
                'source_file' => $resolvedFrom !== null ? $this->sourceFile($resolvedFrom) : null,
                'notes'       => $def['notes'] ?? '',
            ];
        }

        return [
            'generated_at' => date('c'),
            'params'       => $params,
        ];
    }

    // =========================================================================
    // Effective preview
    // =========================================================================

    /**
     * Build the per-module effective config preview.
     * These are the values each module currently operates under (read from their
     * effective/runtime files where available).
     *
     * @param array<string,array<string,mixed>> $sources
     * @return array<string,mixed>
     */
    private function buildEffectivePreview(array $sources): array
    {
        $brainEff = $sources['brain_effective'] ?? [];
        $botRt    = $sources['bot_runtime']     ?? [];
        $botCfg   = $sources['bot_config']      ?? [];

        // Smart Brain migration status — read live from brain runtime artifact.
        // This is separate from rawSources (which are captured at extract time);
        // config_source_status.json is written on every Smart Brain run.
        $migrationStatus = [];
        $migStatusPath   = $this->brainBase . '/runtime/config_source_status.json';
        if (is_file($migStatusPath)) {
            $raw = @file_get_contents($migStatusPath);
            if ($raw !== false) {
                $decoded = @json_decode($raw, true);
                if (is_array($decoded)) {
                    $migrationStatus = $decoded;
                }
            }
        }

        // Smart Brain effective config block
        $brainPreview = [
            'live_trading'      => $brainEff['live_trading']       ?? null,
            'leverage_control'  => $brainEff['leverage_control']   ?? null,
            'stop_control'      => $brainEff['stop_control']        ?? null,
            'exit_policy'       => $brainEff['exit_policy']         ?? null,
            'pattern_selection' => $brainEff['pattern_selection']   ?? null,
            'execution_profile' => $brainEff['execution_profile']   ?? null,
            'symbol_intelligence'=> $brainEff['symbol_intelligence'] ?? null,
            'migration_status'  => empty($migrationStatus) ? null : [
                'switch_wave'              => $migrationStatus['switch_wave']              ?? null,
                'partially_migrated'       => $migrationStatus['partially_migrated']       ?? false,
                'unified_config_available' => $migrationStatus['unified_config_available'] ?? false,
                'migrated_count'           => $migrationStatus['migrated_count']           ?? 0,
                'fallback_count'           => $migrationStatus['fallback_count']           ?? 0,
                'first_wave_total'         => $migrationStatus['first_wave_total']         ?? 0,
                'switched_params'          => $migrationStatus['switched_params']          ?? [],
                'fallback_params'          => $migrationStatus['fallback_params']          ?? [],
                'source'                   => $migrationStatus['source']                   ?? 'legacy_user_config',
                'recorded_at'              => $migrationStatus['recorded_at']              ?? null,
            ],
        ];

        // Trading Bot effective (merge static config.php with bot.json overrides)
        $botPreview = [
            'module'    => array_merge(
                (array)($botCfg['module']    ?? []),
                (array)($botRt['module']     ?? [])
            ),
            'execution' => array_merge(
                (array)($botCfg['execution'] ?? []),
                (array)($botRt['execution']  ?? [])
            ),
            'exchange'  => array_merge(
                (array)($botCfg['exchange']  ?? []),
                (array)($botRt['exchange']   ?? [])
            ),
        ];

        // Profit Manager effective (it reads from bot config)
        $pmCfg = $sources['pm_config'] ?? [];
        $pmPreview = [
            'module'    => $pmCfg['module']    ?? null,
            'execution' => $pmCfg['execution'] ?? null,
        ];

        // Coin Passport — no dedicated effective config
        $passportPreview = [
            'note' => 'Coin Passport has no dedicated config file. Operational params come from Smart Brain and Trading Bot.',
        ];

        return [
            'generated_at'  => date('c'),
            'modules'       => [
                'smart_brain'    => $brainPreview,
                'trading_bot'    => $botPreview,
                'profit_manager' => $pmPreview,
                'coin_passport'  => $passportPreview,
            ],
        ];
    }

    // =========================================================================
    // Parameter inventory (full list of known parameters)
    // =========================================================================

    /**
     * Full parameter inventory used to build the ownership map.
     * Each entry maps a logical key to a source + dot-notation path.
     *
     * @return list<array{key:string,source:string,path:string,type:string,notes:string}>
     */
    private function parameterInventory(): array
    {
        return [
            // ── Smart Brain — live trading ─────────────────────────────────
            ['key' => 'live_trading_enabled',      'source' => 'brain_user_config', 'path' => 'live_trading_enabled',      'type' => 'operational', 'notes' => 'Master live trading on/off switch'],
            ['key' => 'live_trading_enabled',      'source' => 'brain_effective',   'path' => 'live_trading.live_trading_enabled', 'type' => 'operational', 'notes' => ''],
            ['key' => 'live_max_positions',        'source' => 'brain_user_config', 'path' => 'live_max_positions',        'type' => 'operational', 'notes' => 'Max concurrent live positions'],
            ['key' => 'live_max_positions',        'source' => 'brain_effective',   'path' => 'live_trading.live_max_positions', 'type' => 'operational', 'notes' => ''],
            ['key' => 'live_signal_selection_mode','source' => 'brain_user_config', 'path' => 'live_signal_selection_mode','type' => 'operational', 'notes' => 'Signal selection mode (all/sniper/etc)'],
            ['key' => 'live_signal_selection_mode','source' => 'brain_effective',   'path' => 'live_trading.live_signal_selection_mode', 'type' => 'operational', 'notes' => ''],
            ['key' => 'live_one_trade_per_symbol', 'source' => 'brain_user_config', 'path' => 'live_one_trade_per_symbol', 'type' => 'operational', 'notes' => 'One trade per symbol constraint'],
            ['key' => 'live_one_trade_per_symbol', 'source' => 'brain_effective',   'path' => 'live_trading.live_one_trade_per_symbol', 'type' => 'operational', 'notes' => ''],
            ['key' => 'live_entry_policy',         'source' => 'brain_user_config', 'path' => 'live_entry_policy',         'type' => 'operational', 'notes' => 'Live entry policy (enter_now/wait_retrace)'],
            ['key' => 'live_reverse_side_enabled', 'source' => 'brain_user_config', 'path' => 'live_reverse_side_enabled', 'type' => 'operational', 'notes' => 'Invert long↔short before live emit'],

            // ── Smart Brain — leverage ─────────────────────────────────────
            ['key' => 'leverage_mode',             'source' => 'brain_user_config', 'path' => 'leverage_mode',             'type' => 'operational', 'notes' => 'manual | auto'],
            ['key' => 'manual_leverage',           'source' => 'brain_user_config', 'path' => 'manual_leverage',           'type' => 'operational', 'notes' => 'Fixed leverage when leverage_mode=manual'],
            ['key' => 'manual_leverage',           'source' => 'brain_effective',   'path' => 'leverage_control.manual_leverage', 'type' => 'operational', 'notes' => ''],
            ['key' => 'max_leverage',              'source' => 'brain_user_config', 'path' => 'max_leverage',              'type' => 'operational', 'notes' => 'Hard cap on leverage'],

            // ── Smart Brain — budget ───────────────────────────────────────
            ['key' => 'max_budget_per_coin',       'source' => 'brain_user_config', 'path' => 'max_budget_per_coin',       'type' => 'operational', 'notes' => 'Max USDT per symbol (risk block)'],

            // ── Smart Brain — stop control ─────────────────────────────────
            ['key' => 'stop_control_mode',         'source' => 'brain_user_config', 'path' => 'stop_control_mode',         'type' => 'operational', 'notes' => 'auto | entry_roi'],
            ['key' => 'stop_control_mode',         'source' => 'brain_effective',   'path' => 'stop_control.stop_control_mode', 'type' => 'operational', 'notes' => ''],
            ['key' => 'stop_loss_from_entry_roi',  'source' => 'brain_user_config', 'path' => 'stop_loss_from_entry_roi',  'type' => 'operational', 'notes' => 'SL distance from entry in ROI terms'],
            ['key' => 'stop_loss_from_entry_roi',  'source' => 'brain_effective',   'path' => 'stop_control.stop_loss_from_entry_roi', 'type' => 'operational', 'notes' => ''],

            // ── Smart Brain — trailing ─────────────────────────────────────
            ['key' => 'trailing_enabled',          'source' => 'brain_user_config', 'path' => 'trailing_enabled',          'type' => 'operational', 'notes' => 'Brain-side trailing toggle'],
            ['key' => 'trailing_enabled',          'source' => 'bot_runtime',       'path' => 'execution.trailing_enabled', 'type' => 'operational', 'notes' => 'Bot-side trailing toggle'],
            ['key' => 'trailing_mode',             'source' => 'brain_user_config', 'path' => 'trailing_mode',             'type' => 'operational', 'notes' => 'price_distance_floor | fixed_roi_ladder | etc'],
            ['key' => 'trailing_activation_roi',   'source' => 'brain_user_config', 'path' => 'trailing_activation_roi',   'type' => 'operational', 'notes' => 'ROI at which trailing activates'],
            ['key' => 'trailing_activation_floor_roi', 'source' => 'brain_user_config', 'path' => 'trailing_activation_floor_roi', 'type' => 'operational', 'notes' => 'Floor-lock activation ROI'],
            ['key' => 'trailing_floor_lock_roi',   'source' => 'brain_user_config', 'path' => 'trailing_floor_lock_roi',   'type' => 'operational', 'notes' => 'Minimum ROI to lock in'],

            // ── Smart Brain — break-even ───────────────────────────────────
            ['key' => 'break_even_enabled',        'source' => 'brain_user_config', 'path' => 'break_even_enabled',        'type' => 'operational', 'notes' => 'Break-even stop toggle'],
            ['key' => 'break_even_enabled',        'source' => 'bot_runtime',       'path' => 'execution.break_even_enabled', 'type' => 'operational', 'notes' => ''],
            ['key' => 'break_even_activation_roi', 'source' => 'brain_user_config', 'path' => 'break_even_activation_roi', 'type' => 'operational', 'notes' => 'ROI threshold for break-even'],

            // ── Smart Brain — patterns ─────────────────────────────────────
            ['key' => 'patterns_enabled',          'source' => 'brain_user_config', 'path' => 'patterns.enabled',          'type' => 'operational', 'notes' => 'List of enabled pattern algorithms'],
            ['key' => 'patterns_mode',             'source' => 'brain_user_config', 'path' => 'patterns.mode',             'type' => 'operational', 'notes' => 'any | all'],
            ['key' => 'execution_profile',         'source' => 'brain_user_config', 'path' => 'execution_profile',         'type' => 'operational', 'notes' => 'balanced | sniper_lite | sniper_75_attempt | etc'],

            // ── Trading Bot — module ───────────────────────────────────────
            ['key' => 'bot_mode',                  'source' => 'bot_runtime',       'path' => 'module.mode',               'type' => 'operational', 'notes' => 'live | demo | paper'],
            ['key' => 'bot_mode',                  'source' => 'bot_config',        'path' => 'module.mode',               'type' => 'operational', 'notes' => ''],
            ['key' => 'bot_enabled',               'source' => 'bot_runtime',       'path' => 'enabled',                   'type' => 'operational', 'notes' => 'Bot on/off switch'],
            ['key' => 'max_intents_per_run',       'source' => 'bot_runtime',       'path' => 'execution.max_intents_per_run', 'type' => 'operational', 'notes' => 'Max new live opens per cron tick'],
            ['key' => 'max_intents_per_run',       'source' => 'bot_config',        'path' => 'execution.max_intents_per_run', 'type' => 'operational', 'notes' => ''],
            ['key' => 'max_concurrent_positions',  'source' => 'bot_runtime',       'path' => 'max_positions',             'type' => 'operational', 'notes' => 'Bot-side concurrent position cap'],
            ['key' => 'bot_brain_controlled',      'source' => 'bot_runtime',       'path' => 'sources.brain_source_enabled', 'type' => 'operational', 'notes' => 'Brain-controlled mode flag'],

            // ── Profit Manager — core ──────────────────────────────────────
            ['key' => 'pm_trailing_owner',         'source' => 'bot_runtime',       'path' => 'execution.trailing_owner',  'type' => 'operational', 'notes' => 'bot | profit_manager | profit_manager_shadow'],
            ['key' => 'pm_enabled',                'source' => 'bot_config',        'path' => 'profit_manager.module.enabled', 'type' => 'operational', 'notes' => 'Profit Manager on/off'],

            // ── Smart Brain — risk engine coefficients (immutable) ─────────
            ['key' => 'risk_levels',               'source' => 'brain_risk_engine', 'path' => 'settings.risk_levels',      'type' => 'immutable', 'notes' => 'Corridor-width → leverage coefficient table'],
            ['key' => 'brain_stop_corridor_factor','source' => 'brain_risk_engine', 'path' => 'user_limits.brain_stop_corridor_factor', 'type' => 'immutable', 'notes' => 'Internal SL corridor coefficient'],
            ['key' => 'brain_stop_volatility_factor','source' => 'brain_risk_engine','path' => 'user_limits.brain_stop_volatility_factor','type' => 'immutable', 'notes' => ''],
            ['key' => 'brain_stop_liq_safety_factor','source' => 'brain_risk_engine','path' => 'user_limits.brain_stop_liq_safety_factor','type' => 'immutable', 'notes' => ''],

            // ── Trading Bot — exchange defaults (immutable) ────────────────
            ['key' => 'exchange_category',         'source' => 'bot_config',        'path' => 'exchange.category',         'type' => 'immutable', 'notes' => 'Bybit product category (linear)'],
            ['key' => 'exchange_settle_coin',      'source' => 'bot_config',        'path' => 'exchange.settle_coin',      'type' => 'immutable', 'notes' => 'Settlement coin (USDT)'],
            ['key' => 'exchange_tpsl_mode',        'source' => 'bot_config',        'path' => 'exchange.tpsl_mode',        'type' => 'immutable', 'notes' => 'Full | Partial'],
            ['key' => 'exchange_sl_trigger_by',    'source' => 'bot_config',        'path' => 'exchange.sl_trigger_by',    'type' => 'immutable', 'notes' => 'MarkPrice | IndexPrice | LastPrice'],
            ['key' => 'post_open_reconcile_retries','source' => 'bot_config',       'path' => 'execution.post_open_reconcile_retries', 'type' => 'immutable', 'notes' => 'Position fetch retries after open'],
            ['key' => 'safety_stop_on_errors',     'source' => 'bot_config',        'path' => 'execution.safety_stop_on_errors', 'type' => 'immutable', 'notes' => 'Error count before execution safety stop'],

            // ── Trading Bot — signal validation schema (immutable) ─────────
            ['key' => 'signal_schema_version',     'source' => 'bot_config',        'path' => 'validation.signal_schema_version', 'type' => 'immutable', 'notes' => 'Expected Brain signal schema version'],
        ];
    }

    // =========================================================================
    // Operational parameter schema
    // =========================================================================

    /**
     * Schema for the operational master config draft.
     * Defines label, notes, and source precedence for each key.
     *
     * @return array<string,array<string,mixed>>
     */
    private function operationalSchema(): array
    {
        return [
            // Live trading
            'live_trading_enabled' => [
                'label' => 'Live Trading Enabled',
                'notes' => 'Master on/off for live trading',
                'sources' => [
                    'brain_user_config' => 'live_trading_enabled',
                    'brain_effective'   => 'live_trading.live_trading_enabled',
                ],
            ],
            'live_max_positions' => [
                'label' => 'Max Live Positions',
                'notes' => 'Maximum concurrent live positions',
                'sources' => [
                    'brain_user_config' => 'live_max_positions',
                    'brain_effective'   => 'live_trading.live_max_positions',
                ],
            ],
            'live_signal_selection_mode' => [
                'label' => 'Signal Selection Mode',
                'notes' => 'all / sniper / etc',
                'sources' => [
                    'brain_user_config' => 'live_signal_selection_mode',
                    'brain_effective'   => 'live_trading.live_signal_selection_mode',
                ],
            ],
            'live_one_trade_per_symbol' => [
                'label' => 'One Trade Per Symbol',
                'notes' => 'Prevents duplicate open positions on same symbol',
                'sources' => [
                    'brain_user_config' => 'live_one_trade_per_symbol',
                    'brain_effective'   => 'live_trading.live_one_trade_per_symbol',
                ],
            ],
            'live_entry_policy' => [
                'label' => 'Live Entry Policy',
                'notes' => 'enter_now | wait_retrace',
                'sources' => [
                    'brain_user_config' => 'live_entry_policy',
                    'brain_effective'   => 'live_trading.live_entry_policy',
                ],
            ],
            'live_reverse_side_enabled' => [
                'label' => 'Reverse Side (Long↔Short)',
                'notes' => 'Invert trade direction before live emit',
                'sources' => [
                    'brain_user_config' => 'live_reverse_side_enabled',
                ],
            ],
            // Leverage
            'leverage_mode' => [
                'label' => 'Leverage Mode',
                'notes' => 'manual | auto',
                'sources' => [
                    'brain_user_config' => 'leverage_mode',
                    'brain_effective'   => 'leverage_control.leverage_mode',
                ],
            ],
            'manual_leverage' => [
                'label' => 'Manual Leverage',
                'notes' => 'Fixed leverage applied when leverage_mode=manual',
                'sources' => [
                    'brain_user_config' => 'manual_leverage',
                    'brain_effective'   => 'leverage_control.manual_leverage',
                ],
            ],
            'max_leverage' => [
                'label' => 'Max Leverage Cap',
                'notes' => 'Hard cap; Brain and Bot both respect this',
                'sources' => [
                    'brain_user_config' => 'max_leverage',
                ],
            ],
            // Budget
            'max_budget_per_coin' => [
                'label' => 'Budget Per Trade (USDT)',
                'notes' => 'Max USDT allocated per signal/intent',
                'sources' => [
                    'brain_user_config' => 'max_budget_per_coin',
                ],
            ],
            // Stop control
            'stop_control_mode' => [
                'label' => 'Stop Control Mode',
                'notes' => 'auto (liq-based) | entry_roi (entry-based)',
                'sources' => [
                    'brain_user_config' => 'stop_control_mode',
                    'brain_effective'   => 'stop_control.stop_control_mode',
                ],
            ],
            'stop_loss_from_entry_roi' => [
                'label' => 'Stop Loss (ROI from entry)',
                'notes' => 'Used when stop_control_mode=entry_roi',
                'sources' => [
                    'brain_user_config' => 'stop_loss_from_entry_roi',
                    'brain_effective'   => 'stop_control.stop_loss_from_entry_roi',
                ],
            ],
            // Trailing
            'trailing_enabled' => [
                'label' => 'Trailing Enabled',
                'notes' => 'Brain and Bot must both agree; bot_runtime takes precedence for execution',
                'sources' => [
                    'brain_user_config' => 'trailing_enabled',
                    'bot_runtime'       => 'execution.trailing_enabled',
                ],
            ],
            'trailing_mode' => [
                'label' => 'Trailing Mode',
                'notes' => 'price_distance_floor | fixed_roi_ladder | trend_reversal_soft_ladder_short',
                'sources' => [
                    'brain_user_config' => 'trailing_mode',
                ],
            ],
            'trailing_activation_roi' => [
                'label' => 'Trailing Activation ROI',
                'notes' => 'Position ROI at which trailing stop activates',
                'sources' => [
                    'brain_user_config' => 'trailing_activation_roi',
                ],
            ],
            'trailing_activation_floor_roi' => [
                'label' => 'Trailing Floor Activation ROI',
                'notes' => 'ROI at which floor-lock activates',
                'sources' => [
                    'brain_user_config' => 'trailing_activation_floor_roi',
                ],
            ],
            // Break-even
            'break_even_enabled' => [
                'label' => 'Break-Even Enabled',
                'notes' => 'Brain and Bot both define this; conflicts tracked',
                'sources' => [
                    'brain_user_config' => 'break_even_enabled',
                    'bot_runtime'       => 'execution.break_even_enabled',
                ],
            ],
            'break_even_activation_roi' => [
                'label' => 'Break-Even Activation ROI',
                'notes' => 'ROI at which break-even stop is placed',
                'sources' => [
                    'brain_user_config' => 'break_even_activation_roi',
                ],
            ],
            // Patterns
            'patterns_enabled' => [
                'label' => 'Enabled Patterns',
                'notes' => 'List of active pattern algorithm IDs',
                'sources' => [
                    'brain_user_config' => 'patterns.enabled',
                    'brain_effective'   => 'pattern_selection.enabled',
                ],
            ],
            'patterns_mode' => [
                'label' => 'Pattern Match Mode',
                'notes' => 'any = match any enabled pattern; all = require all',
                'sources' => [
                    'brain_user_config' => 'patterns.mode',
                    'brain_effective'   => 'pattern_selection.mode',
                ],
            ],
            // Smart Brain routing
            'execution_profile' => [
                'label' => 'Execution Profile',
                'notes' => 'balanced | sniper_lite | sniper_75_attempt | custom',
                'sources' => [
                    'brain_user_config' => 'execution_profile',
                    'brain_effective'   => 'execution_profile',
                ],
            ],
            // Trading Bot
            'bot_mode' => [
                'label' => 'Bot Mode',
                'notes' => 'live | demo | paper',
                'sources' => [
                    'bot_runtime' => 'module.mode',
                    'bot_config'  => 'module.mode',
                ],
            ],
            'bot_enabled' => [
                'label' => 'Bot Enabled',
                'notes' => 'Trading Bot on/off switch',
                'sources' => [
                    'bot_runtime' => 'enabled',
                    'bot_config'  => 'module.enabled',
                ],
            ],
            'max_intents_per_run' => [
                'label' => 'Max Intents Per Run',
                'notes' => 'Max live opens attempted per cron tick',
                'sources' => [
                    'bot_runtime' => 'execution.max_intents_per_run',
                    'bot_config'  => 'execution.max_intents_per_run',
                ],
            ],
            // Profit Manager
            'pm_trailing_owner' => [
                'label' => 'Trailing Owner',
                'notes' => 'bot | profit_manager | profit_manager_shadow',
                'sources' => [
                    'bot_runtime' => 'execution.trailing_owner',
                ],
            ],
            'pm_enabled' => [
                'label' => 'Profit Manager Enabled',
                'notes' => 'PM on/off (reads from bot config profit_manager block)',
                'sources' => [
                    'bot_config' => 'profit_manager.module.enabled',
                ],
            ],
        ];
    }

    // =========================================================================
    // Immutable parameter schema
    // =========================================================================

    /**
     * Schema for the immutable/internal config draft.
     *
     * @return array<string,array<string,mixed>>
     */
    private function immutableSchema(): array
    {
        return [
            'risk_levels' => [
                'label'   => 'Risk Level Table (corridor→leverage)',
                'notes'   => 'Internal: corridor width brackets mapped to leverage and budget factors',
                'sources' => ['brain_risk_engine' => 'settings.risk_levels'],
            ],
            'brain_stop_corridor_factor' => [
                'label'   => 'Brain Stop Corridor Factor',
                'notes'   => 'Internal coefficient: stop distance relative to corridor width',
                'sources' => ['brain_risk_engine' => 'user_limits.brain_stop_corridor_factor'],
            ],
            'brain_stop_volatility_factor' => [
                'label'   => 'Brain Stop Volatility Factor',
                'notes'   => 'Internal coefficient: volatility weight in SL formula',
                'sources' => ['brain_risk_engine' => 'user_limits.brain_stop_volatility_factor'],
            ],
            'brain_stop_liq_safety_factor' => [
                'label'   => 'Brain Stop Liq Safety Factor',
                'notes'   => 'Internal coefficient: liquidation-price safety margin in SL formula',
                'sources' => ['brain_risk_engine' => 'user_limits.brain_stop_liq_safety_factor'],
            ],
            'exchange_category' => [
                'label'   => 'Exchange Category',
                'notes'   => 'Bybit product category (linear = USDT perps)',
                'sources' => ['bot_config' => 'exchange.category'],
            ],
            'exchange_settle_coin' => [
                'label'   => 'Settlement Coin',
                'notes'   => 'USDT (do not change)',
                'sources' => ['bot_config' => 'exchange.settle_coin'],
            ],
            'exchange_tpsl_mode' => [
                'label'   => 'TP/SL Mode',
                'notes'   => 'Full | Partial — controls how stops are applied',
                'sources' => ['bot_config' => 'exchange.tpsl_mode'],
            ],
            'exchange_sl_trigger_by' => [
                'label'   => 'SL Trigger Price Type',
                'notes'   => 'MarkPrice | IndexPrice | LastPrice',
                'sources' => ['bot_config' => 'exchange.sl_trigger_by'],
            ],
            'post_open_reconcile_retries' => [
                'label'   => 'Post-Open Reconcile Retries',
                'notes'   => 'How many times bot retries position fetch after open',
                'sources' => ['bot_config' => 'execution.post_open_reconcile_retries'],
            ],
            'safety_stop_on_errors' => [
                'label'   => 'Safety Stop Error Threshold',
                'notes'   => 'Number of errors before execution safety stop fires',
                'sources' => ['bot_config' => 'execution.safety_stop_on_errors'],
            ],
            'signal_schema_version' => [
                'label'   => 'Signal Schema Version',
                'notes'   => 'Expected Brain signal schema (clean_signal_v1)',
                'sources' => ['bot_config' => 'validation.signal_schema_version'],
            ],
            'v2_live_quality_floor_enabled' => [
                'label'   => 'V2 Live Quality Floor',
                'notes'   => 'Internal: whether V2 quality scoring gate is active',
                'sources' => ['brain_risk_engine' => 'user_limits.v2_live_quality_floor_enabled'],
            ],
            'sniper_v3_live_filter_enabled' => [
                'label'   => 'Sniper V3 Live Filter',
                'notes'   => 'Internal: Sniper V3 hard quality gate for live signals',
                'sources' => ['brain_risk_engine' => 'user_limits.sniper_v3_live_filter_enabled'],
            ],
            'trailing_presets' => [
                'label'   => 'Trailing Presets (soft/medium/hard)',
                'notes'   => 'Internal: preset ROI bracket tables for trailing modes',
                'sources' => ['bot_config' => 'execution.trailing_presets'],
            ],
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Get a value from a nested array using dot-notation path.
     *
     * @param array<string,mixed> $data
     * @return mixed
     */
    private function dotGet(array $data, string $path)
    {
        $keys = explode('.', $path);
        $cur  = $data;
        foreach ($keys as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) {
                return null;
            }
            $cur = $cur[$k];
        }
        return $cur;
    }

    /**
     * Return the short file description for a source name.
     */
    private function sourceFile(string $source): string
    {
        return match ($source) {
            'brain_risk_engine' => 'modules/system/smart_brain/config/risk_engine.php',
            'brain_user_config' => 'modules/system/smart_brain/runtime/user_config.json',
            'brain_effective'   => 'modules/system/smart_brain/runtime/effective_config.json',
            'bot_config'        => 'modules/system/trading_bot/config/config.php',
            'bot_runtime'       => 'modules/system/trading_bot/config/bot.json',
            'pm_config'         => 'modules/system/profit_manager/config/config.php',
            'passport_manifest' => 'modules/system/coin_passport/manifest.php',
            default             => $source,
        };
    }
}
