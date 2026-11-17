-- Verificacao da migration 025 (Controle de CHG, 9.17). So contagens e
-- assinaturas: nenhum nome, login, link, caminho ou numero de CHG. Rodar depois
-- da 025, no pos-deploy e sempre que quiser conferir:
--   docker exec -i -e MYSQL_PWD="$P" Chrono_Desk_DB mysql -uroot --table sistema_pausas \
--     < migrations/verificacao/20261117_025_chg_check.sql
-- Tambem serve antes da 025 e depois do rollback: as contagens das tabelas so
-- rodam com elas presentes (senao, "025 ausente").
--
-- Esperado com a 025: tabelas_025 = 10, checks_025 = 27; logo depois da
-- migration, todas as contagens de linhas = 0. Sempre: as contagens de
-- problema (cards_sem_plano_1, cards_sem_tentativa, tentativas_atuais_no_historico,
-- historico_sem_data, rollbacks_ativos_repetidos, tools_somente_leitura,
-- tools_inativos) = 0. A assinatura dos cadastros (no fim) e igual antes,
-- depois e depois do rollback: a 025 nao muda nenhuma linha.

SELECT
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('funcionario_grupos', 'portal_chg_carteiras', 'portal_chg_locais', 'portal_chg_updates', 'portal_chg_update_carteiras', 'portal_chg_update_locais', 'portal_chg_plans', 'portal_chg_attempts', 'portal_chg_endpoints', 'portal_chg_rollbacks')) AS tabelas_025,
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'
        AND TABLE_NAME IN ('funcionario_grupos', 'portal_chg_carteiras', 'portal_chg_locais', 'portal_chg_updates', 'portal_chg_update_carteiras', 'portal_chg_update_locais', 'portal_chg_plans', 'portal_chg_attempts', 'portal_chg_endpoints', 'portal_chg_rollbacks')) AS checks_025,
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%\_bkp\_025') AS copias_de_rollback_025;

SET @tem_025 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('funcionario_grupos', 'portal_chg_carteiras', 'portal_chg_locais', 'portal_chg_updates', 'portal_chg_update_carteiras', 'portal_chg_update_locais', 'portal_chg_plans', 'portal_chg_attempts', 'portal_chg_endpoints', 'portal_chg_rollbacks')
);
SET @linhas_025_sql := IF(
    @tem_025 = 10,
    'SELECT
        (SELECT COUNT(*) FROM funcionario_grupos WHERE grupo = ''tools'') AS grupo_tools,
        (SELECT COUNT(*) FROM portal_chg_carteiras) AS carteiras_catalogo,
        (SELECT COUNT(*) FROM portal_chg_locais) AS locais_catalogo,
        (SELECT COUNT(*) FROM portal_chg_updates) AS cards,
        (SELECT COUNT(*) FROM portal_chg_updates WHERE archived_at IS NULL) AS cards_ativos,
        (SELECT COUNT(*) FROM portal_chg_updates WHERE legacy_id IS NOT NULL) AS cards_importados,
        (SELECT COUNT(*) FROM portal_chg_updates WHERE tecnico_nome_legado IS NOT NULL) AS cards_tecnico_historico,
        (SELECT COUNT(*) FROM portal_chg_plans) AS planos,
        (SELECT COUNT(*) FROM portal_chg_attempts) AS tentativas,
        (SELECT COUNT(*) FROM portal_chg_endpoints) AS endpoints,
        (SELECT COUNT(*) FROM portal_chg_rollbacks WHERE removed_at IS NULL) AS rollbacks_ativos',
    'SELECT ''025 ausente'' AS situacao_025'
);
PREPARE linhas_025_stmt FROM @linhas_025_sql;
EXECUTE linhas_025_stmt;
DEALLOCATE PREPARE linhas_025_stmt;

SET @problemas_025_sql := IF(
    @tem_025 = 10,
    'SELECT
        (SELECT COUNT(*) FROM portal_chg_updates u
          WHERE NOT EXISTS (SELECT 1 FROM portal_chg_plans p WHERE p.update_id = u.id AND p.position = 0)) AS cards_sem_plano_1,
        (SELECT COUNT(*) FROM portal_chg_updates u
          WHERE NOT EXISTS (SELECT 1 FROM portal_chg_attempts a WHERE a.update_id = u.id)) AS cards_sem_tentativa,
        (SELECT COUNT(*) FROM portal_chg_attempts a
          WHERE a.registered_at IS NOT NULL
            AND a.attempt_no = (SELECT MAX(b.attempt_no) FROM portal_chg_attempts b WHERE b.update_id = a.update_id)) AS tentativas_atuais_no_historico,
        (SELECT COUNT(*) FROM portal_chg_attempts a
          WHERE a.registered_at IS NULL
            AND a.attempt_no < (SELECT MAX(b.attempt_no) FROM portal_chg_attempts b WHERE b.update_id = a.update_id)) AS historico_sem_data,
        (SELECT COUNT(*) FROM (SELECT update_id FROM portal_chg_rollbacks WHERE removed_at IS NULL
           GROUP BY update_id HAVING COUNT(*) > 1) AS r) AS rollbacks_ativos_repetidos,
        (SELECT COUNT(*) FROM funcionario_grupos g JOIN funcionarios f ON f.id = g.funcionario_id
          WHERE g.grupo = ''tools'' AND LOWER(TRIM(COALESCE(f.access_role, ''''))) = ''somente_leitura'') AS tools_somente_leitura,
        (SELECT COUNT(*) FROM funcionario_grupos g JOIN funcionarios f ON f.id = g.funcionario_id
          WHERE f.ativo = 0) AS tools_inativos',
    'SELECT ''025 ausente'' AS situacao_025'
);
PREPARE problemas_025_stmt FROM @problemas_025_sql;
EXECUTE problemas_025_stmt;
DEALLOCATE PREPARE problemas_025_stmt;

-- Assinatura dos cadastros (CRC32 por linha, combinado por XOR; sem nome,
-- login nem observacao)
SELECT 'cadastros' AS tabela, COUNT(*) AS linhas,
       BIT_XOR(CRC32(CONCAT_WS('|', id, equipe, ativo, COALESCE(access_role, '')))) AS assinatura
  FROM funcionarios;
