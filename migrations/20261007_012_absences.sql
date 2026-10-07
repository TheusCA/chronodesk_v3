-- ChronoDesk - ausencias do RF-04 (Parte B, F3; desenho, secoes 2.3 e 9.4).
-- Plano aprovado em 2026-10-07 (P8, decisoes 1 e 8; migration 013 dispensada).
--
-- Premissa: portal_absences VAZIA (Q3 de 2026-10-07: 0 linhas; nenhum codigo
-- grava nela antes do F3). A premissa e conferida aqui, nao suposta:
-- 0. Se a coluna antiga absence_type ainda existe E ha qualquer linha, PARA
--    com SIGNAL 45000 "migration_012 abortada: ..." antes de alterar. O
--    cliente mysql em lote para no primeiro erro. Com a 012 ja aplicada
--    (absence_type nao existe mais), a checagem nao se aplica e a migration
--    repete sem mudar nada (idempotente).
-- 1. Colunas novas: team (n1, n2, lideranca), category (folga, ferias,
--    ausencia_justificada, compromisso, outro), period_type, start_time,
--    end_time, retroactive, created_by, updated_by, cancelled_by,
--    cancelled_at. NOT NULL onde o servico sempre grava (a tabela esta vazia).
-- 2. absence_type sai (com o valor 'atestado'; P8, D4). Sem mapeamento.
-- 3. status passa a ENUM('approved','cancelled'), padrao approved (pending e
--    rejected so existiam para linhas legadas, que nao ha).
-- 4. Indice (employee_id, status, starts_on, ends_on) e FK fk_absence_employee
--    para funcionarios(id), ON DELETE RESTRICT (0 orfas).
-- 5. CHECK ck_absence_range (fim >= inicio) e ck_absence_period (horario so
--    com um dia; from_time com inicio e sem fim; interval com inicio < fim;
--    full_day sem horario). A sobreposicao (P6) fica no servico, com o
--    colaborador travado.
-- A observacao continua na coluna reason (500); na API, "note". approved_by
-- fica como esta (sem uso).
--
-- Aplicar ANTES do git pull do F3. O codigo anterior ao F3 le absence_type na
-- lista generica de /ausencias: entre a migration e o git pull essa tela
-- responde 503 ("A base do portal ainda nao esta disponivel"); o resto nao usa
-- a coluna. Nenhuma data e gravada; SET time_zone segue a regra do A4.
--
-- Ensaio (no servidor, a partir de /var/www/chronodesk), tres variantes:
--   (a) tabela vazia: migration 2x sem erro; 1o rollback volta; 2o para de
--       proposito (copia existe):
--   sudo env DADOS=portal_absences --     VERIFICA=migrations/verificacao/20261007_012_absences_check.sql --     bash scripts/ensaio-migration.sh f3 --     migrations/20261007_012_absences.sql --     migrations/rollback/20261007_012_absences_down.sql
--   (b) parada com linha: cabecalho de rollback/20261007_012_ensaio_linha_antiga.sql
--   (c) rollback com linhas do F3: cabecalho de rollback/20261007_012_ensaio_linhas_f3.sql
--
-- Rollback: migrations/rollback/20261007_012_absences_down.sql.
-- Verificacao: migrations/verificacao/20261007_012_absences_check.sql.

SET time_zone = 'America/Sao_Paulo';

-- 0. Checagem previa: tabela vazia enquanto a 012 nao foi aplicada
DROP PROCEDURE IF EXISTS migration_012_confere_vazia;
DELIMITER //
CREATE PROCEDURE migration_012_confere_vazia()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'absence_type') > 0
       AND (SELECT COUNT(*) FROM portal_absences) > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'migration_012 abortada: portal_absences tem linhas (o plano supoe tabela vazia). Nada foi alterado.';
    END IF;
END//
DELIMITER ;
CALL migration_012_confere_vazia();
DROP PROCEDURE migration_012_confere_vazia;

-- 1. Colunas novas
SET @has_team := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'team'
);
SET @add_team_sql := IF(
    @has_team = 0,
    'ALTER TABLE portal_absences
        ADD COLUMN team ENUM(''n1'', ''n2'', ''lideranca'') NOT NULL AFTER employee_name',
    'SELECT 1'
);
PREPARE add_team_stmt FROM @add_team_sql;
EXECUTE add_team_stmt;
DEALLOCATE PREPARE add_team_stmt;

SET @has_category := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'category'
);
SET @add_category_sql := IF(
    @has_category = 0,
    'ALTER TABLE portal_absences
        ADD COLUMN category ENUM(''folga'', ''ferias'', ''ausencia_justificada'', ''compromisso'', ''outro'') NOT NULL AFTER team',
    'SELECT 1'
);
PREPARE add_category_stmt FROM @add_category_sql;
EXECUTE add_category_stmt;
DEALLOCATE PREPARE add_category_stmt;

SET @has_period := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'period_type'
);
SET @add_period_sql := IF(
    @has_period = 0,
    'ALTER TABLE portal_absences
        ADD COLUMN period_type ENUM(''full_day'', ''from_time'', ''interval'') NOT NULL DEFAULT ''full_day'' AFTER ends_on,
        ADD COLUMN start_time TIME NULL AFTER period_type,
        ADD COLUMN end_time TIME NULL AFTER start_time',
    'SELECT 1'
);
PREPARE add_period_stmt FROM @add_period_sql;
EXECUTE add_period_stmt;
DEALLOCATE PREPARE add_period_stmt;

SET @has_retroactive := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'retroactive'
);
SET @add_retroactive_sql := IF(
    @has_retroactive = 0,
    'ALTER TABLE portal_absences
        ADD COLUMN retroactive TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
    'SELECT 1'
);
PREPARE add_retroactive_stmt FROM @add_retroactive_sql;
EXECUTE add_retroactive_stmt;
DEALLOCATE PREPARE add_retroactive_stmt;

SET @has_authorship := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'created_by'
);
SET @add_authorship_sql := IF(
    @has_authorship = 0,
    'ALTER TABLE portal_absences
        ADD COLUMN created_by VARCHAR(100) NOT NULL AFTER approved_by,
        ADD COLUMN updated_by VARCHAR(100) NULL AFTER created_by,
        ADD COLUMN cancelled_by VARCHAR(100) NULL AFTER updated_by,
        ADD COLUMN cancelled_at DATETIME NULL AFTER cancelled_by',
    'SELECT 1'
);
PREPARE add_authorship_stmt FROM @add_authorship_sql;
EXECUTE add_authorship_stmt;
DEALLOCATE PREPARE add_authorship_stmt;

-- 2. absence_type sai (P8)
SET @has_absence_type := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'absence_type'
);
SET @drop_absence_type_sql := IF(
    @has_absence_type > 0,
    'ALTER TABLE portal_absences DROP COLUMN absence_type',
    'SELECT 1'
);
PREPARE drop_absence_type_stmt FROM @drop_absence_type_sql;
EXECUTE drop_absence_type_stmt;
DEALLOCATE PREPARE drop_absence_type_stmt;

-- 3. status so aprovado e cancelado
SET @status_type := (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'status'
);
SET @modify_status_sql := IF(
    @status_type IS NOT NULL AND @status_type <> 'enum(''approved'',''cancelled'')',
    'ALTER TABLE portal_absences
        MODIFY COLUMN status ENUM(''approved'', ''cancelled'') NOT NULL DEFAULT ''approved''',
    'SELECT 1'
);
PREPARE modify_status_stmt FROM @modify_status_sql;
EXECUTE modify_status_stmt;
DEALLOCATE PREPARE modify_status_stmt;

-- 4. Indice e FK
SET @has_range_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND INDEX_NAME = 'idx_absence_employee_range'
);
SET @add_range_index_sql := IF(
    @has_range_index = 0,
    'ALTER TABLE portal_absences
        ADD INDEX idx_absence_employee_range (employee_id, status, starts_on, ends_on)',
    'SELECT 1'
);
PREPARE add_range_index_stmt FROM @add_range_index_sql;
EXECUTE add_range_index_stmt;
DEALLOCATE PREPARE add_range_index_stmt;

SET @has_absence_fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences'
      AND CONSTRAINT_NAME = 'fk_absence_employee' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @add_absence_fk_sql := IF(
    @has_absence_fk = 0,
    'ALTER TABLE portal_absences
        ADD CONSTRAINT fk_absence_employee
            FOREIGN KEY (employee_id) REFERENCES funcionarios(id) ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE add_absence_fk_stmt FROM @add_absence_fk_sql;
EXECUTE add_absence_fk_stmt;
DEALLOCATE PREPARE add_absence_fk_stmt;

-- 5. Integridade do periodo no banco
SET @has_range_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences'
      AND CONSTRAINT_NAME = 'ck_absence_range' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @add_range_check_sql := IF(
    @has_range_check = 0,
    'ALTER TABLE portal_absences
        ADD CONSTRAINT ck_absence_range CHECK (ends_on >= starts_on)',
    'SELECT 1'
);
PREPARE add_range_check_stmt FROM @add_range_check_sql;
EXECUTE add_range_check_stmt;
DEALLOCATE PREPARE add_range_check_stmt;

SET @has_period_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences'
      AND CONSTRAINT_NAME = 'ck_absence_period' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @add_period_check_sql := IF(
    @has_period_check = 0,
    'ALTER TABLE portal_absences
        ADD CONSTRAINT ck_absence_period CHECK (
            (period_type = ''full_day'' AND start_time IS NULL AND end_time IS NULL)
            OR (period_type = ''from_time'' AND starts_on = ends_on AND start_time IS NOT NULL AND end_time IS NULL)
            OR (period_type = ''interval'' AND starts_on = ends_on AND start_time IS NOT NULL
                AND end_time IS NOT NULL AND start_time < end_time)
        )',
    'SELECT 1'
);
PREPARE add_period_check_stmt FROM @add_period_check_sql;
EXECUTE add_period_check_stmt;
DEALLOCATE PREPARE add_period_check_stmt;
