<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Shadow — Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #3b82f6;
            --card-bg: #1e293b;
            --border-color: #334155;
        }
        body { background: #0f172a; color: #e2e8f0; font-size: 0.9rem; }
        .card { background: var(--card-bg); border: 1px solid var(--border-color); }
        .card-header { background: rgba(0,0,0,0.2); border-bottom: 1px solid var(--border-color); }
        .table-dark { --bs-table-bg: transparent; }
        .form-control, .form-select {
            background: #1e293b; border-color: var(--border-color); color: #e2e8f0;
        }
        .form-control:focus, .form-select:focus {
            background: #1e293b; border-color: var(--primary); color: #e2e8f0;
        }
        .stat-card { background: var(--card-bg); border: 1px solid var(--border-color);
                     border-radius: 8px; padding: 1rem; text-align: center; }
        .stat-value { font-size: 1.5rem; font-weight: 700; color: var(--primary); }
        .stat-label { font-size: 0.75rem; color: #94a3b8; margin-top: 0.2rem; }
        .badge-enter { background: #16a34a; }
        .badge-skip  { background: #6b7280; }
        .badge-agree { background: #0ea5e9; }
        .badge-disagree { background: #f59e0b; }
        .positive { color: #4ade80; }
        .negative { color: #f87171; }
        .neutral  { color: #94a3b8; }
        #flash-msg { display: none; position: fixed; top: 1rem; right: 1rem;
                     z-index: 9999; min-width: 260px; }
    </style>
</head>
<body>
<div class="container-fluid py-4">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">
                <i class="bi bi-robot text-primary me-2"></i>
                AI Shadow
                <span class="badge bg-secondary ms-2"><?= htmlspecialchars((string)($status['mode'] ?? 'shadow')) ?></span>
                <?php if ($status['enabled'] ?? false): ?>
                    <span class="badge bg-success ms-1">ENABLED</span>
                <?php else: ?>
                    <span class="badge bg-secondary ms-1">DISABLED</span>
                <?php endif; ?>
            </h4>
            <p class="text-muted mb-0">
                Read-only AI simulation overlay — no real trading authority
            </p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm" onclick="runMirror()">
                <i class="bi bi-play-fill me-1"></i> Run Mirror
            </button>
            <a href="?page=ai_shadow&tab=settings" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-gear me-1"></i> Settings
            </a>
        </div>
    </div>

    <!-- Flash message -->
    <div id="flash-msg" class="alert alert-info alert-dismissible" role="alert">
        <span id="flash-text"></span>
        <button type="button" class="btn-close" onclick="document.getElementById('flash-msg').style.display='none'"></button>
    </div>

    <!-- Stats Summary -->
    <div class="row g-3 mb-4">
        <?php
        $statCards = [
            ['label' => 'Mirrored Signals',  'key' => 'total_mirrored_signals', 'fmt' => 'int'],
            ['label' => 'Virtual Trades',     'key' => 'total_virtual_trades',   'fmt' => 'int'],
            ['label' => 'AI Win Rate',         'key' => 'ai_win_rate',            'fmt' => 'pct'],
            ['label' => 'AI Avg ROI',          'key' => 'ai_avg_roi',             'fmt' => 'roi'],
            ['label' => 'AI Expectancy',       'key' => 'ai_expectancy',          'fmt' => 'roi'],
            ['label' => 'Live Avg ROI',        'key' => 'live_avg_roi',           'fmt' => 'roi'],
            ['label' => 'Live vs AI Δ',        'key' => 'live_vs_ai_delta',       'fmt' => 'roi'],
            ['label' => 'Agreement Rate',      'key' => 'agreement_rate',         'fmt' => 'pct'],
        ];
        foreach ($statCards as $sc):
            $raw = $stats[$sc['key']] ?? 0;
            if ($sc['fmt'] === 'pct') {
                $display = number_format((float)$raw * 100, 1) . '%';
            } elseif ($sc['fmt'] === 'roi') {
                $val = (float)$raw * 100;
                $cls = $val > 0 ? 'positive' : ($val < 0 ? 'negative' : 'neutral');
                $display = '<span class="' . $cls . '">' . ($val >= 0 ? '+' : '') . number_format($val, 2) . '%</span>';
            } else {
                $display = (string)(int)$raw;
                $cls     = '';
            }
        ?>
        <div class="col-6 col-sm-4 col-md-3 col-xl-1-5">
            <div class="stat-card">
                <div class="stat-value"><?= $display ?></div>
                <div class="stat-label"><?= htmlspecialchars($sc['label']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header py-2">
            <i class="bi bi-funnel me-1"></i> Filters
        </div>
        <div class="card-body py-3">
            <div class="row g-2">
                <div class="col-12 col-sm-6 col-md-2">
                    <input type="text" id="f-symbol" class="form-control form-control-sm"
                           placeholder="Symbol (e.g. BTCUSDT)" oninput="applyFilters()">
                </div>
                <div class="col-12 col-sm-6 col-md-2">
                    <select id="f-pattern" class="form-select form-select-sm" onchange="applyFilters()">
                        <option value="">All Patterns</option>
                        <?php foreach ((array)($config['allowed_patterns'] ?? []) as $p): ?>
                        <option value="<?= htmlspecialchars((string)$p) ?>"><?= htmlspecialchars((string)$p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-4 col-md-2">
                    <select id="f-decision" class="form-select form-select-sm" onchange="applyFilters()">
                        <option value="">All AI Decisions</option>
                        <option value="enter">Enter</option>
                        <option value="skip">Skip</option>
                    </select>
                </div>
                <div class="col-12 col-sm-4 col-md-2">
                    <select id="f-agreement" class="form-select form-select-sm" onchange="applyFilters()">
                        <option value="">All</option>
                        <option value="agree">Agree</option>
                        <option value="disagree">Disagree</option>
                        <option value="pending">Pending</option>
                    </select>
                </div>
                <div class="col-12 col-sm-4 col-md-2">
                    <select id="f-profitable" class="form-select form-select-sm" onchange="applyFilters()">
                        <option value="">All Results</option>
                        <option value="profitable">Profitable</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-2">
                    <button class="btn btn-outline-secondary btn-sm w-100" onclick="clearFilters()">
                        <i class="bi bi-x-circle me-1"></i> Clear
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Comparison Table -->
    <div class="card">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <span><i class="bi bi-table me-1"></i> Virtual Signals</span>
            <span class="text-muted" id="row-count"></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-dark table-hover table-sm mb-0" id="signals-table">
                    <thead>
                        <tr>
                            <th>Symbol</th>
                            <th>Pattern</th>
                            <th>Live Decision</th>
                            <th>AI Decision</th>
                            <th>Confidence</th>
                            <th>Quality</th>
                            <th>Agreement</th>
                            <th>Mirrored At</th>
                            <th>Provider</th>
                        </tr>
                    </thead>
                    <tbody id="signals-tbody">
                        <?php if (empty($virtualSignals)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                No virtual signals yet. Click <strong>Run Mirror</strong> to start.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($virtualSignals as $vs): ?>
                        <?php
                            $aiDec = (string)($vs['ai_decision'] ?? '');
                            $agree = (string)($vs['agreement']   ?? '');
                            $conf  = number_format((float)($vs['ai_confidence']   ?? 0), 2);
                            $qual  = number_format((float)($vs['ai_quality_score']?? 0), 2);
                            $ts    = isset($vs['mirrored_at']) ? date('Y-m-d H:i', (int)$vs['mirrored_at']) : '—';
                        ?>
                        <tr data-symbol="<?= htmlspecialchars((string)($vs['symbol'] ?? '')) ?>"
                            data-pattern="<?= htmlspecialchars((string)($vs['pattern_algorithm'] ?? '')) ?>"
                            data-decision="<?= htmlspecialchars($aiDec) ?>"
                            data-agreement="<?= htmlspecialchars($agree) ?>">
                            <td><strong><?= htmlspecialchars((string)($vs['symbol'] ?? '—')) ?></strong></td>
                            <td><small><?= htmlspecialchars((string)($vs['pattern_algorithm'] ?? '—')) ?></small></td>
                            <td>
                                <span class="badge bg-secondary"><?= htmlspecialchars((string)($vs['live_decision'] ?? '—')) ?></span>
                            </td>
                            <td>
                                <span class="badge <?= $aiDec === 'enter' ? 'badge-enter' : 'badge-skip' ?>">
                                    <?= htmlspecialchars($aiDec ?: '—') ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($conf) ?></td>
                            <td><?= htmlspecialchars($qual) ?></td>
                            <td>
                                <span class="badge <?= $agree === 'agree' ? 'badge-agree' : ($agree === 'disagree' ? 'badge-disagree' : 'bg-secondary') ?>">
                                    <?= htmlspecialchars($agree ?: '—') ?>
                                </span>
                            </td>
                            <td><small><?= htmlspecialchars($ts) ?></small></td>
                            <td><small class="text-muted"><?= htmlspecialchars((string)($vs['provider'] ?? '—')) ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Pagination -->
        <div class="card-footer d-flex justify-content-between align-items-center py-2">
            <div class="text-muted" id="page-info">Page 1</div>
            <div>
                <button class="btn btn-outline-secondary btn-sm me-1" id="btn-prev" onclick="changePage(-1)" disabled>
                    <i class="bi bi-chevron-left"></i>
                </button>
                <button class="btn btn-outline-secondary btn-sm" id="btn-next" onclick="changePage(1)" disabled>
                    <i class="bi bi-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>

</div><!-- /container-fluid -->

<script>
// ---- Mirror action ----
function runMirror() {
    showFlash('Running mirror cycle…', 'info');
    fetch('?page=ai_shadow&api=run_mirror', { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            if (d.skipped) {
                showFlash('Module is disabled. Enable it in Settings first.', 'warning');
            } else {
                const sc = d.signal_counts || {};
                showFlash(
                    'Mirror done. Newly mirrored: ' + (sc.newly_mirrored ?? 0) +
                    ' signals. Errors: ' + (sc.errors ?? 0), 'success'
                );
                setTimeout(() => location.reload(), 1500);
            }
        })
        .catch(() => showFlash('Error calling mirror API.', 'danger'));
}

function showFlash(msg, type) {
    const el = document.getElementById('flash-msg');
    el.className = 'alert alert-' + type + ' alert-dismissible';
    document.getElementById('flash-text').textContent = msg;
    el.style.display = 'block';
}

// ---- Filters & pagination ----
const PAGE_SIZE = 25;
let currentPage = 1;
let allRows = [];

function initTable() {
    allRows = Array.from(document.querySelectorAll('#signals-tbody tr[data-symbol]'));
    applyFilters();
}

function applyFilters() {
    const symbol   = document.getElementById('f-symbol').value.trim().toUpperCase();
    const pattern  = document.getElementById('f-pattern').value;
    const decision = document.getElementById('f-decision').value;
    const agreement= document.getElementById('f-agreement').value;
    const profit   = document.getElementById('f-profitable').value;

    const filtered = allRows.filter(row => {
        if (symbol && !row.dataset.symbol.includes(symbol)) return false;
        if (pattern && row.dataset.pattern !== pattern) return false;
        if (decision && row.dataset.decision !== decision) return false;
        if (agreement && row.dataset.agreement !== agreement) return false;
        return true;
    });

    currentPage = 1;
    renderPage(filtered);
}

function renderPage(rows) {
    const total = rows.length;
    const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    const start = (currentPage - 1) * PAGE_SIZE;
    const end   = start + PAGE_SIZE;

    allRows.forEach(r => r.style.display = 'none');
    rows.slice(start, end).forEach(r => r.style.display = '');

    document.getElementById('row-count').textContent = total + ' signals';
    document.getElementById('page-info').textContent = 'Page ' + currentPage + ' / ' + totalPages;
    document.getElementById('btn-prev').disabled = currentPage <= 1;
    document.getElementById('btn-next').disabled = currentPage >= totalPages;

    // Store for pagination
    window._filteredRows = rows;
}

function changePage(delta) {
    const total = window._filteredRows ? window._filteredRows.length : 0;
    const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    currentPage = Math.min(totalPages, Math.max(1, currentPage + delta));
    renderPage(window._filteredRows || []);
}

function clearFilters() {
    document.getElementById('f-symbol').value   = '';
    document.getElementById('f-pattern').value  = '';
    document.getElementById('f-decision').value = '';
    document.getElementById('f-agreement').value= '';
    document.getElementById('f-profitable').value='';
    applyFilters();
}

document.addEventListener('DOMContentLoaded', initTable);
</script>
</body>
</html>
