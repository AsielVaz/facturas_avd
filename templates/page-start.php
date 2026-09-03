<?php
require_once dirname(__DIR__) . '/api/SesionEmpresa.php';
require_once dirname(__DIR__) . '/api/Conexion.php';
SesionEmpresa::iniciar();
try {
    SesionEmpresa::sincronizarContexto(Conexion::obtener());
} catch (Throwable $error) {
    // La interfaz conserva el logo local de respaldo si el recurso no esta disponible.
}
require __DIR__ . '/head.php';
?>
<?php require __DIR__ . '/sidebar.php'; ?>
<?php require __DIR__ . '/header.php'; ?>
<div class="page-container">
    <div class="page-content">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <p class="text-uppercase text-muted fs-12 fw-semibold mb-1"><?= htmlspecialchars($pageEyebrow ?? 'ERP Dinamico') ?></p>
                <h4 class="erp-page-title mb-0"><?= htmlspecialchars($pageTitle) ?></h4>
            </div>
            <?php if (!empty($pageAction)): ?>
                <?= $pageAction ?>
            <?php endif; ?>
        </div>
