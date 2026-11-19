-- Linhas no formato do inventario do F10-3 para ensaiar o ROLLBACK da 022 com
-- dados (scripts/ensaio-migration.sh). So para o banco descartavel
-- ensaio_<nome>: em qualquer outro banco nada e gravado (condicao DATABASE()
-- LIKE 'ensaio\_%' em cada comando). Idempotente: a 2a execucao nao grava de
-- novo (as marcacoes e a preferencia ja existem).
--
-- Grava, na copia, so nas colunas e na tabela da 022 (o numero, a fileira, a
-- coluna e a situacao dos PAs nao mudam; nenhum vinculo muda): os dois PAs
-- ativos de maior ordem SEM vinculo vigente ou futuro viram 'fixed' reservados
-- ao Critico; o PA ativo de menor ordem sem vinculo vigente ou futuro vira
-- 'fixed' que empresta; e o cadastro ativo de menor id do grupo comum (N1 ou
-- N2, grupo 'comum', fora do "fora do Mapa") ganha preferencia pelo PA
-- 'flexible' de menor ordem. Nenhum nome nem login e lido ou exibido.
-- O ensaio usa a 022 como PREPARO, este arquivo no lugar da migration e o
-- rollback da 022:
--   sudo env PREPARO=migrations/20261119_022_pa_inventory.sql \
--     DADOS=portal_pa_inventory,portal_pa_assignments \
--     VERIFICA=migrations/verificacao/20261119_022_pa_inventory_check.sql \
--     bash scripts/ensaio-migration.sh f10_022_rollback \
--     migrations/rollback/20261119_022_ensaio_linhas_inv.sql \
--     migrations/rollback/20261119_022_pa_inventory_down.sql audit_log
-- Esperado: depois do "migration" (este arquivo), reservados_critico = 2,
-- fixos = 1, fixos_que_emprestam = 1, preferencias = 1 e as contagens de
-- problema em 0 (igual na 2a vez; menos marcacoes se a copia tiver menos de 3
-- PAs sem vinculo vigente ou futuro, ou nenhum cadastro do grupo comum); depois
-- do 1o rollback, colunas_022 = 0, tabela_022 = 0, copias_de_rollback_022 = 2
-- e as assinaturas do inventario e dos vinculos iguais as do estado inicial;
-- o 2o rollback PARA de proposito (copias ja existem).

SET time_zone = 'America/Sao_Paulo';

-- Lidos antes de cada UPDATE (a tabela alterada nao entra na subconsulta dele).
SET @ja_tem_reserva := (SELECT COUNT(*) FROM portal_pa_inventory WHERE reserved_team IS NOT NULL);

UPDATE portal_pa_inventory i
  JOIN (
        SELECT id FROM (
            SELECT inv.id
              FROM portal_pa_inventory inv
             WHERE inv.active = 1 AND inv.seat_type = 'flexible'
               AND NOT EXISTS (SELECT 1 FROM portal_pa_assignments a
                                WHERE a.active = 1 AND a.pa_number = inv.pa_number
                                  AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()))
             ORDER BY inv.display_order DESC, inv.id DESC
             LIMIT 2
        ) AS livres
  ) AS alvo ON alvo.id = i.id
   SET i.seat_type = 'fixed', i.reserved_team = 'critico', i.can_lend = 0
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND @ja_tem_reserva = 0;

SET @ja_tem_fixo := (SELECT COUNT(*) FROM portal_pa_inventory WHERE seat_type = 'fixed' AND reserved_team IS NULL);

UPDATE portal_pa_inventory i
  JOIN (
        SELECT id FROM (
            SELECT inv.id
              FROM portal_pa_inventory inv
             WHERE inv.active = 1 AND inv.seat_type = 'flexible'
               AND NOT EXISTS (SELECT 1 FROM portal_pa_assignments a
                                WHERE a.active = 1 AND a.pa_number = inv.pa_number
                                  AND (a.valid_until IS NULL OR a.valid_until >= CURDATE()))
             ORDER BY inv.display_order, inv.id
             LIMIT 1
        ) AS livre
  ) AS alvo ON alvo.id = i.id
   SET i.seat_type = 'fixed', i.can_lend = 1
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND @ja_tem_fixo = 0;

INSERT INTO portal_pa_preferences (pa_id, employee_id, created_by)
SELECT (SELECT id FROM portal_pa_inventory WHERE active = 1 AND seat_type = 'flexible' ORDER BY display_order, id LIMIT 1),
       (SELECT id FROM funcionarios
         WHERE ativo = 1 AND LOWER(TRIM(equipe)) IN ('n1', 'n2') AND grupo_escala = 'comum' AND fora_mapa_escala = 0
         ORDER BY id LIMIT 1),
       'qa.ensaio'
  FROM DUAL
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND NOT EXISTS (SELECT 1 FROM portal_pa_preferences)
   AND EXISTS (SELECT 1 FROM portal_pa_inventory WHERE active = 1 AND seat_type = 'flexible')
   AND EXISTS (SELECT 1 FROM funcionarios
                WHERE ativo = 1 AND LOWER(TRIM(equipe)) IN ('n1', 'n2') AND grupo_escala = 'comum' AND fora_mapa_escala = 0);
