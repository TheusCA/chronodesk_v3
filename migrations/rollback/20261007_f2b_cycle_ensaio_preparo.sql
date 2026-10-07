-- PREPARO do ensaio do rollback do F2B (scripts/ensaio-migration.sh).
-- So para o banco descartavel ensaio_<nome>: em qualquer outro banco o
-- UPDATE nao muda nada (condicao DATABASE() LIKE 'ensaio_%').
--
-- Antes do deploy do F2B nao existe regra cycle no servidor; sem isto o
-- ensaio passaria sem exercitar nada. Transforma a regra aberta de menor id
-- da copia em um ciclo ficticio 1R/1P, sem nome nem id fixo.
--
-- Uso (no servidor, a partir de /var/www/chronodesk):
--   sudo env DADOS=portal_schedule_rules,portal_pa_assignments \
--     PREPARO=migrations/rollback/20261007_f2b_cycle_ensaio_preparo.sql \
--     VERIFICA=migrations/verificacao/20261007_f2b_cycle_check.sql \
--     bash scripts/ensaio-migration.sh f2b \
--     migrations/rollback/20261007_f2b_cycle_down.sql \
--     migrations/rollback/20261007_f2b_cycle_up.sql
-- O ensaio roda o "down" duas vezes e o "up" duas vezes. Esperado na
-- verificacao: estado inicial regras_cycle = 1; depois do down,
-- regras_cycle = 0 e undefined_com_pattern = 1 (igual na 2a vez); depois do
-- up, regras_cycle = 1 e undefined_com_pattern = 0 (igual na 2a vez).

SET time_zone = 'America/Sao_Paulo';

UPDATE portal_schedule_rules
   SET rule_type = 'cycle',
       rule_config = '{"pattern":["remote","onsite"],"anchor_date":"2026-10-05","business_days_only":true}'
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND id = (SELECT id FROM (SELECT MIN(id) AS id FROM portal_schedule_rules WHERE effective_until IS NULL) AS primeira);
