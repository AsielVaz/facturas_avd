<?php

declare(strict_types=1);

$hostSolicitud = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'), 2)[0]);
$origenesPermitidos = [
    'localhost' => 'http://localhost',
    'facturacion14.com' => 'https://facturacion14.com',
];
$origen = trim((string) (getenv('PASSKEY_ORIGIN') ?: ($origenesPermitidos[$hostSolicitud] ?? '')));
$archivoSecreto = __DIR__ . DIRECTORY_SEPARATOR . 'passkeys_secret.local.php';
$secreto = getenv('PASSKEY_HANDLE_SECRET');
if ((!is_string($secreto) || $secreto === '') && is_readable($archivoSecreto)) {
    $secreto = require $archivoSecreto;
}

return [
    'origin' => $origen,
    'rp_id' => (string) (parse_url($origen, PHP_URL_HOST) ?: ''),
    'rp_name' => 'Sistema 14',
    'handle_secret' => is_string($secreto) ? $secreto : '',
    'max_credentials' => 10,
    'challenge_ttl' => 120,
    'enrollment_ttl' => 300,
];
