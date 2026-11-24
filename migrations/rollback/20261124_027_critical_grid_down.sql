-- Rollback da migration 20261124_027_critical_grid.sql (Escala do Critico em
-- grade). NAO e migration de deploy. Aplicar so DEPOIS de voltar o codigo (o
-- codigo novo le a cor, os feriados e grava banco de horas e vagas).
--
-- 0. Checagens previas, antes de qualquer alteracao (erro 45000
--    "rollback_027 abortado: ..."):
--    a) qualquer copia *_bkp_027 ja existe (rollback anterior): um segundo
--       rollback trocaria a copia pela atual. Conferir, renomear (RENAME
--       TABLE) e repetir;
--    b) algum rodizio com o mesmo papel mais de uma vez (vagas): a CHECK
--       antiga nao aceita. Salvar antes, pela tela do codigo anterior, uma
--       versao sem repetir (ou corrigir com a Lideranca) e repetir.
--    A checagem usa o procedimento temporario rollback_027_confere; depois de
--    uma parada ele fica no banco ate a proxima execucao, que o recria e o
--    apaga no fim.
-- 1. Feriados: a tabela vira a copia portal_holidays_bkp_027 (RENAME, com os
--    dados).
-- 2. Banco de horas: copia (id) das ausencias com 'banco_horas' em
--    portal_absences_banco_horas_bkp_027; elas passam a 'outro' (o rotulo
--    antigo mais proximo) e o ENUM volta ao de antes.
-- 3. Vagas: a CHECK ck_critical_rotation_roles volta a exigir papeis sem
--    repetir (o 0b garante que nenhuma linha fere).
-- 4. Cor: copia (id, color_token) em portal_critical_roles_color_bkp_027; saem
--    a CHECK e a coluna.
-- Cada passo confere information_schema antes de alterar. O arquivo inteiro
-- NAO se repete depois de uma execucao com a 027 aplicada: a segunda para no
-- passo 0a, de proposito. Sem a 027, nada muda e nenhuma copia e criada.

SET time_zone = 'America/Sao_Paulo';

-- 0. Checagens previas
DROP PROCEDURE IF EXISTS rollback_027_confere;
DELIMITER //
CREATE PROCEDURE rollback_027_confere()
BEGIN
    DECLARE repetidos INT DEFAULT 0;
    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('portal_holidays_bkp_027', 'portal_absences_banco_horas_bkp_027', 'portal_critical_roles_color_bkp_027')) > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'rollback_027 abortado: ja existe copia *_bkp_027 de rollback anterior. Nada foi alterado. Renomeie as copias e repita.';
    END IF;
    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_role_rotations') = 1 THEN
        SELECT COUNT(*) INTO repetidos
          FROM portal_critical_role_rotations r
         WHERE JSON_LENGTH(r.roles) <> (
               SELECT COUNT(DISTINCT j.role_id)
                 FROM JSON_TABLE(r.roles, '$[*]' COLUMNS (role_id INT PATH '$')) AS j);
    END IF;
    IF repetidos > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'rollback_027 abortado: rodizio com papel repetido (vagas). Nada foi alterado. Salve versao sem repetir e repita.';
    END IF;
END//
DELIMITER ;
CALL rollback_027_confere();
DROP PROCEDURE rollback_027_confere;

-- 1. Feriados (a tabela inteira vira a copia)
SET @has_holidays := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_holidays'
);
SET @move_holidays_sql := IF(@has_holidays = 1, 'RENAME TABLE portal_holidays TO portal_holidays_bkp_027', 'SELECT 1');
PREPARE move_holidays_stmt FROM @move_holidays_sql;
EXECUTE move_holidays_stmt;
DEALLOCATE PREPARE move_holidays_stmt;

-- 2. Banco de horas
SET @has_banco := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'category'
      AND COLUMN_TYPE LIKE '%''banco_horas''%'
);
SET @copy_banco_sql := IF(
    @has_banco = 1,
    'CREATE TABLE portal_absences_banco_horas_bkp_027 AS SELECT id FROM portal_absences WHERE category = ''banco_horas''',
    'SELECT 1'
);
PREPARE copy_banco_stmt FROM @copy_banco_sql;
EXECUTE copy_banco_stmt;
DEALLOCATE PREPARE copy_banco_stmt;

SET @move_banco_sql := IF(
    @has_banco = 1,
    'UPDATE portal_absences SET category = ''outro'' WHERE category = ''banco_horas''',
    'SELECT 1'
);
PREPARE move_banco_stmt FROM @move_banco_sql;
EXECUTE move_banco_stmt;
DEALLOCATE PREPARE move_banco_stmt;

SET @drop_banco_sql := IF(
    @has_banco = 1,
    'ALTER TABLE portal_absences
        MODIFY COLUMN category ENUM(''folga'', ''ferias'', ''ausencia_justificada'', ''compromisso'', ''outro'') NOT NULL',
    'SELECT 1'
);
PREPARE drop_banco_stmt FROM @drop_banco_sql;
EXECUTE drop_banco_stmt;
DEALLOCATE PREPARE drop_banco_stmt;

-- 3. Vagas: a CHECK volta a exigir papeis sem repetir
SET @roles_check_repeat := (
    SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'ck_critical_rotation_roles'
      AND CHECK_CLAUSE NOT LIKE '%uniqueItems%'
);
SET @drop_roles_check_sql := IF(
    @roles_check_repeat > 0,
    'ALTER TABLE portal_critical_role_rotations DROP CHECK ck_critical_rotation_roles',
    'SELECT 1'
);
PREPARE drop_roles_check_stmt FROM @drop_roles_check_sql;
EXECUTE drop_roles_check_stmt;
DEALLOCATE PREPARE drop_roles_check_stmt;

SET @has_roles_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_role_rotations'
      AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'ck_critical_rotation_roles'
);
SET @add_roles_check_sql := IF(
    @has_roles_check = 0 AND @roles_check_repeat > 0,
    'ALTER TABLE portal_critical_role_rotations
        ADD CONSTRAINT ck_critical_rotation_roles CHECK (JSON_SCHEMA_VALID(''{"type": "array", "minItems": 1, "maxItems": 20, "uniqueItems": true, "items": {"type": "integer", "minimum": 1}}'', roles))',
    'SELECT 1'
);
PREPARE add_roles_check_stmt FROM @add_roles_check_sql;
EXECUTE add_roles_check_stmt;
DEALLOCATE PREPARE add_roles_check_stmt;

-- 4. Cor do papel
SET @has_color := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_roles' AND COLUMN_NAME = 'color_token'
);
SET @copy_color_sql := IF(
    @has_color = 1,
    'CREATE TABLE portal_critical_roles_color_bkp_027 AS SELECT id, color_token FROM portal_critical_roles WHERE color_token IS NOT NULL',
    'SELECT 1'
);
PREPARE copy_color_stmt FROM @copy_color_sql;
EXECUTE copy_color_stmt;
DEALLOCATE PREPARE copy_color_stmt;

SET @has_color_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_roles'
      AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'ck_critical_role_color'
);
SET @drop_color_check_sql := IF(@has_color_check > 0, 'ALTER TABLE portal_critical_roles DROP CHECK ck_critical_role_color', 'SELECT 1');
PREPARE drop_color_check_stmt FROM @drop_color_check_sql;
EXECUTE drop_color_check_stmt;
DEALLOCATE PREPARE drop_color_check_stmt;

SET @drop_color_sql := IF(@has_color = 1, 'ALTER TABLE portal_critical_roles DROP COLUMN color_token', 'SELECT 1');
PREPARE drop_color_stmt FROM @drop_color_sql;
EXECUTE drop_color_stmt;
DEALLOCATE PREPARE drop_color_stmt;
