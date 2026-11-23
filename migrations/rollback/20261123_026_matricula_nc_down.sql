-- Rollback da migration 20261123_026_matricula_nc.sql (NC no cadastro e a
-- copia nos lancamentos, lote NC). NAO e migration de deploy. Aplicar so
-- DEPOIS de voltar o codigo (o codigo do lote NC le e grava as colunas).
--
-- 0. Checagem previa: se QUALQUER copia *_bkp_026 ja existir (rollback
--    anterior), PARA antes de qualquer alteracao, com o erro 45000
--    "rollback_026 abortado: ...": um segundo rollback trocaria a copia pela
--    atual, ja sem os dados. Para repetir: conferir as copias, renomea-las
--    (RENAME TABLE) e rodar de novo. A checagem usa o procedimento temporario
--    rollback_026_confere_copias (SIGNAL so existe em procedimento); depois de
--    uma parada ele fica no banco ate a proxima execucao, que o recria e o
--    apaga no fim.
-- 0b. Copias, so do que existe e so das linhas com NC (cada uma confere
--    information_schema): funcionarios_nc_bkp_026 (id e NC),
--    portal_overtime_entries_nc_bkp_026 e portal_time_adjustments_nc_bkp_026
--    (id do lancamento e a copia do NC). O NC e a matricula da pessoa: as
--    copias ficam no banco, com o mesmo cuidado do backup; remover a mao
--    depois de conferir.
-- 1. Saem as tres restricoes, o indice unico e as tres colunas. Nenhuma outra
--    coluna muda. Cada passo confere information_schema antes de alterar. O
--    arquivo inteiro NAO se repete depois de uma execucao com a 026 aplicada:
--    a segunda para no passo 0, de proposito. Sem a 026, nada muda e nenhuma
--    copia e criada.

SET time_zone = 'America/Sao_Paulo';

-- 0. Checagem previa das copias
DROP PROCEDURE IF EXISTS rollback_026_confere_copias;
DELIMITER //
CREATE PROCEDURE rollback_026_confere_copias()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('funcionarios_nc_bkp_026', 'portal_overtime_entries_nc_bkp_026', 'portal_time_adjustments_nc_bkp_026')) > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'rollback_026 abortado: ja existe copia *_bkp_026 de rollback anterior. Nada foi alterado. Renomeie as copias e repita.';
    END IF;
END//
DELIMITER ;
CALL rollback_026_confere_copias();
DROP PROCEDURE rollback_026_confere_copias;

-- 0b. Copias (nenhuma existe: a checagem acima garante)
SET @has_matricula := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'matricula'
);
SET @copy_matricula_sql := IF(
    @has_matricula = 1,
    'CREATE TABLE funcionarios_nc_bkp_026 AS SELECT id, matricula FROM funcionarios WHERE matricula IS NOT NULL',
    'SELECT 1'
);
PREPARE copy_matricula_stmt FROM @copy_matricula_sql;
EXECUTE copy_matricula_stmt;
DEALLOCATE PREPARE copy_matricula_stmt;

SET @has_overtime_nc := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_overtime_entries' AND COLUMN_NAME = 'employee_nc'
);
SET @copy_overtime_nc_sql := IF(
    @has_overtime_nc = 1,
    'CREATE TABLE portal_overtime_entries_nc_bkp_026 AS SELECT id, employee_nc FROM portal_overtime_entries WHERE employee_nc IS NOT NULL',
    'SELECT 1'
);
PREPARE copy_overtime_nc_stmt FROM @copy_overtime_nc_sql;
EXECUTE copy_overtime_nc_stmt;
DEALLOCATE PREPARE copy_overtime_nc_stmt;

SET @has_adjustment_nc := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_time_adjustments' AND COLUMN_NAME = 'employee_nc'
);
SET @copy_adjustment_nc_sql := IF(
    @has_adjustment_nc = 1,
    'CREATE TABLE portal_time_adjustments_nc_bkp_026 AS SELECT id, employee_nc FROM portal_time_adjustments WHERE employee_nc IS NOT NULL',
    'SELECT 1'
);
PREPARE copy_adjustment_nc_stmt FROM @copy_adjustment_nc_sql;
EXECUTE copy_adjustment_nc_stmt;
DEALLOCATE PREPARE copy_adjustment_nc_stmt;

-- 1. Restricoes, indice e colunas
SET @has_adjustment_nc_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_time_adjustments'
      AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'ck_time_adjustment_employee_nc'
);
SET @drop_adjustment_nc_check_sql := IF(@has_adjustment_nc_check > 0, 'ALTER TABLE portal_time_adjustments DROP CHECK ck_time_adjustment_employee_nc', 'SELECT 1');
PREPARE drop_adjustment_nc_check_stmt FROM @drop_adjustment_nc_check_sql;
EXECUTE drop_adjustment_nc_check_stmt;
DEALLOCATE PREPARE drop_adjustment_nc_check_stmt;

SET @has_adjustment_nc := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_time_adjustments' AND COLUMN_NAME = 'employee_nc'
);
SET @drop_adjustment_nc_sql := IF(@has_adjustment_nc > 0, 'ALTER TABLE portal_time_adjustments DROP COLUMN employee_nc', 'SELECT 1');
PREPARE drop_adjustment_nc_stmt FROM @drop_adjustment_nc_sql;
EXECUTE drop_adjustment_nc_stmt;
DEALLOCATE PREPARE drop_adjustment_nc_stmt;

SET @has_overtime_nc_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_overtime_entries'
      AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'ck_overtime_employee_nc'
);
SET @drop_overtime_nc_check_sql := IF(@has_overtime_nc_check > 0, 'ALTER TABLE portal_overtime_entries DROP CHECK ck_overtime_employee_nc', 'SELECT 1');
PREPARE drop_overtime_nc_check_stmt FROM @drop_overtime_nc_check_sql;
EXECUTE drop_overtime_nc_check_stmt;
DEALLOCATE PREPARE drop_overtime_nc_check_stmt;

SET @has_overtime_nc := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_overtime_entries' AND COLUMN_NAME = 'employee_nc'
);
SET @drop_overtime_nc_sql := IF(@has_overtime_nc > 0, 'ALTER TABLE portal_overtime_entries DROP COLUMN employee_nc', 'SELECT 1');
PREPARE drop_overtime_nc_stmt FROM @drop_overtime_nc_sql;
EXECUTE drop_overtime_nc_stmt;
DEALLOCATE PREPARE drop_overtime_nc_stmt;

SET @has_matricula_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios'
      AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'ck_funcionarios_matricula'
);
SET @drop_matricula_check_sql := IF(@has_matricula_check > 0, 'ALTER TABLE funcionarios DROP CHECK ck_funcionarios_matricula', 'SELECT 1');
PREPARE drop_matricula_check_stmt FROM @drop_matricula_check_sql;
EXECUTE drop_matricula_check_stmt;
DEALLOCATE PREPARE drop_matricula_check_stmt;

SET @has_matricula_unique := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND INDEX_NAME = 'uq_funcionarios_matricula'
);
SET @drop_matricula_unique_sql := IF(@has_matricula_unique > 0, 'ALTER TABLE funcionarios DROP INDEX uq_funcionarios_matricula', 'SELECT 1');
PREPARE drop_matricula_unique_stmt FROM @drop_matricula_unique_sql;
EXECUTE drop_matricula_unique_stmt;
DEALLOCATE PREPARE drop_matricula_unique_stmt;

SET @has_matricula := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'matricula'
);
SET @drop_matricula_sql := IF(@has_matricula > 0, 'ALTER TABLE funcionarios DROP COLUMN matricula', 'SELECT 1');
PREPARE drop_matricula_stmt FROM @drop_matricula_sql;
EXECUTE drop_matricula_stmt;
DEALLOCATE PREPARE drop_matricula_stmt;
