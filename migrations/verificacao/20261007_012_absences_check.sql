-- Verificacao da migration 012 (ausencias, Parte B, F3). So contagens, nenhum
-- nome nem texto de observacao. Rodar antes e depois da 012, no ensaio, depois
-- do rollback e no pos-deploy:
--   docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas \
--     < migrations/verificacao/20261007_012_absences_check.sql
--
-- Estrutura (vale em qualquer estado):
--   colunas_012 = 10 depois da 012 (0 antes e depois do rollback);
--   absence_type = 0 depois da 012 (1 antes e depois do rollback);
--   status_tipo = enum('approved','cancelled') depois da 012;
--   fk, indice e checks = 1 cada depois da 012 (0 antes e depois do rollback).
-- Dados (so depois da 012; antes, a segunda consulta devolve um aviso):
--   orfas, periodo_invalido e sobreposicoes_aprovadas = 0 esperado.

SELECT
    (SELECT COUNT(*) FROM portal_absences) AS linhas,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences'
        AND COLUMN_NAME IN ('team', 'category', 'period_type', 'start_time', 'end_time', 'retroactive',
                            'created_by', 'updated_by', 'cancelled_by', 'cancelled_at')) AS colunas_012,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'absence_type') AS absence_type,
    (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND COLUMN_NAME = 'status') AS status_tipo,
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences'
        AND CONSTRAINT_NAME = 'fk_absence_employee' AND CONSTRAINT_TYPE = 'FOREIGN KEY') AS fk,
    (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences' AND INDEX_NAME = 'idx_absence_employee_range') AS indice,
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences'
        AND CONSTRAINT_NAME IN ('ck_absence_range', 'ck_absence_period') AND CONSTRAINT_TYPE = 'CHECK') AS checks,
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences_bkp_012') AS copia_rollback;

-- Dados por situacao e periodo e invariantes. Montada so com as colunas da
-- 012 presentes (consulta preparada), para a verificacao rodar em qualquer estado.
SET @has_012_columns := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_absences'
      AND COLUMN_NAME IN ('category', 'period_type', 'start_time', 'end_time')
);
SET @data_check_sql := IF(
    @has_012_columns = 4,
    'SELECT
        (SELECT COUNT(*) FROM portal_absences WHERE status = ''approved'') AS aprovadas,
        (SELECT COUNT(*) FROM portal_absences WHERE status = ''cancelled'') AS canceladas,
        (SELECT COUNT(*) FROM portal_absences WHERE period_type <> ''full_day'') AS parciais,
        (SELECT COUNT(*) FROM portal_absences WHERE retroactive = 1) AS retroativas,
        (SELECT COUNT(*) FROM portal_absences a LEFT JOIN funcionarios f ON f.id = a.employee_id WHERE f.id IS NULL) AS orfas,
        (SELECT COUNT(*) FROM portal_absences
          WHERE ends_on < starts_on
             OR (period_type = ''full_day'' AND (start_time IS NOT NULL OR end_time IS NOT NULL))
             OR (period_type <> ''full_day'' AND (starts_on <> ends_on OR start_time IS NULL))
             OR (period_type = ''interval'' AND (end_time IS NULL OR end_time <= start_time))) AS periodo_invalido,
        (SELECT COUNT(*)
           FROM portal_absences a
           JOIN portal_absences b ON b.employee_id = a.employee_id AND b.id > a.id
           JOIN funcionarios f ON f.id = a.employee_id
          WHERE a.status = ''approved'' AND b.status = ''approved''
            AND a.starts_on <= b.ends_on AND b.starts_on <= a.ends_on
            AND (a.period_type = ''full_day'' OR b.period_type = ''full_day''
                 OR a.starts_on <> a.ends_on OR b.starts_on <> b.ends_on
                 OR (a.start_time < COALESCE(b.end_time, f.jornada_saida)
                     AND b.start_time < COALESCE(a.end_time, f.jornada_saida)))) AS sobreposicoes_aprovadas',
    'SELECT ''colunas da 012 ausentes: dados nao conferidos'' AS aviso'
);
PREPARE data_check_stmt FROM @data_check_sql;
EXECUTE data_check_stmt;
DEALLOCATE PREPARE data_check_stmt;
