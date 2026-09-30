-- ============================================================================
-- BIZ-02 (fuso horario) - 4 de 4: ROLLBACK DA CORRECAO (altera dados)
--
-- NAO E MIGRATION DE DEPLOY. Procedimento: docs/FUSO_HORARIO_BIZ02.md.
--
-- Desfaz o script 3: devolve a cada linha corrigida o valor fotografado no
-- corte (em UTC). So mexe na linha se o valor atual ainda e exatamente o valor
-- corrigido (valor do corte - 3 h): o que a aplicacao regravou depois fica
-- como esta.
--
-- NAO apaga a fotografia nem o marcador do corte: o script 3 pode ser aplicado
-- de novo depois. Nao desfaz o codigo: com o codigo corrigido no ar, as linhas
-- gravadas depois do corte continuam no fuso certo.
--
-- Idempotente: rodar de novo nao altera nada.
--
-- Uso: mysql <BANCO> < biz02_04_rollback.sql
-- ============================================================================

SET time_zone = '-03:00';

SET @corte_registrado := (SELECT COUNT(*) FROM manutencao_marcadores WHERE chave = 'biz02_corte');

START TRANSACTION;

UPDATE audit_log t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'audit_log' AND s.coluna = 'timestamp' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.`timestamp` = s.valor_utc,
    s.corrigido_em = NULL
WHERE @corte_registrado = 1 AND s.corrigido_em IS NOT NULL AND t.`timestamp` = s.valor_utc - INTERVAL 3 HOUR;

UPDATE portal_notification_reads t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_notification_reads' AND s.coluna = 'read_at'
 AND s.linha_id = t.notification_id AND s.linha_chave = t.username
SET t.read_at = s.valor_utc,
    s.corrigido_em = NULL
WHERE @corte_registrado = 1 AND s.corrigido_em IS NOT NULL AND t.read_at = s.valor_utc - INTERVAL 3 HOUR;

UPDATE pause_approval_requests t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'pause_approval_requests' AND s.coluna = 'decided_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.decided_at = s.valor_utc,
    s.corrigido_em = NULL
WHERE @corte_registrado = 1 AND s.corrigido_em IS NOT NULL AND t.decided_at = s.valor_utc - INTERVAL 3 HOUR;

UPDATE portal_overtime_entries t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_overtime_entries' AND s.coluna = 'approved_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.approved_at = s.valor_utc,
    t.updated_at = t.updated_at,
    s.corrigido_em = NULL
WHERE @corte_registrado = 1 AND s.corrigido_em IS NOT NULL AND t.approved_at = s.valor_utc - INTERVAL 3 HOUR;

UPDATE portal_time_adjustments t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_time_adjustments' AND s.coluna = 'approved_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.approved_at = s.valor_utc,
    t.updated_at = t.updated_at,
    s.corrigido_em = NULL
WHERE @corte_registrado = 1 AND s.corrigido_em IS NOT NULL AND t.approved_at = s.valor_utc - INTERVAL 3 HOUR;

UPDATE portal_pa_assignments t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_pa_assignments' AND s.coluna = 'deleted_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.deleted_at = s.valor_utc,
    t.updated_at = t.updated_at,
    s.corrigido_em = NULL
WHERE @corte_registrado = 1 AND s.corrigido_em IS NOT NULL AND t.deleted_at = s.valor_utc - INTERVAL 3 HOUR;

UPDATE portal_pa_map t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_pa_map' AND s.coluna = 'deleted_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.deleted_at = s.valor_utc,
    t.updated_at = t.updated_at,
    s.corrigido_em = NULL
WHERE @corte_registrado = 1 AND s.corrigido_em IS NOT NULL AND t.deleted_at = s.valor_utc - INTERVAL 3 HOUR;

UPDATE portal_critical_incidents t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_critical_incidents' AND s.coluna = 'war_room_started_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.war_room_started_at = s.valor_utc,
    t.updated_at = t.updated_at,
    s.corrigido_em = NULL
WHERE @corte_registrado = 1 AND s.corrigido_em IS NOT NULL AND t.war_room_started_at = s.valor_utc - INTERVAL 3 HOUR;

UPDATE portal_critical_incidents t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_critical_incidents' AND s.coluna = 'room_opened_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.room_opened_at = s.valor_utc,
    t.updated_at = t.updated_at,
    s.corrigido_em = NULL
WHERE @corte_registrado = 1 AND s.corrigido_em IS NOT NULL AND t.room_opened_at = s.valor_utc - INTERVAL 3 HOUR;

UPDATE portal_critical_incidents t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_critical_incidents' AND s.coluna = 'mitigated_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.mitigated_at = s.valor_utc,
    t.updated_at = t.updated_at,
    s.corrigido_em = NULL
WHERE @corte_registrado = 1 AND s.corrigido_em IS NOT NULL AND t.mitigated_at = s.valor_utc - INTERVAL 3 HOUR;

UPDATE portal_critical_incidents t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_critical_incidents' AND s.coluna = 'resolved_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.resolved_at = s.valor_utc,
    t.updated_at = t.updated_at,
    s.corrigido_em = NULL
WHERE @corte_registrado = 1 AND s.corrigido_em IS NOT NULL AND t.resolved_at = s.valor_utc - INTERVAL 3 HOUR;

UPDATE portal_critical_incidents t
JOIN manutencao_biz02_datetime s
  ON s.tabela = 'portal_critical_incidents' AND s.coluna = 'normalized_at' AND s.linha_id = t.id AND s.linha_chave = ''
SET t.normalized_at = s.valor_utc,
    t.updated_at = t.updated_at,
    s.corrigido_em = NULL
WHERE @corte_registrado = 1 AND s.corrigido_em IS NOT NULL AND t.normalized_at = s.valor_utc - INTERVAL 3 HOUR;

DELETE FROM manutencao_marcadores WHERE chave = 'biz02_correcao' AND @corte_registrado = 1;

COMMIT;

SELECT IF(@corte_registrado = 1, 'ROLLBACK EXECUTADO', 'ABORTADO: corte nao registrado') AS resultado;

SELECT chave, registrado_em, detalhe FROM manutencao_marcadores WHERE chave LIKE 'biz02%' ORDER BY chave;

-- Esperado: ainda_corrigidas = 0. Linha que sobrar foi regravada pela aplicacao
-- depois da correcao e nao voltou.
SELECT tabela, coluna,
       COUNT(*) AS fotografadas,
       SUM(corrigido_em IS NOT NULL) AS ainda_corrigidas
FROM manutencao_biz02_datetime
GROUP BY tabela, coluna
ORDER BY tabela, coluna;
