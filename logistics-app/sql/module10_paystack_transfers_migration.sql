-- Paystack Transfers integration: a cached bank list (so riders pick from a real
-- dropdown instead of typing a bank name), verified bank accounts, and transfer
-- tracking on withdrawal requests so admin approval triggers a real payout.

CREATE TABLE IF NOT EXISTS paystack_banks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(150) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uniq_paystack_banks_code UNIQUE (code)
);

ALTER TABLE rider_bank_accounts
    ADD COLUMN IF NOT EXISTS bank_code VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS paystack_recipient_code VARCHAR(60) NULL,
    ADD COLUMN IF NOT EXISTS verified_at DATETIME NULL;

ALTER TABLE withdrawal_requests
    ADD COLUMN IF NOT EXISTS bank_code VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS paystack_transfer_code VARCHAR(60) NULL,
    ADD COLUMN IF NOT EXISTS paystack_transfer_reference VARCHAR(100) NULL,
    -- Set right before calling Paystack's transfer API and only cleared again if the
    -- attempt demonstrably failed (recipient creation error, transfer rejected outright).
    -- Prevents a double-click or two admin sessions from ever firing two real transfers
    -- for the same withdrawal request.
    ADD COLUMN IF NOT EXISTS paystack_transfer_attempted_at DATETIME NULL;
