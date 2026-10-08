-- Ejecutar una sola vez, después de verificar que la columna no exista.
-- La columna es nullable para no modificar los datos de los perfiles existentes.
ALTER TABLE claves_cortas
    ADD COLUMN correo_respuesta VARCHAR(254) NULL DEFAULT NULL;
