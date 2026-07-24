<?php
// Payment receipts + financial audit helpers (module19).
//
// Receipts are immutable: generate_payment_receipt() is idempotent on booking_id, snapshots the
// parties/addresses/amounts at payment time, and is safe to call from both the browser callback and
// the Paystack webhook without ever creating a duplicate. VAT is derived from the admin-configurable
// pricing tax_percent (agreed_cost is VAT-inclusive), so it stays 0 unless a rate is set.
//
// audit_financial_event() wraps log_event() but also records the IP, device/user-agent, transaction
// reference and order id the financial spec requires on every money event.

require_once __DIR__ . '/functions.php';

/**
 * Split a VAT-inclusive gross into [net, vat, total] using the configured tax_percent.
 * agreed_cost already includes tax (see calculate_delivery_price), so we extract the VAT portion.
 */
function receipt_vat_breakdown(PDO $pdo, float $gross): array {
    $settings = pricing_settings($pdo);
    $vatPercent = (float) ($settings['tax_percent'] ?? 0);
    if ($vatPercent <= 0) {
        return ['net' => round($gross, 2), 'vat' => 0.00, 'percent' => 0.00, 'total' => round($gross, 2)];
    }
    $vat = round($gross * ($vatPercent / (100 + $vatPercent)), 2);
    return ['net' => round($gross - $vat, 2), 'vat' => $vat, 'percent' => $vatPercent, 'total' => round($gross, 2)];
}

/** Build a human, sortable, unique receipt number: AIKE-RC-<year>-<zero-padded booking id>. */
function receipt_number_for(int $bookingId): string {
    return sprintf('AIKE-RC-%s-%06d', date('Y'), $bookingId);
}

/** Fetch a booking's receipt row, or null. */
function get_receipt_for_booking(PDO $pdo, int $bookingId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM payment_receipts WHERE booking_id = ? LIMIT 1');
    $stmt->execute([$bookingId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Fetch a receipt by its receipt number, or null. */
function find_receipt_by_number(PDO $pdo, string $receiptNumber): ?array {
    $stmt = $pdo->prepare('SELECT * FROM payment_receipts WHERE receipt_number = ? LIMIT 1');
    $stmt->execute([$receiptNumber]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Create (or return the existing) immutable receipt for a paid booking. Idempotent: a second call
 * for the same booking returns the first receipt untouched. Returns the receipt row.
 */
function generate_payment_receipt(PDO $pdo, int $bookingId, string $reference): ?array {
    $existing = get_receipt_for_booking($pdo, $bookingId);
    if ($existing) {
        return $existing;
    }

    $stmt = $pdo->prepare('
        SELECT b.id, b.booking_code, b.agreed_cost, b.pickup_address, b.delivery_address,
               b.sender_user_id, b.selected_rider_user_id,
               s.full_name AS sender_name, s.email AS sender_email,
               r.full_name AS rider_name
        FROM bookings b
        INNER JOIN users s ON s.id = b.sender_user_id
        LEFT JOIN users r ON r.id = b.selected_rider_user_id
        WHERE b.id = ?
        LIMIT 1
    ');
    $stmt->execute([$bookingId]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$b) {
        return null;
    }

    $breakdown = receipt_vat_breakdown($pdo, (float) $b['agreed_cost']);

    try {
        $stmt = $pdo->prepare('
            INSERT INTO payment_receipts
                (receipt_number, booking_id, payment_reference, order_code, customer_user_id,
                 customer_name, customer_email, rider_user_id, rider_name, pickup_address,
                 delivery_address, amount, vat_amount, vat_percent, total_amount, payment_method,
                 payment_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            receipt_number_for($bookingId),
            $bookingId,
            $reference,
            $b['booking_code'],
            (int) $b['sender_user_id'],
            $b['sender_name'],
            $b['sender_email'],
            $b['selected_rider_user_id'] !== null ? (int) $b['selected_rider_user_id'] : null,
            $b['rider_name'],
            $b['pickup_address'],
            $b['delivery_address'],
            $breakdown['net'],
            $breakdown['vat'],
            $breakdown['percent'],
            $breakdown['total'],
            'paystack',
            'paid',
        ]);
    } catch (Throwable $e) {
        // A concurrent caller (webhook + callback racing) may have inserted first — the UNIQUE
        // constraint on booking_id makes that safe; just return whatever now exists.
        return get_receipt_for_booking($pdo, $bookingId);
    }

    return get_receipt_for_booking($pdo, $bookingId);
}

/**
 * Record a financial audit event. Wraps log_event() (event_type/description/actor/target/meta) and
 * additionally persists the IP, user-agent, transaction reference and order id the spec requires.
 * Falls back to a plain log_event() if the module19 columns are not present yet.
 */
function audit_financial_event(
    PDO $pdo,
    string $eventType,
    string $description,
    ?int $actorUserId = null,
    ?string $actorRole = null,
    ?int $orderId = null,
    ?string $reference = null,
    array $meta = []
): void {
    $ip = function_exists('client_ip') ? client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    try {
        $stmt = $pdo->prepare('
            INSERT INTO event_logs
                (event_type, actor_user_id, actor_role, target_type, target_id, description, meta,
                 ip_address, user_agent, transaction_reference, order_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $eventType,
            $actorUserId,
            $actorRole,
            $orderId !== null ? 'booking' : null,
            $orderId,
            $description,
            $meta !== [] ? json_encode($meta) : null,
            $ip !== '' ? $ip : null,
            $ua !== '' ? $ua : null,
            $reference,
            $orderId,
        ]);
    } catch (Throwable $e) {
        // Columns missing (migration not yet run) or any insert issue: fall back so we never lose
        // the event, and never let auditing break the money path.
        log_event($pdo, $eventType, $description, $actorUserId, $actorRole, $orderId !== null ? 'booking' : null, $orderId, $meta);
    }
}
