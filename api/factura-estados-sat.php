<?php

declare(strict_types=1);

require_once __DIR__ . '/Autenticacion.php';
require_once __DIR__ . '/Conexion.php';
require_once __DIR__ . '/SesionEmpresa.php';
require_once __DIR__ . '/SatEstadoCfdiServicio.php';

Autenticacion::exigirApi();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Método no permitido.');
    }

    $entrada = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($entrada)) {
        throw new RuntimeException('La solicitud no tiene un formato válido.');
    }
    $csrf = (string) ($entrada['csrf'] ?? '');
    if (!isset($_SESSION['sat_status_csrf']) || !hash_equals((string) $_SESSION['sat_status_csrf'], $csrf)) {
        http_response_code(419);
        throw new RuntimeException('La sesión para consultar al SAT expiró. Recarga la página.');
    }

    $ids = array_values(array_unique(array_filter(
        array_map('intval', is_array($entrada['facturas'] ?? null) ? $entrada['facturas'] : []),
        static fn(int $id): bool => $id > 0
    )));
    $ids = array_slice($ids, 0, 20);
    if ($ids === []) {
        echo json_encode(['ok' => true, 'estados' => [], 'consultadas' => 0, 'desde_sesion' => 0], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    $empresa = SesionEmpresa::empresaActual();
    $usuario = Autenticacion::usuarioActualId();
    $claveSesion = 'u' . $usuario . ':e' . $empresa;
    $_SESSION['sat_cfdi_estados'] ??= [];
    $_SESSION['sat_cfdi_estados'][$claveSesion] ??= [];
    $cache =& $_SESSION['sat_cfdi_estados'][$claveSesion];
    $estados = [];
    $pendientes = [];
    foreach ($ids as $id) {
        if (isset($cache[$id]) && is_array($cache[$id]) && (!empty($cache[$id]['comprobado']) || !empty($cache[$id]['cancelada']))) {
            $estados[(string) $id] = $cache[$id] + ['desde_sesion' => true];
        } else {
            $pendientes[] = $id;
        }
    }

    if ($pendientes !== []) {
        $marcadores = implode(',', array_fill(0, count($pendientes), '?'));
        $consulta = Conexion::obtener()->prepare(
            "SELECT f.id, f.uuid,
                    UPPER(TRIM(e.rfc)) AS emisor_rfc,
                    UPPER(TRIM(COALESCE(NULLIF(cc.rfc, ''), c.rfc, ''))) AS receptor_rfc,
                    COALESCE(f.xml_total, f.total_factura, f.total_iva, 0) AS total,
                    COALESCE(f.sello_cfd, '') AS sello_cfd
             FROM facturas f
             INNER JOIN empresas e ON e.id = f.razon
             LEFT JOIN clientes c ON c.id = f.nombre
             LEFT JOIN claves_cortas cc ON cc.id = CAST(f.id_clave_corta AS UNSIGNED)
             WHERE f.razon = ? AND f.id IN ({$marcadores})
               AND f.uuid IS NOT NULL AND TRIM(f.uuid) <> ''"
        );
        $consulta->execute(array_merge([$empresa], $pendientes));
        $facturas = [];
        foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $factura) {
            $facturas[(int) $factura['id']] = $factura;
        }

        $servicio = new SatEstadoCfdiServicio();
        foreach ($pendientes as $id) {
            if (!isset($facturas[$id])) {
                $estados[(string) $id] = [
                    'comprobado' => false,
                    'cancelada' => false,
                    'error' => 'La factura no existe, no está timbrada o no pertenece a la empresa activa.',
                    'desde_sesion' => false,
                ];
                continue;
            }
            try {
                $estado = $servicio->consultar($facturas[$id]);
                $estado['consultado_en'] = date(DATE_ATOM);
                $cache[$id] = $estado;
                $estados[(string) $id] = $estado + ['desde_sesion' => false];
            } catch (SatEstadoCfdiException $error) {
                $estados[(string) $id] = [
                    'comprobado' => false,
                    'cancelada' => false,
                    'error' => $error->getMessage(),
                    'desde_sesion' => false,
                ];
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'empresa' => $empresa,
        'estados' => $estados,
        'consultadas' => count($pendientes),
        'desde_sesion' => count($ids) - count($pendientes),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El contenido JSON no es válido.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (http_response_code() < 400) {
        http_response_code(500);
    }
    error_log('Error al consultar estados CFDI en SAT: ' . $error->getMessage());
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
