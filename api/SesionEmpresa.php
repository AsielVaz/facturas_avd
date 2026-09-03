<?php

declare(strict_types=1);

final class SesionEmpresa
{
    // Cambia solamente estos dos valores para establecer otra empresa por defecto.
    public const EMPRESA_POR_DEFECTO = 108;
    public const CLAVE_POR_DEFECTO = 952;
    public const URL_BASE_LOGOS = 'https://sistema14.com/app';

    public static function iniciar(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (PHP_SAPI !== 'cli' && !headers_sent()) {
                session_set_cookie_params([
                    'httponly' => true,
                    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                    'samesite' => 'Lax',
                ]);
            }
            session_start();
        }

        $_SESSION['empresa_actual'] ??= self::EMPRESA_POR_DEFECTO;
        $_SESSION['clave_actual'] ??= self::CLAVE_POR_DEFECTO;
    }

    public static function empresaActual(): int
    {
        self::iniciar();
        return max(1, (int) $_SESSION['empresa_actual']);
    }

    public static function claveActual(): int
    {
        self::iniciar();
        return max(1, (int) $_SESSION['clave_actual']);
    }

    public static function logoActual(): string
    {
        self::iniciar();
        return (string) ($_SESSION['logo_actual'] ?? 'assets/images/logo-sm.png');
    }

    public static function nombreActual(): string
    {
        self::iniciar();
        return (string) ($_SESSION['empresa_actual_nombre'] ?? ('Empresa #' . self::empresaActual()));
    }

    public static function cambiar(int $empresa, int $clave, ?string $logo = null, ?string $nombre = null): void
    {
        self::iniciar();
        $_SESSION['empresa_actual'] = max(1, $empresa);
        $_SESSION['clave_actual'] = max(1, $clave);
        if ($logo !== null) {
            $_SESSION['logo_actual'] = self::construirUrlLogo($logo);
        } else {
            unset($_SESSION['logo_actual']);
        }
        if ($nombre !== null && trim($nombre) !== '') {
            $_SESSION['empresa_actual_nombre'] = trim($nombre);
        } else {
            unset($_SESSION['empresa_actual_nombre']);
        }
    }

    public static function sincronizarContexto(PDO $conexion): void
    {
        self::iniciar();
        if (!empty($_SESSION['logo_actual']) && !empty($_SESSION['empresa_actual_nombre'])) {
            return;
        }

        $consulta = $conexion->prepare('SELECT razon, logo FROM empresas WHERE id = :empresa LIMIT 1');
        $consulta->bindValue(':empresa', self::empresaActual(), PDO::PARAM_INT);
        $consulta->execute();
        $empresa = $consulta->fetch(PDO::FETCH_ASSOC) ?: [];
        $_SESSION['empresa_actual_nombre'] = trim((string) ($empresa['razon'] ?? '')) ?: ('Empresa #' . self::empresaActual());
        $_SESSION['logo_actual'] = self::construirUrlLogo((string) ($empresa['logo'] ?? ''));
    }

    public static function construirUrlLogo(string $ruta): string
    {
        $ruta = trim(str_replace('\\', '/', $ruta));
        if ($ruta === '') {
            return 'assets/images/logo-sm.png';
        }
        if (preg_match('#^https?://#i', $ruta) === 1) {
            return $ruta;
        }
        $ruta = preg_replace('#/+#', '/', $ruta) ?: $ruta;
        return rtrim(self::URL_BASE_LOGOS, '/') . '/' . ltrim($ruta, '/');
    }
}
