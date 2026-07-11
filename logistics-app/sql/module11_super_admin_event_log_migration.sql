-- Adds a super_admin role (only super admins can assign roles - regular admins can
-- still see everything else, they just can't promote/demote anyone) and a central
-- event log that admins can filter to troubleshoot bookings, payments, withdrawals,
-- admin actions, and outbound emails from one place.

ALTER TABLE users
    MODIFY COLUMN role ENUM('sender', 'rider', 'admin', 'super_admin') NOT NULL DEFAULT 'sender';

CREATE TABLE IF NOT EXISTS event_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(60) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_role VARCHAR(20) NULL,
    target_type VARCHAR(40) NULL,
    target_id BIGINT UNSIGNED NULL,
    description VARCHAR(500) NOT NULL,
    meta TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_event_logs_type (event_type, created_at),
    KEY idx_event_logs_target (target_type, target_id),
    KEY idx_event_logs_created (created_at)
);
