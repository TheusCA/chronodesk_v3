-- ChronoDesk - documentos privados e perfil de acesso de funcionarios.
-- Migration incremental para MySQL 8.0. Nao remove dados legados.

SET @has_access_role := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'funcionarios'
      AND COLUMN_NAME = 'access_role'
);
SET @add_access_role_sql := IF(
    @has_access_role = 0,
    'ALTER TABLE funcionarios
        ADD COLUMN access_role
        ENUM(''tecnico'', ''gestor'', ''admin'', ''somente_leitura'')
        NOT NULL DEFAULT ''tecnico'' AFTER equipe,
        ADD INDEX idx_funcionarios_access_role (access_role, ativo)',
    'SELECT 1'
);
PREPARE add_access_role_stmt FROM @add_access_role_sql;
EXECUTE add_access_role_stmt;
DEALLOCATE PREPARE add_access_role_stmt;

SET @has_access_role_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'funcionarios'
      AND INDEX_NAME = 'idx_funcionarios_access_role'
);
SET @add_access_role_index_sql := IF(
    @has_access_role_index = 0,
    'ALTER TABLE funcionarios ADD INDEX idx_funcionarios_access_role (access_role, ativo)',
    'SELECT 1'
);
PREPARE add_access_role_index_stmt FROM @add_access_role_index_sql;
EXECUTE add_access_role_index_stmt;
DEALLOCATE PREPARE add_access_role_index_stmt;

CREATE TABLE IF NOT EXISTS portal_document_files (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    category VARCHAR(60) NOT NULL,
    description VARCHAR(2000) DEFAULT NULL,
    original_name VARCHAR(180) NOT NULL,
    stored_name VARCHAR(100) NOT NULL,
    storage_key VARCHAR(180) NOT NULL,
    extension VARCHAR(10) NOT NULL,
    detected_mime VARCHAR(160) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by VARCHAR(100) NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'archived', 'deleted') NOT NULL DEFAULT 'active',
    visibility ENUM('internal', 'management') NOT NULL DEFAULT 'internal',
    updated_by VARCHAR(100) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_document_stored_name (stored_name),
    INDEX idx_document_filters (status, category, extension, uploaded_at),
    INDEX idx_document_uploader (uploaded_by, uploaded_at),
    INDEX idx_document_sha256 (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
