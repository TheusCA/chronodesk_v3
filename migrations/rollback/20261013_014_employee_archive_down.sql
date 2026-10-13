-- Rollback da migration 20261013_014_employee_archive.sql (Parte B, F4).
-- NAO e migration de deploy. Aplicar so DEPOIS de voltar o codigo (o codigo
-- do F4 le e grava arquivado_em e arquivado_por).
--
-- 0. Checagem previa: se funcionarios_bkp_014 ja existir (rollback anterior),
--    PARA antes de qualquer alteracao, com o erro 45000
--    "rollback_014 abortado: ...". Mesmo motivo do rollback da 011 e da 012:
--    um segundo rollback trocaria a copia pela atual, ja sem as datas. Para
--    repetir: conferir a copia, renomea-la (RENAME TABLE) e rodar de novo.
-- 0b. Copia de id, ativo, arquivado_em e arquivado_por de TODOS os cadastros
--    em funcionarios_bkp_014 (so essas colunas: nome e login nao sao
--    copiados). Sem as colunas da 014, a copia leva so id e ativo. Fica:
--    apagar a mao depois de conferir.
-- 1. CHECK ck_funcionario_arquivado sai (antes das colunas: o MySQL nao
--    remove coluna usada por CHECK).
-- 2. arquivado_em e arquivado_por saem.
-- Nenhuma linha muda: quem foi arquivado pelo F4 continua com ativo = 0, que
-- o codigo anterior trata como inativo. A data e o autor ficam na copia e na
-- auditoria (FUNCIONARIO_ARQUIVADO).
-- Os passos 1 e 2 conferem information_schema antes de alterar. O arquivo
-- inteiro NAO se repete: a segunda execucao para no passo 0, de proposito.

SET time_zone = 'America/Sao_Paulo';

-- 0. Checagem previa da copia
DROP PROCEDURE IF EXISTS rollback_014_confere_copia;
DELIMITER //
CREATE PROCEDURE rollback_014_confere_copia()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios_bkp_014') > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'rollback_014 abortado: ja existe funcionarios_bkp_014 de rollback anterior. Nada foi alterado. Renomeie a copia.';
    END IF;
END//
DELIMITER ;
CALL rollback_014_confere_copia();
DROP PROCEDURE rollback_014_confere_copia;

-- 0b. Copia (nao existe: a checagem acima garante)
SET @has_014_columns := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios'
      AND COLUMN_NAME IN ('arquivado_em', 'arquivado_por')
);
SET @copy_sql := IF(
    @has_014_columns = 2,
    'CREATE TABLE funcionarios_bkp_014 AS SELECT id, ativo, arquivado_em, arquivado_por FROM funcionarios',
    'CREATE TABLE funcionarios_bkp_014 AS SELECT id, ativo FROM funcionarios'
);
PREPARE copy_stmt FROM @copy_sql;
EXECUTE copy_stmt;
DEALLOCATE PREPARE copy_stmt;

-- 1. CHECK
SET @has_archive_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios'
      AND CONSTRAINT_NAME = 'ck_funcionario_arquivado' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @drop_archive_check_sql := IF(@has_archive_check > 0, 'ALTER TABLE funcionarios DROP CHECK ck_funcionario_arquivado', 'SELECT 1');
PREPARE drop_archive_check_stmt FROM @drop_archive_check_sql;
EXECUTE drop_archive_check_stmt;
DEALLOCATE PREPARE drop_archive_check_stmt;

-- 2. Colunas
SET @has_archived_by := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'arquivado_por'
);
SET @drop_archived_by_sql := IF(@has_archived_by > 0, 'ALTER TABLE funcionarios DROP COLUMN arquivado_por', 'SELECT 1');
PREPARE drop_archived_by_stmt FROM @drop_archived_by_sql;
EXECUTE drop_archived_by_stmt;
DEALLOCATE PREPARE drop_archived_by_stmt;

SET @has_archived_at := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'arquivado_em'
);
SET @drop_archived_at_sql := IF(@has_archived_at > 0, 'ALTER TABLE funcionarios DROP COLUMN arquivado_em', 'SELECT 1');
PREPARE drop_archived_at_stmt FROM @drop_archived_at_sql;
EXECUTE drop_archived_at_stmt;
DEALLOCATE PREPARE drop_archived_at_stmt;
