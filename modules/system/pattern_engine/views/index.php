<?php
/**
 * Pattern Engine — Overview [INTERNAL DEBUG ASSET]
 *
 * NOT part of the normal user flow. No route renders this file.
 * The user-facing Pattern Engine UI lives in Smart Brain:
 *   /admin/smart_brain/patterns
 *
 * @var array<string,mixed>               $lastRun
 * @var array<string,mixed>               $stats
 * @var list<array<string,mixed>>         $candidates
 * @var list<array<string,mixed>>         $signals
 * @var list<array<string,mixed>>         $scenarios
 * @var string                            $baseUrl
 */

$pageTitle  = 'Pattern Engine';
$activeView = 'overview';

// compute per-status counts
$statusCounts = [];
foreach ($scenarios as $sc) {
    $s = $sc['scenario_status'] ?? 'unknown';
    $statusCounts[$s] = ($statusCounts[$s] ?? 0) + 1;
}

$perPattern = (array)($lastRun['per_pattern'] ?? []);
$perSymbol  = (array)($lastRun['per_symbol'] ?? []);
arsort($perSymbol);
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
    <h4 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Pattern Engine</h4>
    <div>
      <button id="runNowBtn" class="btn btn-sm btn-success me-2">
        <i class="bi bi-play-fill me-1"></i>Run Now
      </button>
      <button id="clearBtn" class="btn btn-sm btn-outline-danger me-2">
        <i class="bi bi-trash me-1"></i>Clear Storage
      </button>
      <a href="<?= $baseUrl ?>/settings" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-gear me-1"></i>Settings
      </a>
    </div>
  </div>

  <!-- Nav tabs -->
  <ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link active" href="<?= $baseUrl ?>">Overview</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/candidates">Candidates</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/signals">Signals</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/scenarios">Scenarios</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/settings">Settings</a></li>
  </ul>

  <!-- Summary cards -->
  <div class="row g-3 mb-4">
    <?php
    $cards = [
      ['label' => 'Candidates',  'value' => count($candidates), 'color' => 'secondary', 'icon' => 'search'],
      ['label' => 'Signals',     'value' => count($signals),    'color' => 'primary',   'icon' => 'lightning-charge'],
      ['label' => 'Scenarios',   'value' => count($scenarios),  'color' => 'info',      'icon' => 'diagram-3'],
      ['label' => 'Allow Demo',  'value' => $statusCounts['allow_demo'] ?? 0,   'color' => 'success', 'icon' => 'play-circle'],
      ['label' => 'Shadow Only', 'value' => ($statusCounts['shadow_only'] ?? 0) + ($statusCounts['allow_shadow'] ?? 0), 'color' => 'warning', 'icon' => 'eye'],
      ['label' => 'Rejected',    'value' => $statusCounts['reject'] ?? 0, 'color' => 'danger',  'icon' => 'x-circle'],
    ];
    foreach ($cards as $card): ?>
    <div class="col-md-2 col-sm-4 col-6">
      <div class="card text-center h-100">
        <div class="card-body py-3">
          <i class="bi bi-<?= $card['icon'] ?> fs-3 text-<?= $card['color'] ?>"></i>
          <div class="fs-4 fw-bold mt-1"><?= (int)$card['value'] ?></div>
          <div class="text-muted small"><?= htmlspecialchars($card['label']) ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3">

    <!-- Per-pattern counts -->
    <div class="col-md-6">
      <div class="card">
        <div class="card-header fw-semibold">Detections by Pattern</div>
        <div class="card-body p-0">
          <?php if (empty($perPattern)): ?>
          <div class="p-3 text-muted text-center">No data yet. Run detection to populate.</div>
          <?php else: ?>
          <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Pattern</th><th class="text-end">Count</th></tr></thead>
            <tbody>
              <?php foreach ($perPattern as $algo => $cnt): ?>
              <tr>
                <td><code><?= htmlspecialchars($algo) ?></code></td>
                <td class="text-end"><?= (int)$cnt ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Per-symbol counts -->
    <div class="col-md-6">
      <div class="card">
        <div class="card-header fw-semibold">Detections by Symbol <span class="text-muted fw-normal small">(top 20)</span></div>
        <div class="card-body p-0">
          <?php if (empty($perSymbol)): ?>
          <div class="p-3 text-muted text-center">No data yet.</div>
          <?php else: ?>
          <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Symbol</th><th class="text-end">Count</th></tr></thead>
            <tbody>
              <?php $i = 0; foreach ($perSymbol as $sym => $cnt): if ($i++ >= 20) break; ?>
              <tr>
                <td><?= htmlspecialchars($sym) ?></td>
                <td class="text-end"><?= (int)$cnt ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Scenario status summary -->
    <div class="col-md-6">
      <div class="card">
        <div class="card-header fw-semibold">Scenario Status Distribution</div>
        <div class="card-body p-0">
          <?php if (empty($statusCounts)): ?>
          <div class="p-3 text-muted text-center">No scenarios yet.</div>
          <?php else: ?>
          <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Status</th><th class="text-end">Count</th></tr></thead>
            <tbody>
              <?php
              $statusColors = [
                'allow_live'  => 'success',  'allow_demo' => 'primary',
                'allow_shadow'=> 'info',     'shadow_only' => 'warning',
                'allow_sim'   => 'secondary','sim_only'    => 'secondary',
                'reject'      => 'danger',
              ];
              arsort($statusCounts);
              foreach ($statusCounts as $st => $cnt):
                $color = $statusColors[$st] ?? 'secondary';
              ?>
              <tr>
                <td><span class="badge bg-<?= $color ?>"><?= htmlspecialchars($st) ?></span></td>
                <td class="text-end"><?= (int)$cnt ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Last run info -->
    <div class="col-md-6">
      <div class="card">
        <div class="card-header fw-semibold">Last Run</div>
        <div class="card-body">
          <?php if (empty($lastRun)): ?>
          <div class="text-muted">No run data yet.</div>
          <?php else: ?>
          <dl class="row mb-0 small">
            <dt class="col-sm-5">Generated at</dt>
            <dd class="col-sm-7"><?= htmlspecialchars($lastRun['generated_at'] ?? '—') ?></dd>
            <dt class="col-sm-5">Candidates</dt>
            <dd class="col-sm-7"><?= (int)($lastRun['candidates_count'] ?? 0) ?></dd>
            <dt class="col-sm-5">Signals</dt>
            <dd class="col-sm-7"><?= (int)($lastRun['signals_count'] ?? 0) ?></dd>
            <dt class="col-sm-5">Scenarios</dt>
            <dd class="col-sm-7"><?= (int)($lastRun['scenarios_count'] ?? 0) ?></dd>
          </dl>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div><!-- /row -->
</div>

<script>
document.getElementById('runNowBtn')?.addEventListener('click', function () {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Running…';
    fetch('<?= $baseUrl ?>/run', { method: 'POST', headers: {'Content-Type':'application/json'}, body: '{}' })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Run Now';
            if (d.ok) {
                const msg = 'Run complete. Candidates: ' + (d.candidates_count ?? 0) +
                            ', Signals: ' + (d.signals_count ?? 0) +
                            ', Scenarios: ' + (d.scenarios_count ?? 0);
                alert(msg);
                location.reload();
            } else {
                alert('Run failed: ' + JSON.stringify(d));
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Run Now';
            alert('Request failed: ' + err);
        });
});

document.getElementById('clearBtn')?.addEventListener('click', function () {
    if (!confirm('Clear all pattern engine storage? This cannot be undone.')) return;
    this.disabled = true;
    fetch('<?= $baseUrl ?>/clear', { method: 'POST' })
        .then(r => r.json())
        .then(() => location.reload())
        .catch(() => { this.disabled = false; alert('Request failed.'); });
});
</script>
</body>
</html>
