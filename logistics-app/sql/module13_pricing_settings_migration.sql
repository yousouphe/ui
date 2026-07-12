-- Module 13: admin-configurable delivery pricing (replaces the hardcoded distance*rate
-- formula and the buggy proportional-scaling repricing formula). Idempotent.

CREATE TABLE IF NOT EXISTS pricing_settings (
    id TINYINT UNSIGNED NOT NULL,
    minimum_fee DECIMAL(10,2) NOT NULL DEFAULT 1000.00,
    per_km_rate DECIMAL(10,2) NOT NULL DEFAULT 600.00,
    bike_multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    car_multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.50,
    van_multiplier DECIMAL(4,2) NOT NULL DEFAULT 1.80,
    tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    updated_by BIGINT UNSIGNED DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Single-row settings singleton, seeded with the same defaults callers fall back to in
-- code if this row is ever missing.
INSERT IGNORE INTO pricing_settings (id, minimum_fee, per_km_rate, bike_multiplier, car_multiplier, van_multiplier, tax_percent)
VALUES (1, 1000.00, 600.00, 1.00, 1.50, 1.80, 0.00);
