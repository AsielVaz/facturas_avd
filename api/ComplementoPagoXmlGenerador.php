<?php

declare(strict_types=1);

final class ComplementoPagoXmlException extends RuntimeException
{
}

/** Genera CFDI 4.0 con complemento Pagos 2.0 para pagos en MXN. */
final class ComplementoPagoXmlGenerador
{
    private const CFDI = 'http://www.sat.gob.mx/cfd/4';
    private const PAGOS = 'http://www.sat.gob.mx/Pagos20';
    private const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    /**
     * @param array<string,mixed> $datos
     * @param array<int,array<string,mixed>> $relaciones Cada relación incluye xml_origen.
     */
    public function generar(array $datos, array $relaciones): string
    {
        if ($relaciones === [] || count($relaciones) > 20) {
            throw new ComplementoPagoXmlException('El complemento debe relacionar entre una y veinte facturas.');
        }
        $this->validarCabecera($datos);
        $documento = new DOMDocument('1.0', 'UTF-8');
        $documento->formatOutput = true;
        $comprobante = $documento->createElementNS(self::CFDI, 'cfdi:Comprobante');
        $documento->appendChild($comprobante);
        $comprobante->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:pago20', self::PAGOS);
        $comprobante->setAttributeNS(self::XSI, 'xsi:schemaLocation',
            self::CFDI . ' http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd '
            . self::PAGOS . ' http://www.sat.gob.mx/sitio_internet/cfd/Pagos/Pagos20.xsd');
        $this->atributos($comprobante, [
            'Version' => '4.0', 'Serie' => (string) $datos['serie'], 'Folio' => (string) $datos['folio'],
            'Fecha' => (string) $datos['fecha_emision'], 'SubTotal' => '0', 'Moneda' => 'XXX',
            'Total' => '0', 'TipoDeComprobante' => 'P', 'Exportacion' => '01',
            'LugarExpedicion' => (string) $datos['emisor_cp'],
        ]);
        $emisor = $documento->createElementNS(self::CFDI, 'cfdi:Emisor');
        $comprobante->appendChild($emisor);
        $this->atributos($emisor, ['Rfc' => (string) $datos['emisor_rfc'],
            'Nombre' => (string) $datos['emisor_nombre'], 'RegimenFiscal' => (string) $datos['emisor_regimen']]);
        $receptor = $documento->createElementNS(self::CFDI, 'cfdi:Receptor');
        $comprobante->appendChild($receptor);
        $this->atributos($receptor, ['Rfc' => (string) $datos['receptor_rfc'],
            'Nombre' => (string) $datos['receptor_nombre'],
            'DomicilioFiscalReceptor' => (string) $datos['receptor_cp'],
            'RegimenFiscalReceptor' => (string) $datos['receptor_regimen'], 'UsoCFDI' => 'CP01']);
        $conceptos = $documento->createElementNS(self::CFDI, 'cfdi:Conceptos');
        $comprobante->appendChild($conceptos);
        $concepto = $documento->createElementNS(self::CFDI, 'cfdi:Concepto');
        $conceptos->appendChild($concepto);
        $this->atributos($concepto, ['ClaveProdServ' => '84111506', 'Cantidad' => '1',
            'ClaveUnidad' => 'ACT', 'Descripcion' => 'Pago', 'ValorUnitario' => '0',
            'Importe' => '0', 'ObjetoImp' => '01']);

        $complemento = $documento->createElementNS(self::CFDI, 'cfdi:Complemento');
        $comprobante->appendChild($complemento);
        $pagos = $documento->createElementNS(self::PAGOS, 'pago20:Pagos');
        $complemento->appendChild($pagos);
        $pagos->setAttribute('Version', '2.0');
        $totales = $documento->createElementNS(self::PAGOS, 'pago20:Totales');
        $pagos->appendChild($totales);
        $pago = $documento->createElementNS(self::PAGOS, 'pago20:Pago');
        $pagos->appendChild($pago);
        $this->atributos($pago, ['FechaPago' => (string) $datos['fecha_pago'],
            'FormaDePagoP' => (string) $datos['forma_pago'], 'MonedaP' => 'MXN',
            'Monto' => $this->dinero($this->centavos($datos['monto_total']))]);

        $suma = $baseIva16 = $iva16 = 0;
        $retencionesPago = ['001' => 0, '002' => 0];
        foreach ($relaciones as $relacion) {
            $monto = $this->centavos($relacion['monto'] ?? null);
            $saldo = $this->centavos($relacion['saldo'] ?? null);
            $restante = $this->centavos($relacion['restante'] ?? null);
            if ($monto <= 0 || $saldo <= 0 || $monto > $saldo || $saldo - $monto !== $restante
                || (int) ($relacion['num_pago'] ?? 0) < 1) {
                throw new ComplementoPagoXmlException('Los importes o la parcialidad de una factura relacionada no son válidos.');
            }
            $origen = $this->validarXmlOrigen((string) ($relacion['xml_origen'] ?? ''),
                (string) ($relacion['uuid_rel'] ?? ''), (string) $datos['emisor_rfc'],
                (string) $datos['receptor_rfc']);
            if ($saldo > $origen['total']) {
                throw new ComplementoPagoXmlException('El saldo anterior supera el total del CFDI relacionado.');
            }
            $docto = $documento->createElementNS(self::PAGOS, 'pago20:DoctoRelacionado');
            $pago->appendChild($docto);
            $this->atributos($docto, [
                'IdDocumento' => strtoupper(trim((string) $relacion['uuid_rel'])),
                'MonedaDR' => 'MXN', 'EquivalenciaDR' => '1',
                'NumParcialidad' => (string) $relacion['num_pago'],
                'ImpSaldoAnt' => $this->dinero($saldo), 'ImpPagado' => $this->dinero($monto),
                'ImpSaldoInsoluto' => $this->dinero($restante),
                'ObjetoImpDR' => $origen['tipo'] === 'iva16' ? '02' : '01',
            ]);
            if ($origen['tipo'] === 'iva16') {
                $base = (int) round($origen['base'] * $monto / $origen['total'], 0, PHP_ROUND_HALF_UP);
                $impuesto = (int) round($origen['iva'] * $monto / $origen['total'], 0, PHP_ROUND_HALF_UP);
                $retencionesDr = [];
                foreach ($origen['retenciones'] as $clave => $retencion) {
                    $importeRetenido = (int) round($retencion['importe'] * $monto / $origen['total'], 0, PHP_ROUND_HALF_UP);
                    $baseRetencion = (int) round($retencion['base'] * $monto / $origen['total'], 0, PHP_ROUND_HALF_UP);
                    $retencionesDr[$clave] = ['importe' => $importeRetenido, 'base' => $baseRetencion,
                        'tasa' => $retencion['tasa']];
                }
                // Los importes originales se prorratean; el ajuste de centavos queda en la base.
                $base += $monto - ($base + $impuesto - array_sum(array_column($retencionesDr, 'importe')));
                if ($base <= 0) throw new ComplementoPagoXmlException('El pago no permite desglosar la base gravada.');
                if (abs((int) round($base * 0.16, 0, PHP_ROUND_HALF_UP) - $impuesto) > 1) {
                    throw new ComplementoPagoXmlException('No se pudo desglosar el IVA 16% del importe pagado.');
                }
                $impuestosDr = $documento->createElementNS(self::PAGOS, 'pago20:ImpuestosDR');
                $docto->appendChild($impuestosDr);
                if ($retencionesDr !== []) {
                    $nodoRetenciones = $documento->createElementNS(self::PAGOS, 'pago20:RetencionesDR');
                    $impuestosDr->appendChild($nodoRetenciones);
                    foreach ($retencionesDr as $clave => $retencionDr) {
                        if ($retencionDr['base'] <= 0 || $retencionDr['importe'] < 0) {
                            throw new ComplementoPagoXmlException('No se pudo desglosar una retención del pago.');
                        }
                        $nodoRetencion = $documento->createElementNS(self::PAGOS, 'pago20:RetencionDR');
                        $nodoRetenciones->appendChild($nodoRetencion);
                        $this->atributos($nodoRetencion, ['BaseDR' => $this->dinero($retencionDr['base']),
                            'ImpuestoDR' => $clave, 'TipoFactorDR' => 'Tasa',
                            'TasaOCuotaDR' => $retencionDr['tasa'],
                            'ImporteDR' => $this->dinero($retencionDr['importe'])]);
                        $retencionesPago[$clave] += $retencionDr['importe'];
                    }
                }
                $trasladosDr = $documento->createElementNS(self::PAGOS, 'pago20:TrasladosDR');
                $impuestosDr->appendChild($trasladosDr);
                $trasladoDr = $documento->createElementNS(self::PAGOS, 'pago20:TrasladoDR');
                $trasladosDr->appendChild($trasladoDr);
                $this->atributos($trasladoDr, ['BaseDR' => $this->dinero($base), 'ImpuestoDR' => '002',
                    'TipoFactorDR' => 'Tasa', 'TasaOCuotaDR' => '0.160000',
                    'ImporteDR' => $this->dinero($impuesto)]);
                $baseIva16 += $base;
                $iva16 += $impuesto;
            }
            $suma += $monto;
        }
        if ($suma !== $this->centavos($datos['monto_total'])) {
            throw new ComplementoPagoXmlException('La suma de los documentos no coincide con el monto del pago.');
        }
        $totales->setAttribute('MontoTotalPagos', $this->dinero($suma));
        if ($baseIva16 > 0) {
            $totales->setAttribute('TotalTrasladosBaseIVA16', $this->dinero($baseIva16));
            $totales->setAttribute('TotalTrasladosImpuestoIVA16', $this->dinero($iva16));
            if ($retencionesPago['001'] > 0) $totales->setAttribute('TotalRetencionesISR', $this->dinero($retencionesPago['001']));
            if ($retencionesPago['002'] > 0) $totales->setAttribute('TotalRetencionesIVA', $this->dinero($retencionesPago['002']));
            $impuestosP = $documento->createElementNS(self::PAGOS, 'pago20:ImpuestosP');
            $pago->appendChild($impuestosP);
            if (array_sum($retencionesPago) > 0) {
                $retencionesP = $documento->createElementNS(self::PAGOS, 'pago20:RetencionesP');
                $impuestosP->appendChild($retencionesP);
                foreach ($retencionesPago as $clave => $importe) {
                    if ($importe <= 0) continue;
                    $retencionP = $documento->createElementNS(self::PAGOS, 'pago20:RetencionP');
                    $retencionesP->appendChild($retencionP);
                    $this->atributos($retencionP, ['ImpuestoP' => $clave, 'ImporteP' => $this->dinero($importe)]);
                }
            }
            $trasladosP = $documento->createElementNS(self::PAGOS, 'pago20:TrasladosP');
            $impuestosP->appendChild($trasladosP);
            $trasladoP = $documento->createElementNS(self::PAGOS, 'pago20:TrasladoP');
            $trasladosP->appendChild($trasladoP);
            $this->atributos($trasladoP, ['BaseP' => $this->dinero($baseIva16), 'ImpuestoP' => '002',
                'TipoFactorP' => 'Tasa', 'TasaOCuotaP' => '0.160000',
                'ImporteP' => $this->dinero($iva16)]);
        }
        return $documento->saveXML() ?: throw new ComplementoPagoXmlException('No fue posible generar el XML del complemento.');
    }

    /** @return array{total:int,tipo:string,base?:int,iva?:int,retenciones?:array<string,array{base:int,importe:int,tasa:string}>} */
    private function validarXmlOrigen(string $xml, string $uuid, string $emisorRfc, string $receptorRfc): array
    {
        if ($xml === '' || strlen($xml) > 2097152) {
            throw new ComplementoPagoXmlException('No se encontró el XML timbrado de una factura relacionada.');
        }
        $previo = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        try {
            $valido = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previo);
        }
        $raiz = $dom->documentElement;
        if (!$valido || !$raiz instanceof DOMElement || $raiz->localName !== 'Comprobante'
            || !in_array($raiz->getAttribute('Version'), ['3.3', '4.0'], true)) {
            throw new ComplementoPagoXmlException('El XML de la factura relacionada no es un CFDI válido.');
        }
        $xpath = new DOMXPath($dom);
        $valor = static fn(string $ruta): string => trim((string) $xpath->evaluate('string(' . $ruta . ')'));
        if (strtoupper($valor('//*[local-name()="TimbreFiscalDigital"]/@UUID')) !== strtoupper(trim($uuid))
            || strtoupper($valor('/*[local-name()="Comprobante"]/*[local-name()="Emisor"]/@Rfc')) !== strtoupper($emisorRfc)
            || strtoupper($valor('/*[local-name()="Comprobante"]/*[local-name()="Receptor"]/@Rfc')) !== strtoupper($receptorRfc)
            || strtoupper($raiz->getAttribute('MetodoPago')) !== 'PPD'
            || strtoupper($raiz->getAttribute('Moneda')) !== 'MXN') {
            throw new ComplementoPagoXmlException('El UUID, RFC, método PPD o moneda de una factura no coincide con el complemento.');
        }
        $total = $this->centavos($raiz->getAttribute('Total'));
        if ($total <= 0) throw new ComplementoPagoXmlException('Una factura relacionada no tiene total válido.');
        $retenciones = $xpath->query('/*[local-name()="Comprobante"]/*[local-name()="Impuestos"]/*[local-name()="Retenciones"]/*');
        $traslados = $xpath->query('/*[local-name()="Comprobante"]/*[local-name()="Impuestos"]/*[local-name()="Traslados"]/*');
        if ($retenciones === false || $traslados === false) {
            throw new ComplementoPagoXmlException('No se pudieron consultar los impuestos de la factura.');
        }
        if ($traslados->length === 0) {
            if ($retenciones->length > 0) {
                throw new ComplementoPagoXmlException('La factura tiene retenciones sin traslado; requiere revisión fiscal manual.');
            }
            if ($raiz->getAttribute('Version') !== '4.0') {
                throw new ComplementoPagoXmlException('El CFDI 3.3 sin desglose de impuestos requiere revisión fiscal manual.');
            }
            $objetos = $xpath->query('/*[local-name()="Comprobante"]/*[local-name()="Conceptos"]/*/@ObjetoImp');
            if ($objetos === false || $objetos->length === 0) {
                throw new ComplementoPagoXmlException('No se pudo determinar el objeto de impuesto de la factura.');
            }
            foreach ($objetos as $objeto) {
                if ($objeto->nodeValue !== '01') {
                    throw new ComplementoPagoXmlException('La factura tiene impuestos exentos o mixtos que requieren revisión fiscal manual.');
                }
            }
            return ['total' => $total, 'tipo' => 'no_objeto'];
        }
        if ($traslados->length !== 1 || !$traslados->item(0) instanceof DOMElement || $retenciones->length > 2) {
            throw new ComplementoPagoXmlException('La factura usa varias tasas de impuesto; no se timbrará automáticamente.');
        }
        $traslado = $traslados->item(0);
        if ($traslado->getAttribute('Impuesto') !== '002' || $traslado->getAttribute('TipoFactor') !== 'Tasa'
            || abs((float) $traslado->getAttribute('TasaOCuota') - 0.16) > 0.000001) {
            throw new ComplementoPagoXmlException('Solo se admite IVA trasladado al 16% en el timbrado automático.');
        }
        $base = $raiz->getAttribute('Version') === '3.3'
            ? $this->centavos($raiz->getAttribute('SubTotal'))
            : $this->centavos($traslado->getAttribute('Base'));
        $iva = $this->centavos($traslado->getAttribute('Importe'));
        $retencionesDetalle = [];
        foreach ($retenciones as $retencion) {
            if (!$retencion instanceof DOMElement || !in_array($retencion->getAttribute('Impuesto'), ['001', '002'], true)) {
                throw new ComplementoPagoXmlException('La factura tiene un impuesto retenido no compatible.');
            }
            $clave = $retencion->getAttribute('Impuesto');
            if (isset($retencionesDetalle[$clave])) throw new ComplementoPagoXmlException('La factura tiene retenciones duplicadas.');
            $retencionesDetalle[$clave] = ['importe' => $this->centavos($retencion->getAttribute('Importe')),
                'base' => 0, 'tasa' => ''];
        }
        if ($retencionesDetalle !== []) {
            $conceptosRetenidos = $xpath->query('/*[local-name()="Comprobante"]/*[local-name()="Conceptos"]/*/*[local-name()="Impuestos"]/*[local-name()="Retenciones"]/*');
            if ($conceptosRetenidos === false) throw new ComplementoPagoXmlException('No se pudieron leer las retenciones de los conceptos.');
            foreach ($conceptosRetenidos as $retencion) {
                if (!$retencion instanceof DOMElement) continue;
                $clave = $retencion->getAttribute('Impuesto');
                if (!isset($retencionesDetalle[$clave]) || $retencion->getAttribute('TipoFactor') !== 'Tasa'
                    || !preg_match('/^0\.\d{6}$/D', $retencion->getAttribute('TasaOCuota'))) {
                    throw new ComplementoPagoXmlException('La retención de un concepto requiere revisión fiscal manual.');
                }
                $tasa = $retencion->getAttribute('TasaOCuota');
                if ($retencionesDetalle[$clave]['tasa'] !== '' && $retencionesDetalle[$clave]['tasa'] !== $tasa) {
                    throw new ComplementoPagoXmlException('La factura aplica varias tasas del mismo impuesto retenido.');
                }
                $retencionesDetalle[$clave]['tasa'] = $tasa;
                $retencionesDetalle[$clave]['base'] += $this->centavos($retencion->getAttribute('Base'));
            }
            foreach ($retencionesDetalle as $detalle) {
                if ($detalle['base'] <= 0 || $detalle['tasa'] === '') {
                    throw new ComplementoPagoXmlException('No se pudo desglosar una retención de la factura.');
                }
            }
        }
        $totalCalculado = $base + $iva - array_sum(array_column($retencionesDetalle, 'importe'));
        if ($base <= 0 || abs($totalCalculado - $total) > 1) {
            throw new ComplementoPagoXmlException('La factura combina importes gravados y no gravados; requiere revisión fiscal manual.');
        }
        return ['total' => $total, 'tipo' => 'iva16', 'base' => $base,
            'iva' => $iva, 'retenciones' => $retencionesDetalle];
    }

    /** @param array<string,mixed> $datos */
    private function validarCabecera(array $datos): void
    {
        foreach (['emisor_rfc', 'emisor_nombre', 'emisor_cp', 'emisor_regimen',
            'receptor_rfc', 'receptor_nombre', 'receptor_cp', 'receptor_regimen',
            'forma_pago', 'fecha_pago', 'fecha_emision', 'folio'] as $campo) {
            if (trim((string) ($datos[$campo] ?? '')) === '') {
                throw new ComplementoPagoXmlException('Falta el dato fiscal ' . $campo . ' del complemento.');
            }
        }
        if (!preg_match('/^\d{5}$/D', (string) $datos['emisor_cp'])
            || !preg_match('/^\d{5}$/D', (string) $datos['receptor_cp'])
            || !preg_match('/^\d{3}$/D', (string) $datos['emisor_regimen'])
            || !preg_match('/^\d{3}$/D', (string) $datos['receptor_regimen'])
            || !preg_match('/^\d{2}$/D', (string) $datos['forma_pago'])
            || (string) $datos['forma_pago'] === '99'
            || !preg_match('/^\d+$/D', (string) $datos['folio'])
            || !preg_match('/^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$/Du', (string) $datos['emisor_rfc'])
            || !preg_match('/^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$/Du', (string) $datos['receptor_rfc'])) {
            throw new ComplementoPagoXmlException('El código postal, régimen, forma de pago o folio no es válido.');
        }
        $emision = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', (string) $datos['fecha_emision']);
        $pago = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', (string) $datos['fecha_pago']);
        if (!$emision || !$pago || $emision->format('Y-m-d\TH:i:s') !== $datos['fecha_emision']
            || $pago->format('Y-m-d\TH:i:s') !== $datos['fecha_pago']
            || $pago > $emision || $this->centavos($datos['monto_total'] ?? null) <= 0) {
            throw new ComplementoPagoXmlException('La fecha o el monto del pago no es válido.');
        }
    }

    private function centavos(mixed $valor): int
    {
        $texto = trim((string) $valor);
        if (!preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $texto)) {
            throw new ComplementoPagoXmlException('Un importe fiscal no tiene formato válido.');
        }
        [$entero, $decimal] = array_pad(explode('.', $texto, 2), 2, '');
        return (int) $entero * 100 + (int) round((int) str_pad($decimal, 6, '0') / 10000, 0, PHP_ROUND_HALF_UP);
    }

    private function dinero(mixed $centavos): string
    {
        return number_format((int) $centavos / 100, 2, '.', '');
    }

    /** @param array<string,string> $atributos */
    private function atributos(DOMElement $elemento, array $atributos): void
    {
        foreach ($atributos as $nombre => $valor) $elemento->setAttribute($nombre, $valor);
    }
}
