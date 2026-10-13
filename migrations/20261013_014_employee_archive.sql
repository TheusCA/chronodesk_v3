-- ChronoDesk - arquivamento de tecnicos (Parte B, F4; desenho, secoes 2.5 e 9.6).
-- Plano aprovado em 2026-10-13 (D5, D11).
--
-- 1. funcionarios.arquivado_em (DATETIME) e arquivado_por (VARCHAR(100)),
--    nulas. ativo = 0 continua sendo o estado de arquivado (D7 do desenho);
--    as duas colunas registram quando e por quem. Gravadas so pelo codigo do
--    F4, com a data do PHP como valor explicito (regra do A4).
-- 2. CHECK ck_funcionario_arquivado: cadastro ativo nao tem data nem autor
--    de arquivamento. Restaurar limpa as duas colunas (D12).
-- Nenhuma linha de dado e alterada: os inativos de hoje ficam com data nula
-- ("arquivado em data desconhecida", secao 2.5). Arquiva-los de novo pelo
-- F4 aplica os efeitos e grava a data (D5). As colunas entram no fim da
-- tabela, sem AFTER: a 014 nao depende da 011 (fora_mapa_escala).
-- Idempotente: cada passo confere information_schema antes de alterar.
--
-- Aplicar ANTES do git pull do F4 (o codigo do F4 le as colunas). O codigo
-- anterior funciona sobre o esquema novo: nao le as colunas e so grava
-- ativo = 0 (permitido pelo CHECK com as colunas nulas). A data nao e usada
-- aqui; SET time_zone segue a regra do A4 para sessao manual.
--
-- Ensaio (no servidor, a partir de /var/www/chronodesk), tres variantes:
--   (a) estrutura e dados de funcionarios: migration 2x sem erro; 1o
--       rollback volta; 2o para de proposito (copia existe):
--   sudo env VERIFICA=migrations/verificacao/20261013_014_employee_archive_check.sql \
--     bash scripts/ensaio-migration.sh f4 \
--     migrations/20261013_014_employee_archive.sql \
--     migrations/rollback/20261013_014_employee_archive_down.sql
--   (b) contagens sobre as linhas reais de PA, escala, excecao e ausencia:
--   sudo env DADOS=portal_pa_assignments,portal_schedule_rules,portal_schedule_exceptions,portal_absences \
--     VERIFICA=migrations/verificacao/20261013_014_employee_archive_check.sql \
--     bash scripts/ensaio-migration.sh f4_dados \
--     migrations/20261013_014_employee_archive.sql \
--     migrations/rollback/20261013_014_employee_archive_down.sql
--   (c) rollback com cadastros arquivados: cabecalho de
--       rollback/20261013_014_ensaio_linhas_f4.sql
--
-- Rollback: migrations/rollback/20261013_014_employee_archive_down.sql.
-- Verificacao: migrations/verificacao/20261013_014_employee_archive_check.sql.

SET time_zone = 'America/Sao_Paulo';

-- 1. Colunas do arquivamento
SET @has_archived_at := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'arquivado_em'
);
SET @add_archived_at_sql := IF(
    @has_archived_at = 0,
    'ALTER TABLE funcionarios
        ADD COLUMN arquivado_em DATETIME NULL
        COMMENT ''Quando foi arquivado (F4); nulo em ativo e em inativo anterior ao F4''',
    'SELECT 1'
);
PREPARE add_archived_at_stmt FROM @add_archived_at_sql;
EXECUTE add_archived_at_stmt;
DEALLOCATE PREPARE add_archived_at_stmt;

SET @has_archived_by := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'arquivado_por'
);
SET @add_archived_by_sql := IF(
    @has_archived_by = 0,
    'ALTER TABLE funcionarios
        ADD COLUMN arquivado_por VARCHAR(100) NULL
        COMMENT ''Usuario que arquivou (F4); nulo em ativo e em inativo anterior ao F4''',
    'SELECT 1'
);
PREPARE add_archived_by_stmt FROM @add_archived_by_sql;
EXECUTE add_archived_by_stmt;
DEALLOCATE PREPARE add_archived_by_stmt;

-- 2. Cadastro ativo sem data nem autor de arquivamento (D11)
SET @has_archive_check := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios'
      AND CONSTRAINT_NAME = 'ck_funcionario_arquivado' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @add_archive_check_sql := IF(
    @has_archive_check = 0,
    'ALTER TABLE funcionarios
        ADD CONSTRAINT ck_funcionario_arquivado
            CHECK (ativo = 0 OR (arquivado_em IS NULL AND arquivado_por IS NULL))',
    'SELECT 1'
);
PREPARE add_archive_check_stmt FROM @add_archive_check_sql;
EXECUTE add_archive_check_stmt;
DEALLOCATE PREPARE add_archive_check_stmt;
