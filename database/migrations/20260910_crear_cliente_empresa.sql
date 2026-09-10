CREATE TABLE IF NOT EXISTS cliente_empresa (
    id INT(11) NOT NULL AUTO_INCREMENT,
    id_usuario INT(8) NOT NULL,
    id_empresa INT(8) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cliente_empresa_usuario_empresa (id_usuario, id_empresa),
    KEY idx_cliente_empresa_empresa (id_empresa),
    CONSTRAINT fk_cliente_empresa_usuario
        FOREIGN KEY (id_usuario) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_cliente_empresa_empresa
        FOREIGN KEY (id_empresa) REFERENCES empresas (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

INSERT INTO cliente_empresa (id_usuario, id_empresa)
SELECT u.id, e.id
FROM usuarios u
INNER JOIN empresas e
    ON UPPER(TRIM(e.razon)) = UPPER(TRIM(CONCAT_WS(' ', u.nombre, u.appat, u.apmat)))
ON DUPLICATE KEY UPDATE id_usuario = VALUES(id_usuario);
