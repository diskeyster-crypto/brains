<?php
/**
 * Brain Module - Timeline Panel (v2.2 Блок 5)
 * 
 * Shows events from events.ndjson:
 * - RUN_START, RUN_END
 * - STRATEGY_UPDATED
 * - AUTO_SEARCH
 * - PROCESS_START, PROCESS_END
 * - ERROR
 */

use Core\System\System;

// Variables: $events, $flash
$brainUrl = rtrim(System::web('admin/brain'), '/');

$eventColors = [
    'RUN_START' => '#10b981',
    'RUN_END' => '#3b82f6',
    'STRATEGY_UPDATED' => '#f59e0b',
    'AUTO_SEARCH' => '#8b5cf6',
    'PROCESS_START' => '#64748b',
    'PROCESS_END' => '#64748b',
    'ERROR' => '#ef4444',
];

$eventIcons = [
    'RUN_START' => 'play-fill',
    'RUN_END' => 'stop-fill',
    'STRATEGY_UPDATED' => 'pencil',
    'AUTO_SEARCH' => 'search',
    'PROCESS_START' => 'cpu',
    'PROCESS_END' => 'cpu-fill',
    'ERROR' => 'exclamation-triangle',
];
?>

<!-- Flash Message -->
<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom: 20px;">
    <?= htmlspecialchars($flash['message']) ?>
</div>
<?php endif; ?>

<!-- Navigation Tabs -->
<ul class="nav nav-tabs" style="margin-bottom: 20px;">
    <li class="nav-item">
        <a class="nav-link" href="<?= $brainUrl ?>">
            <i class="bi bi-house"></i> Dashboard
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="<?= $brainUrl ?>/runtime">
            <i class="bi bi-cpu"></i> Runtime
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link active" href="<?= $brainUrl ?>/timeline">
            <i class="bi bi-clock-history"></i> Timeline
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="<?= $brainUrl ?>/runs">
            <i class="bi bi-list-check"></i> Run History
        </a>
    </li>
</ul>

<!-- Filter Buttons -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 12px;">
        <div class="d-flex gap-2 flex-wrap" style="gap: 8px;">
            <button type="button" class="btn btn-outline-secondary btn-sm event-filter active" data-type="">
                All Events
            </button>
            <?php foreach ($eventColors as $type => $color): ?>
                <button type="button" 
                        class="btn btn-outline-secondary btn-sm event-filter" 
                        data-type="<?= htmlspecialchars($type) ?>"
                        style="border-color: <?= $color ?>; color: <?= $color ?>;">
                    <i class="bi bi-<?= $eventIcons[$type] ?? 'circle' ?>"></i>
                    <?= htmlspecialchars($type) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Timeline -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 style="margin: 0;"><i class="bi bi-clock-history"></i> Event Timeline</h5>
        <span class="badge bg-secondary"><?= count($events) ?> events</span>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($events)): ?>
            <div style="padding: 40px; text-align: center; color: #64748b;">
                <i class="bi bi-inbox" style="font-size: 3rem; opacity: 0.5;"></i>
                <p style="margin-top: 16px;">No events recorded yet.</p>
            </div>
        <?php else: ?>
            <div class="timeline-container" style="max-height: 700px; overflow-y: auto; padding: 20px;">
                <?php foreach ($events as $index => $event): ?>
                    <?php 
                    $type = $event['type'] ?? 'UNKNOWN';
                    $color = $eventColors[$type] ?? '#64748b';
                    $icon = $eventIcons[$type] ?? 'circle';
                    $ts = $event['ts'] ?? '';
                    $runId = $event['run_id'] ?? null;
                    $strategyId = $event['strategy_id'] ?? null;
                    $meta = $event['meta'] ?? [];
                    ?>
                    <div class="timeline-item" data-event-type="<?= htmlspecialchars($type) ?>" 
                         style="display: flex; margin-bottom: 20px; position: relative;">
                        <!-- Timeline line -->
                        <?php if ($index < count($events) - 1): ?>
                            <div style="position: absolute; left: 15px; top: 32px; bottom: -20px; width: 2px; background: var(--border-color);"></div>
                        <?php endif; ?>
                        
                        <!-- Icon -->
                        <div style="width: 32px; height: 32px; border-radius: 50%; background: <?= $color ?>; 
                                    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
                                    position: relative; z-index: 1;">
                            <i class="bi bi-<?= $icon ?>" style="color: white; font-size: 0.85rem;"></i>
                        </div>
                        
                        <!-- Content -->
                        <div style="margin-left: 16px; flex: 1; background: rgba(59, 130, 246, 0.05); 
                                    border-radius: 8px; padding: 12px; border: 1px solid var(--border-color);">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                <span class="badge" style="background: <?= $color ?>;"><?= htmlspecialchars($type) ?></span>
                                <code style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($ts) ?></code>
                            </div>
                            
                            <?php if ($runId): ?>
                                <div style="margin-bottom: 4px;">
                                    <small style="color: #94a3b8;">Run ID:</small>
                                    <code style="font-size: 0.8rem;"><?= htmlspecialchars($runId) ?></code>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($strategyId): ?>
                                <div style="margin-bottom: 4px;">
                                    <small style="color: #94a3b8;">Strategy:</small>
                                    <code style="font-size: 0.8rem;"><?= htmlspecialchars($strategyId) ?></code>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($meta)): ?>
                                <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--border-color);">
                                    <small style="color: #64748b;">Meta:</small>
                                    <pre style="margin: 4px 0 0 0; font-size: 0.75rem; background: rgba(0,0,0,0.2); 
                                                padding: 8px; border-radius: 4px; overflow-x: auto; max-height: 100px;">
<?= htmlspecialchars(json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Event filtering
document.querySelectorAll('.event-filter').forEach(btn => {
    btn.addEventListener('click', function() {
        const type = this.dataset.type;
        
        // Update button states
        document.querySelectorAll('.event-filter').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        
        // Filter timeline items
        document.querySelectorAll('.timeline-item').forEach(item => {
            if (type === '' || item.dataset.eventType === type) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    });
});
</script>

<style>
.event-filter.active {
    background: var(--primary) !important;
    color: white !important;
    border-color: var(--primary) !important;
}
</style>
