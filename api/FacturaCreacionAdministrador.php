<?php

declare(strict_types=1);

require_once __DIR__ . '/FacturacionAdministrador.php';
require_once __DIR__ . '/CfdiXmlGenerador.php';

final class FacturaCreacionValidacionException extends RuntimeException
{
    /** @param array<int, string> $errores */
    public function __construct(public readonly array $errores)
    {
        parent::__construct('La factura contiene datos inválidos.');
    }
}

final class FacturaCreacionAdministrador
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

    /**
     * @param array<string, mixed> $datos
     * @return array{id: int, folio: int, serie: string, xml: array{archivo: string, url: string}}
     */
    public function guardarFactura(array $datos): array
    {
        $validacion = $this->facturacion->validarBorrador($datos);
        if (!$validacion['valido']) {
            throw new FacturaCreacionValidacionException($validacion['errores']);
        }
        $receptor = $validacion['receptor'];
        if (!is_array($receptor)) {
            throw new FacturaCreacionValidacionException(['Selecciona un receptor fiscal válido.']);
        }

        $usoCfdiId = $this->obtenerUsoCfdiId((string) $validacion['comprobante']['uso_cfdi']);
        [$serie, $folio] = $this->generarSerieFolio();
        $token = bin2hex(random_bytes(20));
        $facturaId = 0;
        $xmlGenerado = null;

        $this->conexion->beginTransaction();
        try {
            $facturaId = $this->insertarFactura($datos, $validacion, $receptor, $usoCfdiId, $serie, $folio, $token);
            $this->insertarConceptos($facturaId, $validacion['partidas']);
            $xmlGenerado = $this->xmlGenerador->facturar4Plus($facturaId, [
                'serie' => $serie,
                'folio' => $folio,
                'comprobante' => $validacion['comprobante'],
                'emisor' => $validacion['emisor'],
                'receptor' => $receptor,
                'partidas' => $validacion['partidas'],
            ]);
            $this->conexion->commit();
        } catch (Throwable $error) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            if ($facturaId > 0 && $xmlGenerado !== null) {
                $this->xmlGenerador->eliminar($facturaId);
            }
            throw $error;
        }

        return [
            'id' => $facturaId,
            'folio' => $folio,
            'serie' => $serie,
            'xml' => [
                'archivo' => (string) $xmlGenerado['archivo'],
                'url' => (string) $xmlGenerado['url'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $validacion
     * @param array<string, mixed> $receptor
     */
    private function insertarFactura(
        array $datos,
        array $validacion,
        array $receptor,
        int $usoCfdiId,
        string $serie,
        int $folio,
        string $token
    ): int {
        $comprobante = $validacion['comprobante'];
        $consulta = $this->conexion->prepare(
            "INSERT INTO facturas
                (fecha, nombre, metodo_pago, moneda, forma_pago, status_pago,
                 total_factura, razon, total_sin_iva, total_iva, token,
                 tipo_comprobante, uso_cfdi, factura_manual, id_clave_corta,
                 status, status_error, folio, usuario_crea, serie,
                 retencion_transporte, retencion_isr_tasa, retencion_iva_tasa,
                 xml_total, xml_subtotal)
             VALUES
                (:fecha, :cliente, :metodo, :moneda, :forma, :status_pago,
                 :total_factura, :empresa, :subtotal, :total_iva, :token,
                 'I', :uso_cfdi, 0, :perfil,
                 'Pendiente', NULL, :folio, :usuario, :serie,
                 'No', :retencion_isr_tasa, :retencion_iva_tasa,
                 :xml_total, :xml_subtotal)"
        );
        $consulta->bindValue(':fecha', substr((string) $comprobante['fecha'], 0, 10), PDO::PARAM_STR);
        $consulta->bindValue(':cliente', (int) $receptor['cliente_id'], PDO::PARAM_INT);
        $consulta->bindValue(':metodo', (int) $datos['metodo_pago_id'], PDO::PARAM_INT);
        $consulta->bindValue(':moneda', (int) $datos['moneda_id'], PDO::PARAM_INT);
        $consulta->bindValue(':forma', (string) ((int) $datos['forma_pago_id']), PDO::PARAM_STR);
        $consulta->bindValue(':status_pago', $comprobante['metodo_pago'] === 'PUE' ? 'Pagado' : 'No Pagado', PDO::PARAM_STR);
        $consulta->bindValue(':total_factura', (float) $comprobante['total']);
        $consulta->bindValue(':empresa', $this->empresa, PDO::PARAM_INT);
        $consulta->bindValue(':subtotal', (float) $comprobante['subtotal']);
        $consulta->bindValue(':total_iva', (float) $comprobante['total']);
        $consulta->bindValue(':token', $token, PDO::PARAM_STR);
        $consulta->bindValue(':uso_cfdi', $usoCfdiId, PDO::PARAM_INT);
        $consulta->bindValue(':perfil', (string) ((int) $receptor['perfil_id']), PDO::PARAM_STR);
        $consulta->bindValue(':folio', $folio, PDO::PARAM_INT);
        if (isset($_SESSION['usuario_id']) && (int) $_SESSION['usuario_id'] > 0) {
            $consulta->bindValue(':usuario', (int) $_SESSION['usuario_id'], PDO::PARAM_INT);
        } else {
            $consulta->bindValue(':usuario', null, PDO::PARAM_NULL);
        }
        $consulta->bindValue(':serie', $serie, PDO::PARAM_STR);
        $consulta->bindValue(':retencion_isr_tasa', (float) $comprobante['retencion_isr_tasa']);
        $consulta->bindValue(':retencion_iva_tasa', (float) $comprobante['retencion_iva_tasa']);
        $consulta->bindValue(':xml_total', (float) $comprobante['total']);
        $consulta->bindValue(':xml_subtotal', (float) $comprobante['subtotal']);
        $consulta->execute();

        $id = (int) $this->conexion->lastInsertId();
        if ($id <= 0) {
            throw new RuntimeException('No fue posible obtener el identificador de la factura guardada.');
        }
        return $id;
    }

    /** @param array<int, array<string, mixed>> $partidas */
    private function insertarConceptos(int $facturaId, array $partidas): void
    {
        $consulta = $this->conexion->prepare(
            "INSERT INTO detalle_factura
                (id_factura, id_producto, cantidad, precio_unitario,
                 precio_con_iva, precio_neto, precio_neto_iva, concepto, iva)
             VALUES
                (:factura, :producto, :cantidad, :precio_unitario,
                 :precio_con_iva, :precio_neto, :precio_neto_iva, :concepto, :iva)"
        );
        foreach ($partidas as $partida) {
            $cantidad = (float) $partida['cantidad'];
            $base = (float) $partida['importe'] - (float) $partida['descuento'];
            $consulta->bindValue(':factura', $facturaId, PDO::PARAM_INT);
            $consulta->bindValue(':producto', (int) $partida['concepto_id'], PDO::PARAM_INT);
            $consulta->bindValue(':cantidad', $cantidad);
            $consulta->bindValue(':precio_unitario', (float) $partida['valor_unitario']);
            $consulta->bindValue(':precio_con_iva', $cantidad > 0 ? (float) $partida['total'] / $cantidad : 0.0);
            $consulta->bindValue(':precio_neto', $base);
            $consulta->bindValue(':precio_neto_iva', (float) $partida['total']);
            $consulta->bindValue(':concepto', (string) $partida['descripcion'], PDO::PARAM_STR);
            $consulta->bindValue(':iva', (int) round((float) $partida['tasa_iva']), PDO::PARAM_INT);
            $consulta->execute();
        }
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
            throw new FacturaCreacionValidacionException(['El uso CFDI seleccionado no puede guardarse.']);
        }
        return $id;
    }

    /** @return array{0: string, 1: int} */
    private function generarSerieFolio(): array
    {
        $consulta = $this->conexion->prepare(
            'SELECT 1 FROM facturas WHERE razon = :empresa AND serie = :serie AND folio = :folio LIMIT 1'
        );
        for ($intento = 0; $intento < 10; $intento++) {
            $serie = chr(random_int(65, 90)) . chr(random_int(65, 90));
            $folio = random_int(100000, 900000);
            $consulta->execute([':empresa' => $this->empresa, ':serie' => $serie, ':folio' => $folio]);
            if (!$consulta->fetchColumn()) {
                return [$serie, $folio];
            }
        }
        throw new RuntimeException('No fue posible generar una serie y folio únicos.');
    }
}
