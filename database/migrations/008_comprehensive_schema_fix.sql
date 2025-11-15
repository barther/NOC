-- Migration 008: Comprehensive Schema Fix
-- This migration ensures all tables exist and have the columns the code expects

SET @dbname = DATABASE();

-- ============================================================
-- 1. VACANCY_FILLS TABLE
-- ============================================================

-- Create vacancy_fills if it doesn't exist
CREATE TABLE IF NOT EXISTS vacancy_fills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vacancy_id INT NOT NULL,
    filled_by_dispatcher_id INT NULL COMMENT 'Legacy column name',
    dispatcher_id INT NULL COMMENT 'Column name used by code',
    fill_method ENUM(
        'eb_qualified',
        'eb_qualifier',
        'incumbent_overtime',
        'senior_restday_overtime',
        'junior_diversion_same_shift_with_eb',
        'junior_diversion_same_shift_no_eb',
        'senior_diversion_off_shift_overtime',
        'fallback_least_cost'
    ) NULL,
    filled_at DATETIME NULL COMMENT 'Legacy column name',
    filled_date DATETIME NULL COMMENT 'Column name used by code',
    pay_type ENUM('straight', 'overtime') NULL,
    hours_worked DECIMAL(4,2) DEFAULT 8.00,
    calculated_cost DECIMAL(10,2) NULL,
    improper_diversion TINYINT(1) DEFAULT 0,
    penalty_hours DECIMAL(4,2) DEFAULT 0.00,
    penalty_cost DECIMAL(10,2) DEFAULT 0.00,
    created_cascade_vacancy BOOLEAN DEFAULT FALSE,
    cascade_vacancy_id INT NULL,
    decision_log TEXT,
    penalty_owed BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vacancy_id) REFERENCES vacancies(id) ON DELETE CASCADE,
    INDEX idx_vacancy (vacancy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add dispatcher_id column (what code uses)
SET @tablename = "vacancy_fills";
SET @columnname = "dispatcher_id";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE vacancy_fills ADD COLUMN dispatcher_id INT NULL COMMENT 'Column name used by code';",
  "SELECT 1;"
));
PREPARE addDispatcherId FROM @preparedStatement;
EXECUTE addDispatcherId;
DEALLOCATE PREPARE addDispatcherId;

-- Add filled_date column (what code uses)
SET @columnname = "filled_date";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE vacancy_fills ADD COLUMN filled_date DATETIME NULL COMMENT 'Column name used by code';",
  "SELECT 1;"
));
PREPARE addFilledDate FROM @preparedStatement;
EXECUTE addFilledDate;
DEALLOCATE PREPARE addFilledDate;

-- Add hours_worked column
SET @columnname = "hours_worked";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE vacancy_fills ADD COLUMN hours_worked DECIMAL(4,2) DEFAULT 8.00;",
  "SELECT 1;"
));
PREPARE addHoursWorked FROM @preparedStatement;
EXECUTE addHoursWorked;
DEALLOCATE PREPARE addHoursWorked;

-- Add calculated_cost column
SET @columnname = "calculated_cost";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE vacancy_fills ADD COLUMN calculated_cost DECIMAL(10,2) NULL;",
  "SELECT 1;"
));
PREPARE addCalculatedCost FROM @preparedStatement;
EXECUTE addCalculatedCost;
DEALLOCATE PREPARE addCalculatedCost;

-- ============================================================
-- 2. VACANCIES TABLE
-- ============================================================

-- Create vacancies if it doesn't exist
CREATE TABLE IF NOT EXISTS vacancies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    desk_id INT NOT NULL,
    shift ENUM('first', 'second', 'third') NOT NULL,
    vacancy_date DATE NOT NULL,
    vacancy_type ENUM('vacation', 'training', 'loa', 'sick', 'other') NULL,
    reason VARCHAR(255) NULL COMMENT 'Reason for vacancy',
    incumbent_dispatcher_id INT NULL,
    status ENUM('pending', 'filled', 'unfilled', 'cancelled', 'open') DEFAULT 'pending',
    filled_by INT NULL COMMENT 'Dispatcher ID who filled this',
    filled_at DATETIME NULL,
    filled_by_option_rank INT NULL,
    filled_by_option_type VARCHAR(50) NULL,
    total_cost DECIMAL(10,2) NULL,
    is_planned BOOLEAN DEFAULT FALSE,
    posted_as_holddown BOOLEAN DEFAULT FALSE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (desk_id) REFERENCES desks(id) ON DELETE CASCADE,
    INDEX idx_date (vacancy_date),
    INDEX idx_status (status),
    INDEX idx_desk_date (desk_id, vacancy_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add reason column
SET @tablename = "vacancies";
SET @columnname = "reason";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE vacancies ADD COLUMN reason VARCHAR(255) NULL COMMENT 'Reason for vacancy';",
  "SELECT 1;"
));
PREPARE addReason FROM @preparedStatement;
EXECUTE addReason;
DEALLOCATE PREPARE addReason;

-- Add filled_by column
SET @columnname = "filled_by";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE vacancies ADD COLUMN filled_by INT NULL COMMENT 'Dispatcher ID who filled this';",
  "SELECT 1;"
));
PREPARE addFilledBy FROM @preparedStatement;
EXECUTE addFilledBy;
DEALLOCATE PREPARE addFilledBy;

-- Add filled_at column
SET @columnname = "filled_at";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE vacancies ADD COLUMN filled_at DATETIME NULL;",
  "SELECT 1;"
));
PREPARE addFilledAt FROM @preparedStatement;
EXECUTE addFilledAt;
DEALLOCATE PREPARE addFilledAt;

-- ============================================================
-- 3. ASSIGNMENT_LOG TABLE - Already has correct columns
-- ============================================================
-- assignment_log already has dispatcher_id, desk_id, shift, work_date, etc.
-- These were added in previous migrations

-- ============================================================
-- 4. GAD_AVAILABILITY_LOG TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS gad_availability_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispatcher_id INT NOT NULL,
    check_date DATE NOT NULL,
    shift ENUM('first', 'second', 'third') NOT NULL,
    available TINYINT(1) NOT NULL COMMENT 'Was GAD available?',
    unavailable_reason ENUM('rest_day', 'training', 'hos_violation', 'already_assigned', 'forced_limit', 'other') NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dispatcher_id) REFERENCES dispatchers(id) ON DELETE CASCADE,
    INDEX idx_dispatcher_date (dispatcher_id, check_date),
    INDEX idx_date_shift (check_date, shift)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Track GAD availability checks for auditing';

-- ============================================================
-- 5. VACANCY_FILL_OPTIONS TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS vacancy_fill_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vacancy_id INT NOT NULL,
    option_rank INT NOT NULL COMMENT 'Order-of-call rank (1=GAD, 2=Incumbent OT, etc)',
    option_type ENUM('gad', 'incumbent_ot', 'senior_rest_ot', 'junior_diversion_gad', 'junior_diversion', 'senior_offshift_gad', 'least_cost') NOT NULL,
    dispatcher_id INT NULL COMMENT 'Dispatcher being considered for this option',
    available TINYINT(1) DEFAULT 0 COMMENT 'If 1, dispatcher is available for this option',
    unavailable_reason VARCHAR(255) NULL COMMENT 'Why not available (HOS, training, rest day, etc)',
    pay_type ENUM('straight', 'overtime') NOT NULL,
    hours_worked DECIMAL(4,2) DEFAULT 8.00,
    hourly_rate DECIMAL(8,2) NULL COMMENT 'Dispatcher hourly rate',
    calculated_cost DECIMAL(10,2) NULL COMMENT 'Total cost of this option',
    requires_backfill TINYINT(1) DEFAULT 0 COMMENT 'If 1, creates another vacancy (diversion)',
    backfill_vacancy_id INT NULL COMMENT 'ID of vacancy created by this diversion',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vacancy_id) REFERENCES vacancies(id) ON DELETE CASCADE,
    INDEX idx_vacancy (vacancy_id),
    INDEX idx_rank (vacancy_id, option_rank)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Track all vacancy fill options and costs';

-- ============================================================
-- 6. DISPATCHER_PAY_RATES TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS dispatcher_pay_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispatcher_id INT NOT NULL,
    hourly_rate DECIMAL(8,2) NOT NULL COMMENT 'Base hourly rate',
    overtime_rate DECIMAL(8,2) NOT NULL COMMENT 'Overtime rate (typically 1.5x base)',
    effective_date DATE NOT NULL,
    end_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dispatcher_id) REFERENCES dispatchers(id) ON DELETE CASCADE,
    INDEX idx_dispatcher_date (dispatcher_id, effective_date),
    UNIQUE KEY unique_dispatcher_effective (dispatcher_id, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Dispatcher pay rates for cost calculations';

/*
This migration creates all tables that the VacancyEngine code expects.

Key fixes:
1. vacancy_fills: Added dispatcher_id and filled_date (code uses these, schema had filled_by_dispatcher_id and filled_at)
2. vacancies: Added reason, filled_by, filled_at columns
3. Created gad_availability_log, vacancy_fill_options, dispatcher_pay_rates if they don't exist

All CREATE TABLE statements use IF NOT EXISTS for idempotency.
All ALTER TABLE statements use prepared statements for idempotency.
*/
