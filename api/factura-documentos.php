<?php

declare(strict_types=1);

require_once __DIR__ . '/FacturaPdfAdministrador.php';
require_once __DIR__ . '/Autenticacion.php';
Autenticacion::exigirApi();

$rutaTemporal = null;

try {
    SesionEmpresa::iniciar();
    $conexion = Conexion::obtener();
    SesionEmpresa::sincronizarContexto($conexion);

    $facturaId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
    $empresaId = SesionEmpresa::empresaActual();
    if ($facturaId <= 0) {
        throw new RuntimeException('La factura solicitada no es válida.');
    }

    $consulta = $conexion->prepare(
        'SELECT f.uuid, f.xml_firmado, e.razon AS empresa_nombre
         FROM facturas f
         INNER JOIN empresas e ON e.id = f.razon
         WHERE f.id = :factura AND f.razon = :empresa LIMIT 1'
    );
    $consulta->execute([':factura' => $facturaId, ':empresa' => $empresaId]);
    $factura = $consulta->fetch(PDO::FETCH_ASSOC);
    if (!is_array($factura) || trim((string) ($factura['uuid'] ?? '')) === '') {
        throw new RuntimeException('La factura timbrada no existe o no pertenece a la empresa activa.');
    }

    $rutaXml = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'firmados'
        . DIRECTORY_SEPARATOR . 'XML-factura-' . $facturaId . '.xml';
    $contenidoXml = is_file($rutaXml) ? file_get_contents($rutaXml) : false;
    if (!is_string($contenidoXml) || trim($contenidoXml) === '') {
        $contenidoXml = trim((string) ($factura['xml_firmado'] ?? ''));
    }
    if ($contenidoXml === '') {
        throw new RuntimeException('No se encontró el XML timbrado de la factura.');
    }

    $pdf = (new FacturaPdfAdministrador(
        $conexion,
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'pdf',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'pdfs'
    ))->generar($facturaId, $empresaId);

    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('El servidor no tiene habilitado el soporte para archivos ZIP.');
    }

    $nombreBase = FacturaPdfAdministrador::nombreBaseArchivo(
        (string) ($factura['empresa_nombre'] ?? 'Empresa'),
        (string) $factura['uuid']
    );
    $rutaTemporal = tempnam(sys_get_temp_dir(), 'cfdi_');
    if (!is_string($rutaTemporal) || $rutaTemporal === '') {
        throw new RuntimeException('No fue posible preparar la descarga.');
    }

    $zip = new ZipArchive();
    if ($zip->open($rutaTemporal, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('No fue posible crear el paquete de documentos.');
    }
    if (!$zip->addFromString($nombreBase . '.xml', $contenidoXml)
        || !$zip->addFromString($nombreBase . '.pdf', (string) $pdf['contenido'])) {
        $zip->close();
        throw new RuntimeException('No fue posible agregar los comprobantes al paquete.');
    }
    if (!$zip->close()) {
        throw new RuntimeException('No fue posible finalizar el paquete de documentos.');
    }

    $tamano = filesize($rutaTemporal);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $nombreBase . '-documentos.zip"');
    if (is_int($tamano)) {
        header('Content-Length: ' . $tamano);
    }
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($rutaTemporal);
} catch (Throwable $error) {
    error_log('Error al descargar documentos de factura: ' . $error->getMessage());
    if (!headers_sent()) {
        http_response_code($error instanceof RuntimeException ? 404 : 500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'No fue posible descargar los documentos de la factura.';
} finally {
    if (is_string($rutaTemporal) && is_file($rutaTemporal)) {
        @unlink($rutaTemporal);
    }
}
