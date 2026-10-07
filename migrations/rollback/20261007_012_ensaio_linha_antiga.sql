-- PREPARO do ensaio da 012 que confere a PARADA com tabela nao vazia
-- (scripts/ensaio-migration.sh). So para o banco descartavel ensaio_<nome>:
-- em qualquer outro banco o INSERT nao grava nada (condicao DATABASE()).
--
-- Grava UMA ausencia ficticia no formato antigo (antes da 012), no cadastro
-- de menor id da copia, sem nome real nem texto. Com ela, a 012 deve parar nas
-- duas execucoes com "migration_012 abortada: portal_absences tem linhas
-- ...", sem alterar nada (a verificacao mostra colunas_012 = 0 e
-- absence_type = 1 em todos os estados).
--
-- Uso (no servidor, a partir de /var/www/chronodesk):
--   sudo env DADOS=portal_absences \
--     PREPARO=migrations/rollback/20261007_012_ensaio_linha_antiga.sql \
--     VERIFICA=migrations/verificacao/20261007_012_absences_check.sql \
--     bash scripts/ensaio-migration.sh f3_parada \
--     migrations/20261007_012_absences.sql \
--     migrations/rollback/20261007_012_absences_down.sql

SET time_zone = 'America/Sao_Paulo';

INSERT INTO portal_absences (employee_id, employee_name, absence_type, starts_on, ends_on, status)
SELECT f.id, 'Ensaio QA', 'folga', '2026-10-05', '2026-10-05', 'approved'
  FROM (SELECT MIN(id) AS id FROM funcionarios) AS f
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND f.id IS NOT NULL;
