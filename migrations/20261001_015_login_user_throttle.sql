-- ChronoDesk - limite de tentativas de login por usuario, independente de IP (SEC-03).
-- Idempotente. Numeracao: 011 a 014 estao reservadas para a Parte B
-- (docs/DESENHO_PA_ESCALA_AUSENCIA.md); esta tabela nao depende delas e pode
-- ser aplicada antes.
--
-- Uma linha por login normalizado (sAMAccountName, minusculo). Nunca guarda
-- senha. A aplicacao grava as datas como valor explicito, no fuso do PHP; a
-- tabela nao usa NOW() nem CURRENT_TIMESTAMP.
--
-- Deve ser aplicada ANTES do codigo do Lote 7: sem a tabela, o contador fica
-- indisponivel e todo login e recusado com 503 (falha fechada).
--
-- Desbloquear um usuario a mao:
--   DELETE FROM login_user_throttle WHERE login = '<login>';
-- Rollback: migrations/rollback/20261001_015_login_user_throttle_down.sql,
-- somente depois de voltar o codigo para a versao anterior.

SET time_zone = 'America/Sao_Paulo';

CREATE TABLE IF NOT EXISTS login_user_throttle (
    login           VARCHAR(100) NOT NULL,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NOT NULL,
    PRIMARY KEY (login),
    INDEX idx_login_user_throttle_last_attempt (last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
