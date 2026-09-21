<?php
// Mantenemos la ruta del logo de la empresa
$sidebarLogo = 'assets/images/logo.jpg';
?>
<header class="topbar d-flex align-items-center">
    <div class="container-fluid">
        <div class="navbar-header d-flex align-items-center justify-content-between w-100">
            <!-- Lado Izquierdo: Logo con mayor tamaño -->
            <div class="d-flex align-items-center gap-3">
                <a href="facturas.php" class="navbar-brand d-flex align-items-center py-0">
                    <img src="<?= htmlspecialchars($sidebarLogo) ?>" class="erp-company-logo rounded" alt="Logo del sistema" style="height: 48px; width: auto; object-fit: contain;">
                </a>
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
