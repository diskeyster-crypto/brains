<?php
/**
 * Pattern Engine — Scenario Decisions
 *
 * @var list<array<string,mixed>>  $scenarios
 * @var string                     $baseUrl
 */
$pageTitle  = 'Pattern Engine — Scenario Decisions';
$activeView = 'scenarios';

$statusColors = [
    'allow_live'   => 'success',
    'allow_demo'   => 'primary',
    'allow_shadow' => 'info',
    'shadow_only'  => 'warning',
    'allow_sim'    => 'secondary',
    'sim_only'     => 'secondary',
    'reject'       => 'danger',
];
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
    <h4 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Scenario Decisions</h4>
    <a href="<?= $baseUrl ?>" class="btn btn-sm btn-outline-secondary">&larr; Overview</a>
  </div>

  <ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>">Overview</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/candidates">Candidates</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/signals">Signals</a></li>
    <li class="nav-item"><a class="nav-link active" href="<?= $baseUrl ?>/scenarios">Scenarios</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/settings">Settings</a></li>
  </ul>

  <p class="text-muted small">Total: <strong><?= count($scenarios) ?></strong> scenario decision(s) stored.</p>

  <?php if (empty($scenarios)): ?>
    <div class="alert alert-secondary">No scenario decisions stored. Run pattern detection first.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>Symbol</th>
          <th>Side</th>
          <th>Algorithm</th>
          <th>Status</th>
          <th class="text-end">Score</th>
          <th>Profile</th>
          <th>Reason</th>
          <th class="text-center">Live</th>
          <th class="text-center">Demo</th>
          <th class="text-center">Shadow</th>
          <th class="text-center">Sim</th>
          <th>Passport OK</th>
          <th>Market OK</th>
          <th>Decided At</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($scenarios as $sc):
          $status    = $sc['scenario_status'] ?? 'unknown';
          $color     = $statusColors[$status] ?? 'secondary';
          $side      = $sc['side'] ?? '';
          $sideColor = $side === 'long' ? 'success' : ($side === 'short' ? 'danger' : 'secondary');
          $boolIcon  = fn(bool $v) => $v ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash-circle text-muted"></i>';
        ?>
        <tr>
          <td><strong><?= htmlspecialchars($sc['symbol'] ?? '—') ?></strong></td>
          <td><span class="badge bg-<?= $sideColor ?>"><?= htmlspecialchars($side) ?></span></td>
          <td><code class="small"><?= htmlspecialchars($sc['pattern_algorithm'] ?? '—') ?></code></td>
          <td><span class="badge bg-<?= $color ?>"><?= htmlspecialchars($status) ?></span></td>
          <td class="text-end"><?= number_format((float)($sc['scenario_score'] ?? 0), 3) ?></td>
          <td class="small"><?= htmlspecialchars($sc['profile_used'] ?? '—') ?></td>
          <td class="small text-muted"><?= htmlspecialchars($sc['scenario_reason'] ?? '—') ?></td>
          <td class="text-center"><?= $boolIcon((bool)($sc['allowed_for_live'] ?? false)) ?></td>
          <td class="text-center"><?= $boolIcon((bool)($sc['allowed_for_demo'] ?? false)) ?></td>
          <td class="text-center"><?= $boolIcon((bool)($sc['allowed_for_shadow'] ?? false)) ?></td>
          <td class="text-center"><?= $boolIcon((bool)($sc['allowed_for_sim'] ?? false)) ?></td>
          <td class="text-center"><?= $boolIcon((bool)($sc['passport_requirements_met'] ?? false)) ?></td>
          <td class="text-center"><?= $boolIcon((bool)($sc['market_requirements_met'] ?? false)) ?></td>
          <td class="small"><?= htmlspecialchars($sc['decided_at'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div>
</body>
</html>
