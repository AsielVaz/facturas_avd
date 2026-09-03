<?php

declare(strict_types=1);

require_once __DIR__ . '/Conexion.php';
require_once __DIR__ . '/SesionEmpresa.php';

final class NotaInventarioAdministrador
{
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
        int $pagina = 1,
        int $porPagina = 10,
        string $buscar = ''
    ): array {
        $empresa = max(1, $empresa ?? SesionEmpresa::empresaActual());
        $pagina = max(1, $pagina);
        $porPagina = min(50, max(5, $porPagina));
        $buscar = trim($buscar);
        $filtro = "ni.id_empresa = :empresa
            AND (:buscar_vacio = ''
                OR CAST(ni.id AS CHAR) LIKE :buscar_id
                OR CAST(ni.id_factura AS CHAR) LIKE :buscar_factura
                OR ni.responsable LIKE :buscar_responsable
                OR ni.almacen LIKE :buscar_almacen
                OR ni.solicitante LIKE :buscar_solicitante
                OR EXISTS (
                    SELECT 1 FROM notas_inventario_detalle busqueda
                    WHERE busqueda.id_nota = ni.id
                      AND (busqueda.detalle LIKE :buscar_detalle OR busqueda.clave_sat LIKE :buscar_clave)
                )
            )";
        $parametrosBusqueda = [
            ':empresa' => $empresa,
            ':buscar_vacio' => $buscar,
            ':buscar_id' => '%' . $buscar . '%',
            ':buscar_factura' => '%' . $buscar . '%',
            ':buscar_responsable' => '%' . $buscar . '%',
            ':buscar_almacen' => '%' . $buscar . '%',
            ':buscar_solicitante' => '%' . $buscar . '%',
            ':buscar_detalle' => '%' . $buscar . '%',
            ':buscar_clave' => '%' . $buscar . '%',
        ];

        $conteo = $this->conexion->prepare("SELECT COUNT(*) FROM notas_inventario ni WHERE {$filtro}");
        $this->vincularBusqueda($conteo, $parametrosBusqueda);
        $conteo->execute();
        $total = (int) $conteo->fetchColumn();
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min($pagina, $paginas);
        $desplazamiento = ($pagina - 1) * $porPagina;

        $sql = "SELECT
                    ni.id,
                    ni.id_factura,
                    ni.responsable,
                    ni.almacen,
                    ni.solicitante,
                    ni.fecha_salida,
                    ni.estado,
                    COUNT(nid.id) AS partidas,
                    COALESCE(SUM(nid.cantidad), 0) AS unidades
                FROM notas_inventario ni
                LEFT JOIN notas_inventario_detalle nid ON nid.id_nota = ni.id
                WHERE {$filtro}
                GROUP BY ni.id, ni.id_factura, ni.responsable, ni.almacen, ni.solicitante, ni.fecha_salida, ni.estado
                ORDER BY ni.id DESC
                LIMIT :limite OFFSET :desplazamiento";
        $consulta = $this->conexion->prepare($sql);
        $this->vincularBusqueda($consulta, $parametrosBusqueda);
        $consulta->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $consulta->bindValue(':desplazamiento', $desplazamiento, PDO::PARAM_INT);
        $consulta->execute();
        $registros = $consulta->fetchAll();

        $detalles = $this->obtenerDetalles(array_column($registros, 'id'));
        foreach ($registros as &$registro) {
            $registro['detalles'] = $detalles[(int) $registro['id']] ?? [];
        }
        unset($registro);

        return [
            'registros' => $registros,
            'resumen' => $this->obtenerResumen($empresa),
            'total' => $total,
            'pagina' => $pagina,
            'paginas' => $paginas,
            'por_pagina' => $porPagina,
        ];
    }

    /** @return array<string, int|float> */
    private function obtenerResumen(int $empresa): array
    {
        $sql = "SELECT
                    COUNT(DISTINCT ni.id) AS notas,
                    COALESCE(SUM(nid.cantidad), 0) AS unidades,
                    COUNT(DISTINCT NULLIF(TRIM(ni.responsable), '')) AS responsables,
                    COUNT(DISTINCT CASE
                        WHEN DATE_FORMAT(ni.fecha_salida, '%Y-%m') = (
                            SELECT DATE_FORMAT(MAX(n2.fecha_salida), '%Y-%m')
                            FROM notas_inventario n2
                            WHERE n2.id_empresa = :empresa_mes
                        ) THEN ni.id
                    END) AS notas_mes
                FROM notas_inventario ni
                LEFT JOIN notas_inventario_detalle nid ON nid.id_nota = ni.id
                WHERE ni.id_empresa = :empresa_resumen";
        $consulta = $this->conexion->prepare($sql);
        $consulta->bindValue(':empresa_mes', $empresa, PDO::PARAM_INT);
        $consulta->bindValue(':empresa_resumen', $empresa, PDO::PARAM_INT);
        $consulta->execute();
        $fila = $consulta->fetch() ?: [];

        return [
            'notas' => (int) ($fila['notas'] ?? 0),
            'unidades' => (float) ($fila['unidades'] ?? 0),
            'responsables' => (int) ($fila['responsables'] ?? 0),
            'notas_mes' => (int) ($fila['notas_mes'] ?? 0),
        ];
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function obtenerDetalles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $consulta = $this->conexion->prepare(
            "SELECT id, id_nota, id_concepto, detalle, clave_sat, cantidad
             FROM notas_inventario_detalle
             WHERE id_nota IN ({$marcadores})
             ORDER BY id_nota DESC, id ASC"
        );
        foreach (array_values($ids) as $indice => $id) {
            $consulta->bindValue($indice + 1, (int) $id, PDO::PARAM_INT);
        }
        $consulta->execute();

        $agrupados = [];
        foreach ($consulta->fetchAll() as $detalle) {
            $agrupados[(int) $detalle['id_nota']][] = $detalle;
        }
        return $agrupados;
    }

    /** @param array<string, int|string> $parametros */
    private function vincularBusqueda(PDOStatement $consulta, array $parametros): void
    {
        foreach ($parametros as $clave => $valor) {
            $consulta->bindValue($clave, $valor, $clave === ':empresa' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
