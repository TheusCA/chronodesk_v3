-- ============================================================================
-- BIZ-02 (fuso horario) - 2 de 4: VERIFICAR (somente leitura)
--
-- NAO E MIGRATION DE DEPLOY. Procedimento: docs/FUSO_HORARIO_BIZ02.md.
--
-- Rodar ANTES e DEPOIS do script 3 (e depois do script 4, se houver rollback),
-- guardando as duas saidas. Nao altera nada. So devolve contagens, datas e ids
-- de chamado critico; nenhum nome ou login.
--
-- Exige o corte registrado (script 1). Sem ele, para no primeiro SELECT com
-- "Table ... manutencao_marcadores doesn't exist".
--
-- Uso: mysql <BANCO> < biz02_02_verificar.sql
-- ============================================================================

SET time_zone = '-03:00';

-- V1. Relogios e marcadores.
SELECT @@global.time_zone AS fuso_global, @@system_time_zone AS fuso_sistema,
       UTC_TIMESTAMP() AS agora_utc, NOW() AS agora_brt;

SELECT chave, registrado_em, detalhe FROM manutencao_marcadores WHERE chave LIKE 'biz02%' ORDER BY chave;

-- V2. Situacao da fotografia, por coluna. Cada linha fotografada esta em
-- exatamente um estado.
--   a_corrigir        valor atual = valor do corte (ainda em UTC)
--   corrigida         valor atual = valor do corte - 3 h
--   alterada_depois   valor atual diferente dos dois: a aplicacao regravou a
--                     linha depois do corte; o script 3 nao toca nela
--   linha_removida    a linha de origem nao existe mais
-- ANTES do script 3:  corrigida = 0.
-- DEPOIS do script 3: a_corrigir = 0.
SELECT 'audit_log' AS tabela, 'timestamp' AS coluna,
       COUNT(*) AS fotografadas,
       SUM(t.`timestamp` <=> s.valor_utc) AS a_corrigir,
       SUM(t.`timestamp` <=> s.valor_utc - INTERVAL 3 HOUR) AS corrigida,
       SUM(t.id IS NOT NULL AND NOT (t.`timestamp` <=> s.valor_utc) AND NOT (t.`timestamp` <=> s.valor_utc - INTERVAL 3 HOUR)) AS alterada_depois,
       SUM(t.id IS NULL) AS linha_removida,
       SUM(s.corrigido_em IS NOT NULL) AS marcadas_corrigidas
FROM manutencao_biz02_datetime s
LEFT JOIN audit_log t ON t.id = s.linha_id
WHERE s.tabela = 'audit_log' AND s.coluna = 'timestamp'
UNION ALL
SELECT 'portal_notification_reads', 'read_at', COUNT(*),
       SUM(t.read_at <=> s.valor_utc),
       SUM(t.read_at <=> s.valor_utc - INTERVAL 3 HOUR),
       SUM(t.notification_id IS NOT NULL AND NOT (t.read_at <=> s.valor_utc) AND NOT (t.read_at <=> s.valor_utc - INTERVAL 3 HOUR)),
       SUM(t.notification_id IS NULL),
       SUM(s.corrigido_em IS NOT NULL)
FROM manutencao_biz02_datetime s
LEFT JOIN portal_notification_reads t ON t.notification_id = s.linha_id AND t.username = s.linha_chave
WHERE s.tabela = 'portal_notification_reads' AND s.coluna = 'read_at'
UNION ALL
SELECT 'pause_approval_requests', 'decided_at', COUNT(*),
       SUM(t.decided_at <=> s.valor_utc),
       SUM(t.decided_at <=> s.valor_utc - INTERVAL 3 HOUR),
       SUM(t.id IS NOT NULL AND NOT (t.decided_at <=> s.valor_utc) AND NOT (t.decided_at <=> s.valor_utc - INTERVAL 3 HOUR)),
       SUM(t.id IS NULL),
       SUM(s.corrigido_em IS NOT NULL)
FROM manutencao_biz02_datetime s
LEFT JOIN pause_approval_requests t ON t.id = s.linha_id
WHERE s.tabela = 'pause_approval_requests' AND s.coluna = 'decided_at'
UNION ALL
SELECT 'portal_overtime_entries', 'approved_at', COUNT(*),
       SUM(t.approved_at <=> s.valor_utc),
       SUM(t.approved_at <=> s.valor_utc - INTERVAL 3 HOUR),
       SUM(t.id IS NOT NULL AND NOT (t.approved_at <=> s.valor_utc) AND NOT (t.approved_at <=> s.valor_utc - INTERVAL 3 HOUR)),
       SUM(t.id IS NULL),
       SUM(s.corrigido_em IS NOT NULL)
FROM manutencao_biz02_datetime s
LEFT JOIN portal_overtime_entries t ON t.id = s.linha_id
WHERE s.tabela = 'portal_overtime_entries' AND s.coluna = 'approved_at'
UNION ALL
SELECT 'portal_time_adjustments', 'approved_at', COUNT(*),
       SUM(t.approved_at <=> s.valor_utc),
       SUM(t.approved_at <=> s.valor_utc - INTERVAL 3 HOUR),
       SUM(t.id IS NOT NULL AND NOT (t.approved_at <=> s.valor_utc) AND NOT (t.approved_at <=> s.valor_utc - INTERVAL 3 HOUR)),
       SUM(t.id IS NULL),
       SUM(s.corrigido_em IS NOT NULL)
FROM manutencao_biz02_datetime s
LEFT JOIN portal_time_adjustments t ON t.id = s.linha_id
WHERE s.tabela = 'portal_time_adjustments' AND s.coluna = 'approved_at'
UNION ALL
SELECT 'portal_pa_assignments', 'deleted_at', COUNT(*),
       SUM(t.deleted_at <=> s.valor_utc),
       SUM(t.deleted_at <=> s.valor_utc - INTERVAL 3 HOUR),
       SUM(t.id IS NOT NULL AND NOT (t.deleted_at <=> s.valor_utc) AND NOT (t.deleted_at <=> s.valor_utc - INTERVAL 3 HOUR)),
       SUM(t.id IS NULL),
       SUM(s.corrigido_em IS NOT NULL)
FROM manutencao_biz02_datetime s
LEFT JOIN portal_pa_assignments t ON t.id = s.linha_id
WHERE s.tabela = 'portal_pa_assignments' AND s.coluna = 'deleted_at'
UNION ALL
SELECT 'portal_pa_map', 'deleted_at', COUNT(*),
       SUM(t.deleted_at <=> s.valor_utc),
       SUM(t.deleted_at <=> s.valor_utc - INTERVAL 3 HOUR),
       SUM(t.id IS NOT NULL AND NOT (t.deleted_at <=> s.valor_utc) AND NOT (t.deleted_at <=> s.valor_utc - INTERVAL 3 HOUR)),
       SUM(t.id IS NULL),
       SUM(s.corrigido_em IS NOT NULL)
FROM manutencao_biz02_datetime s
LEFT JOIN portal_pa_map t ON t.id = s.linha_id
WHERE s.tabela = 'portal_pa_map' AND s.coluna = 'deleted_at'
UNION ALL
SELECT 'portal_critical_incidents', s.coluna, COUNT(*),
       SUM(v.valor <=> s.valor_utc),
       SUM(v.valor <=> s.valor_utc - INTERVAL 3 HOUR),
       SUM(v.id IS NOT NULL AND NOT (v.valor <=> s.valor_utc) AND NOT (v.valor <=> s.valor_utc - INTERVAL 3 HOUR)),
       SUM(v.id IS NULL),
       SUM(s.corrigido_em IS NOT NULL)
FROM manutencao_biz02_datetime s
LEFT JOIN (
    SELECT t.id, c.coluna,
           CASE c.coluna
               WHEN 1 THEN t.war_room_started_at
               WHEN 2 THEN t.room_opened_at
               WHEN 3 THEN t.mitigated_at
               WHEN 4 THEN t.resolved_at
               ELSE t.normalized_at
           END AS valor
    FROM portal_critical_incidents t
    CROSS JOIN (SELECT 1 AS coluna UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) c
) v ON v.id = s.linha_id
   AND v.coluna = CASE s.coluna
                      WHEN 'war_room_started_at' THEN 1
                      WHEN 'room_opened_at' THEN 2
                      WHEN 'mitigated_at' THEN 3
                      WHEN 'resolved_at' THEN 4
                      ELSE 5
                  END
WHERE s.tabela = 'portal_critical_incidents'
GROUP BY s.coluna;

-- V3. Valores "no futuro". Um DATETIME gravado em UTC fica 3 h adiante: linhas
-- das ultimas 3 h aparecem depois de agora. So tem resultado se houve atividade
-- nas 3 h anteriores a execucao.
-- ANTES: pode ser > 0.  DEPOIS: 0 em todas.
SELECT 'audit_log.timestamp' AS coluna, COUNT(*) AS no_futuro FROM audit_log WHERE `timestamp` > NOW() + INTERVAL 5 MINUTE
UNION ALL SELECT 'portal_notification_reads.read_at', COUNT(*) FROM portal_notification_reads WHERE read_at > NOW() + INTERVAL 5 MINUTE
UNION ALL SELECT 'pause_approval_requests.decided_at', COUNT(*) FROM pause_approval_requests WHERE decided_at > NOW() + INTERVAL 5 MINUTE
UNION ALL SELECT 'portal_overtime_entries.approved_at', COUNT(*) FROM portal_overtime_entries WHERE approved_at > NOW() + INTERVAL 5 MINUTE
UNION ALL SELECT 'portal_time_adjustments.approved_at', COUNT(*) FROM portal_time_adjustments WHERE approved_at > NOW() + INTERVAL 5 MINUTE
UNION ALL SELECT 'portal_pa_assignments.deleted_at', COUNT(*) FROM portal_pa_assignments WHERE deleted_at > NOW() + INTERVAL 5 MINUTE;

-- V4. Auditoria por hora do dia. A operacao vai de 08:00 a 23:59.
-- ANTES: o volume aparece deslocado (11h as 02h).  DEPOIS: de 08h a 23h.
-- Linhas gravadas depois do corte ja entram no horario certo nos dois casos.
SELECT HOUR(`timestamp`) AS hora, COUNT(*) AS eventos FROM audit_log GROUP BY HOUR(`timestamp`) ORDER BY hora;

-- V5. Decisao nunca vem antes do pedido. requested_at e gravado pelo PHP (ja
-- certo); decided_at, pelo MySQL.
-- ANTES:  decisao_antes_do_pedido = 0; espera_media_min inflada em ~180.
-- DEPOIS: decisao_antes_do_pedido = 0; espera_media_min ~180 menor.
-- Se DEPOIS houver decisao antes do pedido, alguma linha NAO estava em UTC e
-- foi corrigida indevidamente: rodar o script 4 e investigar.
SELECT COUNT(*) AS decididas,
       SUM(decided_at < requested_at) AS decisao_antes_do_pedido,
       ROUND(AVG(TIMESTAMPDIFF(MINUTE, requested_at, decided_at))) AS espera_media_min
FROM pause_approval_requests
WHERE decided_at IS NOT NULL;

-- V6. Colunas com irma TIMESTAMP (updated_at): assinatura de cada formato.
--   utc_3h_adiante   = updated_at + 3 h  (formato antigo, ainda por corrigir)
--   igual_updated_at = updated_at        (corrigida, ou gravada pelo codigo novo)
--   outra            nenhuma das duas: linha alterada depois da decisao/remocao.
--                    NAO entra na fotografia; revisar a mao se for > 0.
-- DEPOIS do script 3: utc_3h_adiante = 0.
SELECT 'portal_overtime_entries.approved_at' AS coluna,
       SUM(approved_at = updated_at + INTERVAL 3 HOUR) AS utc_3h_adiante,
       SUM(approved_at = updated_at) AS igual_updated_at,
       SUM(approved_at <> updated_at AND approved_at <> updated_at + INTERVAL 3 HOUR) AS outra
FROM portal_overtime_entries WHERE approved_at IS NOT NULL
UNION ALL
SELECT 'portal_time_adjustments.approved_at',
       SUM(approved_at = updated_at + INTERVAL 3 HOUR),
       SUM(approved_at = updated_at),
       SUM(approved_at <> updated_at AND approved_at <> updated_at + INTERVAL 3 HOUR)
FROM portal_time_adjustments WHERE approved_at IS NOT NULL
UNION ALL
SELECT 'portal_pa_assignments.deleted_at',
       SUM(deleted_at = updated_at + INTERVAL 3 HOUR),
       SUM(deleted_at = updated_at),
       SUM(deleted_at <> updated_at AND deleted_at <> updated_at + INTERVAL 3 HOUR)
FROM portal_pa_assignments WHERE deleted_at IS NOT NULL;

-- V7. TIMESTAMP nao precisa de correcao: confere com uma coluna gravada pelo PHP.
-- fim_pausa (DATETIME, PHP) e data_registro (TIMESTAMP, MySQL) sao do mesmo
-- instante. Com a sessao em -03:00 a diferenca e ~0; era 180 com a sessao em UTC.
-- Esperado antes e depois: fora_de_2_min = 0 (ou residual, em pausas importadas).
SELECT COUNT(*) AS pausas,
       SUM(ABS(TIMESTAMPDIFF(MINUTE, fim_pausa, data_registro)) > 2) AS fora_de_2_min
FROM pausas;

-- V8. REVISAO MANUAL - chamados criticos. Horas preenchidas pelo MySQL na troca
-- de status e que o script 3 NAO corrige, porque a linha foi editada depois e
-- perdeu a assinatura. Indicio: segundos diferentes de zero (a tela grava
-- HH:MM:00) em valor que nao esta na fotografia. Conferir cada id na tela do
-- chamado. Importacao de planilha com segundos tambem aparece aqui.
SELECT i.id AS chamado_id,
       (SECOND(i.war_room_started_at) <> 0) AS war_room_started_at,
       (SECOND(i.room_opened_at) <> 0) AS room_opened_at,
       (SECOND(i.mitigated_at) <> 0) AS mitigated_at,
       (SECOND(i.resolved_at) <> 0) AS resolved_at,
       (SECOND(i.normalized_at) <> 0) AS normalized_at
FROM portal_critical_incidents i
WHERE (SECOND(i.war_room_started_at) <> 0 OR SECOND(i.room_opened_at) <> 0 OR SECOND(i.mitigated_at) <> 0
       OR SECOND(i.resolved_at) <> 0 OR SECOND(i.normalized_at) <> 0)
  AND NOT EXISTS (
      SELECT 1 FROM manutencao_biz02_datetime s
      WHERE s.tabela = 'portal_critical_incidents' AND s.linha_id = i.id
  )
ORDER BY i.id;

-- V9. REVISAO MANUAL - tempo de sala. room_duration_minutes foi calculado pelo
-- MySQL misturando hora digitada (certa) com hora preenchida em UTC: fica 180
-- minutos a mais. O script 3 nao recalcula. Depois dele, o que aparecer aqui
-- com diferenca de 180 deve ser corrigido na tela do chamado.
SELECT id AS chamado_id, room_duration_minutes AS gravado,
       TIMESTAMPDIFF(MINUTE, room_opened_at, normalized_at) AS calculado,
       room_duration_minutes - TIMESTAMPDIFF(MINUTE, room_opened_at, normalized_at) AS diferenca
FROM portal_critical_incidents
WHERE room_duration_minutes IS NOT NULL AND room_opened_at IS NOT NULL AND normalized_at IS NOT NULL
  AND ABS(room_duration_minutes - TIMESTAMPDIFF(MINUTE, room_opened_at, normalized_at)) IN (180, 360)
ORDER BY id;

-- V10. REVISAO MANUAL - datas gravadas com CURRENT_DATE entre 21:00 e 00:00.
-- Escala removida nesse horario foi encerrada no proprio dia em vez da vespera.
-- O script 3 nao corrige colunas DATE. Esperado: poucas linhas ou nenhuma.
SELECT COUNT(*) AS escalas_encerradas_um_dia_adiante
FROM portal_schedule_rules
WHERE effective_until IS NOT NULL
  AND rule_type = 'undefined'
  AND effective_until = DATE(updated_at)
  AND HOUR(updated_at) >= 21;
