-- Verificacao da migration 019 (data de corte do motor, Parte B, F5). So
-- contagens e a data do corte; nenhum nome nem login (registered_by nao sai).
-- Rodar antes e depois da 019, no ensaio, depois do registro do corte, depois
-- do rollback e no pos-deploy:
--   read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/verificacao/20261013_019_presence_cutoff_check.sql; unset P
--
-- 1. Estrutura: tabela_019 = 1 e checks_019 = 2 depois da 019 (0 antes e
--    depois do rollback); copia_rollback = 1 so depois de um rollback;
--    procedimentos_soltos = 0 (registro ou rollback recusado deixa o
--    procedimento: remova com DROP PROCEDURE IF EXISTS <nome>).
-- 2. Corte (com a tabela; antes, um aviso): cortes = 0 depois da 019 e 1
--    depois do registro; corte = a data registrada; corte_dia_16 = cortes;
--    corte_no_futuro = 1 enquanto a data nao chega (D2: pode estar no
--    futuro); sem_autor = 0.
-- 3. Auditoria (com o audit_log; no ensaio, so com audit_log nas tabelas
--    extras): eventos_registro e eventos_remocao, de todos os tempos.
-- "Hoje" e a data do MySQL no fuso do PHP (SET time_zone abaixo, regra do
-- A4); esta consulta so le.

SET time_zone = 'America/Sao_Paulo';
SET @hoje := CURDATE();

-- 1. Estrutura
SELECT
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_presence_cutoff') AS tabela_019,
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_presence_cutoff'
        AND CONSTRAINT_TYPE = 'CHECK'
        AND CONSTRAINT_NAME IN ('ck_presence_cutoff_single', 'ck_presence_cutoff_day')) AS checks_019,
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_presence_cutoff_bkp_019') AS copia_rollback,
    (SELECT COUNT(*) FROM information_schema.ROUTINES
      WHERE ROUTINE_SCHEMA = DATABASE()
        AND ROUTINE_NAME IN ('registrar_corte_019', 'rollback_019_confere_copia')) AS procedimentos_soltos;

-- 2. Corte, so com a tabela (consulta preparada)
SET @has_cutoff_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_presence_cutoff'
);
SET @cutoff_check_sql := IF(
    @has_cutoff_table = 1,
    'SELECT
        COUNT(*) AS cortes,
        MAX(cutoff_date) AS corte,
        COALESCE(SUM(DAYOFMONTH(cutoff_date) = 16), 0) AS corte_dia_16,
        COALESCE(SUM(cutoff_date > @hoje), 0) AS corte_no_futuro,
        COALESCE(SUM(registered_by IS NULL OR TRIM(registered_by) = ''''), 0) AS sem_autor
     FROM portal_presence_cutoff',
    'SELECT ''tabela da 019 ausente: corte nao conferido'' AS aviso'
);
PREPARE cutoff_check_stmt FROM @cutoff_check_sql;
EXECUTE cutoff_check_stmt;
DEALLOCATE PREPARE cutoff_check_stmt;

-- 3. Auditoria, so com o audit_log (consulta preparada)
SET @has_audit_log := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log'
);
SET @audit_check_sql := IF(
    @has_audit_log = 1,
    'SELECT
        COALESCE(SUM(action = ''PRESENCE_CUTOFF_REGISTERED''), 0) AS eventos_registro,
        COALESCE(SUM(action = ''PRESENCE_CUTOFF_REMOVED''), 0) AS eventos_remocao
     FROM audit_log
     WHERE action IN (''PRESENCE_CUTOFF_REGISTERED'', ''PRESENCE_CUTOFF_REMOVED'')',
    'SELECT ''audit_log ausente: eventos nao conferidos'' AS aviso'
);
PREPARE audit_check_stmt FROM @audit_check_sql;
EXECUTE audit_check_stmt;
DEALLOCATE PREPARE audit_check_stmt;
