ALTER TABLE facturas
    ADD COLUMN IF NOT EXISTS cancelado TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

UPDATE facturas
SET cancelado = 1
WHERE LOWER(COALESCE(status, '')) LIKE '%cancel%';
