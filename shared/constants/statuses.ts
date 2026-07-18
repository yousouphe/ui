// Shared, non-sensitive identifiers mirrored from the backend (single source of truth =
// logistics-app/config/functions.php + sql/*.sql). These are IDENTIFIERS ONLY — no business
// logic. Never derive pricing, eligibility, or transition authority on the client from these.
//
// A backend test asserts these match the DB enums so the two never drift (see Phase 3 plan).

export const BOOKING_STATUS = {
  DRAFT: 'draft',
  SUBMITTED: 'submitted',
  MATCHED: 'matched',
  ACCEPTED: 'accepted',
  ARRIVED_AT_PICKUP: 'arrived_at_pickup',
  PACKAGE_RECEIVED: 'package_received',
  IN_TRANSIT: 'in_transit',
  DELIVERED: 'delivered',
  CANCELLED: 'cancelled',
} as const;
export type BookingStatus = (typeof BOOKING_STATUS)[keyof typeof BOOKING_STATUS];

// Statuses that count as a rider's "active" workload (RIDER_ACTIVE_BOOKING_STATUSES).
export const RIDER_ACTIVE_BOOKING_STATUSES: BookingStatus[] = [
  BOOKING_STATUS.MATCHED,
  BOOKING_STATUS.ACCEPTED,
  BOOKING_STATUS.ARRIVED_AT_PICKUP,
  BOOKING_STATUS.PACKAGE_RECEIVED,
  BOOKING_STATUS.IN_TRANSIT,
];

export const PAYMENT_STATUS = {
  UNPAID: 'unpaid',
  PENDING: 'pending',
  PAID: 'paid',
  FAILED: 'failed',
  REFUNDED: 'refunded',
} as const;
export type PaymentStatus = (typeof PAYMENT_STATUS)[keyof typeof PAYMENT_STATUS];

export const ROLES = {
  SENDER: 'sender',
  RIDER: 'rider',
  ADMIN: 'admin',
  SUPER_ADMIN: 'super_admin',
} as const;
export type Role = (typeof ROLES)[keyof typeof ROLES];

// Display-only status → badge colour, aligned with the web theme. Presentation only.
export const BOOKING_STATUS_COLOR: Record<BookingStatus, string> = {
  draft: '#64748b',
  submitted: '#0b6ec9',
  matched: '#0b6ec9',
  accepted: '#0b6ec9',
  arrived_at_pickup: '#0b6ec9',
  package_received: '#7c3aed',
  in_transit: '#7c3aed',
  delivered: '#16a34a',
  cancelled: '#b45309',
};
