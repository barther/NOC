-- Migration: Allow NULL desk_id for ATW assignments
-- Description: ATW jobs don't have a single desk, they rotate across desks

ALTER TABLE job_assignments
MODIFY COLUMN desk_id INT NULL;
