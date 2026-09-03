<?php
$pageTitle = 'Facturas pendientes';
$pageEyebrow = 'Facturas / Por procesar';
$activeModule = 'facturas';
$activePage = 'facturas-pendientes';
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
<?php
$cards = [
 ['Pendientes por timbrar',number_format($invoiceSummary['total']),'$'.number_format($invoiceSummary['importe'], 2),'receipt','primary','Total registrado','primary'],
 ['Del mes mas reciente',number_format($invoiceSummary['este_mes']),'Facturas registradas','calendar-days','info','Actividad mensual','info'],
 ['Con error',number_format($invoiceSummary['errores']),'Requieren correccion','circle-alert','danger','Incidencias detectadas','danger'],
 ['Sin incidencias',number_format(max(0, $invoiceSummary['total'] - $invoiceSummary['errores'])),'Listas para procesar','file-check-2','success','Pendientes validas','success'],
];
foreach ($cards as $c) { [$kpiLabel,$kpiValue,$kpiTrend,$kpiIcon,$kpiColor,$extra,$kpiTrendColor]=$c; $kpiTrend="$kpiTrend · $extra"; require 'templates/kpi-card.php'; }
?>
</div>
<?php $invoiceType='pending'; require 'templates/invoice-table.php'; ?>
<?php require 'templates/scripts.php'; ?>
