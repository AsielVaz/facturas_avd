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
        $desde = $this->tablas();
        $conteo = $this->conexion->prepare('SELECT COUNT(*) ' . $desde . ' WHERE ' . $where);
        $this->vincular($conteo, $parametros);
        $conteo->execute();
        $total = (int) $conteo->fetchColumn();
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min($pagina, $paginas);

        $consulta = $this->conexion->prepare(
            'SELECT rc.id, rc.id_complemento, rc.id_factura, rc.num_pago, rc.monto, rc.saldo,
                    rc.restante, rc.fecha_pago, rc.folio AS folio_factura, rc.serie AS serie_factura,
                    fc.serie AS serie_complemento, fc.folio AS folio_complemento, fc.uuid,
                    fc.status, fc.fecha AS fecha_complemento,
                    COALESCE(NULLIF(cc.razon_social, \'\'), NULLIF(c.razon_social, \'\'), NULLIF(c.nombre, \'\'), CONCAT(\'Cliente #\', fc.nombre)) AS cliente,
                    CASE WHEN f.id IS NULL THEN 0 ELSE 1 END AS factura_disponible
             ' . $desde . ' WHERE ' . $where . '
             ORDER BY rc.fecha_pago DESC, rc.id DESC LIMIT :limite OFFSET :desplazamiento'
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
            'SELECT rc.id, rc.id_complemento, rc.id_factura, rc.uuid_rel, rc.monto, rc.num_pago,
                    rc.saldo, rc.restante, rc.folio AS folio_factura, rc.serie AS serie_factura,
                    rc.fecha_inserta, rc.fecha_pago, rc.tipo_rel,
                    fc.serie AS serie_complemento, fc.folio AS folio_complemento, fc.uuid,
                    fc.status, fc.fecha AS fecha_complemento, fc.fecha_timbrado,
                    fc.nombre AS cliente_id,
                    COALESCE(NULLIF(cc.razon_social, \'\'), NULLIF(c.razon_social, \'\'), NULLIF(c.nombre, \'\'), CONCAT(\'Cliente #\', fc.nombre)) AS cliente,
                    CASE WHEN f.id IS NULL THEN 0 ELSE 1 END AS factura_disponible
             ' . $this->tablas() . ' WHERE fc.razon = :empresa AND rc.id = :id LIMIT 1'
        );
        $consulta->bindValue(':empresa', $this->empresa, PDO::PARAM_INT);
        $consulta->bindValue(':id', $id, PDO::PARAM_INT);
        $consulta->execute();
        $fila = $consulta->fetch(PDO::FETCH_ASSOC);
        return $fila ?: null;
    }

    private function tablas(): string
    {
        return 'FROM facturar_rel_complementos rc
                INNER JOIN facturas_complementos fc ON fc.id = rc.id_complemento
                LEFT JOIN facturas f ON f.id = rc.id_factura AND f.razon = fc.razon
                LEFT JOIN clientes c ON c.id = fc.nombre
                LEFT JOIN claves_cortas cc ON cc.id = CAST(fc.id_clave_corta AS UNSIGNED) AND cc.id_cliente = fc.nombre';
    }

    /** @return array{0:string,1:array<string,int|string>} */
    private function filtros(string $buscar, string $fechaDesde, string $fechaHasta): array
    {
        $condiciones = ['fc.razon = :empresa'];
        $parametros = [':empresa' => $this->empresa];
        $buscar = trim($buscar);
        if ($buscar !== '') {
            $condiciones[] = '(fc.uuid LIKE :uuid OR fc.folio LIKE :folio_complemento OR rc.folio LIKE :folio_factura OR cc.razon_social LIKE :razon OR c.nombre LIKE :cliente)';
            foreach ([':uuid', ':folio_complemento', ':folio_factura', ':razon', ':cliente'] as $clave) {
                $parametros[$clave] = '%' . $buscar . '%';
            }
        }
        if ($fechaDesde !== '') {
            $condiciones[] = 'rc.fecha_pago >= :desde';
            $parametros[':desde'] = $fechaDesde . ' 00:00:00';
        }
        if ($fechaHasta !== '') {
            $condiciones[] = 'rc.fecha_pago < DATE_ADD(:hasta, INTERVAL 1 DAY)';
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
