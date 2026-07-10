-- Rider payment-confirmation migration
-- Run this once on your database before deploying the updated files.
--
-- Adds a rider-side confirmation step after a delivered booking is paid: the sender cannot
-- start a new booking until the assigned rider marks the previous delivered booking's payment
-- as received.

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS rider_payment_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS rider_payment_confirmed_at DATETIME NULL;

CREATE INDEX idx_bookings_sender_payment_confirm ON bookings (sender_user_id, booking_status, rider_payment_confirmed);
