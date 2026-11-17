-- Linhas no formato do rodizio de papeis (9.16) para ensaiar o ROLLBACK da
-- 024 com dados (scripts/ensaio-migration.sh). So para o banco descartavel
-- ensaio_<nome>: em qualquer outro banco nada e gravado (condicao DATABASE()
-- LIKE 'ensaio\_%' em cada comando). Idempotente: a 2a execucao nao grava de
-- novo (os papeis, o rodizio e a troca ja existem).
--
-- Grava, na copia: se nao houver cadastro ativo do Critico, o N2 ativo de
-- menor id passa a 'critico' (grupo 'separado'), como as linhas da 020; dois
-- papeis ("Abertura home ensaio", remoto 07:00-17:00, e "Presencial ensaio",
-- presencial 08:00-17:00); um rodizio semanal com vigencia e ancora na
-- segunda 2026-11-16, os dois papeis e o cadastro ativo do Critico de menor
-- id; e uma troca pontual dele para o 2o papel em 2026-11-18. Datas
-- explicitas (regra do A4). Nenhum nome nem login e lido ou exibido.
-- O ensaio usa a 024 como PREPARO, este arquivo no lugar da migration e o
-- rollback da 024:
--   sudo env PREPARO=migrations/20261117_024_critical_roles.sql \
--     DADOS=portal_schedule_rules,portal_schedule_exceptions \
--     VERIFICA=migrations/verificacao/20261117_024_critical_roles_check.sql \
--     bash scripts/ensaio-migration.sh pc_024_rollback \
--     migrations/rollback/20261117_024_ensaio_linhas_pc.sql \
--     migrations/rollback/20261117_024_critical_roles_down.sql audit_log
-- Esperado: depois do "migration" (este arquivo), papeis = 2, rodizios = 1,
-- trocas = 1 e as contagens de problema em 0 (igual na 2a vez); depois do 1o
-- rollback, tabelas_024 = 0, copias_de_rollback_024 = 3 e a assinatura das
-- regras igual a do estado inicial; o 2o rollback PARA de proposito (copias
-- ja existem).

SET time_zone = 'America/Sao_Paulo';

UPDATE funcionarios f
  JOIN (SELECT MIN(id) AS id FROM funcionarios WHERE ativo = 1 AND LOWER(equipe) = 'n2') AS alvo ON alvo.id = f.id
   SET f.equipe = 'critico', f.grupo_escala = 'separado'
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM funcionarios WHERE ativo = 1 AND LOWER(equipe) = 'critico') AS c);

INSERT INTO portal_critical_roles (name, presence_type, work_start, work_end, active, sort_order, created_by, updated_by)
SELECT 'Abertura home ensaio', 'remote', '07:00:00', '17:00:00', 1, 1, 'qa.ensaio', 'qa.ensaio'
  FROM DUAL
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND NOT EXISTS (SELECT 1 FROM portal_critical_roles WHERE name = 'Abertura home ensaio');

INSERT INTO portal_critical_roles (name, presence_type, work_start, work_end, active, sort_order, created_by, updated_by)
SELECT 'Presencial ensaio', 'onsite', '08:00:00', '17:00:00', 1, 2, 'qa.ensaio', 'qa.ensaio'
  FROM DUAL
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND NOT EXISTS (SELECT 1 FROM portal_critical_roles WHERE name = 'Presencial ensaio');

INSERT INTO portal_critical_role_rotations (effective_from, period, anchor_date, roles, employees, created_by, updated_by)
SELECT '2026-11-16', 'week', '2026-11-16',
       JSON_ARRAY(a.id, p.id),
       JSON_ARRAY((SELECT MIN(id) FROM funcionarios WHERE ativo = 1 AND LOWER(equipe) = 'critico')),
       'qa.ensaio', 'qa.ensaio'
  FROM portal_critical_roles a
  JOIN portal_critical_roles p ON p.name = 'Presencial ensaio'
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND a.name = 'Abertura home ensaio'
   AND EXISTS (SELECT 1 FROM funcionarios WHERE ativo = 1 AND LOWER(equipe) = 'critico')
   AND NOT EXISTS (SELECT 1 FROM portal_critical_role_rotations WHERE effective_from = '2026-11-16');

INSERT INTO portal_critical_role_overrides (employee_id, starts_on, ends_on, role_id, reason, created_by, updated_by)
SELECT (SELECT MIN(id) FROM funcionarios WHERE ativo = 1 AND LOWER(equipe) = 'critico'), '2026-11-18', '2026-11-18', p.id, 'troca', 'qa.ensaio', 'qa.ensaio'
  FROM portal_critical_roles p
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND p.name = 'Presencial ensaio'
   AND EXISTS (SELECT 1 FROM funcionarios WHERE ativo = 1 AND LOWER(equipe) = 'critico')
   AND NOT EXISTS (SELECT 1 FROM portal_critical_role_overrides WHERE starts_on = '2026-11-18');
