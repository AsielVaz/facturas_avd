<?php

require_once __DIR__ . '/api/NotaInventarioAdministrador.php';

$pageTitle = 'Notas de salida';
$pageEyebrow = 'Inventario / Salidas registradas';
$activeModule = 'inventario';
$activePage = 'notas-salida';
$pageAction = '<button class="btn btn-soft-secondary"><i data-lucide="download" class="fs-17 me-1"></i>Exportar</button>';
$notesError = '';

try {
    $notesData = (new NotaInventarioAdministrador(Conexion::obtener()))->listar(
        SesionEmpresa::empresaActual(),
        max(1, (int) ($_GET['pagina'] ?? 1)),
        10,
        trim((string) ($_GET['buscar'] ?? ''))
    );
    $notes = $notesData['registros'];
    $notesSummary = $notesData['resumen'];
} catch (Throwable $error) {
    $notes = [];
    $notesData = ['total' => 0, 'pagina' => 1, 'paginas' => 1, 'por_pagina' => 10];
    $notesSummary = ['notas' => 0, 'unidades' => 0, 'responsables' => 0, 'notas_mes' => 0];
    $notesError = 'No fue posible cargar las notas de inventario. Verifica la conexion con la base de datos.';
}

$notesSearch = trim((string) ($_GET['buscar'] ?? ''));
$buildNotesPage = static function (int $page): string {
    $parameters = $_GET;
    $parameters['pagina'] = $page;
    return '?' . http_build_query($parameters);
};

require 'templates/page-start.php';
?>

<?php if ($notesError !== ''): ?>
    <div class="alert alert-danger"><i data-lucide="database-zap" class="fs-18 me-2"></i><?= htmlspecialchars($notesError) ?></div>
<?php endif; ?>

<div class="alert alert-primary d-flex flex-wrap align-items-center justify-content-between gap-2">
    <div><i data-lucide="package-minus" class="fs-18 me-2"></i><strong>Salidas de inventario</strong> con responsable y detalle de productos.</div>
    <span class="badge bg-primary">Empresa <?= SesionEmpresa::empresaActual() ?></span>
</div>

<div class="row g-3 mb-4">
<?php
$cards = [
    ['Notas registradas', number_format($notesSummary['notas']), 'Movimientos de salida', 'notebook-tabs', 'primary', 'Empresa ' . SesionEmpresa::empresaActual(), 'primary'],
    ['Unidades retiradas', number_format($notesSummary['unidades'], 2), 'Suma de todas las partidas', 'package-minus', 'danger', 'Salida acumulada', 'danger'],
    ['Responsables', number_format($notesSummary['responsables']), 'Personas que registraron salidas', 'users', 'info', 'Responsables distintos', 'info'],
    ['Mes mas reciente', number_format($notesSummary['notas_mes']), 'Notas generadas', 'calendar-days', 'success', 'Actividad mensual', 'success'],
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
                <div class="search-bar"><span><i data-lucide="search"></i></span><input name="buscar" value="<?= htmlspecialchars($notesSearch) ?>" class="form-control" placeholder="Buscar nota, factura, responsable, almacen o producto..."></div>
            </div>
            <div class="col-lg-5 text-lg-end">
                <button class="btn btn-primary" type="submit"><i data-lucide="search" class="fs-16 me-1"></i>Buscar</button>
                <?php if ($notesSearch !== ''): ?><a href="?" class="btn btn-soft-secondary ms-1">Limpiar</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover text-nowrap mb-0 erp-table">
            <thead><tr><th class="ps-3">Nota</th><th>Fecha de salida</th><th>Responsable</th><th>Almacen</th><th>Factura</th><th class="text-end">Partidas</th><th class="text-end">Unidades retiradas</th><th class="text-end pe-3">Detalle</th></tr></thead>
            <tbody>
            <?php foreach ($notes as $note): ?>
                <?php
                $responsible = trim((string) ($note['responsable'] ?? '')) ?: 'Sin responsable';
                $parts = preg_split('/\s+/u', $responsible, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $initials = '';
                foreach (array_slice($parts, 0, 2) as $part) {
                    $initials .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
                }
                $collapseId = 'noteDetails' . (int) $note['id'];
                ?>
                <tr>
                    <td class="ps-3"><span class="fw-semibold text-primary">NS-<?= htmlspecialchars((string) $note['id']) ?></span></td>
                    <td><?= !empty($note['fecha_salida']) ? htmlspecialchars(date('d/m/Y · H:i', strtotime((string) $note['fecha_salida']))) : 'Sin fecha' ?></td>
                    <td><div class="d-flex align-items-center gap-2"><span class="erp-avatar bg-primary-subtle text-primary"><?= htmlspecialchars($initials ?: 'SR') ?></span><span class="fw-medium"><?= htmlspecialchars($responsible) ?></span></div></td>
                    <td><span class="badge bg-light text-dark"><i data-lucide="warehouse" class="fs-14 me-1"></i><?= htmlspecialchars(trim((string) ($note['almacen'] ?? '')) ?: 'Sin almacen') ?></span></td>
                    <td><?php if (!empty($note['id_factura'])): ?><span class="font-monospace">#<?= htmlspecialchars((string) $note['id_factura']) ?></span><?php else: ?><span class="text-muted">Sin factura</span><?php endif; ?></td>
                    <td class="text-end"><?= number_format((int) $note['partidas']) ?></td>
                    <td class="text-end fw-semibold text-danger"><?= number_format((float) $note['unidades'], 2) ?></td>
                    <td class="text-end pe-3"><button class="btn btn-sm btn-soft-primary" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>" aria-expanded="false" aria-controls="<?= $collapseId ?>"><i data-lucide="list-tree" class="fs-16 me-1"></i>Ver productos</button></td>
                </tr>
                <tr class="collapse" id="<?= $collapseId ?>">
                    <td colspan="8" class="p-0">
                        <div class="bg-light p-3 p-md-4">
                            <div class="d-flex align-items-center justify-content-between mb-3"><h6 class="mb-0">Productos retirados del inventario</h6><span class="text-muted fs-13">Nota NS-<?= htmlspecialchars((string) $note['id']) ?></span></div>
                            <div class="table-responsive border rounded bg-body"><table class="table table-sm mb-0"><thead><tr><th class="ps-3">Clave SAT</th><th>Producto</th><th class="text-end pe-3">Cantidad retirada</th></tr></thead><tbody>
                            <?php foreach ($note['detalles'] as $detail): ?>
                                <tr><td class="ps-3 font-monospace text-primary"><?= htmlspecialchars((string) ($detail['clave_sat'] ?? '')) ?></td><td style="min-width:360px;white-space:normal"><?= htmlspecialchars(trim((string) ($detail['detalle'] ?? '')) ?: 'Producto sin descripcion') ?></td><td class="text-end pe-3 fw-semibold text-danger"><?= number_format((float) $detail['cantidad'], 2) ?></td></tr>
                            <?php endforeach; ?>
                            <?php if ($note['detalles'] === []): ?><tr><td colspan="3" class="text-center text-muted py-3">Esta nota no contiene partidas.</td></tr><?php endif; ?>
                            </tbody></table></div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($notes === []): ?>
                <tr><td colspan="8" class="text-center py-5"><i data-lucide="notebook-tabs" class="text-muted mb-2" style="width:34px;height:34px"></i><p class="text-muted mb-0">No se encontraron notas de inventario.</p></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card-body border-top py-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <span class="text-muted fs-13">Mostrando <?= count($notes) ?> de <?= number_format((int) $notesData['total']) ?> notas</span>
        <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item<?= $notesData['pagina'] <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($buildNotesPage(max(1, $notesData['pagina'] - 1))) ?>">Anterior</a></li>
            <li class="page-item active"><span class="page-link"><?= (int) $notesData['pagina'] ?> / <?= (int) $notesData['paginas'] ?></span></li>
            <li class="page-item<?= $notesData['pagina'] >= $notesData['paginas'] ? ' disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($buildNotesPage(min($notesData['paginas'], $notesData['pagina'] + 1))) ?>">Siguiente</a></li>
        </ul></nav>
    </div>
</div>

<?php require 'templates/scripts.php'; ?>
