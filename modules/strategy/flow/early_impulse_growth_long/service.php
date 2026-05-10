<?php

declare(strict_types=1);

namespace Modules\Strategy\EarlyImpulseGrowthLong;

final class EarlyImpulseGrowthLongService
{
    private const STRATEGY_ID = 'early_impulse_growth_long';
    private const SIDE = 'long';

    private static ?self $instance = null;
    private string $moduleDir;
    private string $repoRoot;

    private string $universeSource = 'unknown';
    private int $universeTotal = 0;
    /** @var list<string> */
    private array $universeExamples = [];
    /** @var array<string,array<string,mixed>>|null */
    private ?array $cachedFilterCatalog = null;
    /** @var array<string,array<string,mixed>>|null */
    private ?array $cachedFilterProfiles = null;

    public function __construct(?string $moduleDir = null)
    {
        if ($moduleDir !== null) {
            $this->moduleDir = rtrim($moduleDir, '/');
        } else {
            $this->moduleDir = rtrim(
                \Core\System\SystemPaths::instance()->get('strategy.early_impulse_growth_long'),
                '/'
            );
        }
        // modules/strategy/flow/early_impulse_growth_long -> repo root is 4 levels up
        $this->repoRoot = rtrim(dirname($this->moduleDir, 4), '/');
    }

    public static function instance(?string $moduleDir = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($moduleDir);
        }
        return self::$instance;
    }

    /**
     * @return array<string,mixed>
     */
    public function getConfig(): array
    {
        return $this->loadConfig();
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getFilterCatalog(): array
    {
        if ($this->cachedFilterCatalog === null) {
            require_once $this->repoRoot . '/modules/filter_engine/filter_engine.php';
            $this->cachedFilterCatalog = \Modules\FilterEngine\FilterEngine::discoverFilterMetadata();
        }
        return $this->cachedFilterCatalog;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getFilterProfiles(): array
    {
        if ($this->cachedFilterProfiles === null) {
            $profiles = $this->readPhpArray($this->moduleDir . '/config/filter_profiles.php');
            $this->cachedFilterProfiles = is_array($profiles) ? $profiles : [];
        }
        return $this->cachedFilterProfiles;
    }

    /**
     * Queue one rotating universe window for batched processing.
     *
     * @return array<string,mixed>
     */
    public function queueRun(): array
    {
        $config = $this->loadConfig();
        if (!(bool)$config['enabled']) {
            return ['queued' => false, 'status' => 'disabled'];
        }

        $symbols = $this->fetchUniverse();
        $total = count($symbols);
        $batchSize = max(1, (int)$config['batch_size']);
        $maxSymbols = max(1, (int)$config['max_symbols_per_run']);
        $windowSize = $total > 0 ? min($maxSymbols, $total) : 0;

        $prev = $this->readJson($this->storagePath('run_state.json'), []);
        $rawPrevCursor = (int)($prev['next_registry_cursor'] ?? 0);
        $cursor = $rawPrevCursor;
        $cursorResetReason = null;
        if ($total > 0 && ($cursor < 0 || $cursor >= $total)) {
            $cursor = 0;
            $cursorResetReason = 'cursor_out_of_range';
        }
        if ($total === 0) {
            $cursor = 0;
        }

        $selectedSymbols = [];
        if ($total > 0) {
            for ($i = 0; $i < $windowSize; $i++) {
                $idx = ($cursor + $i) % $total;
                $selectedSymbols[] = $symbols[$idx];
            }
        }

        $selectedTotal = count($selectedSymbols);
        $windowStart = $selectedTotal > 0 ? $cursor : 0;
        $windowEnd = $selectedTotal > 0 ? (($windowStart + $selectedTotal - 1) % max(1, $total)) : 0;
        $wrapped = $selectedTotal > 0 && ($windowStart + $windowSize > $total);
        $nextCursor = ($total > 0 && $selectedTotal > 0) ? (($windowStart + $selectedTotal) % $total) : 0;

        $state = [
            'status' => 'queued',
            'queued_at' => date('c'),
            'batch_offset' => 0,
            'symbols' => $selectedSymbols,
            'selected_window_total' => $selectedTotal,
            'universe_total' => $total,
            'batch_size' => $batchSize,
            'max_symbols_per_run' => $maxSymbols,
            'continuous_scan_enabled' => (bool)$config['continuous_scan_enabled'],
            'auto_requeue_when_done' => (bool)$config['auto_requeue_when_done'],
            'registry_cursor' => $windowStart,
            'previous_registry_cursor' => $rawPrevCursor,
            'next_registry_cursor' => $nextCursor,
            'registry_window_start' => $windowStart,
            'registry_window_end' => $windowEnd,
            'registry_window_wrapped' => $wrapped,
            'registry_cursor_reset_reason' => $cursorResetReason,
            'universe_source' => $this->universeSource,
            'universe_examples' => $this->universeExamples,
        ];

        $this->writeJson($this->storagePath('run_state.json'), $state);

        return [
            'queued' => true,
            'queued_at' => $state['queued_at'],
            'selected_window_total' => $selectedTotal,
            'registry_cursor' => $windowStart,
            'next_registry_cursor' => $nextCursor,
            'universe_total' => $total,
            'batch_size' => $batchSize,
        ];
    }

    /**
     * Process one queue batch. Cron entrypoint.
     *
     * @return array<string,mixed>
     */
    public function tickBatch(): array
    {
        $config = $this->loadConfig();
        if (!(bool)$config['enabled']) {
            return ['status' => 'disabled'];
        }

        $state = $this->readJson($this->storagePath('run_state.json'), []);
        $status = (string)($state['status'] ?? 'idle');

        $continuousScanEnabled = (bool)$config['continuous_scan_enabled'];
        $autoRequeueWhenDone = (bool)$config['auto_requeue_when_done'];

        if (in_array($status, ['idle', 'done'], true) && $continuousScanEnabled && ($status === 'idle' || $autoRequeueWhenDone)) {
            $queue = $this->queueRun();
            if (!($queue['queued'] ?? false)) {
                return ['status' => 'idle'];
            }
            $state = $this->readJson($this->storagePath('run_state.json'), []);
            $status = (string)($state['status'] ?? 'idle');
        }

        if ($status !== 'queued' && $status !== 'running') {
            return ['status' => 'idle'];
        }

        $symbols = isset($state['symbols']) && is_array($state['symbols']) ? array_values($state['symbols']) : [];
        $selectedTotal = (int)($state['selected_window_total'] ?? count($symbols));
        $batchSize = max(1, (int)($state['batch_size'] ?? $config['batch_size']));
        $offsetBefore = max(0, min((int)($state['batch_offset'] ?? 0), max(0, $selectedTotal)));

        $batchSymbols = array_slice($symbols, $offsetBefore, $batchSize);
        $offsetAfter = min($selectedTotal, $offsetBefore + count($batchSymbols));

        $allCandidates = $this->readJson($this->storagePath('candidates.json'), []);
        $allRejects = $this->readJson($this->storagePath('rejects.json'), []);
        $allSignals = $this->readJson($this->storagePath('signals.json'), []);

        $startedAt = date('c');
        $t0 = microtime(true);

        $newCandidates = [];
        $newRejects = [];
        $newSignals = [];

        $diag = [
            'insufficient_data_total' => 0,
            'stale_data_total' => 0,
            'data_source_error_total' => 0,
            'price_impulse_pass_total' => 0,
            'oi_growth_pass_total' => 0,
            'oi_missing_allowed_total' => 0,
            'oi_missing_blocked_total' => 0,
            'filter_engine_checked_total' => 0,
            'filter_engine_blocked_total' => 0,
            'filter_engine_diagnostic_only_total' => 0,
            'open_interest_missing_examples' => [],
            'reject_reason_counts' => [],
        ];

        foreach ($batchSymbols as $symbol) {
            $res = $this->processSymbol((string)$symbol, $config);

            $newCandidates[] = $res['candidate'];
            if (!($res['candidate']['raw_strategy_passed'] ?? false)) {
                $newRejects[] = [
                    'strategy_id' => self::STRATEGY_ID,
                    'symbol' => $res['candidate']['symbol'],
                    'side' => self::SIDE,
                    'reject_reason' => (string)($res['candidate']['raw_reject_reason'] ?? 'unknown'),
                    'detected_at' => $res['candidate']['detected_at'],
                ];
            }
            if (!empty($res['signal'])) {
                $newSignals[] = $res['signal'];
            }

            $reason = (string)($res['candidate']['raw_reject_reason'] ?? '');
            if ($reason !== '') {
                $diag['reject_reason_counts'][$reason] = (int)($diag['reject_reason_counts'][$reason] ?? 0) + 1;
            }

            if (($res['metrics']['price_pass'] ?? false) === true) {
                $diag['price_impulse_pass_total']++;
            }
            if (($res['metrics']['oi_pass'] ?? false) === true) {
                $diag['oi_growth_pass_total']++;
            }
            if (($res['metrics']['oi_missing_allowed'] ?? false) === true) {
                $diag['oi_missing_allowed_total']++;
                if (count($diag['open_interest_missing_examples']) < 8) {
                    $diag['open_interest_missing_examples'][] = [
                        'symbol' => $res['candidate']['symbol'],
                        'detected_at' => $res['candidate']['detected_at'],
                    ];
                }
            }
            if (($res['metrics']['oi_missing_blocked'] ?? false) === true) {
                $diag['oi_missing_blocked_total']++;
            }
            if (($res['metrics']['insufficient_data'] ?? false) === true) {
                $diag['insufficient_data_total']++;
            }
            if (($res['metrics']['stale_data'] ?? false) === true) {
                $diag['stale_data_total']++;
            }
            if (($res['metrics']['data_source_error'] ?? false) === true) {
                $diag['data_source_error_total']++;
            }
            if (($res['metrics']['filter_checked'] ?? false) === true) {
                $diag['filter_engine_checked_total']++;
            }
            if (($res['metrics']['filter_blocked'] ?? false) === true) {
                $diag['filter_engine_blocked_total']++;
            }
            if (($res['metrics']['filter_diagnostic_only'] ?? false) === true) {
                $diag['filter_engine_diagnostic_only_total']++;
            }
        }

        $allCandidates = array_merge(is_array($allCandidates) ? $allCandidates : [], $newCandidates);
        $allRejects = array_merge(is_array($allRejects) ? $allRejects : [], $newRejects);

        $signalIndex = [];
        foreach (is_array($allSignals) ? $allSignals : [] as $signal) {
            if (!is_array($signal)) {
                continue;
            }
            $id = (string)($signal['signal_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $signalIndex[$id] = $signal;
        }
        foreach ($newSignals as $signal) {
            $id = (string)($signal['signal_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $signalIndex[$id] = $signal;
        }
        $allSignals = array_values($signalIndex);

        $allCandidates = array_slice($allCandidates, -max(100, (int)$config['max_candidates_store']));
        $allRejects = array_slice($allRejects, -max(100, (int)$config['max_rejects_store']));
        $allSignals = array_slice($allSignals, -max(100, (int)$config['max_signals_store']));

        $this->writeJson($this->storagePath('candidates.json'), $allCandidates);
        $this->writeJson($this->storagePath('rejects.json'), $allRejects);
        $this->writeJson($this->storagePath('signals.json'), $allSignals);

        $handoffQueue = [];
        foreach ($allSignals as $sig) {
            if (!is_array($sig)) {
                continue;
            }
            if (($sig['handoff_ready'] ?? false) !== true || ($sig['executable'] ?? false) !== true) {
                continue;
            }
            $handoffQueue[] = $sig;
        }
        $this->writeJson($this->storagePath('bot_handoff_queue.json'), $handoffQueue);

        $statusDone = $offsetAfter >= $selectedTotal;
        $state['status'] = $statusDone ? 'done' : 'running';
        $state['started_at'] = $state['started_at'] ?? $startedAt;
        $state['updated_at'] = date('c');
        $state['finished_at'] = $statusDone ? date('c') : null;
        $state['batch_offset'] = $statusDone ? 0 : $offsetAfter;
        $state['batch_offset_before'] = $offsetBefore;
        $state['batch_offset_after'] = $offsetAfter;
        $this->writeJson($this->storagePath('run_state.json'), $state);

        $handoffReadyTotal = count(array_filter($allSignals, static fn(array $s): bool => (bool)($s['handoff_ready'] ?? false)));
        $signalsTotal = count($allSignals);
        $candidatesTotal = count($allCandidates);

        $enabledFilters = $this->computeEnabledFilters($config);

        $lastRun = [
            'strategy_id' => self::STRATEGY_ID,
            'status' => $statusDone ? 'done' : 'running',
            'started_at' => $startedAt,
            'finished_at' => date('c'),
            'duration_ms' => (int)round((microtime(true) - $t0) * 1000),
            'batch_symbols_total' => count($batchSymbols),
            'batch_symbols_examples' => array_slice($batchSymbols, 0, 8),

            'candidates_total' => $candidatesTotal,
            'signals_total' => $signalsTotal,
            'handoff_ready_total' => $handoffReadyTotal,
            'rejects_total' => count($allRejects),

            'universe_source' => $state['universe_source'] ?? $this->universeSource,
            'universe_total' => (int)($state['universe_total'] ?? $this->universeTotal),
            'universe_batch_count' => count($batchSymbols),
            'universe_examples' => $state['universe_examples'] ?? $this->universeExamples,

            'selected_window_total' => (int)($state['selected_window_total'] ?? 0),
            'batch_size' => (int)($state['batch_size'] ?? $batchSize),
            'max_symbols_per_run' => (int)($state['max_symbols_per_run'] ?? $config['max_symbols_per_run']),
            'registry_cursor' => (int)($state['registry_cursor'] ?? 0),
            'previous_registry_cursor' => (int)($state['previous_registry_cursor'] ?? 0),
            'next_registry_cursor' => (int)($state['next_registry_cursor'] ?? 0),
            'batch_offset_before' => $offsetBefore,
            'batch_offset_after' => $offsetAfter,
            'registry_window_start' => (int)($state['registry_window_start'] ?? 0),
            'registry_window_end' => (int)($state['registry_window_end'] ?? 0),
            'registry_window_wrapped' => (bool)($state['registry_window_wrapped'] ?? false),
            'registry_cursor_reset_reason' => $state['registry_cursor_reset_reason'] ?? null,

            'filter_engine_enabled' => (bool)$config['filter_engine_enabled'],
            'enabled_filters_count' => count($enabledFilters),
            'enabled_filters' => $enabledFilters,
            'filter_enforcement_mode' => (string)$config['filter_enforcement_mode'],

            'insufficient_data_total' => $diag['insufficient_data_total'],
            'stale_data_total' => $diag['stale_data_total'],
            'data_source_error_total' => $diag['data_source_error_total'],
            'price_impulse_pass_total' => $diag['price_impulse_pass_total'],
            'oi_growth_pass_total' => $diag['oi_growth_pass_total'],
            'oi_missing_allowed_total' => $diag['oi_missing_allowed_total'],
            'oi_missing_blocked_total' => $diag['oi_missing_blocked_total'],
            'filter_engine_checked_total' => $diag['filter_engine_checked_total'],
            'filter_engine_blocked_total' => $diag['filter_engine_blocked_total'],
            'filter_engine_diagnostic_only_total' => $diag['filter_engine_diagnostic_only_total'],
            'open_interest_missing_examples' => $diag['open_interest_missing_examples'],
            'reject_reason_counts' => $diag['reject_reason_counts'],
        ];

        $this->writeJson($this->storagePath('last_run.json'), $lastRun);
        $this->appendNdjson($this->storagePath('cycle_history.ndjson'), $lastRun);

        return $lastRun;
    }

    /**
     * Cron compatibility wrapper.
     */
    public function tickRun(): array
    {
        return $this->tickBatch();
    }

    /**
     * @return array{candidate: array<string,mixed>, signal: array<string,mixed>|null, metrics: array<string,bool>}
     */
    private function processSymbol(string $symbol, array $config): array
    {
        $now = time();
        $detectedAt = gmdate('c', $now);
        $windowMinutes = $this->resolveWindowMinutes($config);
        $windowSeconds = $windowMinutes * 60;

        $metrics = [
            'price_pass' => false,
            'oi_pass' => false,
            'oi_missing_allowed' => false,
            'oi_missing_blocked' => false,
            'insufficient_data' => false,
            'stale_data' => false,
            'data_source_error' => false,
            'filter_checked' => false,
            'filter_blocked' => false,
            'filter_diagnostic_only' => false,
        ];

        $snapshot = $this->buildMarketSnapshot($symbol, $windowMinutes, $config);

        $priceStart = (float)($snapshot['price_start'] ?? 0.0);
        $priceEnd = (float)($snapshot['price_end'] ?? 0.0);
        $latestTs = (int)($snapshot['latest_ts'] ?? 0);
        $sourceError = (string)($snapshot['error'] ?? '');

        $rejectReason = null;

        if ($sourceError !== '') {
            $rejectReason = 'data_source_error';
            $metrics['data_source_error'] = true;
        } elseif ($priceStart <= 0.0 || $priceEnd <= 0.0) {
            $rejectReason = 'insufficient_data';
            $metrics['insufficient_data'] = true;
        } elseif ($latestTs <= 0 || ($now - $latestTs) > max(60, (int)$config['max_data_staleness_seconds'])) {
            $rejectReason = 'stale_data';
            $metrics['stale_data'] = true;
        }

        $priceChangePct = 0.0;
        if ($priceStart > 0.0 && $priceEnd > 0.0) {
            $priceChangePct = (($priceEnd - $priceStart) / $priceStart) * 100.0;
        }

        $priceScore = $this->scoreRange(
            $priceChangePct,
            (float)$config['min_price_impulse_pct'],
            (float)$config['max_price_impulse_pct']
        );

        $pricePass = $priceChangePct >= (float)$config['min_price_impulse_pct']
            && $priceChangePct <= (float)$config['max_price_impulse_pct']
            && $priceScore >= (float)$config['min_price_impulse_score'];

        if ($rejectReason === null && !$pricePass) {
            $rejectReason = $priceChangePct > (float)$config['max_price_impulse_pct']
                ? 'excessive_price_impulse'
                : 'insufficient_price_impulse';
        }
        if ($pricePass) {
            $metrics['price_pass'] = true;
        }

        $oiStart = null;
        $oiEnd = null;
        $oiGrowthPct = null;
        $oiScore = null;
        $oiPass = false;

        if ((bool)$config['open_interest_enabled']) {
            $oiSeries = $this->buildOpenInterestSeries($snapshot['rows'] ?? [], $windowSeconds, $now);
            if (count($oiSeries) >= 2) {
                $oiStart = (float)$oiSeries[0]['oi'];
                $oiEnd = (float)$oiSeries[count($oiSeries) - 1]['oi'];
                if ($oiStart > 0.0) {
                    $oiGrowthPct = (($oiEnd - $oiStart) / $oiStart) * 100.0;
                    $oiScore = $this->scoreThreshold($oiGrowthPct, (float)$config['min_open_interest_growth_pct']);
                    $oiPass = $oiGrowthPct >= (float)$config['min_open_interest_growth_pct']
                        && $oiScore >= (float)$config['min_open_interest_growth_score'];
                }
            }

            if ($oiPass) {
                $metrics['oi_pass'] = true;
            }

            if ($rejectReason === null && !$oiPass) {
                if ($oiGrowthPct === null) {
                    if ((bool)$config['allow_missing_open_interest']) {
                        $metrics['oi_missing_allowed'] = true;
                    } else {
                        $rejectReason = 'open_interest_missing';
                        $metrics['oi_missing_blocked'] = true;
                    }
                } else {
                    $rejectReason = 'open_interest_growth_too_low';
                }
            }
        } else {
            $oiPass = true;
            $metrics['oi_pass'] = true;
        }

        $rawPassed = $rejectReason === null && $pricePass && ($oiPass || $metrics['oi_missing_allowed']);

        $combinedImpulseScore = round((($priceScore ?: 0.0) + (($oiScore ?? $priceScore) ?: 0.0)) / 2, 4);

        $signalId = strtolower($symbol)
            . '_' . self::SIDE
            . '_' . gmdate('Ymd_Hi', (int)floor($now / 60) * 60)
            . '_' . substr(sha1($symbol . '|' . $now), 0, 8);

        $candidate = [
            'strategy_id' => self::STRATEGY_ID,
            'signal_id' => $signalId,
            'symbol' => $symbol,
            'side' => self::SIDE,
            'detected_at' => $detectedAt,
            'entry_price' => $priceEnd,

            'impulse_window_minutes' => $windowMinutes,
            'price_start' => $priceStart,
            'price_end' => $priceEnd,
            'price_change_pct' => round($priceChangePct, 6),
            'price_impulse_score' => round($priceScore, 6),

            'open_interest_start' => $oiStart,
            'open_interest_end' => $oiEnd,
            'open_interest_growth_pct' => $oiGrowthPct !== null ? round((float)$oiGrowthPct, 6) : null,
            'open_interest_growth_score' => $oiScore !== null ? round((float)$oiScore, 6) : null,

            'combined_impulse_score' => $combinedImpulseScore,
            'raw_strategy_passed' => $rawPassed,
            'raw_reject_reason' => $rawPassed ? null : ($rejectReason ?? 'insufficient_data'),

            'data_source_used' => (string)($snapshot['source'] ?? 'none'),
            'data_latest_ts_unix' => $latestTs,
            'data_latest_ts' => $latestTs > 0 ? gmdate('c', $latestTs) : null,

            'filter_engine_enabled' => (bool)$config['filter_engine_enabled'],
            'filter_results' => [],
            'fatal_filter_hit' => false,
            'would_have_blocked_by_filters' => [],
            'handoff_ready' => false,
            'executable' => false,
            'active_final' => false,
        ];

        $filterEval = [
            'filter_results' => [],
            'fatal_filter_reasons' => [],
            'hard_block_filter_reasons' => [],
            'soft_block_filter_reasons' => [],
            'warning_filter_reasons' => [],
            'would_have_blocked_by_filters' => [],
        ];

        if ((bool)$config['filter_engine_enabled']) {
            $metrics['filter_checked'] = true;
            $filterEval = $this->evaluateFilterEngine($candidate, $config);
        }

        $candidate['filter_results'] = $filterEval['filter_results'] ?? [];
        $candidate['fatal_filter_hit'] = !empty($filterEval['fatal_filter_reasons']);
        $candidate['would_have_blocked_by_filters'] = array_values(array_unique((array)($filterEval['would_have_blocked_by_filters'] ?? [])));

        $hasFatal = !empty($filterEval['fatal_filter_reasons']);
        $hasHard = !empty($filterEval['hard_block_filter_reasons']);
        $hasSoft = !empty($filterEval['soft_block_filter_reasons']);
        $mode = (string)$config['filter_enforcement_mode'];

        $enforcementBlocked = false;
        if ($mode === 'strict') {
            $enforcementBlocked = $hasFatal || $hasHard || $hasSoft;
        } elseif ($mode === 'soft') {
            $enforcementBlocked = $hasFatal || $hasHard;
        } else {
            $metrics['filter_diagnostic_only'] = true;
        }

        if ($enforcementBlocked) {
            $metrics['filter_blocked'] = true;
        }

        $canBeActive = $rawPassed && !$enforcementBlocked;
        $canHandoff = $canBeActive && (bool)$config['handoff_enabled'];

        $candidate['active_final'] = $canBeActive;
        $candidate['handoff_ready'] = $canHandoff;
        $candidate['executable'] = $canHandoff;

        $signal = null;
        if ($canBeActive) {
            $signal = [
                'strategy_id' => self::STRATEGY_ID,
                'signal_id' => $signalId,
                'symbol' => $symbol,
                'side' => self::SIDE,
                'detected_at' => $detectedAt,
                'entry_price' => $priceEnd,
                'impulse_window_minutes' => $windowMinutes,
                'price_start' => $priceStart,
                'price_end' => $priceEnd,
                'price_change_pct' => round($priceChangePct, 6),
                'price_impulse_score' => round($priceScore, 6),
                'open_interest_start' => $oiStart,
                'open_interest_end' => $oiEnd,
                'open_interest_growth_pct' => $oiGrowthPct !== null ? round((float)$oiGrowthPct, 6) : null,
                'open_interest_growth_score' => $oiScore !== null ? round((float)$oiScore, 6) : null,
                'combined_impulse_score' => $combinedImpulseScore,
                'raw_strategy_passed' => true,
                'raw_reject_reason' => null,
                'filter_engine_enabled' => (bool)$config['filter_engine_enabled'],
                'filter_results' => $candidate['filter_results'],
                'fatal_filter_hit' => $candidate['fatal_filter_hit'],
                'would_have_blocked_by_filters' => $candidate['would_have_blocked_by_filters'],
                'handoff_ready' => $canHandoff,
                'executable' => $canHandoff,
                'active_final' => true,
                'signal_source_mode' => 'direct_strategy_handoff',
            ];
        }

        return [
            'candidate' => $candidate,
            'signal' => $signal,
            'metrics' => $metrics,
        ];
    }

    private function resolveWindowMinutes(array $config): int
    {
        $window = (int)$config['impulse_window_minutes'];
        $min = max(1, (int)$config['impulse_min_window_minutes']);
        $max = max($min, (int)$config['impulse_max_window_minutes']);
        return max($min, min($max, $window));
    }

    /**
     * @return array<string,mixed>
     */
    private function buildMarketSnapshot(string $symbol, int $windowMinutes, array $config): array
    {
        $rows = $this->loadParser2Rows($symbol, max((int)$config['parser2_history_lookback_minutes'], $windowMinutes + 15));
        $source = 'parser2_history';
        $error = '';

        $candles = $this->buildMinuteCandlesFromRows($rows);
        if (count($candles) < 2) {
            $candles = $this->fetchBybitCandles($symbol, max(20, (int)$config['bybit_kline_limit']), $config);
            $source = 'bybit_fallback';
            if (count($candles) < 2) {
                $error = 'no_candles';
            }
        }

        $now = time();
        $minTs = $now - ($windowMinutes * 60);
        $windowCandles = array_values(array_filter($candles, static fn(array $c): bool => (int)($c['ts'] ?? 0) >= $minTs));
        if (count($windowCandles) < 2 && count($candles) >= 2) {
            $windowCandles = array_slice($candles, -max(2, $windowMinutes));
        }

        $priceStart = (float)($windowCandles[0]['close'] ?? 0.0);
        $priceEnd = (float)($windowCandles[count($windowCandles) - 1]['close'] ?? 0.0);
        $latestTs = (int)($windowCandles[count($windowCandles) - 1]['ts'] ?? 0);

        return [
            'rows' => $rows,
            'candles' => $windowCandles,
            'price_start' => $priceStart,
            'price_end' => $priceEnd,
            'latest_ts' => $latestTs,
            'source' => $source,
            'error' => $error,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{ts:int,oi:float}>
     */
    private function buildOpenInterestSeries(array $rows, int $windowSeconds, int $now): array
    {
        $minTs = $now - $windowSeconds;
        $series = [];
        foreach ($rows as $row) {
            $ts = (int)($row['ts'] ?? 0);
            $oi = (float)($row['open_interest_value'] ?? $row['open_interest'] ?? 0.0);
            if ($ts <= 0 || $ts < $minTs || $oi <= 0.0) {
                continue;
            }
            $series[] = ['ts' => $ts, 'oi' => $oi];
        }

        if (count($series) >= 2) {
            usort($series, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);
            return $series;
        }

        // Bybit fallback when parser2 OI is unavailable.
        return [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadParser2Rows(string $symbol, int $lookbackMinutes): array
    {
        $normalizedSymbol = strtoupper(trim($symbol));
        if (!preg_match('/^[A-Z0-9]{2,30}$/', $normalizedSymbol)) {
            return [];
        }

        $storageRoot = $this->repoRoot . '/modules/parser/parser2_history_accumulator/storage/' . $normalizedSymbol;
        $files = [
            $storageRoot . '/' . gmdate('Y-m-d') . '.ndjson',
            $storageRoot . '/' . gmdate('Y-m-d', time() - 86400) . '.ndjson',
        ];

        $rows = [];
        $minTs = time() - ($lookbackMinutes * 60);

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $fh = @fopen($file, 'r');
            if (!is_resource($fh)) {
                continue;
            }
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }

                $ts = 0;
                if (isset($row['ts_unix'])) {
                    $ts = (int)$row['ts_unix'];
                } elseif (isset($row['ts'])) {
                    $parsedTs = strtotime((string)$row['ts']);
                    $ts = $parsedTs === false ? 0 : (int)$parsedTs;
                }
                if ($ts <= 0 || $ts < $minTs) {
                    continue;
                }

                $price = (float)($row['last_price'] ?? ($row['data']['lastPrice'] ?? 0.0));
                if ($price <= 0.0) {
                    continue;
                }

                $oi = null;
                if (isset($row['open_interest_value'])) {
                    $oi = (float)$row['open_interest_value'];
                } elseif (isset($row['open_interest'])) {
                    $oi = (float)$row['open_interest'];
                } elseif (isset($row['data']['openInterestValue'])) {
                    $oi = (float)$row['data']['openInterestValue'];
                } elseif (isset($row['data']['openInterest'])) {
                    $oi = (float)$row['data']['openInterest'];
                }

                $rows[] = [
                    'ts' => $ts,
                    'price' => $price,
                    'open_interest_value' => $oi,
                    'open_interest' => $oi,
                ];
            }
            fclose($fh);
        }

        usort($rows, static fn(array $a, array $b): int => ((int)$a['ts']) <=> ((int)$b['ts']));
        $dedup = [];
        foreach ($rows as $row) {
            $dedup[(string)$row['ts']] = $row;
        }
        return array_values($dedup);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function buildMinuteCandlesFromRows(array $rows): array
    {
        $bars = [];
        foreach ($rows as $row) {
            $ts = (int)($row['ts'] ?? 0);
            $price = (float)($row['price'] ?? 0.0);
            if ($ts <= 0 || $price <= 0.0) {
                continue;
            }
            $bucket = (int)(floor($ts / 60) * 60);
            if (!isset($bars[$bucket])) {
                $bars[$bucket] = [
                    'ts' => $bucket,
                    'open' => $price,
                    'high' => $price,
                    'low' => $price,
                    'close' => $price,
                    'volume' => 0.0,
                ];
            } else {
                $bars[$bucket]['high'] = max((float)$bars[$bucket]['high'], $price);
                $bars[$bucket]['low'] = min((float)$bars[$bucket]['low'], $price);
                $bars[$bucket]['close'] = $price;
            }
        }
        ksort($bars);
        return array_values($bars);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchBybitCandles(string $symbol, int $limit, array $config): array
    {
        $baseUrl = rtrim((string)$config['bybit_base_url'], '/');
        $timeout = max(3, (int)$config['bybit_timeout_sec']);
        $url = $baseUrl . '/v5/market/kline?' . http_build_query([
            'category' => 'linear',
            'symbol' => $symbol,
            'interval' => '1',
            'limit' => min(max(20, $limit), 200),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['result']['list']) || !is_array($decoded['result']['list'])) {
            return [];
        }

        $candles = [];
        foreach (array_reverse($decoded['result']['list']) as $row) {
            if (!is_array($row) || count($row) < 6) {
                continue;
            }
            $candles[] = [
                'ts' => (int)round(((int)$row[0]) / 1000),
                'open' => (float)$row[1],
                'high' => (float)$row[2],
                'low' => (float)$row[3],
                'close' => (float)$row[4],
                'volume' => (float)$row[5],
            ];
        }

        return $candles;
    }

    /**
     * @return array<string,mixed>
     */
    private function evaluateFilterEngine(array $signalContext, array $config): array
    {
        if (!is_file($this->repoRoot . '/modules/filter_engine/filter_engine.php')) {
            return [
                'filter_results' => [],
                'fatal_filter_reasons' => [],
                'hard_block_filter_reasons' => [],
                'soft_block_filter_reasons' => [],
                'warning_filter_reasons' => [],
                'would_have_blocked_by_filters' => [],
            ];
        }

        require_once $this->repoRoot . '/modules/filter_engine/filter_result.php';
        require_once $this->repoRoot . '/modules/filter_engine/filter_engine.php';
        require_once $this->repoRoot . '/modules/filter_engine/filters/late_local_entry_filter.php';
        require_once $this->repoRoot . '/modules/filter_engine/filters/low_quality_without_obc_filter.php';
        require_once $this->repoRoot . '/modules/filter_engine/filters/missing_reclaim_filter.php';
        require_once $this->repoRoot . '/modules/filter_engine/filters/tiny_room_filter.php';
        require_once $this->repoRoot . '/modules/filter_engine/filters/point3_terminal_break_filter.php';
        require_once $this->repoRoot . '/modules/filter_engine/filters/daily_extension_filter.php';
        require_once $this->repoRoot . '/modules/filter_engine/filters/whipsaw_filter.php';

        $engineConfig = $this->buildFilterEngineConfig($config);
        $engine = new \Modules\FilterEngine\FilterEngine();

        try {
            return $engine->evaluate($signalContext, $engineConfig);
        } catch (\Throwable) {
            return [
                'filter_results' => [],
                'fatal_filter_reasons' => [],
                'hard_block_filter_reasons' => [],
                'soft_block_filter_reasons' => [],
                'warning_filter_reasons' => [],
                'would_have_blocked_by_filters' => [],
            ];
        }
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function buildFilterEngineConfig(array $config): array
    {
        return $this->normalizeFilterConfig($config)['rows'];
    }

    /**
     * @return list<string>
     */
    private function computeEnabledFilters(array $config): array
    {
        return $this->normalizeFilterConfig($config)['enabled'];
    }

    /**
     * @param array<string,mixed> $config
     * @return array{rows: array<string,array<string,mixed>>, enabled: list<string>, metadata: array<string,array<string,mixed>>}
     */
    private function normalizeFilterConfig(array $config): array
    {
        require_once $this->repoRoot . '/modules/filter_engine/filter_engine.php';
        $metadata = $this->getFilterCatalog();
        $storedRows = is_array($config['filter_config'] ?? null) ? (array)$config['filter_config'] : [];

        $enabledLegacy = [];
        foreach ((array)($config['enabled_filters'] ?? []) as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $enabledLegacy[$id] = true;
            }
        }

        $disabledLegacy = [];
        foreach ((array)($config['disabled_filters'] ?? []) as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $disabledLegacy[$id] = true;
            }
        }

        $rows = [];
        foreach ($metadata as $id => $meta) {
            $row = is_array($storedRows[$id] ?? null) ? (array)$storedRows[$id] : [];
            if (!array_key_exists('enabled', $row)) {
                $legacyKey = 'eig_filter_' . $id . '_enabled';
                if (array_key_exists($legacyKey, $config)) {
                    $row['enabled'] = (bool)$config[$legacyKey];
                } elseif (isset($enabledLegacy[$id])) {
                    $row['enabled'] = true;
                }
            }
            if (isset($disabledLegacy[$id])) {
                $row['enabled'] = false;
            }
            $rows[$id] = \Modules\FilterEngine\FilterEngine::normalizeConfigRow($meta, $row);
        }

        $enabled = [];
        foreach ($rows as $id => $row) {
            if (!empty($row['enabled'])) {
                $enabled[] = $id;
            }
        }

        return [
            'rows' => $rows,
            'enabled' => $enabled,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return list<string>
     */
    private function fetchUniverse(): array
    {
        $symbols = [];
        $source = 'unknown';

        try {
            $registryDir = \Core\System\SystemPaths::instance()->get('parser.parser1_market_registry');
        } catch (\Throwable) {
            $registryDir = $this->repoRoot . '/modules/parser/parser1_market_registry';
        }

        $activePath = rtrim((string)$registryDir, '/') . '/storage/active.json';
        $registryPath = rtrim((string)$registryDir, '/') . '/storage/registry.json';

        foreach ([$activePath, $registryPath] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $raw = @file_get_contents($path);
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }

            if (array_is_list($data)) {
                foreach ($data as $item) {
                    $sym = '';
                    if (is_array($item) && isset($item['symbol'])) {
                        $sym = (string)$item['symbol'];
                    } elseif (is_string($item)) {
                        $sym = $item;
                    }
                    $sym = strtoupper(trim($sym));
                    if ($sym !== '' && preg_match('/^[A-Z0-9]{2,30}USDT$/', $sym)) {
                        $symbols[] = $sym;
                    }
                }
            } else {
                foreach ($data as $key => $val) {
                    $sym = is_array($val) && isset($val['symbol']) ? (string)$val['symbol'] : (string)$key;
                    $sym = strtoupper(trim($sym));
                    if ($sym === '' || !preg_match('/^[A-Z0-9]{2,30}USDT$/', $sym)) {
                        continue;
                    }
                    $status = is_array($val) ? (string)($val['status'] ?? 'active') : 'active';
                    if (strtolower($status) === 'inactive') {
                        continue;
                    }
                    $symbols[] = $sym;
                }
            }

            if (!empty($symbols)) {
                $source = $path;
                break;
            }
        }

        $symbols = array_values(array_unique($symbols));
        sort($symbols);

        $this->universeSource = $source;
        $this->universeTotal = count($symbols);
        $this->universeExamples = array_slice($symbols, 0, 8);

        return $symbols;
    }

    private function loadConfig(): array
    {
        $base = $this->readPhpArray($this->moduleDir . '/config/base.php');
        $active = $this->readPhpArray($this->moduleDir . '/config/active.php');
        $cfg = array_merge($base, $active);

        $cfg['strategy_id'] = (string)($cfg['strategy_id'] ?? self::STRATEGY_ID);
        $cfg['enabled'] = (bool)($cfg['enabled'] ?? false);
        $cfg['handoff_enabled'] = (bool)($cfg['handoff_enabled'] ?? false);
        $cfg['mode'] = (string)($cfg['mode'] ?? 'passive');
        $cfg['side'] = self::SIDE;

        $cfg['batch_size'] = max(1, (int)($cfg['batch_size'] ?? 100));
        $cfg['max_symbols_per_run'] = max(1, (int)($cfg['max_symbols_per_run'] ?? 100));
        $cfg['continuous_scan_enabled'] = (bool)($cfg['continuous_scan_enabled'] ?? true);
        $cfg['auto_requeue_when_done'] = (bool)($cfg['auto_requeue_when_done'] ?? true);

        $cfg['impulse_window_minutes'] = max(1, (int)($cfg['impulse_window_minutes'] ?? 10));
        $cfg['impulse_min_window_minutes'] = max(1, (int)($cfg['impulse_min_window_minutes'] ?? 5));
        $cfg['impulse_max_window_minutes'] = max((int)$cfg['impulse_min_window_minutes'], (int)($cfg['impulse_max_window_minutes'] ?? 10));

        $cfg['min_price_impulse_pct'] = (float)($cfg['min_price_impulse_pct'] ?? 0.4);
        $cfg['max_price_impulse_pct'] = max((float)$cfg['min_price_impulse_pct'], (float)($cfg['max_price_impulse_pct'] ?? 4.0));
        $cfg['min_price_impulse_score'] = max(0.0, min(1.0, (float)($cfg['min_price_impulse_score'] ?? 0.55)));

        $cfg['open_interest_enabled'] = (bool)($cfg['open_interest_enabled'] ?? true);
        $cfg['min_open_interest_growth_pct'] = (float)($cfg['min_open_interest_growth_pct'] ?? 1.0);
        $cfg['min_open_interest_growth_score'] = max(0.0, min(1.0, (float)($cfg['min_open_interest_growth_score'] ?? 0.55)));
        $cfg['allow_missing_open_interest'] = (bool)($cfg['allow_missing_open_interest'] ?? true);
        $cfg['missing_open_interest_mode'] = (string)($cfg['missing_open_interest_mode'] ?? 'diagnostic_only');

        $cfg['filter_engine_enabled'] = (bool)($cfg['filter_engine_enabled'] ?? true);
        $mode = (string)($cfg['filter_enforcement_mode'] ?? 'diagnostic_only');
        $cfg['filter_enforcement_mode'] = in_array($mode, ['diagnostic_only', 'soft', 'strict'], true)
            ? $mode
            : 'diagnostic_only';
        $cfg['filter_profile'] = (string)($cfg['filter_profile'] ?? 'raw_no_filters');
        $cfg['filter_profile_active'] = (string)($cfg['filter_profile_active'] ?? $cfg['filter_profile']);
        $cfg['enabled_filters'] = array_values(array_filter((array)($cfg['enabled_filters'] ?? []), static fn($v): bool => trim((string)$v) !== ''));
        $cfg['disabled_filters'] = array_values(array_filter((array)($cfg['disabled_filters'] ?? []), static fn($v): bool => trim((string)$v) !== ''));
        $cfg['filter_config'] = is_array($cfg['filter_config'] ?? null) ? (array)$cfg['filter_config'] : [];

        $normalizedFilters = $this->normalizeFilterConfig($cfg);
        $cfg['filter_config'] = $normalizedFilters['rows'];
        $cfg['enabled_filters'] = $normalizedFilters['enabled'];
        $cfg['disabled_filters'] = array_values(array_diff(array_keys($normalizedFilters['rows']), $normalizedFilters['enabled']));
        foreach ($normalizedFilters['rows'] as $id => $row) {
            $cfg['eig_filter_' . $id . '_enabled'] = (bool)($row['enabled'] ?? false);
        }

        $cfg['parser2_history_lookback_minutes'] = max(30, (int)($cfg['parser2_history_lookback_minutes'] ?? 180));
        $cfg['max_data_staleness_seconds'] = max(60, (int)($cfg['max_data_staleness_seconds'] ?? 180));
        $cfg['bybit_base_url'] = (string)($cfg['bybit_base_url'] ?? 'https://api.bybit.com');
        $cfg['bybit_timeout_sec'] = max(3, (int)($cfg['bybit_timeout_sec'] ?? 6));
        $cfg['bybit_kline_limit'] = max(20, min(200, (int)($cfg['bybit_kline_limit'] ?? 120)));
        $cfg['bybit_oi_interval'] = (string)($cfg['bybit_oi_interval'] ?? '5min');
        $cfg['bybit_oi_limit'] = max(2, min(50, (int)($cfg['bybit_oi_limit'] ?? 2)));

        $cfg['max_candidates_store'] = max(100, (int)($cfg['max_candidates_store'] ?? 2000));
        $cfg['max_rejects_store'] = max(100, (int)($cfg['max_rejects_store'] ?? 2000));
        $cfg['max_signals_store'] = max(100, (int)($cfg['max_signals_store'] ?? 1000));

        return $cfg;
    }

    /**
     * @return array<string,mixed>
     */
    private function readPhpArray(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        try {
            $loaded = require $path;
            return is_array($loaded) ? $loaded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function storagePath(string $file): string
    {
        return $this->moduleDir . '/storage/' . $file;
    }

    private function readJson(string $path, mixed $default): mixed
    {
        if (!is_file($path) || !is_readable($path)) {
            return $default;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return $decoded === null ? $default : $decoded;
    }

    private function writeJson(string $path, mixed $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) && @file_put_contents($path, $json, LOCK_EX) !== false;
    }

    private function appendNdjson(string $path, array $row): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return false;
        }
        return @file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }

    private function scoreRange(float $value, float $min, float $max): float
    {
        if ($max <= $min) {
            return $value >= $min ? 1.0 : 0.0;
        }
        if ($value <= $min) {
            return 0.0;
        }
        if ($value >= $max) {
            return 1.0;
        }
        return max(0.0, min(1.0, ($value - $min) / ($max - $min)));
    }

    private function scoreThreshold(float $value, float $threshold): float
    {
        if ($threshold <= 0.0) {
            return 1.0;
        }
        return max(0.0, min(1.0, $value / $threshold));
    }
}
