<?php

declare(strict_types=1);

require_once __DIR__ . '/Autenticacion.php';
require_once __DIR__ . '/ComplementoPagoAdministrador.php';
Autenticacion::exigirApi();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** @return array{ok:bool,error:string,etapa:string,codigo_sql:string,referencia:string} */
function respuestaErrorBaseComplemento(PDOException $error, string $etapa): array
{
    $referencia = strtoupper(bin2hex(random_bytes(6)));
    $numero = (int) ($error->errorInfo[1] ?? 0);
    $estado = (string) ($error->errorInfo[0] ?? $error->getCode());
    $codigo = $numero > 0 ? (string) $numero : (preg_match('/^[A-Z0-9]{5}$/D', $estado) ? $estado : 'desconocido');
    $causa = match ($numero) {
        1054 => 'Una columna requerida no existe en la base de datos.',
        1146 => 'Una tabla requerida no existe en la base de datos.',
        1364 => 'Falta un valor obligatorio para guardar el comprobante.',
        1048 => 'Un campo obligatorio recibió un valor vacío.',
        1452 => 'Una referencia a cliente, factura o catálogo no existe en la base de datos.',
        1062 => 'Ya existe un registro con una clave que debe ser única.',
        1264 => 'Un número excede el rango permitido por una columna.',
        1406 => 'Un dato excede la longitud permitida por una columna.',
        1205 => 'La base de datos agotó el tiempo de espera de un bloqueo.',
        1213 => 'La base de datos detectó un bloqueo simultáneo; intenta de nuevo.',
        1045, 2002, 2006, 2013 => 'No se pudo establecer o mantener la conexión con la base de datos.',
        default => $estado === 'HY093'
            ? 'Los parámetros de la consulta no coinciden con los datos enviados.'
            : 'La base de datos rechazó la operación.',
    };
    if (in_array($numero, [1048, 1054, 1364, 1406], true)
        && preg_match("/(?:column|field) '([A-Za-z0-9_]{1,64})'/i", $error->getMessage(), $coincidencia)) {
        $causa .= ' Campo: ' . $coincidencia[1] . '.';
    }
    if (in_array($etapa, ['confirmar la transacción', 'liberar el bloqueo de la solicitud'], true)) {
        $causa .= ' El guardado podría haberse completado; revisa el listado antes de reintentar.';
    }
    error_log(sprintf(
        'Complemento de pago [%s] etapa=%s SQLSTATE=%s MySQL=%d archivo=%s:%d detalle=%s',
        $referencia, $etapa, $estado, $numero, basename($error->getFile()), $error->getLine(), $error->getMessage()
    ));
    return ['ok' => false, 'error' => $causa, 'etapa' => $etapa, 'codigo_sql' => $codigo, 'referencia' => $referencia];
}

try {
    $administrador = new ComplementoPagoAdministrador(Conexion::obtener());
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $accion = (string) ($_GET['accion'] ?? '');
        $clienteId = filter_input(INPUT_GET, 'cliente_id', FILTER_VALIDATE_INT) ?: 0;
        $perfilId = filter_input(INPUT_GET, 'perfil_id', FILTER_VALIDATE_INT) ?: 0;
        $datos = match ($accion) {
            'perfiles' => (new FacturacionAdministrador(Conexion::obtener()))->listarPerfilesCliente($clienteId),
            'facturas' => $administrador->facturasDisponibles($clienteId, $perfilId),
            default => throw new ComplementoPagoValidacionException('La consulta solicitada no existe.'),
        };
        echo json_encode(['ok' => true, 'datos' => $datos], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        throw new ComplementoPagoValidacionException('Método no permitido.');
    }
    $contenido = file_get_contents('php://input');
    if (!is_string($contenido) || strlen($contenido) > 32768) {
        throw new ComplementoPagoValidacionException('La solicitud es demasiado grande.');
    }
    $datos = json_decode($contenido, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($datos)) throw new ComplementoPagoValidacionException('La solicitud no tiene un formato válido.');
    $csrf = (string) ($datos['csrf'] ?? '');
    if (!isset($_SESSION['complemento_pago_csrf'])
        || !hash_equals((string) $_SESSION['complemento_pago_csrf'], $csrf)) {
        http_response_code(419);
        throw new ComplementoPagoValidacionException('La sesión del formulario expiró. Recarga la página.');
    }
    $resultado = $administrador->guardarPendiente($datos);
    echo json_encode(['ok' => true, 'complemento' => $resultado], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El JSON enviado no es válido.'], JSON_UNESCAPED_UNICODE);
} catch (ComplementoPagoValidacionException $error) {
    if (http_response_code() < 400) http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (ComplementoPagoPersistenciaException $error) {
    http_response_code(500);
    echo json_encode(respuestaErrorBaseComplemento($error->getPrevious(), $error->etapa), JSON_UNESCAPED_UNICODE);
} catch (PDOException $error) {
    http_response_code(500);
    echo json_encode(respuestaErrorBaseComplemento($error, 'conectar o consultar la base de datos'), JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    $referencia = strtoupper(bin2hex(random_bytes(6)));
    error_log('Complemento de pago [' . $referencia . '] error interno: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Ocurrió un error interno al procesar el complemento.',
        'etapa' => 'procesar la solicitud',
        'referencia' => $referencia,
    ], JSON_UNESCAPED_UNICODE);
}
