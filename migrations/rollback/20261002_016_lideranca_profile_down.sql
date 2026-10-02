-- Rollback da migration 20261002_016_lideranca_profile.sql (Lote 5a).
-- NAO e migration de deploy. Opcional: o codigo anterior funciona com o ENUM
-- ampliado e com o UNIQUE. Aplicar so DEPOIS de voltar o codigo e de reverter
-- os dados (016b_down, Lote 5b), nesta ordem.
--
-- 0. Se alguma linha ainda usar access_role = 'lideranca', PARA antes de
--    qualquer alteracao, com o erro "Table ...
--    rollback_016_abortado_perfil_lideranca_em_uso doesn't exist".
-- 1. Remove uq_funcionarios_ad_login, se existir.
-- 2. Volta o ENUM de access_role ao original, se tiver 'lideranca'.
-- Idempotente. Nenhuma linha e alterada.

SET time_zone = 'America/Sao_Paulo';

-- 0. Checagem previa
SET @lideranca_em_uso := (SELECT COUNT(*) FROM funcionarios WHERE access_role = 'lideranca');
SET @abortar_016_down_sql := IF(
    @lideranca_em_uso > 0,
    'SELECT 1 FROM rollback_016_abortado_perfil_lideranca_em_uso',
    'SELECT 1'
);
PREPARE abortar_016_down_stmt FROM @abortar_016_down_sql;
EXECUTE abortar_016_down_stmt;
DEALLOCATE PREPARE abortar_016_down_stmt;

-- 1. UNIQUE de ad_login
SET @has_ad_login_unique := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'funcionarios'
      AND INDEX_NAME = 'uq_funcionarios_ad_login'
);
SET @drop_ad_login_unique_sql := IF(
    @has_ad_login_unique > 0,
    'ALTER TABLE funcionarios DROP INDEX uq_funcionarios_ad_login',
    'SELECT 1'
);
PREPARE drop_ad_login_unique_stmt FROM @drop_ad_login_unique_sql;
EXECUTE drop_ad_login_unique_stmt;
DEALLOCATE PREPARE drop_ad_login_unique_stmt;

-- 2. ENUM original
SET @access_role_type := (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'funcionarios'
      AND COLUMN_NAME = 'access_role'
);
SET @remove_lideranca_sql := IF(
    @access_role_type IS NOT NULL AND LOCATE('''lideranca''', @access_role_type) > 0,
    'ALTER TABLE funcionarios
        MODIFY COLUMN access_role
        ENUM(''tecnico'', ''gestor'', ''admin'', ''somente_leitura'')
        NOT NULL DEFAULT ''tecnico''',
    'SELECT 1'
);
PREPARE remove_lideranca_stmt FROM @remove_lideranca_sql;
EXECUTE remove_lideranca_stmt;
DEALLOCATE PREPARE remove_lideranca_stmt;
