-- ChronoDesk - perfil de Lideranca e login AD unico (Lote 5a: SEC-04, A3).
-- Idempotente. Numeracao: 011 a 014 reservadas para a Parte B; 015 e o Lote 7.
-- Nao altera nenhuma linha: so a estrutura de funcionarios.
--
-- 0. Checagem previa: login AD repetido (nao nulo, sem diferenca de
--    maiusculas, como a collation da coluna). Se houver, a migration PARA
--    antes de qualquer alteracao, com o erro "Table ...
--    migration_016_abortada_ad_login_duplicado doesn't exist". O cliente mysql
--    em lote para no primeiro erro. Resolver os duplicados e repetir.
-- 1. access_role ganha o valor 'lideranca', acrescentado no fim do ENUM: os
--    valores existentes mantem a posicao e nenhuma linha muda.
-- 2. UNIQUE KEY uq_funcionarios_ad_login (ad_login). NULL repetido continua
--    permitido. idx_ad_login fica redundante e nao e removido.
--
-- Pode ser aplicada ANTES do git pull (o codigo anterior nao grava
-- 'lideranca'). Ate o pull, o codigo anterior recebe erro do banco ao gravar
-- o login de um cadastro inativo em outro cadastro (antes era aceito).
-- A data nao e usada; SET time_zone segue a regra do A4 para sessao manual.
--
-- Rollback: migrations/rollback/20261002_016_lideranca_profile_down.sql.

SET time_zone = 'America/Sao_Paulo';

-- 0. Checagem previa de duplicados
SET @ad_login_duplicados := (
    SELECT COUNT(*)
    FROM (
        SELECT ad_login
        FROM funcionarios
        WHERE ad_login IS NOT NULL
        GROUP BY ad_login
        HAVING COUNT(*) > 1
    ) AS duplicados
);
SET @abortar_016_sql := IF(
    @ad_login_duplicados > 0,
    'SELECT 1 FROM migration_016_abortada_ad_login_duplicado',
    'SELECT 1'
);
PREPARE abortar_016_stmt FROM @abortar_016_sql;
EXECUTE abortar_016_stmt;
DEALLOCATE PREPARE abortar_016_stmt;

-- 1. Perfil lideranca
SET @access_role_type := (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'funcionarios'
      AND COLUMN_NAME = 'access_role'
);
SET @add_lideranca_sql := IF(
    @access_role_type IS NOT NULL AND LOCATE('''lideranca''', @access_role_type) = 0,
    'ALTER TABLE funcionarios
        MODIFY COLUMN access_role
        ENUM(''tecnico'', ''gestor'', ''admin'', ''somente_leitura'', ''lideranca'')
        NOT NULL DEFAULT ''tecnico''',
    'SELECT 1'
);
PREPARE add_lideranca_stmt FROM @add_lideranca_sql;
EXECUTE add_lideranca_stmt;
DEALLOCATE PREPARE add_lideranca_stmt;

-- 2. Login AD unico
SET @has_ad_login_unique := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'funcionarios'
      AND INDEX_NAME = 'uq_funcionarios_ad_login'
);
SET @add_ad_login_unique_sql := IF(
    @has_ad_login_unique = 0,
    'ALTER TABLE funcionarios ADD UNIQUE KEY uq_funcionarios_ad_login (ad_login)',
    'SELECT 1'
);
PREPARE add_ad_login_unique_stmt FROM @add_ad_login_unique_sql;
EXECUTE add_ad_login_unique_stmt;
DEALLOCATE PREPARE add_ad_login_unique_stmt;
