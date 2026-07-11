-- Self-service profiles + expanded rider KYC migration
-- Run this once on your database before deploying the updated files.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS avatar_path VARCHAR(255) NULL;

ALTER TABLE rider_profiles
    ADD COLUMN IF NOT EXISTS kyc_vehicle_color VARCHAR(30) NULL;
