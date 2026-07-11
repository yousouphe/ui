-- Module 12: refund tracking, login/registration rate limiting, and web push notifications.
-- All statements are idempotent - safe to run directly on production.

-- Refunds
ALTER TABLE bookings MODIFY COLUMN payment_status ENUM('unpaid','pending','paid','failed','refunded') NOT NULL DEFAULT 'unpaid';
ALTER TABLE booking_payments ADD COLUMN IF NOT EXISTS refund_status VARCHAR(20) NOT NULL DEFAULT 'none';
ALTER TABLE booking_payments ADD COLUMN IF NOT EXISTS refund_amount DECIMAL(12,2) DEFAULT NULL;
ALTER TABLE booking_payments ADD COLUMN IF NOT EXISTS refunded_at DATETIME DEFAULT NULL;

-- Rate limiting (brute-force protection on login/register/forgot-password)
CREATE TABLE IF NOT EXISTS rate_limit_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    action VARCHAR(50) NOT NULL,
    identifier VARCHAR(191) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rate_limit_lookup (action, identifier, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Web push notifications
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    endpoint TEXT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_push_endpoint_hash (endpoint_hash),
    KEY idx_push_user (user_id),
    CONSTRAINT fk_push_subscriptions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS push_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    body VARCHAR(255) NOT NULL,
    url VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_push_notifications_pending (user_id, delivered_at, id),
    CONSTRAINT fk_push_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
