-- Linhas no formato do F10-2 para ensaiar o ROLLBACK da 021 com dados
-- (scripts/ensaio-migration.sh). So para o banco descartavel ensaio_<nome>:
-- em qualquer outro banco nada e gravado (condicao DATABASE() LIKE
-- 'ensaio\_%' em cada comando). Idempotente: a 2a execucao nao grava de novo
-- (o time "Time ensaio F10" ja existe; a pertenca e a marcacao tambem).
--
-- Grava, na copia: o time "Time ensaio F10" com uma versao (ciclo de 3
-- posicoes, ancora na segunda 2026-11-16); o cadastro N1 ativo de menor id
-- com regra aberta vira membro desde o inicio dessa regra, e a regra aberta
-- dele passa a ter o rotation_team_id do time (o que o servico grava); o
-- cadastro N2 ativo de menor id fica no grupo 'separado'. Datas explicitas
-- (regra do A4). Nenhum nome nem login e lido ou exibido.
-- O ensaio usa a 021 como PREPARO, este arquivo no lugar da migration e o
-- rollback da 021:
--   sudo env PREPARO=migrations/20261115_021_rotation_teams.sql \
--     DADOS=portal_schedule_rules,portal_schedule_exceptions \
--     VERIFICA=migrations/verificacao/20261115_021_rotation_teams_check.sql \
--     bash scripts/ensaio-migration.sh f10_021_rollback \
--     migrations/rollback/20261115_021_ensaio_linhas_f10.sql \
--     migrations/rollback/20261115_021_rotation_teams_down.sql audit_log
-- Esperado: depois do "migration" (este arquivo), times = 1, versoes = 1,
-- pertencas_abertas = 1, regras_de_time = 1, membros_abertos_sem_regra_aberta_do_time
-- = 0, regras_de_time_sem_membro = 0 e um cadastro no grupo 'separado' (igual
-- na 2a vez; 0 nos tres primeiros se a copia nao tiver N1 ativo com regra
-- aberta); depois do 1o rollback, tabelas_021 = 0, colunas_021 = 0,
-- copias_de_rollback_021 = 5 e a assinatura das regras igual a do estado
-- inicial (a regra do membro fica, so a marcacao sai); o 2o rollback PARA de
-- proposito (copias ja existem).

SET time_zone = 'America/Sao_Paulo';

INSERT INTO portal_rotation_teams (name, active, created_by, updated_by)
SELECT 'Time ensaio F10', 1, 'qa.ensaio', 'qa.ensaio'
  FROM DUAL
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND NOT EXISTS (SELECT 1 FROM portal_rotation_teams WHERE name = 'Time ensaio F10');

INSERT INTO portal_rotation_team_versions (team_id, effective_from, pattern, anchor_date, weeks, created_by, updated_by)
SELECT t.id, '2026-11-16', JSON_ARRAY('remote', 'onsite', 'onsite'), '2026-11-16', NULL, 'qa.ensaio', 'qa.ensaio'
  FROM portal_rotation_teams t
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND t.name = 'Time ensaio F10'
   AND NOT EXISTS (SELECT 1 FROM portal_rotation_team_versions v WHERE v.team_id = t.id);

INSERT INTO portal_rotation_team_members (team_id, employee_id, valid_from, valid_until, created_by, updated_by)
SELECT t.id, r.employee_id, r.effective_from, NULL, 'qa.ensaio', 'qa.ensaio'
  FROM portal_rotation_teams t
  JOIN portal_schedule_rules r
    ON r.effective_until IS NULL
   AND r.employee_id = (
        SELECT MIN(f.id) FROM funcionarios f
          JOIN portal_schedule_rules a ON a.employee_id = f.id AND a.effective_until IS NULL
         WHERE f.ativo = 1 AND LOWER(f.equipe) = 'n1'
   )
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND t.name = 'Time ensaio F10'
   AND NOT EXISTS (SELECT 1 FROM portal_rotation_team_members m WHERE m.team_id = t.id);

UPDATE portal_schedule_rules r
  JOIN portal_rotation_team_members m ON m.employee_id = r.employee_id AND m.valid_until IS NULL
  JOIN portal_rotation_teams t ON t.id = m.team_id AND t.name = 'Time ensaio F10'
   SET r.rotation_team_id = t.id
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND r.effective_until IS NULL
   AND r.rotation_team_id IS NULL;

UPDATE funcionarios f
  JOIN (SELECT MIN(id) AS id FROM funcionarios WHERE ativo = 1 AND LOWER(equipe) = 'n2') AS alvo ON alvo.id = f.id
   SET f.grupo_escala = 'separado'
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND f.grupo_escala = 'comum';
