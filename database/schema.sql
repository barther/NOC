-- Network Operations Center Scheduling System
-- Database Schema

-- Drop tables in reverse dependency order
DROP TABLE IF EXISTS assignment_log;
DROP TABLE IF EXISTS vacancy_fills;
DROP TABLE IF EXISTS vacancies;
DROP TABLE IF EXISTS holddowns;
DROP TABLE IF EXISTS atw_rotation;
DROP TABLE IF EXISTS relief_schedules;
DROP TABLE IF EXISTS job_assignments;
DROP TABLE IF EXISTS dispatchers;
DROP TABLE IF EXISTS desks;
DROP TABLE IF EXISTS divisions;
DROP TABLE IF EXISTS system_config;

-- System Configuration
CREATE TABLE system_config (
    config_key VARCHAR(50) PRIMARY KEY,
    config_value TEXT NOT NULL,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default configuration
INSERT INTO system_config (config_key, config_value, description) VALUES
('eb_baseline_count', '0', 'Baseline Extra Board strength for overtime calculation'),
('timezone', 'America/New_York', 'System timezone (Eastern)'),
('first_shift_start', '06:00', 'First shift start time'),
('second_shift_start', '14:00', 'Second shift start time'),
('third_shift_start', '22:00', 'Third shift start time'),
('shift_duration_hours', '8', 'Standard shift duration in hours'),
('fra_max_duty_hours', '9', 'FRA maximum duty hours'),
('fra_min_rest_hours', '15', 'FRA minimum rest hours');

-- Divisions (e.g., geographic or operational divisions)
CREATE TABLE divisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) NOT NULL UNIQUE,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (active)
);

-- Desks (dispatching positions)
CREATE TABLE desks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    division_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) NOT NULL UNIQUE,
    description TEXT,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE RESTRICT,
    INDEX idx_division (division_id),
    INDEX idx_active (active)
);

-- Dispatchers
CREATE TABLE dispatchers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_number VARCHAR(20) NOT NULL UNIQUE,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    seniority_date DATE NOT NULL,
    seniority_rank INT NOT NULL,
    classification ENUM('job_holder', 'extra_board', 'qualifying') NOT NULL,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_seniority (seniority_rank),
    INDEX idx_classification (classification),
    INDEX idx_active (active),
    UNIQUE KEY unique_seniority (seniority_rank, active)
);

-- Dispatcher Qualifications (many-to-many with desks)
CREATE TABLE dispatcher_qualifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispatcher_id INT NOT NULL,
    desk_id INT NOT NULL,
    qualified BOOLEAN DEFAULT FALSE,
    qualifying_started DATE,
    qualified_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (dispatcher_id) REFERENCES dispatchers(id) ON DELETE CASCADE,
    FOREIGN KEY (desk_id) REFERENCES desks(id) ON DELETE CASCADE,
    UNIQUE KEY unique_dispatcher_desk (dispatcher_id, desk_id),
    INDEX idx_qualified (qualified),
    INDEX idx_dispatcher (dispatcher_id),
    INDEX idx_desk (desk_id)
);

-- Job Assignments (held indefinitely until displaced or bid out)
CREATE TABLE job_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispatcher_id INT NOT NULL,
    desk_id INT NOT NULL,
    shift ENUM('first', 'second', 'third', 'relief', 'atw') NOT NULL,
    assignment_type ENUM('regular', 'relief', 'atw') NOT NULL DEFAULT 'regular',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (dispatcher_id) REFERENCES dispatchers(id) ON DELETE RESTRICT,
    FOREIGN KEY (desk_id) REFERENCES desks(id) ON DELETE RESTRICT,
    INDEX idx_dispatcher (dispatcher_id),
    INDEX idx_desk_shift (desk_id, shift),
    INDEX idx_dates (start_date, end_date),
    INDEX idx_active (end_date)
);

-- Relief Schedules (defines which days relief dispatcher covers)
CREATE TABLE relief_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    desk_id INT NOT NULL,
    relief_dispatcher_id INT NOT NULL,
    day_of_week TINYINT NOT NULL COMMENT '0=Sunday, 6=Saturday',
    shift ENUM('first', 'second', 'third') NOT NULL,
    active BOOLEAN DEFAULT TRUE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (desk_id) REFERENCES desks(id) ON DELETE CASCADE,
    FOREIGN KEY (relief_dispatcher_id) REFERENCES dispatchers(id) ON DELETE RESTRICT,
    UNIQUE KEY unique_desk_day_shift (desk_id, day_of_week, shift),
    INDEX idx_relief_dispatcher (relief_dispatcher_id),
    INDEX idx_active (active)
);

-- Around-the-World Rotation (defines the ATW schedule pattern)
CREATE TABLE atw_rotation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    desk_id INT NOT NULL,
    day_of_week TINYINT NOT NULL COMMENT '0=Sunday, 6=Saturday',
    rotation_order INT NOT NULL COMMENT 'Order in weekly rotation (1-5 for Mon-Fri)',
    atw_dispatcher_id INT NULL COMMENT 'Current ATW dispatcher (can be reassigned)',
    active BOOLEAN DEFAULT TRUE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (desk_id) REFERENCES desks(id) ON DELETE CASCADE,
    FOREIGN KEY (atw_dispatcher_id) REFERENCES dispatchers(id) ON DELETE SET NULL,
    UNIQUE KEY unique_desk_day (desk_id, day_of_week),
    INDEX idx_rotation (rotation_order),
    INDEX idx_atw_dispatcher (atw_dispatcher_id),
    INDEX idx_active (active)
);

-- Vacancies (planned and unplanned absences)
CREATE TABLE vacancies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    desk_id INT NOT NULL,
    shift ENUM('first', 'second', 'third') NOT NULL,
    vacancy_date DATE NOT NULL,
    vacancy_type ENUM('vacation', 'training', 'loa', 'sick', 'other') NOT NULL,
    incumbent_dispatcher_id INT NULL,
    status ENUM('pending', 'filled', 'unfilled', 'cancelled') DEFAULT 'pending',
    is_planned BOOLEAN DEFAULT FALSE COMMENT 'TRUE for vacations/training, FALSE for sick/emergency',
    posted_as_holddown BOOLEAN DEFAULT FALSE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (desk_id) REFERENCES desks(id) ON DELETE CASCADE,
    FOREIGN KEY (incumbent_dispatcher_id) REFERENCES dispatchers(id) ON DELETE SET NULL,
    INDEX idx_date (vacancy_date),
    INDEX idx_status (status),
    INDEX idx_type (vacancy_type),
    INDEX idx_desk_date (desk_id, vacancy_date)
);

-- Hold-downs (bidding for planned absences)
CREATE TABLE holddowns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    desk_id INT NOT NULL,
    shift ENUM('first', 'second', 'third') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    incumbent_dispatcher_id INT NOT NULL COMMENT 'Dispatcher being relieved',
    awarded_dispatcher_id INT NULL COMMENT 'Winning bidder',
    status ENUM('posted', 'awarded', 'active', 'completed', 'cancelled') DEFAULT 'posted',
    posted_date DATETIME NOT NULL,
    award_date DATETIME NULL,
    needs_holdoff_day BOOLEAN DEFAULT FALSE COMMENT 'TRUE if FRA requires a gap day',
    holdoff_date DATE NULL COMMENT 'The inserted hold-off day',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (desk_id) REFERENCES desks(id) ON DELETE CASCADE,
    FOREIGN KEY (incumbent_dispatcher_id) REFERENCES dispatchers(id) ON DELETE RESTRICT,
    FOREIGN KEY (awarded_dispatcher_id) REFERENCES dispatchers(id) ON DELETE SET NULL,
    INDEX idx_dates (start_date, end_date),
    INDEX idx_status (status),
    INDEX idx_awarded (awarded_dispatcher_id)
);

-- Hold-down Bids
CREATE TABLE holddown_bids (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holddown_id INT NOT NULL,
    dispatcher_id INT NOT NULL,
    bid_timestamp DATETIME NOT NULL,
    is_qualified BOOLEAN DEFAULT FALSE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (holddown_id) REFERENCES holddowns(id) ON DELETE CASCADE,
    FOREIGN KEY (dispatcher_id) REFERENCES dispatchers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_holddown_dispatcher (holddown_id, dispatcher_id),
    INDEX idx_timestamp (bid_timestamp)
);

-- Vacancy Fills (how vacancies were filled, with decision trail)
CREATE TABLE vacancy_fills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vacancy_id INT NOT NULL,
    filled_by_dispatcher_id INT NOT NULL,
    fill_method ENUM(
        'eb_qualified',
        'eb_qualifier',
        'incumbent_overtime',
        'senior_restday_overtime',
        'junior_diversion_same_shift_with_eb',
        'junior_diversion_same_shift_no_eb',
        'senior_diversion_off_shift_overtime',
        'fallback_least_cost'
    ) NOT NULL,
    pay_type ENUM('straight', 'overtime') NOT NULL,
    created_cascade_vacancy BOOLEAN DEFAULT FALSE COMMENT 'TRUE if diversion created new vacancy',
    cascade_vacancy_id INT NULL COMMENT 'Link to cascading vacancy',
    decision_log TEXT COMMENT 'JSON log of order-of-call decisions',
    penalty_owed BOOLEAN DEFAULT FALSE COMMENT 'TRUE if improper diversion (4hr penalty)',
    filled_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vacancy_id) REFERENCES vacancies(id) ON DELETE CASCADE,
    FOREIGN KEY (filled_by_dispatcher_id) REFERENCES dispatchers(id) ON DELETE RESTRICT,
    FOREIGN KEY (cascade_vacancy_id) REFERENCES vacancies(id) ON DELETE SET NULL,
    INDEX idx_vacancy (vacancy_id),
    INDEX idx_dispatcher (filled_by_dispatcher_id),
    INDEX idx_method (fill_method)
);

-- Assignment Log (complete audit trail of who worked what/when)
CREATE TABLE assignment_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispatcher_id INT NOT NULL,
    desk_id INT NOT NULL,
    shift ENUM('first', 'second', 'third') NOT NULL,
    work_date DATE NOT NULL,
    actual_start_time DATETIME NOT NULL,
    actual_end_time DATETIME NULL,
    assignment_source ENUM('regular', 'relief', 'atw', 'holddown', 'vacancy_fill') NOT NULL,
    job_assignment_id INT NULL COMMENT 'Link to job_assignments if regular/relief/atw',
    holddown_id INT NULL COMMENT 'Link to holddowns if covering hold-down',
    vacancy_fill_id INT NULL COMMENT 'Link to vacancy_fills if filling vacancy',
    pay_type ENUM('straight', 'overtime') NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dispatcher_id) REFERENCES dispatchers(id) ON DELETE RESTRICT,
    FOREIGN KEY (desk_id) REFERENCES desks(id) ON DELETE RESTRICT,
    FOREIGN KEY (job_assignment_id) REFERENCES job_assignments(id) ON DELETE SET NULL,
    FOREIGN KEY (holddown_id) REFERENCES holddowns(id) ON DELETE SET NULL,
    FOREIGN KEY (vacancy_fill_id) REFERENCES vacancy_fills(id) ON DELETE SET NULL,
    INDEX idx_dispatcher_date (dispatcher_id, work_date),
    INDEX idx_desk_date (desk_id, work_date),
    INDEX idx_work_date (work_date),
    INDEX idx_times (actual_start_time, actual_end_time)
);

-- FRA Hours of Service Tracking
CREATE TABLE fra_hours_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispatcher_id INT NOT NULL,
    assignment_log_id INT NOT NULL,
    duty_start DATETIME NOT NULL,
    duty_end DATETIME NOT NULL,
    duty_hours DECIMAL(4,2) NOT NULL,
    next_available_time DATETIME NOT NULL COMMENT 'duty_end + 15 hours',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dispatcher_id) REFERENCES dispatchers(id) ON DELETE CASCADE,
    FOREIGN KEY (assignment_log_id) REFERENCES assignment_log(id) ON DELETE CASCADE,
    INDEX idx_dispatcher (dispatcher_id),
    INDEX idx_next_available (next_available_time)
);
