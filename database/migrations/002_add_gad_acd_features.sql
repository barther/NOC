-- Migration 002: Add GAD, ACD, and Contract-Compliant Features
-- Version 1.4.0 - Contract Implementation

-- ============================================================
-- 1. GAD (Guaranteed Assigned Dispatcher) Features
-- ============================================================

-- Add GAD rest day group to dispatchers (A-G groups per Article 3(f))
ALTER TABLE dispatchers
ADD COLUMN gad_rest_group ENUM('A', 'B', 'C', 'D', 'E', 'F', 'G') NULL COMMENT 'GAD rotating rest day group (Article 3(f))',
ADD COLUMN training_status ENUM('none', 'in_training', 'training_complete') DEFAULT 'none' COMMENT 'Training status for order-of-call protection',
ADD COLUMN training_protected TINYINT(1) DEFAULT 0 COMMENT 'If 1, skip in order-of-call unless desperate',
ADD COLUMN training_start_date DATE NULL COMMENT 'When current training started',
ADD COLUMN training_end_date DATE NULL COMMENT 'When current training ends',
ADD COLUMN consecutive_days_worked INT DEFAULT 0 COMMENT 'Forcing tracker - consecutive days worked',
ADD COLUMN last_work_date DATE NULL COMMENT 'Last date worked for consecutive tracking';

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
ALTER TABLE desks
ADD COLUMN shift_hours INT DEFAULT 8 COMMENT 'Shift duration: 8 or 12 hours',
ADD COLUMN is_acd_desk TINYINT(1) DEFAULT 0 COMMENT 'If 1, this is an ACD desk (12-hour shifts)';

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
ALTER TABLE vacancy_fills
ADD COLUMN pay_type ENUM('straight', 'overtime') DEFAULT 'straight',
ADD COLUMN hours_worked DECIMAL(4,2) DEFAULT 8.00,
ADD COLUMN calculated_cost DECIMAL(10,2) NULL COMMENT 'Actual cost of fill',
ADD COLUMN improper_diversion TINYINT(1) DEFAULT 0 COMMENT 'If 1, violated order-of-call',
ADD COLUMN penalty_hours DECIMAL(4,2) DEFAULT 0.00 COMMENT 'Penalty hours (4.0 for improper diversion)',
ADD COLUMN penalty_cost DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Dollar value of penalty';

-- ============================================================
-- 4. Enhanced Assignment Log for Cost Tracking
-- ============================================================

ALTER TABLE assignment_log
ADD COLUMN hourly_rate DECIMAL(8,2) NULL COMMENT 'Rate at time of assignment',
ADD COLUMN calculated_cost DECIMAL(10,2) NULL COMMENT 'Total cost for this assignment',
ADD COLUMN gad_baseline_status ENUM('above', 'at', 'below') NULL COMMENT 'GAD baseline status at time of fill',
ADD COLUMN forced TINYINT(1) DEFAULT 0 COMMENT 'If 1, dispatcher was forced (captured before leaving)',
ADD COLUMN consecutive_day_count INT DEFAULT 0 COMMENT 'How many consecutive days at time of assignment';

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
ALTER TABLE vacancies
ADD COLUMN filled_by_option_rank INT NULL COMMENT 'Which order-of-call step was used (1-7)',
ADD COLUMN filled_by_option_type VARCHAR(50) NULL COMMENT 'Type of fill (gad, incumbent_ot, etc)',
ADD COLUMN total_cost DECIMAL(10,2) NULL COMMENT 'Total cost including penalties';

-- ============================================================
-- 8. Indexes for Performance
-- ============================================================

-- Optimize GAD queries
CREATE INDEX idx_dispatchers_gad ON dispatchers(classification, gad_rest_group) WHERE classification = 'gad';
CREATE INDEX idx_dispatchers_training ON dispatchers(training_protected, training_status);
CREATE INDEX idx_dispatchers_consecutive ON dispatchers(consecutive_days_worked, last_work_date);

-- ============================================================
-- Migration Complete
-- ============================================================
