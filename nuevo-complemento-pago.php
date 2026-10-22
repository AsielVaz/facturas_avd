<?php

declare(strict_types=1);

require_once __DIR__ . '/api/Autenticacion.php';
require_once __DIR__ . '/api/ComplementoPagoAdministrador.php';
Autenticacion::exigirPagina();
SesionEmpresa::iniciar();
$_SESSION['complemento_pago_csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['complemento_timbrado_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$contexto = ['clientes' => [], 'formas_pago' => []];
try {
    $contexto = (new ComplementoPagoAdministrador(Conexion::obtener()))->contexto();
} catch (Throwable $excepcion) {
    error_log('Error al preparar el complemento de pago: ' . $excepcion->getMessage());
    $error = 'No fue posible cargar los datos para el complemento.';
}
$esc = static fn(mixed $valor): string => htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$repararFormaPago = static function (string $texto): string {
    return preg_replace_callback(
        '/Ã[^\x00-\x7F]|Â[^\x00-\x7F]|â[^\x00-\x7F]{2}/u',
        static function (array $coincidencia): string {
            $reparado = @iconv('UTF-8', 'Windows-1252', $coincidencia[0]);
            return is_string($reparado) && preg_match('//u', $reparado) === 1
                ? $reparado
                : $coincidencia[0];
        },
        $texto
    ) ?? $texto;
};
$pageTitle = 'Nuevo complemento de pago';
$pageEyebrow = 'Facturas / Complementos de pago';
$pageAction = '<a class="btn btn-soft-secondary" href="complementos-pago.php">Volver al listado</a>';
require __DIR__ . '/templates/page-start.php';
?>
<style>
    .complemento-acciones-sticky {
        position: sticky;
        bottom: 0;
        z-index: 10;
        background: var(--bs-card-bg);
        box-shadow: 0 -0.35rem 1rem rgba(0, 0, 0, 0.12);
    }
</style>

<?php if ($error !== ''): ?><div class="alert alert-danger"><?= $esc($error) ?></div><?php endif; ?>
<div class="alert alert-warning">
    Puedes guardar el complemento como <strong>pendiente</strong> para timbrarlo después, o guardarlo y enviarlo al PAC ahora.
</div>

<form id="complementoForm" novalidate>
    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">Datos del pago</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-lg-6">
                    <label class="form-label" for="cliente">Cliente</label>
                    <select class="form-select" id="cliente" required <?= $error !== '' ? 'disabled' : '' ?>>
                        <option value="">Seleccionar cliente...</option>
                        <?php foreach ($contexto['clientes'] as $cliente): ?>
                        <option value="<?= (int) $cliente['id'] ?>"><?= $esc($cliente['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-6">
                    <label class="form-label" for="perfil">Razón social / perfil fiscal</label>
                    <select class="form-select" id="perfil" required disabled><option value="">Selecciona primero un cliente...</option></select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="formaPago">Forma en que se recibió el pago</label>
                    <select class="form-select" id="formaPago" required <?= $error !== '' ? 'disabled' : '' ?>>
                        <option value="">Seleccionar forma...</option>
                        <?php foreach ($contexto['formas_pago'] as $forma): ?>
                        <?php if ((string) $forma['clave'] !== '99'): ?>
                        <option value="<?= (int) $forma['id'] ?>" <?= (string) $forma['clave'] === '03' ? 'selected' : '' ?>><?= $esc($forma['clave'] . ' · ' . $repararFormaPago((string) $forma['descripcion'])) ?></option>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="fechaPago">Fecha y hora del pago</label>
                    <input class="form-control" type="datetime-local" id="fechaPago" value="<?= $esc(date('Y-m-d\TH:i')) ?>" required <?= $error !== '' ? 'disabled' : '' ?>>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between gap-2 align-items-center">
            <div><h5 class="mb-0">Facturas PPD con saldo</h5><small class="text-muted">Selecciona una o varias facturas del mismo perfil fiscal e indica cuánto se pagó de cada una. Máximo 20 por complemento.</small></div>
            <div class="d-flex flex-wrap align-items-center gap-3">
                <label class="form-check mb-0"><input id="seleccionarTodasFacturas" class="form-check-input" type="checkbox" disabled> Seleccionar todas</label>
                <span id="contadorFacturas" class="badge bg-primary-subtle text-primary">0 seleccionadas</span>
            </div>
        </div>
        <div id="avisoLimiteFacturas" class="alert alert-warning rounded-0 mb-0 d-none" role="status"></div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th scope="col" style="width:42px">#</th><th>Factura</th><th>UUID</th><th>Fecha</th><th>Saldo anterior</th><th>Parcialidad</th><th style="min-width:165px">Monto pagado</th><th>Saldo restante</th></tr></thead>
                <tbody id="facturasBody"><tr><td colspan="8" class="text-center text-muted py-4">Selecciona un cliente y su perfil fiscal para consultar las facturas.</td></tr></tbody>
            </table>
        </div>
    </div>

    <div class="card complemento-acciones-sticky">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div><small class="text-muted d-block">Monto total recibido</small><strong id="totalPago" class="fs-22">$0.00</strong></div>
            <div class="d-flex flex-wrap gap-2">
                <button id="guardarComplemento" class="btn btn-primary" type="submit" disabled><i data-lucide="save" class="fs-17 me-1"></i>Guardar complemento pendiente</button>
                <button id="guardarYTimbrar" class="btn btn-outline-success" type="submit" disabled><i data-lucide="badge-check" class="fs-17 me-1"></i>Guardar y timbrar</button>
            </div>
        </div>
    </div>
</form>

<?php
$configuracion = json_encode([
    'csrf' => $_SESSION['complemento_pago_csrf'],
    'csrfTimbrado' => $_SESSION['complemento_timbrado_csrf'],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
$pageScripts = '<script>window.complementoConfig=' . $configuracion . ';</script>' . <<<'HTML'
<script>
(() => {
    const form = document.getElementById('complementoForm');
    const cliente = document.getElementById('cliente');
    const perfil = document.getElementById('perfil');
    const forma = document.getElementById('formaPago');
    const fecha = document.getElementById('fechaPago');
    const body = document.getElementById('facturasBody');
    const total = document.getElementById('totalPago');
    const contador = document.getElementById('contadorFacturas');
    const seleccionarTodas = document.getElementById('seleccionarTodasFacturas');
    const avisoLimite = document.getElementById('avisoLimiteFacturas');
    const guardar = document.getElementById('guardarComplemento');
    const guardarYTimbrar = document.getElementById('guardarYTimbrar');
    const textoGuardar = guardar.innerHTML;
    const textoTimbrar = guardarYTimbrar.innerHTML;
    const dinero = value => new Intl.NumberFormat('es-MX', {style: 'currency', currency: 'MXN'}).format(value);
    const formatoMonto = value => new Intl.NumberFormat('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(value);
    const montoNumerico = input => Number(input.value.replaceAll(',', ''));
    function separarMiles(input) {
        const anterior = input.value;
        const posicion = input.selectionStart ?? anterior.length;
        const caracteresAntes = anterior.slice(0, posicion).replaceAll(',', '').length;
        const limpio = anterior.replace(/[^\d.]/g, '');
        if (limpio === '') {
            input.value = '';
            return;
        }
        const partes = limpio.split('.');
        const entero = partes[0].replace(/^0+(?=\d)/, '') || '0';
        const decimales = partes.length > 1 ? '.' + partes.slice(1).join('').slice(0, 2) : '';
        input.value = entero.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + decimales;
        let nuevaPosicion = 0;
        let caracteres = 0;
        while (nuevaPosicion < input.value.length && caracteres < caracteresAntes) {
            if (input.value[nuevaPosicion] !== ',') caracteres++;
            nuevaPosicion++;
        }
        input.setSelectionRange(nuevaPosicion, nuevaPosicion);
    }
    const idSolicitud = Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
    let solicitudActual = 0;
    let enProceso = false;

    function aviso(texto, diagnostico = null) {
        const detalles = [texto];
        if (diagnostico?.etapa) detalles.push('Etapa: ' + diagnostico.etapa);
        if (diagnostico?.codigo_sql) detalles.push('Código SQL: ' + diagnostico.codigo_sql);
        if (diagnostico?.referencia) detalles.push('Referencia para el registro del servidor: ' + diagnostico.referencia);
        const mensaje = detalles.join('\n');
        if (window.Swal) return Swal.fire({
            icon: 'error', title: 'No se pudo continuar', text: mensaje,
            didOpen: popup => { popup.querySelector('.swal2-html-container').style.whiteSpace = 'pre-line'; },
        });
        alert(mensaje);
    }
    async function leerRespuesta(response, operacion) {
        const contenido = await response.text();
        try {
            return JSON.parse(contenido);
        } catch {
            throw new Error('El servidor devolvió una respuesta no válida al ' + operacion + ' (HTTP ' + response.status + '). Revisa el registro de PHP.');
        }
    }
    async function obtener(url) {
        const response = await fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
        const payload = await leerRespuesta(response, 'consultar los datos');
        if (!response.ok || !payload.ok) {
            const error = new Error(payload.error || 'No fue posible consultar los datos.');
            error.diagnostico = payload;
            throw error;
        }
        return payload.datos;
    }
    function mostrarVacio(texto) {
        body.replaceChildren();
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 8;
        td.className = 'text-center text-muted py-4';
        td.textContent = texto;
        tr.appendChild(td);
        body.appendChild(tr);
        actualizarTotal();
    }
    function actualizarTotal() {
        let suma = 0;
        let cantidad = 0;
        const filas = [...body.querySelectorAll('tr[data-factura-id]')];
        filas.forEach(tr => {
            const check = tr.querySelector('input[type=checkbox]');
            const monto = tr.querySelector('.monto-pagado');
            const restante = tr.querySelector('.saldo-restante');
            const saldo = Number(tr.dataset.saldo);
            monto.disabled = !check.checked;
            if (check.checked) {
                cantidad++;
                const pagado = montoNumerico(monto) || 0;
                if (pagado > 0 && pagado <= saldo) suma += pagado;
                restante.textContent = dinero(Math.max(0, saldo - pagado));
            } else {
                restante.textContent = dinero(saldo);
            }
        });
        contador.textContent = cantidad + ' seleccionada' + (cantidad === 1 ? '' : 's');
        seleccionarTodas.disabled = enProceso || filas.length === 0;
        seleccionarTodas.checked = filas.length > 0 && cantidad === filas.length;
        seleccionarTodas.indeterminate = cantidad > 0 && cantidad < filas.length;
        const excedeLimite = cantidad > 20;
        avisoLimite.classList.toggle('d-none', !excedeLimite);
        avisoLimite.textContent = excedeLimite
            ? 'Seleccionaste ' + cantidad + ' facturas. Solo se permiten 20 por complemento; desmarca ' + (cantidad - 20) + ' para continuar.'
            : '';
        total.textContent = dinero(suma);
        const deshabilitar = enProceso || !cantidad || excedeLimite || !cliente.value || !perfil.value || !forma.value || !fecha.value;
        guardar.disabled = deshabilitar;
        guardarYTimbrar.disabled = deshabilitar;
    }
    seleccionarTodas.addEventListener('change', () => {
        body.querySelectorAll('tr[data-factura-id] input[type=checkbox]').forEach(check => {
            check.checked = seleccionarTodas.checked;
        });
        actualizarTotal();
    });
    function mostrarFacturas(facturas) {
        body.replaceChildren();
        if (!facturas.length) return mostrarVacio('No hay facturas PPD timbradas con saldo para este perfil fiscal.');
        facturas.forEach(factura => {
            const tr = document.createElement('tr');
            tr.dataset.facturaId = String(factura.id);
            tr.dataset.saldo = String(factura.saldo);
            const agregar = (texto, clase = '') => {
                const td = document.createElement('td');
                td.className = clase;
                td.textContent = texto;
                tr.appendChild(td);
                return td;
            };
            const seleccion = document.createElement('input');
            seleccion.type = 'checkbox';
            seleccion.className = 'form-check-input';
            seleccion.setAttribute('aria-label', 'Seleccionar factura ' + factura.folio);
            agregar('').appendChild(seleccion);
            agregar(factura.folio, 'fw-semibold');
            agregar(factura.uuid, 'font-monospace small');
            agregar(factura.fecha);
            agregar(dinero(factura.saldo));
            agregar(String(factura.siguiente_parcialidad));
            const monto = document.createElement('input');
            monto.type = 'text';
            monto.inputMode = 'decimal';
            monto.className = 'form-control form-control-sm monto-pagado';
            monto.value = formatoMonto(Number(factura.saldo));
            monto.disabled = true;
            monto.setAttribute('aria-label', 'Monto pagado de ' + factura.folio);
            agregar('').appendChild(monto);
            agregar(dinero(factura.saldo), 'saldo-restante');
            seleccion.addEventListener('change', actualizarTotal);
            monto.addEventListener('input', () => { separarMiles(monto); actualizarTotal(); });
            monto.addEventListener('blur', () => {
                const pagado = montoNumerico(monto);
                if (monto.value !== '' && Number.isFinite(pagado)) monto.value = formatoMonto(pagado);
                actualizarTotal();
            });
            body.appendChild(tr);
        });
        actualizarTotal();
    }
    cliente.addEventListener('change', async () => {
        const actual = ++solicitudActual;
        perfil.replaceChildren(new Option('Selecciona primero un cliente...', ''));
        perfil.disabled = true;
        mostrarVacio('Selecciona un perfil fiscal.');
        if (!cliente.value) return;
        try {
            const datos = await obtener('api/complementos-pago-crear.php?accion=perfiles&cliente_id=' + encodeURIComponent(cliente.value));
            if (actual !== solicitudActual) return;
            perfil.replaceChildren(new Option('Seleccionar perfil...', ''));
            datos.perfiles.forEach(item => perfil.add(new Option(item.clave_corta + ' · ' + item.razon_social + ' · ' + item.rfc, item.id)));
            perfil.disabled = !datos.perfiles.length;
        } catch (error) {
            if (actual === solicitudActual) aviso(error.message, error.diagnostico);
        }
    });
    perfil.addEventListener('change', async () => {
        const actual = ++solicitudActual;
        mostrarVacio('Consultando facturas PPD...');
        if (!perfil.value) return;
        try {
            const facturas = await obtener('api/complementos-pago-crear.php?accion=facturas&cliente_id=' + encodeURIComponent(cliente.value) + '&perfil_id=' + encodeURIComponent(perfil.value));
            if (actual === solicitudActual) mostrarFacturas(facturas);
        } catch (error) {
            if (actual === solicitudActual) { mostrarVacio('No fue posible cargar las facturas.'); aviso(error.message, error.diagnostico); }
        }
    });
    forma.addEventListener('change', actualizarTotal);
    fecha.addEventListener('change', actualizarTotal);
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (enProceso || (guardar.disabled && guardarYTimbrar.disabled)) return;
        const timbrarAlGuardar = event.submitter === guardarYTimbrar;
        const facturas = [];
        for (const tr of body.querySelectorAll('tr[data-factura-id]')) {
            if (!tr.querySelector('input[type=checkbox]').checked) continue;
            const monto = tr.querySelector('.monto-pagado');
            const valor = monto.value.replaceAll(',', '');
            const pagado = Number(valor);
            if (!/^\d+(?:\.\d{1,2})?$/.test(valor) || !Number.isFinite(pagado)
                || pagado <= 0 || pagado > Number(tr.dataset.saldo)) {
                return aviso('Revisa el monto pagado de cada factura seleccionada.');
            }
            facturas.push({factura_id: Number(tr.dataset.facturaId), monto: pagado.toFixed(2)});
        }
        if (!facturas.length) return aviso('Selecciona al menos una factura.');
        if (facturas.length > 20) return aviso('Solo se permiten 20 facturas por complemento. Reduce la selección para continuar.');
        enProceso = true;
        actualizarTotal();
        (timbrarAlGuardar ? guardarYTimbrar : guardar).textContent = 'Guardando...';
        try {
            const response = await fetch('api/complementos-pago-crear.php', {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify({csrf: window.complementoConfig.csrf, solicitud_id: idSolicitud,
                    cliente_id: Number(cliente.value), perfil_id: Number(perfil.value),
                    forma_pago_id: Number(forma.value), fecha_pago: fecha.value, facturas}),
            });
            const payload = await leerRespuesta(response, 'guardar el complemento');
            if (!response.ok || !payload.ok) {
                const error = new Error(payload.error || 'No fue posible guardar el complemento.');
                error.diagnostico = payload;
                throw error;
            }
            if (timbrarAlGuardar) {
                guardarYTimbrar.textContent = 'Timbrando...';
                try {
                    const respuestaTimbrado = await fetch('api/complementos-pago-timbrar.php', {
                        method: 'POST', credentials: 'same-origin',
                        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                        body: JSON.stringify({id: payload.complemento.id, csrf: window.complementoConfig.csrfTimbrado}),
                    });
                    const timbrado = await leerRespuesta(respuestaTimbrado, 'timbrar el complemento');
                    if (!respuestaTimbrado.ok || !timbrado.ok) throw new Error(timbrado.error || 'El PAC no pudo timbrar el complemento.');
                    if (window.Swal) await Swal.fire({icon: 'success', title: 'Complemento timbrado',
                        text: 'UUID: ' + timbrado.uuid});
                    else alert('Complemento timbrado. UUID: ' + timbrado.uuid);
                } catch (error) {
                    const mensaje = payload.complemento.folio + ' sí quedó guardado. No se completó el timbrado: '
                        + (error instanceof Error ? error.message : 'Revisa el detalle del complemento.');
                    if (window.Swal) await Swal.fire({icon: 'error', title: 'Guardado, sin confirmar timbrado', text: mensaje});
                    else alert(mensaje);
                }
            } else if (window.Swal) {
                await Swal.fire({icon: 'success', title: 'Complemento guardado',
                    text: payload.complemento.folio + ' quedó pendiente, sin timbrar.'});
            } else {
                alert(payload.complemento.folio + ' quedó pendiente, sin timbrar.');
            }
            window.location.assign('complementos-pago.php?id=' + encodeURIComponent(payload.complemento.id));
        } catch (error) {
            await aviso(error.message || 'No fue posible guardar el complemento.', error.diagnostico);
            guardar.innerHTML = textoGuardar;
            guardarYTimbrar.innerHTML = textoTimbrar;
            enProceso = false;
            actualizarTotal();
        }
    });
})();
</script>
HTML;
require __DIR__ . '/templates/scripts.php';
?>
