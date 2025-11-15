-- Migration 005: Fix GAD Rest Day System
-- Change from 7 fixed groups (A-G) to 3 rotating classes matching Extra Board
-- Fri/Sat is invalid as it spans pay period - use 6-week rotating pairs instead

-- Drop the old gad_rest_group column
ALTER TABLE dispatchers
DROP COLUMN IF EXISTS gad_rest_group;

-- Add new columns for rotating rest day system
ALTER TABLE dispatchers
ADD COLUMN gad_rest_class TINYINT(1) NULL COMMENT 'GAD rest day class (1-3, similar to Extra Board)',
ADD COLUMN gad_cycle_start_date DATE NULL COMMENT 'Start date of 6-week rest day cycle';

-- Drop old index
DROP INDEX IF EXISTS idx_dispatchers_gad ON dispatchers;

-- Create new index
CREATE INDEX idx_dispatchers_gad ON dispatchers(classification, gad_rest_class) WHERE classification = 'gad';

-- Add check constraint to ensure valid class values
ALTER TABLE dispatchers
ADD CONSTRAINT chk_gad_rest_class CHECK (gad_rest_class IS NULL OR gad_rest_class IN (1, 2, 3));

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
*/
