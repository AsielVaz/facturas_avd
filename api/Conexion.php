<?php

declare(strict_types=1);

final class Conexion
{
    private static ?PDO $instancia = null;

    public static function obtener(): PDO
    {
        if (self::$instancia instanceof PDO) {
            return self::$instancia;
        }

        $configuracion = self::leerEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');
        foreach (['BD_HOST', 'BD_NAME', 'BD_USR_NAME', 'BD_USR_PASSWD'] as $clave) {
            if (!array_key_exists($clave, $configuracion)) {
                throw new RuntimeException("Falta la variable {$clave} en el archivo .env");
            }
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $configuracion['BD_HOST'],
            $configuracion['BD_NAME']
        );

        self::$instancia = new PDO(
            $dsn,
            $configuracion['BD_USR_NAME'],
            $configuracion['BD_USR_PASSWD'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        return self::$instancia;
    }

    /** @return array<string, string> */
    private static function leerEnv(string $ruta): array
    {
        if (!is_readable($ruta)) {
            throw new RuntimeException('No fue posible leer el archivo .env');
        }

        $variables = [];
        foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            $linea = trim($linea);
            if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) {
                continue;
            }

            [$clave, $valor] = array_map('trim', explode('=', $linea, 2));
            if ($clave === '') {
                continue;
            }

            if (strlen($valor) >= 2) {
                $primero = $valor[0];
                $ultimo = $valor[strlen($valor) - 1];
                if (($primero === '"' && $ultimo === '"') || ($primero === "'" && $ultimo === "'")) {
                    $valor = substr($valor, 1, -1);
                }
            }
            $variables[$clave] = $valor;
        }

        return $variables;
    }
}
