<?php

require_once __DIR__ . '/api/InventarioAdministrador.php';

$pageTitle = 'Inventario';
$pageEyebrow = 'Inventario / Existencias reales';
$activeModule = 'inventario';
$activePage = 'inventario';
$pageAction = '<button class="btn btn-soft-secondary"><i data-lucide="download" class="fs-17 me-1"></i>Exportar</button>';
$inventoryError = '';

try {
    $inventoryData = (new InventarioAdministrador(Conexion::obtener()))->listar(
        SesionEmpresa::empresaActual(),
        SesionEmpresa::claveActual(),
        max(1, (int) ($_GET['pagina'] ?? 1)),
        10,
        trim((string) ($_GET['buscar'] ?? ''))
    );
    $products = $inventoryData['registros'];
    $inventorySummary = $inventoryData['resumen'];
} catch (Throwable $error) {
    $products = [];
    $inventoryData = ['total' => 0, 'pagina' => 1, 'paginas' => 1, 'por_pagina' => 10];
    $inventorySummary = ['productos' => 0, 'unidades_disponibles' => 0, 'valor_inventario' => 0, 'productos_con_salidas' => 0, 'productos_sin_existencia' => 0];
    $inventoryError = 'No fue posible cargar el inventario. Verifica la conexion con la base de datos.';
}

$inventorySearch = trim((string) ($_GET['buscar'] ?? ''));
$buildInventoryPage = static function (int $page): string {
    $parameters = $_GET;
    $parameters['pagina'] = $page;
    return '?' . http_build_query($parameters);
};

require 'templates/page-start.php';
?>

<?php if ($inventoryError !== ''): ?>
    <div class="alert alert-danger"><i data-lucide="database-zap" class="fs-18 me-2"></i><?= htmlspecialchars($inventoryError) ?></div>
<?php endif; ?>

<div class="alert alert-primary d-flex flex-wrap align-items-center justify-content-between gap-2">
    <div><i data-lucide="building-2" class="fs-18 me-2"></i><strong>Inventario empresarial</strong> calculado desde facturas y notas de salida.</div>
    <div class="d-flex gap-2"><span class="badge bg-primary">Empresa <?= SesionEmpresa::empresaActual() ?></span><span class="badge bg-primary">Clave <?= SesionEmpresa::claveActual() ?></span><span class="badge bg-primary">Desde 01/02/2026</span></div>
</div>

<div class="row g-3 mb-4">
<?php
$cards = [
    ['Productos con existencia', number_format($inventorySummary['productos']), 'Conceptos SAT encontrados', 'boxes', 'primary', 'Inventario consolidado', 'primary'],
    ['Unidades disponibles', number_format($inventorySummary['unidades_disponibles'], 2), 'Compras - ventas - notas', 'package-check', 'success', 'Existencia calculada', 'success'],
    ['Valor del inventario', '$' . number_format($inventorySummary['valor_inventario'], 2), 'Valor comprado - vendido', 'circle-dollar-sign', 'info', 'Importe acumulado', 'info'],
    ['Con salidas por nota', number_format($inventorySummary['productos_con_salidas']), 'Productos con movimientos', 'package-minus', 'warning', $inventorySummary['productos_sin_existencia'] . ' sin existencia', 'warning'],
];
foreach ($cards as $card) {
    [$kpiLabel, $kpiValue, $kpiTrend, $kpiIcon, $kpiColor, $extra, $kpiTrendColor] = $card;
    $kpiTrend .= ' · ' . $extra;
    require 'templates/kpi-card.php';
}
?>
</div>

<div class="card">
    <div class="card-body border-bottom">
        <form method="get" class="row g-2 align-items-center">
            <div class="col-lg-7">
                <div class="search-bar"><span><i data-lucide="search"></i></span><input name="buscar" value="<?= htmlspecialchars($inventorySearch) ?>" class="form-control" placeholder="Buscar por concepto o clave SAT..."></div>
            </div>
            <div class="col-lg-5 text-lg-end">
                <button class="btn btn-primary" type="submit"><i data-lucide="search" class="fs-16 me-1"></i>Buscar</button>
                <?php if ($inventorySearch !== ''): ?><a href="?" class="btn btn-soft-secondary ms-1">Limpiar</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover text-nowrap mb-0 erp-table">
            <thead><tr><th class="ps-3">Concepto</th><th class="text-end">Compradas</th><th class="text-end">Vendidas</th><th class="text-end">Inventario real</th><th class="text-end">Salidas por nota</th><th class="text-end">Disponible</th><th class="text-end">Valor inventario</th><th>Ultima adquisicion</th><th class="text-end pe-3">Factura</th></tr></thead>
            <tbody>
            <?php foreach ($products as $product): ?>
                <?php
                $available = (float) $product['existencia_disponible'];
                $statusColor = $available <= 0 ? 'danger' : ($available < 10 ? 'warning' : 'success');
                ?>
                <tr>
                    <td class="ps-3" style="min-width:360px;max-width:520px;white-space:normal">
                        <div class="d-flex align-items-start gap-2">
                            <span class="erp-avatar bg-primary-subtle text-primary flex-shrink-0"><i data-lucide="package" class="fs-18"></i></span>
                            <div><span class="fw-semibold text-body"><?= htmlspecialchars((string) $product['concepto']) ?></span><small class="d-block text-muted font-monospace mt-1">SAT <?= htmlspecialchars((string) $product['clave_sat']) ?></small></div>
                        </div>
                    </td>
                    <td class="text-end text-success fw-medium"><?= number_format((float) $product['unidades_compradas'], 2) ?></td>
                    <td class="text-end text-danger fw-medium"><?= number_format((float) $product['unidades_vendidas'], 2) ?></td>
                    <td class="text-end fw-semibold"><?= number_format((float) $product['inventario_real'], 2) ?></td>
                    <td class="text-end"><?= number_format((float) $product['salidas_por_nota'], 2) ?></td>
                    <td class="text-end"><span class="badge badge-soft-<?= $statusColor ?> fs-13"><?= number_format($available, 2) ?></span></td>
                    <td class="text-end fw-semibold">$<?= number_format((float) $product['valor_inventario'], 2) ?></td>
                    <td><?= !empty($product['fecha_ultima_adquisicion']) ? htmlspecialchars(date('d/m/Y', strtotime((string) $product['fecha_ultima_adquisicion']))) : 'Sin adquisicion' ?></td>
                    <td class="text-end pe-3"><?php if (!empty($product['ultimo_id_factura'])): ?><span class="badge bg-light text-dark">#<?= htmlspecialchars((string) $product['ultimo_id_factura']) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($products === []): ?>
                <tr><td colspan="9" class="text-center py-5"><i data-lucide="package-search" class="text-muted mb-2" style="width:34px;height:34px"></i><p class="text-muted mb-0">No se encontraron productos con inventario.</p></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card-body border-top py-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <span class="text-muted fs-13">Mostrando <?= count($products) ?> de <?= number_format((int) $inventoryData['total']) ?> productos</span>
        <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item<?= $inventoryData['pagina'] <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($buildInventoryPage(max(1, $inventoryData['pagina'] - 1))) ?>">Anterior</a></li>
            <li class="page-item active"><span class="page-link"><?= (int) $inventoryData['pagina'] ?> / <?= (int) $inventoryData['paginas'] ?></span></li>
            <li class="page-item<?= $inventoryData['pagina'] >= $inventoryData['paginas'] ? ' disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($buildInventoryPage(min($inventoryData['paginas'], $inventoryData['pagina'] + 1))) ?>">Siguiente</a></li>
        </ul></nav>
    </div>
</div>

<?php require 'templates/scripts.php'; ?>
