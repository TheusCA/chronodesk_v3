-- Rollback da migration 20261005_011_schedule_history.sql (Parte B, F1).
-- NAO e migration de deploy. Aplicar so DEPOIS de voltar o codigo (o codigo
-- do F1 le fora_mapa_escala e grava varias regras por colaborador).
-- Idempotente: cada passo confere information_schema antes de alterar.
--
-- 0. Copias, so na primeira execucao (as seguintes nao sobrescrevem):
--    portal_schedule_rules_bkp_011 (todas as regras, com o historico),
--    portal_schedule_exceptions_bkp_011 (excecoes da equipe lideranca) e
--    funcionarios_fora_mapa_bkp_011 (so o id dos cadastros marcados).
--    As copias ficam: remover a mao depois de conferir.
-- 1. Regras e excecoes com equipe lideranca sao apagadas (estao na copia):
--    o ENUM original nao tem o valor.
-- 2. Regras: fica UMA por colaborador, a aberta ou, sem aberta, a de maior
--    inicio de vigencia. O historico anterior so fica na copia. Regra que
--    comeca no futuro e fica pode deixar o colaborador sem regra ate essa data
--    no codigo anterior.
-- 3. cycle vira undefined (sem configuracao): o codigo anterior lanca erro
--    para tipo desconhecido (Calendario, Escala e Relatorios com 400).
-- 4. Indices e coluna gerada do historico saem; uq_schedule_rule_employee
--    volta ANTES de sair o indice que sustenta a FK.
-- 5. ENUMs de rule_type e team voltam ao original.
-- 6. funcionarios.fora_mapa_escala sai (marcacao na copia).

SET time_zone = 'America/Sao_Paulo';

-- 0. Copias
SET @has_rules_bkp := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules_bkp_011'
);
SET @create_rules_bkp_sql := IF(
    @has_rules_bkp = 0,
    'CREATE TABLE portal_schedule_rules_bkp_011 AS
        SELECT id, employee_id, employee_name, ad_login, team, rule_type, rule_config,
               effective_from, effective_until, created_by, updated_by, created_at, updated_at
        FROM portal_schedule_rules',
    'SELECT 1'
);
PREPARE create_rules_bkp_stmt FROM @create_rules_bkp_sql;
EXECUTE create_rules_bkp_stmt;
DEALLOCATE PREPARE create_rules_bkp_stmt;

SET @has_exceptions_bkp := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_exceptions_bkp_011'
);
SET @create_exceptions_bkp_sql := IF(
    @has_exceptions_bkp = 0,
    'CREATE TABLE portal_schedule_exceptions_bkp_011 AS
        SELECT id, employee_id, employee_name, team, exception_date, exception_type, note,
               created_by, created_at, updated_at
        FROM portal_schedule_exceptions
        WHERE team = ''lideranca''',
    'SELECT 1'
);
PREPARE create_exceptions_bkp_stmt FROM @create_exceptions_bkp_sql;
EXECUTE create_exceptions_bkp_stmt;
DEALLOCATE PREPARE create_exceptions_bkp_stmt;

SET @has_fora_mapa := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'fora_mapa_escala'
);
SET @has_fora_mapa_bkp := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios_fora_mapa_bkp_011'
);
SET @create_fora_mapa_bkp_sql := IF(
    @has_fora_mapa > 0 AND @has_fora_mapa_bkp = 0,
    'CREATE TABLE funcionarios_fora_mapa_bkp_011 AS
        SELECT id FROM funcionarios WHERE fora_mapa_escala = 1',
    'SELECT 1'
);
PREPARE create_fora_mapa_bkp_stmt FROM @create_fora_mapa_bkp_sql;
EXECUTE create_fora_mapa_bkp_stmt;
DEALLOCATE PREPARE create_fora_mapa_bkp_stmt;

-- 1. Equipe lideranca fora das tabelas de escala
DELETE FROM portal_schedule_exceptions WHERE team = 'lideranca';
DELETE FROM portal_schedule_rules WHERE team = 'lideranca';

-- 2. Uma regra por colaborador (a tabela derivada com funcao de janela e
--    materializada, o que permite ler a tabela do DELETE)
DELETE FROM portal_schedule_rules
WHERE id IN (
    SELECT id FROM (
        SELECT id,
               ROW_NUMBER() OVER (
                   PARTITION BY employee_id
                   ORDER BY (effective_until IS NULL) DESC, effective_from DESC, id DESC
               ) AS posicao
        FROM portal_schedule_rules
    ) AS ordenadas
    WHERE posicao > 1
);

-- 3. cycle vira undefined
UPDATE portal_schedule_rules SET rule_type = 'undefined', rule_config = NULL WHERE rule_type = 'cycle';

-- 4. Indices e coluna gerada
SET @has_rule_open_unique := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND INDEX_NAME = 'uq_schedule_rule_open'
);
SET @drop_rule_open_unique_sql := IF(
    @has_rule_open_unique > 0,
    'ALTER TABLE portal_schedule_rules DROP INDEX uq_schedule_rule_open',
    'SELECT 1'
);
PREPARE drop_rule_open_unique_stmt FROM @drop_rule_open_unique_sql;
EXECUTE drop_rule_open_unique_stmt;
DEALLOCATE PREPARE drop_rule_open_unique_stmt;

SET @has_rule_open_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'open_employee_id'
);
SET @drop_rule_open_column_sql := IF(
    @has_rule_open_column > 0,
    'ALTER TABLE portal_schedule_rules DROP COLUMN open_employee_id',
    'SELECT 1'
);
PREPARE drop_rule_open_column_stmt FROM @drop_rule_open_column_sql;
EXECUTE drop_rule_open_column_stmt;
DEALLOCATE PREPARE drop_rule_open_column_stmt;

SET @has_rule_from_unique := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND INDEX_NAME = 'uq_schedule_rule_employee_from'
);
SET @drop_rule_from_unique_sql := IF(
    @has_rule_from_unique > 0,
    'ALTER TABLE portal_schedule_rules DROP INDEX uq_schedule_rule_employee_from',
    'SELECT 1'
);
PREPARE drop_rule_from_unique_stmt FROM @drop_rule_from_unique_sql;
EXECUTE drop_rule_from_unique_stmt;
DEALLOCATE PREPARE drop_rule_from_unique_stmt;

SET @has_rule_employee_unique := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND INDEX_NAME = 'uq_schedule_rule_employee'
);
SET @add_rule_employee_unique_sql := IF(
    @has_rule_employee_unique = 0,
    'ALTER TABLE portal_schedule_rules ADD UNIQUE KEY uq_schedule_rule_employee (employee_id)',
    'SELECT 1'
);
PREPARE add_rule_employee_unique_stmt FROM @add_rule_employee_unique_sql;
EXECUTE add_rule_employee_unique_stmt;
DEALLOCATE PREPARE add_rule_employee_unique_stmt;

SET @has_rule_period_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND INDEX_NAME = 'idx_schedule_rule_employee_period'
);
SET @drop_rule_period_index_sql := IF(
    @has_rule_period_index > 0,
    'ALTER TABLE portal_schedule_rules DROP INDEX idx_schedule_rule_employee_period',
    'SELECT 1'
);
PREPARE drop_rule_period_index_stmt FROM @drop_rule_period_index_sql;
EXECUTE drop_rule_period_index_stmt;
DEALLOCATE PREPARE drop_rule_period_index_stmt;

-- 5. ENUMs originais
SET @rule_type_type := (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rule_type'
);
SET @remove_cycle_sql := IF(
    @rule_type_type IS NOT NULL AND LOCATE('''cycle''', @rule_type_type) > 0,
    'ALTER TABLE portal_schedule_rules
        MODIFY COLUMN rule_type
        ENUM(''even_days'', ''odd_days'', ''always_onsite'', ''always_remote'', ''undefined'', ''fixed_weekdays'')
        NOT NULL DEFAULT ''undefined''',
    'SELECT 1'
);
PREPARE remove_cycle_stmt FROM @remove_cycle_sql;
EXECUTE remove_cycle_stmt;
DEALLOCATE PREPARE remove_cycle_stmt;

SET @rule_team_type := (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'team'
);
SET @remove_rule_team_sql := IF(
    @rule_team_type IS NOT NULL AND LOCATE('''lideranca''', @rule_team_type) > 0,
    'ALTER TABLE portal_schedule_rules MODIFY COLUMN team ENUM(''n1'', ''n2'') NOT NULL',
    'SELECT 1'
);
PREPARE remove_rule_team_stmt FROM @remove_rule_team_sql;
EXECUTE remove_rule_team_stmt;
DEALLOCATE PREPARE remove_rule_team_stmt;

SET @exception_team_type := (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_exceptions' AND COLUMN_NAME = 'team'
);
SET @remove_exception_team_sql := IF(
    @exception_team_type IS NOT NULL AND LOCATE('''lideranca''', @exception_team_type) > 0,
    'ALTER TABLE portal_schedule_exceptions MODIFY COLUMN team ENUM(''n1'', ''n2'') NOT NULL',
    'SELECT 1'
);
PREPARE remove_exception_team_stmt FROM @remove_exception_team_sql;
EXECUTE remove_exception_team_stmt;
DEALLOCATE PREPARE remove_exception_team_stmt;

-- 6. Cadastro fora do Mapa PA e da escala
SET @drop_fora_mapa_sql := IF(
    @has_fora_mapa > 0,
    'ALTER TABLE funcionarios DROP COLUMN fora_mapa_escala',
    'SELECT 1'
);
PREPARE drop_fora_mapa_stmt FROM @drop_fora_mapa_sql;
EXECUTE drop_fora_mapa_stmt;
DEALLOCATE PREPARE drop_fora_mapa_stmt;
