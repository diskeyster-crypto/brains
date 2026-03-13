<?php
declare(strict_types=1);

/** @var string $title */
/** @var array<string,mixed> $last_run */
/** @var array<int,array<string,mixed>> $signals */
/** @var array<int,array<string,mixed>> $monitors */
/** @var array<int,array<string,mixed>> $waiting */
/** @var array<int,array<string,mixed>> $active */
/** @var array<int,array<string,mixed>> $closed */
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($title ?? 'Smart Brain') ?></title>
<style>
body { font-family: Arial, sans-serif; background:#101828; color:#e5e7eb; margin:0; padding:24px; }
h1,h2 { margin: 0 0 12px; }
.card { background:#1f2937; border:1px solid #374151; border-radius:10px; padding:16px; margin-bottom:18px; }
.grid { display:grid; grid-template-columns: repeat(3, 1fr); gap:16px; }
table { width:100%; border-collapse: collapse; font-size:14px; }
th, td { border-bottom:1px solid #374151; padding:8px; text-align:left; }
small { color:#9ca3af; }
</style>
</head>
<body>
<h1><?= htmlspecialchars($title ?? 'Smart Brain') ?></h1>

<div class="grid">
  <div class="card">
    <h2>Last Run</h2>
    <div><small>Updated:</small> <?= htmlspecialchars((string)($last_run['updated_at'] ?? '-')) ?></div>
    <div><small>Candidates:</small> <?= htmlspecialchars((string)($last_run['candidates'] ?? '0')) ?></div>
    <div><small>Monitors:</small> <?= htmlspecialchars((string)($last_run['monitors'] ?? '0')) ?></div>
    <div><small>Signals:</small> <?= htmlspecialchars((string)($last_run['signals'] ?? '0')) ?></div>
  </div>

  <div class="card">
    <h2>Signals</h2>
    <div><?= count($signals) ?> шт.</div>
  </div>

  <div class="card">
    <h2>Simulator</h2>
    <div><small>Waiting:</small> <?= count($waiting) ?></div>
    <div><small>Active:</small> <?= count($active) ?></div>
    <div><small>Closed:</small> <?= count($closed) ?></div>
  </div>
</div>

<div class="card">
  <h2>Monitors</h2>
  <table>
    <tr><th>Symbol</th><th>Corridor</th><th>Entry Zone</th><th>Status</th></tr>
    <?php foreach ($monitors as $row): ?>
      <tr>
        <td><?= htmlspecialchars((string)($row['symbol'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string)($row['corridor_low'] ?? '')) ?> → <?= htmlspecialchars((string)($row['corridor_high'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string)($row['entry_zone_low'] ?? '')) ?> → <?= htmlspecialchars((string)($row['entry_zone_high'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string)($row['status'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card">
  <h2>Simulator / WAITING</h2>
  <table>
    <tr><th>Symbol</th><th>Budget</th><th>Leverage</th><th>Entry Zone</th></tr>
    <?php foreach ($waiting as $row): ?>
      <tr>
        <td><?= htmlspecialchars((string)($row['symbol'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string)($row['budget'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string)($row['leverage'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string)($row['entry_zone_low'] ?? '')) ?> → <?= htmlspecialchars((string)($row['entry_zone_high'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
</body>
</html>
