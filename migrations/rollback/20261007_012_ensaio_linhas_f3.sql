-- Linhas no formato do F3 para ensaiar o ROLLBACK da 012 com dados
-- (scripts/ensaio-migration.sh). So para o banco descartavel ensaio_<nome>:
-- em qualquer outro banco o INSERT nao grava nada (condicao DATABASE()).
-- Idempotente: a 2a execucao nao grava de novo (marca created_by = 'qa.ensaio').
--
-- Quatro ausencias ficticias no cadastro de menor id da copia, sem nome real
-- nem texto: folga de dia inteiro aprovada; compromisso "a partir de"
-- cancelado; outro por intervalo aprovado; ferias de varios dias aprovadas.
-- O ensaio usa a 012 como PREPARO, este arquivo no lugar da migration e o
-- rollback da 012:
--   sudo env DADOS=portal_absences \
--     PREPARO=migrations/20261007_012_absences.sql \
--     VERIFICA=migrations/verificacao/20261007_012_absences_check.sql \
--     bash scripts/ensaio-migration.sh f3_rollback \
--     migrations/rollback/20261007_012_ensaio_linhas_f3.sql \
--     migrations/rollback/20261007_012_absences_down.sql
-- Esperado: depois do "migration" (este arquivo), linhas = 4, aprovadas = 3,
-- canceladas = 1, parciais = 2, sobreposicoes_aprovadas = 0 (igual na 2a vez);
-- depois do 1o rollback, colunas_012 = 0, absence_type = 1, status de volta ao
-- ENUM original e linhas = 4 (a cancelada como rejected); o 2o rollback PARA
-- de proposito (copia ja existe).

SET time_zone = 'America/Sao_Paulo';

INSERT INTO portal_absences
    (employee_id, employee_name, team, category, starts_on, ends_on, period_type, start_time, end_time,
     status, retroactive, created_by, cancelled_by, cancelled_at)
SELECT f.id, 'Ensaio QA', 'n1', linhas.category, linhas.starts_on, linhas.ends_on, linhas.period_type,
       linhas.start_time, linhas.end_time, linhas.status, 0, 'qa.ensaio', linhas.cancelled_by, linhas.cancelled_at
  FROM (SELECT MIN(id) AS id FROM funcionarios) AS f
  JOIN (
        SELECT 'folga' AS category, DATE('2026-10-05') AS starts_on, DATE('2026-10-05') AS ends_on,
               'full_day' AS period_type, CAST(NULL AS TIME) AS start_time, CAST(NULL AS TIME) AS end_time,
               'approved' AS status, CAST(NULL AS CHAR(100)) AS cancelled_by, CAST(NULL AS DATETIME) AS cancelled_at
        UNION ALL
        SELECT 'compromisso', DATE('2026-10-06'), DATE('2026-10-06'), 'from_time', CAST('14:00:00' AS TIME), NULL,
               'cancelled', 'qa.ensaio', CAST('2026-10-05 10:00:00' AS DATETIME)
        UNION ALL
        SELECT 'outro', DATE('2026-10-07'), DATE('2026-10-07'), 'interval', CAST('10:00:00' AS TIME), CAST('12:00:00' AS TIME),
               'approved', NULL, NULL
        UNION ALL
        SELECT 'ferias', DATE('2026-10-13'), DATE('2026-10-16'), 'full_day', NULL, NULL,
               'approved', NULL, NULL
  ) AS linhas
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND f.id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM portal_absences WHERE created_by = 'qa.ensaio') AS ja);
