-- Linhas no formato do F9 para ensaiar o ROLLBACK da 020 com dados
-- (scripts/ensaio-migration.sh). So para o banco descartavel ensaio_<nome>:
-- em qualquer outro banco nada e gravado (condicao DATABASE() LIKE
-- 'ensaio\_%' em cada comando). Idempotente: a 2a execucao nao grava de novo.
--
-- Grava, na copia: o cadastro N2 ativo de menor id passa para a equipe
-- 'critico', no grupo 'separado' da 021 (o que a tela grava; D-F9-12), e uma pausa "Almoço" dele, finalizada,
-- com o limite aplicado de 3600 s e sem alerta de 15/20 min. Datas
-- explicitas (regra do A4). Nenhum nome nem login e lido ou exibido.
-- O ensaio usa a 020 como PREPARO, este arquivo no lugar da migration e o
-- rollback da 020:
--   sudo env PREPARO=migrations/20261116_020_teams_critico_hibrido.sql \
--     DADOS=portal_absences,pausas \
--     VERIFICA=migrations/verificacao/20261116_020_teams_critico_hibrido_check.sql \
--     bash scripts/ensaio-migration.sh f9_020_rollback \
--     migrations/rollback/20261116_020_ensaio_linhas_f9.sql \
--     migrations/rollback/20261116_020_teams_critico_hibrido_down.sql \
--     portal_schedule_rules,portal_schedule_exceptions,portal_pa_assignments,portal_shift_attachments,portal_overtime_entries,portal_time_adjustments,portal_oncall_shifts,audit_log
-- Esperado: depois do "migration" (este arquivo), um cadastro a mais em
-- 'critico', pausas_com_limite = 1, almoco_fora_do_critico = 0 e
-- almoco_com_alerta = 0 e critico_ou_hibrido_no_grupo_comum = 0 (igual na 2a vez); os DOIS rollbacks PARAM de
-- proposito com "rollback_020 abortado: ha linhas com equipe critico ou
-- hibrido" e nada muda (coluna_020 = 1, enums_com_critico_020 = 8,
-- copias_de_rollback_020 = 0). Sem N2 ativo na copia, nada e gravado e o
-- rollback passa (variante sem efeito: registrar).

SET time_zone = 'America/Sao_Paulo';

UPDATE funcionarios f
  JOIN (SELECT MIN(n.id) AS id FROM funcionarios n
         WHERE n.ativo = 1 AND LOWER(TRIM(n.equipe)) = 'n2'
           AND NOT EXISTS (SELECT 1 FROM funcionarios c WHERE LOWER(TRIM(c.equipe)) = 'critico')) AS alvo ON alvo.id = f.id
   SET f.equipe = 'critico', f.grupo_escala = 'separado'
 WHERE DATABASE() LIKE 'ensaio\_%';

INSERT INTO pausas (id_funcionario, nome_funcionario, equipe, inicio_pausa, fim_pausa, duracao_segundos,
                    motivo_pausa, alerta_15min, alerta_20min, limite_segundos)
SELECT f.id, 'Pessoa ensaio F9', 'critico', '2026-11-16 12:00:00', '2026-11-16 12:50:00', 3000,
       'Almoço', 0, 0, 3600
  FROM (SELECT MIN(id) AS id FROM funcionarios WHERE LOWER(TRIM(equipe)) = 'critico') AS f
 WHERE DATABASE() LIKE 'ensaio\_%'
   AND f.id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM pausas WHERE motivo_pausa = 'Almoço' AND equipe = 'critico');
