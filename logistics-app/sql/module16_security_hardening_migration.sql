-- Module 16: security hardening support tables.
-- All statements are idempotent - safe to run directly on production.

-- Route-metrics cache.
--
-- The sender's rider-search poll (bookings/ajax_fetch_riders.php) used to call the Mapbox
-- Directions API on every poll (~4x/min per open booking). That turned a normal poll loop
-- into a worker-exhaustion / Mapbox-quota amplification vector. Pickup->delivery never
-- changes for a booking, so the road distance/duration are cached here keyed by the rounded
-- coordinate pair: the first poll populates the row, every later poll is a local read.
-- Pruned by run_maintenance_gc()/scripts/gc.php so it never grows unbounded.
CREATE TABLE IF NOT EXISTS route_cache (
    coord_key CHAR(40) NOT NULL,
    distance_km DECIMAL(10,3) NOT NULL,
    duration_min DECIMAL(10,3) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (coord_key),
    KEY idx_route_cache_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The rate-limit lookup index (action, identifier, created_at) already ships with the
-- rate_limit_attempts table in module12; no change needed here. This migration only adds the
-- route cache that the caching + GC code introduced by the same hardening pass depends on.
