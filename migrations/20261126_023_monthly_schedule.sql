-- ChronoDesk - F10-4: escala do mes no portal (desenho do F10, 3.5 e 8.9).
-- Idempotente: cada passo confere information_schema antes de criar.
--
-- 1. portal_monthly_schedules: um mes por linha (ref_month = dia 1, unico),
--    current_revision (0 = nunca publicado) e row_version (409 da publicacao
--    concorrente, D-F10-48).
-- 2. portal_monthly_schedule_revisions: cada publicacao ou regeracao (nada e
--    apagado): revisao, data inicial, hash das entradas, versao do motor,
--    motivo, autoria e contagens (JSON so com numeros).
-- 3. portal_monthly_schedule_entries: a linha de cada pessoa por dia util da
--    revisao, so com IDs: situacao, origem, time na data, PA, fonte do PA,
--    sem PA e troca. Sem nome, sem categoria de ausencia, sem observacao.
-- 4. portal_pa_day_locks: travas manuais de PA por dia (um PA por pessoa e
--    uma pessoa por PA no dia), com autoria.
--
-- Nenhuma linha de outra tabela muda. Pode ser aplicada ANTES do git merge
-- (o codigo anterior nao le estas tabelas) e DEVE ser aplicada antes dele:
-- o codigo novo le e grava nelas.
-- A data nao e usada; SET time_zone segue a regra do A4 para sessao manual.
-- Exige MySQL 8.0.16 ou mais novo (CHECK).
--
-- Ensaio (no servidor, a partir de /var/www/chronodesk; audit_log so na
-- estrutura):
--   sudo env DADOS=funcionarios,portal_pa_inventory \
--     VERIFICA=migrations/verificacao/20261126_023_monthly_schedule_check.sql \
--     bash scripts/ensaio-migration.sh f10_023 \
--     migrations/20261126_023_monthly_schedule.sql \
--     migrations/rollback/20261126_023_monthly_schedule_down.sql audit_log
--
-- Rollback: migrations/rollback/20261126_023_monthly_schedule_down.sql.
-- Verificacao: migrations/verificacao/20261126_023_monthly_schedule_check.sql.

SET time_zone = 'America/Sao_Paulo';

-- 1. O mes
SET @has_months := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_monthly_schedules'
);
SET @create_months_sql := IF(
    @has_months = 0,
    'CREATE TABLE portal_monthly_schedules (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        ref_month DATE NOT NULL COMMENT ''Mes (sempre o dia 1)'',
        current_revision INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''Ultima revisao publicada (0 = nunca)'',
        row_version INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''Versao para o 409 da publicacao'',
        created_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_monthly_schedule_month (ref_month),
        CONSTRAINT ck_monthly_schedule_day1 CHECK (DAYOFMONTH(ref_month) = 1),
        CONSTRAINT ck_monthly_schedule_version CHECK (row_version >= 1)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Escala do mes (F10-4): um mes por linha''',
    'SELECT 1'
);
PREPARE create_months_stmt FROM @create_months_sql;
EXECUTE create_months_stmt;
DEALLOCATE PREPARE create_months_stmt;

-- 2. As revisoes
SET @has_revisions := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_monthly_schedule_revisions'
);
SET @create_revisions_sql := IF(
    @has_revisions = 0,
    'CREATE TABLE portal_monthly_schedule_revisions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        schedule_id INT UNSIGNED NOT NULL,
        revision INT UNSIGNED NOT NULL COMMENT ''1, 2, ... por mes'',
        from_date DATE NOT NULL COMMENT ''Primeiro dia que esta revisao vale'',
        input_hash CHAR(64) NOT NULL COMMENT ''SHA-256 das entradas do calculo'',
        engine_version VARCHAR(20) NOT NULL,
        reason ENUM(''publicacao'', ''regeracao'') NOT NULL,
        published_by VARCHAR(100) NOT NULL,
        published_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        counts JSON NOT NULL COMMENT ''So contagens'',
        PRIMARY KEY (id),
        UNIQUE KEY uq_monthly_revision (schedule_id, revision),
        CONSTRAINT fk_monthly_revision_schedule FOREIGN KEY (schedule_id) REFERENCES portal_monthly_schedules (id) ON DELETE RESTRICT,
        CONSTRAINT ck_monthly_revision_number CHECK (revision >= 1),
        CONSTRAINT ck_monthly_revision_hash CHECK (CHAR_LENGTH(input_hash) = 64),
        CONSTRAINT ck_monthly_revision_counts CHECK (JSON_TYPE(counts) = ''OBJECT'')
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Escala do mes (F10-4): publicacoes e regeracoes, nada e apagado''',
    'SELECT 1'
);
PREPARE create_revisions_stmt FROM @create_revisions_sql;
EXECUTE create_revisions_stmt;
DEALLOCATE PREPARE create_revisions_stmt;

-- 3. As linhas (so IDs)
SET @has_entries := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_monthly_schedule_entries'
);
SET @create_entries_sql := IF(
    @has_entries = 0,
    'CREATE TABLE portal_monthly_schedule_entries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        revision_id INT UNSIGNED NOT NULL,
        work_date DATE NOT NULL,
        employee_id INT NOT NULL,
        situation ENUM(''onsite'', ''remote'', ''absent'') NOT NULL,
        source ENUM(''rule'', ''team'', ''exception'', ''absence'', ''implicit'', ''role'', ''none'') NOT NULL,
        team_snapshot VARCHAR(20) NULL COMMENT ''Equipe na data'',
        pa_number VARCHAR(20) NULL,
        pa_source ENUM(''fixed'', ''reserved'', ''lock'', ''engine'') NULL,
        no_pa TINYINT(1) NOT NULL DEFAULT 0,
        moved TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY uq_monthly_entry (revision_id, work_date, employee_id),
        INDEX idx_monthly_entry_date (work_date, employee_id),
        CONSTRAINT fk_monthly_entry_revision FOREIGN KEY (revision_id) REFERENCES portal_monthly_schedule_revisions (id) ON DELETE RESTRICT,
        CONSTRAINT fk_monthly_entry_employee FOREIGN KEY (employee_id) REFERENCES funcionarios (id) ON DELETE RESTRICT,
        CONSTRAINT ck_monthly_entry_pa CHECK ((pa_number IS NULL) = (pa_source IS NULL)),
        CONSTRAINT ck_monthly_entry_no_pa CHECK (no_pa IN (0, 1) AND moved IN (0, 1) AND NOT (no_pa = 1 AND pa_number IS NOT NULL))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Escala do mes (F10-4): pessoa por dia, so IDs''',
    'SELECT 1'
);
PREPARE create_entries_stmt FROM @create_entries_sql;
EXECUTE create_entries_stmt;
DEALLOCATE PREPARE create_entries_stmt;

-- 4. Travas de PA por dia
SET @has_locks := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_pa_day_locks'
);
SET @create_locks_sql := IF(
    @has_locks = 0,
    'CREATE TABLE portal_pa_day_locks (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        work_date DATE NOT NULL,
        pa_number VARCHAR(20) NOT NULL,
        employee_id INT NOT NULL,
        created_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pa_day_lock_pa (work_date, pa_number),
        UNIQUE KEY uq_pa_day_lock_employee (work_date, employee_id),
        CONSTRAINT fk_pa_day_lock_employee FOREIGN KEY (employee_id) REFERENCES funcionarios (id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Travas manuais de PA por dia (F10-4)''',
    'SELECT 1'
);
PREPARE create_locks_stmt FROM @create_locks_sql;
EXECUTE create_locks_stmt;
DEALLOCATE PREPARE create_locks_stmt;
