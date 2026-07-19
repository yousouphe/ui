-- Module 18: capture booking columns that existing code already relies on but that were never
-- committed as a migration. These columns exist on the production database (they were added
-- ad-hoc), so the app works in production — but a fresh deployment built purely from sql/*.sql
-- was missing them, which would break payments (payments/initialize.php, payments/start.php,
-- api/routes_v1.php payment init/verify + the /payments receipts list) and cancel/rebook
-- (bookings/ajax_cancel_booking.php, bookings/ajax_rebook.php, api cancel + rebook).
--
-- Additive and idempotent (ADD COLUMN IF NOT EXISTS): a no-op where the columns already exist,
-- and it brings a fresh schema in line with production. No data is modified.

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS paystack_reference   VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS paystack_access_code VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS cancellation_reason  VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS cancelled_by         VARCHAR(20)  NULL;

-- Paystack reference is looked up on payment verification; index it for that lookup.
CREATE INDEX IF NOT EXISTS idx_bookings_paystack_reference ON bookings (paystack_reference);
