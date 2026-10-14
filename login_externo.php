<?php

declare(strict_types=1);

require_once __DIR__ . '/api/Autenticacion.php';
// Este archivo es compartido por el servicio emisor y el despliegue del ERP.
require_once __DIR__ . '/encriptador.php';
date_default_timezone_set(SesionEmpresa::ZONA_HORARIA);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

/** @param array<string, mixed> $datos */
function responderLoginExterno(int $estado, array $datos): never
{
    http_response_code($estado);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderLoginExterno(405, [
        'estatus' => 'error', 'metodo' => 'login14', 'mensaje' => 'Método no permitido.',
    ]);
}

$cadena = $_POST['cadena_total'] ?? null;
if (!is_string($cadena) || $cadena === '' || strlen($cadena) > 4096) {
    responderLoginExterno(400, [
        'estatus' => 'error', 'metodo' => 'login14', 'mensaje' => 'Falta una llave de acceso válida.',
    ]);
}

try {
    $contenido = desencriptar($cadena);
    if (!is_string($contenido) || strlen($contenido) > 2048) {
        throw new AutenticacionException('La llave de acceso no es válida.');
    }

    // Formato del servicio emisor: id,fecha,tipo,cliente,nombre,email.
    $campos = explode(',', $contenido);
    if (count($campos) !== 6) {
        throw new AutenticacionException('La llave de acceso no tiene el formato esperado.');
    }
    [$id, $fecha, $tipo, $cliente, $nombre, $email] = array_map('trim', $campos);
    if (preg_match('/^[1-9][0-9]*$/D', $id) !== 1
        || preg_match('/^[1-9][0-9]*$/D', $cliente) !== 1
        || $fecha !== date('Y-m-d')
        || $tipo === '' || $nombre === '' || $email === '') {
        throw new AutenticacionException('La llave de acceso está vencida o contiene datos inválidos.');
    }

    Autenticacion::iniciarSesionConClaveExterna(
        Conexion::obtener(), (int) $id, (int) $cliente, $tipo, $email
    );
    responderLoginExterno(200, [
        'estatus' => 'exito',
        'metodo' => 'login14',
        'mensaje' => 'Sesión iniciada correctamente.',
        'redirigir' => 'index.php',
    ]);
} catch (AutenticacionException $error) {
    responderLoginExterno(401, [
        'estatus' => 'error', 'metodo' => 'login14', 'mensaje' => $error->getMessage(),
    ]);
} catch (Throwable $error) {
    error_log('Login externo: ' . $error->getMessage());
    responderLoginExterno(500, [
        'estatus' => 'error', 'metodo' => 'login14',
        'mensaje' => 'No fue posible iniciar sesión. Inténtalo de nuevo.',
    ]);
}
