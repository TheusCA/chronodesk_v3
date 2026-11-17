-- ChronoDesk - Controle de CHG no portal (desenho principal, secao 9.17;
-- plano de 2026-11-17, decisoes D-CHG-13 a D-CHG-31).
-- Idempotente: cada passo confere information_schema antes de alterar.
-- So estrutura: dez tabelas novas, VAZIAS; nenhuma linha existente muda. Nenhum
-- nome de cliente, local, tecnico ou link no git: os catalogos e os cards
-- entram pela importacao do Controle v5 (admin) ou pela tela.
--
-- 1. funcionario_grupos: grupos por funcionario, separados da equipe e do
--    perfil (D-CHG-13). Hoje so 'tools' (permissao chg.manage).
-- 2. portal_chg_carteiras e portal_chg_locais: catalogos editaveis.
-- 3. portal_chg_updates: o card (aprovacao Service, janela do Service Desk,
--    numero e status da CHG, tecnico por FK ou nome do historico importado,
--    etapa manual, conclusao, arquivamento, versao da linha).
-- 4. Partes do card: carteiras (copia de codigo e nome, D-CHG-18), locais
--    (sem repetir), planos com o status das tasks (posicao 0 = Plano 1),
--    tentativas de patch (a de maior numero e a atual) com os endpoints, e o
--    rollback (no maximo um ativo por card: coluna gerada unica).
--
-- Pode ser aplicada ANTES do git merge (o codigo anterior nao le nada disto) e
-- DEVE ser aplicada antes dele: o codigo novo le funcionario_grupos no cadastro
-- de funcionarios.
-- A data nao e usada; SET time_zone segue a regra do A4 para sessao manual.
-- Exige MySQL 8.0.16 ou mais novo (CHECK; REGEXP_LIKE em CHECK).
--
-- Ensaio (no servidor, a partir de /var/www/chronodesk; audit_log so na
-- estrutura):
--   sudo env VERIFICA=migrations/verificacao/20261117_025_chg_check.sql \
--     bash scripts/ensaio-migration.sh chg_025 \
--     migrations/20261117_025_chg.sql \
--     migrations/rollback/20261117_025_chg_down.sql audit_log
--
-- Rollback: migrations/rollback/20261117_025_chg_down.sql.
-- Verificacao: migrations/verificacao/20261117_025_chg_check.sql.

SET time_zone = 'America/Sao_Paulo';


-- 1. funcionario_grupos
SET @has_1 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funcionario_grupos'
);
SET @create_1_sql := IF(
    @has_1 = 0,
    'CREATE TABLE funcionario_grupos (
        funcionario_id INT NOT NULL,
        grupo ENUM(''tools'') NOT NULL COMMENT ''Grupo separado da equipe e do perfil (lista fechada)'',
        created_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (funcionario_id, grupo),
        KEY idx_funcionario_grupos_grupo (grupo),
        CONSTRAINT fk_funcionario_grupos_funcionario FOREIGN KEY (funcionario_id) REFERENCES funcionarios (id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Grupos por funcionario (9.17; Tools da permissao chg.manage)''',
    'SELECT 1'
);
PREPARE create_1_stmt FROM @create_1_sql;
EXECUTE create_1_stmt;
DEALLOCATE PREPARE create_1_stmt;

-- 2. portal_chg_carteiras
SET @has_2 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_carteiras'
);
SET @create_2_sql := IF(
    @has_2 = 0,
    'CREATE TABLE portal_chg_carteiras (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        codigo VARCHAR(10) NOT NULL COMMENT ''Codigo da carteira (so digitos); unico'',
        nome VARCHAR(120) NOT NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        created_by VARCHAR(100) NOT NULL,
        updated_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_chg_carteira_codigo (codigo),
        CONSTRAINT ck_chg_carteira_codigo CHECK (REGEXP_LIKE(codigo, ''^[0-9]{1,10}$'')),
        CONSTRAINT ck_chg_carteira_nome CHECK (CHAR_LENGTH(TRIM(nome)) > 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Catalogo de carteiras do Controle de CHG (9.17); preenchido pela importacao ou pela tela''',
    'SELECT 1'
);
PREPARE create_2_stmt FROM @create_2_sql;
EXECUTE create_2_stmt;
DEALLOCATE PREPARE create_2_stmt;

-- 3. portal_chg_locais
SET @has_3 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_locais'
);
SET @create_3_sql := IF(
    @has_3 = 0,
    'CREATE TABLE portal_chg_locais (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        nome VARCHAR(60) NOT NULL COMMENT ''Unico, sem diferenca de caixa'',
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        created_by VARCHAR(100) NOT NULL,
        updated_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_chg_local_nome (nome),
        CONSTRAINT ck_chg_local_nome CHECK (CHAR_LENGTH(TRIM(nome)) > 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Catalogo de locais do Controle de CHG (9.17)''',
    'SELECT 1'
);
PREPARE create_3_stmt FROM @create_3_sql;
EXECUTE create_3_stmt;
DEALLOCATE PREPARE create_3_stmt;

-- 4. portal_chg_updates
SET @has_4 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_updates'
);
SET @create_4_sql := IF(
    @has_4 = 0,
    'CREATE TABLE portal_chg_updates (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        legacy_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT ''id do card no Controle v5.2 (importacao idempotente)'',
        solicitante VARCHAR(100) NOT NULL,
        tipo ENUM(''padrao'', ''emergencial'') NOT NULL DEFAULT ''padrao'',
        versao VARCHAR(500) NOT NULL DEFAULT '''' COMMENT ''Versao ou caminho (texto puro, nunca link)'',
        versao_anterior VARCHAR(500) NOT NULL DEFAULT '''',
        descricao VARCHAR(300) NOT NULL DEFAULT '''',
        link_planilha VARCHAR(2048) NOT NULL DEFAULT '''' COMMENT ''So http(s)'',
        sugestao_at DATETIME NULL,
        service_status ENUM(''aguardando'', ''aprovado'', ''cancelado'') NOT NULL DEFAULT ''aguardando'' COMMENT ''Aprovacao Service'',
        sd_inicio DATETIME NULL COMMENT ''Inicio do Service Desk'',
        sd_fim DATETIME NULL COMMENT ''Fim do Service Desk'',
        chg_numero VARCHAR(40) NULL COMMENT ''Numero da CHG, em maiusculas'',
        chg_status ENUM(''pendente'', ''aprovada'', ''cancelada'') NULL COMMENT ''Nulo = nao solicitada'',
        tecnico_id INT NULL,
        tecnico_nome_legado VARCHAR(100) NULL COMMENT ''So do historico importado sem correspondencia'',
        stage_manual ENUM(''comite'', ''prep'', ''exec'', ''valid'') NULL,
        stage_manual_auto ENUM(''comite'', ''prep'', ''exec'', ''valid'') NULL COMMENT ''Etapa automatica no momento do movimento manual'',
        concluida TINYINT(1) NOT NULL DEFAULT 0,
        concluida_em DATETIME(3) NULL,
        archived_at DATETIME NULL,
        archived_by VARCHAR(100) NULL,
        row_version INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''Concorrencia: edicao com versao velha = 409'',
        created_by VARCHAR(100) NOT NULL,
        updated_by VARCHAR(100) NOT NULL,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        PRIMARY KEY (id),
        UNIQUE KEY uq_chg_update_legacy (legacy_id),
        KEY idx_chg_update_board (archived_at, sd_inicio),
        KEY idx_chg_update_tecnico (tecnico_id),
        CONSTRAINT fk_chg_update_tecnico FOREIGN KEY (tecnico_id) REFERENCES funcionarios (id) ON DELETE RESTRICT,
        CONSTRAINT ck_chg_update_solicitante CHECK (CHAR_LENGTH(TRIM(solicitante)) > 0),
        CONSTRAINT ck_chg_update_janela CHECK (sd_inicio IS NULL OR sd_fim IS NULL OR sd_fim >= sd_inicio),
        CONSTRAINT ck_chg_update_numero CHECK (chg_numero IS NULL OR (CHAR_LENGTH(TRIM(chg_numero)) > 0 AND CAST(chg_numero AS BINARY) = CAST(UPPER(chg_numero) AS BINARY))),
        CONSTRAINT ck_chg_update_status CHECK (chg_status IS NULL OR chg_numero IS NOT NULL),
        CONSTRAINT ck_chg_update_tecnico CHECK (tecnico_id IS NULL OR tecnico_nome_legado IS NULL),
        CONSTRAINT ck_chg_update_manual CHECK ((stage_manual IS NULL) = (stage_manual_auto IS NULL)),
        CONSTRAINT ck_chg_update_concluida CHECK (concluida IN (0, 1))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Cards do Controle de CHG (9.17); nunca apagados (arquivamento)''',
    'SELECT 1'
);
PREPARE create_4_stmt FROM @create_4_sql;
EXECUTE create_4_stmt;
DEALLOCATE PREPARE create_4_stmt;

-- 5. portal_chg_update_carteiras
SET @has_5 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_update_carteiras'
);
SET @create_5_sql := IF(
    @has_5 = 0,
    'CREATE TABLE portal_chg_update_carteiras (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        update_id BIGINT UNSIGNED NOT NULL,
        position SMALLINT UNSIGNED NOT NULL,
        codigo VARCHAR(10) NULL COMMENT ''Nulo = carteira sem codigo'',
        nome VARCHAR(120) NOT NULL,
        pas INT UNSIGNED NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_chg_update_carteira_pos (update_id, position),
        CONSTRAINT fk_chg_update_carteira_update FOREIGN KEY (update_id) REFERENCES portal_chg_updates (id) ON DELETE RESTRICT,
        CONSTRAINT ck_chg_update_carteira_pos CHECK (position < 50),
        CONSTRAINT ck_chg_update_carteira_codigo CHECK (codigo IS NULL OR REGEXP_LIKE(codigo, ''^[0-9]{1,10}$'')),
        CONSTRAINT ck_chg_update_carteira_nome CHECK (CHAR_LENGTH(TRIM(nome)) > 0),
        CONSTRAINT ck_chg_update_carteira_pas CHECK (pas IS NULL OR pas <= 99999)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Carteiras de cada card (copia de codigo e nome; 9.17)''',
    'SELECT 1'
);
PREPARE create_5_stmt FROM @create_5_sql;
EXECUTE create_5_stmt;
DEALLOCATE PREPARE create_5_stmt;

-- 6. portal_chg_update_locais
SET @has_6 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_update_locais'
);
SET @create_6_sql := IF(
    @has_6 = 0,
    'CREATE TABLE portal_chg_update_locais (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        update_id BIGINT UNSIGNED NOT NULL,
        position SMALLINT UNSIGNED NOT NULL,
        nome VARCHAR(60) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_chg_update_local_pos (update_id, position),
        UNIQUE KEY uq_chg_update_local_nome (update_id, nome),
        CONSTRAINT fk_chg_update_local_update FOREIGN KEY (update_id) REFERENCES portal_chg_updates (id) ON DELETE RESTRICT,
        CONSTRAINT ck_chg_update_local_pos CHECK (position < 20),
        CONSTRAINT ck_chg_update_local_nome CHECK (CHAR_LENGTH(TRIM(nome)) > 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Locais de cada card, sem repetir (9.17)''',
    'SELECT 1'
);
PREPARE create_6_stmt FROM @create_6_sql;
EXECUTE create_6_stmt;
DEALLOCATE PREPARE create_6_stmt;

-- 7. portal_chg_plans
SET @has_7 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_plans'
);
SET @create_7_sql := IF(
    @has_7 = 0,
    'CREATE TABLE portal_chg_plans (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        update_id BIGINT UNSIGNED NOT NULL,
        position SMALLINT UNSIGNED NOT NULL COMMENT ''0 = Plano 1 (nunca sai)'',
        implantacao VARCHAR(40) NOT NULL DEFAULT '''',
        backup VARCHAR(40) NOT NULL DEFAULT '''',
        rollback VARCHAR(40) NOT NULL DEFAULT '''',
        task_impl ENUM(''pendente'', ''fechada'') NOT NULL DEFAULT ''pendente'',
        task_bkp ENUM(''pendente'', ''fechada'') NOT NULL DEFAULT ''pendente'',
        task_roll ENUM(''pendente'', ''fechada'') NOT NULL DEFAULT ''pendente'',
        PRIMARY KEY (id),
        UNIQUE KEY uq_chg_plan_pos (update_id, position),
        CONSTRAINT fk_chg_plan_update FOREIGN KEY (update_id) REFERENCES portal_chg_updates (id) ON DELETE RESTRICT,
        CONSTRAINT ck_chg_plan_pos CHECK (position < 20),
        CONSTRAINT ck_chg_plan_implantacao CHECK (CAST(implantacao AS BINARY) = CAST(UPPER(implantacao) AS BINARY)),
        CONSTRAINT ck_chg_plan_backup CHECK (CAST(backup AS BINARY) = CAST(UPPER(backup) AS BINARY)),
        CONSTRAINT ck_chg_plan_rollback CHECK (CAST(rollback AS BINARY) = CAST(UPPER(rollback) AS BINARY))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Planos da CHG de cada card, com o status das tasks (9.17)''',
    'SELECT 1'
);
PREPARE create_7_stmt FROM @create_7_sql;
EXECUTE create_7_stmt;
DEALLOCATE PREPARE create_7_stmt;

-- 8. portal_chg_attempts
SET @has_8 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_attempts'
);
SET @create_8_sql := IF(
    @has_8 = 0,
    'CREATE TABLE portal_chg_attempts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        update_id BIGINT UNSIGNED NOT NULL,
        attempt_no SMALLINT UNSIGNED NOT NULL COMMENT ''A de maior numero e a atual'',
        patch_status ENUM(''sucesso'', ''parcial'', ''erro'') NULL COMMENT ''Nulo = sem status'',
        reagendando TINYINT(1) NOT NULL DEFAULT 0,
        registered_at DATETIME(3) NULL COMMENT ''Quando foi para o historico (nulo na atual)'',
        PRIMARY KEY (id),
        UNIQUE KEY uq_chg_attempt_no (update_id, attempt_no),
        CONSTRAINT fk_chg_attempt_update FOREIGN KEY (update_id) REFERENCES portal_chg_updates (id) ON DELETE RESTRICT,
        CONSTRAINT ck_chg_attempt_no CHECK (attempt_no BETWEEN 1 AND 50),
        CONSTRAINT ck_chg_attempt_reagendando CHECK (reagendando IN (0, 1))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Tentativas de patch de cada card: a atual e o historico (9.17)''',
    'SELECT 1'
);
PREPARE create_8_stmt FROM @create_8_sql;
EXECUTE create_8_stmt;
DEALLOCATE PREPARE create_8_stmt;

-- 9. portal_chg_endpoints
SET @has_9 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_endpoints'
);
SET @create_9_sql := IF(
    @has_9 = 0,
    'CREATE TABLE portal_chg_endpoints (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        attempt_id BIGINT UNSIGNED NOT NULL,
        position SMALLINT UNSIGNED NOT NULL,
        url VARCHAR(2048) NOT NULL DEFAULT '''' COMMENT ''So http(s)'',
        start_at DATETIME NULL COMMENT ''Inicio do patch no endpoint'',
        start_date DATE NULL COMMENT ''So a data: horario pendente'',
        PRIMARY KEY (id),
        UNIQUE KEY uq_chg_endpoint_pos (attempt_id, position),
        CONSTRAINT fk_chg_endpoint_attempt FOREIGN KEY (attempt_id) REFERENCES portal_chg_attempts (id) ON DELETE RESTRICT,
        CONSTRAINT ck_chg_endpoint_pos CHECK (position < 20),
        CONSTRAINT ck_chg_endpoint_inicio CHECK (start_at IS NULL OR start_date IS NULL),
        CONSTRAINT ck_chg_endpoint_url CHECK (url = '''' OR url LIKE ''http://_%'' OR url LIKE ''https://_%'')
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Endpoints de cada tentativa de patch (9.17)''',
    'SELECT 1'
);
PREPARE create_9_stmt FROM @create_9_sql;
EXECUTE create_9_stmt;
DEALLOCATE PREPARE create_9_stmt;

-- 10. portal_chg_rollbacks
SET @has_10 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_chg_rollbacks'
);
SET @create_10_sql := IF(
    @has_10 = 0,
    'CREATE TABLE portal_chg_rollbacks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        update_id BIGINT UNSIGNED NOT NULL,
        url VARCHAR(2048) NOT NULL DEFAULT '''' COMMENT ''So http(s)'',
        start_at DATETIME NULL COMMENT ''Inicio do rollback'',
        started_at DATETIME NOT NULL COMMENT ''Quando foi iniciado'',
        status ENUM(''em_andamento'', ''sucesso'', ''erro'') NOT NULL DEFAULT ''em_andamento'',
        plan_position SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''Plano de origem'',
        removed_at DATETIME NULL COMMENT ''Rollback excluido (fica o historico)'',
        removed_by VARCHAR(100) NULL,
        created_by VARCHAR(100) NOT NULL,
        active_update_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(removed_at IS NULL, update_id, NULL)) STORED,
        PRIMARY KEY (id),
        UNIQUE KEY uq_chg_rollback_ativo (active_update_id),
        KEY idx_chg_rollback_update (update_id),
        CONSTRAINT fk_chg_rollback_update FOREIGN KEY (update_id) REFERENCES portal_chg_updates (id) ON DELETE RESTRICT,
        CONSTRAINT ck_chg_rollback_url CHECK (url = '''' OR url LIKE ''http://_%'' OR url LIKE ''https://_%''),
        CONSTRAINT ck_chg_rollback_plano CHECK (plan_position < 20)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Rollback de cada card: no maximo um ativo (9.17)''',
    'SELECT 1'
);
PREPARE create_10_stmt FROM @create_10_sql;
EXECUTE create_10_stmt;
DEALLOCATE PREPARE create_10_stmt;
