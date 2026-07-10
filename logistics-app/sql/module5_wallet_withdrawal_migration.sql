-- Rider wallet + withdrawal migration
-- Run this once on your database before deploying the updated files.
--
-- Adds the rider earnings ledger (85% of each confirmed delivery goes to the rider, 15% is
-- the platform's cut - captured implicitly by only crediting the 85% share), a rider bank
-- account details table, and a withdrawal request queue an admin processes from the admin
-- portal.

CREATE TABLE IF NOT EXISTS wallet_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rider_user_id BIGINT UNSIGNED NOT NULL,
    booking_id BIGINT UNSIGNED NULL,
    withdrawal_request_id BIGINT UNSIGNED NULL,
    type ENUM('earning', 'withdrawal') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    description VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wt_rider FOREIGN KEY (rider_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_wt_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS rider_bank_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rider_user_id BIGINT UNSIGNED NOT NULL,
    bank_name VARCHAR(120) NOT NULL,
    account_number VARCHAR(30) NOT NULL,
    account_name VARCHAR(150) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rba_rider FOREIGN KEY (rider_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT uniq_rba_rider UNIQUE (rider_user_id)
);

CREATE TABLE IF NOT EXISTS withdrawal_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rider_user_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    bank_name VARCHAR(120) NOT NULL,
    account_number VARCHAR(30) NOT NULL,
    account_name VARCHAR(150) NOT NULL,
    status ENUM('pending', 'processing', 'paid', 'rejected') NOT NULL DEFAULT 'pending',
    admin_user_id BIGINT UNSIGNED NULL,
    admin_note VARCHAR(255) NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    CONSTRAINT fk_wr_rider FOREIGN KEY (rider_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_wr_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL
);

ALTER TABLE wallet_transactions
    ADD CONSTRAINT fk_wt_withdrawal FOREIGN KEY (withdrawal_request_id) REFERENCES withdrawal_requests(id) ON DELETE SET NULL;

CREATE INDEX idx_wallet_transactions_rider ON wallet_transactions (rider_user_id, id);
CREATE INDEX idx_withdrawal_requests_rider ON withdrawal_requests (rider_user_id, id);
CREATE INDEX idx_withdrawal_requests_status ON withdrawal_requests (status, requested_at);
