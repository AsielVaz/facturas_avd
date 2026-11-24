<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/Conexion.php';
require_once dirname(__DIR__) . '/api/DocumentosVivosServicio.php';

$db = Conexion::obtener();
$db->exec('CREATE TEMPORARY TABLE claves_cortas (id INT PRIMARY KEY, documentos_vivos INT NOT NULL DEFAULT 0)');
$db->exec('CREATE TEMPORARY TABLE facturas (
    id INT PRIMARY KEY, nombre INT, razon INT, metodo_pago INT, uuid VARCHAR(36), status VARCHAR(20),
    cancelado TINYINT, desactivado TINYINT NULL, id_clave_corta VARCHAR(50), total_iva DECIMAL(12,2))');
$db->exec('CREATE TEMPORARY TABLE facturar_rel_complementos (id_factura INT, monto DECIMAL(12,2))');
$db->exec('INSERT INTO claves_cortas (id) VALUES (1),(2),(3)');
$uuid = '12345678-1234-1234-1234-123456789ABC';
$facturas = [
    [1, 10, 1, 2, $uuid, null, 0, null, '1', 100],      // PPD con saldo: clave 1, empresa 1.
    [2, 10, 2, 2, $uuid, null, 0, null, '1', 50],       // Misma clave en otra empresa.
    [3, 10, 2, 2, $uuid, null, 0, null, '2', 30],       // Otra clave: solo empresa 2.
    [4, 10, 1, 2, $uuid, null, 0, null, '1', 20],       // Completamente pagada.
    [5, 10, 1, 2, $uuid, 'Cancelado', 1, null, '1', 20],
    [6, 10, 1, 1, $uuid, null, 0, null, '1', 20],        // PUE.
    [7, 10, 1, 2, null, null, 0, null, '1', 20],         // Sin UUID.
    [8, 10, 1, 2, $uuid, null, 0, null, '', 20],        // Clave vacía VARCHAR.
    [9, 10, 1, 2, $uuid, null, 0, null, '2', 25],       // Segunda clave con saldo.
    [10, 10, 2, 2, $uuid, null, 0, null, '3', 15],      // Global, sin documentos en empresa 1.
    [11, 20, 2, 2, $uuid, null, 0, null, '1', 10],      // Clave 1 en otro cliente: solo cuenta global.
];
$insertar = $db->prepare('INSERT INTO facturas VALUES (?,?,?,?,?,?,?,?,?,?)');
foreach ($facturas as $factura) $insertar->execute($factura);
$db->exec('INSERT INTO facturar_rel_complementos VALUES (1,40),(4,20)');
$servicio = new DocumentosVivosServicio();
$conteos = $servicio->conteosCliente($db, 10, 1);
if ($conteos[1] !== ['empresa' => 1, 'global' => 3]
    || $conteos[2] !== ['empresa' => 1, 'global' => 2]
    || $conteos[3] !== ['empresa' => 0, 'global' => 1]) {
    throw new RuntimeException('El conteo por cliente, clave corta o empresa es incorrecto.');
}
$conteosOtraEmpresa = $servicio->conteosCliente($db, 10, 2);
if ($conteosOtraEmpresa[1]['empresa'] !== 1 || $conteosOtraEmpresa[2]['empresa'] !== 1) {
    throw new RuntimeException('La disponibilidad por segunda empresa es incorrecta.');
}
$modificados = DocumentosVivosServicio::actualizarConteos($db);
$resumen = $db->query('SELECT id, documentos_vivos FROM claves_cortas ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
if ((int) $resumen[1] !== 3 || (int) $resumen[2] !== 2 || (int) $resumen[3] !== 1 || $modificados !== 3) {
    throw new RuntimeException('El cron no actualizó el conteo global esperado.');
}
echo "DocumentosVivosIntegracionTest OK\n";
