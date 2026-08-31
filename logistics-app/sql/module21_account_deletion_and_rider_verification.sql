-- Module 21: account deletion requests + rider OTP/email-link verification for withdrawals
-- and bank-account changes.

CREATE TABLE IF NOT EXISTS account_deletion_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    status ENUM('pending','processed') NOT NULL DEFAULT 'pending',
    admin_user_id BIGINT UNSIGNED NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_adr_status (status, created_at),
    CONSTRAINT fk_adr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Gates a rider adding/changing their payout bank account, or requesting a withdrawal: the
-- action's parameters are stored here (not written to their real table) until the rider confirms
-- via either the SMS code or the emailed link - whichever arrives/works for them.
CREATE TABLE IF NOT EXISTS rider_action_verifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rider_user_id BIGINT UNSIGNED NOT NULL,
    action_type ENUM('withdrawal','bank_change') NOT NULL,
    payload_json TEXT NOT NULL,
    code_hash CHAR(64) NOT NULL,        -- sha256(6-digit SMS code)
    link_token_hash CHAR(64) NOT NULL,  -- sha256(email confirmation link token)
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    used_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rav_rider_created (rider_user_id, created_at),
    KEY idx_rav_link_lookup (link_token_hash, used_at),
    CONSTRAINT fk_rav_rider FOREIGN KEY (rider_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
