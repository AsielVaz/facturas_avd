<?php

declare(strict_types=1);

require_once __DIR__ . '/Autenticacion.php';
require_once __DIR__ . '/ComplementoPagoAdministrador.php';
Autenticacion::exigirApi();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $administrador = new ComplementoPagoAdministrador(Conexion::obtener());
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $accion = (string) ($_GET['accion'] ?? '');
        $clienteId = filter_input(INPUT_GET, 'cliente_id', FILTER_VALIDATE_INT) ?: 0;
        $perfilId = filter_input(INPUT_GET, 'perfil_id', FILTER_VALIDATE_INT) ?: 0;
        $datos = match ($accion) {
            'perfiles' => (new FacturacionAdministrador(Conexion::obtener()))->listarPerfilesCliente($clienteId),
            'facturas' => $administrador->facturasDisponibles($clienteId, $perfilId),
            default => throw new ComplementoPagoValidacionException('La consulta solicitada no existe.'),
        };
        echo json_encode(['ok' => true, 'datos' => $datos], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        throw new ComplementoPagoValidacionException('Método no permitido.');
    }
    $contenido = file_get_contents('php://input');
    if (!is_string($contenido) || strlen($contenido) > 32768) {
        throw new ComplementoPagoValidacionException('La solicitud es demasiado grande.');
    }
    $datos = json_decode($contenido, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($datos)) throw new ComplementoPagoValidacionException('La solicitud no tiene un formato válido.');
    $csrf = (string) ($datos['csrf'] ?? '');
    if (!isset($_SESSION['complemento_pago_csrf'])
        || !hash_equals((string) $_SESSION['complemento_pago_csrf'], $csrf)) {
        http_response_code(419);
        throw new ComplementoPagoValidacionException('La sesión del formulario expiró. Recarga la página.');
    }
    $resultado = $administrador->guardarPendiente($datos);
    echo json_encode(['ok' => true, 'complemento' => $resultado], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El JSON enviado no es válido.'], JSON_UNESCAPED_UNICODE);
} catch (ComplementoPagoValidacionException $error) {
    if (http_response_code() < 400) http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (PDOException $error) {
    error_log('Error de base de datos al crear complemento: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No fue posible consultar o guardar el complemento.'], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Error al crear complemento de pago: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No fue posible guardar el complemento.'], JSON_UNESCAPED_UNICODE);
}
