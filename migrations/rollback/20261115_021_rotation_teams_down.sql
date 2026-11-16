-- Rollback da migration 20261115_021_rotation_teams.sql (F10-2).
-- NAO e migration de deploy. Aplicar so DEPOIS de voltar o codigo (o codigo
-- do F10-2 le as tabelas dos times ao gravar regra de escala).
--
-- 0. Checagem previa: se QUALQUER copia *_bkp_021 ja existir (rollback
--    anterior), PARA antes de qualquer alteracao, com o erro 45000
--    "rollback_021 abortado: ...". Mesmo motivo do rollback da 011: um
--    segundo rollback trocaria a copia pela atual, ja vazia. Para repetir:
--    conferir as copias, renomea-las (RENAME TABLE) e rodar de novo. A
--    checagem usa o procedimento temporario rollback_021_confere_copias
--    (SIGNAL so existe em procedimento); depois de uma parada ele fica no
--    banco ate a proxima execucao, que o recria e o apaga no fim.
-- 0b. Copias, so do que existe (cada uma confere information_schema):
--    portal_rotation_teams_bkp_021, portal_rotation_team_versions_bkp_021 e
--    portal_rotation_team_members_bkp_021 (as tabelas inteiras, sem a coluna
--    gerada), portal_schedule_rules_rotation_bkp_021 (id, employee_id e
--    rotation_team_id das regras gravadas por time) e
--    funcionarios_grupo_escala_bkp_021 (id e grupo de quem nao esta no
--    'comum'; so ID, nunca nome). As copias ficam: remover a mao depois de
--    conferir.
-- 1. portal_schedule_rules: a FK, o indice e a coluna rotation_team_id saem.
--    As regras gravadas pelos times continuam regras validas, com o mesmo
--    tipo, padrao e vigencia: a presenca nao muda (desenho 6.5).
-- 2. funcionarios.grupo_escala sai (o grupo de cada um fica na copia).
-- 3. As tabelas dos membros, das versoes e dos times saem, nessa ordem (FK).
-- Os passos 1 a 3 conferem information_schema antes de alterar. O arquivo
-- inteiro NAO se repete depois de uma execucao com a 021 aplicada: a
-- segunda para no passo 0, de proposito. Sem a 021, nada muda e nenhuma
-- copia e criada.

SET time_zone = 'America/Sao_Paulo';

-- 0. Checagem previa das copias
DROP PROCEDURE IF EXISTS rollback_021_confere_copias;
DELIMITER //
CREATE PROCEDURE rollback_021_confere_copias()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('portal_rotation_teams_bkp_021', 'portal_rotation_team_versions_bkp_021', 'portal_rotation_team_members_bkp_021', 'portal_schedule_rules_rotation_bkp_021', 'funcionarios_grupo_escala_bkp_021')) > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'rollback_021 abortado: ja existe copia *_bkp_021 de rollback anterior. Nada foi alterado. Renomeie as copias e repita.';
    END IF;
END//
DELIMITER ;
CALL rollback_021_confere_copias();
DROP PROCEDURE rollback_021_confere_copias;

-- 0b. Copias (nenhuma existe: a checagem acima garante)
SET @has_teams_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_rotation_teams'
);
SET @copy_teams_sql := IF(
    @has_teams_table > 0,
    'CREATE TABLE portal_rotation_teams_bkp_021 AS
        SELECT id, name, active, created_by, updated_by, created_at, updated_at FROM portal_rotation_teams',
    'SELECT 1'
);
PREPARE copy_teams_stmt FROM @copy_teams_sql;
EXECUTE copy_teams_stmt;
DEALLOCATE PREPARE copy_teams_stmt;

SET @has_versions_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_rotation_team_versions'
);
SET @copy_versions_sql := IF(
    @has_versions_table > 0,
    'CREATE TABLE portal_rotation_team_versions_bkp_021 AS
        SELECT id, team_id, effective_from, pattern, anchor_date, weeks, created_by, created_at, updated_by, updated_at
        FROM portal_rotation_team_versions',
    'SELECT 1'
);
PREPARE copy_versions_stmt FROM @copy_versions_sql;
EXECUTE copy_versions_stmt;
DEALLOCATE PREPARE copy_versions_stmt;

SET @has_members_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_rotation_team_members'
);
SET @copy_members_sql := IF(
    @has_members_table > 0,
    'CREATE TABLE portal_rotation_team_members_bkp_021 AS
        SELECT id, team_id, employee_id, valid_from, valid_until, created_by, updated_by, created_at, updated_at
        FROM portal_rotation_team_members',
    'SELECT 1'
);
PREPARE copy_members_stmt FROM @copy_members_sql;
EXECUTE copy_members_stmt;
DEALLOCATE PREPARE copy_members_stmt;

SET @has_rule_team_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rotation_team_id'
);
SET @copy_rule_team_sql := IF(
    @has_rule_team_column > 0,
    'CREATE TABLE portal_schedule_rules_rotation_bkp_021 AS
        SELECT id, employee_id, rotation_team_id FROM portal_schedule_rules WHERE rotation_team_id IS NOT NULL',
    'SELECT 1'
);
PREPARE copy_rule_team_stmt FROM @copy_rule_team_sql;
EXECUTE copy_rule_team_stmt;
DEALLOCATE PREPARE copy_rule_team_stmt;

SET @has_grupo_escala := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'grupo_escala'
);
SET @copy_grupo_escala_sql := IF(
    @has_grupo_escala > 0,
    'CREATE TABLE funcionarios_grupo_escala_bkp_021 AS
        SELECT id, grupo_escala FROM funcionarios WHERE grupo_escala <> ''comum''',
    'SELECT 1'
);
PREPARE copy_grupo_escala_stmt FROM @copy_grupo_escala_sql;
EXECUTE copy_grupo_escala_stmt;
DEALLOCATE PREPARE copy_grupo_escala_stmt;

-- 1. Coluna das regras (FK, indice e coluna, nessa ordem)
SET @has_rule_team_fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules'
      AND CONSTRAINT_NAME = 'fk_schedule_rule_rotation_team' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @drop_rule_team_fk_sql := IF(
    @has_rule_team_fk > 0,
    'ALTER TABLE portal_schedule_rules DROP FOREIGN KEY fk_schedule_rule_rotation_team',
    'SELECT 1'
);
PREPARE drop_rule_team_fk_stmt FROM @drop_rule_team_fk_sql;
EXECUTE drop_rule_team_fk_stmt;
DEALLOCATE PREPARE drop_rule_team_fk_stmt;

SET @has_rule_team_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND INDEX_NAME = 'idx_schedule_rule_rotation_team'
);
SET @drop_rule_team_index_sql := IF(
    @has_rule_team_index > 0,
    'ALTER TABLE portal_schedule_rules DROP INDEX idx_schedule_rule_rotation_team',
    'SELECT 1'
);
PREPARE drop_rule_team_index_stmt FROM @drop_rule_team_index_sql;
EXECUTE drop_rule_team_index_stmt;
DEALLOCATE PREPARE drop_rule_team_index_stmt;

SET @has_rule_team_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rotation_team_id'
);
SET @drop_rule_team_column_sql := IF(
    @has_rule_team_column > 0,
    'ALTER TABLE portal_schedule_rules DROP COLUMN rotation_team_id',
    'SELECT 1'
);
PREPARE drop_rule_team_column_stmt FROM @drop_rule_team_column_sql;
EXECUTE drop_rule_team_column_stmt;
DEALLOCATE PREPARE drop_rule_team_column_stmt;

-- 2. Grupo da escala
SET @has_grupo_escala := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'grupo_escala'
);
SET @drop_grupo_escala_sql := IF(
    @has_grupo_escala > 0,
    'ALTER TABLE funcionarios DROP COLUMN grupo_escala',
    'SELECT 1'
);
PREPARE drop_grupo_escala_stmt FROM @drop_grupo_escala_sql;
EXECUTE drop_grupo_escala_stmt;
DEALLOCATE PREPARE drop_grupo_escala_stmt;

-- 3. Tabelas dos times (membros, versoes, times)
SET @has_members_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_rotation_team_members'
);
SET @drop_members_sql := IF(@has_members_table > 0, 'DROP TABLE portal_rotation_team_members', 'SELECT 1');
PREPARE drop_members_stmt FROM @drop_members_sql;
EXECUTE drop_members_stmt;
DEALLOCATE PREPARE drop_members_stmt;

SET @has_versions_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_rotation_team_versions'
);
SET @drop_versions_sql := IF(@has_versions_table > 0, 'DROP TABLE portal_rotation_team_versions', 'SELECT 1');
PREPARE drop_versions_stmt FROM @drop_versions_sql;
EXECUTE drop_versions_stmt;
DEALLOCATE PREPARE drop_versions_stmt;

SET @has_teams_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_rotation_teams'
);
SET @drop_teams_sql := IF(@has_teams_table > 0, 'DROP TABLE portal_rotation_teams', 'SELECT 1');
PREPARE drop_teams_stmt FROM @drop_teams_sql;
EXECUTE drop_teams_stmt;
DEALLOCATE PREPARE drop_teams_stmt;
