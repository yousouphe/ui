<?php
// Normalised transaction history for a sender or rider (module19, spec §2/§3). Produces a single
// list of transactions with search, date/type/status filters, and sorting, plus a summary
// (opening/closing balance, total credits/debits) for the filtered set. Used by transactions.php
// for the on-screen table, CSV export, and the printable statement.
//
// Ledger sign convention (see config/paystack.php): rider earnings are stored positive (credit),
// withdrawals negative (debit); wallet balance = SUM(amount).

require_once __DIR__ . '/functions.php';

const TX_MAX_ROWS = 5000; // safety cap for a single filtered export

/** Resolve a preset (or custom from/to) into [fromDatetime|null, toDatetime|null]. */
function tx_resolve_range(string $preset, string $from = '', string $to = ''): array {
    $today = new DateTimeImmutable('today');
    switch ($preset) {
        case 'today':      return [$today->format('Y-m-d 00:00:00'), $today->format('Y-m-d 23:59:59')];
        case 'yesterday':  $y = $today->modify('-1 day'); return [$y->format('Y-m-d 00:00:00'), $y->format('Y-m-d 23:59:59')];
        case 'this_week':  $s = $today->modify('monday this week'); return [$s->format('Y-m-d 00:00:00'), $today->format('Y-m-d 23:59:59')];
        case 'last_week':  $s = $today->modify('monday last week'); $e = $s->modify('+6 days'); return [$s->format('Y-m-d 00:00:00'), $e->format('Y-m-d 23:59:59')];
        case 'this_month': $s = $today->modify('first day of this month'); return [$s->format('Y-m-d 00:00:00'), $today->format('Y-m-d 23:59:59')];
        case 'last_month': $s = $today->modify('first day of last month'); $e = $today->modify('last day of last month'); return [$s->format('Y-m-d 00:00:00'), $e->format('Y-m-d 23:59:59')];
        case 'custom':
            $f = $from !== '' ? (new DateTimeImmutable($from))->format('Y-m-d 00:00:00') : null;
            $t = $to !== '' ? (new DateTimeImmutable($to))->format('Y-m-d 23:59:59') : null;
            return [$f, $t];
        default: return [null, null]; // all time
    }
}

/** Build the normalised transaction rows for $user, applying $f (filters). Returns all matching
 *  rows (capped), newest-first before the caller sorts/paginates. */
function tx_rows_for_user(PDO $pdo, array $user, array $f): array {
    $uid = (int) $user['id'];
    [$from, $to] = tx_resolve_range((string) ($f['range'] ?? 'all'), (string) ($f['from'] ?? ''), (string) ($f['to'] ?? ''));
    $rows = [];

    if (($user['role'] ?? '') === 'rider') {
        // Wallet ledger (earnings = credit, withdrawals = debit) joined to withdrawal status/ref.
        $sql = "SELECT wt.id, wt.type, wt.amount, wt.description, wt.created_at, wt.booking_id,
                       b.booking_code, wr.status AS wstatus, wr.paystack_transfer_reference AS wref
                FROM wallet_transactions wt
                LEFT JOIN bookings b ON b.id = wt.booking_id
                LEFT JOIN withdrawal_requests wr ON wr.id = wt.withdrawal_request_id
                WHERE wt.rider_user_id = ?";
        $params = [$uid];
        if ($from) { $sql .= ' AND wt.created_at >= ?'; $params[] = $from; }
        if ($to)   { $sql .= ' AND wt.created_at <= ?'; $params[] = $to; }
        $sql .= ' ORDER BY wt.id DESC LIMIT ' . TX_MAX_ROWS;
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $credit = $r['type'] === 'earning';
            $rows[] = [
                'id' => 'W' . $r['id'],
                'date' => $r['created_at'],
                'category' => $credit ? 'ride_payment' : 'withdrawal',
                'direction' => $credit ? 'credit' : 'debit',
                'description' => $r['description'],
                'order_code' => $r['booking_code'] ?? '',
                'reference' => $credit ? ($r['booking_code'] ?? '') : ($r['wref'] ?? ''),
                'amount' => (float) $r['amount'], // signed
                'status' => $credit ? 'successful' : tx_map_status((string) ($r['wstatus'] ?? 'paid')),
                'party' => '',
            ];
        }
    } else {
        // Sender payments (a debit from their side) + refund state.
        $sql = "SELECT bp.id, bp.amount, bp.reference, bp.status, bp.paid_at, bp.created_at,
                       bp.refund_status, bp.refund_amount, b.booking_code, b.recipient_name
                FROM booking_payments bp INNER JOIN bookings b ON b.id = bp.booking_id
                WHERE bp.user_id = ?";
        $params = [$uid];
        if ($from) { $sql .= ' AND COALESCE(bp.paid_at, bp.created_at) >= ?'; $params[] = $from; }
        if ($to)   { $sql .= ' AND COALESCE(bp.paid_at, bp.created_at) <= ?'; $params[] = $to; }
        $sql .= ' ORDER BY bp.id DESC LIMIT ' . TX_MAX_ROWS;
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $refunded = ($r['refund_status'] ?? 'none') !== 'none';
            $rows[] = [
                'id' => 'P' . $r['id'],
                'date' => $r['paid_at'] ?: $r['created_at'],
                'category' => $refunded ? 'refund' : 'ride_payment',
                'direction' => $refunded ? 'credit' : 'debit',
                'description' => 'Payment for ' . ($r['booking_code'] ?? ''),
                'order_code' => $r['booking_code'] ?? '',
                'reference' => $r['reference'] ?? '',
                'amount' => $refunded ? (float) $r['refund_amount'] : -1 * (float) $r['amount'],
                'status' => $refunded ? 'refunded' : tx_map_status((string) $r['status']),
                'party' => $r['recipient_name'] ?? '',
            ];
        }
    }

    // In-PHP filters: type, status, and free-text search.
    $type = (string) ($f['type'] ?? '');
    $status = (string) ($f['status'] ?? '');
    $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
    $rows = array_values(array_filter($rows, static function (array $r) use ($type, $status, $q) {
        if ($type !== '' && $r['category'] !== $type) {
            // 'deposit' is an alias for a credit that isn't a refund
            if (!($type === 'deposit' && $r['direction'] === 'credit' && $r['category'] !== 'refund')) { return false; }
        }
        if ($status !== '' && $r['status'] !== $status) { return false; }
        if ($q !== '') {
            $hay = mb_strtolower(implode(' ', [$r['id'], $r['reference'], $r['order_code'], $r['party'], $r['status'], $r['description'], number_format(abs($r['amount']), 2)]));
            if (mb_strpos($hay, $q) === false) { return false; }
        }
        return true;
    }));

    // Sort.
    usort($rows, static function (array $a, array $b) use ($f) {
        switch ((string) ($f['sort'] ?? 'newest')) {
            case 'oldest':  return strcmp((string) $a['date'], (string) $b['date']);
            case 'highest': return abs($b['amount']) <=> abs($a['amount']);
            case 'lowest':  return abs($a['amount']) <=> abs($b['amount']);
            default:        return strcmp((string) $b['date'], (string) $a['date']); // newest
        }
    });

    return $rows;
}

/** Summary over a set of rows: totals + (for riders) opening/closing wallet balance. */
function tx_summary(PDO $pdo, array $user, array $rows): array {
    $credits = 0.0; $debits = 0.0;
    foreach ($rows as $r) {
        if ($r['amount'] >= 0) { $credits += $r['amount']; } else { $debits += -$r['amount']; }
    }
    $summary = ['credits' => $credits, 'debits' => $debits, 'count' => count($rows), 'has_balance' => false, 'opening' => 0.0, 'closing' => 0.0];
    if (($user['role'] ?? '') === 'rider') {
        // Closing = current wallet balance; opening = closing minus the net of the filtered set.
        $closing = function_exists('rider_wallet_balance') ? rider_wallet_balance($pdo, (int) $user['id']) : 0.0;
        $summary['has_balance'] = true;
        $summary['closing'] = $closing;
        $summary['opening'] = round($closing - ($credits - $debits), 2);
    }
    return $summary;
}

/** Map a raw payment/withdrawal status to one of the UI status tokens. */
function tx_map_status(string $s): string {
    return match ($s) {
        'success', 'paid' => 'successful',
        'pending', 'processing', 'initialized' => 'pending',
        'failed', 'rejected' => 'failed',
        'refunded' => 'refunded',
        default => $s,
    };
}
