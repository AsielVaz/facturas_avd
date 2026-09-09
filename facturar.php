<?php

declare(strict_types=1);

require_once __DIR__ . '/api/FacturaPendienteAdministrador.php';

SesionEmpresa::iniciar();
$_SESSION['facturacion_csrf'] ??= bin2hex(random_bytes(32));
$facturaId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$modoEdicion = $facturaId > 0;
$facturaPendiente = null;
$facturacionError = '';
try {
    $facturacion = new FacturacionAdministrador(Conexion::obtener());
    $contextoFactura = $facturacion->obtenerContexto();
    if ($modoEdicion) {
        $facturaPendiente = (new FacturaPendienteAdministrador(Conexion::obtener()))->obtener($facturaId);
        $conceptosExistentes = [];
        foreach ($contextoFactura['conceptos'] as $concepto) {
            $conceptosExistentes[(int) $concepto['id']] = true;
        }
        foreach ($facturaPendiente['conceptos_historicos'] as $conceptoHistorico) {
            if (!isset($conceptosExistentes[(int) $conceptoHistorico['id']])) {
                $contextoFactura['conceptos'][] = $conceptoHistorico;
            }
        }
    }
} catch (Throwable $error) {
    $contextoFactura = ['emisor' => [], 'clientes' => [], 'conceptos' => [], 'catalogos' => []];
    $facturacionError = 'No fue posible cargar los datos necesarios para preparar la factura.';
}

$emisor = $contextoFactura['emisor'];
$clientes = $contextoFactura['clientes'];
$conceptos = $contextoFactura['conceptos'];
$catalogos = $contextoFactura['catalogos'];
$metodoPagoPueId = 0;
foreach (($catalogos['metodos_pago'] ?? []) as $metodoPago) {
    if ((string) ($metodoPago['clave'] ?? '') === 'PUE') {
        $metodoPagoPueId = (int) ($metodoPago['id'] ?? 0);
        break;
    }
}
$facturaCompletaInicial = $modoEdicion && (
    (float) ($facturaPendiente['retencion_isr_tasa'] ?? 0) > 0
    || (float) ($facturaPendiente['retencion_iva_tasa'] ?? 0) > 0
    || (string) ($facturaPendiente['exportacion'] ?? '01') !== '01'
    || ($metodoPagoPueId > 0 && (int) ($facturaPendiente['metodo_pago_id'] ?? 0) !== $metodoPagoPueId)
    || (string) ($facturaPendiente['uso_cfdi'] ?? 'G03') !== 'G03'
);
$pageTitle = $modoEdicion ? 'Editar factura pendiente' : 'Preparar factura CFDI 4.0';
$pageEyebrow = $modoEdicion ? 'Facturas / Editar pendiente' : 'Facturas / Nueva factura';
$activeModule = 'facturas';
$activePage = 'facturar';
$pageAction = '<div class="d-flex flex-wrap align-items-center justify-content-end gap-2">'
    . '<div class="invoice-type-switcher d-flex align-items-center gap-3 border rounded-3 px-3 py-2 bg-body-tertiary">'
    . '<i data-lucide="sliders-horizontal" class="text-primary flex-shrink-0"></i>'
    . '<div><small class="d-block text-uppercase text-muted fw-semibold">Tipo de factura</small>'
    . '<div class="form-check form-switch mb-0"><input id="invoiceTypeSwitch" class="form-check-input" type="checkbox" role="switch" '
    . ($facturaCompletaInicial ? 'checked' : '') . '><label id="invoiceTypeLabel" class="form-check-label fw-semibold" for="invoiceTypeSwitch">'
    . ($facturaCompletaInicial ? 'Factura sencilla' : 'Factura completa') . '</label></div>'
    . '<small id="invoiceTypeHint" class="d-block text-muted">Selección manual; el RFC ajusta los impuestos.</small></div></div>'
    . '<a href="facturas-pendientes.php" class="btn btn-soft-secondary"><i data-lucide="arrow-left" class="fs-17 me-1"></i>Volver a pendientes</a></div>';
require 'templates/page-start.php';
?>

<style>
    .invoice-step { width: 30px; height: 30px; border-radius: 9px; display: grid; place-items: center; font-weight: 700; }
    .invoice-fiscal-summary { min-height: 92px; }
    .invoice-concepts-table select { min-width: 260px; }
    .invoice-concepts-table input[type=number] { min-width: 110px; }
    .invoice-total-panel { position: sticky; top: 88px; }
    .invoice-money { font-variant-numeric: tabular-nums; }
    .invoice-readonly { background: var(--bs-tertiary-bg) !important; }
    .invoice-type-switcher .form-check-input { width: 2.75rem; height: 1.35rem; margin-top: .1rem; cursor: pointer; }
    .invoice-type-switcher .form-check-label { cursor: pointer; padding-left: .25rem; }
    @media (max-width: 991.98px) { .invoice-total-panel { position: static; } }
</style>

<?php if ($facturacionError !== ''): ?>
    <div class="alert alert-danger"><i data-lucide="database-zap" class="fs-18 me-2"></i><?= htmlspecialchars($facturacionError) ?></div>
<?php else: ?>
    <?php if ($modoEdicion): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i data-lucide="square-pen" class="fs-19 mt-1 flex-shrink-0"></i>
            <div><strong>Editando <?= htmlspecialchars((string) ($facturaPendiente['folio'] ?? ('factura #' . $facturaId))) ?>.</strong> Al guardar se actualizarán esta factura pendiente y sus partidas. Si fue timbrada entretanto, el servidor rechazará la operación.</div>
        </div>
    <?php else: ?>
        <div class="alert alert-info d-flex align-items-start gap-2">
            <i data-lucide="shield-check" class="fs-19 mt-1 flex-shrink-0"></i>
            <div><strong>Preparador CFDI.</strong> La vista previa valida los datos sin guardarlos. Al confirmar, la factura se guardará y se enviará a timbrar.</div>
        </div>
    <?php endif; ?>

    <?php if (empty($emisor['completo'])): ?>
        <div class="alert alert-danger"><strong>El emisor está incompleto.</strong> <?= htmlspecialchars(implode(' ', $emisor['errores'] ?? [])) ?></div>
    <?php endif; ?>

    <form id="invoiceForm" novalidate>
        <div id="invoiceMessages"></div>
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span class="invoice-step bg-primary-subtle text-primary">1</span>
                        <div><h5 class="mb-0">Emisor y receptor</h5><small class="text-muted">Los datos fiscales provienen directamente de la base.</small></div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 mb-4">
                            <div class="col-12"><h6 class="text-uppercase text-muted fs-12 mb-0">Emisor · Empresa #<?= (int) ($emisor['id'] ?? 0) ?></h6></div>
                            <div class="col-md-6"><label class="form-label">Razón social</label><input class="form-control invoice-readonly" readonly value="<?= htmlspecialchars((string) ($emisor['nombre'] ?? '')) ?>"></div>
                            <div class="col-md-3"><label class="form-label">RFC</label><input class="form-control invoice-readonly font-monospace" readonly value="<?= htmlspecialchars((string) ($emisor['rfc'] ?? '')) ?>"></div>
                            <div class="col-md-3"><label class="form-label">Régimen / CP</label><input class="form-control invoice-readonly" readonly value="<?= htmlspecialchars((string) ($emisor['regimen_fiscal'] ?? '')) ?> · <?= htmlspecialchars((string) ($emisor['cp'] ?? '')) ?>"></div>
                        </div>
                        <hr>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="clientSelect" class="form-label">Cliente <span class="text-danger">*</span></label>
                                <select id="clientSelect" class="form-select" required>
                                    <option value="">Seleccionar cliente...</option>
                                    <?php foreach ($clientes as $cliente): ?>
                                        <option value="<?= (int) $cliente['id'] ?>" data-facturable="<?= !empty($cliente['facturable']) ? '1' : '0' ?>" <?= $modoEdicion && (int) $facturaPendiente['cliente_id'] === (int) $cliente['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string) $cliente['nombre']) ?> · <?= (int) $cliente['perfiles'] ?> perfil(es)<?= empty($cliente['facturable']) ? ' · incompleto' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="profileSelect" class="form-label">Perfil fiscal / clave corta <span class="text-danger">*</span></label>
                                <select id="profileSelect" class="form-select" required disabled><option value="">Selecciona primero un cliente...</option></select>
                            </div>
                            <div class="col-12">
                                <div id="receiverSummary" class="invoice-fiscal-summary border rounded-3 p-3 bg-body-tertiary text-muted d-flex align-items-center">
                                    Selecciona un cliente y uno de sus perfiles fiscales.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span class="invoice-step bg-info-subtle text-info">2</span>
                        <div><h5 class="mb-0">Datos del comprobante</h5><small class="text-muted">CFDI 4.0 de tipo ingreso.</small></div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3"><label class="form-label">Tipo</label><input class="form-control invoice-readonly" value="I · Ingreso" readonly></div>
                            <div class="col-md-3"><label for="invoiceDate" class="form-label">Fecha</label><input id="invoiceDate" type="date" class="form-control" required value="<?= htmlspecialchars($modoEdicion ? (string) $facturaPendiente['fecha'] : date('Y-m-d')) ?>"></div>
                            <div class="col-md-3"><label for="currencySelect" class="form-label">Moneda</label><select id="currencySelect" class="form-select" required><?php foreach (($catalogos['monedas'] ?? []) as $moneda): ?><option value="<?= (int) $moneda['id'] ?>" data-clave="<?= htmlspecialchars((string) $moneda['clave']) ?>" <?= ($modoEdicion ? (int) $facturaPendiente['moneda_id'] === (int) $moneda['id'] : $moneda['clave'] === 'MXN') ? 'selected' : '' ?>><?= htmlspecialchars($moneda['clave'] . ' · ' . $moneda['descripcion']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-3" id="exchangeGroup"><label for="exchangeInput" class="form-label">Tipo de cambio</label><input id="exchangeInput" type="number" min="0.000001" step="0.000001" value="1" class="form-control" readonly></div>
                            <div id="paymentMethodGroup" class="col-md-6<?= $facturaCompletaInicial ? '' : ' d-none' ?>"><label for="paymentMethodSelect" class="form-label">Método de pago</label><select id="paymentMethodSelect" class="form-select" required><?php foreach (($catalogos['metodos_pago'] ?? []) as $metodo): ?><option value="<?= (int) $metodo['id'] ?>" data-clave="<?= htmlspecialchars((string) $metodo['clave']) ?>" <?= ($modoEdicion ? (int) $facturaPendiente['metodo_pago_id'] === (int) $metodo['id'] : $metodo['clave'] === 'PUE') ? 'selected' : '' ?>><?= htmlspecialchars($metodo['clave'] . ' · ' . $metodo['descripcion']) ?></option><?php endforeach; ?></select></div>
                            <div id="paymentFormGroup" class="<?= $facturaCompletaInicial ? 'col-md-6' : 'col-12' ?>"><label for="paymentFormSelect" class="form-label">Forma de pago</label><select id="paymentFormSelect" class="form-select" required><?php foreach (($catalogos['formas_pago'] ?? []) as $forma): ?><option value="<?= (int) $forma['id'] ?>" data-clave="<?= htmlspecialchars((string) $forma['clave']) ?>" <?= ($modoEdicion ? (int) $facturaPendiente['forma_pago_id'] === (int) $forma['id'] : $forma['clave'] === '03') ? 'selected' : '' ?>><?= htmlspecialchars($forma['clave'] . ' · ' . $forma['descripcion']) ?></option><?php endforeach; ?></select></div>
                            <div id="cfdiUseGroup" class="col-md-7<?= $facturaCompletaInicial ? '' : ' d-none' ?>"><label for="cfdiUseSelect" class="form-label">Uso CFDI</label><select id="cfdiUseSelect" class="form-select" required><option value="">Seleccionar uso...</option><?php foreach (($catalogos['usos_cfdi'] ?? []) as $uso): ?><option value="<?= htmlspecialchars((string) $uso['clave']) ?>" data-fisica="<?= !empty($uso['fisica']) ? '1' : '0' ?>" data-moral="<?= !empty($uso['moral']) ? '1' : '0' ?>" <?= ($modoEdicion ? (string) $facturaPendiente['uso_cfdi'] === (string) $uso['clave'] : $uso['clave'] === 'G03') ? 'selected' : '' ?>><?= htmlspecialchars($uso['clave'] . ' · ' . $uso['descripcion']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-5 invoice-advanced-field<?= $facturaCompletaInicial ? '' : ' d-none' ?>"><label for="exportSelect" class="form-label">Exportación</label><select id="exportSelect" class="form-select" required <?= $modoEdicion ? 'disabled' : '' ?>><?php foreach (($catalogos['exportaciones'] ?? []) as $exportacion): ?><option value="<?= htmlspecialchars((string) $exportacion['clave']) ?>"><?= htmlspecialchars($exportacion['clave'] . ' · ' . $exportacion['descripcion']) ?></option><?php endforeach; ?></select><?php if ($modoEdicion): ?><small class="text-muted">El esquema actual solo permite conservar 01.</small><?php endif; ?></div>
                            <div id="vatRateGroup" class="<?= $facturaCompletaInicial ? 'col-md-4' : 'col-12' ?>"><label for="vatRateDisplay" class="form-label">IVA trasladado</label><input id="vatRateDisplay" class="form-control invoice-readonly" value="Selecciona un perfil fiscal" readonly><small id="vatRateHelp" class="text-muted">La tasa se calcula en cada concepto.</small></div>
                            <div class="col-md-4 invoice-advanced-field<?= $facturaCompletaInicial ? '' : ' d-none' ?>"><label for="withholdingIsrInput" class="form-label">Retención ISR (%)</label><input id="withholdingIsrInput" type="number" class="form-control" min="0" max="100" step="0.000001" value="<?= htmlspecialchars((string) ($modoEdicion ? ($facturaPendiente['retencion_isr_tasa'] ?? 0) : 0)) ?>"><small class="text-muted">Para persona moral: 10%.</small></div>
                            <div class="col-md-4 invoice-advanced-field<?= $facturaCompletaInicial ? '' : ' d-none' ?>"><label for="withholdingVatInput" class="form-label">Retención IVA (%)</label><input id="withholdingVatInput" type="number" class="form-control" min="0" max="100" step="0.000001" value="<?= htmlspecialchars((string) ($modoEdicion ? ($facturaPendiente['retencion_iva_tasa'] ?? 0) : 0)) ?>"><small class="text-muted">Para persona moral: 10.6667%.</small></div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div class="d-flex align-items-center gap-2"><span class="invoice-step bg-success-subtle text-success">3</span><div><h5 class="mb-0">Conceptos facturables</h5><small class="text-muted"><?= count($conceptos) ?> conceptos disponibles para la empresa activa.</small></div></div>
                        <button id="addItemButton" type="button" class="btn btn-sm btn-soft-primary"><i data-lucide="plus" class="fs-16 me-1"></i>Agregar concepto</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 invoice-concepts-table">
                            <thead><tr><th class="ps-3">Concepto</th><th>Cantidad</th><th>Precio unitario</th><th>Descuento</th><th>Importe</th><th>IVA</th><th>Total</th><th></th></tr></thead>
                            <tbody id="invoiceItems"></tbody>
                        </table>
                    </div>
                    <div id="emptyItems" class="text-center py-5 text-muted"><i data-lucide="package-plus" style="width:34px;height:34px" class="mb-2"></i><p class="mb-0">Agrega el primer concepto de la factura.</p></div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card invoice-total-panel">
                    <div class="card-header"><h5 class="mb-0">Resumen</h5></div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Subtotal</span><strong id="summarySubtotal" class="invoice-money">$0.00</strong></div>
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Descuento</span><strong id="summaryDiscount" class="invoice-money text-danger">-$0.00</strong></div>
                        <div class="d-flex justify-content-between mb-3"><span class="text-muted">IVA trasladado</span><strong id="summaryTax" class="invoice-money">$0.00</strong></div>
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">ISR retenido</span><strong id="summaryWithholdingIsr" class="invoice-money text-danger">-$0.00</strong></div>
                        <div class="d-flex justify-content-between mb-3"><span class="text-muted">IVA retenido</span><strong id="summaryWithholdingVat" class="invoice-money text-danger">-$0.00</strong></div>
                        <div class="border-top pt-3 d-flex justify-content-between align-items-center"><span class="fw-semibold">Total</span><span id="summaryTotal" class="fs-3 fw-bold text-primary invoice-money">$0.00</span></div>
                        <div class="alert alert-warning fs-13 mt-3 mb-3"><?= $modoEdicion ? 'Guardar actualizará únicamente esta factura pendiente.' : 'La validación no guardará la factura.' ?></div>
                        <button id="validateButton" type="submit" class="btn btn-primary w-100" <?= empty($emisor['completo']) ? 'disabled' : '' ?>><i data-lucide="<?= $modoEdicion ? 'save' : 'file-check-2' ?>" class="fs-17 me-1"></i><?= $modoEdicion ? 'Guardar cambios' : 'Validar y previsualizar' ?></button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
            <div class="modal-header"><div><h5 class="modal-title">Vista previa CFDI 4.0</h5><p class="text-muted mb-0 fs-13"><?= $modoEdicion ? 'Cambios guardados en la factura pendiente; aún no tiene validez fiscal.' : 'Documento no persistido y sin validez fiscal.' ?></p></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="previewContent"></div>
            <div class="modal-footer"><button id="saveInvoiceButton" type="button" class="btn btn-primary<?= $modoEdicion ? ' d-none' : '' ?>">Guardar y timbrar factura</button><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button></div>
        </div></div>
    </div>
<?php endif; ?>

<?php
$configuracionJs = json_encode([
    'csrf' => (string) $_SESSION['facturacion_csrf'],
    'conceptos' => $conceptos,
    'edicion' => $modoEdicion ? $facturaPendiente : null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
$pageScripts = $facturacionError === '' ? '<script>window.facturacionConfig=' . $configuracionJs . ';</script>' . <<<'HTML'
<script>
(async function () {
    'use strict';
    const config = window.facturacionConfig;
    const form = document.getElementById('invoiceForm');
    const clientSelect = document.getElementById('clientSelect');
    const profileSelect = document.getElementById('profileSelect');
    const receiverSummary = document.getElementById('receiverSummary');
    const currencySelect = document.getElementById('currencySelect');
    const exchangeInput = document.getElementById('exchangeInput');
    const paymentMethodGroup = document.getElementById('paymentMethodGroup');
    const paymentMethodSelect = document.getElementById('paymentMethodSelect');
    const paymentFormGroup = document.getElementById('paymentFormGroup');
    const paymentFormSelect = document.getElementById('paymentFormSelect');
    const useSelect = document.getElementById('cfdiUseSelect');
    const useGroup = document.getElementById('cfdiUseGroup');
    const invoiceTypeSwitch = document.getElementById('invoiceTypeSwitch');
    const invoiceTypeLabel = document.getElementById('invoiceTypeLabel');
    const invoiceTypeHint = document.getElementById('invoiceTypeHint');
    const advancedFields = [...document.querySelectorAll('.invoice-advanced-field')];
    const exportSelect = document.getElementById('exportSelect');
    const vatRateGroup = document.getElementById('vatRateGroup');
    const vatRateDisplay = document.getElementById('vatRateDisplay');
    const vatRateHelp = document.getElementById('vatRateHelp');
    const withholdingIsrInput = document.getElementById('withholdingIsrInput');
    const withholdingVatInput = document.getElementById('withholdingVatInput');
    const itemsBody = document.getElementById('invoiceItems');
    const emptyItems = document.getElementById('emptyItems');
    const messages = document.getElementById('invoiceMessages');
    const validateButton = document.getElementById('validateButton');
    const saveInvoiceButton = document.getElementById('saveInvoiceButton');
    const previewContent = document.getElementById('previewContent');
    const concepts = new Map(config.conceptos.map(item => [String(item.id), item]));
    const editing = config.edicion;
    let profiles = new Map();
    let rowSequence = 0;
    let validatedPayload = null;
    let receiverPersonType = '';

    function money(value) {
        const currency = selectedCode(currencySelect) || 'MXN';
        return new Intl.NumberFormat('es-MX', {style: 'currency', currency: currency}).format(Number(value) || 0);
    }

    function escapeHtml(value) {
        const node = document.createElement('div');
        node.textContent = String(value ?? '');
        return node.innerHTML;
    }

    function selectedCode(select) {
        return select.options[select.selectedIndex]?.dataset.clave || '';
    }

    function isCompleteInvoice() {
        return invoiceTypeSwitch.checked;
    }

    function applyInvoiceType() {
        const complete = isCompleteInvoice();
        invoiceTypeLabel.textContent = complete ? 'Factura sencilla' : 'Factura completa';
        advancedFields.forEach(field => field.classList.toggle('d-none', !complete));
        paymentMethodGroup.classList.toggle('d-none', !complete);
        useGroup.classList.toggle('d-none', !complete);
        paymentFormGroup.classList.toggle('col-md-6', complete);
        paymentFormGroup.classList.toggle('col-12', !complete);
        vatRateGroup.classList.toggle('col-md-4', complete);
        vatRateGroup.classList.toggle('col-12', !complete);
        exportSelect.required = complete;
        paymentMethodSelect.required = complete;
        useSelect.required = complete;
        if (!complete) {
            const pueOption = [...paymentMethodSelect.options].find(option => option.dataset.clave === 'PUE');
            if (pueOption) paymentMethodSelect.value = pueOption.value;
            useSelect.value = 'G03';
        }
        updateVatDisplay();
        calculate();
    }

    function personTypeFromRfc(rfc) {
        const length = String(rfc || '').toUpperCase().replace(/[^A-Z0-9Ñ&]/g, '').length;
        if (length === 13) return 'fisica';
        if (length === 12) return 'moral';
        return '';
    }

    function updateVatDisplay() {
        if (receiverPersonType === 'moral') {
            vatRateDisplay.value = '0% o 16% según concepto';
            vatRateHelp.textContent = 'La factura puede combinar conceptos con ambas tasas.';
            return;
        }
        if (receiverPersonType === 'fisica') {
            vatRateDisplay.value = '16%';
            vatRateHelp.textContent = 'Se aplica 16% a los conceptos gravados.';
            return;
        }
        vatRateDisplay.value = 'Selecciona un perfil fiscal';
        vatRateHelp.textContent = 'El IVA se determinará con la longitud del RFC receptor.';
    }

    function resetReceiverTaxRule() {
        receiverPersonType = '';
        invoiceTypeSwitch.disabled = false;
        invoiceTypeHint.textContent = 'Selección manual; el RFC ajusta los impuestos.';
        withholdingIsrInput.value = '0';
        withholdingVatInput.value = '0';
        applyInvoiceType();
    }

    function applyReceiverTaxRule(profile) {
        receiverPersonType = personTypeFromRfc(profile?.rfc);
        if (!receiverPersonType) {
            invoiceTypeSwitch.disabled = false;
            invoiceTypeHint.textContent = 'RFC no clasificable; la selección del tipo sigue siendo manual.';
            updateVatDisplay();
            calculate();
            return;
        }
        const moral = receiverPersonType === 'moral';
        invoiceTypeSwitch.disabled = false;
        invoiceTypeHint.textContent = moral
            ? 'Persona moral detectada; elige sencilla o completa.'
            : 'Persona física detectada; elige sencilla o completa.';
        withholdingIsrInput.value = moral ? '10' : '0';
        withholdingVatInput.value = moral ? '10.6667' : '0';
        applyInvoiceType();
        [...itemsBody.rows].forEach(row => conceptChanged(row, false));
    }

    function showMessages(errors, warnings) {
        const blocks = [];
        if (errors?.length) blocks.push('<div class="alert alert-danger"><strong>Corrige lo siguiente:</strong><ul class="mb-0 mt-2">' + errors.map(error => '<li>' + escapeHtml(error) + '</li>').join('') + '</ul></div>');
        if (warnings?.length) blocks.push('<div class="alert alert-warning"><ul class="mb-0">' + warnings.map(warning => '<li>' + escapeHtml(warning) + '</li>').join('') + '</ul></div>');
        messages.innerHTML = blocks.join('');
        if (errors?.length) messages.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    async function loadProfiles() {
        profiles = new Map();
        resetReceiverTaxRule();
        profileSelect.disabled = true;
        profileSelect.replaceChildren(new Option('Cargando perfiles...', ''));
        receiverSummary.className = 'invoice-fiscal-summary border rounded-3 p-3 bg-body-tertiary text-muted d-flex align-items-center';
        receiverSummary.textContent = 'Consultando perfiles fiscales del cliente...';
        if (!clientSelect.value) {
            profileSelect.replaceChildren(new Option('Selecciona primero un cliente...', ''));
            receiverSummary.textContent = 'Selecciona un cliente y uno de sus perfiles fiscales.';
            return;
        }
        try {
            const response = await fetch('api/facturacion.php?accion=perfiles&cliente_id=' + encodeURIComponent(clientSelect.value), {headers: {'Accept': 'application/json'}});
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.error || 'No fue posible consultar los perfiles.');
            const options = [new Option('Seleccionar perfil fiscal...', '')];
            payload.datos.perfiles.forEach(profile => {
                profiles.set(String(profile.id), profile);
                const suffix = profile.completo ? '' : ' · INCOMPLETO';
                const option = new Option(profile.clave_corta + ' · ' + profile.razon_social + ' · ' + profile.rfc + suffix, profile.id);
                option.dataset.complete = profile.completo ? '1' : '0';
                options.push(option);
            });
            profileSelect.replaceChildren(...options);
            profileSelect.disabled = false;
            receiverSummary.textContent = payload.datos.perfiles.length ? 'Selecciona el perfil fiscal que se usará como receptor.' : 'Este cliente no tiene perfiles en claves_cortas.';
        } catch (error) {
            profileSelect.replaceChildren(new Option('No fue posible cargar perfiles', ''));
            receiverSummary.className = 'invoice-fiscal-summary border border-danger rounded-3 p-3 bg-danger-subtle text-danger d-flex align-items-center';
            receiverSummary.textContent = error.message;
        }
    }

    function showProfile() {
        const profile = profiles.get(profileSelect.value);
        if (!profile) {
            resetReceiverTaxRule();
            receiverSummary.className = 'invoice-fiscal-summary border rounded-3 p-3 bg-body-tertiary text-muted d-flex align-items-center';
            receiverSummary.textContent = 'Selecciona el perfil fiscal que se usará como receptor.';
            return;
        }
        const detectedPersonType = personTypeFromRfc(profile.rfc);
        const personBadge = detectedPersonType
            ? '<span class="badge badge-soft-info">Persona ' + (detectedPersonType === 'fisica' ? 'física' : 'moral') + '</span>'
            : '';
        receiverSummary.className = 'invoice-fiscal-summary border rounded-3 p-3 ' + (profile.completo ? 'border-success bg-success-subtle' : 'border-danger bg-danger-subtle');
        const title = document.createElement('div');
        title.innerHTML = '<div class="d-flex flex-wrap justify-content-between gap-2"><strong>' + escapeHtml(profile.razon_social) + '</strong><span class="d-flex gap-1">' + personBadge + '<span class="badge ' + (profile.completo ? 'badge-soft-success' : 'badge-soft-danger') + '">' + (profile.completo ? 'Perfil completo' : 'Perfil incompleto') + '</span></span></div>' +
            '<div class="mt-2"><span class="font-monospace me-3">RFC ' + escapeHtml(profile.rfc) + '</span><span class="me-3">CP ' + escapeHtml(profile.cp || '—') + '</span><span>Régimen ' + escapeHtml(profile.regimen_fiscal || '—') + '</span></div>' +
            (profile.errores.length ? '<small class="d-block text-danger mt-2">' + escapeHtml(profile.errores.join(' ')) + '</small>' : '');
        receiverSummary.replaceChildren(title);
        filterUses(profile.tipo_persona, profile.regimen_fiscal);
        applyReceiverTaxRule(profile);
    }

    function filterUses(personType, regime) {
        [...useSelect.options].forEach(option => {
            if (!option.value) return;
            let enabled = option.dataset[personType] === '1';
            if (option.value === 'CN01' && regime !== '605') enabled = false;
            option.disabled = !enabled;
        });
        if (useSelect.selectedOptions[0]?.disabled) useSelect.value = '';
    }

    function createConceptSelect() {
        const select = document.createElement('select');
        select.className = 'form-select form-select-sm item-concept';
        select.required = true;
        select.append(new Option('Seleccionar concepto...', ''));
        config.conceptos.forEach(concept => {
            const option = new Option(concept.clave_sat + ' · ' + concept.concepto + (concept.historico ? ' · Histórico' : ''), concept.id);
            option.disabled = !concept.disponible;
            select.append(option);
        });
        return select;
    }

    function addItem(initial = null) {
        rowSequence += 1;
        const row = document.createElement('tr');
        row.dataset.row = String(rowSequence);
        row.dataset.detailId = String(initial?.detalle_id || 0);
        row.innerHTML = '<td class="ps-3"><div class="concept-select-slot"></div><textarea class="form-control form-control-sm item-description mt-1" rows="2" maxlength="1000" placeholder="Descripción del concepto"></textarea><small class="item-help text-muted"></small></td>' +
            '<td><input type="number" class="form-control form-control-sm item-quantity" min="0.000001" step="0.000001" value="1"></td>' +
            '<td><input type="number" class="form-control form-control-sm item-price" min="0" step="0.000001" value="0"></td>' +
            '<td><input type="number" class="form-control form-control-sm item-discount" min="0" step="0.01" value="0" ' + (editing ? 'disabled title="El esquema actual no almacena descuentos por partida"' : '') + '></td>' +
            '<td class="item-subtotal invoice-money text-nowrap">$0.00</td><td class="item-tax invoice-money text-nowrap">$0.00</td><td class="item-total invoice-money fw-semibold text-nowrap">$0.00</td>' +
            '<td class="pe-3"><button type="button" class="btn btn-sm btn-soft-danger item-remove" aria-label="Eliminar concepto"><i data-lucide="trash-2" class="fs-15"></i></button></td>';
        const select = createConceptSelect();
        row.querySelector('.concept-select-slot').append(select);
        itemsBody.append(row);
        emptyItems.classList.add('d-none');
        if (initial) {
            select.value = String(initial.concepto_id || '');
            row.querySelector('.item-quantity').value = initial.cantidad;
            row.querySelector('.item-price').value = initial.precio_unitario;
            row.querySelector('.item-discount').value = initial.descuento || 0;
            row.querySelector('.item-description').value = initial.descripcion || '';
            conceptChanged(row, false);
        } else {
            select.focus();
        }
        if (window.lucide) window.lucide.createIcons();
        calculate();
    }

    function rowValues(row) {
        const concept = concepts.get(row.querySelector('.item-concept').value);
        const quantity = Number(row.querySelector('.item-quantity').value) || 0;
        const price = Number(row.querySelector('.item-price').value) || 0;
        const discount = Number(row.querySelector('.item-discount').value) || 0;
        const subtotal = quantity * price;
        const base = Math.max(0, subtotal - discount);
        const taxRate = receiverPersonType === 'fisica' && concept?.objeto_impuesto === '02'
            ? 16
            : Number(concept?.tasa_iva) || 0;
        const tax = concept?.objeto_impuesto === '02' ? base * (taxRate / 100) : 0;
        return {concept, quantity, price, discount, subtotal, taxRate, tax, total: base + tax};
    }

    function calculate() {
        let subtotal = 0, discount = 0, tax = 0, withholdingIsr = 0, withholdingVat = 0, total = 0;
        const withholdingIsrRate = isCompleteInvoice() ? Number(withholdingIsrInput.value) || 0 : 0;
        const withholdingVatRate = isCompleteInvoice() ? Number(withholdingVatInput.value) || 0 : 0;
        [...itemsBody.rows].forEach(row => {
            const values = rowValues(row);
            const taxableBase = values.concept?.objeto_impuesto === '02' ? Math.max(0, values.subtotal - values.discount) : 0;
            const rowWithholdingIsr = taxableBase * withholdingIsrRate / 100;
            const rowWithholdingVat = taxableBase * withholdingVatRate / 100;
            subtotal += values.subtotal;
            discount += values.discount;
            tax += values.tax;
            withholdingIsr += rowWithholdingIsr;
            withholdingVat += rowWithholdingVat;
            total += values.total - rowWithholdingIsr - rowWithholdingVat;
            row.querySelector('.item-subtotal').textContent = money(values.subtotal);
            row.querySelector('.item-tax').textContent = money(values.tax);
            row.querySelector('.item-total').textContent = money(values.total);
        });
        document.getElementById('summarySubtotal').textContent = money(subtotal);
        document.getElementById('summaryDiscount').textContent = '-' + money(discount);
        document.getElementById('summaryTax').textContent = money(tax);
        document.getElementById('summaryWithholdingIsr').textContent = '-' + money(withholdingIsr);
        document.getElementById('summaryWithholdingVat').textContent = '-' + money(withholdingVat);
        document.getElementById('summaryTotal').textContent = money(total);
    }

    function conceptChanged(row, resetValues = true) {
        const concept = concepts.get(row.querySelector('.item-concept').value);
        const help = row.querySelector('.item-help');
        if (!concept) {
            help.textContent = '';
            return calculate();
        }
        if (resetValues) {
            row.querySelector('.item-price').value = concept.precio_min;
            row.querySelector('.item-description').value = concept.concepto;
        }
        row.querySelector('.item-price').min = '0';
        row.querySelector('.item-price').removeAttribute('max');
        if (concept.unidades_max) row.querySelector('.item-quantity').max = concept.unidades_max;
        else row.querySelector('.item-quantity').removeAttribute('max');
        const taxRate = receiverPersonType === 'fisica' && concept.objeto_impuesto === '02'
            ? 16
            : concept.tasa_iva;
        help.textContent = concept.clave_unidad_medida + ' · ObjetoImp ' + concept.objeto_impuesto + ' · IVA ' + taxRate + '% · Precio sugerido ' + money(concept.precio_min);
        calculate();
    }

    function payload() {
        return {
            csrf: config.csrf,
            tipo_factura: isCompleteInvoice() ? 'completa' : 'sencilla',
            factura_id: editing?.id || 0,
            huella: editing?.huella || '',
            fecha: document.getElementById('invoiceDate').value,
            cliente_id: Number(clientSelect.value),
            perfil_id: Number(profileSelect.value),
            moneda_id: Number(currencySelect.value),
            tipo_cambio: Number(exchangeInput.value),
            metodo_pago_id: Number(paymentMethodSelect.value),
            forma_pago_id: Number(paymentFormSelect.value),
            uso_cfdi: useSelect.value,
            exportacion: isCompleteInvoice() ? exportSelect.value : '01',
            retencion_isr_tasa: isCompleteInvoice() ? Number(withholdingIsrInput.value) || 0 : 0,
            retencion_iva_tasa: isCompleteInvoice() ? Number(withholdingVatInput.value) || 0 : 0,
            partidas: [...itemsBody.rows].map(row => ({
                detalle_id: Number(row.dataset.detailId || 0),
                concepto_id: Number(row.querySelector('.item-concept').value),
                descripcion: row.querySelector('.item-description').value.trim(),
                cantidad: Number(row.querySelector('.item-quantity').value),
                precio_unitario: Number(row.querySelector('.item-price').value),
                descuento: Number(row.querySelector('.item-discount').value),
            })),
        };
    }

    function renderPreview(result) {
        const status = result.valido
            ? '<div class="alert alert-success"><strong>' + (result.persistido ? 'Los cambios fueron guardados en la factura pendiente.' : 'La estructura pasó las validaciones locales. No fue guardada ni timbrada.') + '</strong></div>'
            : '<div class="alert alert-danger"><strong>La estructura todavía contiene errores.</strong></div>';
        const errors = result.errores.length ? '<div class="alert alert-danger"><ul class="mb-0">' + result.errores.map(error => '<li>' + escapeHtml(error) + '</li>').join('') + '</ul></div>' : '';
        const warnings = '<div class="alert alert-warning"><ul class="mb-0">' + result.advertencias.map(warning => '<li>' + escapeHtml(warning) + '</li>').join('') + '</ul></div>';
        const receiver = result.receptor || {};
        const rows = result.partidas.map(item => '<tr><td>' + item.numero + '</td><td><span class="font-monospace">' + escapeHtml(item.clave_prod_serv) + '</span><small class="d-block text-muted">' + escapeHtml(item.descripcion) + '</small></td><td>' + escapeHtml(item.clave_unidad) + '</td><td class="text-end">' + item.cantidad + '</td><td class="text-end">' + money(item.valor_unitario) + '</td><td class="text-end">' + money(item.iva) + '</td><td class="text-end fw-semibold">' + money(item.total) + '</td></tr>').join('');
        previewContent.innerHTML = status + errors + warnings +
            '<div class="row g-3 mb-4"><div class="col-md-6"><div class="border rounded-3 p-3 h-100"><small class="text-uppercase text-muted">Emisor</small><h6 class="mt-2">' + escapeHtml(result.emisor.nombre) + '</h6><div class="font-monospace">' + escapeHtml(result.emisor.rfc) + '</div><div>Régimen ' + escapeHtml(result.emisor.regimen_fiscal) + '</div></div></div>' +
            '<div class="col-md-6"><div class="border rounded-3 p-3 h-100"><small class="text-uppercase text-muted">Receptor</small><h6 class="mt-2">' + escapeHtml(receiver.nombre || 'Sin receptor válido') + '</h6><div class="font-monospace">' + escapeHtml(receiver.rfc || '—') + '</div><div>Régimen ' + escapeHtml(receiver.regimen_fiscal || '—') + ' · CP ' + escapeHtml(receiver.domicilio_fiscal || '—') + '</div></div></div></div>' +
            '<div class="table-responsive"><table class="table"><thead><tr><th>#</th><th>Concepto</th><th>Unidad</th><th class="text-end">Cantidad</th><th class="text-end">Precio</th><th class="text-end">IVA</th><th class="text-end">Total</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
            '<div class="row justify-content-end"><div class="col-md-5"><div class="border rounded-3 p-3"><div class="d-flex justify-content-between"><span>Subtotal</span><strong>' + money(result.comprobante.subtotal) + '</strong></div><div class="d-flex justify-content-between"><span>Descuento</span><strong>-' + money(result.comprobante.descuento) + '</strong></div><div class="d-flex justify-content-between"><span>IVA trasladado</span><strong>' + money(result.comprobante.iva) + '</strong></div><div class="d-flex justify-content-between"><span>ISR retenido</span><strong>-' + money(result.comprobante.retencion_isr) + '</strong></div><div class="d-flex justify-content-between"><span>IVA retenido</span><strong>-' + money(result.comprobante.retencion_iva) + '</strong></div><hr><div class="d-flex justify-content-between fs-5"><span>Total ' + escapeHtml(result.comprobante.moneda) + '</span><strong>' + money(result.comprobante.total) + '</strong></div></div></div></div>';
        if (saveInvoiceButton && !editing) saveInvoiceButton.disabled = !result.valido;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('previewModal')).show();
    }

    clientSelect.addEventListener('change', loadProfiles);
    profileSelect.addEventListener('change', showProfile);
    currencySelect.addEventListener('change', function () {
        const isMxn = selectedCode(currencySelect) === 'MXN';
        exchangeInput.readOnly = isMxn;
        if (isMxn) exchangeInput.value = '1';
    });
    paymentMethodSelect.addEventListener('change', function () {
        if (selectedCode(paymentMethodSelect) === 'PPD') {
            const option = [...paymentFormSelect.options].find(item => item.dataset.clave === '99');
            if (option) paymentFormSelect.value = option.value;
        } else if (['30', '99'].includes(selectedCode(paymentFormSelect))) {
            const option = [...paymentFormSelect.options].find(item => item.dataset.clave === '03');
            if (option) paymentFormSelect.value = option.value;
        }
    });
    document.getElementById('addItemButton').addEventListener('click', () => addItem());
    itemsBody.addEventListener('change', event => {
        const row = event.target.closest('tr');
        if (event.target.classList.contains('item-concept')) conceptChanged(row); else calculate();
    });
    itemsBody.addEventListener('input', calculate);
    withholdingIsrInput.addEventListener('input', calculate);
    withholdingVatInput.addEventListener('input', calculate);
    invoiceTypeSwitch.addEventListener('change', () => {
        const profile = profiles.get(profileSelect.value);
        if (profile) {
            applyReceiverTaxRule(profile);
            return;
        }
        applyInvoiceType();
    });
    saveInvoiceButton?.addEventListener('click', async () => {
        if (!validatedPayload || saveInvoiceButton.disabled) return;
        previewContent.querySelectorAll('.save-invoice-feedback').forEach(element => element.remove());
        saveInvoiceButton.disabled = true;
        saveInvoiceButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Guardando y timbrando...';
        try {
            const response = await fetch('api/facturas.php?accion=guardar', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify(validatedPayload),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                const error = new Error(data.error || 'No fue posible guardar la factura.');
                error.details = data.errores || [];
                error.saved = data.guardada === true;
                error.invoice = data.factura || null;
                throw error;
            }
            const invoiceWasStamped = Boolean(data.factura.uuid);
            const xmlLabel = invoiceWasStamped ? 'Descargar XML timbrado' : 'Descargar XML sin firma';
            const pdfLabel = invoiceWasStamped ? 'Descargar PDF' : 'Descargar PDF de prueba';
            await Swal.fire({
                icon: 'success',
                title: 'Factura guardada y timbrada',
                html: '<p class="mb-2">' + escapeHtml(data.mensaje) + '</p>'
                    + '<div><strong>Folio:</strong> ' + escapeHtml(data.factura.serie + '-' + data.factura.folio) + '</div>'
                    + (data.factura.uuid ? '<div class="mt-1"><strong>Folio fiscal:</strong><br><span class="font-monospace">' + escapeHtml(data.factura.uuid) + '</span></div>' : '')
                    + '<div class="mt-3"><a href="' + escapeHtml(data.factura.xml.url) + '" download>' + xmlLabel + '</a>'
                    + ' · <a href="api/factura-pdf.php?id=' + encodeURIComponent(data.factura.id) + '" download>' + pdfLabel + '</a></div>',
                confirmButtonText: 'Aceptar',
                confirmButtonColor: '#16a34a',
            });
            saveInvoiceButton.innerHTML = '<i data-lucide="circle-check" class="fs-17 me-1"></i>Factura guardada';
            validatedPayload = null;
            if (window.lucide) window.lucide.createIcons();
        } catch (error) {
            const details = error.details?.length ? error.details : [error.message];
            const title = error.saved ? 'La factura quedó guardada.' : 'No se pudo guardar la factura.';
            const savedInfo = error.saved && error.invoice ? '<div class="mt-2">Folio ' + escapeHtml(error.invoice.serie + '-' + error.invoice.folio) + ' · <a href="' + escapeHtml(error.invoice.xml.url) + '" download>Descargar XML sin firma</a></div>' : '';
            await Swal.fire({
                icon: error.saved ? 'warning' : 'error',
                title: title,
                html: '<ul class="text-start mb-0">' + details.map(detail => '<li>' + escapeHtml(detail) + '</li>').join('') + '</ul>' + savedInfo,
                confirmButtonText: 'Aceptar',
                confirmButtonColor: error.saved ? '#d97706' : '#dc2626',
            });
            saveInvoiceButton.disabled = error.saved;
            saveInvoiceButton.innerHTML = error.saved
                ? '<i data-lucide="alert-triangle" class="fs-17 me-1"></i>Guardada sin timbrar'
                : '<i data-lucide="save" class="fs-17 me-1"></i>Guardar y timbrar factura';
            if (error.saved) validatedPayload = null;
            if (window.lucide) window.lucide.createIcons();
        }
    });
    itemsBody.addEventListener('click', event => {
        const button = event.target.closest('.item-remove');
        if (!button) return;
        button.closest('tr').remove();
        emptyItems.classList.toggle('d-none', itemsBody.rows.length > 0);
        calculate();
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        messages.innerHTML = '';
        validateButton.disabled = true;
        validateButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + (editing ? 'Guardando...' : 'Validando...');
        try {
            const invoicePayload = payload();
            const response = await fetch('api/facturacion.php?accion=' + (editing ? 'guardar' : 'validar'), {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify(invoicePayload),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                const error = new Error(data.error || 'No fue posible procesar la factura.');
                error.details = data.errores || [];
                throw error;
            }
            if (editing && data.factura?.huella) editing.huella = data.factura.huella;
            validatedPayload = !editing && data.resultado.valido ? invoicePayload : null;
            showMessages(data.resultado.errores, []);
            renderPreview(data.resultado);
        } catch (error) {
            showMessages(error.details?.length ? error.details : [error.message], []);
        } finally {
            validateButton.disabled = false;
            validateButton.innerHTML = editing
                ? '<i data-lucide="save" class="fs-17 me-1"></i>Guardar cambios'
                : '<i data-lucide="file-check-2" class="fs-17 me-1"></i>Validar y previsualizar';
            if (window.lucide) window.lucide.createIcons();
        }
    });

    applyInvoiceType();
    if (editing) {
        await loadProfiles();
        profileSelect.value = String(editing.perfil_id || '');
        showProfile();
        editing.partidas.forEach(partida => addItem(partida));
        if (!editing.partidas.length) addItem();
    } else {
        addItem();
    }
})();
</script>
HTML : '';
require 'templates/scripts.php';
?>
