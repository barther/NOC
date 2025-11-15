-- Migration 007: Fix FRA Hours Tracking Schema
-- Add missing columns that the code expects but schema doesn't have

SET @dbname = DATABASE();
SET @tablename = "fra_hours_tracking";

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

/*
Note: This migration adds columns to fra_hours_tracking that the code expects but were missing from the schema.
The original schema had duty_start/duty_end/duty_hours/next_available_time columns, which may still be in use.
This migration preserves those and adds the additional columns needed by FRAHours.php.

If you want to clean up the old columns later, create a separate migration to drop them.
*/
