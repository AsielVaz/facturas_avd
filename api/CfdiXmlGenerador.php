<?php

declare(strict_types=1);

final class CfdiXmlGeneracionException extends RuntimeException
{
}

final class CfdiXmlGenerador
{
    private const CFDI_NAMESPACE = 'http://www.sat.gob.mx/cfd/4';
    private const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';
    private const SCHEMA_LOCATION = 'http://www.sat.gob.mx/cfd/4 http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd';

    public function __construct(private readonly string $directorio)
    {
    }

    /**
     * Genera el pre-CFDI 4.0 sin sello ni timbre fiscal.
     *
     * @param array<string, mixed> $factura
     * @return array{archivo: string, ruta: string, url: string, contenido: string}
     */
    public function facturar4Plus(int $facturaId, array $factura): array
    {
        if ($facturaId <= 0) {
            throw new InvalidArgumentException('El identificador de la factura no es válido.');
        }
        if (!class_exists(DOMDocument::class)) {
            throw new CfdiXmlGeneracionException('La extensión DOM de PHP es necesaria para generar el XML.');
        }

        $xml = $this->construir($factura);
        $this->prepararDirectorio();
        $archivo = 'XML-factura-' . $facturaId . '.xml';
        $ruta = rtrim($this->directorio, '/\\') . DIRECTORY_SEPARATOR . $archivo;
        $temporal = $ruta . '.tmp-' . bin2hex(random_bytes(6));

        try {
            if (file_put_contents($temporal, $xml, LOCK_EX) === false) {
                throw new CfdiXmlGeneracionException('No fue posible escribir el archivo XML.');
            }
            if (!rename($temporal, $ruta)) {
                throw new CfdiXmlGeneracionException('No fue posible finalizar el archivo XML.');
            }
        } finally {
            if (is_file($temporal)) {
                @unlink($temporal);
            }
        }

        return [
            'archivo' => $archivo,
            'ruta' => $ruta,
            'url' => 'api/facturas.php?accion=descargar_xml&id=' . $facturaId,
            'contenido' => $xml,
        ];
    }

    public function eliminar(int $facturaId): void
    {
        $ruta = rtrim($this->directorio, '/\\') . DIRECTORY_SEPARATOR . 'XML-factura-' . $facturaId . '.xml';
        if (is_file($ruta)) {
            @unlink($ruta);
        }
    }

    /** @param array<string, mixed> $factura */
    private function construir(array $factura): string
    {
        $comprobante = $factura['comprobante'] ?? null;
        $emisor = $factura['emisor'] ?? null;
        $receptor = $factura['receptor'] ?? null;
        $partidas = $factura['partidas'] ?? null;
        if (!is_array($comprobante) || !is_array($emisor) || !is_array($receptor) || !is_array($partidas) || $partidas === []) {
            throw new CfdiXmlGeneracionException('Los datos validados de la factura están incompletos.');
        }

        $documento = new DOMDocument('1.0', 'UTF-8');
        $documento->formatOutput = true;
        $raiz = $documento->createElementNS(self::CFDI_NAMESPACE, 'cfdi:Comprobante');
        $documento->appendChild($raiz);
        $raiz->setAttributeNS(self::XSI_NAMESPACE, 'xsi:schemaLocation', self::SCHEMA_LOCATION);

        $atributos = [
            'Version' => '4.0',
            'Serie' => (string) ($factura['serie'] ?? ''),
            'Folio' => (string) ($factura['folio'] ?? ''),
            'Fecha' => (string) $comprobante['fecha'],
            'FormaPago' => (string) $comprobante['forma_pago'],
            'SubTotal' => $this->dinero((float) $comprobante['subtotal']),
            'Moneda' => (string) $comprobante['moneda'],
            'Total' => $this->dinero((float) $comprobante['total']),
            'TipoDeComprobante' => (string) $comprobante['tipo_comprobante'],
            'Exportacion' => (string) $comprobante['exportacion'],
            'MetodoPago' => (string) $comprobante['metodo_pago'],
            'LugarExpedicion' => (string) $comprobante['lugar_expedicion'],
        ];
        if ((float) $comprobante['descuento'] > 0) {
            $atributos['Descuento'] = $this->dinero((float) $comprobante['descuento']);
        }
        if ((string) $comprobante['moneda'] !== 'MXN') {
            $atributos['TipoCambio'] = $this->decimal((float) $comprobante['tipo_cambio'], 6);
        }
        $this->atributos($raiz, $atributos);

        $nodoEmisor = $documento->createElementNS(self::CFDI_NAMESPACE, 'cfdi:Emisor');
        $raiz->appendChild($nodoEmisor);
        $this->atributos($nodoEmisor, [
            'Rfc' => (string) $emisor['rfc'],
            'Nombre' => (string) $emisor['nombre'],
            'RegimenFiscal' => (string) $emisor['regimen_fiscal'],
        ]);

        $nodoReceptor = $documento->createElementNS(self::CFDI_NAMESPACE, 'cfdi:Receptor');
        $raiz->appendChild($nodoReceptor);
        $this->atributos($nodoReceptor, [
            'Rfc' => (string) $receptor['rfc'],
            'Nombre' => (string) $receptor['nombre'],
            'DomicilioFiscalReceptor' => (string) $receptor['domicilio_fiscal'],
            'RegimenFiscalReceptor' => (string) $receptor['regimen_fiscal'],
            'UsoCFDI' => (string) $receptor['uso_cfdi'],
        ]);

        $nodoConceptos = $documento->createElementNS(self::CFDI_NAMESPACE, 'cfdi:Conceptos');
        $raiz->appendChild($nodoConceptos);
        $trasladosAgrupados = [];
        $retencionesAgrupadas = [];

        foreach ($partidas as $partida) {
            if (!is_array($partida)) {
                continue;
            }
            $nodoConcepto = $documento->createElementNS(self::CFDI_NAMESPACE, 'cfdi:Concepto');
            $nodoConceptos->appendChild($nodoConcepto);
            $atributosConcepto = [
                'ClaveProdServ' => (string) $partida['clave_prod_serv'],
                'Cantidad' => $this->decimal((float) $partida['cantidad'], 6),
                'ClaveUnidad' => (string) $partida['clave_unidad'],
                'Descripcion' => (string) $partida['descripcion'],
                'ValorUnitario' => $this->decimal((float) $partida['valor_unitario'], 6),
                'Importe' => number_format((float) $partida['importe'], 6, '.', ''),
                'ObjetoImp' => (string) $partida['objeto_impuesto'],
            ];
            if (trim((string) ($partida['unidad'] ?? '')) !== '') {
                $atributosConcepto['Unidad'] = (string) $partida['unidad'];
            }
            if (trim((string) ($partida['no_identificacion'] ?? '')) !== '') {
                $atributosConcepto['NoIdentificacion'] = (string) $partida['no_identificacion'];
            }
            if ((float) $partida['descuento'] > 0) {
                $atributosConcepto['Descuento'] = $this->dinero((float) $partida['descuento']);
            }
            $this->atributos($nodoConcepto, $atributosConcepto);

            if ((string) $partida['objeto_impuesto'] !== '02') {
                continue;
            }

            $base = round((float) $partida['importe'] - (float) $partida['descuento'], 2);
            $importe = round((float) $partida['iva'], 2);
            $tasa = (float) $partida['tasa_iva'];
            $tasaCuota = number_format($tasa / 100, 6, '.', '');
            $nodoImpuestos = $documento->createElementNS(self::CFDI_NAMESPACE, 'cfdi:Impuestos');
            $nodoConcepto->appendChild($nodoImpuestos);
            $nodoTraslados = $documento->createElementNS(self::CFDI_NAMESPACE, 'cfdi:Traslados');
            $nodoImpuestos->appendChild($nodoTraslados);
            $nodoTraslado = $documento->createElementNS(self::CFDI_NAMESPACE, 'cfdi:Traslado');
            $nodoTraslados->appendChild($nodoTraslado);
            $this->atributos($nodoTraslado, [
                'Base' => number_format($base, 6, '.', ''),
                'Impuesto' => '002',
                'TipoFactor' => 'Tasa',
                'TasaOCuota' => $tasaCuota,
                'Importe' => number_format($importe, 6, '.', ''),
            ]);

            $trasladosAgrupados[$tasaCuota] ??= ['base' => 0.0, 'importe' => 0.0];
            $trasladosAgrupados[$tasaCuota]['base'] += $base;
            $trasladosAgrupados[$tasaCuota]['importe'] += $importe;

            $retencionesPartida = [];
            if ((float) ($partida['retencion_isr'] ?? 0) > 0) {
                $retencionesPartida[] = [
                    'impuesto' => '001',
                    'tasa' => (float) $partida['retencion_isr_tasa'],
                    'importe' => (float) $partida['retencion_isr'],
                ];
            }
            if ((float) ($partida['retencion_iva'] ?? 0) > 0) {
                $retencionesPartida[] = [
                    'impuesto' => '002',
                    'tasa' => (float) $partida['retencion_iva_tasa'],
                    'importe' => (float) $partida['retencion_iva'],
                ];
            }
            if ($retencionesPartida !== []) {
                $nodoRetenciones = $documento->createElementNS(self::CFDI_NAMESPACE, 'cfdi:Retenciones');
                $nodoImpuestos->appendChild($nodoRetenciones);
                foreach ($retencionesPartida as $retencion) {
                    $tasaRetencion = number_format($retencion['tasa'] / 100, 6, '.', '');
                    $nodoRetencion = $documento->createElementNS(self::CFDI_NAMESPACE, 'cfdi:Retencion');
                    $nodoRetenciones->appendChild($nodoRetencion);
                    $this->atributos($nodoRetencion, [
                        'Base' => number_format($base, 6, '.', ''),
                        'Impuesto' => $retencion['impuesto'],
                        'TipoFactor' => 'Tasa',
                        'TasaOCuota' => $tasaRetencion,
                        'Importe' => number_format($retencion['importe'], 6, '.', ''),
                    ]);
                    $retencionesAgrupadas[$retencion['impuesto']] ??= 0.0;
                    $retencionesAgrupadas[$retencion['impuesto']] += $retencion['importe'];
                }
            }
        }

        if ($trasladosAgrupados !== [] || $retencionesAgrupadas !== []) {
            ksort($trasladosAgrupados, SORT_STRING);
            $nodoImpuestos = $documento->createElementNS(self::CFDI_NAMESPACE, 'cfdi:Impuestos');
            $raiz->appendChild($nodoImpuestos);
            if ($retencionesAgrupadas !== []) {
                ksort($retencionesAgrupadas, SORT_STRING);
                $totalRetenido = array_sum($retencionesAgrupadas);
                $nodoImpuestos->setAttribute('TotalImpuestosRetenidos', $this->dinero($totalRetenido));
                $nodoRetenciones = $documento->createElementNS(self::CFDI_NAMESPACE, 'cfdi:Retenciones');
                $nodoImpuestos->appendChild($nodoRetenciones);
                foreach ($retencionesAgrupadas as $impuesto => $importeRetenido) {
                    $nodoRetencion = $documento->createElementNS(self::CFDI_NAMESPACE, 'cfdi:Retencion');
                    $nodoRetenciones->appendChild($nodoRetencion);
                    $this->atributos($nodoRetencion, [
                        'Impuesto' => (string) $impuesto,
                        'Importe' => $this->dinero((float) $importeRetenido),
                    ]);
                }
            }
            if ($trasladosAgrupados !== []) {
                $nodoImpuestos->setAttribute('TotalImpuestosTrasladados', $this->dinero((float) $comprobante['iva']));
                $nodoTraslados = $documento->createElementNS(self::CFDI_NAMESPACE, 'cfdi:Traslados');
                $nodoImpuestos->appendChild($nodoTraslados);
                foreach ($trasladosAgrupados as $tasaCuota => $totales) {
                    $nodoTraslado = $documento->createElementNS(self::CFDI_NAMESPACE, 'cfdi:Traslado');
                    $nodoTraslados->appendChild($nodoTraslado);
                    $this->atributos($nodoTraslado, [
                        'Base' => $this->dinero((float) $totales['base']),
                        'Impuesto' => '002',
                        'TipoFactor' => 'Tasa',
                        'TasaOCuota' => $tasaCuota,
                        'Importe' => $this->dinero((float) $totales['importe']),
                    ]);
                }
            }
        }

        $xml = $documento->saveXML();
        if (!is_string($xml) || $xml === '') {
            throw new CfdiXmlGeneracionException('No fue posible serializar el XML de la factura.');
        }
        return $xml;
    }

    private function prepararDirectorio(): void
    {
        if (!is_dir($this->directorio) && !mkdir($this->directorio, 0775, true) && !is_dir($this->directorio)) {
            throw new CfdiXmlGeneracionException('No fue posible crear el directorio para los XML.');
        }
        if (!is_writable($this->directorio)) {
            throw new CfdiXmlGeneracionException('El directorio de XML no tiene permisos de escritura.');
        }
    }

    /** @param array<string, string> $atributos */
    private function atributos(DOMElement $elemento, array $atributos): void
    {
        foreach ($atributos as $nombre => $valor) {
            $elemento->setAttribute($nombre, $valor);
        }
    }

    private function dinero(float $valor): string
    {
        return number_format(round($valor, 2), 2, '.', '');
    }

    private function decimal(float $valor, int $decimales): string
    {
        $formateado = number_format($valor, $decimales, '.', '');
        $formateado = rtrim(rtrim($formateado, '0'), '.');
        return $formateado === '' ? '0' : $formateado;
    }
}
