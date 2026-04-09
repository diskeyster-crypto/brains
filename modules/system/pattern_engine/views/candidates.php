<?php
/**
 * Pattern Engine — Raw Candidates [INTERNAL DEBUG ASSET]
 *
 * NOT part of the normal user flow. No route renders this file.
 * The user-facing Pattern Engine UI lives in Smart Brain:
 *   /admin/smart_brain/patterns
 *
 * @var list<array<string,mixed>>  $candidates
 * @var string                     $baseUrl
 */
$pageTitle  = 'Pattern Engine — Raw Candidates';
$activeView = 'candidates';
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
    <h4 class="mb-0"><i class="bi bi-search me-2"></i>Raw Candidates</h4>
    <a href="<?= $baseUrl ?>" class="btn btn-sm btn-outline-secondary">&larr; Overview</a>
  </div>

  <ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>">Overview</a></li>
    <li class="nav-item"><a class="nav-link active" href="<?= $baseUrl ?>/candidates">Candidates</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/signals">Signals</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/scenarios">Scenarios</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/settings">Settings</a></li>
  </ul>

  <p class="text-muted small">Total: <strong><?= count($candidates) ?></strong> candidate(s) from last run.</p>

  <?php if (empty($candidates)): ?>
    <div class="alert alert-secondary">No raw candidates stored. Run pattern detection first.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>Symbol</th>
          <th>Side</th>
          <th>Algorithm</th>
          <th>Version</th>
          <th class="text-end">Raw Score</th>
          <th class="text-end">Entry Hint</th>
          <th class="text-end">Invalidation</th>
          <th>Detected At</th>
          <th>TW (min)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($candidates as $c):
          $side      = $c['side'] ?? '';
          $sideColor = $side === 'long' ? 'success' : ($side === 'short' ? 'danger' : 'secondary');
        ?>
        <tr>
          <td><strong><?= htmlspecialchars($c['symbol'] ?? '—') ?></strong></td>
          <td><span class="badge bg-<?= $sideColor ?>"><?= htmlspecialchars($side) ?></span></td>
          <td><code class="small"><?= htmlspecialchars($c['pattern_algorithm'] ?? '—') ?></code></td>
          <td><?= htmlspecialchars($c['pattern_version'] ?? '—') ?></td>
          <td class="text-end"><?= number_format((float)($c['raw_score'] ?? 0), 3) ?></td>
          <td class="text-end"><?= $c['entry_hint'] !== null ? number_format((float)$c['entry_hint'], 4) : '—' ?></td>
          <td class="text-end"><?= $c['invalidation_hint'] !== null ? number_format((float)$c['invalidation_hint'], 4) : '—' ?></td>
          <td class="small"><?= htmlspecialchars($c['detected_at'] ?? '—') ?></td>
          <td class="text-center"><?= (int)($c['time_window_minutes'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div>
</body>
</html>
