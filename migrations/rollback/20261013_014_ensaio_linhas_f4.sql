-- Cadastros arquivados no formato do F4 para ensaiar o ROLLBACK da 014 com
-- dados (scripts/ensaio-migration.sh). So para o banco descartavel
-- ensaio_<nome>: em qualquer outro banco o UPDATE nao altera nada (condicao
-- DATABASE()). Idempotente: a 2a execucao nao altera de novo (marca
-- arquivado_por = 'qa.ensaio').
--
-- Arquiva, na copia, o cadastro ativo de menor id (arquivamento novo) e, se
-- houver, o inativo de menor id (regularizacao de inativo antigo, D5), com
-- data explicita (regra do A4). Nenhum nome nem login e lido ou exibido.
-- O ensaio usa a 014 como PREPARO, este arquivo no lugar da migration e o
-- rollback da 014:
--   sudo env PREPARO=migrations/20261013_014_employee_archive.sql \
--     VERIFICA=migrations/verificacao/20261013_014_employee_archive_check.sql \
--     bash scripts/ensaio-migration.sh f4_rollback \
--     migrations/rollback/20261013_014_ensaio_linhas_f4.sql \
--     migrations/rollback/20261013_014_employee_archive_down.sql
-- Esperado: depois do "migration" (este arquivo), arquivados_f4 = 2 (1 se a
-- copia nao tiver inativo), inativos = inativos da origem + 1,
-- ativos_com_data = 0 e arquivados_sem_autor = 0 (igual na 2a vez); depois
-- do 1o rollback, colunas_014 = 0, check_014 = 0, copia_rollback = 1 e
-- inativos igual ao de antes do rollback (ativo = 0 fica); o 2o rollback PARA
-- de proposito (copia ja existe).

SET time_zone = 'America/Sao_Paulo';

UPDATE funcionarios f
  JOIN (
        SELECT MIN(id) AS id FROM funcionarios WHERE ativo = 1
        UNION ALL
        SELECT MIN(id) FROM funcionarios WHERE ativo = 0
  ) AS alvo ON alvo.id = f.id
  JOIN (SELECT COUNT(*) AS n FROM funcionarios WHERE arquivado_por = 'qa.ensaio') AS ja ON ja.n = 0
   SET f.ativo = 0,
       f.arquivado_em = '2026-10-13 10:00:00',
       f.arquivado_por = 'qa.ensaio'
 WHERE DATABASE() LIKE 'ensaio\_%';
