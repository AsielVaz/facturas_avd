<?php

declare(strict_types=1);

require_once __DIR__ . '/Autenticacion.php';
require_once __DIR__ . '/passkeys_crypto.php';

SesionEmpresa::iniciar();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** @param array<string, mixed> $datos */
function responderPasskey(array $datos, int $estado = 200): never
{
    http_response_code($estado);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

/** @param array<string, mixed> $config */
function comprobarConfiguracionPasskey(array $config): void
{
    $origen = (string) ($config['origin'] ?? '');
    $partes = parse_url($origen);
    $host = is_array($partes) ? (string) ($partes['host'] ?? '') : '';
    if (PHP_INT_SIZE < 8 || !extension_loaded('openssl') || $host === ''
        || $host !== (string) ($config['rp_id'] ?? '')
        || isset($partes['path']) || isset($partes['query']) || isset($partes['fragment'])
        || !in_array($partes['scheme'] ?? '', $host === 'localhost' ? ['http', 'https'] : ['https'], true)
        || strlen((string) ($config['handle_secret'] ?? '')) < 32) {
        throw new RuntimeException('Las passkeys no están configuradas para este origen.');
    }
    $hostPeticion = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''), 2)[0]);
    if ($hostPeticion !== $host) {
        throw new RuntimeException('El host no coincide con la configuración de passkeys.');
    }
}

/** @return array<string, mixed> */
function cuerpoPasskey(): array
{
    $contenido = file_get_contents('php://input', false, null, 0, 180001);
    if (!is_string($contenido) || strlen($contenido) > 180000) {
        throw new RuntimeException('La respuesta de la passkey es demasiado grande.');
    }
    $datos = json_decode($contenido, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($datos)) throw new RuntimeException('La solicitud no es válida.');
    return $datos;
}

/** @param array<string, mixed> $datos */
function comprobarSolicitudPasskey(array $datos, array $config): void
{
    if (($_SERVER['HTTP_ORIGIN'] ?? '') !== $config['origin']) {
        throw new RuntimeException('El origen de la solicitud no está permitido.');
    }
    $csrf = (string) ($datos['csrf'] ?? '');
    if ($csrf === '' || !hash_equals((string) ($_SESSION['login_csrf'] ?? ''), $csrf)) {
        throw new RuntimeException('La sesión del login expiró. Recarga la página.');
    }
}

/** @return array<string, mixed> */
function consumirRetoPasskey(string $tipo, int $vigencia): array
{
    $reto = $_SESSION['passkey_challenge'] ?? null;
    unset($_SESSION['passkey_challenge']);
    if (!is_array($reto) || ($reto['tipo'] ?? '') !== $tipo
        || time() - (int) ($reto['creado'] ?? 0) > $vigencia) {
        throw new RuntimeException('El reto venció o ya se utilizó. Vuelve a intentarlo.');
    }
    return $reto;
}

/** @return array<string, mixed> */
function usuarioAutorizadoParaRegistro(PDO $conexion, int $vigencia): array
{
    $permiso = $_SESSION['passkey_enroll'] ?? null;
    $usuarioId = Autenticacion::usuarioActualId();
    if (!Autenticacion::autenticado() || !is_array($permiso) || $usuarioId <= 0
        || (int) ($permiso['usuario_id'] ?? 0) !== $usuarioId
        || time() - (int) ($permiso['creado'] ?? 0) > $vigencia) {
        throw new RuntimeException('Para registrar una passkey, vuelve a entrar con contraseña y 2FA.');
    }
    $consulta = $conexion->prepare('SELECT id, email, usuario, nombre, appat, apmat FROM usuarios WHERE id = :id LIMIT 1');
    $consulta->execute([':id' => $usuarioId]);
    $usuario = $consulta->fetch(PDO::FETCH_ASSOC);
    if (!is_array($usuario)) throw new RuntimeException('El usuario ya no está disponible.');
    return $usuario;
}

function limitarIntentosPasskey(PDO $conexion): void
{
    $ip = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $consulta = $conexion->prepare(
        'SELECT COUNT(*) FROM intentos_login WHERE ip_hash = :ip AND creado_en >= DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $consulta->execute([':ip' => $ip]);
    if ((int) $consulta->fetchColumn() >= 30) {
        throw new RuntimeException('Demasiados intentos. Espera antes de volver a intentarlo.');
    }
}

function registrarFalloPasskey(PDO $conexion): void
{
    $consulta = $conexion->prepare(
        'INSERT INTO intentos_login (identificador_hash, ip_hash, creado_en) VALUES (:identificador, :ip, NOW())'
    );
    $consulta->execute([
        ':identificador' => hash('sha256', '[passkey]'),
        ':ip' => hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '')),
    ]);
}

$config = require __DIR__ . '/passkeys_config.php';
$accion = (string) ($_GET['accion'] ?? '');
$conexion = null;
try {
    if ($accion === 'bootstrap' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $_SESSION['login_csrf'] ??= bin2hex(random_bytes(32));
        try {
            comprobarConfiguracionPasskey($config);
            $conexion = Conexion::obtener();
            $disponible = (bool) $conexion->query("SHOW TABLES LIKE 'usuarios_passkeys'")->fetchColumn();
        } catch (Throwable $error) {
            $disponible = false;
        }
        responderPasskey(['ok' => true, 'csrf' => $_SESSION['login_csrf'], 'disponible' => $disponible]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') responderPasskey(['ok' => false, 'error' => 'Usa POST.'], 405);
    comprobarConfiguracionPasskey($config);
    $datos = cuerpoPasskey();
    comprobarSolicitudPasskey($datos, $config);
    $conexion = Conexion::obtener();

    if ($accion === 'registrar_opciones') {
        $usuario = usuarioAutorizadoParaRegistro($conexion, (int) $config['enrollment_ttl']);
        $consulta = $conexion->prepare('SELECT credential_id FROM usuarios_passkeys WHERE id_usuario = :id');
        $consulta->execute([':id' => (int) $usuario['id']]);
        $existentes = array_map(static fn(array $fila): array => [
            'type' => 'public-key', 'id' => pk_b64($fila['credential_id']),
        ], $consulta->fetchAll(PDO::FETCH_ASSOC));
        if (count($existentes) >= (int) $config['max_credentials']) {
            throw new RuntimeException('Ya alcanzaste el límite de passkeys de esta cuenta.');
        }
        $handle = hash_hmac('sha256', 'Sistema14.usuario.' . $usuario['id'], $config['handle_secret'], true);
        $reto = pk_b64(random_bytes(32));
        $_SESSION['passkey_challenge'] = [
            'tipo' => 'registrar', 'valor' => $reto, 'usuario_id' => (int) $usuario['id'], 'creado' => time(),
        ];
        responderPasskey(['ok' => true, 'publicKey' => [
            'challenge' => $reto,
            'rp' => ['id' => $config['rp_id'], 'name' => $config['rp_name']],
            'user' => [
                'id' => pk_b64($handle),
                'name' => trim((string) ($usuario['email'] ?: $usuario['usuario'])) ?: ('Usuario #' . $usuario['id']),
                'displayName' => trim(implode(' ', array_filter([
                    $usuario['nombre'], $usuario['appat'], $usuario['apmat'],
                ]))) ?: ('Usuario #' . $usuario['id']),
            ],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]],
            'timeout' => 120000,
            'attestation' => 'none',
            'excludeCredentials' => $existentes,
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform', 'residentKey' => 'required',
                'requireResidentKey' => true, 'userVerification' => 'required',
            ],
        ]]);
    }

    if ($accion === 'entrar_opciones') {
        limitarIntentosPasskey($conexion);
        $reto = pk_b64(random_bytes(32));
        $_SESSION['passkey_challenge'] = ['tipo' => 'entrar', 'valor' => $reto, 'creado' => time()];
        responderPasskey(['ok' => true, 'publicKey' => [
            'challenge' => $reto, 'rpId' => $config['rp_id'], 'timeout' => 120000,
            'userVerification' => 'required',
        ]]);
    }

    if (!in_array($accion, ['registrar_verificar', 'entrar_verificar'], true)) {
        throw new RuntimeException('La operación de passkey no existe.');
    }
    $registrando = $accion === 'registrar_verificar';
    $reto = consumirRetoPasskey($registrando ? 'registrar' : 'entrar', (int) $config['challenge_ttl']);
    if (!$registrando) limitarIntentosPasskey($conexion);
    $credencial = $datos['credential'] ?? null;
    if (!is_array($credencial) || ($credencial['type'] ?? '') !== 'public-key'
        || !is_array($credencial['response'] ?? null)
        || ($credencial['id'] ?? null) !== ($credencial['rawId'] ?? null)) {
        throw new RuntimeException('La credencial recibida no es válida.');
    }
    $idBytes = pk_unb64($credencial['rawId'], 1023);

    if ($registrando) {
        $usuario = usuarioAutorizadoParaRegistro($conexion, (int) $config['enrollment_ttl']);
        if ((int) $usuario['id'] !== (int) $reto['usuario_id']) throw new RuntimeException('El usuario cambió durante el registro.');
        $resultado = pk_registration(
            $credencial['response'], $reto['valor'], $config['origin'], $config['rp_id'], $idBytes
        );
        $nombre = trim((string) ($datos['nombre'] ?? '')) ?: 'Mi dispositivo';
        if (mb_strlen($nombre, 'UTF-8') > 100) throw new RuntimeException('El nombre de la passkey es demasiado largo.');
        $transportes = [];
        foreach (is_array($credencial['response']['transports'] ?? null) ? $credencial['response']['transports'] : [] as $transporte) {
            if (is_string($transporte) && in_array($transporte, ['usb', 'nfc', 'ble', 'internal', 'hybrid'], true)
                && !in_array($transporte, $transportes, true)) {
                $transportes[] = $transporte;
            }
        }
        $conexion->beginTransaction();
        try {
            $bloqueo = $conexion->prepare('SELECT id FROM usuarios WHERE id = :id FOR UPDATE');
            $bloqueo->execute([':id' => (int) $usuario['id']]);
            if (!$bloqueo->fetchColumn()) throw new RuntimeException('El usuario ya no está disponible.');
            $conteo = $conexion->prepare('SELECT COUNT(*) FROM usuarios_passkeys WHERE id_usuario = :id');
            $conteo->execute([':id' => (int) $usuario['id']]);
            if ((int) $conteo->fetchColumn() >= (int) $config['max_credentials']) {
                throw new RuntimeException('Ya alcanzaste el límite de passkeys de esta cuenta.');
            }
            $guardar = $conexion->prepare(
                'INSERT INTO usuarios_passkeys
                 (id_usuario, credential_id, credential_hash, public_key, user_handle, sign_count,
                  transports, backup_eligible, backup_state, nombre)
                 VALUES (:usuario, :credencial, :hash, :clave, :handle, :contador, :transportes, :respaldo, :estado, :nombre)'
            );
            $guardar->execute([
                ':usuario' => (int) $usuario['id'], ':credencial' => $idBytes,
                ':hash' => hash('sha256', $idBytes, true), ':clave' => $resultado['public_key'],
                ':handle' => hash_hmac('sha256', 'Sistema14.usuario.' . $usuario['id'], $config['handle_secret'], true),
                ':contador' => $resultado['count'], ':transportes' => json_encode($transportes, JSON_THROW_ON_ERROR),
                ':respaldo' => $resultado['be'], ':estado' => $resultado['bs'], ':nombre' => $nombre,
            ]);
            $conexion->commit();
        } catch (Throwable $error) {
            if ($conexion->inTransaction()) $conexion->rollBack();
            throw $error;
        }
        unset($_SESSION['passkey_enroll'], $_SESSION['passkey_enroll_next']);
        responderPasskey(['ok' => true, 'mensaje' => 'La passkey quedó registrada.']);
    }

    $conexion->beginTransaction();
    try {
        $consulta = $conexion->prepare('SELECT * FROM usuarios_passkeys WHERE credential_hash = :hash FOR UPDATE');
        $consulta->execute([':hash' => hash('sha256', $idBytes, true)]);
        $registro = $consulta->fetch(PDO::FETCH_ASSOC);
        if (!is_array($registro) || !hash_equals((string) $registro['credential_id'], $idBytes)) {
            throw new RuntimeException('La passkey no está registrada.');
        }
        $handleRecibido = $credencial['response']['userHandle'] ?? null;
        if (!is_string($handleRecibido)
            || !hash_equals((string) $registro['user_handle'], pk_unb64($handleRecibido, 64))) {
            throw new RuntimeException('La passkey no corresponde a esta cuenta.');
        }
        $resultado = pk_assertion(
            $credencial['response'], $reto['valor'], $config['origin'], $config['rp_id'], $registro
        );
        $usuario = $conexion->prepare('SELECT id FROM usuarios WHERE id = :id LIMIT 1');
        $usuario->execute([':id' => (int) $registro['id_usuario']]);
        if (!$usuario->fetchColumn()) throw new RuntimeException('El usuario ya no está disponible.');
        $actualizar = $conexion->prepare(
            'UPDATE usuarios_passkeys SET sign_count = :contador, backup_state = :estado, ultimo_uso = NOW() WHERE id = :id'
        );
        $actualizar->execute([
            ':contador' => $resultado['count'], ':estado' => $resultado['bs'], ':id' => (int) $registro['id'],
        ]);
        $conexion->commit();
        unset($_SESSION['passkey_enroll'], $_SESSION['passkey_enroll_next']);
        Autenticacion::iniciarSesionConPasskey($conexion, (int) $registro['id_usuario']);
        responderPasskey(['ok' => true, 'mensaje' => 'Acceso correcto.']);
    } catch (Throwable $error) {
        if ($conexion->inTransaction()) $conexion->rollBack();
        throw $error;
    }
} catch (Throwable $error) {
    error_log('Error de passkey: ' . $error::class . ': ' . $error->getMessage());
    if ($conexion instanceof PDO && in_array($accion, ['entrar_verificar'], true)) {
        try { registrarFalloPasskey($conexion); } catch (Throwable $ignorado) {}
    }
    $mensaje = $error instanceof RuntimeException ? $error->getMessage()
        : 'No fue posible verificar la passkey. Usa contraseña y 2FA o intenta nuevamente.';
    responderPasskey(['ok' => false, 'error' => $mensaje], 400);
}
