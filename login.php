<?php

declare(strict_types=1);

require_once __DIR__ . '/api/Autenticacion.php';

SesionEmpresa::iniciar();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$destino = Autenticacion::rutaSegura((string) ($_POST['next'] ?? $_GET['next'] ?? ''));
if (Autenticacion::autenticado()) {
    header('Location: ' . ($destino !== '' ? $destino : 'facturas.php'));
    exit;
}

$_SESSION['login_csrf'] ??= bin2hex(random_bytes(32));
$errorLogin = '';
$identificador = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $identificador = trim((string) ($_POST['identificador'] ?? ''));
    $token = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals((string) $_SESSION['login_csrf'], $token)) {
        $errorLogin = 'La solicitud expiró. Recarga la página e intenta nuevamente.';
    } else {
        try {
            Autenticacion::iniciarSesion(
                Conexion::obtener(),
                $identificador,
                (string) ($_POST['password'] ?? '')
            );
            unset($_SESSION['login_csrf']);
            header('Location: ' . ($destino !== '' ? $destino : 'facturas.php'));
            exit;
        } catch (AutenticacionException $error) {
            $errorLogin = $error->getMessage();
        } catch (Throwable $error) {
            error_log('Error de autenticación: ' . $error->getMessage());
            $errorLogin = 'No fue posible iniciar sesión en este momento. Intenta nuevamente.';
        }
    }
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Iniciar sesión | ERP Dinámico</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Acceso seguro al sistema de facturación">
    <link rel="shortcut icon" href="assets/images/logo-sm.png">
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css">
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css">
    <script src="assets/js/config.min.js"></script>
    <style>
        .login-shell { min-height: 100vh; background: radial-gradient(circle at top right, rgba(var(--bs-primary-rgb), .12), transparent 38%); }
        .login-card { max-width: 460px; width: 100%; }
        .login-logo { max-height: 44px; max-width: 190px; object-fit: contain; }
    </style>
</head>
<body>
<main class="login-shell d-flex align-items-center py-5">
    <div class="container">
        <div class="login-card mx-auto">
            <div class="card border-0 shadow-lg">
                <div class="card-body p-4 p-sm-5">
                    <div class="text-center mb-4">
                        <img src="assets/images/logo-dark.png" class="login-logo mb-4 logo-dark" alt="ERP Dinámico">
                        <img src="assets/images/logo-white.png" class="login-logo mb-4 logo-light" alt="ERP Dinámico">
                        <h1 class="h4 fw-bold mb-2">Iniciar sesión</h1>
                        <p class="text-muted mb-0">Ingresa con tu usuario o correo electrónico.</p>
                    </div>

                    <?php if ($errorLogin !== ''): ?>
                        <div class="alert alert-danger" role="alert"><i data-lucide="circle-alert" class="fs-17 me-1"></i><?= htmlspecialchars($errorLogin) ?></div>
                    <?php elseif (($_GET['logout'] ?? '') === '1'): ?>
                        <div class="alert alert-success" role="status"><i data-lucide="circle-check" class="fs-17 me-1"></i>La sesión se cerró correctamente.</div>
                    <?php endif; ?>

                    <form method="post" autocomplete="on">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars((string) $_SESSION['login_csrf']) ?>">
                        <input type="hidden" name="next" value="<?= htmlspecialchars($destino) ?>">
                        <div class="mb-3">
                            <label for="identificador" class="form-label">Usuario o correo</label>
                            <input id="identificador" name="identificador" type="text" class="form-control form-control-lg" maxlength="190" autocomplete="username" value="<?= htmlspecialchars($identificador) ?>" required autofocus>
                        </div>
                        <div class="mb-4">
                            <label for="password" class="form-label">Contraseña</label>
                            <div class="input-group input-group-lg">
                                <input id="password" name="password" type="password" class="form-control" maxlength="4096" autocomplete="current-password" required>
                                <button id="togglePassword" class="btn btn-outline-secondary" type="button" aria-label="Mostrar contraseña"><i data-lucide="eye" class="fs-18"></i></button>
                            </div>
                        </div>
                        <div class="d-grid">
                            <button class="btn btn-primary btn-lg" type="submit"><i data-lucide="log-in" class="fs-18 me-1"></i>Entrar</button>
                        </div>
                    </form>
                </div>
            </div>
            <p class="text-center text-muted small mt-4 mb-0">Acceso exclusivo para usuarios autorizados.</p>
        </div>
    </div>
</main>
<script src="assets/js/vendor.js"></script>
<script src="assets/js/app.js"></script>
<script>
document.getElementById('togglePassword').addEventListener('click', function () {
    const input = document.getElementById('password');
    const visible = input.type === 'text';
    input.type = visible ? 'password' : 'text';
    this.setAttribute('aria-label', visible ? 'Mostrar contraseña' : 'Ocultar contraseña');
    this.innerHTML = '<i data-lucide="' + (visible ? 'eye' : 'eye-off') + '" class="fs-18"></i>';
    if (window.lucide) window.lucide.createIcons();
});
</script>
</body>
</html>
