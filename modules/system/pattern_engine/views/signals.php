<?php
/**
 * Pattern Engine — Normalized Signals
 *
 * @var list<array<string,mixed>>  $signals
 * @var string                     $baseUrl
 */
$pageTitle  = 'Pattern Engine — Normalized Signals';
$activeView = 'signals';
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
    <h4 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>Normalized Signals</h4>
    <a href="<?= $baseUrl ?>" class="btn btn-sm btn-outline-secondary">&larr; Overview</a>
  </div>

  <ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>">Overview</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/candidates">Candidates</a></li>
    <li class="nav-item"><a class="nav-link active" href="<?= $baseUrl ?>/signals">Signals</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/scenarios">Scenarios</a></li>
    <li class="nav-item"><a class="nav-link" href="<?= $baseUrl ?>/settings">Settings</a></li>
  </ul>

  <p class="text-muted small">Total: <strong><?= count($signals) ?></strong> normalized signal(s) stored.</p>

  <?php if (empty($signals)): ?>
    <div class="alert alert-secondary">No normalized signals stored. Run pattern detection first.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>Signal ID</th>
          <th>Symbol</th>
          <th>Side</th>
          <th>Algorithm</th>
          <th>Ver</th>
          <th class="text-end">Strength</th>
          <th class="text-end">Quality</th>
          <th class="text-end">Entry Hint</th>
          <th class="text-end">Invalidation</th>
          <th class="text-end">Expected Move</th>
          <th>Detected At</th>
          <th class="text-end">TTL (s)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($signals as $s):
          $side      = $s['side'] ?? '';
          $sideColor = $side === 'long' ? 'success' : ($side === 'short' ? 'danger' : 'secondary');
          $strength  = (float)($s['signal_strength'] ?? 0);
          $quality   = (float)($s['quality_score'] ?? 0);
          $strengthClass = $strength >= 0.7 ? 'text-success fw-bold' : ($strength >= 0.5 ? '' : 'text-muted');
        ?>
        <tr>
          <td><code class="small"><?= htmlspecialchars(substr($s['signal_id'] ?? '—', 0, 18)) ?></code></td>
          <td><strong><?= htmlspecialchars($s['symbol'] ?? '—') ?></strong></td>
          <td><span class="badge bg-<?= $sideColor ?>"><?= htmlspecialchars($side) ?></span></td>
          <td><code class="small"><?= htmlspecialchars($s['pattern_algorithm'] ?? '—') ?></code></td>
          <td class="text-muted small"><?= htmlspecialchars($s['pattern_version'] ?? '—') ?></td>
          <td class="text-end <?= $strengthClass ?>"><?= number_format($strength, 3) ?></td>
          <td class="text-end"><?= number_format($quality, 3) ?></td>
          <td class="text-end"><?= $s['entry_hint'] !== null ? number_format((float)$s['entry_hint'], 4) : '—' ?></td>
          <td class="text-end"><?= $s['invalidation_hint'] !== null ? number_format((float)$s['invalidation_hint'], 4) : '—' ?></td>
          <td class="text-end"><?= $s['expected_move_hint'] !== null ? number_format((float)$s['expected_move_hint'], 4) : '—' ?></td>
          <td class="small"><?= htmlspecialchars($s['detected_at'] ?? '—') ?></td>
          <td class="text-end small"><?= (int)($s['ttl_seconds'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div>
</body>
</html>
