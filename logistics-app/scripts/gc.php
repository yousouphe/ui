<?php
// Housekeeping job - run from cron, e.g. every 15 minutes:
//   */15 * * * * php /path/to/app/scripts/gc.php >/dev/null 2>&1
//
// Prunes append-only/scratch data (expired rate-limit rows, stale route cache, stale
// realtime presence/call files) so nothing grows unbounded and fills the disk - an outage
// vector in its own right. The same routine also runs opportunistically on a small fraction
// of web requests (see config/db.php), so this cron is a reliable backstop, not the only
// line of defence. Safe to run at any time; each step is independently guarded.
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/db.php';

run_maintenance_gc($pdo);

echo "maintenance gc complete\n";
