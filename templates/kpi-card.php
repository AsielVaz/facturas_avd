<?php
$kpiColor = $kpiColor ?? 'primary';
?>
<div class="col-xl-3 col-sm-6">
    <div class="card h-100">
        <div class="card-body">
            <div class="d-flex align-items-start justify-content-between">
                <div>
                    <p class="text-muted mb-2"><?= htmlspecialchars($kpiLabel) ?></p>
                    <h4 class="mb-1"><?= htmlspecialchars($kpiValue) ?></h4>
                    <small class="text-<?= htmlspecialchars($kpiTrendColor ?? 'muted') ?>"><?= htmlspecialchars($kpiTrend ?? '') ?></small>
                </div>
                <span class="erp-kpi-icon bg-<?= htmlspecialchars($kpiColor) ?>-subtle text-<?= htmlspecialchars($kpiColor) ?>"><i data-lucide="<?= htmlspecialchars($kpiIcon) ?>"></i></span>
            </div>
        </div>
    </div>
</div>
