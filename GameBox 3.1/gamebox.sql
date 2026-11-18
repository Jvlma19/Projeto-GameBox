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
