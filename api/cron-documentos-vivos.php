<?php

declare(strict_types=1);

require_once __DIR__ . '/Conexion.php';
require_once __DIR__ . '/DocumentosVivosServicio.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$inicio = microtime(true);
$tokenEsperado = getenv('DOCUMENTOS_VIVOS_CRON_TOKEN') ?: '';
if ($tokenEsperado === '' && is_readable(__DIR__ . '/cron-token.local')) {
    $tokenEsperado = trim((string) file_get_contents(__DIR__ . '/cron-token.local'));
}
if ($tokenEsperado === '') {
    $ruta = dirname(__DIR__) . '/.env';
    foreach (is_readable($ruta) ? (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $linea) {
        if (preg_match('/^\s*DOCUMENTOS_VIVOS_CRON_TOKEN\s*=\s*(.*?)\s*$/', $linea, $partes)) {
            $tokenEsperado = trim($partes[1], "\"'");
            break;
        }
    }
}
$tokenRecibido = (string) ($_GET['token'] ?? '');
if (strlen($tokenEsperado) < 32 || !hash_equals($tokenEsperado, $tokenRecibido)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

$conexion = null;
$bloqueado = false;
try {
    $conexion = Conexion::obtener();
    $bloqueo = $conexion->query("SELECT GET_LOCK('s14_short_key_live_documents_cron', 0)");
    $bloqueado = (int) $bloqueo->fetchColumn() === 1;
    if (!$bloqueado) {
        http_response_code(409);
        throw new RuntimeException('El cálculo de documentos vivos ya está en curso.');
    }
    $modificados = DocumentosVivosServicio::actualizarConteos($conexion);
    $resumen = $conexion->query('SELECT COUNT(*) AS claves_cortas,
        COALESCE(SUM(documentos_vivos > 0), 0) AS claves_con_documentos,
        COALESCE(SUM(documentos_vivos), 0) AS documentos_vivos FROM claves_cortas')->fetch(PDO::FETCH_ASSOC);
    echo json_encode([
        'ok' => true,
        'claves_cortas' => (int) $resumen['claves_cortas'],
        'claves_con_documentos' => (int) $resumen['claves_con_documentos'],
        'documentos_vivos' => (int) $resumen['documentos_vivos'],
        'registros_modificados' => $modificados,
        'duracion_segundos' => round(microtime(true) - $inicio, 3),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(500);
    error_log('Cron de documentos vivos: ' . $error->getMessage());
    echo json_encode(['ok' => false, 'error' => http_response_code() === 409
        ? $error->getMessage() : 'No fue posible actualizar los documentos vivos.'], JSON_UNESCAPED_UNICODE);
} finally {
    if ($bloqueado && $conexion instanceof PDO) {
        $conexion->query("SELECT RELEASE_LOCK('s14_short_key_live_documents_cron')");
    }
}
