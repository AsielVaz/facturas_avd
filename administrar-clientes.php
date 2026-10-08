<?php

declare(strict_types=1);

require_once __DIR__ . '/api/Autenticacion.php';
require_once __DIR__ . '/api/ClientesClaveCortaAdministrador.php';

Autenticacion::exigirPagina();
if (!Autenticacion::puedeAdministrarClientes()) {
    http_response_code(403);
    exit('No tienes permiso para administrar clientes.');
}

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$administrador = new ClientesClaveCortaAdministrador(Conexion::obtener(), Autenticacion::usuarioActualId());
$_SESSION['clientes_admin_csrf'] ??= bin2hex(random_bytes(32));
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$busqueda = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$busqueda = function_exists('mb_substr') ? mb_substr($busqueda, 0, 100, 'UTF-8') : substr($busqueda, 0, 100);
$pagina = max(1, filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT) ?: 1);
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
    $token = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
    if ($id <= 0 || !hash_equals((string) $_SESSION['clientes_admin_csrf'], $token)) {
        $error = 'La solicitud expiró. Recarga la página e intenta de nuevo.';
    } else {
        try {
            $revision = is_string($_POST['revision'] ?? null) ? $_POST['revision'] : '';
            $administrador->guardar($id, $_POST, $revision);
            $_SESSION['clientes_admin_csrf'] = bin2hex(random_bytes(32));
            header('Location: administrar-clientes.php?id=' . $id . '&guardado=1', true, 303);
            exit;
        } catch (InvalidArgumentException | RuntimeException $excepcion) {
            $error = $excepcion->getMessage();
        } catch (Throwable $excepcion) {
            error_log('Error al guardar claves_cortas: ' . $excepcion->getMessage());
            $error = 'No fue posible guardar el perfil. Intenta nuevamente.';
        }
    }
}

$perfil = null;
$listado = ['total' => 0, 'filas' => []];
try {
    if ($id > 0) {
        $perfil = $administrador->obtener($id);
        if ($perfil === null) {
            http_response_code(404);
            $error = 'No se encontró el perfil solicitado.';
        }
    } else {
        $listado = $administrador->listar($busqueda, $pagina);
    }
} catch (Throwable $excepcion) {
    error_log('Error al consultar claves_cortas: ' . $excepcion->getMessage());
    $error = 'No fue posible consultar los clientes.';
}

$esc = static fn(mixed $valor): string => htmlspecialchars(is_scalar($valor) ? (string) $valor : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pageTitle = $perfil ? 'Administrar cliente' : 'Administrar clientes';
$pageEyebrow = 'Configuración / Clientes';
$pageAction = $perfil ? '<a href="administrar-clientes.php" class="btn btn-soft-secondary">Volver al listado</a>' : '';
require __DIR__ . '/templates/page-start.php';
?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= $esc($error) ?></div>
<?php endif; ?>
<?php if ($perfil && ($_GET['guardado'] ?? '') === '1'): ?>
    <div class="alert alert-success" role="status">Los cambios se guardaron correctamente.</div>
<?php endif; ?>

<?php if ($perfil): ?>
<div class="card">
    <div class="card-header"><h5 class="mb-0"><?= $esc($perfil['razon_social']) ?></h5></div>
    <div class="card-body">
        <p class="text-muted mb-4">Perfil #<?= (int) $perfil['id'] ?> · Cliente #<?= (int) $perfil['id_cliente'] ?><?= $perfil['nombre_cliente'] ? ' · ' . $esc($perfil['nombre_cliente']) : '' ?></p>
        <form method="post" action="administrar-clientes.php?id=<?= (int) $perfil['id'] ?>" autocomplete="off">
            <input type="hidden" name="id" value="<?= (int) $perfil['id'] ?>">
            <input type="hidden" name="csrf" value="<?= $esc($_SESSION['clientes_admin_csrf']) ?>">
            <input type="hidden" name="revision" value="<?= $esc(ClientesClaveCortaAdministrador::revision($perfil)) ?>">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label" for="clave_corta">Clave corta</label><input class="form-control" id="clave_corta" name="clave_corta" maxlength="24" required value="<?= $esc($_POST['clave_corta'] ?? $perfil['clave_corta']) ?>"></div>
                <div class="col-md-6"><label class="form-label" for="razon_social">Razón social</label><input class="form-control" id="razon_social" name="razon_social" maxlength="200" required value="<?= $esc($_POST['razon_social'] ?? $perfil['razon_social']) ?>"></div>
                <div class="col-md-6"><label class="form-label" for="rfc">RFC</label><input class="form-control" id="rfc" name="rfc" maxlength="100" required value="<?= $esc($_POST['rfc'] ?? $perfil['rfc']) ?>"></div>
                <div class="col-md-3"><label class="form-label" for="cp">Código postal</label><input class="form-control" id="cp" name="cp" maxlength="6" inputmode="numeric" value="<?= $esc($_POST['cp'] ?? $perfil['cp']) ?>"></div>
                <div class="col-md-3"><label class="form-label" for="regimen_fiscal">Régimen fiscal</label><input class="form-control" id="regimen_fiscal" name="regimen_fiscal" maxlength="8" inputmode="numeric" value="<?= $esc($_POST['regimen_fiscal'] ?? $perfil['regimen_fiscal']) ?>"></div>
                <div class="col-md-6"><label class="form-label" for="facturacion_automatica">Facturación automática</label><select class="form-select" id="facturacion_automatica" name="facturacion_automatica">
                    <?php foreach (['' => 'Sin definir', 'Si' => 'Sí', 'No' => 'No'] as $valor => $etiqueta): ?>
                    <option value="<?= $esc($valor) ?>" <?= (string) ($_POST['facturacion_automatica'] ?? $perfil['facturacion_automatica']) === $valor ? 'selected' : '' ?>><?= $esc($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select></div>
                <div class="col-md-6"><label class="form-label" for="correo_respuesta">Correo de respuesta</label><input class="form-control" type="email" id="correo_respuesta" name="correo_respuesta" maxlength="254" value="<?= $esc($_POST['correo_respuesta'] ?? $perfil['correo_respuesta']) ?>"></div>
                <div class="col-12"><label class="form-label" for="domicilio">Domicilio</label><textarea class="form-control" id="domicilio" name="domicilio" rows="4" maxlength="5000"><?= $esc($_POST['domicilio'] ?? $perfil['domicilio']) ?></textarea></div>
            </div>
           
            <div class="mt-4"><button class="btn btn-success" type="submit"><i data-lucide="save" class="fs-16 me-1"></i>Guardar cambios</button></div>
        </form>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body border-bottom">
        <form class="d-flex gap-2" method="get" action="administrar-clientes.php">
            <input class="form-control" type="search" name="q" maxlength="100" value="<?= $esc($busqueda) ?>" placeholder="Buscar razón social, clave corta, RFC o cliente" aria-label="Buscar clientes">
            <button class="btn btn-primary" type="submit">Buscar</button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover erp-table mb-0">
            <thead><tr><th>Clave</th><th>Razón social</th><th>RFC</th><th>Cliente relacionado</th></tr></thead>
            <tbody>
            <?php foreach ($listado['filas'] as $fila): ?>
                <tr>
                    <td><a href="administrar-clientes.php?id=<?= (int) $fila['id'] ?>"><?= $esc($fila['clave_corta']) ?></a></td>
                    <td><a href="administrar-clientes.php?id=<?= (int) $fila['id'] ?>"><?= $esc($fila['razon_social']) ?></a></td>
                    <td><?= $esc($fila['rfc']) ?></td>
                    <td><?= $esc($fila['nombre_cliente'] ?: '#' . $fila['id_cliente']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($listado['filas'] === []): ?><tr><td colspan="4" class="text-center text-muted py-4">No se encontraron clientes.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="text-muted small"><?= number_format($listado['total']) ?> perfiles</span>
        <nav aria-label="Páginas de clientes" class="d-flex gap-2">
            <?php if ($pagina > 1): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= $esc(http_build_query(['q' => $busqueda, 'pagina' => $pagina - 1])) ?>">Anterior</a><?php endif; ?>
            <?php if ($pagina * 50 < $listado['total']): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= $esc(http_build_query(['q' => $busqueda, 'pagina' => $pagina + 1])) ?>">Siguiente</a><?php endif; ?>
        </nav>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/templates/scripts.php'; ?>
