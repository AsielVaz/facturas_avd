<?php

declare(strict_types=1);

require_once __DIR__ . '/Conexion.php';
require_once __DIR__ . '/SesionEmpresa.php';

final class EmpresaAdministrador
{
    public function __construct(private readonly PDO $conexion)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function listar(): array
    {
        $sql = "SELECT
                    e.id AS empresa_id,
                    e.razon AS empresa,
                    e.rfc,
                    e.estado,
                    e.cp,
                    e.regimen,
                    cc.id AS clave_id,
                    cc.clave_corta,
                    cc.razon_social AS clave_razon_social,
                    cc.rfc AS clave_rfc
                FROM empresas e
                LEFT JOIN claves_cortas cc
                    ON cc.id = (
                        SELECT MIN(cc2.id)
                        FROM claves_cortas cc2
                        WHERE TRIM(COALESCE(e.rfc, '')) <> ''
                          AND UPPER(TRIM(cc2.rfc)) = UPPER(TRIM(e.rfc))
                    )
                ORDER BY e.razon, e.id";
        return $this->conexion->query($sql)->fetchAll();
    }

    /** @return array{empresa: int, clave: int, nombre: string} */
    public function seleccionar(int $empresaId, int $claveId): array
    {
        $consulta = $this->conexion->prepare(
            "SELECT e.id AS empresa_id, e.razon, e.logo, cc.id AS clave_id
             FROM empresas e
             INNER JOIN claves_cortas cc
                ON cc.id = :clave
               AND TRIM(COALESCE(e.rfc, '')) <> ''
               AND UPPER(TRIM(cc.rfc)) = UPPER(TRIM(e.rfc))
             WHERE e.id = :empresa
             LIMIT 1"
        );
        $consulta->bindValue(':empresa', $empresaId, PDO::PARAM_INT);
        $consulta->bindValue(':clave', $claveId, PDO::PARAM_INT);
        $consulta->execute();
        $coincidencia = $consulta->fetch();

        if (!$coincidencia) {
            throw new RuntimeException('La empresa y la clave corta no tienen el mismo RFC.');
        }

        SesionEmpresa::cambiar(
            (int) $coincidencia['empresa_id'],
            (int) $coincidencia['clave_id'],
            (string) $coincidencia['logo'],
            (string) $coincidencia['razon']
        );
        return [
            'empresa' => (int) $coincidencia['empresa_id'],
            'clave' => (int) $coincidencia['clave_id'],
            'nombre' => (string) $coincidencia['razon'],
        ];
    }
}
