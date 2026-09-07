<?php
$invoiceRows = $invoiceRows ?? [];
$invoiceType = $invoiceType ?? 'pending';
$invoicePagination = $invoicePagination ?? ['total' => count($invoiceRows), 'pagina' => 1, 'paginas' => 1, 'por_pagina' => 10];
$invoiceSearch = (string) ($_GET['buscar'] ?? '');
$invoiceDate = (string) ($_GET['fecha'] ?? '');
$buildPageUrl = static function (int $page): string {
    $parameters = $_GET;
    $parameters['pagina'] = $page;
    return '?' . http_build_query($parameters);
};
?>
<div class="card">
    <div class="card-body border-bottom">
        <form method="get" class="row g-2 align-items-center">
            <div class="col-lg-6"><div class="search-bar"><span><i data-lucide="search"></i></span><input name="buscar" value="<?= htmlspecialchars($invoiceSearch) ?>" type="search" class="form-control" placeholder="Buscar folio, cliente o RFC..."></div></div>
            <div class="col-sm-5 col-lg-3"><input name="fecha" value="<?= htmlspecialchars($invoiceDate) ?>" type="date" class="form-control" aria-label="Filtrar por fecha"></div>
            <div class="col-sm-7 col-lg-3 text-sm-end"><button class="btn btn-primary" type="submit"><i data-lucide="search" class="fs-16 me-1"></i>Buscar</button><?php if ($invoiceSearch !== '' || $invoiceDate !== ''): ?><a href="?" class="btn btn-soft-secondary ms-1">Limpiar</a><?php endif; ?></div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover text-nowrap mb-0 erp-table">
            <thead><tr><th class="ps-3">Folio</th><th>Cliente</th><th>Fecha</th><th><?= $invoiceType === 'stamped' ? 'UUID / Timbrado' : 'Vencimiento' ?></th><th>Total</th><th>Estado</th><th class="text-end pe-3">Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($invoiceRows as $row): ?>
                <tr>
                    <td class="ps-3"><a href="#" class="fw-semibold"><?= htmlspecialchars($row['folio']) ?></a><small class="d-block text-muted"><?= htmlspecialchars($row['serie']) ?></small></td>
                    <td><div class="d-flex align-items-center gap-2"><span class="erp-avatar bg-primary-subtle text-primary"><?= htmlspecialchars($row['initials']) ?></span><div><span class="fw-medium"><?= htmlspecialchars($row['client']) ?></span><small class="d-block text-muted"><?= htmlspecialchars($row['rfc']) ?></small></div></div></td>
                    <td><?= htmlspecialchars($row['date']) ?></td>
                    <td><?php if ($invoiceType === 'stamped'): ?><span class="font-monospace fs-12"><?= htmlspecialchars($row['uuid']) ?></span><small class="d-block text-muted"><?= htmlspecialchars($row['due']) ?></small><?php else: ?><?= htmlspecialchars($row['due']) ?><?php endif; ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($row['total']) ?></td>
                    <td><span class="badge badge-soft-<?= htmlspecialchars($row['color']) ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                    <td class="text-end pe-3">
                        <button class="btn btn-sm btn-soft-secondary" title="Ver detalle"><i data-lucide="eye" class="fs-16"></i></button>
                        <?php if ($invoiceType === 'stamped'): ?><a class="btn btn-sm btn-soft-primary" href="api/facturas.php?accion=descargar_xml&amp;tipo=timbrado&amp;id=<?= (int) $row['id'] ?>" title="Descargar XML"><i data-lucide="file-code-2" class="fs-16"></i></a><a class="btn btn-sm btn-soft-danger" href="api/factura-pdf.php?id=<?= (int) $row['id'] ?>" title="Descargar PDF"><i data-lucide="file-down" class="fs-16"></i></a><?php else: ?><a class="btn btn-sm btn-soft-primary" href="facturar.php?id=<?= (int) $row['id'] ?>" title="Editar"><i data-lucide="square-pen" class="fs-16"></i></a><a class="btn btn-sm btn-soft-secondary" href="api/factura-pdf.php?id=<?= (int) $row['id'] ?>" title="Descargar PDF de prueba"><i data-lucide="file-down" class="fs-16"></i></a><!-- Timbrado desactivado temporalmente para pruebas locales. <button class="btn btn-sm btn-soft-success js-stamp-invoice" type="button" data-invoice-id="<?= (int) $row['id'] ?>" title="Timbrar"><i data-lucide="badge-check" class="fs-16"></i></button> --><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($invoiceRows === []): ?><tr><td colspan="7" class="text-center py-5"><i data-lucide="file-search" class="text-muted mb-2" style="width:34px;height:34px"></i><p class="text-muted mb-0">No se encontraron facturas con estos filtros.</p></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body border-top py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="text-muted fs-13">Mostrando <?= count($invoiceRows) ?> de <?= number_format((int) $invoicePagination['total']) ?> facturas</span>
        <nav><ul class="pagination pagination-sm mb-0"><li class="page-item<?= $invoicePagination['pagina'] <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($buildPageUrl(max(1, $invoicePagination['pagina'] - 1))) ?>">Anterior</a></li><li class="page-item active"><span class="page-link"><?= (int) $invoicePagination['pagina'] ?> / <?= (int) $invoicePagination['paginas'] ?></span></li><li class="page-item<?= $invoicePagination['pagina'] >= $invoicePagination['paginas'] ? ' disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($buildPageUrl(min($invoicePagination['paginas'], $invoicePagination['pagina'] + 1))) ?>">Siguiente</a></li></ul></nav>
    </div>
</div>
