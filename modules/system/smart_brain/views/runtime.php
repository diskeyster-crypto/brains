<?php
/**
 * Smart Brain Module - Runtime View
 * 
 * Shows runtime config snapshot, last run information, and full stats.json.
 */

/** @var string $smartBrainUrl */
/** @var array<string,mixed> $config */
/** @var array<string,mixed> $snapshot */
/** @var array<string,mixed> $last_run */
/** @var array<string,mixed> $stats */
/** @var list<string> $config_warnings */

$pageTitle = 'Smart Brain - Runtime';
$activeTab = 'runtime';

$config_warnings = $config_warnings ?? [];

$pageContent = function() use ($config, $snapshot, $last_run, $stats, $smartBrainUrl, $config_warnings) {
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-activity me-2 text-primary"></i>Runtime</h4>
            <p class="text-secondary mb-0">Current runtime state, config snapshot, statistics, and last run details</p>
        </div>
    </div>

    <!-- Config Conflict Guard: warnings (deduplicated) -->
    <?php
        $conflictDetected = (bool)($last_run['config_conflict_detected'] ?? false);
        $conflictMsg = (string)($last_run['config_conflict_message'] ?? '');
        $allWarnings = $config_warnings;
        if ($conflictDetected && $conflictMsg !== '') {
            array_unshift($allWarnings, $conflictMsg);
        }
        // Deduplicate warnings
        $seen = [];
        $uniqueWarnings = [];
        foreach ($allWarnings as $w) {
            $key = mb_strtolower(trim($w));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueWarnings[] = $w;
            }
        }
        if (!empty($uniqueWarnings)):
    ?>
    <div class="card mb-4" style="border-color: #f59e0b;">
        <div class="card-header" style="background: rgba(245,158,11,0.1);">
            <h5 style="margin: 0;"><i class="bi bi-exclamation-triangle-fill me-1 text-warning"></i> Config Conflict Guard</h5>
        </div>
        <div class="card-body">
            <?php foreach ($uniqueWarnings as $w): ?>
            <div class="alert alert-warning mb-2 py-1 px-2" style="font-size:0.85rem;">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <?= htmlspecialchars($w) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Execution Profile Status -->
    <?php
        $execProfile = (string)($last_run['execution_profile'] ?? 'custom');
        $execProfileLabel = (string)($last_run['execution_profile_label'] ?? 'Custom');
        $execProfileIsPreset = $execProfile !== 'custom';
        $execProfileBundles = SmartBrainConfig::getExecutionProfileBundles();
        $execProfileBundle = $execProfileBundles[$execProfile] ?? $execProfileBundles['custom'];
        $execPatternProfileMode = (string)($last_run['pattern_profile_mode'] ?? 'manual_override');
        $execPatternRoutingBundles = SmartBrainConfig::getProfilePatternRoutingBundles();
        // Use pattern_policy from last_run if available (normalized, never null)
        $execPatternPolicy = is_array($last_run['pattern_policy'] ?? null) ? $last_run['pattern_policy'] : null;
        if ($execPatternPolicy !== null) {
            $execPatternRouting = $execPatternPolicy;
        } else {
            // Fallback: derive from profile bundle
            $execPatternRouting = $execPatternRoutingBundles[$execProfile] ?? $execPatternRoutingBundles['custom'];
        }
        $execIsPatternProfileControlled = $execProfileIsPreset && $execPatternProfileMode === 'profile_controlled';
        $execPatternFallbackUsed = (bool)($execPatternRouting['fallback_used'] ?? false);
        $execPatternFallbackReason = (string)($execPatternRouting['fallback_reason'] ?? '');
        $execPatternLabels = [
            'double_bottom' => 'Double Bottom',
            'double_top' => 'Double Top',
            'pullback_trend_continue' => 'Pullback Trend Continue',
            'double_bottom_confirm_v2' => 'DB V2 (confirmed)',
            'double_top_confirm_v2' => 'DT V2 (confirmed)',
            'double_bottom_contextual_v2' => 'DB Contextual V2',
            'double_bottom_contextual_v3' => 'DB Contextual V3',
            'double_top_contextual_v2' => 'DT Contextual V2',
            'double_top_contextual_v3' => 'DT Contextual V3',
        ];
    ?>
    <div class="card mb-4" style="border-color: <?= $execProfileIsPreset ? '#6366f1' : '#6b7280' ?>;">
        <div class="card-header" style="background: <?= $execProfileIsPreset ? 'rgba(99,102,241,0.1)' : 'rgba(107,114,128,0.1)' ?>;">
            <h5 style="margin: 0;">
                <i class="bi bi-crosshair me-1"></i> Execution Profile
                <span class="badge <?= $execProfileIsPreset ? 'bg-primary' : 'bg-secondary' ?> ms-1"><?= htmlspecialchars($execProfileLabel) ?></span>
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 mb-2">
                    <strong>Profile ID:</strong>
                    <code><?= htmlspecialchars($execProfile) ?></code>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>Mode:</strong>
                    <span class="<?= $execProfileIsPreset ? 'text-info' : 'text-secondary' ?>"><?= $execProfileIsPreset ? 'Preset' : 'Custom / Manual' ?></span>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>Managed Fields:</strong>
                    <span class="text-info"><?= count(SmartBrainConfig::getProfileManagedFields()) ?> V2 parameters</span>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>Pattern Routing:</strong>
                    <span class="<?= $execIsPatternProfileControlled ? 'text-info' : 'text-secondary' ?>"><?= $execIsPatternProfileControlled ? 'profile_controlled' : 'manual_override' ?></span>
                </div>
            </div>
            <div class="small text-secondary mt-1"><?= htmlspecialchars($execProfileBundle['description']) ?></div>
            <?php if ($execProfile === 'sniper_75_attempt'): ?>
            <div class="alert alert-warning small mb-0 mt-2 py-1 px-2">
                <i class="bi bi-exclamation-triangle me-1"></i> Very selective profile. Fewer trades expected. Higher target precision, not guaranteed winrate.
            </div>
            <?php elseif ($execProfile === 'sniper_lite'): ?>
            <div class="alert alert-info small mb-0 mt-2 py-1 px-2">
                <i class="bi bi-info-circle me-1"></i> Moderately selective V3-only profile. Allows strong and selected medium confirmations. Higher signal count than Sniper, lower target precision.
            </div>
            <?php endif; ?>

            <!-- Pattern Routing Summary — always shown -->
            <div class="mt-3" style="font-size: 0.85rem;">
                <strong><i class="bi bi-diagram-3 me-1"></i>Active Pattern Routing
                    <small class="text-secondary">(<?= htmlspecialchars((string)($execPatternRouting['canonical_source'] ?? $execPatternProfileMode)) ?>)</small>:</strong>
                <span class="text-success ms-2">Live: <?= !empty($execPatternRouting['live_patterns']) ? implode(', ', array_map(fn($p) => $execPatternLabels[$p] ?? $p, (array)$execPatternRouting['live_patterns'])) : '—' ?></span>
                <span class="text-warning ms-2">Shadow: <?= !empty($execPatternRouting['shadow_patterns']) ? implode(', ', array_map(fn($p) => $execPatternLabels[$p] ?? $p, (array)$execPatternRouting['shadow_patterns'])) : '—' ?></span>
                <span class="text-muted ms-2">Disabled: <?= !empty($execPatternRouting['disabled_patterns']) ? implode(', ', array_map(fn($p) => $execPatternLabels[$p] ?? $p, (array)$execPatternRouting['disabled_patterns'])) : '—' ?></span>
            </div>
            <?php if ($execPatternFallbackUsed): ?>
            <div class="alert alert-warning small mb-0 mt-1 py-1 px-2">
                <i class="bi bi-exclamation-triangle me-1"></i> Pattern routing fallback used: <?= htmlspecialchars($execPatternFallbackReason) ?>. Manual override lists were missing — derived from profile preset.
            </div>
            <?php endif; ?>

            <!-- Key V2 Policy Summary -->
            <?php if ($execProfileIsPreset && !empty($execProfileBundle['values'])): ?>
            <div class="mt-2" style="font-size: 0.83rem;">
                <a class="text-decoration-none small" data-bs-toggle="collapse" href="#runtime-profile-values" role="button" aria-expanded="false">
                    <i class="bi bi-list-ul me-1"></i>Show active V2 entry policy values
                </a>
                <div class="collapse mt-1" id="runtime-profile-values">
                    <div class="row">
                    <?php foreach ($execProfileBundle['values'] as $fk => $fv): ?>
                        <div class="col-md-4 mb-1">
                            <code class="small"><?= htmlspecialchars($fk) ?></code>:
                            <?php if (is_bool($fv)): ?>
                                <span class="<?= $fv ? 'text-success' : 'text-danger' ?>"><?= $fv ? 'true' : 'false' ?></span>
                            <?php else: ?>
                                <span class="text-info"><?= htmlspecialchars((string)$fv) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sniper V3 Live Filters Runtime Status -->
    <?php if (in_array($execProfile, ['sniper_75_attempt', 'sniper_lite'], true)): ?>
    <?php
        $sniperV3Enabled = (bool)($execProfileBundle['values']['sniper_v3_live_filter_enabled'] ?? true);
        $sniperV3AllowedTiers = SmartBrainConfig::getSniperV3AllowedTiers($execProfile);
        $sniperV3Eligible = (int)($last_run['sniper_v3_live_eligible_count'] ?? 0);
        $sniperV3Rejected = (int)($last_run['sniper_v3_live_rejected_count'] ?? 0);
        $sniperV3RejectDist = (array)($last_run['sniper_v3_reject_reason_distribution'] ?? []);
        $sniperV3Structural = (int)($last_run['structural_v3_signal_count'] ?? 0);
    ?>
    <div class="card mb-4" style="border-color: #f59e0b;">
        <div class="card-header" style="background: rgba(245,158,11,0.1);">
            <h5 style="margin: 0;">
                <i class="bi bi-crosshair2 me-1"></i> Sniper V3 Live Filters
                <span class="badge <?= $sniperV3Enabled ? 'bg-success' : 'bg-secondary' ?> ms-1"><?= $sniperV3Enabled ? 'Active' : 'Disabled' ?></span>
            </h5>
        </div>
        <div class="card-body">
            <div class="row mb-2" style="font-size: 0.85rem;">
                <div class="col-md-2 mb-2">
                    <strong>Structural V3:</strong>
                    <span class="badge bg-primary"><?= $sniperV3Structural ?></span>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>Allowed Tiers:</strong>
                    <?php foreach ($sniperV3AllowedTiers as $tier): ?>
                    <span class="badge bg-success ms-1"><?= htmlspecialchars($tier) ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>V3 Live Eligible:</strong>
                    <span class="badge <?= $sniperV3Eligible > 0 ? 'bg-success' : 'bg-secondary' ?>"><?= $sniperV3Eligible ?></span>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>V3 Live Rejected:</strong>
                    <span class="badge <?= $sniperV3Rejected > 0 ? 'bg-warning text-dark' : 'bg-secondary' ?>"><?= $sniperV3Rejected ?></span>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>Entry Action:</strong>
                    <span class="badge bg-info">wait_retrace</span>
                </div>
            </div>
            <?php
                $sniperV3FilterValues = [
                    'Min Confirmation Score' => $execProfileBundle['values']['sniper_v3_min_confirmation_score'] ?? 0.80,
                    'Min Pattern Confidence' => $execProfileBundle['values']['sniper_v3_min_pattern_confidence'] ?? 0.60,
                    'Min Trend Match' => $execProfileBundle['values']['sniper_v3_min_trend_match_score'] ?? 0.55,
                    'Min Trend Match (Short V3)' => $execProfileBundle['values']['sniper_v3_min_trend_match_score_short'] ?? '-',
                    'Min Entry Quality' => $execProfileBundle['values']['sniper_v3_min_entry_quality_score'] ?? 0.75,
                    'Min Entry Quality (Short V3)' => $execProfileBundle['values']['sniper_v3_min_entry_quality_score_short'] ?? '-',
                    'Min Corridor Fit' => $execProfileBundle['values']['sniper_v3_min_corridor_fit_score'] ?? 0.75,
                    'Min Corridor Fit (Short V3)' => $execProfileBundle['values']['sniper_v3_min_corridor_fit_score_short'] ?? '-',
                    'Max Price Position' => $execProfileBundle['values']['sniper_v3_max_price_position'] ?? 0.80,
                ];
            ?>
            <div class="row" style="font-size: 0.83rem;">
                <?php foreach ($sniperV3FilterValues as $label => $val): ?>
                <div class="col-md-4 mb-1">
                    <code class="small"><?= htmlspecialchars($label) ?></code>:
                    <span class="text-info"><?= htmlspecialchars((string)$val) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($sniperV3RejectDist)): ?>
            <div class="mt-2" style="font-size: 0.82rem;">
                <strong>Last Run Reject Reasons:</strong>
                <div class="mt-1">
                <?php foreach ($sniperV3RejectDist as $reason => $count): ?>
                    <span class="badge bg-warning text-dark me-1 mb-1"><?= htmlspecialchars((string)$reason) ?>: <?= (int)$count ?></span>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($execProfile === 'sniper_lite'): ?>
            <div class="small text-secondary mt-2">V3 live allowed for medium (with extra quality gates), strong, and very_strong confirmations. Weak V3 stays in shadow/analytics.</div>
            <?php else: ?>
            <div class="small text-secondary mt-2">V3 live allowed only for strong/very_strong confirmations. Medium/weak V3 stay in shadow/analytics.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- V2 Live Quality Floor Status -->
    <?php
        $v2FloorEnabled = (bool)($execProfileBundle['values']['v2_live_quality_floor_enabled'] ?? true);
        $v2FloorApplied = (int)($last_run['v2_live_quality_floor_applied_count'] ?? 0);
        $v2FloorRejected = (int)($last_run['v2_live_quality_floor_rejected_count'] ?? 0);
        $v2FloorPassed = (int)($last_run['v2_live_quality_floor_passed_count'] ?? 0);
        $v2FloorRejectDist = (array)($last_run['v2_live_quality_floor_reject_reason_distribution'] ?? []);
    ?>
    <div class="card mb-4" style="border-color: #8b5cf6;">
        <div class="card-header" style="background: rgba(139,92,246,0.1);">
            <h5 style="margin: 0;">
                <i class="bi bi-shield-check me-1"></i> V2 Live Quality Floor
                <span class="badge <?= $v2FloorEnabled ? 'bg-success' : 'bg-secondary' ?> ms-1"><?= $v2FloorEnabled ? 'Active' : 'Disabled' ?></span>
            </h5>
        </div>
        <div class="card-body">
            <div class="row mb-2" style="font-size: 0.85rem;">
                <div class="col-md-3 mb-2">
                    <strong>V2 Signals Checked:</strong>
                    <span class="badge bg-primary"><?= $v2FloorApplied ?></span>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>Passed:</strong>
                    <span class="badge <?= $v2FloorPassed > 0 ? 'bg-success' : 'bg-secondary' ?>"><?= $v2FloorPassed ?></span>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>Rejected:</strong>
                    <span class="badge <?= $v2FloorRejected > 0 ? 'bg-warning text-dark' : 'bg-secondary' ?>"><?= $v2FloorRejected ?></span>
                </div>
            </div>
            <?php
                $v2FloorThresholds = [
                    'Min Confirmation Score' => $execProfileBundle['values']['v2_live_min_confirmation_score'] ?? $userLimits['v2_live_min_confirmation_score'] ?? 0.55,
                    'Min Pattern Confidence' => $execProfileBundle['values']['v2_live_min_pattern_confidence'] ?? $userLimits['v2_live_min_pattern_confidence'] ?? 0.50,
                    'Min Trend Match' => $execProfileBundle['values']['v2_live_min_trend_match_score'] ?? $userLimits['v2_live_min_trend_match_score'] ?? 0.40,
                ];
            ?>
            <div class="row" style="font-size: 0.83rem;">
                <?php foreach ($v2FloorThresholds as $label => $val): ?>
                <div class="col-md-4 mb-1">
                    <code class="small"><?= htmlspecialchars($label) ?></code>:
                    <span class="text-info"><?= htmlspecialchars((string)$val) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($v2FloorRejectDist)): ?>
            <div class="mt-2" style="font-size: 0.82rem;">
                <strong>Last Run Reject Reasons:</strong>
                <div class="mt-1">
                <?php foreach ($v2FloorRejectDist as $reason => $count): ?>
                    <span class="badge bg-warning text-dark me-1 mb-1"><?= htmlspecialchars((string)$reason) ?>: <?= (int)$count ?></span>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="small text-secondary mt-2">V2 signals with confirmation_score, pattern_confidence, or trend_match below profile floor are filtered before live approval.</div>
        </div>
    </div>

    <!-- Manual Live Blacklist Status -->
    <?php
        $blActive = (bool)($last_run['manual_blacklist_active'] ?? false);
        $blCount = (int)($last_run['manual_blacklist_count'] ?? 0);
        $blRejected = (int)($last_run['manual_blacklist_rejected_count'] ?? 0);
        $blPreview = (array)($last_run['manual_blacklist_rejected_preview'] ?? []);
    ?>
    <div class="card mb-4" style="border-color: #dc3545;">
        <div class="card-header" style="background: rgba(220,53,69,0.08);">
            <h5 style="margin: 0;">
                <i class="bi bi-shield-x me-1"></i> Manual Live Blacklist
                <span class="badge <?= $blActive ? 'bg-danger' : 'bg-secondary' ?> ms-1"><?= $blCount ?> symbol(s)</span>
            </h5>
        </div>
        <div class="card-body">
            <div class="row mb-2" style="font-size: 0.85rem;">
                <div class="col-md-3 mb-2">
                    <strong>Active:</strong>
                    <span class="badge <?= $blActive ? 'bg-danger' : 'bg-secondary' ?>"><?= $blActive ? 'Yes' : 'No' ?></span>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>Symbols:</strong>
                    <span class="badge bg-primary"><?= $blCount ?></span>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>Live Rejected:</strong>
                    <span class="badge <?= $blRejected > 0 ? 'bg-warning text-dark' : 'bg-secondary' ?>"><?= $blRejected ?></span>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>Scope:</strong>
                    <span class="badge bg-info">LIVE only</span>
                </div>
            </div>
            <?php if (!empty($blPreview)): ?>
            <div class="mt-2" style="font-size: 0.82rem;">
                <strong>Last Run Blocked Symbols:</strong>
                <div class="mt-1">
                <?php foreach ($blPreview as $bp): ?>
                    <span class="badge bg-danger me-1 mb-1"><?= htmlspecialchars((string)($bp['symbol'] ?? '')) ?></span>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="small text-secondary mt-2">
                <i class="bi bi-folder2-open me-1"></i>
                <strong>Source:</strong> <code>storage/manual_live_blacklist.json</code>
                &nbsp;|&nbsp; Separate from Symbol Intelligence blacklist (<code>storage/blacklist.json</code>).
                Blacklisted symbols are blocked from live approval. Simulator and analytics remain unaffected.
            </div>
        </div>
    </div>

    <!-- Live Trading Status -->
    <?php
        $liveEnabled = (bool)($last_run['live_trading_enabled'] ?? false);
        $liveMode = (string)($last_run['live_signal_selection_mode'] ?? 'n/a');
        $liveApproved = (int)($last_run['live_candidates_approved_count'] ?? 0);
        $liveRejected = (int)($last_run['live_candidates_rejected_count'] ?? 0);
        $liveIntents = (int)($last_run['live_intents_created_count'] ?? 0);
        $liveSent = (int)($last_run['live_intents_sent_to_bot_count'] ?? 0);
        $liveTotalAfterMerge = (int)($last_run['live_intents_total_after_merge'] ?? 0);
        $liveTerminalRetained = (int)($last_run['live_terminal_retained_count'] ?? 0);
        $lifecycleSummary = $last_run['lifecycle_summary'] ?? [];
        $lifecycleCounters = $last_run['lifecycle_counters'] ?? [];
        $lateEntryCount = (int)($last_run['late_entry_rejected_count'] ?? 0);
        $lateEntryDist = $last_run['late_entry_rejected_distribution'] ?? [];
    ?>
    <div class="card mb-4" style="border-color: <?= $liveEnabled ? '#22c55e' : '#6b7280' ?>;">
        <div class="card-header" style="background: <?= $liveEnabled ? 'rgba(34,197,94,0.1)' : 'rgba(107,114,128,0.1)' ?>;">
            <h5 style="margin: 0;">
                <i class="bi bi-lightning-charge me-1"></i> Live Trading
                <?php if ($liveEnabled): ?>
                    <span class="badge bg-success ms-1">ENABLED</span>
                <?php else: ?>
                    <span class="badge bg-secondary ms-1">DISABLED</span>
                <?php endif; ?>
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 mb-2">
                    <strong>Selection Mode:</strong>
                    <span class="text-info"><?= htmlspecialchars($liveMode) ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>Approved:</strong>
                    <span class="text-success"><?= $liveApproved ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>Rejected:</strong>
                    <span class="text-danger"><?= $liveRejected ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>Intents:</strong>
                    <span class="text-warning"><?= $liveIntents ?></span>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>New Pending:</strong>
                    <span class="text-primary"><?= $liveSent ?></span>
                    <?php if ($liveTerminalRetained > 0): ?>
                    <small class="text-secondary ms-1">(+<?= $liveTerminalRetained ?> terminal retained)</small>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($lateEntryCount > 0): ?>
            <div class="row mt-1">
                <div class="col-12">
                    <small class="text-warning">
                        <i class="bi bi-clock-history me-1"></i>Late entry rejected: <?= $lateEntryCount ?>
                        <?php if (!empty($lateEntryDist)): ?>
                        (<?php
                            $parts = [];
                            foreach ($lateEntryDist as $sub => $cnt) { $parts[] = str_replace('late_entry_', '', $sub) . ':' . $cnt; }
                            echo implode(', ', $parts);
                        ?>)
                        <?php endif; ?>
                    </small>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($lifecycleSummary)): ?>
            <div class="row mt-2" style="border-top: 1px solid rgba(255,255,255,0.1); padding-top: 8px;">
                <div class="col-12 mb-1"><small class="text-secondary"><i class="bi bi-arrow-repeat me-1"></i>Intent Lifecycle (live_intents.json)</small></div>
                <div class="col-md-2 mb-1">
                    <small class="text-secondary d-block">Pending</small>
                    <strong class="text-info"><?= (int)($lifecycleSummary['pending'] ?? 0) ?></strong>
                </div>
                <div class="col-md-2 mb-1">
                    <small class="text-secondary d-block">Claimed</small>
                    <strong class="text-primary"><?= (int)($lifecycleSummary['claimed'] ?? 0) ?></strong>
                </div>
                <div class="col-md-2 mb-1">
                    <small class="text-secondary d-block">Executed</small>
                    <strong class="text-success"><?= (int)($lifecycleSummary['executed'] ?? 0) ?></strong>
                </div>
                <div class="col-md-2 mb-1">
                    <small class="text-secondary d-block">Rejected</small>
                    <strong class="text-danger"><?= (int)($lifecycleSummary['rejected'] ?? 0) ?></strong>
                </div>
                <div class="col-md-2 mb-1">
                    <small class="text-secondary d-block">Expired</small>
                    <strong class="text-secondary"><?= (int)($lifecycleSummary['expired'] ?? 0) ?></strong>
                </div>
                <div class="col-md-2 mb-1">
                    <small class="text-secondary d-block">Total</small>
                    <strong><?= (int)($lifecycleSummary['total'] ?? 0) ?></strong>
                </div>
            </div>
            <?php if (!empty($lifecycleCounters)): ?>
            <div class="row mt-1">
                <div class="col-12">
                    <small class="text-secondary">
                        Last run: +<?= (int)($lifecycleCounters['new_pending'] ?? 0) ?> new,
                        <?= (int)($lifecycleCounters['preserved_claimed'] ?? 0) ?> claimed preserved,
                        <?= (int)($lifecycleCounters['preserved_pending'] ?? 0) ?> pending preserved,
                        <?= (int)($lifecycleCounters['expired_by_brain'] ?? 0) ?> expired,
                        <?= (int)($lifecycleCounters['cleaned_terminal'] ?? 0) ?> cleaned,
                        <?= $liveTerminalRetained ?> terminal retained
                        | TTL: <?= (int)($last_run['intent_ttl_minutes'] ?? 5) ?>min
                    </small>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <?php
                $filterStage = (string)($last_run['filter_stage_that_removed_all'] ?? 'none');
                $zeroReason = (string)($last_run['zero_output_reason'] ?? '');
                $siSkipped = (bool)($last_run['restrictive_si_skipped'] ?? false);
                $siSkipReason = (string)($last_run['restrictive_si_skip_reason'] ?? '');
                $brainControlledLiveMode = (bool)($last_run['brain_controlled_live_mode'] ?? $liveEnabled);
            ?>
            <?php if ($brainControlledLiveMode): ?>
            <div class="alert alert-success small mb-0 mt-2 py-1 px-2">
                <i class="bi bi-shield-check me-1"></i> <strong>Brain-Controlled Live Mode:</strong> Active — bot will only execute Brain-approved intents. Legacy fallback disabled.
            </div>
            <?php endif; ?>
            <?php if ($filterStage !== 'none' && $zeroReason !== ''): ?>
            <div class="alert alert-info small mb-0 mt-2 py-1 px-2">
                <i class="bi bi-info-circle me-1"></i> <strong>Filter stage:</strong> <?= htmlspecialchars($filterStage) ?> — <?= htmlspecialchars($zeroReason) ?>
            </div>
            <?php endif; ?>
            <?php if ($siSkipped && $siSkipReason !== ''): ?>
            <div class="alert alert-secondary small mb-0 mt-2 py-1 px-2">
                <i class="bi bi-skip-forward me-1"></i> <?= htmlspecialchars($siSkipReason) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- P1: Trading Bot Execution Mirror -->
    <?php
        $botMirror = $bot_execution_mirror ?? [];
        $botAvailable = (bool)($botMirror['available'] ?? false);
        $botError = (string)($botMirror['error'] ?? '');
    ?>
    <div class="card mb-4" style="border-color: <?= $botAvailable ? '#3b82f6' : '#6b7280' ?>;">
        <div class="card-header" style="background: <?= $botAvailable ? 'rgba(59,130,246,0.1)' : 'rgba(107,114,128,0.1)' ?>;">
            <h5 style="margin: 0;">
                <i class="bi bi-robot me-1"></i> Trading Bot Execution Mirror
                <?php if ($botAvailable && ($botMirror['bot_controlled_by_brain'] ?? false)): ?>
                    <span class="badge bg-success ms-1">Brain-Controlled</span>
                <?php elseif ($botAvailable): ?>
                    <span class="badge bg-warning text-dark ms-1">Legacy Mode</span>
                <?php else: ?>
                    <span class="badge bg-secondary ms-1">Unavailable</span>
                <?php endif; ?>
            </h5>
        </div>
        <div class="card-body">
            <?php if (!$botAvailable): ?>
            <p class="text-secondary small mb-0"><i class="bi bi-info-circle me-1"></i>
                Bot runtime unavailable<?= $botError !== '' ? ': ' . htmlspecialchars($botError) : '' ?>
            </p>
            <?php else: ?>
            <div class="row mb-2">
                <div class="col-md-3 mb-2">
                    <strong>Input Source:</strong>
                    <span class="text-info"><?= htmlspecialchars((string)($botMirror['bot_input_source'] ?? '-')) ?></span>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>Source Status:</strong>
                    <span class="text-info"><?= htmlspecialchars((string)($botMirror['source_status'] ?? '-')) ?></span>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>Brain Mode:</strong>
                    <?php if ($botMirror['brain_controlled_live_mode'] ?? false): ?>
                    <span class="text-success">Active</span>
                    <?php else: ?>
                    <span class="text-secondary">Inactive</span>
                    <?php endif; ?>
                </div>
                <div class="col-md-3 mb-2">
                    <strong>Controlled by Brain:</strong>
                    <?php if ($botMirror['bot_controlled_by_brain'] ?? false): ?>
                    <span class="text-success">Yes</span>
                    <?php else: ?>
                    <span class="text-secondary">No</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row mb-2">
                <div class="col-md-2 mb-2">
                    <strong>Loaded:</strong>
                    <span class="text-primary"><?= (int)($botMirror['approved_intents_loaded'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>Duplicates:</strong>
                    <span class="text-secondary"><?= (int)($botMirror['duplicate_skipped'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>After Dedupe:</strong>
                    <span class="text-primary"><?= (int)($botMirror['executable_after_dedupe'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>Busy Skipped:</strong>
                    <span class="text-warning"><?= (int)($botMirror['busy_skipped'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>After Busy:</strong>
                    <span class="text-primary"><?= (int)($botMirror['executable_after_busy'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>Opened:</strong>
                    <span class="text-success"><?= (int)($botMirror['intents_opened'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>Rejected:</strong>
                    <span class="text-danger"><?= (int)($botMirror['intents_rejected'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-2">
                    <strong>Failed / Deferred:</strong>
                    <span class="text-danger"><?= (int)($botMirror['intents_failed'] ?? 0) ?></span>
                    <span class="text-warning"> / <?= (int)($botMirror['intents_deferred'] ?? 0) ?></span>
                </div>
            </div>
            <!-- P0.3: Exchange Submit Visibility -->
            <div class="row mb-2" style="font-size: 0.85rem;">
                <div class="col-md-2 mb-1">
                    <strong>Guard Blocked:</strong>
                    <span class="text-warning"><?= (int)($botMirror['execution_guard_blocked_count'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-1">
                    <strong>Exch. Attempted:</strong>
                    <span class="text-info"><?= (int)($botMirror['exchange_submit_attempted_count'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-1">
                    <strong>Exch. Success:</strong>
                    <span class="text-success"><?= (int)($botMirror['exchange_submit_success_count'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-1">
                    <strong>Exch. Failed:</strong>
                    <span class="text-danger"><?= (int)($botMirror['exchange_submit_failed_count'] ?? 0) ?></span>
                </div>
                <div class="col-md-2 mb-1">
                    <strong>Protection Fail:</strong>
                    <span class="text-danger"><?= (int)($botMirror['protection_apply_failed_count'] ?? 0) ?></span>
                </div>
            </div>
            <?php
                $botRejReasons = (array)($botMirror['rejection_reason_stats'] ?? []);
                $botSourceError = (string)($botMirror['source_error_message'] ?? '');
                $botTopBlocker = (string)($botMirror['top_rejection_reason'] ?? '');
                $botTopExecBlock = (string)($botMirror['top_execution_block_reason'] ?? '');
                $botExchErrCode = $botMirror['latest_exchange_error_code'] ?? null;
                $botExchErrMsg = (string)($botMirror['latest_exchange_error_message'] ?? '');
                $botNoOrderPreview = is_array($botMirror['no_order_path_preview'] ?? null) ? $botMirror['no_order_path_preview'] : [];
            ?>
            <?php if ($botTopBlocker !== '' || $botTopExecBlock !== ''): ?>
            <div class="mb-2 small">
                <?php if ($botTopBlocker !== ''): ?>
                <span class="badge bg-danger bg-opacity-25 text-danger me-1"><i class="bi bi-exclamation-triangle me-1"></i>Top Blocker: <?= htmlspecialchars($botTopBlocker) ?></span>
                <?php endif; ?>
                <?php if ($botTopExecBlock !== ''): ?>
                <span class="badge bg-warning bg-opacity-25 text-warning me-1"><i class="bi bi-shield-exclamation me-1"></i>Guard: <?= htmlspecialchars($botTopExecBlock) ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($botExchErrCode !== null || $botExchErrMsg !== ''): ?>
            <div class="mb-2 small text-danger">
                <i class="bi bi-exclamation-octagon me-1"></i>
                <strong>Latest Exchange Error:</strong>
                <?= $botExchErrCode !== null ? 'code=' . htmlspecialchars((string)$botExchErrCode) : '' ?>
                <?= $botExchErrMsg !== '' ? ' — ' . htmlspecialchars($botExchErrMsg) : '' ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($botRejReasons)): ?>
            <div class="mb-2 small">
                <strong class="text-danger">Rejection Reasons:</strong>
                <?php foreach ($botRejReasons as $reason => $cnt): ?>
                <span class="badge bg-danger bg-opacity-25 text-danger me-1"><?= htmlspecialchars((string)$reason) ?>: <?= (int)$cnt ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($botSourceError !== ''): ?>
            <div class="text-danger small mb-2"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($botSourceError) ?></div>
            <?php endif; ?>
            <?php if (!empty($botNoOrderPreview)): ?>
            <details class="mb-2" style="font-size: 0.78rem;">
                <summary class="text-warning"><i class="bi bi-bug me-1"></i>No-Order Debug Preview (<?= count($botNoOrderPreview) ?> intents)</summary>
                <?php foreach ($botNoOrderPreview as $nop): ?>
                <div class="ps-3 text-muted"><?= htmlspecialchars((string)($nop['symbol'] ?? '')) ?> → <span class="text-danger"><?= htmlspecialchars((string)($nop['final_outcome'] ?? '')) ?></span> @ <?= htmlspecialchars((string)($nop['execution_stage'] ?? '')) ?> — <?= htmlspecialchars(substr((string)($nop['main_reason'] ?? ''), 0, 60)) ?> <?= !empty($nop['exchange_attempted']) ? '(exch: ✓)' : '(exch: ✗)' ?></div>
                <?php endforeach; ?>
            </details>
            <?php endif; ?>
            <!-- Active Protection State (Live Audit) -->
            <?php
                $rtActivePosCount = (int)($botMirror['active_positions_count'] ?? 0);
                $rtProtPosCount = (int)($botMirror['protected_positions_count'] ?? 0);
                $rtTrailActCount = (int)($botMirror['trailing_active_count'] ?? 0);
                $rtBeArmedCount = (int)($botMirror['break_even_armed_count'] ?? 0);
                $rtBeAppliedCount = (int)($botMirror['break_even_applied_count'] ?? 0);
                $rtProtErrCount = (int)($botMirror['protection_errors_count'] ?? 0);
            ?>
            <?php if ($rtActivePosCount > 0): ?>
            <div class="mb-2 small">
                <strong><i class="bi bi-shield-check me-1"></i>Active Protection State:</strong>
                <div class="row mt-1">
                    <div class="col-md-2"><small class="text-secondary">Positions</small><br><strong class="text-info"><?= $rtActivePosCount ?></strong></div>
                    <div class="col-md-2"><small class="text-secondary">Protected</small><br><strong class="text-success"><?= $rtProtPosCount ?></strong></div>
                    <div class="col-md-2"><small class="text-secondary">Trailing Active</small><br><strong class="text-primary"><?= $rtTrailActCount ?></strong></div>
                    <div class="col-md-2"><small class="text-secondary">BE Armed</small><br><strong class="text-warning"><?= $rtBeArmedCount ?></strong></div>
                    <div class="col-md-2"><small class="text-secondary">BE Applied</small><br><strong class="text-success"><?= $rtBeAppliedCount ?></strong></div>
                    <div class="col-md-2"><small class="text-secondary">Prot. Errors</small><br><strong class="text-danger"><?= $rtProtErrCount ?></strong></div>
                </div>
            </div>
            <?php endif; ?>
            <?php
                // Contract generation mix notice (Part 7: Brain mirror must not flatten mixed generations)
                $rtGenStats = is_array($botMirror['active_trade_contract_generation_stats'] ?? null) ? $botMirror['active_trade_contract_generation_stats'] : [];
                $rtMixedGen = (bool)($rtGenStats['mixed_generations'] ?? false);
                if ($rtMixedGen): ?>
            <div class="mb-2 small">
                <strong><i class="bi bi-exclamation-triangle me-1 text-warning"></i>Contract Generation Mix:</strong>
                <span class="badge bg-warning text-dark">Mixed generations detected</span>
                <?php foreach ((array)($rtGenStats['generation_counts'] ?? []) as $gen => $cnt): ?>
                    <span class="badge bg-secondary ms-1"><?= htmlspecialchars((string)$gen) ?>: <?= (int)$cnt ?></span>
                <?php endforeach; ?>
                <?php if ((int)($rtGenStats['migrated_active_trades_count'] ?? 0) > 0): ?>
                    <span class="badge bg-info ms-1">Migrated: <?= (int)$rtGenStats['migrated_active_trades_count'] ?></span>
                <?php endif; ?>
                <br><small class="text-muted">Some active trades were opened under a previous contract generation.</small>
            </div>
            <?php endif; ?>
            <?php
                // Effective Exit Contract Summary (Runtime)
                // Priority: bot mirror flat fields (always populated), then nested contract, then last_run
                $rtEffectiveContract = $botMirror['effective_trailing_contract'] ?? ($last_run['effective_trailing_contract'] ?? []);
                $mirrorExitMode = $botMirror['effective_exit_mode'] ?? ($rtEffectiveContract['exit_mode'] ?? null);
                $mirrorTrailingEnabled = $botMirror['effective_trailing_enabled'] ?? ($rtEffectiveContract['enabled'] ?? null);
                $mirrorBEEnabled = $botMirror['effective_break_even_enabled'] ?? ($rtEffectiveContract['break_even_enabled'] ?? null);
                $mirrorTrailingActivation = $botMirror['effective_trailing_activation'] ?? ($rtEffectiveContract['activation_roi_pct'] ?? ($rtEffectiveContract['trailing_activation_roi_pct'] ?? null));
                $mirrorBEActivation = $botMirror['effective_break_even_activation'] ?? ($rtEffectiveContract['break_even_activation_roi'] ?? ($rtEffectiveContract['break_even_activation_roi_pct'] ?? null));
                $mirrorDrawdown = $botMirror['effective_drawdown_factor'] ?? ($rtEffectiveContract['drawdown_factor'] ?? null);
                $mirrorTrailingMode = $rtEffectiveContract['trailing_mode'] ?? 'roi_giveback';
                $mirrorPriceDistPct = $rtEffectiveContract['trailing_price_distance_pct'] ?? null;
                $mirrorPresetMode = $rtEffectiveContract['trailing_preset_mode'] ?? null;
                $mirrorDistanceRoi = $rtEffectiveContract['trailing_distance_roi'] ?? null;
                $mirrorContractSource = $rtEffectiveContract['trailing_contract_source'] ?? null;
                $mirrorHybridShare = $botMirror['effective_hybrid_tp_share'] ?? ($rtEffectiveContract['hybrid_tp_share'] ?? null);
                $mirrorSource = $botMirror['effective_trailing_contract_source'] ?? 'unknown';
                $rtLegacyPresent = (bool)($rtEffectiveContract['legacy_trailing_fields_present'] ?? false);
                $hasContract = ($mirrorExitMode !== null);
                if ($hasContract):
            ?>
            <div class="mb-2 small">
                <strong><i class="bi bi-arrow-right-circle me-1"></i>Active Trailing Contract</strong>
                <span class="badge bg-primary ms-1" style="font-size:0.65rem;"><?= htmlspecialchars($mirrorTrailingMode) ?></span>
                <?php if ($mirrorPresetMode && $mirrorPresetMode !== 'custom'): ?>
                <span class="badge bg-info ms-1" style="font-size:0.65rem;">preset: <?= htmlspecialchars($mirrorPresetMode) ?></span>
                <?php endif; ?>
                <?php if ($mirrorDistanceRoi !== null && (float)$mirrorDistanceRoi > 0): ?>
                <span class="badge bg-warning text-dark ms-1" style="font-size:0.65rem;">dist: <?= (float)$mirrorDistanceRoi ?> ROI</span>
                <?php endif; ?>
                <?php if ($mirrorContractSource): ?>
                <span class="badge bg-secondary ms-1" style="font-size:0.55rem;"><?= htmlspecialchars($mirrorContractSource) ?></span>
                <?php endif; ?>
                <?php if ($rtLegacyPresent): ?>
                <span class="badge bg-secondary ms-1" style="font-size:0.55rem;">legacy fields preserved</span>
                <?php endif; ?>
                :
                <code><?= htmlspecialchars((string)$mirrorExitMode) ?></code>
                | trailing: <code><?= $mirrorTrailingEnabled ? 'ON' : 'OFF' ?></code>
                | mode: <code><?= htmlspecialchars((string)$mirrorTrailingMode) ?></code>
                | activation: <code><?= htmlspecialchars((string)($mirrorTrailingActivation ?? 'n/a')) ?>%</code>
                <?php if ($mirrorTrailingMode === 'price_distance' || $mirrorTrailingMode === 'price_distance_floor'): ?>
                | distance: <code><?= htmlspecialchars((string)($mirrorPriceDistPct ?? 'n/a')) ?></code> <small class="text-info">(<?= $mirrorPriceDistPct !== null ? round(((float)$mirrorPriceDistPct) * 100, 1) : '?' ?>% from price)</small>
                <?php
                    $rtExchangeDist = $rtEffectiveContract['exchange_trailing_distance'] ?? null;
                    $rtTheoStop = $rtEffectiveContract['theoretical_current_stop_price'] ?? null;
                ?>
                <?php if ($rtExchangeDist !== null): ?>
                | exch.dist: <code><?= round((float)$rtExchangeDist, 4) ?></code>
                <?php endif; ?>
                <?php if ($rtTheoStop !== null): ?>
                | implied stop: <code><?= round((float)$rtTheoStop, 6) ?></code>
                <?php endif; ?>
                <?php if ($mirrorTrailingMode === 'price_distance_floor'): ?>
                <?php
                    $rtFloorLockActive = $rtEffectiveContract['floor_lock_active'] ?? false;
                    $rtFloorLockedRoi = $rtEffectiveContract['floor_locked_roi'] ?? ($rtEffectiveContract['trailing_floor_lock_roi'] ?? null);
                    $rtFloorStopPrice = $rtEffectiveContract['floor_stop_price'] ?? null;
                    $rtStepMode = $rtEffectiveContract['trailing_step_mode'] ?? 'fixed';
                ?>
                | <span class="badge bg-<?= $rtFloorLockActive ? 'success' : 'secondary' ?>">floor <?= $rtFloorLockActive ? 'ON' : 'OFF' ?></span>
                <?php if ($rtFloorLockedRoi !== null): ?>
                lock: <code><?= round((float)$rtFloorLockedRoi, 1) ?>%</code>
                <?php endif; ?>
                <?php if ($rtFloorStopPrice !== null): ?>
                | floor stop: <code><?= round((float)$rtFloorStopPrice, 6) ?></code>
                <?php endif; ?>
                | step: <code><?= htmlspecialchars($rtStepMode) ?></code>
                <?php endif; ?>
                <?php else: ?>
                | drawdown: <code><?= htmlspecialchars((string)($mirrorDrawdown ?? 'n/a')) ?></code>
                <?php endif; ?>
                | BE: <code><?= $mirrorBEEnabled ? 'ON' : 'OFF' ?></code>
                <?php if ($mirrorBEEnabled): ?>
                @ <code><?= htmlspecialchars((string)($mirrorBEActivation ?? '')) ?>%</code>
                <?php endif; ?>
                <?php if ($mirrorExitMode === 'hybrid_tp'): ?>
                | hybrid: <code><?= $mirrorHybridShare !== null ? round(((float)$mirrorHybridShare) * 100) : 'n/a' ?>%</code> @ <code><?= htmlspecialchars((string)($rtEffectiveContract['fixed_take_profit_roi'] ?? '')) ?></code>
                <?php endif; ?>
                | logical stop: <code><?= htmlspecialchars((string)($rtEffectiveContract['logical_stop_roi'] ?? 'n/a')) ?></code>
                | source: <code><?= htmlspecialchars($mirrorSource) ?></code>
                <?php
                $mirrorStopControlMode = (string)($botMirror['effective_stop_control_mode'] ?? 'auto');
                $mirrorEntryRoi = $botMirror['effective_stop_loss_from_entry_roi'] ?? null;
                ?>
                | stop: <code><?= htmlspecialchars($mirrorStopControlMode) ?></code>
                <?php if ($mirrorStopControlMode === 'entry_roi' && $mirrorEntryRoi !== null): ?>
                    (<code><?= round((float)$mirrorEntryRoi * 100, 1) ?>%</code> from entry)
                <?php endif; ?>
                <?php $mirrorStopPrice = $botMirror['effective_stop_price'] ?? null; ?>
                <?php if ($mirrorStopPrice !== null): ?>
                    | stop price: <code><?= number_format((float)$mirrorStopPrice, 4) ?></code>
                <?php endif; ?>
                <?php
                $mirrorInitialStop = $botMirror['initial_computed_stop_price'] ?? null;
                $mirrorStopMoved = (bool)($botMirror['stop_moved_from_initial'] ?? false);
                ?>
                <?php if ($mirrorInitialStop !== null): ?>
                    | initial stop: <code><?= number_format((float)$mirrorInitialStop, 4) ?></code>
                <?php endif; ?>
                <?php if ($mirrorStopMoved): ?>
                    <span class="badge bg-warning text-dark" style="font-size:0.65rem;">moved</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php
            $protDetails = $botMirror['active_position_protection_details'] ?? [];
            if (!empty($protDetails)): ?>
            <div class="mb-2 small">
                <strong><i class="bi bi-shield-fill-check me-1"></i>Active Position Protection Details</strong>
                <div class="table-responsive mt-1">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Symbol</th>
                                <th>Side</th>
                                <th>Entry</th>
                                <th>SL</th>
                                <th>Protection</th>
                                <th>Exit Mode</th>
                                <th>Contract</th>
                                <th>Trailing</th>
                                <th>Break-Even</th>
                                <th>Stop Mode</th>
                                <th>Initial Stop</th>
                                <th>Current Stop</th>
                                <th>Eff. Stop</th>
                                <th>Source</th>
                                <th>⚠</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($protDetails as $pd): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)($pd['symbol'] ?? '')) ?></td>
                                <td><span class="badge bg-<?= ($pd['side'] ?? '') === 'long' ? 'success' : 'danger' ?>"><?= htmlspecialchars(strtoupper((string)($pd['side'] ?? ''))) ?></span></td>
                                <td><?= number_format((float)($pd['entry_price'] ?? 0), 4) ?></td>
                                <td><?= ($pd['stop_loss_applied'] ?? false) ? '✅' : '❌' ?></td>
                                <td><span class="badge bg-<?= ($pd['protection_state'] ?? '') === 'trailing_active' ? 'success' : (($pd['protection_state'] ?? '') === 'opened_protected' ? 'info' : 'warning') ?>"><?= htmlspecialchars((string)($pd['protection_state'] ?? 'unknown')) ?></span></td>
                                <td><small><?= htmlspecialchars((string)($pd['exit_mode'] ?? 'n/a')) ?></small></td>
                                <td>
                                    <small><?= htmlspecialchars((string)($pd['contract_generation'] ?? '')) ?></small>
                                    <?php if ($pd['contract_migrated'] ?? false): ?><span class="badge bg-info" style="font-size:0.6rem;">migrated</span><?php endif; ?>
                                </td>
                                <td>
                                    <?= ($pd['trailing_active'] ?? false) ? '🟢 Active' : (($pd['trailing_enabled'] ?? false) ? '⏳ Enabled' : '⚪ Off') ?>
                                    <?php if ($pd['trailing_activation_roi_pct'] ?? 0): ?>
                                        <small>(<?= number_format((float)($pd['trailing_activation_roi_pct'] ?? 0), 2) ?>%)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($pd['break_even_applied'] ?? false): ?>
                                        ✅ Applied
                                    <?php elseif ($pd['break_even_armed'] ?? false): ?>
                                        🔶 Armed
                                    <?php elseif ($pd['break_even_enabled'] ?? false): ?>
                                        ⏳ Enabled
                                    <?php else: ?>
                                        ⚪ Off
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small><?= htmlspecialchars((string)($pd['stop_control_mode'] ?? 'auto')) ?></small>
                                    <?php if (($pd['stop_control_mode'] ?? 'auto') === 'entry_roi' && ($pd['stop_loss_from_entry_roi'] ?? null) !== null): ?>
                                        <small>(<?= round((float)$pd['stop_loss_from_entry_roi'] * 100, 1) ?>%)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $pdInitialStop = $pd['initial_computed_stop_price'] ?? null; ?>
                                    <?php if ($pdInitialStop !== null): ?>
                                        <small><?= number_format((float)$pdInitialStop, 4) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">—</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $pdStopPrice = $pd['effective_stop_price'] ?? null; ?>
                                    <?php if ($pdStopPrice !== null): ?>
                                        <small><?= number_format((float)$pdStopPrice, 4) ?></small>
                                        <?php if ($pd['stop_moved_from_initial'] ?? false): ?>
                                            <span class="badge bg-warning text-dark" style="font-size:0.55rem;">moved</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <small class="text-muted">—</small>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= htmlspecialchars((string)($pd['effective_trailing_contract_source'] ?? 'unknown')) ?></small></td>
                                <td>
                                    <?php $pdEffStop = (float)($pd['current_effective_stop_price'] ?? 0); ?>
                                    <?php if ($pdEffStop > 0): ?>
                                        <small class="text-success fw-bold"><?= number_format($pdEffStop, 4) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">—</small>
                                    <?php endif; ?>
                                    <?php if ((float)($pd['best_price'] ?? 0) > 0): ?>
                                        <br><small class="text-muted">best: <?= number_format((float)$pd['best_price'], 4) ?></small>
                                    <?php endif; ?>
                                    <?php if ((float)($pd['break_even_stop_price'] ?? 0) > 0): ?>
                                        <br><small class="text-muted">BE: <?= number_format((float)$pd['break_even_stop_price'], 4) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small><?= htmlspecialchars((string)($pd['protection_source_of_truth'] ?? '')) ?></small>
                                </td>
                                <td>
                                    <?php if ($pd['warning_effective_stop_zero_while_protected'] ?? false): ?><span class="badge bg-danger" title="Effective stop = 0 while protected">⚠ stop=0</span><br><?php endif; ?>
                                    <?php if ($pd['warning_protection_source_missing'] ?? false): ?><span class="badge bg-warning text-dark" title="Protection source missing">⚠ src?</span><br><?php endif; ?>
                                    <?php if ($pd['warning_best_price_missing'] ?? false): ?><span class="badge bg-warning text-dark" title="Best price missing">⚠ best?</span><br><?php endif; ?>
                                    <?php if ($pd['warning_floor_lock_active_but_not_enforced'] ?? false): ?><span class="badge bg-danger" title="Floor lock active but not enforced">⚠ floor!</span><br><?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            <div>
                <a href="/admin/trading_bot" class="btn btn-outline-primary btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i>Trading Bot Dashboard</a>
                <a href="/admin/trading_bot/intents" class="btn btn-outline-secondary btn-sm ms-1"><i class="bi bi-list-task me-1"></i>Bot Intents</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- P7: Per-Symbol Exit Statistics (Runtime Detail) -->
    <?php
        $symbolExitStats = $botMirror['symbol_exit_stats'] ?? [];
        if (!empty($symbolExitStats)):
            uasort($symbolExitStats, function($a, $b) {
                return ($b['trades_count'] ?? 0) <=> ($a['trades_count'] ?? 0);
            });
    ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 style="margin: 0;"><i class="bi bi-bar-chart-line me-2"></i>Per-Symbol Exit Behavior</h5>
            <span class="badge bg-primary"><?= count($symbolExitStats) ?> symbols</span>
        </div>
        <div class="card-body">
            <div class="mb-2 small" style="background:rgba(15,23,42,0.5); border:1px solid var(--border-color, #334155); border-radius:0.5rem; padding:0.5rem 0.75rem;">
                <i class="bi bi-info-circle me-1 text-info"></i>
                <strong>Trailing activation rate</strong> = % trades where trailing became active |
                <strong>BE apply rate</strong> = % trades where stop moved to entry |
                <strong>Stop hit</strong> = closed by logical/emergency stop |
                <strong>Median ROI</strong> = robust central measure |
                <strong>MAE p75 Win</strong> = how much adverse move 75% of winning trades survived |
                <strong>Sug. Stop</strong> = recommended working stop for this symbol+side, clamped to [3%–8%]. Emergency stop remains separate and wider
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-sm table-hover mb-0" style="font-size:0.8rem;">
                    <thead>
                        <tr>
                            <th>Symbol</th>
                            <th class="text-center">Trades</th>
                            <th class="text-center">W/L</th>
                            <th class="text-center">WR</th>
                            <th class="text-end">Avg ROI</th>
                            <th class="text-end">Med ROI</th>
                            <th class="text-end">P25</th>
                            <th class="text-end">P75</th>
                            <th class="text-end">Expect</th>
                            <th class="text-center">Stop</th>
                            <th class="text-center">Trail%</th>
                            <th class="text-center">Trail Cl</th>
                            <th class="text-center">BE%</th>
                            <th class="text-end">MAE p75 Win</th>
                            <th class="text-end">Sug. Stop</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($symbolExitStats as $sym => $ss): ?>
                        <?php
                            $cnt = (int)($ss['trades_count'] ?? 0);
                            $wr = (float)($ss['winrate'] ?? 0);
                            $avgR = (float)($ss['avg_roi'] ?? 0);
                            $medR = (float)($ss['roi_stats']['median'] ?? 0);
                            $p25 = (float)($ss['roi_stats']['p25'] ?? 0);
                            $p75 = (float)($ss['roi_stats']['p75'] ?? 0);
                            $exp = (float)($ss['expectancy'] ?? 0);
                            $wrCls = $wr >= 0.5 ? 'text-success' : ($wr >= 0.35 ? 'text-warning' : 'text-danger');
                            $expCls = $exp > 0 ? 'text-success' : ($exp == 0 ? 'text-secondary' : 'text-danger');
                            // MAE-based stop
                            $maeP75W = (float)($ss['mae_winners_stats']['p75'] ?? 0);
                            $maeWinN = (int)($ss['mae_winners_stats']['count'] ?? 0);
                            $sugStop = ($maeWinN >= 5 && $maeP75W > 0)
                                ? max(0.03, min(0.08, $maeP75W)) : 0;
                            $fallback = ($maeWinN < 5 || $maeP75W <= 0);
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($sym) ?></strong>
                                <?php if ($cnt < 10): ?><span class="badge bg-secondary" style="font-size:0.6rem;">low sample</span><?php endif; ?>
                            </td>
                            <td class="text-center"><?= $cnt ?></td>
                            <td class="text-center"><span class="text-success"><?= (int)($ss['wins'] ?? 0) ?></span>/<span class="text-danger"><?= (int)($ss['losses'] ?? 0) ?></span></td>
                            <td class="text-center <?= $wrCls ?>"><?= round($wr * 100, 1) ?>%</td>
                            <td class="text-end"><code><?= number_format($avgR, 2) ?>%</code></td>
                            <td class="text-end"><code><?= number_format($medR, 2) ?>%</code></td>
                            <td class="text-end text-secondary"><code><?= number_format($p25, 2) ?>%</code></td>
                            <td class="text-end text-secondary"><code><?= number_format($p75, 2) ?>%</code></td>
                            <td class="text-end <?= $expCls ?>"><code><?= number_format($exp, 4) ?>%</code></td>
                            <td class="text-center"><?= (int)($ss['stop_hit_count'] ?? 0) ?></td>
                            <td class="text-center"><?= round((float)($ss['trailing_activation_rate'] ?? 0) * 100, 0) ?>%</td>
                            <td class="text-center"><?= (int)($ss['trailing_close_count'] ?? 0) ?></td>
                            <td class="text-center"><?= round((float)($ss['break_even_apply_rate'] ?? 0) * 100, 0) ?>%</td>
                            <td class="text-end"><?php if ($maeP75W > 0): ?><code><?= number_format($maeP75W * 100, 2) ?>%</code> <span class="text-secondary" style="font-size:0.65rem;">n=<?= $maeWinN ?></span><?php else: ?><span class="text-secondary">—</span><?php endif; ?></td>
                            <td class="text-end"><?php if ($sugStop > 0): ?><code class="text-info"><?= number_format($sugStop * 100, 2) ?>%</code><?php if ($fallback): ?> <span class="badge bg-warning text-dark" style="font-size:0.55rem;">fallback</span><?php endif; ?><?php else: ?><span class="text-secondary">—</span><?php endif; ?></td>
                        </tr>
                        <?php // Per-side MAE detail row (compact)
                        $hasSideData = false;
                        foreach (['long', 'short'] as $rtSideKey) {
                            $rtSd = $ss['by_side'][$rtSideKey] ?? null;
                            if ($rtSd && ($rtSd['trades'] ?? 0) > 0 && !empty($rtSd['mae_winners_stats'])) {
                                $hasSideData = true;
                                break;
                            }
                        }
                        if ($hasSideData): ?>
                        <tr style="font-size:0.72rem; background:rgba(15,23,42,0.4);">
                            <td colspan="13" class="text-end text-secondary" style="padding:2px 6px;">
                                <?php foreach (['long', 'short'] as $rtSideKey):
                                    $rtSd = $ss['by_side'][$rtSideKey] ?? null;
                                    if ($rtSd && ($rtSd['trades'] ?? 0) > 0):
                                        $rtSdMaeP75 = (float)($rtSd['mae_winners_stats']['p75'] ?? 0);
                                        $rtSdMaeN = (int)($rtSd['mae_winners_stats']['count'] ?? 0);
                                        $rtSdSugStop = ($rtSdMaeN >= 5 && $rtSdMaeP75 > 0) ? max(0.03, min(0.08, $rtSdMaeP75)) : 0;
                                ?>
                                <span class="badge <?= $rtSideKey === 'long' ? 'bg-success bg-opacity-25 text-success' : 'bg-danger bg-opacity-25 text-danger' ?>" style="font-size:0.65rem;"><?= strtoupper($rtSideKey) ?></span>
                                <?= (int)$rtSd['trades'] ?>t
                                <?php if ($rtSdMaeP75 > 0): ?>MAE p75=<code><?= number_format($rtSdMaeP75 * 100, 2) ?>%</code>(n=<?= $rtSdMaeN ?>)<?php endif; ?>
                                <?php if ($rtSdSugStop > 0): ?>→<code class="text-info"><?= number_format($rtSdSugStop * 100, 2) ?>%</code><?php endif; ?>
                                &nbsp;
                                <?php endif; endforeach; ?>
                            </td>
                            <td class="text-end text-secondary" style="padding:2px 6px;">&nbsp;</td>
                            <td class="text-end text-secondary" style="padding:2px 6px;">&nbsp;</td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Simulator Stats Card -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-bar-chart me-1"></i> Simulator Statistics</h5></div>
        <div class="card-body">
            <?php if (empty($stats)): ?>
                <p class="text-secondary">No stats recorded yet. Run the pipeline to generate metrics.</p>
            <?php else: ?>
                <pre style="background:#0f172a; padding:16px; border-radius:8px; font-size:0.85rem; max-height:400px; overflow:auto; color:#e2e8f0;"><?= htmlspecialchars(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php endif; ?>
        </div>
    </div>

    <!-- Last Run Card -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-clock-history me-1"></i> Last Run</h5></div>
        <div class="card-body">
            <?php if (empty($last_run)): ?>
                <p class="text-secondary">No runs recorded yet.</p>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($last_run as $key => $val): ?>
                    <div class="col-md-3 mb-2">
                        <strong><?= htmlspecialchars((string)$key) ?>:</strong>
                        <span class="text-warning"><?= htmlspecialchars(is_bool($val) ? ($val ? 'true' : 'false') : (string)$val) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Config Snapshot -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-camera me-1"></i> Config Snapshot</h5></div>
        <div class="card-body">
            <?php if (empty($snapshot)): ?>
                <p class="text-secondary">No snapshot recorded yet.</p>
            <?php else: ?>
                <pre style="background:#0f172a; padding:16px; border-radius:8px; font-size:0.85rem; max-height:600px; overflow:auto; color:#e2e8f0;"><?= htmlspecialchars(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php endif; ?>
        </div>
    </div>

    <!-- Current Config -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-gear me-1"></i> Current Config (effective)</h5></div>
        <div class="card-body">
            <pre style="background:#0f172a; padding:16px; border-radius:8px; font-size:0.85rem; max-height:600px; overflow:auto; color:#e2e8f0;"><?= htmlspecialchars(json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        </div>
    </div>
<?php
};

require __DIR__ . '/_layout.php';
