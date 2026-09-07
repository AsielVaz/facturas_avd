<?php

declare(strict_types=1);

require_once __DIR__ . '/FacturacionAdministrador.php';
require_once __DIR__ . '/CfdiXmlGenerador.php';

final class FacturaPendienteValidacionException extends RuntimeException
{
    /** @param array<int, string> $errores */
    public function __construct(public readonly array $errores)
    {
        parent::__construct($errores[0] ?? 'La factura pendiente contiene errores.');
    }
}

final class FacturaPendienteAdministrador
{
    private readonly int $empresa;
    private readonly FacturacionAdministrador $facturacion;
    private readonly CfdiXmlGenerador $xmlGenerador;

    public function __construct(private readonly PDO $conexion, ?int $empresa = null)
    {
        $this->empresa = max(1, $empresa ?? SesionEmpresa::empresaActual());
        $this->facturacion = new FacturacionAdministrador($conexion, $this->empresa);
        $this->xmlGenerador = new CfdiXmlGenerador(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'sinfirma');
    }

    /** @return array<string, mixed> */
    public function obtener(int $facturaId): array
    {
        $consulta = $this->conexion->prepare(
            "SELECT
                f.id, f.fecha, f.nombre AS cliente_original, f.id_clave_corta,
                f.metodo_pago, f.moneda, f.forma_pago, f.uso_cfdi,
                f.status_pago, f.status, f.status_error, f.folio, f.serie,
                COALESCE(f.retencion_isr_tasa, 0) AS retencion_isr_tasa,
                COALESCE(f.retencion_iva_tasa, 0) AS retencion_iva_tasa,
                f.total_sin_iva, f.total_iva, f.total_factura, f.xml_subtotal, f.xml_total,
                cc.id_cliente AS cliente_perfil,
                UPPER(TRIM(COALESCE(uso.cod_sat, ''))) AS uso_clave
             FROM facturas f
             LEFT JOIN claves_cortas cc ON cc.id = CAST(f.id_clave_corta AS UNSIGNED)
             LEFT JOIN catalogo_usos_cfdi uso ON uso.id = f.uso_cfdi
             WHERE f.id = :factura
               AND f.razon = :empresa
               AND (f.uuid IS NULL OR TRIM(f.uuid) = '')
             LIMIT 1"
        );
        $consulta->bindValue(':factura', $facturaId, PDO::PARAM_INT);
        $consulta->bindValue(':empresa', $this->empresa, PDO::PARAM_INT);
        $consulta->execute();
        $factura = $consulta->fetch();
        if (!$factura) {
            throw new RuntimeException('La factura no existe, pertenece a otra empresa o ya fue timbrada.');
        }

        $consultaDetalles = $this->conexion->prepare(
            "SELECT
                df.id AS detalle_id, df.id_producto, df.cantidad, df.precio_unitario,
                df.precio_con_iva, df.precio_neto, df.precio_neto_iva,
                TRIM(COALESCE(df.concepto, '')) AS descripcion_guardada,
                COALESCE(df.iva, 0) AS iva_guardado,
                c.id AS concepto_id, TRIM(COALESCE(c.concepto, '')) AS concepto_catalogo,
                TRIM(COALESCE(c.clave_sat, '')) AS clave_sat,
                TRIM(COALESCE(c.clave_producto, '')) AS clave_producto,
                UPPER(TRIM(COALESCE(c.clave_unidad_medida, ''))) AS clave_unidad_medida,
                TRIM(COALESCE(um.nombre_unidad_medida, '')) AS unidad_medida,
                COALESCE(c.precio_min, 0) AS precio_min, COALESCE(c.precio_max, 0) AS precio_max,
                c.unidades_max, COALESCE(c.objeto_impuesto, 0) AS objeto_impuesto_local,
                COALESCE(c.impuesto, df.iva, 0) AS tasa_catalogo, c.aplica_iva,
                CAST(COALESCE(c.razon, '0') AS UNSIGNED) AS concepto_empresa
             FROM detalle_factura df
             LEFT JOIN conceptos c ON c.id = df.id_producto
             LEFT JOIN catalogo_unidad_medida um
               ON UPPER(TRIM(um.clave_unidad_medida)) = UPPER(TRIM(c.clave_unidad_medida))
             WHERE df.id_factura = :factura
             ORDER BY df.id"
        );
        $consultaDetalles->bindValue(':factura', $facturaId, PDO::PARAM_INT);
        $consultaDetalles->execute();
        $filas = $consultaDetalles->fetchAll();
        $partidas = [];
        $conceptosHistoricos = [];
        foreach ($filas as $fila) {
            $concepto = $this->normalizarConceptoHistorico($fila);
            if ($concepto !== null) {
                $conceptosHistoricos[(int) $concepto['id']] = $concepto;
            }
            $partidas[] = [
                'detalle_id' => (int) $fila['detalle_id'],
                'concepto_id' => (int) ($fila['concepto_id'] ?? $fila['id_producto']),
                'descripcion' => trim((string) $fila['descripcion_guardada']) !== ''
                    ? (string) $fila['descripcion_guardada']
                    : (string) $fila['concepto_catalogo'],
                'cantidad' => (float) $fila['cantidad'],
                'precio_unitario' => (float) $fila['precio_unitario'],
                'descuento' => 0.0,
                'iva' => (float) $fila['iva_guardado'],
            ];
        }

        $resultado = [
            'id' => (int) $factura['id'],
            'fecha' => (string) $factura['fecha'],
            'cliente_id' => (int) ($factura['cliente_perfil'] ?: $factura['cliente_original']),
            'perfil_id' => (int) $factura['id_clave_corta'],
            'metodo_pago_id' => (int) $factura['metodo_pago'],
            'moneda_id' => (int) $factura['moneda'],
            'forma_pago_id' => (int) $factura['forma_pago'],
            'uso_cfdi' => (string) $factura['uso_clave'],
            'retencion_isr_tasa' => (float) $factura['retencion_isr_tasa'],
            'retencion_iva_tasa' => (float) $factura['retencion_iva_tasa'],
            'exportacion' => '01',
            'folio' => trim((string) $factura['serie']) . '-' . (string) ($factura['folio'] ?: $factura['id']),
            'serie_cfdi' => trim((string) $factura['serie']),
            'folio_cfdi' => (int) ($factura['folio'] ?: $factura['id']),
            'status_pago' => (string) $factura['status_pago'],
            'status' => (string) $factura['status'],
            'status_error' => (string) $factura['status_error'],
            'partidas' => $partidas,
            'conceptos_historicos' => array_values($conceptosHistoricos),
        ];
        $resultado['huella'] = $this->crearHuella($resultado);
        return $resultado;
    }

    /** @param array<string, mixed> $datos
     *  @return array<string, mixed>
     */
    public function validar(int $facturaId, array $datos): array
    {
        return $this->validarConPendiente($this->obtener($facturaId), $datos);
    }

    /** @return array{archivo: string, ruta: string, url: string, contenido: string} */
    public function generarXml(int $facturaId): array
    {
        $pendiente = $this->obtener($facturaId);
        $resultado = $this->validarConPendiente($pendiente, $pendiente);
        if (!$resultado['valido']) {
            throw new FacturaPendienteValidacionException($resultado['errores']);
        }
        return $this->xmlGenerador->facturar4Plus($facturaId, [
            'serie' => (string) $pendiente['serie_cfdi'],
            'folio' => (int) $pendiente['folio_cfdi'],
            'comprobante' => $resultado['comprobante'],
            'emisor' => $resultado['emisor'],
            'receptor' => $resultado['receptor'],
            'partidas' => $resultado['partidas'],
        ]);
    }

    /** @param array<string, mixed> $datos
     *  @return array{resultado: array<string, mixed>, factura: array<string, mixed>}
     */
    public function guardar(int $facturaId, array $datos): array
    {
        $rutaXml = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'sinfirma'
            . DIRECTORY_SEPARATOR . 'XML-factura-' . $facturaId . '.xml';
        $xmlAnterior = is_file($rutaXml) ? file_get_contents($rutaXml) : null;
        $xmlActualizado = false;
        $this->conexion->beginTransaction();
        try {
            $bloqueo = $this->conexion->prepare(
                "SELECT id FROM facturas
                 WHERE id = :factura AND razon = :empresa
                   AND (uuid IS NULL OR TRIM(uuid) = '')
                 FOR UPDATE"
            );
            $bloqueo->bindValue(':factura', $facturaId, PDO::PARAM_INT);
            $bloqueo->bindValue(':empresa', $this->empresa, PDO::PARAM_INT);
            $bloqueo->execute();
            if (!$bloqueo->fetchColumn()) {
                throw new RuntimeException('La factura ya no está disponible para edición.');
            }

            $pendiente = $this->obtener($facturaId);
            $huellaRecibida = (string) ($datos['huella'] ?? '');
            if ($huellaRecibida === '' || !hash_equals((string) $pendiente['huella'], $huellaRecibida)) {
                throw new FacturaPendienteValidacionException([
                    'La factura cambió desde que abriste el editor. Recarga la página antes de guardar.',
                ]);
            }
            $resultado = $this->validarConPendiente($pendiente, $datos);
            if (!$resultado['valido']) {
                throw new FacturaPendienteValidacionException($resultado['errores']);
            }
            if ((float) $resultado['comprobante']['descuento'] !== 0.0) {
                throw new FacturaPendienteValidacionException([
                    'La estructura actual de detalle_factura no permite guardar descuentos por partida.',
                ]);
            }

            $usoId = $this->obtenerUsoCfdiId((string) $resultado['comprobante']['uso_cfdi']);
            $metodoClave = (string) $resultado['comprobante']['metodo_pago'];
            $fecha = substr((string) $resultado['comprobante']['fecha'], 0, 10);
            $actualizar = $this->conexion->prepare(
                "UPDATE facturas SET
                    fecha = :fecha,
                    nombre = :cliente,
                    metodo_pago = :metodo,
                    moneda = :moneda,
                    forma_pago = :forma,
                    status_pago = :status_pago,
                    total_factura = :total_factura,
                    total_sin_iva = :subtotal,
                    total_iva = :total_iva,
                    tipo_comprobante = 'I',
                    uso_cfdi = :uso_cfdi,
                    id_clave_corta = :perfil,
                    retencion_isr_tasa = :retencion_isr_tasa,
                    retencion_iva_tasa = :retencion_iva_tasa,
                    xml_subtotal = :xml_subtotal,
                    xml_total = :xml_total,
                    status = 'Pendiente',
                    status_error = NULL
                 WHERE id = :factura
                   AND razon = :empresa
                   AND (uuid IS NULL OR TRIM(uuid) = '')"
            );
            $actualizar->bindValue(':fecha', $fecha, PDO::PARAM_STR);
            $actualizar->bindValue(':cliente', (int) $datos['cliente_id'], PDO::PARAM_INT);
            $actualizar->bindValue(':metodo', (int) $datos['metodo_pago_id'], PDO::PARAM_INT);
            $actualizar->bindValue(':moneda', (int) $datos['moneda_id'], PDO::PARAM_INT);
            $actualizar->bindValue(':forma', (string) ((int) $datos['forma_pago_id']), PDO::PARAM_STR);
            $actualizar->bindValue(':status_pago', $metodoClave === 'PUE' ? 'Pagado' : 'No Pagado', PDO::PARAM_STR);
            $actualizar->bindValue(':total_factura', (float) $resultado['comprobante']['total']);
            $actualizar->bindValue(':subtotal', (float) $resultado['comprobante']['subtotal']);
            $actualizar->bindValue(':total_iva', (float) $resultado['comprobante']['total']);
            $actualizar->bindValue(':uso_cfdi', $usoId, PDO::PARAM_INT);
            $actualizar->bindValue(':perfil', (string) ((int) $datos['perfil_id']), PDO::PARAM_STR);
            $actualizar->bindValue(':retencion_isr_tasa', (float) $resultado['comprobante']['retencion_isr_tasa']);
            $actualizar->bindValue(':retencion_iva_tasa', (float) $resultado['comprobante']['retencion_iva_tasa']);
            $actualizar->bindValue(':xml_subtotal', (float) $resultado['comprobante']['subtotal']);
            $actualizar->bindValue(':xml_total', (float) $resultado['comprobante']['total']);
            $actualizar->bindValue(':factura', $facturaId, PDO::PARAM_INT);
            $actualizar->bindValue(':empresa', $this->empresa, PDO::PARAM_INT);
            $actualizar->execute();

            $detallesActuales = [];
            foreach ($pendiente['partidas'] as $partidaActual) {
                $detallesActuales[(int) $partidaActual['detalle_id']] = true;
            }
            $conservados = [];
            foreach ($resultado['partidas'] as $partida) {
                $detalleId = (int) $partida['detalle_id'];
                $tasa = (float) $partida['tasa_iva'];
                $precioConIva = $partida['objeto_impuesto'] === '02'
                    ? round((float) $partida['valor_unitario'] * (1 + ($tasa / 100)), 6)
                    : (float) $partida['valor_unitario'];
                if ($detalleId > 0) {
                    if (!isset($detallesActuales[$detalleId])) {
                        throw new FacturaPendienteValidacionException(['Una partida no pertenece a esta factura.']);
                    }
                    $detalle = $this->conexion->prepare(
                        "UPDATE detalle_factura SET
                            id_producto = :producto, cantidad = :cantidad,
                            precio_unitario = :precio_unitario, precio_con_iva = :precio_con_iva,
                            precio_neto = :precio_neto, precio_neto_iva = :precio_neto_iva,
                            concepto = :concepto, iva = :iva
                         WHERE id = :detalle AND id_factura = :factura"
                    );
                    $detalle->bindValue(':detalle', $detalleId, PDO::PARAM_INT);
                    $conservados[$detalleId] = true;
                } else {
                    $detalle = $this->conexion->prepare(
                        "INSERT INTO detalle_factura
                            (id_factura, id_producto, cantidad, precio_unitario, precio_con_iva, precio_neto, precio_neto_iva, concepto, iva)
                         VALUES
                            (:factura, :producto, :cantidad, :precio_unitario, :precio_con_iva, :precio_neto, :precio_neto_iva, :concepto, :iva)"
                    );
                }
                $detalle->bindValue(':factura', $facturaId, PDO::PARAM_INT);
                $detalle->bindValue(':producto', (int) $partida['concepto_id'], PDO::PARAM_INT);
                $detalle->bindValue(':cantidad', (float) $partida['cantidad']);
                $detalle->bindValue(':precio_unitario', (float) $partida['valor_unitario']);
                $detalle->bindValue(':precio_con_iva', $precioConIva);
                $detalle->bindValue(':precio_neto', (float) $partida['importe']);
                $detalle->bindValue(':precio_neto_iva', (float) $partida['total']);
                $detalle->bindValue(':concepto', (string) $partida['descripcion'], PDO::PARAM_STR);
                $detalle->bindValue(':iva', (int) round($tasa), PDO::PARAM_INT);
                $detalle->execute();
            }

            $eliminarIds = array_values(array_diff(array_keys($detallesActuales), array_keys($conservados)));
            if ($eliminarIds !== []) {
                $marcadores = [];
                foreach ($eliminarIds as $indice => $detalleId) {
                    $marcadores[] = ':detalle_' . $indice;
                }
                $eliminar = $this->conexion->prepare(
                    'DELETE FROM detalle_factura WHERE id_factura = :factura AND id IN (' . implode(', ', $marcadores) . ')'
                );
                $eliminar->bindValue(':factura', $facturaId, PDO::PARAM_INT);
                foreach ($eliminarIds as $indice => $detalleId) {
                    $eliminar->bindValue(':detalle_' . $indice, $detalleId, PDO::PARAM_INT);
                }
                $eliminar->execute();
            }

            $this->xmlGenerador->facturar4Plus($facturaId, [
                'serie' => (string) $pendiente['serie_cfdi'],
                'folio' => (int) $pendiente['folio_cfdi'],
                'comprobante' => $resultado['comprobante'],
                'emisor' => $resultado['emisor'],
                'receptor' => $resultado['receptor'],
                'partidas' => $resultado['partidas'],
            ]);
            $xmlActualizado = true;
            $resultado['persistido'] = true;
            $this->conexion->commit();
            return ['resultado' => $resultado, 'factura' => $this->obtener($facturaId)];
        } catch (Throwable $error) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            if ($xmlActualizado) {
                if (is_string($xmlAnterior)) {
                    file_put_contents($rutaXml, $xmlAnterior, LOCK_EX);
                } else {
                    $this->xmlGenerador->eliminar($facturaId);
                }
            }
            throw $error;
        }
    }

    /** @param array<string, mixed> $pendiente
     *  @param array<string, mixed> $datos
     *  @return array<string, mixed>
     */
    private function validarConPendiente(array $pendiente, array $datos): array
    {
        $resultado = $this->facturacion->validarBorrador($datos, $pendiente['conceptos_historicos']);
        if ((string) ($datos['exportacion'] ?? '01') !== '01') {
            $resultado['errores'][] = 'Esta tabla no tiene un campo para persistir Exportación; conserva la clave 01.';
        }
        if ((float) ($resultado['comprobante']['descuento'] ?? 0) !== 0.0) {
            $resultado['errores'][] = 'Los descuentos no pueden guardarse porque detalle_factura no tiene una columna para ellos.';
        }
        $resultado['errores'] = array_values(array_unique($resultado['errores']));
        $resultado['valido'] = $resultado['errores'] === [];
        return $resultado;
    }

    /** @param array<string, mixed> $fila
     *  @return array<string, mixed>|null
     */
    private function normalizarConceptoHistorico(array $fila): ?array
    {
        if (empty($fila['concepto_id'])) {
            return null;
        }
        $aplicaIva = in_array(strtolower(trim((string) $fila['aplica_iva'])), ['1', 'si', 'sí', 'true'], true);
        $tasa = (float) $fila['iva_guardado'];
        $objeto = (int) $fila['objeto_impuesto_local'] === 0 ? '01' : (($aplicaIva || $tasa > 0) ? '02' : '04');
        $errores = [];
        if (!preg_match('/^\d{8}$/', (string) $fila['clave_sat'])) {
            $errores[] = 'Clave SAT inválida.';
        }
        if (!preg_match('/^[A-Z0-9]{2,3}$/', (string) $fila['clave_unidad_medida'])) {
            $errores[] = 'Clave de unidad inválida.';
        }
        if ($objeto === '02' && !in_array($tasa, [0.0, 8.0, 16.0], true)) {
            $errores[] = 'La tasa de IVA histórica no es 0%, 8% o 16%.';
        }
        return [
            'id' => (int) $fila['concepto_id'],
            'concepto' => trim((string) $fila['concepto_catalogo']) ?: trim((string) $fila['descripcion_guardada']),
            'clave_sat' => (string) $fila['clave_sat'],
            'clave_producto' => (string) $fila['clave_producto'],
            'clave_unidad_medida' => (string) $fila['clave_unidad_medida'],
            'unidad_medida' => (string) $fila['unidad_medida'],
            'precio_min' => (float) $fila['precio_min'],
            'precio_max' => (float) $fila['precio_max'],
            // Una pendiente histórica puede haber sido creada antes de los límites actuales.
            // Se conserva editable; los conceptos nuevos siguen usando unidades_max del catálogo.
            'unidades_max' => null,
            'tasa_iva' => $tasa,
            'objeto_impuesto' => $objeto,
            'errores' => $errores,
            'disponible' => $errores === [],
            'historico' => (int) $fila['concepto_empresa'] !== $this->empresa,
        ];
    }

    /** @param array<string, mixed> $factura */
    private function crearHuella(array $factura): string
    {
        $datos = $factura;
        unset($datos['huella'], $datos['conceptos_historicos']);
        return hash('sha256', json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function obtenerUsoCfdiId(string $clave): int
    {
        $consulta = $this->conexion->prepare(
            'SELECT id FROM catalogo_usos_cfdi WHERE UPPER(TRIM(cod_sat)) = :clave ORDER BY id LIMIT 1'
        );
        $consulta->bindValue(':clave', strtoupper(trim($clave)), PDO::PARAM_STR);
        $consulta->execute();
        $id = (int) $consulta->fetchColumn();
        if ($id <= 0) {
            throw new FacturaPendienteValidacionException([
                "El uso CFDI {$clave} no existe en catalogo_usos_cfdi y no puede persistirse.",
            ]);
        }
        return $id;
    }
}
