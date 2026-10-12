<?php

declare(strict_types=1);

require_once __DIR__ . '/Autenticacion.php';
require_once __DIR__ . '/ComplementoDocumentoServicio.php';
Autenticacion::exigirApi();

$rutaTemporal = null;
try {
    $relacionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
    $servicio = new ComplementoDocumentoServicio(Conexion::obtener(), dirname(__DIR__));
    $complemento = $servicio->obtener($relacionId, SesionEmpresa::empresaActual());
    $xml = $servicio->obtenerXml($complemento);
    if ($xml === null) {
        throw new RuntimeException('No se encontró el XML timbrado del complemento.');
    }
    $pdf = $servicio->obtenerPdf($complemento);
    if ($pdf === null) {
        throw new RuntimeException('No se encontró el PDF del complemento.');
    }
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('El servidor no tiene soporte para ZIP.');
    }
    $nombre = $servicio->nombreBase($complemento);
    $rutaTemporal = tempnam(sys_get_temp_dir(), 'comp_');
    if (!is_string($rutaTemporal)) {
        throw new RuntimeException('No fue posible preparar la descarga.');
    }
    $zip = new ZipArchive();
    if ($zip->open($rutaTemporal, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('No fue posible crear el paquete de documentos.');
    }
    $agregado = $zip->addFromString($nombre . '.xml', $xml)
        && $zip->addFromString($nombre . '.pdf', $pdf);
    $cerrado = $zip->close();
    if (!$agregado || !$cerrado) {
        throw new RuntimeException('No fue posible finalizar el paquete de documentos.');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $nombre . '-documentos.zip"');
    $tamano = filesize($rutaTemporal);
    if (is_int($tamano)) header('Content-Length: ' . $tamano);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($rutaTemporal);
} catch (Throwable $error) {
    error_log('Error al descargar documentos de complemento: ' . $error->getMessage());
    http_response_code($error instanceof RuntimeException ? 404 : 500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo 'No fue posible descargar los documentos del complemento.';
} finally {
    if (is_string($rutaTemporal) && is_file($rutaTemporal)) @unlink($rutaTemporal);
}
