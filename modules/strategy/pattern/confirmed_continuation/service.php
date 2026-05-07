<?php

declare(strict_types=1);

/**
 * Confirmed Continuation Strategy — Service
 *
 * Narrow bidirectional continuation strategy.
 * Catches ONLY confirmed trend continuation after a visible structure and a
 * controlled pullback/retest.  Does NOT catch bottoms, tops, first bounces,
 * falling knives, or late blowoff moves.
 *
 * Setup classes:
 *   LONG:  higher_low_retest_continuation_long
 *   SHORT: lower_high_retest_continuation_short
 *
 * Pipeline per symbol:
 *   fetchCandles → detectStructure → gateHardRejects → scoreCandidate
 *   → obcGate → buildSignal → writeStorage
 *
 * ARCHITECTURE: Environment-neutral signal producer.
 * Do NOT write execution_mode, live_enabled, or demo/live gates.
 * Bot owns execution mode.
 *
 * Batched path:
 *   queueRun()   Write run_state.json with status=queued.
 *   tickBatch()  Process one batch_size symbols per cron tick.
 */

namespace Modules\Strategy\ConfirmedContinuation;

final class ConfirmedContinuationService
{
    private static ?self $instance = null;
    private string $moduleDir;
    private string $repoRoot;

    /** @var \OrderBookContextService|null */
    private ?\OrderBookContextService $obcService = null;

    // ── Per-tick OBC counters (reset at start of tickBatch) ───────────────────
    private int $obcCheckedTotal             = 0;
    private int $obcFetchSuccessTotal        = 0;
    private int $obcFetchFailedTotal         = 0;
    private int $obcSoftDemoteTotal          = 0;
    private int $obcSoftDemoteBlockedHandoff = 0;
    private int $obcSoftDemoteAllowedHandoff = 0;
    /** @var list<array<string,mixed>> */
    private array $obcBlockExamples = [];

    // ── Per-tick candidate counters ───────────────────────────────────────────
    private int $candidatesTotal      = 0;
    private int $longCandidatesTotal  = 0;
    private int $shortCandidatesTotal = 0;
    private int $signalsTotal         = 0;
    private int $longSignalsTotal     = 0;
    private int $shortSignalsTotal    = 0;
    private int $handoffReadyTotal    = 0;
    private int $rejectedTotal        = 0;
    /** @var array<string,int> */
    private array $rejectReasonCounts = [];
    /** @var list<array<string,mixed>> */
    private array $acceptedExamples            = [];
    /** @var list<array<string,mixed>> */
    private array $rejectedExamples            = [];
    /** @var list<array<string,mixed>> */
    private array $lateEntryRejectExamples     = [];
    /** @var list<array<string,mixed>> */
    private array $firstBounceRejectExamples   = [];
    /** @var list<array<string,mixed>> */
    private array $noRetestRejectExamples      = [];

    public function __construct(?string $moduleDir = null)
    {
        if ($moduleDir !== null) {
            $this->moduleDir = rtrim($moduleDir, '/');
        } else {
            $this->moduleDir = rtrim(
                \Core\System\SystemPaths::instance()->get('strategy.confirmed_continuation'),
                '/'
            );
        }
        // Repo root is 4 levels above modules/strategy/pattern/confirmed_continuation/
        $this->repoRoot = rtrim(dirname($this->moduleDir, 4), '/');

        // ── OrderBook Context service (optional; fails gracefully) ─────────────
        $obcDir = $this->repoRoot . '/modules/system/orderbook_context';
        if (is_file($obcDir . '/service.php')) {
            try {
                require_once $obcDir . '/service.php';
                $this->obcService = new \OrderBookContextService($obcDir);
            } catch (\Throwable) {
                $this->obcService = null;
            }
        }
    }

    public static function instance(?string $moduleDir = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($moduleDir);
        }
        return self::$instance;
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Queue a full universe scan.  Called by admin UI or cron.
     */
    public function queueRun(): array
    {
        $state = [
            'status'       => 'queued',
            'queued_at'    => date('c'),
            'batch_offset' => 0,
        ];
        $this->writeJson($this->moduleDir . '/storage/run_state.json', $state);
        return ['queued' => true, 'queued_at' => $state['queued_at']];
    }

    /**
     * Process one batch of symbols.  Called by cron.
     */
    public function tickBatch(): array
    {
        $this->resetCounters();

        $boot = (new ConfirmedContinuationBootstrap($this->moduleDir))->load();
        if (!$boot['valid'] || empty($boot['config']['enabled'])) {
            return ['status' => 'disabled', 'errors' => $boot['errors']];
        }
        $config = $boot['config'];

        $state = $this->readJson($this->moduleDir . '/storage/run_state.json', []);
        if (($state['status'] ?? '') === 'done') {
            return ['status' => 'idle'];
        }

        $offset    = (int)($state['batch_offset'] ?? 0);
        $batchSize = max(1, (int)($config['batch_size'] ?? 50));
        $maxTotal  = max(1, (int)($config['max_symbols_per_run'] ?? 50));

        $symbols = $this->fetchUniverse($config);
        $symbols = array_slice($symbols, $offset, $batchSize);

        $startedAt = date('c');
        $t0        = microtime(true);

        $allCandidates = $this->readJson($this->moduleDir . '/storage/candidates.json', []);
        $allSignals    = $this->readJson($this->moduleDir . '/storage/signals.json',    []);
        $allHandoff    = $this->readJson($this->moduleDir . '/storage/bot_handoff_queue.json', []);
        $allRejects    = $this->readJson($this->moduleDir . '/storage/rejects.json',    []);

        $newCandidates = [];
        $newSignals    = [];
        $newRejects    = [];

        foreach ($symbols as $symbol) {
            $this->processSymbol($symbol, $config, $allSignals, $newCandidates, $newSignals, $newRejects);
        }

        // Merge new signals into existing (keyed by signal_id, dedup)
        $signalIndex = [];
        foreach ($allSignals as $sig) {
            $sid = (string)($sig['signal_id'] ?? '');
            if ($sid !== '') {
                $signalIndex[$sid] = $sig;
            }
        }
        foreach ($newSignals as $sig) {
            $sid = (string)($sig['signal_id'] ?? '');
            if ($sid !== '') {
                $signalIndex[$sid] = $sig;
            }
        }

        // Mark stale signals
        $ttlMin     = max(1, (int)($config['signal_ttl_minutes'] ?? 90));
        $staleAfter = time() - ($ttlMin * 60);
        foreach ($signalIndex as &$sig) {
            if (!($sig['stale'] ?? false)) {
                $detectedAt = (int)strtotime((string)($sig['detected_at'] ?? ''));
                if ($detectedAt > 0 && $detectedAt < $staleAfter) {
                    $sig['stale']        = true;
                    $sig['stale_reason'] = 'ttl_expired';
                    $sig['active_final'] = false;
                    $sig['handoff_ready'] = false;
                    $sig['executable']   = false;
                }
            }
        }
        unset($sig);

        $freshSignals = array_values($signalIndex);
        $handoffQueue = $this->buildHandoffQueue($freshSignals, $config);

        // Persist rejects (append-style, capped)
        $allRejects = array_merge($allRejects, $newRejects);
        if (count($allRejects) > 500) {
            $allRejects = array_slice($allRejects, -500);
        }

        $this->writeJson($this->moduleDir . '/storage/candidates.json',        array_values(array_merge($allCandidates, $newCandidates)));
        $this->writeJson($this->moduleDir . '/storage/signals.json',           $freshSignals);
        $this->writeJson($this->moduleDir . '/storage/bot_handoff_queue.json', $handoffQueue);
        $this->writeJson($this->moduleDir . '/storage/rejects.json',           $allRejects);

        $newOffset = $offset + $batchSize;
        if ($newOffset >= $maxTotal || count($symbols) < $batchSize) {
            $this->writeJson($this->moduleDir . '/storage/run_state.json', [
                'status'       => 'done',
                'finished_at'  => date('c'),
                'batch_offset' => 0,
            ]);
        } else {
            $state['status']       = 'running';
            $state['batch_offset'] = $newOffset;
            $this->writeJson($this->moduleDir . '/storage/run_state.json', $state);
        }

        $finishedAt  = date('c');
        $durationMs  = (int)round((microtime(true) - $t0) * 1000);

        $lastRun = $this->buildLastRun($config, $startedAt, $finishedAt, $durationMs, $freshSignals);
        $this->writeJson($this->moduleDir . '/storage/last_run.json', $lastRun);

        return $lastRun;
    }

    // ── Core symbol processing ─────────────────────────────────────────────────

    private function processSymbol(
        string $symbol,
        array  $config,
        array  $existingSignals,
        array  &$newCandidates,
        array  &$newSignals,
        array  &$newRejects
    ): void {
        $sideMode = (string)($config['side_mode'] ?? 'all');

        foreach (['long', 'short'] as $side) {
            if ($sideMode !== 'all' && $sideMode !== $side) {
                continue;
            }

            // Guard: max 1 active non-stale signal per symbol+side
            $maxActive = (int)($config['max_active_signals_per_symbol_side'] ?? 1);
            if ($maxActive > 0) {
                $activeCount = 0;
                foreach ($existingSignals as $sig) {
                    if (
                        (string)($sig['symbol'] ?? '') === $symbol
                        && (string)($sig['side']   ?? '') === $side
                        && !($sig['stale']        ?? false)
                        && ($sig['active_final']  ?? false)
                    ) {
                        $activeCount++;
                    }
                }
                if ($activeCount >= $maxActive) {
                    continue;
                }
            }

            // Fetch candles
            $candles = $this->fetchCandles($symbol, (int)($config['lookback_candles'] ?? 60), $config);
            if (count($candles) < 20) {
                continue;
            }

            // Detect structure
            $structure = $this->detectStructure($candles, $side, $config);
            if ($structure === null) {
                continue;
            }

            $this->candidatesTotal++;
            if ($side === 'long') {
                $this->longCandidatesTotal++;
            } else {
                $this->shortCandidatesTotal++;
            }

            // Hard reject filters
            $hardReject = $this->checkHardRejects($structure, $candles, $side, $config);
            if ($hardReject !== null) {
                $this->rejectedTotal++;
                $this->rejectReasonCounts[$hardReject['reason']] = ($this->rejectReasonCounts[$hardReject['reason']] ?? 0) + 1;
                $rejectRec = [
                    'symbol'       => $symbol,
                    'side'         => $side,
                    'setup_class'  => $structure['setup_class'],
                    'reject_reason'=> $hardReject['reason'],
                    'failed_stage' => $hardReject['stage'],
                    'rejected_at'  => date('c'),
                    'structure'    => $structure,
                ];
                $newRejects[] = $rejectRec;
                if (count($this->rejectedExamples) < 10) {
                    $this->rejectedExamples[] = $rejectRec;
                }
                // Track specific reject types for diagnostics
                if (str_contains($hardReject['stage'], 'late_entry')) {
                    if (count($this->lateEntryRejectExamples) < 5) {
                        $this->lateEntryRejectExamples[] = $rejectRec;
                    }
                } elseif (str_contains($hardReject['stage'], 'first_bounce')) {
                    if (count($this->firstBounceRejectExamples) < 5) {
                        $this->firstBounceRejectExamples[] = $rejectRec;
                    }
                } elseif (str_contains($hardReject['stage'], 'no_retest')) {
                    if (count($this->noRetestRejectExamples) < 5) {
                        $this->noRetestRejectExamples[] = $rejectRec;
                    }
                }
                continue;
            }

            // Score candidate
            $quality = $this->scoreCandidate($structure, $candles, $side, $config);

            // Gate on minimum quality
            $minQuality   = (float)($config['min_candidate_quality_score'] ?? 0.75);
            $minStructure = (float)($config['min_structure_score']          ?? 0.75);
            if ($quality['final_candidate_score'] < $minQuality
                || $quality['structure_score']     < $minStructure) {
                $reason = 'quality_below_threshold';
                $this->rejectedTotal++;
                $this->rejectReasonCounts[$reason] = ($this->rejectReasonCounts[$reason] ?? 0) + 1;
                $newRejects[] = [
                    'symbol'       => $symbol,
                    'side'         => $side,
                    'setup_class'  => $structure['setup_class'],
                    'reject_reason'=> $reason,
                    'failed_stage' => 'quality_gate',
                    'rejected_at'  => date('c'),
                    'scores'       => $quality,
                ];
                continue;
            }

            // OBC gate (only after cheap filters pass)
            $obcResult = $this->applyObcGate($symbol, $side, $structure['entry_price'] ?? 0.0, $quality, $config);

            $candidate = $this->buildCandidate($symbol, $side, $structure, $quality, $obcResult, $config);
            $newCandidates[] = $candidate;

            // Determine handoff_ready and executable
            $isHandoffReady = $candidate['handoff_ready'];
            $isExecutable   = $candidate['executable'];

            // Build signal
            $signal = $this->buildSignal($candidate, $config);
            $newSignals[] = $signal;

            $this->signalsTotal++;
            if ($side === 'long') {
                $this->longSignalsTotal++;
            } else {
                $this->shortSignalsTotal++;
            }
            if ($isHandoffReady) {
                $this->handoffReadyTotal++;
            }
            if (count($this->acceptedExamples) < 5) {
                $this->acceptedExamples[] = [
                    'symbol'                => $symbol,
                    'side'                  => $side,
                    'setup_class'           => $structure['setup_class'],
                    'final_candidate_score' => $quality['final_candidate_score'],
                    'handoff_ready'         => $isHandoffReady,
                    'executable'            => $isExecutable,
                    'ob_soft_demoted'       => $obcResult['ob_soft_demoted'] ?? false,
                ];
            }
        }
    }

    // ── Structure detection ────────────────────────────────────────────────────

    /**
     * Detect trend structure from candle data.
     *
     * Returns a structure array with all scored fields, or null if no valid
     * structure is detectable.
     */
    private function detectStructure(array $candles, string $side, array $config): ?array
    {
        if (count($candles) < 10) {
            return null;
        }

        $closes = array_column($candles, 'close');
        $highs  = array_column($candles, 'high');
        $lows   = array_column($candles, 'low');
        $n      = count($closes);

        $currentPrice = (float)end($closes);

        if ($side === 'long') {
            return $this->detectLongStructure($candles, $closes, $highs, $lows, $n, $currentPrice, $config);
        } else {
            return $this->detectShortStructure($candles, $closes, $highs, $lows, $n, $currentPrice, $config);
        }
    }

    private function detectLongStructure(
        array  $candles,
        array  $closes,
        array  $highs,
        array  $lows,
        int    $n,
        float  $currentPrice,
        array  $config
    ): ?array {
        $minHigherLows = max(2, (int)($config['min_higher_lows_long'] ?? 2));

        // Find local swing lows (simple pivot detection: lower than neighbours)
        $swingLows = [];
        for ($i = 2; $i < $n - 2; $i++) {
            if ($lows[$i] < $lows[$i - 1] && $lows[$i] < $lows[$i - 2]
                && $lows[$i] < $lows[$i + 1] && $lows[$i] < $lows[$i + 2]) {
                $swingLows[] = ['idx' => $i, 'price' => $lows[$i]];
            }
        }

        if (count($swingLows) < $minHigherLows) {
            return null;
        }

        // Find sequence of higher lows
        $higherLowSequence = [$swingLows[0]];
        foreach (array_slice($swingLows, 1) as $sl) {
            $last = end($higherLowSequence);
            if ($sl['price'] > $last['price']) {
                $higherLowSequence[] = $sl;
            }
        }

        if (count($higherLowSequence) < $minHigherLows) {
            return null;
        }

        $lastHigherLow   = end($higherLowSequence);
        $firstHigherLow  = $higherLowSequence[0];
        $higherLowsCount = count($higherLowSequence);

        // Rising support: linear interpolation between first and last higher low
        $risingSupport = $lastHigherLow['price'];

        // Current price must be above last higher low
        if ($currentPrice <= $lastHigherLow['price']) {
            return null;
        }

        // Detect pullback: recent candles should have pulled back toward last higher low
        $recentLow = min(array_slice($lows, -5));
        $maxPullbackDepthPct = (float)($config['max_pullback_depth_pct'] ?? 2.0);
        $pullbackPct = ($currentPrice - $recentLow) / $currentPrice * 100;
        $pullbackDetected = $pullbackPct >= 0.1 && $pullbackPct <= $maxPullbackDepthPct;

        // Retest: recent low approached last higher low within 1%
        $retestThreshold = $lastHigherLow['price'] * 1.01;
        $retestPrice     = $recentLow;
        $retestHeld      = $recentLow <= $retestThreshold && $recentLow >= $lastHigherLow['price'] * 0.99;

        if ($config['require_retest'] ?? true) {
            if (!$pullbackDetected) {
                return null;  // No retest detected
            }
        }

        // Structure hold: no fresh lower low after last higher low formed
        $lastHLIdx         = $lastHigherLow['idx'];
        $postStructureLows = array_slice($lows, $lastHLIdx);
        $structureHolds    = (min($postStructureLows) >= $lastHigherLow['price'] * 0.985);

        if (($config['require_structure_hold'] ?? true) && !$structureHolds) {
            return null;
        }

        // Continuation after retest: price recovering from retest
        $recentCloses = array_slice($closes, -3);
        $continuationPrice = (float)end($recentCloses);
        $continuationConfirmed = $continuationPrice > $retestPrice && $continuationPrice > $risingSupport;

        if (($config['require_continuation_after_retest'] ?? true) && !$continuationConfirmed) {
            return null;
        }

        // Entry is current price
        $entryPrice = $currentPrice;

        // Entry distance from last higher low
        $entryDistancePct = ($entryPrice - $lastHigherLow['price']) / $lastHigherLow['price'] * 100;

        return [
            'setup_class'                          => 'higher_low_retest_continuation_long',
            'side'                                 => 'long',
            'entry_price'                          => $entryPrice,
            'current_price'                        => $currentPrice,
            'higher_lows_count'                    => $higherLowsCount,
            'last_higher_low_price'                => $lastHigherLow['price'],
            'rising_support_price'                 => $risingSupport,
            'retest_price'                         => $retestPrice,
            'retest_held'                          => $retestHeld,
            'continuation_reclaim_price'           => $continuationPrice,
            'entry_distance_from_last_higher_low_pct' => round($entryDistancePct, 4),
            'trend_phase'                          => 'confirmed_mid_trend_continuation',
            'structure_holds'                      => $structureHolds,
            'pullback_detected'                    => $pullbackDetected,
            'pullback_pct'                         => round($pullbackPct, 4),
        ];
    }

    private function detectShortStructure(
        array  $candles,
        array  $closes,
        array  $highs,
        array  $lows,
        int    $n,
        float  $currentPrice,
        array  $config
    ): ?array {
        $minLowerHighs = max(2, (int)($config['min_lower_highs_short'] ?? 2));

        // Find local swing highs (pivot detection)
        $swingHighs = [];
        for ($i = 2; $i < $n - 2; $i++) {
            if ($highs[$i] > $highs[$i - 1] && $highs[$i] > $highs[$i - 2]
                && $highs[$i] > $highs[$i + 1] && $highs[$i] > $highs[$i + 2]) {
                $swingHighs[] = ['idx' => $i, 'price' => $highs[$i]];
            }
        }

        if (count($swingHighs) < $minLowerHighs) {
            return null;
        }

        // Find sequence of lower highs
        $lowerHighSequence = [$swingHighs[0]];
        foreach (array_slice($swingHighs, 1) as $sh) {
            $last = end($lowerHighSequence);
            if ($sh['price'] < $last['price']) {
                $lowerHighSequence[] = $sh;
            }
        }

        if (count($lowerHighSequence) < $minLowerHighs) {
            return null;
        }

        $lastLowerHigh   = end($lowerHighSequence);
        $lowerHighsCount = count($lowerHighSequence);

        // Falling resistance
        $fallingResistance = $lastLowerHigh['price'];

        // Current price must be below last lower high
        if ($currentPrice >= $lastLowerHigh['price']) {
            return null;
        }

        // Detect bounce: recent candles should have bounced toward last lower high
        $recentHigh          = max(array_slice($highs, -5));
        $maxPullbackDepthPct = (float)($config['max_pullback_depth_pct'] ?? 2.0);
        $bouncePct           = ($recentHigh - $currentPrice) / $currentPrice * 100;
        $bounceDetected      = $bouncePct >= 0.1 && $bouncePct <= $maxPullbackDepthPct;

        // Retest: recent high approached last lower high within 1%
        $retestThreshold = $lastLowerHigh['price'] * 0.99;
        $retestPrice     = $recentHigh;
        $retestHeld      = $recentHigh >= $retestThreshold && $recentHigh <= $lastLowerHigh['price'] * 1.01;

        if ($config['require_retest'] ?? true) {
            if (!$bounceDetected) {
                return null;
            }
        }

        // Structure hold: no fresh higher high after last lower high formed
        $lastLHIdx          = $lastLowerHigh['idx'];
        $postStructureHighs = array_slice($highs, $lastLHIdx);
        $structureHolds     = (max($postStructureHighs) <= $lastLowerHigh['price'] * 1.015);

        if (($config['require_structure_hold'] ?? true) && !$structureHolds) {
            return null;
        }

        // Continuation after bounce: price declining from bounce
        $recentCloses      = array_slice($closes, -3);
        $continuationPrice = (float)end($recentCloses);
        $continuationConfirmed = $continuationPrice < $retestPrice && $continuationPrice < $fallingResistance;

        if (($config['require_continuation_after_retest'] ?? true) && !$continuationConfirmed) {
            return null;
        }

        $entryPrice         = $currentPrice;
        $entryDistancePct   = ($lastLowerHigh['price'] - $entryPrice) / $lastLowerHigh['price'] * 100;

        return [
            'setup_class'                          => 'lower_high_retest_continuation_short',
            'side'                                 => 'short',
            'entry_price'                          => $entryPrice,
            'current_price'                        => $currentPrice,
            'lower_highs_count'                    => $lowerHighsCount,
            'last_lower_high_price'                => $lastLowerHigh['price'],
            'falling_resistance_price'             => $fallingResistance,
            'retest_price'                         => $retestPrice,
            'retest_held'                          => $retestHeld,
            'continuation_breakdown_price'         => $continuationPrice,
            'entry_distance_from_last_lower_high_pct' => round($entryDistancePct, 4),
            'trend_phase'                          => 'confirmed_downtrend_continuation',
            'structure_holds'                      => $structureHolds,
            'bounce_detected'                      => $bounceDetected,
            'bounce_pct'                           => round($bouncePct, 4),
        ];
    }

    // ── Hard reject filters ────────────────────────────────────────────────────

    /**
     * Returns ['reason' => string, 'stage' => string] or null if no hard reject.
     */
    private function checkHardRejects(array $structure, array $candles, string $side, array $config): ?array
    {
        $closes = array_column($candles, 'close');
        $n      = count($closes);

        $entryPrice = (float)($structure['entry_price'] ?? 0.0);
        if ($entryPrice <= 0) {
            return ['reason' => 'missing_entry_price', 'stage' => 'pre_filter'];
        }

        // Reject: no structure higher lows / lower highs
        if ($side === 'long') {
            if (($structure['higher_lows_count'] ?? 0) < 2) {
                return ['reason' => 'no_higher_lows', 'stage' => 'first_bounce_or_no_structure'];
            }
            // Reject: entry too far above last higher low
            $distPct      = (float)($structure['entry_distance_from_last_higher_low_pct'] ?? 0);
            $maxDist      = (float)($config['max_entry_distance_from_structure_pct'] ?? 0.6);
            if ($distPct > $maxDist) {
                return ['reason' => 'entry_too_far_above_structure', 'stage' => 'late_entry'];
            }
            // Reject: price already pumped too far from local base
            $maxExt = (float)($config['max_extension_from_local_base_pct'] ?? 12.0);
            $lastHLPrice = (float)($structure['last_higher_low_price'] ?? 0);
            if ($lastHLPrice > 0) {
                $extPct = ($entryPrice - $lastHLPrice) / $lastHLPrice * 100;
                if ($extPct > $maxExt) {
                    return ['reason' => 'too_extended_from_local_base', 'stage' => 'late_entry'];
                }
            }
            // Reject: blowoff 1m candle at entry
            if ($n >= 2) {
                $lastClose = $closes[$n - 1];
                $prevClose = $closes[$n - 2];
                $candlePct = $prevClose > 0 ? abs($lastClose - $prevClose) / $prevClose * 100 : 0;
                if ($candlePct > (float)($config['max_1m_blowoff_pct'] ?? 2.0)) {
                    return ['reason' => 'blowoff_candle_at_entry', 'stage' => 'blowoff_reject'];
                }
            }
            // Reject: retest not held (structure broken)
            if (!($structure['retest_held'] ?? false) && ($config['require_retest'] ?? true)) {
                return ['reason' => 'retest_not_held', 'stage' => 'no_retest'];
            }
        } else {
            if (($structure['lower_highs_count'] ?? 0) < 2) {
                return ['reason' => 'no_lower_highs', 'stage' => 'first_dump_or_no_structure'];
            }
            $distPct = (float)($structure['entry_distance_from_last_lower_high_pct'] ?? 0);
            $maxDist = (float)($config['max_entry_distance_from_structure_pct'] ?? 0.6);
            if ($distPct > $maxDist) {
                return ['reason' => 'entry_too_far_below_structure', 'stage' => 'late_entry'];
            }
            $maxExt       = (float)($config['max_extension_from_local_base_pct'] ?? 12.0);
            $lastLHPrice  = (float)($structure['last_lower_high_price'] ?? 0);
            if ($lastLHPrice > 0) {
                $extPct = ($lastLHPrice - $entryPrice) / $lastLHPrice * 100;
                if ($extPct > $maxExt) {
                    return ['reason' => 'too_extended_from_breakdown_base', 'stage' => 'late_entry'];
                }
            }
            if ($n >= 2) {
                $lastClose = $closes[$n - 1];
                $prevClose = $closes[$n - 2];
                $candlePct = $prevClose > 0 ? abs($lastClose - $prevClose) / $prevClose * 100 : 0;
                if ($candlePct > (float)($config['max_1m_blowoff_pct'] ?? 2.0)) {
                    return ['reason' => 'panic_dump_candle_at_entry', 'stage' => 'blowoff_reject'];
                }
            }
            if (!($structure['retest_held'] ?? false) && ($config['require_retest'] ?? true)) {
                return ['reason' => 'retest_not_held', 'stage' => 'no_retest'];
            }
        }

        return null;
    }

    // ── Scoring ────────────────────────────────────────────────────────────────

    private function scoreCandidate(array $structure, array $candles, string $side, array $config): array
    {
        $closes  = array_column($candles, 'close');
        $volumes = array_column($candles, 'volume');
        $n       = count($closes);

        // Structure score: based on number of higher lows / lower highs
        $structureCount  = ($side === 'long')
            ? (int)($structure['higher_lows_count'] ?? 2)
            : (int)($structure['lower_highs_count'] ?? 2);
        $structureScore  = min(1.0, ($structureCount - 1) / 4 + 0.5);

        // Retest score
        $retestScore = ($structure['retest_held'] ?? false) ? 0.85 : 0.40;

        // Continuation score
        $continuationScore = 0.5;
        if ($side === 'long') {
            $contPrice   = (float)($structure['continuation_reclaim_price'] ?? 0);
            $retestPrice = (float)($structure['retest_price'] ?? 0);
            if ($contPrice > 0 && $retestPrice > 0 && $contPrice > $retestPrice) {
                $contMoveAbs = ($contPrice - $retestPrice) / $retestPrice * 100;
                $continuationScore = min(1.0, 0.5 + $contMoveAbs * 0.1);
            }
        } else {
            $contPrice   = (float)($structure['continuation_breakdown_price'] ?? 0);
            $retestPrice = (float)($structure['retest_price'] ?? 0);
            if ($contPrice > 0 && $retestPrice > 0 && $contPrice < $retestPrice) {
                $contMoveAbs = ($retestPrice - $contPrice) / $retestPrice * 100;
                $continuationScore = min(1.0, 0.5 + $contMoveAbs * 0.1);
            }
        }

        // Entry distance score
        $distPct = ($side === 'long')
            ? (float)($structure['entry_distance_from_last_higher_low_pct'] ?? 99)
            : (float)($structure['entry_distance_from_last_lower_high_pct'] ?? 99);
        $maxDist  = (float)($config['max_entry_distance_from_structure_pct'] ?? 0.6);
        $entryDistanceScore = $maxDist > 0 ? max(0.0, min(1.0, 1.0 - $distPct / $maxDist)) : 0.5;

        // Pullback quality score
        $pullbackPct = ($side === 'long')
            ? (float)($structure['pullback_pct'] ?? 0)
            : (float)($structure['bounce_pct'] ?? 0);
        $maxPullback = (float)($config['max_pullback_depth_pct'] ?? 2.0);
        $pullbackQualityScore = $maxPullback > 0 ? max(0.0, min(1.0, 1.0 - ($pullbackPct / $maxPullback))) : 0.5;

        // Volume persistence score (simple: above-median volume in last 5 candles)
        $volumePersistenceScore = 0.5;
        if ($n >= 10) {
            $allVols    = array_slice($volumes, 0, $n - 5);
            $recentVols = array_slice($volumes, -5);
            if (!empty($allVols)) {
                sort($allVols);
                $medianVol = $allVols[(int)(count($allVols) / 2)] ?? 0;
                if ($medianVol > 0) {
                    $avgRecent  = array_sum($recentVols) / max(1, count($recentVols));
                    $volumePersistenceScore = min(1.0, $avgRecent / $medianVol * 0.5);
                }
            }
        }

        // OBC score placeholder (updated after OBC fetch)
        $obcScore = 0.5;

        $finalScore = round(
            $structureScore       * 0.30
            + $retestScore        * 0.25
            + $continuationScore  * 0.20
            + $entryDistanceScore * 0.10
            + $pullbackQualityScore * 0.05
            + $volumePersistenceScore * 0.05
            + $obcScore           * 0.05,
            4
        );

        return [
            'structure_score'         => round($structureScore,        4),
            'retest_score'            => round($retestScore,           4),
            'continuation_score'      => round($continuationScore,     4),
            'entry_distance_score'    => round($entryDistanceScore,    4),
            'pullback_quality_score'  => round($pullbackQualityScore,  4),
            'volume_persistence_score'=> round($volumePersistenceScore,4),
            'obc_score'               => round($obcScore,              4),
            'final_candidate_score'   => $finalScore,
            'candidate_quality_score' => $finalScore,
        ];
    }

    // ── OBC gate ───────────────────────────────────────────────────────────────

    private function applyObcGate(
        string $symbol,
        string $side,
        float  $entryPrice,
        array  $quality,
        array  $config
    ): array {
        $gateEnabled = (bool)($config['orderbook_wall_gate_enabled'] ?? true);
        $defaultResult = [
            'ob_wall_checked'       => false,
            'ob_wall_context'       => null,
            'ob_ask_wall_risk'      => false,
            'ob_bid_wall_risk'      => false,
            'ob_bid_wall_support'   => false,
            'ob_ask_wall_resistance'=> false,
            'ob_soft_demoted'       => false,
            'ob_gate_mode'          => $config['orderbook_wall_gate_mode'] ?? 'soft_demote',
            'ob_fetch_ok'           => false,
            'ob_ask_wall_distance_pct' => null,
            'ob_bid_wall_distance_pct' => null,
            'ob_ask_wall_status'    => null,
            'ob_bid_wall_status'    => null,
        ];

        if (!$gateEnabled || $this->obcService === null) {
            return $defaultResult;
        }

        $minQualityForFetch = (float)($config['orderbook_wall_fetch_after_score'] ?? 0.75);
        if ($quality['final_candidate_score'] < $minQualityForFetch) {
            return array_merge($defaultResult, ['ob_skip_reason' => 'quality_below_obc_threshold']);
        }

        $this->obcCheckedTotal++;

        try {
            $wallCtx = $this->obcService->getWallContext($symbol);
            if ($wallCtx === null) {
                $this->obcFetchFailedTotal++;
                return array_merge($defaultResult, ['ob_fetch_ok' => false]);
            }
            $this->obcFetchSuccessTotal++;

            $nearPct       = (float)($config['orderbook_wall_near_pct'] ?? 1.2);
            $requirePersist= (bool)($config['orderbook_wall_persistent_required'] ?? true);
            $gateMode      = (string)($config['orderbook_wall_gate_mode'] ?? 'soft_demote');

            $askWall = $wallCtx['nearest_ask_wall'] ?? null;
            $bidWall = $wallCtx['nearest_bid_wall'] ?? null;

            $askDist    = null;
            $askStatus  = 'none';
            $askRisk    = false;
            $bidDist    = null;
            $bidStatus  = 'none';
            $bidSupport = false;

            if ($askWall && isset($askWall['price']) && $entryPrice > 0) {
                $askDist   = abs($askWall['price'] - $entryPrice) / $entryPrice * 100;
                $askStatus = $wallCtx['ask_wall_status'] ?? 'present';
                if ($askDist <= $nearPct) {
                    $isPersistent = (bool)($askWall['persistent'] ?? false);
                    if (!$requirePersist || $isPersistent) {
                        $askRisk = true;
                    }
                }
            }

            if ($bidWall && isset($bidWall['price']) && $entryPrice > 0) {
                $bidDist   = abs($entryPrice - $bidWall['price']) / $entryPrice * 100;
                $bidStatus = $wallCtx['bid_wall_status'] ?? 'present';
                if ($bidDist <= $nearPct) {
                    $isPersistent = (bool)($bidWall['persistent'] ?? false);
                    if (!$requirePersist || $isPersistent) {
                        $bidSupport = true;
                    }
                }
            }

            // LONG: ask wall above = risk; bid wall below = support
            // SHORT: bid wall below = risk; ask wall above = resistance
            $isSoftDemoted = false;
            $bidWallRisk   = false;
            $askWallRes    = false;

            if ($side === 'long') {
                $isSoftDemoted = $askRisk;
            } else {
                $bidWallRisk   = $bidSupport; // for short, nearby bid wall = risk
                $isSoftDemoted = $bidWallRisk;
                $askWallRes    = $askRisk;    // ask wall above = resistance (bonus for short)
            }

            if ($isSoftDemoted) {
                $this->obcSoftDemoteTotal++;
            }

            return [
                'ob_wall_checked'        => true,
                'ob_wall_context'        => $wallCtx,
                'ob_ask_wall_risk'       => $askRisk,
                'ob_bid_wall_risk'       => $bidWallRisk,
                'ob_bid_wall_support'    => $bidSupport && $side === 'long',
                'ob_ask_wall_resistance' => $askWallRes,
                'ob_soft_demoted'        => $isSoftDemoted,
                'ob_gate_mode'           => $gateMode,
                'ob_fetch_ok'            => true,
                'ob_ask_wall_distance_pct' => $askDist !== null ? round($askDist, 4) : null,
                'ob_bid_wall_distance_pct' => $bidDist !== null ? round($bidDist, 4) : null,
                'ob_ask_wall_status'     => $askStatus,
                'ob_bid_wall_status'     => $bidStatus,
            ];
        } catch (\Throwable) {
            $this->obcFetchFailedTotal++;
            return array_merge($defaultResult, ['ob_fetch_ok' => false, 'ob_error' => 'exception']);
        }
    }

    // ── Candidate and signal builders ──────────────────────────────────────────

    private function buildCandidate(
        string $symbol,
        string $side,
        array  $structure,
        array  $quality,
        array  $obcResult,
        array  $config
    ): array {
        $softDemoteBlocksHandoff = (bool)($config['orderbook_wall_soft_demote_blocks_handoff'] ?? true);
        $isSoftDemoted           = (bool)($obcResult['ob_soft_demoted'] ?? false);
        $handoffEnabled          = (bool)($config['handoff_enabled'] ?? true);

        $blockReason    = null;
        $handoffReady   = true;
        $executable     = true;

        if ($isSoftDemoted && $softDemoteBlocksHandoff) {
            $blockReason  = 'ob_soft_demote_wall_risk';
            $handoffReady = false;
            $executable   = false;
            $this->obcSoftDemoteBlockedHandoff++;
            if (count($this->obcBlockExamples) < 5) {
                $this->obcBlockExamples[] = [
                    'symbol' => $symbol,
                    'side'   => $side,
                    'block_reason' => $blockReason,
                ];
            }
        } elseif ($isSoftDemoted) {
            // soft_demote annotation only — does not block
            $this->obcSoftDemoteAllowedHandoff++;
        }

        if (!$handoffEnabled) {
            $executable   = false;
            $handoffReady = false;
        }

        $detectedAt = date('c');
        $candidate  = array_merge($structure, $quality, $obcResult, [
            'strategy_id'    => 'confirmed_continuation',
            'symbol'         => $symbol,
            'side'           => $side,
            'detected_at'    => $detectedAt,
            'refreshed_at'   => $detectedAt,
            'active_final'   => !$isSoftDemoted || !$softDemoteBlocksHandoff,
            'stale'          => false,
            'stale_reason'   => null,
            'handoff_ready'  => $handoffReady,
            'executable'     => $executable,
            'block_reason'   => $blockReason,
        ]);

        return $candidate;
    }

    private function buildSignal(array $candidate, array $config): array
    {
        $side = (string)($candidate['side'] ?? 'long');
        $now  = date('c');

        $signalId = 'cc_' . substr(md5(
            ($candidate['symbol'] ?? '') . ':' . $side . ':' . ($candidate['entry_price'] ?? '') . ':' . $now
        ), 0, 16);

        $signal = [
            'strategy_id'            => 'confirmed_continuation',
            'signal_id'              => $signalId,
            'symbol'                 => $candidate['symbol'] ?? '',
            'side'                   => $side,
            'entry_price'            => $candidate['entry_price'] ?? null,
            'confidence_score'       => $candidate['final_candidate_score'] ?? 0.0,
            'candidate_quality_score'=> $candidate['candidate_quality_score'] ?? 0.0,
            'setup_class'            => $candidate['setup_class'] ?? '',
            'detected_at'            => $candidate['detected_at'] ?? $now,
            'refreshed_at'           => $now,
            'active_final'           => $candidate['active_final']  ?? true,
            'stale'                  => $candidate['stale']         ?? false,
            'stale_reason'           => $candidate['stale_reason']  ?? null,
            'handoff_ready'          => $candidate['handoff_ready'] ?? true,
            'executable'             => $candidate['executable']    ?? true,
            'block_reason'           => $candidate['block_reason']  ?? null,
            // OBC fields
            'ob_wall_checked'        => $candidate['ob_wall_checked']        ?? false,
            'ob_wall_context'        => null,  // omit full context from signal to reduce size
            'ob_ask_wall_risk'       => $candidate['ob_ask_wall_risk']       ?? false,
            'ob_bid_wall_risk'       => $candidate['ob_bid_wall_risk']       ?? false,
            'ob_bid_wall_support'    => $candidate['ob_bid_wall_support']    ?? false,
            'ob_ask_wall_resistance' => $candidate['ob_ask_wall_resistance'] ?? false,
            'ob_soft_demoted'        => $candidate['ob_soft_demoted']        ?? false,
            'ob_gate_mode'           => $candidate['ob_gate_mode']           ?? 'soft_demote',
            'ob_fetch_ok'            => $candidate['ob_fetch_ok']            ?? false,
            'ob_ask_wall_distance_pct' => $candidate['ob_ask_wall_distance_pct'] ?? null,
            'ob_bid_wall_distance_pct' => $candidate['ob_bid_wall_distance_pct'] ?? null,
            'ob_ask_wall_status'     => $candidate['ob_ask_wall_status']     ?? null,
            'ob_bid_wall_status'     => $candidate['ob_bid_wall_status']     ?? null,
        ];

        // Side-specific fields
        if ($side === 'long') {
            $signal['higher_lows_count']               = $candidate['higher_lows_count']    ?? 0;
            $signal['last_higher_low_price']           = $candidate['last_higher_low_price'] ?? null;
            $signal['rising_support_price']            = $candidate['rising_support_price']  ?? null;
            $signal['retest_price']                    = $candidate['retest_price']           ?? null;
            $signal['retest_held']                     = $candidate['retest_held']            ?? false;
            $signal['continuation_reclaim_price']      = $candidate['continuation_reclaim_price'] ?? null;
            $signal['trend_phase']                     = 'confirmed_mid_trend_continuation';
            $signal['entry_distance_from_last_higher_low_pct'] = $candidate['entry_distance_from_last_higher_low_pct'] ?? null;
        } else {
            $signal['lower_highs_count']               = $candidate['lower_highs_count']    ?? 0;
            $signal['last_lower_high_price']           = $candidate['last_lower_high_price'] ?? null;
            $signal['falling_resistance_price']        = $candidate['falling_resistance_price'] ?? null;
            $signal['retest_price']                    = $candidate['retest_price']           ?? null;
            $signal['retest_held']                     = $candidate['retest_held']            ?? false;
            $signal['continuation_breakdown_price']    = $candidate['continuation_breakdown_price'] ?? null;
            $signal['trend_phase']                     = 'confirmed_downtrend_continuation';
            $signal['entry_distance_from_last_lower_high_pct'] = $candidate['entry_distance_from_last_lower_high_pct'] ?? null;
        }

        // Strategy signal context for PM handoff
        $strategySignalContext = [
            'strategy_id'                => 'confirmed_continuation',
            'setup_class'                => $signal['setup_class'],
            'candidate_quality_score'    => $signal['candidate_quality_score'],
            'structure_score'            => $candidate['structure_score']          ?? null,
            'retest_score'               => $candidate['retest_score']             ?? null,
            'continuation_score'         => $candidate['continuation_score']       ?? null,
            'entry_distance_score'       => $candidate['entry_distance_score']     ?? null,
            'pullback_quality_score'     => $candidate['pullback_quality_score']   ?? null,
            'volume_persistence_score'   => $candidate['volume_persistence_score'] ?? null,
            // OBC fields in context
            'ob_wall_checked'            => $signal['ob_wall_checked'],
            'ob_ask_wall_risk'           => $signal['ob_ask_wall_risk'],
            'ob_bid_wall_risk'           => $signal['ob_bid_wall_risk'],
            'ob_bid_wall_support'        => $signal['ob_bid_wall_support'],
            'ob_ask_wall_resistance'     => $signal['ob_ask_wall_resistance'],
            'ob_soft_demoted'            => $signal['ob_soft_demoted'],
            'ob_gate_mode'               => $signal['ob_gate_mode'],
            'ob_fetch_ok'                => $signal['ob_fetch_ok'],
            'ob_ask_wall_distance_pct'   => $signal['ob_ask_wall_distance_pct'],
            'ob_bid_wall_distance_pct'   => $signal['ob_bid_wall_distance_pct'],
            'ob_ask_wall_status'         => $signal['ob_ask_wall_status'],
            'ob_bid_wall_status'         => $signal['ob_bid_wall_status'],
            'block_reason'               => $signal['block_reason'],
            'handoff_ready'              => $signal['handoff_ready'],
            'executable'                 => $signal['executable'],
            'active_final'               => $signal['active_final'],
        ];

        // PM context hints
        if ($side === 'long') {
            $strategySignalContext['preferred_pm_phase']         = 'confirmed_mid_trend_continuation';
            $strategySignalContext['allow_mid_trend_hold']        = true;
            $strategySignalContext['structure_stop_reference']    = $candidate['last_higher_low_price'] ?? null;
            $strategySignalContext['trend_structure_intact']      = $candidate['structure_holds'] ?? true;
            $strategySignalContext['higher_lows_count']           = $signal['higher_lows_count']          ?? 0;
            $strategySignalContext['last_higher_low_price']       = $signal['last_higher_low_price']       ?? null;
            $strategySignalContext['rising_support_price']        = $signal['rising_support_price']        ?? null;
            $strategySignalContext['retest_price']                = $signal['retest_price']                ?? null;
            $strategySignalContext['retest_held']                 = $signal['retest_held']                 ?? false;
            $strategySignalContext['continuation_reclaim_price']  = $signal['continuation_reclaim_price']  ?? null;
            $strategySignalContext['trend_phase']                 = 'confirmed_mid_trend_continuation';
        } else {
            $strategySignalContext['preferred_pm_phase']         = 'confirmed_downtrend_continuation';
            $strategySignalContext['structure_stop_reference']    = $candidate['last_lower_high_price'] ?? null;
            $strategySignalContext['trend_structure_intact']      = $candidate['structure_holds'] ?? true;
            $strategySignalContext['lower_highs_count']           = $signal['lower_highs_count']          ?? 0;
            $strategySignalContext['last_lower_high_price']       = $signal['last_lower_high_price']       ?? null;
            $strategySignalContext['falling_resistance_price']    = $signal['falling_resistance_price']    ?? null;
            $strategySignalContext['retest_price']                = $signal['retest_price']                ?? null;
            $strategySignalContext['retest_held']                 = $signal['retest_held']                 ?? false;
            $strategySignalContext['continuation_breakdown_price']= $signal['continuation_breakdown_price'] ?? null;
            $strategySignalContext['trend_phase']                 = 'confirmed_downtrend_continuation';
        }

        $signal['strategy_signal_context'] = $strategySignalContext;

        return $signal;
    }

    private function buildHandoffQueue(array $signals, array $config): array
    {
        $queue = [];
        foreach ($signals as $sig) {
            if (!($sig['executable'] ?? false) || ($sig['stale'] ?? false)) {
                continue;
            }
            $entry = [
                'strategy_id'             => 'confirmed_continuation',
                'signal_id'               => $sig['signal_id'] ?? '',
                'symbol'                  => $sig['symbol'] ?? '',
                'side'                    => $sig['side'] ?? '',
                'entry_price'             => $sig['entry_price'] ?? null,
                'confidence_score'        => $sig['confidence_score'] ?? 0.0,
                'candidate_quality_score' => $sig['candidate_quality_score'] ?? 0.0,
                'setup_class'             => $sig['setup_class'] ?? '',
                'detected_at'             => $sig['detected_at'] ?? '',
                'handoff_ready'           => true,
                'executable'              => true,
                'active_final'            => $sig['active_final'] ?? true,
                'strategy_signal_context' => $sig['strategy_signal_context'] ?? [],
            ];
            $queue[] = $entry;
        }
        return $queue;
    }

    // ── last_run builder ───────────────────────────────────────────────────────

    private function buildLastRun(
        array  $config,
        string $startedAt,
        string $finishedAt,
        int    $durationMs,
        array  $signals
    ): array {
        return [
            'strategy_id'                     => 'confirmed_continuation',
            'strategy_is_environment_neutral' => true,
            'execution_mode_used_for_selection' => false,
            'status'                          => 'done',
            'started_at'                      => $startedAt,
            'finished_at'                     => $finishedAt,
            'duration_ms'                     => $durationMs,
            'candidates_total'                => $this->candidatesTotal,
            'long_candidates_total'           => $this->longCandidatesTotal,
            'short_candidates_total'          => $this->shortCandidatesTotal,
            'signals_total'                   => $this->signalsTotal,
            'long_signals_total'              => $this->longSignalsTotal,
            'short_signals_total'             => $this->shortSignalsTotal,
            'handoff_ready_total'             => $this->handoffReadyTotal,
            'rejected_total'                  => $this->rejectedTotal,
            'reject_reason_counts'            => $this->rejectReasonCounts,
            'obc_checked_total'               => $this->obcCheckedTotal,
            'obc_soft_demote_blocked_total'   => $this->obcSoftDemoteBlockedHandoff,
            'accepted_examples'               => $this->acceptedExamples,
            'rejected_examples'               => $this->rejectedExamples,
            'obc_block_examples'              => $this->obcBlockExamples,
            'late_entry_reject_examples'      => $this->lateEntryRejectExamples,
            'first_bounce_reject_examples'    => $this->firstBounceRejectExamples,
            'no_retest_reject_examples'       => $this->noRetestRejectExamples,
            'active_signals_total'            => count(array_filter($signals, fn($s) => !($s['stale'] ?? false) && ($s['active_final'] ?? false))),
        ];
    }

    // ── Candle fetch ───────────────────────────────────────────────────────────

    /**
     * Fetch OHLCV candles from Bybit.  Returns [] on any failure.
     */
    private function fetchCandles(string $symbol, int $limit, array $config): array
    {
        $baseUrl = rtrim((string)($config['bybit_base_url'] ?? 'https://api.bybit.com'), '/');
        $timeout = max(3, (int)($config['bybit_timeout_sec'] ?? 6));

        $url = $baseUrl . '/v5/market/kline?' . http_build_query([
            'category' => 'linear',
            'symbol'   => $symbol,
            'interval' => '1',
            'limit'    => min($limit, 200),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!isset($decoded['result']['list']) || !is_array($decoded['result']['list'])) {
            return [];
        }

        $candles = [];
        foreach (array_reverse($decoded['result']['list']) as $row) {
            if (!is_array($row) || count($row) < 6) {
                continue;
            }
            $candles[] = [
                'ts'     => (int)$row[0],
                'open'   => (float)$row[1],
                'high'   => (float)$row[2],
                'low'    => (float)$row[3],
                'close'  => (float)$row[4],
                'volume' => (float)$row[5],
            ];
        }
        return $candles;
    }

    // ── Universe ───────────────────────────────────────────────────────────────

    private function fetchUniverse(array $config): array
    {
        // Default: read from a shared symbols file if available; else return a small demo set
        $symbolsFile = $this->repoRoot . '/modules/parser/storage/symbols.json';
        if (is_file($symbolsFile)) {
            $data = $this->readJson($symbolsFile, []);
            if (is_array($data) && !empty($data)) {
                $symbols = [];
                foreach ($data as $item) {
                    if (is_array($item) && isset($item['symbol'])) {
                        $symbols[] = (string)$item['symbol'];
                    } elseif (is_string($item)) {
                        $symbols[] = $item;
                    }
                }
                if (!empty($symbols)) {
                    return array_slice($symbols, 0, (int)($config['max_symbols_per_run'] ?? 50));
                }
            }
        }
        return [];
    }

    // ── Utilities ──────────────────────────────────────────────────────────────

    private function resetCounters(): void
    {
        $this->obcCheckedTotal             = 0;
        $this->obcFetchSuccessTotal        = 0;
        $this->obcFetchFailedTotal         = 0;
        $this->obcSoftDemoteTotal          = 0;
        $this->obcSoftDemoteBlockedHandoff = 0;
        $this->obcSoftDemoteAllowedHandoff = 0;
        $this->obcBlockExamples            = [];
        $this->candidatesTotal             = 0;
        $this->longCandidatesTotal         = 0;
        $this->shortCandidatesTotal        = 0;
        $this->signalsTotal                = 0;
        $this->longSignalsTotal            = 0;
        $this->shortSignalsTotal           = 0;
        $this->handoffReadyTotal           = 0;
        $this->rejectedTotal               = 0;
        $this->rejectReasonCounts          = [];
        $this->acceptedExamples            = [];
        $this->rejectedExamples            = [];
        $this->lateEntryRejectExamples     = [];
        $this->firstBounceRejectExamples   = [];
        $this->noRetestRejectExamples      = [];
    }

    private function readJson(string $path, mixed $default = []): mixed
    {
        if (!is_file($path) || !is_readable($path)) {
            return $default;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return $default;
        }
        $decoded = @json_decode($raw, true);
        return ($decoded !== null) ? $decoded : $default;
    }

    private function writeJson(string $path, mixed $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false && @file_put_contents($path, $json, LOCK_EX) !== false;
    }
}
