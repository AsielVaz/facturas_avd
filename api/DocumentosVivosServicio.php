<?php

declare(strict_types=1);

/** Criterio compartido para el conteo global y la disponibilidad por empresa. */
final class DocumentosVivosServicio
{
    public static function pagosAgrupados(): string
    {
        return '(SELECT id_factura, SUM(monto) AS total_pagado
                 FROM facturar_rel_complementos GROUP BY id_factura)';
    }

    public static function condicion(string $factura = 'f', string $pagos = 'pf'): string
    {
        return "$factura.metodo_pago = 2
            AND TRIM(COALESCE($factura.uuid, '')) <> ''
            AND COALESCE($factura.status, '') <> 'Cancelado'
            AND COALESCE($factura.cancelado, 0) = 0
            AND $factura.desactivado IS NULL
            AND TRIM(COALESCE($factura.id_clave_corta, '')) <> ''
            AND $factura.total_iva - COALESCE($pagos.total_pagado, 0) > 0.005";
    }

    /** @return array<int,array{empresa:int,global:int}> */
    public function conteosCliente(PDO $conexion, int $clienteId, int $empresaId): array
    {
        $consulta = $conexion->prepare(
            'SELECT TRIM(f.id_clave_corta) AS perfil_id, COUNT(*) AS global_count,
                    SUM(CASE WHEN f.razon = :empresa AND f.nombre = :cliente THEN 1 ELSE 0 END) AS empresa_count
             FROM facturas f
             LEFT JOIN ' . self::pagosAgrupados() . ' pf ON pf.id_factura = f.id
             WHERE ' . self::condicion() . '
             GROUP BY TRIM(f.id_clave_corta)'
        );
        $consulta->bindValue(':cliente', $clienteId, PDO::PARAM_INT);
        $consulta->bindValue(':empresa', $empresaId, PDO::PARAM_INT);
        $consulta->execute();
        $resultado = [];
        foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            if (!preg_match('/^[1-9][0-9]*$/D', (string) $fila['perfil_id'])) continue;
            $resultado[(int) $fila['perfil_id']] = [
                'empresa' => (int) $fila['empresa_count'],
                'global' => (int) $fila['global_count'],
            ];
        }
        return $resultado;
    }

    public static function actualizarConteos(PDO $conexion): int
    {
        $sql = 'UPDATE claves_cortas cc
                LEFT JOIN (
                    SELECT TRIM(f.id_clave_corta) AS id_clave_corta,
                           COUNT(*) AS documentos_vivos
                    FROM facturas f
                    LEFT JOIN ' . self::pagosAgrupados() . ' pf ON pf.id_factura = f.id
                    WHERE ' . self::condicion() . '
                    GROUP BY TRIM(f.id_clave_corta)
                ) vivos ON vivos.id_clave_corta = CAST(cc.id AS CHAR)
                SET cc.documentos_vivos = COALESCE(vivos.documentos_vivos, 0)';
        return $conexion->exec($sql);
    }
}
