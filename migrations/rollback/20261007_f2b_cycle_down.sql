-- Rollback do F2B (Parte B): regra de escala "cycle" -> "undefined".
-- Decisao 5 do plano do F2B (aprovada em 2026-10-07), no lugar do rollback
-- da 011, que apagaria o historico de escala.
-- NAO e migration de deploy. Aplicar so se o codigo voltar para antes do
-- F2B com alguma regra cycle gravada: o codigo anterior lanca erro para tipo
-- desconhecido (Calendario, Escala e Relatorios com 400).
--
-- O que faz: troca so o tipo. rule_config fica (com o pattern do ciclo), e
-- e por ele que a volta (20261007_f2b_cycle_up.sql) reconhece as linhas.
-- Nenhuma linha e apagada nem criada; vigencias nao mudam. O codigo anterior
-- le "undefined" como hoje: Sem escala na tela e remoto nos relatorios
-- antigos (P4 so vale no motor). Idempotente: a 2a execucao nao muda nada.
--
-- Mapa PA: nada a fazer. Os vinculos de quem tem ciclo ja sao gravados como
-- 'undefined' com a configuracao (o ENUM da copia nao tem 'cycle').
--
-- Conferir antes e depois (so contagens):
--   docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas \
--     < migrations/verificacao/20261007_f2b_cycle_check.sql
-- Esperado depois: regras_cycle = 0 e undefined_com_pattern = o valor de
-- regras_cycle antes.

SET time_zone = 'America/Sao_Paulo';

UPDATE portal_schedule_rules
   SET rule_type = 'undefined'
 WHERE rule_type = 'cycle';
