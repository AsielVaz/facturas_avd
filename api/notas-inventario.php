<?php

declare(strict_types=1);

require_once __DIR__ . '/NotaInventarioAdministrador.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    $administrador = new NotaInventarioAdministrador(Conexion::obtener());
    $resultado = $administrador->listar(
        SesionEmpresa::empresaActual(),
        filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT) ?: 1,
        filter_input(INPUT_GET, 'por_pagina', FILTER_VALIDATE_INT) ?: 10,
        (string) ($_GET['buscar'] ?? '')
    );

    echo json_encode([
        'ok' => true,
        'empresa' => SesionEmpresa::empresaActual(),
        'datos' => $resultado,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No fue posible consultar las notas de inventario.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
