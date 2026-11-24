<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/Conexion.php';

$db = Conexion::obtener();
if ((int) $db->query("SELECT GET_LOCK('s14_short_key_live_documents_cron', 0)")->fetchColumn() !== 1) {
    throw new RuntimeException('No se pudo adquirir el bloqueo de prueba.');
}
try {
    $codigo = '$_GET["token"] = trim(file_get_contents("api/cron-token.local")); '
        . 'include "api/cron-documentos-vivos.php";';
    $proceso = proc_open([PHP_BINARY, '-r', $codigo],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias, dirname(__DIR__));
    if (!is_resource($proceso)) throw new RuntimeException('No se pudo iniciar la segunda ejecución.');
    $salida = stream_get_contents($tuberias[1]);
    $error = stream_get_contents($tuberias[2]);
    fclose($tuberias[1]);
    fclose($tuberias[2]);
    proc_close($proceso);
    $respuesta = json_decode((string) $salida, true);
    if (!is_array($respuesta) || ($respuesta['ok'] ?? null) !== false
        || !str_contains((string) ($respuesta['error'] ?? ''), 'ya está en curso')) {
        throw new RuntimeException('La ejecución simultánea no fue rechazada: ' . $error);
    }
} finally {
    $db->query("SELECT RELEASE_LOCK('s14_short_key_live_documents_cron')");
}
echo "DocumentosVivosCronBloqueoTest OK\n";
