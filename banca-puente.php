<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Método no permitido.');
}

$cadena = $_POST['cadena_total'] ?? null;
if (!is_string($cadena) || $cadena === '' || strlen($cadena) > 4096
    || preg_match('/[\x00-\x20\x7F]/', $cadena) === 1) {
    http_response_code(400);
    exit('Falta una llave de acceso válida.');
}

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; frame-src https://banca.sistema14.com; form-action https://banca.sistema14.com; base-uri 'none'");
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accediendo a Banca</title>
    <style nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
        body { font: 16px Arial, sans-serif; padding: 25px; }
        iframe, form { display: none; }
    </style>
</head>
<body>
    <p id="estado" role="status">Iniciando sesión en Banca...</p>
    <form id="loginBanca" method="post" enctype="multipart/form-data"
          action="https://banca.sistema14.com/api/login_externo.php" target="respuestaBanca">
        <input type="hidden" name="cadena_total"
               value="<?= htmlspecialchars($cadena, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    </form>
    <script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
        (() => {
            const formulario = document.getElementById('loginBanca');
            const estado = document.getElementById('estado');
            const iframe = document.createElement('iframe');
            iframe.name = 'respuestaBanca';
            iframe.title = 'Respuesta del inicio de sesión en Banca';
            iframe.setAttribute('aria-hidden', 'true');
            iframe.src = 'about:blank';

            let fase = 0;
            const limite = setTimeout(() => {
                fase = 3;
                estado.textContent = 'No terminó el acceso a Banca. Cierra esta pestaña e inténtalo de nuevo.';
            }, 20000);

            iframe.onload = () => {
                if (fase === 3) return;
                if (fase === 0) {
                    fase = 1;
                    try {
                        formulario.submit();
                    } catch (error) {
                        clearTimeout(limite);
                        estado.textContent = 'No se pudo enviar el acceso a Banca. Inténtalo de nuevo.';
                    }
                    return;
                }
                if (fase === 1) {
                    fase = 2;
                    clearTimeout(limite);
                    // El JSON queda dentro del iframe, no en la pestaña visible.
                    window.location.replace('https://banca.sistema14.com/');
                }
            };

            document.body.appendChild(iframe);
        })();
    </script>
</body>
</html>
