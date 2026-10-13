-- Rollback da migration 20261013_019_presence_cutoff.sql (Parte B, F5).
-- NAO e migration de deploy. Aplicar so DEPOIS de voltar o codigo, ou para
-- trocar um corte registrado errado (D3: rollback, 019 de novo e registro
-- novo, com aprovacao).
--
-- 0. Checagem previa: se portal_presence_cutoff_bkp_019 ja existir (rollback
--    anterior), PARA antes de qualquer alteracao, com o erro 45000
--    "rollback_019 abortado: ...". Mesmo motivo dos rollbacks da 011 a 014:
--    um segundo rollback trocaria a copia pela atual. Para repetir: conferir
--    a copia, renomea-la (RENAME TABLE) e rodar de novo.
-- 0b. Com a tabela presente: copia a linha do corte (id, cutoff_date,
--    registered_by) em portal_presence_cutoff_bkp_019 (fica: apagar a mao
--    depois de conferir; guarda o login de quem registrou) e, se havia corte,
--    grava PRESENCE_CUTOFF_REMOVED (CRITICAL) no audit_log com a data.
-- 1. portal_presence_cutoff sai. Sem a tabela, a aplicacao do F5 volta ao
--    calculo antigo em todas as datas; o codigo anterior nao a le.
-- Cada passo confere information_schema antes de alterar. O arquivo inteiro
-- NAO se repete: a segunda execucao para no passo 0, de proposito.

SET time_zone = 'America/Sao_Paulo';

-- 0. Checagem previa da copia
DROP PROCEDURE IF EXISTS rollback_019_confere_copia;
DELIMITER //
CREATE PROCEDURE rollback_019_confere_copia()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_presence_cutoff_bkp_019') > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'rollback_019 abortado: ja existe portal_presence_cutoff_bkp_019 de rollback anterior. Nada foi alterado.';
    END IF;
END//
DELIMITER ;
CALL rollback_019_confere_copia();
DROP PROCEDURE rollback_019_confere_copia;

-- 0b. Copia e auditoria (so com a tabela presente)
SET @has_cutoff_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_presence_cutoff'
);
SET @copy_sql := IF(
    @has_cutoff_table > 0,
    'CREATE TABLE portal_presence_cutoff_bkp_019 AS SELECT id, cutoff_date, registered_by FROM portal_presence_cutoff',
    'SELECT 1'
);
PREPARE copy_stmt FROM @copy_sql;
EXECUTE copy_stmt;
DEALLOCATE PREPARE copy_stmt;

SET @audit_sql := IF(
    @has_cutoff_table > 0,
    'INSERT INTO audit_log (user_ip, username, action, details, severity)
     SELECT ''migration'', ''rollback_019'', ''PRESENCE_CUTOFF_REMOVED'',
            CONCAT(''019: corte do motor de presenca removido (era '', DATE_FORMAT(cutoff_date, ''%Y-%m-%d''), '')''), ''CRITICAL''
     FROM portal_presence_cutoff',
    'SELECT 1'
);
PREPARE audit_stmt FROM @audit_sql;
EXECUTE audit_stmt;
DEALLOCATE PREPARE audit_stmt;

-- 1. Tabela
SET @drop_cutoff_sql := IF(@has_cutoff_table > 0, 'DROP TABLE portal_presence_cutoff', 'SELECT 1');
PREPARE drop_cutoff_stmt FROM @drop_cutoff_sql;
EXECUTE drop_cutoff_stmt;
DEALLOCATE PREPARE drop_cutoff_stmt;
