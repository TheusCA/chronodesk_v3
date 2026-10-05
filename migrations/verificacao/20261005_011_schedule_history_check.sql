-- Verificacao do historico de escala (Parte B, F1). So contagens, nenhum nome.
-- Rodar depois da migration 011, no pos-deploy e sempre que quiser conferir:
--   docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas \
--     < migrations/verificacao/20261005_011_schedule_history_check.sql
-- Tambem serve depois do rollback (as consultas nao dependem das colunas novas).
--
-- Esperado: colaboradores_com_mais_de_uma_aberta = 0, sobreposicoes = 0 e
-- inicio_repetido = 0. vigencia_vazia > 0 so com linhas antigas (escala
-- removida antes de comecar, codigo anterior ao F1): o motor ignora essas linhas.

SELECT
    (SELECT COUNT(*) FROM (
        SELECT employee_id FROM portal_schedule_rules
        WHERE effective_until IS NULL
        GROUP BY employee_id HAVING COUNT(*) > 1
    ) AS abertas) AS colaboradores_com_mais_de_uma_aberta,
    (SELECT COUNT(*)
       FROM portal_schedule_rules a
       JOIN portal_schedule_rules b
         ON b.employee_id = a.employee_id
        AND b.id > a.id
        AND a.effective_from <= COALESCE(b.effective_until, '9999-12-31')
        AND b.effective_from <= COALESCE(a.effective_until, '9999-12-31')
      WHERE (a.effective_until IS NULL OR a.effective_until >= a.effective_from)
        AND (b.effective_until IS NULL OR b.effective_until >= b.effective_from)) AS sobreposicoes,
    (SELECT COUNT(*) FROM (
        SELECT employee_id, effective_from FROM portal_schedule_rules
        GROUP BY employee_id, effective_from HAVING COUNT(*) > 1
    ) AS repetidos) AS inicio_repetido,
    (SELECT COUNT(*) FROM portal_schedule_rules
      WHERE effective_until IS NOT NULL AND effective_until < effective_from) AS vigencia_vazia;

-- Regras por tipo e situacao (Q2 do desenho)
SELECT rule_type, effective_until IS NULL AS aberta, COUNT(*) AS regras
  FROM portal_schedule_rules
 GROUP BY rule_type, aberta
 ORDER BY rule_type, aberta;

-- Equipe gravada nas regras e nas excecoes
SELECT 'regras' AS tabela, team, COUNT(*) AS linhas FROM portal_schedule_rules GROUP BY team
UNION ALL
SELECT 'excecoes', team, COUNT(*) FROM portal_schedule_exceptions GROUP BY team;
