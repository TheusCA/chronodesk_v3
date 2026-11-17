-- ChronoDesk - rodizio de papeis do Critico (desenho principal, secao 9.16;
-- plano aprovado em 2026-11-17 com AD-PC-1 a AD-PC-4 nas respostas padrao).
-- Idempotente: cada passo confere information_schema antes de alterar.
-- So estrutura: tres tabelas novas, vazias; nenhuma linha existente muda.
--
-- 1. portal_critical_roles: o papel (nome unico, tipo de presenca, jornada
--    sem cruzar a meia-noite, ativo, ordem, autoria). Os papeis de hoje
--    (Presencial A e B, Abertura home, Fechamento home, Home no horario
--    normal) sao cadastrados pela tela: nenhum valor no codigo.
-- 2. portal_critical_role_rotations: o rodizio com vigencia. A versao que vale
--    na data d e a de maior effective_from <= d. period: 'week' (ancora na
--    segunda) ou 'month' (mes do calendario, ancora no dia 1; AD-PC-1).
--    roles e employees: listas de IDs (1 a 20, sem repeticao), na ordem; no
--    periodo k, a pessoa da posicao i fica com o papel da posicao
--    (i + k) mod max(pessoas, papeis): posicao sem papel = pessoa a mais (fica
--    pelo cadastro); papel sem pessoa = papel vago (alerta; AD-PC-2).
-- 3. portal_critical_role_overrides: troca pontual de papel de uma pessoa num
--    intervalo curto (motivo em lista fechada, sem texto livre). A nao
--    sobreposicao por pessoa fica no servico, com o colaborador travado, e e
--    conferida pela verificacao.
--
-- Pode ser aplicada ANTES do git merge (o codigo anterior nao le nada disto) e
-- DEVE ser aplicada antes dele: o codigo do rodizio le as tabelas novas na
-- presenca e nas pausas.
-- A data nao e usada; SET time_zone segue a regra do A4 para sessao manual.
-- Exige MySQL 8.0.17 ou mais novo (JSON_SCHEMA_VALID em CHECK).
--
-- Ensaio (no servidor, a partir de /var/www/chronodesk; audit_log so na
-- estrutura):
--   sudo env DADOS=portal_schedule_rules,portal_schedule_exceptions \
--     VERIFICA=migrations/verificacao/20261117_024_critical_roles_check.sql \
--     bash scripts/ensaio-migration.sh pc_024 \
--     migrations/20261117_024_critical_roles.sql \
--     migrations/rollback/20261117_024_critical_roles_down.sql audit_log
--
-- Rollback: migrations/rollback/20261117_024_critical_roles_down.sql.
-- Verificacao: migrations/verificacao/20261117_024_critical_roles_check.sql.

SET time_zone = 'America/Sao_Paulo';

-- 1. Papeis
SET @has_roles_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_roles'
);
SET @create_roles_sql := IF(
    @has_roles_table = 0,
    'CREATE TABLE portal_critical_roles (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(60) NOT NULL COMMENT ''Nome do papel (ex.: Abertura home); unico'',
        presence_type ENUM(''onsite'', ''remote'') NOT NULL COMMENT ''Tipo de presenca do dia com o papel'',
        work_start TIME NOT NULL COMMENT ''Inicio da jornada do papel'',
        work_end TIME NOT NULL COMMENT ''Fim da jornada do papel (mesmo dia)'',
        active TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''0 = papel desativado (fora de rodizio novo)'',
        sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        created_by VARCHAR(100) NOT NULL,
        updated_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_critical_role_name (name),
        CONSTRAINT ck_critical_role_name CHECK (CHAR_LENGTH(TRIM(name)) > 0),
        CONSTRAINT ck_critical_role_shift CHECK (work_start < work_end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Papeis do Critico (9.16): jornada e tipo de presenca do dia''',
    'SELECT 1'
);
PREPARE create_roles_stmt FROM @create_roles_sql;
EXECUTE create_roles_stmt;
DEALLOCATE PREPARE create_roles_stmt;

-- 2. Rodizio com vigencia
SET @has_rotations_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_role_rotations'
);
SET @create_rotations_sql := IF(
    @has_rotations_table = 0,
    'CREATE TABLE portal_critical_role_rotations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        effective_from DATE NOT NULL COMMENT ''A versao vale de effective_from ate a vespera da proxima'',
        period ENUM(''week'', ''month'') NOT NULL COMMENT ''Troca de papel por semana ou por mes do calendario'',
        anchor_date DATE NOT NULL COMMENT ''Periodo 0: segunda (semana) ou dia 1 (mes)'',
        roles JSON NOT NULL COMMENT ''IDs dos papeis, na ordem (1 a 20, sem repeticao)'',
        employees JSON NOT NULL COMMENT ''IDs das pessoas do Critico, na ordem (1 a 20, sem repeticao)'',
        created_by VARCHAR(100) NOT NULL,
        updated_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_critical_rotation_from (effective_from),
        CONSTRAINT ck_critical_rotation_anchor CHECK ((period = ''week'' AND WEEKDAY(anchor_date) = 0) OR (period = ''month'' AND DAYOFMONTH(anchor_date) = 1)),
        CONSTRAINT ck_critical_rotation_roles CHECK (JSON_SCHEMA_VALID(''{"type": "array", "minItems": 1, "maxItems": 20, "uniqueItems": true, "items": {"type": "integer", "minimum": 1}}'', roles)),
        CONSTRAINT ck_critical_rotation_employees CHECK (JSON_SCHEMA_VALID(''{"type": "array", "minItems": 1, "maxItems": 20, "uniqueItems": true, "items": {"type": "integer", "minimum": 1}}'', employees))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Rodizio de papeis do Critico com vigencia (9.16)''',
    'SELECT 1'
);
PREPARE create_rotations_stmt FROM @create_rotations_sql;
EXECUTE create_rotations_stmt;
DEALLOCATE PREPARE create_rotations_stmt;

-- 3. Trocas pontuais
SET @has_overrides_table := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_role_overrides'
);
SET @create_overrides_sql := IF(
    @has_overrides_table = 0,
    'CREATE TABLE portal_critical_role_overrides (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        employee_id INT NOT NULL,
        starts_on DATE NOT NULL,
        ends_on DATE NOT NULL COMMENT ''Ultimo dia da troca (inclusive)'',
        role_id INT UNSIGNED NOT NULL,
        reason ENUM(''troca'', ''cobertura'', ''outro'') NOT NULL COMMENT ''Motivo em lista fechada (sem texto livre)'',
        created_by VARCHAR(100) NOT NULL,
        updated_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_critical_override_employee_from (employee_id, starts_on),
        KEY idx_critical_override_period (starts_on, ends_on),
        KEY idx_critical_override_role (role_id),
        CONSTRAINT fk_critical_override_employee FOREIGN KEY (employee_id) REFERENCES funcionarios (id) ON DELETE RESTRICT,
        CONSTRAINT fk_critical_override_role FOREIGN KEY (role_id) REFERENCES portal_critical_roles (id) ON DELETE RESTRICT,
        CONSTRAINT ck_critical_override_period CHECK (ends_on >= starts_on AND DATEDIFF(ends_on, starts_on) < 31)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Troca pontual de papel do Critico (9.16); precede o rodizio''',
    'SELECT 1'
);
PREPARE create_overrides_stmt FROM @create_overrides_sql;
EXECUTE create_overrides_stmt;
DEALLOCATE PREPARE create_overrides_stmt;
