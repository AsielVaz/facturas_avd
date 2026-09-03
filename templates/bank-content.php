<?php
$bankMovements = $bankMovements ?? [];
?>
<div class="row g-3 mb-4">
    <div class="col-xl-5">
        <div class="card erp-account-card h-100 border-0" style="background: <?= htmlspecialchars($bankGradient) ?>;">
            <div class="card-body position-relative d-flex flex-column justify-content-between">
                <div class="d-flex justify-content-between align-items-start"><div><p class="mb-1 opacity-75">Saldo disponible</p><h2 class="text-white mb-0"><?= htmlspecialchars($bankBalance) ?></h2></div><span class="rounded-circle bg-white bg-opacity-10 p-3"><i data-lucide="landmark"></i></span></div>
                <div class="d-flex justify-content-between align-items-end mt-4"><div><small class="d-block opacity-75 mb-1">Cuenta empresarial</small><span class="font-monospace fs-16"><?= htmlspecialchars($bankAccount) ?></span></div><div class="text-end"><small class="d-block opacity-75">Ultima conciliacion</small><span class="fw-medium">04 ago 2026</span></div></div>
            </div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="row g-3 h-100">
            <div class="col-sm-4"><div class="card h-100"><div class="card-body"><span class="erp-kpi-icon bg-success-subtle text-success mb-3"><i data-lucide="arrow-down-left"></i></span><p class="text-muted mb-1">Ingresos del mes</p><h5 class="mb-1"><?= htmlspecialchars($bankIncome) ?></h5><small class="text-success">+<?= htmlspecialchars($bankIncomeTrend) ?> vs. julio</small></div></div></div>
            <div class="col-sm-4"><div class="card h-100"><div class="card-body"><span class="erp-kpi-icon bg-danger-subtle text-danger mb-3"><i data-lucide="arrow-up-right"></i></span><p class="text-muted mb-1">Egresos del mes</p><h5 class="mb-1"><?= htmlspecialchars($bankExpense) ?></h5><small class="text-muted"><?= htmlspecialchars($bankExpenseCount) ?> movimientos</small></div></div></div>
            <div class="col-sm-4"><div class="card h-100"><div class="card-body"><span class="erp-kpi-icon bg-warning-subtle text-warning mb-3"><i data-lucide="circle-dollar-sign"></i></span><p class="text-muted mb-1">Por conciliar</p><h5 class="mb-1"><?= htmlspecialchars($bankPending) ?></h5><small class="text-warning"><?= htmlspecialchars($bankPendingCount) ?> operaciones</small></div></div></div>
        </div>
    </div>
</div>
<div class="row g-3">
    <div class="col-12">
        <div class="card">
            <div class="card-body border-bottom">
                <div class="d-flex flex-wrap align-items-center gap-2"><h5 class="card-title mb-0 me-auto">Movimientos recientes</h5><div class="search-bar"><span><i data-lucide="search"></i></span><input class="form-control form-control-sm" placeholder="Buscar movimiento..."></div><select class="form-select form-select-sm" style="width:auto"><option>Todos</option><option>Ingresos</option><option>Egresos</option></select><button class="btn btn-sm btn-soft-secondary"><i data-lucide="calendar-days" class="fs-15 me-1"></i>Este mes</button></div>
            </div>
            <div class="table-responsive"><table class="table table-hover text-nowrap mb-0 erp-table"><thead><tr><th class="ps-3">Fecha</th><th>Descripcion</th><th>Referencia</th><th>Categoria</th><th>Estado</th><th class="text-end pe-3">Importe</th></tr></thead><tbody>
            <?php foreach ($bankMovements as $movement): ?>
                <tr><td class="ps-3"><?= htmlspecialchars($movement['date']) ?><small class="d-block text-muted"><?= htmlspecialchars($movement['time']) ?></small></td><td><div class="d-flex align-items-center gap-2"><span class="erp-avatar bg-<?= $movement['type']==='in'?'success':'danger' ?>-subtle text-<?= $movement['type']==='in'?'success':'danger' ?>"><i data-lucide="<?= $movement['type']==='in'?'arrow-down-left':'arrow-up-right' ?>" class="fs-18"></i></span><div><span class="fw-medium"><?= htmlspecialchars($movement['description']) ?></span><small class="d-block text-muted"><?= htmlspecialchars($movement['detail']) ?></small></div></div></td><td class="font-monospace fs-13"><?= htmlspecialchars($movement['reference']) ?></td><td><?= htmlspecialchars($movement['category']) ?></td><td><span class="badge badge-soft-<?= htmlspecialchars($movement['statusColor']) ?>"><?= htmlspecialchars($movement['status']) ?></span></td><td class="text-end pe-3 fw-semibold text-<?= $movement['type']==='in'?'success':'body' ?>"><?= $movement['type']==='in'?'+':'-' ?><?= htmlspecialchars($movement['amount']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <div class="card-body border-top py-3 text-center"><a href="#" class="fw-medium">Ver todos los movimientos <i data-lucide="arrow-right" class="fs-15 ms-1"></i></a></div>
        </div>
    </div>
</div>
