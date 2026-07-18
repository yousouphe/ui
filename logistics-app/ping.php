<?php
// Lightweight connectivity probe used by the PWA splash/offline logic to confirm that the
// network is ACTUALLY reachable (navigator.onLine is only a hint). Deliberately does NOT
// include functions.php/db.php: no session, no database, no heavy headers - it must stay
// cheap and always-available so a probe is a near-zero-cost signal, and it must never depend
// on the DB being up (this checks connectivity, not application health).
//
// Responds 204 No Content. Never cached, so every probe reflects live reachability.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
http_response_code(204);
