-- Migration 007: Fix FRA Hours Tracking Schema
-- Create table if it doesn't exist, then add missing columns

SET @dbname = DATABASE();
SET @tablename = "fra_hours_tracking";

-- Create fra_hours_tracking table if it doesn't exist
CREATE TABLE IF NOT EXISTS fra_hours_tracking (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispatcher_id INT NOT NULL,
    assignment_log_id INT NULL COMMENT 'Link to assignment_log (optional)',
    duty_start DATETIME NULL COMMENT 'Duty start time (legacy)',
    duty_end DATETIME NULL COMMENT 'Duty end time (legacy)',
    duty_hours DECIMAL(4,2) NULL COMMENT 'Total duty hours (legacy)',
    next_available_time DATETIME NULL COMMENT 'When dispatcher is next available (legacy)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dispatcher_id) REFERENCES dispatchers(id) ON DELETE CASCADE,
    INDEX idx_dispatcher (dispatcher_id),
    INDEX idx_next_available (next_available_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='FRA Hours of Service tracking';

-- Add work_date column if it doesn't exist
SET @columnname = "work_date";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE fra_hours_tracking ADD COLUMN work_date DATE NULL COMMENT 'Date of work shift';",
  "SELECT 1;"
));
PREPARE addWorkDate FROM @preparedStatement;
EXECUTE addWorkDate;
DEALLOCATE PREPARE addWorkDate;

-- Add shift column if it doesn't exist
SET @columnname = "shift";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE fra_hours_tracking ADD COLUMN shift ENUM('first', 'second', 'third') NULL COMMENT 'Shift worked';",
  "SELECT 1;"
));
PREPARE addShift FROM @preparedStatement;
EXECUTE addShift;
DEALLOCATE PREPARE addShift;

-- Add actual_start_time column if it doesn't exist
SET @columnname = "actual_start_time";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE fra_hours_tracking ADD COLUMN actual_start_time DATETIME NULL COMMENT 'Actual shift start time';",
  "SELECT 1;"
));
PREPARE addActualStartTime FROM @preparedStatement;
EXECUTE addActualStartTime;
DEALLOCATE PREPARE addActualStartTime;

-- Add actual_end_time column if it doesn't exist
SET @columnname = "actual_end_time";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE fra_hours_tracking ADD COLUMN actual_end_time DATETIME NULL COMMENT 'Actual shift end time';",
  "SELECT 1;"
));
PREPARE addActualEndTime FROM @preparedStatement;
EXECUTE addActualEndTime;
DEALLOCATE PREPARE addActualEndTime;

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
  "ALTER TABLE fra_hours_tracking ADD COLUMN hours_worked DECIMAL(4,2) NULL COMMENT 'Total hours worked';",
  "SELECT 1;"
));
PREPARE addHoursWorked FROM @preparedStatement;
EXECUTE addHoursWorked;
DEALLOCATE PREPARE addHoursWorked;

-- Add index for work_date if it doesn't exist
SET @indexname = "idx_work_date";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (index_name = @indexname)
  ) = 0,
  "CREATE INDEX idx_work_date ON fra_hours_tracking(work_date);",
  "SELECT 1;"
));
PREPARE createWorkDateIndex FROM @preparedStatement;
EXECUTE createWorkDateIndex;
DEALLOCATE PREPARE createWorkDateIndex;

-- Add unique constraint on dispatcher_id, work_date, shift for ON DUPLICATE KEY UPDATE
SET @indexname = "unique_dispatcher_work_shift";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (index_name = @indexname)
  ) = 0,
  "CREATE UNIQUE INDEX unique_dispatcher_work_shift ON fra_hours_tracking(dispatcher_id, work_date, shift);",
  "SELECT 1;"
));
PREPARE createUniqueIndex FROM @preparedStatement;
EXECUTE createUniqueIndex;
DEALLOCATE PREPARE createUniqueIndex;

/*
Note: This migration adds columns to fra_hours_tracking that the code expects but were missing from the schema.
The original schema had duty_start/duty_end/duty_hours/next_available_time columns, which may still be in use.
This migration preserves those and adds the additional columns needed by FRAHours.php.

The UNIQUE constraint on (dispatcher_id, work_date, shift) allows the ON DUPLICATE KEY UPDATE in FRAHours.php to work correctly.

If you want to clean up the old columns later, create a separate migration to drop them.
*/
