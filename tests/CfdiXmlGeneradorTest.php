<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/CfdiXmlGenerador.php';

$directorio = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'facturas-avd-cfdi-' . bin2hex(random_bytes(5));
$generador = new CfdiXmlGenerador($directorio);
$comprobar = static function (bool $condicion, string $mensaje): void {
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
};
$resultado = $generador->facturar4Plus(1, [
    'serie' => 'AA',
    'folio' => 100001,
    'comprobante' => [
        'fecha' => '2026-09-03T12:00:00',
        'forma_pago' => '99',
        'subtotal' => 55000.00,
        'descuento' => 0.00,
        'moneda' => 'MXN',
        'tipo_cambio' => 1,
        'total' => 52433.31,
        'tipo_comprobante' => 'I',
        'exportacion' => '01',
        'metodo_pago' => 'PPD',
        'lugar_expedicion' => '45640',
        'iva' => 8800.00,
    ],
    'emisor' => [
        'rfc' => 'XAXX010101000',
        'nombre' => 'EMISOR DE PRUEBA',
        'regimen_fiscal' => '612',
    ],
    'receptor' => [
        'rfc' => 'XEXX010101000',
        'nombre' => 'RECEPTOR DE PRUEBA',
        'domicilio_fiscal' => '45640',
        'regimen_fiscal' => '603',
        'uso_cfdi' => 'G03',
    ],
    'partidas' => [[
        'clave_prod_serv' => '80121500',
        'cantidad' => 1,
        'clave_unidad' => 'E48',
        'unidad' => 'Unidad de servicio',
        'descripcion' => 'SERVICIO DE PRUEBA',
        'valor_unitario' => 55000.00,
        'importe' => 55000.00,
        'descuento' => 0.00,
        'objeto_impuesto' => '02',
        'tasa_iva' => 16.00,
        'iva' => 8800.00,
        'retencion_isr_tasa' => 10.00,
        'retencion_isr' => 5500.00,
        'retencion_iva_tasa' => 10.6667,
        'retencion_iva' => 5866.685,
    ]],
]);

$documento = new DOMDocument();
$comprobar($documento->loadXML($resultado['contenido']), 'El XML generado no se puede leer.');
$xpath = new DOMXPath($documento);
$xpath->registerNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');
$raiz = $documento->documentElement;
$comprobar($raiz instanceof DOMElement, 'No se generó el nodo Comprobante.');
$comprobar($raiz->getAttribute('Version') === '4.0', 'La versión CFDI es incorrecta.');
$comprobar($raiz->getAttribute('Total') === '52433.31', 'El total con retenciones es incorrecto.');
$comprobar($raiz->getAttribute('MetodoPago') === 'PPD', 'El método de pago es incorrecto.');
$comprobar($xpath->query('/cfdi:Comprobante/cfdi:Conceptos/cfdi:Concepto/cfdi:Impuestos/cfdi:Retenciones/cfdi:Retencion')->length === 2, 'Faltan retenciones por concepto.');
$comprobar($xpath->query('/cfdi:Comprobante/cfdi:Impuestos/cfdi:Retenciones/cfdi:Retencion')->length === 2, 'Faltan retenciones globales.');
$comprobar($xpath->evaluate('string(/cfdi:Comprobante/cfdi:Conceptos/cfdi:Concepto/cfdi:Impuestos/cfdi:Retenciones/cfdi:Retencion[@Impuesto="002"]/@TasaOCuota)') === '0.106667', 'La tasa retenida de IVA es incorrecta.');
$comprobar($xpath->evaluate('string(/cfdi:Comprobante/cfdi:Conceptos/cfdi:Concepto/cfdi:Impuestos/cfdi:Retenciones/cfdi:Retencion[@Impuesto="002"]/@Importe)') === '5866.685000', 'La precisión de la retención de IVA es incorrecta.');
$comprobar($xpath->evaluate('string(/cfdi:Comprobante/cfdi:Impuestos/@TotalImpuestosRetenidos)') === '11366.69', 'El total de retenciones es incorrecto.');
$comprobar(is_file($resultado['ruta']), 'El archivo XML no fue escrito.');

$generador->eliminar(1);
@rmdir($directorio);
echo "CfdiXmlGeneradorTest OK\n";
