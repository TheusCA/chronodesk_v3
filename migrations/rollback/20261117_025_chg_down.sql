-- Rollback da migration 20261117_025_chg.sql (Controle de CHG, 9.17). NAO e
-- migration de deploy. Aplicar so DEPOIS de voltar o codigo (o codigo novo le
-- funcionario_grupos no cadastro de funcionarios).
--
-- 0. Checagem previa: se QUALQUER copia *_bkp_025 ja existir (rollback
--    anterior), PARA antes de qualquer alteracao, com o erro 45000
--    "rollback_025 abortado: ...". Mesmo motivo dos rollbacks da 021 e da 024:
--    um segundo rollback trocaria a copia pela atual, ja vazia. Para repetir:
--    conferir as copias, renomea-las (RENAME TABLE) e rodar de novo.
-- 0b. Copias, so do que existe (as tabelas inteiras, com o sufixo _bkp_025).
--    Os cards e os catalogos tem nomes (solicitante, tecnico do historico,
--    carteiras, locais) e links: as copias ficam no banco, como as tabelas, e
--    saem a mao depois de conferidas.
-- 1. As tabelas saem na ordem inversa das FKs: endpoints, tentativas,
--    rollbacks, planos, locais e carteiras dos cards, cards, catalogos e os
--    grupos por funcionario.
-- O passo 1 confere information_schema antes de alterar. O arquivo inteiro
-- NAO se repete depois de uma execucao com a 025 aplicada: a segunda para no
-- passo 0, de proposito. Sem a 025, nada muda e nenhuma copia e criada.

SET time_zone = 'America/Sao_Paulo';

-- 0. Checagem previa das copias
DROP PROCEDURE IF EXISTS rollback_025_confere_copias;
DELIMITER //
CREATE PROCEDURE rollback_025_confere_copias()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('funcionario_grupos_bkp_025', 'portal_chg_carteiras_bkp_025', 'portal_chg_locais_bkp_025', 'portal_chg_updates_bkp_025', 'portal_chg_update_carteiras_bkp_025', 'portal_chg_update_locais_bkp_025', 'portal_chg_plans_bkp_025', 'portal_chg_attempts_bkp_025', 'portal_chg_endpoints_bkp_025', 'portal_chg_rollbacks_bkp_025')) > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'rollback_025 abortado: ja existe copia *_bkp_025 de rollback anterior. Nada foi alterado. Renomeie as copias e repita.';
    END IF;
END//
DELIMITER ;
CALL rollback_025_confere_copias();
DROP PROCEDURE rollback_025_confere_copias;

-- 0b. Copias (nenhuma existe: a checagem acima garante)
SET @has_1 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionario_grupos'
);
SET @copy_1_sql := IF(
    @has_1 > 0,
    'CREATE TABLE funcionario_grupos_bkp_025 AS
        SELECT funcionario_id, grupo, created_by, created_at
        FROM funcionario_grupos',
    'SELECT 1'
);
PREPARE copy_1_stmt FROM @copy_1_sql;
EXECUTE copy_1_stmt;
DEALLOCATE PREPARE copy_1_stmt;

SET @has_2 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_carteiras'
);
SET @copy_2_sql := IF(
    @has_2 > 0,
    'CREATE TABLE portal_chg_carteiras_bkp_025 AS
        SELECT id, codigo, nome, ativo, created_by, updated_by, created_at, updated_at
        FROM portal_chg_carteiras',
    'SELECT 1'
);
PREPARE copy_2_stmt FROM @copy_2_sql;
EXECUTE copy_2_stmt;
DEALLOCATE PREPARE copy_2_stmt;

SET @has_3 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_locais'
);
SET @copy_3_sql := IF(
    @has_3 > 0,
    'CREATE TABLE portal_chg_locais_bkp_025 AS
        SELECT id, nome, ativo, created_by, updated_by, created_at, updated_at
        FROM portal_chg_locais',
    'SELECT 1'
);
PREPARE copy_3_stmt FROM @copy_3_sql;
EXECUTE copy_3_stmt;
DEALLOCATE PREPARE copy_3_stmt;

SET @has_4 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_updates'
);
SET @copy_4_sql := IF(
    @has_4 > 0,
    'CREATE TABLE portal_chg_updates_bkp_025 AS
        SELECT id, legacy_id, solicitante, tipo, versao, versao_anterior, descricao, link_planilha, sugestao_at, service_status, sd_inicio, sd_fim, chg_numero, chg_status, tecnico_id, tecnico_nome_legado, stage_manual, stage_manual_auto, concluida, concluida_em, archived_at, archived_by, row_version, created_by, updated_by, created_at, updated_at
        FROM portal_chg_updates',
    'SELECT 1'
);
PREPARE copy_4_stmt FROM @copy_4_sql;
EXECUTE copy_4_stmt;
DEALLOCATE PREPARE copy_4_stmt;

SET @has_5 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_update_carteiras'
);
SET @copy_5_sql := IF(
    @has_5 > 0,
    'CREATE TABLE portal_chg_update_carteiras_bkp_025 AS
        SELECT id, update_id, position, codigo, nome, pas
        FROM portal_chg_update_carteiras',
    'SELECT 1'
);
PREPARE copy_5_stmt FROM @copy_5_sql;
EXECUTE copy_5_stmt;
DEALLOCATE PREPARE copy_5_stmt;

SET @has_6 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_update_locais'
);
SET @copy_6_sql := IF(
    @has_6 > 0,
    'CREATE TABLE portal_chg_update_locais_bkp_025 AS
        SELECT id, update_id, position, nome
        FROM portal_chg_update_locais',
    'SELECT 1'
);
PREPARE copy_6_stmt FROM @copy_6_sql;
EXECUTE copy_6_stmt;
DEALLOCATE PREPARE copy_6_stmt;

SET @has_7 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_plans'
);
SET @copy_7_sql := IF(
    @has_7 > 0,
    'CREATE TABLE portal_chg_plans_bkp_025 AS
        SELECT id, update_id, position, implantacao, backup, rollback, task_impl, task_bkp, task_roll
        FROM portal_chg_plans',
    'SELECT 1'
);
PREPARE copy_7_stmt FROM @copy_7_sql;
EXECUTE copy_7_stmt;
DEALLOCATE PREPARE copy_7_stmt;

SET @has_8 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_attempts'
);
SET @copy_8_sql := IF(
    @has_8 > 0,
    'CREATE TABLE portal_chg_attempts_bkp_025 AS
        SELECT id, update_id, attempt_no, patch_status, reagendando, registered_at
        FROM portal_chg_attempts',
    'SELECT 1'
);
PREPARE copy_8_stmt FROM @copy_8_sql;
EXECUTE copy_8_stmt;
DEALLOCATE PREPARE copy_8_stmt;

SET @has_9 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_endpoints'
);
SET @copy_9_sql := IF(
    @has_9 > 0,
    'CREATE TABLE portal_chg_endpoints_bkp_025 AS
        SELECT id, attempt_id, position, url, start_at, start_date
        FROM portal_chg_endpoints',
    'SELECT 1'
);
PREPARE copy_9_stmt FROM @copy_9_sql;
EXECUTE copy_9_stmt;
DEALLOCATE PREPARE copy_9_stmt;

SET @has_10 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_rollbacks'
);
SET @copy_10_sql := IF(
    @has_10 > 0,
    'CREATE TABLE portal_chg_rollbacks_bkp_025 AS
        SELECT id, update_id, url, start_at, started_at, status, plan_position, removed_at, removed_by, created_by
        FROM portal_chg_rollbacks',
    'SELECT 1'
);
PREPARE copy_10_stmt FROM @copy_10_sql;
EXECUTE copy_10_stmt;
DEALLOCATE PREPARE copy_10_stmt;

-- 1. Tabelas (ordem inversa das FKs)
SET @drop_has_1 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_endpoints'
);
SET @drop_1_sql := IF(@drop_has_1 > 0, 'DROP TABLE portal_chg_endpoints', 'SELECT 1');
PREPARE drop_1_stmt FROM @drop_1_sql;
EXECUTE drop_1_stmt;
DEALLOCATE PREPARE drop_1_stmt;

SET @drop_has_2 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_attempts'
);
SET @drop_2_sql := IF(@drop_has_2 > 0, 'DROP TABLE portal_chg_attempts', 'SELECT 1');
PREPARE drop_2_stmt FROM @drop_2_sql;
EXECUTE drop_2_stmt;
DEALLOCATE PREPARE drop_2_stmt;

SET @drop_has_3 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_rollbacks'
);
SET @drop_3_sql := IF(@drop_has_3 > 0, 'DROP TABLE portal_chg_rollbacks', 'SELECT 1');
PREPARE drop_3_stmt FROM @drop_3_sql;
EXECUTE drop_3_stmt;
DEALLOCATE PREPARE drop_3_stmt;

SET @drop_has_4 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_plans'
);
SET @drop_4_sql := IF(@drop_has_4 > 0, 'DROP TABLE portal_chg_plans', 'SELECT 1');
PREPARE drop_4_stmt FROM @drop_4_sql;
EXECUTE drop_4_stmt;
DEALLOCATE PREPARE drop_4_stmt;

SET @drop_has_5 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_update_locais'
);
SET @drop_5_sql := IF(@drop_has_5 > 0, 'DROP TABLE portal_chg_update_locais', 'SELECT 1');
PREPARE drop_5_stmt FROM @drop_5_sql;
EXECUTE drop_5_stmt;
DEALLOCATE PREPARE drop_5_stmt;

SET @drop_has_6 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_update_carteiras'
);
SET @drop_6_sql := IF(@drop_has_6 > 0, 'DROP TABLE portal_chg_update_carteiras', 'SELECT 1');
PREPARE drop_6_stmt FROM @drop_6_sql;
EXECUTE drop_6_stmt;
DEALLOCATE PREPARE drop_6_stmt;

SET @drop_has_7 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_updates'
);
SET @drop_7_sql := IF(@drop_has_7 > 0, 'DROP TABLE portal_chg_updates', 'SELECT 1');
PREPARE drop_7_stmt FROM @drop_7_sql;
EXECUTE drop_7_stmt;
DEALLOCATE PREPARE drop_7_stmt;

SET @drop_has_8 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_locais'
);
SET @drop_8_sql := IF(@drop_has_8 > 0, 'DROP TABLE portal_chg_locais', 'SELECT 1');
PREPARE drop_8_stmt FROM @drop_8_sql;
EXECUTE drop_8_stmt;
DEALLOCATE PREPARE drop_8_stmt;

SET @drop_has_9 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_carteiras'
);
SET @drop_9_sql := IF(@drop_has_9 > 0, 'DROP TABLE portal_chg_carteiras', 'SELECT 1');
PREPARE drop_9_stmt FROM @drop_9_sql;
EXECUTE drop_9_stmt;
DEALLOCATE PREPARE drop_9_stmt;

SET @drop_has_10 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionario_grupos'
);
SET @drop_10_sql := IF(@drop_has_10 > 0, 'DROP TABLE funcionario_grupos', 'SELECT 1');
PREPARE drop_10_stmt FROM @drop_10_sql;
EXECUTE drop_10_stmt;
DEALLOCATE PREPARE drop_10_stmt;
