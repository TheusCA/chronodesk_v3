-- ChronoDesk - times de rodizio como cadastro e grupo da escala (F10-2;
-- desenho do F10, secao 3.1; decisoes D-F10-1 a D-F10-18 aprovadas em
-- 2026-11-13).
-- Idempotente: cada passo confere information_schema antes de alterar.
-- Nenhuma linha de dado e alterada: so a estrutura (tabelas novas vazias,
-- coluna nula nas regras e grupo 'comum' para todos).
--
-- 1. portal_rotation_teams: o time (nome unico, ativo, autoria).
-- 2. portal_rotation_team_versions: o padrao do time com vigencia. A versao
--    que vale na data d e a de maior effective_from <= d. pattern: lista de 2
--    a 20 posicoes 'onsite'/'remote' (CHECK com JSON_SCHEMA_VALID, o mesmo
--    limite do motor); anchor_date em dia util (CHECK com WEEKDAY); weeks: so
--    no ciclo montado semana a semana (1 a 4 semanas, 5 posicoes por semana,
--    ancora na segunda; desenho 3.2). Uma versao por time e data de inicio.
-- 3. portal_rotation_team_members: a pertenca com vigencia (valid_from,
--    valid_until inclusive). Coluna gerada open_employee_id e UNIQUE sobre
--    ela: no maximo uma pertenca aberta por pessoa, como o uq_schedule_rule_open
--    da 011. A nao sobreposicao entre pertencas fechadas fica no servico, com
--    o colaborador travado, e e conferida pela verificacao.
-- 4. portal_schedule_rules.rotation_team_id (nulo, FK): marca a regra gravada
--    pelo time. As regras de hoje ficam com NULL: o historico da 011 nao muda.
-- 5. funcionarios.grupo_escala ENUM('comum', 'separado'), padrao 'comum' para
--    todos: o grupo comum da planilha; 'separado' = Hibrido e Critico ate o F9
--    (desenho 3.8).
--
-- Pode ser aplicada ANTES do git merge (o codigo anterior nao le nada disto) e
-- DEVE ser aplicada antes dele: o codigo do F10-2 le as tabelas novas ao
-- gravar regra de escala.
-- A data nao e usada; SET time_zone segue a regra do A4 para sessao manual.
-- Exige MySQL 8.0.17 ou mais novo (JSON_SCHEMA_VALID em CHECK).
--
-- Ensaio (no servidor, a partir de /var/www/chronodesk; regras e excecoes
-- copiadas com os dados, audit_log so na estrutura):
--   sudo env DADOS=portal_schedule_rules,portal_schedule_exceptions \
--     VERIFICA=migrations/verificacao/20261115_021_rotation_teams_check.sql \
--     bash scripts/ensaio-migration.sh f10_021 \
--     migrations/20261115_021_rotation_teams.sql \
--     migrations/rollback/20261115_021_rotation_teams_down.sql audit_log
--
-- Rollback: migrations/rollback/20261115_021_rotation_teams_down.sql.
-- Verificacao: migrations/verificacao/20261115_021_rotation_teams_check.sql.

SET time_zone = 'America/Sao_Paulo';

-- 1. Times
SET @has_teams_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_rotation_teams'
);
SET @create_teams_sql := IF(
    @has_teams_table = 0,
    'CREATE TABLE portal_rotation_teams (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(60) NOT NULL COMMENT ''Nome do time (ex.: Time 1); unico'',
        active TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''0 = time desativado, sem membros abertos'',
        created_by VARCHAR(100) NOT NULL,
        updated_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_rotation_team_name (name),
        CONSTRAINT ck_rotation_team_name CHECK (CHAR_LENGTH(TRIM(name)) > 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Times de rodizio (F10-2): a fonte das regras de ciclo dos membros''',
    'SELECT 1'
);
PREPARE create_teams_stmt FROM @create_teams_sql;
EXECUTE create_teams_stmt;
DEALLOCATE PREPARE create_teams_stmt;

-- 2. Versoes do padrao
SET @has_versions_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_rotation_team_versions'
);
SET @create_versions_sql := IF(
    @has_versions_table = 0,
    'CREATE TABLE portal_rotation_team_versions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        team_id INT UNSIGNED NOT NULL,
        effective_from DATE NOT NULL COMMENT ''A versao vale de effective_from ate a vespera da proxima'',
        pattern JSON NOT NULL COMMENT ''Lista de 2 a 20 posicoes onsite/remote, em dias uteis'',
        anchor_date DATE NOT NULL COMMENT ''Posicao 0 do padrao; dia util'',
        weeks TINYINT UNSIGNED NULL COMMENT ''Ciclo semana a semana: 1 a 4 semanas (5 posicoes por semana, ancora na segunda); NULL nos demais'',
        created_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_by VARCHAR(100) NOT NULL,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_rotation_version_team_from (team_id, effective_from),
        CONSTRAINT fk_rotation_version_team FOREIGN KEY (team_id) REFERENCES portal_rotation_teams (id) ON DELETE RESTRICT,
        CONSTRAINT ck_rotation_version_pattern CHECK (JSON_SCHEMA_VALID(''{"type": "array", "minItems": 2, "maxItems": 20, "items": {"enum": ["onsite", "remote"]}}'', pattern)),
        CONSTRAINT ck_rotation_version_anchor CHECK (WEEKDAY(anchor_date) < 5),
        CONSTRAINT ck_rotation_version_weeks CHECK (weeks IS NULL OR (weeks BETWEEN 1 AND 4 AND JSON_LENGTH(pattern) = 5 * weeks AND WEEKDAY(anchor_date) = 0))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Padrao do time de rodizio com vigencia (F10-2)''',
    'SELECT 1'
);
PREPARE create_versions_stmt FROM @create_versions_sql;
EXECUTE create_versions_stmt;
DEALLOCATE PREPARE create_versions_stmt;

-- 3. Membros com vigencia
SET @has_members_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_rotation_team_members'
);
SET @create_members_sql := IF(
    @has_members_table = 0,
    'CREATE TABLE portal_rotation_team_members (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        team_id INT UNSIGNED NOT NULL,
        employee_id INT NOT NULL,
        valid_from DATE NOT NULL,
        valid_until DATE NULL COMMENT ''Ultimo dia no time (inclusive); NULL = pertenca aberta'',
        open_employee_id INT
            GENERATED ALWAYS AS (CASE WHEN valid_until IS NULL THEN employee_id ELSE NULL END) STORED
            COMMENT ''Colaborador da pertenca aberta; NULL quando a vigencia tem fim'',
        created_by VARCHAR(100) NOT NULL,
        updated_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_rotation_member_employee_from (employee_id, valid_from),
        UNIQUE KEY uq_rotation_member_open (open_employee_id),
        KEY idx_rotation_member_team (team_id, valid_from),
        CONSTRAINT fk_rotation_member_team FOREIGN KEY (team_id) REFERENCES portal_rotation_teams (id) ON DELETE RESTRICT,
        CONSTRAINT fk_rotation_member_employee FOREIGN KEY (employee_id) REFERENCES funcionarios (id) ON DELETE RESTRICT,
        CONSTRAINT ck_rotation_member_period CHECK (valid_until IS NULL OR valid_until >= valid_from)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Pertenca ao time de rodizio com vigencia e historico (F10-2)''',
    'SELECT 1'
);
PREPARE create_members_stmt FROM @create_members_sql;
EXECUTE create_members_stmt;
DEALLOCATE PREPARE create_members_stmt;

-- 4. Regra gravada pelo time
SET @has_rule_team_column := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND COLUMN_NAME = 'rotation_team_id'
);
SET @add_rule_team_column_sql := IF(
    @has_rule_team_column = 0,
    'ALTER TABLE portal_schedule_rules
        ADD COLUMN rotation_team_id INT UNSIGNED NULL
        COMMENT ''Time de rodizio que gravou a regra (F10-2); NULL = regra individual''
        AFTER rule_config',
    'SELECT 1'
);
PREPARE add_rule_team_column_stmt FROM @add_rule_team_column_sql;
EXECUTE add_rule_team_column_stmt;
DEALLOCATE PREPARE add_rule_team_column_stmt;

SET @has_rule_team_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules' AND INDEX_NAME = 'idx_schedule_rule_rotation_team'
);
SET @add_rule_team_index_sql := IF(
    @has_rule_team_index = 0,
    'ALTER TABLE portal_schedule_rules ADD INDEX idx_schedule_rule_rotation_team (rotation_team_id)',
    'SELECT 1'
);
PREPARE add_rule_team_index_stmt FROM @add_rule_team_index_sql;
EXECUTE add_rule_team_index_stmt;
DEALLOCATE PREPARE add_rule_team_index_stmt;

SET @has_rule_team_fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_schedule_rules'
      AND CONSTRAINT_NAME = 'fk_schedule_rule_rotation_team' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @add_rule_team_fk_sql := IF(
    @has_rule_team_fk = 0,
    'ALTER TABLE portal_schedule_rules
        ADD CONSTRAINT fk_schedule_rule_rotation_team
        FOREIGN KEY (rotation_team_id) REFERENCES portal_rotation_teams (id) ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE add_rule_team_fk_stmt FROM @add_rule_team_fk_sql;
EXECUTE add_rule_team_fk_stmt;
DEALLOCATE PREPARE add_rule_team_fk_stmt;

-- 5. Grupo da escala
SET @has_grupo_escala := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionarios' AND COLUMN_NAME = 'grupo_escala'
);
SET @add_grupo_escala_sql := IF(
    @has_grupo_escala = 0,
    'ALTER TABLE funcionarios
        ADD COLUMN grupo_escala ENUM(''comum'', ''separado'') NOT NULL DEFAULT ''comum''
        COMMENT ''Grupo comum da escala mensal; separado = Hibrido e Critico ate o F9 (F10-2)''
        AFTER fora_mapa_escala',
    'SELECT 1'
);
PREPARE add_grupo_escala_stmt FROM @add_grupo_escala_sql;
EXECUTE add_grupo_escala_stmt;
DEALLOCATE PREPARE add_grupo_escala_stmt;
