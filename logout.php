<?php

declare(strict_types=1);

require_once __DIR__ . '/api/Autenticacion.php';

SesionEmpresa::iniciar();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}
$token = (string) ($_POST['csrf'] ?? '');
if (!isset($_SESSION['logout_csrf']) || !hash_equals((string) $_SESSION['logout_csrf'], $token)) {
    http_response_code(419);
    exit('La solicitud expiró.');
}
Autenticacion::cerrarSesion();
header('Location: login.php?logout=1');
exit;
