<?php

declare(strict_types=1);

require_once __DIR__ . '/InventarioAdministrador.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    $administrador = new InventarioAdministrador(Conexion::obtener());
    $resultado = $administrador->listar(
        SesionEmpresa::empresaActual(),
        SesionEmpresa::claveActual(),
        filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT) ?: 1,
        filter_input(INPUT_GET, 'por_pagina', FILTER_VALIDATE_INT) ?: 10,
        (string) ($_GET['buscar'] ?? '')
    );
    foreach ($resultado['registros'] as &$registro) {
        unset($registro['ultimo_token_factura']);
    }
    unset($registro);

    echo json_encode([
        'ok' => true,
        'empresa' => SesionEmpresa::empresaActual(),
        'empresa_clave' => SesionEmpresa::claveActual(),
        'datos' => $resultado,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'mensaje' => 'No fue posible consultar el inventario.',
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
