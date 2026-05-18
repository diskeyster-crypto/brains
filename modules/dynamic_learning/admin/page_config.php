<?php

declare(strict_types=1);

use Core\Auth\Auth;
use Core\System\System;

if (!Auth::check()) {
    header('Location: ' . System::adminUrl('login'));
    exit;
}

$moduleDir = dirname(__DIR__);
require_once $moduleDir . '/service.php';
$svc = \Modules\DynamicLearning\DynamicLearningService::instance($moduleDir);
$cfg = $svc->getConfig();
$activePath = $moduleDir . '/config/active.php';
$schema = (array)require $moduleDir . '/config/schema.php';
$safeReadJson = static function (string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
};
$currentProfile = $safeReadJson($moduleDir . '/storage/profiles/early_impulse_growth_long/current_profile.json');
$candidateProfile = $safeReadJson($moduleDir . '/storage/profiles/early_impulse_growth_long/candidate_profile.json');
$candidateReplay = $safeReadJson($moduleDir . '/storage/profiles/early_impulse_growth_long/candidate_replay.json');
$lastRun = $svc->getLastRun();
$baseUrl = rtrim(System::web('admin/dynamic_learning'), '/');
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$saved = false;
$saveError = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'save_config') {
    try {
        $active = is_file($activePath) ? ((array)require $activePath) : [];
        $editableKeys = [
            'dynamic_learning_execution_mode',
            'manual_gate_demo_enabled',
            'apply_learning_to_strategy_enabled',
            'selected_candidate_profile_id',
            'selected_candidate_source',
            'selected_candidate_locked',
            'log_passed_demo_signals_enabled',
            'allow_manual_demo_gate_with_insufficient_data',
            'manual_demo_gate_requires_user_selection',
            'max_blocked_demo_signals',
            'max_blocked_demo_signals_ndjson_size_mb',
            'max_passed_demo_signals_ndjson_size_mb',
        ];

        foreach ($editableKeys as $key) {
            $type = (string)($schema[$key] ?? 'string');
            $raw = $_POST[$key] ?? ($cfg[$key] ?? null);
            $active[$key] = match ($type) {
                'bool' => isset($_POST[$key]) ? (string)$raw === '1' : (bool)($cfg[$key] ?? false),
                'int' => (int)$raw,
                'float' => (float)$raw,
                default => trim((string)$raw),
            };
        }

        $executionMode = strtolower(trim((string)($active['dynamic_learning_execution_mode'] ?? 'observe')));
        if (!in_array($executionMode, ['off', 'observe', 'gate_demo'], true)) {
            $executionMode = 'observe';
        }
        $active['dynamic_learning_execution_mode'] = $executionMode;
        $active['mode'] = $executionMode;
        $active['manual_gate_demo_enabled'] = (bool)($active['manual_gate_demo_enabled'] ?? false);
        $active['apply_learning_to_demo_enabled'] = $active['manual_gate_demo_enabled'];
        $active['apply_learning_to_strategy_enabled'] = (bool)($active['apply_learning_to_strategy_enabled'] ?? false);
        $active['apply_learning_to_live_enabled'] = false;
        $active['auto_apply_to_demo_enabled'] = false;
        $active['auto_apply_to_live_enabled'] = false;
        $active['allow_manual_demo_gate_with_insufficient_data'] = (bool)($active['allow_manual_demo_gate_with_insufficient_data'] ?? true);
        $active['manual_demo_gate_requires_user_selection'] = (bool)($active['manual_demo_gate_requires_user_selection'] ?? true);
        $active['selected_candidate_profile_id'] = trim((string)($active['selected_candidate_profile_id'] ?? ''));
        $active['selected_candidate_source'] = trim((string)($active['selected_candidate_source'] ?? 'manual_selection'));
        $active['selected_candidate_locked'] = true;
        $active['log_passed_demo_signals_enabled'] = (bool)($active['log_passed_demo_signals_enabled'] ?? true);
        $active['max_blocked_demo_signals'] = max(1, (int)($active['max_blocked_demo_signals'] ?? 1000));
        $active['max_blocked_demo_signals_ndjson_size_mb'] = max(1.0, (float)($active['max_blocked_demo_signals_ndjson_size_mb'] ?? 20.0));
        $active['max_passed_demo_signals_ndjson_size_mb'] = max(1.0, (float)($active['max_passed_demo_signals_ndjson_size_mb'] ?? 20.0));

        $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($active, true) . ";\n";
        if (@file_put_contents($activePath, $php, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write active config.');
        }
        $cfg = $svc->getConfig();
        $saved = true;
    } catch (\Throwable $t) {
        $saveError = $t->getMessage();
    }
}

$selectedCandidateId = trim((string)($cfg['selected_candidate_profile_id'] ?? ''));
$candidateStatus = (string)($lastRun['final_candidate_status'] ?? $lastRun['candidate_status'] ?? 'pending');
$candidateReplayStatus = (string)($candidateReplay['replay_candidate_status'] ?? 'missing');
$candidateReplaySummary = (array)($candidateReplay['replay_summary'] ?? $lastRun['candidate_replay_summary'] ?? []);
$candidateManualEligible = (bool)($lastRun['final_candidate_manual_demo_gate_eligible'] ?? $lastRun['candidate_manual_demo_gate_eligible'] ?? false);
$candidateAutoEligible = (bool)($lastRun['final_candidate_auto_demo_eligible'] ?? $lastRun['candidate_auto_demo_eligible'] ?? false);

$gateDemoReady = (bool)($lastRun['gate_demo_ready'] ?? false);
$gateDemoNotReadyReason = (string)($lastRun['gate_demo_not_ready_reason'] ?? '');
$selectedCandidateExists = (bool)($lastRun['selected_candidate_exists'] ?? false);
$selectedCandidateRulesTotal = (int)($lastRun['selected_candidate_rules_total'] ?? 0);
$allowOverride = (bool)($cfg['allow_manual_demo_gate_with_insufficient_data'] ?? true);
$requiresUserSelection = (bool)($cfg['manual_demo_gate_requires_user_selection'] ?? true);

$currentProfileId = trim((string)($currentProfile['profile_id'] ?? ''));
$currentCandidateProfileId = trim((string)($currentProfile['candidate_profile_id'] ?? ''));
$currentCandidateOptionId = $currentCandidateProfileId !== '' ? $currentCandidateProfileId : $currentProfileId;
$candidateProfileId = trim((string)($candidateProfile['profile_id'] ?? ''));
$selectedSource = trim((string)($cfg['selected_candidate_source'] ?? 'manual_selection'));
if ($selectedCandidateId !== '' && $selectedCandidateId === $currentCandidateOptionId) {
    $selectedSource = 'current_profile';
} elseif ($selectedCandidateId !== '' && $selectedCandidateId === $candidateProfileId) {
    $selectedSource = 'candidate_profile';
}
$candidateRulesTotalLatest = count(array_values(array_filter((array)($candidateProfile['rules'] ?? []), static fn(mixed $r): bool => is_array($r))));
$visualSelectedCandidateId = $selectedCandidateId;
if ($visualSelectedCandidateId === '' && $candidateProfileId !== '') {
    $visualSelectedCandidateId = $candidateProfileId;
}
$effectiveSelectedCandidateExists = $selectedCandidateExists;
if (!$effectiveSelectedCandidateExists && $visualSelectedCandidateId !== '' && $visualSelectedCandidateId === $candidateProfileId && $candidateProfileId !== '') {
    $effectiveSelectedCandidateExists = true;
}
$effectiveSelectedRulesTotal = $selectedCandidateRulesTotal;
if ($effectiveSelectedRulesTotal <= 0 && $visualSelectedCandidateId !== '' && $visualSelectedCandidateId === $candidateProfileId) {
    $effectiveSelectedRulesTotal = $candidateRulesTotalLatest;
}
$executionModeCurrent = (string)($cfg['dynamic_learning_execution_mode'] ?? 'observe');
$manualGateEnabledCurrent = (bool)($cfg['manual_gate_demo_enabled'] ?? false);
$applyStrategyEnabledCurrent = (bool)($cfg['apply_learning_to_strategy_enabled'] ?? false);
$liveApplyDisabled = !((bool)($cfg['apply_learning_to_live_enabled'] ?? false));
$autoApplyDisabled = !((bool)($cfg['auto_apply_to_demo_enabled'] ?? false) || (bool)($cfg['auto_apply_to_live_enabled'] ?? false));

$statusReasons = [];
if ($executionModeCurrent !== 'gate_demo') {
    $statusReasons[] = 'execution mode is ' . $executionModeCurrent;
}
if (!$manualGateEnabledCurrent) {
    $statusReasons[] = 'manual_gate_demo_enabled is false';
}
if (!$applyStrategyEnabledCurrent) {
    $statusReasons[] = 'apply_learning_to_strategy_enabled is false';
}
if ($visualSelectedCandidateId === '') {
    $statusReasons[] = 'selected_candidate_profile_id is empty';
}
if (!$effectiveSelectedCandidateExists) {
    $statusReasons[] = 'selected candidate profile does not exist';
}
if ($effectiveSelectedRulesTotal <= 0) {
    $statusReasons[] = 'selected candidate rules_total = 0';
}
if (!$liveApplyDisabled) {
    $statusReasons[] = 'live apply is enabled (must be false)';
}
if (!$autoApplyDisabled) {
    $statusReasons[] = 'auto apply is enabled (must be false)';
}
if ($candidateReplay === []) {
    $statusReasons[] = 'candidate_replay.json is missing';
}
$manualDemoGateReadyNow = $statusReasons === [];

$warnings = [];
if ($executionModeCurrent === 'gate_demo' && $visualSelectedCandidateId === '') {
    $warnings[] = 'gate_demo enabled but selected_candidate_profile_id is empty';
}
if ($executionModeCurrent === 'gate_demo' && $effectiveSelectedRulesTotal <= 0) {
    $warnings[] = 'gate_demo enabled but selected candidate rules_total is 0';
}
if ($executionModeCurrent === 'gate_demo' && !$applyStrategyEnabledCurrent) {
    $warnings[] = 'gate_demo enabled but apply_learning_to_strategy_enabled is false';
}
if ($executionModeCurrent === 'gate_demo' && !$liveApplyDisabled) {
    $warnings[] = 'gate_demo enabled while live apply is true (must be false)';
}
?>
<div style="max-width:980px;display:grid;gap:14px;">
  <h3 style="margin:0;">Dynamic Learning — Config</h3>

  <?php if ($saved): ?>
    <div style="padding:10px 12px;border:1px solid #23863655;border-radius:8px;background:rgba(35,134,54,.10);color:#86efac;">
      Config saved.
    </div>
  <?php endif; ?>
  <?php if ($saveError !== ''): ?>
    <div style="padding:10px 12px;border:1px solid #f8514955;border-radius:8px;background:rgba(248,81,73,.10);color:#f87171;">
      <?= $e($saveError) ?>
    </div>
  <?php endif; ?>
  <?php if ($warnings !== []): ?>
    <div style="padding:10px 12px;border:1px solid #f59e0b55;border-radius:8px;background:rgba(245,158,11,.10);color:#fcd34d;">
      <strong>Warnings:</strong>
      <ul style="margin:8px 0 0 18px;">
        <?php foreach ($warnings as $warning): ?>
          <li><?= $e($warning) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= $e($baseUrl) ?>/config" style="display:grid;gap:14px;">
    <input type="hidden" name="action" value="save_config">

    <div style="border:1px solid var(--ui-border);border-radius:10px;padding:14px;background:rgba(56,189,248,.05);display:grid;gap:10px;">
      <h4 style="margin:0;">Manual Demo Gate</h4>
      <div style="padding:10px 12px;border-radius:8px;border:1px solid <?= $manualDemoGateReadyNow ? '#22c55e55' : '#f59e0b55' ?>;background:<?= $manualDemoGateReadyNow ? 'rgba(34,197,94,.12)' : 'rgba(245,158,11,.12)' ?>;color:<?= $manualDemoGateReadyNow ? '#86efac' : '#fcd34d' ?>;">
        <div style="font-weight:700;font-size:14px;">Manual DEMO Gate status: <?= $manualDemoGateReadyNow ? 'READY' : 'NOT READY' ?></div>
        <?php if ($manualDemoGateReadyNow): ?>
          <div style="margin-top:4px;">All required demo-only conditions are satisfied.</div>
        <?php else: ?>
          <div style="margin-top:4px;">Missing/invalid fields:</div>
          <ul style="margin:6px 0 0 18px;">
            <?php foreach ($statusReasons as $reason): ?>
              <li><?= $e($reason) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if (!$manualDemoGateReadyNow && $gateDemoNotReadyReason !== ''): ?>
          <div style="margin-top:6px;font-size:12px;color:#fde68a;">last_run reason: <?= $e($gateDemoNotReadyReason) ?></div>
        <?php endif; ?>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;padding:10px;border:1px solid var(--ui-border);border-radius:8px;background:rgba(15,23,42,.35);">
        <div><strong>Latest candidate profile:</strong> <?= $e($candidateProfileId !== '' ? $candidateProfileId : '—') ?></div>
        <div><strong>Candidate status:</strong> <?= $e($candidateStatus) ?></div>
        <div><strong>Rules total:</strong> <?= $e($candidateRulesTotalLatest) ?></div>
        <div><strong>Replay bad blocked:</strong> <?= $e((int)($lastRun['replay_bad_blocked_total'] ?? 0)) ?></div>
        <div><strong>Replay good blocked:</strong> <?= $e((int)($lastRun['replay_good_blocked_total'] ?? 0)) ?></div>
        <div><strong>Candidate vs default delta:</strong> <?= $e($lastRun['candidate_vs_default_delta_pct'] ?? '—') ?></div>
        <div><strong>Replay status:</strong> <?= $e($candidateReplayStatus) ?></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
        <label>dynamic_learning_execution_mode
          <select name="dynamic_learning_execution_mode" class="form-control">
            <?php foreach (['off', 'observe', 'gate_demo'] as $mode): ?>
              <option value="<?= $e($mode) ?>" <?= (($cfg['dynamic_learning_execution_mode'] ?? 'observe') === $mode) ? 'selected' : '' ?>><?= $e($mode) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>manual_gate_demo_enabled
          <select name="manual_gate_demo_enabled" class="form-control">
            <option value="1" <?= !empty($cfg['manual_gate_demo_enabled']) ? 'selected' : '' ?>>true</option>
            <option value="0" <?= empty($cfg['manual_gate_demo_enabled']) ? 'selected' : '' ?>>false</option>
          </select>
        </label>
        <label>apply_learning_to_strategy_enabled
          <select name="apply_learning_to_strategy_enabled" class="form-control">
            <option value="1" <?= !empty($cfg['apply_learning_to_strategy_enabled']) ? 'selected' : '' ?>>true</option>
            <option value="0" <?= empty($cfg['apply_learning_to_strategy_enabled']) ? 'selected' : '' ?>>false</option>
          </select>
        </label>
        <label>apply_learning_to_live_enabled
          <input class="form-control" value="false" readonly>
        </label>
        <label>selected_candidate_profile_id
          <select class="form-control" name="selected_candidate_profile_id" id="selected_candidate_profile_id">
            <option value="">-- none --</option>
            <?php if ($candidateProfileId !== ''): ?>
              <option value="<?= $e($candidateProfileId) ?>" <?= $visualSelectedCandidateId === $candidateProfileId ? 'selected' : '' ?>>
                latest candidate_profile.json: <?= $e($candidateProfileId) ?>
              </option>
            <?php endif; ?>
            <?php if ($currentCandidateOptionId !== '' && $currentCandidateOptionId !== $candidateProfileId): ?>
              <option value="<?= $e($currentCandidateOptionId) ?>" <?= $visualSelectedCandidateId === $currentCandidateOptionId ? 'selected' : '' ?>>
                current_profile candidate_profile_id: <?= $e($currentCandidateOptionId) ?>
              </option>
            <?php endif; ?>
          </select>
        </label>
        <input type="hidden" name="selected_candidate_source" id="selected_candidate_source" value="<?= $e($selectedSource) ?>">
        <label>selected_candidate_locked
          <input class="form-control" name="selected_candidate_locked" value="1" readonly>
        </label>
        <label>log_passed_demo_signals_enabled
          <select name="log_passed_demo_signals_enabled" class="form-control">
            <option value="1" <?= !empty($cfg['log_passed_demo_signals_enabled']) ? 'selected' : '' ?>>true</option>
            <option value="0" <?= empty($cfg['log_passed_demo_signals_enabled']) ? 'selected' : '' ?>>false</option>
          </select>
        </label>
        <label>max_blocked_demo_signals
          <input type="number" class="form-control" name="max_blocked_demo_signals" value="<?= $e((int)($cfg['max_blocked_demo_signals'] ?? 1000)) ?>">
        </label>
        <label>max_blocked_demo_signals_ndjson_size_mb
          <input type="number" step="0.1" class="form-control" name="max_blocked_demo_signals_ndjson_size_mb" value="<?= $e((float)($cfg['max_blocked_demo_signals_ndjson_size_mb'] ?? 20.0)) ?>">
        </label>
        <label>max_passed_demo_signals_ndjson_size_mb
          <input type="number" step="0.1" class="form-control" name="max_passed_demo_signals_ndjson_size_mb" value="<?= $e((float)($cfg['max_passed_demo_signals_ndjson_size_mb'] ?? 20.0)) ?>">
        </label>
        <label>auto_apply_to_demo_enabled
          <input class="form-control" value="false" readonly>
        </label>
        <label>auto_apply_to_live_enabled
          <input class="form-control" value="false" readonly>
        </label>
        <label>allow_manual_demo_gate_with_insufficient_data
          <select name="allow_manual_demo_gate_with_insufficient_data" class="form-control">
            <option value="1" <?= $allowOverride ? 'selected' : '' ?>>true — allow demo gate even when data insufficient</option>
            <option value="0" <?= !$allowOverride ? 'selected' : '' ?>>false — require normal eligibility</option>
          </select>
        </label>
        <label>manual_demo_gate_requires_user_selection
          <select name="manual_demo_gate_requires_user_selection" class="form-control">
            <option value="1" <?= $requiresUserSelection ? 'selected' : '' ?>>true — selected_candidate_profile_id must be set</option>
            <option value="0" <?= !$requiresUserSelection ? 'selected' : '' ?>>false — no selection required</option>
          </select>
        </label>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <button type="button" onclick="runGateAction(this,'use_latest_candidate_for_demo_gate')" class="btn btn-sm" style="background:#1e40af;color:#fff;">
          Use latest candidate for DEMO gate
        </button>
        <button type="button" onclick="runGateAction(this,'enable_manual_demo_gate_latest')" class="btn btn-sm" style="background:#b91c1c;color:#fff;">
          Enable manual DEMO gate with latest candidate
        </button>
        <button type="button" onclick="runGateAction(this,'disable_dynamic_learning_gate')" class="btn btn-sm" style="background:#334155;color:#fff;">
          Disable Dynamic Learning gate
        </button>
      </div>
      <div id="gate-action-status" style="font-size:12px;color:#94a3b8;"></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;padding:10px;border:1px solid var(--ui-border);border-radius:8px;background:rgba(15,23,42,.35);">
        <div><strong>Checklist:</strong> mode = gate_demo</div><div><?= $executionModeCurrent === 'gate_demo' ? 'yes' : 'no' ?></div>
        <div><strong>manual_gate_demo_enabled</strong></div><div><?= $manualGateEnabledCurrent ? 'yes' : 'no' ?></div>
        <div><strong>apply_learning_to_strategy_enabled</strong></div><div><?= $applyStrategyEnabledCurrent ? 'yes' : 'no' ?></div>
        <div><strong>selected candidate exists</strong></div><div><?= $effectiveSelectedCandidateExists ? 'yes' : 'no' ?></div>
        <div><strong>selected candidate rules_total > 0</strong></div><div><?= $effectiveSelectedRulesTotal > 0 ? 'yes' : 'no' ?></div>
        <div><strong>live apply disabled</strong></div><div><?= $liveApplyDisabled ? 'yes' : 'no' ?></div>
        <div><strong>auto apply disabled</strong></div><div><?= $autoApplyDisabled ? 'yes' : 'no' ?></div>
      </div>
    </div>

    <div style="border:1px solid var(--ui-border);border-radius:10px;padding:14px;background:rgba(99,102,241,.05);display:grid;gap:10px;">
      <h4 style="margin:0;">Current Candidate Diagnostics</h4>
      <?php if ($gateDemoReady): ?>
      <div style="padding:8px 12px;border-radius:6px;background:rgba(34,197,94,.12);border:1px solid #22c55e55;color:#86efac;font-weight:600;">✓ gate_demo_ready: true — gate is active and will evaluate signals</div>
      <?php else: ?>
      <div style="padding:8px 12px;border-radius:6px;background:rgba(245,158,11,.10);border:1px solid #f59e0b55;color:#fcd34d;">
        gate_demo_ready: false<?= $gateDemoNotReadyReason !== '' ? ' — ' . $e($gateDemoNotReadyReason) : '' ?>
      </div>
      <?php endif; ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
        <div><strong>current candidate status:</strong> <?= $e($candidateStatus) ?></div>
        <div><strong>candidate_manual_demo_gate_eligible:</strong> <?= $e($candidateManualEligible ? 'true' : 'false') ?></div>
        <div><strong>candidate_auto_demo_eligible:</strong> <?= $e($candidateAutoEligible ? 'true' : 'false') ?></div>
        <div><strong>selected_candidate_source:</strong> <?= $e($selectedSource) ?></div>
        <div><strong>selected_candidate_exists:</strong> <?= $e($selectedCandidateExists ? 'yes' : 'no') ?></div>
        <div><strong>selected_candidate_rules_total:</strong> <?= $e($selectedCandidateRulesTotal) ?></div>
        <div><strong>allow_manual_demo_override:</strong> <?= $e($allowOverride ? 'yes' : 'no') ?></div>
        <div><strong>current_profile_id:</strong> <?= $e($currentProfileId !== '' ? $currentProfileId : '—') ?></div>
        <div><strong>candidate_profile_id:</strong> <?= $e($candidateProfileId !== '' ? $candidateProfileId : '—') ?></div>
        <div><strong>candidate replay status:</strong> <?= $e($candidateReplayStatus) ?></div>
        <div><strong>candidate vs default delta:</strong> <?= $e($lastRun['candidate_vs_default_delta_pct'] ?? '—') ?></div>
        <div><strong>replay bad blocked:</strong> <?= $e((int)($lastRun['replay_bad_blocked_total'] ?? 0)) ?></div>
        <div><strong>replay good blocked:</strong> <?= $e((int)($lastRun['replay_good_blocked_total'] ?? 0)) ?></div>
        <div><strong>risk threshold:</strong> <?= $e((float)($cfg['replay_demo_only_threshold_candidate'] ?? 30.0)) ?>%</div>
        <div><strong>block threshold:</strong> <?= $e((float)($cfg['replay_risk_threshold_block_candidate'] ?? 60.0)) ?>%</div>
        <div><strong>live apply:</strong> <span style="color:#86efac;">disabled</span></div>
        <div><strong>auto apply:</strong> <span style="color:#86efac;">disabled</span></div>
      </div>
      <pre style="margin:0;overflow:auto;background:#0f172a;color:#cbd5e1;padding:10px;border-radius:8px;"><?= $e(json_encode($candidateReplaySummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>

    <button type="submit" class="btn btn-primary">Save</button>
  </form>
</div>
<script>
function postDlAjax(action) {
    var baseUrl = <?= htmlspecialchars(json_encode($baseUrl), ENT_QUOTES) ?>;
    return fetch(baseUrl + '/ajax', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=' + encodeURIComponent(action)
    }).then(function(r) { return r.json(); });
}
function runGateAction(btn, action) {
    btn.disabled = true;
    var statusEl = document.getElementById('gate-action-status');
    if (statusEl) { statusEl.textContent = 'Saving…'; statusEl.style.color = '#94a3b8'; }
    postDlAjax(action).then(function(data) {
        if (data.ok) {
            var input = document.getElementById('selected_candidate_profile_id');
            if (input && data.selected_candidate_profile_id !== undefined) { input.value = data.selected_candidate_profile_id || ''; }
            var sourceInput = document.getElementById('selected_candidate_source');
            if (sourceInput && data.selected_candidate_source !== undefined) { sourceInput.value = data.selected_candidate_source || ''; }
            if (statusEl) {
                var msg = 'Saved';
                if (data.selected_candidate_profile_id) { msg += ' | selected=' + data.selected_candidate_profile_id; }
                if (typeof data.gate_demo_ready_after_save !== 'undefined') { msg += ' | gate_demo_ready=' + (data.gate_demo_ready_after_save ? 'true' : 'false'); }
                if (Array.isArray(data.warnings) && data.warnings.length) { msg += ' | warnings: ' + data.warnings.join('; '); }
                statusEl.textContent = msg;
                statusEl.style.color = '#86efac';
            }
            window.setTimeout(function () { window.location.reload(); }, 500);
        } else {
            if (statusEl) {
                statusEl.textContent = 'Error: ' + (data.error || 'unknown');
                statusEl.style.color = '#f87171';
            }
        }
    }).catch(function() {
        if (statusEl) {
            statusEl.textContent = 'Request failed';
            statusEl.style.color = '#f87171';
        }
    }).finally(function() { btn.disabled = false; });
}
</script>
