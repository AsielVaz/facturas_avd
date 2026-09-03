<header class="topbar d-flex">
    <div class="container-fluid">
        <div class="navbar-header">
            <div class="d-flex align-items-center gap-2">
                <form class="app-search d-none d-md-block me-auto" onsubmit="return false;">
                    <div class="position-relative">
                        <input type="search" class="form-control" placeholder="Buscar en el ERP..." autocomplete="off">
                        <i data-lucide="search" class="search-widget-icon"></i>
                    </div>
                </form>
            </div>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <div class="topbar-item d-none d-sm-block">
                    <a href="empresas-s.php" class="btn btn-sm btn-soft-primary" title="Cambiar empresa actual">
                        <i data-lucide="building-2" class="fs-16 me-1"></i>Empresa <?= SesionEmpresa::empresaActual() ?>
                    </a>
                </div>
                <div class="topbar-item">
                    <button type="button" class="topbar-button fs-24" id="light-dark-mode" aria-label="Cambiar tema">
                        <i data-lucide="moon" class="light-mode"></i>
                        <i data-lucide="sun" class="dark-mode"></i>
                    </button>
                </div>
                <div class="dropdown topbar-item">
                    <button type="button" class="topbar-button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notificaciones">
                        <i data-lucide="bell"></i><span class="topbar-badge text-bg-danger rounded-pill">3</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end dropdown-lg p-0">
                        <div class="p-3 border-bottom"><h6 class="m-0 fs-16 fw-semibold">Notificaciones</h6></div>
                        <div class="p-2">
                            <a href="facturas-pendientes.php" class="dropdown-item py-2 text-wrap"><span class="badge bg-warning-subtle text-warning me-2">Factura</span> 4 facturas vencen esta semana.</a>
                            <a href="inventario.php" class="dropdown-item py-2 text-wrap"><span class="badge bg-danger-subtle text-danger me-2">Stock</span> 7 productos requieren reposicion.</a>
                            <a href="banco-1.php" class="dropdown-item py-2 text-wrap"><span class="badge bg-success-subtle text-success me-2">Banco</span> Se recibio un deposito por $48,250.</a>
                        </div>
                    </div>
                </div>
                <div class="dropdown topbar-item">
                    <a class="topbar-button p-0" href="#" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="d-flex align-items-center gap-2">
                            <img class="rounded-circle" width="32" src="assets/images/users/avatar-1.jpg" alt="Usuario">
                            <span class="d-lg-flex flex-column d-none"><span class="text-reset fs-14 fw-medium">Administrador</span><small class="text-muted">Control general</small></span>
                        </span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <a class="dropdown-item" href="empresas-s.php"><i data-lucide="building-2" class="fs-16 text-muted align-middle me-2"></i>Cambiar empresa</a>
                        <a class="dropdown-item" href="#"><i data-lucide="user" class="fs-16 text-muted align-middle me-2"></i>Mi perfil</a>
                        <a class="dropdown-item" href="#"><i data-lucide="settings" class="fs-16 text-muted align-middle me-2"></i>Configuracion</a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="#"><i data-lucide="log-out" class="fs-16 text-muted align-middle me-2"></i>Cerrar sesion</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
