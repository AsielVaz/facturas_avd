<?php

declare(strict_types=1);

require_once __DIR__ . '/Autenticacion.php';
require_once __DIR__ . '/ComplementoPagoTimbradoServicio.php';
Autenticacion::exigirApi();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        throw new ComplementoPagoXmlException('Método no permitido.');
    }
    $contenido = file_get_contents('php://input');
    if (!is_string($contenido) || strlen($contenido) > 4096) {
        throw new ComplementoPagoXmlException('La solicitud de timbrado no es válida.');
    }
    $datos = json_decode($contenido, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($datos)) throw new ComplementoPagoXmlException('La solicitud de timbrado no es válida.');
    $csrf = (string) ($datos['csrf'] ?? '');
    if (!isset($_SESSION['complemento_timbrado_csrf'])
        || !hash_equals((string) $_SESSION['complemento_timbrado_csrf'], $csrf)) {
        http_response_code(419);
        throw new ComplementoPagoXmlException('La sesión expiró. Recarga el listado.');
    }
    $id = filter_var($datos['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $resultado = (new ComplementoPagoTimbradoServicio(Conexion::obtener(), dirname(__DIR__)))->timbrar(
        $id, SesionEmpresa::empresaActual(), Autenticacion::usuarioActualId()
    );
    echo json_encode(['ok' => true, 'uuid' => $resultado['uuid'], 'ambiente' => $resultado['ambiente']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (JsonException | ComplementoPagoXmlException | CfdiTimbradoException $error) {
    if (http_response_code() < 400) http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error instanceof JsonException ? 'La solicitud JSON no es válida.' : $error->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    $referencia = strtoupper(bin2hex(random_bytes(6)));
    error_log('Timbrado complemento [' . $referencia . '] ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo completar el timbrado. El complemento permanece pendiente. Revisa el registro del servidor.',
        'referencia' => $referencia], JSON_UNESCAPED_UNICODE);
}
