ALTER TABLE usuarios
    MODIFY COLUMN password VARCHAR(255) NULL;

CREATE TABLE IF NOT EXISTS intentos_login (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identificador_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_intentos_login_identificador_fecha (identificador_hash, creado_en),
    KEY idx_intentos_login_ip_fecha (ip_hash, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
