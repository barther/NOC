-- Add desk default rest days table
-- This stores the standard rest days for each shift at a desk
-- When a dispatcher is assigned to a desk/shift, these become their default rest days

CREATE TABLE IF NOT EXISTS desk_default_rest_days (
    id INT AUTO_INCREMENT PRIMARY KEY,
    desk_id INT NOT NULL,
    shift ENUM('first', 'second', 'third', 'relief') NOT NULL,
    day_of_week TINYINT NOT NULL COMMENT '0=Sunday, 1=Monday, etc.',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (desk_id) REFERENCES desks(id) ON DELETE CASCADE,
    UNIQUE KEY unique_desk_shift_day (desk_id, shift, day_of_week),
    INDEX idx_desk_shift (desk_id, shift)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Default rest days per desk and shift';
