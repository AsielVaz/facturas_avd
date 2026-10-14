<?php

declare(strict_types=1);

require_once __DIR__ . '/api/Autenticacion.php';
require_once __DIR__ . '/api/ComplementoPagoConsulta.php';

Autenticacion::exigirPagina();
$empresa = SesionEmpresa::empresaActual();
$consultaComplementos = new ComplementoPagoConsulta(Conexion::obtener(), $empresa);
$esc = static fn(mixed $valor): string => htmlspecialchars(is_scalar($valor) ? (string) $valor : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$clienteLegible = static function (mixed $valor): string {
    $texto = is_string($valor) ? $valor : '';
    if (!str_contains($texto, 'Ã')) return $texto;

    // Algunos nombres llegan con UTF-8 mal interpretado (p. ej. COMPAÃ‘IA).
    // Reparar solo cada secuencia afectada evita alterar el resto del nombre o la base de datos.
    return preg_replace_callback('/Ã./u', static function (array $coincidencia): string {
        $reparado = @iconv('UTF-8', 'Windows-1252', $coincidencia[0]);
        return $reparado !== false && preg_match('//u', $reparado) ? $reparado : $coincidencia[0];
    }, $texto) ?? $texto;
};
$fechaValida = static function (mixed $valor): string {
    if (!is_string($valor) || $valor === '') return '';
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return $fecha && $fecha->format('Y-m-d') === $valor ? $valor : '';
};
$buscar = is_string($_GET['buscar'] ?? null) ? trim($_GET['buscar']) : '';
$buscar = function_exists('mb_substr') ? mb_substr($buscar, 0, 100, 'UTF-8') : substr($buscar, 0, 100);
$desde = $fechaValida($_GET['desde'] ?? null);
$hasta = $fechaValida($_GET['hasta'] ?? null);
$pagina = max(1, filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT) ?: 1);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$error = '';
$detalle = null;
$relaciones = [];
$listado = ['filas' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1, 'por_pagina' => 25];

try {
    if ($id > 0) {
        $detalle = $consultaComplementos->obtener($id);
        if ($detalle === null) {
            http_response_code(404);
            $error = 'No se encontró el complemento en la empresa activa.';
        } else {
            $relaciones = $consultaComplementos->relaciones($id);
        }
    } else {
        $listado = $consultaComplementos->listar($buscar, $desde, $hasta, $pagina);
    }
} catch (Throwable $excepcion) {
    error_log('Error al consultar complementos de pago: ' . $excepcion->getMessage());
    $error = 'No fue posible consultar los complementos de pago.';
}

$formatoFecha = static function (mixed $valor): string {
    if (!is_string($valor) || trim($valor) === '' || str_starts_with($valor, '0000-00-00')) return 'Sin fecha';
    $timestamp = strtotime($valor);
    return $timestamp === false ? 'Sin fecha' : date(strlen($valor) <= 10 ? 'd/m/Y' : 'd/m/Y H:i', $timestamp);
};
$moneda = static fn(mixed $valor): string => $valor === null ? '—' : '$' . number_format((float) $valor, 2, '.', ',');
$folio = static function (mixed $serie, mixed $numero): string {
    $serie = trim((string) ($serie ?? ''));
    $numero = trim((string) ($numero ?? ''));
    return ($serie === '' && $numero === '') ? '—' : ($serie !== '' ? $serie . '-' : '') . $numero;
};
$regresar = 'complementos-pago.php?' . http_build_query(['buscar' => $buscar, 'desde' => $desde, 'hasta' => $hasta, 'pagina' => $pagina]);
$pageTitle = $detalle ? 'Detalle del complemento' : 'Complementos de pago';
$pageEyebrow = 'Facturas / Complementos de pago';
$pageAction = $detalle
    ? '<a href="' . $esc($regresar) . '" class="btn btn-soft-secondary">Volver al listado</a>'
    : '<a href="nuevo-complemento-pago.php" class="btn btn-primary"><i data-lucide="plus" class="fs-18 me-1"></i>Nuevo complemento</a>';
require __DIR__ . '/templates/page-start.php';
?>

<?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= $esc($error) ?></div><?php endif; ?>

<?php if ($detalle): ?>
<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between gap-2">
        <div><h5 class="mb-1">Complemento <?= $esc($folio($detalle['serie_complemento'], $detalle['folio_complemento'])) ?></h5><span class="text-muted">Complemento #<?= (int) $detalle['id'] ?></span></div>
        <?php if (!empty($detalle['uuid'])): ?><div class="d-flex gap-2 align-self-start">
            <button class="btn btn-sm btn-soft-secondary js-view-complement" type="button" data-pdf-url="api/complemento-pdf.php?id=<?= (int) $detalle['id'] ?>&amp;vista=1" data-folio="<?= $esc($folio($detalle['serie_complemento'], $detalle['folio_complemento'])) ?>" title="Ver PDF" aria-label="Ver PDF del complemento"><i data-lucide="eye" class="fs-16"></i></button>
            <a class="btn btn-sm btn-soft-primary" href="api/complemento-documentos.php?id=<?= (int) $detalle['id'] ?>" title="Descargar XML y PDF" aria-label="Descargar XML y PDF del complemento"><i data-lucide="folder-down" class="fs-16"></i></a>
        </div><?php else: ?><span class="badge bg-warning-subtle text-warning">Pendiente · sin timbrar</span><?php endif; ?>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-md-6"><div class="border rounded p-3 h-100"><h6>Comprobante</h6>
                <dl class="row mb-0"><dt class="col-sm-5">Cliente</dt><dd class="col-sm-7"><?= $esc($clienteLegible($detalle['cliente'])) ?></dd>
                <dt class="col-sm-5">Folio</dt><dd class="col-sm-7"><?= $esc($folio($detalle['serie_complemento'], $detalle['folio_complemento'])) ?></dd>
                <dt class="col-sm-5">UUID</dt><dd class="col-sm-7 text-break"><?= $esc(trim((string) ($detalle['uuid'] ?? '')) ?: 'Sin timbrar') ?></dd>
                <dt class="col-sm-5">Estado registrado</dt><dd class="col-sm-7"><?= $esc($detalle['status'] ?: 'Sin estado') ?></dd>
                <dt class="col-sm-5">Fecha del comprobante</dt><dd class="col-sm-7"><?= $esc($formatoFecha($detalle['fecha_complemento'])) ?></dd>
                <dt class="col-sm-5">Fecha de timbrado</dt><dd class="col-sm-7"><?= $esc($formatoFecha($detalle['fecha_timbrado'])) ?></dd></dl>
            </div></div>
            <div class="col-md-6"><div class="border rounded p-3 h-100"><h6>Pago</h6>
                <dl class="row mb-0"><dt class="col-sm-5">Fecha de pago</dt><dd class="col-sm-7"><?= $esc($formatoFecha($detalle['fecha_pago'])) ?></dd>
                <dt class="col-sm-5">Monto total</dt><dd class="col-sm-7 fw-semibold"><?= $esc($moneda($detalle['total_factura'])) ?></dd>
                <dt class="col-sm-5">Facturas relacionadas</dt><dd class="col-sm-7"><?= count($relaciones) ?></dd></dl>
            </div></div>
        </div>
    </div>
</div>
<div class="card">
    <div class="card-header"><h5 class="mb-0">Facturas relacionadas</h5></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Factura</th><th>UUID relacionado</th><th>Pago</th><th>Fecha de pago</th><th>Monto</th><th>Saldo anterior</th><th>Saldo restante</th></tr></thead>
            <tbody>
            <?php foreach ($relaciones as $relacion): ?>
            <tr>
                <td><?= $esc($folio($relacion['serie_factura'], $relacion['folio_factura'])) ?><?php if ((int) $relacion['factura_disponible'] === 0): ?><div class="text-warning small">Factura no disponible en esta empresa</div><?php endif; ?></td>
                <td class="text-break"><?= $esc($relacion['uuid_rel'] ?: '—') ?></td>
                <td><?= $esc($relacion['num_pago'] ?: '—') ?></td>
                <td><?= $esc($formatoFecha($relacion['fecha_pago'])) ?></td>
                <td><?= $esc($moneda($relacion['monto'])) ?></td>
                <td><?= $esc($moneda($relacion['saldo'])) ?></td>
                <td><?= $esc($moneda($relacion['restante'])) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ($relaciones === []): ?><tr><td colspan="7" class="text-center text-muted py-4">Este complemento no tiene facturas relacionadas.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body border-bottom">
        <form class="row g-2 align-items-end" method="get" action="complementos-pago.php">
            <div class="col-lg-5"><label class="form-label" for="buscar">Buscar complemento, factura, UUID o cliente</label><input class="form-control" id="buscar" type="search" name="buscar" maxlength="100" value="<?= $esc($buscar) ?>" placeholder="Folio, UUID o cliente"></div>
            <div class="col-sm-4 col-lg-2"><label class="form-label" for="desde">Pago desde</label><input class="form-control" id="desde" type="date" name="desde" value="<?= $esc($desde) ?>"></div>
            <div class="col-sm-4 col-lg-2"><label class="form-label" for="hasta">Pago hasta</label><input class="form-control" id="hasta" type="date" name="hasta" value="<?= $esc($hasta) ?>"></div>
            <div class="col-sm-4 col-lg-3 d-flex gap-2"><button class="btn btn-primary" type="submit">Buscar</button><a class="btn btn-soft-secondary" href="complementos-pago.php">Limpiar</a></div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover erp-table mb-0">
            <thead><tr><th>Complemento</th><th>Facturas relacionadas</th><th>Cliente</th><th>Fecha de pago</th><th>Monto</th><th>Estado</th><th class="text-end pe-3">Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($listado['filas'] as $fila): ?>
            <tr>
                <td><a href="?<?= $esc(http_build_query(['id' => $fila['id'], 'buscar' => $buscar, 'desde' => $desde, 'hasta' => $hasta, 'pagina' => $listado['pagina']])) ?>" class="fw-semibold"><?= $esc($folio($fila['serie_complemento'], $fila['folio_complemento'])) ?></a><div class="text-muted small text-truncate" style="max-width:170px" title="<?= $esc($fila['uuid']) ?>"><?= $esc($fila['uuid'] ?: 'Sin UUID') ?></div></td>
                <td><?php if ((int) $fila['facturas_count'] > 0): ?><?= $esc($folio($fila['serie_factura'], $fila['folio_factura'])) ?><?php if ((int) $fila['facturas_count'] > 1): ?><div class="text-muted small">y <?= (int) $fila['facturas_count'] - 1 ?> más</div><?php endif; ?><?php if ((int) $fila['factura_disponible'] === 0): ?><div class="text-warning small">Factura no disponible</div><?php endif; ?><?php else: ?><span class="text-muted">Sin facturas relacionadas</span><?php endif; ?></td>
                <td><?= $esc($clienteLegible($fila['cliente'])) ?></td>
                <td><?= $esc($formatoFecha($fila['fecha_pago'])) ?></td>
                <td class="fw-semibold text-nowrap"><?= $esc($moneda($fila['total_factura'])) ?></td>
                <td><?= $esc($fila['status'] ?: 'Sin estado') ?></td>
                <td class="text-end pe-3 text-nowrap">
                    <?php if (!empty($fila['uuid'])): ?>
                    <button class="btn btn-sm btn-soft-secondary js-view-complement" type="button" data-pdf-url="api/complemento-pdf.php?id=<?= (int) $fila['id'] ?>&amp;vista=1" data-folio="<?= $esc($folio($fila['serie_complemento'], $fila['folio_complemento'])) ?>" title="Ver PDF" aria-label="Ver PDF del complemento <?= $esc($folio($fila['serie_complemento'], $fila['folio_complemento'])) ?>"><i data-lucide="eye" class="fs-16"></i></button>
                    <a class="btn btn-sm btn-soft-primary" href="api/complemento-documentos.php?id=<?= (int) $fila['id'] ?>" title="Descargar XML y PDF" aria-label="Descargar XML y PDF del complemento <?= $esc($folio($fila['serie_complemento'], $fila['folio_complemento'])) ?>"><i data-lucide="folder-down" class="fs-16"></i></a>
                    <?php else: ?><span class="text-muted small">Sin documentos fiscales</span><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if ($listado['filas'] === []): ?><tr><td colspan="7" class="text-center text-muted py-4">No hay complementos para esta empresa y estos filtros.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="text-muted small">Mostrando <?= count($listado['filas']) ?> de <?= number_format($listado['total']) ?> registros</span>
        <nav class="d-flex gap-2" aria-label="Páginas de complementos">
            <?php if ($listado['pagina'] > 1): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= $esc(http_build_query(['buscar' => $buscar, 'desde' => $desde, 'hasta' => $hasta, 'pagina' => $listado['pagina'] - 1])) ?>">Anterior</a><?php endif; ?>
            <span class="btn btn-sm btn-success disabled" aria-current="page"><?= $listado['pagina'] ?> / <?= $listado['paginas'] ?></span>
            <?php if ($listado['pagina'] < $listado['paginas']): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= $esc(http_build_query(['buscar' => $buscar, 'desde' => $desde, 'hasta' => $hasta, 'pagina' => $listado['pagina'] + 1])) ?>">Siguiente</a><?php endif; ?>
        </nav>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="complementoPdfModal" tabindex="-1" aria-labelledby="complementoPdfTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="complementoPdfTitle">Complemento de pago</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body p-0 position-relative" style="min-height:75vh">
                <div id="complementoPdfLoader" class="position-absolute top-0 start-0 w-100 h-100 d-none align-items-center justify-content-center bg-body" style="z-index:2" role="status" aria-live="polite"><div class="text-center"><div class="spinner-border text-primary mb-3" aria-hidden="true"></div><p class="fw-semibold mb-0">Cargando PDF…</p></div></div>
                <iframe id="complementoPdfFrame" title="Vista previa del complemento de pago" style="width:100%;height:75vh;border:0;background:#fff"></iframe>
            </div>
        </div>
    </div>
</div>
<?php
$pageScripts = <<<'HTML'
<script>
(() => {
    const element = document.getElementById('complementoPdfModal');
    const frame = document.getElementById('complementoPdfFrame');
    const loader = document.getElementById('complementoPdfLoader');
    const title = document.getElementById('complementoPdfTitle');
    if (!element || !frame || !loader || !title) return;
    const modal = bootstrap.Modal.getOrCreateInstance(element);
    document.querySelectorAll('.js-view-complement').forEach(button => {
        button.addEventListener('click', () => {
            title.textContent = 'Complemento ' + (button.dataset.folio || '');
            loader.classList.remove('d-none');
            loader.classList.add('d-flex');
            frame.style.visibility = 'hidden';
            frame.src = button.dataset.pdfUrl || 'about:blank';
            modal.show();
        });
    });
    frame.addEventListener('load', () => {
        loader.classList.add('d-none');
        loader.classList.remove('d-flex');
        frame.style.visibility = 'visible';
    });
    element.addEventListener('hidden.bs.modal', () => { frame.src = 'about:blank'; });
})();
</script>
HTML;
?>
<?php require __DIR__ . '/templates/scripts.php'; ?>
