<?php

declare(strict_types=1);

require_once __DIR__ . '/BancoAdministrador.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    $administrador = new BancoAdministrador(Conexion::obtener());
    $empresa = SesionEmpresa::empresaActual();
    $bancoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

    if ($bancoId <= 0) {
        echo json_encode(['ok' => true, 'empresa' => $empresa, 'bancos' => $administrador->listarBancos($empresa)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    $banco = $administrador->obtenerBanco($bancoId, $empresa);
    if ($banco === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Cuenta bancaria no encontrada.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    $movimientos = $administrador->listarMovimientos(
        $bancoId,
        $empresa,
        filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT) ?: 1,
        filter_input(INPUT_GET, 'por_pagina', FILTER_VALIDATE_INT) ?: 15,
        (string) ($_GET['buscar'] ?? ''),
        (string) ($_GET['fecha'] ?? '')
    );
    echo json_encode(['ok' => true, 'empresa' => $empresa, 'banco' => $banco, 'datos' => $movimientos], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No fue posible consultar la informacion bancaria.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
