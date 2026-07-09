CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(30) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('sender','rider','admin') NOT NULL DEFAULT 'sender',
    status ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

DROP TABLE rider_profiles;

CREATE TABLE IF NOT EXISTS rider_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    vehicle_type ENUM('bike','car','van') NOT NULL DEFAULT 'bike',
    rating DECIMAL(3,2) NOT NULL DEFAULT 5.00,
    availability_status ENUM('available','busy','offline') NOT NULL DEFAULT 'available',
    last_latitude DECIMAL(10,7) NULL,
    last_longitude DECIMAL(10,7) NULL,
    last_location_updated_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rider_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

DROP TABLE bookings;

CREATE TABLE IF NOT EXISTS bookings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sender_user_id BIGINT UNSIGNED NOT NULL,
    booking_code VARCHAR(50) NOT NULL UNIQUE,
    recipient_name VARCHAR(150) NOT NULL,
    recipient_phone VARCHAR(30) NOT NULL,
    pickup_address VARCHAR(255) NOT NULL,
    pickup_latitude DECIMAL(10,7) NULL,
    pickup_longitude DECIMAL(10,7) NULL,
    delivery_address VARCHAR(255) NOT NULL,
    delivery_latitude DECIMAL(10,7) NULL,
    delivery_longitude DECIMAL(10,7) NULL,
    item_name VARCHAR(150) NOT NULL,
    item_category VARCHAR(50) NOT NULL,
    item_description TEXT NULL,
    item_image_path VARCHAR(255) NULL,
    estimated_value DECIMAL(12,2) NULL,
    special_instructions TEXT NULL,
    booking_status ENUM('draft','submitted','matched','cancelled') NOT NULL DEFAULT 'submitted',
    selected_rider_user_id BIGINT UNSIGNED NULL,
    agreed_cost DECIMAL(12,2) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_bookings_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_bookings_selected_rider FOREIGN KEY (selected_rider_user_id) REFERENCES users(id) ON DELETE SET NULL
);


drop TABLE rider_requests ;

CREATE TABLE IF NOT EXISTS rider_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    rider_user_id BIGINT UNSIGNED NOT NULL,
    sender_user_id BIGINT UNSIGNED NOT NULL,
    proposed_cost DECIMAL(12,2) NOT NULL,
    request_status ENUM('pending','accepted','rejected','expired','cancelled') NOT NULL DEFAULT 'pending',
    rider_response_note VARCHAR(255) NULL,
    responded_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rider_requests_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_rider_requests_rider FOREIGN KEY (rider_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rider_requests_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_booking_rider (booking_id, rider_user_id)
);

INSERT INTO users (full_name, email, phone, password_hash, role, status) VALUES
('Sender Demo', 'sender@example.com', '08000000001', '$2y$10$2RFG6lW8OSJ5dJ4K6saP0uK5r3Rz9fAkqqjvP93M./r6HOeM6mK1m', 'sender', 'active'),
('Rider One', 'rider1@example.com', '08000000011', '$2y$10$2RFG6lW8OSJ5dJ4K6saP0uK5r3Rz9fAkqqjvP93M./r6HOeM6mK1m', 'rider', 'active'),
('Rider Two', 'rider2@example.com', '08000000012', '$2y$10$2RFG6lW8OSJ5dJ4K6saP0uK5r3Rz9fAkqqjvP93M./r6HOeM6mK1m', 'rider', 'active'),
('Rider Three', 'rider3@example.com', '08000000013', '$2y$10$2RFG6lW8OSJ5dJ4K6saP0uK5r3Rz9fAkqqjvP93M./r6HOeM6mK1m', 'rider', 'active')
ON DUPLICATE KEY UPDATE email=email;

INSERT INTO rider_profiles (user_id, vehicle_type, rating, availability_status, last_latitude, last_longitude, last_location_updated_at)
SELECT u.id, 'bike', 4.90, 'available', 12.0022000, 8.5920000, NOW() FROM users u WHERE u.email='rider1@example.com'
ON DUPLICATE KEY UPDATE user_id=user_id;
INSERT INTO rider_profiles (user_id, vehicle_type, rating, availability_status, last_latitude, last_longitude, last_location_updated_at)
SELECT u.id, 'bike', 4.80, 'available', 12.0158000, 8.5403000, NOW() FROM users u WHERE u.email='rider2@example.com'
ON DUPLICATE KEY UPDATE user_id=user_id;
INSERT INTO rider_profiles (user_id, vehicle_type, rating, availability_status, last_latitude, last_longitude, last_location_updated_at)
SELECT u.id, 'car', 4.70, 'available', 11.9801000, 8.6101000, NOW() FROM users u WHERE u.email='rider3@example.com'
ON DUPLICATE KEY UPDATE user_id=user_id;

INSERT INTO bookings (
    sender_user_id, booking_code, recipient_name, recipient_phone, pickup_address, pickup_latitude, pickup_longitude,
    delivery_address, delivery_latitude, delivery_longitude, item_name, item_category, item_description, booking_status
)
SELECT u.id, 'BK-DEMO-001', 'Amina Yusuf', '08000000999', 'Farm Centre, Kano', 12.0022, 8.5920,
       'Gyadi-Gyadi, Kano', 11.9910, 8.5330, 'Food Flask', 'food', 'Handle with care', 'submitted'
FROM users u WHERE u.email='sender@example.com'
ON DUPLICATE KEY UPDATE booking_code=booking_code;


ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS payment_status ENUM('unpaid','pending','paid','failed') NOT NULL DEFAULT 'unpaid',
    ADD COLUMN IF NOT EXISTS sender_tracking_token VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS sender_handover_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS sender_handover_confirmed_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS delivered_at DATETIME NULL;

ALTER TABLE bookings
    MODIFY booking_status ENUM('draft','submitted','matched','accepted','arrived_at_pickup','package_received','in_transit','delivered','cancelled') NOT NULL DEFAULT 'submitted';

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
