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
            'selected_candidate_locked',
            'log_passed_demo_signals_enabled',
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
        $active['selected_candidate_profile_id'] = trim((string)($active['selected_candidate_profile_id'] ?? ''));
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

$warnings = [];
if (($cfg['dynamic_learning_execution_mode'] ?? 'observe') === 'gate_demo' && $selectedCandidateId === '') {
    $warnings[] = 'gate_demo enabled but no selected_candidate_profile_id is set';
}
if (($cfg['dynamic_learning_execution_mode'] ?? 'observe') === 'gate_demo' && !$candidateManualEligible && !$candidateAutoEligible) {
    $warnings[] = 'gate_demo enabled but current candidate is not demo-eligible';
}
if ((bool)($cfg['apply_learning_to_live_enabled'] ?? false) || (bool)($cfg['auto_apply_to_live_enabled'] ?? false)) {
    $warnings[] = 'live apply safety violation detected';
}
if ($candidateReplay === []) {
    $warnings[] = 'candidate replay file is missing';
}

$currentProfileId = trim((string)($currentProfile['profile_id'] ?? ''));
$candidateProfileId = trim((string)($candidateProfile['profile_id'] ?? ''));
$selectedSource = 'manual_selection';
if ($selectedCandidateId !== '' && $selectedCandidateId === $currentProfileId) {
    $selectedSource = 'current_profile';
} elseif ($selectedCandidateId !== '' && $selectedCandidateId === $candidateProfileId) {
    $selectedSource = 'candidate_profile';
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
          <input class="form-control" name="selected_candidate_profile_id" value="<?= $e($selectedCandidateId) ?>" placeholder="candidate profile_id to gate">
        </label>
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
      </div>
    </div>

    <div style="border:1px solid var(--ui-border);border-radius:10px;padding:14px;background:rgba(99,102,241,.05);display:grid;gap:10px;">
      <h4 style="margin:0;">Current Candidate Diagnostics</h4>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
        <div><strong>current candidate status:</strong> <?= $e($candidateStatus) ?></div>
        <div><strong>candidate_manual_demo_gate_eligible:</strong> <?= $e($candidateManualEligible ? 'true' : 'false') ?></div>
        <div><strong>candidate_auto_demo_eligible:</strong> <?= $e($candidateAutoEligible ? 'true' : 'false') ?></div>
        <div><strong>selected_candidate_source:</strong> <?= $e($selectedSource) ?></div>
        <div><strong>current_profile_id:</strong> <?= $e($currentProfileId !== '' ? $currentProfileId : '—') ?></div>
        <div><strong>candidate_profile_id:</strong> <?= $e($candidateProfileId !== '' ? $candidateProfileId : '—') ?></div>
        <div><strong>candidate replay status:</strong> <?= $e($candidateReplayStatus) ?></div>
        <div><strong>candidate vs default delta:</strong> <?= $e($lastRun['candidate_vs_default_delta_pct'] ?? '—') ?></div>
        <div><strong>replay bad blocked:</strong> <?= $e((int)($lastRun['replay_bad_blocked_total'] ?? 0)) ?></div>
        <div><strong>replay good blocked:</strong> <?= $e((int)($lastRun['replay_good_blocked_total'] ?? 0)) ?></div>
        <div><strong>risk threshold:</strong> <?= $e((float)($cfg['replay_demo_only_threshold_candidate'] ?? 30.0)) ?>%</div>
        <div><strong>block threshold:</strong> <?= $e((float)($cfg['replay_risk_threshold_block_candidate'] ?? 60.0)) ?>%</div>
      </div>
      <pre style="margin:0;overflow:auto;background:#0f172a;color:#cbd5e1;padding:10px;border-radius:8px;"><?= $e(json_encode($candidateReplaySummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>

    <button type="submit" class="btn btn-primary">Save</button>
  </form>
</div>
