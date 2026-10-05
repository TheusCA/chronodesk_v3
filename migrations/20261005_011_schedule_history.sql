-- ChronoDesk - historico e vigencia da escala, Lideranca na escala e cadastro
-- fora do Mapa PA e da escala (Parte B, F1; desenho, secoes 2.1, 2.6 e 0.6).
-- Idempotente: cada passo confere information_schema antes de alterar.
-- Nenhuma linha de dado e alterada: so a estrutura.
--
-- 1. Indice (employee_id, effective_from, effective_until), que passa a
--    sustentar a FK fk_schedule_rule_employee, ANTES de derrubar a UNIQUE.
-- 2. Derruba uq_schedule_rule_employee (uma regra por colaborador, BIZ-03).
-- 3. UNIQUE (employee_id, effective_from): no maximo uma regra por data de
--    inicio.
-- 4. Coluna gerada open_employee_id e UNIQUE sobre ela: no maximo uma regra
--    aberta (sem fim de vigencia) por colaborador. A nao sobreposicao entre
--    linhas fechadas fica no servico, com o colaborador travado, e e conferida
--    por migrations/verificacao/20261005_011_schedule_history_check.sql.
-- 5. rule_type ganha 'cycle' no fim do ENUM (RF-01; o codigo do F1 ainda nao
--    grava esse tipo). Os valores existentes mantem a posicao.
-- 6. team ganha 'lideranca' em portal_schedule_rules e
--    portal_schedule_exceptions (passo 2.6, P12). Ampliar ENUM nao altera
--    linha.
-- 7. funcionarios.fora_mapa_escala (P12, secao 0.6): 0 para todos. Quem fica
--    fora e marcado pela tela, so por admin, depois do deploy.
--
-- Pode ser aplicada ANTES do git pull (o codigo anterior funciona sobre o
-- schema novo; ver o desenho, secao 2.1) e DEVE ser aplicada antes dele: o
-- codigo do F1 le fora_mapa_escala e grava historico.
-- A data nao e usada; SET time_zone segue a regra do A4 para sessao manual.
--
-- Rollback: migrations/rollback/20261005_011_schedule_history_down.sql.

SET time_zone = 'America/Sao_Paulo';

-- 1. Indice que sustenta a FK
SET @has_rule_period_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_schedule_rules'
      AND INDEX_NAME = 'idx_schedule_rule_employee_period'
);
SET @add_rule_period_index_sql := IF(
    @has_rule_period_index = 0,
    'ALTER TABLE portal_schedule_rules
        ADD INDEX idx_schedule_rule_employee_period (employee_id, effective_from, effective_until)',
    'SELECT 1'
);
PREPARE add_rule_period_index_stmt FROM @add_rule_period_index_sql;
EXECUTE add_rule_period_index_stmt;
DEALLOCATE PREPARE add_rule_period_index_stmt;

-- 2. Uma regra por colaborador deixa de valer
SET @has_rule_employee_unique := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_schedule_rules'
      AND INDEX_NAME = 'uq_schedule_rule_employee'
);
SET @drop_rule_employee_unique_sql := IF(
    @has_rule_employee_unique > 0,
    'ALTER TABLE portal_schedule_rules DROP INDEX uq_schedule_rule_employee',
    'SELECT 1'
);
PREPARE drop_rule_employee_unique_stmt FROM @drop_rule_employee_unique_sql;
EXECUTE drop_rule_employee_unique_stmt;
DEALLOCATE PREPARE drop_rule_employee_unique_stmt;

-- 3. Uma regra por data de inicio
SET @has_rule_from_unique := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_schedule_rules'
      AND INDEX_NAME = 'uq_schedule_rule_employee_from'
);
SET @add_rule_from_unique_sql := IF(
    @has_rule_from_unique = 0,
    'ALTER TABLE portal_schedule_rules
        ADD UNIQUE KEY uq_schedule_rule_employee_from (employee_id, effective_from)',
    'SELECT 1'
);
PREPARE add_rule_from_unique_stmt FROM @add_rule_from_unique_sql;
EXECUTE add_rule_from_unique_stmt;
DEALLOCATE PREPARE add_rule_from_unique_stmt;

-- 4. No maximo uma regra aberta por colaborador
SET @has_rule_open_column := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_schedule_rules'
      AND COLUMN_NAME = 'open_employee_id'
);
SET @add_rule_open_column_sql := IF(
    @has_rule_open_column = 0,
    'ALTER TABLE portal_schedule_rules
        ADD COLUMN open_employee_id INT
        GENERATED ALWAYS AS (CASE WHEN effective_until IS NULL THEN employee_id ELSE NULL END) STORED
        COMMENT ''Colaborador da regra aberta; NULL quando a vigencia tem fim''',
    'SELECT 1'
);
PREPARE add_rule_open_column_stmt FROM @add_rule_open_column_sql;
EXECUTE add_rule_open_column_stmt;
DEALLOCATE PREPARE add_rule_open_column_stmt;

SET @has_rule_open_unique := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_schedule_rules'
      AND INDEX_NAME = 'uq_schedule_rule_open'
);
SET @add_rule_open_unique_sql := IF(
    @has_rule_open_unique = 0,
    'ALTER TABLE portal_schedule_rules ADD UNIQUE KEY uq_schedule_rule_open (open_employee_id)',
    'SELECT 1'
);
PREPARE add_rule_open_unique_stmt FROM @add_rule_open_unique_sql;
EXECUTE add_rule_open_unique_stmt;
DEALLOCATE PREPARE add_rule_open_unique_stmt;

-- 5. Tipo cycle
SET @rule_type_type := (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_schedule_rules'
      AND COLUMN_NAME = 'rule_type'
);
SET @add_cycle_sql := IF(
    @rule_type_type IS NOT NULL AND LOCATE('''cycle''', @rule_type_type) = 0,
    'ALTER TABLE portal_schedule_rules
        MODIFY COLUMN rule_type
        ENUM(''even_days'', ''odd_days'', ''always_onsite'', ''always_remote'', ''undefined'', ''fixed_weekdays'', ''cycle'')
        NOT NULL DEFAULT ''undefined''',
    'SELECT 1'
);
PREPARE add_cycle_stmt FROM @add_cycle_sql;
EXECUTE add_cycle_stmt;
DEALLOCATE PREPARE add_cycle_stmt;

-- 6. Equipe lideranca nas regras e nas excecoes (passo 2.6)
SET @rule_team_type := (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_schedule_rules'
      AND COLUMN_NAME = 'team'
);
SET @add_rule_team_sql := IF(
    @rule_team_type IS NOT NULL AND LOCATE('''lideranca''', @rule_team_type) = 0,
    'ALTER TABLE portal_schedule_rules
        MODIFY COLUMN team ENUM(''n1'', ''n2'', ''lideranca'') NOT NULL',
    'SELECT 1'
);
PREPARE add_rule_team_stmt FROM @add_rule_team_sql;
EXECUTE add_rule_team_stmt;
DEALLOCATE PREPARE add_rule_team_stmt;

SET @exception_team_type := (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_schedule_exceptions'
      AND COLUMN_NAME = 'team'
);
SET @add_exception_team_sql := IF(
    @exception_team_type IS NOT NULL AND LOCATE('''lideranca''', @exception_team_type) = 0,
    'ALTER TABLE portal_schedule_exceptions
        MODIFY COLUMN team ENUM(''n1'', ''n2'', ''lideranca'') NOT NULL',
    'SELECT 1'
);
PREPARE add_exception_team_stmt FROM @add_exception_team_sql;
EXECUTE add_exception_team_stmt;
DEALLOCATE PREPARE add_exception_team_stmt;

-- 7. Cadastro fora do Mapa PA e da escala
SET @has_fora_mapa := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'funcionarios'
      AND COLUMN_NAME = 'fora_mapa_escala'
);
SET @add_fora_mapa_sql := IF(
    @has_fora_mapa = 0,
    'ALTER TABLE funcionarios
        ADD COLUMN fora_mapa_escala TINYINT(1) NOT NULL DEFAULT 0
        COMMENT ''1 = fora do Mapa PA, da escala e das contagens; so admin altera''
        AFTER ativo',
    'SELECT 1'
);
PREPARE add_fora_mapa_stmt FROM @add_fora_mapa_sql;
EXECUTE add_fora_mapa_stmt;
DEALLOCATE PREPARE add_fora_mapa_stmt;
