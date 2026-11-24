<?php

declare(strict_types=1);

require_once __DIR__ . '/FacturacionAdministrador.php';
require_once __DIR__ . '/DocumentosVivosServicio.php';
require_once __DIR__ . '/ComplementoTratamientoFiscal.php';
require_once __DIR__ . '/ComplementoMovimientoServicio.php';
require_once __DIR__ . '/ComplementoUuidAutorizacion.php';

final class ComplementoPagoValidacionException extends RuntimeException
{
}

final class ComplementoPagoPersistenciaException extends RuntimeException
{
    public function __construct(public readonly string $etapa, PDOException $causa)
    {
        parent::__construct('Falló la operación de base de datos del complemento.', 0, $causa);
    }
}

final class ComplementoPagoAdministrador
{
    private int $empresa;
    private int $usuario;

    public function __construct(private PDO $conexion)
    {
        $this->empresa = SesionEmpresa::empresaActual();
        $this->usuario = Autenticacion::usuarioActualId();
    }

    /** @return array<string,mixed> */
    public function contexto(): array
    {
        $facturacion = new FacturacionAdministrador($this->conexion, $this->empresa);
        return [
            'clientes' => $facturacion->listarClientes(),
            'formas_pago' => $facturacion->obtenerCatalogos()['formas_pago'],
        ];
    }

    /** @return array<string,mixed> */
    public function movimientoDisponible(int $pagoId): array
    {
        return (new ComplementoMovimientoServicio())->obtenerDisponible(
            $this->conexion, $pagoId, $this->empresa, $this->usuario
        );
    }

    /** @return array{cliente: array<string,mixed>, perfiles: array<int,array<string,mixed>>} */
    public function perfilesConDisponibilidad(int $clienteId, int $pagoId = 0): array
    {
        $datos = (new FacturacionAdministrador($this->conexion, $this->empresa))->listarPerfilesCliente($clienteId);
        $conteos = (new DocumentosVivosServicio())->conteosCliente($this->conexion, $clienteId, $this->empresa);
        $excepciones = [];
        if ($pagoId > 0) {
            $movimiento = $this->movimientoDisponible($pagoId);
            if ((int) $movimiento['cliente_id'] !== $clienteId) {
                throw new ComplementoPagoValidacionException('El movimiento no pertenece a este cliente.');
            }
            $consulta = $this->conexion->prepare(
                "SELECT DISTINCT TRIM(f.id_clave_corta) AS perfil_id
                 FROM facturas f
                 WHERE f.razon = :empresa AND f.nombre = :cliente AND f.metodo_pago = 2
                   AND COALESCE(f.status, '') <> 'Cancelado' AND COALESCE(f.cancelado, 0) = 0
                   AND f.desactivado IS NULL AND TRIM(COALESCE(f.uuid, '')) <> ''
                   AND ABS(f.total_iva - :monto) < 0.005"
            );
            $consulta->execute([':empresa' => $this->empresa, ':cliente' => $clienteId,
                ':monto' => (float) $movimiento['monto']]);
            foreach ($consulta->fetchAll(PDO::FETCH_COLUMN) as $perfil) {
                if (preg_match('/^[1-9][0-9]*$/D', (string) $perfil)) $excepciones[(int) $perfil] = true;
            }
        }
        foreach ($datos['perfiles'] as &$perfil) {
            $conteo = $conteos[(int) $perfil['id']] ?? ['empresa' => 0, 'global' => 0];
            $perfil['documentos_vivos_empresa'] = $conteo['empresa'];
            $perfil['documentos_vivos_global'] = $conteo['global'];
            $perfil['movimiento_excepcion'] = isset($excepciones[(int) $perfil['id']]);
        }
        unset($perfil);
        $datos['empresa_id'] = $this->empresa;
        return $datos;
    }

    /** @return array<int,array<string,mixed>> */
    public function facturasDisponibles(int $clienteId, int $perfilId, int $pagoId = 0): array
    {
        $this->exigirPerfil($clienteId, $perfilId);
        $movimiento = $pagoId > 0 ? $this->movimientoDisponible($pagoId) : null;
        if ($movimiento !== null && (int) $movimiento['cliente_id'] !== $clienteId) {
            throw new ComplementoPagoValidacionException('El movimiento no pertenece al cliente seleccionado.');
        }
        $consulta = $this->conexion->prepare(
            "SELECT f.id, f.uuid, f.serie, f.folio, f.fecha, f.fecha_timbrado,
                    f.total_iva, f.xml_total, f.xml_subtotal, f.total_factura,
                    f.xml_firmado, f.retencion_transporte,
                    COALESCE(SUM(rc.monto), 0) AS pagado,
                    COALESCE(MAX(rc.num_pago), 0) AS ultimo_pago
             FROM facturas f
             LEFT JOIN facturar_rel_complementos rc ON rc.id_factura = f.id
             WHERE f.razon = :empresa AND f.nombre = :cliente
               AND TRIM(COALESCE(f.id_clave_corta, '')) = CAST(:perfil AS CHAR)
               AND f.metodo_pago = 2
               AND COALESCE(f.status, '') <> 'Cancelado'
               AND COALESCE(f.cancelado, 0) = 0
               AND f.desactivado IS NULL
               AND f.uuid IS NOT NULL AND TRIM(f.uuid) <> ''
             GROUP BY f.id
             HAVING f.total_iva - COALESCE(SUM(rc.monto), 0) > 0.005
                 OR (:monto_movimiento > 0 AND ABS(f.total_iva - :monto_exacto) < 0.005)
             ORDER BY f.id DESC"
        );
        $consulta->bindValue(':empresa', $this->empresa, PDO::PARAM_INT);
        $consulta->bindValue(':cliente', $clienteId, PDO::PARAM_INT);
        $consulta->bindValue(':perfil', $perfilId, PDO::PARAM_INT);
        $consulta->bindValue(':monto_movimiento', $movimiento !== null ? (float) $movimiento['monto'] : 0);
        $consulta->bindValue(':monto_exacto', $movimiento !== null ? (float) $movimiento['monto'] : 0);
        $consulta->execute();
        $resultado = [];
        $filas = $consulta->fetchAll(PDO::FETCH_ASSOC);
        $tratamientos = ComplementoTratamientoFiscal::desdeFacturas($this->conexion, $filas);
        foreach ($filas as $fila) {
            if (!$this->uuidValido((string) $fila['uuid'])) continue;
            $total = $this->aCentavos($fila['total_iva']);
            $pagado = $this->aCentavos($fila['pagado']);
            $saldo = max(0, $total - $pagado);
            if ($saldo === 0 && $movimiento === null) continue;
            $tratamiento = $tratamientos[(int) $fila['id']];
            $resultado[] = [
                'id' => (int) $fila['id'],
                'uuid' => strtoupper(trim((string) $fila['uuid'])),
                'folio' => trim((string) ($fila['serie'] ?? '')) . '-' . trim((string) ($fila['folio'] ?? '')),
                'fecha' => (string) ($fila['fecha_timbrado'] ?: $fila['fecha']),
                'total' => $total / 100,
                'saldo' => $saldo / 100,
                'sin_saldo' => $saldo === 0,
                'siguiente_parcialidad' => (int) $fila['ultimo_pago'] + 1,
                'tratamiento' => $tratamiento,
                'tratamiento_nombre' => ComplementoTratamientoFiscal::nombre($tratamiento),
            ];
        }
        return $resultado;
    }

    /** @return array<string,mixed> */
    public function facturaPorUuid(int $clienteId, int $perfilId, string $uuid): array
    {
        $this->exigirPerfil($clienteId, $perfilId);
        $uuid = strtoupper(trim($uuid));
        if (!$this->uuidValido($uuid)) throw new ComplementoPagoValidacionException('El UUID no tiene un formato válido.');
        $consulta = $this->conexion->prepare(
            "SELECT f.id, f.uuid, f.serie, f.folio, f.fecha, f.fecha_timbrado,
                    f.total_iva, f.xml_total, f.xml_subtotal, f.total_factura,
                    f.xml_firmado, f.retencion_transporte,
                    COALESCE(SUM(rc.monto), 0) AS pagado,
                    COALESCE(MAX(rc.num_pago), 0) AS ultimo_pago
             FROM facturas f
             LEFT JOIN facturar_rel_complementos rc ON rc.id_factura = f.id
             WHERE f.razon = :empresa AND UPPER(TRIM(f.uuid)) = :uuid
               AND f.metodo_pago = 2 AND COALESCE(f.status, '') <> 'Cancelado'
               AND COALESCE(f.cancelado, 0) = 0 AND f.desactivado IS NULL
             GROUP BY f.id
             HAVING f.total_iva - COALESCE(SUM(rc.monto), 0) > 0.005 LIMIT 2"
        );
        $consulta->execute([':empresa' => $this->empresa, ':uuid' => $uuid]);
        $filas = $consulta->fetchAll(PDO::FETCH_ASSOC);
        if (count($filas) !== 1) {
            throw new ComplementoPagoValidacionException('El UUID no identifica una factura PPD con saldo de esta empresa.');
        }
        $fila = $filas[0];
        $saldo = $this->aCentavos($fila['total_iva']) - $this->aCentavos($fila['pagado']);
        $tratamiento = ComplementoTratamientoFiscal::desdeFactura($this->conexion, $fila);
        return [
            'id' => (int) $fila['id'],
            'uuid' => $uuid,
            'folio' => trim((string) ($fila['serie'] ?? '')) . '-' . trim((string) ($fila['folio'] ?? '')),
            'fecha' => (string) ($fila['fecha_timbrado'] ?: $fila['fecha']),
            'total' => $this->aCentavos($fila['total_iva']) / 100,
            'saldo' => $saldo / 100,
            'siguiente_parcialidad' => (int) $fila['ultimo_pago'] + 1,
            'tratamiento' => $tratamiento,
            'tratamiento_nombre' => ComplementoTratamientoFiscal::nombre($tratamiento),
            'autorizacion_uuid' => ComplementoUuidAutorizacion::emitir($uuid, $this->empresa, $this->usuario),
        ];
    }

    /** @param array<string,mixed> $datos @return array{id:int,folio:string,total:float} */
    public function guardarPendiente(array $datos): array
    {
        $clienteId = filter_var($datos['cliente_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $perfilId = filter_var($datos['perfil_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $formaId = filter_var($datos['forma_pago_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $pagoId = filter_var($datos['pago_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        if (array_key_exists('pago_id', $datos) && $datos['pago_id'] !== null && $pagoId <= 0) {
            throw new ComplementoPagoValidacionException('El ID del movimiento no es válido.');
        }
        $token = (string) ($datos['solicitud_id'] ?? '');
        $partidas = $datos['facturas'] ?? null;
        if ($clienteId <= 0 || $perfilId <= 0 || $formaId <= 0
            || !preg_match('/^[a-f0-9]{32}$/D', $token)
            || !is_array($partidas) || $partidas === [] || count($partidas) > 20) {
            throw new ComplementoPagoValidacionException('Completa el cliente, perfil, forma de pago y las facturas relacionadas.');
        }
        $ahora = new DateTimeImmutable('now');
        $fechaTexto = $datos['fecha_pago'] ?? null;
        $horaTexto = $datos['hora_pago'] ?? null;
        $fecha = $ahora;
        if ($fechaTexto !== null || $horaTexto !== null) {
            if (!is_string($fechaTexto) || !is_string($horaTexto)
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fechaTexto)
                || !preg_match('/^\d{2}:\d{2}:\d{2}$/D', $horaTexto)) {
                throw new ComplementoPagoValidacionException('Selecciona una fecha de pago válida.');
            }
            $fecha = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s', $fechaTexto . ' ' . $horaTexto,
                new DateTimeZone(SesionEmpresa::ZONA_HORARIA)
            );
            if ($fecha === false || $fecha->format('Y-m-d H:i:s') !== $fechaTexto . ' ' . $horaTexto || $fecha > $ahora) {
                throw new ComplementoPagoValidacionException('La fecha de pago no puede ser inválida ni futura.');
            }
        }
        $montos = [];
        $autorizacionesUuid = [];
        foreach ($partidas as $partida) {
            if (!is_array($partida)) throw new ComplementoPagoValidacionException('Una factura relacionada no es válida.');
            $id = filter_var($partida['factura_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            $monto = $this->montoSolicitado($partida['monto'] ?? null);
            if ($id <= 0 || $monto <= 0 || isset($montos[$id])) {
                throw new ComplementoPagoValidacionException('Revisa los importes y evita repetir la misma factura.');
            }
            $montos[$id] = $monto;
            $autorizacionesUuid[$id] = is_string($partida['autorizacion_uuid'] ?? null)
                ? $partida['autorizacion_uuid'] : '';
        }
        ksort($montos, SORT_NUMERIC);
        $etapa = 'validar el perfil fiscal del cliente';
        try {
            $this->exigirPerfil($clienteId, $perfilId);
            $etapa = 'validar la forma de pago';
            $this->exigirFormaPago($formaId);

            // El token permite recuperar la misma alta si el navegador reintenta la solicitud.
            $etapa = 'bloquear la solicitud para evitar duplicados';
            $nombreBloqueo = 'complemento-pago-' . $this->empresa . '-' . $token;
            $bloqueo = $this->conexion->prepare('SELECT GET_LOCK(:nombre, 5)');
            $bloqueo->execute([':nombre' => $nombreBloqueo]);
            if ((int) $bloqueo->fetchColumn() !== 1) {
                throw new ComplementoPagoValidacionException('Hay otra solicitud en proceso. Inténtalo de nuevo.');
            }
            try {
            $etapa = 'comprobar si el complemento ya fue guardado';
            $existente = $this->buscarPorToken($token, $clienteId);
            if ($existente !== null) return $existente;
            $etapa = 'iniciar la transacción';
            $this->conexion->beginTransaction();
            try {
                $perfiles = $this->perfilesConDisponibilidad($clienteId, $pagoId)['perfiles'];
                $perfilDisponible = false;
                foreach ($perfiles as $perfil) {
                    if ((int) $perfil['id'] === $perfilId
                        && ((int) $perfil['documentos_vivos_empresa'] > 0 || $perfil['movimiento_excepcion'])) {
                        $perfilDisponible = true;
                        break;
                    }
                }
                if (!$perfilDisponible) {
                    throw new ComplementoPagoValidacionException('La razón social no tiene documentos PPD vivos para la empresa emisora.');
                }
                $movimiento = $pagoId > 0
                    ? (new ComplementoMovimientoServicio())->obtenerDisponible(
                        $this->conexion, $pagoId, $this->empresa, $this->usuario, true
                    ) : null;
                if ($movimiento !== null && (int) $movimiento['cliente_id'] !== $clienteId) {
                    throw new ComplementoPagoValidacionException('El movimiento no pertenece al cliente del complemento.');
                }
                $relaciones = [];
                $totalCentavos = 0;
                $tratamientoHeredado = null;
                $uuids = [];
                foreach ($montos as $facturaId => $montoCentavos) {
                    $etapa = 'consultar y bloquear la factura relacionada';
                    $fila = $this->facturaBloqueada($facturaId, $clienteId, $perfilId,
                        $autorizacionesUuid[$facturaId]);
                    $uuid = strtoupper(trim((string) $fila['uuid']));
                    if (isset($uuids[$uuid])) {
                        throw new ComplementoPagoValidacionException('El UUID de una factura está repetido en el complemento.');
                    }
                    $uuids[$uuid] = true;
                    $tratamiento = ComplementoTratamientoFiscal::desdeFactura($this->conexion, $fila);
                    if ($tratamientoHeredado !== null && $tratamientoHeredado !== $tratamiento) {
                        throw new ComplementoPagoValidacionException('La factura usa ' . ComplementoTratamientoFiscal::nombre($tratamiento)
                            . ', pero el complemento ya heredó ' . ComplementoTratamientoFiscal::nombre($tratamientoHeredado)
                            . '. Crea complementos separados.');
                    }
                    $tratamientoHeredado = $tratamiento;
                    $totalFactura = $this->aCentavos($fila['total_iva']);
                    $etapa = 'consultar el saldo y las parcialidades previas';
                    $consultaPagos = $this->conexion->prepare(
                        'SELECT COALESCE(SUM(monto), 0) AS pagado, COALESCE(MAX(num_pago), 0) AS ultimo
                         FROM facturar_rel_complementos WHERE id_factura = :factura'
                    );
                    $consultaPagos->execute([':factura' => $facturaId]);
                    $pagos = $consultaPagos->fetch(PDO::FETCH_ASSOC);
                    $saldo = $totalFactura - $this->aCentavos($pagos['pagado'] ?? 0);
                    if ($saldo <= 0 || $montoCentavos > $saldo) {
                        throw new ComplementoPagoValidacionException('La factura ' . $facturaId . ' ya no tiene saldo suficiente. Recarga el formulario.');
                    }
                    if (substr((string) ($fila['fecha'] ?? ''), 0, 10) > $fecha->format('Y-m-d')) {
                        throw new ComplementoPagoValidacionException('La fecha de pago no puede preceder a la factura ' . $facturaId . '.');
                    }
                    $relaciones[] = [
                        'id' => $facturaId, 'monto' => $montoCentavos / 100,
                        'saldo' => $saldo / 100, 'restante' => ($saldo - $montoCentavos) / 100,
                        'numero' => (int) ($pagos['ultimo'] ?? 0) + 1,
                        'uuid' => $uuid,
                        'folio' => (int) $fila['folio'], 'serie' => (string) ($fila['serie'] ?? ''),
                    ];
                    $totalCentavos += $montoCentavos;
                }
                if ($movimiento !== null && $totalCentavos !== $this->aCentavos($movimiento['monto'])) {
                    throw new ComplementoPagoValidacionException('La suma de pagos debe coincidir con el monto del movimiento.');
                }
                $etapa = 'consultar los catálogos de método y moneda';
                $metodo = (int) $this->conexion->query("SELECT id FROM metodos_pago WHERE UPPER(TRIM(clave)) = 'PUE' ORDER BY id LIMIT 1")->fetchColumn();
                $moneda = (int) $this->conexion->query("SELECT id FROM catalogo_monedas WHERE UPPER(TRIM(clave_moneda)) = 'MXN' ORDER BY id LIMIT 1")->fetchColumn();
                if ($metodo <= 0 || $moneda <= 0) {
                    throw new RuntimeException('Faltan los catálogos de PUE o MXN para el complemento.');
                }
                $etapa = 'insertar el comprobante pendiente';
                $insertar = $this->conexion->prepare(
                    "INSERT INTO facturas_complementos
                        (fecha, nombre, metodo_pago, moneda, forma_pago, status_pago, total_factura, id_pago,
                         razon, tipo_factura, tipo_comprobante, token, id_clave_corta, serie,
                         fecha_pago, status, cadena)
                     VALUES (:fecha_emision, :cliente, :metodo, :moneda, :forma, 'Pendiente', :total, :pago,
                             :empresa, 'P', 'P', :token, :perfil, 'P', :fecha_pago, 'Pendiente', :tratamiento)"
                );
                $insertar->execute([
                    ':fecha_emision' => date('Y-m-d'), ':cliente' => $clienteId,
                    ':pago' => $pagoId > 0 ? $pagoId : null,
                    ':metodo' => $metodo, ':moneda' => $moneda,
                    ':forma' => (string) $formaId, ':total' => $totalCentavos / 100,
                    ':empresa' => $this->empresa, ':token' => $token, ':perfil' => (string) $perfilId,
                    ':fecha_pago' => $fecha->format('Y-m-d H:i:s'),
                    ':tratamiento' => $tratamientoHeredado,
                ]);
                $complementoId = (int) $this->conexion->lastInsertId();
                $etapa = 'asignar el folio al comprobante';
                $actualizarFolio = $this->conexion->prepare('UPDATE facturas_complementos SET folio = :folio WHERE id = :id');
                $actualizarFolio->execute([':folio' => $complementoId, ':id' => $complementoId]);
                $etapa = 'insertar las facturas relacionadas y sus pagos';
                $insertarRelacion = $this->conexion->prepare(
                    'INSERT INTO facturar_rel_complementos
                        (id_factura, id_complemento, monto, num_pago, saldo, restante, uuid_rel, folio, serie, fecha_pago)
                     VALUES (:factura, :complemento, :monto, :numero, :saldo, :restante, :uuid, :folio, :serie, :fecha_pago)'
                );
                foreach ($relaciones as $relacion) {
                    $insertarRelacion->execute([
                        ':factura' => $relacion['id'], ':complemento' => $complementoId,
                        ':monto' => $relacion['monto'], ':numero' => $relacion['numero'],
                        ':saldo' => $relacion['saldo'], ':restante' => $relacion['restante'],
                        ':uuid' => $relacion['uuid'], ':folio' => $relacion['folio'],
                        ':serie' => $relacion['serie'], ':fecha_pago' => $fecha->format('Y-m-d H:i:s'),
                    ]);
                }
                if ($movimiento !== null) {
                    $etapa = 'relacionar el movimiento con el complemento';
                    $actualizarPago = $this->conexion->prepare(
                        'UPDATE pagos SET id_factura_res = :complemento WHERE id = :pago'
                    );
                    $actualizarPago->execute([':complemento' => $complementoId, ':pago' => $pagoId]);
                    if ($actualizarPago->rowCount() !== 1) {
                        throw new ComplementoPagoValidacionException('No fue posible relacionar el movimiento con el complemento.');
                    }
                }
                $etapa = 'confirmar la transacción';
                $this->conexion->commit();
                return ['id' => $complementoId, 'folio' => 'P-' . $complementoId, 'total' => $totalCentavos / 100];
            } catch (Throwable $error) {
                if ($this->conexion->inTransaction()) $this->conexion->rollBack();
                throw $error;
            }
            } finally {
                try {
                    $liberar = $this->conexion->prepare('SELECT RELEASE_LOCK(:nombre)');
                    $liberar->execute([':nombre' => $nombreBloqueo]);
                } catch (PDOException $error) {
                    $etapa = 'liberar el bloqueo de la solicitud';
                    throw $error;
                }
            }
        } catch (PDOException $error) {
            throw new ComplementoPagoPersistenciaException($etapa, $error);
        }
    }

    /** @return array{id:int,folio:string,total:float}|null */
    private function buscarPorToken(string $token, int $clienteId): ?array
    {
        $consulta = $this->conexion->prepare(
            'SELECT id, total_factura FROM facturas_complementos
             WHERE token = :token AND razon = :empresa AND nombre = :cliente LIMIT 1'
        );
        $consulta->execute([':token' => $token, ':empresa' => $this->empresa, ':cliente' => $clienteId]);
        $fila = $consulta->fetch(PDO::FETCH_ASSOC);
        return $fila ? ['id' => (int) $fila['id'], 'folio' => 'P-' . (int) $fila['id'], 'total' => (float) $fila['total_factura']] : null;
    }

    private function exigirPerfil(int $clienteId, int $perfilId): void
    {
        $consulta = $this->conexion->prepare(
            'SELECT cc.id FROM claves_cortas cc
             INNER JOIN usuarios u ON u.cliente = cc.id_cliente AND u.id = :usuario
             WHERE cc.id = :perfil AND cc.id_cliente = :cliente LIMIT 1'
        );
        $consulta->execute([':usuario' => $this->usuario, ':perfil' => $perfilId, ':cliente' => $clienteId]);
        if (!$consulta->fetchColumn()) {
            throw new ComplementoPagoValidacionException('El perfil fiscal no pertenece a un cliente asignado a tu usuario.');
        }
    }

    private function exigirFormaPago(int $formaId): void
    {
        $consulta = $this->conexion->prepare('SELECT LPAD(clave, 2, \'0\') FROM formas_pago WHERE id = :forma LIMIT 1');
        $consulta->execute([':forma' => $formaId]);
        $clave = (string) $consulta->fetchColumn();
        if ($clave === '' || $clave === '99') {
            throw new ComplementoPagoValidacionException('Selecciona una forma de pago válida para el pago recibido.');
        }
    }

    /** @return array<string,mixed> */
    private function facturaBloqueada(int $id, int $clienteId, int $perfilId, string $autorizacionUuid): array
    {
        $consulta = $this->conexion->prepare(
            "SELECT f.id, f.uuid, f.nombre, f.id_clave_corta, f.serie, f.folio, f.fecha,
                    f.total_iva, f.xml_total, f.xml_subtotal, f.total_factura,
                    f.xml_firmado, f.retencion_transporte
             FROM facturas f
             WHERE f.id = :factura AND f.razon = :empresa
               AND f.metodo_pago = 2
               AND COALESCE(f.status, '') <> 'Cancelado'
               AND COALESCE(f.cancelado, 0) = 0 AND f.desactivado IS NULL
             LIMIT 1 FOR UPDATE"
        );
        $consulta->execute([':factura' => $id, ':empresa' => $this->empresa]);
        $fila = $consulta->fetch(PDO::FETCH_ASSOC);
        if (!$fila || !$this->uuidValido((string) $fila['uuid'])) {
            throw new ComplementoPagoValidacionException('La factura ' . $id . ' no está disponible para este cliente y empresa.');
        }
        $mismoPerfil = (int) $fila['nombre'] === $clienteId
            && trim((string) $fila['id_clave_corta']) === (string) $perfilId;
        if (!$mismoPerfil && !ComplementoUuidAutorizacion::verificar(
            $autorizacionUuid, (string) $fila['uuid'], $this->empresa, $this->usuario
        )) {
            throw new ComplementoPagoValidacionException('La factura no pertenece al cliente o perfil, y no tiene autorización UUID vigente.');
        }
        return $fila;
    }

    private function montoSolicitado(mixed $valor): int
    {
        $texto = is_int($valor) || is_float($valor) || is_string($valor) ? (string) $valor : '';
        if (!preg_match('/^(?:0|[1-9]\d{0,8})(?:\.(\d{1,2}))?$/D', $texto, $partes)) return 0;
        [$entero] = explode('.', $texto);
        return (int) $entero * 100 + (int) str_pad($partes[1] ?? '', 2, '0');
    }

    private function aCentavos(mixed $valor): int
    {
        return (int) round((float) $valor * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function uuidValido(string $uuid): bool
    {
        return preg_match('/^[A-Fa-f0-9]{8}(?:-[A-Fa-f0-9]{4}){3}-[A-Fa-f0-9]{12}$/D', trim($uuid)) === 1;
    }
}
