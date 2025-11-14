-- Add table for custom rest days per job assignment
CREATE TABLE IF NOT EXISTS job_rest_days (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_assignment_id INT NOT NULL,
    day_of_week TINYINT NOT NULL COMMENT '0=Sunday, 6=Saturday',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_assignment_id) REFERENCES job_assignments(id) ON DELETE CASCADE,
    UNIQUE KEY unique_assignment_day (job_assignment_id, day_of_week),
    INDEX idx_assignment (job_assignment_id)
);
