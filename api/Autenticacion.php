<?php

declare(strict_types=1);

require_once __DIR__ . '/Conexion.php';
require_once __DIR__ . '/SesionEmpresa.php';

final class AutenticacionException extends RuntimeException
{
}

final class Autenticacion
{
    private const MAX_INTENTOS_USUARIO = 5;
    private const MAX_INTENTOS_IP = 20;
    private const VENTANA_INTENTOS_MINUTOS = 15;
    private const INACTIVIDAD_MAXIMA = 3600;
    private const DURACION_MAXIMA = 43200;
    private const REGENERAR_CADA = 900;
    private const VIGENCIA_SEGUNDO_FACTOR = 300;
    private const MAX_INTENTOS_SEGUNDO_FACTOR = 5;
    private const HASH_FICTICIO = '$2y$12$fh6mO4nSt1ekMaD/nm3wfOxs281exSld8hpzPPfzCumb33s394ez6';

    public static function autenticado(): bool
    {
        SesionEmpresa::iniciar();
        $usuarioId = (int) ($_SESSION['auth_usuario_id'] ?? 0);
        $inicio = (int) ($_SESSION['auth_inicio'] ?? 0);
        $ultimaActividad = (int) ($_SESSION['auth_ultima_actividad'] ?? 0);
        $ahora = time();
        if ($usuarioId <= 0 || $inicio <= 0 || $ultimaActividad <= 0) {
            return false;
        }
        if (($ahora - $ultimaActividad) > self::INACTIVIDAD_MAXIMA || ($ahora - $inicio) > self::DURACION_MAXIMA) {
            self::limpiarAutenticacion();
            return false;
        }
        if (($ahora - (int) ($_SESSION['auth_regenerada'] ?? 0)) >= self::REGENERAR_CADA) {
            session_regenerate_id(false);
            $_SESSION['auth_regenerada'] = $ahora;
        }
        $_SESSION['auth_ultima_actividad'] = $ahora;
        return true;
    }

    public static function exigirPagina(): void
    {
        if (!self::autenticado()) {
            $destino = self::rutaSegura((string) ($_SERVER['REQUEST_URI'] ?? ''));
            header('Location: login.php' . ($destino !== '' ? '?next=' . rawurlencode($destino) : ''));
            exit;
        }

        if (self::asegurarEmpresaActual(Conexion::obtener())) {
            return;
        }

        // La pantalla de selección debe seguir disponible para explicar que aún no hay asignaciones.
        if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'empresas-s.php') {
            return;
        }
        header('Location: empresas-s.php?sin_asignacion=1');
        exit;
    }

    public static function exigirApi(): void
    {
        if (!self::autenticado()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode([
                'ok' => false,
                'error' => 'Tu sesión expiró. Inicia sesión nuevamente.',
                'codigo' => 'SESION_EXPIRADA',
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            exit;
        }

        if (self::asegurarEmpresaActual(Conexion::obtener())) {
            return;
        }
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok' => false,
            'error' => 'Tu usuario no tiene una empresa disponible. Solicita que te asignen una empresa.',
            'codigo' => 'EMPRESA_NO_ASIGNADA',
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    /** Devuelve true si la sesión quedó completa y false si requiere el segundo factor. */
    public static function iniciarSesion(PDO $conexion, string $identificador, string $password): bool
    {
        SesionEmpresa::iniciar();
        $identificador = trim($identificador);
        $longitudIdentificador = function_exists('mb_strlen')
            ? mb_strlen($identificador, 'UTF-8')
            : strlen($identificador);
        if ($identificador === '' || $longitudIdentificador > 190 || $password === '' || strlen($password) > 4096) {
            throw new AutenticacionException('Usuario o contraseña incorrectos.');
        }

        $identificadorNormalizado = function_exists('mb_strtolower')
            ? mb_strtolower($identificador, 'UTF-8')
            : strtolower($identificador);
        $identificadorHash = hash('sha256', $identificadorNormalizado);
        $ipHash = hash('sha256', self::ipCliente());
        self::validarLimite($conexion, $identificadorHash, $ipHash);

        $consulta = $conexion->prepare(
            "SELECT id, nombre, appat, apmat, email, usuario, password, tipo, imagen, `2fa`
             FROM usuarios
             WHERE LOWER(TRIM(COALESCE(email, ''))) = :correo
                OR LOWER(TRIM(COALESCE(usuario, ''))) = :usuario
             ORDER BY id
             LIMIT 20"
        );
        $consulta->bindValue(':correo', $identificadorNormalizado);
        $consulta->bindValue(':usuario', $identificadorNormalizado);
        $consulta->execute();
        $coincidencias = $consulta->fetchAll(PDO::FETCH_ASSOC);
        $usuario = null;
        foreach ($coincidencias as $candidato) {
            if (self::verificarPassword(
                $password,
                (string) ($candidato['password'] ?? ''),
                (string) ($candidato['email'] ?? '')
            )) {
                if ($usuario !== null) {
                    $usuario = null;
                    break;
                }
                $usuario = $candidato;
            }
        }
        if ($coincidencias === []) {
            password_verify($password, self::HASH_FICTICIO);
        }
        if (!is_array($usuario)) {
            self::registrarFallo($conexion, $identificadorHash, $ipHash);
            throw new AutenticacionException('Usuario o contraseña incorrectos.');
        }

        $hashGuardado = (string) ($usuario['password'] ?? '');
        self::actualizarHashSiNecesario($conexion, (int) $usuario['id'], $password, $hashGuardado);
        self::limpiarIntentos($conexion, $identificadorHash);
        $secretoSegundoFactor = strtoupper(trim((string) ($usuario['2fa'] ?? '')));
        if ($secretoSegundoFactor !== '') {
            self::prepararSegundoFactor($usuario, $identificadorHash, $ipHash);
            return false;
        }

        self::completarSesion($conexion, $usuario);
        return true;
    }

    public static function segundoFactorPendiente(): bool
    {
        SesionEmpresa::iniciar();
        $usuarioId = (int) ($_SESSION['auth_2fa_usuario_id'] ?? 0);
        $inicio = (int) ($_SESSION['auth_2fa_inicio'] ?? 0);
        if ($usuarioId <= 0 || $inicio <= 0) {
            return false;
        }
        if ((time() - $inicio) > self::VIGENCIA_SEGUNDO_FACTOR) {
            self::cancelarSegundoFactor();
            return false;
        }
        return true;
    }

    public static function verificarSegundoFactor(PDO $conexion, string $codigo): void
    {
        if (!self::segundoFactorPendiente()) {
            throw new AutenticacionException('La verificación expiró. Ingresa nuevamente tu usuario y contraseña.');
        }
        $codigo = preg_replace('/\D+/', '', $codigo) ?? '';
        if (!preg_match('/^\d{6}$/', $codigo)) {
            throw new AutenticacionException('Ingresa el código de 6 dígitos de tu aplicación autenticadora.');
        }

        $intentos = (int) ($_SESSION['auth_2fa_intentos'] ?? 0);
        if ($intentos >= self::MAX_INTENTOS_SEGUNDO_FACTOR) {
            self::cancelarSegundoFactor();
            throw new AutenticacionException('Se agotaron los intentos. Ingresa nuevamente tu usuario y contraseña.');
        }

        $usuarioId = (int) $_SESSION['auth_2fa_usuario_id'];
        $consulta = $conexion->prepare(
            "SELECT id, nombre, appat, apmat, email, usuario, password, tipo, imagen, `2fa`
             FROM usuarios WHERE id = :usuario LIMIT 1"
        );
        $consulta->execute([':usuario' => $usuarioId]);
        $usuario = $consulta->fetch(PDO::FETCH_ASSOC);
        $contador = is_array($usuario)
            ? self::validarCodigoTotp((string) ($usuario['2fa'] ?? ''), $codigo)
            : null;

        if (!is_array($usuario) || $contador === null) {
            $_SESSION['auth_2fa_intentos'] = $intentos + 1;
            self::registrarFallo(
                $conexion,
                (string) ($_SESSION['auth_2fa_identificador_hash'] ?? hash('sha256', '2fa|' . $usuarioId)),
                (string) ($_SESSION['auth_2fa_ip_hash'] ?? hash('sha256', self::ipCliente()))
            );
            $restantes = self::MAX_INTENTOS_SEGUNDO_FACTOR - (int) $_SESSION['auth_2fa_intentos'];
            if ($restantes <= 0) {
                self::cancelarSegundoFactor();
                throw new AutenticacionException('Código incorrecto. Ingresa nuevamente tu usuario y contraseña.');
            }
            throw new AutenticacionException('Código incorrecto. Te quedan ' . $restantes . ' intentos.');
        }

        self::registrarUsoCodigoTotp($conexion, $usuarioId, $contador);
        self::cancelarSegundoFactor();
        self::completarSesion($conexion, $usuario);
    }

    public static function cancelarSegundoFactor(): void
    {
        SesionEmpresa::iniciar();
        foreach (array_keys($_SESSION) as $clave) {
            if (str_starts_with((string) $clave, 'auth_2fa_')) {
                unset($_SESSION[$clave]);
            }
        }
    }

    public static function cerrarSesion(): void
    {
        SesionEmpresa::iniciar();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $parametros = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $parametros['path'],
                'domain' => $parametros['domain'],
                'secure' => $parametros['secure'],
                'httponly' => $parametros['httponly'],
                'samesite' => $parametros['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    public static function nombreActual(): string
    {
        return trim((string) ($_SESSION['auth_nombre'] ?? 'Usuario')) ?: 'Usuario';
    }

    public static function tipoActual(): string
    {
        $tipo = trim((string) ($_SESSION['auth_tipo'] ?? ''));
        return $tipo !== '' ? ucfirst(str_replace('_', ' ', $tipo)) : 'Cuenta';
    }

    public static function usuarioActualId(): int
    {
        SesionEmpresa::iniciar();
        return max(0, (int) ($_SESSION['auth_usuario_id'] ?? 0));
    }

    public static function accesoRestringidoAEmpresas(): bool
    {
        SesionEmpresa::iniciar();
        $tipo = trim((string) ($_SESSION['auth_tipo'] ?? ''));
        $tipo = function_exists('mb_strtolower') ? mb_strtolower($tipo, 'UTF-8') : strtolower($tipo);
        return $tipo === 'cliente';
    }

    public static function puedeUsarEmpresa(PDO $conexion, int $empresaId): bool
    {
        if (!self::accesoRestringidoAEmpresas()) {
            return true;
        }
        $usuarioId = self::usuarioActualId();
        if ($usuarioId <= 0 || $empresaId <= 0) {
            return false;
        }
        $consulta = $conexion->prepare(
            'SELECT 1 FROM cliente_empresa WHERE id_usuario = :usuario AND id_empresa = :empresa LIMIT 1'
        );
        $consulta->execute([':usuario' => $usuarioId, ':empresa' => $empresaId]);
        return (bool) $consulta->fetchColumn();
    }

    public static function avatarActual(): string
    {
        $imagen = trim(str_replace('\\', '/', (string) ($_SESSION['auth_imagen'] ?? '')));
        if ($imagen === '' || preg_match('#^(?:javascript|data):#i', $imagen) === 1) {
            return 'assets/images/users/avatar-1.jpg';
        }
        return $imagen;
    }

    public static function tokenLogout(): string
    {
        SesionEmpresa::iniciar();
        return (string) ($_SESSION['logout_csrf'] ??= bin2hex(random_bytes(32)));
    }

    public static function rutaSegura(string $ruta): string
    {
        $ruta = trim($ruta);
        if ($ruta === '' || str_starts_with($ruta, '//') || preg_match('/[\x00-\x1F\x7F\\\\]/', $ruta) === 1
            || preg_match('#^[a-z][a-z0-9+.-]*:#i', $ruta) === 1) {
            return '';
        }
        $partes = parse_url($ruta);
        $camino = is_array($partes) ? (string) ($partes['path'] ?? '') : '';
        if (!is_array($partes) || isset($partes['host']) || preg_match('#/(?:login|logout)\.php$#i', $camino) === 1) {
            return '';
        }
        return $ruta;
    }

    private static function verificarPassword(string $password, string $hash, string $email = ''): bool
    {
        $informacion = password_get_info($hash);
        if (!empty($informacion['algo'])) {
            return password_verify($password, $hash);
        }
        if (preg_match('/^[a-f0-9]{40}$/i', $hash) === 1) {
            $hashNormalizado = strtolower($hash);
            // Formato histórico de Sistema 14: sha1(md5(sha1(email + contraseña))).
            $hashSistema14 = sha1(md5(sha1($email . $password)));
            if (hash_equals($hashNormalizado, $hashSistema14)) {
                return true;
            }
            // Conserva compatibilidad con registros antiguos que usaban SHA-1 directo.
            return hash_equals($hashNormalizado, sha1($password));
        }
        return $hash !== '' && hash_equals($hash, $password);
    }

    private static function actualizarHashSiNecesario(PDO $conexion, int $usuarioId, string $password, string $hashActual): void
    {
        [$algoritmo, $opciones] = self::configuracionHash();
        $informacion = password_get_info($hashActual);
        if (!empty($informacion['algo']) && !password_needs_rehash($hashActual, $algoritmo, $opciones)) {
            return;
        }
        $nuevoHash = password_hash($password, $algoritmo, $opciones);
        if (!is_string($nuevoHash) || $nuevoHash === '') {
            throw new AutenticacionException('No fue posible actualizar de forma segura la contraseña.');
        }
        $actualizar = $conexion->prepare('UPDATE usuarios SET password = :nuevo WHERE id = :usuario AND password = :anterior');
        $actualizar->execute([':nuevo' => $nuevoHash, ':usuario' => $usuarioId, ':anterior' => $hashActual]);
    }

    /** @return array{0: string|int, 1: array<string, int>} */
    private static function configuracionHash(): array
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return [PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2]];
        }
        return [PASSWORD_BCRYPT, ['cost' => 12]];
    }

    private static function validarLimite(PDO $conexion, string $identificadorHash, string $ipHash): void
    {
        $desde = (new DateTimeImmutable('-' . self::VENTANA_INTENTOS_MINUTOS . ' minutes'))->format('Y-m-d H:i:s');
        $consulta = $conexion->prepare(
            'SELECT
                SUM(identificador_hash = :identificador) AS intentos_usuario,
                SUM(ip_hash = :ip) AS intentos_ip
             FROM intentos_login
             WHERE creado_en >= :desde'
        );
        $consulta->execute([':identificador' => $identificadorHash, ':ip' => $ipHash, ':desde' => $desde]);
        $intentos = $consulta->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int) ($intentos['intentos_usuario'] ?? 0) >= self::MAX_INTENTOS_USUARIO
            || (int) ($intentos['intentos_ip'] ?? 0) >= self::MAX_INTENTOS_IP) {
            throw new AutenticacionException('Demasiados intentos. Espera 15 minutos antes de volver a intentar.');
        }
    }

    private static function registrarFallo(PDO $conexion, string $identificadorHash, string $ipHash): void
    {
        $consulta = $conexion->prepare(
            'INSERT INTO intentos_login (identificador_hash, ip_hash, creado_en) VALUES (:identificador, :ip, NOW())'
        );
        $consulta->execute([':identificador' => $identificadorHash, ':ip' => $ipHash]);
        if (random_int(1, 100) === 1) {
            $conexion->exec('DELETE FROM intentos_login WHERE creado_en < DATE_SUB(NOW(), INTERVAL 2 DAY)');
        }
    }

    private static function limpiarIntentos(PDO $conexion, string $identificadorHash): void
    {
        $consulta = $conexion->prepare('DELETE FROM intentos_login WHERE identificador_hash = :identificador');
        $consulta->execute([':identificador' => $identificadorHash]);
    }

    /** @param array<string, mixed> $usuario */
    private static function prepararSegundoFactor(array $usuario, string $identificadorHash, string $ipHash): void
    {
        self::limpiarAutenticacion();
        session_regenerate_id(true);
        $_SESSION['auth_2fa_usuario_id'] = (int) $usuario['id'];
        $_SESSION['auth_2fa_inicio'] = time();
        $_SESSION['auth_2fa_intentos'] = 0;
        $_SESSION['auth_2fa_identificador_hash'] = $identificadorHash;
        $_SESSION['auth_2fa_ip_hash'] = $ipHash;
    }

    /** @param array<string, mixed> $usuario */
    private static function completarSesion(PDO $conexion, array $usuario): void
    {
        self::limpiarAutenticacion();
        session_regenerate_id(true);
        $ahora = time();
        $nombre = trim(implode(' ', array_filter([
            trim((string) ($usuario['nombre'] ?? '')),
            trim((string) ($usuario['appat'] ?? '')),
            trim((string) ($usuario['apmat'] ?? '')),
        ])));
        $_SESSION['auth_usuario_id'] = (int) $usuario['id'];
        $_SESSION['usuario_id'] = (int) $usuario['id'];
        $_SESSION['auth_nombre'] = $nombre !== ''
            ? $nombre
            : (string) (($usuario['usuario'] ?? '') ?: ($usuario['email'] ?? 'Usuario'));
        $_SESSION['auth_tipo'] = trim((string) ($usuario['tipo'] ?? ''));
        $_SESSION['auth_imagen'] = trim((string) ($usuario['imagen'] ?? ''));
        $_SESSION['auth_inicio'] = $ahora;
        $_SESSION['auth_ultima_actividad'] = $ahora;
        $_SESSION['auth_regenerada'] = $ahora;
        $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
        self::seleccionarPrimeraEmpresaAsignada($conexion, (int) $usuario['id']);
    }

    private static function validarCodigoTotp(string $secreto, string $codigo): ?int
    {
        $clave = self::decodificarBase32($secreto);
        if ($clave === null) {
            return null;
        }
        $contadorActual = intdiv(time(), 30);
        for ($desfase = -1; $desfase <= 1; $desfase++) {
            $contador = $contadorActual + $desfase;
            $alto = ($contador >> 32) & 0xFFFFFFFF;
            $bajo = $contador & 0xFFFFFFFF;
            $hash = hash_hmac('sha1', pack('N2', $alto, $bajo), $clave, true);
            $offset = ord($hash[19]) & 0x0F;
            $binario = ((ord($hash[$offset]) & 0x7F) << 24)
                | ((ord($hash[$offset + 1]) & 0xFF) << 16)
                | ((ord($hash[$offset + 2]) & 0xFF) << 8)
                | (ord($hash[$offset + 3]) & 0xFF);
            $esperado = str_pad((string) ($binario % 1000000), 6, '0', STR_PAD_LEFT);
            if (hash_equals($esperado, $codigo)) {
                return $contador;
            }
        }
        return null;
    }

    private static function decodificarBase32(string $secreto): ?string
    {
        $secreto = strtoupper(preg_replace('/[\s=-]+/', '', trim($secreto)) ?? '');
        if ($secreto === '' || preg_match('/[^A-Z2-7]/', $secreto)) {
            return null;
        }
        $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $acumulador = 0;
        $bits = 0;
        $resultado = '';
        foreach (str_split($secreto) as $caracter) {
            $valor = strpos($alfabeto, $caracter);
            if ($valor === false) {
                return null;
            }
            $acumulador = ($acumulador << 5) | $valor;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $resultado .= chr(($acumulador >> $bits) & 0xFF);
                $acumulador &= (1 << $bits) - 1;
            }
        }
        return $resultado !== '' ? $resultado : null;
    }

    private static function registrarUsoCodigoTotp(PDO $conexion, int $usuarioId, int $contador): void
    {
        $transaccionPropia = !$conexion->inTransaction();
        if ($transaccionPropia) {
            $conexion->beginTransaction();
        }
        try {
            $consulta = $conexion->prepare(
                'SELECT ultimo_contador FROM autenticacion_2fa_estado WHERE id_usuario = :usuario FOR UPDATE'
            );
            $consulta->execute([':usuario' => $usuarioId]);
            $ultimo = $consulta->fetchColumn();
            if ($ultimo !== false && $contador <= (int) $ultimo) {
                throw new AutenticacionException('Este código ya fue utilizado. Espera el siguiente código de tu aplicación.');
            }
            $guardar = $conexion->prepare(
                'INSERT INTO autenticacion_2fa_estado (id_usuario, ultimo_contador, actualizado_en)
                 VALUES (:usuario, :contador, NOW())
                 ON DUPLICATE KEY UPDATE ultimo_contador = VALUES(ultimo_contador), actualizado_en = NOW()'
            );
            $guardar->execute([':usuario' => $usuarioId, ':contador' => $contador]);
            if ($transaccionPropia) {
                $conexion->commit();
            }
        } catch (Throwable $error) {
            if ($transaccionPropia && $conexion->inTransaction()) {
                $conexion->rollBack();
            }
            throw $error;
        }
    }

    private static function seleccionarPrimeraEmpresaAsignada(PDO $conexion, int $usuarioId): void
    {
        $consulta = $conexion->prepare(
            "SELECT e.id AS empresa_id, e.razon, e.logo, cc.id AS clave_id
             FROM cliente_empresa ce
             INNER JOIN empresas e ON e.id = ce.id_empresa
             INNER JOIN claves_cortas cc
                ON cc.id = (
                    SELECT MIN(cc2.id) FROM claves_cortas cc2
                    WHERE UPPER(TRIM(cc2.rfc)) = UPPER(TRIM(e.rfc))
                )
             WHERE ce.id_usuario = :usuario
             ORDER BY ce.id
             LIMIT 1"
        );
        $consulta->execute([':usuario' => $usuarioId]);
        $empresa = $consulta->fetch(PDO::FETCH_ASSOC);
        if (is_array($empresa)) {
            SesionEmpresa::cambiar(
                (int) $empresa['empresa_id'],
                (int) $empresa['clave_id'],
                (string) $empresa['logo'],
                (string) $empresa['razon']
            );
        }
    }

    private static function asegurarEmpresaActual(PDO $conexion): bool
    {
        if (!self::accesoRestringidoAEmpresas()) {
            return true;
        }
        $usuarioId = self::usuarioActualId();
        if ($usuarioId <= 0) {
            return false;
        }

        $consulta = $conexion->prepare(
            "SELECT e.id AS empresa_id, e.razon, e.logo, cc.id AS clave_id
             FROM cliente_empresa ce
             INNER JOIN empresas e ON e.id = ce.id_empresa
             INNER JOIN claves_cortas cc
                ON cc.id = (
                    SELECT MIN(cc2.id) FROM claves_cortas cc2
                    WHERE UPPER(TRIM(cc2.rfc)) = UPPER(TRIM(e.rfc))
                )
             WHERE ce.id_usuario = :usuario
               AND e.id = :empresa
             LIMIT 1"
        );
        $consulta->execute([
            ':usuario' => $usuarioId,
            ':empresa' => SesionEmpresa::empresaActual(),
        ]);
        $empresa = $consulta->fetch(PDO::FETCH_ASSOC);

        if (!is_array($empresa)) {
            self::seleccionarPrimeraEmpresaAsignada($conexion, $usuarioId);
            $consulta->execute([
                ':usuario' => $usuarioId,
                ':empresa' => SesionEmpresa::empresaActual(),
            ]);
            $empresa = $consulta->fetch(PDO::FETCH_ASSOC);
        }
        if (!is_array($empresa)) {
            return false;
        }

        SesionEmpresa::cambiar(
            (int) $empresa['empresa_id'],
            (int) $empresa['clave_id'],
            (string) $empresa['logo'],
            (string) $empresa['razon']
        );
        return true;
    }

    private static function limpiarAutenticacion(): void
    {
        foreach (array_keys($_SESSION) as $clave) {
            if ($clave === 'usuario_id' || str_starts_with((string) $clave, 'auth_') || $clave === 'logout_csrf') {
                unset($_SESSION[$clave]);
            }
        }
    }

    private static function ipCliente(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'desconocida'), 0, 45);
    }
}
