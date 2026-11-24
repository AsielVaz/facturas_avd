<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/Conexion.php';
class ComplementoPagoValidacionException extends RuntimeException {}
require_once dirname(__DIR__) . '/api/ComplementoMovimientoServicio.php';

$db = Conexion::obtener();
$db->exec('CREATE TEMPORARY TABLE pagos (
    id INT PRIMARY KEY, nombre VARCHAR(100), empresa VARCHAR(100), id_captura INT,
    monto DOUBLE, tipo_movimiento VARCHAR(100), status VARCHAR(50), banco VARCHAR(50),
    id_factura INT, id_factura_res VARCHAR(100), fecha_operacion DATE)');
$db->exec('CREATE TEMPORARY TABLE captura (id INT PRIMARY KEY, razon VARCHAR(100))');
$db->exec('CREATE TEMPORARY TABLE usuarios (id INT PRIMARY KEY, cliente INT)');
$db->exec('CREATE TEMPORARY TABLE facturas_complementos (id INT PRIMARY KEY, id_pago INT)');
$db->exec("INSERT INTO captura VALUES (1,'7'),(2,'8')");
$db->exec('INSERT INTO usuarios VALUES (11,10)');
$filas = [
    [1, '10', '7', 1, 100, 'Deposito', 'asignado', 'Santander', 2, null, '2026-11-24'],
    [2, '10', '7', 1, 100, 'Retiro', 'asignado', 'Santander', null, null, '2026-11-24'],
    [3, '10', '7', 1, 100, 'Abono por comisión', 'asignado', 'Santander', null, null, '2026-11-24'],
    [4, '10', '7', 1, 100, 'Deposito', 'asignado', 'STP', null, null, '2026-11-24'],
    [5, '10', '7', 1, 100, 'Deposito', 'asignado', '99', null, null, '2026-11-24'],
    [6, '10', '7', 1, 100, 'Deposito', 'asignado', 'Santander', 5, null, '2026-11-24'],
    [7, '10', '7', 1, 100, 'Deposito', 'asignado', 'Santander', null, '4', '2026-11-24'],
    [8, '10', '7', 2, 100, 'Deposito', 'asignado', 'Santander', null, null, '2026-11-24'],
    [9, '12', '7', 1, 100, 'Deposito', 'asignado', 'Santander', null, null, '2026-11-24'],
    [10, '10', '7', 1, 0, 'Deposito', 'asignado', 'Santander', null, null, '2026-11-24'],
];
$insertar = $db->prepare('INSERT INTO pagos VALUES (?,?,?,?,?,?,?,?,?,?,?)');
foreach ($filas as $fila) $insertar->execute($fila);
$servicio = new ComplementoMovimientoServicio();
$aceptado = $servicio->obtenerDisponible($db, 1, 7, 11);
if ((int) $aceptado['cliente_id'] !== 10 || (int) $aceptado['empresa_id'] !== 7
    || ComplementoMovimientoServicio::referenciaPositiva('') !== 0
    || ComplementoMovimientoServicio::referenciaPositiva('2') !== 2
    || ComplementoMovimientoServicio::referenciaPositiva('0002') !== 2
    || ComplementoMovimientoServicio::referenciaPositiva('1.5') !== 0) {
    throw new RuntimeException('La bandera de movimiento o las referencias no se interpretaron bien.');
}
foreach (range(2, 10) as $id) {
    try {
        $servicio->obtenerDisponible($db, $id, 7, 11);
        throw new RuntimeException('Se admitió el movimiento inválido ' . $id);
    } catch (ComplementoPagoValidacionException) {
        // El movimiento debe rechazarse antes de crear un complemento.
    }
}
echo "ComplementoMovimientoIntegracionTest OK\n";
