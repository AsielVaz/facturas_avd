<?php
$pageTitle = 'Facturas';
$pageEyebrow = 'Facturas / CFDI emitidos';
$activeModule = 'facturas';
$activePage = 'facturas';
$pageAction = '<a class="btn btn-primary" href="facturar.php"><i data-lucide="plus" class="fs-18 me-1"></i>Nueva factura</a>';
require_once __DIR__ . '/api/FacturaVistaAdministrador.php';
require_once __DIR__ . '/api/Autenticacion.php';
Autenticacion::exigirPagina();
$_SESSION['sat_status_csrf'] ??= bin2hex(random_bytes(32));
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
<!-- <?php
$cards = [
 ['CFDI timbrados',number_format($invoiceSummary['total']),'$'.number_format($invoiceSummary['importe'], 2),'badge-check','success','Historico registrado','success'],
 ['Del mes mas reciente',number_format($invoiceSummary['este_mes']),'Comprobantes emitidos','calendar-days','primary','Actividad mensual','primary'],
 ['CFDI vigentes',number_format(max(0, $invoiceSummary['total'] - $invoiceSummary['canceladas'])),'Disponibles en el sistema','file-check-2','info','Sin cancelacion','info'],
 ['Canceladas',number_format($invoiceSummary['canceladas']),'Comprobantes cancelados','file-x-2','danger','Historico de bajas','danger'],
];
foreach ($cards as $c) { [$kpiLabel,$kpiValue,$kpiTrend,$kpiIcon,$kpiColor,$extra,$kpiTrendColor]=$c; $kpiTrend="$kpiTrend · $extra"; require 'templates/kpi-card.php'; }
?> -->
</div>
<?php $invoiceType='stamped'; require 'templates/invoice-table.php'; ?>
<script>
window.satStatusConfig = <?= json_encode([
    'endpoint' => 'api/factura-estados-sat.php',
    'csrf' => (string) $_SESSION['sat_status_csrf'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<div class="modal fade" id="invoicePdfModal" tabindex="-1" aria-labelledby="invoicePdfModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="invoicePdfModalTitle">Ver factura</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-0 position-relative" style="min-height:75vh">
                <div id="invoicePdfLoader" class="position-absolute top-0 start-0 w-100 h-100 d-none align-items-center justify-content-center bg-body" style="z-index:2" role="status" aria-live="polite">
                    <div class="text-center">
                        <div class="spinner-border text-primary mb-3" aria-hidden="true"></div>
                        <p class="fw-semibold mb-1">Cargando PDF…</p>
                        <small class="text-muted">Esto puede tardar unos segundos.</small>
                    </div>
                </div>
                <iframe id="invoicePdfFrame" title="Vista previa de la factura en PDF" style="width:100%;height:75vh;border:0;background:#fff"></iframe>
            </div>
        </div>
    </div>
</div>
<?php
$pageScripts = <<<'HTML'
<script>
(() => {
    const modalElement = document.getElementById('invoicePdfModal');
    const modalTitle = document.getElementById('invoicePdfModalTitle');
    const pdfFrame = document.getElementById('invoicePdfFrame');
    const pdfLoader = document.getElementById('invoicePdfLoader');
    if (!modalElement || !modalTitle || !pdfFrame || !pdfLoader) return;

    const showLoader = () => {
        pdfLoader.classList.remove('d-none');
        pdfLoader.classList.add('d-flex');
        pdfFrame.style.visibility = 'hidden';
    };
    const hideLoader = () => {
        pdfLoader.classList.add('d-none');
        pdfLoader.classList.remove('d-flex');
        pdfFrame.style.visibility = 'visible';
    };

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    document.querySelectorAll('.js-view-invoice').forEach(button => {
        button.addEventListener('click', () => {
            modalTitle.textContent = 'Factura ' + (button.dataset.invoiceFolio || '');
            showLoader();
            pdfFrame.src = button.dataset.pdfUrl || 'about:blank';
            modal.show();
        });
    });
    pdfFrame.addEventListener('load', () => {
        if (pdfFrame.getAttribute('src') !== 'about:blank') hideLoader();
    });
    modalElement.addEventListener('hidden.bs.modal', () => {
        pdfFrame.src = 'about:blank';
        hideLoader();
    });
})();

(() => {
    const config = window.satStatusConfig;
    const badges = [...document.querySelectorAll('.js-invoice-status[data-invoice-id]')];
    if (!config || badges.length === 0) return;

    const invoiceIds = [...new Set(badges.map(badge => Number(badge.dataset.invoiceId)).filter(Number.isInteger))];
    badges.forEach(badge => {
        badge.dataset.localStatus = badge.textContent.trim();
        badge.textContent = 'Consultando SAT…';
        badge.className = 'badge badge-soft-secondary js-invoice-status';
    });

    fetch(config.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({csrf: config.csrf, facturas: invoiceIds}),
    })
        .then(async response => {
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'No fue posible consultar el SAT.');
            return data;
        })
        .then(data => {
            badges.forEach(badge => {
                const result = data.estados?.[badge.dataset.invoiceId];
                if (!result || !result.comprobado) {
                    badge.textContent = badge.dataset.localStatus || 'Sin comprobar';
                    badge.className = 'badge badge-soft-warning js-invoice-status';
                    badge.title = result?.error || 'No fue posible comprobar esta factura en el SAT.';
                    return;
                }
                const state = String(result.estado || 'Comprobada');
                const normalized = state.toLocaleLowerCase('es-MX');
                const color = result.cancelada ? 'danger' : (normalized.includes('vigente') ? 'success' : 'warning');
                badge.textContent = state;
                badge.className = 'badge badge-soft-' + color + ' js-invoice-status';
                badge.title = [
                    result.codigo_estatus,
                    result.es_cancelable ? 'Cancelación: ' + result.es_cancelable : '',
                    result.estatus_cancelacion ? 'Estatus de cancelación: ' + result.estatus_cancelacion : '',
                    result.validacion_efos ? 'EFOS: ' + result.validacion_efos : '',
                    result.desde_sesion ? 'Resultado guardado en la sesión.' : 'Consultado en el SAT.',
                ].filter(Boolean).join('\n');
            });
        })
        .catch(error => {
            badges.forEach(badge => {
                badge.textContent = badge.dataset.localStatus || 'Sin comprobar';
                badge.className = 'badge badge-soft-warning js-invoice-status';
                badge.title = error.message;
            });
        });
})();
</script>
HTML;
?>
<?php require 'templates/scripts.php'; ?>
