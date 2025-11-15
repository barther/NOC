-- Migration: Dispatcher-Based Absences with Date Ranges
-- Description: Support single-day, multi-day, and open-ended dispatcher absences

-- Add date range fields to vacancies table
ALTER TABLE vacancies
ADD COLUMN start_date DATE NULL AFTER vacancy_date,
ADD COLUMN end_date DATE NULL AFTER start_date,
ADD COLUMN absence_type ENUM('single_day', 'date_range', 'open_ended') NOT NULL DEFAULT 'single_day' AFTER vacancy_type;

-- Update existing vacancies to use new fields
UPDATE vacancies SET start_date = vacancy_date, end_date = vacancy_date, absence_type = 'single_day' WHERE start_date IS NULL;

-- Make start_date required (after populating existing records)
ALTER TABLE vacancies MODIFY COLUMN start_date DATE NOT NULL;

-- Add index for date range queries
CREATE INDEX idx_absence_dates ON vacancies(start_date, end_date, status);

-- Make incumbent_dispatcher_id required (vacancies are now dispatcher-based)
-- Note: This will fail if any existing vacancies don't have a dispatcher
-- Run: UPDATE vacancies SET incumbent_dispatcher_id = 1 WHERE incumbent_dispatcher_id IS NULL;
-- (Replace '1' with appropriate dispatcher ID before running this migration)

-- ALTER TABLE vacancies MODIFY COLUMN incumbent_dispatcher_id INT NOT NULL;
