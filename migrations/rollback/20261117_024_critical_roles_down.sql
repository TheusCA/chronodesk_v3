-- Rollback da migration 20261117_024_critical_roles.sql (rodizio de papeis do
-- Critico, 9.16). NAO e migration de deploy. Aplicar so DEPOIS de voltar o
-- codigo (o codigo do rodizio le estas tabelas na presenca e nas pausas).
--
-- 0. Checagem previa: se QUALQUER copia *_bkp_024 ja existir (rollback
--    anterior), PARA antes de qualquer alteracao, com o erro 45000
--    "rollback_024 abortado: ...". Mesmo motivo do rollback da 021: um
--    segundo rollback trocaria a copia pela atual, ja vazia. Para repetir:
--    conferir as copias, renomea-las (RENAME TABLE) e rodar de novo. A
--    checagem usa o procedimento temporario rollback_024_confere_copias
--    (SIGNAL so existe em procedimento); depois de uma parada ele fica no
--    banco ate a proxima execucao, que o recria e o apaga no fim.
-- 0b. Copias, so do que existe (cada uma confere information_schema):
--    portal_critical_roles_bkp_024, portal_critical_role_rotations_bkp_024 e
--    portal_critical_role_overrides_bkp_024 (as tabelas inteiras: so IDs,
--    datas, jornadas e o motivo de lista fechada; nenhum nome de pessoa). As
--    copias ficam: remover a mao depois de conferir.
-- 1. As tabelas das trocas, do rodizio e dos papeis saem, nessa ordem (FK).
--    A presenca volta a regra individual e ao cadastro de cada um (o
--    historico das regras nao muda: o rodizio nunca gravou regra).
-- O passo 1 confere information_schema antes de alterar. O arquivo inteiro
-- NAO se repete depois de uma execucao com a 024 aplicada: a segunda para no
-- passo 0, de proposito. Sem a 024, nada muda e nenhuma copia e criada.

SET time_zone = 'America/Sao_Paulo';

-- 0. Checagem previa das copias
DROP PROCEDURE IF EXISTS rollback_024_confere_copias;
DELIMITER //
CREATE PROCEDURE rollback_024_confere_copias()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('portal_critical_roles_bkp_024', 'portal_critical_role_rotations_bkp_024', 'portal_critical_role_overrides_bkp_024')) > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'rollback_024 abortado: ja existe copia *_bkp_024 de rollback anterior. Nada foi alterado. Renomeie as copias e repita.';
    END IF;
END//
DELIMITER ;
CALL rollback_024_confere_copias();
DROP PROCEDURE rollback_024_confere_copias;

-- 0b. Copias (nenhuma existe: a checagem acima garante)
SET @has_roles_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_roles'
);
SET @copy_roles_sql := IF(
    @has_roles_table > 0,
    'CREATE TABLE portal_critical_roles_bkp_024 AS
        SELECT id, name, presence_type, work_start, work_end, active, sort_order, created_by, updated_by, created_at, updated_at
        FROM portal_critical_roles',
    'SELECT 1'
);
PREPARE copy_roles_stmt FROM @copy_roles_sql;
EXECUTE copy_roles_stmt;
DEALLOCATE PREPARE copy_roles_stmt;

SET @has_rotations_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_role_rotations'
);
SET @copy_rotations_sql := IF(
    @has_rotations_table > 0,
    'CREATE TABLE portal_critical_role_rotations_bkp_024 AS
        SELECT id, effective_from, period, anchor_date, roles, employees, created_by, updated_by, created_at, updated_at
        FROM portal_critical_role_rotations',
    'SELECT 1'
);
PREPARE copy_rotations_stmt FROM @copy_rotations_sql;
EXECUTE copy_rotations_stmt;
DEALLOCATE PREPARE copy_rotations_stmt;

SET @has_overrides_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_role_overrides'
);
SET @copy_overrides_sql := IF(
    @has_overrides_table > 0,
    'CREATE TABLE portal_critical_role_overrides_bkp_024 AS
        SELECT id, employee_id, starts_on, ends_on, role_id, reason, created_by, updated_by, created_at, updated_at
        FROM portal_critical_role_overrides',
    'SELECT 1'
);
PREPARE copy_overrides_stmt FROM @copy_overrides_sql;
EXECUTE copy_overrides_stmt;
DEALLOCATE PREPARE copy_overrides_stmt;

-- 1. Tabelas (trocas, rodizio, papeis)
SET @has_overrides_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_role_overrides'
);
SET @drop_overrides_sql := IF(@has_overrides_table > 0, 'DROP TABLE portal_critical_role_overrides', 'SELECT 1');
PREPARE drop_overrides_stmt FROM @drop_overrides_sql;
EXECUTE drop_overrides_stmt;
DEALLOCATE PREPARE drop_overrides_stmt;

SET @has_rotations_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_role_rotations'
);
SET @drop_rotations_sql := IF(@has_rotations_table > 0, 'DROP TABLE portal_critical_role_rotations', 'SELECT 1');
PREPARE drop_rotations_stmt FROM @drop_rotations_sql;
EXECUTE drop_rotations_stmt;
DEALLOCATE PREPARE drop_rotations_stmt;

SET @has_roles_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_roles'
);
SET @drop_roles_sql := IF(@has_roles_table > 0, 'DROP TABLE portal_critical_roles', 'SELECT 1');
PREPARE drop_roles_stmt FROM @drop_roles_sql;
EXECUTE drop_roles_stmt;
DEALLOCATE PREPARE drop_roles_stmt;
