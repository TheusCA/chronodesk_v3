-- Linhas no formato do Controle de CHG (9.17) para ensaiar o ROLLBACK da 025
-- com dados (scripts/ensaio-migration.sh). So para o banco descartavel
-- ensaio_<nome>: em qualquer outro banco nada e gravado (condicao DATABASE()
-- LIKE 'ensaio\_%' em cada comando). Idempotente: a 2a execucao nao grava de
-- novo. Tudo ficticio ("Carteira Teste 01", "Local Teste 01", "CHG0000001",
-- endpoint.teste); nenhum nome nem login e lido ou exibido.
--
-- Grava, na copia: o cadastro ativo de menor id que nao e somente leitura no
-- grupo Tools; uma carteira e um local no catalogo; um card aprovado com essa
-- carteira e esse local, o Plano 1, a 1a tentativa com um endpoint e um
-- rollback ativo, com o tecnico = o mesmo cadastro. Datas explicitas (regra do
-- A4).
-- O ensaio usa a 025 como PREPARO, este arquivo no lugar da migration e o
-- rollback da 025:
--   sudo env PREPARO=migrations/20261117_025_chg.sql \
--     VERIFICA=migrations/verificacao/20261117_025_chg_check.sql \
--     bash scripts/ensaio-migration.sh chg_025_rollback \
--     migrations/rollback/20261117_025_ensaio_linhas_chg.sql \
--     migrations/rollback/20261117_025_chg_down.sql audit_log
-- Esperado: depois do "migration" (este arquivo), grupo_tools = 1,
-- carteiras_catalogo = 1, locais_catalogo = 1, cards = 1, planos = 1,
-- tentativas = 1, endpoints = 1, rollbacks_ativos = 1 e as contagens de
-- problema em 0 (igual na 2a vez); depois do 1o rollback, tabelas_025 = 0,
-- copias_de_rollback_025 = 10 e a assinatura dos cadastros igual a do estado
-- inicial; o 2o rollback PARA de proposito (copias ja existem).

SET time_zone = 'America/Sao_Paulo';

INSERT INTO funcionario_grupos (funcionario_id, grupo, created_by)
SELECT MIN(id), 'tools', 'qa.ensaio'
  FROM funcionarios
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND ativo = 1 AND LOWER(TRIM(COALESCE(access_role, ''))) <> 'somente_leitura'
   AND NOT EXISTS (SELECT 1 FROM (SELECT funcionario_id FROM funcionario_grupos) AS g)
HAVING MIN(id) IS NOT NULL;

INSERT INTO portal_chg_carteiras (codigo, nome, ativo, created_by, updated_by)
SELECT '9001', 'Carteira Teste 01', 1, 'qa.ensaio', 'qa.ensaio'
  FROM DUAL
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND NOT EXISTS (SELECT 1 FROM portal_chg_carteiras WHERE codigo = '9001');

INSERT INTO portal_chg_locais (nome, ativo, created_by, updated_by)
SELECT 'Local Teste 01', 1, 'qa.ensaio', 'qa.ensaio'
  FROM DUAL
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND NOT EXISTS (SELECT 1 FROM portal_chg_locais WHERE nome = 'Local Teste 01');

INSERT INTO portal_chg_updates (legacy_id, solicitante, tipo, versao, sugestao_at, service_status, sd_inicio, sd_fim,
                                chg_numero, chg_status, tecnico_id, created_by, updated_by, created_at, updated_at)
SELECT 'ensaio-025-1', 'Solicitante Teste 01', 'padrao', 'Versao Teste 01', '2026-11-18 20:00:00', 'aprovado',
       '2026-11-18 20:00:00', '2026-11-19 20:00:00', 'CHG0000001', 'aprovada', g.funcionario_id,
       'qa.ensaio', 'qa.ensaio', '2026-11-17 10:00:00.000', '2026-11-17 10:00:00.000'
  FROM funcionario_grupos g
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND g.grupo = 'tools'
   AND NOT EXISTS (SELECT 1 FROM portal_chg_updates WHERE legacy_id = 'ensaio-025-1')
 LIMIT 1;

INSERT INTO portal_chg_update_carteiras (update_id, position, codigo, nome, pas)
SELECT u.id, 0, '9001', 'Carteira Teste 01', 12
  FROM portal_chg_updates u
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND u.legacy_id = 'ensaio-025-1'
   AND NOT EXISTS (SELECT 1 FROM portal_chg_update_carteiras c WHERE c.update_id = u.id);

INSERT INTO portal_chg_update_locais (update_id, position, nome)
SELECT u.id, 0, 'Local Teste 01'
  FROM portal_chg_updates u
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND u.legacy_id = 'ensaio-025-1'
   AND NOT EXISTS (SELECT 1 FROM portal_chg_update_locais l WHERE l.update_id = u.id);

INSERT INTO portal_chg_plans (update_id, position, implantacao, backup, rollback, task_impl, task_bkp, task_roll)
SELECT u.id, 0, 'CTASK0000001', 'N/A', 'CTASK0000002', 'fechada', 'pendente', 'pendente'
  FROM portal_chg_updates u
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND u.legacy_id = 'ensaio-025-1'
   AND NOT EXISTS (SELECT 1 FROM portal_chg_plans p WHERE p.update_id = u.id);

INSERT INTO portal_chg_attempts (update_id, attempt_no, patch_status, reagendando, registered_at)
SELECT u.id, 1, 'erro', 0, NULL
  FROM portal_chg_updates u
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND u.legacy_id = 'ensaio-025-1'
   AND NOT EXISTS (SELECT 1 FROM portal_chg_attempts a WHERE a.update_id = u.id);

INSERT INTO portal_chg_endpoints (attempt_id, position, url, start_at, start_date)
SELECT a.id, 0, 'https://endpoint.teste/1', '2026-11-18 21:00:00', NULL
  FROM portal_chg_attempts a
  JOIN portal_chg_updates u ON u.id = a.update_id
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND u.legacy_id = 'ensaio-025-1'
   AND NOT EXISTS (SELECT 1 FROM portal_chg_endpoints e WHERE e.attempt_id = a.id);

INSERT INTO portal_chg_rollbacks (update_id, url, start_at, started_at, status, plan_position, created_by)
SELECT u.id, 'https://endpoint.teste/rollback', '2026-11-18 22:00:00', '2026-11-18 22:00:00', 'em_andamento', 0, 'qa.ensaio'
  FROM portal_chg_updates u
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND u.legacy_id = 'ensaio-025-1'
   AND NOT EXISTS (SELECT 1 FROM portal_chg_rollbacks r WHERE r.update_id = u.id);
