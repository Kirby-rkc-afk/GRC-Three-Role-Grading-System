-- Add stable, automatic account IDs without replacing legacy school identifiers.
ALTER TABLE classbook_account_meta ADD COLUMN IF NOT EXISTS public_id VARCHAR(32) NULL;
ALTER TABLE classbook_account_meta ADD UNIQUE INDEX IF NOT EXISTS unique_public_id (public_id);
