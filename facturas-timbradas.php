<?php
$pageTitle = 'Facturas timbradas';
$pageEyebrow = 'Facturas / CFDI emitidos';
$activeModule = 'facturas';
$activePage = 'facturas-timbradas';
$pageAction = '<div class="btn-group"><button class="btn btn-soft-secondary"><i data-lucide="download" class="fs-17 me-1"></i>Exportar</button><button class="btn btn-primary"><i data-lucide="send" class="fs-17 me-1"></i>Enviar CFDI</button></div>';
require_once __DIR__ . '/api/FacturaVistaAdministrador.php';
$invoiceError = '';
try {
    $invoiceData = (new FacturaVistaAdministrador(new FacturaAdministrador(Conexion::obtener())))->cargar('timbradas', $_GET);
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
 ['CFDI timbrados',number_format($invoiceSummary['total']),'$'.number_format($invoiceSummary['importe'], 2),'badge-check','success','Historico registrado','success'],
 ['Del mes mas reciente',number_format($invoiceSummary['este_mes']),'Comprobantes emitidos','calendar-days','primary','Actividad mensual','primary'],
 ['CFDI vigentes',number_format(max(0, $invoiceSummary['total'] - $invoiceSummary['canceladas'])),'Disponibles en el sistema','file-check-2','info','Sin cancelacion','info'],
 ['Canceladas',number_format($invoiceSummary['canceladas']),'Comprobantes cancelados','file-x-2','danger','Historico de bajas','danger'],
];
foreach ($cards as $c) { [$kpiLabel,$kpiValue,$kpiTrend,$kpiIcon,$kpiColor,$extra,$kpiTrendColor]=$c; $kpiTrend="$kpiTrend · $extra"; require 'templates/kpi-card.php'; }
?>
</div>
<?php $invoiceType='stamped'; require 'templates/invoice-table.php'; ?>
<?php require 'templates/scripts.php'; ?>
