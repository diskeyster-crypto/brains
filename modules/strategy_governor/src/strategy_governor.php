<?php

declare(strict_types=1);

/**
 * Strategy Governor — Core Engine (V2 Shadow Signal Lifecycle)
 *
 * Observes strategy signals, bot queues, active positions and closed trades.
 * Writes shadow-only recommended decisions.  Does NOT touch bot queues,
 * strategy files, Profit Manager, Stop Manager or any exchange API.
 *
 * Signal lifecycle:
 *   - Signals are normalised into a canonical structure keyed by governor_signal_key.
 *   - Signals without a signal_id are immediately rejected (invalid).
 *   - Signals older than max_signal_age_seconds are rejected (stale) without
 *     re-journalling on subsequent runs.
 *   - Corridor-like strategies that require confirmation: pending → wait_confirmation
 *     for N ticks, then final decision.
 *   - Default strategies: immediate shadow decision (approve_demo_shadow or
 *     reject_shadow) without any forced wait.
 *   - Final decisions are kept in pending_signals.json for TTL visibility.
 *   - Decision journal is written only when state actually changes (no spam).
 *
 * Entry point called by StrategyGovernorService:
 *   run(): array   — executes one Governor tick, returns last_run summary
 */

namespace Modules\StrategyGovernor;

final class StrategyGovernor
{
    // ── Decision state constants ──────────────────────────────────────────────
    private const STATE_OBSERVED            = 'observed';
    private const STATE_WAIT_CONFIRM        = 'wait_confirmation';
    private const STATE_APPROVE_DEMO_SHADOW = 'approve_demo_shadow';
    private const STATE_APPROVE_LIVE_SHADOW = 'approve_live_shadow';
    private const STATE_REJECT_SHADOW       = 'reject_shadow';
    private const STATE_EXPIRED_SHADOW      = 'expired_shadow';

    // ── Route constants ───────────────────────────────────────────────────────
    private const ROUTE_NONE  = 'none';
    private const ROUTE_DEMO  = 'demo';
    private const ROUTE_LIVE  = 'live';

    /** All terminal states — no further lifecycle transitions once reached. */
    private const FINAL_STATES = [
        self::STATE_APPROVE_DEMO_SHADOW,
        self::STATE_APPROVE_LIVE_SHADOW,
        self::STATE_REJECT_SHADOW,
        self::STATE_EXPIRED_SHADOW,
    ];

    // ── Properties ───────────────────────────────────────────────────────────
    private string $moduleDir;
    private string $repoRoot;
    private array  $config;

    // ── Constructor ───────────────────────────────────────────────────────────

    public function __construct(string $moduleDir, array $config)
    {
        $this->moduleDir = rtrim($moduleDir, '/');
        $this->repoRoot  = dirname($this->moduleDir, 2);
        $this->config    = $config;
    }

    // =========================================================================
    // Public entry point
    // =========================================================================

    /**
     * Execute one Governor tick.
     *
     * @return array  last_run summary
     */
    public function run(): array
    {
        $startedAt = date('Y-m-d H:i:s');
        $errors    = [];

        // ── Counters ──────────────────────────────────────────────────────────
        $strategiesSeen              = 0;
        $signalsSeen                 = 0;
        $normalizedTotal             = 0;
        $invalidTotal                = 0;
        $staleTotal                  = 0;
        $decisionsTotal              = 0;
        $approvedDemoShadow          = 0;
        $approvedLiveShadow          = 0;
        $rejectedShadow              = 0;
        $expiredShadow               = 0;
        $waitingConfirmation         = 0;
        $corridorPending             = 0;
        $defaultImmediateDecisions   = 0;
        $decisionsWritten            = 0;
        $finalDecisionsTotal         = 0;
        $pendingTotalForRun          = 0;
        $newPending                  = [];
        $decisionsBatch              = [];
        // Phase 3A: approved demo queue counters
        $queueEnabled                = false;
        $queueMode                   = 'shadow_bridge';
        $queueTotal                  = 0;
        $queueAdded                  = 0;
        $queueSkippedInvalid         = 0;
        $queueSkippedStale           = 0;
        $queueDeduped                = 0;
        $queueLimited                = 0;

        try {
            // ── 1. Load config values ─────────────────────────────────────────
            $globalMaxTicks     = max(0, (int)($this->config['pending_confirmation_ticks']      ?? 0));
            $stratPolicies      = is_array($this->config['strategy_policies'] ?? null)
                                    ? $this->config['strategy_policies']
                                    : [];
            $minClosed          = (int)($this->config['min_closed_trades_for_live']            ?? 20);
            $minHourly          = (int)($this->config['min_hourly_trades_for_live']            ?? 5);
            $minWinrate         = (float)($this->config['min_winrate_for_live']                ?? 0.55);
            $minAvgRoi          = (float)($this->config['min_avg_roi_for_live']                ?? 1.0);
            $maxConsecLosses    = (int)($this->config['max_consecutive_losses_live']           ?? 3);
            $mode               = (string)($this->config['mode']                               ?? 'shadow');
            $maxSignalAge       = (int)($this->config['max_signal_age_seconds']                ?? 1800);
            $maxPendingAge      = (int)($this->config['max_pending_age_seconds']               ?? 3600);
            $keepFinalInPending = (bool)($this->config['keep_final_decisions_in_pending']      ?? true);
            $finalDecisionTtl   = (int)($this->config['final_decision_ttl_seconds']           ?? 86400);
            // Phase 3A queue config
            $queueEnabled       = (bool)($this->config['approved_demo_queue_enabled']         ?? true);
            $queueMode          = (string)($this->config['approved_demo_queue_mode']          ?? 'shadow_bridge');
            $maxPerRun          = max(1, (int)($this->config['max_approved_demo_per_run']     ?? 10));
            $demoTtl            = max(60, (int)($this->config['approved_demo_ttl_seconds']    ?? 1800));

            // ── 2. Discover strategies ────────────────────────────────────────
            $strategyIds = $this->discoverStrategies();

            // ── 3. Build stats from closed trades ────────────────────────────
            $closedTrades = $this->loadClosedTrades();
            $stratStats   = $this->buildStrategyStats($closedTrades);
            $hourlyStats  = $this->buildHourlyStats($closedTrades);

            // ── 4. Collect active positions ───────────────────────────────────
            $activePositions = $this->loadJsonSafe(
                $this->repoRoot . '/modules/bot/storage/active_positions.json', []
            );

            // ── 5. Load pending signals (keyed by governor_signal_key) ────────
            $pendingSignals = $this->loadJsonSafe(
                $this->moduleDir . '/storage/pending_signals.json', []
            );
            if (!is_array($pendingSignals)) {
                $pendingSignals = [];
            }

            // ── 6. Compute open-positions count per strategy ──────────────────
            $openPerStrategy = [];
            if (is_array($activePositions)) {
                foreach ($activePositions as $pos) {
                    $sid = (string)($pos['strategy_id'] ?? $pos['owner_strategy'] ?? '');
                    if ($sid !== '') {
                        $openPerStrategy[$sid] = ($openPerStrategy[$sid] ?? 0) + 1;
                    }
                }
            }

            // ── 7. Collect and normalise signals from all strategies ──────────
            // Keyed by governor_signal_key so later signals win on collision.
            $normalizedSignals = [];
            foreach ($strategyIds as $stratId) {
                $strategiesSeen++;
                $rawSignals = $this->loadStrategySignals($stratId);
                foreach ($rawSignals as $raw) {
                    $signalsSeen++;
                    $norm = $this->normalizeSignal($raw, $stratId);
                    if ($norm === null) {
                        // Missing signal_id — invalid, do not create active pending entry.
                        $invalidTotal++;
                        // Generate a stable deduplication key so we do not re-journal the same
                        // raw signal on every Governor run (spam prevention).
                        // MD5 is used here purely as a cheap non-cryptographic hash to produce
                        // a short, stable fingerprint of the raw signal content.
                        $invalidKey = 'invalid:' . $stratId . ':' . substr(
                            md5(($raw['_source_file'] ?? '') . json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                            0, 16
                        );
                        // Journal only once — skip if already in pending as reject_shadow
                        $existingInvalid = $pendingSignals[$invalidKey] ?? null;
                        if ($existingInvalid === null || ($existingInvalid['state'] ?? '') !== self::STATE_REJECT_SHADOW) {
                            $invalidNorm = [
                                'governor_signal_key' => $invalidKey,
                                'signal_id'           => '',
                                'strategy_id'         => $stratId,
                                'symbol'              => (string)($raw['symbol']      ?? ''),
                                'side'                => (string)($raw['side']         ?? ''),
                                'mode'                => (string)($raw['mode']         ?? ''),
                                'entry_price'         => null,
                                'detected_at'         => '',
                            ];
                            $decisionsBatch[] = $this->buildDecisionRecord(
                                $invalidKey, $invalidNorm,
                                null, self::STATE_REJECT_SHADOW, self::STATE_REJECT_SHADOW,
                                self::ROUTE_NONE, 'missing_signal_id',
                                0, 0, $mode
                            );
                            $decisionsWritten++;
                            $decisionsTotal++;
                            $rejectedShadow++;
                            $nowStr = date('Y-m-d H:i:s');
                            $newPending[$invalidKey] = [
                                'governor_signal_key' => $invalidKey,
                                'signal_id'           => '',
                                'strategy_id'         => $stratId,
                                'symbol'              => (string)($raw['symbol']      ?? ''),
                                'side'                => (string)($raw['side']         ?? ''),
                                'mode'                => (string)($raw['mode']         ?? ''),
                                'entry_price'         => null,
                                'detected_at'         => '',
                                'tick_count'          => 0,
                                'max_ticks'           => 0,
                                'state'               => self::STATE_REJECT_SHADOW,
                                'reason'              => 'missing_signal_id',
                                'recommended_route'   => self::ROUTE_NONE,
                                'first_seen_at'       => $nowStr,
                                'last_seen_at'        => $nowStr,
                                'updated_at'          => $nowStr,
                            ];
                        } else {
                            // Already rejected — keep in newPending without re-journalling
                            $newPending[$invalidKey] = $existingInvalid;
                        }
                        continue;
                    }
                    $normalizedTotal++;
                    $normalizedSignals[$norm['governor_signal_key']] = $norm;
                }
            }

            $now = time();

            // ── 8. Process each current normalised signal ─────────────────────
            foreach ($normalizedSignals as $govKey => $norm) {
                $stratId  = $norm['strategy_id'];
                $existing = $pendingSignals[$govKey] ?? null;

                // Already reached a terminal state — keep as-is, no re-processing
                if ($existing !== null && in_array($existing['state'] ?? '', self::FINAL_STATES, true)) {
                    $newPending[$govKey] = $existing;
                    continue;
                }

                // ── Stale signal check ────────────────────────────────────────
                $detectedAt = (string)($norm['detected_at']);
                $detectedTs = $this->parseTimestamp($detectedAt);
                $signalAge = ($detectedTs > 0) ? ($now - $detectedTs) : PHP_INT_MAX;

                if ($signalAge > $maxSignalAge) {
                    $staleTotal++;
                    $prevState = $existing['state'] ?? null;
                    // Only journal on state change — avoids repeat-reject spam
                    if ($prevState !== self::STATE_REJECT_SHADOW) {
                        $decisionsBatch[] = $this->buildDecisionRecord(
                            $govKey, $norm, $prevState,
                            self::STATE_REJECT_SHADOW, self::STATE_REJECT_SHADOW,
                            self::ROUTE_NONE, 'signal_too_old',
                            (int)($existing['tick_count'] ?? 0), 0, $mode
                        );
                        $decisionsWritten++;
                        $decisionsTotal++;
                        $rejectedShadow++;
                    }
                    $pEntry                  = $existing ?? $this->initPendingEntry($norm);
                    $pEntry['state']         = self::STATE_REJECT_SHADOW;
                    $pEntry['reason']        = 'signal_too_old';
                    $pEntry['updated_at']    = date('Y-m-d H:i:s');
                    $pEntry['last_seen_at']  = date('Y-m-d H:i:s');
                    $newPending[$govKey]     = $pEntry;
                    continue;
                }

                // ── Resolve per-strategy confirmation policy ──────────────────
                $policy          = $this->resolvePolicy($stratId, $stratPolicies, $globalMaxTicks);
                $confirmRequired = $policy['confirmation_required'];
                $maxTicks        = $policy['pending_confirmation_ticks'];

                // Initialise or retrieve pending entry
                $pEntry                 = $existing ?? $this->initPendingEntry($norm);
                $pEntry['last_seen_at'] = date('Y-m-d H:i:s');
                $pEntry['max_ticks']    = $maxTicks;
                $prevState              = $pEntry['state'] ?? null;

                if (!$confirmRequired) {
                    // ── Immediate shadow decision ─────────────────────────────
                    $defaultImmediateDecisions++;
                    [$decision, $route, $reason] = $this->decideImmediate(
                        $norm, $stratStats[$stratId] ?? [],
                        $minClosed, $minWinrate, $minAvgRoi, $maxConsecLosses
                    );
                    $decisionsTotal++;
                    $pEntry['state']             = $decision;
                    $pEntry['reason']            = $reason;
                    $pEntry['updated_at']        = date('Y-m-d H:i:s');
                    $pEntry['recommended_route'] = $route;

                    // Journal only on state change — avoids re-writing same entry every run
                    if ($prevState !== $decision) {
                        $decisionsBatch[] = $this->buildDecisionRecord(
                            $govKey, $norm, $prevState, $decision,
                            $decision, $route, $reason, 0, 0, $mode
                        );
                        $decisionsWritten++;
                    }

                    match ($decision) {
                        self::STATE_APPROVE_DEMO_SHADOW => $approvedDemoShadow++,
                        self::STATE_APPROVE_LIVE_SHADOW => $approvedLiveShadow++,
                        self::STATE_REJECT_SHADOW       => $rejectedShadow++,
                        default                         => null,
                    };
                    $newPending[$govKey] = $pEntry;

                } else {
                    // ── Confirmation-required lifecycle ───────────────────────
                    $currentState = $pEntry['state'] ?? null;

                    // Only advance if not already in a final state
                    if (!in_array($currentState, self::FINAL_STATES, true)) {
                        $pEntry['tick_count'] = (int)($pEntry['tick_count'] ?? 0) + 1;
                        $tickCount            = $pEntry['tick_count'];
                        $pEntry['updated_at'] = date('Y-m-d H:i:s');

                        // Per-tick hard-reject check (signal validity during window)
                        $hardRejectReason = $this->checkHardReject($norm, $maxSignalAge);

                        if ($hardRejectReason !== null) {
                            // Hard reject during confirmation window
                            $pEntry['state']  = self::STATE_REJECT_SHADOW;
                            $pEntry['reason'] = $hardRejectReason;
                            $rejectedShadow++;
                            $decisionsTotal++;
                            $decisionsBatch[] = $this->buildDecisionRecord(
                                $govKey, $norm, $currentState,
                                self::STATE_REJECT_SHADOW, self::STATE_REJECT_SHADOW,
                                self::ROUTE_NONE, $hardRejectReason,
                                $tickCount, $maxTicks, $mode
                            );
                            $decisionsWritten++;

                        } elseif ($tickCount < $maxTicks) {
                            // Still accumulating confirmation ticks
                            $pEntry['state'] = self::STATE_WAIT_CONFIRM;
                            $waitingConfirmation++;
                            if ($stratId === 'corridor_bottom_long') {
                                $corridorPending++;
                            }
                            // Journal on first observation or each tick advance
                            $isNew = ($prevState === null || $prevState === '');
                            if ($isNew || $currentState === self::STATE_WAIT_CONFIRM) {
                                $decisionsBatch[] = $this->buildDecisionRecord(
                                    $govKey, $norm, $prevState,
                                    self::STATE_WAIT_CONFIRM, self::STATE_WAIT_CONFIRM,
                                    self::ROUTE_NONE, 'pending_confirmation_tick',
                                    $tickCount, $maxTicks, $mode
                                );
                                $decisionsWritten++;
                            }

                        } else {
                            // Confirmation window complete — make final decision
                            [$decision, $route, $reason] = $this->decideImmediate(
                                $norm, $stratStats[$stratId] ?? [],
                                $minClosed, $minWinrate, $minAvgRoi, $maxConsecLosses
                            );
                            $pEntry['state']             = $decision;
                            $pEntry['reason']            = $reason;
                            $pEntry['recommended_route'] = $route;
                            $decisionsTotal++;

                            $decisionsBatch[] = $this->buildDecisionRecord(
                                $govKey, $norm, self::STATE_WAIT_CONFIRM, $decision,
                                $decision, $route, $reason,
                                $tickCount, $maxTicks, $mode
                            );
                            $decisionsWritten++;

                            match ($decision) {
                                self::STATE_APPROVE_DEMO_SHADOW => $approvedDemoShadow++,
                                self::STATE_APPROVE_LIVE_SHADOW => $approvedLiveShadow++,
                                self::STATE_REJECT_SHADOW       => $rejectedShadow++,
                                default                         => null,
                            };
                        }
                    }
                    $newPending[$govKey] = $pEntry;
                }
            }

            // ── 9. Handle pending signals NOT in the current batch ────────────
            // These are signals that disappeared from strategy storage this run.
            foreach ($pendingSignals as $govKey => $pEntry) {
                if (isset($normalizedSignals[$govKey]) || isset($newPending[$govKey])) {
                    // Already handled above
                    continue;
                }

                $pState    = $pEntry['state'] ?? '';
                $firstSeen = $this->parseTimestamp((string)($pEntry['first_seen_at'] ?? ''));

                if (in_array($pState, self::FINAL_STATES, true)) {
                    // Final state — keep for TTL cleanup pass
                    $newPending[$govKey] = $pEntry;
                } elseif ($firstSeen > 0 && ($now - $firstSeen) > $maxPendingAge) {
                    // Signal disappeared and pending window exceeded → expire
                    $expiredShadow++;
                    $decisionsTotal++;
                    $pEntry['state']      = self::STATE_EXPIRED_SHADOW;
                    $pEntry['reason']     = 'signal_disappeared_pending_expired';
                    $pEntry['updated_at'] = date('Y-m-d H:i:s');

                    $decisionsBatch[] = $this->buildDecisionRecord(
                        $govKey,
                        [
                            'governor_signal_key' => $govKey,
                            'signal_id'           => $pEntry['signal_id']   ?? '',
                            'strategy_id'         => $pEntry['strategy_id'] ?? '',
                            'symbol'              => $pEntry['symbol']      ?? '',
                            'side'                => $pEntry['side']        ?? '',
                            'mode'                => $pEntry['mode']        ?? '',
                            'entry_price'         => $pEntry['entry_price'] ?? null,
                            'detected_at'         => $pEntry['detected_at'] ?? '',
                        ],
                        $pState, self::STATE_EXPIRED_SHADOW,
                        self::STATE_EXPIRED_SHADOW, self::ROUTE_NONE,
                        'signal_disappeared_pending_expired',
                        (int)($pEntry['tick_count'] ?? 0),
                        (int)($pEntry['max_ticks']  ?? 0),
                        $mode
                    );
                    $decisionsWritten++;
                    $newPending[$govKey] = $pEntry;
                } else {
                    // Not yet expired — keep pending (will be re-evaluated next tick)
                    $newPending[$govKey] = $pEntry;
                }
            }

            // ── 10. TTL cleanup for final decisions ───────────────────────────
            $cutoff = $now - $finalDecisionTtl;
            foreach (array_keys($newPending) as $govKey) {
                $pEntry = $newPending[$govKey];
                if (!in_array($pEntry['state'] ?? '', self::FINAL_STATES, true)) {
                    continue;
                }
                if (!$keepFinalInPending) {
                    unset($newPending[$govKey]);
                    continue;
                }
                $updatedTs = $this->parseTimestamp((string)($pEntry['updated_at'] ?? $pEntry['first_seen_at'] ?? ''));
                if ($updatedTs > 0 && $updatedTs < $cutoff) {
                    unset($newPending[$govKey]);
                }
            }

            // ── 11. Tally pending stats ───────────────────────────────────────
            $pendingTotalForRun = count($newPending);
            // Per-run transition counters
            foreach ($newPending as $pEntry) {
                if (in_array($pEntry['state'] ?? '', self::FINAL_STATES, true)) {
                    $finalDecisionsTotal++;
                }
            }
            // Current-state snapshot counters (reflect actual state of pending_signals.json)
            $currPendingTotal        = count($newPending);
            $currWaitConfirmation    = 0;
            $currApprovedDemoShadow  = 0;
            $currApprovedLiveShadow  = 0;
            $currRejectedShadow      = 0;
            $currExpiredShadow       = 0;
            $currFinalDecisions      = 0;
            foreach ($newPending as $pEntry) {
                $s = $pEntry['state'] ?? '';
                match ($s) {
                    self::STATE_WAIT_CONFIRM        => $currWaitConfirmation++,
                    self::STATE_APPROVE_DEMO_SHADOW => $currApprovedDemoShadow++,
                    self::STATE_APPROVE_LIVE_SHADOW => $currApprovedLiveShadow++,
                    self::STATE_REJECT_SHADOW       => $currRejectedShadow++,
                    self::STATE_EXPIRED_SHADOW      => $currExpiredShadow++,
                    default                         => null,
                };
                if (in_array($s, self::FINAL_STATES, true)) {
                    $currFinalDecisions++;
                }
            }

            // ── 12. Persist state files ───────────────────────────────────────
            $this->savePendingSignals($newPending);
            $this->saveStrategyStats(
                $stratStats, $openPerStrategy, $strategyIds,
                $minClosed, $minWinrate, $minAvgRoi, $maxConsecLosses
            );
            $this->saveHourlyStats($hourlyStats);
            $this->appendDecisions($decisionsBatch);

            // ── 13. Phase 3A: build approved demo queue ───────────────────────
            $queuePath     = $this->moduleDir . '/storage/approved_demo_queue.json';
            $existingQueue = $this->loadJsonSafe($queuePath, []);
            if (!is_array($existingQueue)) {
                $existingQueue = [];
            }

            // Index existing queue entries by governor_signal_key for O(1) dedup lookup
            $existingQueueByKey = [];
            foreach ($existingQueue as $qItem) {
                $k = (string)($qItem['governor_signal_key'] ?? '');
                if ($k !== '') {
                    $existingQueueByKey[$k] = $qItem;
                }
            }

            $newQueue          = [];   // keyed by governor_signal_key
            $queueJournalBatch = [];

            if ($queueEnabled) {
                foreach ($newPending as $govKey => $pEntry) {
                    // Only process approve_demo_shadow + demo route entries
                    if (($pEntry['state']              ?? '') !== self::STATE_APPROVE_DEMO_SHADOW
                        || ($pEntry['recommended_route'] ?? '') !== self::ROUTE_DEMO
                    ) {
                        continue;
                    }

                    // Build a pseudo-norm for validateSignalBasic (pending entry has all required fields).
                    // For approve_demo_shadow signals with recommended_route=demo, mode may be absent in
                    // the pending record — normalise it to 'demo' so the basic validator does not reject.
                    $pMode = (string)($pEntry['mode'] ?? '');
                    if ($pMode === '') {
                        $pMode = 'demo'; // safe: we are inside the ROUTE_DEMO filter above
                    }
                    $pseudoNorm = [
                        'governor_signal_key' => $govKey,
                        'signal_id'           => $pEntry['signal_id']   ?? '',
                        'strategy_id'         => $pEntry['strategy_id'] ?? '',
                        'symbol'              => $pEntry['symbol']       ?? '',
                        'side'                => $pEntry['side']         ?? '',
                        'mode'                => $pMode,
                        'entry_price'         => $pEntry['entry_price']  ?? null,
                        'detected_at'         => $pEntry['detected_at']  ?? '',
                        'handoff_valid'       => null, // not stored in pending; treated as unset
                    ];

                    // Basic signal validity check
                    if ($this->validateSignalBasic($pseudoNorm) !== null) {
                        $queueSkippedInvalid++;
                        continue;
                    }

                    // Age check: signal must not be older than the demo TTL
                    $detectedTs = $this->parseTimestamp((string)($pEntry['detected_at'] ?? ''));
                    if ($detectedTs > 0 && ($now - $detectedTs) > $demoTtl) {
                        $queueSkippedStale++;
                        continue;
                    }

                    // Deduplication: already in queue from a previous run
                    if (isset($existingQueueByKey[$govKey])) {
                        $carried                   = $existingQueueByKey[$govKey];
                        $carried['last_seen_at']   = date('Y-m-d H:i:s');
                        $newQueue[$govKey]          = $carried;
                        $queueDeduped++;
                        continue;
                    }

                    // Per-run limit
                    if ($queueAdded >= $maxPerRun) {
                        $queueLimited++;
                        continue;
                    }

                    // Build new queue item
                    $approvedAt   = (string)($pEntry['updated_at'] ?? $pEntry['last_seen_at'] ?? date('Y-m-d H:i:s'));
                    $approvedTs   = $this->parseTimestamp($approvedAt);
                    $ttlExpiresAt = date('Y-m-d H:i:s', ($approvedTs > 0 ? $approvedTs : $now) + $demoTtl);

                    // Pull extra fields from the normalised signal if it is still available
                    $norm = $normalizedSignals[$govKey] ?? null;

                    $newQueue[$govKey] = [
                        'governor_queue_id'   => 'gq_' . substr(md5($govKey . $approvedAt), 0, 12),
                        'governor_signal_key' => $govKey,
                        'signal_id'           => $pEntry['signal_id']   ?? '',
                        'strategy_id'         => $pEntry['strategy_id'] ?? '',
                        'symbol'              => $pEntry['symbol']       ?? '',
                        'side'                => $pEntry['side']         ?? '',
                        'mode'                => $pMode,           // normalised: always 'demo' for this branch
                        'entry_price'         => $pEntry['entry_price']  ?? null,
                        'entry_mode'          => $norm['entry_mode']     ?? null,
                        'entry_type'          => $norm['entry_type']     ?? null,
                        'detected_at'         => $pEntry['detected_at']  ?? '',
                        'created_at'          => $pEntry['first_seen_at'] ?? '',
                        'approved_at'         => $approvedAt,
                        'last_seen_at'        => date('Y-m-d H:i:s'),
                        'decision_id'         => '',
                        'source'              => $norm ? ($norm['source']      ?? '') : '',
                        'source_file'         => $norm ? ($norm['source_file'] ?? '') : '',
                        'governor_reason'     => $pEntry['reason'] ?? '',
                        'governor_state'      => $pEntry['state']  ?? '',
                        'ttl_expires_at'      => $ttlExpiresAt,
                    ];
                    $queueAdded++;

                    // Journal one event per new queue addition (no repeat spam)
                    $queueJournalBatch[] = $this->buildDecisionRecord(
                        $govKey, $pseudoNorm,
                        self::STATE_APPROVE_DEMO_SHADOW, 'approved_demo_queued_shadow',
                        'approved_demo_queued_shadow', self::ROUTE_DEMO,
                        'governor_demo_queue_shadow_bridge',
                        (int)($pEntry['tick_count'] ?? 0), (int)($pEntry['max_ticks'] ?? 0),
                        $mode
                    );
                }
            }

            $queueTotal = count($newQueue);
            $this->appendDecisions($queueJournalBatch);
            // Save as a flat array (values only, no associative key export)
            $this->writeJsonFile($queuePath, array_values($newQueue));

        } catch (\Throwable $ex) {
            $errors[] = $ex->getMessage();
        }

        $finishedAt = date('Y-m-d H:i:s');
        $summary = [
            'started_at'                        => $startedAt,
            'finished_at'                       => $finishedAt,
            'status'                            => empty($errors) ? 'completed' : 'completed_with_errors',
            'mode'                              => $this->config['mode'] ?? 'shadow',
            'strategies_seen'                   => $strategiesSeen,
            'signals_seen'                      => $signalsSeen,
            'normalized_signals_total'          => $normalizedTotal,
            'invalid_signals_total'             => $invalidTotal,
            'stale_signals_total'               => $staleTotal,
            'pending_total'                     => $pendingTotalForRun,
            'waiting_confirmation_total'        => $waitingConfirmation,
            'approved_demo_shadow_total'        => $approvedDemoShadow,
            'approved_live_shadow_total'        => $approvedLiveShadow,
            'rejected_shadow_total'             => $rejectedShadow,
            'expired_shadow_total'              => $expiredShadow,
            'final_decisions_total'             => $finalDecisionsTotal,
            'decisions_written_total'           => $decisionsWritten,
            'decisions_total'                   => $decisionsTotal,
            'corridor_pending_total'            => $corridorPending,
            'default_immediate_decisions_total' => $defaultImmediateDecisions,
            // ── Current snapshot (what exists in pending_signals.json right now) ──
            'current_pending_total'             => $currPendingTotal       ?? 0,
            'current_wait_confirmation_total'   => $currWaitConfirmation   ?? 0,
            'current_approved_demo_shadow_total'=> $currApprovedDemoShadow ?? 0,
            'current_approved_live_shadow_total'=> $currApprovedLiveShadow ?? 0,
            'current_rejected_shadow_total'     => $currRejectedShadow     ?? 0,
            'current_expired_shadow_total'      => $currExpiredShadow      ?? 0,
            'current_final_decisions_total'     => $currFinalDecisions     ?? 0,
            // ── Phase 3A: approved demo queue ──────────────────────────────────
            'approved_demo_queue_enabled'       => $queueEnabled,
            'approved_demo_queue_mode'          => $queueMode,
            'approved_demo_queue_total'         => $queueTotal,
            'approved_demo_queue_added'         => $queueAdded,
            'approved_demo_queue_skipped_invalid' => $queueSkippedInvalid,
            'approved_demo_queue_skipped_stale' => $queueSkippedStale,
            'approved_demo_queue_deduped'       => $queueDeduped,
            'approved_demo_queue_limited'       => $queueLimited,
            'errors'                            => $errors,
        ];

        $this->writeJsonFile($this->moduleDir . '/storage/last_run.json', $summary);

        return $summary;
    }

    // =========================================================================
    // Signal normalisation
    // =========================================================================

    /**
     * Normalise a raw strategy signal into the canonical internal structure.
     *
     * Returns null if signal_id is missing (the signal is considered invalid
     * and must not create a pending lifecycle entry).
     */
    private function normalizeSignal(array $raw, string $stratId): ?array
    {
        $signalId = (string)($raw['signal_id'] ?? $raw['id'] ?? '');
        if ($signalId === '') {
            return null;
        }

        $govKey     = $stratId . ':' . $signalId;
        $sourceFile = (string)($raw['_source_file'] ?? '');

        return [
            'governor_signal_key' => $govKey,
            'signal_id'           => $signalId,
            'strategy_id'         => $stratId,
            'symbol'              => (string)($raw['symbol']      ?? ''),
            'side'                => (string)($raw['side']         ?? ''),
            'mode'                => (string)($raw['mode']         ?? $raw['execution_mode'] ?? ''),
            'entry_price'         => $raw['entry_price'] ?? $raw['price'] ?? null,
            'detected_at'         => (string)($raw['detected_at']  ?? $raw['created_at'] ?? ''),
            'created_at'          => (string)($raw['created_at']   ?? $raw['detected_at'] ?? ''),
            'source'              => $sourceFile !== '' ? basename($sourceFile) : '',
            'source_file'         => $sourceFile,
            'handoff_valid'       => $raw['handoff_valid'] ?? null,
        ];
    }

    // =========================================================================
    // Policy resolver
    // =========================================================================

    /**
     * Resolve per-strategy confirmation policy.
     *
     * Priority: strategy_policies[strategy_id] > strategy_policies['default'] > safe fallback.
     */
    private function resolvePolicy(string $stratId, array $policies, int $globalFallback): array
    {
        $pol = $policies[$stratId] ?? $policies['default'] ?? null;

        if ($pol !== null && is_array($pol)) {
            $req   = (bool)($pol['confirmation_required']     ?? false);
            $ticks = (int)($pol['pending_confirmation_ticks'] ?? ($req ? $globalFallback : 0));
            return [
                'confirmation_required'      => $req,
                'pending_confirmation_ticks' => $req ? max(1, $ticks) : 0,
            ];
        }

        return ['confirmation_required' => false, 'pending_confirmation_ticks' => 0];
    }

    // =========================================================================
    // Pending entry factory
    // =========================================================================

    /** Initialise a fresh pending entry from a normalised signal. */
    private function initPendingEntry(array $norm): array
    {
        $now = date('Y-m-d H:i:s');
        return [
            'governor_signal_key' => $norm['governor_signal_key'],
            'signal_id'           => $norm['signal_id'],
            'strategy_id'         => $norm['strategy_id'],
            'symbol'              => $norm['symbol'],
            'side'                => $norm['side'],
            'mode'                => $norm['mode'],
            'entry_price'         => $norm['entry_price'],
            'detected_at'         => $norm['detected_at'],
            'tick_count'          => 0,
            'max_ticks'           => 0,
            'state'               => null,
            'reason'              => null,
            'recommended_route'   => null,
            'first_seen_at'       => $now,
            'last_seen_at'        => $now,
            'updated_at'          => $now,
        ];
    }

    // =========================================================================
    // Basic signal validation (shared)
    // =========================================================================

    /**
     * Perform basic validity checks that apply to any normalised signal.
     *
     * Checks: symbol, entry_price > 0, detected_at, side (empty or "long"),
     * mode, handoff_valid not false.
     *
     * Returns the rejection reason string, or null if the signal is valid.
     */
    private function validateSignalBasic(array $norm): ?string
    {
        if ((string)($norm['symbol'] ?? '') === '') {
            return 'missing_symbol';
        }
        $ep = $norm['entry_price'] ?? null;
        if ($ep === null || (float)$ep <= 0.0) {
            return 'missing_entry_price';
        }
        if ((string)($norm['detected_at'] ?? '') === '') {
            return 'missing_detected_at';
        }
        $side = (string)($norm['side'] ?? '');
        if ($side !== '' && $side !== 'long') {
            return 'side_not_long';
        }
        if ((string)($norm['mode'] ?? '') === '') {
            return 'missing_mode';
        }
        if (isset($norm['handoff_valid']) && $norm['handoff_valid'] === false) {
            return 'handoff_signal_invalid';
        }
        return null;
    }

    // =========================================================================
    // Hard-reject conditions (used during confirmation window)
    // =========================================================================

    /**
     * Check per-tick hard-reject conditions during a confirmation window.
     *
     * Returns the reject reason string, or null if the signal still passes.
     */
    private function checkHardReject(array $norm, int $maxSignalAge): ?string
    {
        // Use the shared basic validator first
        $basicFail = $this->validateSignalBasic($norm);
        if ($basicFail !== null) {
            return $basicFail;
        }
        // Age re-check (signal may have aged out during the confirmation window)
        $detectedAt = (string)$norm['detected_at'];
        $ts = $this->parseTimestamp($detectedAt);
        if ($ts > 0 && (time() - $ts) > $maxSignalAge) {
            return 'signal_too_old';
        }
        return null;
    }

    // =========================================================================
    // Immediate shadow decision engine
    // =========================================================================

    /**
     * Decide immediately based on signal validity + strategy stats.
     *
     * In shadow mode stats can only determine the recommended route; they never
     * block the demo route entirely.
     *
     * @return array{0: string, 1: string, 2: string}  [decision, route, reason]
     */
    private function decideImmediate(
        array $norm,
        array $stats,
        int   $minClosed,
        float $minWinrate,
        float $minAvgRoi,
        int   $maxConsecLosses,
    ): array {
        // ── Signal validation (shared) ────────────────────────────────────────
        $basicFail = $this->validateSignalBasic($norm);
        if ($basicFail !== null) {
            return [self::STATE_REJECT_SHADOW, self::ROUTE_NONE, $basicFail];
        }

        // ── Stats-based route gate (never rejects demo in shadow mode) ────────
        $closedTotal  = (int)($stats['closed_trades_total'] ?? 0);
        $winrate      = (float)($stats['winrate']           ?? 0.0);
        $avgRoi       = (float)($stats['avg_roi']           ?? 0.0);
        $consecLosses = (int)($stats['consecutive_losses']  ?? 0);

        if ($closedTotal < $minClosed) {
            return [self::STATE_APPROVE_DEMO_SHADOW, self::ROUTE_DEMO, 'not_enough_closed_trades'];
        }
        if ($consecLosses >= $maxConsecLosses) {
            // Too many consecutive losses — keep to demo, do not block
            return [self::STATE_APPROVE_DEMO_SHADOW, self::ROUTE_DEMO, 'loss_streak'];
        }
        if ($winrate >= $minWinrate && $avgRoi >= $minAvgRoi) {
            return [self::STATE_APPROVE_LIVE_SHADOW, self::ROUTE_LIVE, 'live_gate_passed'];
        }
        if ($avgRoi < 0.0) {
            return [self::STATE_APPROVE_DEMO_SHADOW, self::ROUTE_DEMO, 'avg_roi_negative'];
        }
        if ($winrate < $minWinrate) {
            return [self::STATE_APPROVE_DEMO_SHADOW, self::ROUTE_DEMO, 'winrate_too_low'];
        }

        return [self::STATE_APPROVE_DEMO_SHADOW, self::ROUTE_DEMO, 'valid_demo_shadow'];
    }

    // =========================================================================
    // Decision record builder
    // =========================================================================

    /**
     * Build a decision journal record.
     *
     * @param array $norm  Normalised signal (or partial array for expired entries)
     */
    private function buildDecisionRecord(
        string  $govKey,
        array   $norm,
        ?string $prevState,
        string  $newState,
        string  $decision,
        string  $route,
        string  $reason,
        int     $tickCount,
        int     $maxTicks,
        string  $mode,
    ): array {
        return [
            'time'                     => date('Y-m-d H:i:s'),
            'decision_id'              => 'gd_' . substr(md5($govKey . microtime(true)), 0, 12),
            'governor_signal_key'      => $govKey,
            'signal_id'                => (string)($norm['signal_id']   ?? ''),
            'strategy_id'              => (string)($norm['strategy_id'] ?? ''),
            'symbol'                   => (string)($norm['symbol']      ?? ''),
            'previous_state'           => $prevState,
            'new_state'                => $newState,
            'decision'                 => $decision,
            'recommended_route'        => $route,
            'recommended_live_allowed' => ($route === self::ROUTE_LIVE),
            'reason'                   => $reason,
            'tick_count'               => $tickCount,
            'max_ticks'                => $maxTicks,
            'mode'                     => $mode,
        ];
    }

    // =========================================================================
    // Statistics builders
    // =========================================================================

    /**
     * Build per-strategy stats from a closed-trades array.
     *
     * @param array $closedTrades  array of trade records
     * @return array<string, array>  keyed by strategy_id
     */
    private function buildStrategyStats(array $closedTrades): array
    {
        $stats = [];
        foreach ($closedTrades as $trade) {
            $sid = (string)($trade['strategy_id'] ?? $trade['owner_strategy'] ?? '');
            if ($sid === '') {
                continue;
            }
            if (!isset($stats[$sid])) {
                $stats[$sid] = [
                    'closed_trades_total'  => 0,
                    'wins_total'           => 0,
                    'losses_total'         => 0,
                    'roi_sum'              => 0.0,
                    'total_pnl'            => 0.0,
                    'consecutive_losses'   => 0,
                    'consecutive_wins'     => 0,
                    '_last_results'        => [],
                    'last_trade_at'        => null,
                ];
            }
            $s   = &$stats[$sid];
            $roi = isset($trade['roi']) ? (float)$trade['roi'] : null;
            $pnl = isset($trade['pnl']) ? (float)$trade['pnl'] : 0.0;

            $s['closed_trades_total']++;
            $s['total_pnl'] += $pnl;
            if ($roi !== null) {
                $s['roi_sum'] += $roi;
            }
            $win = ($roi !== null && $roi > 0.0);
            if ($win) {
                $s['wins_total']++;
                $s['_last_results'][] = 'win';
            } else {
                $s['losses_total']++;
                $s['_last_results'][] = 'loss';
            }
            $closedAt = (string)($trade['closed_at'] ?? '');
            if ($closedAt !== '') {
                if ($s['last_trade_at'] === null || $closedAt > $s['last_trade_at']) {
                    $s['last_trade_at'] = $closedAt;
                }
            }
            unset($s);
        }

        // Compute derived fields
        foreach ($stats as &$s) {
            $total = $s['closed_trades_total'];
            $s['winrate'] = $total > 0 ? round($s['wins_total'] / $total, 4) : 0.0;
            $s['avg_roi'] = $total > 0 ? round($s['roi_sum'] / $total, 4) : 0.0;
            // Consecutive losses/wins from the end of the result list
            $results = $s['_last_results'];
            $cl = 0;
            $cw = 0;
            for ($i = count($results) - 1; $i >= 0; $i--) {
                if ($results[$i] === 'loss') {
                    if ($cw === 0) {
                        $cl++;
                    } else {
                        break;
                    }
                } else {
                    if ($cl === 0) {
                        $cw++;
                    } else {
                        break;
                    }
                }
            }
            $s['consecutive_losses'] = $cl;
            $s['consecutive_wins']   = $cw;
            unset($s['roi_sum'], $s['_last_results']);
        }
        unset($s);

        return $stats;
    }

    /**
     * Build hourly stats grouped by strategy_id + hour_of_day + weekday.
     *
     * @return array<string, array>  keyed by strategy_id; nested by hour and weekday
     */
    private function buildHourlyStats(array $closedTrades): array
    {
        $hourly = [];
        foreach ($closedTrades as $trade) {
            $sid      = (string)($trade['strategy_id'] ?? $trade['owner_strategy'] ?? '');
            $closedAt = (string)($trade['closed_at'] ?? '');
            if ($sid === '' || $closedAt === '') {
                continue;
            }
            $ts      = $this->parseTimestamp($closedAt);
            if ($ts <= 0) {
                continue;
            }
            $hour    = (int)date('G', $ts);   // 0-23
            $weekday = (int)date('N', $ts);   // 1=Mon…7=Sun
            $key     = $hour . '_' . $weekday;

            if (!isset($hourly[$sid][$key])) {
                $hourly[$sid][$key] = [
                    'hour_of_day'  => $hour,
                    'weekday'      => $weekday,
                    'trades_total' => 0,
                    'wins_total'   => 0,
                    'losses_total' => 0,
                    'roi_sum'      => 0.0,
                    'total_pnl'    => 0.0,
                ];
            }
            $h   = &$hourly[$sid][$key];
            $roi = isset($trade['roi']) ? (float)$trade['roi'] : null;
            $pnl = isset($trade['pnl']) ? (float)$trade['pnl'] : 0.0;

            $h['trades_total']++;
            $h['total_pnl'] += $pnl;
            if ($roi !== null) {
                $h['roi_sum'] += $roi;
                if ($roi > 0.0) {
                    $h['wins_total']++;
                } else {
                    $h['losses_total']++;
                }
            } else {
                $h['losses_total']++;
            }
            unset($h);
        }

        // Compute derived fields
        foreach ($hourly as &$buckets) {
            foreach ($buckets as &$h) {
                $t = $h['trades_total'];
                $h['winrate'] = $t > 0 ? round($h['wins_total'] / $t, 4) : 0.0;
                $h['avg_roi'] = $t > 0 ? round($h['roi_sum']    / $t, 4) : 0.0;
                unset($h['roi_sum']);
            }
            unset($h);
        }
        unset($buckets);

        return $hourly;
    }

    // =========================================================================
    // Strategy-state writer
    // =========================================================================

    private function saveStrategyStats(
        array $stratStats,
        array $openPerStrategy,
        array $strategyIds,
        int   $minClosed,
        float $minWinrate,
        float $minAvgRoi,
        int   $maxConsecLosses,
    ): void {
        $stateMap = [];
        foreach ($strategyIds as $sid) {
            $s           = $stratStats[$sid] ?? [];
            $total       = (int)($s['closed_trades_total'] ?? 0);
            $winrate     = (float)($s['winrate']           ?? 0.0);
            $avgRoi      = (float)($s['avg_roi']           ?? 0.0);
            $consecLoss  = (int)($s['consecutive_losses']  ?? 0);

            $liveAllowed = false;
            $reason      = 'not_enough_closed_trades';
            $state       = 'insufficient_data';

            if ($total >= $minClosed) {
                if ($consecLoss >= $maxConsecLosses) {
                    $reason = 'loss_streak';
                    $state  = 'shadow_observe';
                } elseif ($winrate >= $minWinrate && $avgRoi >= $minAvgRoi) {
                    $reason      = 'live_gate_passed';
                    $state       = 'shadow_live_ready';
                    $liveAllowed = true;
                } else {
                    $reason = 'below_live_threshold';
                    $state  = 'shadow_observe';
                }
            }

            $stateMap[$sid] = [
                'state'                    => $state,
                'recommended_live_allowed' => $liveAllowed,
                'recommended_route'        => $liveAllowed ? 'live' : 'demo',
                'reason'                   => $reason,
                'closed_trades_total'      => $total,
                'winrate'                  => $winrate,
                'avg_roi'                  => $avgRoi,
                'consecutive_losses'       => $consecLoss,
                'consecutive_wins'         => (int)($s['consecutive_wins'] ?? 0),
                'total_pnl'                => round((float)($s['total_pnl'] ?? 0.0), 6),
                'current_open_positions'   => $openPerStrategy[$sid] ?? 0,
                'last_trade_at'            => $s['last_trade_at'] ?? null,
                'updated_at'               => date('Y-m-d H:i:s'),
            ];
        }

        $this->writeJsonFile($this->moduleDir . '/storage/strategy_state.json', $stateMap);
        $this->writeJsonFile($this->moduleDir . '/storage/strategy_stats.json', $stratStats);
    }

    private function saveHourlyStats(array $hourlyStats): void
    {
        $this->writeJsonFile($this->moduleDir . '/storage/hourly_stats.json', $hourlyStats);
    }

    // =========================================================================
    // Decision journal
    // =========================================================================

    /**
     * Append a batch of decision records to decisions.ndjson (one JSON object per line).
     */
    private function appendDecisions(array $decisions): void
    {
        if (empty($decisions)) {
            return;
        }
        $path  = $this->moduleDir . '/storage/decisions.ndjson';
        $lines = '';
        foreach ($decisions as $d) {
            $lines .= json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        }
        @file_put_contents($path, $lines, FILE_APPEND | LOCK_EX);
    }

    // =========================================================================
    // Data loaders
    // =========================================================================

    /** Discover strategy IDs from the pattern directory. */
    private function discoverStrategies(): array
    {
        $patternDir = $this->repoRoot . '/modules/strategy/pattern';
        if (!is_dir($patternDir)) {
            return [];
        }
        $ids = [];
        foreach (scandir($patternDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($patternDir . '/' . $entry)) {
                $ids[] = $entry;
            }
        }
        return $ids;
    }

    /**
     * Load all signals for a strategy.
     *
     * Reads signals.json and bot_handoff_queue.json, merges, deduplicates by signal_id.
     * Adds _source_file to each signal for traceability.
     */
    private function loadStrategySignals(string $stratId): array
    {
        $base = $this->repoRoot . '/modules/strategy/pattern/' . $stratId . '/storage';
        $sigs = [];
        $seen = [];

        foreach (['signals.json', 'bot_handoff_queue.json'] as $fname) {
            $relPath = 'modules/strategy/pattern/' . $stratId . '/storage/' . $fname;
            $arr     = $this->loadJsonSafe($base . '/' . $fname, []);
            if (!is_array($arr)) {
                continue;
            }
            foreach ($arr as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = (string)($item['signal_id'] ?? $item['id'] ?? '');
                $k  = $id !== '' ? $id : json_encode($item);
                if (!isset($seen[$k])) {
                    $seen[$k]            = true;
                    $item['_source_file'] = $relPath;
                    $sigs[]              = $item;
                }
            }
        }
        return $sigs;
    }

    /** Load closed trades from bot storage (may not exist). */
    private function loadClosedTrades(): array
    {
        $path = $this->repoRoot . '/modules/bot/storage/trades/closed_trades.json';
        $data = $this->loadJsonSafe($path, []);
        return is_array($data) ? $data : [];
    }

    // =========================================================================
    // Persistence helpers
    // =========================================================================

    private function savePendingSignals(array $pending): void
    {
        $this->writeJsonFile($this->moduleDir . '/storage/pending_signals.json', $pending);
    }

    /**
     * Safely decode a JSON file; return $default on any failure.
     */
    private function loadJsonSafe(string $path, mixed $default): mixed
    {
        if (!is_file($path)) {
            return $default;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $decoded = @json_decode($raw, true);
        return ($decoded !== null) ? $decoded : $default;
    }

    /**
     * Write data as pretty-printed JSON, creating directories as needed.
     */
    private function writeJsonFile(string $path, mixed $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            LOCK_EX
        );
    }

    /**
     * Parse a timestamp string or numeric value into a Unix timestamp.
     *
     * Accepts either an integer Unix timestamp (as int or numeric string) or
     * any date/time string understood by strtotime().  Returns 0 on failure.
     */
    private function parseTimestamp(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        $ts = @strtotime($value);
        return ($ts !== false && $ts > 0) ? $ts : 0;
    }
}
