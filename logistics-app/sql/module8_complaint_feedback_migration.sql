-- Lets senders leave feedback on how their complaint was handled once an admin
-- resolves it, so admins can see whether resolutions are actually landing well.
ALTER TABLE booking_complaints
    ADD COLUMN IF NOT EXISTS sender_satisfied TINYINT(1) NULL,
    ADD COLUMN IF NOT EXISTS sender_feedback_text VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS feedback_submitted_at DATETIME NULL;
