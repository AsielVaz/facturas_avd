<?php
// Mantenemos la ruta del logo de la empresa
$sidebarLogo = 'assets/images/logo.jpg';
$cadenaBanca = $_SESSION['cadena_total'] ?? null;
$bancaDisponible = is_string($cadenaBanca) && $cadenaBanca !== ''
    && strlen($cadenaBanca) <= 4096 && preg_match('/[\x00-\x20\x7F]/', $cadenaBanca) !== 1;
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
                <form id="formLoginBanca" action="https://banca.sistema14.com/api/login_externo.php" method="post"
                    class="m-0">
                    <?php if ($bancaDisponible): ?>
                        <input type="hidden" name="cadena_total" id="cadena_total"
                            value="<?= htmlspecialchars($cadenaBanca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <?php endif; ?>
                    <button type="submit" class="border-0 bg-transparent p-0 d-flex align-items-center"
                        aria-label="Ir a Banca"
                        title="<?= $bancaDisponible ? 'Ir a Banca' : 'Inicia sesión nuevamente para habilitar Banca' ?>"
                        <?= $bancaDisponible ? '' : 'disabled' ?>>
                        <img src="assets/images/banca_logo.png" class="rounded" alt=""
                            style="height: 48px; width: 48px; object-fit: contain; background: #fff; padding: 2px;">
                    </button>
                </form>

                <script>
                    document.getElementById('formLoginBanca').addEventListener('submit', async function (e) {
                        e.preventDefault(); // Evitamos que el navegador recargue o envíe el formulario de forma clásica

                        const inputCadena = document.getElementById('cadena_total');
                        if (!inputCadena) return;

                        // Preparamos los datos usando FormData (idéntico a form-data de Postman)
                        const formData = new FormData();
                        formData.append('cadena_total', inputCadena.value);

                        try {
                            // Realizamos la petición POST a la API externa
                            const response = await fetch(this.action, {
                                method: 'POST',
                                body: formData
                                // Nota: Al usar FormData, el navegador asigna automáticamente el Content-Type multipart/form-data con su boundary correcto.
                            });

                            // Intentamos parsear la respuesta como JSON
                            const resultado = await response.json();

                            // Validamos si la respuesta fue exitosa
                            if (response.ok && resultado.estatus === 'exito') {
                                // Si todo está bien, redirigimos a la URL solicitada
                                window.location.href = 'https://banca.sistema14.com/';
                            } else {
                                // Manejo de errores devueltos por el servidor
                                alert('Error al acceder a Banca: ' + (resultado.mensaje || 'Respuesta inválida'));
                            }
                        } catch (error) {
                            console.error('Error en la petición:', error);
                            alert('Ocurrió un error de conexión al intentar acceder a Banca.');
                        }
                    });
                </script>
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
                    <h1>hola</h1>
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