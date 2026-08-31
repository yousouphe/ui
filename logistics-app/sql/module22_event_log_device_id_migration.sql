-- Adds device_id to event_logs so an audit entry can be traced to the specific mobile install
-- that made the request (X-Device-Id header, a random per-install UUID - see
-- DeviceIdProvider.kt on the Android side), alongside the ip_address/user_agent columns already
-- added in module19. Not a hardware identifier (no IMEI - see api/routes_v1.php commit notes).

ALTER TABLE event_logs
    ADD COLUMN IF NOT EXISTS device_id VARCHAR(64) NULL;

CREATE INDEX IF NOT EXISTS idx_event_logs_device ON event_logs (device_id, created_at);
