-- ChronoDesk - Mapa de PA recorrente por regra de escala.
-- Idempotente; preserva portal_pa_map legado e adiciona inventario/vinculos.

CREATE TABLE IF NOT EXISTS portal_pa_inventory (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    pa_number VARCHAR(20) NOT NULL,
    display_order INT UNSIGNED NOT NULL DEFAULT 0,
    grid_row INT UNSIGNED DEFAULT NULL,
    grid_column INT UNSIGNED DEFAULT NULL,
    label VARCHAR(80) DEFAULT NULL,
    status ENUM('active', 'unavailable') NOT NULL DEFAULT 'active',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pa_inventory_number (pa_number),
    INDEX idx_pa_inventory_active_order (active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_pa_inventory_grid_row := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_pa_inventory'
      AND COLUMN_NAME = 'grid_row'
);
SET @has_pa_inventory_row_number := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_pa_inventory'
      AND COLUMN_NAME = 'row_number'
);
SET @fix_pa_inventory_grid_row_sql := IF(
    @has_pa_inventory_grid_row = 0 AND @has_pa_inventory_row_number > 0,
    'ALTER TABLE portal_pa_inventory RENAME COLUMN `row_number` TO grid_row',
    IF(
        @has_pa_inventory_grid_row = 0,
        'ALTER TABLE portal_pa_inventory ADD COLUMN grid_row INT UNSIGNED DEFAULT NULL AFTER display_order',
        'SELECT 1'
    )
);
PREPARE fix_pa_inventory_grid_row_stmt FROM @fix_pa_inventory_grid_row_sql;
EXECUTE fix_pa_inventory_grid_row_stmt;
DEALLOCATE PREPARE fix_pa_inventory_grid_row_stmt;

SET @has_pa_inventory_grid_column := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_pa_inventory'
      AND COLUMN_NAME = 'grid_column'
);
SET @has_pa_inventory_column_number := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_pa_inventory'
      AND COLUMN_NAME = 'column_number'
);
SET @fix_pa_inventory_grid_column_sql := IF(
    @has_pa_inventory_grid_column = 0 AND @has_pa_inventory_column_number > 0,
    'ALTER TABLE portal_pa_inventory RENAME COLUMN `column_number` TO grid_column',
    IF(
        @has_pa_inventory_grid_column = 0,
        'ALTER TABLE portal_pa_inventory ADD COLUMN grid_column INT UNSIGNED DEFAULT NULL AFTER grid_row',
        'SELECT 1'
    )
);
PREPARE fix_pa_inventory_grid_column_stmt FROM @fix_pa_inventory_grid_column_sql;
EXECUTE fix_pa_inventory_grid_column_stmt;
DEALLOCATE PREPARE fix_pa_inventory_grid_column_stmt;

INSERT INTO portal_pa_inventory (pa_number, display_order, grid_row, grid_column, label, status, active)
VALUES
    ('1732', 1, 1, 1, NULL, 'active', 1),
    ('1731', 2, 1, 2, NULL, 'active', 1),
    ('1730', 3, 1, 3, NULL, 'active', 1),
    ('1729', 4, 1, 4, NULL, 'active', 1),
    ('1728', 5, 1, 5, NULL, 'active', 1),
    ('1727', 6, 1, 6, NULL, 'active', 1),
    ('1726', 7, 1, 7, NULL, 'active', 1),
    ('1725', 8, 1, 8, NULL, 'active', 1),
    ('1724', 9, 2, 1, NULL, 'active', 1),
    ('1723', 10, 2, 2, NULL, 'active', 1),
    ('1722', 11, 2, 3, NULL, 'active', 1),
    ('1721', 12, 2, 4, NULL, 'active', 1),
    ('1720', 13, 2, 5, NULL, 'active', 1),
    ('1719', 14, 2, 6, NULL, 'active', 1),
    ('1718', 15, 2, 7, NULL, 'active', 1),
    ('1717', 16, 2, 8, NULL, 'active', 1)
ON DUPLICATE KEY UPDATE
    display_order = VALUES(display_order),
    grid_row = VALUES(grid_row),
    grid_column = VALUES(grid_column),
    active = VALUES(active);

CREATE TABLE IF NOT EXISTS portal_pa_assignments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    pa_number VARCHAR(20) NOT NULL,
    employee_id INT NOT NULL,
    employee_name VARCHAR(160) NOT NULL,
    employee_login VARCHAR(100) DEFAULT NULL,
    team ENUM('n1', 'n2', 'lideranca') NOT NULL,
    schedule_rule_type ENUM('even_days', 'odd_days', 'always_onsite', 'always_remote', 'undefined') NOT NULL DEFAULT 'undefined',
    valid_from DATE NOT NULL,
    valid_until DATE DEFAULT NULL,
    notes VARCHAR(1000) DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at DATETIME DEFAULT NULL,
    created_by VARCHAR(100) NOT NULL,
    updated_by VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pa_assignment_pa_period (pa_number, active, valid_from, valid_until),
    INDEX idx_pa_assignment_employee_period (employee_id, active, valid_from, valid_until),
    INDEX idx_pa_assignment_team (team, active),
    CONSTRAINT fk_pa_assignment_employee
        FOREIGN KEY (employee_id) REFERENCES funcionarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE portal_pa_assignments
    MODIFY COLUMN team ENUM('n1', 'n2', 'lideranca') NOT NULL;
