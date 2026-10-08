<?php

declare(strict_types=1);

final class ClientesClaveCortaAdministrador
{
    private const CAMPOS_EDITABLES = ['clave_corta', 'razon_social', 'rfc', 'domicilio', 'cp', 'regimen_fiscal', 'facturacion_automatica', 'correo_respuesta'];

    public function __construct(private PDO $conexion, private int $usuarioId)
    {
        if ($usuarioId <= 0) {
            throw new InvalidArgumentException('Usuario no válido.');
        }
    }

    /** @return array{total:int,filas:array<int,array<string,mixed>>} */
    public function listar(string $busqueda, int $pagina, int $limite = 50): array
    {
        $busqueda = trim($busqueda);
        $where = $busqueda === '' ? '' : ' WHERE cc.razon_social LIKE :busqueda_razon OR cc.clave_corta LIKE :busqueda_clave OR cc.rfc LIKE :busqueda_rfc OR c.nombre LIKE :busqueda_cliente';
        $desde = ' FROM claves_cortas cc INNER JOIN usuarios u ON u.cliente = cc.id_cliente AND u.id = :usuario LEFT JOIN clientes c ON c.id = cc.id_cliente';
        $conteo = $this->conexion->prepare('SELECT COUNT(*)' . $desde . $where);
        $conteo->bindValue(':usuario', $this->usuarioId, PDO::PARAM_INT);
        if ($busqueda !== '') {
            $this->vincularBusqueda($conteo, $busqueda);
        }
        $conteo->execute();
        $total = (int) $conteo->fetchColumn();
        $desplazamiento = (max(1, $pagina) - 1) * $limite;
        $consulta = $this->conexion->prepare(
            'SELECT cc.id, cc.id_cliente, cc.clave_corta, cc.razon_social, cc.rfc, c.nombre AS nombre_cliente'
            . $desde
            . $where . ' ORDER BY cc.razon_social, cc.id LIMIT :limite OFFSET :desplazamiento'
        );
        $consulta->bindValue(':usuario', $this->usuarioId, PDO::PARAM_INT);
        if ($busqueda !== '') {
            $this->vincularBusqueda($consulta, $busqueda);
        }
        $consulta->bindValue(':limite', $limite, PDO::PARAM_INT);
        $consulta->bindValue(':desplazamiento', $desplazamiento, PDO::PARAM_INT);
        $consulta->execute();
        return ['total' => $total, 'filas' => $consulta->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function vincularBusqueda(PDOStatement $consulta, string $busqueda): void
    {
        foreach (['razon', 'clave', 'rfc', 'cliente'] as $campo) {
            $consulta->bindValue(':busqueda_' . $campo, '%' . $busqueda . '%');
        }
    }

    /** @return array<string,mixed>|null */
    public function obtener(int $id): ?array
    {
        $consulta = $this->conexion->prepare(
            'SELECT cc.*, c.nombre AS nombre_cliente FROM claves_cortas cc
             INNER JOIN usuarios u ON u.cliente = cc.id_cliente AND u.id = :usuario
             LEFT JOIN clientes c ON c.id = cc.id_cliente WHERE cc.id = :id LIMIT 1'
        );
        $consulta->bindValue(':id', $id, PDO::PARAM_INT);
        $consulta->bindValue(':usuario', $this->usuarioId, PDO::PARAM_INT);
        $consulta->execute();
        $fila = $consulta->fetch(PDO::FETCH_ASSOC);
        return $fila ?: null;
    }

    /** @param array<string,mixed> $fila */
    public static function revision(array $fila): string
    {
        $valores = [];
        foreach (self::CAMPOS_EDITABLES as $campo) {
            $valores[] = $fila[$campo] === null ? null : (string) $fila[$campo];
        }
        return hash('sha256', json_encode($valores, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $entrada */
    public function guardar(int $id, array $entrada, string $revision): void
    {
        $datos = [];
        foreach (self::CAMPOS_EDITABLES as $campo) {
            if (isset($entrada[$campo]) && !is_scalar($entrada[$campo])) {
                throw new InvalidArgumentException('La solicitud no es válida.');
            }
            $datos[$campo] = trim((string) ($entrada[$campo] ?? ''));
        }
        foreach (['clave_corta' => 24, 'razon_social' => 200, 'rfc' => 100] as $campo => $maximo) {
            $longitud = function_exists('mb_strlen') ? mb_strlen($datos[$campo], 'UTF-8') : strlen($datos[$campo]);
            if ($datos[$campo] === '' || $longitud > $maximo) {
                throw new InvalidArgumentException('Revisa el campo ' . $campo . '.');
            }
        }
        $longitudDomicilio = function_exists('mb_strlen') ? mb_strlen($datos['domicilio'], 'UTF-8') : strlen($datos['domicilio']);
        if ($longitudDomicilio > 5000) {
            throw new InvalidArgumentException('El domicilio es demasiado largo.');
        }
        if ($datos['cp'] !== '' && (strlen($datos['cp']) > 6 || preg_match('/^[0-9]+$/D', $datos['cp']) !== 1)) {
            throw new InvalidArgumentException('El código postal debe contener solo dígitos (máximo 6).');
        }
        if ($datos['regimen_fiscal'] !== '' && preg_match('/^[0-9]{1,8}$/D', $datos['regimen_fiscal']) !== 1) {
            throw new InvalidArgumentException('El régimen fiscal debe ser numérico.');
        }
        if (!in_array($datos['facturacion_automatica'], ['', 'Si', 'No'], true)) {
            throw new InvalidArgumentException('La facturación automática no es válida.');
        }
        if ($datos['correo_respuesta'] !== '' && (strlen($datos['correo_respuesta']) > 254 || filter_var($datos['correo_respuesta'], FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidArgumentException('El correo de respuesta no es válido.');
        }
        $this->conexion->beginTransaction();
        try {
            $bloqueo = $this->conexion->prepare(
                'SELECT cc.* FROM claves_cortas cc
                 INNER JOIN usuarios u ON u.cliente = cc.id_cliente AND u.id = :usuario
                 WHERE cc.id = :id FOR UPDATE'
            );
            $bloqueo->bindValue(':id', $id, PDO::PARAM_INT);
            $bloqueo->bindValue(':usuario', $this->usuarioId, PDO::PARAM_INT);
            $bloqueo->execute();
            $actual = $bloqueo->fetch(PDO::FETCH_ASSOC);
            if (!$actual) {
                throw new RuntimeException('El perfil no está asignado a tu usuario.');
            }
            if (!hash_equals(self::revision($actual), $revision)) {
                throw new RuntimeException('Alguien modificó este perfil. Recarga la página antes de guardar.');
            }
            $actualizacion = $this->conexion->prepare(
                'UPDATE claves_cortas SET clave_corta = :clave_corta, razon_social = :razon_social,
                 rfc = :rfc, domicilio = :domicilio, cp = :cp, regimen_fiscal = :regimen_fiscal,
                 facturacion_automatica = :facturacion_automatica, correo_respuesta = :correo_respuesta WHERE id = :id'
            );
            foreach ($datos as $campo => $valor) {
                if (($campo === 'cp' || $campo === 'regimen_fiscal' || $campo === 'correo_respuesta') && $valor === '') {
                    $actualizacion->bindValue(':' . $campo, null, PDO::PARAM_NULL);
                } elseif ($campo === 'regimen_fiscal') {
                    $actualizacion->bindValue(':' . $campo, (int) $valor, PDO::PARAM_INT);
                } else {
                    $actualizacion->bindValue(':' . $campo, $valor);
                }
            }
            $actualizacion->bindValue(':id', $id, PDO::PARAM_INT);
            $actualizacion->execute();
            $this->conexion->commit();
        } catch (Throwable $error) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            throw $error;
        }
    }
}
