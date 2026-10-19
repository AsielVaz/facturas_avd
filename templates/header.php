<?php
// Mantenemos la ruta del logo de la empresa
$sidebarLogo = 'assets/images/logo.jpg';
$cadenaBanca = $_SESSION['cadena_total'] ?? null;
$bancaDisponible = is_string($cadenaBanca) && $cadenaBanca !== ''
    && strlen($cadenaBanca) <= 4096 && preg_match('/[\x00-\x20\x7F]/', $cadenaBanca) !== 1;
$mostrarBotonBanca = is_array($_SESSION['uso_sistemas'] ?? null)
    && (int) ($_SESSION['uso_sistemas'][1] ?? 0) === 1;

$usoSistemaSesion = array_key_exists('uso_sistemas', $_SESSION)
    ? var_export($_SESSION['uso_sistemas'], true)
    : '(no definida)';
?>
<header class="topbar d-flex align-items-center">
    <div class="container-fluid">
        <div class="navbar-header d-flex align-items-center justify-content-between w-100">
            <!-- Lado Izquierdo: Logo con mayor tamaño -->
            <div class="d-flex align-items-center gap-3">
                <a href="facturas.php" class="navbar-brand d-flex align-items-center py-0">
                    <img src="<?= htmlspecialchars($sidebarLogo) ?>" class="erp-company-logo rounded"
                        alt="Logo del sistema" style="height: 48px; width: auto; object-fit: contain;">
                </a>
                <?php if ($mostrarBotonBanca): ?>
                <form id="formLoginBanca" action="https://facturas.sistema14.com/banca-puente.php" method="post"
                    target="_blank" class="m-0">
                    <?php if ($bancaDisponible): ?>
                        <input type="hidden" name="cadena_total"
                            value="<?= htmlspecialchars($cadenaBanca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <?php endif; ?>
                    <button type="submit" class="border-0 bg-transparent p-0 d-flex align-items-center"
                        aria-label="Ir a Banca">
                        <img src="assets/images/banca_logo.png" class="rounded" alt=""
                            style="height: 48px; width: 48px; object-fit: contain; background: #fff; padding: 2px;">
                    </button>
                </form>
                <?php endif; ?>
                
                <script>
                    // Se llama directamente desde onclick del botón Banca.
                    // POST tradicional: no depende de fetch, AJAX ni permisos CORS.
                    function abrirBanca(boton) {
                        console.log('[Banca] Inicio de ejecución de abrirBanca()');

                        if (boton.disabled) {
                            console.warn('[Banca] El botón ya está deshabilitado. Se detiene la ejecución.');
                            return false;
                        }

                        // Recuperamos el valor de la cadena total directamente de la sesión PHP para validarla en consola
                        var cadenaTotal = <?php echo json_encode(
                            isset($_SESSION['cadena_total']) ? (string) $_SESSION['cadena_total'] : '',
                            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                        ); ?>;

                        if (!cadenaTotal) {
                            console.error('[Banca] No se encontró la variable cadena_total en la sesión PHP.');
                        }

                        // Abrir la pestaña en el clic permite que el navegador no la bloquee.
                        var nuevaPestana = window.open('about:blank', '_blank');

                        if (!nuevaPestana) {
                            console.error('[Banca] El navegador bloqueó la ventana emergente.');
                            alert('Tu navegador bloqueó la pestaña de Banca. Permite ventanas emergentes para este sitio.');
                            return false;
                        }
                        console.log('[Banca] Pestaña emergente abierta correctamente en blanco.');

                        try {
                            nuevaPestana.opener = null;
                            nuevaPestana.document.title = 'Accediendo a Banca';
                            nuevaPestana.document.body.style.cssText = 'font:16px Arial,sans-serif;padding:25px;';
                            nuevaPestana.document.body.textContent = 'Iniciando sesión en Banca...';
                            console.log('[Banca] Título y contenido inicial asignados a la nueva pestaña.');
                        } catch (e) {
                            console.warn('[Banca] No se pudo modificar el DOM de la nueva pestaña (posible restricción):', e);
                        }

                        boton.disabled = true;
                        boton.setAttribute('aria-busy', 'true');

                        // El formulario se envía a un iframe oculto. Cuando termina de cargar,
                        // la pestaña nueva abre la página principal de Banca.
                        var iframe = document.createElement('iframe');
                        var nombreFrame = 's14LoginBanca_' + Date.now();
                        iframe.name = nombreFrame;
                        iframe.title = 'Inicio de sesión en Banca';
                        iframe.style.display = 'none';
                        iframe.setAttribute('aria-hidden', 'true');
                        iframe.src = 'about:blank';

                        var formulario = document.createElement('form');
                        formulario.method = 'POST';
                        formulario.action = 'https://banca.sistema14.com/api/login_externo.php';
                        formulario.target = nombreFrame;
                        formulario.style.display = 'none';
                        formulario.acceptCharset = 'UTF-8';

                        var campo = document.createElement('input');
                        campo.type = 'hidden';
                        campo.name = 'cadena_total';
                        campo.value = cadenaTotal;
                        formulario.appendChild(campo);

                        console.log('[Banca] Formulario e iframe oculto creados con la acción:', formulario.action);

                        var fase = 0;
                        var terminado = false;
                        var timeoutId;

                        function limpiar() {
                            if (terminado) return;
                            terminado = true;
                            clearTimeout(timeoutId);
                            boton.disabled = false;
                            boton.removeAttribute('aria-busy');
                            if (formulario.parentNode) formulario.parentNode.removeChild(formulario);
                            if (iframe.parentNode) iframe.parentNode.removeChild(iframe);
                            console.log('[Banca] Limpieza de elementos temporales ejecutada.');
                        }

                        iframe.onload = function () {
                            if (terminado) return;

                            if (fase === 0) {
                                // Primera carga: about:blank. Ahora enviamos el POST real.
                                fase = 1;
                                console.log('[Banca] Iframe cargado en about:blank (Fase 0). Enviando formulario POST...');
                                try {
                                    formulario.submit();
                                    console.log('[Banca] Formulario enviado exitosamente al iframe.');
                                } catch (error) {
                                    console.error('[Banca] Error al enviar el formulario a Banca:', error);
                                    limpiar();
                                    try { nuevaPestana.close(); } catch (e) { }
                                    alert('No fue posible enviar el inicio de sesión a Banca.');
                                }
                                return;
                            }

                            if (fase === 1) {
                                // La petición POST terminó de navegar en el iframe.
                                // Por políticas entre orígenes, no se puede validar la respuesta.
                                console.log('[Banca] Iframe completó la carga del POST (Fase 1). Redirigiendo pestaña principal...');
                                limpiar();

                                if (!nuevaPestana.closed) {
                                    nuevaPestana.location.replace('https://banca.sistema14.com/');
                                    console.log('[Banca] Redirección aplicada a https://banca.sistema14.com/');
                                } else {
                                    console.warn('[Banca] La pestaña nueva fue cerrada por el usuario antes de redirigir.');
                                }
                            }
                        };

                        document.body.appendChild(formulario);
                        document.body.appendChild(iframe);

                        // Evitar que el botón quede bloqueado indefinidamente si no carga el iframe.
                        timeoutId = setTimeout(function () {
                            if (terminado) return;
                            console.warn('[Banca] Tiempo de espera agotado (Timeout de 20s) para el iframe de Banca.');
                            limpiar();
                            try { nuevaPestana.close(); } catch (e) { }
                            alert('No se pudo confirmar la carga del acceso a Banca. Vuelve a intentarlo.');
                        }, 20000);

                        return false;
                    }

                    // Facturación: envía primero el POST de autenticación y después abre el inicio.
                    // La API actual responde JSON, por lo que el POST se procesa en un iframe oculto.
                    function abrirFacturacion(boton) {
                        console.log('[Facturación] Inicio de ejecución de abrirFacturacion()');

                        if (boton.disabled) {
                            console.warn('[Facturación] El botón ya está deshabilitado. Se detiene la ejecución.');
                            return false;
                        }

                        var cadenaTotal = <?php echo json_encode(
                            isset($_SESSION['cadena_total']) ? (string) $_SESSION['cadena_total'] : '',
                            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                        ); ?>;

                        if (!cadenaTotal) {
                            console.error('[Facturación] No se encontró la variable cadena_total en la sesión PHP.');
                            alert('No se encontró cadena_total en la sesión. Inicia sesión de nuevo.');
                            return false;
                        }

                        // Debe abrirse durante el clic para evitar bloqueos de ventanas emergentes.
                        var nuevaPestana = window.open('about:blank', '_blank');
                        if (!nuevaPestana) {
                            console.error('[Facturación] El navegador bloqueó la ventana emergente.');
                            alert('Tu navegador bloqueó la pestaña de Facturación. Permite ventanas emergentes para este sitio.');
                            return false;
                        }
                        console.log('[Facturación] Pestaña emergente abierta correctamente en blanco.');

                        try {
                            nuevaPestana.opener = null;
                            nuevaPestana.document.title = 'Accediendo a Facturación';
                            nuevaPestana.document.body.style.cssText = 'font:16px Arial,sans-serif;padding:25px;';
                            nuevaPestana.document.body.textContent = 'Iniciando sesión en Facturación...';
                            console.log('[Facturación] Título y contenido inicial asignados a la nueva pestaña.');
                        } catch (e) {
                            console.warn('[Facturación] No se pudo modificar el DOM de la nueva pestaña:', e);
                        }

                        boton.disabled = true;
                        boton.setAttribute('aria-busy', 'true');

                        var iframe = document.createElement('iframe');
                        var nombreFrame = 's14LoginFacturacion_' + Date.now();
                        iframe.name = nombreFrame;
                        iframe.title = 'Inicio de sesión en Facturación';
                        iframe.style.display = 'none';
                        iframe.setAttribute('aria-hidden', 'true');
                        iframe.src = 'about:blank';

                        var formulario = document.createElement('form');
                        formulario.method = 'POST';
                        formulario.action = 'https://facturas.sistema14.com/login_externo.php';
                        formulario.target = nombreFrame;
                        formulario.style.display = 'none';
                        formulario.acceptCharset = 'UTF-8';

                        var campo = document.createElement('input');
                        campo.type = 'hidden';
                        campo.name = 'cadena_total';
                        campo.value = cadenaTotal;
                        formulario.appendChild(campo);

                        console.log('[Facturación] Formulario e iframe oculto creados con la acción:', formulario.action);

                        var fase = 0;
                        var terminado = false;
                        var timeoutId;

                        function limpiar() {
                            if (terminado) return;
                            terminado = true;
                            clearTimeout(timeoutId);
                            boton.disabled = false;
                            boton.removeAttribute('aria-busy');
                            if (formulario.parentNode) formulario.parentNode.removeChild(formulario);
                            if (iframe.parentNode) iframe.parentNode.removeChild(iframe);
                            console.log('[Facturación] Limpieza de elementos temporales ejecutada.');
                        }

                        iframe.onload = function () {
                            if (terminado) return;

                            if (fase === 0) {
                                // Primera carga about:blank: enviamos el login por POST.
                                fase = 1;
                                console.log('[Facturación] Iframe cargado en about:blank (Fase 0). Enviando formulario POST...');
                                try {
                                    formulario.submit();
                                    console.log('[Facturación] Formulario enviado exitosamente al iframe.');
                                } catch (error) {
                                    console.error('[Facturación] Error al enviar el login de Facturación:', error);
                                    limpiar();
                                    try { nuevaPestana.close(); } catch (e) { }
                                    alert('No fue posible enviar el inicio de sesión a Facturación.');
                                }
                                return;
                            }

                            if (fase === 1) {
                                // Terminó de cargar el endpoint. No se puede leer su respuesta
                                // por ser otro origen; la validación de sesión corresponde al destino.
                                console.log('[Facturación] Iframe completó la carga del POST (Fase 1). Redirigiendo pestaña principal...');
                                limpiar();

                                if (!nuevaPestana.closed) {
                                    nuevaPestana.location.replace('https://facturas.sistema14.com/');
                                    console.log('[Facturación] Redirección aplicada a https://facturas.sistema14.com/');
                                } else {
                                    console.warn('[Facturación] La pestaña nueva fue cerrada por el usuario antes de redirigir.');
                                }
                            }
                        };

                        document.body.appendChild(formulario);
                        document.body.appendChild(iframe);

                        timeoutId = setTimeout(function () {
                            if (terminado) return;
                            console.warn('[Facturación] Tiempo de espera agotado (Timeout de 20s) para el iframe de Facturación.');
                            limpiar();
                            try { nuevaPestana.close(); } catch (e) { }
                            alert('No se pudo confirmar la carga del acceso a Facturación. Vuelve a intentarlo.');
                        }, 20000);

                        return false;
                    }
                </script>

                <style>
                    #cornerLoader {

                        /* //esquina izquierda */

                        position: fixed;

                        bottom: 20px;

                        right: 20px;

                        width: 60px;

                        height: 60px;

                        background: rgba(255, 255, 255, 0.9);

                        border-radius: 50%;

                        display: flex;

                        /* visible por defecto */

                        align-items: center;

                        justify-content: center;

                        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);

                        z-index: 99999;

                    }

                    #cornerLoader img {

                        width: 40px;

                        height: 40px;

                        animation: spin 1s linear infinite;

                    }

                    @keyframes spin {

                        0% {

                            transform: rotate(0deg);

                        }

                        100% {

                            transform: rotate(360deg);

                        }

                    }

                    .loader {

                        --c: no-repeat linear-gradient(orange 0 0);

                        background:

                            var(--c), var(--c), var(--c),

                            var(--c), var(--c), var(--c),

                            var(--c), var(--c), var(--c);

                        background-size: 16px 16px;

                        animation:

                            l32-1 1s infinite,

                            l32-2 1s infinite;

                    }

                    @keyframes l32-1 {

                        0%,

                        100% {

                            width: 45px;

                            height: 45px
                        }

                        35%,

                        65% {

                            width: 65px;

                            height: 65px
                        }

                    }

                    @keyframes l32-2 {

                        0%,

                        40% {

                            background-position: 0 0, 0 50%, 0 100%, 50% 100%, 100% 100%, 100% 50%, 100% 0, 50% 0, 50% 50%
                        }

                        60%,

                        100% {

                            background-position: 0 50%, 0 100%, 50% 100%, 100% 100%, 100% 50%, 100% 0, 50% 0, 0 0, 50% 50%
                        }

                    }

                    .loader-overlay {

                        position: fixed;

                        top: 0;

                        left: 0;

                        width: 100%;

                        height: 100%;

                        background: #fff;

                        /* fondo blanco */

                        display: flex;

                        justify-content: center;

                        align-items: center;

                        z-index: 9999;

                        /* transición para el desvanecimiento */

                        opacity: 1;

                        visibility: visible;

                        transition: opacity 0.5s ease, visibility 0.5s ease;

                    }

                    .loader-overlay.hide {

                        opacity: 0;

                        visibility: hidden;

                    }

                    /*

  He añadido 'margin: 10px;' a la clase .custom-button

  para separarlo de otros elementos (incluidos otros botones).

*/

                    .custom-button {

                        /* Variables */

                        --button_radius: 0.75em;

                        --button_color: #e8e8e8;

                        --button_outline_color: #000000;

                        font-size: 17px;

                        font-weight: bold;

                        border: none;

                        cursor: pointer;

                        border-radius: var(--button_radius);

                        background: var(--button_outline_color);

                        /* ¡Nuevo! Agrega un margen de 10px a todos lados para separarlos */

                        margin: 10px;

                        display: inline-block;

                    }

                    .custom-button-top {

                        color: #ffffff;

                        display: block;

                        white-space: nowrap;

                        width: fit-content;

                        box-sizing: border-box;

                        border: 2px solid var(--button_outline_color);

                        border-radius: var(--button_radius);

                        padding: 0.75em 1.5em;

                        background: var(--button_color);

                        transform: translateY(-0.2em);

                        transition: transform 0.1s ease;

                    }

                    /* Hover y Active se mantienen igual */

                    .custom-button:hover .custom-button-top {

                        transform: translateY(-0.33em);

                    }

                    .custom-button:active .custom-button-top {

                        transform: translateY(0);

                    }
                </style>
            </div>

            <!-- Lado Derecho: Elementos de usuario, notificaciones y tema -->
            <div class="d-flex align-items-center gap-2 ms-auto">
                <div class="topbar-item d-none d-sm-block">
                    <a href="empresas-s.php" class="btn btn-sm btn-soft-primary text-nowrap"
                        title="Cambiar empresa actual">
                        <i data-lucide="building-2"
                            class="fs-16 me-1"></i><?= htmlspecialchars(SesionEmpresa::nombreActual()) ?>
                    </a>
                </div>
                <div class="topbar-item">
                    <button type="button" class="topbar-button fs-24" id="light-dark-mode" aria-label="Cambiar tema">
                        <i data-lucide="moon" class="light-mode"></i>
                        <i data-lucide="sun" class="dark-mode"></i>
                    </button>
                </div>
                <?php /* Notificaciones reservadas para una futura implementacion.
<div class="dropdown topbar-item">
<button type="button" class="topbar-button" data-bs-toggle="dropdown" aria-expanded="false"
aria-label="Notificaciones">
<i data-lucide="bell"></i><span class="topbar-badge text-bg-danger rounded-pill">3</span>
</button>
<div class="dropdown-menu dropdown-menu-end dropdown-lg p-0">
<div class="p-3 border-bottom">
<h6 class="m-0 fs-16 fw-semibold">Notificaciones</h6>
</div>
<div class="p-2">
<a href="facturas-pendientes.php" class="dropdown-item py-2 text-wrap"><span
class="badge bg-warning-subtle text-warning me-2">Factura</span> 4 facturas vencen
esta semana.</a>
<a href="inventario.php" class="dropdown-item py-2 text-wrap"><span
class="badge bg-danger-subtle text-danger me-2">Stock</span> 7 productos requieren
reposicion.</a>
<a href="banco-1.php" class="dropdown-item py-2 text-wrap"><span
class="badge bg-success-subtle text-success me-2">Banco</span> Se recibio un
deposito por $48,250.</a>
</div>
</div>
</div>
*/ ?>
                <div class="dropdown topbar-item">
                    <a class="topbar-button p-0" href="#" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="d-flex align-items-center gap-2">
                            <span
                                class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center flex-shrink-0"
                                style="width:32px;height:32px" aria-hidden="true"><i data-lucide="user-round"
                                    class="fs-18"></i></span>
                            <span class="d-lg-flex flex-column d-none"><span
                                    class="text-reset fs-14 fw-medium"><?= htmlspecialchars(Autenticacion::nombreActual()) ?></span><small
                                    class="text-muted"><?= htmlspecialchars(Autenticacion::tipoActual()) ?></small></span>
                        </span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <a class="dropdown-item" href="empresas-s.php"><i data-lucide="building-2"
                                class="fs-16 text-muted align-middle me-2"></i>Cambiar empresa</a>
                        <a class="dropdown-item" href="complementos-pago.php"><i data-lucide="receipt-text"
                                class="fs-16 text-muted align-middle me-2"></i>Complementos de pago</a>
                        <?php if (Autenticacion::puedeAdministrarClientes()): ?>
                            <a class="dropdown-item" href="administrar-clientes.php"><i data-lucide="users-round"
                                    class="fs-16 text-muted align-middle me-2"></i>Administrar clientes</a>
                        <?php endif; ?>
                        <div class="dropdown-divider"></div>
                        <form method="post" action="logout.php"><input type="hidden" name="csrf"
                                value="<?= htmlspecialchars(Autenticacion::tokenLogout()) ?>"><button
                                class="dropdown-item" type="submit"><i data-lucide="log-out"
                                    class="fs-16 text-muted align-middle me-2"></i>Cerrar sesión</button></form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<style>
    body {
        padding-left: 0 !important;
    }

    .page-content,
    .main-content {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        padding: 20px;
    }
</style>
