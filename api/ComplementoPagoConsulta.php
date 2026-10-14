<?php

declare(strict_types=1);

final class ComplementoPagoConsulta
{
    public function __construct(private PDO $conexion, private int $empresa)
    {
        if ($empresa <= 0) {
            throw new InvalidArgumentException('La empresa activa no es válida.');
        }
    }

    /** @return array{filas:array<int,array<string,mixed>>,total:int,pagina:int,paginas:int,por_pagina:int} */
    public function listar(string $buscar, string $fechaDesde, string $fechaHasta, int $pagina, int $porPagina = 25): array
    {
        $porPagina = max(1, min(50, $porPagina));
        $pagina = max(1, $pagina);
        [$where, $parametros] = $this->filtros($buscar, $fechaDesde, $fechaHasta);

        $conteo = $this->conexion->prepare('SELECT COUNT(*) ' . $this->tablasBase() . ' WHERE ' . $where);
        $this->vincular($conteo, $parametros);
        $conteo->execute();
        $total = (int) $conteo->fetchColumn();
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min($pagina, $paginas);

        $consulta = $this->conexion->prepare(
            'SELECT fc.id, fc.serie AS serie_complemento, fc.folio AS folio_complemento,
                    fc.uuid, fc.status, fc.total_factura, fc.fecha_pago,
                    COALESCE(NULLIF(cc.razon_social, \'\'), NULLIF(c.razon_social, \'\'), NULLIF(c.nombre, \'\'), CONCAT(\'Cliente #\', fc.nombre)) AS cliente,
                    rel.facturas_count, rel.monto_pagado,
                    rc.serie AS serie_factura, rc.folio AS folio_factura,
                    CASE WHEN f.id IS NULL THEN 0 ELSE 1 END AS factura_disponible
             ' . $this->tablasListado() . ' WHERE ' . $where . '
             ORDER BY fc.id DESC LIMIT :limite OFFSET :desplazamiento'
        );
        $this->vincular($consulta, $parametros);
        $consulta->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $consulta->bindValue(':desplazamiento', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
        $consulta->execute();
        return ['filas' => $consulta->fetchAll(PDO::FETCH_ASSOC), 'total' => $total,
            'pagina' => $pagina, 'paginas' => $paginas, 'por_pagina' => $porPagina];
    }

    /** @return array<string,mixed>|null */
    public function obtener(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $consulta = $this->conexion->prepare(
            'SELECT fc.id, fc.serie AS serie_complemento, fc.folio AS folio_complemento,
                    fc.uuid, fc.status, fc.status_error, fc.fecha AS fecha_complemento, fc.fecha_timbrado,
                    fc.fecha_pago, fc.total_factura, fc.nombre AS cliente_id,
                    COALESCE(NULLIF(cc.razon_social, \'\'), NULLIF(c.razon_social, \'\'), NULLIF(c.nombre, \'\'), CONCAT(\'Cliente #\', fc.nombre)) AS cliente
             ' . $this->tablasBase() . ' WHERE fc.razon = :empresa AND fc.id = :id LIMIT 1'
        );
        $consulta->bindValue(':empresa', $this->empresa, PDO::PARAM_INT);
        $consulta->bindValue(':id', $id, PDO::PARAM_INT);
        $consulta->execute();
        $fila = $consulta->fetch(PDO::FETCH_ASSOC);
        return $fila ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function relaciones(int $complementoId): array
    {
        if ($complementoId <= 0) {
            return [];
        }
        $consulta = $this->conexion->prepare(
            'SELECT rc.id, rc.id_factura, rc.uuid_rel, rc.monto, rc.num_pago,
                    rc.saldo, rc.restante, rc.serie AS serie_factura,
                    rc.folio AS folio_factura, rc.fecha_pago,
                    CASE WHEN f.id IS NULL THEN 0 ELSE 1 END AS factura_disponible
             FROM facturas_complementos fc
             INNER JOIN facturar_rel_complementos rc ON rc.id_complemento = fc.id
             LEFT JOIN facturas f ON f.id = rc.id_factura AND f.razon = fc.razon
             WHERE fc.id = :id AND fc.razon = :empresa ORDER BY rc.id'
        );
        $consulta->bindValue(':id', $complementoId, PDO::PARAM_INT);
        $consulta->bindValue(':empresa', $this->empresa, PDO::PARAM_INT);
        $consulta->execute();
        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    private function tablasBase(): string
    {
        return 'FROM facturas_complementos fc
                LEFT JOIN clientes c ON c.id = fc.nombre
                LEFT JOIN claves_cortas cc ON cc.id = CAST(fc.id_clave_corta AS UNSIGNED) AND cc.id_cliente = fc.nombre';
    }

    private function tablasListado(): string
    {
        return $this->tablasBase() . '
                LEFT JOIN (
                    SELECT id_complemento, COUNT(*) AS facturas_count,
                           SUM(monto) AS monto_pagado, MIN(id) AS primera_relacion
                    FROM facturar_rel_complementos GROUP BY id_complemento
                ) rel ON rel.id_complemento = fc.id
                LEFT JOIN facturar_rel_complementos rc ON rc.id = rel.primera_relacion
                LEFT JOIN facturas f ON f.id = rc.id_factura AND f.razon = fc.razon';
    }

    /** @return array{0:string,1:array<string,int|string>} */
    private function filtros(string $buscar, string $fechaDesde, string $fechaHasta): array
    {
        $condiciones = ['fc.razon = :empresa'];
        $parametros = [':empresa' => $this->empresa];
        $buscar = trim($buscar);
        if ($buscar !== '') {
            $condiciones[] = '(fc.uuid LIKE :uuid OR fc.folio LIKE :folio_complemento
                OR cc.razon_social LIKE :razon OR c.nombre LIKE :cliente
                OR EXISTS (SELECT 1 FROM facturar_rel_complementos rcb
                           WHERE rcb.id_complemento = fc.id
                             AND (rcb.folio LIKE :folio_factura OR rcb.uuid_rel LIKE :uuid_rel)))';
            foreach ([':uuid', ':folio_complemento', ':razon', ':cliente', ':folio_factura', ':uuid_rel'] as $clave) {
                $parametros[$clave] = '%' . $buscar . '%';
            }
        }
        if ($fechaDesde !== '') {
            $condiciones[] = 'fc.fecha_pago >= :desde';
            $parametros[':desde'] = $fechaDesde . ' 00:00:00';
        }
        if ($fechaHasta !== '') {
            $condiciones[] = 'fc.fecha_pago < DATE_ADD(:hasta, INTERVAL 1 DAY)';
            $parametros[':hasta'] = $fechaHasta;
        }
        return [implode(' AND ', $condiciones), $parametros];
    }

    /** @param array<string,int|string> $parametros */
    private function vincular(PDOStatement $consulta, array $parametros): void
    {
        foreach ($parametros as $clave => $valor) {
            $consulta->bindValue($clave, $valor, $clave === ':empresa' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
