CREATE TABLE IF NOT EXISTS usuarios_passkeys (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_usuario INT NOT NULL,
    credential_id VARBINARY(1023) NOT NULL,
    credential_hash BINARY(32) NOT NULL,
    public_key TEXT NOT NULL,
    user_handle VARBINARY(64) NOT NULL,
    sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    transports VARCHAR(255) DEFAULT NULL,
    backup_eligible TINYINT(1) NOT NULL DEFAULT 0,
    backup_state TINYINT(1) NOT NULL DEFAULT 0,
    nombre VARCHAR(100) NOT NULL DEFAULT 'Mi dispositivo',
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_uso DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_credential_hash (credential_hash),
    KEY idx_id_usuario (id_usuario),
    CONSTRAINT fk_passkey_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
