-- Verificacao da migration 026 (NC no cadastro e a copia nos lancamentos,
-- lote NC). So contagens e assinaturas: nenhum NC, nome nem login. Rodar
-- depois da 026, no pos-deploy e sempre que quiser conferir:
--   docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas \
--     < migrations/verificacao/20261123_026_matricula_nc_check.sql
-- Tambem serve antes da 026 e depois do rollback: as contagens das colunas
-- novas so rodam com elas presentes (senao, "026 ausente").
--
-- Esperado com a 026: colunas_026 = 3, checks_026 = 3, indice_026 = 1; logo
-- depois da migration, ativos_com_nc = 0 (todos sem NC, ate a gestao
-- preencher) e os lancamentos com copia = 0. Sempre: as contagens de problema
-- (nc_repetido, nc_fora_do_formato, copias_fora_do_formato) = 0.
-- copias_diferentes_do_cadastro e so informativa (o NC do cadastro mudou
-- depois do lancamento). As assinaturas (no fim) cobrem o cadastro e os
-- lancamentos sem as colunas novas: a 026 nao muda nenhuma linha, entao sao
-- iguais antes, depois e depois do rollback.

SET time_zone = 'America/Sao_Paulo';

SELECT
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND ((TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'matricula')
          OR (TABLE_NAME IN ('portal_overtime_entries', 'portal_time_adjustments') AND COLUMN_NAME = 'employee_nc'))) AS colunas_026,
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'
        AND CONSTRAINT_NAME IN ('ck_funcionarios_matricula', 'ck_overtime_employee_nc', 'ck_time_adjustment_employee_nc')) AS checks_026,
    (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios'
        AND INDEX_NAME = 'uq_funcionarios_matricula' AND NON_UNIQUE = 0) AS indice_026,
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%\_bkp\_026') AS copias_de_rollback_026;

SET @tem_026 := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND ((TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'matricula')
        OR (TABLE_NAME IN ('portal_overtime_entries', 'portal_time_adjustments') AND COLUMN_NAME = 'employee_nc'))
);
SET @cadastro_026_sql := IF(
    @tem_026 = 3,
    'SELECT
        (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1) AS cadastros_ativos,
        (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND matricula IS NOT NULL) AS ativos_com_nc,
        (SELECT COUNT(*) FROM funcionarios WHERE ativo = 1 AND matricula IS NULL) AS ativos_sem_nc,
        (SELECT COUNT(*) FROM portal_overtime_entries) AS horas_extras,
        (SELECT COUNT(*) FROM portal_overtime_entries WHERE employee_nc IS NOT NULL) AS horas_extras_com_copia,
        (SELECT COUNT(*) FROM portal_time_adjustments) AS correcoes,
        (SELECT COUNT(*) FROM portal_time_adjustments WHERE employee_nc IS NOT NULL) AS correcoes_com_copia',
    'SELECT ''026 ausente'' AS situacao_026'
);
PREPARE cadastro_026_stmt FROM @cadastro_026_sql;
EXECUTE cadastro_026_stmt;
DEALLOCATE PREPARE cadastro_026_stmt;

SET @problemas_026_sql := IF(
    @tem_026 = 3,
    'SELECT
        (SELECT COUNT(*) - COUNT(DISTINCT matricula) FROM funcionarios WHERE matricula IS NOT NULL) AS nc_repetido,
        (SELECT COUNT(*) FROM funcionarios WHERE matricula IS NOT NULL AND NOT REGEXP_LIKE(matricula, ''^[1-9][0-9]{0,11}$'', ''c'')) AS nc_fora_do_formato,
        (SELECT COUNT(*) FROM portal_overtime_entries WHERE employee_nc IS NOT NULL AND NOT REGEXP_LIKE(employee_nc, ''^[1-9][0-9]{0,11}$'', ''c''))
          + (SELECT COUNT(*) FROM portal_time_adjustments WHERE employee_nc IS NOT NULL AND NOT REGEXP_LIKE(employee_nc, ''^[1-9][0-9]{0,11}$'', ''c'')) AS copias_fora_do_formato,
        (SELECT COUNT(*) FROM portal_overtime_entries o JOIN funcionarios f ON f.id = o.employee_id
          WHERE o.employee_nc IS NOT NULL AND NOT (o.employee_nc <=> f.matricula))
          + (SELECT COUNT(*) FROM portal_time_adjustments t JOIN funcionarios f ON f.id = t.employee_id
          WHERE t.employee_nc IS NOT NULL AND NOT (t.employee_nc <=> f.matricula)) AS copias_diferentes_do_cadastro',
    'SELECT ''026 ausente'' AS situacao_026'
);
PREPARE problemas_026_stmt FROM @problemas_026_sql;
EXECUTE problemas_026_stmt;
DEALLOCATE PREPARE problemas_026_stmt;

-- Assinaturas do cadastro e dos lancamentos, sem as colunas novas (CRC32 por
-- linha, combinado por XOR; sem nome, login, NC nem texto livre)
SELECT 'cadastro' AS tabela, COUNT(*) AS linhas,
       BIT_XOR(CRC32(CONCAT_WS('|', id, equipe, access_role, ativo, fora_mapa_escala))) AS assinatura
  FROM funcionarios
UNION ALL
SELECT 'horas_extras', COUNT(*),
       BIT_XOR(CRC32(CONCAT_WS('|', id, employee_id, work_date, total_minutes, status)))
  FROM portal_overtime_entries
UNION ALL
SELECT 'correcoes', COUNT(*),
       BIT_XOR(CRC32(CONCAT_WS('|', id, employee_id, adjustment_date, adjustment_type, status)))
  FROM portal_time_adjustments;
