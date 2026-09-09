<?php
$activeModule = $activeModule ?? '';
$activePage = $activePage ?? '';
$activeBankId = $activeBankId ?? 0;
$isActive = static fn(string $page): string => $activePage === $page ? ' active' : '';
$isOpen = static fn(string $module): string => $activeModule === $module ? ' show' : '';
if (!isset($sidebarBanks)) {
    try {
        require_once dirname(__DIR__) . '/api/BancoAdministrador.php';
        $sidebarBanks = (new BancoAdministrador(Conexion::obtener()))->listarBancos(SesionEmpresa::empresaActual());
    } catch (Throwable $error) {
        $sidebarBanks = [];
    }
}
?>
<div class="main-nav">
    <div class="d-flex justify-content-between main-logo-box">
        <div class="logo-box">
            <a href="facturas.php" class="logo-dark">
                <img src="<?= htmlspecialchars(SesionEmpresa::logoActual()) ?>" class="logo-sm erp-company-logo" alt="Logo de la empresa actual">
                <img src="<?= htmlspecialchars(SesionEmpresa::logoActual()) ?>" class="logo-lg erp-company-logo" alt="Logo de la empresa actual">
            </a>
            <a href="facturas.php" class="logo-light">
                <img src="<?= htmlspecialchars(SesionEmpresa::logoActual()) ?>" class="logo-sm erp-company-logo" alt="Logo de la empresa actual">
                <img src="<?= htmlspecialchars(SesionEmpresa::logoActual()) ?>" class="logo-lg erp-company-logo" alt="Logo de la empresa actual">
            </a>
        </div>
        <button type="button" class="btn btn-link d-flex button-sm-hover button-toggle-menu" aria-label="Alternar menu">
            <i data-lucide="menu" class="button-sm-hover-icon"></i>
        </button>
    </div>
    <div class="h-100" data-simplebar>
        <ul class="navbar-nav" id="navbar-nav">
            <li class="menu-title">Gestion administrativa</li>
            <li class="menu-item">
                <a class="menu-link<?= $activeModule === 'facturas' ? ' active' : '' ?>" href="#sidebarFacturas" data-bs-toggle="collapse" role="button" aria-expanded="<?= $activeModule === 'facturas' ? 'true' : 'false' ?>" aria-controls="sidebarFacturas">
                    <span class="nav-icon"><i data-lucide="receipt-text"></i></span>
                    <span class="nav-text">Facturas</span>
                    <span class="menu-arrow"><i data-lucide="chevron-down"></i></span>
                </a>
                <div class="collapse<?= $isOpen('facturas') ?>" id="sidebarFacturas">
                    <ul class="sub-menu-nav">
                        <li class="sub-menu-item"><a class="sub-menu-link<?= $isActive('facturar') ?>" href="facturar.php">Nueva factura</a></li>
                        <li class="sub-menu-item"><a class="sub-menu-link<?= $isActive('facturas-pendientes') ?>" href="facturas-pendientes.php">Facturas pendientes</a></li>
                        <li class="sub-menu-item"><a class="sub-menu-link<?= $isActive('facturas') ?>" href="facturas.php">Facturas</a></li>
                        <li class="sub-menu-item"><a class="sub-menu-link<?= $isActive('facturas-timbradas') ?>" href="facturas-timbradas.php">Facturas detalles</a></li>
                    </ul>
                </div>
            </li>
            <li class="menu-item">
                <a class="menu-link<?= $activeModule === 'pagos' ? ' active' : '' ?>" href="#sidebarPagos" data-bs-toggle="collapse" role="button" aria-expanded="<?= $activeModule === 'pagos' ? 'true' : 'false' ?>" aria-controls="sidebarPagos">
                    <span class="nav-icon"><i data-lucide="landmark"></i></span>
                    <span class="nav-text">Pagos</span>
                    <span class="menu-arrow"><i data-lucide="chevron-down"></i></span>
                </a>
                <div class="collapse<?= $isOpen('pagos') ?>" id="sidebarPagos">
                    <ul class="sub-menu-nav">
                        <?php foreach ($sidebarBanks as $sidebarBank): ?>
                            <li class="sub-menu-item"><a class="sub-menu-link<?= (int) $activeBankId === (int) $sidebarBank['id'] ? ' active' : '' ?>" href="banco.php?id=<?= (int) $sidebarBank['id'] ?>"><?= htmlspecialchars(trim((string) $sidebarBank['nombre_corto']) ?: (string) $sidebarBank['banco']) ?></a></li>
                        <?php endforeach; ?>
                        <?php if ($sidebarBanks === []): ?><li class="sub-menu-item"><span class="sub-menu-link text-muted">Sin cuentas bancarias</span></li><?php endif; ?>
                    </ul>
                </div>
            </li>
            <li class="menu-item">
                <a class="menu-link<?= $activeModule === 'inventario' ? ' active' : '' ?>" href="#sidebarInventario" data-bs-toggle="collapse" role="button" aria-expanded="<?= $activeModule === 'inventario' ? 'true' : 'false' ?>" aria-controls="sidebarInventario">
                    <span class="nav-icon"><i data-lucide="boxes"></i></span>
                    <span class="nav-text">Inventario</span>
                    <span class="menu-arrow"><i data-lucide="chevron-down"></i></span>
                </a>
                <div class="collapse<?= $isOpen('inventario') ?>" id="sidebarInventario">
                    <ul class="sub-menu-nav">
                        <li class="sub-menu-item"><a class="sub-menu-link<?= $isActive('notas-salida') ?>" href="notas-salida.php">Notas de salida</a></li>
                        <li class="sub-menu-item"><a class="sub-menu-link<?= $isActive('inventario') ?>" href="inventario.php">Inventario</a></li>
                        <li class="sub-menu-item"><a class="sub-menu-link<?= $isActive('escaner') ?>" href="escaner-inventario.php">Escaner de inventario</a></li>
                    </ul>
                </div>
            </li>
        </ul>
    </div>
</div>
