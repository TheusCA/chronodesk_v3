-- Linhas ficticias no formato do lote NC para ensaiar o ROLLBACK da 026 com
-- dados (scripts/ensaio-migration.sh). So para o banco descartavel
-- ensaio_<nome>: em qualquer outro banco nada e gravado (condicao DATABASE()
-- LIKE 'ensaio\_%' em cada comando). Idempotente: a 2a execucao nao grava de
-- novo (os NC ja existem).
--
-- NC ficticio, nunca uma matricula real: '9' seguido do id do cadastro com 8
-- digitos (unico por cadastro). Grava nos 3 cadastros ativos de menor id e
-- copia o NC do cadastro em todos os lancamentos de hora extra e de correcao
-- de ponto deles (a copia que o servidor grava no lancamento). Nenhum nome,
-- login nem valor e lido ou exibido.
-- O ensaio usa a 026 como PREPARO, este arquivo no lugar da migration e o
-- rollback da 026:
--   sudo env PREPARO=migrations/20261123_026_matricula_nc.sql \
--     DADOS=portal_overtime_entries,portal_time_adjustments \
--     VERIFICA=migrations/verificacao/20261123_026_matricula_nc_check.sql \
--     bash scripts/ensaio-migration.sh nc_026_rollback \
--     migrations/rollback/20261123_026_ensaio_linhas_nc.sql \
--     migrations/rollback/20261123_026_matricula_nc_down.sql audit_log
-- Esperado: depois do "migration" (este arquivo), ativos_com_nc = 3 (menos se
-- a copia tiver menos de 3 cadastros ativos), os lancamentos desses cadastros
-- com a copia e as contagens de problema em 0 (igual na 2a vez); depois do 1o
-- rollback, colunas_026 = 0, copias_de_rollback_026 = 3 e as assinaturas
-- iguais as do estado inicial; o 2o rollback PARA de proposito.

SET time_zone = 'America/Sao_Paulo';

-- Lido antes do UPDATE (a tabela alterada nao entra na subconsulta dele).
SET @ja_tem_nc := (SELECT COUNT(*) FROM funcionarios WHERE matricula IS NOT NULL);

UPDATE funcionarios f
  JOIN (
        SELECT id FROM (
            SELECT id FROM funcionarios WHERE ativo = 1 ORDER BY id LIMIT 3
        ) AS primeiros
  ) AS alvo ON alvo.id = f.id
   SET f.matricula = CONCAT('9', LPAD(f.id, 8, '0'))
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND @ja_tem_nc = 0;

UPDATE portal_overtime_entries o
  JOIN funcionarios f ON f.id = o.employee_id
   SET o.employee_nc = f.matricula
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND f.matricula IS NOT NULL
   AND o.employee_nc IS NULL;

UPDATE portal_time_adjustments t
  JOIN funcionarios f ON f.id = t.employee_id
   SET t.employee_nc = f.matricula
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND f.matricula IS NOT NULL
   AND t.employee_nc IS NULL;
