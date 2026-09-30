-- ============================================================================
-- BIZ-02 (fuso horario) - 3 de 4: CORRIGIR (altera dados)
--
-- NAO E MIGRATION DE DEPLOY. Procedimento: docs/FUSO_HORARIO_BIZ02.md.
--
-- PRE-REQUISITOS, nesta ordem:
--   1. backup instalado e com teste de restauracao aprovado (DEPLOY_LINUX.md);
--   2. corte registrado (script 1) na janela do deploy do codigo corrigido;
--   3. ensaio deste script no banco de teste de restauracao, com o script 2
--      antes e depois;
--   4. backup feito imediatamente antes;
--   5. saida do script 2 guardada.
--
-- O que faz: para cada linha fotografada no corte, subtrai 3 horas do DATETIME
-- que foi gravado em UTC. So altera a linha se o valor atual ainda e o valor
-- fotografado: linha regravada pela aplicacao depois do corte nao e tocada.
--
-- Idempotente de duas formas: cada linha fotografada e marcada (corrigido_em)
-- e so e corrigida se ainda tiver o valor do corte. Rodar de novo nao desloca
-- nada pela segunda vez.
--
-- Nao altera updated_at: as colunas TIMESTAMP guardam o instante verdadeiro e
-- continuam valendo como referencia.
--
-- Tudo em uma transacao. Qualquer erro encerra o cliente mysql e desfaz tudo.
-- Sem o corte registrado, o script para no primeiro comando.
--
-- Uso: mysql <BANCO> < biz02_03_corrigir.sql
-- ============================================================================

SET time_zone = '-03:00';

SET @corte_registrado := (SELECT COUNT(*) FROM manutencao_marcadores WHERE chave = 'biz02_corte');
SET @agora := NOW();

START TRANSACTION;

UPDATE audit_log t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'audit_log' AND s.coluna = 'timestamp' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.`timestamp` = s.valor_utc - INTERVAL 3 HOUR,
    s.corrigido_em = @agora
WHERE @corte_registrado = 1 AND s.corrigido_em IS NULL AND t.`timestamp` = s.valor_utc;

UPDATE portal_notification_reads t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_notification_reads' AND s.coluna = 'read_at'
 AND s.linha_id = t.notification_id AND s.linha_chave = t.username
SET t.read_at = s.valor_utc - INTERVAL 3 HOUR,
    s.corrigido_em = @agora
WHERE @corte_registrado = 1 AND s.corrigido_em IS NULL AND t.read_at = s.valor_utc;

UPDATE pause_approval_requests t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'pause_approval_requests' AND s.coluna = 'decided_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.decided_at = s.valor_utc - INTERVAL 3 HOUR,
    s.corrigido_em = @agora
WHERE @corte_registrado = 1 AND s.corrigido_em IS NULL AND t.decided_at = s.valor_utc;

UPDATE portal_overtime_entries t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_overtime_entries' AND s.coluna = 'approved_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.approved_at = s.valor_utc - INTERVAL 3 HOUR,
    t.updated_at = t.updated_at,
    s.corrigido_em = @agora
WHERE @corte_registrado = 1 AND s.corrigido_em IS NULL AND t.approved_at = s.valor_utc;

UPDATE portal_time_adjustments t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_time_adjustments' AND s.coluna = 'approved_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.approved_at = s.valor_utc - INTERVAL 3 HOUR,
    t.updated_at = t.updated_at,
    s.corrigido_em = @agora
WHERE @corte_registrado = 1 AND s.corrigido_em IS NULL AND t.approved_at = s.valor_utc;

UPDATE portal_pa_assignments t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_pa_assignments' AND s.coluna = 'deleted_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.deleted_at = s.valor_utc - INTERVAL 3 HOUR,
    t.updated_at = t.updated_at,
    s.corrigido_em = @agora
WHERE @corte_registrado = 1 AND s.corrigido_em IS NULL AND t.deleted_at = s.valor_utc;

UPDATE portal_pa_map t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_pa_map' AND s.coluna = 'deleted_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.deleted_at = s.valor_utc - INTERVAL 3 HOUR,
    t.updated_at = t.updated_at,
    s.corrigido_em = @agora
WHERE @corte_registrado = 1 AND s.corrigido_em IS NULL AND t.deleted_at = s.valor_utc;

UPDATE portal_critical_incidents t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_critical_incidents' AND s.coluna = 'war_room_started_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.war_room_started_at = s.valor_utc - INTERVAL 3 HOUR,
    t.updated_at = t.updated_at,
    s.corrigido_em = @agora
WHERE @corte_registrado = 1 AND s.corrigido_em IS NULL AND t.war_room_started_at = s.valor_utc;

UPDATE portal_critical_incidents t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_critical_incidents' AND s.coluna = 'room_opened_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.room_opened_at = s.valor_utc - INTERVAL 3 HOUR,
    t.updated_at = t.updated_at,
    s.corrigido_em = @agora
WHERE @corte_registrado = 1 AND s.corrigido_em IS NULL AND t.room_opened_at = s.valor_utc;

UPDATE portal_critical_incidents t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_critical_incidents' AND s.coluna = 'mitigated_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.mitigated_at = s.valor_utc - INTERVAL 3 HOUR,
    t.updated_at = t.updated_at,
    s.corrigido_em = @agora
WHERE @corte_registrado = 1 AND s.corrigido_em IS NULL AND t.mitigated_at = s.valor_utc;

UPDATE portal_critical_incidents t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_critical_incidents' AND s.coluna = 'resolved_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.resolved_at = s.valor_utc - INTERVAL 3 HOUR,
    t.updated_at = t.updated_at,
    s.corrigido_em = @agora
WHERE @corte_registrado = 1 AND s.corrigido_em IS NULL AND t.resolved_at = s.valor_utc;

UPDATE portal_critical_incidents t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_critical_incidents' AND s.coluna = 'normalized_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.normalized_at = s.valor_utc - INTERVAL 3 HOUR,
    t.updated_at = t.updated_at,
    s.corrigido_em = @agora
WHERE @corte_registrado = 1 AND s.corrigido_em IS NULL AND t.normalized_at = s.valor_utc;

-- Marcador de aplicacao. A primeira execucao cria a linha; as seguintes so
-- atualizam o detalhe e preservam a data da primeira.
SET @linhas_corrigidas := (SELECT COUNT(*) FROM manutencao_biz02_datetime WHERE corrigido_em IS NOT NULL);

INSERT INTO manutencao_marcadores (chave, registrado_em, detalhe)
SELECT 'biz02_correcao', @agora, CONCAT('linhas_corrigidas=', @linhas_corrigidas)
FROM DUAL
WHERE @corte_registrado = 1
ON DUPLICATE KEY UPDATE
    detalhe = CONCAT('linhas_corrigidas=', @linhas_corrigidas, '; ultima_execucao=', @agora);

COMMIT;

SELECT IF(@corte_registrado = 1, 'CORRECAO EXECUTADA', 'ABORTADO: corte nao registrado (rode o script 1)') AS resultado;

SELECT chave, registrado_em, detalhe FROM manutencao_marcadores WHERE chave LIKE 'biz02%' ORDER BY chave;

SELECT tabela, coluna,
       COUNT(*) AS fotografadas,
       SUM(corrigido_em IS NOT NULL) AS corrigidas,
       SUM(corrigido_em IS NULL) AS nao_corrigidas
FROM manutencao_biz02_datetime
GROUP BY tabela, coluna
ORDER BY tabela, coluna;
