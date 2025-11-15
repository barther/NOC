-- Migration 002: Add GAD, ACD, and Contract-Compliant Features
-- Version 1.4.0 - Contract Implementation

-- ============================================================
-- 1. GAD (Guaranteed Assigned Dispatcher) Features
-- ============================================================

-- Add columns to dispatchers table conditionally
SET @dbname = DATABASE();
SET @tablename = "dispatchers";

-- Add gad_rest_group column if it doesn't exist (will be removed in migration 005)
SET @columnname = "gad_rest_group";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE dispatchers ADD COLUMN gad_rest_group ENUM('A', 'B', 'C', 'D', 'E', 'F', 'G') NULL COMMENT 'GAD rotating rest day group (Article 3(f))';",
  "SELECT 1;"
));
PREPARE addGadRestGroup FROM @preparedStatement;
EXECUTE addGadRestGroup;
DEALLOCATE PREPARE addGadRestGroup;

-- Add training_status column if it doesn't exist
SET @columnname = "training_status";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE dispatchers ADD COLUMN training_status ENUM('none', 'in_training', 'training_complete') DEFAULT 'none' COMMENT 'Training status for order-of-call protection';",
  "SELECT 1;"
));
PREPARE addTrainingStatus FROM @preparedStatement;
EXECUTE addTrainingStatus;
DEALLOCATE PREPARE addTrainingStatus;

-- Add training_protected column if it doesn't exist
SET @columnname = "training_protected";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE dispatchers ADD COLUMN training_protected TINYINT(1) DEFAULT 0 COMMENT 'If 1, skip in order-of-call unless desperate';",
  "SELECT 1;"
));
PREPARE addTrainingProtected FROM @preparedStatement;
EXECUTE addTrainingProtected;
DEALLOCATE PREPARE addTrainingProtected;

-- Add training_start_date column if it doesn't exist
SET @columnname = "training_start_date";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE dispatchers ADD COLUMN training_start_date DATE NULL COMMENT 'When current training started';",
  "SELECT 1;"
));
PREPARE addTrainingStartDate FROM @preparedStatement;
EXECUTE addTrainingStartDate;
DEALLOCATE PREPARE addTrainingStartDate;

-- Add training_end_date column if it doesn't exist
SET @columnname = "training_end_date";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE dispatchers ADD COLUMN training_end_date DATE NULL COMMENT 'When current training ends';",
  "SELECT 1;"
));
PREPARE addTrainingEndDate FROM @preparedStatement;
EXECUTE addTrainingEndDate;
DEALLOCATE PREPARE addTrainingEndDate;

-- Add consecutive_days_worked column if it doesn't exist
SET @columnname = "consecutive_days_worked";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE dispatchers ADD COLUMN consecutive_days_worked INT DEFAULT 0 COMMENT 'Forcing tracker - consecutive days worked';",
  "SELECT 1;"
));
PREPARE addConsecutiveDays FROM @preparedStatement;
EXECUTE addConsecutiveDays;
DEALLOCATE PREPARE addConsecutiveDays;

-- Add last_work_date column if it doesn't exist
SET @columnname = "last_work_date";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE dispatchers ADD COLUMN last_work_date DATE NULL COMMENT 'Last date worked for consecutive tracking';",
  "SELECT 1;"
));
PREPARE addLastWorkDate FROM @preparedStatement;
EXECUTE addLastWorkDate;
DEALLOCATE PREPARE addLastWorkDate;

-- GAD baseline tracking per division
CREATE TABLE IF NOT EXISTS gad_baseline (
    id INT AUTO_INCREMENT PRIMARY KEY,
    division_id INT NOT NULL,
    total_desks INT NOT NULL COMMENT 'Total regular desks (8-hour)',
    acd_desks INT DEFAULT 0 COMMENT 'Total ACD desks (12-hour)',
    baseline_gad_count DECIMAL(4,1) NOT NULL COMMENT 'Baseline GAD ratio (1.0 per desk including ACDs)',
    current_gad_count INT NOT NULL COMMENT 'Current number of GAD dispatchers',
    above_baseline TINYINT(1) GENERATED ALWAYS AS (current_gad_count > baseline_gad_count) STORED,
    effective_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE CASCADE,
    INDEX idx_division_date (division_id, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='GAD baseline tracking per Appendix 9';

-- ============================================================
-- 2. ACD (Assistant Chief Train Dispatcher) Features
-- ============================================================

-- ACD rotation tracking (GOLD/BLUE crews, 4-on/4-off)
CREATE TABLE IF NOT EXISTS acd_rotation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispatcher_id INT NOT NULL,
    crew_color ENUM('GOLD', 'BLUE') NOT NULL COMMENT 'ACD crew assignment',
    shift_type ENUM('day', 'night') NOT NULL COMMENT 'Day (0600-1800) or Night (1800-0600)',
    rotation_start_date DATE NOT NULL COMMENT 'Start of current 4-day rotation',
    rotation_end_date DATE NOT NULL COMMENT 'End of current 4-day rotation',
    on_rotation TINYINT(1) DEFAULT 1 COMMENT 'If 1, currently on 4-day work block',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (dispatcher_id) REFERENCES dispatchers(id) ON DELETE CASCADE,
    INDEX idx_dispatcher (dispatcher_id),
    INDEX idx_rotation_dates (rotation_start_date, rotation_end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ACD 12-hour shift rotation (4-on/4-off)';

-- Update desks table to support ACD 12-hour shifts
SET @tablename = "desks";

-- Add shift_hours column if it doesn't exist
SET @columnname = "shift_hours";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE desks ADD COLUMN shift_hours INT DEFAULT 8 COMMENT 'Shift duration: 8 or 12 hours';",
  "SELECT 1;"
));
PREPARE addShiftHours FROM @preparedStatement;
EXECUTE addShiftHours;
DEALLOCATE PREPARE addShiftHours;

-- Add is_acd_desk column if it doesn't exist
SET @columnname = "is_acd_desk";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE desks ADD COLUMN is_acd_desk TINYINT(1) DEFAULT 0 COMMENT 'If 1, this is an ACD desk (12-hour shifts)';",
  "SELECT 1;"
));
PREPARE addIsAcdDesk FROM @preparedStatement;
EXECUTE addIsAcdDesk;
DEALLOCATE PREPARE addIsAcdDesk;

-- ============================================================
-- 3. Vacancy Fill Cost Tracking (Article 3(g) "least cost")
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
    FOREIGN KEY (dispatcher_id) REFERENCES dispatchers(id) ON DELETE SET NULL,
    FOREIGN KEY (backfill_vacancy_id) REFERENCES vacancies(id) ON DELETE SET NULL,
    INDEX idx_vacancy (vacancy_id),
    INDEX idx_rank (vacancy_id, option_rank)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Track all vacancy fill options and costs';

-- Update vacancy_fills to track actual cost and penalties
SET @tablename = "vacancy_fills";

-- Add pay_type column if it doesn't exist
SET @columnname = "pay_type";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE vacancy_fills ADD COLUMN pay_type ENUM('straight', 'overtime') DEFAULT 'straight';",
  "SELECT 1;"
));
PREPARE addPayType FROM @preparedStatement;
EXECUTE addPayType;
DEALLOCATE PREPARE addPayType;

-- Add hours_worked column if it doesn't exist
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

-- Add calculated_cost column if it doesn't exist
SET @columnname = "calculated_cost";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE vacancy_fills ADD COLUMN calculated_cost DECIMAL(10,2) NULL COMMENT 'Actual cost of fill';",
  "SELECT 1;"
));
PREPARE addCalculatedCost FROM @preparedStatement;
EXECUTE addCalculatedCost;
DEALLOCATE PREPARE addCalculatedCost;

-- Add improper_diversion column if it doesn't exist
SET @columnname = "improper_diversion";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE vacancy_fills ADD COLUMN improper_diversion TINYINT(1) DEFAULT 0 COMMENT 'If 1, violated order-of-call';",
  "SELECT 1;"
));
PREPARE addImproperDiversion FROM @preparedStatement;
EXECUTE addImproperDiversion;
DEALLOCATE PREPARE addImproperDiversion;

-- Add penalty_hours column if it doesn't exist
SET @columnname = "penalty_hours";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE vacancy_fills ADD COLUMN penalty_hours DECIMAL(4,2) DEFAULT 0.00 COMMENT 'Penalty hours (4.0 for improper diversion)';",
  "SELECT 1;"
));
PREPARE addPenaltyHours FROM @preparedStatement;
EXECUTE addPenaltyHours;
DEALLOCATE PREPARE addPenaltyHours;

-- Add penalty_cost column if it doesn't exist
SET @columnname = "penalty_cost";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE vacancy_fills ADD COLUMN penalty_cost DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Dollar value of penalty';",
  "SELECT 1;"
));
PREPARE addPenaltyCost FROM @preparedStatement;
EXECUTE addPenaltyCost;
DEALLOCATE PREPARE addPenaltyCost;

-- ============================================================
-- 4. Enhanced Assignment Log for Cost Tracking
-- ============================================================

SET @tablename = "assignment_log";

-- Add hourly_rate column if it doesn't exist
SET @columnname = "hourly_rate";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE assignment_log ADD COLUMN hourly_rate DECIMAL(8,2) NULL COMMENT 'Rate at time of assignment';",
  "SELECT 1;"
));
PREPARE addHourlyRate FROM @preparedStatement;
EXECUTE addHourlyRate;
DEALLOCATE PREPARE addHourlyRate;

-- Add calculated_cost column if it doesn't exist
SET @columnname = "calculated_cost";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE assignment_log ADD COLUMN calculated_cost DECIMAL(10,2) NULL COMMENT 'Total cost for this assignment';",
  "SELECT 1;"
));
PREPARE addCalculatedCostAssignment FROM @preparedStatement;
EXECUTE addCalculatedCostAssignment;
DEALLOCATE PREPARE addCalculatedCostAssignment;

-- Add gad_baseline_status column if it doesn't exist
SET @columnname = "gad_baseline_status";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE assignment_log ADD COLUMN gad_baseline_status ENUM('above', 'at', 'below') NULL COMMENT 'GAD baseline status at time of fill';",
  "SELECT 1;"
));
PREPARE addGadBaselineStatus FROM @preparedStatement;
EXECUTE addGadBaselineStatus;
DEALLOCATE PREPARE addGadBaselineStatus;

-- Add forced column if it doesn't exist
SET @columnname = "forced";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE assignment_log ADD COLUMN forced TINYINT(1) DEFAULT 0 COMMENT 'If 1, dispatcher was forced (captured before leaving)';",
  "SELECT 1;"
));
PREPARE addForced FROM @preparedStatement;
EXECUTE addForced;
DEALLOCATE PREPARE addForced;

-- Add consecutive_day_count column if it doesn't exist
SET @columnname = "consecutive_day_count";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE assignment_log ADD COLUMN consecutive_day_count INT DEFAULT 0 COMMENT 'How many consecutive days at time of assignment';",
  "SELECT 1;"
));
PREPARE addConsecutiveDayCount FROM @preparedStatement;
EXECUTE addConsecutiveDayCount;
DEALLOCATE PREPARE addConsecutiveDayCount;

-- ============================================================
-- 5. Dispatcher Pay Rates (for cost calculation)
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

-- ============================================================
-- 6. GAD Availability Tracking
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
-- 7. Update Existing Tables for Contract Compliance
-- ============================================================

-- Add more detailed vacancy tracking
SET @tablename = "vacancies";

-- Add filled_by_option_rank column if it doesn't exist
SET @columnname = "filled_by_option_rank";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE vacancies ADD COLUMN filled_by_option_rank INT NULL COMMENT 'Which order-of-call step was used (1-7)';",
  "SELECT 1;"
));
PREPARE addFilledByOptionRank FROM @preparedStatement;
EXECUTE addFilledByOptionRank;
DEALLOCATE PREPARE addFilledByOptionRank;

-- Add filled_by_option_type column if it doesn't exist
SET @columnname = "filled_by_option_type";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE vacancies ADD COLUMN filled_by_option_type VARCHAR(50) NULL COMMENT 'Type of fill (gad, incumbent_ot, etc)';",
  "SELECT 1;"
));
PREPARE addFilledByOptionType FROM @preparedStatement;
EXECUTE addFilledByOptionType;
DEALLOCATE PREPARE addFilledByOptionType;

-- Add total_cost column if it doesn't exist
SET @columnname = "total_cost";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE vacancies ADD COLUMN total_cost DECIMAL(10,2) NULL COMMENT 'Total cost including penalties';",
  "SELECT 1;"
));
PREPARE addTotalCost FROM @preparedStatement;
EXECUTE addTotalCost;
DEALLOCATE PREPARE addTotalCost;

-- ============================================================
-- 8. Indexes for Performance
-- ============================================================

-- Note: idx_dispatchers_gad index removed - gad_rest_group column removed in migration 005

-- Create idx_dispatchers_training if it doesn't exist
SET @indexname = 'idx_dispatchers_training';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (table_name = 'dispatchers')
      AND (table_schema = @dbname)
      AND (index_name = @indexname)
  ) = 0,
  "CREATE INDEX idx_dispatchers_training ON dispatchers(training_protected, training_status);",
  "SELECT 1;"
));
PREPARE createTrainingIndex FROM @preparedStatement;
EXECUTE createTrainingIndex;
DEALLOCATE PREPARE createTrainingIndex;

-- Create idx_dispatchers_consecutive if it doesn't exist
SET @indexname = 'idx_dispatchers_consecutive';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (table_name = 'dispatchers')
      AND (table_schema = @dbname)
      AND (index_name = @indexname)
  ) = 0,
  "CREATE INDEX idx_dispatchers_consecutive ON dispatchers(consecutive_days_worked, last_work_date);",
  "SELECT 1;"
));
PREPARE createConsecutiveIndex FROM @preparedStatement;
EXECUTE createConsecutiveIndex;
DEALLOCATE PREPARE createConsecutiveIndex;

-- ============================================================
-- Migration Complete
-- ============================================================
