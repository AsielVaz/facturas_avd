ALTER TABLE facturas
    ADD COLUMN IF NOT EXISTS retencion_isr_tasa DECIMAL(9,6) NOT NULL DEFAULT 0 AFTER retencion_transporte,
    ADD COLUMN IF NOT EXISTS retencion_iva_tasa DECIMAL(9,6) NOT NULL DEFAULT 0 AFTER retencion_isr_tasa;
