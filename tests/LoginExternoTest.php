<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/api/Autenticacion.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE usuarios (
    id INTEGER PRIMARY KEY, nombre TEXT, appat TEXT, apmat TEXT, email TEXT,
    usuario TEXT, tipo TEXT, imagen TEXT, cliente INTEGER, empresa_asignada INTEGER
)');
$pdo->exec('CREATE TABLE empresas (id INTEGER PRIMARY KEY, razon TEXT, logo TEXT, rfc TEXT)');
$pdo->exec('CREATE TABLE claves_cortas (id INTEGER PRIMARY KEY, rfc TEXT)');
$pdo->exec('CREATE TABLE cliente_empresa (id INTEGER PRIMARY KEY, id_usuario INTEGER, id_empresa INTEGER)');
$pdo->exec("INSERT INTO usuarios VALUES (42, 'Ana', 'Prueba', '', 'ana@example.com', 'ana', 'cliente', '', 13, 7)");
$pdo->exec("INSERT INTO empresas VALUES (7, 'Empresa de prueba', '', 'ABC010101AAA')");
$pdo->exec("INSERT INTO claves_cortas VALUES (9, 'ABC010101AAA')");
$pdo->exec('INSERT INTO cliente_empresa VALUES (1, 42, 7)');

SesionEmpresa::iniciar();
try {
    Autenticacion::iniciarSesionConClaveExterna($pdo, 42, 99, 'cliente', 'ana@example.com');
    throw new RuntimeException('Se aceptó un cliente diferente al registrado.');
} catch (AutenticacionException) {
    if (isset($_SESSION['auth_usuario_id'])) {
        throw new RuntimeException('Una identidad inválida creó una sesión.');
    }
}

Autenticacion::iniciarSesionConClaveExterna($pdo, 42, 13, 'cliente', 'ana@example.com');
if (!Autenticacion::autenticado()
    || $_SESSION['auth_usuario_id'] !== 42
    || !is_int($_SESSION['auth_inicio'])
    || SesionEmpresa::empresaActual() !== 7
    || SesionEmpresa::claveActual() !== 9) {
    throw new RuntimeException('La sesión externa no coincide con la sesión normal del ERP.');
}

echo "Login externo: OK\n";
