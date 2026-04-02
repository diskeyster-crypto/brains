<?php
/**
 * Pattern Engine — Settings
 *
 * @var array<string,mixed>  $config
 * @var string               $baseUrl
 */
$pageTitle  = 'Pattern Engine — Settings';
$activeView = 'settings';
$profiles   = (array)($config['scenario_profiles'] ?? []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/assets/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body>
<div class="container-fluid py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-gear me-2"></i>Pattern Engine Settings</h4>
    <a href="<?= $baseUrl ?>" class="btn btn-sm btn-outline-secondary">&larr; Overview</a>
  </div>

  <ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>">Overview</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/candidates">Candidates</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/signals">Signals</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/scenarios">Scenarios</a></li>
    <li class="nav-item"><a class="nav-link active" href="<?= $baseUrl ?>/settings">Settings</a></li>
  </ul>

  <div id="flashArea"></div>

  <!-- Global settings -->
  <div class="card mb-4">
    <div class="card-header fw-semibold">Global Settings</div>
    <div class="card-body">
      <form id="globalForm">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Enabled</label>
            <select name="enabled" class="form-select form-select-sm">
              <option value="1" <?= !empty($config['enabled']) ? 'selected' : '' ?>>Yes</option>
              <option value="0" <?= empty($config['enabled']) ? 'selected' : '' ?>>No</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Default Time Window (min)</label>
            <input type="number" name="default_time_window_minutes" class="form-control form-control-sm"
                   value="<?= (int)($config['default_time_window_minutes'] ?? 15) ?>" min="1">
          </div>
        </div>
        <div class="mt-3">
          <button type="submit" class="btn btn-sm btn-primary">Save Global Settings</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Scenario profiles (read-only display for now) -->
  <div class="card">
    <div class="card-header fw-semibold">Scenario Profiles</div>
    <div class="card-body p-0">
      <?php if (empty($profiles)): ?>
        <div class="p-3 text-muted">No scenario profiles configured.</div>
      <?php else: ?>
      <div class="accordion" id="profileAccordion">
        <?php $idx = 0; foreach ($profiles as $name => $profile): ?>
        <div class="accordion-item">
          <h2 class="accordion-header">
            <button class="accordion-button <?= $idx > 0 ? 'collapsed' : '' ?>"
                    type="button" data-bs-toggle="collapse"
                    data-bs-target="#profile<?= $idx ?>">
              <strong><?= htmlspecialchars($name) ?></strong>
              <span class="ms-2 text-muted small"><?= htmlspecialchars($profile['description'] ?? '') ?></span>
              <span class="ms-auto me-3 badge bg-<?= $profile['execution_mode_hint'] === 'allow_live' ? 'success' : ($profile['execution_mode_hint'] === 'allow_demo' ? 'primary' : 'secondary') ?>">
                <?= htmlspecialchars($profile['execution_mode_hint'] ?? '—') ?>
              </span>
            </button>
          </h2>
          <div id="profile<?= $idx ?>" class="accordion-collapse collapse <?= $idx === 0 ? 'show' : '' ?>">
            <div class="accordion-body">
              <dl class="row small mb-0">
                <?php
                $fields = [
                  'allowed_algorithms'    => 'Allowed Algorithms',
                  'allowed_sides'         => 'Allowed Sides',
                  'min_signal_strength'   => 'Min Signal Strength',
                  'min_quality_score'     => 'Min Quality Score',
                  'min_corridor_p75_roi'  => 'Min Corridor P75 ROI',
                  'min_runner_probability'=> 'Min Runner Probability',
                  'max_noise_score'       => 'Max Noise Score',
                  'min_confidence'        => 'Min Confidence',
                  'min_regime_health'     => 'Min Regime Health',
                  'require_passport'      => 'Require Passport',
                  'execution_mode_hint'   => 'Execution Mode',
                  'degraded_execution_mode'=> 'Degraded Mode',
                  'max_hold_minutes'      => 'Max Hold (min)',
                ];
                foreach ($fields as $key => $label):
                  $val = $profile[$key] ?? null;
                  if ($val === null) continue;
                  if (is_array($val)) $val = implode(', ', $val) ?: '—';
                  if (is_bool($val)) $val = $val ? 'Yes' : 'No';
                ?>
                <dt class="col-sm-4"><?= htmlspecialchars($label) ?></dt>
                <dd class="col-sm-8"><?= htmlspecialchars((string)$val) ?></dd>
                <?php endforeach; ?>
              </dl>
            </div>
          </div>
        </div>
        <?php $idx++; endforeach; ?>
      </div>
      <div class="p-3 text-muted small">
        <i class="bi bi-info-circle me-1"></i>
        Edit scenario profiles directly in <code>config/pattern_engine.json</code> to add or modify profiles.
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script src="/assets/js/bootstrap.bundle.min.js"></script>
<script>
function showFlash(msg, type) {
    const area = document.getElementById('flashArea');
    area.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show py-2">
        ${msg}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>`;
}

document.getElementById('globalForm')?.addEventListener('submit', function (e) {
    e.preventDefault();
    const fd   = new FormData(this);
    const data = { enabled: fd.get('enabled') === '1', default_time_window_minutes: parseInt(fd.get('default_time_window_minutes')) };
    fetch('<?= $baseUrl ?>/settings/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(d => showFlash(d.ok ? 'Settings saved.' : 'Save failed.', d.ok ? 'success' : 'danger'))
    .catch(() => showFlash('Request error.', 'danger'));
});
</script>
</body>
</html>
