CREATE TABLE IF NOT EXISTS autenticacion_2fa_estado (
    id_usuario INT(8) NOT NULL,
    ultimo_contador BIGINT UNSIGNED NOT NULL,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_usuario),
    CONSTRAINT fk_autenticacion_2fa_usuario
        FOREIGN KEY (id_usuario) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
