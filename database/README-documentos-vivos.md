# Documentos vivos y complementos de pago

## Preparación

1. Aplicar `database/migrations/20261124_agregar_documentos_vivos_claves_cortas.sql` una sola vez.
2. Configurar un token aleatorio de al menos 32 caracteres en la variable de entorno
   `DOCUMENTOS_VIVOS_CRON_TOKEN` o en `api/cron-token.local` (archivo ignorado por Git).
3. Programar una solicitud GET diaria a la URL del proyecto:
   `http://localhost/facturas_avd/api/cron-documentos-vivos.php?token=TOKEN`.
   En producción, sustituir el origen por `https://facturacion14.com`.

Ejemplo de programación en Linux: `0 2 * * * curl --fail --silent --show-error 'https://facturacion14.com/api/cron-documentos-vivos.php?token=TOKEN'`.
El token debe guardarse fuera del repositorio. La respuesta JSON indica el número de claves,
documentos vivos y filas cuyo conteo cambió. Un segundo proceso concurrente recibe HTTP 409.

El conteo de `claves_cortas.documentos_vivos` es global. El selector de complementos
consulta la disponibilidad por la empresa activa en tiempo real y vuelve a validarla
al guardar. Cambiar de empresa desde `empresas-s.php` abre el formulario con el nuevo contexto;
si cambia en otra pestaña, el formulario detecta el cambio al recuperar el foco y se recarga.

## Pruebas locales

Ejecutar con PHP CLI que tenga `pdo_mysql`, `pdo_sqlite` y `dom`:

```text
php tests/DocumentosVivosIntegracionTest.php
php tests/DocumentosVivosCronBloqueoTest.php
php tests/ComplementoMovimientoIntegracionTest.php
php tests/ComplementoTratamientoFiscalTest.php
php tests/ComplementoPagoGuardadoIntegracionTest.php
php tests/ComplementoPagoXmlGeneradorTest.php
```

Las pruebas de integración usan tablas temporales en la conexión local y no escriben
facturas, pagos ni complementos históricos.

## Esquema verificado en la copia local

- `facturas.id_clave_corta` y `facturas_complementos.id_clave_corta`: `VARCHAR(50)`.
- `pagos.nombre` y `pagos.empresa`: `VARCHAR(100)`.
- `pagos.id_factura_res`: `VARCHAR(100)`; `pagos.id_factura`: `INT`.
- `claves_cortas.documentos_vivos` no existía antes de la migración.
- `metodos_pago.id = 2` corresponde a `PPD` en esta base.
- La mayoría de facturas PPD locales no tienen `xml_firmado`, así que la herencia
  fiscal utiliza `detalle_factura` y `conceptos` como respaldo.

Los complementos creados antes de este cambio conservan su valor histórico de `cadena`.
