// Shared API request/response contracts for the Aike mobile <-> backend `/api/v1` layer.
// TypeScript types only (no logic, no secrets). The backend is the source of truth; these types
// describe the JSON envelope and payload shapes the mobile client relies on.

import type { BookingStatus, PaymentStatus, Role } from '../constants/statuses';
import type { VehicleType } from '../constants/vehicles';

export const API_VERSION = 'v1';

/** Every API response uses this envelope. */
export interface ApiEnvelope<T> {
  ok: boolean;
  data: T | null;
  error: ApiError | null;
  meta?: { requestId?: string; cursor?: string | null };
}

export interface ApiError {
  code: string; // stable machine code, e.g. "RIDER_AT_CAPACITY", "INVALID_TRANSITION"
  message: string; // human-readable, safe to display
  fields?: Record<string, string>; // per-field validation messages
}

export interface AuthTokens {
  accessToken: string;
  refreshToken: string;
  expiresInSeconds: number;
}

export interface UserProfile {
  id: number;
  fullName: string;
  email: string;
  phone: string | null;
  role: Role;
  profileCompleted: boolean;
  avatarUrl: string | null;
}

export interface LatLng {
  lat: number;
  lng: number;
}

export interface PriceBreakdown {
  minimumFee: number;
  perKm: number;
  multiplier: number;
  tax: number;
  total: number;
  distanceKm: number;
  durationMinutes: number;
}

export interface RiderCandidate {
  userId: number;
  fullName: string;
  vehicleType: VehicleType;
  rating: number | null;
  distanceKm: number | null;
  etaMinutes: number | null;
  suggestedFee: number | null;
  pricingAvailable: boolean;
  lastSeenSecondsAgo: number | null; // null => never seen; large => stale (not "live")
}

export interface RiderOffer {
  requestId: number;
  bookingId: number;
  bookingCode: string;
  pickupAddress: string;
  dropoffAddress: string;
  vehicleType: VehicleType | null;
  itemName: string;
  proposedCost: number | null;
}

export interface Booking {
  id: number;
  status: BookingStatus;
  paymentStatus: PaymentStatus;
  vehicleType: VehicleType | null;
  pickup: { address: string } & Partial<LatLng>;
  dropoff: { address: string } & Partial<LatLng>;
  agreedCost: number | null;
  selectedRiderUserId: number | null;
  createdAt: string;
  updatedAt: string;
}

/** Requests. */
export interface LoginRequest { email: string; password: string; }
export interface EstimateRequest { pickup: LatLng; dropoff: LatLng; vehicleType: VehicleType; }
export interface CreateBookingRequest {
  pickup: { address: string } & LatLng;
  dropoff: { address: string } & LatLng;
  vehicleType: VehicleType;
  recipientName: string;
  recipientPhone: string;
  itemName: string;
  itemCategory?: string;
  itemDescription?: string;
  notes?: string;
}
export type BookingListFilter = 'active' | 'unpaid' | 'history';
export type RiderJobFilter = 'active' | 'pending' | 'completed' | 'cancelled';

export interface NotificationItem {
  id: number;
  title: string;
  body: string;
  url: string | null;
  read: boolean;
  createdAt: string;
}

export interface WalletLedgerEntry {
  type: string; // e.g. "earning", "withdrawal"
  amount: number;
  description: string;
  createdAt: string;
}

export interface RiderWallet {
  balance: number;
  availableBalance: number;
  ledger: WalletLedgerEntry[];
}

export type ComplaintCategory = 'damaged_item' | 'late_delivery' | 'wrong_item' | 'rider_behavior' | 'other';

export interface ChatMessage {
  id: number;
  mine: boolean;
  message: string;
  deliveredAt: string | null; // set → one tick ("sent")
  readAt: string | null;      // set → two ticks ("read")
  createdAt: string | null;
}

export interface PaymentReceipt {
  bookingId: number;
  bookingCode: string;
  amount: number | null;
  reference: string | null;
  paidAt: string;
}

// A full immutable receipt (GET /bookings/{id}/receipt), mirroring payment_receipts.
export interface ReceiptDetail {
  receiptNumber: string;
  orderCode: string;
  reference: string;
  customerName: string;
  riderName: string | null;
  pickupAddress: string;
  deliveryAddress: string;
  amount: number;      // net (ex-VAT)
  vatAmount: number;
  vatPercent: number;
  totalAmount: number; // VAT-inclusive total paid
  paymentMethod: string;
  paymentStatus: string;
  createdAt: string;
}

// Normalised transaction history (GET /transactions) shared with the web transactions.php.
export type TransactionDirection = 'credit' | 'debit';
export type TransactionCategory = 'ride_payment' | 'withdrawal' | 'refund';
export type TransactionStatus = 'successful' | 'pending' | 'failed' | 'refunded';

export interface TransactionItem {
  id: string;
  date: string;
  category: TransactionCategory | string;
  direction: TransactionDirection;
  description: string;
  orderCode: string;
  reference: string;
  amount: number; // signed: credit positive, debit negative
  status: TransactionStatus | string;
}

export interface TransactionSummary {
  credits: number;
  debits: number;
  count: number;
  hasBalance: boolean;
  opening: number;
  closing: number;
}

export interface TransactionsResult {
  transactions: TransactionItem[];
  summary: TransactionSummary;
  page: number;
  pages: number;
  total: number;
}

export interface TransactionFilters {
  range?: 'all' | 'today' | 'yesterday' | 'this_week' | 'last_week' | 'this_month' | 'last_month' | 'custom';
  from?: string;
  to?: string;
  type?: string;
  status?: string;
  q?: string;
  sort?: 'newest' | 'oldest' | 'highest' | 'lowest';
  page?: number;
}

export type KycStatus = 'pending' | 'approved' | 'rejected';

export interface RiderKycBiodata {
  age: number | null;
  stateOfOrigin: string | null;
  lgaOfOrigin: string | null;
  hometown: string | null;
  nationalIdNumber: string | null;
  address: string | null;
  guarantorName: string | null;
  guarantorPhone: string | null;
  guarantorAddress: string | null;
  guarantorRelationship: string | null;
  vehiclePlate: string | null;
  vehicleColor: string | null;
}

export interface RiderKyc {
  kycStatus: KycStatus;
  note: string | null;
  biodata: RiderKycBiodata;
  documents: {
    idDocument: boolean;
    proofOfAddress: boolean;
    vehicleDocument: boolean;
    drivingLicense: boolean;
  };
}

export interface RiderBankAccount {
  bankName: string;
  bankCode: string;
  accountNumberMasked: string;
  accountName: string;
  verified: boolean;
}

export type WithdrawalStatus = 'pending' | 'processing' | 'paid' | 'rejected';

export interface WithdrawalItem {
  amount: number;
  status: WithdrawalStatus;
  bankName: string;
  accountNumberMasked: string;
  requestedAt: string;
  processedAt: string | null;
  note: string | null;
}

/** PATCH /bookings/{id}: any subset of editable detail fields and/or a new delivery point. */
export interface UpdateBookingRequest {
  recipientName?: string;
  recipientPhone?: string;
  itemName?: string;
  itemCategory?: string;
  itemDescription?: string;
  notes?: string;
  dropoff?: { address: string } & LatLng;
}

/** Unsafe writes carry an Idempotency-Key header; see docs/04. */
export const IDEMPOTENCY_HEADER = 'Idempotency-Key';
export const AUTH_HEADER = 'Authorization';
