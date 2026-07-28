-- Module 20: rider training completion tracking (mobile-only feature, no web equivalent yet).
-- Additive and idempotent (ADD COLUMN IF NOT EXISTS): a no-op where the column already exists.

ALTER TABLE rider_profiles
    ADD COLUMN IF NOT EXISTS training_completed_at DATETIME NULL;
