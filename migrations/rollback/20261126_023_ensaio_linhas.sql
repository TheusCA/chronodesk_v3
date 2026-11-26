-- Linhas no formato da escala do mes do F10-4 para ensaiar o ROLLBACK da 023
-- com dados (scripts/ensaio-migration.sh). So para o banco descartavel
-- ensaio_<nome>: em qualquer outro banco nada e gravado (condicao DATABASE()
-- LIKE 'ensaio\_%' em cada comando). Idempotente: a 2a execucao nao grava de
-- novo (o mes, a revisao, as linhas e a trava ja existem).
--
-- Grava, na copia, so nas tabelas da 023: o mes de dezembro de 2026 com a
-- revisao 1 publicada (contagens ficticias), uma linha presencial por cadastro
-- ativo de menor id (no PA ativo de menor ordem) e uma trava desse cadastro no
-- mesmo PA em 01/12/2026. Nenhum nome nem login e lido ou exibido.
-- O ensaio usa a 023 como PREPARO, este arquivo no lugar da migration e o
-- rollback da 023:
--   sudo env PREPARO=migrations/20261126_023_monthly_schedule.sql \
--     DADOS=funcionarios,portal_pa_inventory \
--     VERIFICA=migrations/verificacao/20261126_023_monthly_schedule_check.sql \
--     bash scripts/ensaio-migration.sh f10_023_rollback \
--     migrations/rollback/20261126_023_ensaio_linhas.sql \
--     migrations/rollback/20261126_023_monthly_schedule_down.sql audit_log
-- Esperado: depois do "migration" (este arquivo), meses = 1,
-- meses_publicados = 1, revisoes = 1, linhas = 1, travas = 1 e os problemas
-- em 0 (igual na 2a vez; 0 linhas e 0 travas se a copia nao tiver cadastro
-- ativo ou PA ativo); depois do 1o rollback, tabelas_023 = 0,
-- copias_de_rollback_023 = 4 e as assinaturas iguais as do estado inicial; o
-- 2o rollback PARA de proposito (copias ja existem).

SET time_zone = 'America/Sao_Paulo';

INSERT INTO portal_monthly_schedules (ref_month, current_revision, row_version, created_by)
SELECT '2026-12-01', 1, 2, 'ensaio_023'
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND NOT EXISTS (SELECT 1 FROM portal_monthly_schedules WHERE ref_month = '2026-12-01');

INSERT INTO portal_monthly_schedule_revisions (schedule_id, revision, from_date, input_hash, engine_version, reason, published_by, counts)
SELECT m.id, 1, '2026-12-01', REPEAT('0', 64), 'f10-pa-2', 'publicacao', 'ensaio_023', JSON_OBJECT('linhas', 1, 'sem_pa', 0)
  FROM portal_monthly_schedules m
 WHERE DATABASE() LIKE 'ensaio\_%' AND m.ref_month = '2026-12-01'
   AND NOT EXISTS (SELECT 1 FROM portal_monthly_schedule_revisions r WHERE r.schedule_id = m.id AND r.revision = 1);

SET @ensaio_023_employee := (SELECT MIN(id) FROM funcionarios WHERE ativo = 1);
SET @ensaio_023_pa := (SELECT pa_number FROM portal_pa_inventory WHERE active = 1 ORDER BY display_order, pa_number LIMIT 1);

INSERT INTO portal_monthly_schedule_entries (revision_id, work_date, employee_id, situation, source, team_snapshot, pa_number, pa_source, no_pa, moved)
SELECT r.id, '2026-12-01', @ensaio_023_employee, 'onsite', 'rule', NULL, @ensaio_023_pa, 'engine', 0, 0
  FROM portal_monthly_schedule_revisions r JOIN portal_monthly_schedules m ON m.id = r.schedule_id
 WHERE DATABASE() LIKE 'ensaio\_%' AND m.ref_month = '2026-12-01' AND r.revision = 1
   AND @ensaio_023_employee IS NOT NULL AND @ensaio_023_pa IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM portal_monthly_schedule_entries e WHERE e.revision_id = r.id);

INSERT INTO portal_pa_day_locks (work_date, pa_number, employee_id, created_by)
SELECT '2026-12-01', @ensaio_023_pa, @ensaio_023_employee, 'ensaio_023'
 WHERE DATABASE() LIKE 'ensaio\_%' AND @ensaio_023_employee IS NOT NULL AND @ensaio_023_pa IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM portal_pa_day_locks WHERE work_date = '2026-12-01');
