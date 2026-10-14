<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/ComplementoPagoXmlGenerador.php';

$uuid = '12345678-1234-1234-1234-123456789ABC';
$origen = '<?xml version="1.0"?><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="4.0" Moneda="MXN" MetodoPago="PPD" Total="0.95"><cfdi:Emisor Rfc="AAA010101AAA"/><cfdi:Receptor Rfc="BBB010101BBB"/><cfdi:Conceptos><cfdi:Concepto ObjetoImp="02"><cfdi:Impuestos><cfdi:Retenciones><cfdi:Retencion Base="1.000000" Importe="0.100000" Impuesto="001" TipoFactor="Tasa" TasaOCuota="0.100000"/><cfdi:Retencion Base="1.000000" Importe="0.106667" Impuesto="002" TipoFactor="Tasa" TasaOCuota="0.106667"/></cfdi:Retenciones></cfdi:Impuestos></cfdi:Concepto></cfdi:Conceptos><cfdi:Impuestos><cfdi:Retenciones><cfdi:Retencion Importe="0.10" Impuesto="001"/><cfdi:Retencion Importe="0.11" Impuesto="002"/></cfdi:Retenciones><cfdi:Traslados><cfdi:Traslado Base="1.00" Importe="0.16" Impuesto="002" TipoFactor="Tasa" TasaOCuota="0.160000"/></cfdi:Traslados></cfdi:Impuestos><cfdi:Complemento><tfd:TimbreFiscalDigital UUID="' . $uuid . '"/></cfdi:Complemento></cfdi:Comprobante>';
$datos = [
    'serie' => 'P', 'folio' => '99', 'fecha_emision' => '2026-10-14T12:00:00',
    'fecha_pago' => '2026-10-14T11:00:00', 'monto_total' => '0.95', 'forma_pago' => '03',
    'emisor_rfc' => 'AAA010101AAA', 'emisor_nombre' => 'EMISOR DE PRUEBA',
    'emisor_cp' => '01234', 'emisor_regimen' => '601',
    'receptor_rfc' => 'BBB010101BBB', 'receptor_nombre' => 'RECEPTOR DE PRUEBA',
    'receptor_cp' => '01234', 'receptor_regimen' => '601',
];
$relaciones = [['monto' => '0.95', 'saldo' => '0.95', 'restante' => '0', 'num_pago' => 1,
    'uuid_rel' => $uuid, 'xml_origen' => $origen]];
$xml = (new ComplementoPagoXmlGenerador())->generar($datos, $relaciones);
$dom = new DOMDocument();
if (!$dom->loadXML($xml)) throw new RuntimeException('XML de pago inválido.');
$xpath = new DOMXPath($dom);
$xpath->registerNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');
$xpath->registerNamespace('pago20', 'http://www.sat.gob.mx/Pagos20');
$exigir = static function (bool $condicion, string $mensaje): void {
    if (!$condicion) throw new RuntimeException($mensaje);
};
$exigir($dom->documentElement->getAttribute('Version') === '4.0', 'No es CFDI 4.0.');
$exigir($xpath->query('//pago20:Pagos[@Version="2.0"]')->length === 1, 'No es Pagos 2.0.');
$exigir($xpath->evaluate('string(//pago20:Totales/@MontoTotalPagos)') === '0.95', 'Monto incorrecto.');
$exigir($xpath->evaluate('string(//pago20:Totales/@TotalRetencionesISR)') === '0.10', 'ISR incorrecto.');
$exigir($xpath->evaluate('string(//pago20:Totales/@TotalRetencionesIVA)') === '0.11', 'IVA retenido incorrecto.');
$exigir($xpath->evaluate('string(//pago20:Totales/@TotalTrasladosImpuestoIVA16)') === '0.16', 'IVA trasladado incorrecto.');
$exigir($xpath->query('//pago20:DoctoRelacionado/pago20:ImpuestosDR/pago20:RetencionesDR/pago20:RetencionDR')->length === 2,
    'Faltan retenciones relacionadas.');
$exigir($xpath->query('//pago20:Pago/pago20:ImpuestosP/pago20:RetencionesP/pago20:RetencionP')->length === 2,
    'Faltan retenciones agregadas.');
$exigir($xpath->query('/cfdi:Comprobante/@MetodoPago')->length === 0, 'Un pago no debe tener MetodoPago.');
$exigir($xpath->query('/cfdi:Comprobante/@FormaPago')->length === 0, 'Un pago no debe tener FormaPago.');

$origenSinImpuesto = '<?xml version="1.0"?><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="4.0" Moneda="MXN" MetodoPago="PPD" Total="50.00"><cfdi:Emisor Rfc="AAA010101AAA"/><cfdi:Receptor Rfc="BBB010101BBB"/><cfdi:Conceptos><cfdi:Concepto ObjetoImp="01"/></cfdi:Conceptos><cfdi:Complemento><tfd:TimbreFiscalDigital UUID="' . $uuid . '"/></cfdi:Complemento></cfdi:Comprobante>';
$sinImpuesto = $relaciones;
$sinImpuesto[0]['xml_origen'] = $origenSinImpuesto;
$sinImpuesto[0]['monto'] = '25.00';
$sinImpuesto[0]['saldo'] = '50.00';
$sinImpuesto[0]['restante'] = '25.00';
$datosSinImpuesto = $datos;
$datosSinImpuesto['monto_total'] = '25.00';
$xmlSinImpuesto = (new ComplementoPagoXmlGenerador())->generar($datosSinImpuesto, $sinImpuesto);
$domSinImpuesto = new DOMDocument();
$exigir($domSinImpuesto->loadXML($xmlSinImpuesto), 'El XML sin impuesto no se puede leer.');
$xpSinImpuesto = new DOMXPath($domSinImpuesto);
$xpSinImpuesto->registerNamespace('pago20', 'http://www.sat.gob.mx/Pagos20');
$exigir($xpSinImpuesto->evaluate('string(//pago20:DoctoRelacionado/@ObjetoImpDR)') === '01', 'ObjetoImpDR sin impuesto incorrecto.');
$exigir($xpSinImpuesto->query('//pago20:ImpuestosDR|//pago20:ImpuestosP')->length === 0, 'Se emitieron impuestos inexistentes.');

$relaciones[0]['uuid_rel'] = '00000000-0000-0000-0000-000000000000';
try {
    (new ComplementoPagoXmlGenerador())->generar($datos, $relaciones);
    throw new RuntimeException('Se aceptó un UUID de origen incorrecto.');
} catch (ComplementoPagoXmlException) {
    // Debe detener el timbrado antes de contactar al PAC.
}
echo "ComplementoPagoXmlGeneradorTest OK\n";
