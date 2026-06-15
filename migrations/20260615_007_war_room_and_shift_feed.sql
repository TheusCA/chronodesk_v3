-- ChronoDesk - campos operacionais da War Room e feed privado de escalas.
-- Migration incremental para MySQL 8.0. Preserva todos os dados existentes.

SET @critical_columns := 'ALTER TABLE portal_critical_incidents ';

SET @has_incident_number := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_critical_incidents'
      AND COLUMN_NAME = 'incident_number'
);
SET @sql := IF(
    @has_incident_number = 0,
    CONCAT(@critical_columns, 'ADD COLUMN incident_number VARCHAR(100) DEFAULT NULL AFTER id'),
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_room_date := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_critical_incidents'
      AND COLUMN_NAME = 'room_date'
);
SET @sql := IF(
    @has_room_date = 0,
    CONCAT(@critical_columns, 'ADD COLUMN room_date DATE DEFAULT NULL AFTER incident_number'),
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_definitions := JSON_OBJECT(
    'incident_opened_at', 'DATETIME DEFAULT NULL',
    'operation_reported_at', 'DATETIME DEFAULT NULL',
    'room_opened_at', 'DATETIME DEFAULT NULL',
    'normalized_at', 'DATETIME DEFAULT NULL',
    'room_description', 'TEXT DEFAULT NULL',
    'room_finalization_description', 'TEXT DEFAULT NULL',
    'room_opening_duration_minutes', 'INT UNSIGNED DEFAULT NULL',
    'room_duration_minutes', 'INT UNSIGNED DEFAULT NULL',
    'sector', 'VARCHAR(160) DEFAULT NULL',
    'sdk_activity', 'TEXT DEFAULT NULL'
);

SET @column_name := 'incident_opened_at';
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_incidents' AND COLUMN_NAME = @column_name) = 0,
    CONCAT(@critical_columns, 'ADD COLUMN incident_opened_at ', JSON_UNQUOTE(JSON_EXTRACT(@column_definitions, CONCAT('$.', @column_name)))),
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_name := 'operation_reported_at';
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_incidents' AND COLUMN_NAME = @column_name) = 0,
    CONCAT(@critical_columns, 'ADD COLUMN operation_reported_at ', JSON_UNQUOTE(JSON_EXTRACT(@column_definitions, CONCAT('$.', @column_name)))),
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_name := 'room_opened_at';
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_incidents' AND COLUMN_NAME = @column_name) = 0,
    CONCAT(@critical_columns, 'ADD COLUMN room_opened_at ', JSON_UNQUOTE(JSON_EXTRACT(@column_definitions, CONCAT('$.', @column_name)))),
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_name := 'normalized_at';
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_incidents' AND COLUMN_NAME = @column_name) = 0,
    CONCAT(@critical_columns, 'ADD COLUMN normalized_at ', JSON_UNQUOTE(JSON_EXTRACT(@column_definitions, CONCAT('$.', @column_name)))),
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_name := 'room_description';
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_incidents' AND COLUMN_NAME = @column_name) = 0,
    CONCAT(@critical_columns, 'ADD COLUMN room_description ', JSON_UNQUOTE(JSON_EXTRACT(@column_definitions, CONCAT('$.', @column_name)))),
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_name := 'room_finalization_description';
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_incidents' AND COLUMN_NAME = @column_name) = 0,
    CONCAT(@critical_columns, 'ADD COLUMN room_finalization_description ', JSON_UNQUOTE(JSON_EXTRACT(@column_definitions, CONCAT('$.', @column_name)))),
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_name := 'room_opening_duration_minutes';
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_incidents' AND COLUMN_NAME = @column_name) = 0,
    CONCAT(@critical_columns, 'ADD COLUMN room_opening_duration_minutes ', JSON_UNQUOTE(JSON_EXTRACT(@column_definitions, CONCAT('$.', @column_name)))),
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_name := 'room_duration_minutes';
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_incidents' AND COLUMN_NAME = @column_name) = 0,
    CONCAT(@critical_columns, 'ADD COLUMN room_duration_minutes ', JSON_UNQUOTE(JSON_EXTRACT(@column_definitions, CONCAT('$.', @column_name)))),
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_name := 'sector';
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_incidents' AND COLUMN_NAME = @column_name) = 0,
    CONCAT(@critical_columns, 'ADD COLUMN sector ', JSON_UNQUOTE(JSON_EXTRACT(@column_definitions, CONCAT('$.', @column_name)))),
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_name := 'sdk_activity';
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'portal_critical_incidents' AND COLUMN_NAME = @column_name) = 0,
    CONCAT(@critical_columns, 'ADD COLUMN sdk_activity ', JSON_UNQUOTE(JSON_EXTRACT(@column_definitions, CONCAT('$.', @column_name)))),
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE portal_critical_incidents
SET incident_number = COALESCE(NULLIF(incident_number, ''), ticket_number),
    room_date = COALESCE(room_date, DATE(war_room_started_at), DATE(opened_at)),
    incident_opened_at = COALESCE(incident_opened_at, opened_at),
    room_opened_at = COALESCE(room_opened_at, war_room_started_at),
    normalized_at = COALESCE(normalized_at, mitigated_at, resolved_at),
    room_description = COALESCE(room_description, summary, title),
    room_finalization_description = COALESCE(room_finalization_description, resolution),
    sector = COALESCE(sector, responsible_area),
    sdk_activity = COALESCE(sdk_activity, actions_taken)
WHERE incident_number IS NULL
   OR room_date IS NULL
   OR incident_opened_at IS NULL
   OR room_description IS NULL;

SET @has_incident_index := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_critical_incidents'
      AND INDEX_NAME = 'idx_critical_incident_room_date'
);
SET @sql := IF(
    @has_incident_index = 0,
    'ALTER TABLE portal_critical_incidents ADD INDEX idx_critical_incident_room_date (room_date, incident_number)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS portal_shift_attachments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    reference_month CHAR(7) NOT NULL,
    notes VARCHAR(2000) DEFAULT NULL,
    original_name VARCHAR(180) NOT NULL,
    stored_name VARCHAR(100) NOT NULL,
    storage_key VARCHAR(180) NOT NULL,
    extension VARCHAR(10) NOT NULL,
    detected_mime VARCHAR(160) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by VARCHAR(100) NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'deleted') NOT NULL DEFAULT 'active',
    updated_by VARCHAR(100) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shift_attachment_stored_name (stored_name),
    INDEX idx_shift_attachment_feed (status, reference_month, uploaded_at),
    INDEX idx_shift_attachment_type (extension, uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
