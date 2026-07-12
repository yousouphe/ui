-- Module 14: sender picks a transport type up front (price is locked at booking creation
-- instead of being discovered later from whichever rider happens to respond), riders can
-- carry up to 3 concurrent orders instead of 1, and completion time is tracked per booking
-- so a rider's average actual-vs-planned duration ratio can be shown to senders. Idempotent.

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS vehicle_type ENUM('bike','car','van') NULL AFTER item_image_path,
    ADD COLUMN IF NOT EXISTS matched_at DATETIME NULL AFTER booking_status,
    ADD COLUMN IF NOT EXISTS planned_duration_minutes SMALLINT UNSIGNED NULL AFTER agreed_cost,
    ADD COLUMN IF NOT EXISTS actual_duration_minutes SMALLINT UNSIGNED NULL AFTER planned_duration_minutes;
