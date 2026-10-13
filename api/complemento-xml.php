<?php

declare(strict_types=1);

require_once __DIR__ . '/Autenticacion.php';
require_once __DIR__ . '/ComplementoDocumentoServicio.php';

Autenticacion::exigirApi();

try {
    $relacionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
    $servicio = new ComplementoDocumentoServicio(Conexion::obtener(), dirname(__DIR__));
    $complemento = $servicio->obtener($relacionId, SesionEmpresa::empresaActual());
    $resultado = $servicio->obtenerXmlParaVista($complemento);
    if ($resultado === null) {
        throw new RuntimeException('No se encontró el XML del complemento en las rutas configuradas.');
    }

    $xml = $resultado['contenido'];
    $mensaje = $resultado['firmado'] ? '' : "XML SIN FIRMA (solo vista de prueba; no es un comprobante timbrado)\n\n";
    $contenido = $mensaje . $xml;
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: inline; filename="' . $servicio->nombreBase($complemento) . ($resultado['firmado'] ? '.xml' : '-sin-firma.txt') . '"');
    header('Content-Length: ' . strlen($contenido));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $contenido;
} catch (Throwable $error) {
    error_log('Error al consultar XML de complemento: ' . $error->getMessage());
    http_response_code($error instanceof RuntimeException ? 404 : 500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $error instanceof RuntimeException
        ? 'No se encontró un XML accesible para este complemento en las rutas configuradas.'
        : 'No fue posible consultar el XML del complemento.';
}
