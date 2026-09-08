<?php
$pageTitle = 'Facturas';
$pageEyebrow = 'Facturas procesadas';
$activeModule = 'facturas';
$activePage = 'facturas';
$pageAction = '<a class="btn btn-primary" href="facturar.php"><i data-lucide="plus" class="fs-18 me-1"></i>Nueva factura</a>';
require_once __DIR__ . '/api/FacturaVistaAdministrador.php';
$invoiceError = '';
try {
    $invoiceData = (new FacturaVistaAdministrador(new FacturaAdministrador(Conexion::obtener())))->cargar('pendientes', $_GET);
    $invoiceRows = $invoiceData['filas'];
    $invoicePagination = $invoiceData['paginacion'];
    $invoiceSummary = $invoiceData['resumen'];
} catch (Throwable $error) {
    $invoiceRows = [];
    $invoicePagination = ['total' => 0, 'pagina' => 1, 'paginas' => 1, 'por_pagina' => 10];
    $invoiceSummary = ['total' => 0, 'importe' => 0, 'errores' => 0, 'canceladas' => 0, 'este_mes' => 0];
    $invoiceError = 'No fue posible cargar las facturas. Verifica la conexion con la base de datos.';
}
require 'templates/page-start.php';
?>
<?php if ($invoiceError !== ''): ?><div class="alert alert-danger"><i data-lucide="database-zap" class="fs-18 me-2"></i><?= htmlspecialchars($invoiceError) ?></div><?php endif; ?>
<div class="row g-3 mb-4">
<!-- <?php
$cards = [
 ['Pendientes por timbrar',number_format($invoiceSummary['total']),'$'.number_format($invoiceSummary['importe'], 2),'receipt','primary','Total registrado','primary'],
 ['Del mes mas reciente',number_format($invoiceSummary['este_mes']),'Facturas registradas','calendar-days','info','Actividad mensual','info'],
 ['Con error',number_format($invoiceSummary['errores']),'Requieren correccion','circle-alert','danger','Incidencias detectadas','danger'],
 ['Sin incidencias',number_format(max(0, $invoiceSummary['total'] - $invoiceSummary['errores'])),'Listas para procesar','file-check-2','success','Pendientes validas','success'],
];
foreach ($cards as $c) { [$kpiLabel,$kpiValue,$kpiTrend,$kpiIcon,$kpiColor,$extra,$kpiTrendColor]=$c; $kpiTrend="$kpiTrend · $extra"; require 'templates/kpi-card.php'; }
?> -->
</div>
<?php $invoiceType='pending'; require 'templates/invoice-table.php'; ?>
<?php
$csrfTimbrado = (string) ($_SESSION['facturacion_csrf'] ??= bin2hex(random_bytes(32)));
$pageScripts = '<script>window.facturasPendientesConfig=' . json_encode(
    ['csrf' => $csrfTimbrado],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
) . ';</script>' . <<<'HTML'
<script>
document.querySelectorAll('.js-stamp-invoice').forEach(button => {
    button.addEventListener('click', async () => {
        if (button.disabled || !window.confirm('¿Deseas timbrar fiscalmente esta factura?')) return;
        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        try {
            const response = await fetch('api/facturas.php?accion=timbrar', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify({
                    csrf: window.facturasPendientesConfig.csrf,
                    factura_id: Number(button.dataset.invoiceId),
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'No fue posible timbrar la factura.');
            window.location.href = 'facturas-timbradas.php';
        } catch (error) {
            window.alert(error.message || 'No fue posible timbrar la factura.');
            button.disabled = false;
            button.innerHTML = original;
            if (window.lucide) window.lucide.createIcons();
        }
    });
});
</script>
HTML;
?>
<?php require 'templates/scripts.php'; ?>
