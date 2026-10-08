# Passkeys del login

La integración conserva el acceso por contraseña y 2FA. Una passkey solo puede registrarse durante los cinco minutos posteriores a un acceso completo por contraseña (y 2FA si la cuenta lo tiene). El acceso posterior con passkey usa el mismo contexto de sesión y empresas del ERP. No modifica `usuarios.password`.

## Preparar un entorno

1. Respalda la base y ejecuta `database/migrations/20261008_crear_usuarios_passkeys.sql` en una copia de prueba. La migración solo crea `usuarios_passkeys`; no altera `usuarios`.
2. Configura `PASSKEY_HANDLE_SECRET` como variable del proceso PHP o crea `api/passkeys_secret.local.php` con `<?php return 'SECRETO';`. Ese archivo está excluido de Git y debe ejecutarse como PHP, nunca servirse como texto. Mantén el mismo secreto en los servidores que comparten esta base y no lo cambies mientras haya credenciales registradas.
3. Para `https://facturacion14.com` y `http://localhost` el origen se selecciona explícitamente en `api/passkeys_config.php`. Para otro host, configura `PASSKEY_ORIGIN` con el origen exacto, sin ruta ni barra final; usa HTTPS fuera de localhost.
4. Despliega juntos el código, la tabla y el secreto. Si falta cualquiera de ellos, el botón de passkey permanece inhabilitado y el login normal sigue disponible.

## Verificar antes de producción

- Ejecuta `php -l` en `login.php`, `api/Autenticacion.php`, `api/passkeys.php`, `api/passkeys_config.php` y `api/passkeys_crypto.php`.
- Ejecuta `php tests/verify_crypto.php`.
- Prueba contraseña válida e inválida, con y sin 2FA; registra una passkey, cierra sesión y vuelve a entrar con ella en un navegador compatible.
- Confirma que una solicitud de registro sin acceso completo y una solicitud desde otro origen se rechacen.
- Comprueba que `usuarios.password` no cambie y que `usuarios_passkeys` guarde la credencial pública. No pruebes primero en producción.

Las passkeys pueden usar huella, rostro o PIN del dispositivo. La web no recibe datos biométricos. La implementación incluida admite ES256/P-256 con attestation `none` y requiere verificación local de usuario; no tiene auditoría criptográfica independiente.
