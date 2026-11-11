USE gamebox;

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS dois_fatores_segredo VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS dois_fatores_ativo TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS papel ENUM('usuario','master') NOT NULL DEFAULT 'usuario',
    ADD COLUMN IF NOT EXISTS bloqueado TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE biblioteca
    ADD COLUMN IF NOT EXISTS comentario_oculto TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expira_em DATETIME NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX password_reset_usuario_criado (usuario_id, criado_em),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

ALTER TABLE jogos ADD COLUMN IF NOT EXISTS igdb_id INT UNSIGNED NULL UNIQUE;

SET @drop_rawg_index = (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE jogos DROP INDEX rawg_id',
        'SELECT 1'
    )
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'jogos'
      AND index_name = 'rawg_id'
);
PREPARE drop_rawg_statement FROM @drop_rawg_index;
EXECUTE drop_rawg_statement;
DEALLOCATE PREPARE drop_rawg_statement;
