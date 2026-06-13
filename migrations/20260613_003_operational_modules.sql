-- ChronoDesk - modulos operacionais
-- Migration incremental. Nao remove nem altera dados legados.

CREATE TABLE IF NOT EXISTS portal_schedule_rules (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    employee_name VARCHAR(100) NOT NULL,
    ad_login VARCHAR(100) DEFAULT NULL,
    team ENUM('n1', 'n2') NOT NULL,
    rule_type ENUM('even_days', 'odd_days', 'always_remote', 'always_onsite', 'undefined') NOT NULL DEFAULT 'undefined',
    effective_from DATE NOT NULL,
    effective_until DATE DEFAULT NULL,
    created_by VARCHAR(100) NOT NULL,
    updated_by VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_schedule_rule_employee (employee_id),
    INDEX idx_schedule_rule_team (team, rule_type),
    CONSTRAINT fk_schedule_rule_employee
        FOREIGN KEY (employee_id) REFERENCES funcionarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_schedule_exceptions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    employee_name VARCHAR(100) NOT NULL,
    team ENUM('n1', 'n2') NOT NULL,
    exception_date DATE NOT NULL,
    exception_type ENUM(
        'remote', 'onsite', 'day_off', 'absence', 'training',
        'oncall', 'vacation', 'leave'
    ) NOT NULL,
    note VARCHAR(1000) DEFAULT NULL,
    created_by VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_schedule_exception_employee_date (employee_id, exception_date),
    INDEX idx_schedule_exception_date (exception_date, team),
    CONSTRAINT fk_schedule_exception_employee
        FOREIGN KEY (employee_id) REFERENCES funcionarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_overtime_entries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    employee_name VARCHAR(100) NOT NULL,
    team ENUM('n1', 'n2') NOT NULL,
    work_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    total_minutes INT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    justification VARCHAR(2000) NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'synced', 'sync_error') NOT NULL DEFAULT 'pending',
    approved_by VARCHAR(100) DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    created_by VARCHAR(100) NOT NULL,
    updated_by VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_overtime_competency (work_date, team),
    INDEX idx_overtime_status (status, work_date),
    INDEX idx_overtime_employee (employee_id, work_date),
    CONSTRAINT fk_overtime_employee
        FOREIGN KEY (employee_id) REFERENCES funcionarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_time_adjustments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    employee_name VARCHAR(100) NOT NULL,
    team ENUM('n1', 'n2') NOT NULL,
    adjustment_date DATE NOT NULL,
    adjustment_type ENUM('entry', 'lunch_out', 'lunch_return', 'exit', 'absence', 'other') NOT NULL,
    correct_time TIME DEFAULT NULL,
    recorded_time TIME DEFAULT NULL,
    justification VARCHAR(2000) NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'synced', 'sync_error') NOT NULL DEFAULT 'pending',
    approved_by VARCHAR(100) DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    created_by VARCHAR(100) NOT NULL,
    updated_by VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_adjustment_competency (adjustment_date, team),
    INDEX idx_adjustment_status (status, adjustment_date),
    INDEX idx_adjustment_employee (employee_id, adjustment_date),
    CONSTRAINT fk_adjustment_employee
        FOREIGN KEY (employee_id) REFERENCES funcionarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_oncall_shifts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    employee_name VARCHAR(100) NOT NULL,
    team ENUM('n1', 'n2') NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    shift_type ENUM('oncall', 'standby', 'emergency', 'weekend', 'holiday') NOT NULL,
    note VARCHAR(1000) DEFAULT NULL,
    status ENUM('active', 'cancelled', 'completed', 'synced', 'sync_error') NOT NULL DEFAULT 'active',
    created_by VARCHAR(100) NOT NULL,
    updated_by VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_oncall_range (starts_on, ends_on, team),
    INDEX idx_oncall_employee (employee_id, starts_on),
    CONSTRAINT fk_oncall_employee
        FOREIGN KEY (employee_id) REFERENCES funcionarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_integration_settings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    integration_key VARCHAR(60) NOT NULL UNIQUE,
    provider VARCHAR(60) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    settings_json JSON DEFAULT NULL,
    created_by VARCHAR(100) NOT NULL,
    updated_by VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_sync_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    record_type ENUM('overtime', 'time_adjustment', 'schedule', 'oncall') NOT NULL,
    record_id BIGINT NOT NULL,
    operation ENUM('upsert', 'delete') NOT NULL DEFAULT 'upsert',
    status ENUM('pending', 'processing', 'synced', 'error') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME DEFAULT NULL,
    last_attempt_at DATETIME DEFAULT NULL,
    synced_at DATETIME DEFAULT NULL,
    last_error VARCHAR(2000) DEFAULT NULL,
    payload_json JSON NOT NULL,
    created_by VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    pending_key VARCHAR(160) GENERATED ALWAYS AS (
        CASE
            WHEN status = 'pending' THEN CONCAT(record_type, ':', record_id, ':', operation)
            ELSE NULL
        END
    ) STORED,
    INDEX idx_sync_queue_work (status, next_attempt_at, created_at),
    INDEX idx_sync_queue_record (record_type, record_id),
    UNIQUE KEY uq_sync_queue_pending (pending_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO portal_integration_settings (
    integration_key, provider, enabled, settings_json, created_by, updated_by
) VALUES (
    'sharepoint_excel',
    'microsoft_graph',
    FALSE,
    JSON_OBJECT('site_url', '', 'drive_id', '', 'workbook_path', '', 'worksheet_prefix', 'ChronoDesk'),
    'migration',
    'migration'
);
