-- Rollback da migration 20261126_023_monthly_schedule.sql (escala do mes,
-- F10-4). NAO e migration de deploy. Aplicar so DEPOIS de voltar o codigo (o
-- codigo novo le e grava estas tabelas).
--
-- 0. Checagem previa, antes de qualquer alteracao (erro 45000
--    "rollback_023 abortado: ..."): qualquer copia *_bkp_023 ja existe
--    (rollback anterior). Um segundo rollback trocaria a copia pela atual:
--    conferir, renomear (RENAME TABLE) e repetir. A checagem usa o
--    procedimento temporario rollback_023_confere; depois de uma parada ele
--    fica no banco ate a proxima execucao, que o recria e o apaga no fim.
-- 1. As quatro tabelas viram copias (RENAME, com os dados): as linhas, as
--    revisoes, os meses e as travas. Nada e apagado; a presenca nao depende da
--    escala publicada e nao muda (desenho do F10, 6.5).
--    As FKs vao junto com as tabelas (RENAME mantem as restricoes entre elas).
-- Cada passo confere information_schema antes de alterar. O arquivo inteiro
-- NAO se repete depois de uma execucao com a 023 aplicada: a segunda para no
-- passo 0, de proposito. Sem a 023, nada muda e nenhuma copia e criada.

SET time_zone = 'America/Sao_Paulo';

-- 0. Checagem previa
DROP PROCEDURE IF EXISTS rollback_023_confere;
DELIMITER //
CREATE PROCEDURE rollback_023_confere()
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('portal_monthly_schedule_entries_bkp_023', 'portal_monthly_schedule_revisions_bkp_023',
                             'portal_monthly_schedules_bkp_023', 'portal_pa_day_locks_bkp_023')) > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'rollback_023 abortado: ja existe copia *_bkp_023 de rollback anterior. Nada foi alterado. Renomeie as copias e repita.';
    END IF;
END//
DELIMITER ;
CALL rollback_023_confere();
DROP PROCEDURE rollback_023_confere;

-- 1. As tabelas viram copias
SET @has_entries := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_monthly_schedule_entries');
SET @move_entries_sql := IF(@has_entries = 1, 'RENAME TABLE portal_monthly_schedule_entries TO portal_monthly_schedule_entries_bkp_023', 'SELECT 1');
PREPARE move_entries_stmt FROM @move_entries_sql;
EXECUTE move_entries_stmt;
DEALLOCATE PREPARE move_entries_stmt;

SET @has_revisions := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_monthly_schedule_revisions');
SET @move_revisions_sql := IF(@has_revisions = 1, 'RENAME TABLE portal_monthly_schedule_revisions TO portal_monthly_schedule_revisions_bkp_023', 'SELECT 1');
PREPARE move_revisions_stmt FROM @move_revisions_sql;
EXECUTE move_revisions_stmt;
DEALLOCATE PREPARE move_revisions_stmt;

SET @has_months := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_monthly_schedules');
SET @move_months_sql := IF(@has_months = 1, 'RENAME TABLE portal_monthly_schedules TO portal_monthly_schedules_bkp_023', 'SELECT 1');
PREPARE move_months_stmt FROM @move_months_sql;
EXECUTE move_months_stmt;
DEALLOCATE PREPARE move_months_stmt;

SET @has_locks := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_day_locks');
SET @move_locks_sql := IF(@has_locks = 1, 'RENAME TABLE portal_pa_day_locks TO portal_pa_day_locks_bkp_023', 'SELECT 1');
PREPARE move_locks_stmt FROM @move_locks_sql;
EXECUTE move_locks_stmt;
DEALLOCATE PREPARE move_locks_stmt;
