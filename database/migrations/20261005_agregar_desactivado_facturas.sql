ALTER TABLE facturas
    ADD COLUMN IF NOT EXISTS desactivado TINYINT(1) NULL DEFAULT NULL AFTER cancelado;

ALTER TABLE facturas
    MODIFY COLUMN desactivado TINYINT(1) NULL DEFAULT NULL;

UPDATE facturas SET desactivado = NULL WHERE desactivado = 0;
