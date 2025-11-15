-- Migration 005: Fix GAD Rest Day System
-- Change from 7 fixed groups (A-G) to 3 rotating classes matching Extra Board
-- Fri/Sat is invalid as it spans pay period - use 6-week rotating pairs instead

-- Drop old index first (if it exists) - using prepared statement for MySQL < 8.0.1 compatibility
SET @dbname = DATABASE();
SET @tablename = "dispatchers";
SET @indexname = "idx_dispatchers_gad";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (index_name = @indexname)
  ) > 0,
  "DROP INDEX idx_dispatchers_gad ON dispatchers;",
  "SELECT 1;"
));
PREPARE dropIndexIfExists FROM @preparedStatement;
EXECUTE dropIndexIfExists;
DEALLOCATE PREPARE dropIndexIfExists;

-- Check if gad_rest_group column exists and drop it
SET @columnname = "gad_rest_group";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "ALTER TABLE dispatchers DROP COLUMN gad_rest_group;",
  "SELECT 1;"
));
PREPARE alterIfExists FROM @preparedStatement;
EXECUTE alterIfExists;
DEALLOCATE PREPARE alterIfExists;

-- Add new columns for rotating rest day system (check if they don't already exist)
SET @columnname = "gad_rest_class";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE dispatchers ADD COLUMN gad_rest_class TINYINT(1) NULL COMMENT 'GAD rest day class (1-3, similar to Extra Board)';",
  "SELECT 1;"
));
PREPARE addColumn1 FROM @preparedStatement;
EXECUTE addColumn1;
DEALLOCATE PREPARE addColumn1;

SET @columnname = "gad_cycle_start_date";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) = 0,
  "ALTER TABLE dispatchers ADD COLUMN gad_cycle_start_date DATE NULL COMMENT 'Start date of 6-week rest day cycle';",
  "SELECT 1;"
));
PREPARE addColumn2 FROM @preparedStatement;
EXECUTE addColumn2;
DEALLOCATE PREPARE addColumn2;

-- Create new index (if it doesn't exist)
SET @indexname = "idx_dispatchers_gad_class";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (index_name = @indexname)
  ) = 0,
  "CREATE INDEX idx_dispatchers_gad_class ON dispatchers(classification, gad_rest_class);",
  "SELECT 1;"
));
PREPARE createIndexIfNotExists FROM @preparedStatement;
EXECUTE createIndexIfNotExists;
DEALLOCATE PREPARE createIndexIfNotExists;

-- Comments explaining the new system
/*
GAD Rest Day System (6-week rotation):
- 3 classes with staggered rest day pairs
- Valid pairs: Sat/Sun, Sun/Mon, Mon/Tue, Tue/Wed, Wed/Thu, Thu/Fri
- NO Fri/Sat pair (spans pay period)
- Rotation creates natural 4-day rest when Thu/Fri resets to Sat/Sun

Class Offsets:
- Class 1: Starts at Sat/Sun
- Class 2: Starts at Tue/Wed
- Class 3: Starts at Thu/Fri

This ensures no two classes have overlapping rest days.

Note: This migration uses prepared statements for MySQL < 8.0.1 compatibility.
CHECK constraints would be enforced in MySQL 8.0.16+ but are omitted here.
The application code enforces the constraint that gad_rest_class must be 1, 2, or 3.
*/
