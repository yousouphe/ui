-- Ratings + complaints migration
-- Run this once on your database before deploying the updated files.

CREATE TABLE IF NOT EXISTS booking_ratings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    sender_user_id BIGINT UNSIGNED NOT NULL,
    rider_user_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    review_text TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_br_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_br_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_br_rider FOREIGN KEY (rider_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT uniq_br_booking UNIQUE (booking_id)
);

CREATE TABLE IF NOT EXISTS booking_complaints (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    sender_user_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('open','reviewing','resolved') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bc_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_bc_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_ratings_rider ON booking_ratings (rider_user_id, id);
CREATE INDEX idx_complaints_booking ON booking_complaints (booking_id, id);
CREATE INDEX idx_complaints_status ON booking_complaints (status, id);
