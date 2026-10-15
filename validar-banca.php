<?php

declare(strict_types=1);

require_once __DIR__ . '/api/Autenticacion.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

/** Muestra un error sin exponer la llave ni los tokens devueltos por Banca. */
function responderErrorBanca(int $codigo, string $mensaje): never
{
    http_response_code($codigo);
    if (($_POST['diagnostico'] ?? null) === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'estatus_guardado' => 'error',
            'error' => $mensaje,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Acceso a Banca</title>';
    echo '<p>' . htmlspecialchars($mensaje, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    echo '<p><a href="facturas.php">Volver a Facturas</a></p></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderErrorBanca(405, 'Método no permitido.');
}

$diagnostico = ($_POST['diagnostico'] ?? null) === '1';

if (!Autenticacion::autenticado()) {
    responderErrorBanca(401, 'Tu sesión de Facturas expiró. Inicia sesión nuevamente.');
}

$cadenaSesion = $_SESSION['cadena_total'] ?? null;
$cadenaEnviada = $_POST['cadena_total'] ?? null;
if (!is_string($cadenaSesion) || $cadenaSesion === '' || strlen($cadenaSesion) > 4096
    || !is_string($cadenaEnviada) || !hash_equals($cadenaSesion, $cadenaEnviada)) {
    $_SESSION['banca_estatus'] = 'error';
    responderErrorBanca(400, 'La llave de acceso a Banca no está disponible. Inicia sesión nuevamente.');
}

$_SESSION['banca_estatus'] = 'error';
if (!function_exists('curl_init')) {
    responderErrorBanca(503, 'No está disponible la conexión de verificación con Banca.');
}

$peticion = curl_init('https://banca.sistema14.com/api/login_externo.php');
if ($peticion === false) {
    responderErrorBanca(503, 'No fue posible preparar la verificación con Banca.');
}

curl_setopt_array($peticion, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => ['cadena_total' => $cadenaSesion],
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$contenido = curl_exec($peticion);
$codigoHttp = (int) curl_getinfo($peticion, CURLINFO_RESPONSE_CODE);
curl_close($peticion);

if (!is_string($contenido) || $contenido === '' || strlen($contenido) > 65536) {
    responderErrorBanca(502, 'No fue posible consultar la respuesta de Banca.');
}

try {
    $respuesta = json_decode($contenido, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    responderErrorBanca(502, 'Banca devolvió una respuesta que no se pudo interpretar.');
}

if (!is_array($respuesta) || !isset($respuesta['estatus']) || !is_string($respuesta['estatus'])) {
    responderErrorBanca(502, 'Banca devolvió una respuesta sin estatus válido.');
}

$_SESSION['banca_estatus'] = $codigoHttp >= 200 && $codigoHttp < 300 && $respuesta['estatus'] === 'exito'
    ? 'exito'
    : 'error';
if ($diagnostico) {
    // Limitar el diagnóstico a campos conocidos; nunca guardar llaves ni tokens.
    $respuestaVisible = array_intersect_key($respuesta, array_flip(['estatus', 'metodo', 'mensaje']));
    foreach (['token', 'tokenApp'] as $campoSensible) {
        if (array_key_exists($campoSensible, $respuesta)) {
            $respuestaVisible[$campoSensible] = '[oculto]';
        }
    }
    $archivoRelativo = 'tmp/banca-diagnostico-' . hash('sha256', session_id()) . '.txt';
    $datosDiagnostico = [
        'fecha' => date('c'),
        'estatus_guardado' => $_SESSION['banca_estatus'],
        'codigo_http_banca' => $codigoHttp,
        'respuesta_banca' => $respuestaVisible,
    ];
    $textoDiagnostico = json_encode($datosDiagnostico, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $guardado = @file_put_contents(__DIR__ . '/' . $archivoRelativo, $textoDiagnostico . PHP_EOL, LOCK_EX);
    header('Content-Type: application/json; charset=utf-8');
    $datosDiagnostico['archivo_diagnostico'] = $guardado === false ? 'No se pudo guardar el TXT.' : $archivoRelativo;
    echo json_encode($datosDiagnostico, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    exit;
}

if ($codigoHttp < 200 || $codigoHttp >= 300 || $_SESSION['banca_estatus'] !== 'exito') {
    $mensaje = $respuesta['mensaje'] ?? null;
    responderErrorBanca(401, is_string($mensaje) && $mensaje !== ''
        ? substr($mensaje, 0, 300)
        : 'Banca rechazó la llave de acceso.');
}

// La validación anterior es el único POST a Banca. Esta navegación no vuelve
// a enviar la llave y no transfiere al navegador la cookie del POST del servidor.
session_write_close();
header('Location: https://banca.sistema14.com/', true, 303);
exit;
