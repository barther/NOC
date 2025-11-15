-- Migration 005: Fix GAD Rest Day System
-- Change from 7 fixed groups (A-G) to 3 rotating classes matching Extra Board
-- Fri/Sat is invalid as it spans pay period - use 6-week rotating pairs instead

-- Drop old index first (if it exists)
DROP INDEX IF EXISTS idx_dispatchers_gad ON dispatchers;

-- Check if gad_rest_group column exists and drop it
SET @dbname = DATABASE();
SET @tablename = "dispatchers";
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

-- Add new columns for rotating rest day system
ALTER TABLE dispatchers
ADD COLUMN IF NOT EXISTS gad_rest_class TINYINT(1) NULL COMMENT 'GAD rest day class (1-3, similar to Extra Board)',
ADD COLUMN IF NOT EXISTS gad_cycle_start_date DATE NULL COMMENT 'Start date of 6-week rest day cycle';

-- Create new index (conditional indexes with WHERE not supported in MySQL, using regular index)
CREATE INDEX idx_dispatchers_gad_class ON dispatchers(classification, gad_rest_class);

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

Note: MySQL CHECK constraints are enforced in MySQL 8.0.16+
For older versions, the application code enforces the constraint.
*/
