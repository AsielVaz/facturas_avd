<?php

declare(strict_types=1);

require_once __DIR__ . '/api/Autenticacion.php';
require_once __DIR__ . '/api/ComplementoPagoConsulta.php';

Autenticacion::exigirPagina();
$empresa = SesionEmpresa::empresaActual();
$consultaComplementos = new ComplementoPagoConsulta(Conexion::obtener(), $empresa);
$esc = static fn(mixed $valor): string => htmlspecialchars(is_scalar($valor) ? (string) $valor : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
$listado = ['filas' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1, 'por_pagina' => 25];

try {
    if ($id > 0) {
        $detalle = $consultaComplementos->obtener($id);
        if ($detalle === null) {
            http_response_code(404);
            $error = 'No se encontró el complemento en la empresa activa.';
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
$pageAction = $detalle ? '<a href="' . $esc($regresar) . '" class="btn btn-soft-secondary">Volver al listado</a>' : '';
require __DIR__ . '/templates/page-start.php';
?>

<?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= $esc($error) ?></div><?php endif; ?>

<?php if ($detalle): ?>
<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between gap-2">
        <div><h5 class="mb-1">Complemento <?= $esc($folio($detalle['serie_complemento'], $detalle['folio_complemento'])) ?></h5><span class="text-muted">Registro de pago #<?= (int) $detalle['id'] ?></span></div>
        <span class="badge bg-info-subtle text-info align-self-start">Solo consulta</span>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-md-6"><div class="border rounded p-3 h-100"><h6>Comprobante</h6>
                <dl class="row mb-0"><dt class="col-sm-5">Cliente</dt><dd class="col-sm-7"><?= $esc($detalle['cliente']) ?></dd>
                <dt class="col-sm-5">Folio</dt><dd class="col-sm-7"><?= $esc($folio($detalle['serie_complemento'], $detalle['folio_complemento'])) ?></dd>
                <dt class="col-sm-5">UUID</dt><dd class="col-sm-7 text-break"><?= $esc(trim((string) ($detalle['uuid'] ?? '')) ?: 'Sin timbrar') ?></dd>
                <dt class="col-sm-5">Estado registrado</dt><dd class="col-sm-7"><?= $esc($detalle['status'] ?: 'Sin estado') ?></dd>
                <dt class="col-sm-5">Fecha del comprobante</dt><dd class="col-sm-7"><?= $esc($formatoFecha($detalle['fecha_complemento'])) ?></dd>
                <dt class="col-sm-5">Fecha de timbrado</dt><dd class="col-sm-7"><?= $esc($formatoFecha($detalle['fecha_timbrado'])) ?></dd></dl>
            </div></div>
            <div class="col-md-6"><div class="border rounded p-3 h-100"><h6>Pago relacionado</h6>
                <dl class="row mb-0"><dt class="col-sm-5">Factura</dt><dd class="col-sm-7"><?= $esc($folio($detalle['serie_factura'], $detalle['folio_factura'])) ?><?= (int) $detalle['factura_disponible'] === 0 ? ' · No disponible en esta empresa' : '' ?></dd>
                <dt class="col-sm-5">UUID relacionado</dt><dd class="col-sm-7 text-break"><?= $esc($detalle['uuid_rel'] ?: '—') ?></dd>
                <dt class="col-sm-5">Número de pago</dt><dd class="col-sm-7"><?= $esc($detalle['num_pago'] ?: '—') ?></dd>
                <dt class="col-sm-5">Fecha de pago</dt><dd class="col-sm-7"><?= $esc($formatoFecha($detalle['fecha_pago'])) ?></dd>
                <dt class="col-sm-5">Monto pagado</dt><dd class="col-sm-7 fw-semibold"><?= $esc($moneda($detalle['monto'])) ?></dd>
                <dt class="col-sm-5">Saldo anterior</dt><dd class="col-sm-7"><?= $esc($moneda($detalle['saldo'])) ?></dd>
                <dt class="col-sm-5">Saldo restante</dt><dd class="col-sm-7"><?= $esc($moneda($detalle['restante'])) ?></dd></dl>
            </div></div>
        </div>
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
            <thead><tr><th>Complemento</th><th>Factura relacionada</th><th>Cliente</th><th>Fecha de pago</th><th>Pago</th><th>Monto</th><th>Saldo restante</th><th>Estado</th></tr></thead>
            <tbody>
            <?php foreach ($listado['filas'] as $fila): ?>
            <tr>
                <td><a href="?<?= $esc(http_build_query(['id' => $fila['id'], 'buscar' => $buscar, 'desde' => $desde, 'hasta' => $hasta, 'pagina' => $listado['pagina']])) ?>" class="fw-semibold"><?= $esc($folio($fila['serie_complemento'], $fila['folio_complemento'])) ?></a><div class="text-muted small text-truncate" style="max-width:170px" title="<?= $esc($fila['uuid']) ?>"><?= $esc($fila['uuid'] ?: 'Sin UUID') ?></div></td>
                <td><?= $esc($folio($fila['serie_factura'], $fila['folio_factura'])) ?><?php if ((int) $fila['factura_disponible'] === 0): ?><div class="text-warning small">Factura no disponible</div><?php endif; ?></td>
                <td><?= $esc($fila['cliente']) ?></td>
                <td><?= $esc($formatoFecha($fila['fecha_pago'])) ?></td>
                <td><?= $esc($fila['num_pago'] ?: '—') ?></td>
                <td class="fw-semibold text-nowrap"><?= $esc($moneda($fila['monto'])) ?></td>
                <td class="text-nowrap"><?= $esc($moneda($fila['restante'])) ?></td>
                <td><?= $esc($fila['status'] ?: 'Sin estado') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ($listado['filas'] === []): ?><tr><td colspan="8" class="text-center text-muted py-4">No hay complementos para esta empresa y estos filtros.</td></tr><?php endif; ?>
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

<?php require __DIR__ . '/templates/scripts.php'; ?>
