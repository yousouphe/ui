-- Financial module: immutable receipts, admin balance-view OTPs, and an enriched audit trail.
-- Idempotent — safe to run more than once. Run before deploying the module19 code.
--
-- Context: this backs the "Enhance Wallet, Transactions, Receipts, and Order Completion"
-- work. Order completion is decoupled from the rider's manual acknowledgement — Paystack
-- verification is the source of truth and now also auto-credits the rider and generates a
-- receipt. Financial records are immutable: we never edit them, only append reversal or
-- adjustment entries. These tables + indexes support that plus fast search/reporting.

-- 0) booking_payments — the per-booking Paystack charge record. Its CREATE was missing from
--    version control (the table exists in the live DB but no migration defined it, so a
--    from-scratch deploy could not build it or run module12's refund ALTERs). Captured here,
--    idempotently, with the module12 refund columns folded in so module19 is self-contained.
CREATE TABLE IF NOT EXISTS booking_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) NOT NULL DEFAULT 'NGN',
    reference VARCHAR(120) NOT NULL,
    access_code VARCHAR(120) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'initialized',
    paid_at DATETIME NULL,
    refund_status VARCHAR(20) NOT NULL DEFAULT 'none',
    refund_amount DECIMAL(12,2) NULL,
    refunded_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uniq_booking_payments_reference UNIQUE (reference),
    KEY idx_bp_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 1) Immutable payment receipts. One row per successful booking payment (idempotent on
--    booking_id). Snapshots the parties/addresses/amounts as they were at payment time so a
--    later profile/address edit can never rewrite history.
CREATE TABLE IF NOT EXISTS payment_receipts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_number VARCHAR(40) NOT NULL,
    booking_id BIGINT UNSIGNED NOT NULL,
    payment_reference VARCHAR(120) NOT NULL,
    order_code VARCHAR(40) NULL,
    customer_user_id BIGINT UNSIGNED NULL,
    customer_name VARCHAR(150) NULL,
    customer_email VARCHAR(190) NULL,
    rider_user_id BIGINT UNSIGNED NULL,
    rider_name VARCHAR(150) NULL,
    pickup_address VARCHAR(255) NULL,
    delivery_address VARCHAR(255) NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,      -- net (ex-VAT)
    vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    vat_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- what the customer paid (VAT-inclusive)
    payment_method VARCHAR(40) NOT NULL DEFAULT 'paystack',
    payment_status VARCHAR(20) NOT NULL DEFAULT 'paid',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uniq_receipt_number UNIQUE (receipt_number),
    CONSTRAINT uniq_receipt_booking UNIQUE (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE INDEX IF NOT EXISTS idx_receipts_reference ON payment_receipts (payment_reference);
CREATE INDEX IF NOT EXISTS idx_receipts_customer ON payment_receipts (customer_user_id, id);
CREATE INDEX IF NOT EXISTS idx_receipts_rider ON payment_receipts (rider_user_id, id);

-- 2) One-time passwords gating an admin's "View Balance" action. Codes are stored hashed,
--    expire in 5 minutes, are single-use, rate-limited by recent unused rows, and every
--    generate/verify/fail is also written to event_logs for audit.
CREATE TABLE IF NOT EXISTS admin_balance_otps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,           -- sha256(code)
    target_type VARCHAR(20) NOT NULL,      -- 'user' | 'rider'
    target_id BIGINT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    used_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_otp_admin_created (admin_user_id, created_at),
    KEY idx_otp_lookup (admin_user_id, target_type, target_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3) Enrich the existing audit log with the fields the financial spec requires on every
--    event: originating IP, device/user-agent, the transaction reference, and the order id.
ALTER TABLE event_logs
    ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL,
    ADD COLUMN IF NOT EXISTS user_agent VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS transaction_reference VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS order_id BIGINT UNSIGNED NULL;

CREATE INDEX IF NOT EXISTS idx_event_logs_reference ON event_logs (transaction_reference);
CREATE INDEX IF NOT EXISTS idx_event_logs_order ON event_logs (order_id);

-- 4) Indexing for fast transaction search/reporting across the money tables.
--    (bookings.paystack_reference is already indexed by module18.)
CREATE INDEX IF NOT EXISTS idx_bookings_sender_paystatus ON bookings (sender_user_id, payment_status, updated_at);
CREATE INDEX IF NOT EXISTS idx_wallet_tx_booking ON wallet_transactions (booking_id);
CREATE INDEX IF NOT EXISTS idx_wallet_tx_rider_created ON wallet_transactions (rider_user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_booking_payments_reference ON booking_payments (reference);
