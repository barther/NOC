-- Add seniority sequence for tiebreaking same-date seniority
-- When multiple dispatchers have the same seniority_date,
-- the sequence number determines the order (lower = more senior)

ALTER TABLE dispatchers
ADD COLUMN seniority_sequence INT DEFAULT 1 COMMENT 'Tiebreaker for same seniority date (1=most senior)';

-- Add index for sorting
CREATE INDEX idx_seniority_date_seq ON dispatchers(seniority_date, seniority_sequence);

-- Update existing dispatchers to have sequence 1 (no change to current order)
UPDATE dispatchers SET seniority_sequence = 1 WHERE seniority_sequence IS NULL;
