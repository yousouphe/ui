-- Module 15: correct the van pricing multiplier to match the actual fee schedule
-- (bike: min 1000 flat under 1km / 600 per km beyond; car: x1.5; van: x2.5 - not x1.8).
-- Idempotent: only overwrites the settings row if it's still sitting at the old 1.80
-- default, so a deliberate admin override via admin/pricing.php survives a re-run.

ALTER TABLE pricing_settings MODIFY van_multiplier DECIMAL(4,2) NOT NULL DEFAULT 2.50;

UPDATE pricing_settings SET van_multiplier = 2.50 WHERE id = 1 AND van_multiplier = 1.80;
