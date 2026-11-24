<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/Autenticacion.php';
require_once dirname(__DIR__) . '/api/ComplementoPagoAdministrador.php';

$db = Conexion::obtener();
$db->exec('CREATE TEMPORARY TABLE clientes (id INT PRIMARY KEY, nombre VARCHAR(150), razon_social VARCHAR(200)) ENGINE=InnoDB');
$db->exec('CREATE TEMPORARY TABLE usuarios (id INT PRIMARY KEY, cliente INT) ENGINE=InnoDB');
$db->exec('CREATE TEMPORARY TABLE claves_cortas (id INT PRIMARY KEY, id_cliente INT, clave_corta VARCHAR(24),
    razon_social VARCHAR(200), rfc VARCHAR(20), cp VARCHAR(6), regimen_fiscal INT, domicilio TEXT) ENGINE=InnoDB');
$db->exec('CREATE TEMPORARY TABLE facturas (id INT PRIMARY KEY, nombre INT, razon INT, metodo_pago INT,
    uuid VARCHAR(36), status VARCHAR(20), cancelado TINYINT, desactivado TINYINT NULL,
    id_clave_corta VARCHAR(50), total_iva DECIMAL(12,2), xml_total DECIMAL(12,2), xml_subtotal DECIMAL(12,2),
    total_factura DECIMAL(12,2), xml_firmado TEXT, retencion_transporte VARCHAR(30),
    serie VARCHAR(3), folio INT, fecha DATE, fecha_timbrado DATE) ENGINE=InnoDB');
$db->exec('CREATE TEMPORARY TABLE facturar_rel_complementos (id INT AUTO_INCREMENT PRIMARY KEY,
    id_factura INT UNIQUE, id_complemento INT, monto DECIMAL(12,2), num_pago INT,
    saldo DECIMAL(12,2), restante DECIMAL(12,2), uuid_rel VARCHAR(36), folio INT,
    serie VARCHAR(10), fecha_pago DATETIME) ENGINE=InnoDB');
$db->exec('CREATE TEMPORARY TABLE facturas_complementos (id INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATE, nombre INT, metodo_pago INT, moneda INT, forma_pago VARCHAR(10), status_pago VARCHAR(20),
    total_factura DECIMAL(12,2), id_pago INT, razon INT, tipo_factura VARCHAR(10), tipo_comprobante VARCHAR(10),
    token VARCHAR(100), id_clave_corta VARCHAR(50), serie VARCHAR(10), folio INT, fecha_pago DATETIME,
    status VARCHAR(20), cadena VARCHAR(10)) ENGINE=InnoDB');
$db->exec('CREATE TEMPORARY TABLE detalle_factura (id_factura INT, id_producto INT, iva INT) ENGINE=InnoDB');
$db->exec('CREATE TEMPORARY TABLE conceptos (id INT PRIMARY KEY, aplica_iva VARCHAR(10)) ENGINE=InnoDB');
$db->exec('CREATE TEMPORARY TABLE metodos_pago (id INT PRIMARY KEY, clave VARCHAR(10)) ENGINE=InnoDB');
$db->exec('CREATE TEMPORARY TABLE catalogo_monedas (id INT PRIMARY KEY, clave_moneda VARCHAR(10)) ENGINE=InnoDB');
$db->exec('CREATE TEMPORARY TABLE formas_pago (id INT PRIMARY KEY, clave VARCHAR(10)) ENGINE=InnoDB');
$db->exec('CREATE TEMPORARY TABLE pagos (id INT PRIMARY KEY, nombre VARCHAR(100), empresa VARCHAR(100),
    id_captura INT, monto DECIMAL(12,2), tipo_movimiento VARCHAR(100), status VARCHAR(50),
    banco VARCHAR(50), id_factura INT, id_factura_res VARCHAR(100), fecha_operacion DATE) ENGINE=InnoDB');
$db->exec('CREATE TEMPORARY TABLE captura (id INT PRIMARY KEY, razon VARCHAR(100)) ENGINE=InnoDB');

$db->exec("INSERT INTO clientes VALUES (10,'CLIENTE A','CLIENTE A'),(20,'CLIENTE B','CLIENTE B')");
$db->exec('INSERT INTO usuarios VALUES (11,10)');
$db->exec("INSERT INTO claves_cortas VALUES (1,10,'A','RECEPTOR A','BBB010101BBB','01234',601,''),
    (2,20,'B','RECEPTOR B','CCC010101CCC','01234',601,'')");
$db->exec("INSERT INTO conceptos VALUES (1,'si'),(2,'no')");
$db->exec("INSERT INTO metodos_pago VALUES (1,'PUE'),(2,'PPD')");
$db->exec("INSERT INTO catalogo_monedas VALUES (1,'MXN')");
$db->exec("INSERT INTO formas_pago VALUES (3,'03')");
$db->exec("INSERT INTO captura VALUES (1,'7')");
$db->exec("INSERT INTO pagos VALUES (1,'10','7',1,20,'Deposito','asignado','Santander',2,NULL,'2026-01-01')");

$uuids = [
    1 => '11111111-1111-1111-1111-111111111111',
    2 => '22222222-2222-2222-2222-222222222222',
    3 => '33333333-3333-3333-3333-333333333333',
    4 => '44444444-4444-4444-4444-444444444444',
    5 => '55555555-5555-5555-5555-555555555555',
];
$insertar = $db->prepare('INSERT INTO facturas VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
foreach ([
    [1,10,7,2,$uuids[1],null,0,null,'1',100,100,86.21,100,'','No','A',1,'2026-01-01',null],
    [2,10,7,2,$uuids[2],null,0,null,'1',50,50,50,50,'','No','A',2,'2026-01-01',null],
    [3,20,7,2,$uuids[3],null,0,null,'2',30,30,25.86,30,'','No','B',3,'2026-01-01',null],
    [4,10,8,2,$uuids[4],null,0,null,'1',40,40,34.48,40,'','No','A',4,'2026-01-01',null],
    [5,10,7,2,$uuids[5],null,0,null,'1',60,60,51.72,60,'','No','A',5,'2026-01-01',null],
] as $fila) $insertar->execute($fila);
$db->exec('INSERT INTO detalle_factura VALUES (1,1,16),(2,1,0),(3,1,16),(4,1,16),(5,1,16)');
SesionEmpresa::iniciar();
$_SESSION['auth_usuario_id'] = 11;
$_SESSION['empresa_actual'] = 7;
$administrador = new ComplementoPagoAdministrador($db);
$datos = static fn(array $facturas, ?int $pagoId = null): array => [
    'cliente_id' => 10, 'perfil_id' => 1, 'forma_pago_id' => 3,
    'solicitud_id' => bin2hex(random_bytes(16)), 'facturas' => $facturas,
    'pago_id' => $pagoId,
];
$partida = static fn(int $id, string $monto, string $token = ''): array =>
    ['factura_id' => $id, 'monto' => $monto, 'autorizacion_uuid' => $token];
$rechazar = static function (callable $operacion): void {
    try {
        $operacion();
        throw new RuntimeException('Una operación inválida fue aceptada.');
    } catch (ComplementoPagoValidacionException) {
    }
};

$rechazar(static fn() => $administrador->guardarPendiente($datos([$partida(1, '10.00'), $partida(2, '10.00')])));
if ((int) $db->query('SELECT COUNT(*) FROM facturas_complementos')->fetchColumn() !== 0) {
    throw new RuntimeException('Se guardó un complemento con tratamientos fiscales mixtos.');
}
$rechazar(static fn() => $administrador->guardarPendiente($datos([$partida(3, '10.00')])));
$rechazar(static fn() => $administrador->facturaPorUuid(10, 1, $uuids[4]));
$manual = $administrador->facturaPorUuid(10, 1, $uuids[3]);
$rechazar(static fn() => $administrador->guardarPendiente($datos([$partida(4, '10.00', $manual['autorizacion_uuid'])])));
$datosManual = $datos([$partida(3, '10.00', $manual['autorizacion_uuid'])]);
$datosManual['tratamiento_fiscal'] = 'SV'; // El backend debe sobrescribir el dato manipulado.
$resultado = $administrador->guardarPendiente($datosManual);
$cadena = $db->query('SELECT cadena FROM facturas_complementos WHERE id = ' . (int) $resultado['id'])->fetchColumn();
if ($cadena !== 'T') throw new RuntimeException('No se heredó el tratamiento fiscal del UUID manual.');

$resultadoMovimiento = $administrador->guardarPendiente($datos([$partida(5, '20.00')], 1));
$referencia = $db->query('SELECT id_factura_res FROM pagos WHERE id = 1')->fetchColumn();
if ((int) $referencia !== (int) $resultadoMovimiento['id']) {
    throw new RuntimeException('El movimiento no quedó relacionado con el complemento.');
}
$rechazar(static fn() => $administrador->guardarPendiente($datos([$partida(1, '20.00')], 1)));

$insertar->execute([6,10,7,2,$uuids[1],null,0,null,'1',25,25,21.55,25,'','No','A',6,'2026-01-01',null]);
$db->exec('INSERT INTO detalle_factura VALUES (6,1,16)');
$rechazar(static fn() => $administrador->guardarPendiente($datos([$partida(1, '10.00'), $partida(6, '10.00')])));

$antes = (int) $db->query('SELECT COUNT(*) FROM facturas_complementos')->fetchColumn();
try {
    $administrador->guardarPendiente($datos([$partida(5, '10.00')]));
    throw new RuntimeException('No se provocó el fallo de relación esperado.');
} catch (ComplementoPagoPersistenciaException) {
    // La tabla temporal tiene UNIQUE(id_factura) para forzar un fallo después del INSERT del encabezado.
}
if ((int) $db->query('SELECT COUNT(*) FROM facturas_complementos')->fetchColumn() !== $antes) {
    throw new RuntimeException('El encabezado no se revirtió después del fallo de relación.');
}
echo "ComplementoPagoGuardadoIntegracionTest OK\n";
