-- Rollback da migration 20261007_012_absences.sql (Parte B, F3).
-- NAO e migration de deploy. Aplicar so DEPOIS de voltar o codigo (o codigo
-- do F3 le category, period_type e as colunas de autoria).
--
-- 0. Checagem previa: se portal_absences_bkp_012 ja existir (rollback
--    anterior), PARA antes de qualquer alteracao, com o erro 45000
--    "rollback_012 abortado: ...". Mesmo motivo do rollback da 011: um segundo
--    rollback apagaria o que foi gravado depois do primeiro e a copia seria a
--    antiga. Para repetir: conferir a copia, renomea-la (RENAME TABLE) e rodar
--    de novo.
-- 0b. Copia de todas as linhas e colunas em portal_absences_bkp_012 (fica:
--    apagar a mao depois de conferir).
-- 1. CHECKs, FK e indice novos saem.
-- 2. absence_type volta, preenchida a partir de category: folga -> folga,
--    ferias -> ferias, demais -> justificada (o valor 'atestado' nunca e
--    recriado). NOT NULL depois de preencher, como era.
-- 3. status: cancelled vira rejected e o ENUM volta a
--    ('pending','approved','rejected'), padrao pending.
-- 4. Colunas novas saem. Ausencia parcial (a partir de / intervalo) vira, no
--    codigo anterior, ausencia do dia inteiro (ele nao conhece horario).
-- Os passos 1 a 4 conferem information_schema antes de alterar. O arquivo
-- inteiro NAO se repete: a segunda execucao para no passo 0, de proposito.

SET time_zone = 'America/Sao_Paulo';

-- 0. Checagem previa da copia
DROP PROCEDURE IF EXISTS rollback_012_confere_copia;
DELIMITER //
CREATE PROCEDURE rollback_012_confere_copia()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences_bkp_012') > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'rollback_012 abortado: ja existe portal_absences_bkp_012 de rollback anterior. Nada foi alterado. Renomeie a copia e repita.';
    END IF;
END//
DELIMITER ;
CALL rollback_012_confere_copia();
DROP PROCEDURE rollback_012_confere_copia;

-- 0b. Copia (nao existe: a checagem acima garante)
CREATE TABLE portal_absences_bkp_012 AS SELECT * FROM portal_absences;

-- 1. CHECKs, FK e indice
SET @has_period_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences'
      AND CONSTRAINT_NAME = 'ck_absence_period' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @drop_period_check_sql := IF(@has_period_check > 0, 'ALTER TABLE portal_absences DROP CHECK ck_absence_period', 'SELECT 1');
PREPARE drop_period_check_stmt FROM @drop_period_check_sql;
EXECUTE drop_period_check_stmt;
DEALLOCATE PREPARE drop_period_check_stmt;

SET @has_range_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences'
      AND CONSTRAINT_NAME = 'ck_absence_range' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @drop_range_check_sql := IF(@has_range_check > 0, 'ALTER TABLE portal_absences DROP CHECK ck_absence_range', 'SELECT 1');
PREPARE drop_range_check_stmt FROM @drop_range_check_sql;
EXECUTE drop_range_check_stmt;
DEALLOCATE PREPARE drop_range_check_stmt;

SET @has_absence_fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences'
      AND CONSTRAINT_NAME = 'fk_absence_employee' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @drop_absence_fk_sql := IF(@has_absence_fk > 0, 'ALTER TABLE portal_absences DROP FOREIGN KEY fk_absence_employee', 'SELECT 1');
PREPARE drop_absence_fk_stmt FROM @drop_absence_fk_sql;
EXECUTE drop_absence_fk_stmt;
DEALLOCATE PREPARE drop_absence_fk_stmt;

SET @has_range_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND INDEX_NAME = 'idx_absence_employee_range'
);
SET @drop_range_index_sql := IF(@has_range_index > 0, 'ALTER TABLE portal_absences DROP INDEX idx_absence_employee_range', 'SELECT 1');
PREPARE drop_range_index_stmt FROM @drop_range_index_sql;
EXECUTE drop_range_index_stmt;
DEALLOCATE PREPARE drop_range_index_stmt;

-- 2. absence_type volta, preenchida a partir de category
SET @has_absence_type := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'absence_type'
);
SET @add_absence_type_sql := IF(
    @has_absence_type = 0,
    'ALTER TABLE portal_absences
        ADD COLUMN absence_type ENUM(''ferias'', ''atestado'', ''treinamento'', ''folga'', ''justificada'') NULL AFTER employee_name',
    'SELECT 1'
);
PREPARE add_absence_type_stmt FROM @add_absence_type_sql;
EXECUTE add_absence_type_stmt;
DEALLOCATE PREPARE add_absence_type_stmt;

SET @has_category := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'category'
);
SET @fill_absence_type_sql := IF(
    @has_category > 0,
    'UPDATE portal_absences
        SET absence_type = CASE category
            WHEN ''folga'' THEN ''folga''
            WHEN ''ferias'' THEN ''ferias''
            ELSE ''justificada''
        END
      WHERE absence_type IS NULL',
    'SELECT 1'
);
PREPARE fill_absence_type_stmt FROM @fill_absence_type_sql;
EXECUTE fill_absence_type_stmt;
DEALLOCATE PREPARE fill_absence_type_stmt;

SET @absence_type_nullable := (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'absence_type'
);
SET @absence_type_not_null_sql := IF(
    @absence_type_nullable = 'YES',
    'ALTER TABLE portal_absences
        MODIFY COLUMN absence_type ENUM(''ferias'', ''atestado'', ''treinamento'', ''folga'', ''justificada'') NOT NULL',
    'SELECT 1'
);
PREPARE absence_type_not_null_stmt FROM @absence_type_not_null_sql;
EXECUTE absence_type_not_null_stmt;
DEALLOCATE PREPARE absence_type_not_null_stmt;

-- 3. status: cancelled vira rejected antes de o ENUM encolher
SET @status_type := (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'status'
);
SET @widen_status_sql := IF(
    @status_type IS NOT NULL AND @status_type <> 'enum(''pending'',''approved'',''rejected'')',
    'ALTER TABLE portal_absences
        MODIFY COLUMN status ENUM(''pending'', ''approved'', ''rejected'', ''cancelled'') NOT NULL DEFAULT ''pending''',
    'SELECT 1'
);
PREPARE widen_status_stmt FROM @widen_status_sql;
EXECUTE widen_status_stmt;
DEALLOCATE PREPARE widen_status_stmt;

SET @status_type := (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'status'
);
SET @cancelled_to_rejected_sql := IF(
    LOCATE('''cancelled''', @status_type) > 0,
    'UPDATE portal_absences SET status = ''rejected'' WHERE status = ''cancelled''',
    'SELECT 1'
);
PREPARE cancelled_to_rejected_stmt FROM @cancelled_to_rejected_sql;
EXECUTE cancelled_to_rejected_stmt;
DEALLOCATE PREPARE cancelled_to_rejected_stmt;

SET @shrink_status_sql := IF(
    LOCATE('''cancelled''', @status_type) > 0,
    'ALTER TABLE portal_absences
        MODIFY COLUMN status ENUM(''pending'', ''approved'', ''rejected'') NOT NULL DEFAULT ''pending''',
    'SELECT 1'
);
PREPARE shrink_status_stmt FROM @shrink_status_sql;
EXECUTE shrink_status_stmt;
DEALLOCATE PREPARE shrink_status_stmt;

-- 4. Colunas novas saem
SET @drop_columns_sql := (
    SELECT IF(
        COUNT(*) = 0,
        'SELECT 1',
        CONCAT('ALTER TABLE portal_absences ', GROUP_CONCAT(CONCAT('DROP COLUMN ', COLUMN_NAME) ORDER BY COLUMN_NAME SEPARATOR ', '))
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences'
      AND COLUMN_NAME IN ('team', 'category', 'period_type', 'start_time', 'end_time', 'retroactive',
                          'created_by', 'updated_by', 'cancelled_by', 'cancelled_at')
);
PREPARE drop_columns_stmt FROM @drop_columns_sql;
EXECUTE drop_columns_stmt;
DEALLOCATE PREPARE drop_columns_stmt;
