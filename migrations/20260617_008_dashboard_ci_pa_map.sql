-- ChronoDesk - dashboard realtime, tecnico SDK em chamados criticos e Mapa de PA.
-- Migration incremental e idempotente. Nao remove dados existentes.

SET @has_sdk_responsible_employee_id := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_critical_incidents'
      AND COLUMN_NAME = 'sdk_responsible_employee_id'
);
SET @add_sdk_responsible_employee_id_sql := IF(
    @has_sdk_responsible_employee_id = 0,
    'ALTER TABLE portal_critical_incidents
        ADD COLUMN sdk_responsible_employee_id INT DEFAULT NULL AFTER responsible_area,
        ADD COLUMN sdk_responsible_name VARCHAR(160) DEFAULT NULL AFTER sdk_responsible_employee_id,
        ADD COLUMN sdk_responsible_login VARCHAR(100) DEFAULT NULL AFTER sdk_responsible_name,
        ADD INDEX idx_critical_incident_sdk_responsible (sdk_responsible_employee_id, room_date)',
    'SELECT 1'
);
PREPARE add_sdk_responsible_employee_id_stmt FROM @add_sdk_responsible_employee_id_sql;
EXECUTE add_sdk_responsible_employee_id_stmt;
DEALLOCATE PREPARE add_sdk_responsible_employee_id_stmt;

CREATE TABLE IF NOT EXISTS portal_pa_map (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    pa_number VARCHAR(20) NOT NULL,
    work_date DATE NOT NULL,
    shift_label VARCHAR(40) NOT NULL DEFAULT 'integral',
    employee_id INT NOT NULL,
    employee_name VARCHAR(160) NOT NULL,
    employee_login VARCHAR(100) DEFAULT NULL,
    team ENUM('n1', 'n2') NOT NULL,
    status ENUM('onsite', 'hybrid', 'remote', 'free', 'critical', 'unavailable') NOT NULL DEFAULT 'onsite',
    schedule_status VARCHAR(40) DEFAULT NULL,
    schedule_label VARCHAR(80) DEFAULT NULL,
    notes VARCHAR(1000) DEFAULT NULL,
    record_status ENUM('active', 'deleted') NOT NULL DEFAULT 'active',
    deleted_at DATETIME DEFAULT NULL,
    created_by VARCHAR(100) NOT NULL,
    updated_by VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    active_pa_slot_key VARCHAR(120) GENERATED ALWAYS AS (
        CASE
            WHEN record_status = 'active'
            THEN CONCAT(pa_number, ':', work_date, ':', shift_label)
            ELSE NULL
        END
    ) STORED,
    active_employee_slot_key VARCHAR(120) GENERATED ALWAYS AS (
        CASE
            WHEN record_status = 'active'
            THEN CONCAT(employee_id, ':', work_date, ':', shift_label)
            ELSE NULL
        END
    ) STORED,
    UNIQUE KEY uq_pa_map_active_pa_slot (active_pa_slot_key),
    UNIQUE KEY uq_pa_map_active_employee_slot (active_employee_slot_key),
    INDEX idx_pa_map_date_team (work_date, team, record_status),
    INDEX idx_pa_map_employee (employee_id, work_date),
    CONSTRAINT fk_pa_map_employee
        FOREIGN KEY (employee_id) REFERENCES funcionarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
