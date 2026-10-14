<?php

declare(strict_types=1);

require_once __DIR__ . '/Autenticacion.php';
require_once __DIR__ . '/ComplementoDocumentoServicio.php';
Autenticacion::exigirApi();

try {
    $complementoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
    $servicio = new ComplementoDocumentoServicio(Conexion::obtener(), dirname(__DIR__));
    $complemento = $servicio->obtener($complementoId, SesionEmpresa::empresaActual());
    $pdf = $servicio->obtenerPdf($complemento);
    if ($pdf === null) {
        throw new RuntimeException('No se encontró el PDF del complemento.');
    }
    $disposicion = filter_input(INPUT_GET, 'vista') === '1' ? 'inline' : 'attachment';
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . $disposicion . '; filename="' . $servicio->nombreBase($complemento) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
} catch (Throwable $error) {
    error_log('Error al consultar PDF de complemento: ' . $error->getMessage());
    http_response_code($error instanceof RuntimeException ? 404 : 500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo 'No fue posible obtener el PDF del complemento.';
}
