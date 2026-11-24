-- Linhas ficticias no formato da 027 para ensaiar o ROLLBACK da 027 com dados
-- (scripts/ensaio-migration.sh). So para o banco descartavel ensaio_<nome>:
-- em qualquer outro banco nada e gravado (condicao DATABASE() LIKE
-- 'ensaio\_%' em cada comando). Idempotente: a 2a execucao nao grava de novo.
--
-- Grava: um feriado ficticio (25/12 do ano que vem, "Feriado de ensaio"); a
-- cor 'turquesa' no papel de menor id que esteja sem cor; uma ausencia
-- 'banco_horas' de dia inteiro (2 dias, fevereiro do ano que vem) no cadastro
-- ativo de menor id, com nome "Ficticio". Nenhum nome, login nem valor real e
-- lido ou exibido. Rodizio com papel repetido NAO entra aqui: o rollback para
-- de proposito com ele (conferido a parte, no ensaio local).
-- O ensaio usa a 027 como PREPARO, este arquivo no lugar da migration e o
-- rollback da 027:
--   sudo env PREPARO=migrations/20261124_027_critical_grid.sql \
--     DADOS=portal_critical_roles,portal_critical_role_rotations,portal_absences \
--     VERIFICA=migrations/verificacao/20261124_027_critical_grid_check.sql \
--     bash scripts/ensaio-migration.sh esc_027_rollback \
--     migrations/rollback/20261124_027_ensaio_linhas.sql \
--     migrations/rollback/20261124_027_critical_grid_down.sql audit_log
-- Esperado: depois do "migration" (este arquivo), feriados = 1,
-- ausencias_banco_horas = 1 e papeis_com_cor mais 1 (se havia papel sem cor),
-- igual na 2a vez; depois do 1o rollback, colunas_027 = 0, tabela_027 = 0,
-- enum_027 = 0, copias_de_rollback_027 = 3, a ausencia como 'outro' e as
-- assinaturas dos papeis e dos rodizios iguais as do estado inicial; o 2o
-- rollback PARA de proposito.

SET time_zone = 'America/Sao_Paulo';

SET @ano_que_vem := YEAR(CURDATE()) + 1;

INSERT INTO portal_holidays (holiday_date, name, scope, created_by, updated_by)
SELECT STR_TO_DATE(CONCAT(@ano_que_vem, '-12-25'), '%Y-%m-%d'),
       'Feriado de ensaio', 'todos', 'ensaio', 'ensaio'
  FROM DUAL
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND NOT EXISTS (SELECT 1 FROM portal_holidays WHERE name = 'Feriado de ensaio');

SET @papel_sem_cor := (SELECT MIN(id) FROM portal_critical_roles WHERE color_token IS NULL);
SET @ja_tem_turquesa := (SELECT COUNT(*) FROM portal_critical_roles WHERE color_token = 'turquesa');
UPDATE portal_critical_roles
   SET color_token = 'turquesa'
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND id = @papel_sem_cor
   AND @ja_tem_turquesa = 0;

SET @cadastro := (SELECT MIN(id) FROM funcionarios WHERE ativo = 1);
INSERT INTO portal_absences (employee_id, employee_name, team, category, starts_on, ends_on, period_type, status, created_by)
SELECT @cadastro, 'Ficticio', 'critico', 'banco_horas',
       MAKEDATE(@ano_que_vem, 40), MAKEDATE(@ano_que_vem, 41), 'full_day', 'approved', 'ensaio'
  FROM DUAL
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND @cadastro IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM portal_absences WHERE category = 'banco_horas' AND created_by = 'ensaio');
