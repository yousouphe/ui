-- KYC, complaints admin review, password reset, and Google OAuth migration
-- Run this once on your database before deploying the updated files.

-- Rider KYC review fields. Existing riders default to 'approved' so they are not
-- retroactively blocked from going online by this migration.
ALTER TABLE rider_profiles
    ADD COLUMN IF NOT EXISTS kyc_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
    ADD COLUMN IF NOT EXISTS kyc_id_document_path VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS kyc_vehicle_plate VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS kyc_note VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS kyc_reviewed_by BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS kyc_reviewed_at DATETIME NULL;

-- Admin review trail for sender complaints.
ALTER TABLE booking_complaints
    ADD COLUMN IF NOT EXISTS admin_note TEXT NULL,
    ADD COLUMN IF NOT EXISTS resolved_by BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS resolved_at DATETIME NULL;

-- Google sign-in linkage + forced-onboarding flag for accounts missing a phone number.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS google_id VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS profile_completed TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE users
    MODIFY phone VARCHAR(30) NOT NULL DEFAULT '';

CREATE UNIQUE INDEX uq_users_google_id ON users (google_id);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_prt_token_hash ON password_reset_tokens (token_hash);
CREATE INDEX idx_prt_user ON password_reset_tokens (user_id, id);
CREATE INDEX idx_rider_kyc_status ON rider_profiles (kyc_status, id);
