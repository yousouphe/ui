-- Module 17: mobile API support (token auth, push device tokens, idempotency).
-- Additive and idempotent — safe to run on production. No existing table is modified.
-- Backs the stateless /api/v1 bearer-token layer used by the mobile app (see mobile/docs/04).

-- Bearer access + refresh tokens. Tokens are stored HASHED (sha256), never in plaintext.
-- Access and refresh tokens issued together share a `family` so logout / revocation can drop
-- the whole device session, and refresh can rotate the access token within the family.
CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    family CHAR(36) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    type ENUM('access','refresh') NOT NULL,
    platform VARCHAR(20) DEFAULT NULL,
    device_label VARCHAR(120) DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_token_hash (token_hash),
    KEY idx_api_tokens_user (user_id),
    KEY idx_api_tokens_family (family),
    KEY idx_api_tokens_expiry (expires_at),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- FCM/APNs device tokens for mobile push. The push_notifications table remains the record of
-- what was sent; this only stores where to deliver on mobile. Web push (push_subscriptions) is
-- untouched.
CREATE TABLE IF NOT EXISTS device_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    platform ENUM('android','ios') NOT NULL,
    token VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_device_token (token),
    KEY idx_device_tokens_user (user_id),
    CONSTRAINT fk_device_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Idempotency records for unsafe writes (create booking, request, pay, withdraw). A repeated
-- request with the same key + endpoint returns the stored response instead of acting twice, so
-- an interrupted/retried mobile request can never double-act. GC'd by run_maintenance_gc().
CREATE TABLE IF NOT EXISTS idempotency_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    key_hash CHAR(64) NOT NULL,
    endpoint VARCHAR(120) NOT NULL,
    response_code SMALLINT UNSIGNED NOT NULL,
    response_body MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idempotency (user_id, key_hash, endpoint),
    KEY idx_idempotency_created (created_at),
    CONSTRAINT fk_idempotency_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
