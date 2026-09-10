<?php

declare(strict_types=1);

require_once __DIR__ . '/FacturaPendienteAdministrador.php';
require_once __DIR__ . '/Autenticacion.php';
Autenticacion::exigirApi();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

try {
    SesionEmpresa::iniciar();
    $administrador = new FacturacionAdministrador(Conexion::obtener());
    $pendientes = new FacturaPendienteAdministrador(Conexion::obtener());
    $accion = strtolower(trim((string) ($_GET['accion'] ?? 'contexto')));

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $contenido = file_get_contents('php://input');
        $datos = json_decode($contenido ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($datos)) {
            throw new RuntimeException('La solicitud no tiene un formato válido.');
        }
        $token = (string) ($datos['csrf'] ?? '');
        if (!isset($_SESSION['facturacion_csrf']) || !hash_equals((string) $_SESSION['facturacion_csrf'], $token)) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'La sesión del formulario expiró. Recarga la página.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            exit;
        }
        if ($accion === 'guardar') {
            $facturaId = max(0, (int) ($datos['factura_id'] ?? 0));
            $guardado = $pendientes->guardar($facturaId, $datos);
            echo json_encode([
                'ok' => true,
                'mensaje' => 'Los cambios de la factura pendiente fueron guardados.',
                'resultado' => $guardado['resultado'],
                'factura' => $guardado['factura'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            exit;
        }
        if ($accion !== 'validar') {
            throw new RuntimeException('La operación POST solicitada no existe.');
        }
        $facturaId = max(0, (int) ($datos['factura_id'] ?? 0));
        echo json_encode([
            'ok' => true,
            'resultado' => $facturaId > 0
                ? $pendientes->validar($facturaId, $datos)
                : $administrador->validarBorrador($datos),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    $respuesta = match ($accion) {
        'contexto' => $administrador->obtenerContexto(),
        'perfiles' => $administrador->listarPerfilesCliente(filter_input(INPUT_GET, 'cliente_id', FILTER_VALIDATE_INT) ?: 0),
        'conceptos' => $administrador->listarConceptos((string) ($_GET['buscar'] ?? '')),
        'pendiente' => $pendientes->obtener(filter_input(INPUT_GET, 'factura_id', FILTER_VALIDATE_INT) ?: 0),
        default => throw new RuntimeException('La operación solicitada no existe.'),
    };
    echo json_encode(['ok' => true, 'datos' => $respuesta], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El contenido JSON no es válido.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (FacturaPendienteValidacionException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage(), 'errores' => $error->errores], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (RuntimeException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No fue posible preparar la factura.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
