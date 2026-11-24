-- Columna aditiva; no modifica facturas ni complementos históricos.
ALTER TABLE claves_cortas
    ADD COLUMN documentos_vivos INT UNSIGNED NOT NULL DEFAULT 0;
