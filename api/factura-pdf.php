<?php

declare(strict_types=1);

require_once __DIR__ . '/FacturaPdfAdministrador.php';
require_once __DIR__ . '/Autenticacion.php';
Autenticacion::exigirApi();

try {
    SesionEmpresa::iniciar();
    $conexion = Conexion::obtener();
    SesionEmpresa::sincronizarContexto($conexion);
    $facturaId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
    $resultado = (new FacturaPdfAdministrador(
        $conexion,
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'pdf',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'pdfs'
    ))->generar($facturaId, SesionEmpresa::empresaActual());

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $resultado['archivo'] . '"');
    header('Content-Length: ' . strlen($resultado['contenido']));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $resultado['contenido'];
} catch (FacturaPdfException $error) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $error->getMessage();
} catch (Throwable $error) {
    error_log('Error al generar PDF de factura: ' . $error->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No fue posible generar el PDF de la factura.';
}
