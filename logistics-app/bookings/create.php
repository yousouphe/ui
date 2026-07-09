<?php
// Consolidated into bookings/index.php's "New Order" panel (address-first booking
// creation) - kept as a redirect for any existing bookmarks/links.
require_once __DIR__ . '/../config/functions.php';
redirect_to('bookings/index.php?new=1');
