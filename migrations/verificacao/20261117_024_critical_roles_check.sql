-- Verificacao da migration 024 (rodizio de papeis do Critico, 9.16). So
-- contagens e assinaturas, nenhum nome nem login. Rodar depois da 024, no
-- pos-deploy e sempre que quiser conferir:
--   docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas \
--     < migrations/verificacao/20261117_024_critical_roles_check.sql
-- Tambem serve antes da 024 e depois do rollback: as contagens das tabelas so
-- rodam com elas presentes (senao, "024 ausente").
--
-- Esperado com a 024: tabelas_024 = 3, checks_024 = 6; logo depois da
-- migration, papeis = 0, rodizios = 0 e trocas = 0. Sempre: as contagens de
-- problema (trocas_sobrepostas, trocas_fora_do_critico,
-- rodizios_com_pessoa_fora_do_critico, rodizios_com_papel_inexistente) = 0.
-- As assinaturas (no fim) cobrem cadastros e regras: a 024 nao muda nenhuma
-- linha, entao sao iguais antes, depois e depois do rollback.

SELECT
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('portal_critical_roles', 'portal_critical_role_rotations', 'portal_critical_role_overrides')) AS tabelas_024,
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'
        AND TABLE_NAME IN ('portal_critical_roles', 'portal_critical_role_rotations', 'portal_critical_role_overrides')) AS checks_024,
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%\_bkp\_024') AS copias_de_rollback_024;

SET @tem_024 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('portal_critical_roles', 'portal_critical_role_rotations', 'portal_critical_role_overrides')
);
SET @papeis_024_sql := IF(
    @tem_024 = 3,
    'SELECT
        (SELECT COUNT(*) FROM portal_critical_roles) AS papeis,
        (SELECT COUNT(*) FROM portal_critical_roles WHERE active = 1) AS papeis_ativos,
        (SELECT COUNT(*) FROM portal_critical_role_rotations) AS rodizios,
        (SELECT COUNT(*) FROM portal_critical_role_overrides) AS trocas,
        (SELECT COUNT(*) FROM portal_critical_role_overrides a
           JOIN portal_critical_role_overrides b
             ON b.employee_id = a.employee_id AND b.id > a.id
            AND b.starts_on <= a.ends_on AND b.ends_on >= a.starts_on) AS trocas_sobrepostas,
        (SELECT COUNT(*) FROM portal_critical_role_overrides o
           JOIN funcionarios f ON f.id = o.employee_id
          WHERE LOWER(TRIM(f.equipe)) <> ''critico'') AS trocas_fora_do_critico,
        (SELECT COUNT(DISTINCT r.id) FROM portal_critical_role_rotations r
           JOIN JSON_TABLE(r.employees, ''$[*]'' COLUMNS (employee_id INT PATH ''$'')) AS p
           LEFT JOIN funcionarios f ON f.id = p.employee_id
          WHERE f.id IS NULL OR LOWER(TRIM(f.equipe)) <> ''critico'') AS rodizios_com_pessoa_fora_do_critico,
        (SELECT COUNT(DISTINCT r.id) FROM portal_critical_role_rotations r
           JOIN JSON_TABLE(r.roles, ''$[*]'' COLUMNS (role_id INT PATH ''$'')) AS p
           LEFT JOIN portal_critical_roles c ON c.id = p.role_id
          WHERE c.id IS NULL) AS rodizios_com_papel_inexistente',
    'SELECT ''024 ausente'' AS situacao_024'
);
PREPARE papeis_024_stmt FROM @papeis_024_sql;
EXECUTE papeis_024_stmt;
DEALLOCATE PREPARE papeis_024_stmt;

-- Cadastros do Critico por situacao (texto gravado, normalizado so por caixa e espaco)
SELECT ativo, COUNT(*) AS cadastros_critico
  FROM funcionarios
 WHERE LOWER(TRIM(equipe)) = 'critico'
 GROUP BY ativo
 ORDER BY ativo;

-- Assinaturas de cadastros e regras (CRC32 por linha, combinado por XOR; sem
-- nome, login nem observacao)
SELECT 'cadastros' AS tabela, COUNT(*) AS linhas,
       BIT_XOR(CRC32(CONCAT_WS('|', id, equipe, ativo, jornada_entrada, jornada_saida))) AS assinatura
  FROM funcionarios
UNION ALL
SELECT 'regras', COUNT(*),
       BIT_XOR(CRC32(CONCAT_WS('|', id, employee_id, team, rule_type, COALESCE(rule_config, ''),
                               effective_from, COALESCE(effective_until, ''))))
  FROM portal_schedule_rules;
