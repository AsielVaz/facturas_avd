<?php

require_once __DIR__ . '/api/EmpresaAdministrador.php';

SesionEmpresa::iniciar();
$companyManager = new EmpresaAdministrador(Conexion::obtener());
$selectionError = '';
$_SESSION['empresas_csrf'] ??= bin2hex(random_bytes(32));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals((string) $_SESSION['empresas_csrf'], $token)) {
        $selectionError = 'La solicitud expiro. Intenta seleccionar la empresa nuevamente.';
    } else {
        try {
            $selected = $companyManager->seleccionar(
                max(0, (int) ($_POST['empresa_id'] ?? 0)),
                max(0, (int) ($_POST['clave_id'] ?? 0))
            );
            $_SESSION['empresas_csrf'] = bin2hex(random_bytes(32));
            header('Location: empresas-s.php?seleccion=ok');
            exit;
        } catch (Throwable $error) {
            $selectionError = 'No fue posible seleccionar la empresa porque su RFC no coincide con la clave corta.';
        }
    }
}

try {
    $companies = $companyManager->listar();
} catch (Throwable $error) {
    $companies = [];
    $selectionError = 'No fue posible consultar la tabla de empresas.';
}

$currentCompany = SesionEmpresa::empresaActual();
$currentKey = SesionEmpresa::claveActual();
$matchedCompanies = count(array_filter($companies, static fn(array $company): bool => !empty($company['clave_id'])));
$unmatchedCompanies = count($companies) - $matchedCompanies;
$pageTitle = 'Seleccionar empresa';
$pageEyebrow = 'Configuracion / Empresa actual';
$activeModule = '';
$activePage = 'empresas';
$pageAction = '<a href="facturas-timbradas.php" class="btn btn-soft-secondary"><i data-lucide="arrow-left" class="fs-17 me-1"></i>Regresar al ERP</a>';

require 'templates/page-start.php';
?>

<?php if (($_GET['seleccion'] ?? '') === 'ok'): ?>
    <div class="alert alert-success d-flex align-items-center"><i data-lucide="circle-check" class="fs-19 me-2"></i><div><strong>Empresa actualizada.</strong> Todos los modulos ahora utilizan la empresa <?= $currentCompany ?> y la clave <?= $currentKey ?>.</div></div>
<?php endif; ?>
<?php if ($selectionError !== ''): ?>
    <div class="alert alert-danger"><i data-lucide="circle-alert" class="fs-18 me-2"></i><?= htmlspecialchars($selectionError) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
<?php
$cards = [
    ['Empresas registradas', number_format(count($companies)), 'Registros de la tabla empresas', 'building-2', 'primary', 'Listado completo', 'primary'],
    ['Con clave corta', number_format($matchedCompanies), 'RFC con coincidencia exacta', 'link-2', 'success', 'Disponibles para seleccionar', 'success'],
    ['Sin coincidencia', number_format($unmatchedCompanies), 'RFC sin clave corta asociada', 'unlink', 'warning', 'Requieren configuracion', 'warning'],
    ['Empresa activa', '#' . $currentCompany, 'Clave actual #' . $currentKey, 'badge-check', 'info', 'Valores de sesion', 'info'],
];
foreach ($cards as $card) {
    [$kpiLabel, $kpiValue, $kpiTrend, $kpiIcon, $kpiColor, $extra, $kpiTrendColor] = $card;
    $kpiTrend .= ' · ' . $extra;
    require 'templates/kpi-card.php';
}
?>
</div>

<div class="card">
    <div class="card-body border-bottom">
        <div class="row g-2 align-items-center">
            <div class="col-lg-7"><div class="search-bar"><span><i data-lucide="search"></i></span><input id="companySearch" type="search" class="form-control" placeholder="Buscar empresa, RFC o clave corta..."></div></div>
            <div class="col-lg-5 text-lg-end"><span class="text-muted fs-13">La relacion se obtiene comparando <code>empresas.rfc</code> con <code>claves_cortas.rfc</code>.</span></div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover text-nowrap mb-0 erp-table" id="companiesTable">
            <thead><tr><th class="ps-3">ID empresa</th><th>Empresa</th><th>RFC empresa</th><th>ID clave</th><th>Clave corta</th><th>Coincidencia</th><th class="text-end pe-3">Seleccionar</th></tr></thead>
            <tbody>
            <?php foreach ($companies as $company): ?>
                <?php
                $hasMatch = !empty($company['clave_id']);
                $isCurrent = (int) $company['empresa_id'] === $currentCompany && (int) ($company['clave_id'] ?? 0) === $currentKey;
                $searchText = strtolower(implode(' ', [$company['empresa_id'], $company['empresa'], $company['rfc'], $company['clave_id'] ?? '', $company['clave_corta'] ?? '']));
                ?>
                <tr data-company-search="<?= htmlspecialchars($searchText) ?>" class="<?= $isCurrent ? 'table-active' : '' ?>">
                    <td class="ps-3 font-monospace fw-semibold">#<?= (int) $company['empresa_id'] ?></td>
                    <td style="min-width:300px;white-space:normal"><div class="d-flex align-items-center gap-2"><span class="erp-avatar bg-<?= $isCurrent ? 'success' : 'primary' ?>-subtle text-<?= $isCurrent ? 'success' : 'primary' ?>"><i data-lucide="building" class="fs-18"></i></span><div><span class="fw-semibold"><?= htmlspecialchars((string) $company['empresa']) ?></span><?php if ($isCurrent): ?><small class="d-block text-success">Empresa utilizada actualmente</small><?php endif; ?></div></div></td>
                    <td class="font-monospace"><?= htmlspecialchars(trim((string) $company['rfc']) ?: 'Sin RFC') ?></td>
                    <td class="font-monospace"><?= $hasMatch ? '#' . (int) $company['clave_id'] : '—' ?></td>
                    <td><?= $hasMatch ? '<span class="badge badge-soft-primary">' . htmlspecialchars((string) $company['clave_corta']) . '</span>' : '<span class="text-muted">Sin clave</span>' ?></td>
                    <td><?php if ($hasMatch): ?><span class="badge badge-soft-success"><i data-lucide="link-2" class="fs-13 me-1"></i>RFC coincide</span><?php else: ?><span class="badge badge-soft-warning"><i data-lucide="unlink" class="fs-13 me-1"></i>Sin coincidencia</span><?php endif; ?></td>
                    <td class="text-end pe-3">
                        <?php if ($isCurrent): ?>
                            <button class="btn btn-sm btn-success" disabled><i data-lucide="check" class="fs-15 me-1"></i>Seleccionada</button>
                        <?php elseif ($hasMatch): ?>
                            <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?= htmlspecialchars((string) $_SESSION['empresas_csrf']) ?>"><input type="hidden" name="empresa_id" value="<?= (int) $company['empresa_id'] ?>"><input type="hidden" name="clave_id" value="<?= (int) $company['clave_id'] ?>"><button class="btn btn-sm btn-primary" type="submit"><i data-lucide="check-circle-2" class="fs-15 me-1"></i>Usar empresa</button></form>
                        <?php else: ?>
                            <button class="btn btn-sm btn-soft-secondary" disabled title="No existe una clave corta con el mismo RFC">No disponible</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($companies === []): ?><tr><td colspan="7" class="text-center py-5 text-muted">No hay empresas disponibles.</td></tr><?php endif; ?>
            <tr id="noCompanyResults" class="d-none"><td colspan="7" class="text-center py-5 text-muted">No se encontraron empresas con este criterio.</td></tr>
            </tbody>
        </table>
    </div>
    <div class="card-body border-top py-3"><span class="text-muted fs-13">Mostrando expresamente <?= count($companies) ?> registros de la tabla <code>empresas</code>.</span></div>
</div>

<?php
$pageScripts = <<<'HTML'
<script>
(function(){
 const input=document.getElementById('companySearch'), rows=[...document.querySelectorAll('#companiesTable tbody tr[data-company-search]')], empty=document.getElementById('noCompanyResults');
 input.addEventListener('input',function(){const term=this.value.trim().toLocaleLowerCase('es');let visible=0;rows.forEach(function(row){const show=row.dataset.companySearch.includes(term);row.classList.toggle('d-none',!show);if(show)visible++;});empty.classList.toggle('d-none',visible!==0);});
})();
</script>
HTML;
require 'templates/scripts.php';
?>
