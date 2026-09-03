<?php

declare(strict_types=1);

require_once __DIR__ . '/Conexion.php';
require_once __DIR__ . '/SesionEmpresa.php';

final class BancoAdministrador
{
    public function __construct(private readonly PDO $conexion)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function listarBancos(?int $empresa = null): array
    {
        $empresa = max(1, $empresa ?? SesionEmpresa::empresaActual());
        $consulta = $this->conexion->prepare(
            "SELECT id, banco, numero_cuenta, clabe_interbancaria, nombre_corto, moneda, color, texto,
                    fecha_inicial, saldo_inicial, status_banco, limite_superior, limite_inferior
             FROM bancos
             WHERE razon = :empresa
             ORDER BY nombre_corto, banco, id"
        );
        $consulta->bindValue(':empresa', $empresa, PDO::PARAM_INT);
        $consulta->execute();
        return $consulta->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function obtenerBanco(int $bancoId, ?int $empresa = null): ?array
    {
        $empresa = max(1, $empresa ?? SesionEmpresa::empresaActual());
        $consulta = $this->conexion->prepare(
            "SELECT id, banco, numero_cuenta, clabe_interbancaria, nombre_corto, moneda, color, texto,
                    fecha_inicial, saldo_inicial, status_banco, limite_superior, limite_inferior
             FROM bancos
             WHERE id = :banco AND razon = :empresa
             LIMIT 1"
        );
        $consulta->bindValue(':banco', $bancoId, PDO::PARAM_INT);
        $consulta->bindValue(':empresa', $empresa, PDO::PARAM_INT);
        $consulta->execute();
        $banco = $consulta->fetch();
        return $banco ?: null;
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
    public function listarMovimientos(
        int $bancoId,
        ?int $empresa = null,
        int $pagina = 1,
        int $porPagina = 15,
        string $buscar = '',
        string $fecha = ''
    ): array {
        $empresa = max(1, $empresa ?? SesionEmpresa::empresaActual());
        if ($this->obtenerBanco($bancoId, $empresa) === null) {
            throw new RuntimeException('La cuenta bancaria no pertenece a la empresa actual.');
        }

        $pagina = max(1, $pagina);
        $porPagina = min(50, max(5, $porPagina));
        $buscar = trim($buscar);
        $condiciones = ['mov.cuenta = :cuenta'];
        $parametros = [':cuenta' => $bancoId];

        if ($buscar !== '') {
            $condiciones[] = "(mov.descripcion LIKE :buscar_descripcion OR mov.descrip_det LIKE :buscar_detalle OR mov.referencia LIKE :buscar_referencia OR mov.referencia_interb LIKE :buscar_interbancaria OR mov.tipo_movimiento LIKE :buscar_tipo)";
            $valor = '%' . $buscar . '%';
            $parametros[':buscar_descripcion'] = $valor;
            $parametros[':buscar_detalle'] = $valor;
            $parametros[':buscar_referencia'] = $valor;
            $parametros[':buscar_interbancaria'] = $valor;
            $parametros[':buscar_tipo'] = $valor;
        }
        if ($fecha !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1) {
            $condiciones[] = 'COALESCE(mov.fecha_operacion, mov.fecha) = :fecha';
            $parametros[':fecha'] = $fecha;
        }
        $where = implode(' AND ', $condiciones);

        $conteo = $this->conexion->prepare("SELECT COUNT(*) FROM captura_edo_cta mov WHERE {$where}");
        $this->vincular($conteo, $parametros);
        $conteo->execute();
        $total = (int) $conteo->fetchColumn();
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min($pagina, $paginas);
        $desplazamiento = ($pagina - 1) * $porPagina;

        $consulta = $this->conexion->prepare(
            "SELECT id, fecha_operacion, hora_operacion, fecha, referencia, descripcion, deposito, retiro,
                    saldo, movimiento, descrip_det, referencia_interb, tipo_movimiento, fecha_sistema
             FROM captura_edo_cta mov
             WHERE {$where}
             ORDER BY COALESCE(NULLIF(mov.fecha_operacion, '0000-00-00'), mov.fecha, mov.fecha_sistema) DESC, mov.id DESC
             LIMIT :limite OFFSET :desplazamiento"
        );
        $this->vincular($consulta, $parametros);
        $consulta->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $consulta->bindValue(':desplazamiento', $desplazamiento, PDO::PARAM_INT);
        $consulta->execute();

        return [
            'registros' => $consulta->fetchAll(),
            'resumen' => $this->obtenerResumen($bancoId),
            'total' => $total,
            'pagina' => $pagina,
            'paginas' => $paginas,
            'por_pagina' => $porPagina,
        ];
    }

    /** @return array<string, int|float> */
    private function obtenerResumen(int $bancoId): array
    {
        $consulta = $this->conexion->prepare(
            "SELECT COUNT(*) AS movimientos,
                    COALESCE(SUM(deposito), 0) AS depositos,
                    COALESCE(SUM(retiro), 0) AS retiros
             FROM captura_edo_cta
             WHERE cuenta = :cuenta"
        );
        $consulta->bindValue(':cuenta', $bancoId, PDO::PARAM_INT);
        $consulta->execute();
        $resumen = $consulta->fetch() ?: [];

        $saldo = $this->conexion->prepare(
            "SELECT saldo
             FROM captura_edo_cta
             WHERE cuenta = :cuenta AND saldo IS NOT NULL
             ORDER BY COALESCE(NULLIF(fecha_operacion, '0000-00-00'), fecha, fecha_sistema) DESC, id DESC
             LIMIT 1"
        );
        $saldo->bindValue(':cuenta', $bancoId, PDO::PARAM_INT);
        $saldo->execute();

        return [
            'movimientos' => (int) ($resumen['movimientos'] ?? 0),
            'depositos' => (float) ($resumen['depositos'] ?? 0),
            'retiros' => (float) ($resumen['retiros'] ?? 0),
            'saldo' => (float) ($saldo->fetchColumn() ?: 0),
        ];
    }

    /** @param array<string, int|string> $parametros */
    private function vincular(PDOStatement $consulta, array $parametros): void
    {
        foreach ($parametros as $clave => $valor) {
            $consulta->bindValue($clave, $valor, $clave === ':cuenta' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
