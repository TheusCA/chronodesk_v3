-- Verificacao da migration 014 (arquivamento, Parte B, F4). So contagens,
-- nenhum nome, login nem texto. Rodar antes e depois da 014, no ensaio,
-- depois do rollback e no pos-deploy:
--   read -rsp 'Senha root do MySQL: ' P; echo; docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas < migrations/verificacao/20261013_014_employee_archive_check.sql; unset P
--
-- 1. Estrutura (vale em qualquer estado):
--   colunas_014 = 2 e check_014 = 1 depois da 014 (0 antes e depois do
--   rollback); copia_rollback = 1 so depois de um rollback.
-- 2. Cadastros (com as colunas da 014; antes, um aviso):
--   ativos_com_data = 0 e arquivados_sem_autor = 0 esperado (o CHECK e o
--   servico garantem); inativos_sem_data = inativos de antes do F4 (D5).
-- 3. Efeitos pendentes de inativos, de hoje em diante (antes e depois da
--   014; tabela ausente no banco de ensaio aparece como NULL). Depois de um
--   arquivamento pelo F4, os quatro primeiros nao crescem por causa dele;
--   inativo antigo so os perde ao ser arquivado de novo (D5).
--   pa_periodo_invertido = 0 esperado (vinculo com fim antes do inicio; o F4
--   nunca grava, D6). Diferente de 0 ANTES da 014 e dado antigo: anotar.
--   "Hoje" e a data do MySQL no fuso do PHP (SET time_zone abaixo, regra do
--   A4); esta consulta so le.

SET time_zone = 'America/Sao_Paulo';
SET @hoje := CURDATE();

-- 1. Estrutura
SELECT
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios'
        AND COLUMN_NAME IN ('arquivado_em', 'arquivado_por')) AS colunas_014,
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios'
        AND CONSTRAINT_NAME = 'ck_funcionario_arquivado' AND CONSTRAINT_TYPE = 'CHECK') AS check_014,
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios_bkp_014') AS copia_rollback,
    (SELECT COUNT(*) FROM funcionarios) AS cadastros,
    (SELECT COUNT(*) FROM funcionarios WHERE ativo = 0) AS inativos;

-- 2. Cadastros, so com as colunas da 014 (consulta preparada)
SET @has_014_columns := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios'
      AND COLUMN_NAME IN ('arquivado_em', 'arquivado_por')
);
SET @archive_check_sql := IF(
    @has_014_columns = 2,
    'SELECT
        (SELECT COUNT(*) FROM funcionarios WHERE ativo = 0 AND arquivado_em IS NOT NULL) AS arquivados_f4,
        (SELECT COUNT(*) FROM funcionarios WHERE ativo = 0 AND arquivado_em IS NULL) AS inativos_sem_data,
        (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND (arquivado_em IS NOT NULL OR arquivado_por IS NOT NULL)) AS ativos_com_data,
        (SELECT COUNT(*) FROM funcionarios WHERE arquivado_em IS NOT NULL AND (arquivado_por IS NULL OR arquivado_por = '''')) AS arquivados_sem_autor',
    'SELECT ''colunas da 014 ausentes: cadastros nao conferidos'' AS aviso'
);
PREPARE archive_check_stmt FROM @archive_check_sql;
EXECUTE archive_check_stmt;
DEALLOCATE PREPARE archive_check_stmt;

-- 3. Efeitos pendentes de inativos, de hoje em diante. Cada contagem so
--    entra se a tabela existir (consulta preparada).
SET @has_pa := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_assignments'
);
SET @has_rules := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules'
);
SET @has_exceptions := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_exceptions'
);
SET @has_absences := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences'
);
SET @pending_sql := CONCAT(
    'SELECT ',
    IF(@has_pa = 1,
        '(SELECT COUNT(DISTINCT a.employee_id) FROM portal_pa_assignments a
           JOIN funcionarios f ON f.id = a.employee_id AND f.ativo = 0
          WHERE a.active = 1 AND (a.valid_until IS NULL OR a.valid_until >= @hoje))',
        'NULL'), ' AS inativos_com_pa, ',
    IF(@has_rules = 1,
        '(SELECT COUNT(DISTINCT r.employee_id) FROM portal_schedule_rules r
           JOIN funcionarios f ON f.id = r.employee_id AND f.ativo = 0
          WHERE (r.effective_until IS NULL OR r.effective_until >= @hoje)
            AND (r.effective_until IS NULL OR r.effective_until >= r.effective_from))',
        'NULL'), ' AS inativos_com_regra, ',
    IF(@has_exceptions = 1,
        '(SELECT COUNT(*) FROM portal_schedule_exceptions e
           JOIN funcionarios f ON f.id = e.employee_id AND f.ativo = 0
          WHERE e.exception_date >= @hoje)',
        'NULL'), ' AS excecoes_de_inativos, ',
    IF(@has_absences = 1,
        '(SELECT COUNT(*) FROM portal_absences b
           JOIN funcionarios f ON f.id = b.employee_id AND f.ativo = 0
          WHERE b.status = ''approved'' AND b.ends_on >= @hoje)',
        'NULL'), ' AS ausencias_de_inativos, ',
    IF(@has_pa = 1,
        '(SELECT COUNT(*) FROM portal_pa_assignments WHERE valid_until IS NOT NULL AND valid_until < valid_from)',
        'NULL'), ' AS pa_periodo_invertido'
);
PREPARE pending_stmt FROM @pending_sql;
EXECUTE pending_stmt;
DEALLOCATE PREPARE pending_stmt;
