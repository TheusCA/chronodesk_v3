-- Verificacao das regras de ciclo (Parte B, F2B). So contagens, nenhum nome.
-- Rodar antes e depois do rollback do F2B (e da volta) e no pos-deploy:
--   docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas \
--     < migrations/verificacao/20261007_f2b_cycle_check.sql
--
-- regras_cycle: regras com o tipo cycle.
-- undefined_com_pattern: regras "undefined" com configuracao de ciclo (so
--   existem depois do rollback do F2B; 0 com o F2B no ar).
-- cycle_sem_pattern: regras cycle sem JSON valido com pattern. Esperado 0 (o
--   servidor sempre grava o pattern); o motor mostra "Sem escala" para elas.
-- vinculos_pa_com_ciclo: vinculos de PA ativos gravados com a configuracao
--   de ciclo (tipo 'undefined' na copia, porque o ENUM nao tem 'cycle').

SELECT
    (SELECT COUNT(*) FROM portal_schedule_rules
      WHERE rule_type = 'cycle') AS regras_cycle,
    (SELECT COUNT(*) FROM portal_schedule_rules
      WHERE rule_type = 'undefined'
        AND CASE WHEN JSON_VALID(rule_config) THEN JSON_CONTAINS_PATH(rule_config, 'one', '$.pattern') ELSE 0 END = 1) AS undefined_com_pattern,
    (SELECT COUNT(*) FROM portal_schedule_rules
      WHERE rule_type = 'cycle'
        AND NOT (CASE WHEN JSON_VALID(rule_config) THEN JSON_CONTAINS_PATH(rule_config, 'one', '$.pattern') ELSE 0 END <=> 1)) AS cycle_sem_pattern,
    (SELECT COUNT(*) FROM portal_pa_assignments
      WHERE active = 1
        AND schedule_rule_type = 'undefined'
        AND CASE WHEN JSON_VALID(schedule_rule_config) THEN JSON_CONTAINS_PATH(schedule_rule_config, 'one', '$.pattern') ELSE 0 END = 1) AS vinculos_pa_com_ciclo;
