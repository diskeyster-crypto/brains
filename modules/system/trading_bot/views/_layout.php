<?php
/**
 * Trading Bot Layout
 * 
 * Base layout for Trading Bot admin pages.
 */

use Core\System\SystemPaths;
use Core\System\System;

$paths = SystemPaths::instance();
$themeDir = $paths->has('system.theme') ? $paths->get('system.theme') : '';

// Base URL for API calls (no hardcoded /public/admin/trading)
$baseUrl = System::adminUrl('trading');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Trading Bot') ?> — Tredercopis</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    
    <style>
        :root {
            --bg-dark: #1a1d21;
            --bg-card: #212529;
            --border-color: #2d3238;
            --text-muted: #adb5bd;
            --success: #198754;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #0dcaf0;
        }
        
        body {
            background: var(--bg-dark);
            color: #e9ecef;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
        }
        
        .card-header {
            background: rgba(0,0,0,0.2);
            border-bottom: 1px solid var(--border-color);
        }
        
        .table {
            --bs-table-bg: transparent;
            --bs-table-color: #e9ecef;
        }
        
        .table > thead {
            border-bottom: 2px solid var(--border-color);
        }
        
        .table > tbody > tr {
            border-bottom: 1px solid var(--border-color);
        }
        
        .badge-live { background: var(--danger); }
        .badge-dry { background: var(--warning); color: #000; }
        .badge-test { background: var(--info); color: #000; }
        
        .status-ok { color: var(--success); }
        .status-error { color: var(--danger); }
        .status-warning { color: var(--warning); }
        
        .nav-tabs .nav-link {
            color: var(--text-muted);
            border: none;
            border-bottom: 2px solid transparent;
        }
        
        .nav-tabs .nav-link.active {
            color: #fff;
            background: transparent;
            border-bottom-color: var(--info);
        }
        
        .nav-tabs .nav-link:hover {
            color: #fff;
            border-bottom-color: var(--border-color);
        }
        
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        
        .log-line {
            font-family: monospace;
            font-size: 0.85rem;
            padding: 0.25rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .log-error { color: var(--danger); }
        .log-warning { color: var(--warning); }
        .log-info { color: var(--info); }

        /* ==========================================
           Contrast tweaks (P6.11 UI readability)
           ========================================== */
        .text-muted { color: var(--text-muted) !important; }
        small.text-muted, .stat-label { color: var(--text-muted) !important; }

        /* Improve readability of code/json blocks */
        pre, code { color: #e9ecef; }
        #jsonModalPre,
        pre.small,
        pre.p-2,
        pre.p-3 {
            background: #0f1216 !important;
            border: 1px solid #3a4149 !important;
        }

        /* Table headers */
        .table thead th { color: #dee2e6; }

        /* Card headers slightly brighter */
        .card-header { background: rgba(255,255,255,0.03); }


        /* Modal theme */
        .modal-content {
            background: var(--bg-card);
            color: #e9ecef;
            border: 1px solid var(--border-color);
        }
        .modal-header { border-bottom: 1px solid var(--border-color); }


    
        .position-card {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
        }
        .position-card .text-muted {
            color: rgba(233,236,239,0.70) !important;
        }
        .position-card strong {
            color: #fff;
        }
        .form-control, .form-select {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.12);
            color: #fff;
        }
        .form-control:focus, .form-select:focus {
            background: rgba(255,255,255,0.08);
            border-color: rgba(13,202,240,0.5);
            box-shadow: 0 0 0 0.2rem rgba(13,202,240,0.12);
            color: #fff;
        }
        .form-control::placeholder {
            color: rgba(233,236,239,0.45);
        }

    

        /* Readability helpers (labels / muted text / code blocks) */
        .form-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 2px 10px;
            margin-bottom: 6px;
            border-radius: 10px;
            background: rgba(0,0,0,0.18);
            border: 1px solid rgba(255,255,255,0.10);
            color: rgba(233,236,239,0.92);
            font-weight: 600;
            letter-spacing: 0.2px;
        }
        .form-label .bi-question-circle {
            color: rgba(233,236,239,0.60) !important;
        }
        .form-check-label {
            color: rgba(233,236,239,0.90);
        }
        .text-muted {
            color: rgba(233,236,239,0.60) !important;
        }
        .table td:not(.text-muted) {
            color: rgba(233,236,239,0.95);
            font-weight: 600;
        }
        code {
            color: rgba(233,236,239,0.95);
            background: rgba(0,0,0,0.22);
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 8px;
            padding: 0.10rem 0.40rem;
        }
        pre {
            color: rgba(233,236,239,0.95);
            background: rgba(0,0,0,0.22);
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 12px;
            padding: 10px 12px;
        }
        pre code {
            background: transparent;
            border: 0;
            padding: 0;
        }
</style>
</head>
<body>
    <!-- Header -->
    <nav class="navbar navbar-dark bg-dark border-bottom" style="border-color: var(--border-color) !important;">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?= System::adminUrl('') ?>">
                <i class="bi bi-robot me-2"></i>Trading Bot
            </a>
            <div class="d-flex align-items-center gap-3">
                <a href="<?= System::adminUrl('') ?>" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-house me-1"></i> Главная
                </a>
                <a href="<?= System::adminUrl('brain') ?>" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-cpu me-1"></i> Brain
                </a>
                <a href="<?= System::adminUrl('simulator') ?>" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-play-circle me-1"></i> Simulator
                </a>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="container-fluid py-4">
        <?= $content ?>
    </div>
    
    
    <!-- Stops / Trailing Edit Modal -->
    <div class="modal fade" id="stopsModal" tabindex="-1" aria-labelledby="stopsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="stopsModalLabel">Изменить SL / Trailing</h5>
                        <div class="small text-muted" id="stopsModalSub"></div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-light" id="stopsModalRawBtn">
                            <i class="bi bi-code-slash me-1"></i>RAW
                        </button>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="stops_symbol" value="">
                    <input type="hidden" id="stops_side" value="">
                    <input type="hidden" id="stops_position_idx" value="0">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Stop Loss (price)</label>
                            <input class="form-control" id="stops_stop_loss" type="number" step="0.00000001" placeholder="оставь пустым чтобы не менять">
                            <div class="form-text text-muted">Пусто/0 → не меняем</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Trailing Stop (distance)</label>
                            <input class="form-control" id="stops_trailing_stop" type="number" step="0.00000001" placeholder="оставь пустым чтобы не менять">
                            <div class="form-text text-muted">Distance в цене (Bybit trailingStop)</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Active Price</label>
                            <input class="form-control" id="stops_active_price" type="number" step="0.00000001" placeholder="оставь пустым чтобы не менять">
                            <div class="form-text text-muted">Цена активации trailing</div>
                        </div>
                    </div>
                    <div class="mt-3 small text-muted">
                        Важно: сейчас это только ручное изменение. Brain-команды (Phase-2) будут отдельным контуром.
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="me-auto small" id="stopsModalStatus"></div>
                    <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-info" id="stopsModalSaveBtn" onclick="saveStopsNow()">
                        <i class="bi bi-check2 me-1"></i>Save
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Universal JSON Modal (P6.11) -->
    <div class="modal fade" id="jsonModal" tabindex="-1" aria-labelledby="jsonModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="jsonModalLabel">Details</h5>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-light" id="jsonModalCopyBtn">
                            <i class="bi bi-clipboard me-1"></i>Copy
                        </button>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body">
                    <pre id="jsonModalPre" class="p-3 rounded small" style="white-space:pre-wrap;word-break:break-word;max-height:65vh;overflow:auto;background:rgba(0,0,0,0.4);border:1px solid var(--border-color);"></pre>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Base URL from PHP (no hardcoded paths)
        const BASE = <?= json_encode(rtrim($baseUrl, '/')) ?>;
        // Auto-refresh handled via initAutoReload() (pauses while modal is open)
        // API helper - uses dynamic BASE URL
        async function apiCall(endpoint, method = 'GET', data = null) {
            const options = {
                method,
                headers: { 'Content-Type': 'application/json' },
            };
            if (data) {
                options.body = JSON.stringify(data);
            }
            
            const response = await fetch(`${BASE}/${endpoint}`, options);
            return await response.json();
        }


        // Close position now (no confirm)
        async function closePositionNow(payload) {
            try {
                const res = await apiCall('api/position/close', 'POST', payload);
                if (!res.ok) {
                    const msg = res.error || (res.close && (res.close.error || res.close.ret_msg)) || 'close_failed';
                    alert('Close failed: ' + msg);
                    return;
                }
                // reload to refresh positions
                location.reload();
            } catch (e) {
                alert('Close request failed: ' + e.message);
            }
        }

        // Open modal to edit SL / trailing
        let STOPS_LAST_RAW = null;
        function openStopsModal(position) {
            STOPS_LAST_RAW = position;
            document.getElementById('stops_symbol').value = position.symbol || '';
            document.getElementById('stops_side').value = position.side || '';
            document.getElementById('stops_position_idx').value = position.position_idx || 0;

            document.getElementById('stops_stop_loss').value = (position.stop_loss && position.stop_loss > 0) ? position.stop_loss : '';
            document.getElementById('stops_trailing_stop').value = (position.trailing_stop && position.trailing_stop > 0) ? position.trailing_stop : '';
            document.getElementById('stops_active_price').value = (position.active_price && position.active_price > 0) ? position.active_price : '';

            const sub = `${position.symbol || ''} · ${String(position.side || '').toUpperCase()} · idx=${position.position_idx || 0}`;
            document.getElementById('stopsModalSub').textContent = sub;
            document.getElementById('stopsModalStatus').textContent = '';

            const rawBtn = document.getElementById('stopsModalRawBtn');
            rawBtn.onclick = () => openJsonModal('Position RAW', position.raw || position);

            const modalEl = document.getElementById('stopsModal');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        }

        async function saveStopsNow() {
            const btn = document.getElementById('stopsModalSaveBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

            const payload = {
                symbol: document.getElementById('stops_symbol').value,
                side: document.getElementById('stops_side').value,
                position_idx: parseInt(document.getElementById('stops_position_idx').value || '0', 10),
            };

            const sl = parseFloat(document.getElementById('stops_stop_loss').value);
            const ts = parseFloat(document.getElementById('stops_trailing_stop').value);
            const ap = parseFloat(document.getElementById('stops_active_price').value);

            if (!isNaN(sl) && sl > 0) payload.stop_loss = sl;
            if (!isNaN(ts) && ts > 0) payload.trailing_stop = ts;
            if (!isNaN(ap) && ap > 0) payload.active_price = ap;

            try {
                const res = await apiCall('api/position/stops', 'POST', payload);
                if (!res.ok) {
                    const msg = res.error || (res.exchange && (res.exchange.error || res.exchange.ret_msg)) || 'update_failed';
                    document.getElementById('stopsModalStatus').innerHTML = `<span class="text-danger">Failed: ${msg}</span>`;
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save';
                    return;
                }
                document.getElementById('stopsModalStatus').innerHTML = '<span class="text-success">Updated</span>';
                // Close modal + reload
                const modalEl = document.getElementById('stopsModal');
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                location.reload();
            } catch (e) {
                document.getElementById('stopsModalStatus').innerHTML = `<span class="text-danger">Request failed: ${e.message}</span>`;
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save';
            }
        }

        // Run button handler
        async function runBot() {
            const btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Running...';
            
            try {
                const result = await apiCall('api/run', 'POST');
                if (result.ok) {
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-play-fill me-1"></i> Run';
                }
            } catch (e) {
                alert('Request failed: ' + e.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-play-fill me-1"></i> Run';
            }
        }
        
        // Stop button handler
        async function stopBot() {
            if (!confirm('Are you sure you want to stop the bot?')) return;
            
            const btn = event.target;
            btn.disabled = true;
            
            try {
                const result = await apiCall('api/stop', 'POST');
                if (result.ok) {
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (e) {
                alert('Request failed: ' + e.message);
            }
            
            btn.disabled = false;
        }
        
        // Reconcile button handler
        async function reconcile() {
            const btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Syncing...';
            
            try {
                const result = await apiCall('api/reconcile', 'POST');
                if (result.ok) {
                    location.reload();
                } else {
                    alert('Error: ' + (result.error || 'Unknown error'));
                }
            } catch (e) {
                alert('Request failed: ' + e.message);
            }
            
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Reconcile';
        }

        /* ======================================================
           P6.11: JSON modal helpers (UI explainability)
           ====================================================== */
        let __tbJsonModal = null;
        let __tbJsonModalOpen = false;
        let __tbJsonModalLastText = '';

        function openJsonModal(title, payload) {
            try {
                if (__tbJsonModal === null) {
                    const el = document.getElementById('jsonModal');
                    __tbJsonModal = new bootstrap.Modal(el);
                    el.addEventListener('shown.bs.modal', () => { __tbJsonModalOpen = true; });
                    el.addEventListener('hidden.bs.modal', () => { __tbJsonModalOpen = false; });
                }

                document.getElementById('jsonModalLabel').textContent = title || 'Details';

                let obj = payload;
                if (typeof payload === 'string') {
                    // Try parse JSON string; if fails, show raw
                    try {
                        obj = JSON.parse(payload);
                    } catch (e) {
                        obj = payload;
                    }
                }

                const text = (typeof obj === 'string') ? obj : JSON.stringify(obj, null, 2);
                __tbJsonModalLastText = text;

                const pre = document.getElementById('jsonModalPre');
                pre.textContent = text;

                __tbJsonModal.show();
            } catch (e) {
                alert('Failed to open modal: ' + e.message);
            }
        }

        function openJsonModalFromBtn(btn, title) {
            if (!btn) return;
            const payload = btn.getAttribute('data-json') || '';
            openJsonModal(title, payload);
        }

        (function initJsonModalCopy() {
            const btn = document.getElementById('jsonModalCopyBtn');
            if (!btn) return;
            btn.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(__tbJsonModalLastText || '');
                    btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copied';
                    setTimeout(() => {
                        btn.innerHTML = '<i class="bi bi-clipboard me-1"></i>Copy';
                    }, 900);
                } catch (e) {
                    alert('Copy failed: ' + e.message);
                }
            });
        })();

        /* ======================================================
           Optional auto-reload (disabled while modal is open)
           Set window.TRADING_BOT_AUTO_RELOAD_SEC in page content.
           ====================================================== */
        (function initAutoReload() {
            const sec = window.TRADING_BOT_AUTO_RELOAD_SEC;
            if (!sec || typeof sec !== 'number' || sec < 5) return;

            setInterval(() => {
                if (__tbJsonModalOpen) return;
                // Don't reload if user is typing in a form
                const active = document.activeElement;
                if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.isContentEditable)) {
                    return;
                }
                location.reload();
            }, sec * 1000);
        })();

    </script>
</body>
</html>

<?php
/* RULES
- Layout wrapper for Trading Bot Admin UI
- Contains universal JSON modal (P6.11) for explainability
- No business logic here; view-only
- Any dynamic data must be escaped via htmlspecialchars()
*/
?>
