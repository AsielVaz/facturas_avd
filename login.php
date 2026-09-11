<?php

declare(strict_types=1);

require_once __DIR__ . '/api/Autenticacion.php';

SesionEmpresa::iniciar();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$destino = Autenticacion::rutaSegura((string) ($_POST['next'] ?? $_GET['next'] ?? ($_SESSION['login_2fa_next'] ?? '')));
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
    $accion = (string) ($_POST['accion'] ?? 'credenciales');
    if (!hash_equals((string) $_SESSION['login_csrf'], $token)) {
        $errorLogin = 'La solicitud expiró. Recarga la página e intenta nuevamente.';
    } elseif ($accion === 'cancelar_2fa') {
        Autenticacion::cancelarSegundoFactor();
        unset($_SESSION['login_2fa_next']);
        $identificador = '';
    } else {
        try {
            if ($accion === 'verificar_2fa') {
                Autenticacion::verificarSegundoFactor(
                    Conexion::obtener(),
                    (string) ($_POST['codigo_2fa'] ?? '')
                );
                $destino = Autenticacion::rutaSegura((string) ($_SESSION['login_2fa_next'] ?? $destino));
                unset($_SESSION['login_csrf'], $_SESSION['login_2fa_next']);
                header('Location: ' . ($destino !== '' ? $destino : 'facturas.php'));
                exit;
            }

            $sesionCompleta = Autenticacion::iniciarSesion(
                Conexion::obtener(),
                $identificador,
                (string) ($_POST['password'] ?? '')
            );
            if ($sesionCompleta) {
                unset($_SESSION['login_csrf'], $_SESSION['login_2fa_next']);
                header('Location: ' . ($destino !== '' ? $destino : 'facturas.php'));
                exit;
            }
            $_SESSION['login_2fa_next'] = $destino;
        } catch (AutenticacionException $error) {
            $errorLogin = $error->getMessage();
        } catch (Throwable $error) {
            $referencia = 'LOGIN-' . strtoupper(substr(hash('sha256', microtime(true) . '|' . random_bytes(16)), 0, 10));
            error_log(sprintf(
                '[%s] Error de autenticación (%s, código %s): %s en %s:%d',
                $referencia,
                get_class($error),
                (string) $error->getCode(),
                $error->getMessage(),
                $error->getFile(),
                $error->getLine()
            ));
            $errorLogin = 'No fue posible iniciar sesión por un problema interno. Referencia: ' . $referencia . '.';
        }
    }
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}
$requiereSegundoFactor = Autenticacion::segundoFactorPendiente();
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
        .login-card { max-width: 480px; width: 100%; }
        .login-brand { width: min(270px, 78%); height: 108px; object-fit: contain; filter: drop-shadow(0 10px 20px rgba(0,0,0,.14)); }
        .login-card .card-body { position: relative; overflow: hidden; }
        .login-card .card-body::before { content: ''; position: absolute; width: 180px; height: 180px; right: -90px; top: -95px; border-radius: 50%; background: rgba(var(--bs-primary-rgb), .08); pointer-events: none; }
        .otp-input { min-height: 62px; text-align: center; font-size: 1.65rem; font-weight: 700; letter-spacing: .55rem; padding-left: calc(.55rem + .75rem); font-variant-numeric: tabular-nums; }
    </style>
</head>
<body>
<main class="login-shell d-flex align-items-center py-5">
    <div class="container">
        <div class="login-card mx-auto">
            <div class="card border-0 shadow-lg">
                <div class="card-body p-4 p-sm-5">
                    <div class="text-center mb-4">
                        <?php if ($requiereSegundoFactor): ?>
                            <form method="post" class="text-start mb-2">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars((string) $_SESSION['login_csrf']) ?>">
                                <input type="hidden" name="next" value="<?= htmlspecialchars($destino) ?>">
                                <button class="btn btn-link text-muted p-0 text-decoration-none" type="submit" name="accion" value="cancelar_2fa"><i data-lucide="arrow-left" class="fs-16 me-1"></i>Volver</button>
                            </form>
                        <?php endif; ?>
                        <img src="assets/images/logo-login-sh.png" class="login-brand mb-3" alt="ERP Dinámico">
                        <h1 class="h4 fw-bold mb-2"><?= $requiereSegundoFactor ? 'Verificación en dos pasos' : 'Bienvenido de nuevo' ?></h1>
                        <p class="text-muted mb-0"><?= $requiereSegundoFactor ? 'Ingresa el código de 6 dígitos de tu aplicación autenticadora.' : 'Ingresa tus credenciales para continuar.' ?></p>
                    </div>

                    <?php if ($errorLogin !== ''): ?>
                        <div class="alert alert-danger" role="alert"><i data-lucide="circle-alert" class="fs-17 me-1"></i><?= htmlspecialchars($errorLogin) ?></div>
                    <?php elseif (($_GET['logout'] ?? '') === '1'): ?>
                        <div class="alert alert-success" role="status"><i data-lucide="circle-check" class="fs-17 me-1"></i>La sesión se cerró correctamente.</div>
                    <?php endif; ?>

                    <?php if ($requiereSegundoFactor): ?>
                        <form method="post" autocomplete="off">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars((string) $_SESSION['login_csrf']) ?>">
                            <input type="hidden" name="next" value="<?= htmlspecialchars($destino) ?>">
                            <div class="mb-4">
                                <label for="codigo_2fa" class="form-label">Código de verificación</label>
                                <input id="codigo_2fa" name="codigo_2fa" type="text" class="form-control otp-input" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" aria-describedby="ayudaCodigo" required autofocus>
                                <div id="ayudaCodigo" class="form-text mt-2">El código cambia cada 30 segundos.</div>
                            </div>
                            <div class="d-grid gap-2">
                                <button class="btn btn-primary btn-lg" type="submit" name="accion" value="verificar_2fa"><i data-lucide="shield-check" class="fs-18 me-1"></i>Verificar y entrar</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <form method="post" autocomplete="on">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars((string) $_SESSION['login_csrf']) ?>">
                            <input type="hidden" name="next" value="<?= htmlspecialchars($destino) ?>">
                            <input type="hidden" name="accion" value="credenciales">
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
                                <button class="btn btn-primary btn-lg" type="submit">Continuar<i data-lucide="arrow-right" class="fs-18 ms-2"></i></button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <p class="text-center text-muted small mt-4 mb-0">Acceso exclusivo para usuarios autorizados.</p>
        </div>
    </div>
</main>
<script src="assets/js/vendor.js"></script>
<script src="assets/js/app.js"></script>
<script>
const togglePassword = document.getElementById('togglePassword');
if (togglePassword) togglePassword.addEventListener('click', function () {
    const input = document.getElementById('password');
    const visible = input.type === 'text';
    input.type = visible ? 'password' : 'text';
    this.setAttribute('aria-label', visible ? 'Mostrar contraseña' : 'Ocultar contraseña');
    this.innerHTML = '<i data-lucide="' + (visible ? 'eye' : 'eye-off') + '" class="fs-18"></i>';
    if (window.lucide) window.lucide.createIcons();
});
const otpInput = document.getElementById('codigo_2fa');
if (otpInput) otpInput.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 6); });
</script>
</body>
</html>
