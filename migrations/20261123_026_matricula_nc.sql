-- ChronoDesk - NC (matricula do cadastro Senior) no cadastro do funcionario e
-- uma copia dela em cada lancamento de hora extra e de correcao de ponto
-- (lote NC, 2026-11-23; desenho em docs/DESENHO_NC_E_ICONE.md).
-- Idempotente: cada passo confere information_schema antes de alterar.
-- So estrutura: nenhuma linha existente muda. Todo cadastro fica sem NC
-- (NULL) ate a gestao preencher pela tela; os lancamentos antigos ficam com a
-- copia NULL (o CSV usa o NC atual do cadastro nessas linhas).
--
-- 1. funcionarios.matricula VARCHAR(12) NULL (ALGORITHM=INSTANT), so digitos,
--    de 1 a 12 (ck_funcionarios_matricula, ALGORITHM=COPY: o MySQL confere as
--    linhas que existem, com a manutencao ligada) e unica entre os cadastros
--    (uq_funcionarios_matricula; varios NULL continuam permitidos).
-- 2. portal_overtime_entries.employee_nc e portal_time_adjustments.employee_nc
--    VARCHAR(12) NULL (ALGORITHM=INSTANT), com a mesma regra de digitos
--    (ck_overtime_employee_nc e ck_time_adjustment_employee_nc, COPY): a copia
--    do NC do cadastro no momento do lancamento, gravada pelo servidor.
--
-- O NC e a matricula da pessoa: nenhum valor real entra no git, nos docs nem
-- nos QAs (so dados ficticios). A verificacao so conta.
--
-- Pode ser aplicada ANTES do git merge (o codigo anterior le essas tabelas
-- com a lista de colunas dele e grava sem as colunas novas, que ficam NULL) e
-- DEVE ser aplicada antes dele: o codigo do lote NC le e grava as colunas.
-- A data nao e usada; SET time_zone segue a regra do A4 para sessao manual.
--
-- Ensaio (no servidor, a partir de /var/www/chronodesk; funcionarios vai
-- sempre com os dados; audit_log so na estrutura):
--   sudo env DADOS=portal_overtime_entries,portal_time_adjustments \
--     VERIFICA=migrations/verificacao/20261123_026_matricula_nc_check.sql \
--     bash scripts/ensaio-migration.sh nc_026 \
--     migrations/20261123_026_matricula_nc.sql \
--     migrations/rollback/20261123_026_matricula_nc_down.sql audit_log
--
-- Rollback: migrations/rollback/20261123_026_matricula_nc_down.sql.
-- Verificacao: migrations/verificacao/20261123_026_matricula_nc_check.sql.

SET time_zone = 'America/Sao_Paulo';

-- 1. Cadastro: coluna, regra de digitos e unicidade
SET @has_matricula := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'matricula'
);
SET @add_matricula_sql := IF(
    @has_matricula = 0,
    'ALTER TABLE funcionarios
        ADD COLUMN matricula VARCHAR(12) NULL DEFAULT NULL
        COMMENT ''NC: matricula do cadastro Senior (so digitos, 1 a 12); NULL = sem NC'',
        ALGORITHM=INSTANT',
    'SELECT 1'
);
PREPARE add_matricula_stmt FROM @add_matricula_sql;
EXECUTE add_matricula_stmt;
DEALLOCATE PREPARE add_matricula_stmt;

SET @has_matricula_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios'
      AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'ck_funcionarios_matricula'
);
SET @add_matricula_check_sql := IF(
    @has_matricula_check = 0,
    'ALTER TABLE funcionarios
        ADD CONSTRAINT ck_funcionarios_matricula CHECK (matricula IS NULL OR REGEXP_LIKE(matricula, ''^[0-9]{1,12}$'', ''c'')),
        ALGORITHM=COPY',
    'SELECT 1'
);
PREPARE add_matricula_check_stmt FROM @add_matricula_check_sql;
EXECUTE add_matricula_check_stmt;
DEALLOCATE PREPARE add_matricula_check_stmt;

SET @has_matricula_unique := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND INDEX_NAME = 'uq_funcionarios_matricula'
);
SET @add_matricula_unique_sql := IF(
    @has_matricula_unique = 0,
    'ALTER TABLE funcionarios ADD UNIQUE KEY uq_funcionarios_matricula (matricula), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1'
);
PREPARE add_matricula_unique_stmt FROM @add_matricula_unique_sql;
EXECUTE add_matricula_unique_stmt;
DEALLOCATE PREPARE add_matricula_unique_stmt;

-- 2. Copia do NC nos lancamentos
SET @has_overtime_nc := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_overtime_entries' AND COLUMN_NAME = 'employee_nc'
);
SET @add_overtime_nc_sql := IF(
    @has_overtime_nc = 0,
    'ALTER TABLE portal_overtime_entries
        ADD COLUMN employee_nc VARCHAR(12) NULL DEFAULT NULL
        COMMENT ''Copia do NC do cadastro no lancamento (lote NC); NULL nas linhas antigas'',
        ALGORITHM=INSTANT',
    'SELECT 1'
);
PREPARE add_overtime_nc_stmt FROM @add_overtime_nc_sql;
EXECUTE add_overtime_nc_stmt;
DEALLOCATE PREPARE add_overtime_nc_stmt;

SET @has_overtime_nc_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_overtime_entries'
      AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'ck_overtime_employee_nc'
);
SET @add_overtime_nc_check_sql := IF(
    @has_overtime_nc_check = 0,
    'ALTER TABLE portal_overtime_entries
        ADD CONSTRAINT ck_overtime_employee_nc CHECK (employee_nc IS NULL OR REGEXP_LIKE(employee_nc, ''^[0-9]{1,12}$'', ''c'')),
        ALGORITHM=COPY',
    'SELECT 1'
);
PREPARE add_overtime_nc_check_stmt FROM @add_overtime_nc_check_sql;
EXECUTE add_overtime_nc_check_stmt;
DEALLOCATE PREPARE add_overtime_nc_check_stmt;

SET @has_adjustment_nc := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_time_adjustments' AND COLUMN_NAME = 'employee_nc'
);
SET @add_adjustment_nc_sql := IF(
    @has_adjustment_nc = 0,
    'ALTER TABLE portal_time_adjustments
        ADD COLUMN employee_nc VARCHAR(12) NULL DEFAULT NULL
        COMMENT ''Copia do NC do cadastro no lancamento (lote NC); NULL nas linhas antigas'',
        ALGORITHM=INSTANT',
    'SELECT 1'
);
PREPARE add_adjustment_nc_stmt FROM @add_adjustment_nc_sql;
EXECUTE add_adjustment_nc_stmt;
DEALLOCATE PREPARE add_adjustment_nc_stmt;

SET @has_adjustment_nc_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_time_adjustments'
      AND CONSTRAINT_TYPE = 'CHECK' AND CONSTRAINT_NAME = 'ck_time_adjustment_employee_nc'
);
SET @add_adjustment_nc_check_sql := IF(
    @has_adjustment_nc_check = 0,
    'ALTER TABLE portal_time_adjustments
        ADD CONSTRAINT ck_time_adjustment_employee_nc CHECK (employee_nc IS NULL OR REGEXP_LIKE(employee_nc, ''^[0-9]{1,12}$'', ''c'')),
        ALGORITHM=COPY',
    'SELECT 1'
);
PREPARE add_adjustment_nc_check_stmt FROM @add_adjustment_nc_check_sql;
EXECUTE add_adjustment_nc_check_stmt;
DEALLOCATE PREPARE add_adjustment_nc_check_stmt;
