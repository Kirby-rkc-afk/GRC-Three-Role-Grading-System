-- Existing records keep their original calculation until explicitly converted.
ALTER TABLE classbook_settings ADD COLUMN IF NOT EXISTS grading_model VARCHAR(30) NOT NULL DEFAULT 'legacy_components';
