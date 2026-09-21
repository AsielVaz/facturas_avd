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
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <title>Iniciar sesión | ERP Dinámico</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Acceso seguro al sistema de facturación">
    <link rel="icon" type="image/jpeg" href="assets/images/logo.jpg?v=3">
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css">
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css">
    <script src="assets/js/config.min.js"></script>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #191c1f; color: #f3eee9; }
        .login-shell {
            position: relative;
            isolation: isolate;
            min-height: 100vh;
            overflow: hidden;
            background: #191c1f;
        }
        .login-shell::before,
        .login-shell::after {
            content: '';
            position: absolute;
            z-index: -1;
            border-radius: 50%;
            pointer-events: none;
            filter: blur(22px);
        }
        .login-shell::before {
            width: min(780px, 65vw);
            height: min(780px, 76vw);
            left: -230px;
            top: 70px;
            background: radial-gradient(circle, rgba(126, 48, 4, .82) 0%, rgba(91, 34, 3, .48) 38%, transparent 72%);
        }
        .login-shell::after {
            width: min(560px, 52vw);
            height: min(560px, 52vw);
            right: -120px;
            bottom: -210px;
            background: radial-gradient(circle, rgba(112, 72, 24, .42) 0%, rgba(75, 50, 22, .2) 42%, transparent 72%);
        }
        .login-card { width: min(420px, calc(100vw - 32px)); }
        .login-panel {
            background: linear-gradient(145deg, rgba(48, 29, 20, .95), rgba(37, 28, 24, .94));
            border: 1px solid rgba(255, 138, 42, .04);
            border-radius: 24px;
            box-shadow: 0 28px 62px rgba(0, 0, 0, .42), 0 0 42px rgba(227, 91, 0, .05);
        }
        .login-panel .card-body { padding: 42px 40px 32px; }
        .login-brand-wrap { text-align: center; margin-bottom: 32px; }
        .login-brand {
            display: inline-block;
            width: 145px;
            height: 64px;
            object-fit: contain;
            filter: drop-shadow(0 7px 15px rgba(232, 100, 20, .18));
        }
        .login-heading { color: #f4eee8; font-size: 1.2rem; letter-spacing: -.02em; }
        .login-subtitle { color: #8d7c72; font-size: .84rem; }
        .login-label {
            color: #9c8b81;
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: .025em;
            text-transform: uppercase;
        }
        .login-control { position: relative; }
        .login-control > svg {
            position: absolute;
            z-index: 3;
            left: 14px;
            top: 50%;
            width: 17px;
            height: 17px;
            color: #817a80;
            transform: translateY(-50%);
            pointer-events: none;
        }
        .login-control .form-control {
            min-height: 48px;
            padding: .7rem 46px .7rem 42px;
            color: #f7f2ee;
            background: #3a373f;
            border: 1px solid transparent;
            border-radius: 13px !important;
            box-shadow: none;
        }
        .login-control .form-control::placeholder { color: #746e73; }
        .login-control .form-control:focus {
            background: #3d3941;
            border-color: #d66a0b;
            box-shadow: 0 0 0 3px rgba(214, 106, 11, .15), 0 0 22px rgba(214, 86, 0, .09);
        }
        .password-toggle {
            position: absolute;
            z-index: 4;
            top: 50%;
            right: 7px;
            width: 36px;
            height: 36px;
            padding: 0;
            color: #938c91;
            background: transparent;
            border: 0;
            border-radius: 9px;
            transform: translateY(-50%);
        }
        .password-toggle:hover,
        .password-toggle:focus { color: #f08a27; background: rgba(255, 255, 255, .04); }
        .login-submit {
            min-height: 48px;
            color: #fff;
            font-weight: 700;
            background: linear-gradient(90deg, #cb4c00, #dc7900);
            border: 0;
            border-radius: 13px;
            box-shadow: 0 9px 22px rgba(207, 82, 0, .24);
        }
        .login-submit:hover,
        .login-submit:focus {
            color: #fff;
            background: linear-gradient(90deg, #dc5700, #ed8b08);
            box-shadow: 0 11px 26px rgba(224, 91, 0, .32);
        }
        .login-back { color: #9c8b81; font-size: .82rem; }
        .login-back:hover { color: #e98729; }
        .login-footer { color: #64564f; font-size: .72rem; }
        .login-panel .alert { color: #f5e9e2; background: rgba(113, 42, 30, .42); border-color: rgba(238, 91, 55, .3); }
        .login-panel .alert-success { background: rgba(25, 94, 57, .4); border-color: rgba(41, 190, 104, .25); }
        .otp-input {
            min-height: 54px !important;
            padding-left: 42px !important;
            text-align: center;
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: .52rem;
            font-variant-numeric: tabular-nums;
            border-color: #a34b00 !important;
            background: rgba(53, 31, 22, .78) !important;
        }
        @media (max-width: 575.98px) {
            .login-panel .card-body { padding: 34px 24px 26px; }
            .login-brand-wrap { margin-bottom: 26px; }
        }
    </style>
</head>
<body>
<main class="login-shell d-flex align-items-center justify-content-center py-4">
    <div class="login-card">
        <div class="card login-panel border-0">
            <div class="card-body">
                <div class="login-brand-wrap">
                    <img src="assets/images/logo-login-sh.png" class="login-brand" alt="Sistema 14">
                </div>

                <?php if ($requiereSegundoFactor): ?>
                    <form method="post" class="mb-4">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars((string) $_SESSION['login_csrf']) ?>">
                        <input type="hidden" name="next" value="<?= htmlspecialchars($destino) ?>">
                        <button class="btn btn-link login-back p-0 text-decoration-none" type="submit" name="accion" value="cancelar_2fa"><i data-lucide="arrow-left" class="fs-15 me-1"></i>Volver</button>
                    </form>
                <?php endif; ?>

                <div class="mb-4">
                    <h1 class="login-heading fw-bold mb-2"><?= $requiereSegundoFactor ? 'Verificación en dos pasos' : 'Bienvenido de nuevo' ?></h1>
                    <p class="login-subtitle mb-0"><?= $requiereSegundoFactor ? 'Ingresa el código de 6 dígitos de tu app.' : 'Ingresa tus credenciales para continuar.' ?></p>
                </div>

                <?php if ($errorLogin !== ''): ?>
                    <div class="alert alert-danger py-2 small" role="alert"><i data-lucide="circle-alert" class="fs-16 me-1"></i><?= htmlspecialchars($errorLogin) ?></div>
                <?php elseif (($_GET['logout'] ?? '') === '1'): ?>
                    <div class="alert alert-success py-2 small" role="status"><i data-lucide="circle-check" class="fs-16 me-1"></i>La sesión se cerró correctamente.</div>
                <?php endif; ?>

                <?php if ($requiereSegundoFactor): ?>
                    <form method="post" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars((string) $_SESSION['login_csrf']) ?>">
                        <input type="hidden" name="next" value="<?= htmlspecialchars($destino) ?>">
                        <div class="mb-4">
                            <label for="codigo_2fa" class="form-label login-label">Código 2FA</label>
                            <div class="login-control">
                                <i data-lucide="shield"></i>
                                <input id="codigo_2fa" name="codigo_2fa" type="text" class="form-control otp-input" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" required autofocus>
                            </div>
                        </div>
                        <div class="d-grid">
                            <button class="btn login-submit" type="submit" name="accion" value="verificar_2fa">Verificar y entrar</button>
                        </div>
                    </form>
                <?php else: ?>
                    <form method="post" autocomplete="on">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars((string) $_SESSION['login_csrf']) ?>">
                        <input type="hidden" name="next" value="<?= htmlspecialchars($destino) ?>">
                        <input type="hidden" name="accion" value="credenciales">
                        <div class="mb-3">
                            <label for="identificador" class="form-label login-label">Correo electrónico o usuario</label>
                            <div class="login-control">
                                <i data-lucide="mail"></i>
                                <input id="identificador" name="identificador" type="text" class="form-control" maxlength="190" autocomplete="username" value="<?= htmlspecialchars($identificador) ?>" required autofocus>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label for="password" class="form-label login-label">Contraseña</label>
                            <div class="login-control">
                                <i data-lucide="lock-keyhole"></i>
                                <input id="password" name="password" type="password" class="form-control" maxlength="4096" autocomplete="current-password" required>
                                <button id="togglePassword" class="password-toggle" type="button" aria-label="Mostrar contraseña"><i data-lucide="eye" class="fs-17"></i></button>
                            </div>
                        </div>
                        <div class="d-grid">
                            <button class="btn login-submit" type="submit">Continuar <span class="ms-1">→</span></button>
                        </div>
                    </form>
                <?php endif; ?>

                <p class="login-footer text-center mt-4 mb-0">Copyright © <?= date('Y') ?> Sistema 14. Todos los derechos reservados.</p>
            </div>
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
