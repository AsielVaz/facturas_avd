<?php

declare(strict_types=1);

final class ComplementoUuidAutorizacion
{
    public static function emitir(string $uuid, int $empresa, int $usuario): string
    {
        SesionEmpresa::iniciar();
        $_SESSION['complemento_uuid_secreto'] ??= bin2hex(random_bytes(32));
        $vence = time() + 900;
        return $vence . ':' . self::firma($uuid, $empresa, $usuario, $vence);
    }

    public static function verificar(string $token, string $uuid, int $empresa, int $usuario): bool
    {
        SesionEmpresa::iniciar();
        if (!preg_match('/^([1-9][0-9]*):([a-f0-9]{64})$/D', $token, $partes)) return false;
        $vence = (int) $partes[1];
        return $vence >= time() && $vence <= time() + 900
            && hash_equals(self::firma($uuid, $empresa, $usuario, $vence), $partes[2]);
    }

    private static function firma(string $uuid, int $empresa, int $usuario, int $vence): string
    {
        $secreto = (string) ($_SESSION['complemento_uuid_secreto'] ?? '');
        if (strlen($secreto) !== 64) return '';
        return hash_hmac('sha256', strtoupper(trim($uuid)) . '|' . $empresa . '|' . $usuario . '|' . $vence, $secreto);
    }
}
