<?php

declare(strict_types=1);

require_once __DIR__ . '/FacturaAdministrador.php';
require_once __DIR__ . '/FacturaCreacionAdministrador.php';
require_once __DIR__ . '/FacturaPendienteAdministrador.php';
require_once __DIR__ . '/CfdiTimbradoServicio.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

try {
    SesionEmpresa::iniciar();
    $conexion = Conexion::obtener();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['accion'] ?? '') === 'descargar_xml') {
        $facturaId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
        $tipoXml = strtolower(trim((string) ($_GET['tipo'] ?? 'sin_firma')));
        $timbrado = $tipoXml === 'timbrado';
        $consulta = $conexion->prepare('SELECT uuid, xml_firmado FROM facturas WHERE id = :factura AND razon = :empresa LIMIT 1');
        $consulta->execute([':factura' => $facturaId, ':empresa' => SesionEmpresa::empresaActual()]);
        $facturaXml = $consulta->fetch(PDO::FETCH_ASSOC);
        $ruta = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . ($timbrado ? 'firmados' : 'sinfirma')
            . DIRECTORY_SEPARATOR . 'XML-factura-' . $facturaId . '.xml';
        $contenidoBase = $timbrado && is_array($facturaXml) ? trim((string) ($facturaXml['xml_firmado'] ?? '')) : '';
        if ($facturaId <= 0 || !is_array($facturaXml) || ($timbrado && trim((string) ($facturaXml['uuid'] ?? '')) === '') || (!is_file($ruta) && $contenidoBase === '')) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'El XML solicitado no existe.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            exit;
        }
        $contenidoXml = is_file($ruta) ? file_get_contents($ruta) : $contenidoBase;
        if (!is_string($contenidoXml) || $contenidoXml === '') {
            throw new RuntimeException('No fue posible leer el XML solicitado.');
        }
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="XML-factura-' . $facturaId . '.xml"');
        header('Content-Length: ' . strlen($contenidoXml));
        echo $contenidoXml;
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $accion = strtolower(trim((string) ($_GET['accion'] ?? '')));
        if ($accion !== 'guardar') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'La operación solicitada no existe.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            exit;
        }

        $contenido = file_get_contents('php://input');
        $datos = json_decode($contenido ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($datos)) {
            throw new RuntimeException('La solicitud no tiene un formato válido.');
        }

        $tokenCsrf = (string) ($datos['csrf'] ?? '');
        if (!isset($_SESSION['facturacion_csrf']) || !hash_equals((string) $_SESSION['facturacion_csrf'], $tokenCsrf)) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'La sesión del formulario expiró. Recarga la página.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            exit;
        }

        /* TIMBRADO DESACTIVADO TEMPORALMENTE PARA PRUEBAS LOCALES.
        $servicioTimbrado = new CfdiTimbradoServicio(
            $conexion,
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'sinfirma',
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'firmados'
        );
        $usuarioId = isset($_SESSION['usuario_id']) && (int) $_SESSION['usuario_id'] > 0
            ? (int) $_SESSION['usuario_id']
            : null;

        if ($accion === 'timbrar') {
            $facturaId = max(0, (int) ($datos['factura_id'] ?? 0));
            try {
                $rutaPrevia = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'sinfirma'
                    . DIRECTORY_SEPARATOR . 'XML-factura-' . $facturaId . '.xml';
                if (!is_file($rutaPrevia)) {
                    (new FacturaPendienteAdministrador($conexion))->generarXml($facturaId);
                }
                $timbrado = $servicioTimbrado->timbrar($facturaId, SesionEmpresa::empresaActual(), $usuarioId);
                echo json_encode([
                    'ok' => true,
                    'mensaje' => 'La factura fue timbrada correctamente.',
                    'factura' => [
                        'id' => $facturaId,
                        'uuid' => $timbrado['uuid'],
                        'fecha_timbrado' => $timbrado['fecha_timbrado'],
                        'ambiente' => $timbrado['ambiente'],
                        'xml' => $timbrado['xml'],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (CfdiTimbradoException | FacturaPendienteValidacionException $error) {
                http_response_code(502);
                echo json_encode([
                    'ok' => false,
                    'error' => $error->getMessage(),
                    'errores' => $error instanceof FacturaPendienteValidacionException ? $error->errores : [],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            exit;
        }
        */

        $factura = (new FacturaCreacionAdministrador($conexion))->guardarFactura($datos);
        /* TIMBRADO DESACTIVADO TEMPORALMENTE PARA PRUEBAS LOCALES.
        try {
            $timbrado = $servicioTimbrado->timbrar(
                (int) $factura['id'],
                SesionEmpresa::empresaActual(),
                $usuarioId
            );
        } catch (CfdiTimbradoException $error) {
            $_SESSION['facturacion_csrf'] = bin2hex(random_bytes(32));
            http_response_code(502);
            echo json_encode([
                'ok' => false,
                'guardada' => true,
                'error' => 'La factura se guardó, pero no pudo timbrarse: ' . $error->getMessage(),
                'factura' => $factura,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            exit;
        } catch (Throwable $error) {
            error_log('Error al persistir el timbrado de la factura ' . (int) $factura['id'] . ': ' . $error->getMessage());
            $_SESSION['facturacion_csrf'] = bin2hex(random_bytes(32));
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'guardada' => true,
                'error' => 'La factura se guardó, pero no fue posible completar el registro del timbrado.',
                'factura' => $factura,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            exit;
        }
        $factura['xml_sin_firma'] = $factura['xml'];
        $factura['xml'] = $timbrado['xml'];
        $factura['uuid'] = $timbrado['uuid'];
        $factura['fecha_timbrado'] = $timbrado['fecha_timbrado'];
        $factura['ambiente'] = $timbrado['ambiente'];
        */
        $_SESSION['facturacion_csrf'] = bin2hex(random_bytes(32));
        echo json_encode([
            'ok' => true,
            'mensaje' => 'La factura y su XML sin firma fueron generados correctamente.',
            'factura' => $factura,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    $administrador = new FacturaAdministrador($conexion);
    $tipo = ($_GET['tipo'] ?? 'pendientes') === 'timbradas' ? 'timbradas' : 'pendientes';
    $resultado = $administrador->listar(
        $tipo,
        filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT) ?: 1,
        filter_input(INPUT_GET, 'por_pagina', FILTER_VALIDATE_INT) ?: 10,
        (string) ($_GET['buscar'] ?? ''),
        (string) ($_GET['fecha'] ?? '')
    );

    echo json_encode([
        'ok' => true,
        'empresa' => SesionEmpresa::empresaActual(),
        'tipo' => $tipo,
        'resumen' => $administrador->obtenerResumen($tipo),
        'datos' => $resultado,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El contenido JSON no es válido.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (FacturaCreacionValidacionException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage(), 'errores' => $error->errores], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (CfdiXmlGeneracionException $error) {
    error_log('Error al generar XML CFDI: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No fue posible generar el XML de la factura.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (PDOException $error) {
    error_log('Error al guardar factura: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No fue posible guardar la factura.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (RuntimeException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Error inesperado al generar factura: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No fue posible generar la factura.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
