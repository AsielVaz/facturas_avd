<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/ComplementoTratamientoFiscal.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE detalle_factura (id_factura INTEGER, id_producto INTEGER, iva INTEGER)');
$db->exec('CREATE TABLE conceptos (id INTEGER PRIMARY KEY, aplica_iva TEXT)');
$db->exec("INSERT INTO conceptos VALUES (1, 'si'), (2, 'no')");
$comprobante = static fn(string $impuestos, string $objeto = '02'): string =>
    '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4"><cfdi:Conceptos><cfdi:Concepto ObjetoImp="'
    . $objeto . '"/></cfdi:Conceptos><cfdi:Impuestos>' . $impuestos . '</cfdi:Impuestos></cfdi:Comprobante>';
$traslado = static fn(string $tasa): string => '<cfdi:Traslados><cfdi:Traslado Impuesto="002" TasaOCuota="'
    . $tasa . '"/></cfdi:Traslados>';
$retencion = '<cfdi:Retenciones><cfdi:Retencion Impuesto="002"/></cfdi:Retenciones>';
$casos = [
    'iva16' => [$comprobante($traslado('0.160000')), 'T'],
    'tasa0' => [$comprobante($traslado('0.000000')), 'T0'],
    'siniva' => [$comprobante('', '01'), 'SV'],
    'retencion' => [$comprobante($traslado('0.160000') . $retencion), '4'],
];
foreach ($casos as $nombre => [$xml, $esperado]) {
    $actual = ComplementoTratamientoFiscal::desdeFactura($db,
        ['id' => 1, 'xml_firmado' => $xml, 'retencion_transporte' => 'No']);
    if ($actual !== $esperado) throw new RuntimeException("Tratamiento $nombre: $actual, esperado $esperado");
}
$db->exec('INSERT INTO detalle_factura VALUES (2, 1, 0), (3, 2, 0), (4, 1, 16)');
foreach ([[2, 'T0'], [3, 'SV'], [4, 'T']] as [$id, $esperado]) {
    $actual = ComplementoTratamientoFiscal::desdeFactura($db,
        ['id' => $id, 'xml_firmado' => '', 'retencion_transporte' => 'No']);
    if ($actual !== $esperado) throw new RuntimeException("Respaldo de detalle $id incorrecto: $actual");
}
$actual = ComplementoTratamientoFiscal::desdeFactura($db,
    ['id' => 1, 'xml_firmado' => '<inválido', 'retencion_transporte' => 'Si']);
if ($actual !== '4') throw new RuntimeException('No se aplicó el respaldo de retención.');
echo "ComplementoTratamientoFiscalTest OK\n";
