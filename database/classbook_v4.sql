-- A major exam is shared across lecture/laboratory criteria in one subject offering.
ALTER TABLE classbook_criteria ADD COLUMN IF NOT EXISTS exam_key VARCHAR(20) NULL;
