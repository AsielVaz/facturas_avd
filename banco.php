<?php

require_once __DIR__ . '/api/BancoAdministrador.php';

$bankManager = new BancoAdministrador(Conexion::obtener());
$currentCompany = SesionEmpresa::empresaActual();
$sidebarBanks = $bankManager->listarBancos($currentCompany);
$requestedBankId = max(0, (int) ($_GET['id'] ?? 0));
$activeBankId = $requestedBankId ?: (int) ($sidebarBanks[0]['id'] ?? 0);
$bank = $activeBankId > 0 ? $bankManager->obtenerBanco($activeBankId, $currentCompany) : null;
$bankError = '';

if ($bank === null) {
    $bankData = ['registros' => [], 'resumen' => ['movimientos' => 0, 'depositos' => 0, 'retiros' => 0, 'saldo' => 0], 'total' => 0, 'pagina' => 1, 'paginas' => 1, 'por_pagina' => 15];
    $bankError = $activeBankId > 0 ? 'La cuenta seleccionada no pertenece a la empresa actual.' : 'La empresa actual no tiene cuentas bancarias registradas.';
} else {
    try {
        $bankData = $bankManager->listarMovimientos(
            $activeBankId,
            $currentCompany,
            max(1, (int) ($_GET['pagina'] ?? 1)),
            15,
            trim((string) ($_GET['buscar'] ?? '')),
            trim((string) ($_GET['fecha'] ?? ''))
        );
    } catch (Throwable $error) {
        $bankData = ['registros' => [], 'resumen' => ['movimientos' => 0, 'depositos' => 0, 'retiros' => 0, 'saldo' => 0], 'total' => 0, 'pagina' => 1, 'paginas' => 1, 'por_pagina' => 15];
        $bankError = 'No fue posible cargar los movimientos de esta cuenta.';
    }
}

$pageTitle = $bank ? (trim((string) $bank['nombre_corto']) ?: (string) $bank['banco']) : 'Cuenta bancaria';
$pageEyebrow = 'Pagos / Movimientos bancarios';
$activeModule = 'pagos';
$activePage = 'banco';
$pageAction = '<button class="btn btn-soft-secondary"><i data-lucide="download" class="fs-17 me-1"></i>Exportar movimientos</button>';
$bankSearch = trim((string) ($_GET['buscar'] ?? ''));
$bankDate = trim((string) ($_GET['fecha'] ?? ''));
$buildBankPage = static function (int $page): string {
    $parameters = $_GET;
    $parameters['pagina'] = $page;
    return '?' . http_build_query($parameters);
};
$maskAccount = static function (string $account): string {
    $clean = preg_replace('/\s+/', '', $account) ?: '';
    return strlen($clean) > 4 ? '•••• ' . substr($clean, -4) : $clean;
};
$formatMovementDate = static function (array $movement): string {
    foreach (['fecha_operacion', 'fecha', 'fecha_sistema'] as $field) {
        $value = (string) ($movement[$field] ?? '');
        if ($value !== '' && $value !== '0000-00-00') {
            $time = strtotime($value);
            if ($time !== false) {
                return date('d/m/Y', $time);
            }
        }
    }
    return 'Sin fecha';
};

require 'templates/page-start.php';
?>

<?php if ($bankError !== ''): ?><div class="alert alert-danger"><i data-lucide="landmark" class="fs-18 me-2"></i><?= htmlspecialchars($bankError) ?></div><?php endif; ?>

<?php if ($bank): ?>
<?php
$bankColor = preg_match('/^[0-9a-f]{6}$/i', (string) $bank['color']) ? '#' . $bank['color'] : '#6658dd';
$summary = $bankData['resumen'];
?>
<div class="row g-3 mb-4">
    <div class="col-xl-4">
        <div class="card erp-account-card h-100 border-0" style="background:linear-gradient(135deg,<?= htmlspecialchars($bankColor) ?>,#1f2937)">
            <div class="card-body position-relative d-flex flex-column justify-content-between">
                <div class="d-flex justify-content-between align-items-start"><div><p class="mb-1 opacity-75"><?= htmlspecialchars((string) $bank['banco']) ?></p><h4 class="text-white mb-0"><?= htmlspecialchars(trim((string) $bank['nombre_corto']) ?: (string) $bank['banco']) ?></h4></div><span class="rounded-circle bg-white bg-opacity-10 p-3"><i data-lucide="landmark"></i></span></div>
                <div class="mt-4"><small class="d-block opacity-75 mb-1">Cuenta <?= htmlspecialchars((string) ($bank['moneda'] ?: '')) ?></small><span class="font-monospace fs-18"><?= htmlspecialchars($maskAccount((string) $bank['numero_cuenta'])) ?></span><small class="d-block opacity-75 mt-2">CLABE <?= htmlspecialchars($maskAccount((string) $bank['clabe_interbancaria'])) ?></small></div>
            </div>
        </div>
    </div>
    <div class="col-xl-8"><div class="row g-3 h-100">
        <div class="col-sm-6 col-lg-3"><div class="card h-100"><div class="card-body"><span class="erp-kpi-icon bg-primary-subtle text-primary mb-3"><i data-lucide="wallet-cards"></i></span><p class="text-muted mb-1">Saldo registrado</p><h5 class="mb-0">$<?= number_format((float) $summary['saldo'], 2) ?></h5></div></div></div>
        <div class="col-sm-6 col-lg-3"><div class="card h-100"><div class="card-body"><span class="erp-kpi-icon bg-success-subtle text-success mb-3"><i data-lucide="arrow-down-left"></i></span><p class="text-muted mb-1">Depositos</p><h5 class="mb-0">$<?= number_format((float) $summary['depositos'], 2) ?></h5></div></div></div>
        <div class="col-sm-6 col-lg-3"><div class="card h-100"><div class="card-body"><span class="erp-kpi-icon bg-danger-subtle text-danger mb-3"><i data-lucide="arrow-up-right"></i></span><p class="text-muted mb-1">Retiros</p><h5 class="mb-0">$<?= number_format((float) $summary['retiros'], 2) ?></h5></div></div></div>
        <div class="col-sm-6 col-lg-3"><div class="card h-100"><div class="card-body"><span class="erp-kpi-icon bg-info-subtle text-info mb-3"><i data-lucide="list"></i></span><p class="text-muted mb-1">Movimientos</p><h5 class="mb-0"><?= number_format((int) $summary['movimientos']) ?></h5></div></div></div>
    </div></div>
</div>

<div class="card">
    <div class="card-body border-bottom">
        <form method="get" class="row g-2 align-items-center">
            <input type="hidden" name="id" value="<?= (int) $bank['id'] ?>">
            <div class="col-lg-6"><div class="search-bar"><span><i data-lucide="search"></i></span><input name="buscar" value="<?= htmlspecialchars($bankSearch) ?>" class="form-control" placeholder="Buscar descripcion, referencia o tipo..."></div></div>
            <div class="col-sm-5 col-lg-3"><input name="fecha" value="<?= htmlspecialchars($bankDate) ?>" type="date" class="form-control" aria-label="Fecha del movimiento"></div>
            <div class="col-sm-7 col-lg-3 text-sm-end"><button class="btn btn-primary" type="submit"><i data-lucide="search" class="fs-16 me-1"></i>Buscar</button><?php if ($bankSearch !== '' || $bankDate !== ''): ?><a href="banco.php?id=<?= (int) $bank['id'] ?>" class="btn btn-soft-secondary ms-1">Limpiar</a><?php endif; ?></div>
        </form>
    </div>
    <div class="table-responsive"><table class="table table-hover text-nowrap mb-0 erp-table"><thead><tr><th class="ps-3">Fecha</th><th>Movimiento</th><th>Referencia</th><th>Tipo</th><th class="text-end">Deposito</th><th class="text-end">Retiro</th><th class="text-end pe-3">Saldo</th></tr></thead><tbody>
    <?php foreach ($bankData['registros'] as $movement): ?>
        <?php
        $description = trim((string) ($movement['descripcion'] ?? '')) ?: trim((string) ($movement['descrip_det'] ?? '')) ?: 'Movimiento bancario';
        $reference = trim((string) ($movement['referencia'] ?? '')) ?: trim((string) ($movement['referencia_interb'] ?? '')) ?: 'Sin referencia';
        $deposit = (float) ($movement['deposito'] ?? 0);
        $withdrawal = (float) ($movement['retiro'] ?? 0);
        ?>
        <tr><td class="ps-3"><?= htmlspecialchars($formatMovementDate($movement)) ?><small class="d-block text-muted"><?= htmlspecialchars(substr((string) ($movement['hora_operacion'] ?? ''), 0, 8)) ?></small></td><td style="min-width:320px;max-width:520px;white-space:normal"><div class="d-flex align-items-start gap-2"><span class="erp-avatar bg-<?= $deposit > 0 ? 'success' : 'danger' ?>-subtle text-<?= $deposit > 0 ? 'success' : 'danger' ?> flex-shrink-0"><i data-lucide="<?= $deposit > 0 ? 'arrow-down-left' : 'arrow-up-right' ?>" class="fs-18"></i></span><span class="fw-medium"><?= htmlspecialchars($description) ?></span></div></td><td class="font-monospace fs-13" style="max-width:220px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($reference) ?></td><td><span class="badge badge-soft-<?= $deposit > 0 ? 'success' : 'danger' ?>"><?= htmlspecialchars(trim((string) ($movement['tipo_movimiento'] ?? '')) ?: ($deposit > 0 ? 'Deposito' : 'Retiro')) ?></span></td><td class="text-end text-success fw-semibold"><?= $deposit > 0 ? '$' . number_format($deposit, 2) : '—' ?></td><td class="text-end text-danger fw-semibold"><?= $withdrawal > 0 ? '$' . number_format($withdrawal, 2) : '—' ?></td><td class="text-end pe-3 fw-semibold"><?= $movement['saldo'] !== null ? '$' . number_format((float) $movement['saldo'], 2) : '—' ?></td></tr>
    <?php endforeach; ?>
    <?php if ($bankData['registros'] === []): ?><tr><td colspan="7" class="text-center py-5"><i data-lucide="search-x" class="text-muted mb-2" style="width:34px;height:34px"></i><p class="text-muted mb-0">No se encontraron movimientos para esta cuenta.</p></td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="card-body border-top py-3 d-flex flex-wrap gap-2 justify-content-between align-items-center"><span class="text-muted fs-13">Mostrando <?= count($bankData['registros']) ?> de <?= number_format((int) $bankData['total']) ?> movimientos</span><nav><ul class="pagination pagination-sm mb-0"><li class="page-item<?= $bankData['pagina'] <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($buildBankPage(max(1, $bankData['pagina'] - 1))) ?>">Anterior</a></li><li class="page-item active"><span class="page-link"><?= (int) $bankData['pagina'] ?> / <?= (int) $bankData['paginas'] ?></span></li><li class="page-item<?= $bankData['pagina'] >= $bankData['paginas'] ? ' disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($buildBankPage(min($bankData['paginas'], $bankData['pagina'] + 1))) ?>">Siguiente</a></li></ul></nav></div>
</div>
<?php endif; ?>

<?php require 'templates/scripts.php'; ?>
