CREATE DATABASE IF NOT EXISTS gamebox
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gamebox;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    senha VARCHAR(255) NOT NULL,
    foto_perfil VARCHAR(255) NULL,
    papel ENUM('usuario','master') NOT NULL DEFAULT 'usuario',
    bloqueado TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS foto_perfil VARCHAR(255) NULL;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expira_em DATETIME NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX password_reset_usuario_criado (usuario_id, criado_em),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS jogos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    igdb_id INT UNSIGNED NULL UNIQUE,
    nome VARCHAR(255) NOT NULL,
    imagem TEXT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS biblioteca (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    jogo_id INT UNSIGNED NOT NULL,
    status ENUM('jogando','quero_jogar','zerado','abandonado') DEFAULT 'quero_jogar',
    nota DECIMAL(3,1) NULL,
    comentario TEXT NULL,
    comentario_oculto TINYINT(1) NOT NULL DEFAULT 0,
    favorito TINYINT(1) NOT NULL DEFAULT 0,
    adicionado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY usuario_jogo (usuario_id, jogo_id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (jogo_id) REFERENCES jogos(id) ON DELETE CASCADE
);

ALTER TABLE biblioteca ADD COLUMN IF NOT EXISTS favorito TINYINT(1) NOT NULL DEFAULT 0;
