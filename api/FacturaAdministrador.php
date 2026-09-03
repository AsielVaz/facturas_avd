<?php

declare(strict_types=1);

require_once __DIR__ . '/Conexion.php';
require_once __DIR__ . '/SesionEmpresa.php';

final class FacturaAdministrador
{
    private readonly int $empresa;

    public function __construct(private readonly PDO $conexion, ?int $empresa = null)
    {
        $this->empresa = max(1, $empresa ?? SesionEmpresa::empresaActual());
    }

    /**
     * @return array{registros: array<int, array<string, mixed>>, total: int, pagina: int, por_pagina: int, paginas: int}
     */
    public function listar(
        string $tipo = 'pendientes',
        int $pagina = 1,
        int $porPagina = 10,
        string $buscar = '',
        string $fecha = ''
    ): array {
        $tipo = $tipo === 'timbradas' ? 'timbradas' : 'pendientes';
        $pagina = max(1, $pagina);
        $porPagina = min(50, max(5, $porPagina));
        [$condiciones, $parametros] = $this->crearFiltros($tipo, $buscar, $fecha);
        $where = implode(' AND ', $condiciones);

        $conteo = $this->conexion->prepare("SELECT COUNT(*) FROM facturas f LEFT JOIN clientes c ON c.id = f.nombre LEFT JOIN claves_cortas cc ON cc.id = CAST(f.id_clave_corta AS UNSIGNED) WHERE {$where}");
        $conteo->execute($parametros);
        $total = (int) $conteo->fetchColumn();
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min($pagina, $paginas);
        $desplazamiento = ($pagina - 1) * $porPagina;

        $sql = "SELECT
                    f.id,
                    f.fecha,
                    f.folio,
                    f.serie,
                    f.status,
                    f.status_pago,
                    f.status_error,
                    f.uuid,
                    f.fecha_timbrado,
                    f.hora_timbrado,
                    COALESCE(f.xml_total, f.total_factura, f.total_iva, 0) AS total,
                    COALESCE(NULLIF(cc.razon_social, ''), NULLIF(c.razon_social, ''), NULLIF(c.nombre, ''), CONCAT('Cliente #', f.nombre)) AS cliente,
                    COALESCE(NULLIF(cc.rfc, ''), c.rfc, '') AS rfc,
                    COALESCE(cm.clave_moneda, IF(f.moneda = 2, 'USD', 'MXN')) AS moneda
                FROM facturas f
                LEFT JOIN clientes c ON c.id = f.nombre
                LEFT JOIN claves_cortas cc ON cc.id = CAST(f.id_clave_corta AS UNSIGNED)
                LEFT JOIN catalogo_monedas cm ON cm.id = f.moneda
                WHERE {$where}
                ORDER BY f.id DESC
                LIMIT :limite OFFSET :desplazamiento";

        $consulta = $this->conexion->prepare($sql);
        foreach ($parametros as $clave => $valor) {
            $consulta->bindValue($clave, $valor, $clave === ':empresa_actual' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $consulta->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $consulta->bindValue(':desplazamiento', $desplazamiento, PDO::PARAM_INT);
        $consulta->execute();

        return [
            'registros' => $consulta->fetchAll(),
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'paginas' => $paginas,
        ];
    }

    /** @return array<string, int|float> */
    public function obtenerResumen(string $tipo = 'pendientes'): array
    {
        $tipo = $tipo === 'timbradas' ? 'timbradas' : 'pendientes';
        $condicionTipo = $tipo === 'timbradas'
            ? "f.uuid IS NOT NULL AND TRIM(f.uuid) <> ''"
            : "(f.uuid IS NULL OR TRIM(f.uuid) = '')";

        $sql = "SELECT
                    COUNT(*) AS total,
                    COALESCE(SUM(COALESCE(f.xml_total, f.total_factura, f.total_iva, 0)), 0) AS importe,
                    SUM(LOWER(COALESCE(f.status, '')) = 'error' OR COALESCE(f.status_error, '') <> '') AS errores,
                    SUM(LOWER(COALESCE(f.status, '')) LIKE '%cancel%') AS canceladas,
                    SUM(DATE_FORMAT(f.fecha, '%Y-%m') = (
                        SELECT DATE_FORMAT(MAX(f2.fecha), '%Y-%m')
                        FROM facturas f2
                        WHERE f2.razon = :empresa_mes
                    )) AS este_mes
                FROM facturas f
                WHERE {$condicionTipo}
                  AND f.razon = :empresa_actual";

        $consulta = $this->conexion->prepare($sql);
        $consulta->bindValue(':empresa_actual', $this->empresa, PDO::PARAM_INT);
        $consulta->bindValue(':empresa_mes', $this->empresa, PDO::PARAM_INT);
        $consulta->execute();
        $fila = $consulta->fetch() ?: [];
        return [
            'total' => (int) ($fila['total'] ?? 0),
            'importe' => (float) ($fila['importe'] ?? 0),
            'errores' => (int) ($fila['errores'] ?? 0),
            'canceladas' => (int) ($fila['canceladas'] ?? 0),
            'este_mes' => (int) ($fila['este_mes'] ?? 0),
        ];
    }

    /** @return array{0: array<int, string>, 1: array<string, string>} */
    private function crearFiltros(string $tipo, string $buscar, string $fecha): array
    {
        $condiciones = [
            'f.razon = :empresa_actual',
            $tipo === 'timbradas'
            ? "f.uuid IS NOT NULL AND TRIM(f.uuid) <> ''"
            : "(f.uuid IS NULL OR TRIM(f.uuid) = '')"
        ];
        $parametros = [':empresa_actual' => $this->empresa];

        $buscar = trim($buscar);
        if ($buscar !== '') {
            $condiciones[] = "(CONCAT(COALESCE(f.serie, ''), COALESCE(f.folio, '')) LIKE :buscar_folio OR c.nombre LIKE :buscar_nombre OR c.razon_social LIKE :buscar_razon OR c.rfc LIKE :buscar_rfc OR cc.razon_social LIKE :buscar_clave_razon OR cc.rfc LIKE :buscar_clave_rfc)";
            $valorBusqueda = '%' . $buscar . '%';
            $parametros[':buscar_folio'] = $valorBusqueda;
            $parametros[':buscar_nombre'] = $valorBusqueda;
            $parametros[':buscar_razon'] = $valorBusqueda;
            $parametros[':buscar_rfc'] = $valorBusqueda;
            $parametros[':buscar_clave_razon'] = $valorBusqueda;
            $parametros[':buscar_clave_rfc'] = $valorBusqueda;
        }

        if ($fecha !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1) {
            $condiciones[] = 'f.fecha = :fecha';
            $parametros[':fecha'] = $fecha;
        }

        return [$condiciones, $parametros];
    }
}
