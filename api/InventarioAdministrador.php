<?php

declare(strict_types=1);

require_once __DIR__ . '/Conexion.php';
require_once __DIR__ . '/SesionEmpresa.php';

final class InventarioAdministrador
{
    public const FECHA_INICIAL_PREDETERMINADA = '2026-02-01';

    public function __construct(private readonly PDO $conexion)
    {
    }

    /**
     * @return array{
     *   registros: array<int, array<string, mixed>>,
     *   resumen: array<string, int|float>,
     *   total: int,
     *   pagina: int,
     *   paginas: int,
     *   por_pagina: int
     * }
     */
    public function listar(
        ?int $empresa = null,
        ?int $empresaClave = null,
        int $pagina = 1,
        int $porPagina = 10,
        string $buscar = ''
    ): array {
        $empresa = max(1, $empresa ?? SesionEmpresa::empresaActual());
        $empresaClave = max(1, $empresaClave ?? SesionEmpresa::claveActual());
        $pagina = max(1, $pagina);
        $porPagina = min(50, max(5, $porPagina));
        $desplazamiento = ($pagina - 1) * $porPagina;

        $sql = $this->consultaBase() . "
            SELECT
                inventario.*,
                (inventario.inventario_real - inventario.salidas_por_nota) AS existencia_disponible,
                COUNT(*) OVER() AS total_registros,
                SUM(inventario.inventario_real - inventario.salidas_por_nota) OVER() AS total_unidades_disponibles,
                SUM(inventario.valor_inventario) OVER() AS valor_total_inventario,
                SUM(inventario.salidas_por_nota > 0) OVER() AS productos_con_salidas,
                SUM((inventario.inventario_real - inventario.salidas_por_nota) <= 0) OVER() AS productos_sin_existencia
            FROM inventario
            WHERE inventario.inventario_real <> 0
              AND (:buscar_vacio = '' OR inventario.clave_sat LIKE :buscar_clave OR inventario.concepto LIKE :buscar_concepto)
            ORDER BY inventario.fecha_ultima_adquisicion DESC, inventario.clave_sat
            LIMIT :limite OFFSET :desplazamiento";

        $consulta = $this->conexion->prepare($sql);
        $this->vincularParametrosBase($consulta, $empresa, $empresaClave);
        $buscar = trim($buscar);
        $consulta->bindValue(':buscar_vacio', $buscar, PDO::PARAM_STR);
        $consulta->bindValue(':buscar_clave', '%' . $buscar . '%', PDO::PARAM_STR);
        $consulta->bindValue(':buscar_concepto', '%' . $buscar . '%', PDO::PARAM_STR);
        $consulta->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $consulta->bindValue(':desplazamiento', $desplazamiento, PDO::PARAM_INT);
        $consulta->execute();

        $registros = $consulta->fetchAll();
        $primero = $registros[0] ?? [];
        $total = (int) ($primero['total_registros'] ?? 0);
        $paginas = max(1, (int) ceil($total / $porPagina));

        foreach ($registros as &$registro) {
            unset(
                $registro['total_registros'],
                $registro['total_unidades_disponibles'],
                $registro['valor_total_inventario'],
                $registro['productos_con_salidas'],
                $registro['productos_sin_existencia']
            );
        }
        unset($registro);

        return [
            'registros' => $registros,
            'resumen' => [
                'productos' => $total,
                'unidades_disponibles' => (float) ($primero['total_unidades_disponibles'] ?? 0),
                'valor_inventario' => (float) ($primero['valor_total_inventario'] ?? 0),
                'productos_con_salidas' => (int) ($primero['productos_con_salidas'] ?? 0),
                'productos_sin_existencia' => (int) ($primero['productos_sin_existencia'] ?? 0),
            ],
            'total' => $total,
            'pagina' => min($pagina, $paginas),
            'paginas' => $paginas,
            'por_pagina' => $porPagina,
        ];
    }

    private function consultaBase(): string
    {
        return "WITH movimientos AS (
                    SELECT
                        c.clave_sat,
                        c.concepto,
                        c.id AS concepto_id,
                        df.cantidad,
                        df.precio_neto,
                        f.id AS factura_id,
                        f.token AS factura_token,
                        f.fecha_timbrado,
                        (f.id_clave_corta = :clave_empresa AND f.razon <> :empresa_compra) AS es_compra,
                        (f.razon = :empresa_venta) AS es_venta
                    FROM detalle_factura df
                    INNER JOIN facturas f ON f.id = df.id_factura
                    INNER JOIN conceptos c ON c.id = df.id_producto
                    WHERE c.clave_unidad_medida <> 'E48'
                      AND c.clave_sat IS NOT NULL
                      AND f.fecha_timbrado > :fecha_movimientos
                ),
                notas AS (
                    SELECT
                        nid.id_concepto,
                        SUM(nid.cantidad) AS salidas_por_nota
                    FROM notas_inventario ni
                    INNER JOIN notas_inventario_detalle nid ON nid.id_nota = ni.id
                    INNER JOIN facturas fn ON fn.id = ni.id_factura
                    WHERE ni.id_empresa = :empresa_notas
                      AND fn.id_clave_corta = :clave_notas
                      AND fn.razon <> :empresa_notas_compra
                      AND fn.fecha_timbrado > :fecha_notas
                    GROUP BY nid.id_concepto
                ),
                inventario AS (
                    SELECT
                        m.clave_sat,
                        m.concepto,
                        SUM(CASE WHEN m.es_compra THEN m.cantidad ELSE 0 END) AS unidades_compradas,
                        SUM(CASE WHEN m.es_venta THEN m.cantidad ELSE 0 END) AS unidades_vendidas,
                        SUM(CASE WHEN m.es_compra THEN m.cantidad ELSE 0 END)
                            - SUM(CASE WHEN m.es_venta THEN m.cantidad ELSE 0 END) AS inventario_real,
                        COALESCE(MAX(n.salidas_por_nota), 0) AS salidas_por_nota,
                        SUM(CASE WHEN m.es_compra THEN m.precio_neto ELSE 0 END) AS valor_comprado,
                        SUM(CASE WHEN m.es_venta THEN m.precio_neto ELSE 0 END) AS valor_vendido,
                        SUM(CASE WHEN m.es_compra THEN m.precio_neto ELSE 0 END)
                            - SUM(CASE WHEN m.es_venta THEN m.precio_neto ELSE 0 END) AS valor_inventario,
                        MAX(CASE WHEN m.es_compra THEN m.fecha_timbrado END) AS fecha_ultima_adquisicion,
                        SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN m.es_compra THEN m.factura_id END ORDER BY m.fecha_timbrado DESC, m.factura_id DESC SEPARATOR ','), ',', 1) AS ultimo_id_factura,
                        SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN m.es_compra THEN m.factura_token END ORDER BY m.fecha_timbrado DESC, m.factura_id DESC SEPARATOR ','), ',', 1) AS ultimo_token_factura
                    FROM movimientos m
                    LEFT JOIN notas n ON n.id_concepto = m.concepto_id
                    GROUP BY m.clave_sat, m.concepto
                )";
    }

    private function vincularParametrosBase(PDOStatement $consulta, int $empresa, int $empresaClave): void
    {
        $consulta->bindValue(':clave_empresa', $empresaClave, PDO::PARAM_INT);
        $consulta->bindValue(':empresa_compra', $empresa, PDO::PARAM_INT);
        $consulta->bindValue(':empresa_venta', $empresa, PDO::PARAM_INT);
        $consulta->bindValue(':fecha_movimientos', self::FECHA_INICIAL_PREDETERMINADA, PDO::PARAM_STR);
        $consulta->bindValue(':empresa_notas', $empresa, PDO::PARAM_INT);
        $consulta->bindValue(':clave_notas', $empresaClave, PDO::PARAM_INT);
        $consulta->bindValue(':empresa_notas_compra', $empresa, PDO::PARAM_INT);
        $consulta->bindValue(':fecha_notas', self::FECHA_INICIAL_PREDETERMINADA, PDO::PARAM_STR);
    }
}
