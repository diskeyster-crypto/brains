<?php
/**
 * Trading Bot Intents View
 * 
 * Shows processed intent lifecycle and rejected intents history.
 * Observability: each intent shows lifecycle state, result, and reason.
 */

use Core\System\System;
use Core\System\SystemPaths;

$tab = 'intents';

// Include tabs via SystemPaths
$moduleBase = SystemPaths::instance()->get('system.trading_bot');
require $moduleBase . '/views/_tabs.php';
?>

<?php
$lastRunBot = $lastRun ?? [];
$brainSourceBadge = (bool)($lastRunBot['controlled_by_brain'] ?? false)
    ? '<span class="badge bg-info">Brain-controlled</span>'
    : '<span class="badge bg-secondary">Legacy signals</span>';
$brainControlledIntents = (bool)($lastRunBot['controlled_by_brain'] ?? false);
$inputSourceIntents = (string)($lastRunBot['input_source'] ?? 'unknown');
$sourceStatusIntents = (string)($lastRunBot['source_status'] ?? 'n/a');
$legacyFallbackAllowedIntents = (bool)($lastRunBot['legacy_fallback_allowed'] ?? true);
$legacyFallbackUsedIntents = (bool)($lastRunBot['legacy_fallback_used'] ?? false);
$executionKeyBasisIntents = (string)($lastRunBot['execution_identity_key'] ?? 'n/a');
$trailingByBrainIntents = (bool)($lastRunBot['trailing_controlled_by_brain'] ?? false);
$localTrailingOverriddenIntents = (bool)($lastRunBot['local_trailing_toggles_overridden'] ?? false);
$intentResults = is_array($lastRunBot['intent_results'] ?? null) ? $lastRunBot['intent_results'] : [];

// Query string filters
$filterState = $_GET['state'] ?? '';
$filterSymbol = $_GET['symbol'] ?? '';
$filterSide = $_GET['side'] ?? '';

// Collect unique symbols/sides from intent_results for filter dropdowns
$allSymbols = [];
$allStates = [];
foreach ($intentResults as $ir) {
    $s = $ir['symbol'] ?? '';
    $st = $ir['lifecycle_state'] ?? '';
    if ($s !== '' && !in_array($s, $allSymbols, true)) { $allSymbols[] = $s; }
    if ($st !== '' && !in_array($st, $allStates, true)) { $allStates[] = $st; }
}
sort($allSymbols);
sort($allStates);

// Apply filters
$filteredResults = $intentResults;
if ($filterState !== '') {
    $filteredResults = array_filter($filteredResults, fn($r) => ($r['lifecycle_state'] ?? '') === $filterState);
}
if ($filterSymbol !== '') {
    $filteredResults = array_filter($filteredResults, fn($r) => ($r['symbol'] ?? '') === $filterSymbol);
}
if ($filterSide !== '') {
    $filteredResults = array_filter($filteredResults, fn($r) => ($r['side'] ?? '') === $filterSide);
}
$filteredResults = array_values($filteredResults);
?>

<!-- Brain-controlled runtime summary -->
<div class="alert <?= $brainControlledIntents ? 'alert-info' : 'alert-secondary' ?> py-2 mb-3" style="font-size: 0.82rem;">
    <?= $brainSourceBadge ?>
    <strong class="ms-2">Brain-controlled mode:</strong> <?= $brainControlledIntents ? 'ON' : 'OFF' ?>
    | <strong>Input source:</strong> <code><?= htmlspecialchars($inputSourceIntents) ?></code>
    | <strong>Source status:</strong> <code><?= htmlspecialchars($sourceStatusIntents) ?></code>
    <br>
    <strong>Legacy fallback allowed:</strong> <?= $legacyFallbackAllowedIntents ? 'yes' : 'no' ?>
    | <strong>Legacy fallback used:</strong> <?= $legacyFallbackUsedIntents ? 'yes' : 'no' ?>
    | <strong>Execution key basis:</strong> <code><?= htmlspecialchars($executionKeyBasisIntents) ?></code>
    <br>
    <strong>Trailing controlled by Brain:</strong> <?= $trailingByBrainIntents ? 'yes' : 'no' ?>
    | <strong>Local trailing toggles overridden:</strong> <?= $localTrailingOverriddenIntents ? 'yes' : 'no' ?>
</div>

<!-- Processed Intents (Lifecycle) - from last run -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-check me-2"></i>Processed Intents (Last Run)</span>
        <span class="badge bg-secondary"><?= count($filteredResults) ?> / <?= count($intentResults) ?></span>
    </div>
    <div class="card-body">
        <!-- Compact filters -->
        <form method="get" class="row g-2 mb-3 align-items-end" style="font-size: 0.85rem;">
            <input type="hidden" name="tab" value="intents">
            <div class="col-auto">
                <label class="form-label mb-0 small">State</label>
                <select name="state" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All</option>
                    <?php foreach ($allStates as $st): ?>
                    <option value="<?= htmlspecialchars($st) ?>" <?= $filterState === $st ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label mb-0 small">Symbol</label>
                <select name="symbol" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All</option>
                    <?php foreach ($allSymbols as $sym): ?>
                    <option value="<?= htmlspecialchars($sym) ?>" <?= $filterSymbol === $sym ? 'selected' : '' ?>><?= htmlspecialchars($sym) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label mb-0 small">Side</label>
                <select name="side" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All</option>
                    <option value="long" <?= $filterSide === 'long' ? 'selected' : '' ?>>Long</option>
                    <option value="short" <?= $filterSide === 'short' ? 'selected' : '' ?>>Short</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-outline-light">Filter</button>
                <a href="?tab=intents" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
            </div>
        </form>

        <?php if (empty($filteredResults)): ?>
        <p class="text-muted mb-0">No processed intents in last run<?= ($filterState !== '' || $filterSymbol !== '' || $filterSide !== '') ? ' (with current filters)' : '' ?></p>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm table-hover">
            <thead>
                <tr>
                    <th>Intent ID</th>
                    <th>Signal ID</th>
                    <th>Symbol</th>
                    <th>Side</th>
                    <th>State</th>
                    <th>Stage</th>
                    <th>Result</th>
                    <th>Reason</th>
                    <th>Exch?</th>
                    <th>Protection</th>
                    <th>Trailing</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filteredResults as $ir): ?>
                <?php
                    $stateColors = [
                        'opened' => 'success',
                        'protected' => 'success',
                        'trailing_active' => 'primary',
                        'rejected' => 'danger',
                        'failed' => 'danger',
                        'deferred' => 'warning',
                        'skipped' => 'secondary',
                        'pending' => 'info',
                        'closed' => 'dark',
                    ];
                    $irState = $ir['lifecycle_state'] ?? 'unknown';
                    $stateBadge = $stateColors[$irState] ?? 'secondary';
                    $irId = $ir['intent_id'] ?? $ir['execution_identity_key'] ?? '';
                ?>
                <?php $irSignalId = $ir['signal_id'] ?? ''; ?>
                <tr>
                    <td class="small" title="<?= htmlspecialchars($irId) ?>"><?= htmlspecialchars(substr($irId, 0, 12)) ?><?= strlen($irId) > 12 ? '...' : '' ?></td>
                    <td class="small" title="<?= htmlspecialchars($irSignalId) ?>"><?= htmlspecialchars(substr($irSignalId, 0, 10)) ?><?= strlen($irSignalId) > 10 ? '...' : '' ?></td>
                    <td><strong><?= htmlspecialchars($ir['symbol'] ?? '') ?></strong></td>
                    <td>
                        <span class="badge bg-<?= ($ir['side'] ?? '') === 'long' ? 'success' : 'danger' ?>">
                            <?= strtoupper($ir['side'] ?? '') ?>
                        </span>
                    </td>
                    <td><span class="badge bg-<?= $stateBadge ?>"><?= htmlspecialchars($irState) ?></span></td>
                    <td class="small"><?= htmlspecialchars($ir['execution_stage'] ?? '-') ?></td>
                    <td class="small"><?= htmlspecialchars($ir['execution_result'] ?? '') ?></td>
                    <td class="small text-danger">
                        <?= htmlspecialchars($ir['rejection_reason'] ?? $ir['close_reason'] ?? $ir['debug_message'] ?? '') ?>
                        <?php if (!empty($ir['missing_fields_preview'])): ?>
                        <br><small class="text-warning">[<?= htmlspecialchars(implode(', ', $ir['missing_fields_preview'])) ?>]</small>
                        <?php endif; ?>
                    </td>
                    <td class="small text-center"><?= !empty($ir['exchange_submit_attempted']) ? '<span class="text-info">✓</span>' : '<span class="text-muted">✗</span>' ?></td>
                    <td class="small"><?= htmlspecialchars($ir['protection_status'] ?? '-') ?></td>
                    <td class="small"><?= htmlspecialchars($ir['trailing_status'] ?? '-') ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-light"
                                data-json="<?= htmlspecialchars(json_encode($ir, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
                                onclick="openJsonModalFromBtn(this, 'intent_result')">
                            View
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Rejected Intents (Historical) -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-inbox me-2"></i>Rejected Intents (Storage)</span>
        <span class="badge bg-danger"><?= count($intents ?? []) ?></span>
    </div>
    <div class="card-body">
        <?php if (empty($intents)): ?>
        <p class="text-muted mb-0">No rejected intents in storage</p>
        <?php else: ?>
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Execution Key</th>
                    <th>Symbol</th>
                    <th>Side</th>
                    <th>Dedupe</th>
                    <th>Reason</th>
                    <th>Missing Fields</th>
                    <th>Rejected At</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($intents as $item): ?>
                <?php $intent = $item['intent'] ?? $item; ?>
                <?php
                    $execKey = $intent['intent_id'] ?? $intent['id'] ?? $intent['signal_id'] ?? '';
                    $dedupe = !empty($intent['brain_controlled']) ? 'intent_id' : 'signal_id';
                ?>
                <tr>
                    <td class="small" title="<?= htmlspecialchars($execKey) ?>"><?= htmlspecialchars(substr($execKey, 0, 12)) ?>...</td>
                    <td><strong><?= htmlspecialchars($intent['symbol'] ?? '') ?></strong></td>
                    <td>
                        <span class="badge bg-<?= ($intent['side'] ?? '') === 'long' ? 'success' : 'danger' ?>">
                            <?= strtoupper($intent['side'] ?? '') ?>
                        </span>
                    </td>
                    <td class="small"><span class="badge bg-<?= $dedupe === 'intent_id' ? 'info' : 'secondary' ?>"><?= htmlspecialchars($dedupe) ?></span></td>
                    <td class="text-danger small"><?= htmlspecialchars($item['reason'] ?? '') ?></td>
                    <td class="small">
                        <?php if (!empty($item['missing_fields'])): ?>
                            <?= htmlspecialchars(implode(', ', $item['missing_fields'])) ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= htmlspecialchars($item['rejected_at'] ?? '') ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-light"
                                data-json="<?= htmlspecialchars(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"
                                onclick="openJsonModalFromBtn(this, 'rejected_intent')">
                            View
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php
/* RULES
- Intents page shows: (1) processed intents lifecycle from last run, (2) rejected intents history
- Processed intents show lifecycle_state, execution_result, rejection/close reason
- Compact filters: state, symbol, side
- Provides JSON details via universal modal (P6.11)
- No business logic; view-only
- Any dynamic data must be escaped via htmlspecialchars()
*/
?>
