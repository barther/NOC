-- Migration: Create ATW (Around-the-World) Tables
-- Description: Add support for ATW job assignments with rotating desk schedules

-- Create ATW jobs table
CREATE TABLE IF NOT EXISTS atw_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_atw_name (name, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create ATW schedules table (defines the rotation)
CREATE TABLE IF NOT EXISTS atw_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    atw_job_id INT NOT NULL,
    day_of_week TINYINT NOT NULL COMMENT '0=Sunday, 1=Monday, ..., 6=Saturday',
    desk_id INT NOT NULL,
    shift VARCHAR(20) DEFAULT 'third' COMMENT 'Always third for ATW',
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (atw_job_id) REFERENCES atw_jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (desk_id) REFERENCES desks(id) ON DELETE CASCADE,
    UNIQUE KEY unique_atw_day (atw_job_id, day_of_week, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add atw_job_id to job_assignments table
ALTER TABLE job_assignments
ADD COLUMN atw_job_id INT NULL AFTER desk_id,
ADD FOREIGN KEY (atw_job_id) REFERENCES atw_jobs(id) ON DELETE SET NULL;

-- Create index for faster lookups
CREATE INDEX idx_atw_schedules_lookup ON atw_schedules(atw_job_id, day_of_week, active);
CREATE INDEX idx_job_assignments_atw ON job_assignments(atw_job_id, assignment_type);
