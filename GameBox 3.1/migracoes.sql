-- Migracao aditiva para instalacoes existentes.
-- Faca backup antes de executar. Nenhuma tabela, coluna ou indice legado e removido.
-- Se houver dados duplicados/orfaos ou tipos incompatíveis, a migracao deve parar
-- para que os dados sejam revisados, em vez de apaga-los ou altera-los automaticamente.

CREATE DATABASE IF NOT EXISTS gamebox
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE gamebox;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(50) NOT NULL,
    email VARCHAR(150) NOT NULL,
    senha VARCHAR(255) NOT NULL,
    foto_perfil VARCHAR(255) NULL,
    papel ENUM('usuario', 'master') NOT NULL DEFAULT 'usuario',
    bloqueado TINYINT(1) NOT NULL DEFAULT 0,
    dois_fatores_segredo VARCHAR(64) NULL,
    dois_fatores_ativo TINYINT(1) NOT NULL DEFAULT 0,
    dois_fatores_versao INT UNSIGNED NOT NULL DEFAULT 0,
    nome_normalizado VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
        GENERATED ALWAYS AS (LOWER(TRIM(nome))) STORED,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_nome (nome),
    UNIQUE KEY uq_usuarios_nome_normalizado (nome_normalizado),
    UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jogos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    igdb_id INT UNSIGNED NULL,
    nome VARCHAR(255) NOT NULL,
    imagem TEXT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_jogos_igdb_id (igdb_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS biblioteca (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT UNSIGNED NOT NULL,
    jogo_id INT UNSIGNED NOT NULL,
    status ENUM('jogando', 'quero_jogar', 'zerado', 'abandonado') NOT NULL DEFAULT 'quero_jogar',
    nota DECIMAL(3,1) NULL,
    comentario TEXT NULL,
    comentario_oculto TINYINT(1) NOT NULL DEFAULT 0,
    favorito TINYINT(1) NOT NULL DEFAULT 0,
    adicionado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_biblioteca_usuario_jogo (usuario_id, jogo_id),
    KEY idx_biblioteca_jogo (jogo_id),
    KEY idx_biblioteca_usuario_data (usuario_id, adicionado_em),
    CONSTRAINT fk_biblioteca_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE,
    CONSTRAINT fk_biblioteca_jogo
        FOREIGN KEY (jogo_id) REFERENCES jogos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expira_em DATETIME NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_reset_token_hash (token_hash),
    KEY idx_password_reset_usuario_criado (usuario_id, criado_em),
    CONSTRAINT fk_password_reset_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempt_account_ip_time (account_hash, ip_address, attempted_at),
    KEY idx_login_attempt_ip_time (ip_address, attempted_at),
    KEY idx_login_attempt_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS two_factor_challenges (
    usuario_id INT UNSIGNED NOT NULL,
    challenge_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    code_hash VARCHAR(255) NULL,
    purpose ENUM('login', 'activation') NULL,
    created_at DATETIME NULL,
    expires_at DATETIME NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_sent_at DATETIME NULL,
    request_window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sends_in_window TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (usuario_id),
    KEY idx_two_factor_expires (expires_at),
    CONSTRAINT fk_two_factor_challenge_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A coluna gerada torna nomes equivalentes por caixa e espaços externos únicos.
SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'nome_normalizado') = 0,
    'ALTER TABLE usuarios ADD COLUMN nome_normalizado VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (LOWER(TRIM(nome))) STORED',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

-- Adiciona somente colunas que ainda nao existem; funciona em MySQL e MariaDB.
SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'foto_perfil') = 0,
    'ALTER TABLE usuarios ADD COLUMN foto_perfil VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'papel') = 0,
    'ALTER TABLE usuarios ADD COLUMN papel ENUM(''usuario'', ''master'') NOT NULL DEFAULT ''usuario''',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'bloqueado') = 0,
    'ALTER TABLE usuarios ADD COLUMN bloqueado TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'dois_fatores_segredo') = 0,
    'ALTER TABLE usuarios ADD COLUMN dois_fatores_segredo VARCHAR(64) NULL',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'dois_fatores_ativo') = 0,
    'ALTER TABLE usuarios ADD COLUMN dois_fatores_ativo TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'dois_fatores_versao') = 0,
    'ALTER TABLE usuarios ADD COLUMN dois_fatores_versao INT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'criado_em') = 0,
    'ALTER TABLE usuarios ADD COLUMN criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'jogos' AND column_name = 'igdb_id') = 0,
    'ALTER TABLE jogos ADD COLUMN igdb_id INT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'jogos' AND column_name = 'criado_em') = 0,
    'ALTER TABLE jogos ADD COLUMN criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'biblioteca' AND column_name = 'comentario_oculto') = 0,
    'ALTER TABLE biblioteca ADD COLUMN comentario_oculto TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'biblioteca' AND column_name = 'favorito') = 0,
    'ALTER TABLE biblioteca ADD COLUMN favorito TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'biblioteca' AND column_name = 'adicionado_em') = 0,
    'ALTER TABLE biblioteca ADD COLUMN adicionado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'password_reset_tokens' AND column_name = 'usuario_id') = 0,
    'ALTER TABLE password_reset_tokens ADD COLUMN usuario_id INT UNSIGNED NOT NULL',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'password_reset_tokens' AND column_name = 'token_hash') = 0,
    'ALTER TABLE password_reset_tokens ADD COLUMN token_hash CHAR(64) NOT NULL',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'password_reset_tokens' AND column_name = 'expira_em') = 0,
    'ALTER TABLE password_reset_tokens ADD COLUMN expira_em DATETIME NOT NULL',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'password_reset_tokens' AND column_name = 'criado_em') = 0,
    'ALTER TABLE password_reset_tokens ADD COLUMN criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

-- Garante os indices usados por cadastro, upsert, favoritos, diario e reset de senha.
SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM (
        SELECT index_name FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND non_unique = 0
        GROUP BY index_name
        HAVING COUNT(*) = 1 AND MAX(column_name) = 'nome' AND MAX(sub_part) IS NULL
    ) AS nome_indices) = 0,
    'ALTER TABLE usuarios ADD UNIQUE KEY uq_usuarios_nome (nome)',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

-- Se este indice falhar por duplicidade normalizada, revise os ids antes de repetir:
-- SELECT nome_normalizado, GROUP_CONCAT(id ORDER BY id) FROM usuarios GROUP BY nome_normalizado HAVING COUNT(*) > 1;
SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'usuarios'
       AND index_name = 'uq_usuarios_nome_normalizado' AND non_unique = 0) = 0,
    'ALTER TABLE usuarios ADD UNIQUE KEY uq_usuarios_nome_normalizado (nome_normalizado)',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM (
        SELECT index_name FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND non_unique = 0
        GROUP BY index_name
        HAVING COUNT(*) = 1 AND MAX(column_name) = 'email' AND MAX(sub_part) IS NULL
    ) AS email_indices) = 0,
    'ALTER TABLE usuarios ADD UNIQUE KEY uq_usuarios_email (email)',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM (
        SELECT index_name FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'jogos' AND non_unique = 0
        GROUP BY index_name
        HAVING COUNT(*) = 1 AND MAX(column_name) = 'igdb_id' AND MAX(sub_part) IS NULL
    ) AS igdb_indices) = 0,
    'ALTER TABLE jogos ADD UNIQUE KEY uq_jogos_igdb_id (igdb_id)',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM (
        SELECT index_name FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'biblioteca' AND non_unique = 0
        GROUP BY index_name
        HAVING COUNT(*) = 2
           AND MAX(CASE WHEN seq_in_index = 1 THEN column_name END) = 'usuario_id'
           AND MAX(CASE WHEN seq_in_index = 2 THEN column_name END) = 'jogo_id'
           AND MAX(sub_part) IS NULL
    ) AS biblioteca_unique_indices) = 0,
    'ALTER TABLE biblioteca ADD UNIQUE KEY uq_biblioteca_usuario_jogo (usuario_id, jogo_id)',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'biblioteca' AND column_name = 'jogo_id' AND seq_in_index = 1) = 0,
    'ALTER TABLE biblioteca ADD KEY idx_biblioteca_jogo (jogo_id)',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'biblioteca'
       AND index_name = 'idx_biblioteca_usuario_data') = 0,
    'ALTER TABLE biblioteca ADD KEY idx_biblioteca_usuario_data (usuario_id, adicionado_em)',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM (
        SELECT index_name FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'password_reset_tokens' AND non_unique = 0
        GROUP BY index_name
        HAVING COUNT(*) = 1 AND MAX(column_name) = 'token_hash' AND MAX(sub_part) IS NULL
    ) AS reset_token_indices) = 0,
    'ALTER TABLE password_reset_tokens ADD UNIQUE KEY uq_password_reset_token_hash (token_hash)',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'password_reset_tokens'
       AND index_name = 'idx_password_reset_usuario_criado') = 0,
    'ALTER TABLE password_reset_tokens ADD KEY idx_password_reset_usuario_criado (usuario_id, criado_em)',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

-- Cria chaves estrangeiras somente quando ainda nao ha uma equivalente.
SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.key_column_usage
     WHERE constraint_schema = DATABASE() AND table_name = 'biblioteca'
       AND column_name = 'usuario_id' AND referenced_table_schema = DATABASE()
       AND referenced_table_name = 'usuarios' AND referenced_column_name = 'id') = 0,
    'ALTER TABLE biblioteca ADD CONSTRAINT fk_biblioteca_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.key_column_usage
     WHERE constraint_schema = DATABASE() AND table_name = 'biblioteca'
       AND column_name = 'jogo_id' AND referenced_table_schema = DATABASE()
       AND referenced_table_name = 'jogos' AND referenced_column_name = 'id') = 0,
    'ALTER TABLE biblioteca ADD CONSTRAINT fk_biblioteca_jogo FOREIGN KEY (jogo_id) REFERENCES jogos (id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;

SET @gamebox_sql = IF(
    (SELECT COUNT(*) FROM information_schema.key_column_usage
     WHERE constraint_schema = DATABASE() AND table_name = 'password_reset_tokens'
       AND column_name = 'usuario_id' AND referenced_table_schema = DATABASE()
       AND referenced_table_name = 'usuarios' AND referenced_column_name = 'id') = 0,
    'ALTER TABLE password_reset_tokens ADD CONSTRAINT fk_password_reset_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE gamebox_stmt FROM @gamebox_sql;
EXECUTE gamebox_stmt;
DEALLOCATE PREPARE gamebox_stmt;
