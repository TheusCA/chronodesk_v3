-- Volta do rollback do F2B (Parte B): "undefined" com pattern -> "cycle".
-- Desfaz migrations/rollback/20261007_f2b_cycle_down.sql.
-- Aplicar so DEPOIS de o codigo do F2B voltar ao ar: o codigo anterior lanca
-- erro para o tipo cycle (Calendario, Escala e Relatorios com 400).
--
-- Reconhece as linhas pelo rule_config: JSON valido com a chave pattern. Antes
-- do F2B nenhuma regra "undefined" tinha configuracao (a remocao antiga
-- gravava rule_config = NULL), entao so voltam as que eram ciclo. Nenhuma
-- linha e apagada nem criada; vigencias nao mudam. Idempotente.
--
-- Conferir antes e depois (so contagens):
--   docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas \
--     < migrations/verificacao/20261007_f2b_cycle_check.sql
-- Esperado depois: undefined_com_pattern = 0 e regras_cycle = o valor de
-- undefined_com_pattern antes.

SET time_zone = 'America/Sao_Paulo';

UPDATE portal_schedule_rules
   SET rule_type = 'cycle'
 WHERE rule_type = 'undefined'
   AND CASE WHEN JSON_VALID(rule_config) THEN JSON_CONTAINS_PATH(rule_config, 'one', '$.pattern') ELSE 0 END = 1;
