<?php
/**
 * Copytrading Parser — Admin View
 *
 * @var array $traders
 * @var array $lastRun
 * @var array $positions
 * @var array $history
 */

// Detect base URL
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if (strpos($basePath, '/public') !== false) {
    $basePath = substr($basePath, 0, strpos($basePath, '/public') + 7);
}
$adminUrl = $basePath . '/admin';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Copytrading Parser</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f1419;
            color: #e7e9ea;
            line-height: 1.5;
            padding: 20px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { color: #1d9bf0; margin-bottom: 20px; }
        h2 { color: #71767b; font-size: 1.1rem; margin: 20px 0 10px; }
        
        .card {
            background: #16181c;
            border: 1px solid #2f3336;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .card-title {
            color: #e7e9ea;
            font-size: 1.2rem;
            font-weight: bold;
        }
        
        .btn {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
            border: none;
            font-size: 14px;
            transition: background 0.2s;
        }
        
        .btn-primary {
            background: #1d9bf0;
            color: white;
        }
        .btn-primary:hover { background: #1a8cd8; }
        
        .btn-danger {
            background: #f4212e;
            color: white;
        }
        .btn-danger:hover { background: #dc1d29; }
        
        .btn-secondary {
            background: #2f3336;
            color: #e7e9ea;
        }
        .btn-secondary:hover { background: #3a3d41; }
        
        .back-link {
            display: inline-block;
            color: #1d9bf0;
            text-decoration: none;
            margin-bottom: 20px;
        }
        .back-link:hover { text-decoration: underline; }
        
        .status-card {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .status-item {
            background: #1e2328;
            padding: 15px;
            border-radius: 12px;
        }
        
        .status-label {
            color: #71767b;
            font-size: 12px;
            margin-bottom: 5px;
        }
        
        .status-value {
            font-size: 1.2rem;
            font-weight: bold;
        }
        
        .status-ok { color: #00ba7c; }
        .status-error { color: #f4212e; }
        .status-warning { color: #ffd400; }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            color: #71767b;
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            background: #2f3336;
            border: 1px solid #3a3d41;
            border-radius: 8px;
            color: #e7e9ea;
            font-size: 14px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #1d9bf0;
        }
        
        .form-hint {
            color: #71767b;
            font-size: 12px;
            margin-top: 5px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #2f3336;
        }
        
        th {
            color: #71767b;
            font-weight: 500;
            font-size: 13px;
        }
        
        tr:hover {
            background: #1e2328;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-long { background: #00ba7c22; color: #00ba7c; }
        .badge-short { background: #f4212e22; color: #f4212e; }
        .badge-profit { color: #00ba7c; }
        .badge-loss { color: #f4212e; }
        
        .trader-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            background: #1e2328;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .trader-id {
            font-family: monospace;
            color: #e7e9ea;
            word-break: break-all;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #71767b;
        }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 1px solid #2f3336;
            padding-bottom: 10px;
        }
        
        .tab {
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            color: #71767b;
            background: transparent;
            border: none;
            font-size: 14px;
        }
        
        .tab.active {
            background: #1d9bf0;
            color: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
            color: #71767b;
        }
        
        .loading.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="<?= htmlspecialchars($adminUrl, ENT_QUOTES, 'UTF-8') ?>" class="back-link">← Back to Admin</a>
        
        <h1>📊 Copytrading Parser</h1>
        <p style="color: #71767b; margin-bottom: 20px;">Parse trader positions and history from Bybit copytrading</p>
        
        <!-- Status Card -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">📈 Status</span>
                <button class="btn btn-primary" id="runBtn" onclick="runParser()">
                    ▶ Run Now
                </button>
            </div>
            
            <div class="status-card">
                <div class="status-item">
                    <div class="status-label">Last Run</div>
                    <div class="status-value">
                        <?php if (!empty($lastRun['ts'])): ?>
                            <?= htmlspecialchars(date('d.m.Y H:i:s', strtotime($lastRun['ts'])), ENT_QUOTES, 'UTF-8') ?>
                        <?php else: ?>
                            <span style="color: #71767b;">Never</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="status-item">
                    <div class="status-label">Status</div>
                    <div class="status-value <?= ($lastRun['ok'] ?? false) ? 'status-ok' : 'status-error' ?>">
                        <?= ($lastRun['ok'] ?? false) ? '✓ OK' : '✗ ' . htmlspecialchars($lastRun['status'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>
                
                <div class="status-item">
                    <div class="status-label">Traders</div>
                    <div class="status-value"><?= count($traders) ?></div>
                </div>
                
                <div class="status-item">
                    <div class="status-label">Active Positions</div>
                    <div class="status-value"><?= (int)($lastRun['total_positions'] ?? 0) ?></div>
                </div>
                
                <div class="status-item">
                    <div class="status-label">History Records</div>
                    <div class="status-value"><?= (int)($lastRun['total_history'] ?? 0) ?></div>
                </div>
                
                <div class="status-item">
                    <div class="status-label">Duration</div>
                    <div class="status-value"><?= (int)($lastRun['duration_ms'] ?? 0) ?>ms</div>
                </div>
            </div>
            
            <div class="loading" id="loadingIndicator">
                ⏳ Running parser...
            </div>
        </div>
        
        <!-- Add Trader Card -->
        <div class="card">
            <div class="card-title" style="margin-bottom: 15px;">➕ Add Trader</div>
            
            <form id="addTraderForm" onsubmit="addTrader(event)">
                <div class="form-group">
                    <label for="leaderMark">Trader ID (leaderMark) or Bybit URL</label>
                    <input type="text" class="form-control" id="leaderMark" name="leader_mark" 
                           placeholder="1F9xX0kUmFX9Q0YUu4ma9g== or full Bybit URL" required>
                    <div class="form-hint">
                        Example: https://www.bybit.com/copyTrade/trade-center/detail?leaderMark=1F9xX0kUmFX9Q0YUu4ma9g==
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Add Trader</button>
            </form>
        </div>
        
        <!-- Tracked Traders Card -->
        <div class="card">
            <div class="card-title" style="margin-bottom: 15px;">👥 Tracked Traders (<?= count($traders) ?>)</div>
            
            <?php if (empty($traders)): ?>
                <div class="empty-state">
                    No traders configured. Add a trader above.
                </div>
            <?php else: ?>
                <?php foreach ($traders as $trader): ?>
                    <div class="trader-item">
                        <span class="trader-id"><?= htmlspecialchars($trader, ENT_QUOTES, 'UTF-8') ?></span>
                        <div>
                            <a href="https://www.bybit.com/copyTrade/trade-center/detail?leaderMark=<?= urlencode($trader) ?>" 
                               target="_blank" class="btn btn-secondary" style="margin-right: 5px;">
                                View on Bybit
                            </a>
                            <button class="btn btn-danger" onclick="removeTrader('<?= htmlspecialchars($trader, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>')">
                                Remove
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Data Tabs -->
        <div class="card">
            <div class="tabs">
                <button class="tab active" onclick="switchTab('positions')">Active Positions</button>
                <button class="tab" onclick="switchTab('history')">Trade History</button>
            </div>
            
            <!-- Positions Tab -->
            <div class="tab-content active" id="tab-positions">
                <?php
                $allPositions = [];
                foreach ($positions['traders'] ?? [] as $traderId => $traderData) {
                    foreach ($traderData['positions'] ?? [] as $pos) {
                        $pos['_trader'] = $traderId;
                        $allPositions[] = $pos;
                    }
                }
                ?>
                
                <?php if (empty($allPositions)): ?>
                    <div class="empty-state">
                        No active positions. Run parser to fetch data.
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Symbol</th>
                                <th>Side</th>
                                <th>Entry Price</th>
                                <th>Mark Price</th>
                                <th>Size</th>
                                <th>Leverage</th>
                                <th>PnL</th>
                                <th>ROI %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allPositions as $pos): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($pos['symbol'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong></td>
                                    <td>
                                        <?php
                                        $side = strtolower($pos['side'] ?? '');
                                        $sideClass = (strpos($side, 'buy') !== false || strpos($side, 'long') !== false) ? 'badge-long' : 'badge-short';
                                        ?>
                                        <span class="badge <?= $sideClass ?>"><?= htmlspecialchars(strtoupper($pos['side'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td><?= number_format((float)($pos['entry_price'] ?? 0), 6) ?></td>
                                    <td><?= number_format((float)($pos['mark_price'] ?? 0), 6) ?></td>
                                    <td><?= number_format((float)($pos['size'] ?? 0), 4) ?></td>
                                    <td><?= (int)($pos['leverage'] ?? 1) ?>x</td>
                                    <td class="<?= ($pos['unrealized_pnl'] ?? 0) >= 0 ? 'badge-profit' : 'badge-loss' ?>">
                                        <?= number_format((float)($pos['unrealized_pnl'] ?? 0), 2) ?> USDT
                                    </td>
                                    <td class="<?= ($pos['roi_pct'] ?? 0) >= 0 ? 'badge-profit' : 'badge-loss' ?>">
                                        <?= number_format((float)($pos['roi_pct'] ?? 0), 2) ?>%
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <!-- History Tab -->
            <div class="tab-content" id="tab-history">
                <?php
                $allHistory = [];
                foreach ($history['traders'] ?? [] as $traderId => $traderData) {
                    foreach ($traderData['trades'] ?? [] as $trade) {
                        $trade['_trader'] = $traderId;
                        $allHistory[] = $trade;
                    }
                }
                // Sort by close time descending
                usort($allHistory, function ($a, $b) {
                    return ($b['close_time'] ?? 0) <=> ($a['close_time'] ?? 0);
                });
                ?>
                
                <?php if (empty($allHistory)): ?>
                    <div class="empty-state">
                        No trade history. Run parser to fetch data.
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Symbol</th>
                                <th>Side</th>
                                <th>Entry</th>
                                <th>Exit</th>
                                <th>Size</th>
                                <th>PnL</th>
                                <th>ROI %</th>
                                <th>Closed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($allHistory, 0, 50) as $trade): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($trade['symbol'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong></td>
                                    <td>
                                        <?php
                                        $side = strtolower($trade['side'] ?? '');
                                        $sideClass = (strpos($side, 'buy') !== false || strpos($side, 'long') !== false) ? 'badge-long' : 'badge-short';
                                        ?>
                                        <span class="badge <?= $sideClass ?>"><?= htmlspecialchars(strtoupper($trade['side'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td><?= number_format((float)($trade['entry_price'] ?? 0), 6) ?></td>
                                    <td><?= number_format((float)($trade['close_price'] ?? 0), 6) ?></td>
                                    <td><?= number_format((float)($trade['size'] ?? 0), 4) ?></td>
                                    <td class="<?= ($trade['pnl'] ?? 0) >= 0 ? 'badge-profit' : 'badge-loss' ?>">
                                        <?= number_format((float)($trade['pnl'] ?? 0), 2) ?> USDT
                                    </td>
                                    <td class="<?= ($trade['roi_pct'] ?? 0) >= 0 ? 'badge-profit' : 'badge-loss' ?>">
                                        <?= number_format((float)($trade['roi_pct'] ?? 0), 2) ?>%
                                    </td>
                                    <td style="color: #71767b; font-size: 12px;">
                                        <?php
                                        $closeTime = $trade['close_time'] ?? null;
                                        if ($closeTime) {
                                            if (is_numeric($closeTime)) {
                                                $closeTime = $closeTime > 9999999999 ? $closeTime / 1000 : $closeTime;
                                                echo date('d.m H:i', (int)$closeTime);
                                            } else {
                                                echo date('d.m H:i', strtotime($closeTime));
                                            }
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (count($allHistory) > 50): ?>
                        <p style="text-align: center; color: #71767b; margin-top: 15px;">
                            Showing 50 of <?= count($allHistory) ?> trades
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        const basePath = <?= json_encode($adminUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        
        function switchTab(tabName) {
            // Update tab buttons
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            
            // Update tab content
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.getElementById('tab-' + tabName).classList.add('active');
        }
        
        async function runParser() {
            const btn = document.getElementById('runBtn');
            const loading = document.getElementById('loadingIndicator');
            
            btn.disabled = true;
            btn.textContent = '⏳ Running...';
            loading.classList.add('active');
            
            try {
                const response = await fetch(basePath + '/copytrading/run', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                });
                
                const result = await response.json();
                
                if (result.ok) {
                    alert('✓ Parser completed successfully!\n\nPositions: ' + (result.total_positions || 0) + '\nHistory: ' + (result.total_history || 0));
                    location.reload();
                } else {
                    alert('✗ Parser failed: ' + (result.status || result.error || 'Unknown error'));
                }
            } catch (err) {
                alert('✗ Request failed: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.textContent = '▶ Run Now';
                loading.classList.remove('active');
            }
        }
        
        async function addTrader(event) {
            event.preventDefault();
            
            const form = event.target;
            const leaderMark = document.getElementById('leaderMark').value;
            
            try {
                const response = await fetch(basePath + '/copytrading/add', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'leader_mark=' + encodeURIComponent(leaderMark)
                });
                
                const result = await response.json();
                
                if (result.ok) {
                    alert('✓ Trader added successfully!');
                    location.reload();
                } else {
                    alert('✗ Failed: ' + (result.message || result.error || 'Unknown error'));
                }
            } catch (err) {
                alert('✗ Request failed: ' + err.message);
            }
        }
        
        async function removeTrader(leaderMark) {
            if (!confirm('Remove this trader?')) return;
            
            try {
                const response = await fetch(basePath + '/copytrading/remove', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'leader_mark=' + encodeURIComponent(leaderMark)
                });
                
                const result = await response.json();
                
                if (result.ok) {
                    location.reload();
                } else {
                    alert('✗ Failed: ' + (result.message || result.error || 'Unknown error'));
                }
            } catch (err) {
                alert('✗ Request failed: ' + err.message);
            }
        }
    </script>
</body>
</html>
