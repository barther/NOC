-- Migration: Extra Board System
-- Description: Support rotating rest days for extra board dispatchers

-- Extra board assignments
CREATE TABLE extra_board_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispatcher_id INT NOT NULL,
    board_class TINYINT NOT NULL COMMENT '1, 2, or 3 - ensures staggered rest days',
    cycle_start_date DATE NOT NULL COMMENT 'Reference date for calculating rotation',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (dispatcher_id) REFERENCES dispatchers(id) ON DELETE CASCADE,
    INDEX idx_dispatcher (dispatcher_id, end_date),
    INDEX idx_active (end_date, board_class)
);

-- Comment on rotation logic:
-- Rest day pairs rotate every week in this order:
-- Week 1: Sat/Sun
-- Week 2: Sun/Mon
-- Week 3: Mon/Tue
-- Week 4: Tue/Wed
-- Week 5: Wed/Thu
-- Week 6: Thu/Fri
-- Week 7: Sat/Sun (cycle repeats, creating 4-day weekend)
--
-- Classes are offset:
-- Class 1: Starts at Sat/Sun
-- Class 2: Starts at Tue/Wed (2 pairs ahead)
-- Class 3: Starts at Thu/Fri (4 pairs ahead)
