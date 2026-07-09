-- Performance + compatibility migration for shared hosting
-- Run this once on your current database before deploying the updated files.

ALTER TABLE bookings
    MODIFY booking_status ENUM('draft','submitted','matched','accepted','arrived_at_pickup','package_received','in_transit','delivered','cancelled') NOT NULL DEFAULT 'submitted';

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS payment_status ENUM('unpaid','pending','paid','failed') NOT NULL DEFAULT 'unpaid',
    ADD COLUMN IF NOT EXISTS sender_tracking_token VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS sender_handover_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS sender_handover_confirmed_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS delivered_at DATETIME NULL;

ALTER TABLE rider_requests
    ADD COLUMN IF NOT EXISTS responded_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS booking_chat_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    sender_user_id BIGINT UNSIGNED NOT NULL,
    receiver_user_id BIGINT UNSIGNED NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    delivered_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_bcm_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_bcm_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_bookings_sender_id ON bookings (sender_user_id, id);
CREATE INDEX idx_bookings_rider_status ON bookings (selected_rider_user_id, booking_status, id);
CREATE INDEX idx_bookings_tracking_token ON bookings (sender_tracking_token);
CREATE INDEX idx_bookings_status_payment ON bookings (booking_status, payment_status);

CREATE INDEX idx_rider_requests_rider_status ON rider_requests (rider_user_id, request_status, id);
CREATE INDEX idx_rider_requests_booking_status ON rider_requests (booking_id, request_status);

CREATE INDEX idx_chat_booking_id ON booking_chat_messages (booking_id, id);
CREATE INDEX idx_chat_receiver_read ON booking_chat_messages (receiver_user_id, is_read, booking_id);

CREATE INDEX idx_rider_profiles_availability_updated ON rider_profiles (availability_status, last_location_updated_at);
